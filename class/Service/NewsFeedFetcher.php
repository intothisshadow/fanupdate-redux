<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — NewsFeedFetcher
 *
 * Default NewsFeedFetcherInterface implementation for the Dashboard
 * "FanUpdate Redux News" widget (FP-23): fetches an admin-configured RSS
 * feed URL and parses its <channel><item> entries.
 *
 * HTTPS-only (rejected outright before any request is attempted — defence
 * in depth, mirroring GitHubUpdateProvider::request()'s URL allowlist
 * check), cURL-first with a file_get_contents()+stream-context fallback
 * when the curl extension isn't available, short timeout — same safety
 * posture as class/Update/GitHubUpdateProvider.php's
 * requestViaCurl()/requestViaStream(), reimplemented generically here
 * rather than reused since that logic is private and GitHub-API-coupled
 * (fixed API base URL, GitHub-specific headers/error handling).
 *
 * Parsing replaces the old dead FanUpdate::checkUpdates() method's
 * DOMDocument logic (removed — see docs/CHANGELOG.md), fixing its two real
 * bugs: node access here is null-safe (checkUpdates() called
 * ->item(0)->nodeValue directly, fatal-erroring on any item missing an
 * expected element) and failures always throw \RuntimeException rather
 * than assuming every feed guarantees every field.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class NewsFeedFetcher implements NewsFeedFetcherInterface
{
    private const TIMEOUT_SECONDS = 8;

    /** Hard cap on parsed items — defends against a pathologically large feed. */
    private const MAX_ITEMS = 20;

    public function fetch(string $url): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Refused to fetch a non-HTTPS news feed URL.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('Invalid news feed URL.');
        }

        [$status, $body] = function_exists('curl_init')
            ? $this->requestViaCurl($url)
            : $this->requestViaStream($url);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('News feed request failed with HTTP status ' . $status . '.');
        }

        return $this->parseFeed($body);
    }

    /** @return array{0:int,1:string} */
    private function requestViaCurl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise a cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: FanUpdateRedux-NewsFeed'],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Could not connect to news feed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }

    /** @return array{0:int,1:string} */
    private function requestViaStream(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'header'           => 'User-Agent: FanUpdateRedux-NewsFeed',
                'timeout'          => self::TIMEOUT_SECONDS,
                'ignore_errors'    => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new \RuntimeException(
                'Could not connect to news feed (allow_url_fopen may be disabled and the curl extension is not installed).'
            );
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, (string) $body];
    }

    /** @return array<int, array{title: string, link: string, date: string, description: string}> */
    private function parseFeed(string $xml): array
    {
        $doc              = new \DOMDocument();
        $previousSetting  = libxml_use_internal_errors(true);
        $loaded           = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if (!$loaded) {
            throw new \RuntimeException('Could not parse news feed XML.');
        }

        $channels = $doc->getElementsByTagName('channel');
        if ($channels->length === 0) {
            return [];
        }

        $channel = $channels->item(0);
        if (!$channel instanceof \DOMElement) {
            return [];
        }

        $items = [];
        foreach ($channel->getElementsByTagName('item') as $itemNode) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $items[] = [
                'title'       => $this->firstChildText($itemNode, 'title'),
                'link'        => $this->firstChildText($itemNode, 'link'),
                'date'        => $this->firstChildText($itemNode, 'pubDate'),
                'description' => $this->firstChildText($itemNode, 'description'),
            ];
        }

        return $items;
    }

    private function firstChildText(\DOMElement $parent, string $tagName): string
    {
        $nodes = $parent->getElementsByTagName($tagName);

        return $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';
    }
}
