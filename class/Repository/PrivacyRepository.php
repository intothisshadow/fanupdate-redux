<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — PrivacyRepository
 *
 * Data access for the Opt-In Anonymous Install Ping's persisted config/state
 * (TODO.md "FP-15"), stored as two JSON blobs under private keys in the
 * options table — same pattern and same reasoning as UpdateRepository (see
 * UpdateRepositoryInterface's docblock). The table name is injected via the
 * constructor rather than read from FanUpdate::getOpt() internally, so this
 * class has no dependency on the FanUpdate singleton and can be constructed
 * with a mock SqlConnection in a unit test.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class PrivacyRepository implements PrivacyRepositoryInterface
{
    private const CONFIG_KEY = '_privacy_config';
    private const STATE_KEY  = '_privacy_state';

    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $optionsTable,
    ) {
    }

    public function getConfig(): array
    {
        return $this->getJsonOption(self::CONFIG_KEY);
    }

    public function saveConfig(array $config): bool
    {
        return $this->setJsonOption(
            self::CONFIG_KEY,
            $config,
            'Internal: FanUpdate Redux anonymous install ping settings (enabled, installUuid). Do not edit manually.'
        );
    }

    public function getState(): array
    {
        return $this->getJsonOption(self::STATE_KEY);
    }

    public function saveState(array $state): bool
    {
        return $this->setJsonOption(
            self::STATE_KEY,
            $state,
            'Internal: FanUpdate Redux anonymous install ping cached result. Do not edit manually.'
        );
    }

    /** @return array<string, mixed> */
    private function getJsonOption(string $key): array
    {
        $raw = $this->db->GetFirstCell(
            'SELECT optvalue FROM ' . $this->optionsTable . ' WHERE optkey = ?',
            [$key]
        );

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $value */
    private function setJsonOption(string $key, array $value, string $description): bool
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return $this->db->Execute(
            'INSERT INTO ' . $this->optionsTable . ' (optkey, optvalue, optdesc)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE optvalue = VALUES(optvalue)',
            'Could not save install ping data.',
            [$key, $json, $description]
        );
    }
}
