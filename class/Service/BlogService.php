<?php

/*****************************************************************************
 * FanUpdate — BlogService
 *
 * Business logic for blog entries and their catjoin category links (the
 * blog table has no cat_id column). Depends on BlogRepositoryInterface, so
 * it is unit-testable with a fake.
 *
 * createEntry()/updateEntry() run the entry write and its category-link
 * writes in one transaction; any failure rolls back (0 / false). Delete
 * atomicity lives in BlogRepository::delete().
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
     * @param array{title:string,body:string,cat:int[],is_public:int,comments_on:int,date:string} $clean
     * @return int The new entry's ID, or 0 if the insert failed or any
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

        foreach ($clean['cat'] as $catId) {
            if (!$this->entries->setCategory($entryId, $catId)) {
                $this->entries->rollBack();
                return 0;
            }
        }

        $this->entries->commit();

        return $entryId;
    }

    /**
     * @param array{title:string,body:string,cat:int[],is_public:int,comments_on:int,date:string} $clean
     */
    public function updateEntry(int $entryId, array $clean): bool
    {
        $this->entries->beginTransaction();

        if (!$this->entries->update($entryId, $clean)) {
            $this->entries->rollBack();
            return false;
        }

        // Links are replaced wholesale rather than diffed; entries have few categories.
        if (!$this->entries->clearCategory($entryId)) {
            $this->entries->rollBack();
            return false;
        }

        foreach ($clean['cat'] as $catId) {
            if (!$this->entries->setCategory($entryId, $catId)) {
                $this->entries->rollBack();
                return false;
            }
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
     *         'cat_ids' key (int[], possibly empty), or null when the entry
     *         doesn't exist.
     */
    public function getEntryForEdit(int $entryId): ?array
    {
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            return null;
        }

        $entry['cat_ids'] = $this->entries->findCategoryIds($entryId);

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
     * Runs a filtered, paginated listing; read results from the shared
     * SqlConnection's ReadRecordPaginate()/PrintPaginate() cursor.
     */
    public function search(int $filterCatId, string $filterSearch, int $perPage): void
    {
        $this->entries->search($filterCatId, $filterSearch, $perPage);
    }
}
