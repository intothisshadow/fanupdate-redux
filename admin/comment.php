<?php
/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Comment CRUD, moderation (approve/pending/spam), and listing/search.
 * Delegates persistence to CommentService/CommentRepository; this page
 * parses/sanitises $_POST and $_GET, enforces CSRF, builds the
 * FanUpdate_Comment domain object used for preview/gravatar/abstract
 * rendering, and renders. Destructive actions (delete) go through a
 * server-side confirmation view with its own <form>, so they work with
 * JavaScript disabled.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/gpl-3.0.html>.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/CommentRepositoryInterface.php');
require_once('../class/Repository/CommentRepository.php');
require_once('../class/Service/CommentService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$commentService = new CommentService(new CommentRepository(
    $fu->db,
    (string) $fu->getOpt('comments_table'),
    (string) $fu->getOpt('blog_table')
));

$showNav  = true;
$showform = false;   // default: show the list; only true when editing/adding
$clean    = [];
$cmt      = new FanUpdate_Comment([], $fu, $fu->getOpt('blog_page'));

// Delete-confirmation view state.
$confirmDeleteId       = 0;
$confirmDeleteAbstract = '';
$confirmDeleteName     = '';

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action'])) {

    $clean['id']      = isset($_POST['id'])      ? (int) $_POST['id']                           : 0;
    $clean['name']    = isset($_POST['name'])     ? clean_input($_POST['name'])                  : '';
    $clean['email']   = isset($_POST['email'])    ? clean_input($_POST['email'])                 : '';
    $clean['url']     = isset($_POST['url'])      ? clean_input($_POST['url'])                   : '';
    $clean['comment'] = isset($_POST['comment'])  ? clean_input($_POST['comment'], true)               : '';
    $clean['entry']   = isset($_POST['entry'])    ? (int) $_POST['entry']                        : 0;
    $clean['date']    = isset($_POST['date'])     ? clean_input($_POST['date'])                  : '';
    $clean['approved']= isset($_POST['approved']) ? (int) $_POST['approved']                     : 0;

    if ($_POST['action'] === 'preview') {

        $showform = true;

        $cmt = new FanUpdate_Comment([
            'comment_id' => $clean['id'],
            'name'       => $clean['name'],
            'email'      => $clean['email'],
            'url'        => $clean['url'],
            'comment'    => $clean['comment'],
            'entry_id'   => $clean['entry'],
            'added'      => $clean['date'],
            'approved'   => $clean['approved'],
        ], $fu, $fu->getOpt('blog_page'));

    } elseif ($_POST['action'] === 'add') {

        if (empty($clean['comment'])) {
            $fu->addErr('The comment body cannot be blank.');
            $showform = true;
        } else {
            $date = !empty($clean['date']) ? $clean['date'] : gmdate('Y-m-d H:i:s');

            $ok = $commentService->addComment([
                'name'     => $clean['name'],
                'email'    => $clean['email'],
                'url'      => $clean['url'],
                'comment'  => $clean['comment'],
                'entry_id' => $clean['entry'],
                'date'     => $date,
                'approved' => $clean['approved'],
            ]);

            if (!$ok) {
                $fu->addErr('Failed to add comment.');
                $showform = true;
            } else {
                $fu->addSuccess('Comment added.');
                $clean = [];
            }
        }

    } elseif ($_POST['action'] === 'update') {

        $ok = $commentService->updateComment($clean['id'], [
            'name'     => $clean['name'],
            'email'    => $clean['email'],
            'url'      => $clean['url'],
            'comment'  => $clean['comment'],
            'entry_id' => $clean['entry'],
            'date'     => $clean['date'],
            'approved' => $clean['approved'],
        ]);

        if (!$ok) {
            $fu->addErr('Failed to update comment #<strong>' . $clean['id'] . '</strong>.');
            $showform = true;
        } else {
            $fu->addSuccess('Comment #<strong>' . $clean['id'] . '</strong> updated.');
            $clean = [];
        }

    } elseif ($_POST['action'] === 'delete') {

        // Reached from the edit form's inline delete button, or from the
        // confirm-delete view's form.
        if (!$commentService->deleteComment($clean['id'])) {
            $fu->addErr('Failed to delete comment #<strong>' . $clean['id'] . '</strong>.');
        } else {
            $fu->addSuccess('Comment #<strong>' . $clean['id'] . '</strong> deleted.');
        }
        $clean = [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Delete confirmation view  (GET ?action=confirm-delete&id=N)
// ─────────────────────────────────────────────────────────────────────────────

if (!$showform && isset($_GET['action']) && $_GET['action'] === 'confirm-delete'
    && isset($_GET['id']) && is_numeric($_GET['id'])
) {
    $confirmDeleteId = (int) $_GET['id'];
    $row             = $commentService->getDeleteConfirmation($confirmDeleteId);
    if ($row !== null) {
        $cmt_confirm            = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page'));
        $confirmDeleteAbstract  = $cmt_confirm->getAbstract(12);
        $confirmDeleteName      = (string) $row['name'];
    } else {
        $confirmDeleteId = 0;
        $fu->addErr('That comment no longer exists.');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Load existing comment for edit  (GET ?id=N)
// ─────────────────────────────────────────────────────────────────────────────

if (!$showform && $confirmDeleteId === 0 && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id  = (int) $_GET['id'];
    $row = $commentService->getCommentForEdit($id);
    if ($row !== null) {
        $cmt      = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page'));
        $clean    = $row;
        $showform = true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// "New comment" button  (GET ?new=1)
// ─────────────────────────────────────────────────────────────────────────────

if (!$showform && isset($_GET['new'])) {
    $showform = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Determine filter for the list
// ─────────────────────────────────────────────────────────────────────────────

$show   = isset($_GET['show'])   ? clean_input($_GET['show'])   : '';
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';

$fu->getHeader('Comments');

?>

<div class="page-header">
    <h2>Comments<?php
        if ($showform && $cmt->getID()) { echo ': Edit'; }
        elseif ($showform && !$cmt->getID()) { echo ': Add'; }
        elseif ($show !== '')           { echo ': ' . ucwords(htmlspecialchars($show, ENT_QUOTES, 'UTF-8')); }
    ?></h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li<?php echo ($show === '' && !$showform) ? ' class="active"' : ''; ?>><a href="comment.php">Approved</a></li>
        <li<?php echo $show === 'pending' ? ' class="active"' : ''; ?>><a href="comment.php?show=pending">Pending</a></li>
        <li<?php echo $show === 'spam' ? ' class="active"' : ''; ?>><a href="comment.php?show=spam">Spam</a></li>
        <li class="form">
            <form action="comment.php" method="get">
                <input type="text" name="search"
                       value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="Search comments…" accesskey="q" />
                <input type="submit" value="Search" class="button" title="Search comments, accesskey q" />
            </form>
        </li>
    </ul>
</div>

<?php

$fu->reportSuccess();
$fu->reportErrors();

// ── Preview ───────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'preview') { ?>
<div id="preview">
    <h3>Preview</h3>
    <div><?php $cmt->printComment(); ?></div>
</div>
<?php } ?>

<?php
// ── Edit / Add form ───────────────────────────────────────────────────────

if ($showform) {
    $gravatar_url = '';
    if ($cmt->getID()) {
        $gravatar_url = htmlspecialchars($cmt->getGravatarUrl(), ENT_QUOTES, 'UTF-8');
    }
?>

<form action="comment.php" method="post">
    <?php echo $fu->csrfField(); ?>

    <div class="col-layout-12">

        <div>
            <fieldset<?php if ($gravatar_url) {
                echo ' style="background: url(\'' . $gravatar_url . '\') right 1rem center / 64px 64px no-repeat;"';
            } ?>>
                <legend>Comment</legend>

                <p><label for="comment">Comment</label>
                <textarea id="comment" name="comment" rows="8"><?php
                    echo htmlspecialchars((string) ($clean['comment'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?></textarea></p>

                <p><label for="name">Name</label>
                <input type="text" id="name" name="name" maxlength="20" size="25"
                       value="<?php echo htmlspecialchars((string) ($clean['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" /></p>

                <p><label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="70" size="25"
                       value="<?php echo htmlspecialchars((string) ($clean['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" /></p>

                <p><label for="url">URL</label>
                <input type="url" id="url" name="url" maxlength="70" size="25"
                       value="<?php echo htmlspecialchars((string) ($clean['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" /></p>

                <p><label for="date">Date</label>
                <input type="text" id="date" name="date" size="20"
                       value="<?php echo htmlspecialchars((string) ($clean['added'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" /></p>

                <p><label for="entry">Entry ID</label>
                <input type="number" id="entry" name="entry" size="5"
                       value="<?php echo (int) ($clean['entry_id'] ?? 0); ?>" /></p>

                <p><label for="approved">Approved</label>
                <select id="approved" name="approved">
                    <option value="0"<?php echo ((int) ($clean['approved'] ?? 0)) === 0 ? ' selected' : ''; ?>>No (pending)</option>
                    <option value="1"<?php echo ((int) ($clean['approved'] ?? 0)) === 1 ? ' selected' : ''; ?>>Yes</option>
                    <option value="2"<?php echo ((int) ($clean['approved'] ?? 0)) === 2 ? ' selected' : ''; ?>>Spam</option>
                </select></p>

                <?php if (isset($clean['comment_id'])) { ?>
                <input type="hidden" name="id" value="<?php echo (int) $clean['comment_id']; ?>" />
                <?php } ?>
            </fieldset>
        </div>

        <div>
            <fieldset id="action">
                <legend>Action</legend>
                <div class="primary">
                    <input type="submit" name="action" value="preview" class="button" title="Preview changes" />
                </div>
                <div class="secondary">
                    <?php if (isset($clean['comment_id'])) { ?>
                    <input type="submit" name="action" value="update" class="update" title="Save changes, accesskey s" accesskey="s" />
                    <input type="submit" name="action" value="delete" class="delete" title="Delete comment, accesskey x" accesskey="x" />
                    <?php } else { ?>
                    <input type="submit" name="action" value="add" class="add" title="Add comment, accesskey s" accesskey="s" />
                    <?php } ?>
                </div>
            </fieldset>
        </div>

    </div>

</form>

<?php } elseif ($confirmDeleteId > 0) {

// ── Delete confirmation ──────────────────────────────────────────────────
?>

<fieldset>
    <legend>Confirm deletion</legend>
    <p>
        Are you sure you want to delete comment #<?php echo $confirmDeleteId; ?>
        from <strong><?php echo htmlspecialchars($confirmDeleteName, ENT_QUOTES, 'UTF-8'); ?></strong>
        (&ldquo;<?php echo htmlspecialchars($confirmDeleteAbstract, ENT_QUOTES, 'UTF-8'); ?>&rdquo;)?
        This cannot be undone.
    </p>

    <form action="comment.php" method="post">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="id" value="<?php echo $confirmDeleteId; ?>" />
        <div class="primary">
            <input type="submit" name="action" value="delete" class="delete" data-skip-confirm="1"
                title="Permanently delete this comment, accesskey s" accesskey="s" />
            <a href="comment.php" class="button">Cancel</a>
        </div>
    </form>
</fieldset>

<?php } else {

// ── Comment list ──────────────────────────────────────────────────────────

$commentService->search($show, $search, (int) $fu->getOpt('num_per_page') ?: 20);

if ($fu->db->NumRows() > 0) { ?>

<table aria-label="Comments list">
    <thead>
        <tr>
            <th scope="col">#</th>
            <th scope="col">From</th>
            <th scope="col">Comment</th>
            <th scope="col">Entry</th>
            <th scope="col">Date</th>
            <th scope="col">Status</th>
            <th scope="col" colspan="2">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $class = 'odd';
        while ($row = $fu->db->ReadRecordPaginate()) {
            $cmt_item = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page'));
            $class    = ($class === 'even') ? 'odd' : 'even';

            $status_badge = match ((int) $row['approved']) {
                0       => '<span class="badge badge-warning">pending</span>',
                2       => '<span class="badge badge-danger">spam</span>',
                default => '<span class="badge badge-success">approved</span>',
            };

            echo '<tr class="' . $class . '">';
            echo '<td>' . (int) $row['comment_id'] . '</td>';
            echo '<td>' . $cmt_item->getGravatar(24) . ' ' . htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars($cmt_item->getAbstract(12), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['entry_title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td class="hint small">' . htmlspecialchars($cmt_item->getDateFormatted(), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '<td class="form"><a href="comment.php?id=' . (int) $row['comment_id'] . '" class="edit button">Edit</a></td>';
            echo '<td class="form">'
                . '<a href="comment.php?action=confirm-delete&id=' . (int) $row['comment_id'] . '" class="delete button" data-skip-confirm="1"'
                . ' title="Delete comment #' . (int) $row['comment_id'] . '">Delete</a></td>';
            echo '</tr>';
        }
        ?>
    </tbody>
</table>

<p class="paginate"><?php $fu->db->PrintPaginate(); ?></p>

<?php } else {
    echo '<p>No comments found.</p>';
}
$fu->db->FreeResult();
} ?>

<script src="../assets/js/fanupdate-comment-admin.js"></script>

<?php
$fu->getFooter();
