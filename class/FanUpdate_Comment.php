<?php
/*****************************************************************************
 * FanUpdate_Comment — PHP 8.2+ refactor
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+ compatibility:
 *   - declare(strict_types=1) added
 *   - All properties explicitly declared with types
 *   - Strict return types on all methods
 *   - Gravatar URL updated from HTTP to HTTPS
 ******************************************************************************/

declare(strict_types=1);

class FanUpdate_Comment
{
    // Explicitly declared — PHP 8.2 forbids dynamic properties
    public array     $params = [];
    public FanUpdate $fu;

    public function __construct(array $params, FanUpdate $fu, ?string $self = null)
    {
        $this->params = $params;
        $this->fu     = $fu;

        $this->params['comment_id'] = (int)($this->params['comment_id'] ?? 0);
        $this->params['entry_id']   = (int)($this->params['entry_id']   ?? 0);
        $this->params['name']       = $this->params['name']    ?? 'Anonymous';
        $this->params['email']      = $this->params['email']   ?? '';
        $this->params['url']        = $this->params['url']     ?? '';
        $this->params['comment']    = $this->params['comment'] ?? '';
        $this->params['added']      = $this->params['added']   ?? date('Y-m-d H:i:s');
        $this->params['points']     = (int)($this->params['points'] ?? 0);
        $this->params['approved']   = isset($this->params['approved']) && (int)$this->params['approved'] === 1;

        $base = $self ?? $this->fu->getCleanSelf();
        $this->params['link_url'] = $base . '?id=' . $this->params['entry_id'] . '#comment' . $this->params['comment_id'];
    }

    public function getID(): int
    {
        return (int)$this->params['comment_id'];
    }

    public function getLinkUrl(): string
    {
        return (string)$this->params['link_url'];
    }

    public function isApproved(): bool
    {
        return (bool)$this->params['approved'];
    }

    public function getPoints(): int
    {
        return (int)$this->params['points'];
    }

    public function getName(): string
    {
        return (string)$this->params['name'];
    }

    public function getEmail(): string
    {
        return (string)$this->params['email'];
    }

    public function getUrl(): string
    {
        return (string)$this->params['url'];
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

    public function getAbstract(int $words = 10, bool $doSmile = false): string
    {
        $text = truncate_wc(strip_tags((string)$this->params['comment']), $words);
        if ($doSmile) {
            $text = $this->fu->replaceSmilies($text);
        }
        return $text;
    }

    public function getBody(): string
    {
        return (string)$this->params['comment'];
    }

    public function getBodyFormatted(): string
    {
        return wpautop($this->fu->replaceSmilies((string)$this->params['comment']));
    }

    public function getCommenterLink(): string
    {
        $name = htmlspecialchars((string)$this->params['name'], ENT_QUOTES, 'UTF-8');

        if (empty($this->params['url'])) {
            return '<span class="commenter">' . $name . '</span>';
        }

        $url = htmlspecialchars((string)$this->params['url'], ENT_QUOTES, 'UTF-8');
        return '<a class="commenter" href="' . $url . '" title="' . $name . '&#8217;s website">' . $name . '</a>';
    }

    public function getGravatarUrl(int $size = 80, string $rating = 'G', ?string $default = null): string
    {
        if (!$this->fu->getOpt('gravatar_on')) {
            return '';
        }

        if ($default === null) {
            $default = (string)$this->fu->getOpt('gravatar_default');
        }

        if (is_email((string)$this->params['email'])) {
            // Use HTTPS Gravatar endpoint (old HTTP URL deprecated)
            return 'https://www.gravatar.com/avatar/' . md5(strtolower(trim((string)$this->params['email'])))
                . '?rating=' . urlencode($rating)
                . '&default=' . urlencode($default)
                . '&size=' . $size;
        }

        return $default;
    }

    public function getGravatar(int $size = 80, string $rating = 'G', ?string $default = null): string
    {
        if (!$this->fu->getOpt('gravatar_on')) {
            return '';
        }

        $grav_url = $this->getGravatarUrl($size, $rating, $default);
        $name     = htmlspecialchars((string)$this->params['name'], ENT_QUOTES, 'UTF-8');

        return '<img class="gravatar" src="' . htmlspecialchars($grav_url, ENT_QUOTES, 'UTF-8')
             . '" alt="' . $name . '&#8217;s gravatar"'
             . ' width="' . $size . '" height="' . $size . '" />';
    }

    public function printComment(): void
    {
        $gravatar = $this->getGravatar(
            (int)$this->fu->getOpt('gravatar_size'),
            (string)$this->fu->getOpt('gravatar_rating')
        );

        $text = str_replace('{{gravatar}}', $gravatar,                 (string)$this->fu->getOpt('comment_template'));
        $text = str_replace('{{id}}',       (string)$this->getID(),    $text);
        $text = str_replace('{{name}}',     $this->getCommenterLink(), $text);
        $text = str_replace('{{date}}',     $this->getDateFormatted(), $text);
        $text = str_replace('{{body}}',     $this->getBodyFormatted(), $text);

        echo $text . "\n";
    }
}
