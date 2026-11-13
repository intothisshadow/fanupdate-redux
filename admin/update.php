<?php
/*****************************************************************************
 * FanUpdate — admin/update.php
 *
 * Admin Update Manager: checks GitHub for the newest release on the selected
 * channel, downloads it, manages backups, installs a downloaded or uploaded
 * release ZIP (with automatic rollback on failure), restores backups, runs
 * pending database updates on demand (for installs updated by hand over
 * FTP), and shows a System Status panel. Everything runs in PHP, with no
 * shell commands.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../version.php');
require_once('../class/Update/ReleaseInfo.php');
require_once('../class/Update/DownloadResult.php');
require_once('../class/Update/BackupResult.php');
require_once('../class/Update/InstallResult.php');
require_once('../class/Update/RollbackResult.php');
require_once('../class/Update/SystemStatusCheck.php');
require_once('../class/Update/UpgradeResult.php');
require_once('../class/Update/UpdateProviderInterface.php');
require_once('../class/Update/GitHubUpdateProvider.php');
require_once('../class/Repository/UpdateRepositoryInterface.php');
require_once('../class/Repository/UpdateRepository.php');
require_once('../class/Service/DownloadManager.php');
require_once('../class/Service/BackupManager.php');
require_once('../class/Service/InstallManager.php');
require_once('../class/Service/RollbackManager.php');
require_once('../class/Service/SystemStatusChecker.php');
require_once('../class/Service/UpdateManager.php');
require_once('../class/Parsedown.php');
require_once('../class/Service/ReleaseNotesRenderer.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

$updateRepository = new UpdateRepository($fu->db, (string) $fu->getOpt('options_table'));

$config      = $updateRepository->getConfig();
$githubToken = (!empty($config['githubToken']) && is_string($config['githubToken'])) ? $config['githubToken'] : null;

$provider = new GitHubUpdateProvider(FU_UPDATE_PROVIDER_OWNER, FU_UPDATE_PROVIDER_REPO, $githubToken);

// ── download temp directory ──────────────────────────────────────────────
// Holds staged release archives, so it is fully denied to web requests.
$install_path_opt = $fu->getOpt('install_path');
$install_path      = ($install_path_opt !== false && (string) $install_path_opt !== '')
    ? rtrim((string) $install_path_opt, '/')
    : dirname(__DIR__);
$installScriptStillHere = fu_install_script_exists($install_path);
$update_tmp_dir = $install_path . '/fu-update-tmp';

if (!is_dir($update_tmp_dir)) {
    @mkdir($update_tmp_dir, 0755, true);
}
if (is_dir($update_tmp_dir)) {
    $tmp_htaccess = $update_tmp_dir . '/.htaccess';
    if (!file_exists($tmp_htaccess)) {
        $htaccess_body = "# FanUpdate — update download staging directory\n"
            . "# Never web-servable: holds release archives being staged for install.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
        @file_put_contents($tmp_htaccess, $htaccess_body);
    }
    @file_put_contents($update_tmp_dir . '/index.html', '');
}

$downloadManager = new DownloadManager($update_tmp_dir);

// ── backups directory ────────────────────────────────────────────────────
// Contains copies of blog-config.php (DB credentials): fully denied like the temp directory.
$backup_dir = $install_path . '/fu-backups';

if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
}
if (is_dir($backup_dir)) {
    $backup_htaccess = $backup_dir . '/.htaccess';
    if (!file_exists($backup_htaccess)) {
        $htaccess_body = "# FanUpdate — installation backups\n"
            . "# Never web-servable: contains full copies of blog-config.php.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
        @file_put_contents($backup_htaccess, $htaccess_body);
    }
    @file_put_contents($backup_dir . '/index.html', '');
}

$backupManager = new BackupManager($install_path, $backup_dir);

$installManager = new InstallManager(
    $install_path,
    $update_tmp_dir . '/extract',
    $install_path . '/.fu-maintenance'
);

$rollbackManager = new RollbackManager(
    $install_path,
    $update_tmp_dir . '/rollback',
    $install_path . '/.fu-maintenance'
);

$systemStatusChecker = new SystemStatusChecker($install_path, $update_tmp_dir);

// Shared with admin/header.php's pending-upgrades banner.
$upgradeRunner = fu_build_upgrade_runner($fu);

$updateManager = new UpdateManager($provider, $updateRepository, FU_VERSION, $downloadManager, $backupManager, $installManager, $rollbackManager);

// ── POST handlers ────────────────────────────────────────────────────────

if (($_GET['action'] ?? '') === 'run-upgrades') {
    // Reached via redirect after an install: a fresh request, so UpgradeRunner is
    // the just-installed release's copy.
    if (isset($_GET['installed_version'])) {
        $removedFiles = (int) ($_GET['removed_files'] ?? 0);
        $fu->addSuccess(
            'Update installed: version ' . htmlspecialchars((string) $_GET['installed_version'], ENT_QUOTES, 'UTF-8')
            . (isset($_GET['installed_files']) ? ' (' . (int) $_GET['installed_files'] . ' files)' : '') . '.'
            . ($removedFiles > 0 ? ' Removed ' . $removedFiles . ' obsolete file(s) left over from the previous version.' : '')
            . (($_GET['install_script_removed'] ?? '0') === '1' ? ' Deleted install.php — the release shipped one, but this flow never runs it, so it never gets the chance to delete itself the way it does after a manual install.' : '')
        );
    }

    $upgradeOutcome = $updateManager->runPendingUpgrades($upgradeRunner, FU_VERSION);

    if (!$upgradeOutcome['ok']) {
        $fu->addErr('Database upgrade failed: ' . htmlspecialchars((string) $upgradeOutcome['error'], ENT_QUOTES, 'UTF-8'));
    } elseif (empty($upgradeOutcome['messages'])) {
        $fu->addSuccess('Database is already up to date — no post-install migrations were needed.');
    } else {
        foreach ($upgradeOutcome['messages'] as $upgradeMessage) {
            $fu->addSuccess(htmlspecialchars($upgradeMessage, ENT_QUOTES, 'UTF-8'));
        }
    }
}

$action = $_POST['action'] ?? '';

// Streams the file and exits; on any failure falls through to render the page with an error.
if ($action === 'download_backup' && $fu->verifyCsrfOrFail()) {
    $backupFile = $backupManager->locateBackup((string) ($_POST['backup_file'] ?? ''));

    if ($backupFile === null) {
        $fu->addErr('That backup could not be found.');
    } else {
        $fu->logEvent(LogService::UPDATE_ACTION, 'Backup downloaded: ' . basename($backupFile) . '.');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $backupManager->downloadFileName(basename($backupFile)) . '"');
        header('Content-Length: ' . (string) filesize($backupFile));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($backupFile);
        exit;
    }
}

if ($action === 'check') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $result = $updateManager->checkForUpdates();

        if ($result['status'] === 'ok') {
            $fu->addSuccess(
                $result['updateAvailable']
                    ? 'Update check complete — a new version is available.'
                    : 'Update check complete — you are up to date.'
            );
        } else {
            $fu->addErr('Update check failed: ' . htmlspecialchars((string) $result['lastError'], ENT_QUOTES, 'UTF-8'));
        }
    }
} elseif ($action === 'save_settings') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $channel        = ($_POST['channel'] ?? 'stable') === 'prerelease' ? 'prerelease' : 'stable';
        $token          = trim((string) clean_input($_POST['github_token'] ?? ''));
        $clearToken     = !empty($_POST['clear_github_token']);
        $autoCheckOn    = !empty($_POST['auto_check_enabled']);
        $checkFrequency = (int) ($_POST['check_frequency_hours'] ?? 24);

        $updateManager->setChannel($channel);
        $updateManager->setAutoCheckPreferences($autoCheckOn, $checkFrequency);

        $config = $updateRepository->getConfig();
        if ($clearToken) {
            unset($config['githubToken']);
        } elseif ($token !== '') {
            // A blank field leaves the saved token unchanged.
            $config['githubToken'] = $token;
        }

        if ($updateRepository->saveConfig($config)) {
            $fu->addSuccess('Update settings saved.');
        } else {
            $fu->addErr('Failed to save update settings.');
        }
    }
} elseif ($action === 'download') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $result = $updateManager->downloadLatestRelease();

        if ($result['ok']) {
            $fu->logEvent(LogService::UPDATE_ACTION, 'Release downloaded and verified.');
            $fu->addSuccess(
                'Release downloaded and verified (' . fu_update_format_bytes((int) $result['size']) . ').'
                . (($result['checksumVerified'] ?? false)
                    ? ' Checksum verified against the published release.'
                    : ' No checksum was published for this release to verify against.')
            );
        } else {
            $fu->addErr('Download failed: ' . htmlspecialchars((string) $result['error'], ENT_QUOTES, 'UTF-8'));
        }
    }
} elseif ($action === 'backup') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        try {
            $backupResult = $backupManager->createBackup((new VersionNormalizer())->normalize(FU_VERSION));
            $fu->addSuccess(
                'Backup created and verified (' . fu_update_format_bytes($backupResult->size)
                . ', ' . $backupResult->fileCount . ' files).'
            );
        } catch (\Throwable $e) {
            $fu->addErr('Backup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
} elseif ($action === 'install') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $result = $updateManager->installLatestRelease();

        if ($result['ok']) {
            $fu->logEvent(LogService::UPDATE_ACTION, 'Installed update: ' . (string) $result['version'] . '.');
            // Redirect rather than render: PHP can't hot-reload the classes already
            // loaded, so a fresh request is needed to use the newly copied files.
            header(
                'Location: update.php?action=run-upgrades'
                . '&installed_version=' . rawurlencode((string) $result['version'])
                . '&installed_files=' . (int) $result['fileCount']
                . '&removed_files=' . (int) ($result['removedFileCount'] ?? 0)
                . '&install_script_removed=' . ((!empty($result['installScriptRemoved'])) ? '1' : '0')
            );
            exit;
        }

        $fu->addErr('Install failed: ' . htmlspecialchars((string) $result['error'], ENT_QUOTES, 'UTF-8'));
    }
} elseif ($action === 'upload_install') {
    // Install an admin-uploaded release ZIP (for servers that can't reach GitHub).
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } elseif (!isset($_FILES['update_zip']) || $_FILES['update_zip']['error'] === UPLOAD_ERR_NO_FILE) {
        $fu->addErr('Please choose a ZIP file to upload.');
    } elseif ($_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
        $upload_err_map = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded file is larger than this server allows (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded file is larger than this server allows.',
            UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder configured for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A server extension stopped the file upload.',
        ];
        $fu->addErr($upload_err_map[$_FILES['update_zip']['error']] ?? 'The file upload failed. Please try again.');
    } elseif (!is_uploaded_file($_FILES['update_zip']['tmp_name'])) {
        $fu->addErr('The file upload failed. Please try again.');
    } else {
        $result = $updateManager->installUploadedRelease($_FILES['update_zip']['tmp_name']);

        if ($result['ok']) {
            $fu->logEvent(LogService::UPDATE_ACTION, 'Installed uploaded release: ' . (string) $result['version'] . '.');
            // Fresh request so the DB migration runs against the new files.
            header(
                'Location: update.php?action=run-upgrades'
                . '&installed_version=' . rawurlencode((string) $result['version'])
                . '&installed_files=' . (int) $result['fileCount']
                . '&removed_files=' . (int) ($result['removedFileCount'] ?? 0)
                . '&install_script_removed=' . ((!empty($result['installScriptRemoved'])) ? '1' : '0')
            );
            exit;
        }

        $fu->addErr('Install from uploaded ZIP failed: ' . htmlspecialchars((string) $result['error'], ENT_QUOTES, 'UTF-8'));
    }
} elseif ($action === 'disable_maintenance') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $installManager->disableMaintenanceMode();
        $fu->addSuccess('Maintenance mode disabled.');
    }
} elseif ($action === 'run_db_upgrade') {
    // For installs updated by hand; the Install flow runs this via the run-upgrades redirect.
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $upgradeOutcome = $updateManager->runPendingUpgrades($upgradeRunner, FU_VERSION);

        if (!$upgradeOutcome['ok']) {
            $fu->addErr('Database upgrade failed: ' . htmlspecialchars((string) $upgradeOutcome['error'], ENT_QUOTES, 'UTF-8'));
        } elseif (empty($upgradeOutcome['messages'])) {
            $fu->addSuccess('Database is already up to date — no pending migrations were needed.');
        } else {
            $fu->logEvent(LogService::UPDATE_ACTION, 'Database upgrade run (' . count($upgradeOutcome['messages']) . ' change(s)).');
            foreach ($upgradeOutcome['messages'] as $upgradeMessage) {
                $fu->addSuccess(htmlspecialchars($upgradeMessage, ENT_QUOTES, 'UTF-8'));
            }
        }
    }
} elseif ($action === 'rollback') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $backupPath    = (string) ($_POST['backup_path'] ?? '');
        $backupVersion = (string) ($_POST['backup_version'] ?? '');
        $result        = $updateManager->restoreBackup($backupPath, $backupVersion);

        if ($result['ok']) {
            $fu->logEvent(LogService::UPDATE_ACTION, 'Rolled back to version ' . (string) $result['version'] . '.');
            $fu->addSuccess(
                'Restored version' . htmlspecialchars((string) $result['version'], ENT_QUOTES, 'UTF-8')
                . ' (' . $result['fileCount'] . ' files) from backup. Refresh this page to see it reflected everywhere.'
            );
        } else {
            $fu->addErr('Restore failed: ' . htmlspecialchars((string) $result['error'], ENT_QUOTES, 'UTF-8'));
        }
    }
} elseif ($action === 'delete_backup') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $backupManager->deleteBackup((string) ($_POST['backup_path'] ?? ''));
        $fu->addSuccess('Backup deleted.');
    }
} elseif ($action === 'clear_log') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $updateManager->clearLog();
        $fu->addSuccess('Update log cleared.');
    }
} elseif ($action === 'dismiss') {
    if (!$fu->verifyCsrfOrFail()) {
        // Invalid/missing CSRF token — error already queued.
    } else {
        $updateManager->dismissNotice();
    }
    header('Location: index.php');
    exit;
}

$summary = $updateManager->getStatusSummary();
$backups = $backupManager->listBackups();

// ── Helper: format an ISO 8601 timestamp for display ──────────────────────
function fu_update_format_date(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts !== false ? date('M j, Y g:i A', $ts) . ' UTC' : htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
}

// ── Helper: human-readable byte size ───────────────────────────────────────
function fu_update_format_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '—';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i     = 0;
    $size  = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

// ── Helper: status badge ────────────────────────────────────────────────────
function fu_update_status_badge(array $summary): string
{
    if ($summary['status'] === 'error') {
        return '<span class="badge badge-danger">Check failed</span>';
    }
    if ($summary['status'] === 'never') {
        return '<span class="badge badge-muted">Never checked</span>';
    }
    if ($summary['updateAvailable']) {
        return '<span class="badge badge-accent">Update available</span>';
    }
    return '<span class="badge badge-success">Up to date</span>';
}

// ── Helper: System Status check badge ──────────────────────────────
function fu_update_check_badge(string $status): string
{
    return match ($status) {
        'error'   => '<span class="badge badge-danger">Error</span>',
        'warning' => '<span class="badge badge-warning">Warning</span>',
        default   => '<span class="badge badge-success">Pass</span>',
    };
}

// ── Install confirmation view (GET, read-only) ─────────────────────────────
// Reached via a link; warns about backups/maintenance mode before the "install" POST form.
if (($_GET['action'] ?? '') === 'install-progress') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($updateManager->getProgress() ?? ['message' => null], JSON_THROW_ON_ERROR);
    exit;
}

if (($_GET['action'] ?? '') === 'download-log') {
    $logJson = json_encode($updateManager->getRecentLogEntries(PHP_INT_MAX), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="fanupdate-update-log-' . gmdate('Y-m-d') . '.json"');
    header('Content-Length: ' . strlen($logJson));
    echo $logJson;
    exit;
}

if (($_GET['action'] ?? '') === 'confirm-install') {
    if (!$summary['downloadReady'] || !$summary['updateAvailable']) {
        header('Location: update.php');
        exit;
    }

    $fu->getHeader('Confirm Install');
    ?>
    <div class="page-header">
        <h2>Confirm installation</h2>
    </div>

    <fieldset style="margin-bottom:2rem;">
        <legend>Installation summary</legend>
        <ul>
            <li>Current version: <strong><?php echo htmlspecialchars($summary['installedVersion'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <li>Target version: <strong><?php echo htmlspecialchars((string) $summary['latestVersion'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <li>Release date: <?php echo fu_update_format_date($summary['latestPublishedAt']); ?></li>
            <li>Update size: <?php echo fu_update_format_bytes($summary['downloadedSize']); ?></li>
            <li>Checksum: <?php echo $summary['downloadedChecksumVerified'] ? 'verified against the published release' : 'not published for this release (size and ZIP integrity were still verified)'; ?></li>
        </ul>

        <p class="error" style="margin-top:1rem;">
            A backup of the current installation will be created automatically before installing —
            if the backup fails, the install is aborted. Comment submissions will be temporarily
            unavailable while files are being copied (usually a few seconds). Any file this tool
            installed previously that the new release no longer ships will be removed — files you
            added yourself are never touched, only ones this tool is tracking from its own past
            installs. If the install itself fails partway through, that backup is automatically
            restored before you see the error; see the Backups section below for a manual restore
            option too.
        </p>

        <form action="update.php" method="post" id="fu-install-form" style="margin-top:1rem;"
              data-fu-progress data-panel="fu-progress-panel" data-message="fu-progress-message"
              data-bar="fu-progress-bar" data-busy-label="Installing…">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="action" value="install" />
            <input type="submit" class="update" value="Install now" />
        </form>
        <p style="margin-top:0.5rem;"><a href="update.php">Cancel</a></p>

        <div id="fu-progress-panel" style="display:none; margin-top:1.5rem;">
            <p id="fu-progress-message" class="hint small">Starting install…</p>
            <div style="background:var(--fu-border); border-radius:var(--fu-radius); overflow:hidden; height:0.75rem;">
                <div id="fu-progress-bar" style="background:var(--fu-accent, #4a90d9); height:100%; width:0%; transition:width 0.4s ease;"></div>
            </div>
            <p class="hint small" style="margin-top:0.5rem;">This page will refresh automatically once the install finishes.</p>
        </div>
        <script src="../assets/js/fanupdate-progress.js"></script>
    </fieldset>
    <?php
    $fu->getFooter();
    exit;
}

// ── Rollback confirmation view (GET, read-only) ────────────────────────
// Reached via a link; confirms which backup will be restored before the POST form.
if (($_GET['action'] ?? '') === 'confirm-rollback') {
    $targetPath = (string) ($_GET['backup_path'] ?? '');
    $target     = null;

    foreach ($backups as $backup) {
        if ($backup->filePath === $targetPath) {
            $target = $backup;
            break;
        }
    }

    if ($target === null) {
        header('Location: update.php');
        exit;
    }

    $fu->getHeader('Confirm Restore');
    ?>
    <div class="page-header">
        <h2>Confirm restore</h2>
    </div>

    <fieldset style="margin-bottom:2rem;">
        <legend>Restore summary</legend>
        <ul>
            <li>Current version: <strong><?php echo htmlspecialchars($summary['installedVersion'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <li>Backup version: <strong><?php echo htmlspecialchars($target->version, ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <li>Backup created: <?php echo fu_update_format_date($target->createdAt); ?></li>
            <li>Backup size: <?php echo fu_update_format_bytes($target->size); ?></li>
        </ul>

        <p class="error" style="margin-top:1rem;">
            This overwrites the application's code and configuration with what's in this backup —
            any files changed since it was taken (including a more recent install) will be lost.
            Your uploaded images and <code>blog-config.php</code> are never touched. Comment
            submissions will be temporarily unavailable while files are being restored.
        </p>

        <form action="update.php" method="post" id="fu-rollback-form" style="margin-top:1rem;"
              data-fu-progress data-panel="fu-progress-panel" data-message="fu-progress-message"
              data-bar="fu-progress-bar" data-busy-label="Restoring…">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="action" value="rollback" />
            <input type="hidden" name="backup_path" value="<?php echo htmlspecialchars($target->filePath, ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="hidden" name="backup_version" value="<?php echo htmlspecialchars($target->version, ENT_QUOTES, 'UTF-8'); ?>" />
            <input type="submit" class="delete" value="Restore this backup" />
        </form>
        <p style="margin-top:0.5rem;"><a href="update.php">Cancel</a></p>

        <div id="fu-progress-panel" style="display:none; margin-top:1.5rem;">
            <p id="fu-progress-message" class="hint small">Starting restore…</p>
            <div style="background:var(--fu-border); border-radius:var(--fu-radius); overflow:hidden; height:0.75rem;">
                <div id="fu-progress-bar" style="background:var(--fu-accent, #4a90d9); height:100%; width:0%; transition:width 0.4s ease;"></div>
            </div>
            <p class="hint small" style="margin-top:0.5rem;">This page will refresh automatically once the restore finishes.</p>
        </div>
        <script src="../assets/js/fanupdate-progress.js"></script>
    </fieldset>
    <?php
    $fu->getFooter();
    exit;
}

$fu->getHeader('Updates');

?>

<div class="page-header">
    <h2>Updates</h2>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<?php if ($installScriptStillHere) { ?>
<fieldset id="install-php-notice" style="margin-bottom:1.5rem;">
    <legend>Security notice</legend>
    <p class="error" style="margin-bottom:0;">
        <strong><code>install.php</code> is still present on your server</strong> at
        <code><?php echo htmlspecialchars($install_path, ENT_QUOTES, 'UTF-8'); ?>/install.php</code>.
        It normally deletes itself automatically after a successful install or upgrade, but that
        can fail silently (e.g. if the directory isn't writable). Left in place, anyone who finds
        the URL can attempt to run it against this site. Please delete it from your server as soon
        as possible — it isn't needed for normal operation.
    </p>
</fieldset>
<?php } ?>

<?php if ($summary['maintenanceModeOn']) { ?>
    <p class="error">
        Maintenance mode is currently enabled — comment submissions are being rejected. This is
        normally cleared automatically at the end of an install; if it's still on and no install is
        running, an earlier one may have crashed before it could clean up.
    </p>
    <form action="update.php" method="post" style="margin-bottom:1.5rem;">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="action" value="disable_maintenance" />
        <input type="submit" class="delete" value="Disable maintenance mode" data-skip-confirm />
    </form>
    <?php
}

// ── Database Updates ──
// Hidden when nothing is pending. Lets manually updated installs run migrations,
// since install.php deletes itself after running.
if ($upgradeRunner->hasPendingUpgrades(FU_VERSION)) {
    ?>
    <div class="page-header" id="database-updates">
        <h2>Database updates</h2>
    </div>

    <fieldset style="margin-bottom:2rem;">
        <legend>Pending database changes</legend>
        <p class="error" style="margin-bottom:1rem;">
            This installation's database needs updates that haven't run yet — for example if you
            updated files manually (a zip upload) instead of through the Install flow below, which
            runs this automatically. Nothing else about your site changes until you run this.
        </p>

        <form action="update.php" method="post" id="fu-db-upgrade-form"
              data-fu-progress data-panel="fu-db-progress-panel" data-message="fu-db-progress-message"
              data-bar="fu-db-progress-bar" data-busy-label="Running…">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="action" value="run_db_upgrade" />
            <input type="submit" class="update" value="Run pending database updates" data-skip-confirm />
        </form>

        <div id="fu-db-progress-panel" style="display:none; margin-top:1.5rem;">
            <p id="fu-db-progress-message" class="hint small">Starting…</p>
            <div style="background:var(--fu-border); border-radius:var(--fu-radius); overflow:hidden; height:0.75rem;">
                <div id="fu-db-progress-bar" style="background:var(--fu-accent, #4a90d9); height:100%; width:0%; transition:width 0.4s ease;"></div>
            </div>
            <p class="hint small" style="margin-top:0.5rem;">This page will refresh automatically once it finishes.</p>
        </div>
    </fieldset>
    <?php
}
?>

<!-- ── Overview: installed version / status / source ────────────────────── -->

<div class="dash-grid" style="margin-bottom:2rem;">

    <section>
        <h3>Installed version</h3>
        <ul>
            <li>FanUpdate <strong><?php echo htmlspecialchars(FU_VERSION, ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <li>Released <?php echo htmlspecialchars(FU_RELEASE_DATE, ENT_QUOTES, 'UTF-8'); ?>
                <?php if (FU_RELEASE_NAME !== '') { ?>&middot; <?php echo htmlspecialchars(FU_RELEASE_NAME, ENT_QUOTES, 'UTF-8'); ?><?php } ?>
            </li>
            <li>Database schema <?php echo htmlspecialchars(FU_DB_VERSION, ENT_QUOTES, 'UTF-8'); ?></li>
            <li>Installed at <span class="hint small"><?php echo htmlspecialchars((string) $fu->getOpt('install_path', true), ENT_QUOTES, 'UTF-8'); ?></span></li>
        </ul>
    </section>

    <section>
        <h3>Update status</h3>
        <ul>
            <li><?php echo fu_update_status_badge($summary); ?></li>
            <li>Latest known version:
                <strong><?php echo htmlspecialchars($summary['latestVersion'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong>
            </li>
            <li>Channel: <?php echo $summary['channel'] === 'prerelease' ? 'Prerelease' : 'Stable'; ?></li>
            <li>Last checked: <?php echo fu_update_format_date($summary['lastChecked']); ?></li>
            <?php if ($summary['lastInstall']) { ?>
            <li>Last install: version <?php echo htmlspecialchars((string) $summary['lastInstall']['version'], ENT_QUOTES, 'UTF-8'); ?>
                at <?php echo fu_update_format_date($summary['lastInstall']['installedAt']); ?>
                <?php if (!empty($summary['lastInstall']['removedFileCount'])) { ?>
                    &middot; <?php echo (int) $summary['lastInstall']['removedFileCount']; ?> obsolete file(s) removed
                <?php } ?>
                <?php if (!empty($summary['lastInstall']['installScriptRemoved'])) { ?>
                    &middot; install.php deleted
                <?php } ?>
            </li>
            <?php } ?>
            <?php if ($summary['lastRollback']) { ?>
            <li>Last restore: version <?php echo htmlspecialchars((string) $summary['lastRollback']['version'], ENT_QUOTES, 'UTF-8'); ?>
                at <?php echo fu_update_format_date($summary['lastRollback']['restoredAt']); ?></li>
            <?php } ?>
        </ul>
    </section>

    <section>
        <h3>Update source</h3>
        <ul>
            <li>Provider: <?php echo htmlspecialchars($updateManager->getProviderName(), ENT_QUOTES, 'UTF-8'); ?></li>
            <li>Repository: <a href="https://github.com/<?php echo htmlspecialchars($updateManager->getSourceLabel(), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($updateManager->getSourceLabel(), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <li>Releases: <a href="https://github.com/<?php echo htmlspecialchars($updateManager->getSourceLabel(), ENT_QUOTES, 'UTF-8'); ?>/releases" target="_blank" rel="noopener">View all releases</a></li>
        </ul>
    </section>

</div><!-- END .dash-grid -->

<!-- ── Manual check ──────────────────────────────────────────────────────── -->

<form action="update.php" method="post" style="margin-bottom:2rem;">
    <?php echo $fu->csrfField(); ?>
    <button type="submit" name="action" value="check" class="update" title="Check GitHub for a newer release, accesskey s" accesskey="s">Check</button>
</form>

<!-- ── Install from Uploaded ZIP ─────────────────────────────────── -->
<!-- Always shown: the server may not reach GitHub, or the build may be unpublished.
     Uses the same backup/install/rollback pipeline as "Install now". -->

<div class="page-header">
    <h2>Install from uploaded ZIP</h2>
</div>

<fieldset style="margin-bottom:2rem;">
    <legend>Manual install</legend>
    <p class="hint small" style="margin-bottom:1rem;">
        Install or reinstall FanUpdate Redux from a release ZIP file you upload directly &mdash;
        useful if this server can't reach GitHub over outbound HTTPS, or to install a build that
        isn't published as a GitHub release. The uploaded package is validated the same way a
        GitHub-fetched release is (it must contain <code>version.php</code>, <code>index.php</code>,
        and the <code>admin/</code>/<code>class/</code> directories), and a backup of the current
        installation is created automatically before anything is replaced &mdash; if the install
        itself fails, that backup is automatically restored, the same as the GitHub-based flow below.
    </p>

    <form action="update.php" method="post" enctype="multipart/form-data" id="fu-upload-install-form"
          data-fu-progress data-panel="fu-upload-progress-panel" data-message="fu-upload-progress-message"
          data-bar="fu-upload-progress-bar" data-busy-label="Uploading &amp; installing…">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="action" value="upload_install" />

        <p>
            <label for="update_zip" style="display:block; font-weight:500; color:var(--fu-text); margin-bottom:3px;">Release ZIP file</label>
            <input type="file" id="update_zip" name="update_zip" accept=".zip" required />
        </p>

        <p class="hint small">
            <label class="checkbox">
                <input type="checkbox" id="fu-upload-confirm-chk" required />
                I understand that this will replace application files. A backup is created
                automatically first, but I have confirmed this ZIP is a genuine FanUpdate Redux
                release package.
            </label>
        </p>

        <input type="submit" class="update" value="Upload &amp; install" />

        <div id="fu-upload-progress-panel" style="display:none; margin-top:1.5rem;">
            <p id="fu-upload-progress-message" class="hint small">Starting install…</p>
            <div style="background:var(--fu-border); border-radius:var(--fu-radius); overflow:hidden; height:0.75rem;">
                <div id="fu-upload-progress-bar" style="background:var(--fu-accent, #4a90d9); height:100%; width:0%; transition:width 0.4s ease;"></div>
            </div>
            <p class="hint small" style="margin-top:0.5rem;">This page will refresh automatically once the install finishes.</p>
        </div>
    </form>
</fieldset>

<script src="../assets/js/fanupdate-progress.js"></script>

<?php if ($summary['status'] === 'ok') { ?>

<!-- ── Release notes ─────────────────────────────────────────────────────── -->

<div class="page-header">
    <h2>Latest release</h2>
</div>

<fieldset style="margin-bottom:2rem;">
    <legend>Release information</legend>

    <p>
        <strong><?php echo htmlspecialchars((string) ($summary['latestName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
        &mdash; version <?php echo htmlspecialchars((string) $summary['latestVersion'], ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($summary['latestPrerelease']) { ?><span class="badge badge-warning">Prerelease</span><?php } ?>
        <?php if ($summary['updateAvailable']) { ?><span class="badge badge-accent">Update available</span><?php } ?>
    </p>
    <p class="hint small">
        Published <?php echo fu_update_format_date($summary['latestPublishedAt']); ?>
        <?php if ($summary['downloadSize']) { ?>&middot; download size <?php echo fu_update_format_bytes($summary['downloadSize']); ?><?php } ?>
        &middot; <?php echo $summary['checksumAvailable'] ? 'checksum published' : 'no checksum published for this release'; ?>
    </p>
    <p>
        <?php if (!empty($summary['latestUrl'])) { ?>
        <a href="<?php echo htmlspecialchars((string) $summary['latestUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View release notes on GitHub</a>
        <?php } ?>
        <?php if (!empty($summary['downloadUrl'])) { ?>
        &middot; <a href="<?php echo htmlspecialchars((string) $summary['downloadUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Download release ZIP</a>
        <?php } ?>
    </p>

    <?php if ($summary['downloadUrl']) { ?>
    <div style="margin-top:1rem;">
        <?php if ($summary['downloadReady']) { ?>
            <p class="success" style="margin-bottom:0.5rem;">
                Downloaded and verified
                <?php if ($summary['downloadedSize']) { ?>(<?php echo fu_update_format_bytes($summary['downloadedSize']); ?>)<?php } ?>
                at <?php echo fu_update_format_date($summary['downloadedAt']); ?>.
            </p>
            <?php if ($summary['downloadedChecksumVerified']) { ?>
                <p class="hint small">
                    <span class="badge badge-accent">Checksum verified</span>
                    against the checksum published for this release.
                </p>
            <?php } else { ?>
                <p class="hint small">
                    <span class="badge badge-warning">No checksum published</span>
                    for this release — only file size and ZIP archive integrity were verified.
                </p>
            <?php } ?>
            <p class="hint small">SHA-256: <code><?php echo htmlspecialchars((string) $summary['downloadedSha256'], ENT_QUOTES, 'UTF-8'); ?></code></p>
        <?php } ?>
        <form action="update.php" method="post">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="action" value="download" />
            <input type="submit" class="update"
                   title="Download this release's ZIP asset for later installation"
                   value="<?php echo $summary['downloadReady'] ? 'Re-download release' : 'Download release'; ?>" />
        </form>
        <?php if ($summary['downloadReady'] && $summary['updateAvailable']) { ?>
            <p style="margin-top:0.75rem;">
                <a href="update.php?action=confirm-install" class="button">Review &amp; install update</a>
            </p>
        <?php } ?>
        <p class="hint small" style="margin-top:0.5rem;">
            Downloading stages the release ZIP in a protected directory on this server, verified for
            size and archive integrity. Installing copies it over the live site — a backup is created
            automatically first.
        </p>
    </div>
    <?php } ?>

    <?php
    $notes = trim((string) ($summary['latestNotes'] ?? ''));
    if ($notes !== '') {
        // The renderer escapes internally (Parsedown safe mode), so pass the raw notes.
        $releaseNotesRenderer = new ReleaseNotesRenderer();
        ?>
        <div class="fu-release-notes" style="max-height:360px; overflow-y:auto; padding:1rem; background:var(--fu-surface); border:0.5px solid var(--fu-border-med); border-radius:var(--fu-radius); margin-top:0.75rem;">
            <?php echo $releaseNotesRenderer->renderToHtml($notes); ?>
        </div>
    <?php } ?>
</fieldset>

<?php } elseif ($summary['status'] === 'error') { ?>

<p class="error">Last check failed: <?php echo htmlspecialchars((string) $summary['lastError'], ENT_QUOTES, 'UTF-8'); ?></p>

<?php } ?>

<div class="page-header">
    <h2>Backups</h2>
</div>

<fieldset style="margin-bottom:2rem;">
    <legend>Installation backups</legend>

    <p class="hint small">
        A backup is a ZIP snapshot of the application's own code and configuration — not
        your uploaded images, which an update never touches. Up to <?php echo BackupManager::RETENTION_COUNT; ?> backups are kept;
        creating a new one automatically removes the oldest beyond that. Download one to keep
        an off-site copy — it includes <code>blog-config.php</code>, so store it somewhere private.
    </p>

    <form action="update.php" method="post" style="margin:1rem 0;">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="action" value="backup" />
        <input type="submit" class="update" value="Back up now" title="Create a backup of the current installation" />
    </form>

    <?php if (empty($backups)) { ?>
        <p class="hint small">No backups yet.</p>
    <?php } else { ?>
        <table>
            <thead>
                <tr>
                    <th>Version</th>
                    <th>Created</th>
                    <th>Size</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($backup->version, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo fu_update_format_date($backup->createdAt); ?></td>
                    <td><?php echo fu_update_format_bytes($backup->size); ?></td>
                    <td>
                        <form action="update.php" method="post" style="display:inline; margin-right:0.5rem;">
                            <?php echo $fu->csrfField(); ?>
                            <input type="hidden" name="action" value="download_backup" />
                            <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars(basename($backup->filePath), ENT_QUOTES, 'UTF-8'); ?>" />
                            <input type="submit" class="update" value="Download" title="Download this backup ZIP" />
                        </form>
                        <a href="update.php?action=confirm-rollback&amp;backup_path=<?php echo urlencode($backup->filePath); ?>" class="button" style="margin-right:0.5rem;">Restore</a>
                        <form action="update.php" method="post" style="display:inline;">
                            <?php echo $fu->csrfField(); ?>
                            <input type="hidden" name="action" value="delete_backup" />
                            <input type="hidden" name="backup_path" value="<?php echo htmlspecialchars($backup->filePath, ENT_QUOTES, 'UTF-8'); ?>" />
                            <input type="submit" class="delete" value="Delete" data-skip-confirm />
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</fieldset>

<!-- ── System Status ──────────────────────────────────────────────────── -->

<div class="page-header">
    <h2>System status</h2>
</div>

<fieldset style="margin-bottom:2rem;">
    <legend>Environment checks</legend>

    <p class="hint small">
        These reflect this server's current environment — they can change independently of
        anything above (e.g. if a host changes a PHP setting or free disk space runs low).
    </p>

    <table>
        <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($systemStatusChecker->runChecks() as $check) { ?>
            <tr>
                <td><?php echo htmlspecialchars($check->name, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo fu_update_check_badge($check->status); ?></td>
                <td class="hint small"><?php echo htmlspecialchars($check->message, ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</fieldset>

<!-- ── Update settings ───────────────────────────────────────────────────── -->

<div class="page-header">
    <h2>Update logs</h2>
</div>

<fieldset style="margin-bottom:2rem;">
    <legend>Recent activity</legend>

    <p class="hint small">
        A running record of update checks, downloads, installs, and restores — up to the most
        recent 200 events. Failures are logged as error-status entries of their relevant type
        (e.g. a failed install shows here as "Install" with an Error badge), not as a separate
        category.
    </p>

    <p style="margin:1rem 0;">
        <a href="update.php?action=download-log" class="button" style="margin-right:0.5rem;">Download full log (JSON)</a>
    </p>

    <?php $logEntries = $updateManager->getRecentLogEntries(50); ?>
    <?php if (empty($logEntries)) { ?>
        <p class="hint small">No logged activity yet.</p>
    <?php } else { ?>
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>Event</th>
                    <th>Status</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logEntries as $entry) { ?>
                <tr>
                    <td class="hint small"><?php echo fu_update_format_date($entry['timestamp'] ?? null); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string) ($entry['type'] ?? '?')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo fu_update_check_badge((string) ($entry['status'] ?? '')); ?></td>
                    <td class="hint small"><?php echo htmlspecialchars((string) ($entry['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php if (count($logEntries) >= 50) { ?>
            <p class="hint small" style="margin-top:0.5rem;">Showing the 50 most recent events — download the full log above for the complete history.</p>
        <?php } ?>
    <?php } ?>

    <form action="update.php" method="post" style="margin-top:1rem;">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="action" value="clear_log" />
        <input type="submit" class="delete" value="Clear log" data-skip-confirm />
    </form>
</fieldset>

<div class="page-header">
    <h2>Update settings</h2>
</div>

<form action="update.php" method="post">
    <?php echo $fu->csrfField(); ?>
    <input type="hidden" name="action" value="save_settings" />

    <div class="option odd" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="channel" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">Release channel</label>
        <select id="channel" name="channel">
            <option value="stable" <?php echo $summary['channel'] === 'stable' ? 'selected' : ''; ?>>Stable</option>
            <option value="prerelease" <?php echo $summary['channel'] === 'prerelease' ? 'selected' : ''; ?>>Prerelease (include beta/pre-release versions)</option>
        </select>
        <p class="hint small" style="margin-top:3px;">Stable checks GitHub's latest full release only. Prerelease also considers beta/pre-release tags.</p>
    </div>

    <div class="option even" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label class="checkbox" for="auto_check_enabled" style="font-weight:500; color:var(--fu-text);">
            <input type="checkbox" id="auto_check_enabled" name="auto_check_enabled" value="1" <?php echo $updateManager->isAutoCheckEnabled() ? 'checked' : ''; ?> />
            Automatically check for updates
        </label>
        <p class="hint small" style="margin-top:3px;">
            There's no cron available on typical shared hosting, so this checks opportunistically —
            the next time any admin page loads a check is due, rather than on a fixed schedule.
        </p>
        <label for="check_frequency_hours" style="font-weight:500; color:var(--fu-text); margin-top:0.75rem; margin-bottom:3px; display:block;">Check frequency</label>
        <?php $currentFrequency = $updateManager->getCheckFrequencyHours(); ?>
        <select id="check_frequency_hours" name="check_frequency_hours">
            <option value="6" <?php echo $currentFrequency === 6 ? 'selected' : ''; ?>>Every 6 hours</option>
            <option value="12" <?php echo $currentFrequency === 12 ? 'selected' : ''; ?>>Every 12 hours</option>
            <option value="24" <?php echo $currentFrequency === 24 ? 'selected' : ''; ?>>Daily</option>
            <option value="72" <?php echo $currentFrequency === 72 ? 'selected' : ''; ?>>Every 3 days</option>
            <option value="168" <?php echo $currentFrequency === 168 ? 'selected' : ''; ?>>Weekly</option>
        </select>
    </div>

    <div class="option odd" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="github_token" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">GitHub token (optional)</label>
        <input type="password" id="github_token" name="github_token" value="" placeholder="<?php echo $githubToken !== null ? 'Token is set — leave blank to keep it' : 'Not set'; ?>" autocomplete="off" />
        <p class="hint small" style="margin-top:3px;">
            Only needed if update checks start hitting GitHub's unauthenticated rate limit. A fine-grained personal access token with no
            permissions (public read access only) is sufficient. Leave blank to keep the current token unchanged.
        </p>
        <?php if ($githubToken !== null) { ?>
        <label class="checkbox" for="clear_github_token" style="margin-left:0; margin-top:6px;">
            <input type="checkbox" id="clear_github_token" name="clear_github_token" value="1" />
            Remove the saved token
        </label>
        <?php } ?>
    </div>

    <p style="margin-top:1.5rem;">
        <input type="submit" value="Save settings" class="update" />
    </p>
</form>

<?php
$fu->getFooter();
