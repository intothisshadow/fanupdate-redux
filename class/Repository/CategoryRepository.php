<?php

/*****************************************************************************
 * FanUpdate — CategoryRepository
 *
 * Data access for the category domain (collective table, optional
 * standalone table, catjoin/catoptions). Table and column names are
 * injected rather than read from the FanUpdate singleton, so the class is
 * unit-testable with a mock SqlConnection. See
 * CategoryRepositoryInterface for the hybrid design and ID-space offset.
 *
 * The catjoin table (post-to-category rows, used only for listAll()'s
 * counts) and the catoptions table (one settings row per category) are
 * distinct and take separate constructor dependencies; do not conflate them.
 *
 * beginTransaction()/commit()/rollBack() pass through to SqlConnection. They
 * only provide atomicity on InnoDB tables.
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
     * Standalone category IDs are this offset plus the table's own `id`, so
     * they can't collide with a collective table's native IDs. The table's
     * AUTO_INCREMENT is seeded to offset + 1 at creation.
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
            // No standalone table: the collective table is the standalone store.
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
            // Union of both sources in one ordered listing; entry counts join the
            // catjoin table (not catoptions).
            $offset = self::STANDALONE_ID_OFFSET;

            // cname is forced to utf8mb4_general_ci: an external table's
            // collation otherwise makes MySQL reject the UNION ("Illegal mix
            // of collations"). The collective column is CONVERTed first
            // because a bare COLLATE is invalid on utf8mb3/latin1 columns
            // (Listing Admin's tables are utf8mb3).
            $query = '(SELECT s.id + ' . $offset . ' AS cid,
                    s.name COLLATE utf8mb4_general_ci AS cname,
                    COUNT(j1.cat_id) AS num
                FROM ' . $this->standaloneTable . ' s
                LEFT JOIN ' . $this->catjoinTable . ' j1 ON j1.cat_id = s.id + ' . $offset . '
                GROUP BY s.id)
                UNION ALL
                (SELECT ' . $this->colId . ' AS cid,
                    CONVERT(' . $this->colSubj . ' USING utf8mb4) COLLATE utf8mb4_general_ci AS cname,
                    COUNT(j2.cat_id) AS num
                FROM ' . $this->collectiveTable . '
                LEFT JOIN ' . $this->catjoinTable . ' j2 ON ' . $this->colId . ' = j2.cat_id
                GROUP BY ' . $this->colId . ')
                ORDER BY cname ASC';

            $this->db->Execute($query);

            return;
        }

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
