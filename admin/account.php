<?php
declare(strict_types=1);
/*****************************************************************************
 * FanUpdate — account.php
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 Redux contributor
 *
 * Allows the logged-in admin to change their username and/or password without
 * manually editing blog-config.php.
 *
 * Security model:
 *  - Password change requires current password (password_verify()) before any write
 *  - New hashes/values are written to blog-config.php via preg_replace
 *  - addcslashes(..., '\\$') prevents bcrypt '$' from being misread as
 *    backreference markers by preg_replace
 *  - A fresh HMAC token is issued after either change so the session cookie
 *    remains valid (token = HMAC(username, storedHash))
 *  - Cookies: Secure=true, HttpOnly=true, SameSite=Strict
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

// ── shared cookie options ────────────────────────────────────────────────────
// Mirrors the flags set by FanUpdate::setAuthCookie().
// account.php issues cookies directly (outside the FanUpdate class) so it
// must replicate the options array rather than calling the private helper.

$authCookieOpts = static function (int $expire): array {
    return [
        'expires'  => $expire,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
};

// ── handle username change ───────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'change_username') {

    $clean = clean_input($_POST);

    $newUsername     = trim((string) ($clean['new_username']     ?? ''));
    $confirmUsername = trim((string) ($clean['confirm_username'] ?? ''));

    if ($newUsername === '') {
        $fu->addErr('Please enter a new username.');
    } elseif (strlen($newUsername) > 50) {
        $fu->addErr('Username must be 50 characters or fewer.');
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $newUsername)) {
        $fu->addErr('Username may only contain letters, numbers, and underscores.');
    }

    if ($newUsername !== '' && $newUsername !== $confirmUsername) {
        $fu->addErr('Usernames do not match.');
    }

    if ($fu->noErr()) {

        $configFile = __DIR__ . '/blog-config.php';

        if (!is_writable($configFile)) {
            $fu->addErr(
                '<strong>blog-config.php</strong> is not writable. '
                . 'Please <code>chmod 644</code> (or temporarily <code>chmod 666</code>) the file and try again, '
                . 'or update the username manually:<br>'
                . '<code>$fanupdate[\'admin_username\'] = \''
                . htmlspecialchars($newUsername, ENT_QUOTES, 'UTF-8')
                . '\';</code>'
            );
        } else {
            $configContent = (string) file_get_contents($configFile);

            // Matches:  $fanupdate['admin_username'] = '...';
            $newContent = preg_replace(
                "/(\\\$fanupdate\['admin_username'\]\s*=\s*)'[^']*'/",
                "\$1'" . addslashes($newUsername) . "'",
                $configContent
            );

            if ($newContent === null || file_put_contents($configFile, $newContent) === false) {
                $fu->addErr('Failed to write to <strong>blog-config.php</strong>. Username not changed.');
            } else {
                // Reissue cookie pair — token is HMAC(username, storedHash),
                // so changing the username key invalidates the old token.
                $fu->addOpt('admin_username', $newUsername);

                $storedHash = (string) $fu->getOpt('admin_password', true);
                $newToken   = hash_hmac('sha256', $newUsername, $storedHash);

                $remember = isset($_COOKIE['fu_username']);
                $expire   = $remember ? time() + 60 * 60 * 24 * 30 : 0;

                setcookie('fu_username', $newUsername, $authCookieOpts($expire));
                setcookie('fu_token',    $newToken,    $authCookieOpts($expire));

                $fu->addSuccess('Username changed successfully.');
            }
        }
    }
}

// ── handle password change ───────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'change_password') {

    $clean = clean_input($_POST);

    $currentPassword = $clean['current_password'] ?? '';
    $newPassword     = $clean['new_password']     ?? '';
    $confirmPassword = $clean['confirm_password'] ?? '';

    $storedHash = (string) $fu->getOpt('admin_password', true);

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
                // Reissue cookie pair — the HMAC token is keyed on the stored
                // hash, so it becomes stale the moment the hash changes.
                $fu->addOpt('admin_password', $newHash);

                $username = (string) $fu->getOpt('admin_username', true);
                $newToken = hash_hmac('sha256', $username, $newHash);

                $remember = isset($_COOKIE['fu_username']);
                $expire   = $remember ? time() + 60 * 60 * 24 * 30 : 0;

                setcookie('fu_username', $username, $authCookieOpts($expire));
                setcookie('fu_token',    $newToken,  $authCookieOpts($expire));

                $fu->addSuccess('Password changed successfully.');
            }
        }
    }
}

// ── render page ──────────────────────────────────────────────────────────────

$fu->getHeader('Account');

?>

<h2>Account</h2>

<?php

$fu->reportSuccess();
$fu->reportErrors();

?>

<div id="options">

<h3>Change Username</h3>

<form action="account.php" method="post">
    <input type="hidden" name="action" value="change_username" />

    <div class="option odd">
        <p>
            <label for="new_username">New username:
                <span class="help">Letters, numbers, and underscores only. Maximum 50 characters.</span>
            </label>
            <input type="text"
                   id="new_username"
                   name="new_username"
                   size="30"
                   maxlength="50"
                   autocomplete="username" />
        </p>
    </div>

    <div class="option even">
        <p>
            <label for="confirm_username">Confirm new username:
                <span class="help">Re-enter the new username.</span>
            </label>
            <input type="text"
                   id="confirm_username"
                   name="confirm_username"
                   size="30"
                   maxlength="50"
                   autocomplete="username" />
        </p>
    </div>

    <p style="margin-top: 1em;">
        <input type="submit"
               name="submit_username"
               value="Change Username"
               class="update" />
    </p>

</form>

<h3>Change Password</h3>

<form action="account.php" method="post">
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
