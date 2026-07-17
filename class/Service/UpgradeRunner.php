<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeRunner
 *
 * Non-interactive post-install database migrations for the Admin Update
 * Manager (TODO.md "1. Admin Update Manager", Installation Engine "Post
 * Install" > "Run upgrade scripts").
 *
 * install.php's own version-gated migration blocks (pre-2.2b, pre-2.2b3,
 * pre-2.2.1b2, and a final "from any version" block) are interactive —
 * they read $_POST-submitted values for newly introduced options via a
 * rendered form, since the original install/upgrade flow always asks the
 * admin to review each new option's value before saving it. That's not
 * compatible with an automated, unattended install triggered by the Admin
 * Update Manager, and those specific version thresholds predate FanUpdate
 * Redux's PHP 8.2+ baseline by well over a decade — no installation capable
 * of running this updater at all could still be that old. This class
 * deliberately does not port them.
 *
 * What IS a real, ongoing concern for every future release is the general
 * pattern install.php's last block demonstrates: obsolete option keys
 * should be cleaned up, and options a newer release introduces should get
 * a sensible default automatically rather than being silently absent
 * (which would make `getOpt()` return false everywhere that option is
 * read) — an automated update can't pause and ask the admin to fill in a
 * form the way install.php's UI does. runPendingUpgrades() below implements
 * exactly that pattern, non-interactively, and is meant to be extended by
 * each future release: add newly-introduced option keys to
 * self::NEW_DEFAULT_OPTIONS and anything that becomes obsolete to
 * self::OBSOLETE_OPTION_KEYS.
 *
 * Why this matters architecturally: InstallManager::installUpdate() copies
 * a new release's files onto disk, but PHP has no way to "hot reload"
 * already-`require`d classes mid-request — the currently running process
 * keeps using whatever UpgradeRunner was loaded at the start of the
 * request, not whatever the just-copied files now contain. So this can
 * only correctly apply the migrations *this* release's author already
 * knew about when it shipped; a future release must ship its own updated
 * copy of this file (with its own new entries) and have that copy actually
 * run in a *fresh* HTTP request made after the file copy — never
 * mid-request, in the same call that performed the copy. See
 * admin/update.php's `run-upgrades` GET action for how that's arranged.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class UpgradeRunner
{
    /**
     * Option keys no longer used by any part of the current codebase.
     * Deleting them is purely tidiness — nothing reads them — so this list
     * is safe to extend freely; deleting a key that was already gone is a
     * silent no-op (deleteOptionIfExists() returns false, no message is
     * added).
     *
     * @var array<int, string>
     */
    private const OBSOLETE_OPTION_KEYS = [
        '_server_tz_offset',
        '_last_update_check',
        '_last_update_version',
    ];

    /**
     * Options a release introduced that need a sensible default on any
     * installation upgrading from before that release — extend this when
     * a future release adds a new option `getOpt()` should never just
     * silently return false for. Empty for the current release cycle;
     * the mechanism exists and is tested so a future release can add
     * entries here with confidence it will actually run.
     *
     * @var array<string, array{value: string, description: string}>
     */
    private const NEW_DEFAULT_OPTIONS = [
        // 'example_new_option' => ['value' => 'y', 'description' => 'What this option controls.'],
    ];

    public function __construct(
        private readonly UpgradeRepositoryInterface $repository,
    ) {
    }

    /**
     * Bring the installed database schema/options up to $targetVersionRaw,
     * if it isn't already. Safe to call unconditionally on every install
     * (including a fresh one, and repeatedly) — it always checks
     * `_db_version` first and does nothing if already current.
     */
    public function runPendingUpgrades(string $targetVersionRaw): UpgradeResult
    {
        $targetVersion = UpdateManager::normalizeVersion($targetVersionRaw);

        $installedRaw     = $this->repository->getOptionValue('_db_version');
        $installedVersion = $installedRaw !== null ? UpdateManager::normalizeVersion($installedRaw) : null;

        if ($installedVersion !== null && version_compare($installedVersion, $targetVersion, '>=')) {
            return new UpgradeResult($installedVersion, $targetVersion, [], gmdate('c'));
        }

        $messages = [];

        foreach (self::OBSOLETE_OPTION_KEYS as $key) {
            if ($this->repository->deleteOptionIfExists($key)) {
                $messages[] = 'Removed obsolete option "' . $key . '".';
            }
        }

        foreach (self::NEW_DEFAULT_OPTIONS as $key => $default) {
            if ($this->repository->getOptionValue($key) === null) {
                $this->repository->setOptionValue($key, $default['value'], $default['description']);
                $messages[] = 'Added new default option "' . $key . '" = ' . $default['value'] . '.';
            }
        }

        $this->repository->setOptionValue('_db_version', $targetVersion, 'Version of current database schema.');

        return new UpgradeResult($installedVersion ?? 'unknown', $targetVersion, $messages, gmdate('c'));
    }
}
