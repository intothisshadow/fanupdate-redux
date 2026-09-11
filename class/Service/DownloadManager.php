<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — DownloadManager
 *
 * Downloads a release's ZIP asset over HTTPS into a temporary directory,
 * validates it, and reports the result as a DownloadResult. Part of the
 * Admin Update Manager's Phase 4 (Download Manager) — see TODO.md.
 * acceptUploadedFile() (FP-33) covers the same "get a validated release ZIP
 * into the temp directory" job for an administrator-uploaded file instead
 * of a provider download — everything downstream (InstallManager, the
 * DownloadResult shape) treats the two identically.
 *
 * Runs entirely in PHP with no shell/Composer/Git dependency, per the
 * shared-hosting assumptions in TODO.md's "Update System Assumptions". Uses
 * cURL when available (CURLOPT_FILE streams straight to disk, so memory use
 * stays flat regardless of archive size) and falls back to a stream-context
 * copy when it isn't — the same dual-transport approach as
 * GitHubUpdateProvider.
 *
 * Security:
 *  - HTTPS only, both for the initial URL and every redirect hop.
 *  - The host of the initial URL and of every redirect target must appear
 *    in the caller-supplied $allowedHosts allow-list (see
 *    UpdateProviderInterface::getAllowedDownloadHosts()) — this is what
 *    stops a release payload from redirecting the download to an
 *    attacker-controlled host.
 *  - Redirects are followed manually (CURLOPT_FOLLOWLOCATION is left off,
 *    and the stream fallback disables follow_location) so each hop's host
 *    can be checked before it is followed, capped at self::MAX_REDIRECTS.
 *  - The downloaded file is written under a caller-supplied temp directory
 *    and validated (size against the provider's reported size, then ZIP
 *    integrity) before download() returns successfully. Nothing here
 *    extracts or executes the archive — that is the Installation Engine's
 *    job (see TODO.md, not yet built).
 *
 * The network transport is isolated behind $transport (a callable) so it
 * can be swapped for a fake in unit tests without making real HTTP
 * requests — the validation logic (host allow-list, size check, ZIP
 * integrity) is what is actually under test.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class DownloadManager
{
    private const MAX_REDIRECTS   = 5;
    private const TIMEOUT_SECONDS = 300; // large archives on slow shared hosting

    /** @var callable(string, string, array<int, string>): int */
    private $transport;

    /**
     * @param string $tempDir Directory the ZIP is downloaded into; created
     *        (recursively) on first use if it does not already exist.
     * @param null|callable(string, string, array<int, string>): int $transport
     *        Network transport override for testing. Defaults to the real
     *        cURL/stream implementation.
     */
    public function __construct(
        private readonly string $tempDir,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? $this->defaultTransport(...);
    }

    /**
     * Download and validate a release's ZIP asset.
     *
     * @param array<int, string> $allowedHosts Hostnames the download URL
     *        (and any redirect it follows) is allowed to resolve to.
     * @throws \RuntimeException on a missing download URL, a disallowed
     *         host, a transport failure, a size mismatch, or a corrupt/
     *         non-ZIP archive.
     */
    public function download(ReleaseInfo $release, array $allowedHosts): DownloadResult
    {
        if ($release->downloadUrl === null || $release->downloadUrl === '') {
            throw new \RuntimeException('This release has no downloadable ZIP asset.');
        }

        $this->assertHttpsAndAllowedHost($release->downloadUrl, $allowedHosts);

        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0755, true) && !is_dir($this->tempDir)) {
            throw new \RuntimeException('Could not create the update temporary directory.');
        }

        $safeVersion = (string) preg_replace('/[^-_.0-9a-zA-Z]/', '', $release->version);
        $destPath    = $this->tempDir . '/release-' . $safeVersion . '-' . bin2hex(random_bytes(4)) . '.zip';

        $bytesWritten = ($this->transport)($release->downloadUrl, $destPath, $allowedHosts);

        if (!is_file($destPath) || $bytesWritten <= 0) {
            $this->cleanup($destPath);
            throw new \RuntimeException('Download failed: no data was received.');
        }

        $actualSize = filesize($destPath);
        if ($actualSize === false) {
            $this->cleanup($destPath);
            throw new \RuntimeException('Could not verify the downloaded file size.');
        }

        // Validate against the size the provider reported, when known. A
        // small tolerance absorbs the odd byte of re-chunking some shared-
        // hosting proxies/CDNs introduce, without masking a genuinely
        // truncated or substituted download.
        if ($release->downloadSize !== null && $release->downloadSize > 0) {
            $tolerance = max(1024, (int) ($release->downloadSize * 0.01));
            if (abs($actualSize - $release->downloadSize) > $tolerance) {
                $this->cleanup($destPath);
                throw new \RuntimeException(sprintf(
                    'Downloaded file size (%d bytes) does not match the expected size (%d bytes).',
                    $actualSize,
                    $release->downloadSize
                ));
            }
        }

        if (!$this->isValidZip($destPath)) {
            $this->cleanup($destPath);
            throw new \RuntimeException('The downloaded file is not a valid ZIP archive.');
        }

        $actualSha256 = hash_file('sha256', $destPath) ?: '';

        // FP-17: verify against the release's published checksum, when it
        // has one. Fail closed on a mismatch — the same pattern as the size
        // check above — since a downloaded ZIP that doesn't match what the
        // release actually published must never be installed. A release
        // with no published checksum (the normal case for anything cut
        // before FP-16 shipped) is not an error; $checksumVerified is simply
        // false and DownloadManager's caller decides how to surface that.
        $checksumVerified = false;
        if ($release->expectedSha256 !== null) {
            if (!hash_equals(strtolower($release->expectedSha256), strtolower($actualSha256))) {
                $this->cleanup($destPath);
                throw new \RuntimeException(
                    'Downloaded file checksum does not match the checksum published for this release. '
                    . 'The download may be corrupted or tampered with — installation was refused.'
                );
            }
            $checksumVerified = true;
        }

        return new DownloadResult(
            filePath: $destPath,
            size: $actualSize,
            sha256: $actualSha256,
            checksumVerified: $checksumVerified,
        );
    }

    /**
     * Accept an administrator-uploaded release ZIP into the managed temp
     * directory — the manual-upload counterpart to download() (FP-33).
     * Moves $uploadedTmpPath into $this->tempDir using the same
     * "release-<version>-<random>.zip" naming download() uses, confirms ZIP
     * integrity via the same isValidZip() check, and computes its SHA-256.
     * There is no HTTPS/host allow-list to check (the file is already local)
     * and no expected size/checksum to verify against (an upload has no
     * provider metadata to compare with) — those are download()'s concerns,
     * not this method's.
     *
     * @throws \RuntimeException if the source file is missing, it can't be
     *         moved into the temp directory, or it isn't a valid ZIP.
     */
    public function acceptUploadedFile(string $uploadedTmpPath, string $version): DownloadResult
    {
        if (!is_file($uploadedTmpPath)) {
            throw new \RuntimeException('The uploaded file could not be found.');
        }

        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0755, true) && !is_dir($this->tempDir)) {
            throw new \RuntimeException('Could not create the update temporary directory.');
        }

        $safeVersion = (string) preg_replace('/[^-_.0-9a-zA-Z]/', '', $version);
        $destPath    = $this->tempDir . '/release-' . $safeVersion . '-' . bin2hex(random_bytes(4)) . '.zip';

        // rename() is preferred (atomic, no double disk I/O) but can fail
        // across filesystem/mount boundaries — PHP's upload tmp dir is not
        // guaranteed to share a filesystem with $this->tempDir on every
        // host. Same copy+unlink fallback InstallManager's own move-like
        // operations use elsewhere in this codebase.
        if (!@rename($uploadedTmpPath, $destPath)) {
            if (!@copy($uploadedTmpPath, $destPath)) {
                throw new \RuntimeException('Could not store the uploaded file. Check permissions on the update temp directory.');
            }
            @unlink($uploadedTmpPath);
        }

        if (!$this->isValidZip($destPath)) {
            $this->cleanup($destPath);
            throw new \RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        $actualSize = filesize($destPath);
        $sha256     = hash_file('sha256', $destPath);

        return new DownloadResult(
            filePath: $destPath,
            size: $actualSize !== false ? $actualSize : 0,
            sha256: $sha256 !== false ? $sha256 : '',
            checksumVerified: false, // no published checksum exists to verify an upload against
        );
    }

    /** Delete a previously downloaded file (e.g. after a failed/abandoned install). */
    public function cleanup(string $filePath): void
    {
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    /** @param array<int, string> $allowedHosts */
    private function assertHttpsAndAllowedHost(string $url, array $allowedHosts): void
    {
        $parts = parse_url($url);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('Refusing to download from a non-HTTPS URL.');
        }

        $allowed = array_map('strtolower', $allowedHosts);
        if (!in_array(strtolower($parts['host']), $allowed, true)) {
            throw new \RuntimeException('Refusing to download from an unrecognised host: ' . $parts['host']);
        }
    }

    private function isValidZip(string $path): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            // ZIP extension unavailable — fall back to a magic-byte check
            // (local file header, or the empty-archive/spanned signatures).
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return false;
            }
            $sig = fread($handle, 4);
            fclose($handle);

            return in_array($sig, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
        }

        $zip    = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::CHECKCONS);

        if ($result !== true) {
            return false;
        }

        $ok = $zip->numFiles > 0;
        $zip->close();

        return $ok;
    }

    /**
     * Default network transport: cURL when available, a stream-context copy
     * otherwise. Redirects are followed manually so every hop's host can be
     * validated before it is followed.
     *
     * @param array<int, string> $allowedHosts
     */
    private function defaultTransport(string $url, string $destPath, array $allowedHosts): int
    {
        return function_exists('curl_init')
            ? $this->downloadViaCurl($url, $destPath, $allowedHosts)
            : $this->downloadViaStream($url, $destPath, $allowedHosts);
    }

    /** @param array<int, string> $allowedHosts */
    private function downloadViaCurl(string $url, string $destPath, array $allowedHosts): int
    {
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $this->assertHttpsAndAllowedHost($url, $allowedHosts);

            $fh = fopen($destPath, 'wb');
            if ($fh === false) {
                throw new \RuntimeException('Could not open the destination file for writing.');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                fclose($fh);
                throw new \RuntimeException('Could not initialise a cURL handle.');
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_HEADER         => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT      => 'FanUpdateRedux-Updater',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $ok       = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
            $error    = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($ok === false) {
                throw new \RuntimeException('Download failed: ' . $error);
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (!is_string($location) || $location === '') {
                    throw new \RuntimeException('Redirect response with no Location header.');
                }
                $url = $location;
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Download failed with HTTP status ' . $status . '.');
            }

            $size = filesize($destPath);

            return $size !== false ? $size : 0;
        }

        throw new \RuntimeException('Too many redirects while downloading the release archive.');
    }

    /** @param array<int, string> $allowedHosts */
    private function downloadViaStream(string $url, string $destPath, array $allowedHosts): int
    {
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $this->assertHttpsAndAllowedHost($url, $allowedHosts);

            $context = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'timeout'         => self::TIMEOUT_SECONDS,
                    'follow_location' => 0,
                    'header'          => 'User-Agent: FanUpdateRedux-Updater',
                    'ignore_errors'   => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $in = @fopen($url, 'rb', false, $context);
            if ($in === false) {
                throw new \RuntimeException('Could not connect to download the release archive.');
            }

            $status = 0;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                    $status = (int) $m[1];
                }
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = null;
                foreach ($http_response_header ?? [] as $header) {
                    if (preg_match('#^Location:\s*(.+)$#i', $header, $m)) {
                        $location = trim($m[1]);
                    }
                }
                fclose($in);

                if ($location === null || $location === '') {
                    throw new \RuntimeException('Redirect response with no Location header.');
                }
                $url = $location;
                continue;
            }

            if ($status < 200 || $status >= 300) {
                fclose($in);
                throw new \RuntimeException('Download failed with HTTP status ' . $status . '.');
            }

            $out = fopen($destPath, 'wb');
            if ($out === false) {
                fclose($in);
                throw new \RuntimeException('Could not open the destination file for writing.');
            }

            $bytes = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            return $bytes !== false ? $bytes : 0;
        }

        throw new \RuntimeException('Too many redirects while downloading the release archive.');
    }
}
