<?php
/*****************************************************************************
 * FanUpdate — admin/category.php
 *
 * Category CRUD and listing. Standalone and collective-sourced categories
 * share one list (see CategoryService for the rules). Delegates persistence
 * to CategoryService; this page sanitises input, enforces CSRF and renders.
 *
 * $hybridMode (a collective script is configured) controls whether the
 * "Source" column and badges are shown.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/CategoryRepositoryInterface.php');
require_once('../class/Repository/CategoryRepository.php');
require_once('../class/Service/CategoryService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

// Only whether a script exists matters here; the raw value feeds fanupdate_category_source_label().
$collectiveScript = (string) $fu->getOpt('collective_script', true);
$hybridMode       = $fu->hasCollectiveScript();
$standaloneTable  = $hybridMode ? ((string) $fu->getOpt('standalone_category_table') ?: null) : null;

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

/**
 * Label for a category's source (list column and edit notice); a generic
 * "Collective" for an unrecognised collective_script.
 */
function fanupdate_category_source_label(bool $isStandalone, mixed $collectiveScript): string
{
    if ($isStandalone) {
        return 'Standalone';
    }

    return match ((string) $collectiveScript) {
        'e', 'enthusiast'   => 'Enthusiast',
        'f', 'fanatically'  => 'Fanatically',
        'l', 'listingadmin' => 'Listing Admin',
        default             => 'Collective',
    };
}

$showNav  = true;
$showform = false;
$clean    = [];

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action'])) {

    $clean['name']             = isset($_POST['name'])             ? clean_input($_POST['name'])             : '';
    $clean['cat_id']           = isset($_POST['cat_id'])           ? (int) $_POST['cat_id']                  : null;
    $clean['entry_template']   = isset($_POST['entry_template'])   ? clean_input($_POST['entry_template'],   true) : '';
    $clean['comment_template'] = isset($_POST['comment_template']) ? clean_input($_POST['comment_template'], true) : '';
    $clean['gravatar_default'] = isset($_POST['gravatar_default']) ? clean_input($_POST['gravatar_default']) : '';
    $clean['gravatar_size']    = isset($_POST['gravatar_size'])    ? (int) $_POST['gravatar_size']           : 40;
    $clean['gravatar_rating']  = isset($_POST['gravatar_rating'])  ? clean_input($_POST['gravatar_rating'])  : 'g';

    if ($_POST['action'] === 'add') {

        $result = $categoryService->createCategory($clean);

        if ($result['standalone_insert_ok'] === false) {
            $fu->addErr('Failed to add category.');
        }

        if ($result['settings_ok'] === true) {
            $clean['cat_id'] = $result['cat_id'];
            $fu->logEvent(LogService::CATEGORY_CHANGED, 'Category "' . $clean['name'] . '" added.');
            $fu->addSuccess('Category <strong>' . htmlspecialchars($clean['name'], ENT_QUOTES, 'UTF-8') . '</strong> added.');
        } elseif ($result['settings_ok'] === false) {
            $fu->addErr('Failed to save category settings.');
        }

    } elseif ($_POST['action'] === 'update' && $clean['cat_id'] !== null) {

        if ($categoryService->updateCategory($clean['cat_id'], $clean)) {
            $fu->logEvent(LogService::CATEGORY_CHANGED, 'Category #' . $clean['cat_id'] . ' updated.');
            $fu->addSuccess('Category updated.');
        } else {
            $fu->addErr('Failed to update category.');
        }

    } elseif ($_POST['action'] === 'delete' && $clean['cat_id'] !== null) {

        // deleteCategory() refuses collective categories; show a clearer message for that.
        if ($categoryService->deleteCategory($clean['cat_id'])) {
            $fu->logEvent(LogService::CATEGORY_CHANGED, 'Category #' . $clean['cat_id'] . ' deleted.');
            $fu->addSuccess('Category deleted.');
        } elseif (!$categoryService->isStandaloneCategory($clean['cat_id'])) {
            $fu->addErr('This category is managed by an external source and cannot be deleted here.');
        } else {
            $fu->addErr('Failed to delete category.');
        }

    } elseif ($_POST['action'] === 'confirm-delete-orphan' && isset($_POST['orphan_cat_id'])) {

        // The admin confirmed removing this settings row; the service re-checks it is orphaned.
        $orphanCatId = (int) $_POST['orphan_cat_id'];

        if ($categoryService->confirmDeleteOrphanedCategory($orphanCatId)) {
            $fu->addSuccess('Category #' . $orphanCatId . ' removed.');
        } else {
            $fu->addErr('Could not remove category #' . $orphanCatId . ' — it may no longer be orphaned, or the delete failed.');
        }
    }
}

// Collective categories are listed live, so the only stale data is a settings
// row left for a category deleted upstream; surfaced below for confirmation.
$orphanedCategoryIds = $hybridMode ? $categoryService->listOrphanedCollectiveCategoryIds() : [];

// Load for edit
$isStandaloneCategory = true;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $showform = true;
    $id       = (int) $_GET['id'];

    $row = $categoryService->getCategoryForEdit($id);
    if ($row !== null) {
        $clean = $row;
        $clean['cat_id'] = $id;
        $isStandaloneCategory = $categoryService->isStandaloneCategory($id);
    }
} elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
    $showform = true;
    $isStandaloneCategory = true;
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

if (!$showform && $orphanedCategoryIds !== []) { ?>
<div id="orphaned-categories" class="error" style="display:flow-root;">
    <p style="margin-bottom:0.5rem;">
        <strong>
            <?php echo count($orphanedCategoryIds); ?>
            categor<?php echo count($orphanedCategoryIds) === 1 ? 'y' : 'ies'; ?>
            no longer found in <?php echo htmlspecialchars(fanupdate_category_source_label(false, $collectiveScript), ENT_QUOTES, 'UTF-8'); ?>.
        </strong>
        The categor<?php echo count($orphanedCategoryIds) === 1 ? 'y' : 'ies'; ?> below
        <?php echo count($orphanedCategoryIds) === 1 ? 'was' : 'were'; ?> synced from
        <?php echo htmlspecialchars(fanupdate_category_source_label(false, $collectiveScript), ENT_QUOTES, 'UTF-8'); ?>
        but has since been deleted there — it no longer appears in the list below and can't be
        selected for new entries, but its FanUpdate-side template/gravatar settings are still
        stored. Confirm removal to clean them up. Existing blog entries already filed under
        it are not affected.
    </p>
    <?php foreach ($orphanedCategoryIds as $orphanCatId) { ?>
    <form action="category.php" method="post" style="display:inline-block;margin-right:0.5rem;">
        <?php echo $fu->csrfField(); ?>
        <input type="hidden" name="orphan_cat_id" value="<?php echo (int) $orphanCatId; ?>" />
        <button type="submit" name="action" value="confirm-delete-orphan" class="delete"
               title="Confirm removing category #<?php echo (int) $orphanCatId; ?>"
               data-confirm="Remove FanUpdate settings for category #<?php echo (int) $orphanCatId; ?>? This cannot be undone.">Confirm delete</button>
        <span class="hint">Category #<?php echo (int) $orphanCatId; ?></span>
    </form>
    <?php } ?>
</div>
<?php } ?>

<?php if ($showform) { ?>

<form action="category.php" method="post">
    <?php echo $fu->csrfField(); ?>

    <div class="col-layout-12">

        <div>
            <?php if ($isStandaloneCategory) { ?>
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
            <fieldset>
                <legend>Name</legend>
                <?php if (isset($clean['cat_id'])) { ?>
                <p class="hint">ID: <strong><?php echo (int) $clean['cat_id']; ?></strong></p>
                <?php } ?>
                <p class="hint">
                    <strong><?php echo htmlspecialchars((string) ($clean['cname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                    — managed by <?php echo htmlspecialchars(fanupdate_category_source_label(false, $collectiveScript), ENT_QUOTES, 'UTF-8'); ?>,
                    read-only here.
                </p>
            </fieldset>
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
                    <button type="submit" name="action" value="update" class="update"
                           title="Save changes, accesskey s" accesskey="s">Update</button>
                    <?php } else { ?>
                    <button type="submit" name="action" value="add" class="add"
                           title="Add category, accesskey s" accesskey="s">Add</button>
                    <?php } ?>
                </div>
                <?php if (isset($clean['cat_id']) && $isStandaloneCategory) { ?>
                <div class="secondary">
                    <button type="submit" name="action" value="delete" class="delete"
                           title="Delete this category, accesskey x" accesskey="x">Delete</button>
                </div>
                <?php } ?>
            </fieldset>
        </div>

    </div>

</form>

<?php } else {

    $categoryService->listAll();

    if ($fu->db->NumRows() > 0) { ?>

    <table class="sortable" aria-label="Blog entry categories">
        <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Name</th>
                <?php if ($hybridMode) { ?>
                <th scope="col">Source</th>
                <?php } ?>
                <th scope="col">Entries</th>
                <th scope="col">Get code</th>
                <th scope="col">Edit</th>
                <th scope="col">Delete</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $class = 'odd';
            while ($row = $fu->db->ReadRecord()) {
                $rowCatId          = (int) $row['cid'];
                $rowIsStandalone   = $categoryService->isStandaloneCategory($rowCatId);

                $class = ($class === 'even') ? 'odd' : 'even';
                echo '<tr class="' . $class . '">';
                echo '<td>' . $rowCatId . '</td>';
                echo '<td>' . htmlspecialchars((string) $row['cname'], ENT_QUOTES, 'UTF-8') . '</td>';
                if ($hybridMode) {
                    $badgeClass = $rowIsStandalone ? 'badge-success' : 'badge-muted';
                    echo '<td><span class="badge ' . $badgeClass . '">'
                        . htmlspecialchars(fanupdate_category_source_label($rowIsStandalone, $collectiveScript), ENT_QUOTES, 'UTF-8')
                        . '</span></td>';
                }
                echo '<td>' . (int) $row['num'] . '</td>';
                echo '<td class="form"><a href="get-code.php?cat=' . $rowCatId . '" class="button">Get code</a></td>';
                echo '<td class="form"><a href="category.php?id=' . $rowCatId . '" class="edit button">Edit</a></td>';
                if ($rowIsStandalone) {
                    echo '<td class="form">'
                        . '<form action="category.php" method="post" style="display:inline;">'
                        . $fu->csrfField()
                        . '<input type="hidden" name="cat_id" value="' . $rowCatId . '" />'
                        . '<button type="submit" name="action" value="delete" class="delete"'
                        . ' title="Delete ' . htmlspecialchars((string) $row['cname'], ENT_QUOTES, 'UTF-8') . '">Delete</button>'
                        . '</form></td>';
                } else {
                    echo '<td class="form hint">read-only</td>';
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
