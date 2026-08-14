<?php

/*****************************************************************************
 * FanUpdate — BlogRepository
 *
 * Data access for the blog entries table and its catjoin/comments
 * relations. Table names are injected via the constructor rather than read
 * from FanUpdate::getOpt() internally, so this class has no dependency on
 * the FanUpdate singleton and can be constructed with a mock SqlConnection
 * in a unit test.
 *
 * delete() is atomic: the entry row, its catjoin row(s), and its comments
 * are all deleted inside a single transaction, so a failed catjoin/comments
 * cleanup can never leave an orphaned entry-less catjoin or comment row
 * behind. setCategory()/clearCategory() return the underlying Execute()
 * result so BlogService can detect a failed write and roll back its own
 * transaction (see BlogService::createEntry()/updateEntry()).
 * beginTransaction()/commit()/rollBack() are thin passthroughs to the
 * injected SqlConnection.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class BlogRepository implements BlogRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $blogTable,
        private readonly string $catjoinTable,
        private readonly string $commentsTable,
    ) {
    }

    public function findById(int $entryId): ?array
    {
        $this->db->Execute(
            'SELECT * FROM ' . $this->blogTable . ' WHERE entry_id = ?',
            'Could not execute query.',
            [$entryId]
        );
        $row = $this->db->ReadRecord();
        $this->db->FreeResult();

        return $row === false ? null : $row;
    }

    public function findCategoryIds(int $entryId): array
    {
        $this->db->Execute(
            'SELECT cat_id FROM ' . $this->catjoinTable . ' WHERE entry_id = ?',
            'Could not execute query.',
            [$entryId]
        );

        $catIds = [];
        while ($row = $this->db->ReadRecord()) {
            $catIds[] = (int) $row['cat_id'];
        }
        $this->db->FreeResult();

        return $catIds;
    }

    public function insert(array $data): int
    {
        $ok = $this->db->Execute(
            'INSERT INTO ' . $this->blogTable . '
                (title, body, is_public, comments_on, added)
                VALUES (?, ?, ?, ?, ?)',
            'Could not execute query.',
            [
                (string) $data['title'],
                (string) $data['body'],
                (int) $data['is_public'],
                (int) $data['comments_on'],
                (string) $data['date'],
            ]
        );

        return $ok ? $this->db->GetLastSequence() : 0;
    }

    public function update(int $entryId, array $data): bool
    {
        return $this->db->Execute(
            'UPDATE ' . $this->blogTable . '
                SET title = ?, body = ?, is_public = ?, comments_on = ?, added = ?
                WHERE entry_id = ?',
            'Could not execute query.',
            [
                (string) $data['title'],
                (string) $data['body'],
                (int) $data['is_public'],
                (int) $data['comments_on'],
                (string) $data['date'],
                $entryId,
            ]
        );
    }

    /**
     * Atomic: deletes the entry row, its catjoin row(s), and its comments
     * inside a single transaction. All three must succeed for the delete
     * to commit — if any fails, the transaction rolls back and none of
     * the rows are removed, so a failed catjoin/comments cleanup can never
     * leave an orphaned entry-less catjoin or comment row behind.
     */
    public function delete(int $entryId): bool
    {
        $this->db->beginTransaction();

        $entryOk    = $this->db->Execute('DELETE FROM ' . $this->blogTable . ' WHERE entry_id = ?', 'Could not execute query.', [$entryId]);
        $catjoinOk  = $this->db->Execute('DELETE FROM ' . $this->catjoinTable . ' WHERE entry_id = ?', 'Could not execute query.', [$entryId]);
        $commentsOk = $this->db->Execute('DELETE FROM ' . $this->commentsTable . ' WHERE entry_id = ?', 'Could not execute query.', [$entryId]);

        if ($entryOk && $catjoinOk && $commentsOk) {
            $this->db->commit();
            return true;
        }

        $this->db->rollBack();
        return false;
    }

    public function deleteMany(array $entryIds): int
    {
        $count = 0;
        foreach ($entryIds as $entryId) {
            if ($this->delete((int) $entryId)) {
                $count++;
            }
        }

        return $count;
    }

    public function setCategory(int $entryId, int $catId): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->catjoinTable . ' (entry_id, cat_id) VALUES (?, ?)',
            'Could not execute query.',
            [$entryId, $catId]
        );
    }

    public function clearCategory(int $entryId): bool
    {
        return $this->db->Execute(
            'DELETE FROM ' . $this->catjoinTable . ' WHERE entry_id = ?',
            'Could not execute query.',
            [$entryId]
        );
    }

    public function findTitlesByIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $this->db->Execute(
            'SELECT entry_id, title FROM ' . $this->blogTable . ' WHERE entry_id IN (' . $placeholders . ')',
            'Could not execute query.',
            array_map('intval', $entryIds)
        );

        $rows = [];
        while ($row = $this->db->ReadRecord()) {
            $rows[] = ['entry_id' => (int) $row['entry_id'], 'title' => (string) $row['title']];
        }
        $this->db->FreeResult();

        return $rows;
    }

    public function search(int $filterCatId, string $filterSearch, int $perPage): void
    {
        // catjoin LEFT JOIN to get cat info, comments LEFT JOIN for counts.
        // Category filter requires an additional JOIN to avoid ambiguous cat_id.
        $query = 'SELECT b.entry_id, b.title, b.is_public, b.comments_on, b.added,
                COUNT(DISTINCT com.comment_id) AS num_comments
            FROM ' . $this->blogTable . ' b
            LEFT JOIN ' . $this->catjoinTable . ' j ON b.entry_id = j.entry_id
            LEFT JOIN ' . $this->commentsTable . ' com ON b.entry_id = com.entry_id';

        $params = [];

        if ($filterCatId > 0) {
            $query .= ' JOIN ' . $this->catjoinTable . ' cat ON b.entry_id = cat.entry_id AND cat.cat_id = ?';
            $params[] = $filterCatId;
        }

        if ($filterSearch !== '') {
            $query .= ' WHERE MATCH (b.title, b.body) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $filterSearch;
        }

        $query .= ' GROUP BY b.entry_id ORDER BY b.added DESC';

        $this->db->FreeResult();
        $this->db->ExecutePaginate($query, $perPage, $params);
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
