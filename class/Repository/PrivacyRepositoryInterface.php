<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — PrivacyRepositoryInterface
 *
 * Data-access contract for the Opt-In Anonymous Install Ping's persisted
 * state (TODO.md "FP-15"). Storage piggybacks on the existing options table
 * via two private (underscore-prefixed) keys — `_privacy_config` and
 * `_privacy_state` — each holding a JSON blob, the same pattern
 * UpdateRepositoryInterface already uses for the Admin Update Manager.
 * Private keys are already excluded from admin/options.php's editable list
 * and from OptionRepository::listEditableOptions(), so this data never
 * appears there.
 *
 * `_privacy_config` holds the user's opt-in preference and the installation
 * UUID, both of which persist until explicitly changed — the UUID is
 * generated once on first opt-in and never regenerated afterwards, even
 * across a later disable/re-enable. `_privacy_state` holds the result of the
 * most recent ping attempt and is overwritten each time
 * InstallPingService::maybePingIfDue()/pingNow() runs.
 *
 * InstallPingService (see class/Service/InstallPingService.php) depends on
 * this interface rather than the concrete PrivacyRepository, so it can be
 * unit tested with a fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

interface PrivacyRepositoryInterface
{
    /**
     * @return array{enabled?: bool, installUuid?: string}
     */
    public function getConfig(): array;

    /** @param array<string, mixed> $config */
    public function saveConfig(array $config): bool;

    /**
     * @return array{lastPingAt?: string, lastPingStatus?: string, lastPingError?: string|null}
     */
    public function getState(): array;

    /** @param array<string, mixed> $state */
    public function saveState(array $state): bool;
}
