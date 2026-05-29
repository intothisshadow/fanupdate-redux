# FanUpdate Redux

A lightweight PHP blogging script designed to keep an updates log for an entire
collective of fan websites. Works equally well as a standalone blog.

Originally written by **Jenny Ferenc** in 2005. Ported to PHP 7 by **Ekaterina**
(scripts.robotess.net) in 2020. 

This Redux release updates the codebase to **PHP 8.2+** (current patch: Redux 1.4.0).

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

Once installation completes successfully, **delete `install.php` from your
server.** It is not needed again and should not be left accessible.

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

Upload image files to the `img/` directory inside your FanUpdate folder.
They will appear automatically in the **Admin → Smilies** panel, where you can
map each image to a text shortcut (e.g. `:)` → `smile.gif`).

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
├── blog-config.php          # Your configuration (copy from .sample)
├── blog-config.sample.php   # Configuration template
├── version.php              # Single source of truth for version metadata
├── functions.php            # Shared helper functions
├── index.php                # Admin dashboard
├── blog.php                 # Admin: entries list
├── category.php             # Admin: categories
├── comment.php              # Admin: comments
├── options.php              # Admin: main options
├── templates.php            # Admin: template editor
├── smilies.php              # Admin: smilies
├── blacklist.php            # Admin: spam blacklist
├── account.php              # Admin: change username and password
├── credits.php              # Admin: version info and credits
├── install.php              # Installer (DELETE after use)
├── process.php              # Comment submission handler
├── show-blog.php            # Public blog display (all categories)
├── show-cat.php             # Public blog display (single category)
├── rss.php                  # RSS feed
├── rss-comments.php         # RSS comments feed
├── captcha.php              # CAPTCHA image generator
├── header.php               # Admin panel header
├── footer.php               # Admin panel footer
├── get-code.php             # Display snippet generator
├── class/
│   ├── FanUpdate.php        # Core application class
│   ├── FanUpdate_Post.php   # Post model
│   ├── FanUpdate_Comment.php# Comment model
│   ├── LoginRateLimiter.php # IP-based login rate limiter
│   └── SqlConnection.php    # Database abstraction (singleton)
├── data/
│   └── login_attempts/      # Rate limiter lock files (auto-created, not web-accessible)
├── css/
│   └── style.css            # Admin panel styles
├── js/
│   └── fanupdate.js         # Admin panel JavaScript
├── img/
│   ├── gravatar.png         # Default Gravatar placeholder
│   └── *.gif / *.png        # Smiley images
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

---

## License

GNU General Public License v3 or later.  
See <https://www.gnu.org/licenses/gpl-3.0.html> for details.

Original script by Jenny Ferenc — <http://prism-perfect.net/fanupdate>  
PHP 7 port by Ekaterina — <https://scripts.robotess.net>  
PHP 8.2 Redux by Ariane
