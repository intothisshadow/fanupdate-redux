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
const FU_VERSION      = 'Redux 2.3.0';

/** Database schema version — update only when install.php adds/alters tables. */
const FU_DB_VERSION   = '2.2.1b2';

/** ISO 8601 release date. */
const FU_RELEASE_DATE = '2026-07-27';

/** Short release codename (shown on the Dashboard and Credits page). */
const FU_RELEASE_NAME = '';

// ── project URLs (rarely change) ─────────────────────────────────────────────

/** URL of the original FanUpdate project page by Jenny Ferenc. */
const FU_URL_ORIGINAL = 'http://prism-perfect.net/fanupdate';

/** URL of the Robotess Fork (Ekaterina's PHP 7 port). */
const FU_URL_ROBOTESS = 'https://scripts.robotess.net';

/**
 * URL of this Redux release.
 * Update this to your repo, release page, or distribution site.
 */
const FU_URL_REDUX = 'https://www.unloved-heart.net';

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
        'contribution' => 'Full PHP 8.2+ compatibility pass: explicit property '
            . 'declarations, strict return types, utf8_encode() removal, '
            . 'bcrypt password storage, HMAC session tokens, '
            . 'account management, version system, credits page, '
            . 'and GPLv3 licence upgrade.',
    ],
];

// ── third-party library credits ──────────────────────────────────────────────
// Used by credits.php. Source: original readme.txt by Jenny Ferenc.

const FU_LIBRARIES = [
    [
        'name'    => 'Standardista Table Sorting',
        'author'  => 'Neil Crosby',
        'years'   => '2006',
        'url'     => 'http://www.workingwith.me.uk/articles/scripting/standardista_table_sorting',
        'used_in' => 'js/standardista-table-sorting.js — sortable admin tables',
    ],
    [
        'name'    => 'Silk icon set',
        'author'  => 'Mark James',
        'years'   => '2006',
        'url'     => 'http://famfamfam.com/lab/icons/silk/',
        'used_in' => 'img/ and css/ — admin panel icons',
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
        'used_in' => 'functions.php — Markdown parsing in post bodies',
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
