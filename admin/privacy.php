<?php
/*****************************************************************************
 * FanUpdate — admin/privacy.php
 *
 * Options subpage for the opt-in anonymous install ping (off by default):
 * enable or disable it, see exactly what is and isn't sent, the installation
 * ID and the latest outcome, and send a test ping
 * (InstallPingService::pingNow()).
 *
 * Independent of the Admin Update Manager; see InstallPingService.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../version.php');
require_once('../class/Repository/PrivacyRepositoryInterface.php');
require_once('../class/Repository/PrivacyRepository.php');
require_once('../class/Service/InstallPingService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

$privacyRepository = new PrivacyRepository($fu->db, (string) $fu->getOpt('options_table'));
$installPingService = new InstallPingService($privacyRepository, FU_VERSION, FU_INSTALL_PING_URL);

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

if ($action === 'update') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $enabled = !empty($_POST['privacy_ping_enabled']);

        if ($installPingService->setEnabled($enabled)) {
            $fu->addSuccess($enabled ? 'Anonymous install statistics enabled.' : 'Anonymous install statistics disabled.');

            // Sending the first ping immediately (rather than waiting for the
            // next opportunistic Dashboard load) gives instant feedback that
            // the feature actually works — maybePingIfDue() is always due
            // right after a fresh opt-in, since there is no prior
            // lastPingAt yet, so this doesn't bypass the schedule for any
            // later ping.
            if ($enabled) {
                $installPingService->maybePingIfDue();
            }
        } else {
            $fu->addErr('Failed to save privacy settings.');
        }
    }
} elseif ($action === 'send_test_ping') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $result = $installPingService->pingNow();

        if ($result['ok']) {
            $fu->addSuccess('Test ping sent successfully.');
        } else {
            $fu->addErr('Test ping failed: ' . htmlspecialchars((string) ($result['error'] ?? 'unknown error'), ENT_QUOTES, 'UTF-8'));
        }
    }
}

$status = $installPingService->getStatus();

// ── Helper: format an ISO 8601 timestamp for display ──────────────────────
// Page-local copy of admin/update.php's identical helper — that file is
// never require'd here (this page is independent of the Admin Update
// Manager, see class docblock), so its function isn't available to reuse.
function fu_privacy_format_date(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts !== false ? date('M j, Y g:i A', $ts) . ' UTC' : htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
}

$fu->getHeader('Privacy');

?>

<div class="page-header">
    <h2>Options: Privacy</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li><a href="options.php">Main</a></li>
        <li><a href="blacklist.php">Blacklist</a></li>
        <li><a href="smilies.php">Smilies</a></li>
        <li><a href="templates.php">Templates</a></li>
        <li><a href="collective-source.php">Collective Source</a></li>
        <li class="active"><a href="privacy.php">Privacy</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<form action="privacy.php" method="post">
    <?php echo $fu->csrfField(); ?>
    <input type="hidden" name="action" value="update" />

    <div class="option odd" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label class="checkbox" for="privacy_ping_enabled" style="font-weight:500; color:var(--fu-text);">
            <input type="checkbox" id="privacy_ping_enabled" name="privacy_ping_enabled" value="1"
                   <?php echo $status['enabled'] ? 'checked' : ''; ?> />
            Enable anonymous install statistics
        </label>
        <p class="hint small" style="margin-top:0.5rem;">
            Help improve FanUpdate Redux by anonymously reporting that this installation exists.
            Only a randomly generated installation ID, the FanUpdate Redux version, and the PHP
            version are sent — nothing else, no domain, site title, admin info, database info, or
            content. This gives a rough install count and a PHP-version breakdown, which is what
            tells the developer when it's actually safe to drop support for an old PHP version.
        </p>
        <p class="hint small" style="margin-top:0.5rem;">
            Completely independent of the Admin Update Manager (<a href="update.php">Admin &rarr; Updates</a>) —
            works the same whether or not automatic update checks are enabled. Sent to
            <code><?php echo htmlspecialchars(FU_INSTALL_PING_URL, ENT_QUOTES, 'UTF-8'); ?></code>
            approximately once every 30 days while enabled. See the
            <a href="<?php echo htmlspecialchars((string) $fu->getOpt('url'), ENT_QUOTES, 'UTF-8'); ?>">documentation</a>
            for the full list of what is and isn't collected.
        </p>
    </div>

    <p style="margin-top:1.5rem;">
        <button type="submit" name="action" value="update" class="update"
               title="Save privacy settings, accesskey s" accesskey="s">Update</button>
    </p>
</form>

<div class="page-header" style="margin-top:2rem;">
    <h2>Activity log</h2>
</div>

<p class="hint small">
    <a href="logs.php">Admin &rarr; Logs</a> keeps a local record of logins, lockouts and admin changes:
    event type, time, acting username, IP address and a short message. It never stores passwords, comment
    text, setting values, or the username typed into a failed login. Nothing is sent anywhere. Entries are
    deleted automatically after <code>log_retention_days</code> days, and IP addresses can be anonymised
    with <code>log_anonymize_ips</code> (both in <a href="options.php">Options &rarr; Main</a>).
</p>

<div class="page-header" style="margin-top:2rem;">
    <h2>Status</h2>
</div>

<fieldset style="margin-bottom:2rem;">
    <legend>Current status</legend>
    <ul>
        <li>
            Installation ID:
            <?php if ($status['installUuid'] !== null) { ?>
                <code><?php echo htmlspecialchars($status['installUuid'], ENT_QUOTES, 'UTF-8'); ?></code>
                <span class="hint small">(generated once; only ever used to avoid double-counting this installation)</span>
            <?php } else { ?>
                <span class="hint small">Not generated yet — created automatically the first time this is enabled.</span>
            <?php } ?>
        </li>
        <li>
            Last ping:
            <?php if ($status['lastPingAt'] === null) { ?>
                <span class="hint small">No ping has been sent yet.</span>
            <?php } elseif ($status['lastPingStatus'] === 'ok') { ?>
                <span class="badge badge-accent">Succeeded</span>
                <?php echo fu_privacy_format_date($status['lastPingAt']); ?>
            <?php } else { ?>
                <span class="badge badge-warning">Failed</span>
                <?php echo fu_privacy_format_date($status['lastPingAt']); ?>
            <?php } ?>
        </li>
        <?php if ($status['lastPingStatus'] === 'error' && $status['lastPingError'] !== null) { ?>
        <li class="hint small">Last error: <?php echo htmlspecialchars($status['lastPingError'], ENT_QUOTES, 'UTF-8'); ?></li>
        <?php } ?>
    </ul>

    <?php if ($status['enabled']) { ?>
    <form action="privacy.php" method="post" style="margin-top:1rem;">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="action" value="send_test_ping" />
        <input type="submit" class="update" value="Send a test ping now"
               title="Send a ping immediately, ignoring the usual ~30-day schedule" />
    </form>
    <?php } ?>
</fieldset>

<?php
$fu->getFooter();
