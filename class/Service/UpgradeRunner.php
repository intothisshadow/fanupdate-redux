<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeRunner
 *
 * Non-interactive post-install database migrations for the Admin Update
 * Manager (TODO.md "1. Admin Update Manager", Installation Engine "Post
 * Install" > "Run upgrade scripts"), extended (TODO.md item "10.
 * Admin-Panel Database Update Runner") to also drive schema migrations via
 * an injected SchemaMigrator — see that class's docblock for what it
 * covers (Categories Support's standalone_category_table, the InnoDB/
 * utf8mb4 engine/charset migration).
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
 * hasPendingUpgrades()/runPendingUpgrades() now consider THREE independent
 * things "pending": the option/version housekeeping above (gated by
 * comparing stored `_db_version` against the target version, as before), a
 * SchemaMigrator's own hasPendingSchemaChanges() when one is injected, and
 * (TODO.md's Database Architecture item — "Move collective-script
 * configuration from blog-config.php into the database") a one-time
 * migration of the legacy collective_script/col_id/col_subj values out of
 * blog-config.php and into the options table, when the caller supplies
 * them. Any one alone is enough to make something "pending";
 * runPendingUpgrades() runs whichever ones actually apply. The
 * collective-script migration is independent of `_db_version` for the same
 * reason the schema migration is: an installation already on the current
 * version needs this to run exactly once regardless of what its version
 * history looks like.
 *
 * collective_table has its own independent one-time migration, gated and
 * run separately from collective_script/col_id/col_subj above (see
 * collectiveTableMigrationPending()/migrateLegacyCollectiveTable()) —
 * discovered after the fact when an installation that had already migrated
 * collective_script (e.g. by switching it via Admin -> Options -> Collective
 * Source) still had no way to change collective_table at all, silently
 * leaving Categories/Entries pointed at the wrong table after switching to
 * an integrated script. Keeping the two gates independent means an
 * installation that already migrated collective_script still picks up
 * collective_table on its next admin page load once this ships.
 *
 * The FP-19 timezone migration (timezoneMigrationPending()/
 * migrateLegacyTimezoneOffset()) follows the same independent-gate pattern
 * but needs no constructor-injected legacy value: the legacy
 * 'timezone_offset' value already lives in the options table itself (it's
 * been a real, editable option since Redux 2.2b3), so the migration reads
 * it straight from the repository instead.
 *
 * The FP-25 entry_template `.fu-entry` wrapper migration
 * (entryTemplateMigrationPending()/migrateLegacyEntryTemplateWrapper())
 * follows the timezone migration's no-constructor-value pattern too: every
 * existing installation's `entry_template` option row already holds
 * self::OLD_DEFAULT_ENTRY_TEMPLATE (install.php seeded it verbatim, and
 * FanUpdate::__construct()/FanUpdate_Post::printPost() used the identical
 * string as their in-code fallback, before this migration shipped — see
 * TODO.md's FP-25). The gate is a plain string-equality check against that
 * exact stock value: an installation whose entry_template has ever been
 * edited — even by one character — no longer matches, so this never
 * clobbers a real customization the way a version-gated backfill might.
 * Once migrated, the row holds self::NEW_DEFAULT_ENTRY_TEMPLATE and the
 * equality check permanently fails on that installation, same idempotency
 * guarantee as every other migration here.
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

// isVersionBehind()/currentInstalledVersion() call UpdateManager::
// normalizeVersion() directly. admin/index.php and admin/update.php happen
// to require_once this already (for their own unrelated UpdateManager use),
// but every other admin/*.php page reaches UpgradeRunner only via
// functions.php's fu_build_upgrade_runner() (called from admin/header.php's
// global pending-upgrades banner, shown on every page) — none of those
// require UpdateManager.php themselves, so relying on a caller to have
// already loaded it produced a live "Class UpdateManager not found" fatal
// on every admin page except the two that happened to load it first. A
// class with a hard dependency on another class should require it itself
// rather than rely on incidental load order from unrelated callers — same
// pattern as class/FeedWriter.php requiring class/FeedItem.php.
require_once(__DIR__ . '/UpdateManager.php');

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
        // FP-23: official FanUpdate Redux news feed shown on the Dashboard
        // (NewsFeedService). Fresh installs get this from install.php's own
        // $setting[] array instead — this entry only matters for installs
        // upgrading from before FP-23 that never re-run install.php.
        'news_feed_url' => [
            'value'       => 'https://coding.unloved-heart.net/update-blog/rss.php?c=4',
            'description' => 'RSS feed URL shown on the Dashboard as "FanUpdate Redux News". Leave blank to hide the Dashboard widget entirely.',
        ],
    ];

    /**
     * FP-25: the stock entry_template value every installation had before
     * this migration shipped — verbatim from install.php's pre-FP-25
     * $setting[14]['optvalue'] (and the identical in-code fallback in
     * FanUpdate::__construct()/FanUpdate_Post::printPost()). Used only for
     * the exact string-equality check in entryTemplateMigrationPending() —
     * never written anywhere.
     */
    private const OLD_DEFAULT_ENTRY_TEMPLATE =
        '<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>

<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>

{{body}}';

    /**
     * FP-25: the new default, wrapping each post in `<div class="fu-entry">`
     * for per-entry card styling — see class/FanUpdate.php's/
     * class/FanUpdate_Post.php's matching in-code fallback and
     * install.php's matching fresh-install seed.
     */
    private const NEW_DEFAULT_ENTRY_TEMPLATE =
        '<div class="fu-entry">
<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>

<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>

{{body}}
</div>';

    public function __construct(
        private readonly UpgradeRepositoryInterface $repository,
        private readonly ?SchemaMigrator $schemaMigrator = null,
        private readonly ?string $legacyCollectiveScript = null,
        private readonly ?string $legacyColId = null,
        private readonly ?string $legacyColSubj = null,
        private readonly ?string $legacyCollectiveTable = null,
    ) {
    }

    /**
     * Cheap check for whether runPendingUpgrades() would actually do
     * anything right now — the stored `_db_version` is behind
     * $targetVersionRaw, a SchemaMigrator (when injected) reports pending
     * schema changes, or the legacy collective_script migration (when a
     * legacy value was supplied) hasn't run yet. Safe to call on every
     * admin page load (e.g. the Dashboard notice) — no writes, and both
     * the SchemaMigrator check and the collective_script check
     * (repository->getOptionValue()) are similarly cheap single reads.
     */
    public function hasPendingUpgrades(string $targetVersionRaw): bool
    {
        if ($this->isVersionBehind($targetVersionRaw)) {
            return true;
        }

        if ($this->collectiveScriptMigrationPending()) {
            return true;
        }

        if ($this->collectiveTableMigrationPending()) {
            return true;
        }

        if ($this->timezoneMigrationPending()) {
            return true;
        }

        if ($this->entryTemplateMigrationPending()) {
            return true;
        }

        return $this->schemaMigrator !== null && $this->schemaMigrator->hasPendingSchemaChanges();
    }

    /**
     * Bring the installed database schema/options up to $targetVersionRaw,
     * if it isn't already. Safe to call unconditionally on every install
     * (including a fresh one, and repeatedly) — it always checks
     * `_db_version` (and, when a SchemaMigrator is injected, schema state)
     * first and does nothing for whichever part is already current.
     *
     * @param callable(string, int, int): void|null $onProgress Forwarded
     *        to SchemaMigrator::runPendingSchemaChanges() when schema
     *        changes are actually pending — see that method's docblock.
     *        Not used for the option/version housekeeping below, which is
     *        fast enough not to need step-by-step progress reporting.
     */
    public function runPendingUpgrades(string $targetVersionRaw, ?callable $onProgress = null): UpgradeResult
    {
        $targetVersion           = UpdateManager::normalizeVersion($targetVersionRaw);
        $installedVersion        = $this->normalizedInstalledVersion();
        $versionBehind           = $this->isVersionBehind($targetVersionRaw);
        $schemaPending           = $this->schemaMigrator !== null && $this->schemaMigrator->hasPendingSchemaChanges();
        $collectiveScriptPending = $this->collectiveScriptMigrationPending();
        $collectiveTablePending  = $this->collectiveTableMigrationPending();
        $timezonePending         = $this->timezoneMigrationPending();
        $entryTemplatePending    = $this->entryTemplateMigrationPending();

        if (!$versionBehind && !$schemaPending && !$collectiveScriptPending && !$collectiveTablePending && !$timezonePending && !$entryTemplatePending) {
            return new UpgradeResult($installedVersion ?? $targetVersion, $targetVersion, [], gmdate('c'));
        }

        $messages = [];

        if ($schemaPending && $this->schemaMigrator !== null) {
            foreach ($this->schemaMigrator->runPendingSchemaChanges($onProgress) as $result) {
                $messages[] = $result['message'];
            }
        }

        if ($collectiveScriptPending) {
            $messages[] = $this->migrateLegacyCollectiveScript();
        }

        if ($collectiveTablePending) {
            $messages[] = $this->migrateLegacyCollectiveTable();
        }

        if ($timezonePending) {
            $messages[] = $this->migrateLegacyTimezoneOffset();
        }

        if ($entryTemplatePending) {
            $messages[] = $this->migrateLegacyEntryTemplateWrapper();
        }

        if ($versionBehind) {
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
        }

        return new UpgradeResult($installedVersion ?? 'unknown', $targetVersion, $messages, gmdate('c'));
    }

    /**
     * Whether the one-time legacy collective_script/col_id/col_subj
     * migration (TODO.md's Database Architecture item) still needs to run:
     * the caller supplied a legacy value to migrate FROM, and the options
     * table doesn't already have a migrated collective_script row.
     */
    private function collectiveScriptMigrationPending(): bool
    {
        return $this->legacyCollectiveScript !== null
            && $this->repository->getOptionValue('collective_script') === null;
    }

    /**
     * One-time migration of blog-config.php's legacy collective_script/
     * $coltable values into the options table, normalising the shorthand
     * ('e', 'n') to the descriptive identifier this codebase now stores
     * ('enthusiast', 'none') — see FanUpdate::normalizeCollectiveScriptIdentifier().
     * Only ever runs once per installation: collectiveScriptMigrationPending()
     * gates on the options table not already having a collective_script row,
     * so a subsequent call is a safe no-op regardless of whether
     * blog-config.php's legacy values are still present or have since been
     * removed.
     */
    private function migrateLegacyCollectiveScript(): string
    {
        $normalized = FanUpdate::normalizeCollectiveScriptIdentifier((string) $this->legacyCollectiveScript);

        $this->repository->setOptionValue(
            'collective_script',
            $normalized,
            'Which collective/fanlisting script this installation is paired with. Supported: "enthusiast" (Enthusiast), "fanatically" (Fanatically). Use "none" for standalone mode, where FanUpdate manages its own categories table entirely with no external script involved. ("listingadmin" is reserved for future Listing Admin support and is not yet functional.) Editable in Admin -> Options -> Collective Source.'
        );

        if ($this->legacyColId !== null && $this->legacyColId !== '') {
            $this->repository->setOptionValue('col_id', $this->legacyColId, 'Collective table primary key column name. Only relevant when collective_script is not "none". Expected values: Enthusiast = listingid, Fanatically = id.');
        }

        if ($this->legacyColSubj !== null && $this->legacyColSubj !== '') {
            $this->repository->setOptionValue('col_subj', $this->legacyColSubj, 'Collective table subject/name column name. Only relevant when collective_script is not "none". Expected values: Enthusiast = subject, Fanatically = subject.');
        }

        return 'Migrated collective-script configuration from blog-config.php into the database (collective_script = "' . $normalized . '").';
    }

    /**
     * Whether the one-time legacy collective_table migration still needs to
     * run: the caller supplied a legacy value to migrate FROM, and the
     * options table doesn't already have a collective_table row. Gated
     * independently of collectiveScriptMigrationPending() — an installation
     * can easily have already migrated collective_script (e.g. by changing
     * it via Admin -> Options -> Collective Source) while still never
     * having had a collective_table row at all, since that setting was
     * never exposed anywhere until now.
     */
    private function collectiveTableMigrationPending(): bool
    {
        return $this->legacyCollectiveTable !== null
            && $this->legacyCollectiveTable !== ''
            && $this->repository->getOptionValue('collective_table') === null;
    }

    /**
     * One-time migration of blog-config.php's legacy
     * $fanupdate['collective_table'] value into the options table. Only
     * ever runs once per installation: collectiveTableMigrationPending()
     * gates on the options table not already having a collective_table row,
     * so a subsequent call is a safe no-op. Once migrated, the value is
     * freely editable via Admin -> Options -> Collective Source, same as
     * collective_script/col_id/col_subj.
     */
    private function migrateLegacyCollectiveTable(): string
    {
        $this->repository->setOptionValue(
            'collective_table',
            (string) $this->legacyCollectiveTable,
            'The name of your collective/categories table. Must match the collective_script currently selected — e.g. Fanatically\'s default is "fanatic_listings" (the fanlistings this collective actually owns — NOT "fanatic_categories", which is Fanatically\'s own site-wide list of every category across the whole directory), Enthusiast\'s is "owned". For a standalone installation (collective_script = "none") this is FanUpdate\'s own categories table. Editable in Admin -> Options -> Collective Source.'
        );

        return 'Migrated collective_table configuration from blog-config.php into the database (collective_table = "' . $this->legacyCollectiveTable . '").';
    }

    /**
     * Whether the one-time FP-19 timezone_offset -> timezone migration still
     * needs to run: an old-style timezone_offset row still exists, and the
     * new 'timezone' key doesn't have one yet. Unlike the collective_*
     * migrations above, this one needs no constructor-injected legacy
     * value — the legacy value already lives in the options table itself,
     * left over from before this release.
     */
    private function timezoneMigrationPending(): bool
    {
        return $this->repository->getOptionValue('timezone_offset') !== null
            && $this->repository->getOptionValue(FU_TIMEZONE_OPTION_KEY) === null;
    }

    /**
     * One-time migration of the legacy 'timezone_offset' (whole-hour GMT
     * difference) to a real IANA 'timezone' identifier, via
     * fu_migrate_legacy_timezone_offset()'s best-effort offset->zone map
     * (functions.php). Always leaves an explicit message telling the admin
     * to double check the result — a fixed offset cannot be mapped back to
     * an exact zone with certainty, since several zones share any given
     * offset and most also observe daylight saving time that
     * timezone_offset never accounted for.
     */
    private function migrateLegacyTimezoneOffset(): string
    {
        $legacyOffset = (float) $this->repository->getOptionValue('timezone_offset');
        $migrated     = fu_migrate_legacy_timezone_offset($legacyOffset);

        $this->repository->setOptionValue(
            FU_TIMEZONE_OPTION_KEY,
            $migrated,
            'Your local IANA timezone (e.g. Europe/Helsinki). Used to display dates in your local time. Default: UTC'
        );
        $this->repository->deleteOptionIfExists('timezone_offset');

        return 'Migrated legacy timezone_offset to timezone "' . $migrated . '". '
            . 'Please review Admin -> Options and adjust it if this guess isn\'t exactly right for your location.';
    }

    /**
     * FP-25: whether entry_template still holds the exact stock value it
     * shipped with before this migration existed. False for any
     * installation that has ever saved a different entry_template — through
     * Admin -> Options -> Templates, a fresh install already seeded with
     * the new default by a current install.php, or a previous run of this
     * same migration — so this is safe to leave permanently enabled rather
     * than gating it behind `_db_version`.
     */
    private function entryTemplateMigrationPending(): bool
    {
        return $this->repository->getOptionValue('entry_template') === self::OLD_DEFAULT_ENTRY_TEMPLATE;
    }

    /**
     * One-time swap of the stock entry_template for the `.fu-entry`-wrapped
     * version. Only ever runs when entryTemplateMigrationPending() is true,
     * i.e. the stored value is still byte-for-byte the old stock default —
     * see that method's docblock for why a real customization can never be
     * matched here.
     */
    private function migrateLegacyEntryTemplateWrapper(): string
    {
        $this->repository->setOptionValue(
            'entry_template',
            self::NEW_DEFAULT_ENTRY_TEMPLATE,
            'See readme.txt for template variables.'
        );

        return 'Updated entry_template to wrap each post in <div class="fu-entry"> for per-entry '
            . 'card styling (Admin -> Category -> Get Code\'s CSS starter now includes a matching '
            . '#fanupdate .fu-entry rule). Your entry_template was still the stock default, so this '
            . 'was applied automatically — a customized entry_template is never touched by this migration.';
    }

    private function normalizedInstalledVersion(): ?string
    {
        $installedRaw = $this->repository->getOptionValue('_db_version');

        return $installedRaw !== null ? UpdateManager::normalizeVersion($installedRaw) : null;
    }

    private function isVersionBehind(string $targetVersionRaw): bool
    {
        $targetVersion    = UpdateManager::normalizeVersion($targetVersionRaw);
        $installedVersion = $this->normalizedInstalledVersion();

        return $installedVersion === null || version_compare($installedVersion, $targetVersion, '<');
    }
}
