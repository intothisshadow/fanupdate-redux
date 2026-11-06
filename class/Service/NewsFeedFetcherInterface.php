<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedFetcherInterface
 *
 * The network and parse boundary for the Dashboard news widget, separate
 * from NewsFeedService so its caching and fallback logic can be tested with
 * a mock fetcher and no network.
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
