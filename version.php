<?php

declare(strict_types=1);
/*****************************************************************************
 * FanUpdate — version.php
 *
 * Single source of truth for all version and release metadata.
 *
 * HOW TO UPDATE FOR A NEW RELEASE:
 *   1. Change FU_VERSION         — the human-readable version shown in the UI
 *   2. Change FU_DB_VERSION      — only if the database schema changed;
 *                                   must match a version string in install.php's
 *                                   $setting array so the installer knows what
 *                                   to run; leave unchanged for non-DB releases
 *   3. Change FU_RELEASE_DATE    — ISO 8601 date of this release (YYYY-MM-DD)
 *   4. Change FU_RELEASE_NAME    — short codename shown in the admin panel
 *   That's it. Nothing else needs editing across the codebase.
 *
 * GNU General Public License v3 or later.
 * See <https://www.gnu.org/licenses/gpl-3.0.html> for details.
 ******************************************************************************/

// ── release identity ─────────────────────────────────────────────────────────

/** Human-readable version string displayed in the UI, footer, and RSS feeds. */
const FU_VERSION      = 'Redux 2.5.0';

/** Database schema version — update only when install.php adds/alters tables. */
const FU_DB_VERSION   = '2.2.1b2';

/** ISO 8601 release date. */
const FU_RELEASE_DATE = '2026-08-14';

/** Short release codename (shown on the Dashboard and Credits page). */
const FU_RELEASE_NAME = '';

// ── project URLs (rarely change) ─────────────────────────────────────────────

/** URL of the Robotess Fork (Ekaterina's PHP 7 port). */
const FU_URL_ROBOTESS = 'https://scripts.robotess.net';

/**
 * URL of this Redux release.
 * Update this to your repo, release page, or distribution site.
 */
const FU_URL_REDUX = 'https://coding.unloved-heart.net/scripts/fanupdate-redux';

// ── licence ──────────────────────────────────────────────────────────────────

const FU_LICENCE     = 'GNU General Public License v3 or later';
const FU_LICENCE_URL = 'https://www.gnu.org/licenses/gpl-3.0.html';

// ── update provider (Admin Update Manager) ──────────────────────────────────
// Fixed, not user-editable — only the release channel and an optional
// GitHub token are configurable from admin/update.php. See
// class/Update/GitHubUpdateProvider.php.

const FU_UPDATE_PROVIDER_OWNER = 'intothisshadow';
const FU_UPDATE_PROVIDER_REPO  = 'fanupdate-redux';

// ── install ping (Opt-In Anonymous Install Ping — see admin/privacy.php,
// class/Service/InstallPingService.php) ─────────────────────────────────────
// Deliberately separate from the update provider above — the install ping
// must remain completely independent of update checking (own endpoint, own
// request code, functions regardless of whether update checks are enabled).

const FU_INSTALL_PING_URL = 'https://coding.unloved-heart.net/fanupdate-redux/install-tracking-server/ping.php';

// ── contributor roster ───────────────────────────────────────────────────────
// Used by credits.php. Add entries here when a new contributor is credited.

const FU_CONTRIBUTORS = [
    [
        'name'         => 'Jenny Ferenc',
        'role'         => 'Original author',
        'years'        => '2005–2008',
        'url'          => 'http://prism-perfect.net/',
        'email'        => 'jenny@prism-perfect.net',
        'contribution' => 'Created FanUpdate from scratch. Wrote the core engine, '
            . 'admin panel, comment system, RSS feeds, CAPTCHA, smilies, '
            . 'blacklist, and collective script integration.',
    ],
    [
        'name'         => 'Ekaterina',
        'role'         => 'PHP 7 port (Robotess Fork)',
        'years'        => '2020',
        'url'          => 'https://scripts.robotess.net',
        'email'        => null,
        'contribution' => 'Ported the codebase to PHP 7.2+. Introduced the class/'
            . ' directory structure, OOP mysqli, and the Robotess Fork '
            . 'release series. Maintained the GitLab repository.',
    ],
    [
        'name'         => 'Ariane',
        'role'         => 'PHP 8.2+ refactor (Redux)',
        'years'        => '2026',
        'url'          => 'https://www.unloved-heart.net',
        'email'        => null,
        'contribution' => 'Ongoing PHP 8.2+ refactor and feature development: full PHP '
            . '8.2+ compatibility pass (explicit property declarations, strict '
            . 'return types, utf8_encode() removal), the Admin Update Manager, '
            . 'Categories Support, Fanatically collective-script integration, '
            . 'the Media/Image Manager, TinyMCE editor integration, database '
            . 'engine/charset migration (MyISAM to InnoDB, latin1 to utf8mb4), '
            . 'security hardening (bcrypt passwords, HMAC session tokens, '
            . 'CSRF protection, emergency password reset), account '
            . 'management, and the version/credits/GPLv3 licensing system.',
    ],
];

// ── third-party library credits ──────────────────────────────────────────────
// Used by credits.php. Original entries sourced from readme.txt by Jenny
// Ferenc; Redux-era additions (Parsedown, TinyMCE, Tablesort) cross-checked
// against docs/THIRD-PARTY-LICENSES.md, which is the authoritative license
// record for every vendored dependency — keep both in sync when either
// changes. The Silk icon set entry was removed (replaced by Tabler Icons,
// confirmed unused). The Standardista entry is rescoped, not removed —
// its `css` utility object is still genuinely used even though its
// table-sorting code (the actual "Standardista Table Sorting" feature) was
// replaced by Tablesort.
//
// The Michel Fortin "PHP Markdown" entry below was WRONGLY removed on
// 2026-08-13 and has since been restored — it was mistaken for dead code
// during a Credits-page audit that only checked for callers of doHeaders()/
// doLists() outside functions.php, missing that wpautop() itself calls both
// as part of its own pipeline. wpautop() is called by every post/comment
// render (FanUpdate_Post/FanUpdate_Comment), so the "dead code" removal
// briefly made every one of those calls fatal until caught. See
// docs/CHANGELOG.md for the full incident.

const FU_LIBRARIES = [
    [
        'name'    => 'Standardista (css utility helpers)',
        'author'  => 'Neil Crosby',
        'years'   => '2006',
        'url'     => 'http://www.workingwith.me.uk/articles/scripting/standardista_table_sorting',
        'used_in' => 'assets/js/fanupdate.js — the `css` class-manipulation utility object (getElementsByClass/addClassToElement/removeClassFromElement), still used across several admin JS files. The table-sorting code itself has been replaced — see the Tablesort entry below.',
    ],
    [
        'name'    => 'Tablesort',
        'author'  => 'Tristen Brown',
        'years'   => '2013–2024',
        'url'     => 'https://github.com/tristen/tablesort',
        'used_in' => 'assets/vendor/tablesort/tablesort.js — sortable admin tables (Entries, Blacklist, Categories, Smilies)',
    ],
    [
        'name'    => 'Text parsing functions (wpautop, make_clickable, wptexturize)',
        'author'  => 'WordPress',
        'years'   => '2003–2008',
        'url'     => 'https://wordpress.org/',
        'used_in' => 'functions.php — post body formatting',
    ],
    [
        'name'    => 'PHP Markdown',
        'author'  => 'Michel Fortin (based on Markdown by John Gruber)',
        'years'   => '2004–2007',
        'url'     => 'https://michelf.ca/projects/php-markdown/',
        'used_in' => 'functions.php — heading/list Markdown syntax within wpautop(), used by every post/comment body render',
    ],
    [
        'name'    => 'Parsedown',
        'author'  => 'Emanuil Rusev',
        'years'   => '2013–2018',
        'url'     => 'https://parsedown.org',
        'used_in' => 'class/Parsedown.php — GitHub Flavored Markdown rendering for release notes in Admin → Updates',
    ],
    [
        'name'    => 'TinyMCE',
        'author'  => 'Tiny Technologies, Inc.',
        'years'   => '2024',
        'url'     => 'https://www.tiny.cloud/',
        'used_in' => 'assets/vendor/tinymce/ — post body editor in Admin → Entries',
    ],
    [
        'name'    => 'CaptchaSecurityImages',
        'author'  => 'Simon Jarvis',
        'years'   => '2006',
        'url'     => 'http://www.white-hat-web-design.co.uk/articles/php-captcha.php',
        'used_in' => 'captcha.php — comment CAPTCHA image generation',
    ],
    [
        'name'    => 'SqlConnection class',
        'author'  => 'Jay Pipes',
        'years'   => '2004',
        'url'     => 'http://www.jpipes.com/',
        'used_in' => 'class/SqlConnection.php — database abstraction layer',
    ],
    [
        'name'    => 'FeedWriter / FeedItem classes',
        'author'  => 'Anis uddin Ahmad',
        'years'   => '2007',
        'url'     => 'http://www.ajaxray.com/projects/rssheader.php',
        'used_in' => 'class/FeedWriter.php, class/FeedItem.php — RSS feed generation',
    ],
];
