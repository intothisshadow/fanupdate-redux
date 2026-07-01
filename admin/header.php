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
 * CSRF protection (added this release):
 *  - Emits `const fuCsrfToken = "...";` so AJAX calls in fanupdate-admin.js,
 *    fanupdate-post-admin.js and fanupdate-comment-admin.js (which build
 *    their own request bodies rather than submitting an HTML <form>) can
 *    include the token. Uses $this->generateCsrfToken() — header.php is
 *    include()'d from FanUpdate::getHeader(), so $this is the FanUpdate
 *    instance.
 ******************************************************************************/
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
    <link rel="stylesheet" id="fu-theme-css" href="../assets/css/style.css" />
    <link rel="shortcut icon" href="../favicon.ico" />

    <?php /* Apply saved theme before first paint to avoid flash of wrong theme */ ?>
    <script>
    (function () {
        var saved = localStorage.getItem('fu_theme');
        if (saved === 'night') {
            document.getElementById('fu-theme-css').href = '../assets/css/style-night.css';
        }
    }());
    </script>

    <script src="../assets/js/fanupdate.js"></script>
    <script>const fuCsrfToken = <?php echo json_encode($this->generateCsrfToken(), JSON_THROW_ON_ERROR); ?>;</script>
    <script src="../assets/js/fanupdate-admin.js"></script>

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

            <h1><a href="index.php">Fan<span>Update</span></a></h1>

            <?php $showNav = $showNav ?? ($GLOBALS['showNav'] ?? false); if ($showNav === true) { ?>
            <nav id="nav" aria-label="Main navigation">
                <ul>
                    <li><a href="index.php"    class="dashboard"><i class="ti ti-layout-dashboard" aria-hidden="true"></i> Dashboard</a></li>
                    <li><a href="blog.php"      class="entries"><i class="ti ti-pencil" aria-hidden="true"></i> Entries</a></li>
                    <li><a href="comment.php"   class="comments"><i class="ti ti-message-circle" aria-hidden="true"></i> Comments</a></li>
                    <li><a href="category.php"  class="categories"><i class="ti ti-tag" aria-hidden="true"></i> Categories</a></li>
                    <li><a href="options.php"   class="options"><i class="ti ti-settings" aria-hidden="true"></i> Options</a></li>
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
