<?php

/*****************************************************************************
 * FanUpdate — admin/media.php
 *
 * Media gallery (TODO.md item "4. UI - Create an Image/File Manager"):
 * browse every image uploaded through the WYSIWYG editor's "insert image"
 * button (admin/upload.php), delete one or several (checkbox select + confirm step), and — via the JSON ?action=list
 * endpoint below — supply the "choose existing image" picker in the
 * WYSIWYG toolbar (assets/js/fanupdate-admin.js) with paginated results.
 * Delegates storage/business logic to MediaService/MediaRepository; this
 * page only parses/sanitises $_GET/$_POST, enforces CSRF, and renders.
 *
 * Deletion has no "is this image still referenced by a post?" check —
 * deliberately: scanning every post body for a URL match can't use an
 * index and doesn't scale to the "tens of thousands of images" performance
 * target this feature was built against (see CLAUDE.md's Performance
 * section). A post that already embedded a deleted image simply ends up
 * with a broken <img> src, same as manually deleting a file from
 * uploads/images/ always could.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/MediaRepositoryInterface.php');
require_once('../class/Repository/MediaRepository.php');
require_once('../class/Service/MediaService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

// install_path/install_url resolution mirrors admin/upload.php exactly —
// both need the same absolute-path/URL base to join against stored
// uploads/-relative paths.
$install_path_opt = $fu->getOpt('install_path');
$install_path      = ($install_path_opt !== false && (string) $install_path_opt !== '')
    ? rtrim((string) $install_path_opt, '/')
    : dirname(__DIR__);
$install_url = rtrim((string) $fu->getOpt('install_url'), '/');

$media_table = (string) $fu->getOpt('media_table');
$per_page    = 24;

// ── installations that predate this feature (no media_table configured) ───

if ($media_table === '') {
    $fu->getHeader('Media');
    ?>
    <div class="page-header">
        <h2>Media</h2>
    </div>
    <div class="error">
        <p style="margin-bottom:0;">
            The Media gallery isn't configured for this installation yet. Add
            <code>$fanupdate['media_table'] = 'blog_media';</code> to your
            <code>blog-config.php</code>, then visit
            <a href="update.php">Admin &rarr; Updates</a> to create the table.
        </p>
    </div>
    <?php
    $fu->getFooter();
    exit;
}

$mediaService = new MediaService(
    new MediaRepository($fu->db, $media_table),
    $install_path,
    $install_url
);

// ── JSON list endpoint for the WYSIWYG "choose existing image" picker ─────
// GET, read-only — no CSRF token required, same as any other read-only
// admin JSON endpoint in this project (e.g. admin/update.php's
// ?action=install-progress).

if (($_GET['action'] ?? '') === 'list') {
    header('Content-Type: application/json; charset=UTF-8');

    $mediaService->listPage($per_page);

    $items = [];
    while ($row = $fu->db->ReadRecordPaginate()) {
        $items[] = $mediaService->rowToDto($row);
    }
    $fu->db->FreeResult();

    $totalPages = $fu->db->max_results > 0
        ? (int) ceil($fu->db->total_results / $fu->db->max_results)
        : 1;

    echo json_encode([
        'items'      => $items,
        'page'       => $fu->db->page,
        'totalPages' => $totalPages,
        'hasMore'    => $fu->db->page < $totalPages,
    ], JSON_THROW_ON_ERROR);
    exit;
}

// ── POST handler: delete ───────────────────────────────────────────────────

$confirmDeleteId  = 0;
$confirmDeleteDto = null;
$confirmBulkDtos  = [];

// id[] (bulk) is an array; id (single) is scalar — never both on one request.
/** @var int[] $bulkIds */
$bulkIds = [];
if (isset($_POST['id']) && is_array($_POST['id'])) {
    $bulkIds = array_values(array_unique(array_filter(
        array_map('intval', $_POST['id']),
        static fn (int $v): bool => $v > 0
    )));
}

if (isset($_POST['action']) && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action']) && $_POST['action'] === 'bulk-delete') {
    // Submitted from the gallery's select form — confirm before deleting.
    if ($bulkIds === []) {
        $fu->addErr('No images were selected.');
    } else {
        $confirmBulkDtos = $mediaService->getBulkDeleteConfirmation($bulkIds);
        if ($confirmBulkDtos === []) {
            $fu->addErr('The selected images no longer exist.');
        }
    }
} elseif (isset($_POST['action']) && $_POST['action'] === 'bulk-delete-confirmed') {
    if ($bulkIds === []) {
        $fu->addErr('No images were selected.');
    } else {
        $count = $mediaService->deleteImages($bulkIds);
        $fu->logEvent(LogService::MEDIA_DELETED, $count . ' ' . ($count === 1 ? 'image' : 'images') . ' deleted.');
        $fu->addSuccess($count . ' ' . ($count === 1 ? 'image' : 'images') . ' deleted.');
    }
} elseif (
    isset($_POST['action'], $_POST['id'])
    && $_POST['action'] === 'delete'
    && is_numeric($_POST['id'])
) {
    // Reached only from the confirm-delete view's form below.
    if ($mediaService->deleteImage((int) $_POST['id'])) {
        $fu->logEvent(LogService::MEDIA_DELETED, 'Image #' . (int) $_POST['id'] . ' deleted.');
        $fu->addSuccess('Image deleted.');
    } else {
        $fu->addErr('That image no longer exists.');
    }
} elseif (
    isset($_GET['action'], $_GET['id'])
    && $_GET['action'] === 'confirm-delete'
    && is_numeric($_GET['id'])
) {
    // Single-image delete confirmation view (GET, no side effects).
    $confirmDeleteId  = (int) $_GET['id'];
    $confirmDeleteDto = $mediaService->findDto($confirmDeleteId);
    if ($confirmDeleteDto === null) {
        $confirmDeleteId = 0;
        $fu->addErr('That image no longer exists.');
    }
}

$fu->getHeader('Media');
?>

<div class="page-header">
    <h2>Media</h2>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();

if ($confirmBulkDtos !== []) {
    $bulkCount = count($confirmBulkDtos);
    ?>
    <fieldset>
        <legend>Confirm deletion</legend>
        <p>
            Are you sure you want to delete the following <?php echo $bulkCount; ?>
            <?php echo $bulkCount === 1 ? 'image' : 'images'; ?>?
            This cannot be undone. Any post that already embedded these images will show a broken image instead.
        </p>

        <ul class="catlist">
            <?php foreach ($confirmBulkDtos as $bulkDto) { ?>
                <li><?php echo htmlspecialchars($bulkDto['filename'], ENT_QUOTES, 'UTF-8'); ?></li>
            <?php } ?>
        </ul>

        <form action="media.php" method="post">
            <?php echo $fu->csrfField(); ?>
            <?php foreach ($confirmBulkDtos as $bulkDto) { ?>
                <input type="hidden" name="id[]" value="<?php echo $bulkDto['id']; ?>" />
            <?php } ?>
            <div class="primary">
                <button type="submit" name="action" value="bulk-delete-confirmed" class="delete" data-skip-confirm="1"
                       title="Permanently delete these images, accesskey s" accesskey="s">Permanently Delete</button>
                <a href="media.php" class="button">Cancel</a>
            </div>
        </form>
    </fieldset>
    <?php
} elseif ($confirmDeleteId > 0 && $confirmDeleteDto !== null) {
    ?>
    <fieldset>
        <legend>Confirm deletion</legend>
        <p>
            <img src="<?php echo htmlspecialchars($confirmDeleteDto['thumbUrl'], ENT_QUOTES, 'UTF-8'); ?>"
                 alt="" style="max-width:120px; max-height:120px; vertical-align:middle; margin-right:0.75rem; border-radius:var(--fu-radius);" />
            Are you sure you want to delete
            <strong><?php echo htmlspecialchars($confirmDeleteDto['filename'], ENT_QUOTES, 'UTF-8'); ?></strong>?
            This cannot be undone. Any post that already embedded this image will show a broken image instead.
        </p>

        <form action="media.php" method="post">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="id" value="<?php echo $confirmDeleteId; ?>" />
            <div class="primary">
                <button type="submit" name="action" value="delete" class="delete" data-skip-confirm="1"
                       title="Permanently delete this image, accesskey s" accesskey="s">Delete</button>
                <a href="media.php" class="button">Cancel</a>
            </div>
        </form>
    </fieldset>
    <?php
} else {
    $mediaService->listPage($per_page);

    if ($fu->db->total_results > 0) {
        ?>
        <form action="media.php" method="post" id="media-select-form">
            <?php echo $fu->csrfField(); ?>
            <div class="media-bulk-bar">
                <label><input type="checkbox" id="select-all" /> Select all on this page</label>
                <button type="submit" name="action" value="bulk-delete" class="delete" data-skip-confirm="1"
                        title="Delete all checked images">Delete Selected</button>
            </div>
        <div class="media-grid">
            <?php
            while ($row = $fu->db->ReadRecordPaginate()) {
                $dto = $mediaService->rowToDto($row);
                ?>
                <figure class="media-item">
                    <input type="checkbox" name="id[]" value="<?php echo $dto['id']; ?>" class="media-select"
                           aria-label="Select <?php echo htmlspecialchars($dto['filename'], ENT_QUOTES, 'UTF-8'); ?>" />
                    <a href="<?php echo htmlspecialchars($dto['url'], ENT_QUOTES, 'UTF-8'); ?>" class="fu-lightbox">
                        <img src="<?php echo htmlspecialchars($dto['thumbUrl'], ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars($dto['filename'], ENT_QUOTES, 'UTF-8'); ?>"
                             loading="lazy" />
                    </a>
                    <figcaption>
                        <span class="hint small" title="<?php echo htmlspecialchars($dto['filename'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($dto['filename'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="hint small"><?php echo $dto['width']; ?>&times;<?php echo $dto['height']; ?> &middot; <?php echo htmlspecialchars($dto['humanSize'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <a href="media.php?action=confirm-delete&id=<?php echo $dto['id']; ?>" class="delete button" data-skip-confirm="1"
                           title="Delete '<?php echo htmlspecialchars($dto['filename'], ENT_QUOTES, 'UTF-8'); ?>'">Delete</a>
                    </figcaption>
                </figure>
                <?php
            }
            $fu->db->FreeResult();
            ?>
        </div>
        </form>

        <p class="paginate"><?php $fu->db->PrintPaginate(); ?></p>
        <script src="../assets/js/fanupdate-post-admin.js"></script>
        <script>fuLightboxInit();</script>
        <?php
    } else {
        echo '<p>No images have been uploaded yet. Use the WYSIWYG editor\'s "insert image" button on '
            . '<a href="blog.php?action=new">a new blog entry</a> to upload one.</p>';
    }
}

$fu->getFooter();
