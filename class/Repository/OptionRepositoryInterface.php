<?php

/*****************************************************************************
 * FanUpdate — OptionRepositoryInterface
 *
 * Data-access contract for the general (non-template, non-email) rows of
 * the options table (mirrors TemplateRepositoryInterface, which covers the
 * complementary template-flavoured rows of the same table). OptionService
 * (see class/Service/OptionService.php) depends on this interface rather
 * than the concrete OptionRepository, so unit tests can substitute a test
 * double without touching SqlConnection or a real database connection.
 *
 * No business rules or presentation formatting belong here — this is pure
 * data access. The skip-private-keys rule, the explicit
 * admin_password/admin_username/admin_email/version deny-list, and the
 * y/n-toggle-vs-textarea-vs-text-input rendering choice all stay in
 * admin/options.php — those are request-level validation and presentation
 * concerns, not a repository concern.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface OptionRepositoryInterface
{
    /**
     * Every options-table row except template-flavoured ones (optkey
     * containing "template") and the admin_email row, ordered
     * alphabetically by optkey.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEditableOptions(): array;

    /**
     * Update a single option's value by key.
     *
     * @return int|null The number of affected rows (0 or more) on success,
     *         or null when the query itself failed to execute.
     */
    public function updateOption(string $key, string $value): ?int;
}
