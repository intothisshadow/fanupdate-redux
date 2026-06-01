<?php
/*****************************************************************************
 * FanUpdate — admin/blacklist.php
 * HTML modernisation: aria-label on table; subnav wrapped in .subnav-bar
 * All PHP/SQL logic preserved exactly from previous version.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;
$clean   = [];

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action'])) {

    if ($_POST['action'] === 'add') {

        $clean['badword'] = isset($_POST['badword']) ? clean_input($_POST['badword']) : '';
        $sql_word         = $fu->db->Escape($clean['badword']);

        $query = "SELECT COUNT(*) FROM " . $fu->getOpt('blacklist_table') . " WHERE badword='$sql_word'";
        $exists = (int) $fu->db->GetFirstCell($query);

        if ($exists > 0) {
            $fu->addErr('Word <strong>' . htmlspecialchars($clean['badword'], ENT_QUOTES, 'UTF-8') . '</strong> is already blacklisted.');
        } else {
            $query = "INSERT INTO " . $fu->getOpt('blacklist_table') . " (badword) VALUES ('$sql_word')";
            if ($fu->db->Execute($query)) {
                $fu->addSuccess('Word <strong>' . htmlspecialchars($clean['badword'], ENT_QUOTES, 'UTF-8') . '</strong> blacklisted.');
            } else {
                $fu->addErr('Failed to add word to blacklist.');
            }
            $clean = [];
        }

    } elseif ($_POST['action'] === 'delete') {

        $words = isset($_POST['badword']) && is_array($_POST['badword']) ? $_POST['badword'] : [];
        foreach ($words as $word) {
            $sql_id = $fu->db->Escape(clean_input($word));
            $query  = "DELETE FROM " . $fu->getOpt('blacklist_table') . " WHERE badword='$sql_id'";
            if ($fu->db->Execute($query)) {
                $fu->addSuccess('Word <strong>' . htmlspecialchars($sql_id, ENT_QUOTES, 'UTF-8') . '</strong> removed from blacklist.');
            }
        }
        $clean = [];
    }
}

$fu->getHeader('Blacklist');

?>

<div class="page-header">
    <h2>Options: Blacklist</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li><a href="options.php">Main</a></li>
        <li class="active"><a href="blacklist.php">Blacklist</a></li>
        <li><a href="smilies.php">Smilies</a></li>
        <li><a href="templates.php">Templates</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<form action="blacklist.php" method="post" style="margin-bottom:1.5rem;">
    <p>
        <label for="badword">Add word to blacklist</label>
        <input type="text" id="badword" name="badword" size="20" maxlength="50"
               value="<?php echo htmlspecialchars((string) ($clean['badword'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               accesskey="n" style="width:auto;" />
        <input type="submit" name="action" value="add" class="add"
               title="Blacklist this word, accesskey s" accesskey="s" />
    </p>
</form>

<?php

$query = 'SELECT * FROM ' . $fu->getOpt('blacklist_table') . ' ORDER BY badword ASC';
$fu->db->Execute($query);

if ($fu->db->NumRows() > 0) { ?>

<form action="blacklist.php" method="post">

    <table class="sortable" aria-label="Blacklisted words">
        <thead>
            <tr>
                <th scope="col">Word</th>
                <th scope="col">Delete?</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $class = 'odd';
            while ($row = $fu->db->ReadRecord()) {
                $class = ($class === 'even') ? 'odd' : 'even';
                ?>
                <tr class="<?php echo $class; ?>">
                    <td><?php echo htmlspecialchars((string) $row['badword'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="form">
                        <input type="checkbox" name="badword[]"
                               value="<?php echo htmlspecialchars((string) $row['badword'], ENT_QUOTES, 'UTF-8'); ?>" />
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <p style="margin-top:1rem;">
        <input type="submit" name="action" value="delete" class="delete"
               title="Delete selected words" />
    </p>

</form>

<?php } else {
    echo '<p>No words are currently blacklisted.</p>';
}

$fu->db->FreeResult();
$fu->getFooter();
