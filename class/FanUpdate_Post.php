<?php
/*****************************************************************************
 * FanUpdate_Post — PHP 8.2+ refactor
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+ compatibility:
 *   - declare(strict_types=1) added
 *   - All properties explicitly declared with types
 *   - Strict return types on all methods
 ******************************************************************************/

declare(strict_types=1);

class FanUpdate_Post
{
    // Explicitly declared — PHP 8.2 forbids dynamic properties
    public array     $params = [];
    public FanUpdate $fu;

    public function __construct(array $params, FanUpdate $fu, ?string $self = null)
    {
        $this->params = $params;
        $this->fu     = $fu;

        $this->params['entry_id']     = (int)($this->params['entry_id']     ?? 0);
        $this->params['num_comments'] = (int)($this->params['num_comments'] ?? 0);
        $this->params['category']     = $this->params['category']     ?? [];
        $this->params['title']        = $this->params['title']        ?? '';
        $this->params['body']         = $this->params['body']         ?? '';
        $this->params['added']        = $this->params['added']        ?? gmdate('Y-m-d H:i:s');

        $this->params['url']  = ($self ?? $this->fu->getCleanSelf()) . '?id=' . $this->params['entry_id'];
    }

    public function getID(): int
    {
        return (int)$this->params['entry_id'];
    }

    public function getTitle(): string
    {
        return (string)$this->params['title'];
    }

    public function getBody(): string
    {
        return (string)$this->params['body'];
    }

    public function getDate(): string
    {
        return (string)$this->params['added'];
    }

    public function getDateFormatted(string|false $format = false): string
    {
        if ($format === false) {
            $format = (string)$this->fu->getOpt('date_format');
        }
        return date(
            $format,
            strtotime((string)$this->params['added']) + ((int)$this->fu->getOpt('timezone_offset') * 3600)
        );
    }

    public function getUrl(): string
    {
        return (string)$this->params['url'];
    }

    public function getNumComments(): int
    {
        return (int)$this->params['num_comments'];
    }

    public function allowComments(): bool
    {
        if (!$this->fu->getOpt('comments_on')) {
            return false;
        }
        return (bool)($this->params['allow_comments'] ?? true);
    }

    public function addParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    /**
     * Fetch and populate category data from the database.
     */
    public function getCatFromDb(): void
    {
        $query = 'SELECT j.cat_id, c.' . $this->fu->getOpt('col_subj') . ' AS cat_name
          FROM ' . $this->fu->getOpt('catjoin_table') . ' j
          LEFT JOIN ' . $this->fu->getOpt('collective_table') . ' c
            ON j.cat_id = c.' . $this->fu->getOpt('col_id') . '
          WHERE j.entry_id = ' . $this->getID();

        $this->fu->db->Execute($query);
        $cats = [];
        while ($row = $this->fu->db->ReadRecord()) {
            $cats[(int)$row['cat_id']] = (string)$row['cat_name'];
        }
        $this->fu->db->FreeResult();
        $this->params['category'] = $cats;
    }

    public function getCategoryLinks(): string
    {
        $links = [];
        foreach ((array)$this->params['category'] as $cat_id => $cat_name) {
            $name    = $cat_name ?: 'Whole Collective';
            $links[] = '<a href="' . $this->fu->getCleanSelf() . '?c=' . $cat_id . '">'
                     . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        return implode(', ', $links);
    }

    public function getCommentLink(): string
    {
        $num  = $this->getNumComments();
        $label = $num === 1 ? '1 comment' : $num . ' comments';
        return '<a href="' . $this->getUrl() . '#comments">' . $label . '</a>';
    }

    public function getAbstract(int $words = 20, bool $doSmile = false): string
    {
        $text = truncate_wc(strip_tags((string)$this->params['body']), $words);
        if ($doSmile) {
            $text = $this->fu->replaceSmilies($text);
        }
        return $text;
    }

    public function getBodyFormatted(): string
    {
        return wpautop($this->fu->replaceSmilies((string)$this->params['body']));
    }

    /**
     * Output the post using the configured entry template.
     *
     * @param bool $abstract  If true, display truncated abstract instead of full body.
     */
    public function printPost(bool $abstract = false): void
    {
        $word_count = (int)$this->fu->getOpt('abstract_word_count');

        if ($abstract && $word_count > 0) {
            $body = $this->getAbstract($word_count, true);
        } else {
            $body = $this->getBodyFormatted();
        }

        $text = (string)$this->fu->getOpt('entry_template');
        $text = str_replace('{{id}}',           (string)$this->getID(),          $text);
        $text = str_replace('{{title}}',        htmlspecialchars($this->getTitle(), ENT_QUOTES, 'UTF-8'), $text);
        $text = str_replace('{{url}}',          htmlspecialchars($this->getUrl(), ENT_QUOTES, 'UTF-8'),   $text);
        $text = str_replace('{{date}}',         $this->getDateFormatted(),        $text);
        $text = str_replace('{{category}}',     $this->getCategoryLinks(),        $text);
        $text = str_replace('{{comment_link}}', $this->getCommentLink(),          $text);
        $text = str_replace('{{body}}',         $body,                            $text);

        echo $text . "\n";
    }
}
