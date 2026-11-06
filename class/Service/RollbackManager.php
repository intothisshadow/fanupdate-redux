<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — RollbackManager
 *
 * Restores a BackupManager archive over the live installation, in pure PHP.
 * rollbackTo() runs, in order:
 *  1. Validate the archive exists and passes ZipArchive::CHECKCONS.
 *  2. Enable maintenance mode (same flag file as InstallManager).
 *  3. Extract to staging, rejecting Zip Slip / absolute / traversal names.
 *     Backups have no wrapper folder, so there is no root detection.
 *  4. Copy files back, skipping InstallManager's preserved paths (defence in
 *     depth; backups never contain them).
 *  5. Reset OPcache, disable maintenance mode, delete staging (always).
 *
 * This deliberately duplicates some of InstallManager's logic: each ZIP
 * service owns its own small integrity/path helpers, and restore differs
 * enough from install that a shared base class would add more coupling than
 * it saves.
 *
 * After a restore the InstallManager manifest is overwritten with the
 * restored file list, so a later install doesn't compare against a version
 * no longer on disk. Rollback never removes obsolete files; it restores
 * exactly what was backed up.
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
     * @param string $installDir Live installation directory to restore into.
     * @param string $stagingDir Scratch extraction directory; deleted after every rollback.
     * @param string $maintenanceFlagPath Maintenance flag file (shared with InstallManager).
     * @param array<int, string>|null $preserve Top-level names never overwritten
     *        (defaults to DEFAULT_PRESERVE).
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
     * Restores a BackupManager archive over the live installation.
     *
     * @param callable(string, int, int): void|null $onProgress Same contract as
     *        InstallManager::installUpdate().
     * @throws \RuntimeException on a bad archive, unsafe entry name, non-installation tree, or I/O failure.
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

    /** Calls $onProgress if given (a nullable callable can't use `?->`). */
    private function notifyProgress(?callable $onProgress, string $message, int $step, int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($message, $step, $total);
        }
    }

    // ── maintenance mode ─────────────────────────────────────────────────────
    // Same flag file as InstallManager.

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
     * Validates entry names, extracts into staging, and checks the result
     * looks like an installation.
     *
     * @throws \RuntimeException on an unsafe name, unopenable archive, or non-installation tree.
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

    /** Rejects Zip Slip, absolute-path and traversal entry names. */
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
     * Copies every non-preserved file from staging into the install.
     *
     * @return array<int, string> Relative paths copied (the new manifest).
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
     * Overwrites InstallManager's manifest with the restored file list.
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
