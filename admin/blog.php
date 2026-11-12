<?php

/*****************************************************************************
 * FanUpdate — admin/blog.php
 *
 * Blog entry CRUD (add/edit/preview/delete, single and bulk) and the entry
 * list with category filter and search. Delegates persistence to
 * BlogService/BlogRepository; this page parses/sanitises $_POST and $_GET,
 * enforces CSRF, and renders. Category dropdowns come from
 * CategoryService::listForDropdown() (standalone and collective together).
 *
 * Deletes (single and bulk) use a server-side confirmation view with its own
 * <form>, so they work without JavaScript. Per-row delete is a link, not a
 * nested <form> (invalid HTML5).
 *
 * Non-obvious behaviour:
 *  - The body is HTML from the TinyMCE editor, so it is cleaned with
 *    clean_input(..., allowtags: true).
 *  - New-entry dates default to gmdate() (UTC) to match show-blog.php's
 *    UTC_TIMESTAMP() comparison; a local default would hide new entries on
 *    servers ahead of UTC.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/BlogRepositoryInterface.php');
require_once('../class/Repository/BlogRepository.php');
require_once('../class/Service/BlogService.php');
require_once('../class/Repository/CategoryRepositoryInterface.php');
require_once('../class/Repository/CategoryRepository.php');
require_once('../class/Service/CategoryService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$blogService = new BlogService(new BlogRepository(
    $fu->db,
    (string) $fu->getOpt('blog_table'),
    (string) $fu->getOpt('catjoin_table'),
    (string) $fu->getOpt('comments_table')
));

// Constructed exactly as in admin/category.php.
$standaloneTable = $fu->hasCollectiveScript() ? ((string) $fu->getOpt('standalone_category_table') ?: null) : null;

$categoryService = new CategoryService(new CategoryRepository(
    $fu->db,
    (string) $fu->getOpt('collective_table'),
    (string) $fu->getOpt('catjoin_table'),
    (string) $fu->getOpt('catoptions_table'),
    (string) $fu->getOpt('col_id'),
    (string) $fu->getOpt('col_subj'),
    $standaloneTable,
    $fu->showCollectiveCategories()
));

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
    // allowtags=true: without it strip_tags() would remove the WYSIWYG markup.
    $clean['body']       = isset($_POST['body'])        ? clean_input($_POST['body'], true)       : '';
    // Checkbox list (cat[]): any number of categories, including none.
    $clean['cat']        = [];
    if (isset($_POST['cat']) && is_array($_POST['cat'])) {
        $clean['cat'] = array_values(array_unique(array_filter(
            array_map('intval', $_POST['cat']),
            static fn (int $v): bool => $v > 0
        )));
    }
    $clean['is_public']    = isset($_POST['is_public'])     ? (int) $_POST['is_public']         : 0;
    $clean['comments_on'] = isset($_POST['comments_on'])   ? (int) $_POST['comments_on']       : 0;
    // 'id' is scalar for single-entry actions, id[] for bulk delete.
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

    } elseif ($_POST['action'] === 'add') {

        $newEntryId = $blogService->createEntry([
            'title'       => $clean['title'],
            'body'        => $clean['body'],
            'cat'         => $clean['cat'],
            'is_public'   => $clean['is_public'],
            'comments_on' => $clean['comments_on'],
            'date'        => $clean['date'],
        ]);

        if ($newEntryId > 0) {
            $fu->logEvent(LogService::ENTRY_CREATED, 'Entry #' . $newEntryId . ' "' . $clean['title'] . '" created.');
            $fu->addSuccess('Entry <strong>' . htmlspecialchars($clean['title'], ENT_QUOTES, 'UTF-8') . '</strong> added.');
        } else {
            $fu->addErr('Failed to add entry.');
            $showform = true;
        }
    } elseif ($_POST['action'] === 'update') {

        // blog table has no cat_id column — BlogService::updateEntry()
        // replaces the catjoin link after a successful update.
        $ok = $blogService->updateEntry($clean['id'], [
            'title'       => $clean['title'],
            'body'        => $clean['body'],
            'cat'         => $clean['cat'],
            'is_public'   => $clean['is_public'],
            'comments_on' => $clean['comments_on'],
            'date'        => $clean['date'],
        ]);

        if ($ok) {
            $fu->logEvent(LogService::ENTRY_UPDATED, 'Entry #' . $clean['id'] . ' "' . $clean['title'] . '" updated.');
            $fu->addSuccess('Entry <strong>' . htmlspecialchars($clean['title'], ENT_QUOTES, 'UTF-8') . '</strong> updated.');
        } else {
            $fu->addErr('Failed to update entry.');
            $showform = true;
        }
    } elseif ($_POST['action'] === 'delete') {

        // Reached only from the single-entry confirm-delete view's form.
        $blogService->deleteEntry($clean['id']);
        $fu->logEvent(LogService::ENTRY_DELETED, 'Entry #' . $clean['id'] . ' deleted.');
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
            $blogService->deleteEntries($bulkIds);
            $count = count($bulkIds);
            $fu->logEvent(LogService::ENTRY_DELETED, $count . ' ' . ($count === 1 ? 'entry' : 'entries') . ' deleted (#' . implode(', #', $bulkIds) . ').');
            $fu->addSuccess($count . ' ' . ($count === 1 ? 'entry' : 'entries') . ' deleted.');
        }
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'confirm-delete' && isset($_GET['id']) && is_numeric($_GET['id'])) {

    // Single-entry delete confirmation view (GET, no side effects).
    $confirmDeleteId = (int) $_GET['id'];
    $confirmation    = $blogService->getDeleteConfirmation($confirmDeleteId);
    if ($confirmation !== null) {
        $confirmDeleteTitle = $confirmation['title'];
    } else {
        $confirmDeleteId = 0;
        $fu->addErr('That entry no longer exists.');
    }
} elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {

    $showform = true;
    $id       = (int) $_GET['id'];
    $entry    = $blogService->getEntryForEdit($id);
    if ($entry !== null) {
        $clean = $entry;
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
    $showform = true;
    // UTC, to match show-blog.php's UTC_TIMESTAMP() comparison.
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
                        <textarea id="body" name="body" rows="16"><?php
                            echo htmlspecialchars((string) ($clean['body'] ?? ''), ENT_QUOTES, 'UTF-8');
                        ?></textarea>
                    </p>
                </fieldset>
            </div>

            <div>
                <fieldset>
                    <legend>Settings</legend>

                    <fieldset>
                        <legend>Category</legend>
                        <?php
                        // Checked: $clean['cat'] after a POST, $clean['cat_ids'] on edit, none on new.
                        $selected_cats = array_map('intval', $clean['cat'] ?? $clean['cat_ids'] ?? []);
                        ?>
                        <ul id="categories" class="catlist select_all">
                            <?php
                            foreach ($categoryService->listForDropdown() as $cat_row) {
                                $checked = in_array($cat_row['cid'], $selected_cats, true) ? ' checked' : '';
                                $label = $cat_row['cname'];
                                if ($standaloneTable !== null) {
                                    // Hybrid mode: distinguish source in the label.
                                    $label .= $categoryService->isStandaloneCategory($cat_row['cid'])
                                        ? ' (Standalone)'
                                        : ' (Collective)';
                                }
                                echo '<li><label><input type="checkbox" name="cat[]" value="' . $cat_row['cid'] . '"' . $checked . ' /> '
                                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                                    . '</label></li>';
                            }
                            ?>
                        </ul>
                    </fieldset>

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
                        <button type="submit" name="action" value="preview" class="button"
                            title="Preview entry, accesskey p" accesskey="p">Preview</button>
                        <?php if ($entry_id > 0) { ?>
                            <input type="hidden" name="id" value="<?php echo $entry_id; ?>" />
                            <button type="submit" name="action" value="update" class="update"
                                title="Save changes, accesskey s" accesskey="s">Update</button>
                        <?php } else { ?>
                            <button type="submit" name="action" value="add" class="add"
                                title="Add entry, accesskey s" accesskey="s">Add</button>
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

    <?php
    // Advanced settings not in Admin -> Options: add editor_min_height /
    // editor_max_height rows to the options table to override the defaults.
    $editor_min_height_opt = $fu->getOpt('editor_min_height');
    $editor_max_height_opt = $fu->getOpt('editor_max_height');
    $editor_min_height     = ($editor_min_height_opt !== false && (int) $editor_min_height_opt > 0) ? (int) $editor_min_height_opt : 300;
    $editor_max_height     = ($editor_max_height_opt !== false && (int) $editor_max_height_opt > 0) ? (int) $editor_max_height_opt : 800;
    ?>
    <div id="fu-editor-config" hidden
         data-min-height="<?php echo $editor_min_height; ?>"
         data-max-height="<?php echo $editor_max_height; ?>"></div>
    <script src="../assets/vendor/tinymce/tinymce.min.js"></script>
    <script src="../assets/js/fanupdate-tinymce.js"></script>

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
                <button type="submit" name="action" value="delete" class="delete" data-skip-confirm="1"
                    title="Permanently delete this entry, accesskey s" accesskey="s">Delete</button>
                <a href="blog.php" class="button">Cancel</a>
            </div>
        </form>
    </fieldset>

<?php } elseif (!empty($confirmBulkIds)) {

    // ── Bulk delete confirmation ────────────────────────────────────────────

    $confirmRows = $blogService->getBulkDeleteConfirmation($confirmBulkIds);
    $bulkCount   = count($confirmBulkIds);
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
                <li><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></li>
            <?php } ?>
        </ul>

        <form action="blog.php" method="post">
            <?php echo $fu->csrfField(); ?>
            <?php foreach ($confirmBulkIds as $bulkId) { ?>
                <input type="hidden" name="id[]" value="<?php echo (int) $bulkId; ?>" />
            <?php } ?>
            <div class="primary">
                <button type="submit" name="action" value="bulk-delete-confirmed" class="delete" data-skip-confirm="1"
                    title="Permanently delete these entries, accesskey s" accesskey="s">Permanently Delete</button>
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
                    foreach ($categoryService->listForDropdown() as $cat_row) {
                        $selected = ($filter_cat === $cat_row['cid']) ? ' selected' : '';
                        $label    = $cat_row['cname'];
                        if ($standaloneTable !== null) {
                            $label .= $categoryService->isStandaloneCategory($cat_row['cid'])
                                ? ' (Standalone)'
                                : ' (Collective)';
                        }
                        echo '<option value="' . $cat_row['cid'] . '"'
                            . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
                    }
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
        $blogService->search($filter_cat, $filter_search, (int) $fu->getOpt('num_per_page') ?: 20);

        if ($fu->db->total_results > 0) { ?>

            <div style="display:flex; justify-content:flex-end; margin-bottom:0.75rem;">
                <button type="submit" name="action" value="bulk-delete" class="delete" data-skip-confirm="1"
                    title="Delete all checked entries">Delete Selected</button>
            </div>

            <table class="sortable" aria-label="Blog entries">
                <thead>
                    <tr>
                        <th scope="col" data-sort-method="none"><input type="checkbox" id="select-all" title="Select all" /></th>
                        <th scope="col">Title</th>
                        <th scope="col">Category</th>
                        <th scope="col">Date</th>
                        <th scope="col">Comments</th>
                        <th scope="col">Public</th>
                        <th scope="col" data-sort-method="none" colspan="3">Actions</th>
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
                        // data-sort holds the raw UTC datetime so Tablesort works for any date_format.
                        echo '<td class="hint small" data-sort="' . htmlspecialchars($post->getDate(), ENT_QUOTES, 'UTF-8') . '">'
                            . htmlspecialchars($post->getDateFormatted(), ENT_QUOTES, 'UTF-8') . '</td>';
                        echo '<td><a href="comment.php?entry=' . $post->getID() . '" title="See comments">'
                            . (int) $row['num_comments'] . '</a></td>';
                        echo '<td>' . $public_badge . '</td>';
                        // Drafts have no front-end page; keep the empty cell so the
                        // column count matches the header's colspan="3".
                        echo '<td class="form">' . ($post->isPublic()
                            ? '<a href="' . htmlspecialchars($post->getUrl(), ENT_QUOTES, 'UTF-8') . '" class="button" target="_blank" rel="noopener noreferrer" title="View \'' . htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8') . '\' on the live site">View</a>'
                            : '') . '</td>';
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
