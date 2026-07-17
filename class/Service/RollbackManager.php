<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — RollbackManager
 *
 * Restores a previously created BackupManager archive over the live
 * installation. Part of the Admin Update Manager's Rollback System (see
 * TODO.md) — runs entirely in PHP with no shell/Composer/Git dependency,
 * per the shared-hosting assumptions in TODO.md's "Update System
 * Assumptions".
 *
 * rollbackTo() does, in order:
 *  1. Validate the backup archive exists and passes ZIP integrity
 *     validation (ZipArchive::CHECKCONS).
 *  2. Enable maintenance mode (the same flag file InstallManager uses —
 *     see its class docblock for why this is a simple flag rather than a
 *     full public-facing takeover).
 *  3. Extract it into a scratch staging directory, validating every entry
 *     name against Zip Slip / absolute-path / traversal attacks first.
 *     Unlike InstallManager there is no wrapping-root-folder detection to
 *     do — BackupManager always writes paths relative to the install
 *     directory's own top level, with no extra wrapper.
 *  4. Copy every file back over the live installation, skipping the same
 *     preserved paths InstallManager does (uploads/, blog-config.php,
 *     fu-update-tmp/, fu-backups/, .git/, data/) — a backup never
 *     contains these anyway (BackupManager excludes them when creating
 *     one), but the check stays as defence in depth.
 *  5. Reset OPcache, then disable maintenance mode and delete the staging
 *     directory (always, even on failure, via a `finally` block).
 *
 * This intentionally duplicates a good part of InstallManager's
 * extraction/validation/copy/maintenance-mode logic rather than sharing a
 * base class — each Zip-handling service in this codebase (DownloadManager,
 * BackupManager, InstallManager) already owns its own small ZIP-integrity
 * and path-safety helpers rather than factoring out a shared one, and a
 * restore-from-backup operation is different enough from an install
 * (no wrapper-folder detection, different structural guarantees about
 * what the archive contains) that sharing a base class would trade a
 * small amount of duplication for a more tangled inheritance relationship.
 *
 * After a successful restore, this also overwrites InstallManager's file
 * manifest (InstallManager::MANIFEST_FILENAME) with the restored file
 * list — see InstallManager's class docblock for the full "Remove
 * obsolete files" rationale. Without this, a rollback followed by a
 * later install could leave the manifest describing a version that's no
 * longer actually on disk, which wouldn't cause anything unsafe (it can
 * only make a later obsolete-file check under-delete a stale leftover,
 * never delete something it shouldn't), but is worth avoiding anyway.
 * RollbackManager itself does not remove obsolete files — that's scoped
 * to InstallManager only, since "restore exactly what was backed up" is
 * rollback's whole point, not "also clean up anything not in it".
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class RollbackManager
{
    private const REQUIRED_FILES = ['version.php', 'index.php'];
    private const REQUIRED_DIRS  = ['admin', 'class'];

    private const DEFAULT_PRESERVE = [
        'uploads',
        'fu-update-tmp',
        'fu-backups',
        '.git',
        'data',
        'blog-config.php',
    ];

    /** @var array<int, string> */
    private array $preserve;

    /**
     * @param string $installDir The live installation directory to restore into.
     * @param string $stagingDir Scratch directory the backup is extracted
     *        into; created (recursively) on first use and deleted again
     *        once the rollback completes (successfully or not).
     * @param string $maintenanceFlagPath Path to the maintenance-mode flag
     *        file, typically "{install_path}/.fu-maintenance" — the same
     *        one InstallManager uses.
     * @param array<int, string>|null $preserve Top-level names under
     *        $installDir that are never overwritten. Defaults to
     *        self::DEFAULT_PRESERVE.
     */
    public function __construct(
        private readonly string $installDir,
        private readonly string $stagingDir,
        private readonly string $maintenanceFlagPath,
        ?array $preserve = null,
    ) {
        $this->preserve = $preserve ?? self::DEFAULT_PRESERVE;
    }

    /**
     * Restore a backup archive (as produced by BackupManager::createBackup())
     * over the live installation.
     *
     * @param callable(string, int, int): void|null $onProgress Called at
     *        each phase boundary as (message, stepNumber, totalSteps) —
     *        same contract as InstallManager::installUpdate()'s.
     * @throws \RuntimeException on a missing/invalid archive, an unsafe
     *         entry name, an archive that doesn't look like a FanUpdate
     *         Redux installation, or an I/O failure while copying.
     */
    public function rollbackTo(string $backupPath, string $version, ?callable $onProgress = null): RollbackResult
    {
        if (!is_file($backupPath)) {
            throw new \RuntimeException('The backup archive could not be found.');
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to restore a backup.');
        }

        $totalSteps = 7;

        $this->notifyProgress($onProgress, 'Validating backup integrity', 1, $totalSteps);
        if (!$this->isValidZip($backupPath)) {
            throw new \RuntimeException('The backup archive failed integrity validation and will not be restored.');
        }

        $this->notifyProgress($onProgress, 'Enabling maintenance mode', 2, $totalSteps);
        $this->enableMaintenanceMode();

        try {
            $this->notifyProgress($onProgress, 'Preparing staging directory', 3, $totalSteps);
            $this->resetStagingDir();

            $this->notifyProgress($onProgress, 'Extracting backup archive', 4, $totalSteps);
            $this->extractAndValidate($backupPath);

            $this->notifyProgress($onProgress, 'Copying files to the live installation', 5, $totalSteps);
            $manifest  = $this->installFiles();
            $fileCount = count($manifest);
            $this->saveManifest($manifest);

            $this->notifyProgress($onProgress, 'Clearing the object cache', 6, $totalSteps);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return new RollbackResult(
                backupPath: $backupPath,
                version: $version,
                fileCount: $fileCount,
                restoredAt: gmdate('c'),
            );
        } finally {
            $this->notifyProgress($onProgress, 'Disabling maintenance mode', 7, $totalSteps);
            $this->removeTree($this->stagingDir);
            $this->disableMaintenanceMode();
        }
    }

    /** Invoke $onProgress if one was given — a nullable callable can't be called via the `?->` operator, so this is the null check instead. */
    private function notifyProgress(?callable $onProgress, string $message, int $step, int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($message, $step, $total);
        }
    }

    // ── maintenance mode ─────────────────────────────────────────────────────
    // Same flag file InstallManager uses; see that class's docblock for why
    // this is a simple flag rather than a full public-facing takeover.

    public function enableMaintenanceMode(): void
    {
        @file_put_contents($this->maintenanceFlagPath, gmdate('c'));
    }

    public function disableMaintenanceMode(): void
    {
        if (is_file($this->maintenanceFlagPath)) {
            @unlink($this->maintenanceFlagPath);
        }
    }

    public function isMaintenanceModeEnabled(): bool
    {
        return is_file($this->maintenanceFlagPath);
    }

    // ── extraction ────────────────────────────────────────────────────────────

    private function resetStagingDir(): void
    {
        $this->removeTree($this->stagingDir);

        if (!mkdir($this->stagingDir, 0755, true) && !is_dir($this->stagingDir)) {
            throw new \RuntimeException('Could not create the rollback staging directory.');
        }
    }

    /**
     * Extract $backupPath into $this->stagingDir, validating every entry
     * name first, then confirm the result looks like a real installation.
     *
     * @throws \RuntimeException on an unsafe entry name, an unopenable
     *         archive, or a tree that doesn't look like a FanUpdate Redux
     *         installation.
     */
    private function extractAndValidate(string $backupPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($backupPath) !== true) {
            throw new \RuntimeException('The backup archive could not be opened.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $this->assertSafeEntryName($name);
        }

        if (!$zip->extractTo($this->stagingDir)) {
            $zip->close();
            throw new \RuntimeException('Could not extract the backup archive.');
        }
        $zip->close();

        foreach (self::REQUIRED_FILES as $file) {
            if (!is_file($this->stagingDir . '/' . $file)) {
                throw new \RuntimeException('The backup archive does not look like a valid FanUpdate Redux installation (missing ' . $file . ').');
            }
        }

        foreach (self::REQUIRED_DIRS as $dir) {
            if (!is_dir($this->stagingDir . '/' . $dir)) {
                throw new \RuntimeException('The backup archive does not look like a valid FanUpdate Redux installation (missing ' . $dir . '/ directory).');
            }
        }
    }

    /**
     * Reject Zip Slip / absolute-path / directory-traversal entry names
     * before anything is extracted.
     */
    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new \RuntimeException('The backup archive contains an unsafe absolute path: ' . $name);
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
            throw new \RuntimeException('The backup archive contains an unsafe absolute path: ' . $name);
        }

        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException('The backup archive contains a directory traversal entry: ' . $name);
            }
        }
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

    // ── file installation ────────────────────────────────────────────────────

    /**
     * Copy every file from the staging directory into $this->installDir,
     * skipping preserved paths.
     *
     * @return array<int, string> Relative path of every file copied —
     *         becomes the new InstallManager file manifest.
     */
    private function installFiles(): array
    {
        $sourceRoot = rtrim($this->stagingDir, '/');
        $manifest   = [];

        $directory = new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS);
        $iterator  = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = ltrim(substr($item->getPathname(), strlen($sourceRoot)), '/');
            if ($relative === '' || $this->isPreserved($relative)) {
                continue;
            }

            $destination = $this->installDir . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0755, true);
                }
                continue;
            }

            $destDir = dirname($destination);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            if (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException('Could not write file during rollback: ' . $relative);
            }

            $manifest[] = $relative;
        }

        return $manifest;
    }

    /**
     * Overwrite InstallManager's file manifest with the file list this
     * restore just wrote — see the class docblock for why.
     *
     * @param array<int, string> $manifest
     */
    private function saveManifest(array $manifest): void
    {
        @file_put_contents(
            $this->installDir . '/' . InstallManager::MANIFEST_FILENAME,
            json_encode(array_values($manifest), JSON_PRETTY_PRINT)
        );
    }

    /** True when $relativePath falls under one of the preserved top-level names. */
    private function isPreserved(string $relativePath): bool
    {
        $firstSegment = explode('/', $relativePath)[0];

        return in_array($firstSegment, $this->preserve, true);
    }

    // ── filesystem helper ────────────────────────────────────────────────────

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
