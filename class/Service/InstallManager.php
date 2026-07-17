<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — InstallManager
 *
 * Extracts a downloaded release ZIP and installs it over the live
 * installation. Part of the Admin Update Manager's Installation Engine
 * (see TODO.md) — runs entirely in PHP with no shell/Composer/Git
 * dependency, per the shared-hosting assumptions in TODO.md's "Update
 * System Assumptions".
 *
 * installUpdate() does, in order:
 *  1. Enable maintenance mode (a flag file — see below).
 *  2. Extract the archive into a scratch staging directory, validating
 *     every entry name against Zip Slip / absolute-path / traversal
 *     attacks before anything is written to disk.
 *  3. Auto-detect and strip a single wrapping root directory — GitHub's
 *     auto-generated source ZIPs (zipball_url, tag archive links) always
 *     wrap the whole tree in one folder (e.g. "fanupdate-redux-2.2.0/");
 *     a release built with a dedicated packaging step might not.
 *  4. Validate the resulting tree actually looks like a FanUpdate Redux
 *     release (version.php/index.php files and admin//class/ directories
 *     present) — a defence against installing an unrelated or corrupt
 *     archive.
 *  5. Copy every file over the live installation, skipping anything under
 *     a preserved path (uploads/, this tool's own temp/backup
 *     directories, blog-config.php, .git/) so user content and local
 *     configuration are never touched.
 *  6. Remove obsolete files — see below for how this stays safe.
 *  7. Reset OPcache, if loaded — the only cache layer present in this
 *     codebase, and the one most likely to keep serving stale bytecode
 *     after files have just been overwritten on disk.
 *  8. Disable maintenance mode and delete the staging directory.
 *
 * Maintenance mode is a simple flag file
 * ({install_path}/.fu-maintenance) — not a full public-facing takeover.
 * The public-facing scripts (show-blog.php, show-cat.php, rss.php,
 * rss-comments.php) are designed to be embedded inline in a host page's
 * own template rather than rendered as standalone pages, so a page-wide
 * maintenance banner doesn't fit their architecture; process.php (the one
 * write path that could collide badly with an in-progress file swap)
 * checks FanUpdate::isMaintenanceModeEnabled() and rejects new comment
 * submissions while it's set. The flag is always cleared in a `finally`
 * block, even when the install fails, so a failed run can never leave
 * the site permanently locked out of new comments.
 *
 * "Remove obsolete files" is scoped carefully so it can never delete
 * anything an admin added themselves: after every successful install,
 * a manifest ({install_path}/.fu-manifest.json) listing every relative
 * path this tool itself just copied is written to disk. On the *next*
 * install, the previous run's manifest is read first; only paths that
 * appear in that old manifest but are absent from the new release are
 * ever considered for removal — a file that was never recorded in any
 * manifest (because an admin added it, or because it predates this
 * mechanism entirely) is never a candidate, regardless of whether the
 * new release happens to ship a file at that path or not. When no prior
 * manifest exists yet (e.g. the very first install performed through the
 * Admin Update Manager on an existing installation), there is nothing to
 * compare against, so nothing is removed that run — tracking simply
 * begins from that point forward. RollbackManager updates the same
 * manifest after a successful restore, so a rollback followed by a later
 * install doesn't leave the manifest describing a version that's no
 * longer actually on disk.
 *
 * Running database upgrade scripts is a separate concern — see
 * UpgradeRunner and TODO.md's "Run upgrade scripts" item.
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

    /**
     * Filename (relative to $installDir) of the manifest listing every
     * relative path this tool itself installed — see the class docblock
     * for the full "Remove obsolete files" safety rationale. Shared with
     * RollbackManager, which updates the same file after a restore.
     */
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
     * @param string $installDir The live installation directory to install into.
     * @param string $stagingDir Scratch directory the archive is extracted
     *        into; created (recursively) on first use and deleted again
     *        once the install completes (successfully or not).
     * @param string $maintenanceFlagPath Path to the maintenance-mode flag
     *        file, typically "{install_path}/.fu-maintenance".
     * @param array<int, string>|null $preserve Top-level names under
     *        $installDir that are never overwritten or removed. Defaults
     *        to self::DEFAULT_PRESERVE.
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
     * @param callable(string, int, int): void|null $onProgress Called at
     *        each phase boundary as (message, stepNumber, totalSteps) —
     *        e.g. for a live progress display during the (synchronous)
     *        install request. Never called with the final outcome; the
     *        caller reports that itself once installUpdate() returns or
     *        throws. Exceptions from $onProgress itself are not caught —
     *        it should never throw.
     * @throws \RuntimeException on any validation or I/O failure. Always
     *         disables maintenance mode before returning or throwing.
     */
    public function installUpdate(string $zipPath, string $version, ?callable $onProgress = null): InstallResult
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException('The release archive could not be found.');
        }

        $totalSteps = 7;

        $this->notifyProgress($onProgress, 'Enabling maintenance mode', 1, $totalSteps);
        $this->enableMaintenanceMode();

        try {
            // Read *before* anything is copied — the old manifest describes
            // what's on disk right now, from the install this is about to
            // replace.
            $oldManifest = $this->loadManifest();

            $this->notifyProgress($onProgress, 'Preparing staging directory', 2, $totalSteps);
            $this->resetStagingDir();

            $this->notifyProgress($onProgress, 'Extracting release archive', 3, $totalSteps);
            $sourceRoot = $this->extractAndValidate($zipPath);

            $this->notifyProgress($onProgress, 'Copying files to the live installation', 4, $totalSteps);
            $newManifest = $this->installFiles($sourceRoot);

            $this->notifyProgress($onProgress, 'Removing obsolete files', 5, $totalSteps);
            $removedCount = count($this->removeObsoleteFiles($oldManifest, $newManifest));
            $this->saveManifest($newManifest);

            $this->notifyProgress($onProgress, 'Clearing the object cache', 6, $totalSteps);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return new InstallResult(
                version: $version,
                fileCount: count($newManifest),
                installedAt: gmdate('c'),
                removedFileCount: $removedCount,
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
     * Extract $zipPath into $this->stagingDir, validate every entry name
     * first, then resolve and validate the release's source root.
     *
     * @return string Absolute path to the release's source root — either
     *         $this->stagingDir itself, or a single wrapping subdirectory
     *         within it.
     * @throws \RuntimeException on an invalid archive, an unsafe entry
     *         name, or a tree that doesn't look like a FanUpdate Redux
     *         release.
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

        // GitHub's auto-generated source ZIPs (zipball_url, tag archive
        // links) always wrap the whole tree in a single top-level folder.
        // A dedicated release asset might not. Detect the single-wrapper
        // case and use it as the real source root.
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

    /**
     * Reject Zip Slip / absolute-path / directory-traversal entry names
     * before anything is extracted.
     */
    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            throw new \RuntimeException('The release archive contains an unsafe absolute path: ' . $name);
        }

        // Windows drive-letter absolute paths (e.g. "C:\...").
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
            throw new \RuntimeException('The release archive contains an unsafe absolute path: ' . $name);
        }

        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException('The release archive contains a directory traversal entry: ' . $name);
            }
        }
    }

    /**
     * Confirm $sourceRoot actually contains a FanUpdate Redux release —
     * defence against installing an unrelated or corrupt archive.
     */
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
     * Copy every file from $sourceRoot into $this->installDir, skipping
     * preserved paths.
     *
     * @return array<int, string> Relative path of every file copied —
     *         this becomes the new manifest.
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
     * Delete every path present in $oldManifest but absent from
     * $newManifest — see the class docblock for why this can never touch
     * a file that wasn't recorded in a previous manifest. Best-effort: a
     * file that can't be removed (e.g. a permissions issue) is simply
     * skipped rather than failing the whole install over cleanup.
     *
     * @param array<int, string> $oldManifest
     * @param array<int, string> $newManifest
     * @return array<int, string> Relative paths actually removed.
     */
    private function removeObsoleteFiles(array $oldManifest, array $newManifest): array
    {
        $removed = [];

        foreach (array_diff($oldManifest, $newManifest) as $relative) {
            // Defence in depth — a preserved path should never have been
            // written into a manifest in the first place, but never risk
            // touching one regardless.
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
     * Read the manifest left by the previous install/rollback, or [] if
     * none exists yet or it can't be parsed.
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
