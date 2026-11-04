# FanUpdate Redux

A lightweight PHP blogging script designed to keep an updates log for an entire
collective of fan websites. Works equally well as a standalone blog.

Originally written by **Jenny Ferenc** in 2005. Ported to PHP 7 by **Ekaterina**
(scripts.robotess.net) in 2020. 

This Redux release updates the codebase to **PHP 8.2+** and includes a full admin UI redesign.

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.2+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| PHP extensions | `pdo_mysql`, `mbstring`, `gd` (CAPTCHA and image uploads) |

> **Note:** The GD extension is required for image thumbnail generation and the
> CAPTCHA feature. It is available on virtually all shared hosts by default.

---

## Features

- **Posts & categories** — write entries, assign them to one or more categories,
  schedule future posts
- **Timezone-aware dates** — pick your real IANA timezone (e.g.
  `Europe/Helsinki`) from a searchable, region-grouped dropdown in
  **Admin → Options**, instead of a fixed UTC-hour offset. Post, comment,
  and admin-dashboard dates automatically account for daylight saving
  time; upgrading from an older version migrates any existing offset to
  the closest matching timezone automatically.
- **TinyMCE post editor** — a full self-hosted TinyMCE 7 editor (no CDN, no
  account) for the post body: headings, lists, tables, links, blockquotes,
  a Visual/HTML source toggle, fullscreen, special characters, and more.
  See [Post editor](#post-editor) below.
- **Image uploads & Media gallery** — upload JPEG, PNG, GIF, or WebP images
  directly from the post editor toolbar, or pick a previously uploaded one from
  the built-in **Choose Existing** picker. Thumbnails are generated
  automatically (GD); clicking a thumbnail on the public blog opens the
  full-size image in a built-in lightbox. Uploads live in
  `uploads/images/YYYY/MM/` with PHP execution blocked via `.htaccess`.
  **Admin → Media** lists every upload in a paginated gallery and lets you
  delete one or several at once.
- **Comments** — threaded display, moderation queue, spam blacklist, optional CAPTCHA
- **Spam protection** — four automatic layers stop bots before any database work;
  points-based scoring handles borderline human submissions
- **Gravatars** — commenter avatars pulled from gravatar.com
- **Smilies** — upload images and map them to text shortcuts
- **RSS feeds** — per-blog and per-category feeds, with podcast enclosure support
- **Fully templated** — entry, comment, comment form, and footer layouts are all
  editable from the admin panel without touching code
- **Archive pages** — browse posts by month or by category
- **Full-text search**
- **Collective script integration** — works alongside Enthusiast 3, Fanatically or
  fully standalone
- **Keyboard accesskeys** throughout the admin panel
- **Admin Update Manager** — **Admin → Updates** checks GitHub for newer
  FanUpdate Redux releases (stable or prerelease channel, with optional
  automatic checks on a configurable interval) and shows a dismissible
  Dashboard notice when one is available. From the same page you can
  download the release, review a confirmation summary, and install it. An
  admin can also install a release ZIP file they upload directly — useful
  if the server can't reach GitHub, or to install a build that isn't
  published as a release — through the same validated backup/install/
  automatic-rollback pipeline as a GitHub-fetched release.
- **Activity & error logs** — **Admin → Logs** keeps a searchable record of
  logins, lockouts, content and settings changes and update actions, plus a
  viewer for PHP errors. See [Logs](#logs).
- **Opt-in anonymous install statistics** — **off by default.** Enabled from
  **Admin → Options → Privacy**, this sends a small, anonymous ping (a
  randomly generated installation ID, the FanUpdate Redux version, and the
  PHP version — nothing else) roughly once every 30 days, so the maintainer
  can see a rough install count and which PHP versions are actually in use. See
  [Privacy: Anonymous Install Statistics](#privacy-anonymous-install-statistics)
  for exactly what is and isn't sent.

---

## Installation

### 1. Configure the database connection

Copy `blog-config.sample.php` to `blog-config.php` and open it in a text editor.

```php
// Database
$fanupdate['dbhost'] = 'localhost';
$fanupdate['dbname'] = 'db_name';
$fanupdate['dbuser'] = 'db_user';
$fanupdate['dbpass'] = 'db_pass';
```

You **must** set the database variables before running the installer.
Until installation finishes, opening the front page or `admin/` sends you
to `install.php` automatically (and `install.php` tells you to create
`blog-config.php` if it isn't there yet). The
table name variables only need changing if you are integrating with a
collective management script (see [Collective integration](#collective-integration)).

Admin credentials (username/password) are **not** configured here at all —
you'll set them interactively on the installer form in step 3, and they're
stored as plain options-table rows (bcrypt hash for the password) from then
on. Change them later from **Admin → Account**, never by editing this file.

### 2. Upload files

Upload all FanUpdate Redux files to an HTTPS-accessible directory on your server, e.g.:

```
https://yoursite.com/fanupdate/
```

### 3. Run the installer

Navigate to:

```
https://yoursite.com/fanupdate/install.php
```

Fill in the site options (site name, blog URL, install path, etc.). On a fresh
install, **admin username and password fields** appear at the bottom of the form
— fill these in and they will be saved to the database automatically as a
bcrypt hash. Click the install button and the script will create the required
database tables.

### 4. Delete the installer

`install.php` will **attempt to delete itself automatically** on successful
completion. The completion page will confirm whether this succeeded.

If auto-delete fails (usually a file permissions issue), a prominent warning
will appear on the admin dashboard every time you log in until the file is
removed. Delete it manually via FTP or your hosting file manager.

> **Why this matters:** `install.php` is unauthenticated and can overwrite your
> database settings. It must not remain accessible after installation.

### 5. Add the display snippet to your site

Visit the admin panel home page and follow the "how to display your blog" link.
It will generate a PHP snippet to copy into any `.php` page on your site:

```php
<?php
$main_limit = 5;
require_once('/path/to/fanupdate/show-blog.php');
?>
```

For a single-category blog, use `show-cat.php` instead and set `$cat_id`:

```php
<?php
// FanUpdate Redux
// subject: Collective
$cat_id = 1;
 $main_limit = 5;
require_once('/path/to/fanupdate/show-cat.php');
?>
```

To show only "Whole Collective" entries, set `$cat_id = 0`.

> **Compatibility note:** The display snippet must live in a plain `.php` file.
> It cannot be placed inside conditional PHP blocks or NL-ConvertToPHP dynamic
> include pages.

---

## Upgrading

### Enabling the Media gallery (upgrading from before Redux 2.3.0)

**Admin → Media** is backed by `media_table`, a setting read from
`blog-config.php` — not the database, so upgrading can't add it for you
automatically the way database-backed settings migrate. A fresh install's
`blog-config.php` already has it (copied from `blog-config.sample.php`),
but an existing installation upgrading from before Redux 2.3.0 needs to add
one line by hand:

```php
$fanupdate['media_table'] = 'blog_media';
```

Then visit **Admin → Updates** (a "Media gallery not configured" banner
appears on every admin page until this is done, with a direct link) to
create the table. Until then, **Admin → Media** shows an explanatory
notice instead of the gallery, and the "Choose Existing" image picker in
the post editor is simply unavailable — uploading and inserting new images
still works as before.

### Timezone setting migration (any version with a `timezone_offset` option)

The old **Options → `timezone_offset`** setting (a fixed hour offset from GMT
that never accounted for daylight saving time) has been replaced by a proper
**Timezone** dropdown backed by PHP's IANA timezone database. Upgrading
migrates it automatically — no action needed — but the mapping from a bare
hour offset to a real timezone is necessarily a best guess (many timezones
share the same offset, and most observe DST that the old option never did),
so a success message will ask you to check **Admin → Options → Timezone**
and correct it if needed.

### From any Redux 1.5–1.9 build

1. Back up your files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**.
3. If upgrading from **1.5.x**: run `install.php` — it removes the obsolete
   `_server_tz_offset` option. `install.php` will delete itself on success.
4. If upgrading from **1.6.x or earlier**: the directory structure changed in
   1.7.0 — move your files to their new locations and delete the old `css/`,
   `js/`, and `img/` directories. Update the `gravatar_default` URL in
   **Admin → Options** to `.../assets/images/gravatar.png`. Also delete
   `assets/js/standardista-table-sorting.js` (now a stub; no longer loaded).
5. Your `admin_email` setting, if present, moves to **Admin → Account → Change
   Email** (was under Options).
6. Delete these now-unused image files from `assets/images/` if upgrading from
   before 2.0: `new-entry-button.png`, `new-entry-button-active.png`,
   `tick.png`, `cross.png`, `gradient-green.png`, `gradient-teal.png`,
   `gradient-red.png`, `gradient-black.png`.

### From any Redux 1.1–1.4 build

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**.
3. Run `install.php` to apply any database changes (creates `blog_category`
   table if missing; removes obsolete options on upgrade).
   `install.php` will delete itself on success.
4. Admin credentials now live in the database, not `blog-config.php` — this
   old file-based MD5 hash isn't read at all anymore. Generate a fresh bcrypt
   hash and insert it directly into the `options` table as `admin_password`
   (and `admin_username`), or simply re-run `install.php` on a fresh database
   to set new credentials through the normal installer flow.

### From Robotess Fork 1.0 (PHP 7 port)

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**,
   which you can keep.
3. Run `install.php` again to apply any database changes.
4. Delete `install.php`.
5. Admin credentials now live in the database — see the note in "From any
   Redux 1.1–1.4 build" above for how to set new ones without the old
   automatic MD5 → bcrypt upgrade path, which no longer exists.

### From original FanUpdate 2.1 or higher

Same steps as above — keep your existing `blog-config.php`.

### From FanUpdate 2.0 / 1.6 / 1.5

You must copy your settings into the **new** `blog-config.php` format before
running the installer. The old format is not compatible.

---

## Changing your admin credentials

Use **Admin → Account** in the admin panel. The page has three sections:

- **Change Email** — update the email address used for comment notifications.
  Validated with `filter_var()` before saving.
- **Change Username** — enter a new username and confirm it. No current value
  required since you are already authenticated.
- **Change Password** — enter your current password, then the new password
  (minimum 8 characters) and a confirmation. The new bcrypt hash is written to
  the database (the `admin_password` option row) automatically and your
  session stays active.

### Lost your password?

`admin/account.php` requires your *current* password, so it can't help if
you've forgotten it. Instead, upload `reset-password.php` to the same
directory as `blog-config.php` (it already ships there — see
[File structure](#file-structure)) and visit it in a browser:

```
https://yoursite.com/fanupdate/reset-password.php
```

It works the same way `install.php` does: no login required, protected only
by the fact that reaching it at all already means you have file access next
to `blog-config.php`. Enter a new password and it's written straight to the
`admin_password` option row, same as **Admin → Account** does. The IP rate
limiter from the login form applies here too (its own lockout store, separate
from `data/login_attempts/`), and **the file deletes itself automatically on
success** — same as `install.php`. If auto-delete fails, delete it manually
via FTP or your hosting file manager right away.

---

## Templates

All templates are editable from **Admin → Options → Templates**.

### Entry template variables

| Variable | Description |
|----------|-------------|
| `{{id}}` | Numeric ID of the entry |
| `{{title}}` | Entry title |
| `{{url}}` | Permalink to the single-post page |
| `{{date}}` | Formatted post date |
| `{{category}}` | Category names as links |
| `{{comment_link}}` | Link to the comments section |
| `{{body}}` | Full entry text |

The default entry template wraps each post in `<div class="fu-entry">`, so
every entry sits in its own container inside `div#fanupdate` out of the box:

```html
<div class="fu-entry">
<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>
<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>
{{body}}
</div>
```

Style `#fanupdate .fu-entry { ... }` in your site's CSS to give each entry a
card look (background, border, shadow) — see the `.fu-entry` rule in
**Admin → Category → Get Code**'s CSS starter. If you'd rather posts flow
directly into `div#fanupdate` with no wrapper, replace `entry_template` in
**Admin → Options → Templates** with the three lines above minus the
`<div class="fu-entry">`/`</div>` wrapper. No PHP changes needed either
way — `entry_template` accepts raw HTML.

### Comment template variables

| Variable | Description |
|----------|-------------|
| `{{id}}` | Numeric ID of the comment |
| `{{name}}` | Commenter name (linked to their URL if provided) |
| `{{gravatar}}` | Commenter's Gravatar image |
| `{{date}}` | Formatted comment date |
| `{{body}}` | Comment text |

Unlike `entry_template`, the comment wrapper isn't part of `comment_template`
and can't be customized away — every comment is always printed inside
`<div id="comment{{id}}" class="comment fu-comment">...</div>`. Style
`#fanupdate .fu-comment { ... }` in your site's CSS (the recommended
selector, following the `fu-` prefix also used by `fu-entry` and
`fu-lightbox`); the older `.comment` class is kept on the same element
permanently for sites whose CSS already targets it.

### Archives (`?view=archive`)

The Month/Category Archives lists on `show-blog.php` and the per-category
year-grouped listing on `show-cat.php` aren't templates either — same as the
comment wrapper, this markup is hardcoded and always present. Each `<ul>`
carries `class="fu-archive-list"`, each `<li>` carries
`class="fu-archive-item"`, and (on `show-blog.php`'s two lists only —
`show-cat.php`'s year listing has no count) the `"(N)"` entry count is
wrapped in `<span class="fu-archive-count">`. Style `#fanupdate
.fu-archive-list`/`.fu-archive-item`/`.fu-archive-count` in your site's CSS.

### Nav, category, comment-count, and credit links

The category filter links inside `{{category}}`, the `{{comment_link}}`
link, the main/archive nav links in the default `footer_template`, and the
FanUpdate credit link all carry their own `fu-` prefixed hook class so they
can be styled directly instead of relying on inherited `font-size`/
`letter-spacing` from an ancestor element:

| Class | Where |
|-------|-------|
| `.fu-cat-link` | Category filter links inside `p.catfile` |
| `.fu-comment-count-link` | The `{{comment_link}}` link inside `p.catfile` |
| `.fu-nav-link` | Main/archive nav links inside `div.archivelink` |
| `.fu-credit-link` | The FanUpdate credit link inside `div.credit` |

See the corresponding rules in **Admin → Category → Get Code**'s CSS
starter.

`.fu-nav-link` and `.fu-credit-link` are part of the *default* footer
template. A site whose footer was saved before they existed (or that has
customised it) keeps its stored copy, so add the classes by hand in
**Admin → Options → Templates → Footer**: `class="fu-nav-link"` on the
main/archive links and `class="ext fu-credit-link"` on the credit link.

### Comment form template variables

| Variable | Description |
|----------|-------------|
| `{{fanuname}}` | Remembered commenter name |
| `{{fanuemail}}` | Remembered commenter email |
| `{{fanuurl}}` | Remembered commenter URL |
| `{{captcha_image}}` | URL of the CAPTCHA image |

### Footer template variables

| Variable | Description |
|----------|-------------|
| `{{main_url}}` | Blog front page URL (also used as the search action) |
| `{{archive_url}}` | Archive page URL |
| `{{rss_url}}` | RSS feed URL |
| `{{fanupdate_url}}` | FanUpdate project URL |
| `{{fanupdate_version}}` | Currently running version string |

---

## Adding smilies

Upload image files to the `assets/images/smilies/` directory inside your
FanUpdate folder. They will appear automatically in the **Admin → Smilies**
panel, where you can map each image to a text shortcut (e.g. `:)` → `smile.gif`).

---

## Post editor

**Admin → Entries**' post body is a self-hosted [TinyMCE](https://www.tiny.cloud/)
7 editor — vendored directly into `assets/vendor/tinymce/`, no CDN and no Tiny
Cloud account required.

Toolbar: Undo/Redo, a Format/Heading dropdown (H2–H6), Bold, Italic, Underline,
Strikethrough, text alignment, bullet/numbered lists, blockquote, horizontal
rule, Link/Unlink, Remove formatting, an "Insert image" button (see
[Image uploads](#image-uploads) below), a Visual/HTML **Code** source toggle,
Fullscreen, table insertion, and special characters.

The editor grows automatically to fit its content (between a configurable
minimum and maximum height — see below), and follows the admin panel's
day/night theme at the time the page loads (switching the theme while already
on the edit-entry page updates everything else instantly, but the editor's own
chrome needs a page reload to match — a TinyMCE limitation, not a bug).

If JavaScript is disabled, the page falls back automatically to a plain
`<textarea>` — there is nothing extra to configure for this; TinyMCE simply
never initialises and the underlying field posts as plain text like any other
form field.

**Advanced configuration** (not exposed in **Admin → Options** — set directly
in the options table if you need to change them):

| Option | Default | Description |
|--------|---------|-------------|
| `editor_min_height` | `300` | Minimum editor height in pixels |
| `editor_max_height` | `800` | Maximum editor height in pixels (before it scrolls internally) |

---

## Image uploads

The post editor's "Insert image" toolbar button opens a modal with two tabs:
**Upload New** and **Choose Existing**.

Upload New picks one or more local JPEG, PNG, GIF, or WebP files at once.
Each file is uploaded immediately to `uploads/images/YYYY/MM/` and a
constrained-fit thumbnail is generated into the `thumbs/` subdirectory at
the same path, and the upload is recorded in the **Admin → Media** gallery
(see below). Selecting several files uploads them one at a time and inserts
each into the post as its own upload finishes, in the order they were
selected — a failure on one file doesn't stop the rest, and a single
end-of-batch summary reports anything that failed. Choose Existing browses
that gallery directly and inserts a previously uploaded image with no new
upload. Either way, the snippet

```html
<a href="FULL_URL" class="fu-lightbox"><img src="THUMB_URL" alt="" /></a>
```

is inserted at the cursor position in the post body. On the public blog, clicking
any thumbnail opens the full-size image in an overlay lightbox. Pressing Escape
or clicking outside the image closes it.

When a post contains more than one `fu-lightbox` image, the lightbox groups
them and adds Prev/Next navigation (buttons, plus Left/Right arrow keys)
with a "N / total" counter — so a visitor can step through every image in
that post without closing and reopening the lightbox. Grouping is scoped to
the clicked image's post (its nearest `.fu-entry` ancestor), so images in
different posts on the same page never mix into one gallery. Navigation
doesn't wrap around: Prev is disabled on the first image, Next on the last.
A post with only one image shows no Prev/Next/counter at all.

**Upload options** (configurable from **Admin → Options**):

| Option | Default | Description |
|--------|---------|-------------|
| `upload_max_filesize` | `2097152` (2 MB) | Maximum file size in bytes |
| `upload_thumb_width` | `300` | Maximum thumbnail width in pixels |
| `upload_thumb_height` | `300` | Maximum thumbnail height in pixels |

**Image alignment** — add one of these classes to the `<a class="fu-lightbox">`
element in your post body to position the image relative to surrounding text:

| Class | Effect |
|-------|--------|
| `fu-img-left` | Float left — text wraps to the right |
| `fu-img-right` | Float right — text wraps to the left |
| `fu-img-center` | Centred block — no text wrap |

Example:

```html
<a href="FULL_URL" class="fu-lightbox fu-img-right"><img src="THUMB_URL" alt="" /></a>
```

The `uploads/` directory is protected by an auto-written `.htaccess` that blocks
PHP execution, so uploaded files cannot be executed as scripts even if a
malformed file slips through validation. The MIME type of every upload is
verified from the file bytes (not the browser-supplied Content-Type header) using
PHP's `finfo` extension.

### Managing uploaded images

**Admin → Media** lists every uploaded image in a paginated thumbnail grid
(newest first), and lets you delete one — this removes the original file, its
thumbnail, and the gallery's record of it. To delete several at once, tick
the checkbox on each image (or "Select all on this page"), click **Delete
Selected**, and confirm on the next screen. Deleting an image does not check
whether a post still embeds it; a post that does simply shows a broken image
afterwards, the same as if the file had been deleted manually.

---

## Spam protection

FanUpdate uses four automatic layers that run before any database access and
before any user-visible error is shown. Failed checks produce a fake-success
response so bots get no signal.

### Layer 1 — Honeypot (zero user friction)

Every comment form contains a hidden `<input name="fax">` field that is
positioned off-screen with CSS and marked `aria-hidden="true"`. Real users never
see or interact with it; bots fill in all fields automatically. Any submission
with a non-empty `fax` value is silently discarded.

### Layer 2 — Time gate

A hidden `<input name="fu_form_time">` contains the Unix timestamp when the page
was rendered. Submissions arriving faster than **3 seconds** are discarded as
bot traffic. Real humans take at least a few seconds to read and type a comment.

### Layer 3 — Per-IP rate limit

`class/CommentRateLimiter.php` allows up to **5 comments per IP per hour**.
Exceeding that triggers a **10-minute block**. Block state is stored in
`data/comment_rate/` (file-based, auto-created, `.htaccess` protected, not
web-accessible). This mirrors the admin login rate limiter in design.

### Layer 4 — Duplicate guard

After a comment is saved, its MD5 hash is stored in a temp file keyed by IP.
An identical comment resubmitted from the same IP within **5 minutes** is
silently discarded. This prevents simple flood/retry attacks.

### Layer 5 — Points scoring

Comments that pass all four bot gates are then scored. A comment must reach
`points_approval_threshold` to be auto-approved. Comments below
`points_pending_threshold` are silently discarded as spam.

| Test | Points |
|------|--------|
| No `HTTP_ACCEPT_LANGUAGE` header | −4 |
| More than 2 links in comment | −(number of links) |
| Zero links in comment | +2 |
| Comment shorter than 20 characters | −2 |
| Comment longer than 20 characters with no links | +1 |
| Approved comments from same email (>7 days old) | +(count) |
| Unapproved comments from same email (>7 days old) | −(count) |
| IP address equals hostname | −2 |
| Commenter provided a URL | −1 |
| URL longer than 35 characters | −2 |
| No email address | −2 |

Thresholds are configurable from **Admin → Options**.

### Optional additional layers

Enable any of these from **Admin → Options**:

- **CAPTCHA** (`captcha_on = y`) — GD-generated image challenge
- **Comment moderation** (`comment_moderation = y`) — hold all comments for
  manual approval regardless of score
- **Blacklist** — add spam keywords via **Admin → Options → Blacklist**; matched
  keywords deduct points (or block the comment outright if points scoring is off)

---

## Collective integration

FanUpdate can use your existing collective management script's table as a
category source — synced read-only from that script and shown alongside
your own admin-created categories (see "Hybrid categories" below), rather
than replacing them. Supported scripts:

| Script | Config value |
|--------|-------------|
| Enthusiast 3 | `enthusiast` |
| Fanatically | `fanatically` |
| Standalone / none | `none` |

Configured entirely from **Admin → Options → Collective Source**, *after*
installing — pick a known script from the dropdown (its collective table
name and column names are filled in automatically) or choose **Custom…** to
integrate with an unlisted script by entering its identifier, table name,
and column names directly. FanUpdate must be installed in the **same
database** as the collective script by default — see
"Separate-database support" below if that database is different from
FanUpdate's own. `blog-config.sample.php` no longer pre-declares
`collective_script` at all — a fresh install simply starts standalone, and
you configure the real script from this page afterward.

> **`collective_table` is the one exception** that's still worth setting in
> `blog-config.php` *before* installing if you already know which script
> you're integrating with: unlike `collective_script`/`col_id`/`col_subj`
> (which have safe defaults everywhere and are purely a database-migration
> concern), a fresh **standalone** install needs a real `collective_table`
> value to actually create its own categories table with, before the
> database exists to source one from any other way.
>
> **Legacy config file values:** `blog-config.php`'s
> `$fanupdate['collective_script']`, `$coltable`, and
> `$fanupdate['collective_table']` are only read once — by `install.php` on
> a fresh install, or by the Admin Update Manager's one-time migration on
> an existing installation — to seed the database. After that, only the
> values in **Admin → Options → Collective Source** are used; editing
> `blog-config.php` again has no further effect. (If you're on an
> installation that migrated `collective_script` before `collective_table`
> got its own admin field, the one-time migration for `collective_table`
> runs independently the next time any admin page loads, so it's picked up
> automatically without needing to redo anything.)

### Separate-database support

If your collective script's database is separate from the one you want
FanUpdate installed in — common on shared hosting, which usually gives you
one MySQL server but a small quota of separate databases — you can
schema-qualify the **Collective/categories table name** field on
**Admin → Options → Collective Source** instead of moving everything into
one database, e.g. `enthusiast_db.owned`.

This works as long as both databases are on the **same MySQL server**, and
the `dbuser` FanUpdate connects with has been **GRANTed privileges on that
other database too** (ask your host, or set it up yourself if you manage the
server). FanUpdate still makes only one database connection, using the
`dbhost`/`dbname`/`dbuser`/`dbpass` credentials above — those always describe
FanUpdate's own database; only `collective_table` can point at a different
one. A genuinely separate MySQL *server* (a different host entirely) is not
supported — several places join the collective table directly against
FanUpdate's own tables in one query, which isn't possible across servers.

### Hybrid categories (standalone + collective, side by side)

When `collective_script` is set to anything other than `'n'`, FanUpdate
keeps a second, always-its-own categories table
(`$fanupdate['standalone_category_table']`, default
`blog_category_standalone` — created automatically by `install.php`) so you
can still create and manage your own categories from **Admin → Categories**
at the same time as the ones synced from your collective script. The two
kinds coexist in every category list and the post editor's category picker,
labelled by source. Categories synced from the collective script are
read-only in FanUpdate — you can't rename or delete them here — but their
per-category settings (entry/comment templates, Gravatar options) are fully
editable regardless of source, same as always.

If you'd rather only see your own categories, turn off **Show collective
categories** (`show_collective_categories`) in **Admin → Options → Main**.
Collective categories then disappear from the Categories list and the post
editor's picker; entries already filed under them, and your collective
script configuration, are left alone.

In standalone mode (`collective_script = 'n'`) there is only ever one
category source, exactly as before — the table above isn't created or used.

---

## RSS feeds

| URL | Description |
|-----|-------------|
| `/fanupdate/rss.php` | All posts |
| `/fanupdate/rss.php?c=3` | Posts in category 3 |
| `/fanupdate/rss-comments.php` | All comments |
| `/fanupdate/rss-comments.php?id=5` | Comments on entry 5 |

Links to iTunes-compatible media files (`.mp3`, `.mov`, `.pdf`, etc.) in post
bodies are automatically added as RSS enclosures for podcast support.

---

## Admin keyboard shortcuts

| Key | Action |
|-----|--------|
| `n` | New item (entry, category, blacklist word, smiley) |
| `s` | Save / Add / Update |
| `p` | Preview |
| `x` | Delete |
| `q` | Search input |

---

## Security

### Login rate limiting

After 5 failed login attempts from the same IP address, that IP is locked out
for 15 minutes. Each subsequent lockout doubles the window (exponential
backoff), capped at 24 hours. A successful login resets the counter immediately.

Lock state is stored in `data/login_attempts/` (flat JSON files, one per IP).
The directory is created automatically on first use and is protected by an
auto-generated `.htaccess` deny rule. It is not web-accessible. If you use
nginx, add a `location ^~ /data/ { deny all; }` rule to your server config.

### Session cookies

The `fu_username` and `fu_token` session cookies are issued with:

| Flag | Value | Reason |
|------|-------|--------|
| `Secure` | `true` | Never transmitted over plain HTTP |
| `HttpOnly` | `true` | Inaccessible to JavaScript |
| `SameSite` | `Strict` | Not sent on any cross-site request |

The site must run on HTTPS for `Secure` cookies to work. The admin panel will
function on HTTP but the cookies will not be sent to the browser, effectively
preventing login.

### CSRF protection

Every administrative POST request — adding, editing, or deleting entries,
comments, categories, blacklist words, smilies, templates, options, account
details, and image uploads, plus the login form itself — requires a valid CSRF
token. Tokens are generated with `random_bytes(32)`, stored server-side in a
PHP session, and compared with `hash_equals()` to prevent timing attacks.

The session used to store the token is separate from the `fu_username` /
`fu_token` login cookies above and carries the same cookie flags (`Secure`,
`HttpOnly`, `SameSite=Strict`), under the cookie name `fu_session`. As with the
login cookies, this requires HTTPS.

Requests that submit an HTML form (the standard admin pages, including the new
blog/comment delete-confirmation views — see "Deleting entries and comments"
below) receive the token via a hidden `csrf_token` field. Requests made via
JavaScript `fetch()` — the approve-comment and image-upload actions — include
the token from a page-level `fuCsrfToken` value emitted in the admin `<head>`.
A missing or invalid token is rejected before any action is processed; the
approve-comment AJAX request receives an HTTP 403 response instead of a page
re-render, so the browser-side code can distinguish a rejected request from a
successful one.

### Deleting entries and comments

Deleting a blog entry or comment is a two-step, page-based flow rather than a
single click:

1. Clicking **Delete** — on the entries list, comments list, or an entry's
   edit page — navigates to a confirmation view (`?action=confirm-delete&id=N`)
   that names what will be removed and does not touch the database.
2. The confirmation view has its own **Delete** button in a real form. Only
   submitting that form actually deletes the record.

This works with JavaScript disabled, and protects against a stray click on a
table row deleting something by accident — there's no JS `confirm()` popup to
miss or auto-dismiss.

On the entries list, checking one or more boxes and clicking **Delete
selected** goes through the same two-step flow for all of them at once,
listing every affected title before you confirm.

---

## Logs

**Admin → Logs** has two tabs.

**Activity** records security and admin events: admin logins (successes,
failures, and lockouts), logouts, password/username/email changes and resets,
entry, category, comment and media changes, blacklist and settings changes,
update actions (download, install, rollback, database upgrade), and public
comments blocked by the rate limit. You can filter by event type, search the
text, and filter by date range. Times, and the date filters, use the timezone
set in **Admin → Options** (entries are stored in UTC, so changing the
timezone later re-labels old entries correctly).

- It never stores passwords, tokens, comment text, setting *values*, or the
  username typed into a failed login (a mistyped password often lands there).
- Entries older than **Log retention (days)** (default 90, `0` = keep forever)
  are removed automatically.
- Turn on **Anonymise IPs in logs** to zero the last part of each address
  before it is stored.
- Both settings are in **Admin → Options → Main**. They appear there after
  your next version upgrade; until then the defaults above apply.

**Errors** lists PHP warnings, errors and uncaught exceptions that FanUpdate
caught, newest first, with server paths shortened to install-relative ones.
They are kept in `data/logs/error.log` (auto-created and protected by a
`.htaccess` deny rule — on nginx, add the `location ^~ /data/ { deny all; }`
rule mentioned under [Login rate limiting](#login-rate-limiting)). The file
rotates at 512&nbsp;KB, so it cannot grow without bound.

Both tabs have a **Clear** button. The activity log lives in a `blog_log`
table, created automatically — on an upgrade, by the database update that
**Admin → Updates** offers. To use a different table name, set
`$fanupdate['log_table']` in `blog-config.php`.

## Privacy: Anonymous Install Statistics

**Off by default.** From **Admin → Options → Privacy** you can opt in to
sending a small, anonymous ping that helps the maintainer gauge roughly how
many installations exist and which PHP versions are actually in use — the
latter is what makes it possible to decide when it's safe to drop support for
an old PHP version, instead of guessing.

### What is sent

Only these three fields, ever:

| Field | Example | What it is |
|-------|---------|------------|
| `install_uuid` | `a1b2c3d4-...` | A random UUIDv4, generated once the first time you enable this and stored in this installation's own database. Its only purpose is letting the server tell two pings from the same installation apart from two pings from different installations, so a long-running site isn't miscounted as a new install every time it pings. |
| `version` | `2.2.0` | This installation's FanUpdate Redux version. |
| `php_version` | `8.4.2` | This server's PHP version. |

### What is never sent

Domain name, site URL or hostname, site title, admin username or email,
database information, blog content, theme, plugins, or your IP address
(beyond whatever your own webserver's normal access logs already record for
any request, the same as they do for every other page on your site).

### How often

Approximately once every 30 days while enabled — not on every page load.
There's no cron involved: like the Admin Update Manager's automatic update
checks, this happens opportunistically the next time an admin page loads
after 30 days have passed since the last *successful* ping. A failed ping
(the server was briefly unreachable, for example) doesn't push the schedule
out further — it's simply retried on the next admin page load.

The ping request itself uses a short (3 second) timeout and never delays or
blocks a page load for your site's visitors — it only ever happens on an
authenticated admin page view, and any failure is silent (no warnings or
errors shown, nothing is logged as broken).

### How to disable

Uncheck **Enable anonymous install statistics** on **Admin → Options →
Privacy** at any time. No further pings are sent. Your installation ID is
kept (not deleted) so that re-enabling later doesn't register as a brand new
installation — it is never regenerated unless you reset it yourself by
editing the stored value directly.

### Independence from update checking

This feature is completely separate from the Admin Update Manager described
above — its own settings, its own request code, and its own endpoint. It
works identically whether or not automatic update checks are enabled, and
disabling one has no effect on the other.

---

## Theming

The admin panel ships with two stylesheets in `assets/css/`:

| File | Description |
|------|-------------|
| `style.css` | Default — Dusty Rose palette (light base, mauve accent) |
| `style-night.css` | Night variant — Midnight palette (dark navy base, indigo accent) |

Click the moon icon in the top-right of the admin nav bar (next to
Account/Credits/Logout) to toggle between them — no file editing needed.
The choice is saved in the browser's `localStorage` (key `fu_theme`) and
applied instantly on every subsequent admin page load, before first paint,
so there's no flash of the wrong theme. This is a per-browser preference,
not a site-wide setting — each admin who logs in picks their own.

`style.css` also responds to the browser's `prefers-color-scheme: dark` media
query with a softer dark variant of the Dusty Rose palette when no explicit
choice has been saved yet. `style-night.css` is always dark and ignores the
media query — that's what the toggle switches to.

---

## JavaScript modernisation notes (Redux 1.6.0+)

All JavaScript files have been rewritten to remove IE-era compatibility code:

- **`fetch()` replaces `XMLHttpRequest`** — `getXmlHttpObject()` (IE 5/6/7
  `ActiveXObject` fallback) is gone; `makeRequest()` now uses `fetch()`
- **`addEventListener` replaces Dean Edwards' `addEvent` shim** — the 2005
  IE-compat event system is removed; `addEvent` / `removeEvent` are kept as
  thin wrappers so existing call sites require no changes
- **Dead IE code deleted** — the `document.selection.createRange()` branch in
  `formatText()` and the `window.event` / `srcElement` fallbacks in
  `getEventTarget()` are gone
- **`classList` replaces manual `className` string manipulation** — used by the
  `css` utility object throughout
- **`querySelectorAll` replaces manual class-search loops**
- **`standardista-table-sorting.js` merged** — the sorting logic now lives
  inside `fanupdate.js`; the separate file is a stub and can be deleted from
  the server once `header.php` has been updated (Redux 1.6.0 `header.php` no
  longer loads it)
- **All `var` → `const` / `let`; all global leaks eliminated**

No functional changes to the admin panel — all features work identically in
current browsers.

---

## PHP 8.2 compatibility notes

This release resolves all PHP 8.2 deprecation warnings and enforces strict typing
throughout the codebase:

- **`declare(strict_types=1)`** — present in every PHP file; call sites that pass
  `getOpt()` return values (which are `mixed`) directly to typed parameters have
  been updated with explicit casts
- **Dynamic properties** — all class properties are now explicitly declared
- **`utf8_encode()` / `utf8_decode()`** — replaced with `mb_convert_encoding()`
  wrappers `fu_utf8_encode()` / `fu_utf8_decode()` in `functions.php`
- **`get_magic_quotes_gpc()`** — removed (was removed in PHP 8.0)
- **Curly-brace string indexing** (`$str{0}`) — replaced with `$str[0]`
- **`srand()` / `rand()`** in `antispambot()` — replaced with `random_int()`

---

## File structure

```
fanupdate/
├── index.php                # Redirects to admin/index.php (suppresses directory listing)
├── blog-config.php          # Your configuration (copy from .sample)
├── blog-config.sample.php   # Configuration template
├── version.php              # Single source of truth for version metadata
├── functions.php            # Shared helper functions
├── install.php              # Installer (auto-deletes on success)
├── reset-password.php       # Emergency admin password reset (auto-deletes on success)
├── process.php              # Comment submission handler
├── show-blog.php            # Public blog display (all categories)
├── show-cat.php             # Public blog display (single category)
├── rss.php                  # RSS feed
├── rss-comments.php         # RSS comments feed
├── captcha.php              # CAPTCHA image generator
├── favicon.ico
├── LICENSE.md                # GPLv3 licence text
├── admin/
│   ├── index.php            # Admin dashboard
│   ├── blog.php             # Admin: entries list
│   ├── upload.php           # Admin: image upload endpoint (AJAX)
│   ├── logs.php             # Admin: activity log and PHP error log viewer
│   ├── media.php            # Admin: paginated media gallery (browse/delete uploads)
│   ├── category.php         # Admin: categories
│   ├── comment.php          # Admin: comments
│   ├── options.php          # Admin: main options
│   ├── templates.php        # Admin: template editor
│   ├── smilies.php          # Admin: smilies
│   ├── blacklist.php        # Admin: spam blacklist
│   ├── collective-source.php# Admin: collective/fanlisting script pairing
│   ├── privacy.php          # Admin: opt-in anonymous install statistics
│   ├── account.php          # Admin: change email, username, and password
│   ├── update.php           # Admin: check for FanUpdate Redux updates (GitHub)
│   ├── credits.php          # Admin: version info and credits
│   ├── get-code.php         # Display snippet generator
│   ├── header.php           # Admin panel header
│   └── footer.php           # Admin panel footer
├── class/
│   ├── FanUpdate.php        # Core application class
│   ├── FanUpdate_Post.php   # Post model
│   ├── FanUpdate_Comment.php# Comment model
│   ├── CommentRateLimiter.php# Per-IP comment rate limiter
│   ├── LoginRateLimiter.php # IP-based login rate limiter
│   ├── SqlConnection.php    # Database abstraction (singleton)
│   ├── Repository/          # Data-access classes (one *RepositoryInterface + *Repository pair per admin domain, plus Update/Upgrade/Privacy)
│   ├── Service/              # Business-logic classes: one *Service per admin domain, plus the Admin Update Manager's UpdateManager, DownloadManager, BackupManager, InstallManager, RollbackManager, SystemStatusChecker, UpgradeRunner, and the anonymous install ping's InstallPingService
│   └── Update/               # Admin Update Manager value objects/provider: UpdateProviderInterface, GitHubUpdateProvider, ReleaseInfo, DownloadResult, BackupResult, InstallResult, RollbackResult, SystemStatusCheck, UpgradeResult
├── data/
│   ├── login_attempts/      # Login rate limiter lock files (auto-created, not web-accessible)
│   ├── comment_rate/        # Comment rate limiter lock files (auto-created, not web-accessible)
│   └── logs/                # Application error log (auto-created, not web-accessible)
├── uploads/
│   ├── .htaccess            # Blocks PHP execution (auto-created on first upload)
│   └── images/
│       └── YYYY/MM/         # Uploaded originals, date-partitioned
│           └── thumbs/      # Auto-generated thumbnails (same filename, sub-directory)
├── assets/
│   ├── css/
│   │   ├── style.css        # Admin panel styles — Dusty Rose (default)
│   │   └── style-night.css  # Admin panel styles — Midnight Night variant
│   ├── js/
│   │   ├── fanupdate.js              # Core utilities + table sorting + lightbox
│   │   ├── fanupdate-admin.js        # Admin panel init (confirm-delete, theme toggle, copy buttons)
│   │   ├── fanupdate-tinymce.js      # TinyMCE init + image upload/Choose Existing (admin/blog.php only)
│   │   ├── fanupdate-comment-admin.js# Comment approve AJAX
│   │   ├── fanupdate-post-admin.js   # Entry list "select all" checkbox wiring
│   │   ├── fanupdate-comment-submit.js# Front-end comment submission AJAX
│   │   └── select-all.js             # Checkbox select-all, dynamic categories, timestamp
│   ├── vendor/
│   │   └── tinymce/         # Self-hosted TinyMCE (vendored, trimmed — see docs/THIRD-PARTY-LICENSES.md)
│   ├── fonts/
│   │   └── VeraSe.ttf       # CAPTCHA font (Bitstream Vera Serif)
│   └── images/
│       ├── fanupdate.png    # Admin panel logo (RSS feed icon)
│       ├── gravatar.png     # Default Gravatar placeholder
│       └── smilies/         # Smiley images (upload your own here)
│           └── *.gif / *.png
├── docs/
│   ├── CHANGELOG.md         # This project's changelog
│   ├── HISTORY.md           # Archive of completed TODO.md items, by release
│   ├── TROUBLESHOOTING.md   # Common issues and fixes
│   ├── DATABASE.md          # Database layer developer docs (PDO API, query patterns)
│   └── THIRD-PARTY-LICENSES.md# Vendored library licences (TinyMCE, DOMPurify, Parsedown, etc.)
└── old-docs/
    ├── README.md            # Original-author attribution notes
    ├── readme.txt           # Original readme (2008)
    └── changelog.txt        # Original changelog (2008)
```

---

## Releasing a new version

Edit only `version.php`:

```php
const FU_VERSION      = 'Redux 1.x.y';
const FU_DB_VERSION   = '2.2.1b2';   // update only if install.php adds DB changes
const FU_RELEASE_DATE = 'YYYY-MM-DD';
const FU_RELEASE_NAME = 'codename';
```

Everything else — the admin panel footer, header title, RSS generator string,
install version check, and credits page — reads from these constants
automatically.

---

## License

GNU General Public License v3 or later.  
See <https://www.gnu.org/licenses/gpl-3.0.html> for details.

Original script by Jenny Ferenc  
PHP 7 port by Ekaterina — <https://scripts.robotess.net>  
PHP 8.2 Redux by Ariane
