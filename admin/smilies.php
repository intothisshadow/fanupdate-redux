<?php
/*****************************************************************************
 * FanUpdate — admin/smilies.php
 *
 * Manages the smiley set (add/update/delete, bulk update and bulk delete).
 * Delegates persistence to SmilieService/SmilieRepository; this page only
 * parses/sanitises $_POST, enforces CSRF, and renders. Bulk update/delete
 * actions silently no-op on a failed individual row.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/SmilieRepositoryInterface.php');
require_once('../class/Repository/SmilieRepository.php');
require_once('../class/Service/SmilieService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$smilieService = new SmilieService(new SmilieRepository(
    $fu->db,
    (string) $fu->getOpt('smilies_table')
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

        $clean['smiley'] = isset($_POST['smiley']) ? clean_input($_POST['smiley']) : '';
        $clean['image']  = isset($_POST['image'])  ? clean_input($_POST['image'])  : '';

        if ($smilieService->addSmiley($clean['smiley'], $clean['image'])) {
            $fu->addSuccess('Smiley <strong>' . htmlspecialchars($clean['smiley'], ENT_QUOTES, 'UTF-8') . '</strong> = '
                . $fu->makeSmiley($clean['smiley'], $clean['image']) . ' added.');
        } else {
            $fu->addErr('Failed to add smiley.');
        }
        $clean = [];

    } elseif ($_POST['action'] === 'update') {

        $smileys = isset($_POST['smiley']) && is_array($_POST['smiley']) ? $_POST['smiley'] : [];
        $images  = isset($_POST['image'])  && is_array($_POST['image'])  ? $_POST['image']  : [];

        foreach ($smileys as $key => $smiley_val) {
            $smiley_clean = clean_input($smiley_val);
            $image_clean  = clean_input($images[$key] ?? '');

            if ($smilieService->updateSmiley($smiley_clean, $image_clean)) {
                $fu->addSuccess('Smiley <strong>' . htmlspecialchars($smiley_val, ENT_QUOTES, 'UTF-8') . '</strong> = '
                    . $fu->makeSmiley($smiley_val, $images[$key] ?? '') . ' updated.');
            }
        }

    } elseif ($_POST['action'] === 'delete') {

        $deletes = isset($_POST['smiley_delete']) && is_array($_POST['smiley_delete']) ? $_POST['smiley_delete'] : [];
        foreach ($deletes as $key) {
            $key_clean = clean_input($key);
            if ($smilieService->deleteSmiley($key_clean)) {
                $fu->addSuccess('Smiley <strong>' . htmlspecialchars($key_clean, ENT_QUOTES, 'UTF-8') . '</strong> deleted.');
            }
        }
        $clean = [];
    }
}

$fu->getHeader('Smilies');

?>

<div class="page-header">
    <h2>Options: Smilies</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li><a href="options.php">Main</a></li>
        <li><a href="blacklist.php">Blacklist</a></li>
        <li class="active"><a href="smilies.php">Smilies</a></li>
        <li><a href="templates.php">Templates</a></li>
        <li><a href="collective-source.php">Collective Source</a></li>
        <li><a href="privacy.php">Privacy</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<form action="smilies.php" method="post" style="margin-bottom:1.5rem;">
    <?php echo $fu->csrfField(); ?>
    <p>
        <label for="smiley">Add smiley</label>
        <input type="text" id="smiley" name="smiley" size="10" maxlength="10"
               accesskey="n" style="width:auto;" />
        &nbsp;=&nbsp;
        <?php $fu->printSmileyImgDropdown('image'); ?>
        <input type="submit" name="action" value="add" class="add"
               title="Add smiley, accesskey s" accesskey="s" />
    </p>
</form>

<?php

$smilies = $smilieService->listAll();

if (count($smilies) > 0) { ?>

<form action="smilies.php" method="post">

    <table class="sortable" aria-label="Smiley images">
        <thead>
            <tr>
                <th scope="col">Text</th>
                <th scope="col">Image</th>
                <th scope="col">New image</th>
                <th scope="col">Delete?</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i     = 0;
            $class = 'odd';
            foreach ($smilies as $row) {
                $class = ($class === 'even') ? 'odd' : 'even';
                ?>
                <tr class="<?php echo $class; ?>">
                    <td>
                        <label for="smiley<?php echo $i; ?>">
                            <?php echo htmlspecialchars((string) $row['smiley'], ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <input type="hidden" name="smiley[<?php echo $i; ?>]"
                               value="<?php echo htmlspecialchars((string) $row['smiley'], ENT_QUOTES, 'UTF-8'); ?>" />
                    </td>
                    <td><?php echo $fu->makeSmiley($row['smiley'], $row['image']); ?></td>
                    <td><?php $fu->printSmileyImgDropdown('image[' . $i . ']', $row['image']); ?></td>
                    <td class="form">
                        <input type="checkbox" id="smiley<?php echo $i; ?>"
                               name="smiley_delete[]"
                               value="<?php echo htmlspecialchars((string) $row['smiley'], ENT_QUOTES, 'UTF-8'); ?>" />
                    </td>
                </tr>
                <?php
                $i++;
            }
            ?>
        </tbody>
    </table>

    <p style="margin-top:1rem; display:flex; gap:8px;">
        <?php echo $fu->csrfField(); ?>
        <input type="submit" name="action" value="update" class="update" title="Save changes, accesskey s" accesskey="s" />
        <input type="submit" name="action" value="delete" class="delete" title="Delete selected smilies, accesskey x" accesskey="x" />
    </p>

</form>

<?php } else {
    echo '<p>No smilies defined yet.</p>';
}

$fu->getFooter();
