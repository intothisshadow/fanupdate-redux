<?php
/*****************************************************************************
 * FanUpdate — admin/options.php
 *
 * General site options: lists and updates all editable option-table rows
 * (excluding templates, credentials, private keys, and the collective-source
 * keys, which have their own page). Delegates persistence to
 * OptionService/OptionRepository; this page sanitises $_POST, enforces CSRF,
 * and renders. y/n options render as toggle switches.
 *
 * Also corrects install_path on every load when the stored value is missing
 * or points at a directory that isn't this installation (e.g. a stale copy
 * left after a domain move). A manual value that still resolves to a real
 * copy of this installation is left alone.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/OptionRepositoryInterface.php');
require_once('../class/Repository/OptionRepository.php');
require_once('../class/Service/OptionService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$optionService = new OptionService(new OptionRepository(
    $fu->db,
    (string) $fu->getOpt('options_table')
));

$showNav = true;

// ── Deny-list: keys that must never be edited here ────────────────────────
// (private "_" keys are already filtered in the SQL query)
$disallow = ['admin_password', 'admin_username', 'admin_email', 'version'];

// ── Helper: detect this server's actual install directory ────────────────
// Same normalisation as install.php (DreamHost "/.username" quirk, Windows slashes).
function fu_detect_install_path(): string {
    $dir = dirname(__DIR__);
    $dir = (string) preg_replace('#/\.([a-z]+)#', '', $dir);
    return str_replace('\\', '/', $dir);
}

// ── Helper: does a directory actually look like a FanUpdate Redux install? ──
// is_dir() alone can't tell a stale, abandoned directory from this installation, so
// check for the same files InstallManager requires of a release.
function fu_looks_like_install_path(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }

    foreach (['version.php', 'index.php'] as $file) {
        if (!is_file($dir . '/' . $file)) {
            return false;
        }
    }

    foreach (['admin', 'class'] as $subdir) {
        if (!is_dir($dir . '/' . $subdir)) {
            return false;
        }
    }

    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'update' && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action']) && $_POST['action'] === 'update') {

    $keys   = isset($_POST['key'])   && is_array($_POST['key'])   ? $_POST['key']   : [];
    $values = isset($_POST['value']) && is_array($_POST['value']) ? $_POST['value'] : [];

    foreach ($keys as $id => $key) {
        $key = clean_input($key);

        // Block private keys and deny-listed keys
        if (str_starts_with($key, '_') || in_array($key, $disallow, true)) {
            continue;
        }

        $value = clean_input((string) ($values[$id] ?? ''));

        // Reject an invalid timezone now rather than letting dates silently revert to UTC.
        if ($key === FU_TIMEZONE_OPTION_KEY && !fu_is_valid_timezone($value)) {
            $fu->addErr('<strong>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</strong> is not a recognised timezone — timezone not updated.');
            continue;
        }

        $affected = $optionService->updateOption($key, $value);

        if ($affected === null) {
            $fu->addErr('Failed to update <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong>.');
        } elseif ($affected > 0) {
            // Key only: some option values are sensitive.
            $fu->logEvent(LogService::OPTIONS_CHANGED, 'Setting "' . $key . '" changed.');
            $fu->addSuccess(
                'Set <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> = '
                . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
            );
        }
    }
}

// ── Auto-detect install path ───────────────────────────────────────────────
// A plain file copy to a new server/domain never updates install_path. If the stored
// path is missing or isn't this installation (fu_looks_like_install_path()), correct
// it. A manual value that still resolves to a real copy is kept, as the override for
// non-standard setups such as symlinks.
$detectedInstallPath = fu_detect_install_path();
$storedInstallPath   = (string) $fu->getOpt('install_path', true);

if ($storedInstallPath !== '' && $storedInstallPath !== $detectedInstallPath && !fu_looks_like_install_path($storedInstallPath)) {
    $affected = $optionService->updateOption('install_path', $detectedInstallPath);

    if ($affected === null) {
        $fu->addErr('Could not auto-update <strong>install_path</strong> to match this server’s actual location.');
    } elseif ($affected > 0) {
        $fu->addOpt('install_path', $detectedInstallPath);
        $fu->addSuccess(
            'Install path automatically updated to <strong>' . htmlspecialchars($detectedInstallPath, ENT_QUOTES, 'UTF-8') . '</strong> '
            . '(the previously stored path no longer looks like this installation on this server, likely after a move or migration — '
            . 'it may still exist as a leftover, unrelated, or stale directory). '
            . 'You can override this manually below if needed.'
        );
    }
}

// ── Load options (exclude templates, admin_email, private keys) ───────────
$rows = $optionService->listEditableOptions();

// Options are alphabetical; move timezone next to date_format since the two read together.
$dateFormatIndex = null;
$timezoneIndex   = null;
foreach ($rows as $i => $row) {
    if ($row['optkey'] === FU_DATE_FORMAT_OPTION_KEY) {
        $dateFormatIndex = $i;
    } elseif ($row['optkey'] === FU_TIMEZONE_OPTION_KEY) {
        $timezoneIndex = $i;
    }
}
if ($dateFormatIndex !== null && $timezoneIndex !== null && $timezoneIndex !== $dateFormatIndex + 1) {
    $timezoneRow = $rows[$timezoneIndex];
    array_splice($rows, $timezoneIndex, 1);
    $dateFormatIndex = array_search(FU_DATE_FORMAT_OPTION_KEY, array_column($rows, 'optkey'), true);
    array_splice($rows, $dateFormatIndex + 1, 0, [$timezoneRow]);
}

$fu->getHeader('Options');

?>

<div class="page-header">
    <h2>Options</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li class="active"><a href="options.php">Main</a></li>
        <li><a href="blacklist.php">Blacklist</a></li>
        <li><a href="smilies.php">Smilies</a></li>
        <li><a href="templates.php">Templates</a></li>
        <li><a href="collective-source.php">Collective Source</a></li>
        <li><a href="privacy.php">Privacy</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<form action="options.php" method="post">
    <?php echo $fu->csrfField(); ?>
    <?php
    $class = 'odd';
    foreach ($rows as $id => $row) {
        $optkey = (string) $row['optkey'];

        // Skip private keys (any key beginning with '_')
        if (str_starts_with($optkey, '_') || in_array($optkey, $disallow, true)) {
            continue;
        }

        $class       = ($class === 'even') ? 'odd' : 'even';
        $safe_key    = htmlspecialchars($optkey, ENT_QUOTES, 'UTF-8');
        $display_key = htmlspecialchars(fu_option_label($optkey), ENT_QUOTES, 'UTF-8');
        $safe_desc   = htmlspecialchars((string) ($row['optdesc']  ?? ''), ENT_QUOTES, 'UTF-8');
        $safe_val    = htmlspecialchars((string) ($row['optvalue'] ?? ''), ENT_QUOTES, 'UTF-8');
        $is_yn       = fu_is_yn_option($optkey, (string) ($row['optdesc'] ?? ''));
        $is_on       = (strtolower(trim((string) ($row['optvalue'] ?? ''))) === 'y');
        ?>

        <div class="option <?php echo $class; ?>" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
            <input type="hidden" name="key[<?php echo $id; ?>]" value="<?php echo $safe_key; ?>" />

            <?php if ($optkey === FU_TIMEZONE_OPTION_KEY): ?>

            <label for="val_<?php echo $id; ?>" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
                <?php echo $display_key; ?>
            </label>
            <select id="val_<?php echo $id; ?>" name="value[<?php echo $id; ?>]">
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
            <?php if ($safe_desc !== ''): ?>
            <p class="hint small" style="margin-top:3px;"><?php echo $safe_desc; ?></p>
            <?php endif; ?>

            <?php elseif ($optkey === FU_DATE_FORMAT_OPTION_KEY):
                $isKnownDateFormatPreset = fu_is_known_date_format_preset((string) $row['optvalue']);
            ?>

            <label for="val_<?php echo $id; ?>_select" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
                <?php echo $display_key; ?>
            </label>
            <select id="val_<?php echo $id; ?>_select" class="fu-date-format-select" data-target="val_<?php echo $id; ?>">
                <?php foreach (fu_date_format_presets() as $preset): ?>
                <option value="<?php echo htmlspecialchars($preset, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo ($preset === (string) $row['optvalue']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(fu_date_format_option_label($preset), ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
                <option value="<?php echo FU_DATE_FORMAT_CUSTOM_VALUE; ?>" <?php echo !$isKnownDateFormatPreset ? 'selected' : ''; ?>>Custom&hellip;</option>
            </select>
            <input type="text" id="val_<?php echo $id; ?>" name="value[<?php echo $id; ?>]"
                   value="<?php echo $safe_val; ?>" <?php echo $isKnownDateFormatPreset ? 'readonly' : ''; ?>
                   style="margin-top:0.5rem;<?php echo $isKnownDateFormatPreset ? ' display:none;' : ''; ?>" />
            <?php if ($safe_desc !== ''): ?>
            <p class="hint small" style="margin-top:3px;"><?php echo $safe_desc; ?></p>
            <?php endif; ?>

            <?php elseif ($is_yn): ?>

            <div class="option-yn-row">
                <label class="option-yn-label" for="val_<?php echo $id; ?>">
                    <?php echo $display_key; ?>
                </label>
                <label class="fu-toggle" title="<?php echo $safe_desc; ?>">
                    <input type="hidden"   name="value[<?php echo $id; ?>]" value="n" />
                    <input type="checkbox" id="val_<?php echo $id; ?>"
                           name="value[<?php echo $id; ?>]" value="y"
                           <?php echo $is_on ? 'checked' : ''; ?>
                           class="fu-toggle__input" />
                    <span class="fu-toggle__track" aria-hidden="true">
                        <span class="fu-toggle__thumb"></span>
                    </span>
                    <span class="fu-toggle__state-label">
                        <?php echo $is_on ? 'Yes' : 'No'; ?>
                    </span>
                </label>
                <?php if ($safe_desc !== ''): ?>
                <p class="hint small option-yn-hint"><?php echo $safe_desc; ?></p>
                <?php endif; ?>
            </div>

            <?php elseif (mb_strlen((string) $row['optvalue']) > 60 || str_contains((string) $row['optvalue'], "\n")): ?>

            <label for="val_<?php echo $id; ?>" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
                <?php echo $display_key; ?>
            </label>
            <textarea id="val_<?php echo $id; ?>" name="value[<?php echo $id; ?>]"
                      rows="4" style="font-family: 'Courier New', monospace; font-size:0.8rem;"><?php echo $safe_val; ?></textarea>
            <?php if ($safe_desc !== ''): ?>
            <p class="hint small" style="margin-top:3px;"><?php echo $safe_desc; ?></p>
            <?php endif; ?>

            <?php else: ?>

            <label for="val_<?php echo $id; ?>" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
                <?php echo $display_key; ?>
            </label>
            <input type="text" id="val_<?php echo $id; ?>" name="value[<?php echo $id; ?>]"
                   value="<?php echo $safe_val; ?>" />
            <?php if ($safe_desc !== ''): ?>
            <p class="hint small" style="margin-top:3px;"><?php echo $safe_desc; ?></p>
            <?php endif; ?>

            <?php endif; ?>
        </div>

    <?php } ?>

    <p style="margin-top:1.5rem;">
        <button type="submit" name="action" value="update" class="update"
               title="Save all options, accesskey s" accesskey="s">Update</button>
    </p>
</form>

<?php
// Toggle labels are wired globally by assets/js/fanupdate.js.
$fu->getFooter();
