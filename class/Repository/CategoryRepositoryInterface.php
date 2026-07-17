<?php

/*****************************************************************************
 * FanUpdate — CategoryRepositoryInterface
 *
 * Data-access contract for categories. The category domain spans two
 * tables: the "collective" table (the category's name/identity — either
 * FanUpdate's own standalone table, or an external collective script's
 * table such as Enthusiast's) and the catjoin table (per-category
 * settings: entry_template, comment_template, gravatar_*). The collective
 * table's primary-key and name columns have configurable names
 * (col_id/col_subj), so — like table names elsewhere in this layer — they
 * are injected via the repository's constructor rather than read from
 * FanUpdate::getOpt() internally.
 *
 * CategoryService (see class/Service/CategoryService.php) depends on this
 * interface rather than the concrete CategoryRepository, so unit tests can
 * substitute a test double without touching SqlConnection or a real
 * database connection. No business rules belong here — this is pure data
 * access. Whether the collective table is managed at all (standalone vs.
 * integrated mode) is a business rule that lives in CategoryService, not
 * here.
 *
 * beginTransaction()/commit()/rollBack() delegate to the underlying
 * SqlConnection, so CategoryService can wrap the two-table create/delete
 * sequences in a single transaction without taking on a second constructor
 * dependency. See CategoryService::createCategory()/deleteCategory() for
 * the transactional behaviour this enables.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface CategoryRepositoryInterface
{
    /**
     * Load a category by ID, joined against its catjoin settings row.
     *
     * @return array<string, mixed>|null The joined row, or null if it doesn't exist.
     */
    public function findById(int $catId): ?array;

    /**
     * Insert a new row into the collective table (standalone mode only).
     *
     * @return int The new category's ID, or 0 if the insert failed.
     */
    public function insertCollective(string $name): int;

    public function updateCollectiveName(int $catId, string $name): bool;

    public function deleteCollective(int $catId): bool;

    /**
     * @param array{entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $data
     */
    public function insertSettings(int $catId, array $data): bool;

    /**
     * @param array{entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $data
     */
    public function updateSettings(int $catId, array $data): bool;

    public function deleteSettings(int $catId): bool;

    /**
     * Run the category listing (collective table LEFT JOINed against the
     * catjoin table for an entry count per category) on the underlying
     * SqlConnection. Results are read afterwards via the connection's
     * existing NumRows()/ReadRecord()/FreeResult() cursor — the category
     * list is not paginated, matching the original admin/category.php
     * behaviour exactly.
     */
    public function listAll(): void;

    /**
     * Transaction control, delegated to the underlying SqlConnection. See
     * class docblock for why this lives on the repository interface rather
     * than requiring callers to depend on SqlConnection directly.
     */
    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;
}
