<?php

/*****************************************************************************
 * FanUpdate — OptionRepository
 *
 * Data access for the general (non-template, non-credential) rows of the
 * options table. admin_email/admin_username/admin_password are excluded
 * from listEditableOptions() — they have their own dedicated page,
 * admin/account.php, and admin_username/admin_password specifically must
 * never be displayed as a plain editable text field on the generic Main
 * Options list (that would show the bcrypt hash in a plaintext input). The
 * table name is injected via the constructor rather than read from
 * FanUpdate::getOpt() internally, so this class has no dependency on the
 * FanUpdate singleton and can be constructed with a mock SqlConnection in
 * a unit test.
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
                  AND optkey NOT IN ('admin_email', 'admin_username', 'admin_password')
                  AND optkey NOT IN ('collective_script', 'col_id', 'col_subj', 'collective_table')
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
        // Upsert (INSERT ... ON DUPLICATE KEY UPDATE) rather than a plain
        // UPDATE: optkey is the options table's primary key, and some keys
        // (e.g. collective_table, set from admin/collective-source.php) may
        // not have a row yet on an installation that predates that key's
        // introduction. A plain UPDATE would silently affect 0 rows in that
        // case — indistinguishable from "value unchanged" to every caller
        // here, which all treat null (query failure) as the only error case.
        // optdesc is only ever populated on first insert; an existing row's
        // description is left untouched, matching the previous UPDATE-only
        // behaviour for every key that already had a row.
        $ok = $this->db->Execute(
            'INSERT INTO ' . $this->optionsTable . ' (optkey, optvalue, optdesc)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE optvalue = VALUES(optvalue)',
            'Could not execute query.',
            [$key, $value, '']
        );

        return $ok ? $this->db->AffectedRows() : null;
    }
}
