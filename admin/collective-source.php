<?php
/*****************************************************************************
 * FanUpdate — admin/collective-source.php
 *
 * Dedicated Options subpage for the four settings that control which
 * collective/fanlisting script (if any) FanUpdate is paired with:
 * collective_script, col_id, col_subj, collective_table. The first three
 * used to be listed on admin/options.php's generic Main list like any
 * other setting; moved here so there's room to explain what each accepted
 * value actually does (see TODO.md's "UI: Move to a dedicated Options
 * subpage" item) and so the known scripts can be picked from a dropdown
 * instead of typed by hand.
 *
 * collective_table was added to this page after the fact (bug fix): unlike
 * the other three, it never had ANY admin UI — it was only ever read once
 * from blog-config.php's legacy $fanupdate['collective_table'] global (see
 * UpgradeRunner's migrateLegacyCollectiveTable()). That meant switching
 * collective_script here correctly updated col_id/col_subj but silently
 * left collective_table pointed at whatever table the installation started
 * with — e.g. switching from standalone to Fanatically updated col_id to
 * "id" and col_subj to "name", but Categories/Entries kept querying the old
 * standalone categories table under those column names, which don't exist
 * there, breaking both pages. Now first-class here, same as the other
 * three.
 *
 * Persistence is still just four plain options-table rows — reuses
 * OptionService/OptionRepository exactly like admin/options.php, no new
 * repository method (OptionRepository::updateOption() upserts, so this
 * works even for an installation whose collective_table row hasn't been
 * created by UpgradeRunner's migration yet). The <select> + auto-fill JS
 * below is a pure UI convenience over those same free-text values;
 * FanUpdate::normalizeCollectiveScriptIdentifier()'s
 * passthrough-for-unknown-values behaviour, and how collective_script/
 * col_id/col_subj are actually resolved and read (FanUpdate::
 * resolveCollectiveScriptConfig()), are unchanged by this page.
 * collective_table needs no equivalent resolution method — addOptFromDb()
 * already merges every options-table row (including collective_table) into
 * FanUpdate::$_cfg unconditionally, so getOpt('collective_table') picks up
 * a saved value the same way any other plain option does.
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
// Mirrors blog-config.sample.php's $coltable defaults. "listingadmin" is
// deliberately NOT listed here — Listing Admin support is reserved/planned
// (FanUpdate::normalizeCollectiveScriptIdentifier() already normalises the
// identifier correctly) but not yet functional until its Integrated Mode
// adapter is built, so it isn't offered as a choice yet. An installation
// somehow already storing "listingadmin" (or any other unrecognised value)
// falls through to the Custom… state below, same as any other unknown value.
const FU_KNOWN_COLLECTIVE_SCRIPTS = [
    'none'        => ['label' => 'None (standalone)', 'id' => 'fl_id',     'subject' => 'fl_subject', 'table' => 'blog_category'],
    'enthusiast'  => ['label' => 'Enthusiast',         'id' => 'listingid', 'subject' => 'subject',    'table' => 'owned'],
    'fanatically' => ['label' => 'Fanatically',        'id' => 'id',        'subject' => 'subject',    'table' => 'fanatic_listings'],
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

    $scriptValue = FanUpdate::normalizeCollectiveScriptIdentifier($scriptValue);
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
    (see <strong>Admin → Categories</strong>). Supported scripts: <strong>Enthusiast</strong> and
    <strong>Fanatically</strong>. Choose <strong>None</strong> for standalone mode — FanUpdate
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
            <code>listingid</code>, Fanatically = <code>id</code>. Auto-filled when you choose a
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
            <code>subject</code>, Fanatically = <code>subject</code>. Auto-filled when you choose a
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
            owns). For "None" (standalone), this is FanUpdate's own categories table. Auto-filled
            when you choose a known script above, but always editable — e.g. if your collective
            script's database lives on the same MySQL server but under a different schema, you
            can schema-qualify this as <code>other_db.table_name</code>.
        </p>
    </div>

    <p style="margin-top:1.5rem;">
        <input type="submit" name="action" value="update" class="update"
               title="Save collective source settings, accesskey s" accesskey="s" />
    </p>
</form>

<script>
// Auto-fill col_id/col_subj/collective_table from the known-script lookup
// table, and toggle the Custom… identifier field, when the
// collective_script dropdown changes. Purely a UI convenience — the
// underlying values stay freely editable, and nothing here changes what
// gets submitted (four plain text values).
(function () {
    var colTable = <?php echo json_encode(array_map(
        static fn (array $info): array => ['id' => $info['id'], 'subject' => $info['subject'], 'table' => $info['table']],
        FU_KNOWN_COLLECTIVE_SCRIPTS
    ), JSON_THROW_ON_ERROR); ?>;

    var select     = document.getElementById('collective_script');
    var customWrap = document.getElementById('collective_script_custom_wrap');
    var colId      = document.getElementById('col_id');
    var colSubj    = document.getElementById('col_subj');
    var collectiveTable = document.getElementById('collective_table');

    select.addEventListener('change', function () {
        customWrap.style.display = (this.value === 'custom') ? 'block' : 'none';

        if (colTable[this.value]) {
            colId.value           = colTable[this.value].id;
            colSubj.value         = colTable[this.value].subject;
            collectiveTable.value = colTable[this.value].table;
        }
    });
})();
</script>

<?php
$fu->getFooter();
