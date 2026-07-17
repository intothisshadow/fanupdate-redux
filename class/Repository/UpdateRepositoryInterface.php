<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpdateRepositoryInterface
 *
 * Data-access contract for the Admin Update Manager's persisted state.
 * Storage piggybacks on the existing options table via three private
 * (underscore-prefixed) keys — `_update_config`, `_update_state`, and
 * `_update_log` — each holding a JSON blob, rather than adding new tables
 * or a migration. Private keys are already excluded from
 * admin/options.php's editable list and from
 * OptionRepository::listEditableOptions(), so this data never appears
 * there.
 *
 * `_update_config` holds user preferences that persist until explicitly
 * changed (release channel, optional GitHub token). `_update_state` holds
 * the result of the most recent update check and is overwritten each time
 * UpdateManager::checkForUpdates() runs — this doubles as the response
 * cache that lets the Dashboard notice and the Update Overview panel show
 * status without making a live GitHub API call on every page load.
 * `_update_log` holds a bounded (most-recent-N) history of check/download/
 * install/rollback events, each appended to rather than overwritten — see
 * UpdateManager's logging methods.
 *
 * UpdateManager (see class/Service/UpdateManager.php) depends on this
 * interface rather than the concrete UpdateRepository, so it can be unit
 * tested with a fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

interface UpdateRepositoryInterface
{
    /** @return array<string, mixed> */
    public function getConfig(): array;

    /** @param array<string, mixed> $config */
    public function saveConfig(array $config): bool;

    /** @return array<string, mixed> */
    public function getState(): array;

    /** @param array<string, mixed> $state */
    public function saveState(array $state): bool;

    /**
     * @return array<int, array<string, mixed>> Log entries, most recent
     *         first. Each entry has at least 'timestamp' (ISO 8601 UTC),
     *         'type' ('check'|'download'|'install'|'rollback'), 'status'
     *         ('ok'|'error'), and 'message' (a short human-readable
     *         summary). Failures are entries with status 'error' within
     *         their relevant type, not a separate type of their own.
     */
    public function getLog(): array;

    /** @param array<int, array<string, mixed>> $entries */
    public function saveLog(array $entries): bool;
}
