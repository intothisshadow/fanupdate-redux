<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — BackupManager
 *
 * Creates a restorable ZIP snapshot of the current installation before an
 * update is installed. Part of the Admin Update Manager's Installation
 * Engine "Preparation" phase (create backup, validate backup success,
 * verify free disk space — see TODO.md); the Rollback System (not yet
 * built) will restore from a BackupManager archive on a failed install.
 *
 * Runs entirely in PHP with no shell/Composer/Git dependency, per the
 * shared-hosting assumptions in TODO.md's "Update System Assumptions" —
 * archives are built with ZipArchive, not a `zip` binary.
 *
 * Scope: only the application's own code and configuration are backed up
 * (everything under the install directory except uploads/, the update
 * staging directory, the backups directory itself, and .git/). User
 * uploads are never touched by an update install (the release ZIP doesn't
 * contain them), so backing them up before every update would just be
 * slow and wasteful on installs with tens of thousands of images — see
 * CLAUDE.md's Performance section. This does mean a restore only covers
 * application state, not uploaded content; that's intentional.
 *
 * Security: the backup directory contains a full copy of blog-config.php
 * (database credentials included), so it is guarded exactly like
 * fu-update-tmp/ — .htaccess-denied (not just script-execution-blocked)
 * and index.html-guarded. Backups are retained up to self::RETENTION_COUNT
 * (oldest deleted automatically on each new backup) so a shared-hosting
 * disk quota isn't silently exhausted by an unbounded backup history.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class BackupManager
{
    private const RETENTION_COUNT      = 3;
    private const DEFAULT_EXCLUDE_DIRS = ['uploads', 'fu-update-tmp', 'fu-backups', '.git'];

    /** @var array<int, string> */
    private array $excludeDirs;

    /**
     * @param string $sourceDir The installation directory to back up
     *        (typically the `install_path` option).
     * @param string $backupDir Directory backup ZIPs are written to and
     *        listed from; created (recursively) on first use.
     * @param array<int, string>|null $excludeDirs Directory names (not
     *        paths) to exclude from the backup, relative to $sourceDir's
     *        top level. Defaults to self::DEFAULT_EXCLUDE_DIRS.
     */
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $backupDir,
        ?array $excludeDirs = null,
    ) {
        $this->excludeDirs = $excludeDirs ?? self::DEFAULT_EXCLUDE_DIRS;
    }

    /**
     * Create a new backup archive of $sourceDir and enforce retention.
     *
     * @throws \RuntimeException when the backup directory can't be
     *         created, there isn't enough free disk space, or the
     *         resulting archive fails integrity validation.
     */
    public function createBackup(string $version): BackupResult
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to create backups.');
        }

        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException('Could not create the backup directory.');
        }

        $estimatedSize = $this->estimateSourceSize();
        $this->assertSufficientDiskSpace($estimatedSize);

        $safeVersion = (string) preg_replace('/[^-_.0-9a-zA-Z]/', '', $version);
        $timestamp   = gmdate('Ymd-His');
        $filePath    = $this->backupDir . '/backup-' . $safeVersion . '-' . $timestamp . '.zip';

        $fileCount = $this->writeArchive($filePath);

        if ($fileCount === 0) {
            $this->deleteFile($filePath);
            throw new \RuntimeException($this->describeEmptyBackupFailure());
        }

        if (!$this->isValidZip($filePath)) {
            $this->deleteFile($filePath);
            throw new \RuntimeException('The backup archive failed integrity validation.');
        }

        $size = filesize($filePath);
        if ($size === false) {
            $this->deleteFile($filePath);
            throw new \RuntimeException('Could not verify the backup file size.');
        }

        $this->enforceRetention();

        return new BackupResult(
            filePath: $filePath,
            size: $size,
            fileCount: $fileCount,
            createdAt: gmdate('c'),
            version: $version,
        );
    }

    /**
     * List existing backups, newest first. Read from the filesystem only
     * (filename + mtime + size) — archives are not re-opened, so this is
     * cheap to call on every page load.
     *
     * @return array<int, BackupResult>
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob($this->backupDir . '/backup-*.zip') ?: [];
        $backups = [];

        foreach ($files as $file) {
            $mtime = filemtime($file);
            $size  = filesize($file);

            if ($mtime === false || $size === false) {
                continue;
            }

            $version = 'unknown';
            if (preg_match('/^backup-(.+)-\d{8}-\d{6}\.zip$/', basename($file), $m)) {
                $version = $m[1];
            }

            $backups[] = new BackupResult(
                filePath: $file,
                size: $size,
                fileCount: null,
                createdAt: gmdate('c', $mtime),
                version: $version,
            );
        }

        usort($backups, static fn (BackupResult $a, BackupResult $b): int => strcmp($b->createdAt, $a->createdAt));

        return $backups;
    }

    /**
     * Diagnose why writeArchive() returned 0 files, for createBackup()'s
     * exception message. Distinguishes three genuinely different causes
     * that all previously collapsed into the same generic "empty archive"
     * message, each needing a different fix:
     *  - $sourceDir doesn't exist/isn't a directory at all (bad
     *    `install_path` option).
     *  - $sourceDir exists but has literally nothing in it (install_path
     *    resolved to a real but wrong/empty directory).
     *  - $sourceDir has entries, but every one of them is an excluded
     *    directory name (uploads/fu-update-tmp/fu-backups/.git) — i.e.
     *    there's nothing left to back up after exclusions, which would be
     *    a very unusual real installation.
     * Also serves as a live marker that this fixed code is actually the
     * code executing — the wording differs per case, so a report of the
     * exact old generic sentence coming back again means this method
     * itself never ran (stale OPcache / an old file still being served),
     * not a fourth, undiagnosed cause.
     */
    private function describeEmptyBackupFailure(): string
    {
        $sourceBase = $this->normalizedSourceDir();

        if ($sourceBase === null) {
            return sprintf(
                'Backup produced an empty archive: the configured source directory "%s" does not exist or is not readable as a directory. Check the install_path option.',
                $this->sourceDir
            );
        }

        $entries = @scandir($sourceBase);
        if ($entries === false) {
            return sprintf(
                'Backup produced an empty archive: could not list the contents of "%s" (scandir failed) — check file permissions.',
                $sourceBase
            );
        }

        $topLevel = array_values(array_diff($entries, ['.', '..']));

        if ($topLevel === []) {
            return sprintf(
                'Backup produced an empty archive: the source directory "%s" exists but is completely empty. install_path is likely pointing at the wrong directory — check it against where this application\'s files actually live on this server.',
                $sourceBase
            );
        }

        return sprintf(
            'Backup produced an empty archive: found %d top-level item(s) under "%s" (%s), but every one is an excluded directory (%s) or otherwise contains no files.',
            count($topLevel),
            $sourceBase,
            implode(', ', $topLevel),
            implode(', ', $this->excludeDirs)
        );
    }

    /** Delete a single backup file. Silently no-ops if it doesn't exist or isn't under $backupDir. */
    public function deleteBackup(string $filePath): void
    {
        $realBackupDir = realpath($this->backupDir);
        $realFilePath  = realpath($filePath);

        if ($realBackupDir === false || $realFilePath === false) {
            return;
        }

        // Defence in depth against a path-traversal filePath — only ever
        // delete a file that resolves to actually being inside $backupDir.
        if (!str_starts_with($realFilePath, $realBackupDir . DIRECTORY_SEPARATOR)) {
            return;
        }

        $this->deleteFile($realFilePath);
    }

    /** Delete backups beyond self::RETENTION_COUNT, oldest first. */
    private function enforceRetention(): void
    {
        $backups = $this->listBackups();

        if (count($backups) <= self::RETENTION_COUNT) {
            return;
        }

        $toDelete = array_slice($backups, self::RETENTION_COUNT);

        foreach ($toDelete as $backup) {
            $this->deleteFile($backup->filePath);
        }
    }

    private function deleteFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Sum the size of every file under $sourceDir that isn't excluded. */
    private function estimateSourceSize(): int
    {
        $total = 0;

        foreach ($this->walkSourceFiles() as $absolutePath) {
            $size = @filesize($absolutePath);
            if ($size !== false) {
                $total += $size;
            }
        }

        return $total;
    }

    /**
     * Build the backup archive, returning the number of files written.
     * Every file under $sourceDir not inside an excluded directory is
     * added, using its path relative to $sourceDir as the in-archive name.
     */
    private function writeArchive(string $destPath): int
    {
        $zip = new \ZipArchive();

        if ($zip->open($destPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the backup archive file.');
        }

        $fileCount   = 0;
        $walkedCount = 0;
        $sourceBase  = $this->normalizedSourceDir() ?? '';

        foreach ($this->walkSourceFiles() as $absolutePath) {
            $walkedCount++;
            $relative = ltrim(substr($absolutePath, strlen($sourceBase)), '/');
            if ($relative === '') {
                continue;
            }

            if ($zip->addFile($absolutePath, $relative)) {
                $fileCount++;
            }
        }

        $zip->close();

        if ($fileCount === 0 && $walkedCount > 0) {
            // Files were found under $sourceDir but ZipArchive::addFile()
            // rejected every single one — this is not "nothing to back up"
            // (createBackup()'s generic empty-archive message below covers
            // that case), it's the zip extension refusing to read files it
            // was handed. In practice this almost always means an
            // open_basedir restriction is blocking access — most commonly
            // triggered by resolving a symlinked install path with
            // realpath() into a target outside the configured
            // open_basedir, which is why $sourceDir is used here exactly
            // as configured rather than realpath()'d — or a file-permission
            // problem introduced by a prior file operation.
            throw new \RuntimeException(sprintf(
                'Found %d file(s) under %s but the zip extension could not read any of them '
                . '— check open_basedir and file permissions on this server.',
                $walkedCount,
                $sourceBase
            ));
        }

        return $fileCount;
    }

    /**
     * $sourceDir as configured, trailing slash normalised — deliberately
     * NOT realpath()'d. Resolving a symlinked install path with realpath()
     * can point outside a host's open_basedir restriction even though the
     * symlink path itself is inside it, which silently breaks every
     * ZipArchive::addFile() call in writeArchive().
     */
    private function normalizedSourceDir(): ?string
    {
        $dir = rtrim($this->sourceDir, '/');
        return ($dir !== '' && is_dir($dir)) ? $dir : null;
    }

    /**
     * Yield the absolute path of every file under $sourceDir, skipping
     * excluded top-level directories entirely (not just their contents —
     * RecursiveDirectoryIterator never descends into them).
     *
     * @return \Generator<int, string>
     */
    private function walkSourceFiles(): \Generator
    {
        $sourceBase = $this->normalizedSourceDir();
        if ($sourceBase === null) {
            return;
        }

        $excluded = array_map(
            static fn (string $name): string => $sourceBase . DIRECTORY_SEPARATOR . $name,
            $this->excludeDirs
        );

        $directory = new \RecursiveDirectoryIterator($sourceBase, \FilesystemIterator::SKIP_DOTS);

        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $current) use ($excluded): bool {
                if ($current->isDir() && in_array($current->getPathname(), $excluded, true)) {
                    return false;
                }
                return true;
            }
        );

        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * @throws \RuntimeException when free disk space is known and
     *         insufficient. Silently allows the backup to proceed when
     *         disk_free_space() can't determine a value (some hosts
     *         disable it) — better to attempt the backup than to block
     *         it on an inconclusive check.
     */
    private function assertSufficientDiskSpace(int $estimatedBytes): void
    {
        $free = @disk_free_space($this->backupDir);
        if ($free === false) {
            return;
        }

        // 20% margin for ZIP overhead variance plus a flat 10MB buffer.
        $required = (int) ($estimatedBytes * 1.2) + 10 * 1024 * 1024;

        if ($free < $required) {
            throw new \RuntimeException(sprintf(
                'Not enough free disk space for a backup: approximately %s required, %s available.',
                $this->formatBytes($required),
                $this->formatBytes((int) $free)
            ));
        }
    }

    private function formatBytes(int $bytes): string
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

    private function isValidZip(string $path): bool
    {
        $zip    = new \ZipArchive();
        $result = $zip->open($path, \ZipArchive::CHECKCONS);

        if ($result !== true) {
            return false;
        }

        $ok = $zip->numFiles > 0;
        $zip->close();

        return $ok;
    }
}
