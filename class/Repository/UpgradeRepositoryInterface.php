<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeRepositoryInterface
 *
 * Data-access contract for UpgradeRunner's migrations. Deliberately narrow
 * (read, upsert, delete-if-exists one option); the admin Options page uses
 * OptionRepositoryInterface instead.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

interface UpgradeRepositoryInterface
{
    /** The stored value for $key, or null if no such option row exists. */
    public function getOptionValue(string $key): ?string;

    /**
     * Insert $key with $value/$description, or update its value if the
     * key already exists (an upsert — $key is the options table's primary
     * key). Returns whether the query succeeded (not whether a row
     * actually changed value).
     */
    public function setOptionValue(string $key, string $value, string $description = ''): bool;

    /** Delete $key if it exists. Returns true only if a row was actually removed. */
    public function deleteOptionIfExists(string $key): bool;
}
