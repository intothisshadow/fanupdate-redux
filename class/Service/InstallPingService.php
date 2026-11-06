<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — InstallPingService
 *
 * Opt-in anonymous install ping. Off by default; once enabled in
 * admin/privacy.php it POSTs install_uuid, version and php_version to
 * FU_INSTALL_PING_URL about every 30 days, giving the maintainer an install
 * count and PHP-version breakdown.
 *
 * Deliberately independent of the update manager: its own repository,
 * transport and endpoint. The duplication is intentional, since the two
 * features must share no handlers or endpoints and neither may depend on the
 * other being enabled.
 *
 * Scheduling is opportunistic (no cron on shared hosts): maybePingIfDue() is
 * cheap enough for every Dashboard load and only contacts the network once
 * the interval has elapsed. Failures are caught and never surfaced; a ping is
 * a courtesy.
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
     *        Test override: receives the URL and POST fields, returns the HTTP
     *        status (or throws). Defaults to cURL/stream.
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
     * Enables or disables the ping. The install UUID is generated once and
     * never regenerated, even across disable/re-enable.
     */
    public function setEnabled(bool $enabled): bool
    {
        $config            = $this->repository->getConfig();
        $config['enabled'] = $enabled;

        if ($enabled && empty($config['installUuid'])) {
            $config['installUuid'] = $this->generateUuidV4();
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
     * Pings if enabled and PING_INTERVAL_SECONDS have passed since the last
     * *successful* ping (a failure is retried on the next admin page load).
     * Safe on every Dashboard load; never throws.
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
     * Pings now, ignoring the schedule (admin/privacy.php's test button).
     * Still requires the feature to be enabled.
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
            // setEnabled(true) always generates one; never send a blank ID.
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
            'version'      => $this->normalizeVersion($this->installedVersionRaw),
            'php_version'  => PHP_VERSION,
        ];

        $status = ($this->transport)($this->pingUrl, $fields);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Install ping failed with HTTP status ' . $status . '.');
        }
    }

    /**
     * Strips a non-numeric prefix (e.g. "Redux " from "Redux 2.2.0"); returns
     * the input unchanged if no version is found. Duplicated from the update
     * checker on purpose (see class docblock).
     */
    private function normalizeVersion(string $raw): string
    {
        if (preg_match('/\d+(?:\.\d+){1,3}/', $raw, $m)) {
            return $m[0];
        }

        return $raw;
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10

        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Default transport: cURL, else a stream-context POST (kept separate from
     * the update code; see class docblock).
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

        curl_exec($ch); // The response body is deliberately ignored.
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
