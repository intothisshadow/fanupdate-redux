<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — GitHubUpdateProvider
 *
 * UpdateProviderInterface backed by the GitHub Releases API. Built for shared
 * hosting: no shell/Composer/Git, and cURL is optional (falls back to
 * file_get_contents() with a stream context).
 *
 * HTTPS only. Owner/repo are fixed constructor arguments, not admin-editable,
 * so the UI can't repoint this at another source; only the release channel
 * and an optional access token (higher rate limits) are configurable.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class GitHubUpdateProvider implements UpdateProviderInterface
{
    private const API_BASE        = 'https://api.github.com';
    private const TIMEOUT_SECONDS = 12;

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly ?string $token = null,
    ) {
    }

    public function getProviderName(): string
    {
        return 'GitHub';
    }

    public function getSourceLabel(): string
    {
        return $this->owner . '/' . $this->repo;
    }

    /**
     * Hosts a release download may legitimately touch: the initial request
     * host, plus the signed-URL and codeload redirect targets.
     *
     * @return array<int, string>
     */
    public function getAllowedDownloadHosts(): array
    {
        return [
            'github.com',
            'api.github.com',
            'codeload.github.com',
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
        ];
    }

    public function getLatestRelease(string $channel = 'stable'): ReleaseInfo
    {
        $release = $channel === 'prerelease'
            ? $this->fetchNewestRelease()
            : $this->fetchLatestStableRelease();

        return $this->normalise($release);
    }

    /**
     * GET /releases/latest (GitHub excludes prereleases and drafts).
     *
     * @return array<string, mixed>
     */
    private function fetchLatestStableRelease(): array
    {
        $url  = self::API_BASE . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/releases/latest';
        $data = $this->request($url);

        if (!is_array($data) || !isset($data['tag_name'])) {
            throw new \RuntimeException('No stable release was found for ' . $this->getSourceLabel() . '.');
        }

        return $data;
    }

    /**
     * GET /releases, newest first including prereleases; the first non-draft
     * entry is the newest on either channel.
     *
     * @return array<string, mixed>
     */
    private function fetchNewestRelease(): array
    {
        $url  = self::API_BASE . '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo) . '/releases?per_page=10';
        $data = $this->request($url);

        if (!is_array($data)) {
            throw new \RuntimeException('Unexpected response from the GitHub Releases API.');
        }

        foreach ($data as $release) {
            if (is_array($release) && empty($release['draft'])) {
                return $release;
            }
        }

        throw new \RuntimeException('No releases were found for ' . $this->getSourceLabel() . '.');
    }

    /** @param array<string, mixed> $release */
    private function normalise(array $release): ReleaseInfo
    {
        $this->assertIsGenuineRelease($release);

        $tag     = (string) ($release['tag_name'] ?? '');
        $version = $this->parseAndValidateVersion($tag);

        $downloadUrl  = null;
        $downloadSize = null;
        $zipAssetName = null;

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && str_ends_with((string) ($asset['name'] ?? ''), '.zip')) {
                $downloadUrl  = ((string) ($asset['browser_download_url'] ?? '')) ?: null;
                $downloadSize = isset($asset['size']) ? (int) $asset['size'] : null;
                $zipAssetName = (string) ($asset['name'] ?? '');
                break;
            }
        }

        // Fall back to the auto-generated source ZIP when there is no build asset.
        if ($downloadUrl === null) {
            $downloadUrl = ((string) ($release['zipball_url'] ?? '')) ?: null;
        }

        // A checksum is only looked up for a named ZIP asset (not the zipball fallback).
        $expectedSha256 = $zipAssetName !== null
            ? $this->findChecksum((array) ($release['assets'] ?? []), $zipAssetName)
            : null;

        return new ReleaseInfo(
            version: $version,
            name: (string) ($release['name'] ?: $tag),
            notes: (string) ($release['body'] ?? ''),
            url: (string) ($release['html_url'] ?? ''),
            publishedAt: (string) ($release['published_at'] ?? $release['created_at'] ?? ''),
            prerelease: (bool) ($release['prerelease'] ?? false),
            downloadUrl: $downloadUrl,
            downloadSize: $downloadSize,
            expectedSha256: $expectedSha256,
        );
    }

    /**
     * Finds a SHA-256 for $zipAssetName: "{$zipAssetName}.sha256" first,
     * then "sha256sums.txt"/"checksums.txt". Never throws; older releases
     * have no checksum and must still be downloadable.
     *
     * @param array<int, mixed> $assets
     */
    private function findChecksum(array $assets, string $zipAssetName): ?string
    {
        $checksumAsset = null;
        $exactName     = strtolower($zipAssetName) . '.sha256';

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = strtolower((string) ($asset['name'] ?? ''));
            if ($name === $exactName) {
                $checksumAsset = $asset;
                break;
            }
        }

        if ($checksumAsset === null) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = strtolower((string) ($asset['name'] ?? ''));
                if ($name === 'sha256sums.txt' || $name === 'checksums.txt') {
                    $checksumAsset = $asset;
                    break;
                }
            }
        }

        if ($checksumAsset === null) {
            return null;
        }

        $url = (string) ($checksumAsset['browser_download_url'] ?? '');
        if ($url === '') {
            return null;
        }

        $raw = $this->fetchAssetContent($url);
        if ($raw === null) {
            return null;
        }

        return $this->parseChecksum($raw, $zipAssetName);
    }

    /**
     * Parses a bare 64-char hex hash, or `sha256sum` output ("{hash}  {filename}"
     * per line, matching $zipAssetName). Returns the lowercase hash or null.
     */
    private function parseChecksum(string $raw, string $zipAssetName): ?string
    {
        $trimmed = trim($raw);

        if (preg_match('/^[a-f0-9]{64}$/i', $trimmed)) {
            return strtolower($trimmed);
        }

        $needle = strtolower($zipAssetName);
        foreach (explode("\n", $trimmed) as $line) {
            $line = trim($line);
            if (
                str_contains(strtolower($line), $needle)
                && preg_match('/^([a-f0-9]{64})\s/i', $line, $m)
            ) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /**
     * Fetches a release asset's raw body (e.g. a checksum file). Unlike
     * request(), follows redirects and doesn't decode JSON. Size-capped, and
     * any failure yields null because checksums are optional.
     */
    private function fetchAssetContent(string $url): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $body = function_exists('curl_init')
                ? $this->fetchAssetViaCurl($url)
                : $this->fetchAssetViaStream($url);
        } catch (\Throwable) {
            return null;
        }

        return $body !== null ? substr($body, 0, 65536) : null;
    }

    private function fetchAssetViaCurl(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'FanUpdateRedux-Updater',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RANGE          => '0-65535',
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        return (string) $body;
    }

    private function fetchAssetViaStream(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'header'           => 'User-Agent: FanUpdateRedux-Updater' . "\r\n" . 'Range: bytes=0-65535',
                'timeout'          => self::TIMEOUT_SECONDS,
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
                'follow_location'  => 1,
                'max_redirects'    => 4,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return ($status >= 200 && $status < 300) ? (string) $body : null;
    }

    /**
     * Defence in depth: confirms the response describes a real release of
     * this repository. normalise() defaults every missing field, so a
     * malformed body would otherwise yield a ReleaseInfo built from nothing.
     *
     * @param array<string, mixed> $release
     * @throws \RuntimeException if the release doesn't look genuine.
     */
    private function assertIsGenuineRelease(array $release): void
    {
        if (!isset($release['id']) || !is_int($release['id'])) {
            throw new \RuntimeException('The GitHub API response does not look like a real release (missing a numeric release ID).');
        }

        $htmlUrl = (string) ($release['html_url'] ?? '');
        $expectedPrefix = 'https://github.com/' . $this->owner . '/' . $this->repo . '/releases/tag/';

        if ($htmlUrl === '' || !str_starts_with($htmlUrl, $expectedPrefix)) {
            throw new \RuntimeException(
                'The release URL in the GitHub API response does not match the expected repository (' . $this->getSourceLabel() . ').'
            );
        }
    }

    /**
     * Strips an optional "v" and requires the whole string to be a version
     * (1–4 numeric segments, optional pre-release suffix). Fully anchored so
     * a tag like "2.2.0/../../evil" is rejected: the value ends up in backup
     * filenames and logs.
     *
     * @throws \RuntimeException if the tag isn't a safe version number.
     */
    private function parseAndValidateVersion(string $tag): string
    {
        $version = ltrim($tag, 'vV');

        if ($version === '' || !preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.]+)?$/', $version)) {
            throw new \RuntimeException('Release tag "' . $tag . '" is not a recognisable version number.');
        }

        return $version;
    }

    /**
     * Fetches and JSON-decodes a GitHub API URL.
     *
     * @throws \RuntimeException on transport failure, non-2xx status, or invalid JSON.
     */
    private function request(string $url): mixed
    {
        if (!str_starts_with($url, self::API_BASE . '/')) {
            // Defence in depth: never request anywhere but API_BASE.
            throw new \RuntimeException('Refused to request a URL outside the GitHub API.');
        }

        $headers = [
            'User-Agent: FanUpdateRedux-Updater',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        [$status, $body] = function_exists('curl_init')
            ? $this->requestViaCurl($url, $headers)
            : $this->requestViaStream($url, $headers);

        if ($status === 403 && stripos($body, 'rate limit') !== false) {
            throw new \RuntimeException(
                'GitHub API rate limit exceeded. Please wait and try again, or set a GitHub token in Update Settings for a higher limit.'
            );
        }

        if ($status === 404) {
            throw new \RuntimeException('Repository or release not found on GitHub (' . $this->getSourceLabel() . ').');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('GitHub API request failed with HTTP status ' . $status . '.');
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Could not parse the GitHub API response: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array{0:int,1:string}
     */
    private function requestViaCurl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise a cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Could not connect to GitHub: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }

    /**
     * @param array<int, string> $headers
     * @return array{0:int,1:string}
     */
    private function requestViaStream(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'header'           => implode("\r\n", $headers),
                'timeout'          => self::TIMEOUT_SECONDS,
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException(
                'Could not connect to GitHub (allow_url_fopen may be disabled and the curl extension is not installed).'
            );
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $body];
    }
}
