<?php
/*****************************************************************************
 * FanUpdate — admin/options.php
 * HTML modernisation:
 *  - subnav wrapped in .subnav-bar
 *  - strpos(...) != 0 → str_starts_with() for private-key detection
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

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'update') {

    $keys   = isset($_POST['key'])   && is_array($_POST['key'])   ? $_POST['key']   : [];
    $values = isset($_POST['value']) && is_array($_POST['value']) ? $_POST['value'] : [];

    foreach ($keys as $id => $key) {
        $key = clean_input($key);

        // Block private keys and deny-listed keys
        if (str_starts_with($key, '_') || in_array($key, $disallow, true)) {
            continue;
        }

        $value     = clean_input((string) ($values[$id] ?? ''));
        $sql_key   = $fu->db->Escape($key);
        $sql_value = $fu->db->Escape($value);

        $query = "UPDATE " . $fu->getOpt('options_table') . "
            SET optvalue='$sql_value' WHERE optkey='$sql_key'";

        if (!$fu->db->Execute($query)) {
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
    <?php
    $class = 'odd';
    foreach ($rows as $id => $row) {
        $optkey = (string) $row['optkey'];

        // Skip private keys (any key beginning with '_')
        if (str_starts_with($optkey, '_') || in_array($optkey, $disallow, true)) {
            continue;
        }

        $class    = ($class === 'even') ? 'odd' : 'even';
        $safe_key  = htmlspecialchars($optkey, ENT_QUOTES, 'UTF-8');
        $safe_desc = htmlspecialchars((string) ($row['optdesc']  ?? ''), ENT_QUOTES, 'UTF-8');
        $safe_val  = htmlspecialchars((string) ($row['optvalue'] ?? ''), ENT_QUOTES, 'UTF-8');
        ?>

        <div class="option <?php echo $class; ?>" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
            <input type="hidden" name="key[<?php echo $id; ?>]" value="<?php echo $safe_key; ?>" />

            <label for="val_<?php echo $id; ?>" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
                <?php echo $safe_key; ?>
            </label>

            <?php if (mb_strlen((string) $row['optvalue']) > 60 || str_contains((string) $row['optvalue'], "\n")) { ?>
            <textarea id="val_<?php echo $id; ?>" name="value[<?php echo $id; ?>]"
                      rows="4" style="font-family: 'Courier New', monospace; font-size:0.8rem;"><?php echo $safe_val; ?></textarea>
            <?php } else { ?>
            <input type="text" id="val_<?php echo $id; ?>" name="value[<?php echo $id; ?>]"
                   value="<?php echo $safe_val; ?>" />
            <?php } ?>

            <?php if ($safe_desc !== '') { ?>
            <p class="hint small" style="margin-top:3px;"><?php echo $safe_desc; ?></p>
            <?php } ?>
        </div>

    <?php } ?>

    <p style="margin-top:1.5rem;">
        <input type="submit" name="action" value="update" class="update"
               title="Save all options, accesskey s" accesskey="s" />
    </p>
</form>

<?php
$fu->getFooter();
