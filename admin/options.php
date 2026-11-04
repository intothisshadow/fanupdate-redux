<?php
/*****************************************************************************
 * FanUpdate — admin/options.php
 *
 * General site options: lists and updates all editable option-table rows
 * (excluding templates, credentials, private/internal keys, and the
 * collective-source keys collective_script/col_id/col_subj, which have
 * their own pages or are never user-editable — see admin/collective-source.php
 * for the latter). Delegates persistence to OptionService/OptionRepository;
 * this page parses/sanitises $_POST, enforces CSRF, and renders. y/n options
 * render as toggle switches.
 *
 * Also auto-detects and corrects install_path on every page load: if the
 * stored value no longer resolves to a directory that actually looks like
 * this installation (missing entirely, or a real directory that doesn't
 * contain this app's own files — e.g. left over from a domain move where
 * the old directory is still physically present but is no longer the live
 * install), it is corrected automatically. A manually-set install_path that
 * still resolves to a real copy of this installation is left alone.
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

// ── Deny-list: keys that must never be edited on this page ────────────────
// (private keys starting with _ are filtered in the SQL query below;
//  these are additional explicit exclusions)
$disallow = ['admin_password', 'admin_username', 'admin_email', 'version'];

// fu_is_yn_option() (boolean-toggle detection) lives in functions.php now,
// shared with install.php's fresh-install form — see FU_YN_OPTION_KEYS.

// fu_option_label() (human-readable label from an option key, e.g.
// 'comments_on' -> 'Comments on') now lives in functions.php, shared with
// install.php's fresh-install form.

// ── Helper: detect this server's actual install directory ────────────────
// Mirrors the path normalisation install.php applies when it first writes
// install_path: strip the DreamHost "/.username" quirk and normalise
// backslashes to forward slashes on Windows dev environments. admin/options.php
// lives one directory below the install root, hence dirname(__DIR__).
function fu_detect_install_path(): string {
    $dir = dirname(__DIR__);
    $dir = (string) preg_replace('#/\.([a-z]+)#', '', $dir);
    return str_replace('\\', '/', $dir);
}

// ── Helper: does a directory actually look like a FanUpdate Redux install? ──
// A plain is_dir() check only proves *some* directory exists at the stored
// path — it says nothing about whether it's still *this* installation. A
// stale install_path left over from a domain move can keep pointing at a
// real, but abandoned, directory indefinitely (confirmed live: this was the
// actual root cause of a BackupManager "empty archive" failure on July 18,
// 2026 — see docs/CHANGELOG.md's [Unreleased] "Fixed" entry). Checked the
// same way InstallManager validates an extracted release before installing
// over the live site: version.php/index.php present, plus admin/ and
// class/ directories.
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

        // FP-19: 'timezone' must be a real IANA identifier — a hand-edited
        // or tampered value would otherwise silently break every date
        // display (FanUpdate_Post/FanUpdate_Comment::getDateFormatted()
        // already falls back to UTC for an invalid stored value, but
        // rejecting it here means the admin finds out immediately instead
        // of dates quietly reverting to UTC).
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
// If the site has been moved to a different server, directory, or domain (a
// plain file copy, unlike a real "migration" tool, never updates
// install_path), the stored value stops pointing at a directory that's
// still actually this installation. That covers two distinct cases, both
// checked by fu_looks_like_install_path(): the stored path no longer exists
// at all, OR it exists but is some other (possibly stale/abandoned)
// directory that isn't this app — e.g. left over from an old domain that
// was never cleaned up after a move, which otherwise defeats this exact
// auto-correction silently (confirmed live as the root cause of a
// BackupManager "empty archive" failure — see docs/CHANGELOG.md). When
// either happens, silently correct install_path to match this server's real
// location, same as install.php auto-detects it on first install. A
// manually-entered install_path is left alone as long as it still resolves
// to a real copy of this installation — that's the override escape hatch
// for non-standard setups (e.g. a symlinked path).
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

// listEditableOptions() sorts alphabetically, which already puts
// 'date_format' before 'timezone' (d < t) but not necessarily adjacent to
// it — other options can sort in between. Both are date/time-related and
// their dropdowns (FU_DATE_FORMAT_OPTION_KEY/FU_TIMEZONE_OPTION_KEY below)
// read more naturally next to each other, so timezone is moved to
// immediately follow date_format here; every other option keeps its
// existing alphabetical position.
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
                // FP-21: preset dropdown + "Custom" fallback — see functions.php.
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
// Toggle state-label live update (.fu-toggle__input) is wired globally by
// assets/js/fanupdate.js, loaded on every admin page (and install.php) via
// header.php — no per-page inline script needed here anymore.
$fu->getFooter();
