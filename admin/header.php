<?php
declare(strict_types=1);
/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
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
 *
 * Note: emits `const fuCsrfToken = "...";` so AJAX calls in fanupdate-admin.js,
 * fanupdate-post-admin.js, and fanupdate-comment-admin.js (which build their
 * own request bodies rather than submitting an HTML <form>) can include the
 * CSRF token. header.php is include()'d from FanUpdate::getHeader(), so
 * $this refers to the FanUpdate instance.
 *
 * Bug fix: this file is included from every admin/*.php page AND from
 * install.php, which lives one directory UP from here (the project root,
 * a sibling of assets/ — not inside admin/). The asset/favicon/logo links
 * below used to hardcode "../assets/..." unconditionally, which is only
 * correct for an admin/*.php caller; from install.php's own URL, "../"
 * escapes the project directory entirely, so every asset 404s and the
 * install page renders completely unstyled. $fuAssetPrefix below detects
 * which case we're in by comparing the actually-requested script's
 * directory (SCRIPT_FILENAME) against this file's own directory (always
 * admin/, since header.php physically lives here) — if they match, we're
 * being viewed via an admin/*.php page and "../" is correct; otherwise
 * (install.php) no prefix is needed at all.
 ******************************************************************************/
$fuAssetPrefix = (dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __DIR__) ? '../' : '';
?><!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex,nofollow" />

    <title><?php
        $pageTitle = $pageTitle ?? ($GLOBALS['pageTitle'] ?? null);
        if ($pageTitle) {
            echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . ' | ';
            $pageID = strtolower(str_replace(' ', '-', $pageTitle));
        }
    ?>FanUpdate Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&family=Playfair+Display:ital,wght@0,500;1,400&display=swap" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" />
    <link rel="stylesheet" id="fu-theme-css" href="<?php echo $fuAssetPrefix; ?>assets/css/style.css" />
    <link rel="shortcut icon" href="<?php echo $fuAssetPrefix; ?>favicon.ico" />

    <?php /* Apply saved theme before first paint to avoid flash of wrong theme */ ?>
    <script>
    (function () {
        var saved = localStorage.getItem('fu_theme');
        if (saved === 'night') {
            document.getElementById('fu-theme-css').href = '<?php echo $fuAssetPrefix; ?>assets/css/style-night.css';
        }
    }());
    </script>

    <script src="<?php echo $fuAssetPrefix; ?>assets/js/fanupdate.js"></script>
    <script>const fuCsrfToken = <?php echo json_encode($this->generateCsrfToken(), JSON_THROW_ON_ERROR); ?>;</script>
    <script src="<?php echo $fuAssetPrefix; ?>assets/js/fanupdate-admin.js"></script>

</head>

<body<?php
    $pageID = $pageID ?? ($GLOBALS['pageID'] ?? null);
    if (!empty($pageID)) {
        echo ' id="' . htmlspecialchars($pageID, ENT_QUOTES, 'UTF-8') . '"';
    }
?>>

<div id="wrap">

    <header id="header">
        <div id="header-inner">

            <h1><a href="index.php">Fan<span>Update</span> Redux</a></h1>

            <?php $showNav = $showNav ?? ($GLOBALS['showNav'] ?? false); if ($showNav === true) { ?>
            <nav id="nav" aria-label="Main navigation">
                <ul>
                    <li><a href="index.php"    class="dashboard"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a></li>
                    <li><a href="blog.php"      class="entries"><i class="ti ti-pencil" aria-hidden="true"></i> Entries</a></li>
                    <li><a href="comment.php"   class="comments"><i class="ti ti-message-circle" aria-hidden="true"></i> Comments</a></li>
                    <li><a href="category.php"  class="categories"><i class="ti ti-tag" aria-hidden="true"></i> Categories</a></li>
                    <li><a href="media.php"     class="media"><i class="ti ti-photo" aria-hidden="true"></i> Media</a></li>
                    <li><a href="options.php"   class="options"><i class="ti ti-settings" aria-hidden="true"></i> Options</a></li>
                    <li><a href="update.php"    class="updates"><i class="ti ti-refresh" aria-hidden="true"></i> Updates</a></li>
                </ul>

                <div id="nav-end">
                    <a href="account.php"            class="nav-icon-btn" title="Account"><i class="ti ti-user" aria-label="Account"></i></a>
                    <a href="credits.php"             class="nav-icon-btn" title="Credits"><i class="ti ti-info-circle" aria-label="Credits"></i></a>
                    <button type="button" id="fu-theme-toggle" class="nav-icon-btn" title="Toggle night theme" aria-pressed="false">
                        <i class="ti ti-moon" aria-hidden="true"></i>
                    </button>
                    <a href="index.php?action=logout" class="nav-icon-btn" title="Logout"><i class="ti ti-logout" aria-label="Logout"></i></a>
                </div>
            </nav>
            <?php } ?>

        </div>
    </header>

    <main id="main">

    <?php
    // ── Global "install.php present" / "pending DB upgrades" banners ────────
    // Previously only shown on admin/index.php's Dashboard, so an admin who
    // logged in, missed it, and went straight to e.g. Entries would never
    // see it again until their next Dashboard visit. Rendered here instead
    // (admin/header.php — included by every admin/*.php page) so both
    // banners show on every page until actually resolved. Gated on
    // isLoggedIn() (never redirects, safe to call pre-auth) so nothing here
    // ever renders on the login form itself or during install.php's own
    // execution (which never has valid auth cookies yet) — these checks
    // touch the database and shouldn't run before an admin is authenticated.
    // isLoggedIn() being true also means we're necessarily viewing an
    // admin/*.php page (never install.php), so plain "update.php" links
    // below resolve correctly without needing $fuAssetPrefix.
    // admin/update.php itself is excluded: it already shows its own richer,
    // detail-page version of both notices (exact path, "Review & run now"
    // inline), so the generic summary banner here would just duplicate it.
    if ($this->isLoggedIn() && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'update.php') {
        $installPathOpt = $this->getOpt('install_path');
        $installPath    = ($installPathOpt !== false && (string) $installPathOpt !== '')
            ? rtrim((string) $installPathOpt, '/')
            : dirname(__DIR__);

        if (fu_install_script_exists($installPath)) {
            ?>
            <div class="error" style="display:flow-root;">
                <p style="margin-bottom:0;">
                    <strong>Security: <code>install.php</code> is still on your server.</strong>
                    It should be deleted after installation — anyone who finds the URL can attempt
                    to run it against this site. <a href="update.php#install-php-notice">More detail</a>,
                    or just delete <code>install.php</code> from your server now.
                </p>
            </div>
            <?php
        }

        if (fu_build_upgrade_runner($this)->hasPendingUpgrades(FU_VERSION)) {
            ?>
            <div class="error" style="display:flow-root;">
                <p style="margin-bottom:0.5rem;">
                    <strong>Pending database updates.</strong>
                    This installation's database needs updates that haven't run yet — for example if it was
                    updated by uploading files manually rather than through the Install flow, which runs this
                    automatically.
                </p>
                <p style="margin-bottom:0;">
                    <a href="update.php#database-updates" class="button update">Review &amp; run now</a>
                </p>
            </div>
            <?php
        }

        // Media gallery ("Redux 2.3.0" — TODO.md item "4. UI - Create an
        // Image/File Manager") reads media_table from blog-config.php, same
        // as dbhost/collective_table/etc. — it's a config-file setting, not
        // an options-table one, so there's no safe way for an update to add
        // it automatically the way UpgradeRunner migrates database-backed
        // settings. Without this notice, an installation that upgrades
        // (rather than fresh-installs) gets no indication the feature exists
        // at all until an admin happens to click into Admin -> Media
        // themselves and sees that page's own inline notice — reported live
        // after exactly that happened following a 2.2.0 -> 2.3.0 upgrade.
        if ((string) $this->getOpt('media_table') === '') {
            ?>
            <div class="error" style="display:flow-root;">
                <p style="margin-bottom:0;">
                    <strong>Media gallery not configured.</strong>
                    Add <code>$fanupdate['media_table'] = 'blog_media';</code> to your
                    <code>blog-config.php</code>, then visit
                    <a href="update.php#database-updates">Admin &rarr; Updates</a> to create the table.
                </p>
            </div>
            <?php
        }
    }
    ?>
