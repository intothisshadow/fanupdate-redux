<?php

/*****************************************************************************
 * FanUpdate — SmilieRepository
 *
 * Data access for the smilies table. The table name is injected via the
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

class SmilieRepository implements SmilieRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $smiliesTable,
    ) {
    }

    public function insert(string $smiley, string $image): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->smiliesTable . ' (smiley, image) VALUES (?, ?)',
            'Could not execute query.',
            [$smiley, $image]
        );
    }

    public function update(string $smiley, string $image): bool
    {
        return $this->db->Execute(
            'UPDATE ' . $this->smiliesTable . ' SET image = ? WHERE smiley = ?',
            'Could not execute query.',
            [$image, $smiley]
        );
    }

    public function delete(string $smiley): bool
    {
        return $this->db->Execute(
            'DELETE FROM ' . $this->smiliesTable . ' WHERE smiley = ?',
            'Could not execute query.',
            [$smiley]
        );
    }

    public function listAll(): array
    {
        $this->db->Execute('SELECT * FROM ' . $this->smiliesTable);

        $rows = [];
        while ($row = $this->db->ReadRecord()) {
            $rows[] = $row;
        }
        $this->db->FreeResult();

        return $rows;
    }
}
