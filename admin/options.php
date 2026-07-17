<?php
/*****************************************************************************
 * FanUpdate — admin/options.php
 *
 * General site options: lists and updates all editable option-table rows
 * (excluding templates, credentials, and private/internal keys, which have
 * their own pages or are never user-editable). Delegates persistence to
 * OptionService/OptionRepository; this page parses/sanitises $_POST,
 * enforces CSRF, and renders. y/n options render as toggle switches.
 *
 * Also auto-detects and corrects install_path on every page load: if the
 * stored value no longer resolves to a real, existing directory (e.g. after
 * a server move/migration where install_path was never manually updated),
 * it is corrected automatically. A manually-set install_path that still
 * points to a real directory is left alone.
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

// ── Helper: detect y/n boolean options ───────────────────────────────────
// An option is a boolean toggle if its description contains "y or n"
// (case-insensitive) — this matches all y/n fields defined in install.php.
function fu_is_yn_option(string $desc): bool {
    return stripos($desc, 'y or n') !== false;
}

// ── Helper: human-readable label from an option key ──────────────────────
// Replaces underscores with spaces and applies sentence case (first word
// capitalised, rest lowercase). The raw key is still used in hidden inputs
// and POST processing — only the visible label is affected.
function fu_option_label(string $key): string {
    return ucfirst(str_replace('_', ' ', $key));
}

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

        $affected = $optionService->updateOption($key, $value);

        if ($affected === null) {
            $fu->addErr('Failed to update <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong>.');
        } elseif ($affected > 0) {
            $fu->addSuccess(
                'Set <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> = '
                . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
            );
        }
    }
}

// ── Auto-detect install path ───────────────────────────────────────────────
// If the site has been moved to a different server or directory (a plain
// file copy, unlike a real "migration" tool, never updates install_path),
// the stored value stops pointing at a directory that actually exists on
// this filesystem. When that happens, silently correct it to match this
// server's real location, same as install.php auto-detects it on first
// install. A manually-entered install_path is left alone as long as it
// still resolves to a real, existing directory — that's the override
// escape hatch for non-standard setups (e.g. a symlinked path).
$detectedInstallPath = fu_detect_install_path();
$storedInstallPath   = (string) $fu->getOpt('install_path', true);

if ($storedInstallPath !== '' && $storedInstallPath !== $detectedInstallPath && !is_dir($storedInstallPath)) {
    $affected = $optionService->updateOption('install_path', $detectedInstallPath);

    if ($affected === null) {
        $fu->addErr('Could not auto-update <strong>install_path</strong> to match this server’s actual location.');
    } elseif ($affected > 0) {
        $fu->addOpt('install_path', $detectedInstallPath);
        $fu->addSuccess(
            'Install path automatically updated to <strong>' . htmlspecialchars($detectedInstallPath, ENT_QUOTES, 'UTF-8') . '</strong> '
            . '(the previously stored path no longer exists on this server, likely after a move or migration). '
            . 'You can override this manually below if needed.'
        );
    }
}

// ── Load options (exclude templates, admin_email, private keys) ───────────
$rows = $optionService->listEditableOptions();

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
        $is_yn       = fu_is_yn_option((string) ($row['optdesc'] ?? ''));
        $is_on       = (strtolower(trim((string) ($row['optvalue'] ?? ''))) === 'y');
        ?>

        <div class="option <?php echo $class; ?>" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
            <input type="hidden" name="key[<?php echo $id; ?>]" value="<?php echo $safe_key; ?>" />

            <?php if ($is_yn): ?>

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
        <input type="submit" name="action" value="update" class="update"
               title="Save all options, accesskey s" accesskey="s" />
    </p>
</form>

<script>
// Update the Yes/No state label live when the toggle is clicked
document.querySelectorAll('.fu-toggle__input').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var label = this.closest('.fu-toggle').querySelector('.fu-toggle__state-label');
        if (label) {
            label.textContent = this.checked ? 'Yes' : 'No';
        }
    });
});
</script>

<?php
$fu->getFooter();
