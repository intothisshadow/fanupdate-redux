<?php

/*****************************************************************************
 * FanUpdate — CategoryService
 *
 * Business logic for managing categories. Depends on
 * CategoryRepositoryInterface, not a concrete repository or the FanUpdate
 * singleton, so it can be unit tested with a fake.
 *
 * Standalone and collective-sourced categories coexist; the rules are
 * per-category (see isStandaloneCategory()):
 *  - createCategory() always creates a standalone category; collective ones
 *    only arrive from that script's own sync.
 *  - updateCategory() renames only standalone categories (a collective
 *    category's identity belongs to its source script). Catjoin settings
 *    (templates, gravatar) are editable for any category.
 *  - deleteCategory() refuses collective categories outright. A standalone
 *    delete removes its identity and settings rows atomically.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
    ) {
    }

    /**
     * Creates a standalone category, whether or not a collective script is
     * configured.
     *
     * @param array{name:string,entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $clean
     * @return array{cat_id:?int,standalone_insert_ok:?bool,settings_ok:?bool}
     *   Both inserts share one transaction: if the settings insert fails the
     *   identity insert is rolled back, so nothing is half-created.
     */
    public function createCategory(array $clean): array
    {
        $this->categories->beginTransaction();

        $newId              = $this->categories->insertStandalone($clean['name']);
        $standaloneInsertOk = $newId > 0;

        if (!$standaloneInsertOk) {
            $this->categories->rollBack();

            return [
                'cat_id'               => null,
                'standalone_insert_ok' => false,
                'settings_ok'          => null,
            ];
        }

        $catId      = $newId;
        $settingsOk = $this->categories->insertSettings($catId, [
            'entry_template'   => $clean['entry_template'],
            'comment_template' => $clean['comment_template'],
            'gravatar_default' => $clean['gravatar_default'],
            'gravatar_size'    => $clean['gravatar_size'],
            'gravatar_rating'  => $clean['gravatar_rating'],
        ]);

        if (!$settingsOk) {
            $this->categories->rollBack();

            return [
                'cat_id'               => null,
                'standalone_insert_ok' => false,
                'settings_ok'          => false,
            ];
        }

        $this->categories->commit();

        return [
            'cat_id'               => $catId,
            'standalone_insert_ok' => true,
            'settings_ok'          => true,
        ];
    }

    /**
     * Updates a category's settings, and its name only when standalone.
     *
     * @param array{name:string,entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $clean
     */
    public function updateCategory(int $catId, array $clean): bool
    {
        if ($this->categories->isStandaloneId($catId)) {
            // Result unchecked on purpose: only the settings update decides success.
            $this->categories->updateStandaloneName($catId, $clean['name']);
        }

        return $this->categories->updateSettings($catId, [
            'entry_template'   => $clean['entry_template'],
            'comment_template' => $clean['comment_template'],
            'gravatar_default' => $clean['gravatar_default'],
            'gravatar_size'    => $clean['gravatar_size'],
            'gravatar_rating'  => $clean['gravatar_rating'],
        ]);
    }

    /**
     * Deletes a standalone category's identity and settings rows atomically.
     * Collective categories are refused without touching the repository.
     *
     * @return bool True if both rows were deleted; false if either failed
     *              (neither removed) or $catId isn't standalone.
     */
    public function deleteCategory(int $catId): bool
    {
        if (!$this->categories->isStandaloneId($catId)) {
            return false;
        }

        $this->categories->beginTransaction();

        $identityOk = $this->categories->deleteStandalone($catId);
        $settingsOk = $this->categories->deleteSettings($catId);

        if ($identityOk && $settingsOk) {
            $this->categories->commit();
            return true;
        }

        $this->categories->rollBack();
        return false;
    }

    /**
     * Whether $catId is FanUpdate-managed rather than synced from a collective script.
     */
    public function isStandaloneCategory(int $catId): bool
    {
        return $this->categories->isStandaloneId($catId);
    }

    /**
     * @return array<string, mixed>|null The joined category row, or null when it doesn't exist.
     */
    public function getCategoryForEdit(int $catId): ?array
    {
        return $this->categories->findById($catId);
    }

    /**
     * Runs the unpaginated listing; read results from the shared SqlConnection cursor.
     */
    public function listAll(): void
    {
        $this->categories->listAll();
    }

    /**
     * Array form of the listing for dropdowns (no manual cursor needed).
     *
     * @return list<array{cid:int,cname:string,num:int}>
     */
    public function listForDropdown(): array
    {
        return $this->categories->listForDropdown();
    }

    /**
     * Whether any collective category FanUpdate holds settings for has been
     * deleted upstream. One indexed LEFT JOIN, cheap enough for every page load.
     */
    public function hasOrphanedCollectiveCategories(): bool
    {
        return $this->categories->listOrphanedCollectiveCategoryIds() !== [];
    }

    /**
     * @return list<int>
     */
    public function listOrphanedCollectiveCategoryIds(): array
    {
        return $this->categories->listOrphanedCollectiveCategoryIds();
    }

    /**
     * Deletes an orphaned collective category's settings row after admin
     * confirmation. Entries stay filed under $catId (catjoin_table is not
     * touched). Re-checks the category is orphaned so a stale or forged
     * request can't delete a live category's settings.
     *
     * @return bool True if deleted; false if $catId isn't orphaned or the delete failed.
     */
    public function confirmDeleteOrphanedCategory(int $catId): bool
    {
        if (!in_array($catId, $this->categories->listOrphanedCollectiveCategoryIds(), true)) {
            return false;
        }

        return $this->categories->deleteSettings($catId);
    }
}
