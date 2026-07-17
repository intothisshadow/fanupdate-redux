<?php

/*****************************************************************************
 * FanUpdate — FanUpdate_Comment
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 refactored for PHP 8.2+ compatibility
 *
 * Domain object wrapping a single comment row: commenter info, formatted
 * body, date formatting (adjusted to the admin's configured timezone_offset,
 * matching FanUpdate_Post), Gravatar URL/tag, and template-based rendering.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class FanUpdate_Comment
{
    // ── explicitly declared properties (PHP 8.2: no dynamic properties) ────
    public array     $params = [];
    public FanUpdate $fu;

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(array $params, FanUpdate $fu, ?string $self = null)
    {
        $this->params = $params;
        $this->fu     = $fu;

        $this->params['comment_id'] = (int)   ($this->params['comment_id'] ?? 0);
        $this->params['entry_id']   = (int)   ($this->params['entry_id']   ?? 0);
        $this->params['approved']   = (int)   ($this->params['approved']   ?? 0);
        $this->params['name']       = (string)($this->params['name']       ?? 'Anonymous');
        $this->params['email']      = (string)($this->params['email']      ?? '');
        $this->params['url']        = (string)($this->params['url']        ?? '');
        $this->params['comment']    = (string)($this->params['comment']    ?? '');
        $this->params['added']      = (string)($this->params['added']      ?? date('Y-m-d H:i:s'));

        $base = $self ?? $this->fu->getCleanSelf();
        $this->params['link_url'] = $base
            . '?id=' . $this->params['entry_id']
            . '#comment' . $this->params['comment_id'];
    }

    // ── param helper ─────────────────────────────────────────────────────────

    public function addParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    // ── identity ─────────────────────────────────────────────────────────────

    public function getID(): int
    {
        return (int) ($this->params['comment_id'] ?? 0);
    }

    public function getEntryID(): int
    {
        return (int) ($this->params['entry_id'] ?? 0);
    }

    // ── commenter info ────────────────────────────────────────────────────────

    public function getName(): string
    {
        return (string) ($this->params['name'] ?? 'Anonymous');
    }

    public function getEmail(): string
    {
        return (string) ($this->params['email'] ?? '');
    }

    public function getUrl(): string
    {
        return (string) ($this->params['url'] ?? '');
    }

    public function getPoints(): int
    {
        return (int) ($this->params['points'] ?? 0);
    }

    // ── body ──────────────────────────────────────────────────────────────────

    public function getBody(): string
    {
        return (string) ($this->params['comment'] ?? '');
    }

    public function getBodyFormatted(): string
    {
        return wpautop($this->getBody(), true, false);
    }

    public function getAbstract(int $words = 0, bool $strip_tags = false): string
    {
        $text = $this->getBody();

        if ($words > 0) {
            $text = truncate_wc($text, $words);
        }

        if ($strip_tags) {
            $text = strip_tags($text);
        }

        return $text;
    }

    // ── date ──────────────────────────────────────────────────────────────────

    public function getDate(): string
    {
        return (string) ($this->params['added'] ?? '');
    }

    /**
     * Return the date formatted for display, adjusted to the admin's configured
     * local timezone (timezone_offset = hours difference from UTC).
     *
     * Uses timezone_offset instead of the old _server_tz_offset — see
     * FanUpdate_Post::getDateFormatted() for full explanation.
     */
    public function getDateFormatted(string $format = ''): string
    {
        if ($format === '') {
            $format = (string) $this->fu->getOpt('date_format');
        }

        $ts = strtotime($this->getDate() . ' UTC');
        if ($ts === false) {
            return '';
        }

        $raw    = $this->fu->getOpt('timezone_offset');
        $offset = ($raw === false) ? 0 : (int) $raw;
        $ts    += $offset * 3600;

        return date($format, $ts);
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function isApproved(): bool
    {
        return !empty($this->params['approved']);
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    public function getLinkUrl(): string
    {
        return (string) ($this->params['link_url'] ?? '');
    }

    // ── Gravatar ──────────────────────────────────────────────────────────────

    public function getGravatarUrl(int $size = 0): string
    {
        if (!$this->fu->getOpt('gravatar_on')) {
            return (string) $this->fu->getOpt('gravatar_default');
        }

        if ($size <= 0) {
            $size = (int) $this->fu->getOpt('gravatar_size');
            if ($size <= 0) {
                $size = 80;
            }
        }

        $email   = strtolower(trim($this->getEmail()));
        $hash    = md5($email);
        $default = urlencode((string) $this->fu->getOpt('gravatar_default'));
        $rating  = (string) $this->fu->getOpt('gravatar_rating');
        if ($rating === '') {
            $rating = 'G';
        }

        return 'https://www.gravatar.com/avatar/' . $hash
            . '?s=' . $size
            . '&d=' . $default
            . '&r=' . $rating;
    }

    public function getGravatar(int $size = 0): string
    {
        if (!$this->fu->getOpt('gravatar_on')) {
            return '';
        }

        if ($size <= 0) {
            $size = (int) $this->fu->getOpt('gravatar_size');
            if ($size <= 0) {
                $size = 80;
            }
        }

        $url  = htmlspecialchars($this->getGravatarUrl($size));
        $name = htmlspecialchars($this->getName());

        return '<img src="' . $url . '" alt="' . $name . '" width="' . $size . '" height="' . $size . '" class="gravatar" />';
    }

    // ── commenter link ────────────────────────────────────────────────────────

    public function getCommenterLink(): string
    {
        $name = htmlspecialchars($this->getName());
        $url  = $this->getUrl();

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            return '<a href="' . htmlspecialchars($url) . '" rel="nofollow">' . $name . '</a>';
        }

        return $name;
    }

    // ── template rendering ────────────────────────────────────────────────────

    public function printComment(): void
    {
        $template = (string) $this->fu->getOpt('comment_template');

        if ($template === '') {
            $template =
                '{{gravatar}}' . "\n"
                . '<p class="commenter">On '
                . '<a href="#comment{{id}}" title="permanent link to this comment">{{date}}</a> '
                . '{{name}} said:</p>' . "\n"
                . '{{body}}';
        }

        $template = str_replace('{{id}}', (string) $this->getID(), $template);
        $template = str_replace('{{name}}', $this->getCommenterLink(), $template);
        $template = str_replace('{{gravatar}}', $this->getGravatar(), $template);
        $template = str_replace('{{date}}', $this->getDateFormatted(), $template);
        $template = str_replace('{{body}}', $this->getBodyFormatted(), $template);

        echo '<div id="comment' . $this->getID() . '" class="comment">' . "\n";
        echo $template . "\n";
        echo "</div>\n";
    }
}
