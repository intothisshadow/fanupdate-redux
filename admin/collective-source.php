<?php
/*****************************************************************************
 * FanUpdate — admin/collective-source.php
 *
 * Options subpage for the four settings that pair FanUpdate with a
 * collective/fanlisting script: collective_script, col_id, col_subj and
 * collective_table. They must change together, since switching only the
 * script would leave Categories/Entries querying the old table with the new
 * script's column names.
 *
 * Persistence is four plain options rows via OptionService (updateOption()
 * upserts). The <select> and auto-fill JS are a UI convenience over those
 * free-text values; resolution is unchanged (see
 * FanUpdate::resolveCollectiveScriptConfig()).
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/OptionRepositoryInterface.php');
require_once('../class/Repository/OptionRepository.php');
require_once('../class/Service/OptionService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$optionService = new OptionService(new OptionRepository(
    $fu->db,
    (string) $fu->getOpt('options_table')
));

$showNav = true;

// ── Known collective scripts ───────────────────────────────────────────────
// Mirrors blog-config.sample.php's $coltable defaults. An unknown stored
// value shows as Custom…. Listing Admin's table is its table prefix without
// underscores (default prefix "trex_" gives "trex"), so that preset is only
// a starting point.
const FU_KNOWN_COLLECTIVE_SCRIPTS = [
    'none'         => ['label' => 'None (standalone)', 'id' => 'fl_id',     'subject' => 'fl_subject', 'table' => 'blog_category'],
    'enthusiast'   => ['label' => 'Enthusiast (Erin\'s PHP 8 fork)', 'id' => 'listingid', 'subject' => 'subject',    'table' => 'owned'],
    'fanatically'  => ['label' => 'Fanatically',        'id' => 'id',        'subject' => 'subject',    'table' => 'fanatic_listings'],
    'listingadmin' => ['label' => 'Listing Admin (Redux or Erin\'s fork)', 'id' => 'id',        'subject' => 'subject',    'table' => 'trex'],
];

// ─────────────────────────────────────────────────────────────────────────────
// POST handler
// ─────────────────────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'update' && !$fu->verifyCsrfOrFail()) {
    // CSRF check failed — error already queued; skip processing the action.
} elseif (isset($_POST['action']) && $_POST['action'] === 'update') {

    $selected = isset($_POST['collective_script']) ? clean_input($_POST['collective_script']) : 'none';

    if ($selected === 'custom') {
        $scriptValue = isset($_POST['collective_script_custom']) ? clean_input($_POST['collective_script_custom']) : '';
    } else {
        $scriptValue = $selected;
    }

    $scriptValue = (new CollectiveScriptNormalizer())->normalize($scriptValue);
    $colId       = isset($_POST['col_id'])   ? clean_input($_POST['col_id'])   : '';
    $colSubj     = isset($_POST['col_subj']) ? clean_input($_POST['col_subj']) : '';
    $collectiveTable = isset($_POST['collective_table']) ? clean_input($_POST['collective_table']) : '';

    $updates = [
        'collective_script' => $scriptValue,
        'col_id'             => $colId,
        'col_subj'           => $colSubj,
        'collective_table'   => $collectiveTable,
    ];

    $ok = true;
    foreach ($updates as $key => $value) {
        if ($optionService->updateOption($key, $value) === null) {
            $ok = false;
            $fu->addErr('Failed to update <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong>.');
        }
    }

    if ($ok) {
        $fu->addOpt('collective_script', $scriptValue);
        $fu->addOpt('col_id', $colId);
        $fu->addOpt('col_subj', $colSubj);
        $fu->addOpt('collective_table', $collectiveTable);
        $fu->addSuccess('Collective source settings saved.');
    }
}

// ── Current values ──────────────────────────────────────────────────────────

$currentScript = (string) $fu->getOpt('collective_script', true);
$currentColId  = (string) $fu->getOpt('col_id', true);
$currentColSubj = (string) $fu->getOpt('col_subj', true);
$currentCollectiveTable = (string) $fu->getOpt('collective_table', true);

$isKnownScript  = array_key_exists($currentScript, FU_KNOWN_COLLECTIVE_SCRIPTS);
$selectValue    = $isKnownScript ? $currentScript : 'custom';
$customValue    = $isKnownScript ? '' : $currentScript;

$fu->getHeader('Collective Source');

?>

<div class="page-header">
    <h2>Options: Collective Source</h2>
</div>

<div class="subnav-bar">
    <ul class="subnav">
        <li><a href="options.php">Main</a></li>
        <li><a href="blacklist.php">Blacklist</a></li>
        <li><a href="smilies.php">Smilies</a></li>
        <li><a href="templates.php">Templates</a></li>
        <li class="active"><a href="collective-source.php">Collective Source</a></li>
        <li><a href="privacy.php">Privacy</a></li>
    </ul>
</div>

<?php
$fu->reportSuccess();
$fu->reportErrors();
?>

<p class="hint" style="margin-bottom:1.25rem;">
    Controls which collective/fanlisting script's categories appear alongside FanUpdate's own
    (see <strong>Admin → Categories</strong>). Supported scripts: <strong>Enthusiast</strong>
    (Erin's PHP 8 fork), <strong>Fanatically</strong> and <strong>Listing Admin</strong>
    (<a href="https://github.com/intothisshadow/ListinAdmin-Redux" target="_blank" rel="noopener noreferrer">Redux</a>,
    or <a href="https://github.com/DudeThatsErin/listingadmin" target="_blank" rel="noopener noreferrer">Erin's PHP 8 fork</a> it is based on). Other forks that keep the same fanlistings table will usually work too. Choose <strong>None</strong> for standalone mode — FanUpdate
    manages its own categories table entirely, with no external script involved. Choosing
    <strong>Custom…</strong> lets you integrate with an unlisted script by entering its column
    names directly; this is the same escape hatch the underlying settings always supported, just
    exposed as a dropdown for the scripts already known to work.
</p>

<form action="collective-source.php" method="post">
    <?php echo $fu->csrfField(); ?>

    <div class="option odd" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="collective_script" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
            Collective script
        </label>
        <select id="collective_script" name="collective_script">
            <?php foreach (FU_KNOWN_COLLECTIVE_SCRIPTS as $key => $info) { ?>
            <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo ($selectValue === $key) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
            <?php } ?>
            <option value="custom" <?php echo ($selectValue === 'custom') ? 'selected' : ''; ?>>Custom…</option>
        </select>
        <p class="hint small" style="margin-top:3px;">
            Which collective/fanlisting script this installation is paired with, or "None" for standalone.
        </p>
    </div>

    <div class="option even" id="collective_script_custom_wrap"
         style="display:<?php echo ($selectValue === 'custom') ? 'block' : 'none'; ?>; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="collective_script_custom" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
            Custom script identifier
        </label>
        <input type="text" id="collective_script_custom" name="collective_script_custom"
               value="<?php echo htmlspecialchars($customValue, ENT_QUOTES, 'UTF-8'); ?>" />
        <p class="hint small" style="margin-top:3px;">
            Identifier for your self-integrated script. Stored as-is (lowercased/trimmed).
        </p>
    </div>

    <div class="option odd" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="col_id" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
            Collective table primary key column
        </label>
        <input type="text" id="col_id" name="col_id"
               value="<?php echo htmlspecialchars($currentColId, ENT_QUOTES, 'UTF-8'); ?>" />
        <p class="hint small" style="margin-top:3px;">
            Only relevant when Collective script isn't "None". Expected values: Enthusiast =
            <code>listingid</code>, Fanatically = <code>id</code>, Listing Admin = <code>id</code>. Auto-filled when you choose a
            known script above, but always editable.
        </p>
    </div>

    <div class="option even" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="col_subj" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
            Collective table subject/name column
        </label>
        <input type="text" id="col_subj" name="col_subj"
               value="<?php echo htmlspecialchars($currentColSubj, ENT_QUOTES, 'UTF-8'); ?>" />
        <p class="hint small" style="margin-top:3px;">
            Only relevant when Collective script isn't "None". Expected values: Enthusiast =
            <code>subject</code>, Fanatically = <code>subject</code>, Listing Admin = <code>subject</code>. Auto-filled when you choose a
            known script above, but always editable.
        </p>
    </div>

    <div class="option odd" style="display:flow-root; padding:0.875rem 0; border-bottom:0.5px solid var(--fu-border);">
        <label for="collective_table" style="font-weight:500; color:var(--fu-text); margin-bottom:3px;">
            Collective/categories table name
        </label>
        <input type="text" id="collective_table" name="collective_table"
               value="<?php echo htmlspecialchars($currentCollectiveTable, ENT_QUOTES, 'UTF-8'); ?>" />
        <p class="hint small" style="margin-top:3px;">
            The actual table these column names above live in. Must match whichever Collective
            script is selected — Enthusiast = <code>owned</code>, Fanatically =
            <code>fanatic_listings</code> (the fanlistings this collective actually owns —
            NOT <code>fanatic_categories</code>, which is Fanatically's own site-wide list of
            every category across the whole directory, unrelated to what any one collective
            owns), Listing Admin = its main fanlistings table, which is your Listing Admin table
            prefix without the underscore (default prefix <code>trex_</code> gives
            <code>trex</code> — not <code>trex_categories</code>). For "None" (standalone), this is FanUpdate's own categories table. Auto-filled
            when you choose a known script above, but always editable — e.g. if your collective
            script's database lives on the same MySQL server but under a different schema, you
            can schema-qualify this as <code>other_db.table_name</code>.
        </p>
    </div>

    <p style="margin-top:1.5rem;">
        <button type="submit" name="action" value="update" class="update"
               title="Save collective source settings, accesskey s" accesskey="s">Update</button>
    </p>
</form>

<?php /* Known-script presets for fanupdate-collective-source.js, passed as JSON in a data attribute. */ ?>
<div id="fu-collective-presets" hidden data-presets="<?php echo htmlspecialchars(json_encode(array_map(
    static fn (array $info): array => ['id' => $info['id'], 'subject' => $info['subject'], 'table' => $info['table']],
    FU_KNOWN_COLLECTIVE_SCRIPTS
), JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8'); ?>"></div>
<script src="../assets/js/fanupdate-collective-source.js"></script>

<?php
$fu->getFooter();
