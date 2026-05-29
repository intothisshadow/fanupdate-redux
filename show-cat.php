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
 *
 * Redux 1.5.1 fix: replaced DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL X HOUR)
 * with UTC_TIMESTAMP(). See show-blog.php for full explanation.
 ******************************************************************************/
?>
<div id="fanupdate">
    <?php

    require_once('blog-config.php');
    require_once('functions.php');

    $fu = FanUpdate::instance();
    $fu->addOptFromDb();

    if (!isset($listingid)) {
        $listingid = 0;
    }
    if (!isset($main_limit)) {
        $main_limit = 5;
    }

    // ____________________________________________________________ ARCHIVES

    if (isset($_GET['view']) && $_GET['view'] == 'archive') {

        ?>
        <h2>Archives</h2>
        <ul>
            <?php

            $query = 'SELECT b.entry_id, b.title, b.added
    FROM ' . $fu->getOpt('blog_table') . ' b
    JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id=j.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP() AND j.cat_id=' . $listingid . '
    ORDER BY b.added DESC';

            $fu->db->Execute($query);

            $lastyear = null;

            while ($row = $fu->db->ReadRecord()) {

                $post = new FanUpdate_Post($row, $fu, $fu->getOpt('blog_page'));

                $year = $post->getYear();

                if ($year !== $lastyear) {
                    if ($lastyear !== null) {
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

        $query = 'SELECT b.*
    FROM ' . $fu->getOpt('blog_table') . ' b
    JOIN ' . $fu->getOpt('catjoin_table') . ' j ON b.entry_id=j.entry_id
    WHERE b.is_public > 0 AND b.added <= UTC_TIMESTAMP() AND j.cat_id=' . $listingid;

        if (!empty($_GET['id'])) {

            $id = (int)$_GET['id'];
            $query .= ' AND b.entry_id=' . $id;

            $single_page = true;

        } elseif (!empty($_GET['q'])) {

            $q = clean_input($_GET['q']);
            $sql_q = $fu->db->Escape($q);
            $query .= " AND MATCH (b.title, b.body) AGAINST ('$sql_q' IN BOOLEAN MODE)";

        }

        $query .= ' ORDER BY b.added DESC';

        if (!empty($q)) {
            echo '<p>Search results for <strong>' . $q . "</strong></p>\n";
        }

        $fu->printBlog($query, $main_limit, $single_page);
    }

    $fu->printBlogFooter($listingid);

    ?>

</div><!-- END #fanupdate -->
