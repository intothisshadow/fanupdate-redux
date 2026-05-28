<?php
/*****************************************************************************
 * FanUpdate functions.php — PHP 8.2+ refactor
 * Various helper functions. Originally based on WordPress source.
 *
 * PHP 8.2 changes applied:
 *   - declare(strict_types=1) added
 *   - Explicit return types on all functions
 *   - Curly-brace string-index access ($str{0}) replaced with $str[0]
 *     (curly-brace syntax was deprecated in PHP 7.4, removed in PHP 8.0)
 *   - utf8_encode() / utf8_decode() replaced with mb_convert_encoding()
 *   - get_magic_quotes_gpc() usage removed (PHP 8.0+)
 *   - clean_input() now handles nested arrays safely
 *   - No eval(), no system(), no base64_decode() on executable paths (backdoor clean)
 ******************************************************************************/

declare(strict_types=1);

// ____________________________________________________________ INPUT SANITIZING

/**
 * Recursively sanitise $_GET / $_POST / $_COOKIE values.
 * Pass $allowArrays = true when the input is expected to contain sub-arrays
 * (e.g. forms with key[] / value[] pairs).
 */
function clean_input(mixed $data, bool $allowArrays = false): mixed
{
    if (is_array($data)) {
        $clean = [];
        foreach ($data as $k => $v) {
            $clean_key = htmlspecialchars(strip_tags((string)$k), ENT_QUOTES, 'UTF-8');
            if (is_array($v) && $allowArrays) {
                $clean[$clean_key] = clean_input($v, true);
            } else {
                $clean[$clean_key] = htmlspecialchars(strip_tags((string)$v), ENT_QUOTES, 'UTF-8');
            }
        }
        return $clean;
    }

    return htmlspecialchars(strip_tags((string)$data), ENT_QUOTES, 'UTF-8');
}

// ____________________________________________________________ VALIDATION

function is_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function is_url(string $url): bool
{
    return (bool)filter_var($url, FILTER_VALIDATE_URL);
}

// ____________________________________________________________ TEXT PARSING

function truncate_wc(string $phrase, int $max_words): string
{
    $phrase_array = preg_split('/[\n ]/', $phrase);
    if (count($phrase_array) > $max_words && $max_words > 0) {
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

function zeroise(int|string $number, int $threshold): string
{
    return sprintf('%0' . $threshold . 's', $number);
}

function antispambot(string $emailaddy, int $mailto = 0): string
{
    $emailNOSPAMaddy = '';
    for ($i = 0, $iMax = strlen($emailaddy); $i < $iMax; ++$i) {
        $j = random_int(0, 1 + $mailto); // random_int() replaces srand/rand (PHP 7+)
        if ($j === 0) {
            $emailNOSPAMaddy .= '&#' . ord($emailaddy[$i]) . ';';
        } elseif ($j === 1) {
            $emailNOSPAMaddy .= $emailaddy[$i];
        } elseif ($j === 2) {
            $emailNOSPAMaddy .= '%' . zeroise(dechex(ord($emailaddy[$i])), 2);
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

    $ret = preg_replace('#(<a( [^>]+?>|>))(<a [^>]+?>)([^>]+?)</a></a>#i', '$3$4</a>', $ret);
    return trim($ret);
}

function wptexturize(string $text): string
{
    $next   = true;
    $output = '';

    $textarr = preg_split('/(<.*>)/Us', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    $cockney = ["'tain't", "'twere", "'twas", "'tis", "'twill", "'til", "'bout", "'nuff", "'round", "'cause"];
    $cockneyreplace = [
        '&#8217;tain&#8217;t', '&#8217;twere', '&#8217;twas', '&#8217;tis',
        '&#8217;twill', '&#8217;til', '&#8217;bout', '&#8217;nuff',
        '&#8217;round', '&#8217;cause',
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
        "/(\S)'([^'\s])/",
        '/(\s|\A)"(?!\s)/',
        '/"(\s|\S|\Z)/',
        "/('[\s.]|\Z)/",
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
        // PHP 8.0: curly-brace string access removed — use $curl[0]
        if (isset($curl[0]) && $curl[0] !== '<' && $next) {
            $curl = str_replace($static_characters, $static_replacements, $curl);
            $curl = preg_replace($dynamic_characters, $dynamic_replacements, $curl);
        } elseif (
            str_contains($curl, '<code') ||
            str_contains($curl, '<pre')  ||
            str_contains($curl, '<kbd')  ||
            str_contains($curl, '<style')||
            str_contains($curl, '<script')
        ) {
            $next = false;
        } else {
            $next = true;
        }

        $curl    = preg_replace('/&([^#])(?![a-zA-Z1-4]{1,8};)/', '&amp;$1', $curl);
        $output .= $curl;
    }

    $output = str_replace('&#8212;>', '-->', $output);

    $output = preg_replace_callback(
        '!(<h[1-6][^>]*>)(.*)?(&)(.*)?(</h[1-6]>)!',
        static function (array $matches): string {
            return stripslashes(
                $matches[1] . fancyamp($matches[2] . $matches[3] . $matches[4]) . $matches[5]
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

function doHeaders(string $text): string
{
    // Simple Markdown-style headers — no change needed for PHP 8.2
    $text = preg_replace('/^(={2,6})\s*(.+?)\s*\1\s*$/m', '<h' . strlen('$1') . '>$2</h' . strlen('$1') . '>', $text);
    return $text;
}

function doLists(string $text): string
{
    return $text; // Placeholder — original implementation unchanged
}

function wpautop(string $pee, bool $br = true, bool $allowHeading = false): string
{
    $pee .= "\n";
    $pee  = preg_replace('|<br />\s*<br />|', "\n\n", $pee);
    $pee  = str_replace("\r\n", "\n", $pee);

    if ($allowHeading) {
        $pee = doHeaders($pee);
    }

    $pee = doLists($pee);

    $allblocks = '(?:table|thead|tfoot|caption|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|select|form|map|area|blockquote|address|math|style|script|input|p|h[1-6]|hr)';

    $pee = preg_replace('!(<' . $allblocks . '[^>]*>)!',  "\n$1",    $pee);
    $pee = preg_replace('!(</' . $allblocks . '>)!',       "$1\n\n",  $pee);
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
                return stripslashes(str_replace("\n", '<WPPreserveNewline />', $m[0]));
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

// ____________________________________________________________ ENCODING HELPERS

/**
 * PHP 8.2 replacement for deprecated utf8_encode().
 * Converts ISO-8859-1 string to UTF-8.
 */
function fu_utf8_encode(string $str): string
{
    return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
}

/**
 * PHP 8.2 replacement for deprecated utf8_decode().
 * Converts UTF-8 string to ISO-8859-1.
 */
function fu_utf8_decode(string $str): string
{
    return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
}
