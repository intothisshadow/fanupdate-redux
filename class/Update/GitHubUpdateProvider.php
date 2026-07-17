<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — GitHubUpdateProvider
 *
 * UpdateProviderInterface implementation backed by the GitHub Releases API
 * (https://api.github.com/repos/{owner}/{repo}/releases…). Designed for
 * typical shared hosting: no shell_exec()/exec()/Composer/Git, and no hard
 * dependency on the cURL extension — falls back to file_get_contents() with
 * a stream context when curl isn't loaded, since both are common on shared
 * hosts and neither can be assumed.
 *
 * HTTPS only. The repository owner/name are fixed constructor arguments set
 * by admin/update.php, not exposed as user-editable admin fields, so this
 * can't be repointed at an attacker-controlled source through the UI — only
 * the release channel (stable/prerelease) and an optional personal access
 * token (for higher, authenticated rate limits) are configurable.
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
     * GitHub release asset downloads (browser_download_url) redirect through
     * a signed URL on objects.githubusercontent.com or, for newer releases,
     * release-assets.githubusercontent.com; the zipball_url fallback starts
     * on api.github.com (the API host itself serves that endpoint) and
     * redirects to codeload.github.com. github.com itself is kept in the
     * list since it's the host of the initial request for asset downloads.
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
     * GET /repos/{owner}/{repo}/releases/latest — GitHub itself excludes
     * prereleases and drafts from this endpoint.
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
     * GET /repos/{owner}/{repo}/releases — newest first, includes
     * prereleases; the first non-draft entry is the newest available
     * release on either channel.
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

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && str_ends_with((string) ($asset['name'] ?? ''), '.zip')) {
                $downloadUrl  = ((string) ($asset['browser_download_url'] ?? '')) ?: null;
                $downloadSize = isset($asset['size']) ? (int) $asset['size'] : null;
                break;
            }
        }

        // Fall back to GitHub's auto-generated source ZIP when the release
        // carries no dedicated build asset.
        if ($downloadUrl === null) {
            $downloadUrl = ((string) ($release['zipball_url'] ?? '')) ?: null;
        }

        return new ReleaseInfo(
            version: $version,
            name: (string) ($release['name'] ?: $tag),
            notes: (string) ($release['body'] ?? ''),
            url: (string) ($release['html_url'] ?? ''),
            publishedAt: (string) ($release['published_at'] ?? $release['created_at'] ?? ''),
            prerelease: (bool) ($release['prerelease'] ?? false),
            downloadUrl: $downloadUrl,
            downloadSize: $downloadSize,
        );
    }

    /**
     * Security Hardening "Update Validation" — "Verify source repository"
     * and "Validate release metadata": confirm the response actually
     * describes a real release of *this* repository, not just any
     * JSON object that happens to have the fields normalise() reads.
     *
     * The request URL is already built exclusively from the fixed
     * $this->owner/$this->repo (see class docblock), so this can't
     * actually be fooled into fetching a different repository's data —
     * this is defence in depth against a malformed, truncated, or
     * otherwise unexpected response body reaching this far, since
     * every field normalise() reads is read with a `?? null`/`?? ''`
     * fallback and would otherwise silently produce a ReleaseInfo built
     * from missing data instead of a clear error.
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
     * Security Hardening "Update Validation" — "Validate version numbers":
     * strip an optional "v"/"V" prefix and require the *entire* remaining
     * string to be a clean, fully-anchored version number — 1–4 numeric
     * segments plus an optional semver-style pre-release suffix
     * (`-beta.1`, `-rc2`, etc). Anchored on both ends (unlike a simple
     * prefix match) so a tag like "2.2.0/../../evil" is rejected outright
     * rather than silently truncated to "2.2.0" — this version string
     * ends up in backup filenames and log messages, so it must never
     * carry a path separator, whitespace, or other unexpected character.
     *
     * @throws \RuntimeException if the tag isn't a recognisable, safe
     *         version number.
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
     * Fetch and JSON-decode a GitHub API URL.
     *
     * @throws \RuntimeException on transport failure, a non-2xx status
     *         (with a specific message for rate limiting and 404s), or
     *         invalid JSON in the response body.
     */
    private function request(string $url): mixed
    {
        if (!str_starts_with($url, self::API_BASE . '/')) {
            // Defence in depth — every URL passed in is already built from
            // self::API_BASE above, but never send a request anywhere else.
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
