<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedService
 *
 * Cache-first fetch for the Dashboard "FanUpdate Redux News" widget
 * (FP-23), mirroring UpdateManager's cache-first/check-when-due pattern
 * (class/Service/UpdateManager.php's getStatusSummary()/
 * runAutomaticCheckIfDue()) — the Dashboard is the highest-traffic admin
 * page, so a slow or unreachable remote feed must never make it hang or
 * error. refreshIfDue() always returns something renderable: a fresh fetch
 * when the cache is stale and reachable, the existing cache when it's
 * still fresh, and a graceful fall-back to whatever is cached (even if
 * stale) when the live fetch fails — it never throws to the caller.
 *
 * Depends on NewsFeedRepositoryInterface/NewsFeedFetcherInterface rather
 * than the concrete classes, so it can be unit tested with fakes/mocks for
 * both — no database and no real network call needed in tests.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class NewsFeedService
{
    private const DEFAULT_MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly NewsFeedRepositoryInterface $repository,
        private readonly NewsFeedFetcherInterface $fetcher,
    ) {
    }

    /**
     * Cached items only — no network call. Safe to call on every admin page
     * load.
     *
     * @return array<int, array{title: string, link: string, date: string, description: string}>
     */
    public function getCachedItems(): array
    {
        $cache = $this->repository->getCache();

        return $cache['items'] ?? [];
    }

    /**
     * Cache hit for the SAME feed URL, within $maxAgeSeconds → return
     * cached items untouched, no network call. Cache stale, missing, or
     * for a DIFFERENT feed URL (an admin just changed news_feed_url) →
     * fetch live; on success, persist and return the fresh items; on
     * failure, fall back to whatever is cached for this URL (even if
     * stale) rather than throwing — or an empty list if the cache belongs
     * to a different (now-abandoned) URL, since serving a stale result
     * from a feed the admin no longer configured would be more confusing
     * than showing nothing.
     *
     * @return array<int, array{title: string, link: string, date: string, description: string}>
     */
    public function refreshIfDue(string $feedUrl, int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS): array
    {
        $cache          = $this->repository->getCache();
        $cacheIsForUrl  = ($cache['url'] ?? null) === $feedUrl;
        $cachedItems    = $cacheIsForUrl ? ($cache['items'] ?? []) : [];
        $fetchedAt      = $cacheIsForUrl ? ($cache['fetchedAt'] ?? null) : null;

        if (is_string($fetchedAt) && $fetchedAt !== '') {
            $fetchedAtTs = strtotime($fetchedAt);
            if ($fetchedAtTs !== false && time() < $fetchedAtTs + $maxAgeSeconds) {
                return $cachedItems;
            }
        }

        try {
            $items = $this->fetcher->fetch($feedUrl);
        } catch (\Throwable) {
            return $cachedItems;
        }

        $this->repository->saveCache($items, gmdate('c'), $feedUrl);

        return $items;
    }
}
