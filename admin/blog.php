<?php

/*****************************************************************************
 * FanUpdate — admin/blog.php
 *
 * Bug fix (Redux 1.5.2):
 *  - Added class="wysiwyg" to the body <textarea> so fanupdate-admin.js
 *    can attach the WYSIWYG toolbar (makeWysiwyg targets textarea.wysiwyg).
 *    Previously the textarea had no class, so the toolbar was never inserted
 *    and the JS call to makeWysiwyg was silently a no-op.
 *  - Category <select> on new-entry form was using $clean['cat_id'] for
 *    selected state, but on a new entry $clean has no 'cat_id' key.  Changed
 *    to check $clean['cat'] first (set on POST) then $clean['cat_id'] (set
 *    when editing an existing entry loaded from DB).
 *
 * HTML modernisation (unchanged from prior version):
 *  - PNG tick/cross images → CSS badge spans
 *  - <table summary=""> → <table aria-label="">
 *  - type="text/javascript" removed from <script> tags
 *  - page-header row with new-entry-btn replacing new-entry-button.png
 *  - All other PHP/SQL logic preserved exactly
 ******************************************************************************/

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
// POST / GET handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action'])) {

    $clean['title']      = isset($_POST['title'])      ? clean_input($_POST['title'])      : '';
    $clean['body']       = isset($_POST['body'])        ? clean_input($_POST['body'])        : '';
    $clean['cat']        = isset($_POST['cat'])         ? (int) $_POST['cat']               : 0;
    $clean['is_public']  = isset($_POST['is_public'])   ? (int) $_POST['is_public']         : 0;
    $clean['id']         = isset($_POST['id'])          ? (int) $_POST['id']                : 0;
    $clean['date']       = isset($_POST['date'])        ? clean_input($_POST['date'])        : '';

    $sql_title  = $fu->db->Escape($clean['title']);
    $sql_body   = $fu->db->Escape($clean['body']);

    if ($_POST['action'] === 'preview') {

        $showform = true;
        // Preview is rendered below the form using $clean values

    } elseif ($_POST['action'] === 'add') {

        $query = "INSERT INTO " . $fu->getOpt('blog_table') . "
            (title, body, cat_id, is_public, added)
            VALUES ('$sql_title','$sql_body','" . (int)$clean['cat'] . "','" . (int)$clean['is_public'] . "','" . $fu->db->Escape($clean['date']) . "')";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Entry <strong>' . htmlspecialchars($clean['title'], ENT_QUOTES, 'UTF-8') . '</strong> added.');
        } else {
            $fu->addErr('Failed to add entry.');
            $showform = true;
        }
    } elseif ($_POST['action'] === 'update') {

        $query = "UPDATE " . $fu->getOpt('blog_table') . "
            SET title='$sql_title', body='$sql_body',
                cat_id='" . (int)$clean['cat'] . "', is_public='" . (int)$clean['is_public'] . "',
                added='" . $fu->db->Escape($clean['date']) . "'
            WHERE entry_id='" . (int)$clean['id'] . "'";

        if ($fu->db->Execute($query)) {
            $fu->addSuccess('Entry <strong>' . htmlspecialchars($clean['title'], ENT_QUOTES, 'UTF-8') . '</strong> updated.');
        } else {
            $fu->addErr('Failed to update entry.');
            $showform = true;
        }
    } elseif ($_POST['action'] === 'delete') {

        $fu->db->Execute("DELETE FROM " . $fu->getOpt('blog_table') . " WHERE entry_id='" . (int)$clean['id'] . "'");
        $fu->db->Execute("DELETE FROM " . $fu->getOpt('comments_table') . " WHERE entry_id='" . (int)$clean['id'] . "'");
        $fu->addSuccess('Entry deleted.');
    }
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {

    $showform = true;
    $id       = (int) $_GET['id'];
    $query    = "SELECT * FROM " . $fu->getOpt('blog_table') . " WHERE entry_id='$id'";
    $fu->db->Execute($query);
    if ($row = $fu->db->ReadRecord()) {
        $clean = $row;
    }
    $fu->db->FreeResult();
} elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
    $showform = true;
    $clean['date'] = date('Y-m-d H:i:s');
}

// ─────────────────────────────────────────────────────────────────────────────
// Category search filter
// ─────────────────────────────────────────────────────────────────────────────

$filter_cat    = isset($_GET['cat'])    && is_numeric($_GET['cat']) ? (int) $_GET['cat']         : 0;
$filter_search = isset($_GET['search']) ? clean_input($_GET['search']) : '';

$fu->getHeader('Entries');
?>

<div class="page-header">
    <h2>Entries</h2>
    <a href="blog.php?action=new" id="new-entry-btn">
        <i class="ti ti-plus" aria-hidden="true"></i> New entry
    </a>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();

// ── Add/Edit form ─────────────────────────────────────────────────────────

if ($showform) {
    $entry_id = isset($clean['entry_id']) ? (int) $clean['entry_id'] : 0;
?>

    <form action="blog.php" method="post">

        <div class="col-layout-12">

            <div>
                <fieldset>
                    <legend>Entry</legend>

                    <p><label for="title">Title</label>
                        <input type="text" id="title" name="title" maxlength="200"
                            value="<?php echo htmlspecialchars((string) ($clean['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            accesskey="n" />
                    </p>

                    <p><label for="body">Body</label>
                        <?php $fu->printWysiwyg('body'); ?>
                        <textarea id="body" name="body" rows="16" class="wysiwyg"><?php
                            echo htmlspecialchars((string) ($clean['body'] ?? ''), ENT_QUOTES, 'UTF-8');
                        ?></textarea>
                    </p>
                </fieldset>
            </div>

            <div>
                <fieldset>
                    <legend>Settings</legend>

                    <p><label for="cat">Category</label>
                        <select name="cat" id="cat">
                            <?php
                            $fu->db->Execute(
                                'SELECT ' . $fu->getOpt('col_id') . ' AS cat_id, '
                                . $fu->getOpt('col_subj') . ' AS cat_name FROM '
                                . $fu->getOpt('collective_table') . ' ORDER BY cat_name ASC'
                            );
                            // Determine the currently selected category:
                            // - On POST (preview/retry): $clean['cat'] is set
                            // - On edit (GET ?id=N): $clean['cat_id'] is the DB column
                            // - On new (GET ?action=new): neither is set → 0
                            $selected_cat = (int) ($clean['cat'] ?? $clean['cat_id'] ?? 0);
                            while ($cat_row = $fu->db->ReadRecord()) {
                                $sel = ($selected_cat === (int) $cat_row['cat_id']) ? ' selected' : '';
                                echo '<option value="' . (int) $cat_row['cat_id'] . '"' . $sel . '>'
                                    . htmlspecialchars((string) $cat_row['cat_name'], ENT_QUOTES, 'UTF-8')
                                    . '</option>';
                            }
                            $fu->db->FreeResult();
                            ?>
                        </select>
                    </p>

                    <p><label for="date">Date</label>
                        <input type="text" id="date" name="date"
                            value="<?php echo htmlspecialchars((string) ($clean['date'] ?? $clean['added'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?>" />
                    </p>

                    <p><label for="is_public">Status</label>
                        <select id="is_public" name="is_public">
                            <option value="1" <?php echo ((int) ($clean['is_public'] ?? 1)) === 1 ? ' selected' : ''; ?>>Published</option>
                            <option value="0" <?php echo ((int) ($clean['is_public'] ?? 1)) === 0 ? ' selected' : ''; ?>>Draft</option>
                        </select>
                    </p>
                </fieldset>

                <fieldset id="action">
                    <legend>Action</legend>
                    <div class="primary">
                        <input type="submit" name="action" value="preview" class="button"
                            title="Preview entry, accesskey p" accesskey="p" />
                        <?php if ($entry_id > 0) { ?>
                            <input type="hidden" name="id" value="<?php echo $entry_id; ?>" />
                            <input type="submit" name="action" value="update" class="update"
                                title="Save changes, accesskey s" accesskey="s" />
                        <?php } else { ?>
                            <input type="submit" name="action" value="add" class="add"
                                title="Add entry, accesskey s" accesskey="s" />
                        <?php } ?>
                    </div>
                    <?php if ($entry_id > 0) { ?>
                        <div class="secondary">
                            <input type="submit" name="action" value="delete" class="delete"
                                title="Delete this entry, accesskey x" accesskey="x" />
                        </div>
                    <?php } ?>
                </fieldset>

                <?php if (isset($_POST['action']) && $_POST['action'] === 'preview') { ?>
                    <div id="preview">
                        <h3>Preview</h3>
                        <div>
                            <?php
                            $preview_post = new FanUpdate_Post($clean, $fu, $fu->getOpt('blog_page'));
                            $preview_post->printPost();
                            ?>
                        </div>
                    </div>
                <?php } ?>
            </div>

        </div>

    </form>

<?php } else {

    // ── Entry list ────────────────────────────────────────────────────────────

?>

    <div style="display:flex; gap:1rem; align-items:flex-end; margin-bottom:1.25rem; flex-wrap:wrap;">
        <form action="blog.php" method="get" style="display:flex; gap:8px; align-items:flex-end;">
            <div>
                <label for="cat_filter" style="display:block;">Filter by category</label>
                <select name="cat" id="cat_filter">
                    <option value="">All categories</option>
                    <?php
                    $fu->db->Execute(
                        'SELECT ' . $fu->getOpt('col_id') . ' AS cat_id, '
                        . $fu->getOpt('col_subj') . ' AS cat_name FROM '
                        . $fu->getOpt('collective_table') . ' ORDER BY cat_name ASC'
                    );
                    while ($cat_row = $fu->db->ReadRecord()) {
                        $selected = ($filter_cat === (int) $cat_row['cat_id']) ? ' selected' : '';
                        echo '<option value="' . (int) $cat_row['cat_id'] . '"'
                            . $selected . '>' . htmlspecialchars((string) $cat_row['cat_name'], ENT_QUOTES, 'UTF-8') . '</option>';
                    }
                    $fu->db->FreeResult();
                    ?>
                </select>
            </div>
            <input type="submit" value="Filter" class="button" />
            <?php if ($filter_cat > 0) { ?>
                <a href="blog.php" class="button">Clear</a>
            <?php } ?>
        </form>

        <form action="blog.php" method="get" style="display:flex; gap:8px; align-items:flex-end;">
            <div>
                <label for="search" style="display:block;">Search</label>
                <input type="text" id="search" name="search" style="width:auto;"
                    value="<?php echo htmlspecialchars($filter_search, ENT_QUOTES, 'UTF-8'); ?>"
                    accesskey="q" />
            </div>
            <input type="submit" value="Search" class="button" title="Search entries, accesskey q" />
        </form>
    </div>

    <form action="blog.php" method="post" id="blog-select-form">

        <?php
        // Build the query:
        // catjoin LEFT JOIN to get cat info, comments LEFT JOIN for counts.
        // Category filter requires an additional JOIN to avoid ambiguous cat_id.
        $entry_query = 'SELECT b.entry_id, b.title, b.is_public, b.comments_on, b.added,
     COUNT(DISTINCT com.comment_id) AS num_comments
     FROM ' . $fu->getOpt('blog_table') . ' b
     LEFT JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id = j.entry_id
     LEFT JOIN ' . $fu->getOpt('comments_table') . ' com ON b.entry_id = com.entry_id';

        if ($filter_cat > 0) {
            $entry_query .= ' JOIN ' . $fu->getOpt('catjoin_table') . " cat ON b.entry_id = cat.entry_id AND cat.cat_id = $filter_cat";
        }

        if ($filter_search !== '') {
            $sql_search = $fu->db->Escape($filter_search);
            $entry_query .= " WHERE MATCH (b.title, b.body) AGAINST ('$sql_search' IN BOOLEAN MODE)";
        }

        $entry_query .= ' GROUP BY b.entry_id ORDER BY b.added DESC';

        $fu->db->FreeResult();
        $fu->db->ExecutePaginate($entry_query, (int) $fu->getOpt('num_per_page') ?: 20);

        if ($fu->db->total_results > 0) { ?>

            <table class="sortable" aria-label="Blog entries">
                <thead>
                    <tr>
                        <th scope="col" class="nosort"><input type="checkbox" id="select-all" title="Select all" /></th>
                        <th scope="col">Title</th>
                        <th scope="col">Category</th>
                        <th scope="col">Date</th>
                        <th scope="col">Comments</th>
                        <th scope="col">Public</th>
                        <th scope="col" class="nosort" colspan="2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $class = 'odd';
                    while ($row = $fu->db->ReadRecordPaginate()) {
                        $post  = new FanUpdate_Post($row, $fu, $fu->getOpt('blog_page'));
                        $post->getCatFromDb();
                        $class = ($class === 'even') ? 'odd' : 'even';

                        $public_badge = $post->isPublic()
                            ? '<span class="badge badge-success">published</span>'
                            : '<span class="badge badge-muted">draft</span>';

                        echo '<tr class="' . $class . '">';
                        echo '<td class="form"><input type="checkbox" name="id[]" value="' . $post->getID() . '" /></td>';
                        echo '<td><a href="blog.php?id=' . $post->getID() . '">'
                            . htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8') . '</a></td>';
                        echo '<td>' . htmlspecialchars($post->getCategoryString(), ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td class="hint small">' . htmlspecialchars($post->getDateFormatted(), ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td><a href="comment.php?entry=' . $post->getID() . '" title="See comments">'
                            . (int) $row['num_comments'] . '</a></td>';
                        echo '<td>' . $public_badge . '</td>';
                        echo '<td class="form"><a href="blog.php?id=' . $post->getID() . '" class="edit button">Edit</a></td>';
                        echo '<td class="form">'
                            . '<form action="blog.php" method="post" style="display:inline;">'
                            . '<input type="hidden" name="id" value="' . $post->getID() . '" />'
                            . '<input type="submit" name="action" value="delete" class="delete"'
                            . ' title="Delete \'' . htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8') . '\'" />'
                            . '</form></td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>

            <p class="paginate"><?php $fu->db->PrintPaginate(); ?></p>

        <?php } else {
            echo '<p>No entries found.</p>';
        }

        $fu->db->FreeResult();
        ?>

    </form>

<?php } ?>

<script src="../assets/js/select-all.js"></script>
<script src="../assets/js/fanupdate-post-admin.js"></script>

<?php
$fu->getFooter();
