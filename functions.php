<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Shared utility functions: input sanitization/validation, text parsing and
 * formatting (WordPress-derived helpers), and a modified subset of PHP
 * Markdown.
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
require_once(__DIR__ . '/class/Service/CollectiveScriptNormalizer.php');
require_once(__DIR__ . '/class/FanUpdate.php');
require_once(__DIR__ . '/class/FanUpdate_Post.php');
require_once(__DIR__ . '/class/FanUpdate_Comment.php');
require_once(__DIR__ . '/class/Repository/CategoryRepositoryInterface.php');
require_once(__DIR__ . '/class/Repository/CategoryRepository.php');
require_once(__DIR__ . '/class/Repository/UpgradeRepositoryInterface.php');
require_once(__DIR__ . '/class/Repository/UpgradeRepository.php');
require_once(__DIR__ . '/class/Service/SchemaMigrator.php');
require_once(__DIR__ . '/class/Service/UpgradeRunner.php');
require_once(__DIR__ . '/class/Service/LogService.php');
require_once(__DIR__ . '/class/Service/ErrorLogService.php');

// _____________________________________________ BOOLEAN (Y/N) OPTIONS

/** Option keys rendered as on/off toggles; shared by admin/options.php and install.php. */
const FU_YN_OPTION_KEYS = [
    'comments_on',
    'email_new_comments',
    'comment_moderation',
    'captcha_on',
    'gravatar_on',
    'points_scoring',
    'ajax_comments',
    'log_anonymize_ips',
];

/** Whether $optkey renders as a toggle; also honours the legacy "y or n" description convention. */
function fu_is_yn_option(string $optkey, string $desc): bool
{
    return in_array($optkey, FU_YN_OPTION_KEYS, true) || stripos($desc, 'y or n') !== false;
}

/** Display label for an option key, e.g. 'comments_on' -> 'Comments on'. */
function fu_option_label(string $key): string
{
    return ucfirst(str_replace('_', ' ', $key));
}

// _____________________________________________ TIMEZONE OPTION
// Stores an IANA identifier (not a fixed offset) so daylight saving is handled.

/** The option key rendered as a timezone dropdown. */
const FU_TIMEZONE_OPTION_KEY = 'timezone';

/** Fallback used whenever no timezone is configured, or the stored value is invalid. */
const FU_DEFAULT_TIMEZONE = 'UTC';

/**
 * IANA identifiers grouped by region for <optgroup> rendering; those with
 * no '/' (e.g. UTC) go under 'Other'.
 *
 * @return array<string, array<int, string>>
 */
function fu_timezone_identifiers_grouped(): array
{
    $groups = [];

    foreach (DateTimeZone::listIdentifiers() as $tz) {
        $region = str_contains($tz, '/') ? substr($tz, 0, (int) strpos($tz, '/')) : 'Other';
        $groups[$region][] = $tz;
    }

    ksort($groups);

    return $groups;
}

/** Display label such as "Europe/Helsinki (UTC+02:00)", using the current DST offset. Never stored. */
function fu_timezone_label(string $tz): string
{
    try {
        $offsetSeconds = (new DateTimeZone($tz))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
    } catch (Exception) {
        return $tz;
    }

    $sign   = $offsetSeconds < 0 ? '-' : '+';
    $abs    = abs($offsetSeconds);
    $hours  = intdiv($abs, 3600);
    $mins   = intdiv($abs % 3600, 60);
    $offset = sprintf('%s%02d:%02d', $sign, $hours, $mins);

    return $tz . ' (UTC' . $offset . ')';
}

/** Whether $tz is a real IANA timezone identifier PHP's DateTimeZone accepts. */
function fu_is_valid_timezone(string $tz): bool
{
    return in_array($tz, DateTimeZone::listIdentifiers(), true);
}

/** $tz when it is a valid IANA identifier, otherwise FU_DEFAULT_TIMEZONE. */
function fu_resolve_timezone(mixed $tz): string
{
    return is_string($tz) && fu_is_valid_timezone($tz) ? $tz : FU_DEFAULT_TIMEZONE;
}

/** Formats a stored UTC datetime string in $tz; returns '' when it cannot be parsed. */
function fu_format_utc_in_timezone(string $utcDatetime, string $tz, string $format = 'Y-m-d H:i:s'): string
{
    try {
        $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    } catch (Exception) {
        return '';
    }

    return $dt->setTimezone(new DateTimeZone(fu_resolve_timezone($tz)))->format($format);
}

/**
 * Representative IANA zone per legacy whole-hour 'timezone_offset', for the
 * one-time migration only. A fixed offset is ambiguous, so this is a guess.
 *
 * @var array<int, string>
 */
const FU_LEGACY_OFFSET_TIMEZONE_MAP = [
    -12 => 'Etc/GMT+12',
    -11 => 'Pacific/Midway',
    -10 => 'Pacific/Honolulu',
    -9  => 'America/Anchorage',
    -8  => 'America/Los_Angeles',
    -7  => 'America/Denver',
    -6  => 'America/Chicago',
    -5  => 'America/New_York',
    -4  => 'America/Halifax',
    -3  => 'America/Sao_Paulo',
    -2  => 'Atlantic/South_Georgia',
    -1  => 'Atlantic/Azores',
    0   => 'UTC',
    1   => 'Europe/Berlin',
    2   => 'Europe/Helsinki',
    3   => 'Europe/Moscow',
    4   => 'Asia/Dubai',
    5   => 'Asia/Karachi',
    6   => 'Asia/Dhaka',
    7   => 'Asia/Bangkok',
    8   => 'Asia/Shanghai',
    9   => 'Asia/Tokyo',
    10  => 'Australia/Sydney',
    11  => 'Pacific/Noumea',
    12  => 'Pacific/Auckland',
    13  => 'Pacific/Tongatapu',
    14  => 'Pacific/Kiritimati',
];

/** Maps a legacy hour offset (possibly fractional, rounded) to an IANA zone, else FU_DEFAULT_TIMEZONE. */
function fu_migrate_legacy_timezone_offset(float $offsetHours): string
{
    $rounded = (int) round($offsetHours);

    return FU_LEGACY_OFFSET_TIMEZONE_MAP[$rounded] ?? FU_DEFAULT_TIMEZONE;
}

// _____________________________________________ DATE FORMAT OPTION
// Rendered as a preset <select> plus a "Custom" free-text fallback, since any
// string is a valid date() format.

/** The option key that renders as a date-format preset dropdown instead of a plain text field. */
const FU_DATE_FORMAT_OPTION_KEY = 'date_format';

/** Sentinel <select> value that unlocks the free-text "Custom" input. */
const FU_DATE_FORMAT_CUSTOM_VALUE = '__custom__';

/**
 * Common date() formats for the dropdown; anything else works via "Custom".
 *
 * @return array<int, string>
 */
function fu_date_format_presets(): array
{
    return [
        'M j, Y',
        'F j, Y',
        'l, F j, Y',
        'Y-m-d',
        'm/d/Y',
        'd/m/Y',
        'd.m.Y',
        'j F Y',
        'M j, Y g:i A',
        'Y-m-d H:i',
    ];
}

/** Preview of $format applied to now, e.g. 'M j, Y' -> 'Aug 13, 2026'. */
function fu_date_format_example(string $format): string
{
    return (new DateTime())->format($format);
}

/** Dropdown option label combining the raw format string with a live example. */
function fu_date_format_option_label(string $format): string
{
    return $format . ' (' . fu_date_format_example($format) . ')';
}

/** Whether $format is one of the curated presets fu_date_format_presets() returns. */
function fu_is_known_date_format_preset(string $format): bool
{
    return in_array($format, fu_date_format_presets(), true);
}

// _____________________________________________ ERROR REPORTING

/** Directory for the file-based error log; FU_ERROR_LOG_DIR overrides it (e.g. in tests). */
function fu_error_log_directory(): string
{
    return defined('FU_ERROR_LOG_DIR') ? (string) FU_ERROR_LOG_DIR : __DIR__ . '/data/logs';
}

/** Appends a PHP error to the error log; never throws and never recurses into the handler. */
function fu_log_php_error(string $level, string $message, string $file, int $line): void
{
    static $logging = false;

    if ($logging) {
        return;
    }

    $logging = true;
    try {
        (new ErrorLogService(fu_error_log_directory(), __DIR__))->record($level, $message, $file, $line);
    } catch (\Throwable) {
        // Logging must never break the request being logged.
    } finally {
        $logging = false;
    }
}

function fu_php_error_level(int $errno): string
{
    return match ($errno) {
        E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
        E_WARNING, E_USER_WARNING => 'warning',
        E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
        default => 'notice',
    };
}

function myErrorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
{
    // PHP 8+ makes @ narrow error_reporting() to fatal-class errors rather
    // than zeroing it, so a `=== 0` test no longer detects suppression.
    if (!(error_reporting() & $errno)) {
        return false;
    }

    // "Table doesn't exist" warnings are expected during install/version detection.
    $expectedMissingTable = str_contains($errstr, "doesn't exist") || str_contains($errstr, 'does not exist');
    if (!$expectedMissingTable) {
        fu_log_php_error(fu_php_error_level($errno), $errstr, $errfile, $errline);
    }

    switch ($errno) {
        case E_USER_ERROR:
            echo '<p class="error"><strong>ERROR:</strong> ' . $errstr . "</p>\n";
            exit(1);

        case E_WARNING:
        case E_USER_WARNING:
            // Must not render above the page header on a fresh install.
            if (str_contains($errstr, "doesn't exist") || str_contains($errstr, "does not exist")) {
                return true;
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

/**
 * Logs an uncaught exception, then re-throws it so PHP's own handling
 * (display_errors / host error log) is unchanged.
 */
function fu_uncaught_exception_handler(\Throwable $e): void
{
    fu_log_php_error('exception', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());

    restore_exception_handler();
    throw $e;
}

set_exception_handler('fu_uncaught_exception_handler');


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


// _____________________________________________ SECURITY

/**
 * Whether install.php is still under $installPath. Its self-deletion can
 * fail silently, and a leftover copy is a security risk, so the admin
 * notices use this to keep warning until it is gone.
 */
function fu_install_script_exists(string $installPath): bool
{
    $installPath = rtrim($installPath, '/');

    if ($installPath === '') {
        return false;
    }

    return is_file($installPath . '/install.php');
}

/**
 * Builds the SchemaMigrator + UpgradeRunner used to check for and run
 * pending migrations, so every caller constructs them identically. Legacy
 * values come from the raw blog-config.php globals ($fanupdate/$coltable)
 * to keep UpgradeRunner decoupled from the FanUpdate singleton.
 */
function fu_build_upgrade_runner(FanUpdate $fu): UpgradeRunner
{
    global $fanupdate, $coltable;

    $collectiveScriptConfigured = $fu->hasCollectiveScript();
    $standaloneCategoryTableOpt = (string) $fu->getOpt('standalone_category_table');
    $mediaTableOpt              = (string) $fu->getOpt('media_table');
    $logTableOpt                = (string) $fu->getOpt('log_table');

    $schemaMigrator = new SchemaMigrator(
        $fu->db,
        (string) $fu->getOpt('blog_table'),
        (string) $fu->getOpt('comments_table'),
        (string) $fu->getOpt('collective_table'),
        (string) $fu->getOpt('catjoin_table'),
        (string) $fu->getOpt('options_table'),
        (string) $fu->getOpt('blacklist_table'),
        (string) $fu->getOpt('catoptions_table'),
        (string) $fu->getOpt('smilies_table'),
        (string) $fu->getOpt('col_subj'),
        $collectiveScriptConfigured,
        $standaloneCategoryTableOpt !== '' ? $standaloneCategoryTableOpt : null,
        $mediaTableOpt !== '' ? $mediaTableOpt : null,
        $logTableOpt !== '' ? $logTableOpt : null
    );

    $legacyCollectiveScript = (string) ($fanupdate['collective_script'] ?? 'n');
    if ($legacyCollectiveScript === '') {
        $legacyCollectiveScript = 'n';
    }
    $legacyColId   = (string) ($coltable[$legacyCollectiveScript]['id']      ?? '');
    $legacyColSubj = (string) ($coltable[$legacyCollectiveScript]['subject'] ?? '');
    $legacyCollectiveTable = (string) ($fanupdate['collective_table'] ?? '');

    return new UpgradeRunner(
        new UpgradeRepository($fu->db, (string) $fu->getOpt('options_table')),
        $schemaMigrator,
        $legacyCollectiveScript,
        $legacyColId !== '' ? $legacyColId : null,
        $legacyColSubj !== '' ? $legacyColSubj : null,
        $legacyCollectiveTable !== '' ? $legacyCollectiveTable : null
    );
}

/**
 * Whether a table exists, accepting a schema-qualified "other_db.table"
 * reference (SHOW TABLES only sees the current database, so that form uses
 * information_schema). The DB user needs privileges on the other schema.
 */
function fu_table_exists(SqlConnection $db, string $tableRef): bool
{
    if (!str_contains($tableRef, '.')) {
        return (bool) $db->GetFirstCell("SHOW TABLES LIKE '" . $tableRef . "'");
    }

    [$schema, $table] = explode('.', $tableRef, 2);

    return $db->fetchColumn(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
        [$schema, $table]
    ) !== false;
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
// Not dead code: wpautop() above calls doHeaders()/doLists() directly.

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
