<?php

/*****************************************************************************
 * FanUpdate — BlogRepositoryInterface
 *
 * Data-access contract for blog entries. Service classes (see
 * class/Service/BlogService.php) depend on this interface rather than the
 * concrete BlogRepository, so unit tests can substitute a test double
 * without touching SqlConnection or a real database connection. No
 * business rules belong here — this is pure data access. Validation,
 * category-link orchestration, etc. live in BlogService.
 *
 * setCategory()/clearCategory() return bool so a failed write is observable
 * by callers. beginTransaction()/commit()/rollBack() delegate to the
 * underlying SqlConnection and are exposed on this interface (rather than
 * requiring BlogService to take a second SqlConnection constructor
 * dependency) so BlogService can wrap a multi-step sequence (entry write +
 * category link) in a single transaction while still depending only on
 * this interface. See BlogRepository::delete() and
 * BlogService::createEntry()/updateEntry() for the transactional behaviour
 * this enables.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface BlogRepositoryInterface
{
    /**
     * @return array<string, mixed>|null The entry row, or null if it doesn't exist.
     */
    public function findById(int $entryId): ?array;

    /**
     * The blog table has no cat_id column — category membership lives in
     * the catjoin table. An entry may belong to any number of categories
     * (including none), so this returns every linked cat_id.
     *
     * @return int[]
     */
    public function findCategoryIds(int $entryId): array;

    /**
     * @param array{title:string,body:string,is_public:int,comments_on:int,date:string} $data
     * @return int The new entry's ID, or 0 if the insert failed.
     */
    public function insert(array $data): int;

    /**
     * @param array{title:string,body:string,is_public:int,comments_on:int,date:string} $data
     */
    public function update(int $entryId, array $data): bool;

    /**
     * Deletes the entry along with its catjoin row(s) and comments,
     * atomically: if any of the three deletes fails, the whole operation
     * rolls back and no rows are removed. See BlogRepository::delete().
     */
    public function delete(int $entryId): bool;

    /**
     * @param int[] $entryIds
     * @return int Number of entries successfully deleted.
     */
    public function deleteMany(array $entryIds): int;

    public function setCategory(int $entryId, int $catId): bool;

    public function clearCategory(int $entryId): bool;

    /**
     * @param int[] $entryIds
     * @return array<int, array{entry_id:int,title:string}>
     */
    public function findTitlesByIds(array $entryIds): array;

    /**
     * Run a filtered, paginated entry listing on the underlying
     * SqlConnection. Results are read afterwards via the connection's
     * existing ReadRecordPaginate()/PrintPaginate()/total_results cursor —
     * the same pagination pattern used throughout the rest of the codebase —
     * rather than being returned directly, so callers don't have to change
     * how they iterate paginated results.
     */
    public function search(int $filterCatId, string $filterSearch, int $perPage): void;

    /**
     * Transaction control, delegated to the underlying SqlConnection. See
     * class docblock for why this lives on the repository interface rather
     * than requiring callers to depend on SqlConnection directly.
     */
    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;
}
