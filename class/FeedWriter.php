<?php

declare(strict_types=1);

/*****************************************************************************
 * Universal Feed Writer
 *
 * Copyright (c) Anis uddin Ahmad <anisniit@gmail.com>
 * Copyright (c) 2008 by Jenny Ferenc (contributor) <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Generates RSS 2.0 or Atom feeds: channel-level metadata, namespace
 * declarations, item collection, and XML output (with CDATA wrapping for
 * HTML-bearing elements such as description/content:encoded/summary).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 ******************************************************************************/

define('RSS2', 'RSS 2.0');
define('ATOM', 'ATOM');

if (!defined('DATE_RSS')) {
    define('DATE_RSS', 'r');
}
if (!defined('DATE_ATOM')) {
    define('DATE_ATOM', 'Y-m-d\TH:i:sP');
}

require_once(__DIR__ . '/FeedItem.php');

class FeedWriter
{
    // ── explicitly declared properties ─────────────────────────────────────
    public array  $channels      = [];
    public array  $items         = [];
    public array  $namespaces    = [];
    public array  $names         = [];
    public array  $CDATAEncoding = [];
    public string $version;

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(string $version = RSS2)
    {
        $this->version = $version;

        $this->channels['title'] = $version . ' Feed';
        $this->channels['link']  = 'http://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $this->names = [
            'atom'    => 'http://www.w3.org/2005/Atom',
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'itunes'  => 'http://www.itunes.com/dtds/podcast-1.0.dtd',
            'slash'   => 'http://purl.org/rss/1.0/modules/slash/',
            'wfw'     => 'http://wellformedweb.org/CommentAPI/',
        ];

        $this->CDATAEncoding = ['description', 'content:encoded', 'summary'];

        if ($this->version === RSS2) {
            $this->setChannelElement('docs', 'http://www.rssboard.org/rss-specification');
        } elseif ($this->version === ATOM) {
            $this->addNamespace('', $this->names['atom']);
        }
    }

    // ── channel helpers ─────────────────────────────────────────────────────

    public function setChannelElement(string $elementName, string $content): void
    {
        $this->channels[$elementName] = $content;
    }

    public function setChannelElementsFromArray(array $elementArray): void
    {
        foreach ($elementArray as $elementName => $content) {
            $this->setChannelElement((string) $elementName, (string) $content);
        }
    }

    public function setTitle(string $title): void
    {
        $this->setChannelElement('title', $title);
    }

    public function setDescription(string $description): void
    {
        $this->setChannelElement('description', $description);
    }

    public function setLink(string $link): void
    {
        $this->setChannelElement('link', $link);
    }

    public function setLinkSelf(string $link): void
    {
        if ($this->version === RSS2) {
            $this->setChannelElement('atom:link', $link);
        } elseif ($this->version === ATOM) {
            $this->setChannelElement('link', $link);
        }
    }

    public function setAuthor(string $email, string $name = ''): void
    {
        if ($this->version === RSS2) {
            $author = $email;
            if ($name !== '') {
                $author .= ' (' . $name . ')';
            }
            $this->setChannelElement('managingEditor', $author);
        } elseif ($this->version === ATOM) {
            // ATOM author is a nested element — handled in printChannels
            $this->channels['author'] = ['name' => $name, 'email' => $email];
        }
    }

    public function addNamespace(string $prefix, string $uri): void
    {
        $this->namespaces[$prefix] = $uri;
    }

    // ── item helpers ────────────────────────────────────────────────────────

    public function createNewItem(): FeedItem
    {
        return new FeedItem($this->version);
    }

    public function addItem(FeedItem $feedItem): void
    {
        $this->items[] = $feedItem;
    }

    // ── feed generation ─────────────────────────────────────────────────────

    public function generateFeed(): void
    {
        header('Content-type: text/xml; charset=UTF-8');
        $this->printHead();
        $this->printChannels();
        $this->printItems();
        $this->printTail();
    }

    public function printHead(): void
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;

        if ($this->version === RSS2) {
            $out .= '<rss version="2.0"';
        } elseif ($this->version === ATOM) {
            $out .= '<feed';
        }

        foreach ($this->namespaces as $key => $val) {
            $out .= ' xmlns';
            if ($key !== '') {
                $out .= ':' . $key;
            }
            $out .= '="' . $val . '"';
        }
        $out .= '>' . PHP_EOL;

        echo $out;
    }

    public function printTail(): void
    {
        if ($this->version === RSS2) {
            echo '</channel>' . PHP_EOL . '</rss>';
        } elseif ($this->version === ATOM) {
            echo '</feed>';
        }
    }

    public function printChannels(): void
    {
        if ($this->version === RSS2) {
            echo '<channel>' . PHP_EOL;
        }

        foreach ($this->channels as $key => $value) {
            if ($this->version === ATOM && $key === 'link') {
                echo $this->makeNode($key, '', ['href' => $value]);
                echo $this->makeNode('id', $this->uuid($value, 'urn:uuid:'));
            } else {
                echo $this->makeNode($key, $value);
            }
        }
    }

    public function printItems(): void
    {
        foreach ($this->items as $item) {
            $thisItems = $item->getElements();
            $this->startItem($thisItems['link']['content'] ?? '');

            foreach ($thisItems as $feedItem) {
                echo $this->makeNode(
                    $feedItem['name'],
                    $feedItem['content'],
                    $feedItem['attributes']
                );
            }
            $this->endItem();
        }
    }

    public function startItem(string $about = ''): void
    {
        if ($this->version === RSS2) {
            echo '<item>' . PHP_EOL;
        } elseif ($this->version === ATOM) {
            echo '<entry>' . PHP_EOL;
        }
    }

    public function endItem(): void
    {
        if ($this->version === RSS2) {
            echo '</item>' . PHP_EOL;
        } elseif ($this->version === ATOM) {
            echo '</entry>' . PHP_EOL;
        }
    }

    // ── node building ────────────────────────────────────────────────────────

    public function makeNode(string $tagName, mixed $tagContent, ?array $attributes = null): string
    {
        $nodeText = '';
        $attrText = '';

        if (is_array($attributes)) {
            foreach ($attributes as $key => $value) {
                $attrText .= ' ' . $key . '="' . $value . '"';
            }
        }

        if (in_array($tagName, $this->CDATAEncoding, true) && $this->version === ATOM) {
            $attrText .= ' type="html"';
        }

        $doCDATA = in_array($tagName, $this->CDATAEncoding, true)
            && is_string($tagContent)
            && str_contains($tagContent, '<');

        if (empty($tagContent)) {
            $nodeText .= "<{$tagName}{$attrText} />";
        } else {
            $nodeText .= $doCDATA
                ? "<{$tagName}{$attrText}><![CDATA["
                : "<{$tagName}{$attrText}>";

            if (is_array($tagContent)) {
                $nodeText .= PHP_EOL;
                foreach ($tagContent as $key => $value) {
                    $nodeText .= $this->makeNode((string) $key, $value);
                }
            } else {
                $nodeText .= $doCDATA
                    ? (string) $tagContent
                    : htmlspecialchars((string) $tagContent, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }

            $nodeText .= $doCDATA ? "]]></{$tagName}>" : "</{$tagName}>";
        }

        return $nodeText . PHP_EOL;
    }

    // ── UUID helper ─────────────────────────────────────────────────────────

    public function uuid(string $url, string $prefix = ''): string
    {
        $parsed = parse_url($url);
        $host   = $parsed['host']   ?? 'localhost';
        $path   = $parsed['path']   ?? '/';
        $query  = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $id     = md5($host . $path . $query);

        return $prefix
            . substr($id, 0, 8) . '-'
            . substr($id, 8, 4) . '-'
            . substr($id, 12, 4) . '-'
            . substr($id, 16, 4) . '-'
            . substr($id, 20);
    }
}
