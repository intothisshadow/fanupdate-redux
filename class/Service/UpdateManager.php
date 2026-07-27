<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpdateManager
 *
 * Business logic for the Admin Update Manager: performs a live update check
 * against the configured UpdateProviderInterface, persists the result via
 * UpdateRepositoryInterface, and builds the display summary used by
 * admin/update.php and the Dashboard notice from cached state alone (no
 * network call — safe to call on every admin page load).
 *
 * Depends on UpdateProviderInterface and UpdateRepositoryInterface
 * (constructor injection), not concrete classes or the FanUpdate singleton,
 * so it can be unit tested with fakes for both.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class UpdateManager
{
    private readonly string $installedVersion;

    /** Cap on stored log entries — shared hosting has no cron to prune this otherwise. */
    private const MAX_LOG_ENTRIES = 200;

    public function __construct(
        private readonly UpdateProviderInterface $provider,
        private readonly UpdateRepositoryInterface $repository,
        string $installedVersionRaw,
        private readonly ?DownloadManager $downloadManager = null,
        private readonly ?BackupManager $backupManager = null,
        private readonly ?InstallManager $installManager = null,
        private readonly ?RollbackManager $rollbackManager = null,
    ) {
        $this->installedVersion = self::normalizeVersion($installedVersionRaw);
    }

    /**
     * Strip any non-numeric prefix (e.g. "Redux " in FU_VERSION's
     * "Redux 2.1.0") so version_compare() sees a plain version number.
     * Returns the input unchanged if no numeric version can be found.
     */
    public static function normalizeVersion(string $raw): string
    {
        if (preg_match('/\d+(?:\.\d+){1,3}/', $raw, $m)) {
            return $m[0];
        }

        return $raw;
    }

    public function getChannel(): string
    {
        $config  = $this->repository->getConfig();
        $channel = (string) ($config['channel'] ?? 'stable');

        return $channel === 'prerelease' ? 'prerelease' : 'stable';
    }

    /** Whether opportunistic automatic update checks are enabled. Defaults to true. */
    public function isAutoCheckEnabled(): bool
    {
        $config = $this->repository->getConfig();

        return (bool) ($config['autoCheckEnabled'] ?? true);
    }

    /** Hours between automatic checks. Defaults to 24; never less than 1. */
    public function getCheckFrequencyHours(): int
    {
        $config = $this->repository->getConfig();
        $hours  = (int) ($config['checkFrequencyHours'] ?? 24);

        return $hours > 0 ? $hours : 24;
    }

    /** Persist the automatic-check enabled flag and frequency (in hours, minimum 1). */
    public function setAutoCheckPreferences(bool $enabled, int $frequencyHours): bool
    {
        $config                         = $this->repository->getConfig();
        $config['autoCheckEnabled']     = $enabled;
        $config['checkFrequencyHours']  = max(1, $frequencyHours);

        return $this->repository->saveConfig($config);
    }

    public function getProviderName(): string
    {
        return $this->provider->getProviderName();
    }

    public function getSourceLabel(): string
    {
        return $this->provider->getSourceLabel();
    }

    /**
     * Record an event in the persisted log (most recent first), capped to
     * MAX_LOG_ENTRIES. Logging itself never throws or fails visibly — a
     * logging problem should never be the reason an update action itself
     * is reported as failed.
     */
    private function logEvent(string $type, string $status, string $message): void
    {
        try {
            $log = $this->repository->getLog();
            array_unshift($log, [
                'timestamp' => gmdate('c'),
                'type'      => $type,
                'status'    => $status,
                'message'   => $message,
            ]);
            $this->repository->saveLog(array_slice($log, 0, self::MAX_LOG_ENTRIES));
        } catch (\Throwable) {
            // Never let a logging failure surface as an update failure.
        }
    }

    /**
     * The most recent log entries, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLogEntries(int $limit = 50): array
    {
        return array_slice($this->repository->getLog(), 0, max(0, $limit));
    }

    /** Erase the entire persisted log. */
    public function clearLog(): bool
    {
        return $this->repository->saveLog([]);
    }

    /**
     * Record the current install/rollback progress into cached state, so a
     * concurrent request (the Update Progress poll endpoint) can read it
     * while the install/rollback request is still in flight. This is only
     * meaningful because the underlying DB connection auto-commits each
     * query — there is no long-lived transaction wrapping the whole
     * install spanning these writes. Wrapped in its own try/catch, same as
     * logEvent() — a progress-reporting failure must never surface as an
     * install/rollback failure.
     */
    private function reportProgress(string $message, int $step, int $total): void
    {
        try {
            $state             = $this->repository->getState();
            $state['progress'] = [
                'message'   => $message,
                'step'      => $step,
                'total'     => $total,
                'updatedAt' => gmdate('c'),
            ];
            $this->repository->saveState($state);
        } catch (\Throwable) {
            // Never let progress reporting surface as an install failure.
        }
    }

    /**
     * The most recently reported install/rollback progress, or null if
     * none is recorded (nothing running, or the last run's progress was
     * never cleared — the poll endpoint treats a stale timestamp the same
     * as no progress, since there's no separate "is anything running right
     * now" flag; the caller simply stops polling once its own request
     * completes).
     *
     * @return array{message: string, step: int, total: int, updatedAt: string}|null
     */
    public function getProgress(): ?array
    {
        $state = $this->repository->getState();

        return $state['progress'] ?? null;
    }

    /**
     * Perform a live check against the configured provider and persist the
     * result. Never throws — provider failures are captured into the
     * returned/persisted state as an 'error' status instead, so the caller
     * can always render a normal page afterwards.
     *
     * @return array<string, mixed> Same shape as getStatusSummary().
     */
    public function checkForUpdates(): array
    {
        $channel   = $this->getChannel();
        $checkedAt = gmdate('c');
        $existing  = $this->repository->getState();

        try {
            $release = $this->provider->getLatestRelease($channel);

            $state = [
                'lastChecked'       => $checkedAt,
                'status'            => 'ok',
                'lastError'         => '',
                'latestVersion'     => $release->version,
                'latestName'        => $release->name,
                'latestNotes'       => $release->notes,
                'latestUrl'         => $release->url,
                'latestPublishedAt' => $release->publishedAt,
                'latestPrerelease'  => $release->prerelease,
                'downloadUrl'       => $release->downloadUrl,
                'downloadSize'      => $release->downloadSize,
                'expectedSha256'    => $release->expectedSha256,
            ];

            // A freshly detected version invalidates any previous dismissal
            // so the Dashboard notice re-surfaces for the new release, even
            // if an earlier one was dismissed.
            if (
                isset($existing['dismissedVersion'])
                && $existing['dismissedVersion'] !== $release->version
            ) {
                unset($existing['dismissedVersion']);
            }

            // A previously downloaded ZIP for a now-superseded version is
            // useless (and would otherwise sit in the temp directory
            // indefinitely on shared hosting with no cron to clean it up) —
            // delete it and clear its state whenever the check finds a
            // different version than the one that was downloaded.
            if (
                isset($existing['downloadedVersion'], $existing['downloadedPath'])
                && $existing['downloadedVersion'] !== $release->version
            ) {
                $this->downloadManager?->cleanup((string) $existing['downloadedPath']);
                unset(
                    $existing['downloadedVersion'],
                    $existing['downloadedPath'],
                    $existing['downloadedSize'],
                    $existing['downloadedSha256'],
                    $existing['downloadedChecksumVerified'],
                    $existing['downloadedAt'],
                );
            }
        } catch (\Throwable $e) {
            $state = [
                'lastChecked' => $checkedAt,
                'status'      => 'error',
                'lastError'   => $e->getMessage(),
            ];
        }

        $merged = array_merge($existing, $state);
        $this->repository->saveState($merged);

        if (($state['status'] ?? '') === 'ok') {
            $this->logEvent('check', 'ok', 'Checked for updates — latest known version: ' . (string) ($state['latestVersion'] ?? '?') . '.');
        } else {
            $this->logEvent('check', 'error', 'Update check failed: ' . (string) ($state['lastError'] ?? 'unknown error') . '.');
        }

        return $this->getStatusSummary();
    }

    /**
     * Run a live check only if automatic checks are enabled and the
     * configured frequency has elapsed since the last check (manual or
     * automatic) — otherwise just returns the cached summary, with no
     * network call. Safe to call on every admin page load (e.g. the
     * Dashboard): the live GitHub request only actually happens once per
     * configured interval, no cron required. Never throws — same
     * error-capturing pattern as checkForUpdates(), which this delegates to
     * when a check is due.
     *
     * @return array<string, mixed> Same shape as getStatusSummary().
     */
    public function runAutomaticCheckIfDue(): array
    {
        if (!$this->isAutoCheckEnabled()) {
            return $this->getStatusSummary();
        }

        $state       = $this->repository->getState();
        $lastChecked = $state['lastChecked'] ?? null;

        if (is_string($lastChecked) && $lastChecked !== '') {
            $lastCheckedTs = strtotime($lastChecked);
            if ($lastCheckedTs !== false) {
                $dueAt = $lastCheckedTs + ($this->getCheckFrequencyHours() * 3600);
                if (time() < $dueAt) {
                    return $this->getStatusSummary();
                }
            }
        }

        return $this->checkForUpdates();
    }

    /** Persist the release channel preference ('stable' or 'prerelease'). */
    public function setChannel(string $channel): bool
    {
        $channel          = $channel === 'prerelease' ? 'prerelease' : 'stable';
        $config           = $this->repository->getConfig();
        $config['channel'] = $channel;

        return $this->repository->saveConfig($config);
    }

    /**
     * Build the display summary from cached state only — no network call.
     * Safe to call on every admin page load (e.g. the Dashboard notice).
     *
     * @return array<string, mixed>
     */
    public function getStatusSummary(): array
    {
        $state = $this->repository->getState();

        $latestVersion = (string) ($state['latestVersion'] ?? '');
        $status        = (string) ($state['status'] ?? 'never');

        $updateAvailable = $status === 'ok'
            && $latestVersion !== ''
            && version_compare($latestVersion, $this->installedVersion, '>');

        $dismissedVersion = $state['dismissedVersion'] ?? null;

        return [
            'installedVersion'  => $this->installedVersion,
            'channel'           => $this->getChannel(),
            'status'            => $status, // 'never' | 'ok' | 'error'
            'lastChecked'       => $state['lastChecked'] ?? null,
            'lastError'         => (string) ($state['lastError'] ?? ''),
            'updateAvailable'   => $updateAvailable,
            'latestVersion'     => $latestVersion !== '' ? $latestVersion : null,
            'latestName'        => $state['latestName'] ?? null,
            'latestNotes'       => $state['latestNotes'] ?? null,
            'latestUrl'         => $state['latestUrl'] ?? null,
            'latestPublishedAt' => $state['latestPublishedAt'] ?? null,
            'latestPrerelease'  => (bool) ($state['latestPrerelease'] ?? false),
            'downloadUrl'       => $state['downloadUrl'] ?? null,
            'downloadSize'      => isset($state['downloadSize']) ? (int) $state['downloadSize'] : null,
            // FP-17: whether this release published a checksum at all — shown
            // regardless of whether a download has happened yet.
            'checksumAvailable' => isset($state['expectedSha256']),
            'noticeDismissed'   => $dismissedVersion !== null && $dismissedVersion === $latestVersion,
            'downloadedVersion' => $state['downloadedVersion'] ?? null,
            'downloadedSize'    => isset($state['downloadedSize']) ? (int) $state['downloadedSize'] : null,
            'downloadedSha256'  => $state['downloadedSha256'] ?? null,
            // FP-17: true once a download has actually been checksum-verified
            // against the release's published checksum. False both when no
            // download has happened yet and when one happened but no
            // checksum was published — 'checksumAvailable' above and
            // 'downloadedVersion' distinguish those cases for the UI.
            'downloadedChecksumVerified' => (bool) ($state['downloadedChecksumVerified'] ?? false),
            'downloadedAt'      => $state['downloadedAt'] ?? null,
            // True once the currently-known latest version has been
            // downloaded and validated — i.e. ready for the (not yet built)
            // Installation Engine to install.
            'downloadReady'     => isset($state['downloadedVersion'], $state['downloadedPath'])
                && $state['downloadedVersion'] === $latestVersion,
            'lastInstall'       => $state['lastInstall'] ?? null,
            'lastRollback'      => $state['lastRollback'] ?? null,
            'maintenanceModeOn' => $this->installManager?->isMaintenanceModeEnabled() ?? false,
        ];
    }

    /**
     * Install the currently downloaded release (from cached check state —
     * no live provider call) over the live installation. Automatically
     * creates a backup first as a safety net; if the install itself then
     * fails, automatically restores that same backup via RollbackManager
     * before reporting the failure (see the catch block below for what
     * happens when the rollback manager isn't available or the rollback
     * itself also fails). Never throws — failures are captured into
     * the returned array as an error, the same pattern as
     * checkForUpdates()/downloadLatestRelease().
     *
     * @return array{ok: bool, error?: string, version?: string, fileCount?: int, removedFileCount?: int}
     */
    public function installLatestRelease(): array
    {
        if ($this->installManager === null) {
            return ['ok' => false, 'error' => 'The install manager is not available.'];
        }

        if ($this->backupManager === null) {
            return ['ok' => false, 'error' => 'The backup manager is not available.'];
        }

        $state = $this->repository->getState();

        $downloadedVersion = (string) ($state['downloadedVersion'] ?? '');
        $downloadedPath    = (string) ($state['downloadedPath'] ?? '');
        $latestVersion     = (string) ($state['latestVersion'] ?? '');

        if (
            $downloadedVersion === ''
            || $downloadedPath === ''
            || $downloadedVersion !== $latestVersion
            || !is_file($downloadedPath)
        ) {
            return ['ok' => false, 'error' => 'No verified download is ready to install. Download the release first.'];
        }

        try {
            $backupResult = $this->backupManager->createBackup($this->installedVersion);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Backup failed, install aborted: ' . $e->getMessage()];
        }

        try {
            $onProgress = function (string $message, int $step, int $total): void {
                $this->reportProgress($message, $step, $total);
            };

            $result = $this->installManager->installUpdate($downloadedPath, $downloadedVersion, $onProgress);

            $this->downloadManager?->cleanup($downloadedPath);

            unset(
                $state['downloadedVersion'],
                $state['downloadedPath'],
                $state['downloadedSize'],
                $state['downloadedSha256'],
                $state['downloadedChecksumVerified'],
                $state['downloadedAt'],
            );
            $state['lastInstall'] = [
                'version'          => $result->version,
                'fileCount'        => $result->fileCount,
                'installedAt'      => $result->installedAt,
                'removedFileCount' => $result->removedFileCount,
            ];

            $this->repository->saveState($state);

            $this->logEvent(
                'install',
                'ok',
                'Installed version ' . $result->version . ' (' . $result->fileCount . ' files).'
                . ($result->removedFileCount > 0 ? ' Removed ' . $result->removedFileCount . ' obsolete file(s).' : '')
            );

            return [
                'ok'               => true,
                'version'          => $result->version,
                'fileCount'        => $result->fileCount,
                'removedFileCount' => $result->removedFileCount,
            ];
        } catch (\Throwable $e) {
            // Installation Engine "Automatic Rollback": detect the failure
            // (this catch), and restore the backup just taken above, before
            // ever reporting the failure back to the admin. RollbackManager's
            // own maintenance-mode handling guarantees maintenance mode ends
            // up disabled either way ("Restore maintenance state").
            $message = 'Install failed: ' . $e->getMessage();
            $this->logEvent('install', 'error', $message);

            if ($this->rollbackManager !== null) {
                try {
                    $rollbackProgress = function (string $message, int $step, int $total): void {
                        $this->reportProgress($message, $step, $total);
                    };
                    $this->rollbackManager->rollbackTo($backupResult->filePath, $this->installedVersion, $rollbackProgress);
                    $message .= ' The previous version was automatically restored from backup.';
                    $this->logEvent('rollback', 'ok', 'Automatically restored version ' . $this->installedVersion . ' after a failed install of ' . $downloadedVersion . '.');
                } catch (\Throwable $rollbackException) {
                    $message .= ' Automatic rollback ALSO failed (' . $rollbackException->getMessage()
                        . ') — manual recovery is needed; see the Backups section.';
                    $this->logEvent('rollback', 'error', 'Automatic rollback after a failed install ALSO failed: ' . $rollbackException->getMessage());
                }
            } else {
                $message .= ' Automatic rollback is not available — restore the backup taken just before this install manually from the Backups section.';
            }

            return ['ok' => false, 'error' => $message];
        }
    }

    /**
     * Manually restore a specific backup over the live installation — used
     * from the Backups list, independent of any install that may or may not
     * have run. Never throws — failures are captured into the returned
     * array as an error, the same pattern as the other public methods here.
     *
     * @return array{ok: bool, error?: string, version?: string, fileCount?: int}
     */
    public function restoreBackup(string $backupPath, string $backupVersion): array
    {
        if ($this->rollbackManager === null) {
            return ['ok' => false, 'error' => 'The rollback manager is not available.'];
        }

        if (!is_file($backupPath)) {
            return ['ok' => false, 'error' => 'That backup file could not be found.'];
        }

        try {
            $onProgress = function (string $message, int $step, int $total): void {
                $this->reportProgress($message, $step, $total);
            };

            $result = $this->rollbackManager->rollbackTo($backupPath, $backupVersion, $onProgress);

            $state                  = $this->repository->getState();
            $state['lastRollback']  = [
                'version'    => $result->version,
                'fileCount'  => $result->fileCount,
                'restoredAt' => $result->restoredAt,
                'backupPath' => $result->backupPath,
            ];
            $this->repository->saveState($state);

            $this->logEvent('rollback', 'ok', 'Manually restored version ' . $result->version . ' (' . $result->fileCount . ' files) from backup.');

            return [
                'ok'        => true,
                'version'   => $result->version,
                'fileCount' => $result->fileCount,
            ];
        } catch (\Throwable $e) {
            $this->logEvent('rollback', 'error', 'Manual restore failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run any pending database upgrades (schema migration + option/version
     * housekeeping) via $upgradeRunner (TODO.md item "10. Admin-Panel
     * Database Update Runner"), reporting progress through the same
     * cached-state mechanism installLatestRelease()/restoreBackup() use
     * (so admin/update.php's existing progress-bar polling works
     * unmodified for this too), and logging the outcome. Never throws —
     * same error-capturing pattern as the other public methods here.
     *
     * Used two ways: automatically right after a GitHub-release install
     * (admin/update.php's `?action=run-upgrades` redirect step, unchanged
     * from before this method existed except for now going through
     * UpdateManager instead of calling $upgradeRunner directly, so it gets
     * progress/logging too), and from the new "Database Updates" section's
     * own button — for an installation updated by manual file upload,
     * which never goes through that redirect at all.
     *
     * @return array{ok: bool, error?: string, messages?: array<int, string>, fromVersion?: string, toVersion?: string}
     */
    public function runPendingUpgrades(UpgradeRunner $upgradeRunner, string $targetVersion): array
    {
        try {
            $onProgress = function (string $message, int $step, int $total): void {
                $this->reportProgress($message, $step, $total);
            };

            $result = $upgradeRunner->runPendingUpgrades($targetVersion, $onProgress);

            if (empty($result->messages)) {
                $this->logEvent('upgrade', 'ok', 'Database already up to date — no migrations were needed.');
            } else {
                $this->logEvent(
                    'upgrade',
                    'ok',
                    'Ran ' . count($result->messages) . ' pending database migration(s): ' . implode(' ', $result->messages)
                );
            }

            return [
                'ok'          => true,
                'messages'    => $result->messages,
                'fromVersion' => $result->fromVersion,
                'toVersion'   => $result->toVersion,
            ];
        } catch (\Throwable $e) {
            $this->logEvent('upgrade', 'error', 'Database upgrade failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Download the currently-known latest release's ZIP asset (from cached
     * check state — no live provider call) and persist the outcome. Never
     * throws — failures are captured into the returned array as an error,
     * the same pattern as checkForUpdates().
     *
     * @return array{ok: bool, error?: string, path?: string, size?: int, sha256?: string, checksumVerified?: bool}
     */
    public function downloadLatestRelease(): array
    {
        if ($this->downloadManager === null) {
            return ['ok' => false, 'error' => 'The download manager is not available.'];
        }

        $state   = $this->repository->getState();
        $release = $this->releaseFromState($state);

        if ($release === null) {
            return ['ok' => false, 'error' => 'No release information is available. Run an update check first.'];
        }

        try {
            $result = $this->downloadManager->download($release, $this->provider->getAllowedDownloadHosts());

            $state['downloadedVersion']          = $release->version;
            $state['downloadedPath']             = $result->filePath;
            $state['downloadedSize']             = $result->size;
            $state['downloadedSha256']           = $result->sha256;
            $state['downloadedChecksumVerified'] = $result->checksumVerified;
            $state['downloadedAt']               = gmdate('c');

            $this->repository->saveState($state);

            $this->logEvent(
                'download',
                'ok',
                'Downloaded release ' . $release->version . ' (' . $result->size . ' bytes).'
                . ($result->checksumVerified ? ' Checksum verified against the published release.' : ' No published checksum was available to verify against.')
            );

            return [
                'ok'                => true,
                'path'              => $result->filePath,
                'size'              => $result->size,
                'sha256'            => $result->sha256,
                'checksumVerified'  => $result->checksumVerified,
            ];
        } catch (\Throwable $e) {
            $this->logEvent('download', 'error', 'Download of release ' . $release->version . ' failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rebuild a ReleaseInfo from cached check state, or null when the state
     * is missing, from a failed check, or has no download URL.
     *
     * @param array<string, mixed> $state
     */
    private function releaseFromState(array $state): ?ReleaseInfo
    {
        $version = (string) ($state['latestVersion'] ?? '');

        if ($version === '' || ($state['status'] ?? '') !== 'ok') {
            return null;
        }

        return new ReleaseInfo(
            version: $version,
            name: (string) ($state['latestName'] ?? ''),
            notes: (string) ($state['latestNotes'] ?? ''),
            url: (string) ($state['latestUrl'] ?? ''),
            publishedAt: (string) ($state['latestPublishedAt'] ?? ''),
            prerelease: (bool) ($state['latestPrerelease'] ?? false),
            downloadUrl: isset($state['downloadUrl']) ? (string) $state['downloadUrl'] : null,
            downloadSize: isset($state['downloadSize']) ? (int) $state['downloadSize'] : null,
            expectedSha256: isset($state['expectedSha256']) ? (string) $state['expectedSha256'] : null,
        );
    }

    /** Dismiss the Dashboard notice until a newer version is detected. */
    public function dismissNotice(): bool
    {
        $state = $this->repository->getState();

        if (!isset($state['latestVersion'])) {
            return false;
        }

        $state['dismissedVersion'] = $state['latestVersion'];

        return $this->repository->saveState($state);
    }
}
