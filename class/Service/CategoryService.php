<?php

/*****************************************************************************
 * FanUpdate — CategoryService
 *
 * Business logic for managing categories. Depends on
 * CategoryRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository.
 *
 * Categories Support (hybrid): standalone and collective-sourced
 * categories now coexist rather than being mutually exclusive modes. The
 * business rule this class enforces is per-category, not page-wide:
 *
 *  - createCategory() always creates a NEW standalone category — there is
 *    no "create a collective category" action; collective-sourced
 *    categories only ever arrive via that script's own sync (a separate,
 *    not-yet-built feature — see TODO.md's Categories Support integrated-
 *    mode sections). This works identically whether or not a collective
 *    script is configured: the repository's insertStandalone() already
 *    knows how to fall back to the single-table behaviour when there is no
 *    dedicated standalone table (collective_script = 'n').
 *  - updateCategory() renames the category only when it is standalone
 *    ($categories->isStandaloneId()) — a collective-sourced category's
 *    identity is owned by its source script and is read-only here. The
 *    catjoin settings (templates, gravatar) are always editable for any
 *    category regardless of source; that has been true since before
 *    Categories Support and is unchanged.
 *  - deleteCategory() refuses outright (returns false, touches nothing)
 *    for a collective-sourced category — read-only except through the
 *    explicit "confirm deletion" flow described in TODO.md, which is
 *    integration-specific and not implemented by this generic service.
 *    Deletion of a standalone category is unchanged: both the standalone
 *    row and its catjoin settings row are removed atomically.
 *
 * Per-mode behaviour before Categories Support (documented for context —
 * superseded by the above): "standalone vs. integrated" used to be a single
 * page-wide flag gating whether the add form did anything at all. That
 * flag no longer exists; every category now individually reports its own
 * source via isStandaloneCategory().
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
     * Create a new standalone category. Works the same whether or not a
     * collective script is configured — see class docblock.
     *
     * @param array{name:string,entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $clean
     * @return array{cat_id:?int,standalone_insert_ok:?bool,settings_ok:?bool}
     *   The collective/standalone insert and the settings insert are
     *   wrapped in one transaction: if the settings insert fails, the
     *   identity insert is rolled back too, so cat_id/standalone_insert_ok
     *   reflect "nothing was persisted" rather than a half-created
     *   category.
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
            // Roll back the identity insert too — a category with no
            // template settings is a partial write, not a usable category.
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
     * Update a category's settings, and — only when it is standalone — its
     * name too. Collective-sourced categories' names are read-only; see
     * class docblock.
     *
     * @param array{name:string,entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $clean
     */
    public function updateCategory(int $catId, array $clean): bool
    {
        if ($this->categories->isStandaloneId($catId)) {
            // Result deliberately not checked — only the catjoin settings
            // update below determines success/failure. See class docblock.
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
     * Deletes a standalone category's identity row and its catjoin
     * settings row atomically: both must succeed for the delete to commit,
     * otherwise the transaction rolls back and neither row is removed.
     *
     * Collective-sourced categories are read-only and refused outright —
     * nothing is attempted against the repository at all. See class
     * docblock.
     *
     * @return bool True if both rows were deleted; false if either delete
     *              failed (in which case neither was removed), or if
     *              $catId is not a standalone category.
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
     * Whether $catId is a standalone (FanUpdate-managed) category, as
     * opposed to one synced from a collective script. Used by
     * admin/category.php to decide whether to show the name field/delete
     * action as editable, and by admin/blog.php's category picker to label
     * each option's source.
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
     * Run the (unpaginated) category listing. Results are read afterwards
     * via the shared SqlConnection's NumRows()/ReadRecord()/FreeResult()
     * cursor.
     */
    public function listAll(): void
    {
        $this->categories->listAll();
    }

    /**
     * Simple array form of the category listing for dropdown/select
     * pickers (e.g. admin/blog.php) that don't need the manual cursor
     * pattern listAll() uses.
     *
     * @return list<array{cid:int,cname:string,num:int}>
     */
    public function listForDropdown(): array
    {
        return $this->categories->listForDropdown();
    }

    /**
     * Integrated Mode (Fanatically/Enthusiast/Listing Admin): cheap,
     * read-only check for whether any collective-sourced category that
     * FanUpdate holds settings for has since been deleted upstream in the
     * collective script. Safe to call on every Dashboard/Categories page
     * load, same bar as SchemaMigrator::hasPendingSchemaChanges()/
     * UpgradeRunner::hasPendingUpgrades() — one indexed LEFT JOIN, no
     * network call.
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
     * Delete an orphaned collective-sourced category's FanUpdate-side
     * settings row, after the admin has explicitly confirmed via the
     * "notify the user and request confirmation before deleting the
     * corresponding category in FanUpdate" flow (TODO.md's Integrated Mode
     * sections). Deliberately does NOT touch catjoin_table (post-to-
     * category assignments) — existing entries stay filed under $catId,
     * matching how a live collective-sourced category's deletion is
     * already handled (deleteCategory() refuses to touch catjoin either);
     * only the settings row this class actually owns is removed.
     *
     * Re-checks that $catId is genuinely orphaned before touching
     * anything, rather than trusting the caller — defence in depth so a
     * stale or forged request can't delete a still-live category's
     * settings out from under it.
     *
     * @return bool True if the settings row was deleted; false if $catId
     *              is not (or no longer) orphaned, or the delete failed.
     */
    public function confirmDeleteOrphanedCategory(int $catId): bool
    {
        if (!in_array($catId, $this->categories->listOrphanedCollectiveCategoryIds(), true)) {
            return false;
        }

        return $this->categories->deleteSettings($catId);
    }
}
