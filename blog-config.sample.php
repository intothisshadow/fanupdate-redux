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

// Admin login (username/password) is not configured here at all — it's set
// once, interactively, on install.php's fresh-install form, and stored as
// plain options-table rows (bcrypt hash for the password) from then on.
// Change it later from Admin -> Account, never by editing this file.

// ------------->DATABASE VARIABLES

$fanupdate['dbhost']		= 'localhost';
$fanupdate['dbuser']		= 'admin';
$fanupdate['dbpass']		= 'pass';
$fanupdate['dbname']		= 'database_name';

// ------------->TABLE VARIABLES

// Which collective/fanlisting script (if any) this installation integrates
// with, and its column names, are configured from Admin -> Options ->
// Collective Source AFTER you install — not here. (They used to live in
// this file as $fanupdate['collective_script']/$coltable, but every value
// there had a known-good default and a dedicated admin UI now sets it up
// interactively — including auto-filling the right column names for a
// known script — so pre-declaring it in a config file you edit by hand
// before the database even exists added nothing but clutter. If you're
// looking at an EXISTING blog-config.php that still has those two settings
// in it, leave them — they're read once, automatically, to migrate your
// site's current configuration into the database; removing them here only
// affects brand-new installs going forward.)

// collective_table is different: it's an ordinary per-request setting
// (like blog_table/catjoin_table below), not something with a database-
// migrated equivalent that fully takes over — a fresh standalone install
// needs a real table name here to create its own categories table with in
// the first place, before the database has anywhere else to read one from.
// If you're integrating with an existing collective script (Enthusiast/
// Fanatically) from the start, point this at the table listing what this
// collective actually OWNS — here's the usual defaults:

// Enthusiast			'owned'
// Fanatically			'fanatic_listings'

// NOT Fanatically's 'fanatic_categories' table — that's Fanatically's own
// site-wide list of every category across the whole directory (Actors,
// Movies, Music, ...), unrelated to which specific fanlistings any one
// collective owns. Pointing collective_table at it pulls in every category
// in the directory instead of just this site's own fanlistings.

// Otherwise, leave this as FanUpdate's own categories table name (created
// automatically on fresh install) or enter a custom one.
//
// Separate-database support (TODO.md's Database Architecture item): if your
// collective script's database lives in a different database/schema than
// this one (common on shared hosting, which usually gives you one MySQL
// server but a small quota of separate databases), you can schema-qualify
// this value instead of moving everything into one database, e.g.:
//   $fanupdate['collective_table'] = 'enthusiast_db.owned';
// This only works when both databases are on the SAME MySQL server, and the
// dbuser above must be GRANTed privileges on that other database too (ask
// your host, or set it up yourself if you manage the server) — FanUpdate
// connects once, using the credentials above, and simply qualifies this one
// table reference; it does not open a second connection. dbhost/dbuser/
// dbpass/dbname above always describe FanUpdate's OWN database — only this
// one setting (the externally-owned collective table) can point elsewhere.
// This value is also freely editable later from Admin -> Options ->
// Collective Source, same as collective_script/col_id/col_subj.

$fanupdate['collective_table']	= 'blog_category';

// Other tables for the script.

$fanupdate['blog_table']		= 'blog';
$fanupdate['catjoin_table']		= 'blog_catjoin';
$fanupdate['comments_table']	= 'blog_comments';
$fanupdate['options_table']		= 'blog_options';
$fanupdate['blacklist_table']	= 'blog_blacklist';
$fanupdate['catoptions_table']	= 'blog_catoptions';
$fanupdate['smilies_table']		= 'blog_smilies';

// Uploaded-image index (Admin -> Media). Records one row per image uploaded
// through the WYSIWYG editor's "insert image" button (admin/upload.php) so
// the Media gallery can list/paginate/delete them without re-scanning the
// uploads/images/ directory tree on every page load.
$fanupdate['media_table']		= 'blog_media';

// Standalone categories table (Categories Support / hybrid mode).
// Only used when a collective script is actually configured (see Admin ->
// Options -> Collective Source after installing) — lets FanUpdate manage
// its own admin-created categories alongside the ones synced from your
// collective script, instead of the two being mutually exclusive. Ignored
// (and safe to leave as the default) for a standalone installation.
$fanupdate['standalone_category_table'] = 'blog_category_standalone';
