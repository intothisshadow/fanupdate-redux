<?php
declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — install.php
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Handles fresh installation and version upgrades: creates database tables
 * on fresh install, applies incremental schema/option migrations for
 * upgrades, seeds default blacklist words and smilies, and writes admin
 * credentials to blog-config.php.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

// Hosts often set display_errors=Off, which turns install failures into a blank page.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// index.php and admin/index.php send first-time visitors here, so a missing
// config file must produce guidance rather than a fatal error.
if (!is_file(__DIR__ . '/blog-config.php')) {
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>FanUpdate Redux — Setup</title></head><body>'
        . '<h1>FanUpdate Redux setup</h1>'
        . '<p>Copy <code>blog-config.sample.php</code> to <code>blog-config.php</code>, enter your database '
        . 'details in it, then reload this page.</p></body></html>';
    exit;
}

require_once('blog-config.php');
require_once('functions.php');
// SchemaMigrator needs CategoryRepository::STANDALONE_ID_OFFSET.
require_once('class/Repository/CategoryRepositoryInterface.php');
require_once('class/Repository/CategoryRepository.php');
require_once('class/Service/SchemaMigrator.php');

$fu = FanUpdate::instance();

$clean = array();

$fu->db->Connect();

$version_installed = 0;
$installer_version = FU_DB_VERSION;

// ── detect currently installed version ──────────────────────────────────────

// Queries against missing legacy tables can throw, so detection is wrapped in try/catch.
try {
    $query_cat = 'SELECT * FROM ' . $fu->getOpt('blog_table') . ' WHERE listingid=0 LIMIT 1';
    if ($fu->db->Execute($query_cat, null)) {
        $version_installed = 1.5;
    } else {
        throw new \RuntimeException('blog table not v1.5');
    }
} catch (\Exception $e) {
    try {
        $query_opt = 'SELECT * FROM ' . $fu->getOpt('options_table') . ' WHERE id=1 LIMIT 1';
        $fu->db->Execute($query_opt, null);

        if ($fu->db->NumRows() > 0) {
            $version_installed = 1.6;
            $row_opt = $fu->db->GetRecord();
        } else {
            try {
                $fu->addOptFromDb();
            } catch (\Exception $e2) {
                // options table doesn't exist yet — fresh install
            }

            if (!$version_installed = $fu->getOpt('_db_version')) {
                if ($fu->getOpt('comment_form_template')) {
                    $version_installed = 2.1;
                } elseif ($fu->getOpt('admin_email')) {
                    $version_installed = 2.0;
                }
            }
        }
    } catch (\Exception $e) {
        // Neither legacy table exists — this is a fresh install, $version_installed stays 0
    }
}

// ── render page header (after version detection so DB warnings don't appear above it) ──
$fu->getHeader('Install');
?>

<h2>Install FanUpdate <?php echo htmlspecialchars((string) $fu->getOpt('version')); ?></h2>

<?php

// ── auto-detect paths ────────────────────────────────────────────────────────

$dir = __DIR__;
$dir = preg_replace('#/\.([a-z]+)#', '', $dir);   // fix DreamHost .name quirk
$dir = str_replace('\\', '/', $dir);               // fix Windows slashes

$row_opt['install_path'] = $dir;

$dir = str_replace($_SERVER['DOCUMENT_ROOT'], '', $dir);

// An http:// install_url breaks CAPTCHA sessions and causes mixed content on HTTPS-only hosts.
$detected_proto = 'https';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $detected_proto = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $detected_proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
} elseif (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
    $detected_proto = 'https';
} else {
    $detected_proto = 'http';
}

// The URL where FanUpdate is installed — NO TRAILING SLASH
$row_opt['install_url'] = $detected_proto . '://' . $_SERVER['SERVER_NAME'] . $dir;

// Suggested default only; a v1.6 install's collective_updates_page takes priority.
$row_opt['blog_page'] = $row_opt['install_url'] . '/show-blog.php';

// Admin credentials are options-table rows, never written to blog-config.php.
$current_admin_username = 'admin';

// ── full settings array ──────────────────────────────────────────────────────

$setting[0]['optkey']   = 'admin_email';
$setting[0]['optvalue'] = (!empty($row_opt['admin_email'])) ? $row_opt['admin_email'] : 'me@example.com';
$setting[0]['optdesc']  = 'Your email address, so you can be notified of new comments.';
$setting[0]['version']  = 2.0;

$setting[1]['optkey']   = 'site_name';
$setting[1]['optvalue'] = (!empty($row_opt['collective_name'])) ? $row_opt['collective_name'] : 'My Site';
$setting[1]['optdesc']  = 'The name of your site or blog.';
$setting[1]['version']  = 2.0;

$setting[2]['optkey']   = 'blog_page';
$setting[2]['optvalue'] = (!empty($row_opt['collective_updates_page'])) ? $row_opt['collective_updates_page'] : $row_opt['blog_page'];
$setting[2]['optdesc']  = 'The URL of your blog page (needed for RSS feeds).';
$setting[2]['version']  = 2.0;

$setting[3]['optkey']   = 'install_path';
$setting[3]['optvalue'] = $row_opt['install_path'];
$setting[3]['optdesc']  = 'The full server path to your FanUpdate directory. NO trailing slash. Ex: /home/username/public_html/fanupdate';
$setting[3]['version']  = 2.0;

$setting[4]['optkey']   = 'install_url';
$setting[4]['optvalue'] = $row_opt['install_url'];
$setting[4]['optdesc']  = 'The URL to your FanUpdate directory. NO trailing slash. Ex: https://example.com/fanupdate';
$setting[4]['version']  = 2.0;

$setting[5]['optkey']   = 'comments_on';
$setting[5]['optvalue'] = (!empty($row_opt['comments_on'])) ? $row_opt['comments_on'] : 'y';
$setting[5]['optdesc']  = 'Allow comments?';
$setting[5]['version']  = 2.0;

$setting[6]['optkey']   = 'date_format';
$setting[6]['optvalue'] = 'M j, Y';
$setting[6]['optdesc']  = 'PHP date format string. See options here: http://php.net/date';
$setting[6]['version']  = 2.0;

// Replaces the legacy 'timezone_offset' (migrated by UpgradeRunner). Placed after
// date_format because render order follows insertion order, not array key.
$setting[26]['optkey']   = FU_TIMEZONE_OPTION_KEY;
$setting[26]['optvalue'] = FU_DEFAULT_TIMEZONE;
$setting[26]['optdesc']  = 'Your local IANA timezone (e.g. Europe/Helsinki). Used to display dates in your local time. Default: UTC';
$setting[26]['version']  = '2.2b3';

$setting[7]['optkey']   = 'email_new_comments';
$setting[7]['optvalue'] = 'y';
$setting[7]['optdesc']  = 'Email you about new comments?';
$setting[7]['version']  = 2.0;

$setting[8]['optkey']   = 'comment_moderation';
$setting[8]['optvalue'] = 'n';
$setting[8]['optdesc']  = 'Hold new comments for moderation?';
$setting[8]['version']  = 2.0;

$setting[9]['optkey']   = 'captcha_on';
$setting[9]['optvalue'] = 'n';
$setting[9]['optdesc']  = 'Protect comments with captcha?';
$setting[9]['version']  = 2.0;

$setting[10]['optkey']   = 'gravatar_on';
$setting[10]['optvalue'] = 'y';
$setting[10]['optdesc']  = 'Use Gravatars?';
$setting[10]['version']  = 2.0;

$setting[11]['optkey']   = 'gravatar_default';
$setting[11]['optvalue'] = $row_opt['install_url'] . '/gravatar.png';
$setting[11]['optdesc']  = 'URL of default Gravatar image. Ex: https://example.com/fanupdate/gravatar.png';
$setting[11]['version']  = 2.0;

$setting[12]['optkey']   = 'gravatar_size';
$setting[12]['optvalue'] = 80;
$setting[12]['optdesc']  = 'Gravatar image dimension in pixels (80 max).';
$setting[12]['version']  = 2.0;

$setting[13]['optkey']   = 'gravatar_rating';
$setting[13]['optvalue'] = 'G';
$setting[13]['optdesc']  = 'Highest allowable Gravatar image rating: G, PG, R, X';
$setting[13]['version']  = 2.0;

$setting[14]['optkey']   = 'entry_template';
$setting[14]['optvalue'] = '<div class="fu-entry">
<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>

<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>

{{body}}
</div>';
$setting[14]['optdesc']  = 'See readme.txt for template variables.';
$setting[14]['version']  = 2.0;

$setting[15]['optkey']   = 'comment_template';
$setting[15]['optvalue'] = '{{gravatar}}

<p class="commenter">On <a href="#comment{{id}}" title="permanent link to this comment">{{date}}</a> {{name}} said:</p>

{{body}}';
$setting[15]['optdesc']  = 'See readme.txt for template variables.';
$setting[15]['version']  = 2.0;

$setting[16]['optkey']   = 'num_per_page';
$setting[16]['optvalue'] = '20';
$setting[16]['optdesc']  = 'Number of items displayed per page for pagination.';
$setting[16]['version']  = 2.0;

$setting[17]['optkey']   = 'abstract_word_count';
$setting[17]['optvalue'] = '0';
$setting[17]['optdesc']  = 'The number of words to display for post summaries. 0 to turn off.';
$setting[17]['version']  = 2.0;

$setting[18]['optkey']   = 'comment_form_template';
$setting[18]['optvalue'] = '<h3 id="postcomment">Post A Comment</h3>

<!-- MODERATION -->
<p id="cmt-moderation"><strong>Comment moderation is currently turned on.</strong> Your comment will not be displayed until it has been approved by the site owner.</p>
<!-- END MODERATION -->

<p><label for="name">Name:</label>
<input type="text" id="name" name="name" maxlength="20" size="25" value="{{fanuname}}" />
<label for="remember_me" class="checkbox"><input type="checkbox" id="remember_me" name="remember_me" value="1" checked="checked" /> Remember?</label></p>

<p><label for="email">Email:</label>
<input type="text" id="email" name="email" maxlength="70" size="25" value="{{fanuemail}}" /></p>

<p><label for="url">URL:</label>
<input type="text" id="url" name="url" maxlength="70" size="25" value="{{fanuurl}}" /></p>

<p><label for="myta">Comment:</label>
<textarea id="myta" name="comment" cols="50" rows="8"></textarea></p>

<!-- CAPTCHA -->
<p><label for="captcha">Captcha:</label>
<img id="captcha-img" src="{{captcha_image}}" alt="" />
<input type="text" id="captcha" name="captcha" /></p>
<!-- END CAPTCHA -->

<p><input type="submit" id="submit" name="submit_comment" value="Post Comment" class="submit" /></p>

<p id="cmt-rules">Your email is only for accessing <a href="https://www.gravatar.com/">gravatar.com</a>. No <abbr title="HyperText Markup Language">HTML</abbr> allowed.</p>';
$setting[18]['optdesc']  = 'Customize your comment form. Don\'t change the names of the inputs or the CAPTCHA and MODERATION comments. See readme.txt for template variables.';
$setting[18]['version']  = 2.1;

$setting[19]['optkey']   = 'footer_template';
$setting[19]['optvalue'] = '<div class="archivelink">
<form action="{{main_url}}" method="get">
<p>
<a class="fu-nav-link" href="{{main_url}}">main</a> &middot;
<a class="fu-nav-link" href="{{archive_url}}">archive</a> &middot;
<a class="rss" href="{{rss_url}}">feed</a> &middot;
<input type="text" name="q" value="" />
<input type="submit" value="Search" class="button" />
</p>
</form>
</div><!-- END .archivelink -->

<div class="credit">
<p>Powered by <a href="{{fanupdate_url}}" target="_blank" class="ext fu-credit-link">FanUpdate {{fanupdate_version}}</a></p>
</div><!-- END .credit -->';
$setting[19]['optdesc']  = 'Put your footer blog navigation here. See readme.txt for template variables.';
$setting[19]['version']  = 2.1;

$setting[22]['optkey']   = 'points_scoring';
$setting[22]['optvalue'] = 'y';
$setting[22]['optdesc']  = 'Use point scoring system to block spam.';
$setting[22]['version']  = '2.2b';

$setting[23]['optkey']   = 'points_approval_threshold';
$setting[23]['optvalue'] = 1;
$setting[23]['optdesc']  = 'Minimum points for automatic comment approval. Default: 1';
$setting[23]['version']  = '2.2b';

$setting[24]['optkey']   = 'points_pending_threshold';
$setting[24]['optvalue'] = -4;
$setting[24]['optdesc']  = 'Minimum points for comment to be moderated. Any less points and it is spam. Default: -4';
$setting[24]['version']  = '2.2b';

$setting[25]['optkey']   = '_db_version';
$setting[25]['optvalue'] = $fu->getOpt('version');
$setting[25]['optdesc']  = 'Version of current database schema.';
$setting[25]['version']  = '2.2b';

$setting[28]['optkey']   = 'ajax_comments';
$setting[28]['optvalue'] = 'y';
$setting[28]['optdesc']  = 'Use ajax commenting? Turn off if you have problems.';
$setting[28]['version']  = '2.2.1b2';

// Collective settings are seeded from blog-config.php's legacy globals; installs
// that never re-run install.php get the equivalent migration via UpgradeRunner.
$legacy_collective_script = (string) ($fanupdate['collective_script'] ?? 'n');
if ($legacy_collective_script === '') {
    $legacy_collective_script = 'n';
}

$setting[29]['optkey']   = 'collective_script';
$setting[29]['optvalue'] = (new CollectiveScriptNormalizer())->normalize($legacy_collective_script);
$setting[29]['optdesc']  = 'Which collective/fanlisting script this installation is paired with. Supported: "enthusiast" (Enthusiast), "fanatically" (Fanatically). Use "none" for standalone mode, where FanUpdate manages its own categories table entirely with no external script involved. ("listingadmin" is reserved for future Listing Admin support and is not yet functional.) Editable in Admin -> Options -> Collective Source.';
$setting[29]['version']  = '2.2.1b2';

$setting[30]['optkey']   = 'col_id';
$setting[30]['optvalue'] = (string) ($coltable[$legacy_collective_script]['id'] ?? 'fl_id');
$setting[30]['optdesc']  = 'Collective table primary key column name. Only relevant when collective_script is not "none". Expected values: Enthusiast = listingid, Fanatically = id.';
$setting[30]['version']  = '2.2.1b2';

$setting[31]['optkey']   = 'col_subj';
$setting[31]['optvalue'] = (string) ($coltable[$legacy_collective_script]['subject'] ?? 'fl_subject');
$setting[31]['optdesc']  = 'Collective table subject/name column name. Only relevant when collective_script is not "none". Expected values: Enthusiast = subject, Fanatically = subject.';
$setting[31]['version']  = '2.2.1b2';

// Seeded from the legacy $fanupdate['collective_table']; see UpgradeRunner for installs that skip install.php.
$setting[32]['optkey']   = 'collective_table';
$setting[32]['optvalue'] = (string) ($fanupdate['collective_table'] ?? 'blog_category');
$setting[32]['optdesc']  = 'The name of your collective/categories table. Must match the collective_script currently selected — e.g. Fanatically\'s default is "fanatic_listings" (the fanlistings this collective actually owns — NOT "fanatic_categories", which is Fanatically\'s own site-wide list of every category across the whole directory), Enthusiast\'s is "owned". For a standalone installation (collective_script = "none") this is FanUpdate\'s own categories table. Editable in Admin -> Options -> Collective Source.';
$setting[32]['version']  = '2.2.1b2';

// Dashboard news feed; blank hides the widget. Existing installs get this via
// UpgradeRunner::NEW_DEFAULT_OPTIONS.
$setting[33]['optkey']   = 'news_feed_url';
$setting[33]['optvalue'] = 'https://coding.unloved-heart.net/update-blog/rss.php?c=4';
$setting[33]['optdesc']  = 'RSS feed URL shown on the Dashboard as "FanUpdate Redux News". Leave blank to hide the Dashboard widget entirely.';
$setting[33]['version']  = '2.2.1b2';

// Existing installs get this via UpgradeRunner::NEW_DEFAULT_OPTIONS.
$setting[34]['optkey']   = 'show_collective_categories';
$setting[34]['optvalue'] = 'y';
$setting[34]['optdesc']  = 'Show categories from your collective script (Enthusiast, Fanatically, ...) alongside FanUpdate\'s own in the category list and entry category picker. Turn off to list only FanUpdate\'s own categories. Has no effect when no collective script is configured.';
$setting[34]['version']  = '2.2.1b2';

// Existing installs get these via UpgradeRunner::NEW_DEFAULT_OPTIONS.
$setting[35]['optkey']   = 'log_retention_days';
$setting[35]['optvalue'] = '90';
$setting[35]['optdesc']  = 'Number of days to keep entries in the Admin → Logs activity log. Older entries are removed automatically. Use 0 to keep them forever.';
$setting[35]['version']  = '2.2.1b2';

$setting[36]['optkey']   = 'log_anonymize_ips';
$setting[36]['optvalue'] = 'n';
$setting[36]['optdesc']  = 'Anonymise IP addresses in the activity log (the last part of each address is zeroed before it is stored). Does not affect entries already logged.';
$setting[36]['version']  = '2.2.1b2';

// ── check tables don't already exist (fresh install only) ───────────────────

if ($version_installed == 0) {
    $query = 'SHOW TABLES';
    $fu->db->Execute($query);
    while ($row = $fu->db->ReadRecord()) {
        $table = array_shift($row);
        if (!$fu->hasCollectiveScript() || $table != $fu->getOpt('collective_table')) {
            if (in_array($table, (array)$fu->getOpt('tables'), true)) {
                $fu->addErr('Table <strong>' . htmlspecialchars($table) . '</strong> already exists. Please choose a different name in your FanUpdate blog-config.php');
            }
        }
    }
}

// ── check collective_table is configured correctly ───────────────────────────

if ($fu->hasCollectiveScript()) {
    // An external collective table must already exist (it may be schema-qualified).
    if (!fu_table_exists($fu->db, (string) $fu->getOpt('collective_table'))) {
        $fu->addErr(
            'Could not find collective_table <strong>' . $fu->getOpt('collective_table') . '</strong>. '
            . 'Please check your settings in blog-config.php'
            . (str_contains((string) $fu->getOpt('collective_table'), '.')
                ? ' and that your database user has been granted access to that other database.'
                : '.')
        );
    }
}

// ── already up to date ───────────────────────────────────────────────────────

if (version_compare((string) $version_installed, $installer_version, '>=')) {
    $fu->addErr('You currently have FanUpdate version <strong>' . $version_installed . '</strong> installed, so you <strong>do not</strong> need to run this installation script; your database should already be configured to run FanUpdate ' . htmlspecialchars((string) $fu->getOpt('version')) . '. <strong>DELETE</strong> this file from your server and continue on <a href="index.php">to the ADMIN panel.</a>');
}

// ── process form submission ──────────────────────────────────────────────────

if (!$fu->noErr()) {

    $fu->reportErrors();
} elseif (isset($_POST['upgrade']) || isset($_POST['fresh_install'])) {

    // ── validate admin credentials on fresh install ───────────────────────
    if ($version_installed == 0) {
        $clean_creds = clean_input($_POST);
        if (empty($clean_creds['admin_username'])) {
            $fu->addErr('Please enter an admin username.');
        }
        if (empty($clean_creds['admin_password'])) {
            $fu->addErr('Please enter an admin password.');
        } elseif ($clean_creds['admin_password'] !== $clean_creds['admin_password_confirm']) {
            $fu->addErr('Passwords do not match. Please re-enter.');
        }
        $clean['admin_username'] = $clean_creds['admin_username'] ?? '';
        $clean['admin_password'] = $clean_creds['admin_password'] ?? '';
        if (!$fu->noErr()) {
            $fu->reportErrors();
            $fu->getFooter();
            exit;
        }
    }

    // _____________________________________________ FRESH INSTALL

    if ($version_installed == 0) {

        // blog table
        $query = 'CREATE TABLE ' . $fu->getOpt('blog_table') . " (
      `entry_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
      `added` datetime NOT NULL,
      `title` varchar(50) NOT NULL DEFAULT '',
      `body` text NOT NULL,
      `is_public` tinyint(1) unsigned NOT NULL DEFAULT '1',
      `comments_on` tinyint(1) unsigned NOT NULL DEFAULT '1',
      PRIMARY KEY (`entry_id`),
      FULLTEXT (title,body)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('blog_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('blog_table') . '</strong> not created!');
        }

        // comments table
        $query = 'CREATE TABLE ' . $fu->getOpt('comments_table') . " (
      `comment_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
      `entry_id` int(6) unsigned NOT NULL DEFAULT '0',
      `name` varchar(30) NOT NULL DEFAULT '',
      `email` varchar(100) NOT NULL DEFAULT '',
      `url` varchar(100) NOT NULL DEFAULT '',
      `added` datetime NOT NULL,
      `comment` text NOT NULL,
      `approved` tinyint(1) NOT NULL DEFAULT '0',
      `points` int(4) NOT NULL DEFAULT '0',
      PRIMARY KEY (`comment_id`),
      KEY `entry` (`entry_id`),
      KEY `approved` (`approved`),
      FULLTEXT (name,comment)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('comments_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('comments_table') . '</strong> not created!');
        }
        // collective/category table: standalone mode only (an external script owns its own).
        if (!$fu->hasCollectiveScript()) {
            // Standalone column names are FanUpdate's own fixed schema.
            $col_id   = 'fl_id';
            $col_subj = 'fl_subject';
            $query = 'CREATE TABLE ' . $fu->getOpt('collective_table') . " (
      `$col_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
      `$col_subj` varchar(50) NOT NULL DEFAULT '',
      PRIMARY KEY (`$col_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            if ($fu->db->Execute($query)) {
                $fu->addSuccess('Table <strong>' . $fu->getOpt('collective_table') . '</strong> created.');
            } else {
                $fu->addErr('Table <strong>' . $fu->getOpt('collective_table') . '</strong> not created!');
            }
        }
        // catjoin table
        $query = 'CREATE TABLE ' . $fu->getOpt('catjoin_table') . " (
      `entry_id` int(6) unsigned NOT NULL DEFAULT '0',
      `cat_id` int(6) unsigned NOT NULL DEFAULT '0',
      `collective_id` int(6) unsigned NOT NULL DEFAULT '0',
      PRIMARY KEY (`entry_id`,`cat_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('catjoin_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('catjoin_table') . '</strong> not created!');
        }

        // options table
        $query = 'CREATE TABLE ' . $fu->getOpt('options_table') . " (
      `optkey` varchar(30) NOT NULL,
      `optvalue` TEXT NOT NULL,
      `optdesc` TEXT NOT NULL,
      PRIMARY KEY (`optkey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('options_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('options_table') . '</strong> not created!');
        }

        // blacklist table
        $query = 'CREATE TABLE ' . $fu->getOpt('blacklist_table') . " (
      `badword` varchar(50) NOT NULL,
      PRIMARY KEY (`badword`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('blacklist_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('blacklist_table') . '</strong> not created!');
        }

        // Default blacklist: high-signal terms only. Multi-word phrases use
        // process.php's substring matcher.
        $query = 'INSERT INTO ' . $fu->getOpt('blacklist_table') . " (`badword`) VALUES
      ('bitcoin'),
      ('blackjack'),
      ('casino'),
      ('cialis'),
      ('crypto'),
      ('cryptocurrency'),
      ('diazepam'),
      ('essay writing'),
      ('gambling'),
      ('hydrocodone'),
      ('kasino'),
      ('levitra'),
      ('nft'),
      ('payday loan'),
      ('phentermine'),
      ('seo'),
      ('viagra')";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Blacklist words added.');
        } else {
            $fu->addErr('Blacklist words not added!');
        }

        // catoptions table
        $query = 'CREATE TABLE ' . $fu->getOpt('catoptions_table') . " (
      `cat_id` INT(6) UNSIGNED NOT NULL,
      `comments_on` TINYINT(1) UNSIGNED NULL,
      `date_format` VARCHAR(30) NULL,
      `gravatar_on` TINYINT(1) UNSIGNED NULL,
      `gravatar_default` VARCHAR(100) NULL,
      `gravatar_size` VARCHAR(2) NULL,
      `gravatar_rating` VARCHAR(2) NULL,
      `entry_template` TEXT NULL,
      `comment_template` TEXT NULL,
      PRIMARY KEY (`cat_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('catoptions_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('catoptions_table') . '</strong> not created!');
        }

        // smilies table
        $query = 'CREATE TABLE ' . $fu->getOpt('smilies_table') . " (
      `smiley_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
      `smiley` varchar(10) NOT NULL DEFAULT '',
      `image` varchar(100) NOT NULL DEFAULT '',
      PRIMARY KEY (`smiley_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('smilies_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('smilies_table') . '</strong> not created!');
        }

        $query = 'INSERT INTO ' . $fu->getOpt('smilies_table') . " (`smiley`, `image`) VALUES
      (':)', 'emoticon_smile.png'),
      (':D', 'emoticon_grin.png'),
      ('XD', 'emoticon_evilgrin.png'),
      (':O', 'emoticon_surprised.png'),
      (':P', 'emoticon_tongue.png'),
      (':(', 'emoticon_unhappy.png'),
      (';D', 'emoticon_wink.png')";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Default smilies added.');
        } else {
            $fu->addErr('Default smilies not added!');
        }

        // media table: file_path/thumb_path are relative to uploads/, so they survive an install_path change.
        $query = 'CREATE TABLE ' . $fu->getOpt('media_table') . " (
      `media_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `filename` varchar(191) NOT NULL DEFAULT '',
      `file_path` varchar(255) NOT NULL DEFAULT '',
      `thumb_path` varchar(255) NOT NULL DEFAULT '',
      `width` int(10) unsigned NOT NULL DEFAULT 0,
      `height` int(10) unsigned NOT NULL DEFAULT 0,
      `size` int(10) unsigned NOT NULL DEFAULT 0,
      `mime_type` varchar(50) NOT NULL DEFAULT '',
      `uploaded_at` datetime NOT NULL,
      PRIMARY KEY (`media_id`),
      KEY `uploaded_at` (`uploaded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Table <strong>' . $fu->getOpt('media_table') . '</strong> created.');
        } else {
            $fu->addErr('Table <strong>' . $fu->getOpt('media_table') . '</strong> not created!');
        }
    } // end fresh install

    // _______________________________________________ DATABASE SCHEMA MIGRATION
    // Shared with Admin -> Updates via SchemaMigrator; a safe no-op on tables already converted.

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
        $fu->hasCollectiveScript(),
        ((string) $fu->getOpt('standalone_category_table')) !== '' ? (string) $fu->getOpt('standalone_category_table') : null,
        ((string) $fu->getOpt('media_table')) !== '' ? (string) $fu->getOpt('media_table') : null,
        ((string) $fu->getOpt('log_table')) !== '' ? (string) $fu->getOpt('log_table') : null
    );

    foreach ($schemaMigrator->runPendingSchemaChanges() as $migrationResult) {
        if ($migrationResult['ok']) {
            $fu->addSuccess(htmlspecialchars($migrationResult['message'], ENT_QUOTES, 'UTF-8'));
        } else {
            $fu->addErr(htmlspecialchars($migrationResult['message'], ENT_QUOTES, 'UTF-8'));
        }
    }

    // _______________________________________________ UPGRADE FROM < 2.2b

    if ($version_installed > 0 && version_compare((string) $version_installed, '2.2b', '<')) {

        $query = 'UPDATE ' . $fu->getOpt('options_table') . " SET optkey='site_name' WHERE optkey='collective_name'";
        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Updated option name for <strong>site_name</strong>');
        } else {
            $fu->addErr('Failed to update option name for <strong>site_name</strong>.');
        }

        $query = 'UPDATE ' . $fu->getOpt('options_table') . " SET optkey='blog_page' WHERE optkey='collective_updates_page'";
        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Updated option name for <strong>blog_page</strong>');
        } else {
            $fu->addErr('Failed to update option name for <strong>blog_page</strong>.');
        }

        $query = 'UPDATE ' . $fu->getOpt('options_table') . " SET optkey='install_path' WHERE optkey='install_folder'";
        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Updated option name for <strong>install_path</strong>');
        } else {
            $fu->addErr('Failed to update option name for <strong>install_path</strong>.');
        }

        $query = 'ALTER TABLE ' . $fu->getOpt('comments_table') . "
      ADD `points` INT(4) NOT NULL DEFAULT '0' AFTER `approved`";
        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Old table <strong>' . $fu->getOpt('comments_table') . '</strong> modified.');
        } else {
            $fu->addErr('Old table <strong>' . $fu->getOpt('comments_table') . '</strong> not modified!');
        }

        $query = 'UPDATE ' . $fu->getOpt('options_table') . " SET optvalue=REPLACE(optvalue, '{{wysiwyg}}<br />', '') WHERE optkey='comment_form_template'";
        if ($fu->db->Execute($query)) {
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Removed old {{wysiwyg}} template tag.');
            }
        } else {
            $fu->addErr('Old {{wysiwyg}} template tag not removed!');
        }

        $query = 'UPDATE ' . $fu->getOpt('options_table') . " SET optvalue=REPLACE(optvalue, '{{wysiwyg}}', '') WHERE optkey='comment_form_template'";
        if ($fu->db->Execute($query)) {
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Removed old {{wysiwyg}} template tag.');
            }
        } else {
            $fu->addErr('Old {{wysiwyg}} template tag not removed!');
        }
    }

    // _______________________________________________ UPGRADE FROM < 2.2b3

    if ($version_installed > 0 && version_compare((string) $version_installed, '2.2b3', '<')) {

        // _server_tz_offset no longer exists in $setting[], so read it
        // directly from the DB for this legacy time-conversion query.
        $legacy_offset = (float) $fu->db->GetFirstCell(
            'SELECT optvalue FROM ' . $fu->getOpt('options_table') . " WHERE optkey='_server_tz_offset' LIMIT 1"
        );

        $query = 'UPDATE ' . $fu->getOpt('blog_table') . ' SET added=DATE_ADD(added, INTERVAL ' . (0 - $legacy_offset) . ' HOUR)';
        if ($fu->db->Execute($query)) {
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Blog times converted to GMT.');
            }
        } else {
            $fu->addErr('Failed to convert blog times to GMT!');
        }

        $query = 'UPDATE ' . $fu->getOpt('comments_table') . ' SET added=DATE_ADD(added, INTERVAL ' . (0 - $legacy_offset) . ' HOUR)';
        if ($fu->db->Execute($query)) {
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Comment times converted to GMT.');
            }
        } else {
            $fu->addErr('Failed to convert comment times to GMT!');
        }
    }

    // _______________________________________________ UPGRADE FROM < 2.2.1b2

    if ($version_installed > 0 && version_compare((string) $version_installed, '2.2.1b2', '<')) {

        $query = 'ALTER TABLE ' . $fu->getOpt('comments_table') . "
      CHANGE `added` `added` DATETIME NOT NULL";
        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Old table <strong>' . $fu->getOpt('comments_table') . '</strong> modified.');
        } else {
            $fu->addErr('Old table <strong>' . $fu->getOpt('comments_table') . '</strong> not modified!');
        }
    }

    // _______________________________________________ UPGRADE FROM ANY VERSION

    if ($version_installed > 0 && version_compare((string) $version_installed, $installer_version, '<')) {
        // _server_tz_offset is obsolete (queries use UTC_TIMESTAMP()).
        $fu->db->Execute(
            'DELETE FROM ' . $fu->getOpt('options_table') . " WHERE optkey='_server_tz_offset'",
            null
        );
        if ($fu->db->AffectedRows() > 0) {
            $fu->addSuccess('Removed obsolete option <strong>_server_tz_offset</strong>.');
        }
        // Defunct update-polling keys.
        foreach (['_last_update_check', '_last_update_version'] as $_dead_key) {
            $fu->db->Execute(
                'DELETE FROM ' . $fu->getOpt('options_table') . " WHERE optkey='$_dead_key'",
                null
            );
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Removed obsolete option <strong>' . htmlspecialchars($_dead_key) . '</strong>.');
            }
        }
        unset($_dead_key);

        // Migrate timezone_offset -> timezone, once (gated on 'timezone' not existing). The
        // $setting[] form can't do it: its field is version-gated to 2.2b3 and skipped on
        // any later install. UpgradeRunner has the non-interactive equivalent.
        $existingTimezone = $fu->db->GetFirstCell(
            'SELECT optvalue FROM ' . $fu->getOpt('options_table') . " WHERE optkey='" . FU_TIMEZONE_OPTION_KEY . "' LIMIT 1"
        );

        if ($existingTimezone === false) {
            $legacyOffsetRaw = $fu->db->GetFirstCell(
                'SELECT optvalue FROM ' . $fu->getOpt('options_table') . " WHERE optkey='timezone_offset' LIMIT 1"
            );

            $migratedTimezone = ($legacyOffsetRaw === false)
                ? FU_DEFAULT_TIMEZONE
                : fu_migrate_legacy_timezone_offset((float) $legacyOffsetRaw);

            $fu->db->Execute(
                'INSERT INTO ' . $fu->getOpt('options_table') . ' (optkey, optvalue, optdesc)
                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE optvalue = VALUES(optvalue)',
                'Could not migrate timezone_offset to timezone.',
                [FU_TIMEZONE_OPTION_KEY, $migratedTimezone, 'Your local IANA timezone (e.g. Europe/Helsinki). Used to display dates in your local time. Default: UTC']
            );

            $fu->db->Execute(
                'DELETE FROM ' . $fu->getOpt('options_table') . " WHERE optkey='timezone_offset'",
                null
            );

            $fu->addSuccess(
                'Migrated legacy <strong>timezone_offset</strong> to timezone <strong>' . htmlspecialchars($migratedTimezone, ENT_QUOTES, 'UTF-8') . '</strong>. '
                . 'Please review Admin -> Options and adjust it if this guess isn’t exactly right for your location.'
            );
        }

        // Blacklist update: INSERT IGNORE never overwrites user additions; retired terms are deleted if present.
        $new_words = [
            'bitcoin',
            'crypto',
            'cryptocurrency',
            'essay writing',
            'nft',
            'payday loan',
            'seo',
        ];

        foreach ($new_words as $_word) {
            $fu->db->Execute(
                'INSERT IGNORE INTO ' . $fu->getOpt('blacklist_table') . ' (`badword`) VALUES (?)',
                null,
                [$_word]
            );
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Added <strong>' . htmlspecialchars($_word) . '</strong> to blacklist.');
            }
        }
        unset($_word);

        $retired_words = ['hoodia', 'ringtones', 'webcam'];

        foreach ($retired_words as $_word) {
            $fu->db->Execute(
                'DELETE FROM ' . $fu->getOpt('blacklist_table') . ' WHERE badword = ?',
                null,
                [$_word]
            );
            if ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess('Removed retired blacklist word <strong>' . htmlspecialchars($_word) . '</strong>.');
            }
        }
        unset($_word);

        $files = [];
        $files[] = 'add-fl.php';
        $files[] = 'blog-admin.php';
        $files[] = 'comments-admin.php';
        $files[] = 'admin-wysiwyg.php';
        $files[] = 'path.php';
        $files[] = 'gravatar.gif';
        $files[] = 'formatText.js';
        $files[] = 'sortTable.js';
        $files[] = 'fanupdate.gif';
        $files[] = 'approve-comment.php';
        $files[] = 'changelog.txt';
        $files[] = 'fanupdate.js';
        $files[] = 'fanupdate.png';
        $files[] = 'fanupdate.svg';
        $files[] = 'fanupdate-logo.svg';
        $files[] = 'new-entry-button.png';
        $files[] = 'new-entry-button.svg';
        $files[] = 'new-entry-button-active.png';
        $files[] = 'SqlConnection.php';
        $files[] = 'standardista-table-sorting.js';
        $files[] = 'nav.php';
        $files[] = 'protect.php';
        $files[] = 'readme.txt';
        $files[] = 'show-post.inc.php';
        $files[] = 'style.css';
        $files[] = 'wysiwyg.php';
        $files[] = 'wysiwyg-admin.php';
        $files[] = 'img/cross.png';
        $files[] = 'img/tick.png';

        foreach ($files as $file) {
            if (file_exists($file)) {
                if (unlink($file)) {
                    $fu->addSuccess('Old file <strong>' . $file . '</strong> deleted.');
                } else {
                    $fu->addErr('Old file <strong>' . $file . '</strong> not deleted! Please delete it manually.');
                }
            }
        }

        foreach ($setting as $val) {
            $query = 'UPDATE ' . $fu->getOpt('options_table') . ' SET optdesc = ? WHERE optkey = ?';

            if ($fu->db->Execute($query, 'Could not execute query.', [$val['optdesc'], $val['optkey']])) {
                if ($fu->db->AffectedRows() > 0) {
                    $fu->addSuccess('Updated description for <strong>' . $val['optkey'] . '</strong>');
                }
            } else {
                $fu->addErr('Failed to update description for <strong>' . $val['optkey'] . '</strong>.');
            }
        }
    }

    // _______________________________________________ FRESH INSTALL OR UPGRADE FROM ANY VERSION

    if (version_compare((string) $version_installed, $installer_version, '<')) {

        $clean = clean_input($_POST, true);

        foreach ($clean['key'] as $key => $val) {

            $val = (int) $val;
            if (!isset($setting[$val])) {
                continue;
            }

            $query = 'INSERT INTO ' . $fu->getOpt('options_table') . ' SET optkey = ?, optvalue = ?, optdesc = ?';

            if ($fu->db->Execute($query, 'Could not execute query.', [
                $setting[$val]['optkey'],
                $clean['value'][$key],
                $setting[$val]['optdesc'],
            ])) {
                $fu->addSuccess('Set <strong>' . htmlspecialchars($setting[$val]['optkey']) . '</strong> = ' . nl2br(htmlspecialchars($clean['value'][$key])));
            } else {
                $fu->addErr('Failed to insert <strong>' . htmlspecialchars($setting[$val]['optkey']) . '</strong>');
            }
        }
    }

    $query = 'UPDATE ' . $fu->getOpt('options_table') . " SET optvalue = ? WHERE optkey='_db_version'";

    if (!$fu->db->Execute($query, 'Could not execute query.', [(string) $fu->getOpt('version')])) {
        $fu->addErr('Failed to set current db version.');
    }

    // ── write admin credentials (bcrypt-hashed options rows) ─────────────────

    if (!empty($clean['admin_username']) && !empty($clean['admin_password'])) {

        $bcrypt = password_hash($clean['admin_password'], PASSWORD_BCRYPT);

        $credentialQuery = 'INSERT INTO ' . $fu->getOpt('options_table') . ' SET optkey = ?, optvalue = ?, optdesc = ?';

        $usernameSaved = $fu->db->Execute($credentialQuery, 'Could not execute query.', [
            'admin_username',
            $clean['admin_username'],
            'Your admin login username. Editable from Admin -> Account.',
        ]);

        $passwordSaved = $fu->db->Execute($credentialQuery, 'Could not execute query.', [
            'admin_password',
            $bcrypt,
            'Your admin login password (bcrypt hash). Editable from Admin -> Account.',
        ]);

        if ($usernameSaved && $passwordSaved) {
            $fu->addSuccess('Admin credentials saved.');
        } else {
            $fu->addErr('Failed to save admin credentials.');
        }
    }

    // _____________________________________________ REPORT SUCCESS

    $fu->reportSuccess();

    // _____________________________________________ REPORT ERRORS

    $fu->reportErrors();

    // ── auto-delete install.php ───────────────────────────────────────────
    $install_file = __FILE__;
    if ($fu->noErr() && @unlink($install_file)) {
        echo '<p class="success"><strong>install.php has been automatically deleted</strong> from your server.</p>' . "\n";
    } else {
        echo '<p class="error"><strong>Could not auto-delete install.php</strong> — please delete it from your server manually before using the admin panel.</p>' . "\n";
    }
    echo '<p><a href="index.php">To the ADMIN panel.</a></p>' . "\n";
} else {

?>

    <p>To install FanUpdate <?php echo htmlspecialchars((string) $fu->getOpt('version')); ?>, edit these options for your site, then click the
        button below. <?php if (version_compare((string) $version_installed, (string) $setting[3]['version'], '<')) {
                            echo ' The auto-detected path settings should be correct, but please double-check them.';
                        } ?></p>

    <form id="options" action="install.php" method="post">
        <?php

        // Collective-source settings are seeded as hidden inputs, not editable here:
        // Admin -> Options -> Collective Source has a dropdown that auto-fills values
        // matching the chosen script's real schema.
        $fuHiddenCollectiveSourceKeys = ['collective_script', 'col_id', 'col_subj', 'collective_table'];

        foreach ($setting as $id => $row) {

            if (version_compare((string) $version_installed, (string) $row['version'], '<')) {

                // Strict === 0: a loose == 0 hid too many fields.
                if (strpos($row['optkey'], '_') === 0 || in_array($row['optkey'], $fuHiddenCollectiveSourceKeys, true)) { // hide private vars + collective-source settings

        ?>
                    <input type="hidden" id="<?php echo $row['optkey']; ?>" name="value[]"
                        value="<?php echo htmlspecialchars((string) $row['optvalue']); ?>" />
                    <input type="hidden" name="key[]" value="<?php echo $id; ?>" />
                <?php

                } elseif ($row['optkey'] === FU_TIMEZONE_OPTION_KEY) {

                    // Grouped by region from PHP's timezone database; the script below
                    // pre-selects the browser's detected zone.
                    $class = (isset($class) && $class === 'even') ? 'odd' : 'even';
                ?>
                    <div class="option <?php echo $class; ?>">
                        <p><label for="<?php echo $row['optkey']; ?>"><?php echo htmlspecialchars(fu_option_label($row['optkey']), ENT_QUOTES, 'UTF-8'); ?>:
                                <span class="help"><?php echo htmlspecialchars($row['optdesc']); ?></span></label>
                            <select id="timezone" name="value[]">
                                <?php foreach (fu_timezone_identifiers_grouped() as $region => $zones) { ?>
                                <optgroup label="<?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php foreach ($zones as $tz) { ?>
                                    <option value="<?php echo htmlspecialchars($tz, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo ($tz === (string) $row['optvalue']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(fu_timezone_label($tz), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                    <?php } ?>
                                </optgroup>
                                <?php } ?>
                            </select>
                            <input type="hidden" name="key[]" value="<?php echo $id; ?>" />
                        </p>
                    </div><!-- END .option -->
        <?php

                } elseif ($row['optkey'] === FU_DATE_FORMAT_OPTION_KEY) {

                    // The <select> is visual only; one named field per row carries value[],
                    // because key[]/value[] are paired by position.
                    $class                    = (isset($class) && $class === 'even') ? 'odd' : 'even';
                    $isKnownDateFormatPreset  = fu_is_known_date_format_preset((string) $row['optvalue']);
                ?>
                    <div class="option <?php echo $class; ?>">
                        <p><label for="<?php echo $row['optkey']; ?>_select"><?php echo htmlspecialchars(fu_option_label($row['optkey']), ENT_QUOTES, 'UTF-8'); ?>:
                                <span class="help"><?php echo htmlspecialchars($row['optdesc']); ?></span></label>
                            <select id="<?php echo $row['optkey']; ?>_select" class="fu-date-format-select"
                                    data-target="<?php echo $row['optkey']; ?>_value">
                                <?php foreach (fu_date_format_presets() as $preset) { ?>
                                <option value="<?php echo htmlspecialchars($preset, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo ($preset === (string) $row['optvalue']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(fu_date_format_option_label($preset), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php } ?>
                                <option value="<?php echo FU_DATE_FORMAT_CUSTOM_VALUE; ?>" <?php echo !$isKnownDateFormatPreset ? 'selected' : ''; ?>>Custom&hellip;</option>
                            </select>
                            <input type="text" id="<?php echo $row['optkey']; ?>_value" name="value[]"
                                   value="<?php echo htmlspecialchars((string) $row['optvalue'], ENT_QUOTES, 'UTF-8'); ?>"
                                   <?php echo $isKnownDateFormatPreset ? 'readonly' : ''; ?>
                                   style="margin-top:0.5rem;<?php echo $isKnownDateFormatPreset ? ' display:none;' : ''; ?>" />
                            <input type="hidden" name="key[]" value="<?php echo $id; ?>" />
                        </p>
                    </div><!-- END .option -->
                <?php

                } elseif (fu_is_yn_option($row['optkey'], $row['optdesc'])) {

                    // The checkbox is nameless: key[]/value[] are paired by position, so one
                    // that only submits when checked would misalign later fields. A hidden
                    // field carries the value, synced by the script below.
                    $class  = (isset($class) && $class === 'even') ? 'odd' : 'even';
                    $is_on  = (strtolower(trim((string) $row['optvalue'])) === 'y');
                ?>
                    <div class="option <?php echo $class; ?>">
                        <div class="option-yn-row">
                            <label class="option-yn-label" for="<?php echo $row['optkey']; ?>">
                                <?php echo htmlspecialchars(fu_option_label($row['optkey']), ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                            <label class="fu-toggle" title="<?php echo htmlspecialchars($row['optdesc']); ?>">
                                <input type="hidden" id="yn_hidden_<?php echo $id; ?>" name="value[]"
                                    value="<?php echo $is_on ? 'y' : 'n'; ?>" />
                                <?php /* Checkbox must immediately precede .fu-toggle__track — the CSS's
                                        ":checked + .fu-toggle__track" selector requires adjacent siblings. */ ?>
                                <input type="checkbox" id="<?php echo $row['optkey']; ?>"
                                    class="fu-toggle__input"
                                    data-yn-hidden="yn_hidden_<?php echo $id; ?>"
                                    <?php echo $is_on ? 'checked' : ''; ?> />
                                <span class="fu-toggle__track" aria-hidden="true">
                                    <span class="fu-toggle__thumb"></span>
                                </span>
                                <span class="fu-toggle__state-label">
                                    <?php echo $is_on ? 'Yes' : 'No'; ?>
                                </span>
                            </label>
                            <?php if ($row['optdesc'] !== '') { ?>
                            <p class="hint small option-yn-hint"><?php echo htmlspecialchars($row['optdesc']); ?></p>
                            <?php } ?>
                        </div>
                        <input type="hidden" name="key[]" value="<?php echo $id; ?>" />
                    </div><!-- END .option -->
        <?php

                } else {

                    $class = (isset($class) && $class === 'even') ? 'odd' : 'even';

                ?>
                    <div class="option <?php echo $class; ?>">
                        <p><label for="<?php echo $row['optkey']; ?>"><?php echo htmlspecialchars(fu_option_label($row['optkey']), ENT_QUOTES, 'UTF-8'); ?>:
                                <span class="help"><?php echo htmlspecialchars($row['optdesc']); ?></span></label>
                            <?php if (strpos($row['optkey'], 'template') !== false) { ?>
                                <textarea id="<?php echo $row['optkey']; ?>" name="value[]" cols="80"
                                    rows="15"><?php echo htmlspecialchars((string) $row['optvalue']); ?></textarea>
                            <?php } else { ?>
                                <input type="<?php echo $row['optkey'] === 'admin_pass' ? 'password' : 'text'; ?>"
                                    id="<?php echo $row['optkey']; ?>" name="value[]" size="50"
                                    maxlength="255"
                                    value="<?php echo htmlspecialchars((string) $row['optvalue']); ?>" />
                            <?php } ?>
                            <input type="hidden" name="key[]" value="<?php echo $id; ?>" />
                        </p>
                    </div><!-- END .option -->
        <?php

                }
            }
        }

        ?>

        <?php if ($version_installed == 0) { ?>
            <div class="option odd">
                <p><label for="admin_username">Admin username:
                        <span class="help">Your admin login username.</span></label>
                    <input type="text" id="admin_username" name="admin_username" size="50"
                        maxlength="50" value="<?php echo htmlspecialchars($current_admin_username); ?>" />
                </p>
            </div>
            <div class="option even">
                <p><label for="admin_password">Admin password:
                        <span class="help">Your admin login password. Choose something secure.</span></label>
                    <input type="password" id="admin_password" name="admin_password" size="50"
                        maxlength="100" value="" />
                </p>
            </div>
            <div class="option odd">
                <p><label for="admin_password_confirm">Admin password (confirm):
                        <span class="help">Re-enter your password to confirm.</span></label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" size="50"
                        maxlength="100" value="" />
                </p>
            </div>
        <?php } ?>

        <p id="new-button">
            <?php if ($version_installed > 0) { ?>
                <input type="submit" name="upgrade" value="Upgrade from version <?php echo htmlspecialchars((string) $version_installed); ?>"
                    class="update" />
            <?php } else { ?>
                <input type="submit" name="fresh_install" value="Create fresh installation" class="update" />
            <?php } ?>
        </p>

    </form>

    <script type="text/javascript">
        // Pre-select the browser's timezone when it is present in the <select>.
        const tzSelect = document.getElementById('timezone');
        if (tzSelect) {
            try {
                const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
                const option = tzSelect.querySelector('option[value="' + detected + '"]');
                if (option) {
                    tzSelect.value = detected;
                }
            } catch (e) {
                // Intl unsupported or timezone lookup failed — leave default selected.
            }
        }
    </script>

<?php

}

$fu->getFooter();
