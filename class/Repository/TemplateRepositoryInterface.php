<?php

/*****************************************************************************
 * FanUpdate — TemplateRepositoryInterface
 *
 * Data-access contract for the template-flavoured rows of the options
 * table (mirrors OptionRepositoryInterface, BlogRepositoryInterface,
 * CommentRepositoryInterface, CategoryRepositoryInterface,
 * BlacklistRepositoryInterface, and SmilieRepositoryInterface).
 * TemplateService (see class/Service/TemplateService.php) depends on this
 * interface rather than the concrete TemplateRepository, so unit tests can
 * substitute a test double without touching SqlConnection or a real
 * database connection. No business rules or presentation formatting
 * belong here — this is pure data access. The "skip private
 * (underscore-prefixed) keys" rule stays in admin/templates.php — it's
 * request-level validation, not a repository concern.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface TemplateRepositoryInterface
{
    /**
     * Every options-table row whose optkey contains "template", ordered
     * alphabetically by optkey.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTemplateOptions(): array;

    /**
     * Update a single option's value by key.
     *
     * @return int|null The number of affected rows (0 or more) on success,
     *         or null when the query itself failed to execute.
     */
    public function updateOption(string $key, string $value): ?int;
}
