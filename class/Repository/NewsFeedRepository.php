<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedRepository
 *
 * Data access for the Dashboard "FanUpdate Redux News" widget's (FP-23)
 * cache, stored as a single JSON blob under a private key in the options
 * table (see NewsFeedRepositoryInterface for why). The table name is
 * injected via the constructor rather than read from FanUpdate::getOpt()
 * internally, so this class has no dependency on the FanUpdate singleton
 * and can be constructed with a mock SqlConnection in a unit test — same
 * pattern as UpdateRepository/OptionRepository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class NewsFeedRepository implements NewsFeedRepositoryInterface
{
    private const CACHE_KEY = '_news_feed_cache';

    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $optionsTable,
    ) {
    }

    public function getCache(): array
    {
        $raw = $this->db->GetFirstCell(
            'SELECT optvalue FROM ' . $this->optionsTable . ' WHERE optkey = ?',
            [self::CACHE_KEY]
        );

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (
            !is_array($decoded)
            // 'url' missing (e.g. a cache written before this field
            // existed) is deliberately treated the same as "invalid" —
            // NewsFeedService can't tell whether it matches the currently
            // configured feed, so the safest behaviour is to force a fresh
            // fetch rather than risk serving a different feed's stale (or
            // empty) result.
            || !isset($decoded['items'], $decoded['fetchedAt'], $decoded['url'])
            || !is_array($decoded['items'])
            || !is_string($decoded['fetchedAt'])
            || !is_string($decoded['url'])
        ) {
            return [];
        }

        return ['items' => $decoded['items'], 'fetchedAt' => $decoded['fetchedAt'], 'url' => $decoded['url']];
    }

    public function saveCache(array $items, string $fetchedAt, string $url): bool
    {
        try {
            $json = json_encode(['items' => $items, 'fetchedAt' => $fetchedAt, 'url' => $url], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return $this->db->Execute(
            'INSERT INTO ' . $this->optionsTable . ' (optkey, optvalue, optdesc)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE optvalue = VALUES(optvalue)',
            'Could not save news feed cache.',
            [self::CACHE_KEY, $json, 'Internal: FanUpdate Redux Dashboard news feed cache. Do not edit manually.']
        );
    }
}
