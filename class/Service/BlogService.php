<?php

/*****************************************************************************
 * FanUpdate — BlogService
 *
 * Business logic for managing blog entries: creating/updating/deleting an
 * entry, and keeping its catjoin category link in sync (the blog table
 * itself has no cat_id column). Depends on BlogRepositoryInterface
 * (constructor injection), not a concrete repository or the FanUpdate
 * singleton, so it can be unit tested with a fake/mock repository.
 *
 * createEntry()/updateEntry() wrap the entry write and its category-link
 * write(s) in a single transaction via BlogRepositoryInterface's
 * beginTransaction()/commit()/rollBack(): if any step fails, the whole
 * operation rolls back — createEntry() returns 0 and updateEntry() returns
 * false, exactly as they already do for a failed primary write. The
 * atomicity for delete lives in BlogRepository::delete() itself, since all
 * three of its deletes are internal to that one repository method.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class BlogService
{
    public function __construct(
        private readonly BlogRepositoryInterface $entries,
    ) {
    }

    /**
     * @param array{title:string,body:string,cat:int,is_public:int,comments_on:int,date:string} $clean
     * @return int The new entry's ID, or 0 if the insert failed or the
     *             category link could not be established.
     */
    public function createEntry(array $clean): int
    {
        $this->entries->beginTransaction();

        $entryId = $this->entries->insert($clean);

        if ($entryId <= 0) {
            $this->entries->rollBack();
            return 0;
        }

        if ($clean['cat'] > 0 && !$this->entries->setCategory($entryId, $clean['cat'])) {
            $this->entries->rollBack();
            return 0;
        }

        $this->entries->commit();

        return $entryId;
    }

    /**
     * @param array{title:string,body:string,cat:int,is_public:int,comments_on:int,date:string} $clean
     */
    public function updateEntry(int $entryId, array $clean): bool
    {
        $this->entries->beginTransaction();

        if (!$this->entries->update($entryId, $clean)) {
            $this->entries->rollBack();
            return false;
        }

        // The category link is always fully replaced (clear then
        // re-insert) rather than diffed — an entry has at most one
        // category today, and this matches the original
        // admin/blog.php behaviour exactly.
        if (!$this->entries->clearCategory($entryId)) {
            $this->entries->rollBack();
            return false;
        }

        if ($clean['cat'] > 0 && !$this->entries->setCategory($entryId, $clean['cat'])) {
            $this->entries->rollBack();
            return false;
        }

        $this->entries->commit();

        return true;
    }

    public function deleteEntry(int $entryId): bool
    {
        return $this->entries->delete($entryId);
    }

    /**
     * @param int[] $entryIds
     * @return int Number of entries successfully deleted.
     */
    public function deleteEntries(array $entryIds): int
    {
        return $this->entries->deleteMany($entryIds);
    }

    /**
     * @return array<string, mixed>|null Entry row merged with its resolved
     *         'cat_id' key, or null when the entry doesn't exist.
     */
    public function getEntryForEdit(int $entryId): ?array
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            return null;
        }

        $catId = $this->entries->findCategoryId($entryId);
        if ($catId !== null) {
            $entry['cat_id'] = $catId;
        }

        return $entry;
    }

    /**
     * @return array{id:int,title:string}|null Null when the entry no longer exists.
     */
    public function getDeleteConfirmation(int $entryId): ?array
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            return null;
        }

        return ['id' => $entryId, 'title' => (string) $entry['title']];
    }

    /**
     * @param int[] $entryIds
     * @return array<int, array{entry_id:int,title:string}>
     */
    public function getBulkDeleteConfirmation(array $entryIds): array
    {
        return $this->entries->findTitlesByIds($entryIds);
    }

    /**
     * Run a filtered, paginated entry listing. Results are read afterwards
     * via the shared SqlConnection's ReadRecordPaginate()/PrintPaginate()
     * cursor, exactly as before this refactor — only the query construction
     * moved into the repository.
     */
    public function search(int $filterCatId, string $filterSearch, int $perPage): void
    {
        $this->entries->search($filterCatId, $filterSearch, $perPage);
    }
}
