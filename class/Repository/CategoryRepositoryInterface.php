<?php

/*****************************************************************************
 * FanUpdate — CategoryRepositoryInterface
 *
 * Data-access contract for categories, spanning up to four tables:
 *
 *  - "collective": FanUpdate's own categories table, or an external
 *    script's (Enthusiast/Fanatically/...); key and name columns are
 *    configurable (col_id/col_subj).
 *  - "standalone": a FanUpdate-owned table (`id`/`name`), used only when a
 *    collective script is configured, so admin-created categories coexist
 *    with synced ones. With no standalone table ($standaloneTable === null)
 *    every method delegates to the collective table, which is then the only
 *    store.
 *  - catoptions: per-category settings, one row per category keyed by
 *    `cat_id` in the shared ID space.
 *  - catjoin: post-to-category assignments, used only for listAll()'s entry
 *    counts. Not the same table as catoptions.
 *
 * ID space: standalone categories are addressed everywhere outside this
 * class as `row id + CategoryRepository::STANDALONE_ID_OFFSET`, while
 * collective categories keep their native IDs, so the two never collide.
 * isStandaloneId() is the single source of truth for which space an ID
 * belongs to.
 *
 * CategoryService depends on this interface so tests can substitute a double.
 * It is pure data access; which categories may be created, renamed or
 * deleted is a business rule in CategoryService. beginTransaction()/commit()/
 * rollBack() delegate to SqlConnection so the service can wrap multi-table
 * sequences in one transaction.
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
     * Loads a category joined to its catoptions row, resolving against the
     * standalone or collective table by ID space.
     *
     * @return array<string, mixed>|null The joined row, or null if it doesn't exist.
     */
    public function findById(int $catId): ?array;

    /**
     * Inserts directly into the collective table; only meaningful when it is
     * the standalone store. Callers should use insertStandalone().
     *
     * @return int The new category's ID, or 0 if the insert failed.
     */
    public function insertCollective(string $name): int;

    public function updateCollectiveName(int $catId, string $name): bool;

    public function deleteCollective(int $catId): bool;

    /**
     * Creates a standalone category: in the standalone table (ID shifted into
     * its space) when configured, otherwise via insertCollective().
     *
     * @return int The new category's public-facing ID, or 0 on failure.
     */
    public function insertStandalone(string $name): int;

    public function updateStandaloneName(int $catId, string $name): bool;

    public function deleteStandalone(int $catId): bool;

    /**
     * Whether $catId is in the standalone ID space (always true without a
     * dedicated standalone table).
     */
    public function isStandaloneId(int $catId): bool;

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
     * Runs the unpaginated listing; read results from the SqlConnection
     * cursor. With a standalone table it is a UNION ALL of both tables
     * (isStandaloneId() reveals each row's source).
     */
    public function listAll(): void;

    /**
     * listAll() as an array, for dropdown callers that don't need the cursor.
     *
     * @return list<array{cid:int,cname:string,num:int}>
     */
    public function listForDropdown(): array;

    /**
     * Native collective cat_ids that have a catoptions row but no longer
     * exist in the collective table (deleted upstream). Listing is always
     * live, so a leftover settings row is the only thing that can go stale.
     * Empty unless a standalone table is configured.
     *
     * @return list<int>
     */
    public function listOrphanedCollectiveCategoryIds(): array;

    /** Transaction control, delegated to SqlConnection. */
    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;
}
