# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


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
