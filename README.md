# FanUpdate Redux

A lightweight PHP blogging script designed to keep an updates log for an entire
collective of fan websites. Works equally well as a standalone blog.

Originally written by **Jenny Ferenc** in 2005. Ported to PHP 7 by **Ekaterina**
(scripts.robotess.net) in 2020. 

This Redux release updates the codebase to **PHP 8.2+** (current release: Redux 1.7.0).

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.2+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| PHP extensions | `mysqli`, `mbstring`, `gd` (CAPTCHA only) |

> **Note:** The GD extension with FreeType support is only required if you
> enable the CAPTCHA feature.

---

## Features

- **Posts & categories** — write entries, assign them to one or more categories,
  schedule future posts
- **Comments** — threaded display, moderation queue, spam blacklist, optional CAPTCHA
- **Spam scoring** — points-based automatic spam detection (no configuration
  required for most sites)
- **Gravatars** — commenter avatars pulled from gravatar.com
- **Smilies** — upload images and map them to text shortcuts
- **RSS feeds** — per-blog and per-category feeds, with podcast enclosure support
- **Fully templated** — entry, comment, comment form, and footer layouts are all
  editable from the admin panel without touching code
- **Archive pages** — browse posts by month or by category
- **Full-text search**
- **Collective script integration** — works alongside Enthusiast 3, Flinx
  Collective, and Fan Admin, or fully standalone
- **Keyboard accesskeys** throughout the admin panel

---

## Installation

### 1. Configure the database connection

Copy `blog-config.sample.php` to `blog-config.php` and open it in a text editor.

```php
// Admin credentials — can also be set via the installer (see step 3)
$fanupdate['admin_username'] = 'admin';
$fanupdate['admin_password'] = password_hash('changeme', PASSWORD_BCRYPT);

// Database
$fanupdate['dbhost'] = 'localhost';
$fanupdate['dbuser'] = 'db_user';
$fanupdate['dbpass'] = 'db_pass';
$fanupdate['dbname'] = 'db_name';
```

You **must** set the database variables before running the installer.  
Admin credentials can be set here manually **or** entered through the install
form in step 3 — the installer will write them into `blog-config.php` for you.  
The table name variables only need changing if you are integrating with a
collective management script (see [Collective integration](#collective-integration)).

> **Tip:** If you want the installer to write your credentials automatically,
> make `blog-config.php` writable before running it (`chmod 666 blog-config.php`
> on most shared hosts), then tighten permissions afterward (`chmod 644`).

> **Password format:** Since Redux 1.2.0 passwords are stored as bcrypt hashes.
> Do **not** use `md5()` here. To generate a hash manually:
> `php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"`
> If you have an existing MD5 hash in `blog-config.php`, simply log in once
> and it will be upgraded to bcrypt automatically.

### 2. Upload files

Upload all FanUpdate Redux files to an HTTP-accessible directory on your server, e.g.:

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
— fill these in and they will be saved to `blog-config.php` automatically as a
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

For a single-category blog, use `show-cat.php` instead and set `$listingid`:

```php
<?php
$listingid = 3;   // category ID from the Categories admin tab
$main_limit = 5;
require_once('/path/to/fanupdate/show-cat.php');
?>
```

To show only "Whole Collective" entries, set `$listingid = 0`.

> **Compatibility note:** The display snippet must live in a plain `.php` file.
> It cannot be placed inside conditional PHP blocks or NL-ConvertToPHP dynamic
> include pages.

---

## Upgrading

### From Redux 1.6.0

1. Back up your files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**.
3. The directory structure has changed in this release — see
   [File structure](#file-structure) below. Move your existing files to their
   new locations and delete the old `css/`, `js/`, and `img/` directories.
4. Update the `gravatar_default` URL in **Admin → Options** to point to the
   new path (`/assets/images/gravatar.png`).
5. No database changes; `install.php` does not need to be re-run.

### From Redux 1.5.2

1. Back up your files.
2. Upload the `assets/js/` directory and `admin/header.php`.
3. Delete `assets/js/standardista-table-sorting.js` from the server — it is no
   longer loaded and can be removed.
4. No database changes; `install.php` does not need to be re-run.

### From Redux 1.5.1

1. Back up your files.
2. Upload `show-cat.php` — this is the only changed file.
3. No database changes; `install.php` does not need to be re-run.

### From Redux 1.5.x (1.5.0)

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**,
   which you can keep.
3. Run `install.php` — it will automatically remove the obsolete
   `_server_tz_offset` option from your database.
4. `install.php` will delete itself automatically on success.

### From Redux 1.4.x

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**,
   which you can keep.
3. Run `install.php` to apply the `blog_category` table creation if you are
   using standalone mode and the table does not yet exist (see
   [Troubleshooting](#blog_category-table-doesnt-exist-categories-page-is-empty)).
4. `install.php` will delete itself automatically on success.

### From Redux 1.3.x

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**,
   which you can keep.
3. Your existing session cookies will remain valid; no re-login required unless
   you are upgrading from a version before the HMAC token was introduced.

### From Redux 1.1.x or 1.2.x

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**,
   which you can keep.
3. If your `admin_password` in `blog-config.php` is still an MD5 hash (32 hex
   characters), simply log in once — the hash will be upgraded to bcrypt
   automatically. No manual step required as long as `blog-config.php` is
   writable.

### From Robotess Fork 1.0 (PHP 7 port)

1. Back up your database and all files.
2. Upload all new files, overwriting existing ones — **except `blog-config.php`**,
   which you can keep.
3. Run `install.php` again to apply any database changes.
4. Delete `install.php`.
5. Log in once to trigger the automatic MD5 → bcrypt password upgrade.

### From original FanUpdate 2.1 or higher

Same steps as above — keep your existing `blog-config.php`.

### From FanUpdate 2.0 / 1.6 / 1.5

You must copy your settings into the **new** `blog-config.php` format before
running the installer. The old format is not compatible.

---

## Changing your admin credentials

Use **Admin → Account** in the admin panel. The page has two sections:

- **Change Username** — enter a new username and confirm it. No current value
  required since you are already authenticated.
- **Change Password** — enter your current password, then the new password
  (minimum 8 characters) and a confirmation. The new bcrypt hash is written to
  `blog-config.php` automatically and your session stays active.

If `blog-config.php` is not writable, both forms display the ready-to-paste
value so you can update the file manually in a text editor.

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

### Comment template variables

| Variable | Description |
|----------|-------------|
| `{{id}}` | Numeric ID of the comment |
| `{{name}}` | Commenter name (linked to their URL if provided) |
| `{{gravatar}}` | Commenter's Gravatar image |
| `{{date}}` | Formatted comment date |
| `{{body}}` | Comment text |

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

## Spam protection

Comments are scored automatically using a points system. A comment must reach
`points_approval_threshold` to be auto-approved. Comments below
`points_pending_threshold` are silently discarded as spam.

Scoring checks include:

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
You can also enable manual moderation or CAPTCHA as additional layers.

---

## Collective integration

FanUpdate can use your existing collective management script's table as its
category source. Supported scripts:

| Script | Config value |
|--------|-------------|
| Enthusiast 3 | `'e'` |
| Flinx Collective | `'f'` |
| Fan Admin | `'g'` |
| Standalone / none | `'n'` |

Set `$fanupdate['collective_script']` in `blog-config.php` to the appropriate
value. FanUpdate must be installed in the **same database** as the collective
script.

To use a different collective script, add a `$coltable` entry in
`blog-config.php`:

```php
$coltable['x']['id']      = 'primary_key_column';
$coltable['x']['subject'] = 'name_column';
$fanupdate['collective_script'] = 'x';
```

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

This release resolves all PHP 8.2 deprecation warnings:

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
├── process.php              # Comment submission handler
├── show-blog.php            # Public blog display (all categories)
├── show-cat.php             # Public blog display (single category)
├── rss.php                  # RSS feed
├── rss-comments.php         # RSS comments feed
├── captcha.php              # CAPTCHA image generator
├── admin/
│   ├── index.php            # Admin dashboard
│   ├── blog.php             # Admin: entries list
│   ├── category.php         # Admin: categories
│   ├── comment.php          # Admin: comments
│   ├── options.php          # Admin: main options
│   ├── templates.php        # Admin: template editor
│   ├── smilies.php          # Admin: smilies
│   ├── blacklist.php        # Admin: spam blacklist
│   ├── account.php          # Admin: change username and password
│   ├── credits.php          # Admin: version info and credits
│   ├── get-code.php         # Display snippet generator
│   ├── header.php           # Admin panel header
│   └── footer.php           # Admin panel footer
├── class/
│   ├── FanUpdate.php        # Core application class
│   ├── FanUpdate_Post.php   # Post model
│   ├── FanUpdate_Comment.php# Comment model
│   ├── LoginRateLimiter.php # IP-based login rate limiter
│   └── SqlConnection.php    # Database abstraction (singleton)
├── data/
│   └── login_attempts/      # Rate limiter lock files (auto-created, not web-accessible)
├── assets/
│   ├── css/
│   │   └── style.css        # Admin panel styles
│   ├── js/
│   │   ├── fanupdate.js              # Core utilities + table sorting
│   │   ├── fanupdate-admin.js        # Admin panel init (confirm-delete, WYSIWYG)
│   │   ├── fanupdate-comment-admin.js# Comment approve/delete AJAX
│   │   ├── fanupdate-post-admin.js   # Post delete AJAX
│   │   ├── fanupdate-comment-submit.js# Front-end comment submission AJAX
│   │   └── select-all.js             # Checkbox select-all, dynamic categories, timestamp
│   ├── fonts/
│   │   └── VeraSe.ttf       # CAPTCHA font (Bitstream Vera Serif)
│   └── images/
│       ├── fanupdate.png    # Admin panel logo
│       ├── gravatar.png     # Default Gravatar placeholder
│       ├── feed.png         # RSS feed icon
│       ├── gradient-*.png   # Button gradient images
│       ├── new-entry-button.png
│       ├── text_bold.png    # WYSIWYG toolbar icons
│       ├── text_italic.png
│       └── smilies/         # Smiley images (upload your own here)
│           └── *.gif / *.png
└── docs/
    ├── readme.txt           # Original readme
    └── changelog.txt        # Original changelog
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

## Troubleshooting

### install.php shows a blank white page

This means a PHP fatal error is being swallowed by the server. Redux 1.1.1+
forces `display_errors = On` at the top of `install.php`, so this should no
longer occur. If you are on an older copy, add these two lines at the very top
of `install.php` (after `<?php`):

```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

### install.php shows no form fields (just the button)

You have a pre-1.1.1 copy of `install.php`. The bug was a loose comparison
(`strpos(...) == 0`) that hid every visible field. Download the current
`install.php`.

### Fatal error: Uncaught mysqli_sql_exception … table doesn't exist

PHP 8.0+ changed mysqli's default error reporting mode to throw exceptions
instead of returning `false`. The `@` operator does not catch exceptions.
Redux 1.1.1+ wraps all version-detection queries in `try/catch` and temporarily
disables exception mode inside `SqlConnection::Execute()`. Download the current
`install.php` and `class/SqlConnection.php`.

### WARNING: Could not execute query. Table '…blog_options' doesn't exist

This warning appeared on the install form in pre-1.1.1 builds because
`FanUpdate::addOptFromDb()` triggered a visible warning when the options table
didn't exist yet. Fixed in Redux 1.1.1 — `addOptFromDb()` now passes
`null` as the error message so missing tables fail silently during install.

### I can't log in after installing — no credentials were set

The admin username and password live in `blog-config.php`, not the database.
The install form in Redux 1.1.1+ collects them and writes them for you. If you
installed with an older version, open `blog-config.php` and set:

```php
$fanupdate['admin_username'] = 'your-username';
$fanupdate['admin_password'] = password_hash('your-password', PASSWORD_BCRYPT);
```

### I upgraded from Redux 1.1.x and can't log in

Your `admin_password` in `blog-config.php` may still be an MD5 hash. Redux 1.2.0+
handles this automatically — as long as `blog-config.php` is writable, the hash
is upgraded to bcrypt on your first successful login. If `blog-config.php` is
read-only, update it manually:

```php
$fanupdate['admin_password'] = password_hash('your-password', PASSWORD_BCRYPT);
```

Or run: `php -r "echo password_hash('your-password', PASSWORD_BCRYPT);"` and
paste the result.

### I am locked out of the admin panel

After 5 failed login attempts your IP is locked out for 15 minutes (Redux
1.3.0+). The lockout window doubles on each subsequent lockout, up to a maximum
of 24 hours.

To clear a lockout manually, delete the relevant lock file from
`data/login_attempts/` on the server. Files are named `fu_<md5-of-ip>.json`.
If you do not know which file is yours, deleting all `fu_*.json` files in that
directory is safe — they will be recreated on the next failed attempt.

### Login fails even with the correct password after upgrading to Redux 1.4.0

Redux 1.4.0 adds `Secure=true` to the session cookies, which means the browser
will not send them over plain HTTP. If your admin panel URL begins with `http://`
rather than `https://`, the cookies will never arrive and every page load will
show the login form. The fix is to ensure the site runs on HTTPS and access the
admin panel via `https://`.

### `blog_category` table doesn't exist — categories page is empty

On a fresh standalone install with Redux 1.4.x or earlier, the `blog_category`
table was never created by the installer. Fix it by running this query in
phpMyAdmin (or any MySQL client):

```sql
CREATE TABLE `blog_category` (
  `fl_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `fl_subject` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`fl_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

Redux 1.5.0+ creates this table automatically during fresh installation.
If you are upgrading from 1.4.x and the table is missing, run either the SQL
above or re-run `install.php` (it will skip existing tables and only create
missing ones).

### The dashboard shows a warning about install.php still being present

`install.php` auto-deletes itself on success (Redux 1.5.0+), but deletion can
fail if the file is not writable by the web server process. Delete it manually
via FTP or your hosting file manager. The warning will disappear on the next
login once the file is gone.

### `div#fanupdate` and its content break out of their container

This was a bug in `show-cat.php` present in Redux 1.5.1 and earlier. The guard
that checks for a valid `$listingid` ran *after* the opening `<div id="fanupdate">`
had already been written to the page. If `$listingid` was absent or non-numeric,
the script exited with the `<div>` unclosed, and the browser treated the rest of
the host page as children of `#fanupdate`.

Fixed in Redux 1.5.2 — the guard now runs before any HTML is emitted. Upgrade
to 1.5.2 and replace `show-cat.php`.

### Some posts are not visible on the public blog

This was a timezone bug present in Redux 1.5.0 and earlier. The post visibility
query used `DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL X HOUR)` with the
`_server_tz_offset` option to determine which posts had passed their scheduled
time. On servers where the MySQL timezone differed from UTC, this calculation
could make recently-added posts appear future-dated and hide them.

Fixed in Redux 1.5.1 — all visibility queries now use `UTC_TIMESTAMP()`
directly, which is always correct regardless of server timezone. Upgrade to
1.5.1 and run `install.php` to remove the obsolete `_server_tz_offset` option.

If you cannot upgrade immediately, go to **Admin → Options** and set
`_server_tz_offset` to `0`.

### Smilies are not displaying after upgrading to Redux 1.7.0

The smiley image directory moved from `img/` to `assets/images/smilies/` in
Redux 1.7.0. Make sure you have moved your smiley image files to the new
location. The `gravatar_default` URL stored in the database also needs updating
— go to **Admin → Options** and change the Gravatar default image URL from
`.../img/gravatar.png` to `.../assets/images/gravatar.png`.

---

## License

GNU General Public License v3 or later.  
See <https://www.gnu.org/licenses/gpl-3.0.html> for details.

Original script by Jenny Ferenc — <http://prism-perfect.net/fanupdate>  
PHP 7 port by Ekaterina — <https://scripts.robotess.net>  
PHP 8.2 Redux by Ariane
