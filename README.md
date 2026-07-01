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
- **Image uploads** — upload JPEG, PNG, GIF, or WebP images directly from the
  post editor toolbar. Thumbnails are generated automatically (GD); clicking a
  thumbnail on the public blog opens the full-size image in a built-in lightbox.
  Uploads live in `uploads/images/YYYY/MM/` with PHP execution blocked via
  `.htaccess`.
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
4. If your `admin_password` in `blog-config.php` is still an MD5 hash (32 hex
   characters), log in once — the hash upgrades to bcrypt automatically as long
   as `blog-config.php` is writable.

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

Use **Admin → Account** in the admin panel. The page has three sections:

- **Change Email** — update the email address used for comment notifications.
  Validated with `filter_var()` before saving.
- **Change Username** — enter a new username and confirm it. No current value
  required since you are already authenticated.
- **Change Password** — enter your current password, then the new password
  (minimum 8 characters) and a confirmation. The new bcrypt hash is written to
  `blog-config.php` automatically and your session stays active.

If `blog-config.php` is not writable, the username and password forms display
the ready-to-paste value so you can update the file manually in a text editor.

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

## Image uploads

The post editor toolbar contains an image icon button. Click it to pick a local
JPEG, PNG, GIF, or WebP file. The file is uploaded immediately to
`uploads/images/YYYY/MM/` and a constrained-fit thumbnail is generated into the
`thumbs/` subdirectory at the same path. The snippet

```html
<a href="FULL_URL" class="fu-lightbox"><img src="THUMB_URL" alt="" /></a>
```

is inserted at the cursor position in the post body. On the public blog, clicking
any thumbnail opens the full-size image in an overlay lightbox. Pressing Escape
or clicking outside the image closes it.

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

FanUpdate can use your existing collective management script's table as its
category source. Supported scripts:

| Script | Config value |
|--------|-------------|
| Enthusiast 3 | `'e'` |
| Fanatically       |              |
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

## Theming

The admin panel ships with two stylesheets in `assets/css/`:

| File | Description |
|------|-------------|
| `style.css` | Default — Dusty Rose palette (light base, mauve accent) |
| `style-night.css` | Night variant — Midnight palette (dark navy base, indigo accent) |

To switch themes, change the `<link>` tag in `admin/header.php`:

```php
// Default (Dusty Rose)
<link rel="stylesheet" href="<?= FU_ASSETS ?>css/style.css">

// Night (Midnight)
<link rel="stylesheet" href="<?= FU_ASSETS ?>css/style-night.css">
```

`style.css` also responds to the browser's `prefers-color-scheme: dark` media
query with a softer dark variant of the Dusty Rose palette. `style-night.css`
is always dark and ignores the media query — use it when you want a
consistently dark interface regardless of the visitor's OS setting.

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
├── process.php              # Comment submission handler
├── show-blog.php            # Public blog display (all categories)
├── show-cat.php             # Public blog display (single category)
├── rss.php                  # RSS feed
├── rss-comments.php         # RSS comments feed
├── captcha.php              # CAPTCHA image generator
├── admin/
│   ├── index.php            # Admin dashboard
│   ├── blog.php             # Admin: entries list
│   ├── upload.php           # Admin: image upload endpoint (AJAX)
│   ├── category.php         # Admin: categories
│   ├── comment.php          # Admin: comments
│   ├── options.php          # Admin: main options
│   ├── templates.php        # Admin: template editor
│   ├── smilies.php          # Admin: smilies
│   ├── blacklist.php        # Admin: spam blacklist
│   ├── account.php          # Admin: change email, username, and password
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
│   └── SqlConnection.php    # Database abstraction (singleton)
├── data/
│   ├── login_attempts/      # Login rate limiter lock files (auto-created, not web-accessible)
│   └── comment_rate/        # Comment rate limiter lock files (auto-created, not web-accessible)
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
│   │   ├── fanupdate-admin.js        # Admin panel init (confirm-delete, WYSIWYG, image upload)
│   │   ├── fanupdate-comment-admin.js# Comment approve AJAX
│   │   ├── fanupdate-post-admin.js   # Entry list "select all" checkbox wiring
│   │   ├── fanupdate-comment-submit.js# Front-end comment submission AJAX
│   │   └── select-all.js             # Checkbox select-all, dynamic categories, timestamp
│   ├── fonts/
│   │   └── VeraSe.ttf       # CAPTCHA font (Bitstream Vera Serif)
│   └── images/
│       ├── fanupdate.png    # Admin panel logo (RSS feed icon)
│       ├── gravatar.png     # Default Gravatar placeholder
│       └── smilies/         # Smiley images (upload your own here)
│           └── *.gif / *.png
└── docs/
    ├── CHANGELOG.md         # This project's changelog
    ├── TROUBLESHOOTING.md   # Common issues and fixes
    ├── DATABASE.md          # Database layer developer docs (PDO API, query patterns)
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

## License

GNU General Public License v3 or later.  
See <https://www.gnu.org/licenses/gpl-3.0.html> for details.

Original script by Jenny Ferenc — <http://prism-perfect.net/fanupdate>  
PHP 7 port by Ekaterina — <https://scripts.robotess.net>  
PHP 8.2 Redux by Ariane
