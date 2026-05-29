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

/**
 * @var FanUpdate $this
 */
?></div><!-- END #main -->

<?php if (isset($showNav) && $showNav === true) { ?>

<div id="nav">
<ul>
<li><a href="index.php" class="dashboard">Dashboard</a></li>
<li><a href="blog.php" class="entries">Entries</a></li>
<li><a href="comment.php" class="comments">Comments</a></li>
<li><a href="category.php" class="categories">Categories</a></li>
<li><a href="options.php" class="options">Options</a></li>
<li><a href="change-password.php" class="password">Password</a></li>
<li><a href="credits.php" class="credits">Credits</a></li>
<li><a href="index.php?action=logout">Logout</a></li>
</ul>
</div>

<?php } ?>

<div class="credit">
   <?php $this->printCredits() ?>
</div>

</div><!-- END #wrap -->

</body>

</html>
