<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeRunner
 *
 * Non-interactive post-install migrations for the Admin Update Manager:
 * option housekeeping (obsolete keys removed, new defaults seeded, gated on
 * `_db_version`), schema changes via an injected SchemaMigrator, and
 * one-time migrations of legacy blog-config.php / option values.
 *
 * install.php's interactive version-gated blocks are deliberately not
 * ported: they need a form, and their thresholds predate the PHP 8.2+
 * baseline. To extend: add new option keys to NEW_DEFAULT_OPTIONS and
 * retired ones to OBSOLETE_OPTION_KEYS.
 *
 * Each one-time migration has its own independent gate (and ignores
 * `_db_version`), so an install already on the current version still picks
 * it up exactly once.
 *
 * PHP cannot hot-reload classes mid-request, so a release's migrations must
 * run in a fresh request after the file copy (see admin/update.php's
 * `run-upgrades` action).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

require_once(__DIR__ . '/VersionNormalizer.php');
require_once(__DIR__ . '/CollectiveScriptNormalizer.php');

class UpgradeRunner
{
    /**
     * Option keys nothing reads any more; deleting an already-gone key is a no-op.
     *
     * @var array<int, string>
     */
    private const OBSOLETE_OPTION_KEYS = [
        '_server_tz_offset',
        '_last_update_check',
        '_last_update_version',
    ];

    /**
     * Options that upgrading installs need seeded with a default. Fresh
     * installs get theirs from install.php's $setting[] instead.
     *
     * @var array<string, array{value: string, description: string}>
     */
    private const NEW_DEFAULT_OPTIONS = [
        'news_feed_url' => [
            'value'       => 'https://coding.unloved-heart.net/update-blog/rss.php?c=4',
            'description' => 'RSS feed URL shown on the Dashboard as "FanUpdate Redux News". Leave blank to hide the Dashboard widget entirely.',
        ],
        // A missing row already behaves as "y"; seeding just surfaces the toggle in Options.
        'show_collective_categories' => [
            'value'       => 'y',
            'description' => 'Show categories from your collective script (Enthusiast, Fanatically, ...) alongside FanUpdate\'s own in the category list and entry category picker. Turn off to list only FanUpdate\'s own categories. Has no effect when no collective script is configured.',
        ],
        // Missing rows already behave as these defaults; seeding makes them editable.
        'log_retention_days' => [
            'value'       => '90',
            'description' => 'Number of days to keep entries in the Admin → Logs activity log. Older entries are removed automatically. Use 0 to keep them forever.',
        ],
        'log_anonymize_ips' => [
            'value'       => 'n',
            'description' => 'Anonymise IP addresses in the activity log (the last part of each address is zeroed before it is stored). Does not affect entries already logged.',
        ],
    ];

    /** The pre-`.fu-entry` stock entry_template; only used for the equality check, never written. */
    private const OLD_DEFAULT_ENTRY_TEMPLATE =
        '<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>

<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>

{{body}}';

    /** The current default; keep in sync with FanUpdate's in-code fallback and install.php's seed. */
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
        private readonly VersionNormalizer $versionNormalizer = new VersionNormalizer(),
        private readonly CollectiveScriptNormalizer $collectiveScriptNormalizer = new CollectiveScriptNormalizer(),
    ) {
    }

    /** Whether runPendingUpgrades() would do anything. Read-only and cheap enough for every admin page load. */
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
     * Brings schema/options up to $targetVersionRaw. Idempotent: each part
     * is skipped when already current.
     *
     * @param callable(string, int, int): void|null $onProgress Forwarded to
     *        SchemaMigrator::runPendingSchemaChanges().
     */
    public function runPendingUpgrades(string $targetVersionRaw, ?callable $onProgress = null): UpgradeResult
    {
        $targetVersion           = $this->versionNormalizer->normalize($targetVersionRaw);
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

    /** A legacy collective_script was supplied and no options row exists yet. */
    private function collectiveScriptMigrationPending(): bool
    {
        return $this->legacyCollectiveScript !== null
            && $this->repository->getOptionValue('collective_script') === null;
    }

    /** Moves blog-config.php's collective_script/$coltable values into the options table, normalising shorthand ('e' → 'enthusiast'). */
    private function migrateLegacyCollectiveScript(): string
    {
        $normalized = $this->collectiveScriptNormalizer->normalize((string) $this->legacyCollectiveScript);

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
     * A legacy collective_table was supplied and no options row exists yet.
     * Gated separately from collective_script: that may already be migrated
     * while collective_table never had a row.
     */
    private function collectiveTableMigrationPending(): bool
    {
        return $this->legacyCollectiveTable !== null
            && $this->legacyCollectiveTable !== ''
            && $this->repository->getOptionValue('collective_table') === null;
    }

    /** Moves blog-config.php's legacy collective_table into the options table. */
    private function migrateLegacyCollectiveTable(): string
    {
        $this->repository->setOptionValue(
            'collective_table',
            (string) $this->legacyCollectiveTable,
            'The name of your collective/categories table. Must match the collective_script currently selected — e.g. Fanatically\'s default is "fanatic_listings" (the fanlistings this collective actually owns — NOT "fanatic_categories", which is Fanatically\'s own site-wide list of every category across the whole directory), Enthusiast\'s is "owned". For a standalone installation (collective_script = "none") this is FanUpdate\'s own categories table. Editable in Admin -> Options -> Collective Source.'
        );

        return 'Migrated collective_table configuration from blog-config.php into the database (collective_table = "' . $this->legacyCollectiveTable . '").';
    }

    /** A legacy timezone_offset row exists and no 'timezone' row does yet. */
    private function timezoneMigrationPending(): bool
    {
        return $this->repository->getOptionValue('timezone_offset') !== null
            && $this->repository->getOptionValue(FU_TIMEZONE_OPTION_KEY) === null;
    }

    /**
     * Converts the legacy GMT offset to an IANA zone (best-effort guess, so
     * the returned message always asks the admin to review it).
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
     * Whether entry_template still equals the old stock default, so any
     * customised template is never touched. Compared after whitespace
     * normalisation because browsers submit textareas with CRLF line
     * endings, which install.php's option-review form round-trips.
     */
    private function entryTemplateMigrationPending(): bool
    {
        $stored = $this->repository->getOptionValue('entry_template');

        return $stored !== null
            && $this->normalizeTemplateWhitespace($stored) === $this->normalizeTemplateWhitespace(self::OLD_DEFAULT_ENTRY_TEMPLATE);
    }

    /** Normalises line endings and outer whitespace only; the markup must still match exactly. */
    private function normalizeTemplateWhitespace(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    /** Swaps the stock entry_template for the `.fu-entry`-wrapped default. */
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

        return $installedRaw !== null ? $this->versionNormalizer->normalize($installedRaw) : null;
    }

    private function isVersionBehind(string $targetVersionRaw): bool
    {
        $targetVersion    = $this->versionNormalizer->normalize($targetVersionRaw);
        $installedVersion = $this->normalizedInstalledVersion();

        return $installedVersion === null || version_compare($installedVersion, $targetVersion, '<');
    }
}
