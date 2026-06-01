<?php
/*****************************************************************************
 * FanUpdate — admin/account.php
 * HTML modernisation:
 *  - admin_email input already type="email" — preserved
 *  - page-header row added
 *  - All PHP/SQL logic preserved exactly
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

// ─────────────────────────────────────────────────────────────────────────────
// POST handler — username, email, password
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action'])) {

    if ($_POST['action'] === 'update_username') {

        $new_username = isset($_POST['new_username']) ? clean_input($_POST['new_username']) : '';

        if (!preg_match('/^[A-Za-z0-9_]+$/', $new_username)) {
            $fu->addErr('Username may only contain letters, numbers, and underscores.');
        } elseif ($new_username === '') {
            $fu->addErr('Username cannot be empty.');
        } else {
            $fu->updateConfigKey('admin_username', $new_username);
            $fu->reissueToken($new_username);
            $fu->addSuccess('Username updated to <strong>' . htmlspecialchars($new_username, ENT_QUOTES, 'UTF-8') . '</strong>.');
        }

    } elseif ($_POST['action'] === 'update_email') {

        $new_email = isset($_POST['new_email']) ? clean_input($_POST['new_email']) : '';

        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $fu->addErr('Please enter a valid email address.');
        } else {
            $fu->db->Execute(
                "UPDATE " . $fu->getOpt('options_table') . "
                 SET optvalue='" . $fu->db->Escape($new_email) . "'
                 WHERE optkey='admin_email'"
            );
            $fu->addSuccess('Email updated to <strong>' . htmlspecialchars($new_email, ENT_QUOTES, 'UTF-8') . '</strong>.');
        }

    } elseif ($_POST['action'] === 'update_password') {

        $current_pw  = isset($_POST['current_password'])  ? $_POST['current_password']  : '';
        $new_pw      = isset($_POST['new_password'])      ? $_POST['new_password']      : '';
        $confirm_pw  = isset($_POST['confirm_password'])  ? $_POST['confirm_password']  : '';
        $stored_hash = (string) $fu->getOpt('admin_password', true);

        if (!password_verify($current_pw, $stored_hash)) {
            $fu->addErr('Current password is incorrect.');
        } elseif ($new_pw !== $confirm_pw) {
            $fu->addErr('New passwords do not match.');
        } elseif (mb_strlen($new_pw) < 8) {
            $fu->addErr('New password must be at least 8 characters.');
        } else {
            $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
            $fu->updateConfigKey('admin_password', $new_hash);
            $fu->reissueToken((string) $fu->getOpt('admin_username', true));
            $fu->addSuccess('Password updated.');
        }
    }
}

$current_email    = htmlspecialchars((string) $fu->getOpt('admin_email'), ENT_QUOTES, 'UTF-8');
$current_username = htmlspecialchars((string) $fu->getOpt('admin_username', true), ENT_QUOTES, 'UTF-8');

$fu->getHeader('Account');

?>

<div class="page-header">
    <h2>Account</h2>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<div class="col-layout-3">

    <!-- Change username -->
    <form action="account.php" method="post">
        <fieldset>
            <legend>Username</legend>
            <p class="hint small" style="margin-bottom:0.75rem;">
                Current: <strong><?php echo $current_username; ?></strong>
            </p>
            <p><label for="new_username">New username</label>
            <input type="text" id="new_username" name="new_username"
                   maxlength="50" autocomplete="username" /></p>
            <input type="submit" name="action" value="update_username" class="update"
                   title="Save username" />
        </fieldset>
    </form>

    <!-- Change email -->
    <form action="account.php" method="post">
        <fieldset>
            <legend>Email</legend>
            <p class="hint small" style="margin-bottom:0.75rem;">
                Current: <strong><?php echo $current_email; ?></strong>
            </p>
            <p><label for="new_email">New email</label>
            <input type="email" id="new_email" name="new_email"
                   maxlength="200" autocomplete="email" /></p>
            <input type="submit" name="action" value="update_email" class="update"
                   title="Save email" />
        </fieldset>
    </form>

    <!-- Change password -->
    <form action="account.php" method="post">
        <fieldset>
            <legend>Password</legend>
            <p><label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   autocomplete="current-password" /></p>
            <p><label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password"
                   autocomplete="new-password" /></p>
            <p><label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   autocomplete="new-password" /></p>
            <input type="submit" name="action" value="update_password" class="update"
                   title="Save password" />
        </fieldset>
    </form>

</div>

<?php
$fu->getFooter();
