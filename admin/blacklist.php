<?php
/*****************************************************************************
 * FanUpdate — admin/blacklist.php
 *
 * Manages the comment-spam blacklist (add/list/delete words). Delegates
 * storage to BlacklistService/BlacklistRepository; this page only
 * parses/sanitises $_POST, enforces CSRF, and renders. A failed per-word
 * delete is a silent no-op.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/BlacklistRepositoryInterface.php');
require_once('../class/Repository/BlacklistRepository.php');
require_once('../class/Service/BlacklistService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$blacklistService = new BlacklistService(new BlacklistRepository(
    $fu->db,
    (string) $fu->getOpt('blacklist_table')
));

$showNav = true;
$clean   = [];

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action'])) {

    if ($_POST['action'] === 'add') {

        $clean['badword'] = isset($_POST['badword']) ? clean_input($_POST['badword']) : '';

        if ($blacklistService->wordExists($clean['badword'])) {
            $fu->addErr('Word <strong>' . htmlspecialchars($clean['badword'], ENT_QUOTES, 'UTF-8') . '</strong> is already blacklisted.');
        } else {
            if ($blacklistService->addWord($clean['badword'])) {
                $fu->logEvent(LogService::BLACKLIST_CHANGED, 'Blacklist word added.');
                $fu->addSuccess('Word <strong>' . htmlspecialchars($clean['badword'], ENT_QUOTES, 'UTF-8') . '</strong> blacklisted.');
            } else {
                $fu->addErr('Failed to add word to blacklist.');
            }
            $clean = [];
        }

    } elseif ($_POST['action'] === 'delete') {

        $words = isset($_POST['badword']) && is_array($_POST['badword']) ? $_POST['badword'] : [];
        foreach ($words as $word) {
            $word_clean = clean_input($word);
            if ($blacklistService->deleteWord($word_clean)) {
                $fu->logEvent(LogService::BLACKLIST_CHANGED, 'Blacklist word removed.');
                $fu->addSuccess('Word <strong>' . htmlspecialchars($word_clean, ENT_QUOTES, 'UTF-8') . '</strong> removed from blacklist.');
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
        <li><a href="collective-source.php">Collective Source</a></li>
        <li><a href="privacy.php">Privacy</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<form action="blacklist.php" method="post" style="margin-bottom:1.5rem;">
    <?php echo $fu->csrfField(); ?>
    <p>
        <label for="badword">Add word to blacklist</label>
        <input type="text" id="badword" name="badword" size="20" maxlength="50"
               value="<?php echo htmlspecialchars((string) ($clean['badword'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               accesskey="n" style="width:auto;" />
        <button type="submit" name="action" value="add" class="add"
               title="Blacklist this word, accesskey s" accesskey="s">Add</button>
    </p>
</form>

<?php

$words = $blacklistService->listAll();

if (count($words) > 0) { ?>

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
            foreach ($words as $row) {
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
        <?php echo $fu->csrfField(); ?>
        <button type="submit" name="action" value="delete" class="delete"
               title="Delete selected words">Delete</button>
    </p>

</form>

<?php } else {
    echo '<p>No words are currently blacklisted.</p>';
}

$fu->getFooter();
