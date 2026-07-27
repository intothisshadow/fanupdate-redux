<?php

/*****************************************************************************
 * FanUpdate — CategoryRepositoryInterface
 *
 * Data-access contract for categories. As of Categories Support (hybrid),
 * the category domain spans up to FOUR tables:
 *
 *  - The "collective" table — either FanUpdate's own default categories
 *    table (when collective_script is 'n', unchanged since Milestone 4),
 *    or an external collective script's table (Enthusiast/Fanatically/
 *    Listing Admin/etc.) when integrated. Its primary-key and name columns
 *    have configurable names (col_id/col_subj), injected via the
 *    repository's constructor, exactly as before.
 *  - The "standalone" table — a NEW, always-FanUpdate-owned table
 *    (fixed `id`/`name` columns) that only exists/is used when a
 *    collective script IS configured. It lets admin-created categories
 *    coexist alongside the collective's synced ones instead of being
 *    mutually exclusive with them. When no collective script is configured
 *    ($standaloneTable === null at construction), every method here
 *    transparently delegates to the existing collective-table methods —
 *    there is only ever one category store, exactly as before Categories
 *    Support. This is what makes hybrid mode fully additive: a plain
 *    standalone installation's SQL is byte-for-byte unchanged.
 *  - The catoptions table — per-category settings (entry_template,
 *    comment_template, gravatar_*), ONE row per category, primary key
 *    `cat_id`, keyed by a single shared ID space regardless of which
 *    table a category's identity lives in. See STANDALONE_ID_OFFSET below
 *    for how that shared space works.
 *  - The catjoin table — post-to-category assignments (`entry_id`/
 *    `cat_id`/`collective_id`, composite key), used ONLY for listAll()'s
 *    per-category entry count. Not the same table as catoptions above,
 *    despite the similar name — conflating the two was a real bug fixed
 *    shortly after Categories Support shipped (see CategoryRepository's
 *    class docblock for the full story).
 *
 * ID space: when a standalone table is in use, standalone categories are
 * addressed everywhere outside this class (catoptions, admin UI, URLs) by
 * `$standaloneTable row id + CategoryRepository::STANDALONE_ID_OFFSET`,
 * while collective-sourced categories keep their native (small) IDs from
 * the collective table unshifted. This keeps the two ID spaces from ever
 * colliding without requiring any change to the catoptions table's schema
 * or to code that already treats cat_id as a plain int. isStandaloneId()
 * is the single source of truth for which space a given ID belongs to.
 *
 * CategoryService (see class/Service/CategoryService.php) depends on this
 * interface rather than the concrete CategoryRepository, so unit tests can
 * substitute a test double without touching SqlConnection or a real
 * database connection. No business rules belong here — this is pure data
 * access. Which categories may be created/renamed/deleted through
 * FanUpdate (standalone: yes; collective-sourced: read-only, see
 * CategoryService's class docblock) is a business rule that lives in
 * CategoryService, not here.
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
     * Load a category by ID, joined against its catoptions settings row.
     * Hybrid-aware: resolves against the standalone table when $catId is
     * in the standalone ID space (see class docblock), otherwise against
     * the collective table exactly as before.
     *
     * @return array<string, mixed>|null The joined row, or null if it doesn't exist.
     */
    public function findById(int $catId): ?array;

    /**
     * Insert a new row into the collective table directly. Only meaningful
     * when the collective table IS the standalone store (collective_script
     * = 'n'); prefer insertStandalone() from calling code, which handles
     * both modes. Kept as a distinct method (rather than folded away) since
     * it maps 1:1 onto a single INSERT and is independently unit tested.
     *
     * @return int The new category's ID, or 0 if the insert failed.
     */
    public function insertCollective(string $name): int;

    public function updateCollectiveName(int $catId, string $name): bool;

    public function deleteCollective(int $catId): bool;

    /**
     * Create a new standalone category. When no dedicated standalone table
     * is configured (collective_script = 'n'), delegates to
     * insertCollective() — the collective table itself is the standalone
     * store in that mode, exactly as before Categories Support. When a
     * dedicated standalone table IS configured, inserts there and returns
     * the ID shifted into the standalone ID space (see class docblock).
     *
     * @return int The new category's public-facing ID, or 0 on failure.
     */
    public function insertStandalone(string $name): int;

    public function updateStandaloneName(int $catId, string $name): bool;

    public function deleteStandalone(int $catId): bool;

    /**
     * Whether $catId belongs to the standalone ID space. Always true when
     * no dedicated standalone table is configured (there is only one
     * store, and it IS the standalone one). See class docblock.
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
     * Run the category listing on the underlying SqlConnection. Results are
     * read afterwards via the connection's existing
     * NumRows()/ReadRecord()/FreeResult() cursor — the category list is
     * not paginated, matching the original admin/category.php behaviour.
     *
     * Hybrid-aware: when a standalone table is configured, this is a
     * UNION ALL of both tables' categories (each row's cid reveals its
     * source via isStandaloneId()); otherwise it is the original
     * single-table query, unchanged.
     */
    public function listAll(): void;

    /**
     * Convenience wrapper around listAll() that consumes the cursor
     * internally and returns the rows as an array — used by simple
     * dropdown/select-list callers (e.g. admin/blog.php's category
     * picker) that don't need the manual cursor pattern.
     *
     * @return list<array{cid:int,cname:string,num:int}>
     */
    public function listForDropdown(): array;

    /**
     * Integrated Mode (Fanatically/Enthusiast/Listing Admin): return every
     * collective-space (native, unshifted) cat_id that has a catoptions
     * settings row here but no longer has a matching row in the collective
     * table -- i.e. a category FanUpdate synced/edited settings for that has
     * since been deleted upstream in the collective script. This is what
     * "refresh category data" and "notify + confirm before deleting the
     * corresponding category" (TODO.md's Integrated Mode sections) actually
     * detect: category *listing* is already always live (findById()/
     * listAll()/listForDropdown() query the collective table directly on
     * every call, so a rename or a new category upstream shows up
     * immediately with no sync step needed) -- the only thing that can go
     * stale is a leftover settings row for a category that's gone.
     *
     * Only meaningful in hybrid mode ($standaloneTable !== null); returns an
     * empty array otherwise, since a collective_script = 'n' installation
     * has no external source to fall out of sync with (the collective table
     * IS the only, always-consistent store there).
     *
     * @return list<int>
     */
    public function listOrphanedCollectiveCategoryIds(): array;

    /**
     * Transaction control, delegated to the underlying SqlConnection. See
     * class docblock for why this lives on the repository interface rather
     * than requiring callers to depend on SqlConnection directly.
     */
    public function beginTransaction(): bool;

    public function commit(): bool;

    public function rollBack(): bool;
}
