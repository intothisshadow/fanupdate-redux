<?php
declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — reset-password.php
 *
 * Copyright (c) 2026 by Ariane (contributor)
 *
 * FP-22 — Emergency admin password reset. Run this directly in a browser — no login
 * required — when the admin password is lost and admin/account.php (which
 * requires the current password to change it) is out of reach.
 *
 * Trust model: identical to install.php. Reading blog-config.php's database
 * credentials to connect and writing a new admin_password hash IS the
 * authentication here — anyone who can reach this file already has
 * filesystem access next to blog-config.php (FTP/file manager), which means
 * full control of the installation regardless. There is no separate
 * identity check to perform. The IP rate limiter below (same mechanism as
 * the login form, separate lockout store) only slows down someone probing
 * this file's URL who does NOT have that access.
 *
 * Pre-existing-row vs. no-row: admin_username/admin_password are options
 * table rows (see AccountRepository) on every install built after admin
 * credentials moved into the database (see docs/CHANGELOG.md's "Admin
 * credentials moved from blog-config.php into the database" entry) — the
 * normal case, handled with a plain UPDATE via AccountRepository, exactly
 * like admin/account.php's own password change. An install that predates
 * that change may never have had those rows written at all (credentials
 * used to live directly in blog-config.php, which FanUpdate::login() no
 * longer reads). An UPDATE against a row that doesn't exist matches zero
 * rows and returns success with nothing actually changed — so that case is
 * detected explicitly up front and handled with an INSERT of both rows
 * instead, asking for a username as well as a password.
 *
 * Like install.php, this file deletes itself after a successful reset so it
 * does not sit on the server as a standing, unauthenticated way to overwrite
 * the admin password.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

// ── FORCE ERRORS VISIBLE ─────────────────────────────────────────────────────
// Same reasoning as install.php: shared hosts often ship display_errors=Off,
// which would otherwise turn any failure here into a blank white page.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// ─────────────────────────────────────────────────────────────────────────────

require_once('blog-config.php');
require_once('functions.php');
require_once('class/Repository/AccountRepositoryInterface.php');
require_once('class/Repository/AccountRepository.php');

$fu = FanUpdate::instance();
$fu->db->Connect();
$fu->addOptFromDb();

$optionsTable = (string) $fu->getOpt('options_table');
$accountRepo  = new AccountRepository($fu, $fu->db, $optionsTable);

// Separate lockout store from the login form's own (data/login_attempts) —
// this is a distinct unauthenticated surface with its own attempt history.
$rateLimiter = new LoginRateLimiter(__DIR__ . '/data/reset_password_attempts');

// See the class docblock above: a query, not getOpt(), so an existing row
// with an empty optvalue (which addOptFromDb() silently skips loading into
// memory) still correctly counts as "exists" here.
$hasAdminPasswordRow = (bool) $fu->db->GetFirstCell(
    'SELECT COUNT(*) FROM ' . $optionsTable . " WHERE optkey = 'admin_password'"
);

$currentUsername = $hasAdminPasswordRow ? $accountRepo->getAdminUsername() : '';
$showform         = true;

if (isset($_POST['reset_password'])) {

    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued; fall through to
        // render the form again.
    } elseif ($rateLimiter->isLockedOut()) {
        $fu->addErr(
            'Too many attempts. Please try again in '
            . $rateLimiter->humanTimeRemaining() . '.'
        );
    } else {
        $new_pw       = $_POST['new_password']     ?? '';
        $confirm_pw   = $_POST['confirm_password'] ?? '';
        $new_username = $hasAdminPasswordRow
            ? $currentUsername
            : clean_input($_POST['new_username'] ?? '');

        if (!$hasAdminPasswordRow && !preg_match('/^[A-Za-z0-9_]+$/', (string) $new_username)) {
            $rateLimiter->recordFailure();
            $fu->addErr('Username may only contain letters, numbers, and underscores.');
        } elseif (mb_strlen((string) $new_pw) < 8) {
            $rateLimiter->recordFailure();
            $fu->addErr('New password must be at least 8 characters.');
        } elseif ($new_pw !== $confirm_pw) {
            $rateLimiter->recordFailure();
            $fu->addErr('New passwords do not match.');
        } else {
            $hash = password_hash((string) $new_pw, PASSWORD_BCRYPT);

            if ($hasAdminPasswordRow) {
                $ok = $accountRepo->updatePasswordHash($hash);
            } else {
                $insertQuery = 'INSERT INTO ' . $optionsTable . ' SET optkey = ?, optvalue = ?, optdesc = ?';
                $ok = $fu->db->Execute($insertQuery, 'Could not save admin username.', [
                    'admin_username',
                    $new_username,
                    'Your admin login username. Editable from Admin -> Account.',
                ]) && $fu->db->Execute($insertQuery, 'Could not save admin password.', [
                    'admin_password',
                    $hash,
                    'Your admin login password (bcrypt hash). Editable from Admin -> Account.',
                ]);
            }

            if ($ok) {
                $fu->logEvent(LogService::PASSWORD_RESET, 'Admin password reset via reset-password.php.', '');
                $rateLimiter->recordSuccess();
                $currentUsername = (string) $new_username;
                $showform        = false;
            } else {
                $rateLimiter->recordFailure();
                $fu->addErr('Could not update the password. Check your database connection and try again.');
            }
        }
    }
}

$fu->getHeader('Reset Password');
?>

<div class="page-header">
    <h2>Reset Admin Password</h2>
</div>

<div class="error" style="display:flow-root;">
    <p style="margin-bottom:0;">
        <strong>Security notice:</strong> this page resets the admin password for
        whichever database <code>blog-config.php</code> points at, with no login
        required — the same trust model as <code>install.php</code>. Reaching this
        file at all already means you have full server access.
        <strong>Delete this file as soon as you're done.</strong>
    </p>
</div>

<?php if (!$hasAdminPasswordRow && $showform) { ?>
<div class="error" style="display:flow-root;">
    <p style="margin-bottom:0;">
        <strong>No admin_username/admin_password found in the database.</strong>
        This installation predates admin credentials moving into the database —
        they may still be set in <code>blog-config.php</code>, but the login form
        no longer reads that. Choose a username and password below to create
        fresh credentials.
    </p>
</div>
<?php } ?>

<?php if (!$showform) { ?>

    <p class="success">
        Password for <strong><?php echo htmlspecialchars($currentUsername, ENT_QUOTES, 'UTF-8'); ?></strong>
        has been reset.
    </p>

    <?php
    $resetFile = __FILE__;
    if (@unlink($resetFile)) {
        echo '<p class="success"><strong>reset-password.php has been automatically deleted</strong> from your server.</p>' . "\n";
    } else {
        echo '<p class="error"><strong>Could not auto-delete reset-password.php</strong> — please delete it from your server manually right now.</p>' . "\n";
    }
    ?>

    <p><a href="admin/index.php">Log in with your new password.</a></p>

<?php } else { ?>

    <?php if ($hasAdminPasswordRow) { ?>
        <p>Current admin username: <strong><?php echo htmlspecialchars((string) $currentUsername, ENT_QUOTES, 'UTF-8'); ?></strong></p>
    <?php } ?>

    <?php $fu->reportErrors(); ?>

    <form action="reset-password.php" method="post">
        <?php echo $fu->csrfField(); ?>
        <?php if (!$hasAdminPasswordRow) { ?>
        <p><label for="new_username">Admin username</label>
        <input type="text" id="new_username" name="new_username" maxlength="50"
               autocomplete="username" value="admin" /></p>
        <?php } ?>
        <p><label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password"
               autocomplete="new-password" /></p>
        <p><label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               autocomplete="new-password" /></p>
        <p><input type="submit" name="reset_password" value="Reset Password" class="update"
               title="Reset password" /></p>
    </form>

<?php } ?>

<?php
$fu->getFooter(false);
