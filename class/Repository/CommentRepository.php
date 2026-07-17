<?php

/*****************************************************************************
 * FanUpdate — CommentRepository
 *
 * Data access for the comments table. Table names are injected via the
 * constructor rather than read from FanUpdate::getOpt() internally, so
 * this class has no dependency on the FanUpdate singleton and can be
 * constructed with a mock SqlConnection in a unit test.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class CommentRepository implements CommentRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $commentsTable,
        private readonly string $blogTable,
    ) {
    }

    public function findById(int $commentId): ?array
    {
        $this->db->Execute(
            'SELECT * FROM ' . $this->commentsTable . ' WHERE comment_id = ?',
            'Could not execute query.',
            [$commentId]
        );
        $row = $this->db->ReadRecord();
        $this->db->FreeResult();

        return $row === false ? null : $row;
    }

    public function insert(array $data): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->commentsTable . '
                (name, email, url, comment, entry_id, added, approved)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
            'Could not execute query.',
            [
                (string) $data['name'],
                (string) $data['email'],
                (string) $data['url'],
                (string) $data['comment'],
                (int) $data['entry_id'],
                (string) $data['date'],
                (int) $data['approved'],
            ]
        );
    }

    public function update(int $commentId, array $data): bool
    {
        return $this->db->Execute(
            'UPDATE ' . $this->commentsTable . '
                SET name = ?, email = ?, url = ?,
                    comment = ?, entry_id = ?,
                    added = ?, approved = ?
                WHERE comment_id = ?',
            'Could not execute query.',
            [
                (string) $data['name'],
                (string) $data['email'],
                (string) $data['url'],
                (string) $data['comment'],
                (int) $data['entry_id'],
                (string) $data['date'],
                (int) $data['approved'],
                $commentId,
            ]
        );
    }

    public function delete(int $commentId): bool
    {
        return $this->db->Execute(
            'DELETE FROM ' . $this->commentsTable . ' WHERE comment_id = ?',
            'Could not execute query.',
            [$commentId]
        );
    }

    public function search(string $show, string $searchTerm, int $perPage): void
    {
        $whereParts  = [];
        $whereParams = [];

        if ($searchTerm !== '') {
            $whereParts[]  = '(name LIKE ? OR comment LIKE ? OR email LIKE ?)';
            $like          = '%' . $searchTerm . '%';
            $whereParams[] = $like;
            $whereParams[] = $like;
            $whereParams[] = $like;
        }

        if ($show === 'pending') {
            $whereParts[] = 'approved = 0';
        } elseif ($show === 'spam') {
            $whereParts[] = 'approved = 2';
        } else {
            $whereParts[] = 'approved > 0';
        }

        $where = 'WHERE ' . implode(' AND ', $whereParts);

        $this->db->FreeResult();
        $this->db->ExecutePaginate(
            'SELECT c.*, b.title AS entry_title
             FROM ' . $this->commentsTable . ' c
             LEFT JOIN ' . $this->blogTable . ' b ON c.entry_id = b.entry_id
             ' . $where . '
             ORDER BY c.added DESC',
            $perPage,
            $whereParams
        );
    }
}
