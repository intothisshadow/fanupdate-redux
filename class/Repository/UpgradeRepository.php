<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeRepository
 *
 * Data access for UpgradeRunner's post-install migrations, backed by the
 * same options table every other repository in this codebase uses. The
 * table name is injected via the constructor rather than read from
 * FanUpdate::getOpt() internally, so this class has no dependency on the
 * FanUpdate singleton and can be constructed with a mock SqlConnection in
 * a unit test — same pattern as OptionRepository/UpdateRepository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class UpgradeRepository implements UpgradeRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $optionsTable,
    ) {
    }

    public function getOptionValue(string $key): ?string
    {
        $value = $this->db->GetFirstCell(
            'SELECT optvalue FROM ' . $this->optionsTable . ' WHERE optkey = ? LIMIT 1',
            [$key]
        );

        // GetFirstCell()'s "false means no row" contract — an actual stored
        // value of '' is fetched as '', not false, so this check is safe.
        return $value === false ? null : (string) $value;
    }

    public function setOptionValue(string $key, string $value, string $description = ''): bool
    {
        return $this->db->Execute(
            'INSERT INTO ' . $this->optionsTable . ' (optkey, optvalue, optdesc) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE optvalue = VALUES(optvalue)',
            'Could not execute query.',
            [$key, $value, $description]
        );
    }

    public function deleteOptionIfExists(string $key): bool
    {
        $this->db->Execute(
            'DELETE FROM ' . $this->optionsTable . ' WHERE optkey = ?',
            'Could not execute query.',
            [$key]
        );

        return $this->db->AffectedRows() > 0;
    }
}
