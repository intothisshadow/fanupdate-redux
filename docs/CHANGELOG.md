# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


---

## [Unreleased]

### Fixed

- **`show-blog.php` / `show-cat.php` — loose comparisons replaced with strict** —
  `$_GET['view'] == 'archive'` → `===`; `$year != $lastyear` → `!==`;
  `$value != ''` (category options loop) → `!== ''`. Per CLAUDE.md: prefer strict
  comparison throughout.

- **`show-blog.php` / `show-cat.php` — search query output not HTML-escaped** —
  `echo '<p>Search results for <strong>' . $q . '</strong></p>'` used `$q` directly.
  `clean_input()` strips tags but leaves `&`, `"`, and other HTML-significant characters
  unescaped. Wrapped with `htmlspecialchars($q, ENT_QUOTES, 'UTF-8')`.

- **`admin/category.php` — HTML stripped from entry/comment templates on save** —
  `clean_input($_POST['entry_template'])` and `clean_input($_POST['comment_template'])`
  were called without `allowtags=true`. `clean_input()` calls `strip_tags()` by default,
  silently stripping every `<h2>`, `<p>`, `<a>`, `<strong>` etc. from templates on each
  save. Added `true` as second argument to preserve HTML in template fields.

- **`admin/templates.php` — HTML stripped from template values on save** —
  Same `clean_input()` / `strip_tags()` bug. Template option values contain HTML;
  `clean_input(..., true)` now used.

- **`admin/comment.php` — HTML stripped from comment body when admin edits a comment** —
  `clean_input($_POST['comment'])` stripped all HTML from the comment body. Since
  `process.php` converts BBCode to HTML (`[B]` → `<strong>`, `[URL]` → `<a>`, etc.),
  stored comments can contain HTML markup; editing and re-saving corrupted that markup.
  Fixed: `clean_input($_POST['comment'], true)`.

- **`admin/index.php` — `admin_email` unescaped in Top commenters SQL query** —
  `$fu->getOpt('admin_email')` was interpolated directly into a SQL WHERE clause without
  `$fu->db->Escape()`. Although the value comes from the admin's own options, it should
  always be escaped when placed in SQL. Fixed.

- **`process.php` — loose comparisons replaced with strict** —
  `$ip_hostname == $_SERVER['REMOTE_ADDR']` → `===`;
  `$num_links == 0` → `=== 0`;
  `$clean['approved'] == 0` → `=== 0`.
  `getOpt('points_approval_threshold', true)` now cast to `(int)` before the `<`
  comparison so PHP does not silently coerce a string.

- **`admin/get-code.php` — `$subject` and `install_path` not HTML-escaped in textarea** —
  Values echoed inside the `<textarea>` code snippet were not passed through
  `htmlspecialchars()`. A subject or install path containing `&`, `<`, or `>` would
  produce malformed HTML. Both now wrapped in `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

- **`class/SqlConnection.php` — `@` error suppressors removed from `Connect()`** —
  `@mysqli_connect()`, `@mysqli_select_db()`, and `@mysqli_set_charset()` used the `@`
  error-control operator, violating CLAUDE.md ("Never suppress errors with @ operators").
  Replaced: `mysqli_driver::report_mode` is set to `MYSQLI_REPORT_OFF` before each call
  and restored afterward — the same pattern already used in `Execute()`. Return values are
  checked explicitly and `trigger_error()` called on failure as before.

- **`admin/upload.php` — `install_path` option absence causes upload failure** —
  `rtrim((string) $fu->getOpt('install_path'), '/')` cast `false` to the empty string
  when the option was not yet saved in the database (e.g. before first install completes).
  An empty install path caused `mkdir()` to attempt creating
  `//uploads/images/YYYY/MM/` relative to the web root, which silently fails on most
  hosts. Added a fallback to `dirname(__DIR__)` (the fanupdate root resolved from
  upload.php's own location) so the feature works even when the option is absent.

### Files changed

- `show-blog.php`
- `show-cat.php`
- `admin/category.php`
- `admin/templates.php`
- `admin/comment.php`
- `admin/index.php`
- `process.php`
- `admin/get-code.php`
- `class/SqlConnection.php`
- `admin/upload.php`

---

## [Unreleased] (previous batch)

- **`admin/category.php` — fatal `Error: Call to undefined method SqlConnection::InsertID()`** —
  When adding a new category in standalone mode, the code called `$fu->db->InsertID()` to
  retrieve the new category's auto-increment ID. This method does not exist on `SqlConnection`;
  the correct method is `GetLastSequence()`. Fixed.

- **`process.php` — blacklisted comments submitted despite error message** —
  `$pass_blacklist` was initialised to `true` and never set to `false`, even when a
  blacklisted word was found and `$fu->addErr()` was called (non-scoring mode). The `break`
  only exited the while loop; `$pass_blacklist` stayed `true` and comment processing
  continued. Fixed: `$pass_blacklist = false` added alongside `$fu->addErr()` so comments
  containing blacklisted words are correctly blocked.

- **`process.php` / `show-cat.php` — unnecessary reference assignment `=&` on singleton call** —
  `$fu = &FanUpdate::instance()` and `$fu =& FanUpdate::instance()` are unnecessary —
  PHP passes objects by handle, not value, so no reference is needed. In PHP 8, assigning
  by reference from a non-reference-returning method produces an `E_NOTICE`. Fixed:
  both changed to `$fu = FanUpdate::instance()`.

- **`show-cat.php` — wrong-case method calls `AddOpt()`, `getDateForMatted()`, `getURL()`** —
  `$fu->AddOpt()` (capital A) and `$post->getDateForMatted()` (capital M, wrong spelling)
  and `$post->getURL()` (all caps) were inconsistent with the project's camelCase naming
  standards. While PHP method calls are case-insensitive at runtime, these are naming
  violations. Fixed: corrected to `addOpt()`, `getDateFormatted()`, and `getUrl()`.

- **`process.php` — `AddOptFromDb()` wrong casing** — `$fu->AddOptFromDb()` corrected to
  `$fu->addOptFromDb()` per camelCase standard.

- **`process.php` — loose comparisons `!=` / `==` replaced with `!==` / `===`** —
  `$_POST['submit_comment'] != 'ajax'` and `$clean['remember_me'] == 'y'` used loose
  comparison. Both replaced with strict comparison per CLAUDE.md standards.

- **`rss.php` — loose `$c == 0` replaced with `$c === 0`** — the category ID is already
  cast to `(int)`, so strict comparison is appropriate.

- **`class/FanUpdate.php` — `@` error suppression in `checkUpdates()` removed** —
  `@$doc->load()` violated the "never suppress errors with @ operators" rule.
  Replaced with `libxml_use_internal_errors(true)` / `libxml_clear_errors()` so
  libxml warnings are swallowed cleanly without the error-control operator.

- **`class/FanUpdate.php` — inefficient `SELECT *` comment count in `printBlog()`** —
  The method fetched all approved comment rows with `SELECT *` and counted them in a PHP
  `while` loop. Replaced with a single `SELECT COUNT(*)` via `GetFirstCell()`, which
  is O(1) at the database level regardless of comment volume.

- **`blog-config.sample.php` — removed obsolete Flinx Collective and Fan Admin references** —
  Per TODO.md, all references to the deprecated `'f'` (Flinx Collective) and `'g'`
  (Fan Admin) collective script options have been removed from the sample config.
  The `$coltable['f']` and `$coltable['g']` entries and their associated comments
  are gone. Only `'e'` (Enthusiast) and `'n'` (standalone) remain.

### Files changed

- `admin/category.php`
- `process.php`
- `show-cat.php`
- `rss.php`
- `class/FanUpdate.php`
- `blog-config.sample.php`

---

- **`admin/credits.php` — footer credits link gave 404 for unauthenticated users** —
  `printCredits()` in `class/FanUpdate.php` emitted `<a href="credits.php">` as a
  relative URL. When the admin was accessed without an explicit `admin/` path segment
  via URL rewriting, the relative URL resolved to the document root and produced a 404.
  Additionally, `admin/credits.php` called `$fu->login()`, which forces a login redirect,
  making the credits link unusable for any visitor who wasn't already authenticated — even
  though credits are read-only public information.
  Fixed:
  - `printCredits()` now uses the absolute URL
    `rtrim($install_url, '/') . '/admin/credits.php'` so the link resolves correctly
    from any page on any site structure.
  - A new `isLoggedIn(): bool` public method was added to `FanUpdate` — a non-redirecting
    cookie check safe to call before any output.
  - `admin/credits.php` no longer calls `$fu->login()`. The admin navigation bar is
    shown only when `$fu->isLoggedIn()` returns `true`, so unauthenticated visitors
    see the credits page without being bounced to the login form.

- **`declare(strict_types=1)` added to all remaining PHP files** — the following files
  were missing the declaration and have been updated. Where `getOpt()` (which returns
  `mixed`) was passed directly to typed parameters, the necessary casts were added at the
  same time:

  - `process.php` — `@mail($to, ...)`: cast `$to` to `(string)($fu->getOpt('admin_email') ?: '')`
  - `rss.php` — see below for full list of fixes
  - `rss-comments.php` — see below
  - `show-blog.php` — `printBlog()` second arg cast to `(int)($main_limit ?? 5)`
  - `show-cat.php` — same `printBlog()` cast
  - `install.php` — `in_array($table, $fu->getOpt('tables'), true)` →
    `in_array($table, (array)$fu->getOpt('tables'), true)`
  - `class/FeedItem.php` — declaration only
  - `class/FeedWriter.php` — declaration only

- **`rss.php` / `rss-comments.php` — RSS feeds broken by undefined `FeedWriter` methods** —
  `$feed->setPubDate()` and `$feed->setBuildDate()` do not exist on `FeedWriter`; calling
  them produced fatal errors, silently breaking all RSS output. Fixed: replaced both with
  `$feed->setChannelElement('pubDate', date('r', ...))` and
  `$feed->setChannelElement('lastBuildDate', date('r'))`, which use the RFC-2822 date
  format the RSS spec requires.

- **`rss.php` / `rss-comments.php` — `addNamespace()` called with one argument** —
  `FeedWriter::addNamespace(string $prefix, string $uri)` requires two arguments; the
  calls for `'content'`, `'slash'`, and `'wfw'` omitted the URI and produced
  `ArgumentCountError`. Fixed: explicit namespace URIs added to every call site.

### Files changed

- `class/FanUpdate.php`
- `admin/credits.php`
- `process.php`
- `rss.php`
- `rss-comments.php`
- `show-blog.php`
- `show-cat.php`
- `install.php`
- `class/FeedItem.php`
- `class/FeedWriter.php`

---

## [Redux 2.0.1] — June 10, 2026

### Added

- **CSS styling for uploaded images** — `a.fu-lightbox` and its child `img` now have
  explicit rules in both `style.css` and `style-night.css`. The wrapper link gets
  `border-radius`, a theme-token border, box-shadow, and a smooth border/shadow
  transition on hover/focus. The `img` gets `display:block`, `max-width:100%`,
  `height:auto`, `cursor:zoom-in`, and an opacity fade on hover. All values use
  `--fu-*` design tokens, so both themes and the `prefers-color-scheme` dark variant
  inherit correct colours automatically.

- **Image alignment helper classes** — three utility classes for positioning images
  within post text, all added to both stylesheets:
  - `fu-img-left` — floats the image left; text wraps to its right
  - `fu-img-right` — floats the image right; text wraps to its left
  - `fu-img-center` — displays the image as a centred block

  Usage: add the class to the `<a class="fu-lightbox">` element in the post body,
  e.g. `<a href="…" class="fu-lightbox fu-img-right"><img src="…" alt="" /></a>`.

### Files changed

- `assets/css/style.css`
- `assets/css/style-night.css`

---

## [Redux 2.0.1] — June 6, 2026

### Fixed

- **`admin/blog.php` — new entries not appearing on front end (timezone mismatch)** — the new-entry date field was pre-filled with `date('Y-m-d H:i:s')` (PHP server local time). `show-blog.php` and `show-cat.php` filter with `WHERE added <= UTC_TIMESTAMP()`. On servers whose PHP timezone is ahead of UTC — Finland is UTC+3 in summer — a new entry written at 19:00 local time gets stored as `2026-06-06 19:00:00`, while `UTC_TIMESTAMP()` returns `2026-06-06 16:00:00`, making the entry appear to be 3 hours in the future and excluding it from every front-end query until the clocks catch up. Fixed: date default changed to `gmdate('Y-m-d H:i:s')` (UTC) in both the new-entry branch and the inline fallback in the date input, so all new entries are stored in UTC and immediately pass the `<= UTC_TIMESTAMP()` check. The `timezone_offset` option continues to handle display-time correction in `FanUpdate_Post::getDateFormatted()` unchanged. **To fix existing hidden entries:** edit each one in the admin and subtract your UTC offset from the date field (e.g. change `2026-06-06 19:47:09` → `2026-06-06 16:47:09` for UTC+3), then save.

- **`admin/blog.php` — HTML stripped from post body on save** — `clean_input($_POST['body'])` was called without `allowtags=true`, so `strip_tags()` silently removed every HTML tag from the body every time an entry was saved. Images inserted via the upload button (`<a class="fu-lightbox"><img …></a>`), bold/italic markup, headings, links, and blockquotes were all stripped on the first save. Fixed: body is now cleaned with `clean_input($_POST['body'], true)`. The body is still safely escaped via `$fu->db->Escape()` before insertion.

- **`fanupdate-admin.js` — uploaded image inserted at position 0 instead of cursor** — opening the OS file picker causes the textarea to lose focus, resetting `selectionStart` to 0 in most browsers. `fuInsertImage()` was reading `textarea.selectionStart` at response time, so images always landed at the start of the body. Fixed: cursor position saved into `_fuUploadCursorPos` in `fuImageButtonClick()` before the picker opens; `fuInsertImage()` uses that saved position.

### Files changed

- `admin/blog.php`
- `assets/js/fanupdate-admin.js`

---

## [Redux 2.0.1] — June 6, 2026

### Added

- **Image upload for admin** — new `admin/upload.php` POST endpoint accepts JPEG, PNG, GIF, and WebP files from the WYSIWYG toolbar. MIME type is validated from file bytes via `finfo` (not the browser-supplied Content-Type), filename is sanitised by `FileHandler::fixFilename()` and prefixed with `uniqid()`, and files are placed in a date-partitioned directory `uploads/images/YYYY/MM/`. A `thumbs/` subdirectory at the same level receives a GD-generated constrained-fit thumbnail (default 300×300, configurable via `upload_thumb_width` / `upload_thumb_height` options). A PHP-execution-blocking `.htaccess` is written to `uploads/` on first use.

- **"Insert image" WYSIWYG toolbar button** — an image icon button is added to the post-body toolbar in `admin/blog.php`. Clicking it opens a hidden `<input type="file">` picker. The selected file is POSTed to `upload.php` via `fetch()` + `FormData`; a spinner replaces the button icon while the upload is in progress. On success the JS inserts `<a href="FULL_URL" class="fu-lightbox"><img src="THUMB_URL" alt="" /></a>` at the cursor position in the textarea.

- **Lightbox for post images** — `fuLightboxInit()` added to `fanupdate.js`. It wires a click handler on every `<a class="fu-lightbox">` link, opening a full-screen dark overlay that displays the linked full-size image. The overlay fades in/out (CSS `transition: opacity`); Escape or clicking outside the image closes it. Focus is moved into the overlay for keyboard accessibility and returned to the trigger link on close. Self-contained scoped styles are injected into `<head>` once on first call — the lightbox works on public pages without the admin stylesheet. `FanUpdate::printBlog()` emits `<script src="…/fanupdate.js">` (once per page) and `<script>fuLightboxInit();</script>` after posts are rendered, covering both list and single-post views.

- **Upload options** — three new options readable by `upload.php` via `getOpt()`: `upload_max_filesize` (bytes, default 2 MB), `upload_thumb_width` (pixels, default 300), `upload_thumb_height` (pixels, default 300). Configurable from **Admin → Options** after install.

### Fixed

- **`makeWysiwyg()` — image button wired per-textarea** — the image button in `myWysiwyg` has `func: null`; `makeWysiwyg()` in `fanupdate.js` detects this by name and binds a closure that captures the specific textarea instance, so `fuImageButtonClick()` always inserts into the correct field. On non-admin pages where `fanupdate-admin.js` is not loaded, `fuImageButtonClick` is undefined and the button is silently omitted from the toolbar.

### Files changed

- `admin/upload.php` *(new)*
- `uploads/.htaccess` *(auto-created on first upload)*
- `class/FanUpdate.php`
- `assets/js/fanupdate.js`
- `assets/js/fanupdate-admin.js`

---

## [Redux 2.0.1] — June 6, 2026

### Added

- **Spam protection: honeypot field** — `FanUpdate::printBlog()` now injects a hidden `<input name="fax">` into every comment form. The field is visually positioned off-screen (`position:absolute; left:-9999px`) and carries `aria-hidden="true"` so it is invisible to humans and screen readers but filled in automatically by bots. Any submission with a non-empty `fax` value is silently discarded by `process.php` before any database work.

- **Spam protection: time gate** — `printBlog()` injects a hidden `<input name="fu_form_time">` containing the server's Unix timestamp at page-render time. `process.php` rejects submissions that arrive in under 3 seconds (`FU_MIN_COMMENT_SECONDS`) — real humans take longer; bots submit instantly.

- **Spam protection: per-IP comment rate limiter** — new `class/CommentRateLimiter.php` (modelled on `LoginRateLimiter`). Allows up to `MAX_COMMENTS_PER_WINDOW` (5) comments per IP per hour; exceeding the limit triggers a 10-minute block. Block state is stored in `data/comment_rate/` (auto-created, `.htaccess` protected, not web-accessible). Check runs in `process.php` before any database access.

- **Spam protection: duplicate submission guard** — `process.php` stores an MD5 hash of the last accepted comment from each IP in a temp file (`fu_last_comment_<hash>.json`). Identical comment text resubmitted from the same IP within 5 minutes (`FU_DUPE_WINDOW`) is silently discarded.

- All four bot checks produce a fake-success response (identical to a held-for-moderation reply) so bots receive no signal that the submission was rejected.

- `assets/css/style-night.css` — Midnight Night theme variant.

- **Theme toggle button in the admin header** — moon/sun toggle in the nav bar; preference persisted in `localStorage`.

### Changed

- **Default blacklist refreshed**, **`.nav-icon-btn` button fixes**, **Options page toggle switches**, **options label sentence-casing**, **admin panel content centering**.

### Files changed

- `class/CommentRateLimiter.php` *(new)*
- `class/FanUpdate.php`
- `functions.php`
- `install.php`
- `process.php`
- `admin/header.php`
- `admin/options.php`
- `assets/js/fanupdate-admin.js`
- `assets/css/style.css`
- `assets/css/style-night.css`


---

## [Redux 2.0.1] — June 5, 2026

### Removed / Fixed

- 25 obsolete image files deleted from `assets/images/`.
- WYSIWYG toolbar fixes: gap, alignment, full-width stretch, front-end scoped styles, double-toolbar, broken SVG icons.

### Files changed

- `assets/js/fanupdate-comment-submit.js`
- `assets/js/fanupdate.js`
- `class/FanUpdate.php`


---

## [Redux 2.0.1] — June 4, 2026

### Fixed

- "Allow comments" toggle missing from blog form; comments and comment form missing on single-post pages; `submitCmtInit()` not callable directly; comments never saved in `process.php`; CAPTCHA session cookie mismatch.

### Files changed

- `admin/blog.php`
- `assets/js/fanupdate-comment-submit.js`
- `class/FanUpdate.php`
- `process.php`
- `captcha.php`


---

## [Redux 2.0] — June 2, 2026 — Admin UI Redesign & HTML Modernisation

Complete redesign of the admin panel UI with a modern aesthetic and full HTML modernisation pass across every admin file.

---

## [Redux 1.5–1.9: Modernisation] — May 29–June 1, 2026

Five iterations: PHP 8.2+ compatibility pass, JS modernisation, directory restructure, email/options cleanup, FileHandler refactor.

---

## [Redux 1.1–1.4: Foundation] — May 28–29, 2026

Seven iterations: PHP 8.2+, bcrypt passwords, HMAC tokens, rate limiting, admin account page.

---

## [Robotess Fork 1.0] — 2020 — PHP 7 Port

`mysql_*` → `mysqli_*`, `ereg_*` → `preg_*`, null-coalescing operators.

---

## [2.2.1] — 2008-06-08 through [2.0] — 2007-06-20

Original Jenny Ferenc releases. See git history for details.

---

## [1.x] — 2005–2007

Original versions by Jenny Ferenc.

