<?php

declare(strict_types=1);

/*****************************************************************************
 * Universal Feed Writer — FeedItem
 *
 * Copyright (c) Anis uddin Ahmad <anisniit@gmail.com>
 * Copyright (c) 2008 by Jenny Ferenc (contributor) <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Represents a single feed entry (RSS 2.0 or Atom): element storage plus
 * convenience setters (title, description, date, link, author, enclosure)
 * that emit the correct element/attribute shape for the active feed version.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 ******************************************************************************/

class FeedItem
{
    // ── explicitly declared properties ─────────────────────────────────────
    public array  $elements = [];
    public string $version;

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(string $version = RSS2)
    {
        $this->version = $version;
    }

    // ── element management ──────────────────────────────────────────────────

    public function addElement(string $elementName, mixed $content, ?array $attributes = null): void
    {
        $this->elements[$elementName]['name']       = $elementName;
        $this->elements[$elementName]['content']    = $content;
        $this->elements[$elementName]['attributes'] = $attributes;
    }

    public function addElementArray(array $elementArray): void
    {
        foreach ($elementArray as $elementName => $content) {
            $this->addElement((string) $elementName, $content);
        }
    }

    public function getElements(): array
    {
        return $this->elements;
    }

    // ── wrapper helpers ─────────────────────────────────────────────────────

    public function setDescription(string $description): void
    {
        $tag = ($this->version === ATOM) ? 'summary' : 'description';
        $this->addElement($tag, $description);
    }

    public function setTitle(string $title): void
    {
        $this->addElement('title', $title);
    }

    public function setDate(string|int $date): void
    {
        if (!is_numeric($date)) {
            $date = strtotime((string) $date);
        }
        $date = (int) $date;

        if ($this->version === ATOM) {
            $tag   = 'updated';
            $value = date(DATE_ATOM, $date);
        } elseif ($this->version === RSS2) {
            $tag   = 'pubDate';
            $value = date(DATE_RSS, $date);
        } else {
            $tag   = 'dc:date';
            $value = date('Y-m-d', $date);
        }

        $this->addElement($tag, $value);
    }

    public function setLink(string $link, bool $guid = false): void
    {
        if ($this->version === RSS2) {
            $this->addElement('link', $link);
            if ($guid) {
                $this->addElement('guid', $link, ['isPermaLink' => 'true']);
            }
        } elseif ($this->version === ATOM) {
            $this->addElement('link', '', ['href' => $link]);
            $this->addElement('id', (new FeedWriter())->uuid($link, 'urn:uuid:'));
        }
    }

    public function setAuthor(string $email, string $name = ''): void
    {
        if ($this->version === RSS2) {
            if ($email === '') {
                $email = 'nobody@example.com';
            }
            $author = $email;
            if ($name !== '') {
                $author .= ' (' . $name . ')';
            }
            $this->addElement('author', $author);
        } elseif ($this->version === ATOM) {
            $authorArray = ['email' => $email];
            if ($name !== '') {
                $authorArray['name'] = $name;
            }
            $this->addElement('author', $authorArray);
        }
    }

    public function setEnclosure(string $url, int $length, string $type): void
    {
        $this->addElement('enclosure', '', [
            'url'    => $url,
            'length' => $length,
            'type'   => $type,
        ]);
    }

    /**
     * Safely convert a string to UTF-8.
     * Replaces the deprecated utf8_encode() from PHP 8.2.
     */
    public static function toUtf8(string $value): string
    {
        // mb_convert_encoding is the recommended replacement for utf8_encode()
        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Safely convert a UTF-8 string to ISO-8859-1.
     * Replaces the deprecated utf8_decode() from PHP 8.2.
     */
    public static function fromUtf8(string $value): string
    {
        return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    }
}
