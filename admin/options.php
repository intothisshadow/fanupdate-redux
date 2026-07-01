<?php
/*****************************************************************************
 * FanUpdate — admin/options.php
 * HTML modernisation:
 *  - subnav wrapped in .subnav-bar
 *  - strpos(...) != 0 → str_starts_with() for private-key detection
 *  - y/n options rendered as toggle switches instead of plain text inputs
 *  - option key underscores replaced with spaces, sentence-cased for display
 *  - All PHP/SQL logic preserved exactly
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

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

        $query = "UPDATE " . $fu->getOpt('options_table') . "
            SET optvalue = ? WHERE optkey = ?";

        if (!$fu->db->Execute($query, 'Could not execute query.', [$value, $key])) {
            $fu->addErr('Failed to update <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong>.');
        } elseif ($fu->db->AffectedRows() > 0) {
            $fu->addSuccess(
                'Set <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> = '
                . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
            );
        }
    }
}

// ── Load options (exclude templates, admin_email, private keys) ───────────
$query = "SELECT * FROM " . $fu->getOpt('options_table') . "
    WHERE optkey NOT LIKE '%template%'
      AND optkey != 'admin_email'
    ORDER BY optkey ASC";

$fu->db->Execute($query);
$rows = [];
while ($row = $fu->db->ReadRecord()) {
    $rows[] = $row;
}
$fu->db->FreeResult();

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
