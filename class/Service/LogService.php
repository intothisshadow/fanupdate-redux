<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — LogService (FP-46)
 *
 * Writes to and queries the log table: a small, always-on audit trail of
 * security and admin events (logins, lockouts, content and settings changes,
 * update-pipeline actions).
 *
 * Privacy: only an event type, the acting username, an IP address and a short
 * message are stored — never passwords, tokens or submitted comment bodies.
 * Control characters are stripped and field lengths capped. IPs can be
 * anonymised (last IPv4 octet / last 80 IPv6 bits zeroed) via the
 * log_anonymize_ips option.
 *
 * Every write is wrapped in try/catch and fails silently, including when the
 * table does not exist yet on a pre-upgrade install: an audit-log write must
 * never break the action it is recording.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class LogService
{
    public const LOGIN_SUCCESS    = 'login_success';
    public const LOGIN_FAILURE    = 'login_failure';
    public const LOGIN_LOCKOUT    = 'login_lockout';
    public const LOGOUT           = 'logout';
    public const PASSWORD_CHANGED = 'password_changed';
    public const PASSWORD_RESET   = 'password_reset';
    public const ACCOUNT_UPDATED  = 'account_updated';
    public const ENTRY_CREATED    = 'entry_created';
    public const ENTRY_UPDATED    = 'entry_updated';
    public const ENTRY_DELETED    = 'entry_deleted';
    public const CATEGORY_CHANGED = 'category_changed';
    public const MEDIA_DELETED    = 'media_deleted';
    public const COMMENT_CHANGED  = 'comment_changed';
    public const COMMENT_BLOCKED  = 'comment_rate_limited';
    public const BLACKLIST_CHANGED = 'blacklist_changed';
    public const OPTIONS_CHANGED  = 'options_changed';
    public const UPDATE_ACTION    = 'update_action';

    /**
     * Event-type catalog; order defines the filter dropdown order.
     *
     * @var array<string, string>
     */
    public const EVENT_TYPES = [
        self::LOGIN_SUCCESS     => 'Login — Success',
        self::LOGIN_FAILURE     => 'Login — Failure',
        self::LOGIN_LOCKOUT     => 'Login — Locked Out',
        self::LOGOUT            => 'Logout',
        self::PASSWORD_CHANGED  => 'Account — Password Changed',
        self::PASSWORD_RESET    => 'Account — Password Reset',
        self::ACCOUNT_UPDATED   => 'Account — Username/Email Updated',
        self::ENTRY_CREATED     => 'Entry — Created',
        self::ENTRY_UPDATED     => 'Entry — Updated',
        self::ENTRY_DELETED     => 'Entry — Deleted',
        self::CATEGORY_CHANGED  => 'Category — Changed',
        self::MEDIA_DELETED     => 'Media — Deleted',
        self::COMMENT_CHANGED   => 'Comment — Moderated',
        self::COMMENT_BLOCKED   => 'Comment — Rate Limited',
        self::BLACKLIST_CHANGED => 'Blacklist — Changed',
        self::OPTIONS_CHANGED   => 'Settings — Changed',
        self::UPDATE_ACTION     => 'Updates — Action',
    ];

    public const DEFAULT_RETENTION_DAYS = 90;
    public const MAX_PER_PAGE           = 200;

    private const MAX_MESSAGE_CHARS = 500;

    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $table,
        private readonly bool $anonymizeIps = false,
    ) {
    }

    public function log(string $eventType, string $username, string $ip, string $message): void
    {
        try {
            $this->db->query(
                'INSERT INTO `' . $this->table . '` (`event_type`, `username`, `ip`, `message`, `created_at`)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                [
                    self::clean($eventType, 32),
                    self::clean($username, 50),
                    self::clean($this->anonymizeIps ? self::anonymizeIp($ip) : $ip, 45),
                    self::clean($message, self::MAX_MESSAGE_CHARS),
                ]
            );
        } catch (\Throwable) {
            // Table absent on a pre-upgrade install, or the DB is down; never break the caller.
        }
    }

    /**
     * Newest first.
     *
     * @param array{event_type?: string, search?: string, date_from?: string, date_to?: string} $filters
     * @return list<array{id: int, event_type: string, username: string, ip: string, message: string, created_at: string}>
     */
    public function query(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        try {
            return array_values($this->db->fetchAll(
                'SELECT `id`, `event_type`, `username`, `ip`, `message`, `created_at`
                   FROM `' . $this->table . '` ' . $where . '
                  ORDER BY `created_at` DESC, `id` DESC
                  LIMIT ' . $perPage . ' OFFSET ' . $offset,
                $params
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array{event_type?: string, search?: string, date_from?: string, date_to?: string} $filters */
    public function countByFilters(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        try {
            return (int) $this->db->fetchColumn(
                'SELECT COUNT(*) FROM `' . $this->table . '` ' . $where,
                $params
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Deletes rows older than $days days (0 = keep forever). Returns rows removed. */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        try {
            return $this->db->query(
                'DELETE FROM `' . $this->table . '` WHERE `created_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)'
            )->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function clear(): bool
    {
        try {
            $this->db->query('DELETE FROM `' . $this->table . '`');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts    = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;

        if ($packed === false) {
            return '';
        }

        $anonymized = substr($packed, 0, 6) . str_repeat("\0", 10);

        return (string) inet_ntop($anonymized);
    }

    /** Strips control characters (log-forging defence) and caps the length. */
    public static function clean(string $value, int $maxLength): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return mb_substr(trim($value), 0, $maxLength);
    }

    /**
     * @param array{event_type?: string, search?: string, date_from?: string, date_to?: string} $filters
     * @return array{0: string, 1: list<string>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        $type = $filters['event_type'] ?? '';
        if ($type !== '' && isset(self::EVENT_TYPES[$type])) {
            $clauses[] = '`event_type` = ?';
            $params[]  = $type;
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $like      = '%' . addcslashes($search, '\\%_') . '%';
            $clauses[] = '(`message` LIKE ? OR `username` LIKE ? OR `ip` LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $from = $filters['date_from'] ?? '';
        if (self::isDate($from)) {
            $clauses[] = '`created_at` >= ?';
            $params[]  = $from . ' 00:00:00';
        }

        $to = $filters['date_to'] ?? '';
        if (self::isDate($to)) {
            $clauses[] = '`created_at` <= ?';
            $params[]  = $to . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    private static function isDate(string $value): bool
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
