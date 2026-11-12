<?php
declare(strict_types=1);
/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc
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
 * Emits the CSRF token and asset version as <meta> tags;
 * assets/js/fanupdate-admin-config.js exposes them as the `fuCsrfToken` /
 * `fuAssetVersion` globals the admin AJAX scripts use. Included from
 * FanUpdate::getHeader(), so $this is the FanUpdate instance.
 *
 * Also included from install.php, which sits one directory above admin/, so
 * asset links need a "../" prefix only for admin pages; $fuAssetPrefix
 * detects which by comparing the requested script's directory with this
 * file's.
 ******************************************************************************/
$fuAssetPrefix = (dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __DIR__) ? '../' : '';

// Cache-buster so a version bump can't pair stale cached JS/CSS with new markup.
$fuAssetVersion = urlencode(FU_VERSION);
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
    <link rel="stylesheet" id="fu-theme-css" href="<?php echo $fuAssetPrefix; ?>assets/css/style.css?v=<?php echo $fuAssetVersion; ?>" />
    <link rel="shortcut icon" href="<?php echo $fuAssetPrefix; ?>favicon.ico" />

    <?php /* Page values reach the scripts through <meta> tags, not inline scripts, so the admin works under a strict script-src CSP. */ ?>
    <meta name="fu-csrf-token" content="<?php echo htmlspecialchars($this->generateCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>" />
    <?php /* Must match the ?v= query string on the style.css/style-night.css <link> tags, or fanupdate-admin.js's href comparison breaks. */ ?>
    <meta name="fu-asset-version" content="<?php echo htmlspecialchars($fuAssetVersion, ENT_QUOTES, 'UTF-8'); ?>" />

    <?php /* Apply saved theme before first paint to avoid flash of wrong theme */ ?>
    <script src="<?php echo $fuAssetPrefix; ?>assets/js/fanupdate-theme-init.js?v=<?php echo $fuAssetVersion; ?>"></script>

    <script src="<?php echo $fuAssetPrefix; ?>assets/vendor/tablesort/tablesort.js?v=<?php echo $fuAssetVersion; ?>"></script>
    <script src="<?php echo $fuAssetPrefix; ?>assets/js/fanupdate.js?v=<?php echo $fuAssetVersion; ?>"></script>
    <script src="<?php echo $fuAssetPrefix; ?>assets/js/fanupdate-admin-config.js?v=<?php echo $fuAssetVersion; ?>"></script>
    <script src="<?php echo $fuAssetPrefix; ?>assets/js/fanupdate-admin.js?v=<?php echo $fuAssetVersion; ?>"></script>

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
                <ul id="nav-links">
                    <li><a href="index.php"    class="dashboard"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a></li>
                    <li><a href="blog.php"      class="entries"><i class="ti ti-pencil" aria-hidden="true"></i> Entries</a></li>
                    <li><a href="comment.php"   class="comments"><i class="ti ti-message-circle" aria-hidden="true"></i> Comments</a></li>
                    <li><a href="category.php"  class="categories"><i class="ti ti-tag" aria-hidden="true"></i> Categories</a></li>
                    <li><a href="media.php"     class="media"><i class="ti ti-photo" aria-hidden="true"></i> Media</a></li>
                    <li><a href="options.php"   class="options"><i class="ti ti-settings" aria-hidden="true"></i> Options</a></li>
                    <li><a href="logs.php"      class="logs"><i class="ti ti-list-details" aria-hidden="true"></i> Logs</a></li>
                    <li><a href="update.php"    class="updates"><i class="ti ti-refresh" aria-hidden="true"></i> Updates</a></li>
                </ul>

                <div id="nav-end">
                    <button type="button" id="fu-nav-toggle" class="nav-icon-btn" title="Menu" aria-expanded="false" aria-controls="nav-links">
                        <i class="ti ti-menu-2" aria-hidden="true"></i>
                    </button>
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
    // Shown on every admin page until resolved. Gated on isLoggedIn() so the
    // database checks don't run on the login form or during install.php, and
    // so plain "update.php" links resolve. update.php is excluded: it has its
    // own detailed versions.
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

        // media_table is a blog-config.php setting, so an update can't add it
        // automatically; without this notice upgraders never learn the Media
        // gallery exists.
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
