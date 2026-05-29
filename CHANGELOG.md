# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


---

## [Redux 1.5.1] — May 29, 2026 — Timezone Fix & Options Cleanup

### Fixed

- **Posts hidden by timezone offset miscalculation** — `show-blog.php`,
  `show-cat.php`, and `rss.php` all used
  `DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL -X HOUR)` to filter post visibility,
  where `X` was the `_server_tz_offset` option. On servers where the MySQL
  timezone differed from UTC, this calculation could make recently-added posts
  appear future-dated and hide them from the public blog. All three files now
  use `UTC_TIMESTAMP()` directly — no offset arithmetic, no drift, correct
  behaviour regardless of server timezone configuration.
- **Post and comment dates displayed in wrong timezone** — `getDateFormatted()`
  in both `FanUpdate_Post` and `FanUpdate_Comment` was applying
  `_server_tz_offset` to shift display dates, which was the server's own UTC
  offset rather than the admin's local timezone. Replaced with `timezone_offset`
  (the admin-configured hours-from-UTC value, e.g. `3` for UTC+3), so displayed
  dates now correctly reflect the admin's local time.

### Changed

- **`_server_tz_offset` option removed** — no longer used anywhere in the
  codebase. Existing installs: delete it via
  `DELETE FROM blog_options WHERE optkey = '_server_tz_offset'` in phpMyAdmin,
  or simply run `install.php` which now removes it automatically on upgrade.
- `install.php` — `$setting[27]` (`_server_tz_offset`) removed from the
  settings array so fresh installs never create it
- `install.php` — upgrade path now issues a `DELETE` to remove
  `_server_tz_offset` from any existing database automatically
- `install.php` — the legacy `UPGRADE FROM < 2.2b3` time-conversion block
  previously referenced `$setting[27]['optvalue']`; now reads the value
  directly from the database (for anyone upgrading from a pre-2.2b3 install
  where the conversion has not yet run)

### Files changed

- `show-blog.php`
- `show-cat.php`
- `rss.php`
- `class/FanUpdate_Post.php`
- `class/FanUpdate_Comment.php`
- `install.php`

---

## [Redux 1.5.0] — May 29, 2026 — Post-Install Hardening & Bug Fixes

### Security

- **`install.php` auto-deletes itself on successful install** — after all tables
  are created and options written without errors, `install.php` calls
  `unlink(__FILE__)` on itself; success or failure is reported on the completion
  page so the admin knows whether manual deletion is still required
- **Dashboard warning if `install.php` still exists** — `index.php` checks for
  the presence of `install.php` on every admin login and displays a prominent
  error banner with a direct link until the file is removed; provides a safety
  net when auto-delete fails due to file permissions

### Fixed

- **`blog_category` table not created on fresh standalone install** — `install.php`
  now creates the `collective_table` (`blog_category` by default) when
  `collective_script` is not set; previously the table was assumed to be
  provided by an external collective script and was silently never created,
  causing a fatal `#1146 Table doesn't exist` error the first time categories
  were used
- **`blog.php` undefined array key warnings on new post** — `$clean['id']`,
  `$clean['cat']`, and `$clean['new_cat']` are now defaulted immediately after
  `clean_input($_POST)`; when posting a new entry the browser does not send an
  `id` field, and when no category checkboxes are checked `cat[]` is absent
  entirely; PHP 8.2 promotes these silent notices to visible warnings that
  disrupted the form flow
- **`category.php` — nothing saved, form appeared to submit silently** —
  `$clean['cat_name']`, `$clean['cat_id']`, `$clean['date_format']`,
  `$clean['gravatar_default']`, `$clean['gravatar_size']`,
  `$clean['gravatar_rating']`, `$clean['entry_template']`, and
  `$clean['comment_template']` are now defaulted to `''` immediately after
  `clean_input()`; the undefined-key warnings were emitted before `getHeader()`
  ran, corrupting the output buffer and preventing the save query from executing
- **`FanUpdate_Post.php` — fatal error on all post-rendering pages** — the stored
  class was a stub missing every rendering method; `getTitle()`, `getBody()`,
  `getBodyFormatted()`, `getAbstract()`, `getDate()`, `getDateFormatted()`,
  `getYear()`, `isPublic()`, `commentsOn()`, `allowComments()`, `getUrl()`,
  `getCommentsUrl()`, `getCommentsFeedUrl()`, `getCatFromDb()`, `addCategory()`,
  `getCategoryArray()`, `getCategoryString()`, `getCategoryLinks()`, and
  `printPost()` are now fully implemented; recovered from all call sites across
  `blog.php`, `rss.php`, `show-blog.php`, `show-cat.php`, and `index.php`
- **`FanUpdate_Comment.php` — fatal error on dashboard and all comment pages** —
  the stored class was similarly stubbed; `getName()`, `getEmail()`, `getUrl()`,
  `getBody()`, `getBodyFormatted()`, `getAbstract()`, `getDate()`,
  `getDateFormatted()`, `isApproved()`, `getLinkUrl()`, `getGravatarUrl()`,
  `getGravatar()`, `getCommenterLink()`, and `printComment()` are now fully
  implemented; recovered from call sites in `index.php`, `comment.php`,
  `rss-comments.php`, and `process.php`
- **`FanUpdate_Post.php` — `Cannot redeclare` fatal on PHP** — `getDateForMatted()`
  (historical typo alias) and `getURL()` (capitalisation alias) were declared as
  separate methods; PHP method names are case-insensitive so both were treated as
  redeclarations of `getDateFormatted()` and `getUrl()`; aliases removed — PHP's
  own case-insensitive dispatch handles callers using either spelling natively

### Changed

- `install.php` — standalone `blog_category` table creation block added inside
  the `$version_installed == 0` branch, conditional on `!$fu->getOpt('collective_script')`;
  uses `$coltable['n']['id']` and `$coltable['n']['subject']` column names
  (`fl_id`, `fl_subject`) to match the values `FanUpdate` reads back via `col_id`
  and `col_subj`
- `install.php` — pre-install duplicate-table check already correctly skips
  `collective_table` only when `collective_script` is set; no change needed
- `install.php` — completion message updated: "Now DELETE this file" replaced
  with auto-delete result; manual deletion instruction shown only if `unlink()`
  failed
- `index.php` — `install.php` existence check added immediately after
  `$fu->checkUpdates()` call

---

## [Redux 1.4.0] — May 29, 2026 — Cookie Security

### Security

- **`Secure` flag** added to `fu_username` and `fu_token` cookies — cookies are
  now never transmitted over plain HTTP; requires the site to run on HTTPS
- **`SameSite=Strict`** added to `fu_username` and `fu_token` cookies — cookies
  are not sent on any cross-site request, closing a CSRF vector against the
  admin panel
- All `setcookie()` calls for auth cookies converted from the positional-argument
  form to the **options-array form** (PHP 7.3+) — the only clean way to set
  `SameSite` without writing a raw `Set-Cookie` header manually
- `FanUpdate::setAuthCookie()` private helper added — centralises all auth
  cookie attributes so a single change propagates everywhere; used by all six
  cookie-issuing points in `login()`
- `account.php` — cookie reissue calls updated to match; uses an `$authCookieOpts`
  closure since `account.php` issues cookies outside the class
- `process.php` commenter cookies (`fanuname`, `fanuemail`, `fanuurl`) updated:
  `Secure=true` added; `HttpOnly` remains `false` (JS-readable for form
  pre-fill); `SameSite=Lax` (not Strict — Strict would clear cookies on
  cross-page navigation, breaking the pre-fill feature)

---

## [Redux 1.3.0] — May 29, 2026 — Rate Limiting & Account Page

### Security

- **Login rate limiting** — `class/LoginRateLimiter.php` added; tracks failed
  login attempts per IP in flat JSON files stored in `data/login_attempts/`
  (not web-accessible); after 5 failures the IP is locked out for 15 minutes;
  each subsequent lockout doubles the window (exponential backoff), capped at
  24 hours; a successful login resets the counter immediately
- `REMOTE_ADDR` used as the rate-limit key — `X-Forwarded-For` is never trusted
  because it is trivially spoofable
- Lock files are stored in `data/login_attempts/` with a `.htaccess` deny rule
  auto-created on first use; directory is created automatically if absent
- Stale lock files pruned automatically (at most once per hour) so the directory
  does not grow indefinitely

### Added

- **`account.php`** — combined admin credentials page replacing the separate
  `change-password.php` and `change-username.php` files; hosts both the
  "Change Username" and "Change Password" forms on a single page; username
  change requires no current-value verification (admin is already authenticated);
  password change still requires current password via `password_verify()`; both
  reissue a fresh HMAC session token immediately so the admin stays logged in
- **Account link** added to main nav in `footer.php`, positioned before Options
  (more important than Smilies or Credits)
- `class/LoginRateLimiter.php` — standalone class, no DB or APCu dependency;
  self-healing (stale files pruned automatically); shared-host friendly

### Changed

- `change-password.php` and `change-username.php` retired — superseded by
  `account.php`
- Main nav order updated: Dashboard · Entries · Comments · Categories ·
  **Account** · Options · Credits · Logout
- `footer.php` — Password link replaced with Account link

### Fixed

- Username validation in `account.php` restricts to `[A-Za-z0-9_]` to prevent
  single-quote injection into the `blog-config.php` regex replacement

---

## [Redux 1.2.2] — May 29, 2026 — GPLv3 Licence Upgrade

### Changed

- **Licence upgraded from GPLv2-or-later to GPLv3-or-later** — all original
  FanUpdate files carry a "version 2 or later" clause which explicitly permits
  this upgrade; third-party class files (`SqlConnection`, `FeedWriter`,
  `FeedItem`, `FileHandler`) retain their original authors' licence
  declarations and are unaffected
- All owned PHP file headers updated: "version 2 of the License" →
  "version 3 of the License"; bare `http://www.gnu.org/licenses/` URL →
  `https://www.gnu.org/licenses/gpl-3.0.html`
- `version.php` — added `FU_LICENCE` and `FU_LICENCE_URL` constants;
  added `FU_URL_REDUX` constant for the Redux release URL; updated
  `FU_RELEASE_NAME` to `'gplv3'`
- `credits.php` — licence section now reads from `FU_LICENCE` / `FU_LICENCE_URL`
  constants; note added clarifying that third-party class files are unaffected
- `README.md` and `CHANGELOG.md` — licence footer updated to GPLv3

### Added

- **`upgrade-licence-gplv3.sh`** — idempotent shell script that performs the
  header substitutions across all owned `.php` files in one shot; safe to
  re-run; skips files already on GPLv3 and files missing from disk;
  explicitly excludes third-party class files

---

## [Redux 1.2.1] — May 29, 2026 — Version System & Credits

### Added

- **`version.php`** — single source of truth for all version and release
  metadata; exports PHP constants `FU_VERSION`, `FU_DB_VERSION`,
  `FU_RELEASE_DATE`, `FU_RELEASE_NAME`, `FU_URL_ORIGINAL`, `FU_URL_ROBOTESS`,
  `FU_CONTRIBUTORS`, and `FU_LIBRARIES`; to release a new version, edit only
  this file
- **`credits.php`** — admin panel credits page showing version info, full
  contributor history (Jenny Ferenc, Ekaterina, Redux contributor), and
  third-party library attributions sourced from the original `readme.txt`
- **Credits nav link** added to `footer.php` between Password and Logout

### Changed

- `functions.php` — `require_once('version.php')` added at the top so all
  constants are available to every admin page and class file
- `class/FanUpdate.php` — hardcoded version/URL strings in `$config` replaced
  with `FU_VERSION`, `FU_URL_ORIGINAL`, `FU_URL_ROBOTESS` constants
- `install.php` — hardcoded `$installer_version` string replaced with
  `FU_DB_VERSION` constant

---

## [Redux 1.2.0] — May 29, 2026 — bcrypt Password Security

### Security

- **MD5 → bcrypt password storage** — `FanUpdate::login()` now uses
  `password_verify($password, $storedHash)` instead of MD5 comparison;
  passwords are stored as bcrypt hashes via `password_hash(PASSWORD_BCRYPT)`
- **MD5 legacy upgrade path** — if `admin_password` in `blog-config.php` still
  contains a 32-char MD5 hex string, login is permitted once and the hash is
  silently upgraded to bcrypt in `blog-config.php` automatically; requires the
  file to be writable; the fallback never fires again after the first login
- **HMAC session token replaces MD5 cookie** — `fu_password` cookie (MD5 hash
  of password) replaced by `fu_token`, a `hash_hmac('sha256', $username,
  $storedBcryptHash)` value; stateless, no session table required; token
  auto-invalidates the moment `admin_password` changes in `blog-config.php`
- **`HttpOnly` flag** added to both `fu_username` and `fu_token` cookies
- **`addslashes()` → `addcslashes(..., '\\$')` bug fix** — bcrypt hashes
  contain literal `$` characters which `preg_replace()` treats as backreference
  markers; using `addslashes()` silently corrupted `blog-config.php` on any
  config rewrite; fixed in `install.php`, `FanUpdate::login()` (legacy upgrade
  path), and `change-password.php`

### Added

- **`change-password.php`** — new admin panel page for changing the admin
  password without manually editing `blog-config.php`; requires current
  password (verified with `password_verify()`), new password (minimum 8
  characters), and confirmation; rewrites `blog-config.php` with a fresh bcrypt
  hash and immediately reissues a new HMAC session token so the admin stays
  logged in; if `blog-config.php` is not writable, the error message includes
  the ready-to-paste bcrypt hash
- **Password nav link** added to `footer.php`
- **`blog-config.sample.php`** updated — `md5('pass')` replaced with
  `password_hash('changeme', PASSWORD_BCRYPT)`; comment block added explaining
  how to generate a hash manually from the command line

### Changed

- `install.php` credential-writing block — now writes `password_hash(PASSWORD_BCRYPT)`
  instead of `md5()`; `preg_replace` pattern broadened to match all three
  possible existing formats (`md5('...')` wrapper, bare 32-char MD5 hex,
  already-bcrypt `$2y$...`) so the installer is safe to re-run; if
  `blog-config.php` is not writable, error message includes the bcrypt hash
  ready to paste manually

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
  PHP 8.0; replaced with square-bracket syntax (`$str[0]`)`
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
