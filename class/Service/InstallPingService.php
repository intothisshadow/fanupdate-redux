<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — InstallPingService
 *
 * TODO.md "FP-15" — Opt-In Anonymous Install Ping. Off by default; once an
 * administrator enables it from admin/privacy.php, this sends a small,
 * anonymous POST (install_uuid, version, php_version — nothing else, see
 * class docblock in admin/privacy.php for the full field list) to
 * FU_INSTALL_PING_URL approximately once every 30 days, so the maintainer
 * can get a rough install count and a PHP-version adoption breakdown —
 * enough to judge when it's safe to drop support for an old PHP version.
 *
 * Deliberately independent of the Admin Update Manager (UpdateManager/
 * GitHubUpdateProvider/DownloadManager): its own repository
 * (PrivacyRepositoryInterface, not UpdateRepositoryInterface), its own
 * request transport (sendViaCurl()/sendViaStream() below, not shared with
 * GitHubUpdateProvider's or DownloadManager's near-identical-looking
 * methods), and its own endpoint (FU_INSTALL_PING_URL, not
 * FU_UPDATE_PROVIDER_OWNER/REPO). This is intentional duplication, not an
 * oversight — the ticket explicitly requires the two features share no
 * request handlers, endpoints, or a dependency on each other being enabled.
 *
 * "Opportunistic, on an admin page load" scheduling — same reasoning as
 * UpdateManager::runAutomaticCheckIfDue(): there's no cron available on
 * typical shared hosting, so maybePingIfDue() is cheap to call on every
 * Dashboard load (a single cached-state read plus a date comparison) and
 * only actually contacts the network once the configured interval has
 * elapsed. Failures are always caught and never thrown further — a ping is
 * a courtesy the host's network conditions might simply not allow this
 * time, not something that should ever be visible to the admin as an error,
 * let alone block anything else on the page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class InstallPingService
{
    private const PING_INTERVAL_SECONDS = 30 * 24 * 3600;
    private const TIMEOUT_SECONDS        = 3;

    /** @var callable(string, array<string, string>): int */
    private $transport;

    /**
     * @param null|callable(string, array<string, string>): int $transport
     *        Network transport override for testing — receives the ping URL
     *        and POST fields, returns the HTTP status code (or throws on a
     *        transport-level failure). Defaults to the real cURL/stream
     *        implementation.
     */
    public function __construct(
        private readonly PrivacyRepositoryInterface $repository,
        private readonly string $installedVersionRaw,
        private readonly string $pingUrl,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->defaultTransport(...);
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->repository->getConfig()['enabled'] ?? false);
    }

    /**
     * Enable or disable the ping. Generates a UUIDv4 the first time it is
     * enabled and this installation has none yet; an install UUID is never
     * regenerated afterwards, including across a later disable/re-enable —
     * per FP-15's "never regenerate unless explicitly reset".
     */
    public function setEnabled(bool $enabled): bool
    {
        $config            = $this->repository->getConfig();
        $config['enabled'] = $enabled;

        if ($enabled && empty($config['installUuid'])) {
            $config['installUuid'] = self::generateUuidV4();
        }

        return $this->repository->saveConfig($config);
    }

    /**
     * @return array{
     *   enabled: bool,
     *   installUuid: string|null,
     *   lastPingAt: string|null,
     *   lastPingStatus: string|null,
     *   lastPingError: string|null,
     * }
     */
    public function getStatus(): array
    {
        $config = $this->repository->getConfig();
        $state  = $this->repository->getState();

        return [
            'enabled'        => (bool) ($config['enabled'] ?? false),
            'installUuid'    => is_string($config['installUuid'] ?? null) ? $config['installUuid'] : null,
            'lastPingAt'     => is_string($state['lastPingAt'] ?? null) ? $state['lastPingAt'] : null,
            'lastPingStatus' => is_string($state['lastPingStatus'] ?? null) ? $state['lastPingStatus'] : null,
            'lastPingError'  => is_string($state['lastPingError'] ?? null) ? $state['lastPingError'] : null,
        ];
    }

    /**
     * Send a ping if the feature is enabled and at least
     * PING_INTERVAL_SECONDS have elapsed since the last *successful* ping
     * (never since the last attempt — a failed attempt must not push the
     * next try out by a further 30 days, it should simply be retried the
     * next time an admin page loads). Safe to call on every Dashboard load;
     * never throws, never returns anything for the caller to act on — this
     * is a fire-and-forget courtesy ping, not a user-facing operation.
     */
    public function maybePingIfDue(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $lastPingAt = $this->repository->getState()['lastPingAt'] ?? null;

        if (is_string($lastPingAt) && $lastPingAt !== '') {
            $lastPingTs = strtotime($lastPingAt);
            if ($lastPingTs !== false && time() < $lastPingTs + self::PING_INTERVAL_SECONDS) {
                return; // Not due yet.
            }
        }

        $this->attemptPing();
    }

    /**
     * Send a ping right now, ignoring the 30-day schedule — used by the
     * "Send a test ping now" button on admin/privacy.php so an
     * administrator can confirm the feature actually works without waiting
     * a month. Still requires the feature to be enabled first.
     *
     * @return array{ok: bool, error?: string}
     */
    public function pingNow(): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'Anonymous install statistics are not enabled.'];
        }

        return $this->attemptPing();
    }

    /** @return array{ok: bool, error?: string} */
    private function attemptPing(): array
    {
        $installUuid = $this->repository->getConfig()['installUuid'] ?? null;

        if (!is_string($installUuid) || $installUuid === '') {
            // Shouldn't normally happen — setEnabled(true) always generates
            // one — but guard defensively rather than sending a blank ID.
            return ['ok' => false, 'error' => 'No installation ID has been generated yet.'];
        }

        $state = $this->repository->getState();

        try {
            $this->sendPing($installUuid);

            $state['lastPingAt']     = gmdate('c');
            $state['lastPingStatus'] = 'ok';
            $state['lastPingError']  = null;
            $this->repository->saveState($state);

            return ['ok' => true];
        } catch (\Throwable $e) {
            $state['lastPingStatus'] = 'error';
            $state['lastPingError']  = $e->getMessage();
            $this->repository->saveState($state);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @throws \RuntimeException on a non-HTTPS URL, transport failure, or non-2xx response. */
    private function sendPing(string $installUuid): void
    {
        if (!str_starts_with($this->pingUrl, 'https://')) {
            throw new \RuntimeException('Refusing to ping a non-HTTPS URL.');
        }

        $fields = [
            'install_uuid' => $installUuid,
            'version'      => self::normalizeVersion($this->installedVersionRaw),
            'php_version'  => PHP_VERSION,
        ];

        $status = ($this->transport)($this->pingUrl, $fields);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Install ping failed with HTTP status ' . $status . '.');
        }
    }

    /**
     * Strip any non-numeric prefix (e.g. "Redux " in FU_VERSION's
     * "Redux 2.2.0") so the reported version is a plain, comparable version
     * number. Deliberately duplicated from UpdateManager::normalizeVersion()
     * rather than reused — see class docblock on why this feature shares no
     * code with the update checker. Returns the input unchanged if no
     * numeric version can be found.
     */
    private static function normalizeVersion(string $raw): string
    {
        if (preg_match('/\d+(?:\.\d+){1,3}/', $raw, $m)) {
            return $m[0];
        }

        return $raw;
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10

        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Default network transport: cURL when available, a stream-context POST
     * otherwise — same dual-transport approach as GitHubUpdateProvider/
     * DownloadManager, kept as separate code per this class's
     * independence requirement (see class docblock).
     *
     * @param array<string, string> $fields
     * @throws \RuntimeException on a transport-level connection failure.
     */
    private function defaultTransport(string $url, array $fields): int
    {
        return function_exists('curl_init')
            ? $this->sendViaCurl($url, $fields)
            : $this->sendViaStream($url, $fields);
    }

    /** @param array<string, string> $fields */
    private function sendViaCurl(string $url, array $fields): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise a cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'FanUpdateRedux-InstallPing',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($ch); // Response body is ignored entirely, per FP-15.
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($status === 0) {
            throw new \RuntimeException('Could not connect: ' . $error);
        }

        return $status;
    }

    /** @param array<string, string> $fields */
    private function sendViaStream(string $url, array $fields): int
    {
        $context = stream_context_create([
            'http' => [
                'method'           => 'POST',
                'header'           => "Content-Type: application/x-www-form-urlencoded\r\n"
                    . 'User-Agent: FanUpdateRedux-InstallPing',
                'content'          => http_build_query($fields),
                'timeout'          => self::TIMEOUT_SECONDS,
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($result === false && $status === 0) {
            throw new \RuntimeException('Could not connect to the install ping endpoint.');
        }

        return $status;
    }
}
