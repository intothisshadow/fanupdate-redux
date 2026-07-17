<?php

/*****************************************************************************
 * FanUpdate — CategoryService
 *
 * Business logic for managing categories. Depends on
 * CategoryRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository.
 *
 * "Standalone vs. integrated" mode (the collective_script option) is a
 * genuine business rule, not presentation, so it lives here: whether the
 * collective table (category name/identity) is managed by FanUpdate at all
 * depends on it.
 *
 * Per-mode behaviour:
 *  - createCategory(): in standalone mode, a collective-table row is
 *    inserted first, and the catjoin settings are only inserted if that
 *    succeeds; both writes run inside one transaction, so if the settings
 *    insert fails, the collective insert is rolled back too — cat_id/
 *    standalone_insert_ok reflect "nothing was persisted" rather than a
 *    half-created category. In integrated mode, the collective insert is
 *    skipped entirely and — since the add form does not submit a cat_id in
 *    that mode — the catjoin settings insert never runs either; the "add"
 *    action is effectively a no-op with no success/error message.
 *  - updateCategory(): the collective-table name update is attempted in
 *    standalone mode, but its result is not checked or reported — only the
 *    catjoin settings update's result determines success/failure. This is
 *    existing behaviour, not an oversight.
 *  - deleteCategory(): deletes the collective-table row and the catjoin
 *    settings row atomically (both must succeed for the delete to commit).
 *    Callers (admin/category.php) are responsible for only invoking this
 *    in standalone mode, exactly as the admin page gates the delete action
 *    (and hides the delete button) behind `!collective_script`; this
 *    service does not re-check the flag.
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
     * @param array{name:string,entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $clean
     * @return array{cat_id:?int,standalone_insert_ok:?bool,settings_ok:?bool}
     *   In integrated mode ($standalone === false), nothing is attempted
     *   and every key is null — matches the original no-op behaviour.
     *   In standalone mode, the collective insert and the settings insert
     *   are wrapped in one transaction: if the settings insert fails, the
     *   collective insert is rolled back too, so cat_id/standalone_insert_ok
     *   reflect "nothing was persisted" rather than a half-created category.
     */
    public function createCategory(array $clean, bool $standalone): array
    {
        if (!$standalone) {
            // Integrated mode: no cat_id ever arrives via $_POST on the add
            // form, so this is a no-op — no success or error message, no
            // repository access at all.
            return [
                'cat_id'               => null,
                'standalone_insert_ok' => null,
                'settings_ok'          => null,
            ];
        }

        $this->categories->beginTransaction();

        $newId              = $this->categories->insertCollective($clean['name']);
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
            // Roll back the collective insert too — a category with no
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
     * @param array{name:string,entry_template:string,comment_template:string,gravatar_default:string,gravatar_size:int,gravatar_rating:string} $clean
     */
    public function updateCategory(int $catId, array $clean, bool $standalone): bool
    {
        if ($standalone) {
            // Result deliberately not checked — only the catjoin settings
            // update below determines success/failure. See class docblock.
            $this->categories->updateCollectiveName($catId, $clean['name']);
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
     * Deletes the category's collective-table row and its catjoin settings
     * row atomically: both must succeed for the delete to commit,
     * otherwise the transaction rolls back and neither row is removed.
     * Callers must only invoke this in standalone mode — see class
     * docblock.
     *
     * @return bool True if both rows were deleted; false if either delete
     *              failed (in which case neither was removed).
     */
    public function deleteCategory(int $catId): bool
    {
        $this->categories->beginTransaction();

        $collectiveOk = $this->categories->deleteCollective($catId);
        $settingsOk   = $this->categories->deleteSettings($catId);

        if ($collectiveOk && $settingsOk) {
            $this->categories->commit();
            return true;
        }

        $this->categories->rollBack();
        return false;
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
}
