<?php

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+:
 *  - Curly-brace string offsets ($str{0}) replaced with square-bracket ($str[0])
 *  - utf8_encode() / utf8_decode() removed (deprecated in 8.2);
 *    use mb_convert_encoding() where encoding conversion is needed
 *  - Strict type hints added to all functions
 *  - Return types declared throughout
 *  - PHP 8.0 removed: get_magic_quotes_gpc() — removed from clean_input
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/
require_once(__DIR__ . '/version.php');
require_once(__DIR__ . '/class/LoginRateLimiter.php');
require_once(__DIR__ . '/class/CommentRateLimiter.php');
require_once(__DIR__ . '/class/SqlConnection.php');
require_once(__DIR__ . '/class/FanUpdate.php');
require_once(__DIR__ . '/class/FanUpdate_Post.php');
require_once(__DIR__ . '/class/FanUpdate_Comment.php');


// _____________________________________________ ERROR REPORTING

function myErrorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
{
    if (error_reporting() === 0) {
        return false;
    }

    switch ($errno) {
        case E_USER_ERROR:
            echo '<p class="error"><strong>ERROR:</strong> ' . $errstr . "</p>\n";
            exit(1);

        case E_WARNING:
        case E_USER_WARNING:
            // Suppress "table doesn't exist" warnings silently during install/version-detection.
            // These are expected on a fresh install and must not appear above the page header.
            if (str_contains($errstr, "doesn't exist") || str_contains($errstr, "does not exist")) {
                return true; // swallow silently
            }
            echo '<p class="error"><strong>WARNING:</strong> ' . $errstr . '</p>' . "\n";
            break;

        case E_NOTICE:
        case E_USER_NOTICE:
            // Suppressed by default
            break;
    }

    return true; // suppress PHP internal handler
}

set_error_handler('myErrorHandler');


// _____________________________________________ VALIDATION

function clean_input(mixed $str, bool $allowtags = false): mixed
{
    if (is_array($str)) {
        array_walk($str, 'clean_walk', $allowtags);
        return $str;
    }

    $str = stripslashes((string) $str);

    if (!$allowtags) {
        $str = strip_tags($str);
    }

    return trim($str);
}

function clean_walk(mixed &$item, mixed $key, bool $allowtags): void
{
    $item = clean_input($item, $allowtags);
}

function is_email(string $email): bool
{
    return (bool) preg_match('!^([_a-z0-9-]+)(\.[_a-z0-9-]+)*@([a-z0-9-]+)(\.[a-z0-9-]+)*(\.[a-z]{2,4})$!i', $email);
}

function is_url(string $url): bool
{
    return (bool) preg_match('!^[\w]+?://[\w\#$%&~/.\-;:=,?@\[\]+]+$!', $url);
}


// _____________________________________________ TEXT PARSING

function truncate_wc(string $phrase, int $max_words): string
{
    $phrase_array = preg_split('/[\n ]/', $phrase);
    if (is_array($phrase_array) && count($phrase_array) > $max_words && $max_words > 0) {
        $phrase = implode(' ', array_slice($phrase_array, 0, $max_words)) . '&#8230;';
    }
    return $phrase;
}

function fancyamp(string $text): string
{
    return str_replace(
        [' & ', ' &amp; '],
        [' &amp; ', ' <span class="amp">&amp;</span> '],
        $text
    );
}

// ── WordPress-derived text functions (modified) ─────────────────────────────

function zeroise(int $number, int $threshold): string
{
    return sprintf('%0' . $threshold . 's', $number);
}

function antispambot(string $emailaddy, int $mailto = 0): string
{
    $emailNOSPAMaddy = '';
    for ($i = 0, $iMax = strlen($emailaddy); $i < $iMax; ++$i) {
        $j = random_int(0, 1 + $mailto); // random_int replaces the deprecated srand/rand pattern
        if ($j === 0) {
            $emailNOSPAMaddy .= '&#' . ord($emailaddy[$i]) . ';';
        } elseif ($j === 1) {
            $emailNOSPAMaddy .= $emailaddy[$i];
        } else {
            $emailNOSPAMaddy .= '%' . zeroise((int) dechex(ord($emailaddy[$i])), 2);
        }
    }
    return str_replace('@', '&#64;', $emailNOSPAMaddy);
}

function make_clickable(string $ret): string
{
    $ret = ' ' . $ret;

    $ret = preg_replace(
        [
            '#([\s>])([\w]+?://[\w\#$%&~/.\-;:=,?@\[\]+]+)#is',
            '#([\s>])((www|ftp)\.[\w\#$%&~/.\-;:=,?@\[\]+]+)#is',
        ],
        [
            '$1<a href="$2">$2</a>',
            '$1<a href="http://$2">$2</a>',
        ],
        $ret
    );

    $ret = preg_replace_callback(
        '!([\s>])([a-z0-9\-_.]+)(@)([a-zA-Z0-9\-.]+\.[a-z]{2,4})!i',
        static function (array $matches): string {
            $antispammed = antispambot($matches[2] . '@' . $matches[4]);
            return $matches[1] . '<a href="mailto:' . $antispammed . '">' . $antispammed . '</a>';
        },
        $ret
    );

    $ret = preg_replace_callback(
        '!(mailto:)([a-z0-9\-_.]+)(@)([a-z0-9\-.]+\.[a-z]{2,4})!i',
        static function (array $matches): string {
            return 'mailto:' . antispambot($matches[2] . '@' . $matches[4]);
        },
        $ret
    );

    // Clean up accidental links-within-links
    $ret = preg_replace('#(<a( [^>]+?>|>))(<a [^>]+?>)([^>]+?)</a></a>#i', '$3$4</a>', $ret);
    return trim($ret);
}

function wptexturize(string $text): string
{
    $next    = true;
    $output  = '';
    $textarr = preg_split('/(<.*>)/Us', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    $cockney = [
        "'tain't",
        "'twere",
        "'twas",
        "'tis",
        "'twill",
        "'til",
        "'bout",
        "'nuff",
        "'round",
        "'cause",
    ];
    $cockneyreplace = [
        '&#8217;tain&#8217;t',
        '&#8217;twere',
        '&#8217;twas',
        '&#8217;tis',
        '&#8217;twill',
        '&#8217;til',
        '&#8217;bout',
        '&#8217;nuff',
        '&#8217;round',
        '&#8217;cause',
    ];

    $static_characters = array_merge(
        ['---', ' -- ', ' - ', '--', 'xn&#8211;', '...', '``', "'s", "''", ' (tm)'],
        $cockney
    );
    $static_replacements = array_merge(
        ['&#8212;', '&#8212;', '&#8212;', '&#8212;', 'xn--', '&#8230;', '&#8220;', '&#8217;s', '&#8221;', ' &#8482;'],
        $cockneyreplace
    );

    $dynamic_characters = [
        '/\'(\d\d(?:&#8217;|\')?s)/',
        '/(\s|\A|")\'/',
        '/(\d+)"/',
        "/(\d+)'/",
        '/(\S)\'([^\'\s])/',
        '/(\s|\A)"(?!\s)/',
        '/"(\s|\S|\Z)/',
        '/\'([\s.]|\Z)/',
        '/(\d+)x(\d+)/',
    ];
    $dynamic_replacements = [
        '&#8217;$1',
        '$1&#8216;',
        '$1&#8243;',
        '$1&#8242;',
        '$1&#8217;$2',
        '$1&#8220;$2',
        '&#8221;$1',
        '&#8217;$1',
        '$1&#215;$2',
    ];

    foreach ($textarr as $curl) {
        // PHP 8.2: use $curl[0] instead of deprecated $curl{0}
        if (isset($curl[0]) && $curl[0] !== '<' && $next) {
            $curl = str_replace($static_characters, $static_replacements, $curl);
            $curl = preg_replace($dynamic_characters, $dynamic_replacements, $curl);
        } elseif (
            str_contains($curl, '<code')  || str_contains($curl, '<pre') ||
            str_contains($curl, '<kbd')   || str_contains($curl, '<style') ||
            str_contains($curl, '<script')
        ) {
            $next = false;
        } else {
            $next = true;
        }

        $curl   = preg_replace('/&([^#])(?![a-zA-Z1-4]{1,8};)/', '&amp;$1', $curl);
        $output .= $curl;
    }

    $output = str_replace('&#8212;>', '-->', $output); // fix HTML comments

    $output = preg_replace_callback(
        '!(<h[1-6][^>]*>)(.*)?(&)(.*)?(</h[1-6]>)!',
        static function (array $matches): string {
            return stripslashes(
                $matches[1]
                    . fancyamp($matches[2] . $matches[3] . $matches[4])
                    . $matches[5]
            );
        },
        $output
    );

    $output = preg_replace('!([0-9]+)(st|nd|rd|th)!', '$1<sup>$2</sup>', $output);

    return $output;
}

function clean_pre(array|string $matches): string
{
    if (is_array($matches)) {
        $text = $matches[1] . $matches[2] . '</pre>';
    } else {
        $text = $matches;
    }

    $text = str_replace('<br />', '', $text);
    $text = str_replace(['<p>', '</p>'], ["\n", ''], $text);

    return $text;
}

function wpautop(string $pee, bool $br = true, bool $allowHeading = false): string
{
    $pee .= "\n";
    $pee = preg_replace('|<br />\s*<br />|', "\n\n", $pee);
    $pee = str_replace("\r\n", "\n", $pee);

    if ($allowHeading) {
        $pee = doHeaders($pee);
    }

    $pee = doLists($pee);

    $allblocks = '(?:table|thead|tfoot|caption|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|select|form|map|area|blockquote|address|math|style|script|input|p|h[1-6]|hr)';

    $pee = preg_replace('!(<' . $allblocks . '[^>]*>)!', "\n$1", $pee);
    $pee = preg_replace('!(</' . $allblocks . '>)!', "$1\n\n", $pee);
    $pee = str_replace(["\r\n", "\r"], "\n", $pee);
    $pee = preg_replace("/\n\n+/", "\n\n", $pee);
    $pee = preg_replace('/\n?(.+?)(?:\n\s*\n|\z)/s', "<p>$1</p>\n", $pee);
    $pee = preg_replace('|<p>\s*?</p>|', '', $pee);
    $pee = preg_replace('!<p>([^<]+)\s*?(</(?:div|address|form)[^>]*>)!', '<p>$1</p>$2', $pee);
    $pee = preg_replace('|<p>|', '$1<p>', $pee);
    $pee = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)\s*</p>!', '$1', $pee);
    $pee = preg_replace('|<p>(<li.+?)</p>|', '$1', $pee);
    $pee = preg_replace('|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $pee);
    $pee = str_replace('</blockquote></p>', '</p></blockquote>', $pee);
    $pee = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)!', '$1', $pee);
    $pee = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*</p>!', '$1', $pee);

    if ($br) {
        $pee = preg_replace_callback(
            '/<(script|style).*?<\/\\1>/s',
            static function (array $m): string {
                return str_replace("\n", '<WPPreserveNewline />', $m[0]);
            },
            $pee
        );
        $pee = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $pee);
        $pee = str_replace('<WPPreserveNewline />', "\n", $pee);
    }

    $pee = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*<br />!', '$1', $pee);
    $pee = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $pee);

    if (str_contains($pee, '<pre')) {
        $pee = preg_replace_callback('!(<pre.*?>)(.*?)</pre>!is', 'clean_pre', $pee);
    }

    $pee = preg_replace("|\n</p>$|", '</p>', $pee);

    return make_clickable(wptexturize($pee));
}


// ── PHP Markdown (modified subset) ──────────────────────────────────────────
// Copyright (c) 2004-2007 Michel Fortin, based on Markdown by John Gruber.
// BSD licence — see original file header for full terms.

$tab_width  = 4;
$list_level = 0;

function outdent(string $text): string
{
    global $tab_width;
    return preg_replace('/^(\t|[ ]{1,' . $tab_width . '})/m', '', $text);
}

function doHeaders(string $text): string
{
    // Setext-style
    $text = preg_replace_callback(
        '{ ^(.+?)[ ]*\n(=+|-+)[ ]*\n+ }mx',
        '_doHeaders_callback_setext',
        $text
    );
    // atx-style
    $text = preg_replace_callback(
        '{
            ^(\#{1,6})  # $1 = string of #\'s
            [ ]*
            (.+?)       # $2 = header text
            [ ]*
            \#*         # optional closing #\'s
            \n+
        }xm',
        '_doHeaders_callback_atx',
        $text
    );
    return $text;
}

function _doHeaders_callback_setext(array $matches): string
{
    // PHP 8.2: use $matches[2][0] instead of deprecated $matches[2]{0}
    $level = $matches[2][0] === '=' ? 3 : 4;
    return "\n<h{$level}>" . $matches[1] . "</h{$level}>\n\n";
}

function _doHeaders_callback_atx(array $matches): string
{
    $level = strlen($matches[1]);
    return "\n<h{$level}>" . $matches[2] . "</h{$level}>\n\n";
}

function doLists(string $text): string
{
    global $tab_width, $list_level;

    $less_than_tab = $tab_width - 1;
    $marker_ul     = '[*+-]';
    $marker_ol     = '\d+[.]';
    $marker_any    = "(?:{$marker_ul}|{$marker_ol})";

    foreach ([$marker_ul, $marker_ol] as $marker) {
        $whole_list = '
            (                               # $1 = whole list
              (                             # $2
                [ ]{0,' . $less_than_tab . '}
                (' . $marker . ')           # $3 = first list item marker
                [ ]+
              )
              (?s:.+?)
              (                             # $4
                  \z
                |
                  \n{2,}
                  (?=\S)
                  (?!
                    [ ]*
                    ' . $marker . ' [ ]+
                  )
              )
            )
        ';

        if ($list_level) {
            $text = preg_replace_callback(
                '{
                    ^
                    ' . $whole_list . '
                }xm',
                '_processListItems',
                $text
            );
        } else {
            $text = preg_replace_callback(
                '{
                    (?:(?<=\n\n)|\A\n?)
                    ' . $whole_list . '
                }x',
                '_processListItems',
                $text
            );
        }
    }

    return $text;
}

function _processListItems(array $matches): string
{
    global $list_level;

    $list_str = $matches[1];
    $marker   = isset($matches[3]) ? $matches[3] : '';

    $tag = preg_match('/^\d+[.]/', $marker) ? 'ol' : 'ul';

    $list_level++;
    $list_str = preg_replace("/\n{2,}\\z/", "\n", $list_str);
    $list_str = preg_replace_callback(
        '{
            (\n)?                           # leading line = $1
            (^[ ]*)                         # leading whitespace = $2
            (?:[*+\-]|\d+[.]) [ ]+          # list marker
            ((?s:.+?))                      # list item text = $3
            (?:(\n+(?=\n))|\n)              # trailing blank line = $4
            (?= \n* (\z | \2 (?:[*+\-]|\d+[.]) [ ]+))
        }xm',
        '_processListItems_callback',
        $list_str
    );
    $list_level--;

    return "<{$tag}>\n" . $list_str . "</{$tag}>\n";
}

function _processListItems_callback(array $matches): string
{
    $item               = $matches[3];
    $leading_line       = $matches[1];
    $tailing_blank_line = $matches[4] ?? '';

    if ($leading_line || $tailing_blank_line || preg_match('/\n{2,}/', $item)) {
        $item = wpautop(outdent($item) . "\n");
    } else {
        $item = doLists(outdent($item));
        $item = preg_replace('/\n+$/', '', $item);
    }

    return '<li>' . $item . "</li>\n";
}
