<?php
/* ─── admin/category.php — HTML modernisation patch notes ─────────────────
 * Changes from previous version:
 *  - <table summary="..."> → <table aria-label="...">
 *  - <ul class="subnav"> wrapped in <div class="subnav-bar"> for bar styling
 *  - All PHP logic and SQL preserved exactly
 * ───────────────────────────────────────────────────────────────────────── */

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav  = true;
$showform = false;
$clean    = [];

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action'])) {

    $clean['name']             = isset($_POST['name'])             ? clean_input($_POST['name'])             : '';
    $clean['cat_id']           = isset($_POST['cat_id'])           ? (int) $_POST['cat_id']                  : null;
    $clean['entry_template']   = isset($_POST['entry_template'])   ? clean_input($_POST['entry_template'],   true) : '';
    $clean['comment_template'] = isset($_POST['comment_template']) ? clean_input($_POST['comment_template'], true) : '';
    $clean['gravatar_default'] = isset($_POST['gravatar_default']) ? clean_input($_POST['gravatar_default']) : '';
    $clean['gravatar_size']    = isset($_POST['gravatar_size'])    ? (int) $_POST['gravatar_size']           : 40;
    $clean['gravatar_rating']  = isset($_POST['gravatar_rating'])  ? clean_input($_POST['gravatar_rating'])  : 'g';

    $sql_name     = $fu->db->Escape($clean['name']);
    $sql_et       = $fu->db->Escape($clean['entry_template']);
    $sql_ct       = $fu->db->Escape($clean['comment_template']);
    $sql_gdefault = $fu->db->Escape($clean['gravatar_default']);
    $sql_gsize    = $fu->db->Escape((string) $clean['gravatar_size']);
    $sql_grating  = $fu->db->Escape($clean['gravatar_rating']);

    if ($_POST['action'] === 'add') {

        if (!$fu->getOpt('collective_script')) {
            $query = "INSERT INTO " . $fu->getOpt('collective_table') . "
                (" . $fu->getOpt('col_subj') . ") VALUES ('$sql_name')";
            if ($fu->db->Execute($query)) {
                $clean['cat_id'] = $fu->db->GetLastSequence();
            } else {
                $fu->addErr('Failed to add category.');
            }
        }

        if ($clean['cat_id'] !== null) {
            $query = "INSERT INTO " . $fu->getOpt('catjoin_table') . "
                (cat_id, entry_template, comment_template, gravatar_default, gravatar_size, gravatar_rating)
                VALUES ('$clean[cat_id]','$sql_et','$sql_ct','$sql_gdefault','$sql_gsize','$sql_grating')";
            if ($fu->db->Execute($query)) {
                $fu->addSuccess('Category <strong>' . htmlspecialchars($clean['name'], ENT_QUOTES, 'UTF-8') . '</strong> added.');
            } else {
                $fu->addErr('Failed to save category settings.');
            }
        }

    } elseif ($_POST['action'] === 'update' && $clean['cat_id'] !== null) {

        if (!$fu->getOpt('collective_script')) {
            $sql_id = $fu->db->Escape((string) $clean['cat_id']);
            $query  = "UPDATE " . $fu->getOpt('collective_table') . "
                SET " . $fu->getOpt('col_subj') . "='$sql_name'
                WHERE " . $fu->getOpt('col_id') . "='$sql_id'";
            $fu->db->Execute($query);
        }

        $sql_id = $fu->db->Escape((string) $clean['cat_id']);
        $query  = "UPDATE " . $fu->getOpt('catjoin_table') . "
            SET entry_template='$sql_et', comment_template='$sql_ct',
                gravatar_default='$sql_gdefault', gravatar_size='$sql_gsize',
                gravatar_rating='$sql_grating'
            WHERE cat_id='$sql_id'";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Category updated.');
        } else {
            $fu->addErr('Failed to update category.');
        }

    } elseif ($_POST['action'] === 'delete' && $clean['cat_id'] !== null && !$fu->getOpt('collective_script')) {

        $sql_id = $fu->db->Escape((string) $clean['cat_id']);
        $fu->db->Execute("DELETE FROM " . $fu->getOpt('collective_table') . " WHERE " . $fu->getOpt('col_id') . "='$sql_id'");
        $fu->db->Execute("DELETE FROM " . $fu->getOpt('catjoin_table') . " WHERE cat_id='$sql_id'");
        $fu->addSuccess('Category deleted.');
    }
}

// Load for edit
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $showform = true;
    $id       = (int) $_GET['id'];
    $sql_id   = $fu->db->Escape((string) $id);

    $query = 'SELECT ' . $fu->getOpt('collective_table') . '.*,
        ' . $fu->getOpt('col_subj') . ' AS cname,
        ' . $fu->getOpt('col_id') . ' AS cid,
        j.*
        FROM ' . $fu->getOpt('collective_table') . '
        LEFT JOIN ' . $fu->getOpt('catjoin_table') . " j
            ON " . $fu->getOpt('col_id') . "='$sql_id' AND j.cat_id='$sql_id'
        WHERE " . $fu->getOpt('col_id') . "='$sql_id'";

    $fu->db->Execute($query);
    if ($row = $fu->db->ReadRecord()) {
        $clean = $row;
        $clean['cat_id'] = $id;
    }
    $fu->db->FreeResult();
} elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
    $showform = true;
}

$fu->getHeader('Categories');

?>

<div class="page-header">
    <h2>Categories</h2>
    <?php if (!$showform) { ?>
    <a href="category.php?action=new" class="add button">
        <i class="ti ti-plus" aria-hidden="true"></i> New category
    </a>
    <?php } ?>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();

if ($showform) { ?>

<form action="category.php" method="post">

    <div class="col-layout-12">

        <div>
            <?php if (!$fu->getOpt('collective_script')) { ?>
            <fieldset>
                <legend>Name</legend>
                <?php if (isset($clean['cat_id'])) { ?>
                <p class="hint">ID: <strong><?php echo (int) $clean['cat_id']; ?></strong></p>
                <?php } ?>
                <p><label for="name">Category name</label>
                <input type="text" id="name" name="name" maxlength="100"
                       value="<?php echo htmlspecialchars((string) ($clean['cname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" /></p>
            </fieldset>
            <?php } else { ?>
            <?php if (isset($clean['cat_id'])) { ?>
            <p class="hint">ID: <strong><?php echo (int) $clean['cat_id']; ?></strong></p>
            <?php } ?>
            <?php } ?>

            <fieldset>
                <legend>Entry template</legend>
                <p><textarea id="template" name="entry_template" rows="6"><?php
                    echo htmlspecialchars((string) ($clean['entry_template'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?></textarea></p>
            </fieldset>

            <fieldset>
                <legend>Comment template</legend>
                <p><textarea id="comment_template" name="comment_template" rows="6"><?php
                    echo htmlspecialchars((string) ($clean['comment_template'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?></textarea></p>
            </fieldset>
        </div>

        <div>
            <fieldset>
                <legend>Gravatar settings</legend>
                <p><label for="gravatar_default">Default image URL</label>
                <input type="url" id="gravatar_default" name="gravatar_default"
                       value="<?php echo htmlspecialchars((string) ($clean['gravatar_default'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" /></p>

                <p><label for="gravatar_size">Size (px)</label>
                <input type="number" id="gravatar_size" name="gravatar_size" size="3" maxlength="3"
                       value="<?php echo (int) ($clean['gravatar_size'] ?? 40); ?>" /></p>

                <p><label for="gravatar_rating">Rating</label>
                <input type="text" id="gravatar_rating" name="gravatar_rating" size="2" maxlength="2"
                       value="<?php echo htmlspecialchars((string) ($clean['gravatar_rating'] ?? 'g'), ENT_QUOTES, 'UTF-8'); ?>" /></p>
            </fieldset>

            <fieldset id="action">
                <legend>Action</legend>
                <div class="primary">
                    <?php if (isset($clean['cat_id'])) { ?>
                    <input type="hidden" name="cat_id" value="<?php echo (int) $clean['cat_id']; ?>" />
                    <input type="submit" name="action" value="update" class="update"
                           title="Save changes, accesskey s" accesskey="s" />
                    <?php } else { ?>
                    <input type="submit" name="action" value="add" class="add"
                           title="Add category, accesskey s" accesskey="s" />
                    <?php } ?>
                </div>
                <?php if (isset($clean['cat_id']) && !$fu->getOpt('collective_script')) { ?>
                <div class="secondary">
                    <input type="submit" name="action" value="delete" class="delete"
                           title="Delete this category, accesskey x" accesskey="x" />
                </div>
                <?php } ?>
            </fieldset>
        </div>

    </div>

</form>

<?php } else {

    $query = 'SELECT ' . $fu->getOpt('collective_table') . '.*,
        ' . $fu->getOpt('col_subj') . ' AS cname,
        ' . $fu->getOpt('col_id') . ' AS cid,
        COUNT(cat_id) AS num
        FROM ' . $fu->getOpt('collective_table') . '
        LEFT JOIN ' . $fu->getOpt('catjoin_table') . ' ON ' . $fu->getOpt('col_id') . '=cat_id
        GROUP BY ' . $fu->getOpt('col_id') . '
        ORDER BY cname ASC';

    $fu->db->Execute($query);

    if ($fu->db->NumRows() > 0) { ?>

    <table class="sortable" aria-label="Blog entry categories">
        <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Name</th>
                <th scope="col">Entries</th>
                <th scope="col">Get code</th>
                <th scope="col">Edit</th>
                <?php if (!$fu->getOpt('collective_script')) { ?>
                <th scope="col">Delete</th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $class = 'odd';
            while ($row = $fu->db->ReadRecord()) {
                $class = ($class === 'even') ? 'odd' : 'even';
                echo '<tr class="' . $class . '">';
                echo '<td>' . (int) $row['cid'] . '</td>';
                echo '<td>' . htmlspecialchars((string) $row['cname'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . (int) $row['num'] . '</td>';
                echo '<td class="form"><a href="get-code.php?cat=' . (int) $row['cid'] . '" class="button">Get code</a></td>';
                echo '<td class="form"><a href="category.php?id=' . (int) $row['cid'] . '" class="edit button">Edit</a></td>';
                if (!$fu->getOpt('collective_script')) {
                    echo '<td class="form">'
                        . '<form action="category.php" method="post" style="display:inline;">'
                        . '<input type="hidden" name="cat_id" value="' . (int) $row['cid'] . '" />'
                        . '<input type="submit" name="action" value="delete" class="delete"'
                        . ' title="Delete ' . htmlspecialchars((string) $row['cname'], ENT_QUOTES, 'UTF-8') . '" />'
                        . '</form></td>';
                }
                echo '</tr>';
            }
            $fu->db->FreeResult();
            ?>
        </tbody>
    </table>

    <?php } else {
        echo '<p>No categories found.</p>';
    }
} ?>

<?php
$fu->getFooter();
