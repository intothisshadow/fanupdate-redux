<?php
/*****************************************************************************
 * FanUpdate — admin/templates.php
 * HTML modernisation:
 *  - subnav wrapped in .subnav-bar
 *  - strpos(...) != 0 → str_starts_with() for private-key hide logic (PHP 8.0+)
 *  - type="text/javascript" removed from <script> tags
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
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'update' && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action']) && $_POST['action'] === 'update') {

    $keys   = isset($_POST['key'])   && is_array($_POST['key'])   ? $_POST['key']   : [];
    $values = isset($_POST['value']) && is_array($_POST['value']) ? $_POST['value'] : [];

    foreach ($keys as $id => $key) {
        $key = clean_input($key);

        // Skip private keys
        if (str_starts_with($key, '_')) {
            continue;
        }

        $value     = clean_input((string) ($values[$id] ?? ''), true);

        $query = "UPDATE " . $fu->getOpt('options_table') . "
            SET optvalue = ? WHERE optkey = ?";

        if (!$fu->db->Execute($query, 'Could not execute query.', [$value, $key])) {
            $fu->addErr('Failed to update <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong>.');
        } elseif ($fu->db->AffectedRows() > 0) {
            $fu->addSuccess('Template <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> updated.');
        }
    }
}

// ── Load template options ─────────────────────────────────────────────────

$query = "SELECT * FROM " . $fu->getOpt('options_table') . "
    WHERE optkey LIKE '%template%'
    ORDER BY optkey ASC";

$fu->db->Execute($query);
$rows = [];
while ($row = $fu->db->ReadRecord()) {
    $rows[] = $row;
}
$fu->db->FreeResult();

$fu->getHeader('Templates');

?>

<div class="page-header">
    <h2>Options: Templates</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li><a href="options.php">Main</a></li>
        <li><a href="blacklist.php">Blacklist</a></li>
        <li><a href="smilies.php">Smilies</a></li>
        <li class="active"><a href="templates.php">Templates</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<form action="templates.php" method="post">
    <?php echo $fu->csrfField(); ?>
    <?php
    foreach ($rows as $id => $row) {
        $optkey = (string) $row['optkey'];

        // Hide private keys — str_starts_with replaces the old strpos != 0 pattern
        if (str_starts_with($optkey, '_')) {
            continue;
        }

        $safe_key  = htmlspecialchars($optkey, ENT_QUOTES, 'UTF-8');
        $safe_desc = htmlspecialchars((string) ($row['optdesc']  ?? ''), ENT_QUOTES, 'UTF-8');
        $safe_val  = htmlspecialchars((string) ($row['optvalue'] ?? ''), ENT_QUOTES, 'UTF-8');
        ?>

        <fieldset style="margin-bottom:1.25rem;">
            <legend><?php echo $safe_key; ?></legend>
            <?php if ($safe_desc !== '') { ?>
            <p class="hint small" style="margin-bottom:0.5rem;"><?php echo $safe_desc; ?></p>
            <?php } ?>
            <input type="hidden" name="key[<?php echo $id; ?>]" value="<?php echo $safe_key; ?>" />
            <textarea id="tpl_<?php echo $id; ?>" name="value[<?php echo $id; ?>]"
                      rows="8" style="font-family: 'Courier New', monospace; font-size:0.8rem;"><?php echo $safe_val; ?></textarea>
        </fieldset>

    <?php } ?>

    <p>
        <input type="submit" name="action" value="update" class="update"
               title="Save all templates, accesskey s" accesskey="s" />
    </p>
</form>

<?php
$fu->getFooter();
