<?php

/*****************************************************************************
 * FanUpdate — CategoryRepository
 *
 * Data access for the category domain (collective table + catjoin
 * settings). Table names AND the collective table's column names
 * (col_id/col_subj — configurable per collective_script integration) are
 * injected via the constructor rather than read from FanUpdate::getOpt()
 * internally, so this class has no dependency on the FanUpdate singleton
 * and can be constructed with a mock SqlConnection in a unit test.
 *
 * beginTransaction()/commit()/rollBack() are thin passthroughs to the
 * injected SqlConnection, letting CategoryService wrap the two-table
 * create/delete sequences atomically. See
 * CategoryService::createCategory()/deleteCategory().
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class CategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $collectiveTable,
        private readonly string $catjoinTable,
        private readonly string $colId,
        private readonly string $colSubj,
    ) {
    }

    public function findById(int $catId): ?array
    {
        $query = 'SELECT ' . $this->collectiveTable . '.*,
            ' . $this->colSubj . ' AS cname,
            ' . $this->colId . ' AS cid,
            j.*
            FROM ' . $this->collectiveTable . '
            LEFT JOIN ' . $this->catjoinTable . ' j
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

    public function insertSettings(int $catId, array $data): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->catjoinTable . '
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
            'UPDATE ' . $this->catjoinTable . '
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
            'DELETE FROM ' . $this->catjoinTable . ' WHERE cat_id = ?',
            'Could not execute query.',
            [$catId]
        );
    }

    public function listAll(): void
    {
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
