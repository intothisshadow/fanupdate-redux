<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedFetcherInterface
 *
 * The actual network+parse boundary for the Dashboard "FanUpdate Redux
 * News" widget (FP-23). Kept separate from NewsFeedService so the service's
 * caching/due-check/fallback logic can be unit tested with a mock fetcher,
 * without ever making a real network call — this project has no existing
 * precedent for testing the transport layer itself directly (see
 * class/Update/GitHubUpdateProvider.php's private request()/
 * requestViaCurl()/requestViaStream(), reachable in tests only via the
 * higher UpdateProviderInterface boundary); this interface is the
 * equivalent seam for the news feed.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

interface NewsFeedFetcherInterface
{
    /**
     * Fetch and parse an RSS 2.0 feed's <channel><item> entries.
     *
     * @return array<int, array{title: string, link: string, date: string, description: string}>
     * @throws \RuntimeException on a non-HTTPS URL, transport failure, a
     *         non-2xx HTTP status, or unparsable feed content.
     */
    public function fetch(string $url): array;
}
