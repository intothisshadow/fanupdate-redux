<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpdateRepository
 *
 * Data access for the Admin Update Manager's persisted config/state/log,
 * stored as three JSON blobs under private keys in the options table (see
 * UpdateRepositoryInterface for why). The table name is injected via the
 * constructor rather than read from FanUpdate::getOpt() internally, so this
 * class has no dependency on the FanUpdate singleton and can be constructed
 * with a mock SqlConnection in a unit test — same pattern as
 * OptionRepository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class UpdateRepository implements UpdateRepositoryInterface
{
    private const CONFIG_KEY = '_update_config';
    private const STATE_KEY  = '_update_state';
    private const LOG_KEY    = '_update_log';

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
        return $this->setJsonOption(self::CONFIG_KEY, $config, 'Internal: FanUpdate Redux update manager settings (channel, token). Do not edit manually.');
    }

    public function getState(): array
    {
        return $this->getJsonOption(self::STATE_KEY);
    }

    public function saveState(array $state): bool
    {
        return $this->setJsonOption(self::STATE_KEY, $state, 'Internal: FanUpdate Redux update manager cached check result. Do not edit manually.');
    }

    public function getLog(): array
    {
        // getJsonOption() returns array<string, mixed> in general, but this
        // key specifically always holds a JSON *list* (numeric keys) —
        // array_values() guards against a malformed/manually-edited value
        // decoding to an associative array instead.
        return array_values($this->getJsonOption(self::LOG_KEY));
    }

    public function saveLog(array $entries): bool
    {
        return $this->setJsonOption(self::LOG_KEY, $entries, 'Internal: FanUpdate Redux update manager event log. Do not edit manually.');
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

    /** @param array<string, mixed>|array<int, mixed> $value */
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
            'Could not save update manager data.',
            [$key, $json, $description]
        );
    }
}
