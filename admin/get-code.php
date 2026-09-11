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
 ******************************************************************************/

require_once(__DIR__ . '/../blog-config.php');
require_once(__DIR__ . '/../functions.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();

$showNav = true;

$fu->getHeader('Snippet');

// ____________________________________________________________ DISPLAY CODE
// NOTE: category.php links here as get-code.php?cat=N — parameter is 'cat',
// not 'id'. All $_GET checks below use 'cat' consistently.

$is_category = !empty($_GET['cat']) && is_numeric($_GET['cat']);

if ($is_category) {

    $sql_id = (int) $_GET['cat'];

    $query = 'SELECT ' . $fu->getOpt('col_subj') . ' AS cat_name
      FROM ' . $fu->getOpt('collective_table') . '
      WHERE ' . $fu->getOpt('col_id') . ' = ? LIMIT 1';

    $subject = $fu->db->GetFirstCell($query, [$sql_id]);
    if (empty($subject)) {
        $subject = 'category ' . $sql_id;
    }
} else {
    $subject = 'all posts';
}

?>

<div class="col12">

    <h2>Display Code</h2>

    <p>Edit the variables in this snippet according to the instructions below, then copy an paste it into the
        PHP-enabled page where you'd like to display the blog for <strong><?php echo htmlspecialchars((string)$subject, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

    <p><textarea id="fu-code-snippet" cols="90" rows="<?php echo $is_category ? 8 : 7; ?>">&lt;?php
// FanUpdate Redux
// subject: <?php echo htmlspecialchars((string) $subject, ENT_QUOTES, 'UTF-8'); ?>

<?php if ($is_category) { ?>
$cat_id = <?php echo $sql_id; ?>;
<?php } ?>
$main_limit = <?php echo $is_category ? 1 : 5; ?>;
<?php if ($is_category) { ?>
require_once('<?php echo htmlspecialchars((string) $fu->getOpt('install_path'), ENT_QUOTES, 'UTF-8'); ?>/show-cat.php');
<?php } else { ?>
require_once('<?php echo htmlspecialchars((string) $fu->getOpt('install_path'), ENT_QUOTES, 'UTF-8'); ?>/show-blog.php');
<?php } ?>
?&gt;</textarea></p>

    <p><button type="button" class="button fu-copy-btn" data-copy-target="fu-code-snippet"><i class="ti ti-copy" aria-hidden="true"></i> Copy</button></p>

    <h3>Explanation of Variables</h3>

    <ul>
        <?php if ($is_category) { ?>
            <li><strong>$cat_id</strong> is the unique ID for this particular category.</li>
        <?php } ?>
        <li><strong>$main_limit</strong> is the number of posts you'd like displayed on the main updates page.</li>
    </ul>

</div><!-- END .col12 -->

<div class="col22">

    <h2>CSS Rules</h2>

    <p>You may want to include these <abbr title="Cascading Style Sheet">CSS</abbr> rules in your site style sheet if
        you are using the default templates. You can of course <a href="templates.php">change the
            templates</a> and/or modify this styling however you see fit.</p>

    <p><textarea id="fu-css-rules" cols="90" rows="16">
/*	====	FanUpdate	====	*/

div#fanupdate {}

/* Each post — the default entry_template wraps {{title}}/{{catfile}}/
   {{body}} in <div class="fu-entry">...</div>, so this rule styles every
   entry as its own card by default. If you've customised entry_template
   (Admin -> Options -> Templates) to drop the wrapper, this rule simply
   matches nothing.

   overflow-wrap keeps long unbroken strings in post bodies (raw URLs,
   file paths) from forcing the page wider than the viewport — on mobile
   that widened layout viewport also stretches any position:fixed element
   sized with inset:0/100vw, including the fu-lightbox overlay, pushing its
   centered image off-screen. */
#fanupdate .fu-entry {
	overflow-wrap: break-word;
}

h2 {}
h3 {}
p {}

/*	====	Entries	====	*/

p.catfile {padding: 0 0 0 3em;}

/* Category-filter links inside catfile. */
.fu-cat-link {}

/* Comment-count link in catfile (e.g. "3 comments"). */
.fu-comment-count-link {}

/* Entry date in the category archive listing (show-cat.php). */
span.date {}

/*	====	Archives (?view=archive)	====	*/

/* The Month/Category Archives lists (show-blog.php) and the per-category
   year-grouped listing (show-cat.php) all use these three fu- prefixed
   classes: fu-archive-list on each <ul>, fu-archive-item on each <li>,
   and fu-archive-count around the "(N)" entry-count shown on show-blog.php's
   two lists only (show-cat.php's year listing has no count to wrap). */
#fanupdate .fu-archive-list {}
#fanupdate .fu-archive-item {}
#fanupdate .fu-archive-count {}

/*	====	Images	====	*/

/* Wraps every image inserted via the post editor's "Insert image" button.
   Rounds the corners, adds a subtle border, and shows a "zoom" cursor on
   hover to hint that it opens in a lightbox. */
a.fu-lightbox {
	display: inline-block;
	border-radius: 6px;
	overflow: hidden;
	border: 1px solid #ddd;
}

/* Border/shadow brighten slightly on hover or keyboard focus. */
a.fu-lightbox:hover,
a.fu-lightbox:focus {
	border-color: #999;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
}

a.fu-lightbox img {
	display: block;
	max-width: 100%;
	height: auto;
	cursor: zoom-in;
	transition: opacity 0.15s;
}

/* Slight fade on hover/focus to reinforce that the image is clickable. */
a.fu-lightbox:hover img,
a.fu-lightbox:focus img {
	opacity: 0.88;
}

/* Optional: add one of these classes to the <a class="fu-lightbox">
   element in a post body to position the image relative to surrounding
   text (see the README's "Image uploads" section for examples). */
a.fu-lightbox.fu-img-left {
	float: left;
	margin: 0 1.25em 0.75em 0;
}
a.fu-lightbox.fu-img-right {
	float: right;
	margin: 0 0 0.75em 1.25em;
}
a.fu-lightbox.fu-img-center {
	display: block;
	margin: 0.75em auto;
}

/*	====	Comments	====	*/

div#comments {}

/* Each comment is always wrapped in <div id="comment{{id}}"
   class="comment fu-comment">...</div> — both classes are always present,
   regardless of comment_template. #fanupdate .fu-comment follows the fu-
   prefix used elsewhere (fu-entry, fu-lightbox) and is the recommended
   selector for new styling; div.comment is kept below for sites already
   using it. */
#fanupdate .fu-comment {}

div.comment {
	clear: both;
	padding: 0.5em;
}

div.comment p {padding: 0 0 0 3em;}

div.comment p.commenter {padding: 0;}

div#newComment {}

img.gravatar {
	float: right;
	margin: 0 1em 1em 0;
}

/* Shown in place of the comment list/form when an entry has comments
   turned off. */
p.comments-closed {}

/* Comment validation/spam-guard error messages (e.g. blank comment,
   blacklisted word, failed CAPTCHA). */
p.error, ul.error {
	color: #a00;
}

/*	====	Comment Form	====	*/

form#comments-form {}

h3#postcomment {}

p#cmt-rules {}
p#cmt-moderation {}

/* Moderation notice in the built-in fallback comment form template (used
   only if the comment_form_template option is ever empty). */
p.hint {}

/* "Remember me" checkbox label. */
label.checkbox {}

/* Comment form submit button. */
.submit {}

#captcha-img {}

label {
	display: block;
	float: left;
	width: 9em;
	text-align: right;
	margin: 0 0.5em 0 0;
}

input, textarea, select, option {
	font-family: Arial, sans-serif;
	font-size: 1em;
}

textarea {
	width: 100%;
}

/*	====	Footer	====	*/

a.rss {}

div.archivelink {
	text-align: right;
	margin: 2em 0 0 0;
}

/* main/archive nav links inside div.archivelink. */
.fu-nav-link {}

div.credit {
	clear: both;
	text-align: center;
	border-top: 6px solid #eee;
	margin: 1em 0 0 0;
}

/* FanUpdate credit link inside div.credit. */
.fu-credit-link {}

div.credit p {margin: 0;}
</textarea></p>

    <p><button type="button" class="button fu-copy-btn" data-copy-target="fu-css-rules"><i class="ti ti-copy" aria-hidden="true"></i> Copy</button></p>

</div><!-- END .col22 -->

<?php $fu->getFooter();
