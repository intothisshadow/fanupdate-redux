<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — PrivacyRepository
 *
 * Data access for the install ping's config and state, stored as two JSON
 * blobs under private options keys (same pattern as UpdateRepository). The
 * table name is injected, so the class is testable with a mock SqlConnection.
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
