<?php
declare(strict_types=1);
/*****************************************************************************
 * FanUpdate — change-password.php
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 Redux contributor
 *
 * Allows the logged-in admin to change their password without manually
 * editing blog-config.php.
 *
 * Security model:
 *  - Current password is verified with password_verify() before any change
 *  - New hash is written to blog-config.php via preg_replace with addcslashes
 *    to safely handle bcrypt's literal '$' signs in the replacement string
 *  - A fresh HMAC token is issued immediately so the session remains valid
 *    (the old cookie token would be stale as soon as the stored hash changes)
 *  - HttpOnly flag set on both cookies
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

require_once('blog-config.php');
require_once('functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

// ── handle form submission ───────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'change_password') {

    $clean = clean_input($_POST);

    $currentPassword = $clean['current_password'] ?? '';
    $newPassword     = $clean['new_password']     ?? '';
    $confirmPassword = $clean['confirm_password'] ?? '';

    $storedHash = (string) $fu->getOpt('admin_password', true);

    // ── validate ─────────────────────────────────────────────────────────────

    if ($currentPassword === '') {
        $fu->addErr('Please enter your current password.');
    } elseif (!password_verify($currentPassword, $storedHash)) {
        $fu->addErr('Current password is incorrect.');
    }

    if ($newPassword === '') {
        $fu->addErr('Please enter a new password.');
    } elseif (strlen($newPassword) < 8) {
        $fu->addErr('New password must be at least 8 characters.');
    }

    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $fu->addErr('New passwords do not match.');
    }

    // ── write new hash if valid ───────────────────────────────────────────────

    if ($fu->noErr()) {

        $newHash    = password_hash($newPassword, PASSWORD_BCRYPT);
        $configFile = __DIR__ . '/blog-config.php';

        if (!is_writable($configFile)) {
            $fu->addErr(
                '<strong>blog-config.php</strong> is not writable. '
                . 'Please <code>chmod 644</code> (or temporarily <code>chmod 666</code>) the file and try again, '
                . 'or set the password manually:<br>'
                . '<code>$fanupdate[\'admin_password\'] = \''
                . htmlspecialchars($newHash, ENT_QUOTES, 'UTF-8')
                . '\';</code>'
            );
        } else {
            $configContent = (string) file_get_contents($configFile);

            // Matches all three possible existing formats:
            //   $fanupdate['admin_password'] = md5('...');     ← legacy original
            //   $fanupdate['admin_password'] = 'abc123...';    ← bare MD5 string
            //   $fanupdate['admin_password'] = '$2y$...';      ← bcrypt (normal)
            //
            // addcslashes(..., '\\$') escapes literal '$' in the bcrypt hash so
            // preg_replace does not treat them as backreference markers.
            $newContent = preg_replace(
                "/(\\\$fanupdate\['admin_password'\]\s*=\s*)(?:md5\('[^']*'\)|'[^']*')/",
                "\$1'" . addcslashes($newHash, '\\$') . "'",
                $configContent
            );

            if ($newContent === null || file_put_contents($configFile, $newContent) === false) {
                $fu->addErr('Failed to write to <strong>blog-config.php</strong>. Password not changed.');
            } else {
                // ── update in-memory config and reissue session token ─────────
                // The HMAC token in the browser cookie is keyed on the stored
                // hash.  Now that the hash has changed, the old cookie is stale.
                // Derive a fresh token from the new hash and rewrite the cookie
                // so the admin is not immediately kicked to the login form.
                $fu->addOpt('admin_password', $newHash);

                $username = (string) $fu->getOpt('admin_username', true);
                $newToken = hash_hmac('sha256', $username, $newHash);

                $remember = isset($_COOKIE['fu_username']); // preserve "remember me" state
                $expire   = $remember ? time() + 60 * 60 * 24 * 30 : 0;

                setcookie('fu_username', $username, $expire, '/', '', false, true);
                setcookie('fu_token',    $newToken,  $expire, '/', '', false, true);

                $fu->addSuccess('Password changed successfully.');
            }
        }
    }
}

// ── render page ──────────────────────────────────────────────────────────────

$fu->getHeader('Change Password');

?>

<h2>Change Password</h2>

<ul class="subnav">
    <li><a href="options.php">Main</a></li>
    <li><a href="blacklist.php">Blacklist</a></li>
    <li><a href="smilies.php">Smilies</a></li>
    <li><a href="templates.php">Templates</a></li>
    <li><a href="change-password.php">Password</a></li>
</ul>

<?php

$fu->reportSuccess();
$fu->reportErrors();

?>

<div id="options">
<form action="change-password.php" method="post">
    <input type="hidden" name="action" value="change_password" />

    <div class="option odd">
        <p>
            <label for="current_password">Current password:
                <span class="help">Enter your existing password to confirm your identity.</span>
            </label>
            <input type="password"
                   id="current_password"
                   name="current_password"
                   size="30"
                   maxlength="255"
                   autocomplete="current-password" />
        </p>
    </div>

    <div class="option even">
        <p>
            <label for="new_password">New password:
                <span class="help">Minimum 8 characters.</span>
            </label>
            <input type="password"
                   id="new_password"
                   name="new_password"
                   size="30"
                   maxlength="255"
                   autocomplete="new-password" />
        </p>
    </div>

    <div class="option odd">
        <p>
            <label for="confirm_password">Confirm new password:
                <span class="help">Re-enter the new password.</span>
            </label>
            <input type="password"
                   id="confirm_password"
                   name="confirm_password"
                   size="30"
                   maxlength="255"
                   autocomplete="new-password" />
        </p>
    </div>

    <p style="margin-top: 1em;">
        <input type="submit"
               name="submit_password"
               value="Change Password"
               class="update"
               accesskey="s" />
    </p>

</form>
</div><!-- END #options -->

<?php $fu->getFooter(); ?>
