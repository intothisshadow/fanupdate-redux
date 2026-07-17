<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeRepositoryInterface
 *
 * Data-access contract for UpgradeRunner's post-install database
 * migrations (TODO.md "1. Admin Update Manager" — Installation Engine,
 * "Post Install" > "Run upgrade scripts"). Deliberately narrow — just the
 * three primitive operations a version-gated option migration actually
 * needs (read one option, upsert one option, delete one option if it
 * exists) — rather than a general-purpose options repository, since that's
 * already OptionRepositoryInterface's job for the admin-facing Options
 * page.
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
