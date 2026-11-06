<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedService
 *
 * Cache-first fetch for the Dashboard "FanUpdate Redux News" widget, in the
 * style of UpdateManager. The Dashboard is the busiest admin page, so a slow
 * or unreachable feed must never hang or break it: refreshIfDue() always
 * returns something renderable and never throws.
 *
 * Depends on the repository and fetcher interfaces, so tests need no database
 * or network.
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
     * Cached items only (no network call).
     *
     * @return array<int, array{title: string, link: string, date: string, description: string}>
     */
    public function getCachedItems(): array
    {
        $cache = $this->repository->getCache();

        return $cache['items'] ?? [];
    }

    /**
     * Returns the cache if it is for this URL and within $maxAgeSeconds.
     * Otherwise fetches live and persists; on failure falls back to this
     * URL's stale cache, or [] if the cache is for a different, abandoned URL.
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
