<?php
/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
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

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav  = true;
$showform = true;
$clean    = [];
$cmt      = new FanUpdate_Comment([], $fu, $fu->getOpt('blog_page'));

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action'])) {

    $clean['id']      = isset($_POST['id'])      ? (int) $_POST['id']                           : 0;
    $clean['name']    = isset($_POST['name'])     ? clean_input($_POST['name'])                  : '';
    $clean['email']   = isset($_POST['email'])    ? clean_input($_POST['email'])                 : '';
    $clean['url']     = isset($_POST['url'])      ? clean_input($_POST['url'])                   : '';
    $clean['comment'] = isset($_POST['comment'])  ? clean_input($_POST['comment'])               : '';
    $clean['entry']   = isset($_POST['entry'])    ? (int) $_POST['entry']                        : 0;
    $clean['date']    = isset($_POST['date'])     ? clean_input($_POST['date'])                  : '';
    $clean['approved']= isset($_POST['approved']) ? (int) $_POST['approved']                     : 0;

    $sql_id      = $fu->db->Escape((string) $clean['id']);
    $sql_name    = $fu->db->Escape($clean['name']);
    $sql_email   = $fu->db->Escape($clean['email']);
    $sql_url     = $fu->db->Escape($clean['url']);
    $sql_comment = $fu->db->Escape($clean['comment']);
    $sql_entry   = $fu->db->Escape((string) $clean['entry']);
    $sql_date    = $fu->db->Escape($clean['date']);
    $sql_approved= $fu->db->Escape((string) $clean['approved']);

    if ($_POST['action'] === 'preview') {

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

    } elseif ($_POST['action'] === 'update') {

        $query = "UPDATE " . $fu->getOpt('comments_table') . "
            SET name='$sql_name', email='$sql_email', url='$sql_url',
                comment='$sql_comment', entry_id='$sql_entry',
                added='$sql_date', approved='$sql_approved'
            WHERE comment_id='$sql_id'";

        if (!$fu->db->Execute($query)) {
            $fu->addErr('Failed to update comment #<strong>' . $clean['id'] . '</strong>.');
        } else {
            $fu->addSuccess('Comment #<strong>' . $clean['id'] . '</strong> updated.');
            $showform = false;
        }

    } elseif ($_POST['action'] === 'delete') {

        $query = "DELETE FROM " . $fu->getOpt('comments_table') . "
            WHERE comment_id='$sql_id'";

        if (!$fu->db->Execute($query)) {
            $fu->addErr('Failed to delete comment #<strong>' . $clean['id'] . '</strong>.');
        } else {
            $fu->addSuccess('Comment #<strong>' . $clean['id'] . '</strong> deleted.');
            $showform = false;
        }
        $clean = [];
    }
}

// Load existing comment for edit
if ($showform && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id    = (int) $_GET['id'];
    $query = "SELECT * FROM " . $fu->getOpt('comments_table') . " WHERE comment_id='$id'";
    $fu->db->Execute($query);
    if ($row = $fu->db->ReadRecord()) {
        $cmt = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page'));
        $clean = $row;
    }
    $fu->db->FreeResult();
}

// ─────────────────────────────────────────────────────────────────────────────
// Determine filter
// ─────────────────────────────────────────────────────────────────────────────

$show   = isset($_GET['show'])   ? clean_input($_GET['show'])   : '';
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';

$fu->getHeader('Comments');

?>

<div class="page-header">
    <h2>Comments<?php
        if ($showform && $cmt->getID()) { echo ': Edit'; }
        elseif ($show !== '')           { echo ': ' . ucwords(htmlspecialchars($show, ENT_QUOTES, 'UTF-8')); }
    ?></h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li<?php echo $show === '' ? ' class="active"' : ''; ?>><a href="comment.php">Approved</a></li>
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
    <div><?php echo $cmt->printComment(); ?></div>
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

<?php } else {

// ── Comment list ──────────────────────────────────────────────────────────

$where_parts = [];

if ($search !== '') {
    $sql_search = $fu->db->Escape($search);
    $where_parts[] = "(name LIKE '%$sql_search%' OR comment LIKE '%$sql_search%' OR email LIKE '%$sql_search%')";
}

if ($show === 'pending') {
    $where_parts[] = 'approved = 0';
} elseif ($show === 'spam') {
    $where_parts[] = 'approved = 2';
} else {
    $where_parts[] = 'approved > 0';
}

$where = 'WHERE ' . implode(' AND ', $where_parts);

$fu->db->ExecutePaginate(
    'SELECT c.*, b.title AS entry_title
     FROM ' . $fu->getOpt('comments_table') . ' c
     LEFT JOIN ' . $fu->getOpt('blog_table') . ' b ON c.entry_id = b.entry_id
     ' . $where . '
     ORDER BY c.added DESC',
    (int) $fu->getOpt('num_per_page') ?: 20
);

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
                . '<form action="comment.php" method="post" style="display:inline;">'
                . '<input type="hidden" name="id" value="' . (int) $row['comment_id'] . '" />'
                . '<input type="submit" name="action" value="delete" class="delete"'
                . ' title="Delete comment #' . (int) $row['comment_id'] . '" />'
                . '</form></td>';
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
