<?php

/*****************************************************************************
 * FanUpdate — OptionRepository
 *
 * Data access for the general (non-template, non-email) rows of the
 * options table. The table name is injected via the constructor rather
 * than read from FanUpdate::getOpt() internally, so this class has no
 * dependency on the FanUpdate singleton and can be constructed with a mock
 * SqlConnection in a unit test.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class OptionRepository implements OptionRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $optionsTable,
    ) {
    }

    public function listEditableOptions(): array
    {
        $this->db->Execute(
            'SELECT * FROM ' . $this->optionsTable . "
                WHERE optkey NOT LIKE '%template%'
                  AND optkey != 'admin_email'
                ORDER BY optkey ASC"
        );

        $rows = [];
        while ($row = $this->db->ReadRecord()) {
            $rows[] = $row;
        }
        $this->db->FreeResult();

        return $rows;
    }

    public function updateOption(string $key, string $value): ?int
    {
        $ok = $this->db->Execute(
            'UPDATE ' . $this->optionsTable . '
                SET optvalue = ? WHERE optkey = ?',
            'Could not execute query.',
            [$value, $key]
        );

        return $ok ? $this->db->AffectedRows() : null;
    }
}
