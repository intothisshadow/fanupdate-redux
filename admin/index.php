<?php
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

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../version.php');
require_once('../class/Update/ReleaseInfo.php');
require_once('../class/Update/UpdateProviderInterface.php');
require_once('../class/Update/GitHubUpdateProvider.php');
require_once('../class/Repository/UpdateRepositoryInterface.php');
require_once('../class/Repository/UpdateRepository.php');
require_once('../class/Repository/CategoryRepositoryInterface.php');
require_once('../class/Repository/CategoryRepository.php');
require_once('../class/Service/CategoryService.php');
require_once('../class/Service/UpdateManager.php');
require_once('../class/Repository/PrivacyRepositoryInterface.php');
require_once('../class/Repository/PrivacyRepository.php');
require_once('../class/Service/InstallPingService.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

// "install.php still present" and "pending DB upgrades" notices used to be
// computed and rendered here (Dashboard-only) — both are now global banners
// in admin/header.php, shown on every admin page. See that file's comment
// for the full rationale.

// ── Update notice ───────────────────────────────────────────────────────
// runAutomaticCheckIfDue() only makes a live GitHub call when automatic
// checks are enabled AND the configured interval has actually elapsed since
// the last check (manual or automatic) — otherwise it's exactly as cheap as
// the cached-only getStatusSummary() this used to call. This is the "no cron
// available" opportunistic-check pattern: the Dashboard is the
// highest-traffic admin page, so it's the natural place to notice a check
// is due without needing a real cron job.

$updateRepository = new UpdateRepository($fu->db, (string) $fu->getOpt('options_table'));
$updateConfig     = $updateRepository->getConfig();
$updateProvider   = new GitHubUpdateProvider(
    FU_UPDATE_PROVIDER_OWNER,
    FU_UPDATE_PROVIDER_REPO,
    (!empty($updateConfig['githubToken']) && is_string($updateConfig['githubToken'])) ? $updateConfig['githubToken'] : null
);
$updateManager  = new UpdateManager($updateProvider, $updateRepository, FU_VERSION);
$updateSummary  = $updateManager->runAutomaticCheckIfDue();

if (isset($_POST['action']) && $_POST['action'] === 'dismiss_update') {
    if ($fu->verifyCsrfOrFail()) {
        $updateManager->dismissNotice();
        header('Location: index.php');
        exit;
    }
}

// ── Opt-In Anonymous Install Ping (TODO.md "FP-15") ────────────────────────
// Deliberately independent of the update-check block above: its own
// repository, its own request code, and it runs (or doesn't) regardless of
// whether update checking is enabled — see class/Service/InstallPingService.php's
// docblock. Off by default; maybePingIfDue() is a no-op unless an admin has
// explicitly opted in from admin/privacy.php, and even then it only actually
// contacts the network once every ~30 days. Never throws, nothing to render.
(new InstallPingService(
    new PrivacyRepository($fu->db, (string) $fu->getOpt('options_table')),
    FU_VERSION,
    FU_INSTALL_PING_URL
))->maybePingIfDue();

// ── Integrated Mode: orphaned collective-sourced categories notice ──────
// Cheap enough for every Dashboard load — one indexed LEFT JOIN, no network
// call. Detects a category FanUpdate holds settings for that's since been
// deleted upstream in the collective script (Enthusiast/Fanatically/Listing
// Admin), per TODO.md's Integrated Mode sections.
$collectiveScriptConfigured = $fu->hasCollectiveScript();
$standaloneCategoryTableOpt = (string) $fu->getOpt('standalone_category_table');
$standaloneCategoryTable = $standaloneCategoryTableOpt !== '' ? $standaloneCategoryTableOpt : null;
$categoryService = new CategoryService(new CategoryRepository(
    $fu->db,
    (string) $fu->getOpt('collective_table'),
    (string) $fu->getOpt('catjoin_table'),
    (string) $fu->getOpt('catoptions_table'),
    (string) $fu->getOpt('col_id'),
    (string) $fu->getOpt('col_subj'),
    $collectiveScriptConfigured ? $standaloneCategoryTable : null
));
$hasOrphanedCategories = $collectiveScriptConfigured && $categoryService->hasOrphanedCollectiveCategories();

// ── Unapproved comment count ──────────────────────────────────────────────

$unapproved_comments = (int) $fu->db->GetFirstCell(
    'SELECT COUNT(comment_id) FROM ' . $fu->getOpt('comments_table') . ' WHERE approved = 0'
);

// ── Entry and comment totals ──────────────────────────────────────────────

$entry_count   = (int) $fu->db->GetFirstCell(
    'SELECT COUNT(entry_id) FROM ' . $fu->getOpt('blog_table') . ' WHERE is_public > 0'
);
$comment_count = (int) $fu->db->GetFirstCell(
    'SELECT COUNT(comment_id) FROM ' . $fu->getOpt('comments_table') . ' WHERE approved > 0'
);

$fu->getHeader('Dashboard');

?>

<div class="page-header">
    <h2>Dashboard</h2>
    <a href="blog.php?action=new" id="new-entry-btn">
        <i class="ti ti-plus" aria-hidden="true"></i> New entry
    </a>
</div>

<?php if ($updateSummary['updateAvailable'] && !$updateSummary['noticeDismissed']) { ?>
<div class="success" style="display:flow-root;">
    <p style="margin-bottom:0.5rem;">
        <strong>FanUpdate <?php echo htmlspecialchars((string) $updateSummary['latestVersion'], ENT_QUOTES, 'UTF-8'); ?> is available</strong>
        (you have <?php echo htmlspecialchars($updateSummary['installedVersion'], ENT_QUOTES, 'UTF-8'); ?>).
        <?php if (!empty($updateSummary['latestName'])) { ?>&mdash; <?php echo htmlspecialchars((string) $updateSummary['latestName'], ENT_QUOTES, 'UTF-8'); ?><?php } ?>
    </p>
    <p style="margin-bottom:0;">
        <a href="update.php" class="button update" style="margin-right:0.5rem;">Update now</a>
        <?php if (!empty($updateSummary['latestUrl'])) { ?>
        <a href="<?php echo htmlspecialchars((string) $updateSummary['latestUrl'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">View release notes</a>
        <?php } ?>
        <form action="index.php" method="post" style="display:inline;">
            <?php echo $fu->csrfField(); ?>
            <input type="hidden" name="action" value="dismiss_update" />
            <button type="submit" class="button" style="margin-left:0.5rem;">Dismiss</button>
        </form>
    </p>
</div>
<?php } ?>

<?php if ($hasOrphanedCategories) { ?>
<div class="error" style="display:flow-root;">
    <p style="margin-bottom:0.5rem;">
        <strong>Some categories no longer exist upstream.</strong>
        One or more categories FanUpdate holds template/gravatar settings for have since been
        deleted in your collective script and no longer appear in the category list.
    </p>
    <p style="margin-bottom:0;">
        <a href="category.php#orphaned-categories" class="button update">Review &amp; confirm removal</a>
    </p>
</div>
<?php } ?>

<?php if ($unapproved_comments > 0) { ?>
<p class="success">
    <strong><?php echo $unapproved_comments; ?></strong>
    comment<?php echo $unapproved_comments !== 1 ? 's' : ''; ?> awaiting approval —
    <a href="comment.php?show=pending">review now</a>
</p>
<?php } ?>

<!-- ── Stats ─────────────────────────────────────────────────────────── -->

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-num"><?php echo $entry_count; ?></div>
        <div class="stat-label">Published entries</div>
    </div>
    <div class="stat-card">
        <div class="stat-num"><?php echo $comment_count; ?></div>
        <div class="stat-label">Approved comments</div>
    </div>
    <?php if ($unapproved_comments > 0) { ?>
    <div class="stat-card">
        <div class="stat-num accent"><?php echo $unapproved_comments; ?></div>
        <div class="stat-label">Awaiting approval</div>
    </div>
    <?php } ?>
</div>

<!-- ── Quick links ───────────────────────────────────────────────────── -->

<div class="stat-grid" style="margin-bottom:2rem;">
    <div class="stat-card">
        <div class="stat-label" style="margin-bottom:4px;">Quick links</div>
        <ul style="list-style:none;padding:0;margin:0;font-size:0.875rem;line-height:2;">
            <li><a href="<?php echo htmlspecialchars((string) $fu->getOpt('blog_page'), ENT_QUOTES, 'UTF-8'); ?>">View your blog</a></li>
            <li><a href="get-code.php">How to display your blog</a></li>
            <li><a href="<?php echo htmlspecialchars((string) $fu->getOpt('url'), ENT_QUOTES, 'UTF-8'); ?>">Documentation &amp; FAQs</a></li>
        </ul>
    </div>
</div>

<!-- ── Three-column dashboard grid ──────────────────────────────────── -->

<div class="dash-grid">

    <!-- Last Entries -->
    <section>
        <h3>
            Last entries
            <a href="<?php echo htmlspecialchars((string) $fu->getOpt('install_url'), ENT_QUOTES, 'UTF-8'); ?>/rss.php"
               class="feed" title="RSS feed for entries">RSS</a>
        </h3>
        <ul>
            <?php
            $query = 'SELECT b.*, COUNT(com.comment_id) AS num_comments
                FROM ' . $fu->getOpt('blog_table') . ' b
                LEFT JOIN ' . $fu->getOpt('comments_table') . ' com
                    ON b.entry_id = com.entry_id AND (com.approved > 0 OR com.approved IS NULL)
                WHERE b.is_public > 0
                GROUP BY b.entry_id
                ORDER BY b.added DESC LIMIT 5';

            $fu->db->Execute($query);
            while ($row = $fu->db->ReadRecord()) {
                $post = new FanUpdate_Post($row, $fu, $fu->getOpt('blog_page'));
                echo '<li>'
                    . '<a href="' . htmlspecialchars($post->getUrl(), ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8')
                    . '</a><br>'
                    . '<span class="hint small">'
                    . $post->getDateFormatted() . ' &middot; '
                    . $row['num_comments'] . ' comment' . ($row['num_comments'] != 1 ? 's' : '')
                    . '</span></li>';
            }
            $fu->db->FreeResult();
            ?>
        </ul>
    </section>

    <!-- Last Comments -->
    <section>
        <h3>
            Last comments
            <a href="<?php echo htmlspecialchars((string) $fu->getOpt('install_url'), ENT_QUOTES, 'UTF-8'); ?>/rss-comments.php"
               class="feed" title="RSS feed for comments">RSS</a>
        </h3>
        <ul>
            <?php
            $query = 'SELECT c.*, b.title
                FROM ' . $fu->getOpt('comments_table') . ' c
                JOIN ' . $fu->getOpt('blog_table') . ' b ON c.entry_id = b.entry_id
                WHERE c.approved > 0
                ORDER BY c.added DESC LIMIT 5';

            $fu->db->Execute($query);
            while ($row = $fu->db->ReadRecord()) {
                $cmt = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page'));
                echo '<li>'
                    . $cmt->getGravatar(32) . ' '
                    . $cmt->getDateFormatted()
                    . ' on <a href="' . htmlspecialchars($cmt->getLinkUrl(), ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8')
                    . '</a><br>'
                    . '<span class="hint small">' . $cmt->getAbstract(12, true) . '</span>'
                    . '</li>';
            }
            $fu->db->FreeResult();
            ?>
        </ul>
    </section>

    <!-- Top Entries / Top Commenters -->
    <section>
        <?php
        $query = 'SELECT b.*, COUNT(c.comment_id) AS num_comments
            FROM ' . $fu->getOpt('blog_table') . ' b
            JOIN ' . $fu->getOpt('comments_table') . ' c ON b.entry_id = c.entry_id
            WHERE c.approved > 0
            GROUP BY b.entry_id
            ORDER BY num_comments DESC LIMIT 5';

        $fu->db->Execute($query);

        if ($fu->db->NumRows() > 0) {
            echo "<h3>Top entries</h3>\n<ul>\n";
            while ($row = $fu->db->ReadRecord()) {
                $post = new FanUpdate_Post($row, $fu, $fu->getOpt('blog_page'));
                echo '<li>'
                    . '<a href="' . htmlspecialchars($post->getUrl(), ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8')
                    . '</a><br>'
                    . '<span class="hint small">'
                    . $row['num_comments'] . ' comment' . ($row['num_comments'] != 1 ? 's' : '')
                    . '</span></li>';
            }
            echo "</ul>\n";
        }
        $fu->db->FreeResult();

        $query = 'SELECT c.*, COUNT(c.comment_id) AS num_comments
            FROM ' . $fu->getOpt('comments_table') . ' c
            WHERE c.approved > 0 AND c.email != ?
            GROUP BY c.name, c.email
            ORDER BY num_comments DESC LIMIT 5';

        $fu->db->Execute($query, 'Could not execute query.', [(string) ($fu->getOpt('admin_email') ?: '')]);

        if ($fu->db->NumRows() > 0) {
            echo "<h3 style='margin-top:1.5rem;'>Top commenters</h3>\n<ul>\n";
            while ($row = $fu->db->ReadRecord()) {
                $cmt = new FanUpdate_Comment($row, $fu, $fu->getOpt('blog_page'));
                echo '<li>'
                    . $cmt->getGravatar(32) . ' '
                    . $cmt->getCommenterLink()
                    . '<br><span class="hint small">'
                    . $row['num_comments'] . ' comment' . ($row['num_comments'] != 1 ? 's' : '')
                    . '</span></li>';
            }
            echo "</ul>\n";
        }
        $fu->db->FreeResult();
        ?>
    </section>

</div><!-- END .dash-grid -->

<?php
$fu->getFooter();
