<?php
declare(strict_types=1);
/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc
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
 *
 * Note: post visibility is compared against UTC_TIMESTAMP() (not local server
 * time) so that scheduled/future posts are hidden correctly regardless of the
 * server's timezone setting.
 ******************************************************************************/

// Guard sits above the opening <div> so an invalid/missing $cat_id never
// leaves an unclosed <div id="fanupdate"> in the host page's output; return
// (not exit) lets the host page continue running normally. $cat_id (not
// $listingid) avoids colliding with show-blog.php when both are embedded on
// the same page.
if (!isset($cat_id) || !is_numeric($cat_id)) {
    return;
}
?>
<div id="fanupdate">

    <?php

    require_once('blog-config.php');
    require_once('functions.php');

    $fu = FanUpdate::instance();
    $fu->addOptFromDb();

    $query = 'SELECT * FROM ' . $fu->getOpt('catoptions_table') . ' WHERE cat_id = ? LIMIT 1';

    $cat = $fu->db->GetRecord($query, [(int) $cat_id]);

    if (is_array($cat)) {
        // Bug fix: this used to only guard against an empty STRING, not SQL
        // NULL. catoptions_table's date_format/comments_on columns are
        // nullable with no DEFAULT, and CategoryRepository::insertSettings()
        // (called by every standalone-category creation, via
        // CategoryService::createCategory()) never sets either one — so a
        // standalone category's row always has date_format/comments_on as
        // NULL, not ''. That NULL was passed straight through to addOpt(),
        // clobbering the site-wide date_format option with null for the
        // rest of the request — getDateFormatted() then cast that to '',
        // and date('', $ts) returns '', blanking the date on every post
        // filed under a standalone category. Fanatically/Enthusiast-synced
        // categories never hit this: they only get a catoptions row at all
        // if an admin explicitly saves per-category settings for one, so
        // $cat is null/false and this whole loop is skipped until then.
        foreach ($cat as $key => $value) {
            if ($value !== '' && $value !== null) { // don't update defaults!
                $fu->addOpt($key, $value);
            }
        }
    }

    // ____________________________________________________________ ARCHIVES

    if (isset($_GET['view']) && $_GET['view'] === 'archive') {

        ?>
        <h2>Archives</h2>
        <?php

        $query = 'SELECT b.entry_id, b.added, b.title
    FROM ' . $fu->getOpt('blog_table') . ' b
    JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id=j.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP() AND j.cat_id = ?
    ORDER BY b.added DESC';

        $fu->db->Execute($query, 'Could not execute query.', [(int) $cat_id]);

        $lastyear = '';

        while ($row = $fu->db->ReadRecord()) {

            $post = new FanUpdate_Post($row, $fu);

            $year = $post->getYear();
            if ($year !== $lastyear) {
                if (!empty($lastyear)) {
                    echo "</ul>\n";
                }
                echo '<h3>' . $year . "</h3>\n";
                echo "<ul>\n";
            }

            echo '<li><span class="date">' . $post->getDateFormatted() . ':</span> <a href="' . $post->getUrl() . '">' . $post->getTitle() . "</a></li>\n";

            $lastyear = $year;

        }
        $fu->db->FreeResult();

        echo "</ul>\n";

    } else {

// ____________________________________________________________ DISPLAY ENTRIES QUERY

        $single_page = false;
        $params = [(int) $cat_id];

        $query = 'SELECT b.*
    FROM ' . $fu->getOpt('blog_table') . ' b
    JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id=j.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP() AND j.cat_id = ?';

        if (!empty($_GET['id'])) {

            $id = (int)$_GET['id'];
            $query .= ' AND b.entry_id = ?';
            $params[] = $id;

            $single_page = true;

        } elseif (!empty($_GET['q'])) {

            $q = clean_input($_GET['q']);
            $query .= ' AND MATCH (b.title, b.body) AGAINST (? IN BOOLEAN MODE)';
            $params[] = $q;

        }

        $query .= ' ORDER BY b.added DESC';

        if (!empty($q)) {
            echo '<p>Search results for <strong>' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . "</strong></p>\n";
        }

        // $main_limit is declared by the host page (e.g. $main_limit = 5).
        // Cast to int to satisfy strict_types; default to 5 if unset.
        $fu->printBlog($query, (int)($main_limit ?? 5), $single_page, $params);
    }

    $fu->printBlogFooter($cat_id);

    ?>

</div><!-- END #fanupdate -->
