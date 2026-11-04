<?php

/*****************************************************************************
 * FanUpdate — CategoryRepository
 *
 * Data access for the category domain (collective table + optional
 * standalone table + catjoin/catoptions settings). Table names, the
 * collective table's column names (col_id/col_subj — configurable per
 * collective_script integration), and the optional standalone table name
 * are all injected via the constructor rather than read from
 * FanUpdate::getOpt() internally, so this class has no dependency on the
 * FanUpdate singleton and can be constructed with a mock SqlConnection in
 * a unit test.
 *
 * Bug fix (post-Categories Support): this class previously used a single
 * $catjoinTable for two genuinely different tables that happen to share a
 * naming convention:
 *  - The real catjoin table (`entry_id`/`cat_id`/`collective_id`, one row
 *    per post-to-category assignment, composite primary key) — needed
 *    ONLY by listAll()'s `COUNT(cat_id)` aggregation, to show how many
 *    entries are filed under each category.
 *  - The catoptions table (`cat_id` primary key, one row per CATEGORY —
 *    entry_template/comment_template/gravatar_* columns) — needed by
 *    findById()'s settings join and by insertSettings()/updateSettings()/
 *    deleteSettings(), which key strictly by a single cat_id.
 * Passing catjoin_table for both meant every settings write actually
 * targeted a table with no entry_template/comment_template/gravatar_*
 * columns at all, and findById()'s settings join returned wrong (or,
 * since a category can have many catjoin rows, potentially multiple)
 * results. This was invisible to the unit tests (SqlConnection is always
 * mocked) and mostly invisible in production too, since both tables are
 * MyISAM — the failed writes never rolled back the categories they
 * belonged to (MyISAM doesn't support transactions), and the underlying
 * PDOException was only ever reported via trigger_error(), not thrown.
 * Fixed by giving this class its own distinct $catoptionsTable dependency.
 *
 * See CategoryRepositoryInterface's class docblock for the full hybrid
 * (standalone + collective, side by side) design — the ID-space offset
 * that keeps the two tables' primary keys from colliding, and why every
 * method here transparently degrades to the original single-table
 * behaviour when $standaloneTable is null (collective_script = 'n').
 *
 * beginTransaction()/commit()/rollBack() are thin passthroughs to the
 * injected SqlConnection, letting CategoryService wrap the two-table
 * create/delete sequences atomically. See
 * CategoryService::createCategory()/deleteCategory(). Note that on a
 * MyISAM installation (the default table engine throughout this project)
 * these are effectively no-ops — see the bug-fix note above — so that
 * "atomicity" is aspirational unless the tables are converted to InnoDB.
 * Out of scope for this fix; flagged for a future engine-migration pass.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class CategoryRepository implements CategoryRepositoryInterface
{
    /**
     * Added for Categories Support (hybrid). Standalone-table category IDs
     * are always this constant plus the table's own auto-increment `id`,
     * so they can never collide with a collective table's (typically
     * small) native IDs. The standalone table's AUTO_INCREMENT is seeded
     * to STANDALONE_ID_OFFSET + 1 at creation time (see install.php) so the
     * shift never has to be applied more than once per row, keeping the
     * arithmetic in this class the single source of truth either way.
     */
    public const STANDALONE_ID_OFFSET = 1_000_000;

    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $collectiveTable,
        private readonly string $catjoinTable,
        private readonly string $catoptionsTable,
        private readonly string $colId,
        private readonly string $colSubj,
        private readonly ?string $standaloneTable = null,
        private readonly bool $showCollectiveCategories = true,
    ) {
    }

    public function findById(int $catId): ?array
    {
        if ($this->standaloneTable !== null && $this->isStandaloneId($catId)) {
            $localId = $catId - self::STANDALONE_ID_OFFSET;

            $query = 'SELECT ' . $this->standaloneTable . '.id AS cid,
                ' . $this->standaloneTable . '.name AS cname,
                j.*
                FROM ' . $this->standaloneTable . '
                LEFT JOIN ' . $this->catoptionsTable . ' j
                    ON j.cat_id = ?
                WHERE ' . $this->standaloneTable . '.id = ?';

            $this->db->Execute($query, 'Could not execute query.', [$catId, $localId]);
            $row = $this->db->ReadRecord();
            $this->db->FreeResult();

            if ($row === false) {
                return null;
            }

            // Present the shared public-facing (shifted) ID, not the
            // standalone table's own local primary key.
            $row['cid'] = $catId;

            return $row;
        }

        $query = 'SELECT ' . $this->collectiveTable . '.*,
            ' . $this->colSubj . ' AS cname,
            ' . $this->colId . ' AS cid,
            j.*
            FROM ' . $this->collectiveTable . '
            LEFT JOIN ' . $this->catoptionsTable . ' j
                ON ' . $this->colId . ' = j.cat_id
            WHERE ' . $this->colId . ' = ?';

        $this->db->Execute($query, 'Could not execute query.', [$catId]);
        $row = $this->db->ReadRecord();
        $this->db->FreeResult();

        return $row === false ? null : $row;
    }

    public function insertCollective(string $name): int
    {
        $query = 'INSERT INTO ' . $this->collectiveTable . '
            (' . $this->colSubj . ') VALUES (?)';

        $ok = $this->db->Execute($query, 'Could not execute query.', [$name]);

        return $ok ? $this->db->GetLastSequence() : 0;
    }

    public function updateCollectiveName(int $catId, string $name): bool
    {
        $query = 'UPDATE ' . $this->collectiveTable . '
            SET ' . $this->colSubj . ' = ?
            WHERE ' . $this->colId . ' = ?';

        return $this->db->Execute($query, 'Could not execute query.', [$name, $catId]);
    }

    public function deleteCollective(int $catId): bool
    {
        $query = 'DELETE FROM ' . $this->collectiveTable . ' WHERE ' . $this->colId . ' = ?';

        return $this->db->Execute($query, 'Could not execute query.', [$catId]);
    }

    public function insertStandalone(string $name): int
    {
        if ($this->standaloneTable === null) {
            // No dedicated standalone table configured (collective_script =
            // 'n') — the collective table IS the standalone store, exactly
            // as before Categories Support.
            return $this->insertCollective($name);
        }

        $query = 'INSERT INTO ' . $this->standaloneTable . ' (name) VALUES (?)';
        $ok    = $this->db->Execute($query, 'Could not execute query.', [$name]);

        return $ok ? (self::STANDALONE_ID_OFFSET + $this->db->GetLastSequence()) : 0;
    }

    public function updateStandaloneName(int $catId, string $name): bool
    {
        if ($this->standaloneTable === null) {
            return $this->updateCollectiveName($catId, $name);
        }

        $localId = $catId - self::STANDALONE_ID_OFFSET;
        $query   = 'UPDATE ' . $this->standaloneTable . ' SET name = ? WHERE id = ?';

        return $this->db->Execute($query, 'Could not execute query.', [$name, $localId]);
    }

    public function deleteStandalone(int $catId): bool
    {
        if ($this->standaloneTable === null) {
            return $this->deleteCollective($catId);
        }

        $localId = $catId - self::STANDALONE_ID_OFFSET;
        $query   = 'DELETE FROM ' . $this->standaloneTable . ' WHERE id = ?';

        return $this->db->Execute($query, 'Could not execute query.', [$localId]);
    }

    public function isStandaloneId(int $catId): bool
    {
        if ($this->standaloneTable === null) {
            // Only one store exists, and it IS the standalone one.
            return true;
        }

        return $catId >= self::STANDALONE_ID_OFFSET;
    }

    public function insertSettings(int $catId, array $data): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->catoptionsTable . '
                (cat_id, entry_template, comment_template, gravatar_default, gravatar_size, gravatar_rating)
                VALUES (?, ?, ?, ?, ?, ?)',
            'Could not execute query.',
            [
                $catId,
                (string) $data['entry_template'],
                (string) $data['comment_template'],
                (string) $data['gravatar_default'],
                (int) $data['gravatar_size'],
                (string) $data['gravatar_rating'],
            ]
        );
    }

    public function updateSettings(int $catId, array $data): bool
    {
        return $this->db->Execute(
            'UPDATE ' . $this->catoptionsTable . '
                SET entry_template = ?, comment_template = ?,
                    gravatar_default = ?, gravatar_size = ?,
                    gravatar_rating = ?
                WHERE cat_id = ?',
            'Could not execute query.',
            [
                (string) $data['entry_template'],
                (string) $data['comment_template'],
                (string) $data['gravatar_default'],
                (int) $data['gravatar_size'],
                (string) $data['gravatar_rating'],
                $catId,
            ]
        );
    }

    public function deleteSettings(int $catId): bool
    {
        return $this->db->Execute(
            'DELETE FROM ' . $this->catoptionsTable . ' WHERE cat_id = ?',
            'Could not execute query.',
            [$catId]
        );
    }

    public function listAll(): void
    {
        if ($this->standaloneTable !== null && !$this->showCollectiveCategories) {
            // Collective-sourced categories are hidden by the
            // show_collective_categories option: list only the standalone table.
            $offset = self::STANDALONE_ID_OFFSET;
            $query  = 'SELECT s.id + ' . $offset . ' AS cid,
                    s.name AS cname,
                    COUNT(j1.cat_id) AS num
                FROM ' . $this->standaloneTable . ' s
                LEFT JOIN ' . $this->catjoinTable . ' j1 ON j1.cat_id = s.id + ' . $offset . '
                GROUP BY s.id
                ORDER BY cname ASC';

            $this->db->Execute($query);

            return;
        }

        if ($this->standaloneTable !== null) {
            // Hybrid mode: union the always-present standalone table
            // (IDs shifted into their own space) with the collective
            // table's synced categories (native IDs), so both sources
            // appear together in one ordered listing. isStandaloneId()
            // tells a caller which source each returned cid came from.
            // The entry-count join below is intentionally against the
            // real catjoin table (not catoptions) — it's counting posts
            // per category, unrelated to the per-category settings row.
            $offset = self::STANDALONE_ID_OFFSET;

            // Both cname columns are explicitly COLLATEd to FanUpdate's own
            // standard (every table this codebase creates — including
            // $standaloneTable — uses utf8mb4_general_ci; see install.php/
            // SchemaMigrator) before the UNION. Without this, a collective
            // table living in a genuinely separate external database (e.g.
            // Fanatically's own schema, schema-qualified via
            // collective_table — see README's "Separate-database support")
            // can easily default to a different collation (Fanatically's
            // fanatic_listings ships as utf8mb4_unicode_ci) and MySQL
            // refuses the UNION outright: "Illegal mix of collations for
            // operation 'UNION'". Collating a column with strings this
            // codebase itself never author-controls (they're synced
            // read-only from the external script) is safe — it only
            // affects string comparison/sorting, not the stored bytes.
            $query = '(SELECT s.id + ' . $offset . ' AS cid,
                    s.name COLLATE utf8mb4_general_ci AS cname,
                    COUNT(j1.cat_id) AS num
                FROM ' . $this->standaloneTable . ' s
                LEFT JOIN ' . $this->catjoinTable . ' j1 ON j1.cat_id = s.id + ' . $offset . '
                GROUP BY s.id)
                UNION ALL
                (SELECT ' . $this->colId . ' AS cid,
                    ' . $this->colSubj . ' COLLATE utf8mb4_general_ci AS cname,
                    COUNT(j2.cat_id) AS num
                FROM ' . $this->collectiveTable . '
                LEFT JOIN ' . $this->catjoinTable . ' j2 ON ' . $this->colId . ' = j2.cat_id
                GROUP BY ' . $this->colId . ')
                ORDER BY cname ASC';

            $this->db->Execute($query);

            return;
        }

        // Entry-count join is against the real catjoin table here too —
        // see the note in the hybrid branch above.
        $query = 'SELECT ' . $this->collectiveTable . '.*,
            ' . $this->colSubj . ' AS cname,
            ' . $this->colId . ' AS cid,
            COUNT(cat_id) AS num
            FROM ' . $this->collectiveTable . '
            LEFT JOIN ' . $this->catjoinTable . ' ON ' . $this->colId . '=cat_id
            GROUP BY ' . $this->colId . '
            ORDER BY cname ASC';

        $this->db->Execute($query);
    }

    public function listForDropdown(): array
    {
        $this->listAll();

        $rows = [];
        while ($row = $this->db->ReadRecord()) {
            $rows[] = [
                'cid'   => (int) $row['cid'],
                'cname' => (string) $row['cname'],
                'num'   => (int) $row['num'],
            ];
        }
        $this->db->FreeResult();

        return $rows;
    }

    public function listOrphanedCollectiveCategoryIds(): array
    {
        if ($this->standaloneTable === null) {
            return [];
        }

        $query = 'SELECT j.cat_id
            FROM ' . $this->catoptionsTable . ' j
            LEFT JOIN ' . $this->collectiveTable . ' c ON c.' . $this->colId . ' = j.cat_id
            WHERE c.' . $this->colId . ' IS NULL AND j.cat_id < ' . self::STANDALONE_ID_OFFSET;

        $this->db->Execute($query, 'Could not execute query.');

        $ids = [];
        while ($row = $this->db->ReadRecord()) {
            $ids[] = (int) $row['cat_id'];
        }
        $this->db->FreeResult();

        return $ids;
    }

    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->db->commit();
    }

    public function rollBack(): bool
    {
        return $this->db->rollBack();
    }
}
