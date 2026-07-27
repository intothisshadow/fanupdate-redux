<?php

/*****************************************************************************
 * FanUpdate — OptionRepositoryInterface
 *
 * Data-access contract for the general (non-template, non-credential) rows
 * of the options table (mirrors TemplateRepositoryInterface, which covers the
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
     * containing "template"), the admin_email/admin_username/admin_password
     * rows (see admin/account.php), and the collective-source keys
     * (collective_script/col_id/col_subj/collective_table — see
     * admin/collective-source.php), ordered alphabetically by optkey.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEditableOptions(): array;

    /**
     * Update a single option's value by key — an upsert (INSERT ... ON
     * DUPLICATE KEY UPDATE): if no row exists yet for $key, one is created
     * (with an empty optdesc); otherwise only optvalue is touched, leaving
     * the existing optdesc alone.
     *
     * @return int|null The number of affected rows (0 or more) on success,
     *         or null when the query itself failed to execute.
     */
    public function updateOption(string $key, string $value): ?int;
}
