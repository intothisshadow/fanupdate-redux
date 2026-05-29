# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Redux 1.1.1] — May 29, 2026 — Installer Fixes

Post-release patch addressing issues found during live installation testing.

### Fixed

- **`install.php` blank white page** — `ini_set('display_errors', '1')` and
  `error_reporting(E_ALL)` added at the very top of `install.php` so fatal
  errors are never swallowed silently by the server's `php.ini` settings
- **`install.php` missing form fields** — the `$setting` array comparison used
  loose `==` (`strpos(...) == 0`) which evaluates `false == 0` as `true` in
  PHP, causing every visible field to be rendered as `<input type="hidden">`;
  changed to strict `=== 0`
- **`install.php` full `$setting` array restored** — the complete set of 29
  installer options (`$setting[0]` through `$setting[28]`) is now present,
  matching the original Robotess Fork source; previously only a stub array was
  included
- **`mysqli_sql_exception` crash on fresh install** — PHP 8.0 changed mysqli's
  default error mode to throw `mysqli_sql_exception` instead of returning
  `false`; the `@` error-suppression operator does not catch exceptions, so
  version-detection queries against non-existent tables caused an uncaught fatal
  error; all version-detection queries are now wrapped in `try/catch` blocks
- **`SqlConnection::Execute()` exception safety** — `Execute()` now temporarily
  sets `mysqli_report(MYSQLI_REPORT_OFF)` around the query and restores the
  previous report mode afterward; passing `$err_msg = null` now reliably returns
  `false` on failure without throwing regardless of the server's global mysqli
  error mode
- **`FanUpdate::addOptFromDb()` warning on fresh install** — both `Execute()`
  calls inside `addOptFromDb()` now pass `null` as `$err_msg`, so a missing
  options or smilies table (expected on a fresh install) is handled silently
  rather than triggering a visible WARNING on the page
- **Installer warning appearing above page header** — `$fu->getHeader()` was
  called before the version-detection block ran; any triggered warning therefore
  appeared above the HTML layout; call order corrected so the header renders
  only after all version-detection queries have completed
- **`functions.php` error handler** — `myErrorHandler()` now silently discards
  warnings whose message contains `"doesn't exist"` or `"does not exist"`;
  this is a defensive backstop for table-not-found warnings that may originate
  from older class files during the install sequence

### Added

- **Admin credentials collected during install** — `install.php` now presents
  `admin_username`, `admin_password`, and `admin_password (confirm)` fields
  at the bottom of the fresh-install form; on submission the values are
  validated (non-empty, passwords match) and written directly into
  `blog-config.php` via `preg_replace`; if `blog-config.php` is not writable
  a clear error message is shown with manual instructions
- **Password confirmation validation** — installer rejects the form and
  re-displays errors immediately if the two password fields do not match,
  before any database writes occur

---

## [Redux 1.1.0] — May 28, 2026 — PHP 8.2+ Refactor

This release brings full PHP 8.2+ compatibility across the entire codebase and
includes a security audit pass.

### Changed

- `declare(strict_types=1)` added to all PHP class and function files
- All class properties are now explicitly declared with types — PHP 8.2 deprecated
  the creation of "dynamic properties" (properties assigned but never declared):
  - `FanUpdate`: `$_cfg`, `$_clean_self`, `$db`, `$errors`, `$success`,
    `$smilies`, `$smiley_imgs`, `$observers` all now carry explicit types
  - `SqlConnection`: `$_Cnn` changed from `false` to `?mysqli`; `$_Res` to
    `mysqli_result|bool`; pagination state vars (`$max_results`, `$page`,
    `$total_results`, `$cur_row`) typed as `int`
  - `FanUpdate_Post` and `FanUpdate_Comment`: `$params` typed as `array`,
    `$fu` typed as `FanUpdate`
- All methods now carry strict PHP 8.x return types and typed parameter lists
- `SqlConnection` refactored to use OOP `mysqli` methods throughout (was using
  procedural `mysqli_*` functions)
- `SqlConnection::Connect()` now calls `set_charset('utf8mb4')` on every new
  connection — correct modern approach, removes the need for encoding conversion
  in most cases
- `SqlConnection` singleton `instance()` now uses named parameters rather than
  variadic `func_get_arg()` (PHP 4-era pattern)
- `FanUpdate_Comment::getGravatarUrl()` updated to HTTPS Gravatar endpoint
  (`https://www.gravatar.com/avatar/<hash>`) — the old `http://` URL and
  `avatar.php?gravatar_id=` path are deprecated by Gravatar

### Removed

- `get_magic_quotes_gpc()` call in `SqlConnection::Escape()` — the function was
  removed in PHP 8.0; `stripslashes()` companion call also removed
- Curly-brace string-index syntax (`$str{0}`) in `functions.php` — removed in
  PHP 8.0; replaced with square-bracket syntax (`$str[0]`)
- Redundant `mysqli_real_escape_string()` availability check (PHP 4 era)

### Fixed

- `antispambot()` in `functions.php` now uses `random_int()` instead of
  `srand()` + `rand()` — cryptographically secure, no manual seeding required
- `clean_input()` now handles nested arrays correctly and uses consistent
  `ENT_QUOTES, 'UTF-8'` parameters throughout
- `is_email()` and `is_url()` now delegate to `filter_var()` — more reliable
  than the previous hand-rolled regex implementations
- `FanUpdate::login()` inline JavaScript changed from `$('username').focus()`
  (prototype.js shorthand) to `document.getElementById('username').focus()`
  for correctness without dependency

### Security

- Full backdoor scan performed — no malicious code found
- `eval()`, `base64_decode()` on exec paths, `system/exec/shell_exec/passthru`,
  PRCE `/e` modifier, `assert()` on user input, remote file inclusion patterns,
  and hex/chr obfuscation were all checked and not present
- `mail()` header injection risk reviewed — the `From:` header is built from
  the admin-controlled `admin_email` option; recommended: validate with
  `filter_var($v, FILTER_VALIDATE_EMAIL)` before saving to the database
- **Note:** password storage still uses MD5 (`login()`) to preserve
  compatibility with existing installs. New installations should migrate to
  `password_hash()` / `password_verify()`.

### Added

- `fu_utf8_encode(string): string` and `fu_utf8_decode(string): string`
  helper functions in `functions.php` — safe replacements for the deprecated
  `utf8_encode()` / `utf8_decode()` (deprecated PHP 8.1, scheduled for removal
  in PHP 9), implemented via `mb_convert_encoding()`
- `SqlConnection::GetAll()` — convenience method returning all rows as an array
  of associative arrays, then freeing the result

---

## [Robotess Fork 1.0] — 2020 — PHP 7 Port

Community fork by Ekaterina (scripts.robotess.net) to restore compatibility
after the script stopped working on PHP 7 hosts.

### Changed

- Replaced all deprecated `mysql_*` functions with `mysqli_*` equivalents
- Replaced all deprecated `ereg_*` functions with `preg_*` equivalents
- Null-coalescing operator (`??`) used where appropriate
- Minor code modernisation throughout

---

## [2.2.1] — 2008-06-08 — Jenny Ferenc

### Fixed

- Auto-headings disabled inside comment bodies

---

## [2.2.1b2] — 2008-06-03

### Fixed

- Comment date bug for MySQL < 4.1
- Non-AJAX commenting option added

---

## [2.2.1b] — 2008-06-02

### Fixed

- Smiley `:/` no longer interferes with URLs
- `points_pending_threshold` zero-value bug

---

## [2.2] — 2008-06-01

### Added

- Post scheduling: post-dated entries are not displayed until their actual date
- New post date synced to user's local clock via JavaScript
- Time zone offset setting so displayed dates match the admin's time zone
- Markdown parsing for headings and lists in post bodies

---

## [2.2b3] — 2008-03-19

### Added

- "Delete all spam" button on the spam comments tab

---

## [2.2b2] — 2008-03-18

### Added

- Additional spam-scoring checks:
  - `-2` points if commenter IP matches hostname
  - `-1` point for providing a URL (treated as a link)
- Comment notification email reformatted for better scannability

### Fixed

- Update check frequency reduced to once per week
- Spam link count now runs on raw (unstripped) comment text; counts bare `www.`
  domains
- Installer bugs for fresh installations
- Empty category array error in `blog.php`

---

## [2.2b] — 2008-03-16

### Added

- Points-based spam blocking for comments (idea from Jonathan Snook)
- Basic podcasting / RSS enclosure support for linked iTunes-compatible media
  files (mp3, mov, pdf, etc.)
- Keyboard accesskey support throughout the admin panel:
  `n` new, `s` save, `p` preview, `x` delete, `q` search
- New and improved admin dashboard with statistics (frequent commenters,
  popular posts)
- Dynamic category adding on the entry add/edit page
- WYSIWYG toolbar controls

### Changed

- Text-parsing functions updated to latest WordPress equivalents
  (`wpautop`, `make_clickable`, `wptexturize`, etc.)
- Many under-the-hood refactors to ease maintenance

---

## [2.1.1] — 2007-10-30

### Fixed

- `show-blog.php`: duplicate `ORDER BY added DESC` removed from month archives
- `class/FanUpdate.php` line 394: missing `echo`
- `process.php`: headers-already-sent / session conflict

### Changed

- Improved and more efficient pagination
- Categories column added to admin entries list

---

## [2.1] — 2007-08-25

### Added

- Smilies system with admin panel management
- Improved JavaScript functionality

### Changed

- Full reorganisation into subdirectories (`class/`, `css/`, `docs/`, `img/`,
  `js/`)
- Improved pagination

### Fixed

- `comment.php`: comments could not be edited
- `show-cat.php`: RSS link used `id=` instead of `c=`

---

## [2.0.5] — 2007-06-30

### Fixed

- `show-cat.php`: added `is_array($cat)` check to prevent invalid argument
  warnings

---

## [2.0.4] — 2007-06-27

### Fixed

- `functions.php`: mis-named `getDateRFC()` function

---

## [2.0.3] — 2007-06-26

### Fixed

- `rss.php`, `rss-comments.php`, `functions.php`: `pubDate` format corrected
- `install.php`: only unlink files that actually exist
- `process.php`: auto-prepend `http://` to URL field if missing

---

## [2.0.2] — 2007-06-24

### Fixed

- `rss.php`: typo causing problems for single-category feeds
- `functions.php`: default Gravatar URL

---

## [2.0.1] — 2007-06-23

### Fixed

- `get-code.php`, `options.php`: renamed links from `add-fl.php` to
  `category.php`
- `functions.php`, `blog.php`, `rss.php`: improved logic for determining entry
  URL

### Added

- `fanupdate.png`, `gravatar.png` (replaced GIF versions)

---

## [2.0] — 2007-06-20

Initial public release of the 2.x generation.

### Added

- Full rewrite; class-based architecture
- Gravatar support
- Comment spam blacklist
- CAPTCHA support (requires GD + FreeType)
- Configurable templates for entries, comments, comment form, and footer
- RSS feed (`rss.php`) and RSS comments feed (`rss-comments.php`)
- Archive pages (monthly and by category)
- Category management
- Search
- Collective script integration: Enthusiast 3, Flinx Collective, Fan Admin
- Abstract / word-count-limited post summaries
- Standalone blog mode (no collective script required)

---

## [1.x] — 2005–2007

Original versions by Jenny Ferenc. Upgrade scripts for 1.1 and earlier are no
longer maintained.
