<?php
/*****************************************************************************
 * FanUpdate — admin/credits.php
 *
 * Displays version, contributor, and licence information. Publicly readable
 * (no login required) so the footer "credits" link works for logged-out
 * visitors; the admin nav bar is shown only when the visitor is actually
 * authenticated.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../version.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();

// Credits is publicly readable — no login required.
// Show the admin nav bar only when the visitor is authenticated.
$showNav = $fu->isLoggedIn();

$fu->getHeader('Credits');

?>

<div class="page-header">
    <h2>Credits</h2>
</div>

<!-- ── Version ────────────────────────────────────────────────────────── -->

<div class="credits-section">
    <h3>Version</h3>
    <p>
        <strong>FanUpdate <?php echo htmlspecialchars(FU_VERSION, ENT_QUOTES, 'UTF-8'); ?></strong>
        (<?php echo htmlspecialchars(FU_RELEASE_NAME, ENT_QUOTES, 'UTF-8'); ?>,
        released <?php echo htmlspecialchars(FU_RELEASE_DATE, ENT_QUOTES, 'UTF-8'); ?>)
    </p>
    <p class="credits-meta">
        <a href="<?php echo htmlspecialchars(FU_URL_ORIGINAL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Original project</a>
        &middot;
        <a href="<?php echo htmlspecialchars(FU_URL_ROBOTESS, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Robotess fork</a>
        &middot;
        Licence: <a href="<?php echo htmlspecialchars(FU_LICENCE_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(FU_LICENCE, ENT_QUOTES, 'UTF-8'); ?></a>
    </p>
</div>

<!-- ── Contributors ───────────────────────────────────────────────────── -->

<div class="credits-section">
    <h3>Contributors</h3>
    <?php foreach (FU_CONTRIBUTORS as $person) { ?>
    <div style="margin-bottom:1.25rem; padding-bottom:1.25rem; border-bottom:0.5px solid var(--fu-border);">
        <p style="margin-bottom:2px;">
            <strong>
                <?php if (!empty($person['url'])) { ?>
                <a href="<?php echo htmlspecialchars($person['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                    <?php echo htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <?php } else {
                    echo htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8');
                } ?>
            </strong>
            &mdash;
            <?php echo htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8'); ?>
            <span class="credits-meta">(<?php echo htmlspecialchars($person['years'], ENT_QUOTES, 'UTF-8'); ?>)</span>
        </p>
        <p class="small muted"><?php echo htmlspecialchars($person['contribution'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php } ?>
</div>

<!-- ── Libraries ──────────────────────────────────────────────────────── -->

<div class="credits-section">
    <h3>Libraries &amp; third-party code</h3>
    <?php foreach (FU_LIBRARIES as $lib) { ?>
    <div style="margin-bottom:0.875rem;">
        <p style="margin-bottom:2px;">
            <strong>
                <?php if (!empty($lib['url'])) { ?>
                <a href="<?php echo htmlspecialchars($lib['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                    <?php echo htmlspecialchars($lib['name'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <?php } else {
                    echo htmlspecialchars($lib['name'], ENT_QUOTES, 'UTF-8');
                } ?>
            </strong>
            <?php if (!empty($lib['author'])) { ?>
            <span class="credits-meta">by <?php echo htmlspecialchars($lib['author'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php } ?>
            <?php if (!empty($lib['licence'])) { ?>
            &middot; <span class="credits-meta"><?php echo htmlspecialchars($lib['licence'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php } ?>
        </p>
        <?php if (!empty($lib['note'])) { ?>
        <p class="small muted"><?php echo htmlspecialchars($lib['note'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php } ?>
    </div>
    <?php } ?>
</div>

<?php
$fu->getFooter();
