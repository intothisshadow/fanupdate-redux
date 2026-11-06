<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — PrivacyRepositoryInterface
 *
 * Data-access contract for the install ping, stored as JSON blobs under two
 * private options keys (hidden from the editable Options list):
 *  - `_privacy_config`: the opt-in preference and installation UUID, which
 *    is generated once and never regenerated, even across disable/re-enable.
 *  - `_privacy_state`: the latest ping attempt, overwritten on each run.
 * InstallPingService depends on this interface so it can be tested with a fake.
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
