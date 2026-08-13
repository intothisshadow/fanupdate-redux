<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedRepositoryInterface
 *
 * Data-access contract for the Dashboard's "FanUpdate Redux News" widget
 * (FP-23) cache. Storage piggybacks on the existing options table via a
 * single private (underscore-prefixed) key, `_news_feed_cache`, holding a
 * JSON blob — same pattern as UpdateRepositoryInterface's `_update_state`,
 * which doubles as the response cache that lets the Dashboard show status
 * without a live network call on every page load. Private keys are already
 * excluded from admin/options.php's editable list and from
 * OptionRepository::listEditableOptions(), so this data never appears
 * there.
 *
 * NewsFeedService (see class/Service/NewsFeedService.php) depends on this
 * interface rather than the concrete NewsFeedRepository, so it can be unit
 * tested with a fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

interface NewsFeedRepositoryInterface
{
    /**
     * @return array{items: array<int, array{title: string, link: string, date: string, description: string}>, fetchedAt: string, url: string}|array{}
     *         Empty array when nothing has ever been cached yet. 'url' is
     *         the feed URL this cache was actually fetched from — lets
     *         NewsFeedService::refreshIfDue() notice an admin changed
     *         news_feed_url and force an immediate refetch instead of
     *         serving another feed's stale (or empty) cached result for up
     *         to the rest of the freshness window.
     */
    public function getCache(): array;

    /**
     * @param array<int, array{title: string, link: string, date: string, description: string}> $items
     * @param string $fetchedAt ISO 8601 UTC timestamp of this fetch.
     * @param string $url The feed URL this fetch was made against.
     */
    public function saveCache(array $items, string $fetchedAt, string $url): bool;
}
