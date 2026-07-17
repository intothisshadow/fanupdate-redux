<?php

/*****************************************************************************
 * FanUpdate — BlacklistRepository
 *
 * Data access for the comment-spam blacklist table. The table name is
 * injected via the constructor rather than read from FanUpdate::getOpt()
 * internally, so this class has no dependency on the FanUpdate singleton
 * and can be constructed with a mock SqlConnection in a unit test.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class BlacklistRepository implements BlacklistRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $blacklistTable,
    ) {
    }

    public function exists(string $word): bool
    {
        $count = $this->db->GetFirstCell(
            'SELECT COUNT(*) FROM ' . $this->blacklistTable . ' WHERE badword = ?',
            [$word]
        );

        return ((int) $count) > 0;
    }

    public function insert(string $word): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->blacklistTable . ' (badword) VALUES (?)',
            'Could not execute query.',
            [$word]
        );
    }

    public function delete(string $word): bool
    {
        return $this->db->Execute(
            'DELETE FROM ' . $this->blacklistTable . ' WHERE badword = ?',
            'Could not execute query.',
            [$word]
        );
    }

    public function listAll(): array
    {
        $this->db->Execute('SELECT * FROM ' . $this->blacklistTable . ' ORDER BY badword ASC');

        $rows = [];
        while ($row = $this->db->ReadRecord()) {
            $rows[] = $row;
        }
        $this->db->FreeResult();

        return $rows;
    }
}
