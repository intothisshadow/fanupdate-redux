<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — SystemStatusChecker
 *
 * Runs a set of environment diagnostics relevant to the Admin Update
 * Manager (see TODO.md, "System Status") — the shared-hosting assumptions
 * used throughout this feature (no shell access, uncertain cURL/ZIP
 * availability, uncertain disk space) mean an install can fail for
 * environment reasons that have nothing to do with the release itself;
 * this gives the admin a place to check for those before (or after)
 * something goes wrong, rather than only finding out via a failed install.
 *
 * Each check returns a SystemStatusCheck with a status of 'pass',
 * 'warning', or 'error':
 *  - 'error' means the corresponding Admin Update Manager feature will not
 *    work at all (e.g. no ZIP extension: backups/installs/rollbacks are
 *    all impossible).
 *  - 'warning' means a fallback exists but isn't ideal (e.g. no cURL:
 *    GitHubUpdateProvider/DownloadManager both fall back to
 *    file_get_contents() with a stream context, which works but is
 *    slightly slower and depends on allow_url_fopen).
 *  - 'pass' means the check found nothing to flag.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class SystemStatusChecker
{
    private const MIN_PHP_VERSION = '8.2.0';

    private const DISK_SPACE_ERROR_BYTES   = 50 * 1024 * 1024;
    private const DISK_SPACE_WARNING_BYTES = 200 * 1024 * 1024;

    /**
     * @param string $installPath The live installation directory —
     *        checked for writability and used as the disk-space probe
     *        location (same volume the install/backup directories live on).
     * @param string $updateTmpDir The update staging directory
     *        (`{install_path}/fu-update-tmp/`) — checked for existence and
     *        writability directly, since a failure here blocks every
     *        Admin Update Manager feature (download, backup staging,
     *        install extraction, rollback extraction all use it).
     */
    public function __construct(
        private readonly string $installPath,
        private readonly string $updateTmpDir,
    ) {
    }

    /** @return array<int, SystemStatusCheck> */
    public function runChecks(): array
    {
        return [
            $this->checkPhpVersion(),
            $this->checkZipExtension(),
            $this->checkCurlAvailability(),
            $this->checkFilePermissions(),
            $this->checkDiskSpace(),
            $this->checkTempDirectoryWritable(),
        ];
    }

    private function checkPhpVersion(): SystemStatusCheck
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=')) {
            return new SystemStatusCheck(
                'PHP version',
                'pass',
                'Running PHP ' . PHP_VERSION . ' (minimum ' . self::MIN_PHP_VERSION . ').'
            );
        }

        return new SystemStatusCheck(
            'PHP version',
            'error',
            'Running PHP ' . PHP_VERSION . ', but FanUpdate Redux requires at least ' . self::MIN_PHP_VERSION . '.'
        );
    }

    private function checkZipExtension(): SystemStatusCheck
    {
        if (class_exists(\ZipArchive::class)) {
            return new SystemStatusCheck('ZIP extension', 'pass', 'The PHP zip extension is loaded.');
        }

        return new SystemStatusCheck(
            'ZIP extension',
            'error',
            'The PHP zip extension is not loaded — creating backups, installing updates, and rolling back all require it.'
        );
    }

    private function checkCurlAvailability(): SystemStatusCheck
    {
        if (function_exists('curl_init')) {
            return new SystemStatusCheck('cURL availability', 'pass', 'The cURL extension is loaded.');
        }

        return new SystemStatusCheck(
            'cURL availability',
            'warning',
            'The cURL extension is not loaded — update checks and downloads fall back to a stream-context request, which works but is slightly slower and depends on allow_url_fopen.'
        );
    }

    private function checkFilePermissions(): SystemStatusCheck
    {
        if (is_writable($this->installPath)) {
            return new SystemStatusCheck('File permissions', 'pass', 'The installation directory is writable.');
        }

        return new SystemStatusCheck(
            'File permissions',
            'error',
            'The installation directory (' . $this->installPath . ') is not writable — installs and rollbacks cannot write files here.'
        );
    }

    private function checkDiskSpace(): SystemStatusCheck
    {
        $free = @disk_free_space($this->installPath);

        if ($free === false) {
            return new SystemStatusCheck(
                'Available disk space',
                'warning',
                'Could not determine free disk space on this host.'
            );
        }

        $free = (int) $free;

        if ($free < self::DISK_SPACE_ERROR_BYTES) {
            return new SystemStatusCheck(
                'Available disk space',
                'error',
                'Only ' . self::formatBytes($free) . ' free — likely too little to safely create a backup or install an update.'
            );
        }

        if ($free < self::DISK_SPACE_WARNING_BYTES) {
            return new SystemStatusCheck(
                'Available disk space',
                'warning',
                self::formatBytes($free) . ' free — may be tight for a larger release or backup.'
            );
        }

        return new SystemStatusCheck('Available disk space', 'pass', self::formatBytes($free) . ' free.');
    }

    private function checkTempDirectoryWritable(): SystemStatusCheck
    {
        if (is_dir($this->updateTmpDir) && is_writable($this->updateTmpDir)) {
            return new SystemStatusCheck('Temporary directory', 'pass', 'The update staging directory is writable.');
        }

        return new SystemStatusCheck(
            'Temporary directory',
            'error',
            'The update staging directory (' . $this->updateTmpDir . ') could not be created or is not writable — downloads, backups, installs, and rollbacks all need it.'
        );
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        $size  = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
