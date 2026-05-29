<?php
/*****************************************************************************
 * FanUpdate — FanUpdate_Post
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 refactored for PHP 8.2+ compatibility
 *
 * Refactored for PHP 8.2+:
 *  - All dynamic properties declared explicitly (PHP 8.2 deprecates dynamic props)
 *  - Strict scalar type hints and return types throughout
 *  - No utf8_encode() / utf8_decode() usage
 *  - declare(strict_types=1) at file level
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/gpl-3.0.html>.
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

    /**
     * Return the raw (unformatted) body text.
     */
    public function getBody(): string
    {
        return (string) ($this->params['body'] ?? '');
    }

    /**
     * Return the body passed through wpautop() / wptexturize() for HTML output.
     * abstract_word_count is respected: if > 0 the body is truncated first.
     */
    public function getBodyFormatted(): string
    {
        $body  = $this->getBody();
        $limit = (int) $this->fu->getOpt('abstract_word_count');

        if ($limit > 0) {
            $body = truncate_wc($body, $limit);
        }

        return wpautop($body, true, true);
    }

    /**
     * Return a plain-text abstract (word-count-limited, tags stripped).
     * Used by FanUpdate_Comment too — the signature matches that usage.
     *
     * @param int  $words     Word limit (0 = no limit).
     * @param bool $strip_tags Strip HTML tags before returning.
     */
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

    /**
     * Return the raw stored date string (MySQL datetime, UTC).
     */
    public function getDate(): string
    {
        return (string) ($this->params['added'] ?? '');
    }

    /**
     * Return the date formatted according to $format (PHP date() format string).
     * Falls back to the option 'date_format' if no format is supplied.
     */
    public function getDateFormatted(string $format = ''): string
    {
        if ($format === '') {
            $format = (string) $this->fu->getOpt('date_format');
        }

        $ts = strtotime($this->getDate());
        if ($ts === false) {
            return '';
        }

        // Apply the configured server timezone offset (stored in minutes in some
        // versions, hours in others — getOpt returns a string; cast to int).
        $offset = (int) $this->fu->getOpt('_server_tz_offset');
        $ts    -= $offset * 3600;

        return date($format, $ts);
    }

    /**
     * Return the four-digit year of this post (used in archive grouping).
     */
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

    /**
     * Returns true when comments are enabled on this post.
     * Used as both commentsOn() (admin UI) and allowComments() (public/RSS).
     */
    public function commentsOn(): bool
    {
        return !empty($this->params['comments_on']);
    }

    public function allowComments(): bool
    {
        return $this->commentsOn();
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    /**
     * Permanent URL of this entry (single-post page).
     */
    public function getUrl(): string
    {
        return (string) ($this->params['url'] ?? '');
    }

    /**
     * URL of the comments section for this entry.
     * Points to the public blog page with anchor #comments.
     */
    public function getCommentsUrl(): string
    {
        return $this->getUrl() . '#comments';
    }

    /**
     * URL of the per-entry RSS comments feed.
     */
    public function getCommentsFeedUrl(): string
    {
        return $this->fu->getOpt('install_url') . '/rss-comments.php?id=' . $this->getID();
    }

    // ── category ──────────────────────────────────────────────────────────────

    /**
     * Load categories from the catjoin table into $this->params['category'].
     *
     * An optional pre-loaded array of cat_id values can be passed in; when
     * supplied the DB is not queried and the array is used directly
     * (matches the calling pattern in blog.php where cat_id values come
     * from a submitted form).
     *
     * @param array<int>|null $cat_ids Optional list of category IDs (no DB query).
     */
    public function getCatFromDb(?array $cat_ids = null): void
    {
        if ($cat_ids !== null) {
            // Build lightweight category rows from supplied IDs so the rest of
            // the methods work the same way regardless of how cats were loaded.
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

    /**
     * Add a single category to the in-memory category list.
     *
     * @param int    $cat_id   Category ID.
     * @param string $cat_name Category display name.
     */
    public function addCategory(int $cat_id, string $cat_name): void
    {
        if (!is_array($this->params['category'])) {
            $this->params['category'] = [];
        }
        $this->params['category'][$cat_id] = ['cat_id' => $cat_id, 'cat_name' => $cat_name];
    }

    /**
     * Return the category array keyed by cat_id.
     * Used by blog.php to determine which checkboxes to pre-tick.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategoryArray(): array
    {
        if (!is_array($this->params['category'])) {
            return [];
        }
        return $this->params['category'];
    }

    /**
     * Return a comma-separated string of category names (plain text, no links).
     * Used by the admin entry table.
     */
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

    /**
     * Return a comma-separated string of category links for use inside entry
     * templates on the public-facing blog.
     */
    public function getCategoryLinks(): string
    {
        $cats = $this->getCategoryArray();
        if (empty($cats)) {
            return 'Uncategorised';
        }

        $links = [];
        foreach ($cats as $row) {
            $cat_id   = (int)    ($row['cat_id']   ?? 0);
            $cat_name = (string) ($row['cat_name'] ?? '');

            if ($cat_name === '') {
                $cat_name = 'Whole Collective';
            }

            $url     = $this->fu->getOpt('blog_page') . '?c=' . $cat_id;
            $links[] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($cat_name) . '</a>';
        }

        return implode(', ', $links);
    }

    // ── template rendering ────────────────────────────────────────────────────

    /**
     * Print the entry using the configured entry_template.
     *
     * Template variables (from readme.txt):
     *   {{id}}           — entry ID
     *   {{title}}        — entry title
     *   {{url}}          — permalink URL
     *   {{date}}         — formatted date
     *   {{category}}     — category links (or plain names)
     *   {{comment_link}} — link to comments section
     *   {{body}}         — formatted entry body (truncated if abstract_word_count > 0)
     *
     * @param bool $abstract  If true, truncate body to abstract_word_count words.
     */
    public function printPost(bool $abstract = false): void
    {
        $template = (string) $this->fu->getOpt('entry_template');

        if ($template === '') {
            // Hard fallback — mirrors the default set in install.php
            $template =
                '<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>' . "\n"
                . '<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>' . "\n"
                . '{{body}}';
        }

        // ── {{id}} ──
        $template = str_replace('{{id}}', (string) $this->getID(), $template);

        // ── {{title}} ──
        $template = str_replace('{{title}}', htmlspecialchars($this->getTitle()), $template);

        // ── {{url}} ──
        $template = str_replace('{{url}}', htmlspecialchars($this->getUrl()), $template);

        // ── {{date}} ──
        $template = str_replace('{{date}}', $this->getDateFormatted(), $template);

        // ── {{category}} ──
        $template = str_replace('{{category}}', $this->getCategoryLinks(), $template);

        // ── {{comment_link}} ──
        if ($this->commentsOn()) {
            $num      = (int) ($this->params['num_comments'] ?? 0);
            $label    = $num === 1 ? '1 comment' : $num . ' comments';
            $cmt_link = '<a href="' . htmlspecialchars($this->getCommentsUrl()) . '">' . $label . '</a>';
        } else {
            $cmt_link = 'Comments off';
        }
        $template = str_replace('{{comment_link}}', $cmt_link, $template);

        // ── {{body}} ──
        $body = $this->getBody();
        $limit = (int) $this->fu->getOpt('abstract_word_count');
        if ($abstract && $limit > 0) {
            $body = truncate_wc($body, $limit);
        }
        $formatted_body = wpautop($body, true, true);
        $template = str_replace('{{body}}', $formatted_body, $template);

        echo $template . "\n";
    }
}
