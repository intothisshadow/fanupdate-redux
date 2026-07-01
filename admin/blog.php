<?php

/*****************************************************************************
 * FanUpdate — admin/blog.php
 *
 * Milestone 3 — Admin UI Cleanup (nested forms):
 *  - The entry list previously wrapped the whole table (including each row's
 *    own <form>...delete...</form>) inside an outer
 *    <form id="blog-select-form">. Nested <form> elements are invalid HTML5 —
 *    per the parsing spec, the browser ignores the inner <form> start tag
 *    (the "form element pointer" is already set), so the inner hidden id
 *    field and delete button silently become children of the OUTER form,
 *    and the inner </form> close tag prematurely closes the outer form
 *    instead. This left rows after the first delete button outside any form
 *    at all in the parsed DOM.
 *  - Fixed by making the outer "select" form the ONLY form around the list:
 *    it now has a real purpose (bulk delete of checked entries) with a
 *    "Delete selected" submit button. Per-row "Delete" is now a plain link
 *    (not a form) to a new confirm-delete view, so nothing nests.
 *  - Destructive actions (single delete, bulk delete) now go through a
 *    dedicated server-side confirmation view with its own single, valid
 *    <form>, rather than relying on a JS confirm() dialog as the only
 *    safeguard. This works with JavaScript disabled.
 *
 * Bug fix (Redux 2.0.1):
 *  - Added "Allow comments" toggle to the new-entry and edit-entry forms.
 *    The blog table has a comments_on column and FanUpdate_Post::commentsOn()
 *    reads it, but the admin form never exposed it — every entry was saved
 *    with comments_on = 0 (off) because the field was absent from the form
 *    and therefore absent from both INSERT and UPDATE queries.
 *    Fix: read comments_on from POST, include it in INSERT/UPDATE, and render
 *    a <select> for it in the Settings fieldset.
 *
 * Bug fix (this release):
 *  - clean_input($_POST['body']) was called without allowtags=true, causing
 *    strip_tags() to remove every HTML tag from the post body on save.
 *    Images inserted via the WYSIWYG image button (e.g.
 *    <a href="…" class="fu-lightbox"><img src="…" /></a>) were silently
 *    stripped on the first save. Fixed: body cleaned with allowtags=true.
 *
 * Bug fix (this release):
 *  - New-entry date default used date('Y-m-d H:i:s') — PHP server local time.
 *    show-blog.php filters with WHERE added <= UTC_TIMESTAMP(). On servers
 *    whose PHP timezone is ahead of UTC (e.g. UTC+3 in Finland) new entries
 *    got a local timestamp that appeared to be in the future relative to UTC,
 *    so they were excluded from the front-end query and never shown to visitors.
 *    Fixed: default changed to gmdate('Y-m-d H:i:s') (UTC), matching the
 *    UTC_TIMESTAMP() comparator. The date field in the form still shows the
 *    UTC timestamp for the admin to edit; timezone_offset handles display
 *    conversion at render time in FanUpdate_Post::getDateFormatted().
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

// Delete-confirmation view state (single entry).
$confirmDeleteId    = 0;
$confirmDeleteTitle = '';

// Delete-confirmation view state (bulk).
/** @var int[] */
$confirmBulkIds = [];

// ─────────────────────────────────────────────────────────────────────────────
// POST / GET handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action'])) {

    $clean['title']      = isset($_POST['title'])      ? clean_input($_POST['title'])            : '';
    // allowtags=true: the body is HTML — images, links, bold, etc. must be preserved.
    // clean_input() without this flag calls strip_tags(), which silently strips every
    // <a>, <img>, <strong>, etc. on save, breaking the WYSIWYG output.
    $clean['body']       = isset($_POST['body'])        ? clean_input($_POST['body'], true)       : '';
    $clean['cat']        = isset($_POST['cat'])         ? (int) $_POST['cat']               : 0;
    $clean['is_public']    = isset($_POST['is_public'])     ? (int) $_POST['is_public']         : 0;
    $clean['comments_on'] = isset($_POST['comments_on'])   ? (int) $_POST['comments_on']       : 0;
    // 'id' is scalar for single-entry actions (add/update/delete/preview) and an
    // array (id[]) for bulk-delete actions — never both on the same request.
    $clean['id']         = (isset($_POST['id']) && !is_array($_POST['id'])) ? (int) $_POST['id'] : 0;
    $clean['date']       = isset($_POST['date'])        ? clean_input($_POST['date'])        : '';

    /** @var int[] $bulkIds */
    $bulkIds = [];
    if (isset($_POST['id']) && is_array($_POST['id'])) {
        $bulkIds = array_values(array_unique(array_filter(
            array_map('intval', $_POST['id']),
            static fn (int $v): bool => $v > 0
        )));
    }

    if ($_POST['action'] === 'preview') {

        $showform = true;
        // Preview is rendered below the form using $clean values

    } elseif ($_POST['action'] === 'add') {

        // blog table has no cat_id column — category is stored in catjoin
        $query = "INSERT INTO " . $fu->getOpt('blog_table') . "
            (title, body, is_public, comments_on, added)
            VALUES (?, ?, ?, ?, ?)";

        if ($fu->db->Execute($query, 'Could not execute query.', [
            $clean['title'],
            $clean['body'],
            (int) $clean['is_public'],
            (int) $clean['comments_on'],
            $clean['date'],
        ])) {
            $new_entry_id = $fu->db->GetLastSequence();
            // Write the category link into the catjoin table
            if ($clean['cat'] > 0) {
                $fu->db->Execute(
                    "INSERT INTO " . $fu->getOpt('catjoin_table') . "
                    (entry_id, cat_id) VALUES (?, ?)",
                    'Could not execute query.',
                    [$new_entry_id, (int) $clean['cat']]
                );
            }
            $fu->addSuccess('Entry <strong>' . htmlspecialchars($clean['title'], ENT_QUOTES, 'UTF-8') . '</strong> added.');
        } else {
            $fu->addErr('Failed to add entry.');
            $showform = true;
        }
    } elseif ($_POST['action'] === 'update') {

        // blog table has no cat_id column — update post fields only
        $query = "UPDATE " . $fu->getOpt('blog_table') . "
            SET title = ?, body = ?,
                is_public = ?,
                comments_on = ?,
                added = ?
            WHERE entry_id = ?";

        if ($fu->db->Execute($query, 'Could not execute query.', [
            $clean['title'],
            $clean['body'],
            (int) $clean['is_public'],
            (int) $clean['comments_on'],
            $clean['date'],
            (int) $clean['id'],
        ])) {
            // Replace the category link in catjoin
            $fu->db->Execute(
                "DELETE FROM " . $fu->getOpt('catjoin_table') . "
                WHERE entry_id = ?",
                'Could not execute query.',
                [(int) $clean['id']]
            );
            if ($clean['cat'] > 0) {
                $fu->db->Execute(
                    "INSERT INTO " . $fu->getOpt('catjoin_table') . "
                    (entry_id, cat_id) VALUES (?, ?)",
                    'Could not execute query.',
                    [(int) $clean['id'], (int) $clean['cat']]
                );
            }
            $fu->addSuccess('Entry <strong>' . htmlspecialchars($clean['title'], ENT_QUOTES, 'UTF-8') . '</strong> updated.');
        } else {
            $fu->addErr('Failed to update entry.');
            $showform = true;
        }
    } elseif ($_POST['action'] === 'delete') {

        // Reached only from the single-entry confirm-delete view's form.
        $fu->db->Execute("DELETE FROM " . $fu->getOpt('blog_table') . " WHERE entry_id = ?", 'Could not execute query.', [(int) $clean['id']]);
        $fu->db->Execute("DELETE FROM " . $fu->getOpt('catjoin_table') . " WHERE entry_id = ?", 'Could not execute query.', [(int) $clean['id']]);
        $fu->db->Execute("DELETE FROM " . $fu->getOpt('comments_table') . " WHERE entry_id = ?", 'Could not execute query.', [(int) $clean['id']]);
        $fu->addSuccess('Entry deleted.');

    } elseif ($_POST['action'] === 'bulk-delete') {

        // Submitted from the entry list's select form — show a confirmation
        // view listing exactly which entries will be removed before anything
        // is actually deleted.
        if (empty($bulkIds)) {
            $fu->addErr('No entries were selected.');
        } else {
            $confirmBulkIds = $bulkIds;
        }

    } elseif ($_POST['action'] === 'bulk-delete-confirmed') {

        // Reached only from the bulk confirm-delete view's form.
        if (empty($bulkIds)) {
            $fu->addErr('No entries were selected.');
        } else {
            foreach ($bulkIds as $bulkId) {
                $fu->db->Execute("DELETE FROM " . $fu->getOpt('blog_table') . " WHERE entry_id = ?", 'Could not execute query.', [$bulkId]);
                $fu->db->Execute("DELETE FROM " . $fu->getOpt('catjoin_table') . " WHERE entry_id = ?", 'Could not execute query.', [$bulkId]);
                $fu->db->Execute("DELETE FROM " . $fu->getOpt('comments_table') . " WHERE entry_id = ?", 'Could not execute query.', [$bulkId]);
            }
            $count = count($bulkIds);
            $fu->addSuccess($count . ' ' . ($count === 1 ? 'entry' : 'entries') . ' deleted.');
        }
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'confirm-delete' && isset($_GET['id']) && is_numeric($_GET['id'])) {

    // Single-entry delete confirmation view (GET, no side effects).
    $confirmDeleteId = (int) $_GET['id'];
    $query = "SELECT entry_id, title FROM " . $fu->getOpt('blog_table') . " WHERE entry_id = ?";
    $fu->db->Execute($query, 'Could not execute query.', [$confirmDeleteId]);
    if ($row = $fu->db->ReadRecord()) {
        $confirmDeleteTitle = (string) $row['title'];
    } else {
        $confirmDeleteId = 0;
        $fu->addErr('That entry no longer exists.');
    }
    $fu->db->FreeResult();
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {

    $showform = true;
    $id       = (int) $_GET['id'];
    $query    = "SELECT * FROM " . $fu->getOpt('blog_table') . " WHERE entry_id = ?";
    $fu->db->Execute($query, 'Could not execute query.', [$id]);
    if ($row = $fu->db->ReadRecord()) {
        $clean = $row;
    }
    $fu->db->FreeResult();
    // Load the category from catjoin (blog table has no cat_id column)
    $fu->db->Execute(
        "SELECT cat_id FROM " . $fu->getOpt('catjoin_table') . " WHERE entry_id = ? LIMIT 1",
        'Could not execute query.',
        [$id]
    );
    if ($cat_row = $fu->db->ReadRecord()) {
        $clean['cat_id'] = (int) $cat_row['cat_id'];
    }
    $fu->db->FreeResult();
} elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
    $showform = true;
    // gmdate() writes UTC — must match the UTC_TIMESTAMP() comparator in
    // show-blog.php. Using date() (server local time) causes new entries to
    // appear in the future on servers whose PHP timezone is ahead of UTC,
    // which hides them from the front-end query until the clock catches up.
    $clean['date'] = gmdate('Y-m-d H:i:s');
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
        <?php echo $fu->csrfField(); ?>

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
                            value="<?php echo htmlspecialchars((string) ($clean['date'] ?? $clean['added'] ?? gmdate('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?>" />
                    </p>

                    <p><label for="is_public">Status</label>
                        <select id="is_public" name="is_public">
                            <option value="1" <?php echo ((int) ($clean['is_public'] ?? 1)) === 1 ? ' selected' : ''; ?>>Published</option>
                            <option value="0" <?php echo ((int) ($clean['is_public'] ?? 1)) === 0 ? ' selected' : ''; ?>>Draft</option>
                        </select>
                    </p>

                    <p><label for="comments_on">Allow comments</label>
                        <select id="comments_on" name="comments_on">
                            <option value="1" <?php echo ((int) ($clean['comments_on'] ?? 1)) === 1 ? ' selected' : ''; ?>>Yes</option>
                            <option value="0" <?php echo ((int) ($clean['comments_on'] ?? 1)) === 0 ? ' selected' : ''; ?>>No</option>
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
                            <a href="blog.php?action=confirm-delete&id=<?php echo $entry_id; ?>" class="delete button"
                                title="Delete this entry, accesskey x" accesskey="x">Delete</a>
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

<?php } elseif ($confirmDeleteId > 0) {

    // ── Single-entry delete confirmation ────────────────────────────────────
?>

    <fieldset>
        <legend>Confirm deletion</legend>
        <p>
            Are you sure you want to delete
            <strong><?php echo htmlspecialchars($confirmDeleteTitle, ENT_QUOTES, 'UTF-8'); ?></strong>?
            This cannot be undone, and all comments on this entry will also be deleted.
        </p>

        <form action="blog.php" method="post">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="id" value="<?php echo $confirmDeleteId; ?>" />
            <div class="primary">
                <input type="submit" name="action" value="delete" class="delete" data-skip-confirm="1"
                    title="Permanently delete this entry, accesskey s" accesskey="s" />
                <a href="blog.php" class="button">Cancel</a>
            </div>
        </form>
    </fieldset>

<?php } elseif (!empty($confirmBulkIds)) {

    // ── Bulk delete confirmation ────────────────────────────────────────────

    $placeholders = implode(',', array_fill(0, count($confirmBulkIds), '?'));
    $fu->db->Execute(
        "SELECT entry_id, title FROM " . $fu->getOpt('blog_table') . " WHERE entry_id IN ($placeholders)",
        'Could not execute query.',
        $confirmBulkIds
    );
    $confirmRows = [];
    while ($row = $fu->db->ReadRecord()) {
        $confirmRows[] = $row;
    }
    $fu->db->FreeResult();

    $bulkCount = count($confirmBulkIds);
?>

    <fieldset>
        <legend>Confirm deletion</legend>
        <p>
            Are you sure you want to delete the following <?php echo $bulkCount; ?>
            <?php echo $bulkCount === 1 ? 'entry' : 'entries'; ?>?
            This cannot be undone, and all comments on these entries will also be deleted.
        </p>

        <ul class="catlist">
            <?php foreach ($confirmRows as $row) { ?>
                <li><?php echo htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8'); ?></li>
            <?php } ?>
        </ul>

        <form action="blog.php" method="post">
            <?php echo $fu->csrfField(); ?>
            <?php foreach ($confirmBulkIds as $bulkId) { ?>
                <input type="hidden" name="id[]" value="<?php echo (int) $bulkId; ?>" />
            <?php } ?>
            <div class="primary">
                <input type="submit" name="action" value="bulk-delete-confirmed" class="delete" data-skip-confirm="1"
                    title="Permanently delete these entries, accesskey s" accesskey="s" />
                <a href="blog.php" class="button">Cancel</a>
            </div>
        </form>
    </fieldset>

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
        <?php echo $fu->csrfField(); ?>

        <?php
        // Build the query:
        // catjoin LEFT JOIN to get cat info, comments LEFT JOIN for counts.
        // Category filter requires an additional JOIN to avoid ambiguous cat_id.
        $entry_query = 'SELECT b.entry_id, b.title, b.is_public, b.comments_on, b.added,
     COUNT(DISTINCT com.comment_id) AS num_comments
     FROM ' . $fu->getOpt('blog_table') . ' b
     LEFT JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id = j.entry_id
     LEFT JOIN ' . $fu->getOpt('comments_table') . ' com ON b.entry_id = com.entry_id';

        $entry_params = [];

        if ($filter_cat > 0) {
            $entry_query .= ' JOIN ' . $fu->getOpt('catjoin_table') . ' cat ON b.entry_id = cat.entry_id AND cat.cat_id = ?';
            $entry_params[] = $filter_cat;
        }

        if ($filter_search !== '') {
            $entry_query .= ' WHERE MATCH (b.title, b.body) AGAINST (? IN BOOLEAN MODE)';
            $entry_params[] = $filter_search;
        }

        $entry_query .= ' GROUP BY b.entry_id ORDER BY b.added DESC';

        $fu->db->FreeResult();
        $fu->db->ExecutePaginate($entry_query, (int) $fu->getOpt('num_per_page') ?: 20, $entry_params);

        if ($fu->db->total_results > 0) { ?>

            <div style="display:flex; justify-content:flex-end; margin-bottom:0.75rem;">
                <input type="submit" name="action" value="bulk-delete" class="delete" data-skip-confirm="1"
                    title="Delete all checked entries" />
            </div>

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
                            . '<a href="blog.php?action=confirm-delete&id=' . $post->getID() . '" class="delete button" data-skip-confirm="1"'
                            . ' title="Delete \'' . htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8') . '\'">Delete</a></td>';
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
