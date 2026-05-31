<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
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

require_once('../blog-config.php');
require_once('../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

/** @var array<string, mixed> $clean */
$clean = [];

// ── PRIVATE KEYS: never shown in the form, never accepted from POST ──────────
//
// Any optkey that starts with '_' is treated as private by convention.
// The list below is an explicit deny-list for the UPDATE handler so that even
// if someone crafts a POST with these keys, they are silently ignored.

/** @var list<string> OPTIONS_PRIVATE_KEYS Keys that must never be written via this form. */
const OPTIONS_PRIVATE_KEYS = [
    '_db_version',       // schema version used by install.php — read-only
    '_server_tz_offset', // removed in Redux 1.5.1
    'admin_email',       // managed in account.php
];

// ── UPDATE HANDLER ────────────────────────────────────────────────────────────

if (isset($_POST['action']) && $_POST['action'] === 'update') {

    $clean = clean_input($_POST, true);

    // $clean['key'] is an array of optkey strings submitted from hidden inputs.
    // Each index maps 1-to-1 with $clean['value'].
    if (is_array($clean['key'] ?? null) && is_array($clean['value'] ?? null)) {

        foreach ($clean['key'] as $id => $key) {

            // PHP 8.2: explicit (string) cast — $key comes from user input
            $key = (string) $key;

            // Security: refuse to update any private key regardless of POST content
            if (str_starts_with($key, '_') || in_array($key, OPTIONS_PRIVATE_KEYS, true)) {
                continue;
            }

            // Ensure a matching value exists at this index
            if (!array_key_exists($id, $clean['value'])) {
                continue;
            }

            $sql_key   = $fu->db->Escape($key);
            $sql_value = $fu->db->Escape((string) $clean['value'][$id]);

            $query = 'UPDATE ' . $fu->getOpt('options_table')
                . " SET optvalue='$sql_value' WHERE optkey='$sql_key'";

            if (!$fu->db->Execute($query)) {
                $fu->addErr('Failed to update <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong>');
            } elseif ($fu->db->AffectedRows() > 0) {
                $fu->addSuccess(
                    'Set <strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</strong> = '
                        . nl2br(htmlspecialchars((string) $clean['value'][$id], ENT_QUOTES, 'UTF-8'))
                );
            }
        }
    }
}

$fu->getHeader('Options');

?>

<h2>Options</h2>
<ul class="subnav">
    <li><a href="options.php">Main</a></li>
    <li><a href="blacklist.php">Blacklist</a></li>
    <li><a href="smilies.php">Smilies</a></li>
    <li><a href="templates.php">Templates</a></li>
</ul>

<?php

// ── REPORT SUCCESS / ERRORS ───────────────────────────────────────────────────

$fu->reportSuccess();
$fu->reportErrors();

// ── OPTIONS FORM ──────────────────────────────────────────────────────────────

// Exclude:
//   - template keys  (managed in templates.php)
//   - all keys whose name starts with '_'  (private/internal)
//
// The SQL WHERE clause handles templates; the PHP loop handles the '_' prefix.

$query = 'SELECT * FROM ' . $fu->getOpt('options_table')
    . " WHERE optkey NOT LIKE '%template%' AND optkey != 'admin_email' ORDER BY optkey ASC";

$fu->db->Execute($query);

$rows = [];
while ($row = $fu->db->ReadRecord()) {
    $rows[] = $row;
}
$fu->db->FreeResult();

?>

<form action="options.php" method="post">
    <?php

    $class = 'odd'; // PHP 8.2: declare before use — no dynamic property risk here,
    // but explicit initialisation avoids the isset() dance below.

    foreach ($rows as $row) {

        $optkey = (string) $row['optkey'];

        // Skip private keys: any key that starts with '_' is internal.
        // This covers _db_version and any future private keys automatically.
        if (str_starts_with($optkey, '_')) {
            continue;
        }

        $class = ($class === 'even') ? 'odd' : 'even';

        $safe_key  = htmlspecialchars($optkey, ENT_QUOTES, 'UTF-8');
        $safe_desc = htmlspecialchars((string) ($row['optdesc'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safe_val  = htmlspecialchars((string) ($row['optvalue'] ?? ''), ENT_QUOTES, 'UTF-8');

    ?>
        <div class="option <?php echo $class; ?>">
            <p>
                <label for="<?php echo $safe_key; ?>">
                    <?php echo $safe_key; ?>:
                    <span class="help"><?php echo $safe_desc; ?></span>
                </label>
                <input type="text"
                    id="<?php echo $safe_key; ?>"
                    name="value[]"
                    size="50"
                    maxlength="255"
                    value="<?php echo $safe_val; ?>" />
                <input type="hidden" name="key[]" value="<?php echo $safe_key; ?>" />
            </p>
        </div><!-- END .option -->
    <?php

    }

    ?>

    <p>
        <input type="hidden" name="action" value="update" />
        <input type="submit"
            value="Save Changes"
            class="update"
            title="Save changes, accesskey s"
            accesskey="s"
            id="primary_action" />
    </p>

</form>

<p>Many of these options can be overridden by <a href="category.php">category-level settings</a>.
    Be aware, if you change the <strong>install_path</strong> you will need to generate new
    code snippets for inclusion in your sites.</p>

<?php $fu->getFooter();
