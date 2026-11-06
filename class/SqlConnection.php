<?php

/*****************************************************************************
 * SqlConnection Class for MySQL databases
 *
 * Copyright (c) Jay Pipes http://www.jpipes.com/
 * Copyright (c) 2008 by Jenny Ferenc (contributor)
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 by Ariane (contributor)
 *
 * PDO (pdo_mysql) database abstraction layer using real server-side
 * prepared statements (exceptions on, emulated prepares off).
 *
 * Two API families:
 *  - Legacy (Execute, GetRecord, GetFirstCell, GetAll, ...): original
 *    signatures; query errors go to trigger_error() (or are silent) rather
 *    than throwing. Each accepts an optional trailing `array $params`.
 *  - query()/fetch()/fetchAll()/fetchColumn(): let PDOException propagate.
 *    Prefer these in new code.
 *
 * There is deliberately no manual-escaping helper; always bind parameters.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class SqlConnection
{
    // ── connection credentials ──────────────────────────────────────────────
    private string $_host = '';
    private string $_user = '';
    private string $_pass = '';
    private string $_name = '';

    private ?\PDO $_pdo = null;

    /** @var \PDOStatement[] */
    private array $_Results = [];

    private ?\PDOStatement $_Res = null;

    private int $_NumQueries = 0;

    // ── pagination state ────────────────────────────────────────────────────
    public int $max_results   = 15;
    public int $page          = 1;
    public int $total_results = 0;
    public int $cur_row       = 0;

    /** @var self */
    private static SqlConnection $instance;

    private function __construct()
    {
    }

    // ── singleton ───────────────────────────────────────────────────────────

    public static function instance(
        string $host = '',
        string $user = '',
        string $pass = '',
        string $name = ''
    ): self {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if ($host !== '') {
                self::$instance->SetConnOpt($host, $user, $pass, $name);
            }
        }
        return self::$instance;
    }

    // ── internal helpers ────────────────────────────────────────────────────

    private function SetConnOpt(
        string $host,
        string $user,
        string $pass,
        string $name
    ): void {
        $this->_host = $host;
        $this->_user = $user;
        $this->_pass = $pass;
        $this->_name = $name;
    }

    /** Connect lazily on first query if a caller never called Connect() explicitly. */
    private function ensureConnected(): void
    {
        if ($this->_pdo === null) {
            $this->Connect();
        }
    }

    /** The underlying PDO instance, for functionality this class doesn't wrap. */
    public function pdo(): \PDO
    {
        $this->ensureConnected();

        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo;
    }

    // ── connection ──────────────────────────────────────────────────────────

    /** Connects to the database; passing credentials switches to a different database. */
    public function Connect(
        string $host = '',
        string $user = '',
        string $pass = '',
        string $name = ''
    ): bool {
        if ($host !== '') {
            $this->SetConnOpt($host, $user, $pass, $name);
        }

        if ($this->_pdo !== null && $host === '') {
            return true;
        }

        // Configs may give dbhost as "host:port"; PDO needs the port as its own DSN segment.
        $dsnHost = $this->_host;
        $dsnPort = '';
        if (str_contains($dsnHost, ':')) {
            [$dsnHost, $maybePort] = explode(':', $dsnHost, 2);
            if (ctype_digit($maybePort)) {
                $dsnPort = ';port=' . $maybePort;
            }
        }

        $dsn = 'mysql:host=' . $dsnHost . $dsnPort . ';dbname=' . $this->_name . ';charset=utf8mb4';

        try {
            $this->_pdo = new \PDO($dsn, $this->_user, $this->_pass, [
                \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES         => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE       => \PDO::FETCH_ASSOC,
                // Buffered so rowCount() works for SELECTs and further queries can run mid-result.
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (\PDOException $e) {
            trigger_error('Could not connect to database server: ' . $e->getMessage(), E_USER_ERROR);
            return false;
        }

        return true;
    }

    // ── query execution ─────────────────────────────────────────────────────

    /**
     * Prepares and executes a statement, tracking it as the current result.
     * The single entry point to PDO::prepare()/execute() for both API families.
     *
     * @param array<int|string, mixed> $params
     * @throws \PDOException on prepare or execute failure.
     */
    private function runStatement(string $sql, array $params = []): \PDOStatement
    {
        $this->ensureConnected();
        $this->_NumQueries++;

        /** @var \PDO $pdo */
        $pdo  = $this->_pdo;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $this->_Res       = $stmt;
        $this->_Results[] = $stmt;

        return $stmt;
    }

    /** Fetches one row, then frees the statement. */
    private function fetchOneRow(\PDOStatement $stmt, int $fetchMode): array|false
    {
        $row = $stmt->fetch($fetchMode);
        $this->FreeResult();
        return $row === false ? false : $row;
    }

    /**
     * Fetches every remaining row, then frees the statement.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllRows(\PDOStatement $stmt): array
    {
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->FreeResult();
        return $rows ?: [];
    }

    /**
     * Executes a query with `?` placeholders bound from $params. Never
     * throws: failures go to trigger_error() (silently when $err_msg is
     * null) and return false. Use query() for exceptions.
     *
     * @param array<int|string, mixed> $params
     */
    public function Execute(string $Sql, ?string $err_msg = 'Could not execute query.', array $params = []): bool
    {
        try {
            $this->runStatement($Sql, $params);
        } catch (\PDOException $e) {
            if ($err_msg !== null) {
                trigger_error(
                    $err_msg . ' ' . $e->getMessage() . ': ' . $Sql,
                    E_USER_WARNING
                );
            }
            return false;
        }

        return true;
    }

    /**
     * Executes a paginated query. The total comes from a COUNT(*) subquery
     * (ORDER BY stripped), which also handles GROUP BY queries.
     *
     * @param array<int|string, mixed> $params
     */
    public function ExecutePaginate(string $Sql, int $max_results = 0, array $params = []): void
    {
        if ($max_results > 0) {
            $this->max_results = $max_results;
        }

        $this->page = isset($_GET['p']) ? (int) $_GET['p'] : 1;

        $sql_without_order = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $Sql);
        $count_sql = 'SELECT COUNT(*) FROM (' . $sql_without_order . ') AS _fu_count_sub';

        $this->total_results = (int) $this->GetFirstCell($count_sql, $params);

        if (isset($_GET['nopage'])) {
            $this->max_results = $this->total_results;
        }

        $this->cur_row = 0;
        $this->Execute(
            $Sql . ' LIMIT ' . (($this->page - 1) * $this->max_results) . ', ' . $this->max_results,
            'Could not execute query.',
            $params
        );
    }

    // ── result navigation ───────────────────────────────────────────────────

    public function ReadRecord(): array|false
    {
        $res = $this->GetLastResult();
        if ($res === false) {
            return false;
        }
        $row = $res->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? false : $row;
    }

    public function ReadRecordPaginate(): array|false
    {
        if ($this->cur_row >= $this->max_results) {
            return false;
        }
        $this->cur_row++;
        return $this->ReadRecord();
    }

    /**
     * Runs $sql (if given) and returns the first row, or [] on no rows or
     * failure.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     */
    public function GetRecord(string $sql = '', array $params = []): array
    {
        if ($sql !== '') {
            $this->Execute($sql, 'Could not execute query.', $params);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return [];
        }

        $row = $this->fetchOneRow($res, \PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    /**
     * Runs $sql (if given) and returns the first column of the first row.
     * `false` means no row or failure, so it can't be told apart from a
     * stored `false` value.
     *
     * @param array<int|string, mixed> $params
     * @return int|float|string|bool|null
     */
    public function GetFirstCell(string $sql = '', array $params = []): mixed
    {
        if ($sql !== '') {
            $this->Execute($sql, 'Could not execute query.', $params);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return false;
        }

        $row = $this->fetchOneRow($res, \PDO::FETCH_NUM);
        return $row === false ? false : ($row[0] ?? false);
    }

    /**
     * Runs $sql (if given) and returns every row, or [] on no rows or failure.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function GetAll(string $sql = '', array $params = []): array
    {
        if ($sql !== '') {
            $this->Execute($sql, 'Could not execute query.', $params);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return [];
        }

        return $this->fetchAllRows($res);
    }

    public function GetLastResult(): \PDOStatement|false
    {
        $result = end($this->_Results);
        return ($result instanceof \PDOStatement) ? $result : false;
    }

    // ── exception-throwing API ──────────────────────────────────────────────
    // No lowercase execute() alias: PHP method names are case-insensitive,
    // so it would collide with Execute().

    /**
     * Runs a bound-parameter query and returns the raw PDOStatement (e.g. to
     * stream a large result set).
     *
     * @param array<int|string, mixed> $params
     * @throws \PDOException on prepare or execute failure.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        return $this->runStatement($sql, $params);
    }

    /**
     * Returns the first row of a bound-parameter query, or [].
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     * @throws \PDOException on prepare or execute failure.
     */
    public function fetch(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $row  = $this->fetchOneRow($stmt, \PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    /**
     * Returns every row of a bound-parameter query.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws \PDOException on prepare or execute failure.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $this->fetchAllRows($stmt);
    }

    /**
     * Returns the first column of the first row, or false when there are no
     * rows (same ambiguity as GetFirstCell()).
     *
     * @param array<int|string, mixed> $params
     * @return int|float|string|bool|null
     * @throws \PDOException on prepare or execute failure.
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->query($sql, $params);
        $row  = $this->fetchOneRow($stmt, \PDO::FETCH_NUM);
        return $row === false ? false : ($row[0] ?? false);
    }

    // ── transactions ─────────────────────────────────────────────────────────

    /** @throws \PDOException if a transaction is already active. */
    public function beginTransaction(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->beginTransaction();
    }

    /** @throws \PDOException if there is no active transaction. */
    public function commit(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->commit();
    }

    /** @throws \PDOException if there is no active transaction. */
    public function rollBack(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->inTransaction();
    }

    // ── statistics ──────────────────────────────────────────────────────────

    public function GetNumResults(): int
    {
        return count($this->_Results);
    }

    public function GetNumQueries(): int
    {
        return $this->_NumQueries;
    }

    public function NumRows(): int
    {
        $res = $this->GetLastResult();
        return $res ? $res->rowCount() : 0;
    }

    public function AffectedRows(): int
    {
        return $this->_Res !== null ? $this->_Res->rowCount() : 0;
    }

    public function GetLastSequence(): int
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return (int) $pdo->lastInsertId();
    }

    // ── result management ───────────────────────────────────────────────────

    public function FreeResult(): void
    {
        $last = $this->GetLastResult();
        if ($last !== false) {
            $stmt = array_pop($this->_Results);
            $stmt->closeCursor();
        }
    }

    // ── pagination output ───────────────────────────────────────────────────

    public function PrintPaginate(
        bool   $doNums   = true,
        string $nextName = 'Next',
        string $prevName = 'Previous'
    ): void {
        if ($this->total_results <= $this->max_results) {
            return;
        }

        $total_pages = (int) ceil($this->total_results / $this->max_results);
        $clean_uri   = htmlspecialchars(clean_input($_SERVER['REQUEST_URI']));

        if ($this->page === 1 && empty($_GET['p'])) {
            $clean_uri .= empty($_GET) ? '?p=1' : '&amp;p=1';
        }

        $clean_uri = str_replace('&amp;nopage=1', '', $clean_uri);

        if ($this->page > 1) {
            $prev = $this->page - 1;
            $url  = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1p=' . $prev, $clean_uri);
            echo '<a href="' . $url . '" class="prev">' . $prevName . '</a> ';
        }

        if ($doNums) {
            $sep_str = '';
            if ($this->page > 1) {
                echo '&middot; ';
            }

            for ($i = 1; $i <= $total_pages; $i++) {
                $near_start = $i < 4;
                $near_end   = $i > ($total_pages - 3);
                $near_cur   = ($i < $this->page + 3 && $i > $this->page - 3);

                if ($near_start || $near_end || $near_cur) {
                    echo $sep_str;
                    $sep_str = '';
                    if ($this->page === $i) {
                        echo '<strong class="here">' . $i . '</strong> ';
                    } else {
                        $url = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1p=' . $i, $clean_uri);
                        echo '<a href="' . $url . '">' . $i . '</a> ';
                    }
                } else {
                    $sep_str = ' ... ';
                }
            }

            $url = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1nopage=1', $clean_uri);
            echo '&middot; <a href="' . $url . '">All</a> ';

            if ($this->page < $total_pages) {
                echo '&middot; ';
            }
        }

        if ($this->page < $total_pages) {
            $next = $this->page + 1;
            $url  = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1p=' . $next, $clean_uri);
            echo '<a href="' . $url . '" class="next">' . $nextName . '</a>';
        }
    }
}
