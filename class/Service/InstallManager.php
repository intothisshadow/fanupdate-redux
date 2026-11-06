<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — InstallManager
 *
 * Extracts a release ZIP and installs it over the live installation, in
 * pure PHP (no shell/Composer/Git). installUpdate() runs, in order:
 *  1. Enable maintenance mode.
 *  2. Extract into a staging directory, rejecting Zip Slip / absolute /
 *     traversal entry names before anything is written.
 *  3. Strip a single wrapping root directory (GitHub source ZIPs have one).
 *  4. Validate the tree looks like a FanUpdate Redux release.
 *  5. Copy files over the install, skipping preserved paths (uploads,
 *     backups, blog-config.php, ...).
 *  6. Delete install.php: this flow never executes it, so its self-delete
 *     would never run and it would reappear after every update.
 *  7. Remove obsolete files (see below).
 *  8. Reset OPcache.
 *  9. Disable maintenance mode and delete staging (always, via `finally`).
 *
 * Maintenance mode is a flag file ({install_path}/.fu-maintenance), not a
 * page-wide takeover, because the public scripts are embedded in host
 * pages. process.php checks it and rejects comments mid-install.
 *
 * Obsolete-file removal can never delete admin-added files: each install
 * writes a manifest ({install_path}/.fu-manifest.json) of the paths it
 * copied, and only paths in the previous manifest but absent from the new
 * release are removed. With no prior manifest nothing is removed.
 * RollbackManager updates the same manifest after a restore.
 *
 * Database upgrades are separate; see UpgradeRunner.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class InstallManager
{
    private const REQUIRED_FILES = ['version.php', 'index.php'];
    private const REQUIRED_DIRS  = ['admin', 'class'];

    /** Manifest of installed paths (relative to $installDir); shared with RollbackManager. */
    public const MANIFEST_FILENAME = '.fu-manifest.json';

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
     * @param string $installDir Live installation directory.
     * @param string $stagingDir Scratch directory for extraction; deleted after every install.
     * @param string $maintenanceFlagPath Maintenance-mode flag file path.
     * @param array<int, string>|null $preserve Top-level names never overwritten or removed
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
     * Install a downloaded release ZIP over the live installation.
     *
     * @param callable(string, int, int): void|null $onProgress Called at each
     *        phase boundary as (message, step, totalSteps); must not throw.
     * @throws \RuntimeException on any validation or I/O failure. Maintenance
     *         mode is always disabled first.
     */
    public function installUpdate(string $zipPath, string $version, ?callable $onProgress = null): InstallResult
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException('The release archive could not be found.');
        }

        $totalSteps = 8;

        $this->notifyProgress($onProgress, 'Enabling maintenance mode', 1, $totalSteps);
        $this->enableMaintenanceMode();

        try {
            // Must be read before any file is copied.
            $oldManifest = $this->loadManifest();

            $this->notifyProgress($onProgress, 'Preparing staging directory', 2, $totalSteps);
            $this->resetStagingDir();

            $this->notifyProgress($onProgress, 'Extracting release archive', 3, $totalSteps);
            $sourceRoot = $this->extractAndValidate($zipPath);

            $this->notifyProgress($onProgress, 'Copying files to the live installation', 4, $totalSteps);
            $newManifest = $this->installFiles($sourceRoot);

            $this->notifyProgress($onProgress, 'Removing install.php', 5, $totalSteps);
            [$installScriptRemoved, $newManifest] = $this->removeInstallScript($newManifest);

            $this->notifyProgress($onProgress, 'Removing obsolete files', 6, $totalSteps);
            $removedCount = count($this->removeObsoleteFiles($oldManifest, $newManifest));
            $this->saveManifest($newManifest);

            $this->notifyProgress($onProgress, 'Clearing the object cache', 7, $totalSteps);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return new InstallResult(
                version: $version,
                fileCount: count($newManifest),
                installedAt: gmdate('c'),
                removedFileCount: $removedCount,
                installScriptRemoved: $installScriptRemoved,
            );
        } finally {
            $this->notifyProgress($onProgress, 'Disabling maintenance mode', 8, $totalSteps);
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
            throw new \RuntimeException('Could not create the install staging directory.');
        }
    }

    /**
     * Validates entry names, extracts into staging, and resolves the source
     * root (staging itself or its single wrapping subdirectory).
     *
     * @throws \RuntimeException on an invalid archive, unsafe entry name, or non-release tree.
     */
    private function extractAndValidate(string $zipPath): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to install updates.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('The release archive could not be opened.');
        }

        $topLevelNames = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $this->assertSafeEntryName($name);

            $firstSegment = explode('/', ltrim($name, '/'))[0] ?? '';
            if ($firstSegment !== '') {
                $topLevelNames[$firstSegment] = true;
            }
        }

        if (!$zip->extractTo($this->stagingDir)) {
            $zip->close();
            throw new \RuntimeException('Could not extract the release archive.');
        }
        $zip->close();

        // GitHub source ZIPs wrap everything in one folder; dedicated release assets may not.
        $sourceRoot = $this->stagingDir;
        if (count($topLevelNames) === 1) {
            $wrapper = array_key_first($topLevelNames);
            $candidate = $this->stagingDir . '/' . $wrapper;
            if (is_dir($candidate)) {
                $sourceRoot = $candidate;
            }
        }

        $this->assertLooksLikeARelease($sourceRoot);

        return $sourceRoot;
    }

    /** Rejects Zip Slip, absolute-path and traversal entry names. */
    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new \RuntimeException('The release archive contains an unsafe absolute path: ' . $name);
        }

        // Windows drive letters, e.g. "C:\...".
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
            throw new \RuntimeException('The release archive contains an unsafe absolute path: ' . $name);
        }

        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException('The release archive contains a directory traversal entry: ' . $name);
            }
        }
    }

    /** Guards against installing an unrelated or corrupt archive. */
    private function assertLooksLikeARelease(string $sourceRoot): void
    {
        foreach (self::REQUIRED_FILES as $file) {
            if (!is_file($sourceRoot . '/' . $file)) {
                throw new \RuntimeException('The release archive does not look like a valid FanUpdate Redux release (missing ' . $file . ').');
            }
        }

        foreach (self::REQUIRED_DIRS as $dir) {
            if (!is_dir($sourceRoot . '/' . $dir)) {
                throw new \RuntimeException('The release archive does not look like a valid FanUpdate Redux release (missing ' . $dir . '/ directory).');
            }
        }
    }

    // ── file installation ────────────────────────────────────────────────────

    /**
     * Copies every non-preserved file from $sourceRoot into the install.
     *
     * @return array<int, string> Relative paths copied (the new manifest).
     */
    private function installFiles(string $sourceRoot): array
    {
        $sourceRoot = rtrim($sourceRoot, '/');
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
                throw new \RuntimeException('Could not write file during install: ' . $relative);
            }

            $manifest[] = $relative;
        }

        return $manifest;
    }

    /**
     * Best-effort delete of install.php; a failure leaves it in place (the
     * admin notice still flags it) rather than failing the install.
     *
     * @param array<int, string> $manifest
     * @return array{0: bool, 1: array<int, string>} Whether it was removed, and
     *         $manifest without 'install.php' either way, so a failed delete
     *         is never later treated as an obsolete file.
     */
    private function removeInstallScript(array $manifest): array
    {
        $manifest = array_values(array_diff($manifest, ['install.php']));

        $installScript = $this->installDir . '/install.php';
        if (!is_file($installScript)) {
            return [false, $manifest];
        }

        return [@unlink($installScript), $manifest];
    }

    /**
     * Deletes paths in $oldManifest but not $newManifest. Best-effort:
     * unremovable files are skipped.
     *
     * @param array<int, string> $oldManifest
     * @param array<int, string> $newManifest
     * @return array<int, string> Relative paths actually removed.
     */
    private function removeObsoleteFiles(array $oldManifest, array $newManifest): array
    {
        $removed = [];

        foreach (array_diff($oldManifest, $newManifest) as $relative) {
            // Defence in depth: preserved paths should never be in a manifest.
            if ($this->isPreserved($relative)) {
                continue;
            }

            $fullPath = $this->installDir . '/' . $relative;
            if (is_file($fullPath) && @unlink($fullPath)) {
                $removed[] = $relative;
            }
        }

        return $removed;
    }

    /**
     * The previous install/rollback's manifest, or [] if missing or unparseable.
     *
     * @return array<int, string>
     */
    private function loadManifest(): array
    {
        $path = $this->installDir . '/' . self::MANIFEST_FILENAME;
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    /** @param array<int, string> $manifest */
    private function saveManifest(array $manifest): void
    {
        @file_put_contents(
            $this->installDir . '/' . self::MANIFEST_FILENAME,
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
