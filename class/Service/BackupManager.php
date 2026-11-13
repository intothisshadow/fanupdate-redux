<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — BackupManager
 *
 * Creates a restorable ZIP snapshot of the installation before an update
 * (pure PHP via ZipArchive, no `zip` binary); RollbackManager restores from it.
 *
 * Scope: application code and configuration only, excluding uploads/, the
 * staging and backup directories and .git/. Updates never touch uploads, and
 * backing up tens of thousands of images each time would be wasteful, so a
 * restore intentionally covers application state only.
 *
 * Security: backups contain blog-config.php (database credentials), so the
 * directory is .htaccess-denied and index.html-guarded like fu-update-tmp/.
 * Only the newest RETENTION_COUNT backups are kept, to protect disk quota.
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
     * @param string $sourceDir Installation directory to back up (the `install_path` option).
     * @param string $backupDir Where backup ZIPs are written; created on first use.
     * @param array<int, string>|null $excludeDirs Top-level directory names to exclude
     *        (defaults to DEFAULT_EXCLUDE_DIRS).
     */
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $backupDir,
        ?array $excludeDirs = null,
    ) {
        $this->excludeDirs = $excludeDirs ?? self::DEFAULT_EXCLUDE_DIRS;
    }

    /**
     * Creates a backup archive of $sourceDir and enforces retention.
     *
     * @throws \RuntimeException on directory, disk-space or archive-integrity failure.
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
     * Lists backups newest first, from filename/mtime/size only (archives
     * aren't opened).
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
     * Explains why writeArchive() wrote 0 files: $sourceDir missing (bad
     * `install_path`), empty, or containing only excluded directories. Each
     * needs a different fix.
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

    /**
     * Resolves a backup by bare filename to its real path, or null. Accepts only
     * names this class creates and files that resolve inside $backupDir, so a
     * request can't name an arbitrary file (archives contain blog-config.php).
     */
    public function locateBackup(string $fileName): ?string
    {
        if ($fileName !== basename($fileName) || !preg_match('/^backup-.+-\d{8}-\d{6}\.zip$/', $fileName)) {
            return null;
        }

        $realBackupDir = realpath($this->backupDir);
        $realFilePath  = realpath($this->backupDir . '/' . $fileName);

        if (
            $realBackupDir === false
            || $realFilePath === false
            || !is_file($realFilePath)
            || !str_starts_with($realFilePath, $realBackupDir . DIRECTORY_SEPARATOR)
        ) {
            return null;
        }

        return $realFilePath;
    }

    /** Filename offered on download, e.g. "FanUpdateRedux-2026-11-03-172441.zip", from the date and time in the stored name. */
    public function downloadFileName(string $storedName): string
    {
        if (preg_match('/-(\d{4})(\d{2})(\d{2})-(\d{6})\.zip$/', $storedName, $m)) {
            return 'FanUpdateRedux-' . $m[1] . '-' . $m[2] . '-' . $m[3] . '-' . $m[4] . '.zip';
        }

        return 'FanUpdateRedux-backup.zip';
    }

    /** Delete a single backup file. Silently no-ops if it doesn't exist or isn't under $backupDir. */
    public function deleteBackup(string $filePath): void
    {
        $realBackupDir = realpath($this->backupDir);
        $realFilePath  = realpath($filePath);

        if ($realBackupDir === false || $realFilePath === false) {
            return;
        }

        // Defence in depth: only delete files that resolve inside $backupDir.
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
     * Builds the archive (paths relative to $sourceDir) and returns the file count.
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
            // Files exist but addFile() rejected all of them: usually an
            // open_basedir restriction (hence no realpath() on $sourceDir) or
            // a file-permission problem.
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
     * $sourceDir as configured, deliberately not realpath()'d: resolving a
     * symlinked install path can land outside open_basedir and silently break
     * every ZipArchive::addFile() call.
     */
    private function normalizedSourceDir(): ?string
    {
        $dir = rtrim($this->sourceDir, '/');
        return ($dir !== '' && is_dir($dir)) ? $dir : null;
    }

    /**
     * Yields every file path under $sourceDir, never descending into excluded
     * top-level directories.
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
     * @throws \RuntimeException when free space is known and insufficient. An
     *         unknown value (disk_free_space() disabled) lets the backup proceed.
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
