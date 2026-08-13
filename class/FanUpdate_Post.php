<?php

/*****************************************************************************
 * FanUpdate — FanUpdate_Post
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 refactored for PHP 8.2+ compatibility
 *
 * Domain object wrapping a single blog entry row: title/body, template-based
 * rendering, category assignment/lookup, and date formatting adjusted to the
 * admin's configured timezone (posts are stored in UTC).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class FanUpdate_Post
{
    // ── explicitly declared properties (PHP 8.2: no dynamic properties) ────
    public array     $params = [];
    public FanUpdate $fu;

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(array $params, FanUpdate $fu, ?string $self = null)
    {
        $this->params = $params;
        $this->fu     = $fu;

        $this->params['entry_id']     = (int)   ($this->params['entry_id']     ?? 0);
        $this->params['num_comments'] = (int)   ($this->params['num_comments'] ?? 0);
        $this->params['is_public']    = (int)   ($this->params['is_public']    ?? 0);
        $this->params['comments_on']  = (int)   ($this->params['comments_on']  ?? 0);
        $this->params['category']     =          ($this->params['category']    ?? []);
        $this->params['title']        = (string)($this->params['title']        ?? '');
        $this->params['body']         = (string)($this->params['body']         ?? '');
        $this->params['added']        = (string)($this->params['added']        ?? gmdate('Y-m-d H:i:s'));

        $base = $self ?? $this->fu->getCleanSelf();
        $this->params['url'] = $base . '?id=' . $this->params['entry_id'];
    }

    // ── param helpers ────────────────────────────────────────────────────────

    public function addParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    // ── identity ─────────────────────────────────────────────────────────────

    public function getID(): int
    {
        return (int) ($this->params['entry_id'] ?? 0);
    }

    // ── title ─────────────────────────────────────────────────────────────────

    public function getTitle(): string
    {
        return (string) ($this->params['title'] ?? '');
    }

    // ── body ──────────────────────────────────────────────────────────────────

    public function getBody(): string
    {
        return (string) ($this->params['body'] ?? '');
    }

    public function getBodyFormatted(): string
    {
        $body  = $this->getBody();
        $limit = (int) $this->fu->getOpt('abstract_word_count');

        if ($limit > 0) {
            $body = truncate_wc($body, $limit);
        }

        return wpautop($body, true, true);
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
     * Return the date formatted for display, adjusted to the admin's
     * configured IANA timezone (FP-19 — replaces the legacy fixed-hour
     * timezone_offset, so daylight saving time is handled automatically).
     *
     * Posts are stored in UTC. We convert to the configured timezone so the
     * displayed date matches the admin's local time rather than the
     * server's UTC clock.
     */
    public function getDateFormatted(string $format = ''): string
    {
        if ($format === '') {
            $format = (string) $this->fu->getOpt('date_format');
        }

        try {
            $dt = new DateTime($this->getDate(), new DateTimeZone('UTC'));
        } catch (Exception) {
            return '';
        }

        // getOpt returns false when the option is missing entirely, so fall
        // back to UTC in that case as well as for an invalid stored value.
        $tz = (string) $this->fu->getOpt(FU_TIMEZONE_OPTION_KEY);
        if (!fu_is_valid_timezone($tz)) {
            $tz = FU_DEFAULT_TIMEZONE;
        }
        $dt->setTimezone(new DateTimeZone($tz));

        return $dt->format($format);
    }

    public function getYear(): string
    {
        return $this->getDateFormatted('Y');
    }

    // ── visibility ────────────────────────────────────────────────────────────

    public function isPublic(): bool
    {
        return !empty($this->params['is_public']);
    }

    // ── comments ─────────────────────────────────────────────────────────────

    public function commentsOn(): bool
    {
        return !empty($this->params['comments_on']);
    }

    public function allowComments(): bool
    {
        return $this->commentsOn();
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    public function getUrl(): string
    {
        return (string) ($this->params['url'] ?? '');
    }

    public function getCommentsUrl(): string
    {
        return $this->getUrl() . '#comments';
    }

    public function getCommentsFeedUrl(): string
    {
        return $this->fu->getOpt('install_url') . '/rss-comments.php?id=' . $this->getID();
    }

    // ── category ──────────────────────────────────────────────────────────────

    public function getCatFromDb(?array $cat_ids = null): void
    {
        if ($cat_ids !== null) {
            $cats = [];
            foreach ($cat_ids as $id) {
                $cats[(int) $id] = ['cat_id' => (int) $id, 'cat_name' => ''];
            }
            $this->params['category'] = $cats;
            return;
        }

        $catjoin_table    = $this->fu->getOpt('catjoin_table');
        $collective_table = $this->fu->getOpt('collective_table');
        $col_id           = $this->fu->getOpt('col_id');
        $col_subj         = $this->fu->getOpt('col_subj');

        $query = 'SELECT j.cat_id, c.' . $col_subj . ' AS cat_name
                  FROM ' . $catjoin_table . ' j
                  LEFT JOIN ' . $collective_table . ' c ON j.cat_id = c.' . $col_id . '
                  WHERE j.entry_id=' . $this->getID();

        $this->fu->db->Execute($query);

        $cats = [];
        while ($row = $this->fu->db->ReadRecord()) {
            $cats[(int) $row['cat_id']] = $row;
        }
        $this->fu->db->FreeResult();

        $this->params['category'] = $cats;
    }

    public function addCategory(int $cat_id, string $cat_name): void
    {
        if (!is_array($this->params['category'])) {
            $this->params['category'] = [];
        }
        $this->params['category'][$cat_id] = ['cat_id' => $cat_id, 'cat_name' => $cat_name];
    }

    public function getCategoryArray(): array
    {
        if (!is_array($this->params['category'])) {
            return [];
        }
        return $this->params['category'];
    }

    public function getCategoryString(): string
    {
        $cats = $this->getCategoryArray();
        if (empty($cats)) {
            return '';
        }

        $names = [];
        foreach ($cats as $row) {
            if (!empty($row['cat_name'])) {
                $names[] = (string) $row['cat_name'];
            }
        }

        return implode(', ', $names);
    }

    public function getCategoryLinks(): string
    {
        $cats = $this->getCategoryArray();
        if (empty($cats)) {
            return 'Uncategorised';
        }

        $base  = $this->fu->getCleanSelf();
        $links = [];

        foreach ($cats as $row) {
            $id   = (int) $row['cat_id'];
            $name = htmlspecialchars((string) ($row['cat_name'] ?? ''));
            if ($name === '') {
                $name = 'Whole Collective';
            }
            $links[] = '<a href="' . $base . '?c=' . $id . '">' . $name . '</a>';
        }

        return implode(', ', $links);
    }

    // ── template rendering ────────────────────────────────────────────────────

    public function printPost(bool $abstract = false): void
    {
        $template = (string) $this->fu->getOpt('entry_template');

        if ($template === '') {
            $template =
                '<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>' . "\n"
                . '<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>' . "\n"
                . '{{body}}';
        }

        $template = str_replace('{{id}}', (string) $this->getID(), $template);
        $template = str_replace('{{title}}', htmlspecialchars($this->getTitle()), $template);
        $template = str_replace('{{url}}', htmlspecialchars($this->getUrl()), $template);
        $template = str_replace('{{date}}', $this->getDateFormatted(), $template);
        $template = str_replace('{{category}}', $this->getCategoryLinks(), $template);

        if ($this->commentsOn()) {
            $num      = (int) ($this->params['num_comments'] ?? 0);
            $label    = $num === 1 ? '1 comment' : $num . ' comments';
            $cmt_link = '<a href="' . htmlspecialchars($this->getCommentsUrl()) . '">' . $label . '</a>';
        } else {
            $cmt_link = 'Comments off';
        }
        $template = str_replace('{{comment_link}}', $cmt_link, $template);

        $body   = $this->getBody();
        $limit  = (int) $this->fu->getOpt('abstract_word_count');
        if ($abstract && $limit > 0) {
            $body = truncate_wc($body, $limit);
        }
        $template = str_replace('{{body}}', wpautop($body, true, true), $template);

        echo $template . "\n";
    }
}
