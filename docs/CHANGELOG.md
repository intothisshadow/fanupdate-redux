# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


---

## [Unreleased]

### Fixed

- **`admin/comment.php` — type mismatch on comment preview** — the preview block
  called `echo $cmt->printComment();`, but `FanUpdate_Comment::printComment()`
  is declared `void` — it echoes its own output directly and returns nothing.
  Wrapping the call in `echo` passed a `void` return where `string|Stringable|null`
  is expected (flagged by static analysis). Every other call site in the
  codebase (e.g. `FanUpdate::printBlog()`) already calls it as a bare
  statement. Fixed: `$cmt->printComment();` with no `echo`.

### Files changed

- `admin/comment.php`

---

## [Redux 2.1.0] — July 1, 2026 — Security, Database & Admin UI Modernisation

Milestones 1–3 of the architecture roadmap (`TODO security.md`): CSRF protection
on every administrative POST request, a full migration of the database layer
from `mysqli` to PDO with prepared statements throughout, and admin UI HTML5
compliance fixes (nested `<form>` elements removed, destructive actions moved
to server-side confirmation views).

### Added

- **CSRF protection for all administrative POST requests** (Milestone 1 — Security) —
  a central CSRF API was added to `class/FanUpdate.php`:
  - `generateCsrfToken()` — returns the current session's token, generating one
    (32 random bytes, hex-encoded via `random_bytes()`) on first use.
  - `verifyCsrfToken(?string $token)` — timing-safe comparison via `hash_equals()`.
  - `csrfField()` — renders the hidden `<input name="csrf_token">` for a form.
  - `verifyCsrfOrFail()` — call at the top of a POST handler; queues an error and
    returns `false` on failure (AJAX requests with `mode=ajax` instead get an
    immediate 403 response, since those callers only check `response.ok`).
  - A new `ensureSession()` helper starts a PHP session (secure, httponly,
    `SameSite=Strict` cookie, name `fu_session`) used solely to store the CSRF
    token — separate from the existing HMAC auth cookies (`fu_username`/`fu_token`),
    which are unchanged.
  - Every admin POST handler (`admin/blog.php`, `admin/comment.php`,
    `admin/options.php`, `admin/account.php`, `admin/category.php`,
    `admin/blacklist.php`, `admin/smilies.php`, `admin/templates.php`,
    `admin/upload.php`) now calls `verifyCsrfOrFail()` before processing any
    submitted action, and every admin `<form method="post">` — including
    per-row delete forms — now includes `$fu->csrfField()`.
  - The login form (`FanUpdate::login()`) is also CSRF-protected, since login
    CSRF can be used to force a victim into an attacker-controlled account.
  - `admin/header.php` emits `const fuCsrfToken = "...";` so the AJAX calls in
    `fanupdate-post-admin.js` (delete entry), `fanupdate-comment-admin.js`
    (delete/approve comment), and `fanupdate-admin.js` (image upload) — which
    build their own request bodies rather than submitting an HTML form — can
    include the token. The post/comment delete AJAX calls instead read the
    token from their enclosing per-row form's hidden field.

- **`class/SqlConnection.php` — migrated from `ext/mysqli` to PDO (Milestone 2 — Database Modernisation)** —
  the class's backing engine is now `PDO` (`pdo_mysql`) configured with
  `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`,
  `PDO::ATTR_EMULATE_PREPARES => false`, and
  `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true` (preserves the old mysqli
  buffered-result behaviour that `NumRows()`/`AffectedRows()`/multiple
  in-flight queries depend on). Every query — including calls made through
  the legacy `Execute()` / `GetRecord()` / `GetFirstCell()` / `GetAll()` /
  `ExecutePaginate()` methods — now goes through a real server-side prepared
  statement instead of a raw `mysqli_query()` string.
  - The existing public API is preserved exactly, so call sites elsewhere in
    the codebase continue to work unmodified. Every method that accepts a
    SQL string now also accepts an optional trailing `array $params` for
    bound parameters, so a call site can move to prepared statements without
    changing its shape (e.g. `$fu->db->Execute($sql, $err_msg, [$value])`).
  - New convenience methods for bound-parameter queries: `query()`,
    `fetch()`, `fetchAll()`, `fetchColumn()`. Unlike the legacy family, these
    let `PDOException` propagate to the caller instead of calling
    `trigger_error()`, per CLAUDE.md's request to improve database error
    handling using PDO exceptions in new code. (A lowercase `execute()`
    alias was deliberately not added — PHP method names are
    case-insensitive, so it would collide with the existing `Execute()`.)
  - New transaction helpers: `beginTransaction()`, `commit()`, `rollBack()`,
    `inTransaction()`.
  - `GetLastSequence()` now reads `PDO::lastInsertId()` (previously
    `mysqli_insert_id()`); the method name and return type are unchanged.
  - `Connect()` now parses a `host:port`-style `dbhost` value (a format
    `mysqli_connect()` also accepted) into separate DSN `host`/`port`
    segments, since PDO's mysql driver requires them split.
  - `Escape()` has been removed entirely — every call site across the
    codebase now uses bound parameters instead.

- **`docs/DATABASE.md`** — new developer documentation for the database layer:
  the legacy-vs-modern API table, bound-parameter examples for `fetch()`/
  `fetchAll()`/`fetchColumn()`/`query()`, pagination via `ExecutePaginate()`,
  a transaction example using `beginTransaction()`/`commit()`/`rollBack()`, the
  `pdo()` escape hatch, and a short "Internals" section pointing maintainers at
  `runStatement()`/`fetchOneRow()`/`fetchAllRows()` for future query helpers.
  Linked from `README.md`'s file structure listing.

- **`admin/account.php` — email update query converted to a bound parameter** —
  the `UPDATE ... SET optvalue='...'` string built with `$fu->db->Escape($new_email)`
  replaced with `optvalue = ?` and `$fu->db->Execute($sql, $err_msg, [$new_email])`.

- **`admin/options.php` — bulk option-save query converted to bound parameters** —
  both the option key and value were previously escaped with `$fu->db->Escape()`
  and interpolated into the query string; both are now passed as `?` placeholders
  via `$fu->db->Execute($sql, $err_msg, [$value, $key])`.

- **Milestone 2, Phase 2 — blog/comment/category/template/smiley/blacklist admin pages
  migrated to bound parameters** — every `$fu->db->Escape()` call in these files was
  removed; all `INSERT`/`UPDATE`/`DELETE`/`SELECT` queries that previously interpolated
  user input into the SQL string now use `?` placeholders with values passed through
  `Execute()`/`GetFirstCell()`/`ExecutePaginate()`'s `$params` argument.
  - `admin/blog.php` — add/update/delete entry queries, the catjoin insert/delete pair,
    the GET-edit lookup, and the entry-list category filter + full-text search query
    (`MATCH ... AGAINST`) all converted.
  - `admin/comment.php` — add/update/delete comment queries, the GET-edit lookup, and the
    comment-list search (`LIKE '%...%'`) + status filter query all converted.
  - `admin/category.php` — add/update/delete category queries (both the `collective_table`
    name update and the `catjoin_table` settings row) and the GET-edit lookup converted.
  - `admin/templates.php` — the per-template `UPDATE options_table SET optvalue=...`
    query converted.
  - `admin/smilies.php` — add/update/delete smiley queries converted.
  - `admin/blacklist.php` — the duplicate-check `SELECT COUNT(*)`, add, and delete queries
    converted.
  - `admin/index.php` — the dashboard "top commenters" query converted to a bound
    parameter.
  - `admin/get-code.php` — the category-name lookup converted to a bound parameter for
    consistency.

- **Milestone 2, Phase 3 — remaining public-facing pages migrated to bound parameters;
  `SqlConnection::Escape()` removed** — `class/FanUpdate.php`'s `printBlog()` gained a
  fourth parameter, `array $params = []`, threaded through to
  `$this->db->ExecutePaginate($query, $main_limit, $params)`, so the public
  blog-listing pages could pass bound values for their dynamic `WHERE`/
  `MATCH ... AGAINST` clauses.
  - `show-blog.php` / `show-cat.php` — category filter, full-text search, single-entry
    lookup, and archive filter queries all converted.
  - `rss.php` / `rss-comments.php` — category-subject lookup, entry/comment-list query,
    and most-recent-`added` query all converted.
  - `process.php` — the entry lookup, the two per-email spam-scoring `COUNT(*)`
    lookups, and the comment `INSERT` all converted.
  - `install.php` — the blacklist upgrade loops, the per-setting `optdesc` update loop,
    the options `INSERT`, and the final `_db_version` `UPDATE` all converted.
  - `class/SqlConnection.php` — `Escape()` deleted; nothing calls it anymore.

### Changed

- **`admin/blog.php` — Milestone 3, fixed nested `<form>` elements in the entry list** —
  the entry list wrapped the whole table (including each row's own
  `<form>...delete...</form>`) inside an outer `<form id="blog-select-form">`.
  Nested `<form>` elements are invalid HTML5: per the parsing spec the browser
  ignores the inner `<form>` start tag (the "form element pointer" is already
  set), so the inner hidden `id` field and delete button silently became
  children of the OUTER form, and the inner `</form>` close tag prematurely
  closed the outer form instead — leaving later table rows outside any form
  in the parsed DOM.
  - Fixed by giving the outer form a real purpose: checkboxes + a
    "Delete selected" button now submit `action=bulk-delete`.
  - Per-row "Delete" is now a plain link (not a nested form) to a new
    `?action=confirm-delete&id=N` view.
  - Both single-entry and bulk delete now route through a dedicated
    server-side confirmation view with its own single, valid `<form>` before
    anything is actually deleted, instead of relying on a JS `confirm()`
    dialog as the only safeguard — this also works with JavaScript disabled.
  - New POST actions: `bulk-delete` (shows the confirmation view for the
    checked entries) and `bulk-delete-confirmed` (performs the deletes).
    The existing `delete` action is unchanged and is now reached from the
    confirm-delete view's form instead of a nested per-row form.

- **`admin/comment.php` — Milestone 3, per-row delete converted to the same confirmation-view pattern** —
  there was no outer form wrapping the comment table, so this was not an
  actual nesting bug, but the per-row `<form>` pattern was identical to the
  one that caused the `admin/blog.php` bug and relied solely on a JS
  `confirm()` dialog. Converted to a plain link to a new
  `?action=confirm-delete&id=N` view with its own single form, for
  consistency and so a future bulk-select addition can't reintroduce
  nesting. The edit form's inline "Delete" button (a single, already-valid
  form) is unchanged.

- **`assets/js/fanupdate-admin.js` — fixed `confirmDelete()` not actually blocking the default action** —
  `confirmDelete()` returned `false` when the user cancelled the dialog, but
  since it's wired up via `addEventListener` (not an `onclick=""` attribute),
  returning `false` from the handler is a no-op — only `e.preventDefault()`
  actually cancels the click/submit. This meant clicking "Cancel" on the
  confirm dialog for any `.delete` element still let the action through.
  Fixed by calling `e.preventDefault()` before returning `false`. This
  benefits every admin page still using the JS-confirm-only pattern
  (`admin/category.php`, `admin/blacklist.php`, `admin/smilies.php`,
  `admin/templates.php`, and the comment/blog edit forms' inline delete
  buttons). `confirmDeleteInit()` also now skips elements carrying
  `[data-skip-confirm]` — used on `admin/blog.php` and `admin/comment.php`'s
  new confirmation-view buttons, since the page itself is already the
  confirmation step and a second JS dialog on top of it would just be
  redundant friction.

- **`assets/js/fanupdate-post-admin.js` — Milestone 3, replaced AJAX row-delete with "select all" wiring** —
  the old `fuPost.deletePost()`/`deletePostCallback()` AJAX delete flow read
  a per-row form nested inside the entry list's outer select form; since
  per-row delete is now a plain link to a real confirmation page (see
  `admin/blog.php` above), there is nothing left for this file to intercept.
  Replaced with wiring for the entry list's `#select-all` checkbox, which now
  has a real function: toggling every `id[]` row checkbox for the bulk-delete
  form, and staying in sync when rows are checked/unchecked individually.

- **`assets/js/fanupdate-comment-admin.js` — Milestone 3, removed dead AJAX delete code** —
  `fuComment.deleteCmt()`/`deleteCmtCallback()` and the delete-button wiring
  in `init()` removed; per-row delete in `admin/comment.php` is now a plain
  link to a server-side confirmation view (see above), so there are no
  `.delete` buttons left in the comment list for this file to wire up.
  `approveCmt()` and the "Approve" link wiring are unaffected.

- **`class/SqlConnection.php` — Milestone 2.5, database layer internal consolidation** —
  no public API or behaviour changes; internal refactor to eliminate duplicated
  query/fetch logic and tighten documentation.
  - Added a private `runStatement()` helper that owns the single `PDO::prepare()`/
    `execute()` call site. Both `Execute()` (catches `PDOException`,
    `trigger_error()`s, returns `false` — legacy contract) and `query()` (lets
    the exception propagate — modern contract) now delegate to it instead of
    each containing its own prepare/execute block.
  - Added private `fetchOneRow()`/`fetchAllRows()` helpers that own the fetch+
    `FreeResult()` logic. `GetRecord()`/`fetch()`, `GetFirstCell()`/`fetchColumn()`,
    and `GetAll()`/`fetchAll()` are now thin wrappers around these two helpers.
  - Added `@throws \PDOException` PHPDoc to every method that can propagate one,
    and clarified the `mixed`-return caveat on `GetFirstCell()`/`fetchColumn()`
    with precise `@return int|float|string|bool|null` PHPDoc.

### Fixed

- **`show-blog.php` / `show-cat.php` — loose comparisons replaced with strict** —
  `$_GET['view'] == 'archive'` → `===`; `$year != $lastyear` → `!==`;
  `$value != ''` (category options loop) → `!== ''`.

- **`show-blog.php` / `show-cat.php` — search query output not HTML-escaped** —
  `echo '<p>Search results for <strong>' . $q . '</strong></p>'` used `$q` directly.
  `clean_input()` strips tags but leaves `&`, `"`, and other HTML-significant characters
  unescaped. Wrapped with `htmlspecialchars($q, ENT_QUOTES, 'UTF-8')`.

- **`admin/category.php` / `admin/templates.php` — HTML stripped from templates on save** —
  `clean_input()` was called without `allowtags=true`, silently stripping every `<h2>`,
  `<p>`, `<a>`, `<strong>` etc. from template fields on each save. Fixed by passing
  `true` as the second argument.

- **`admin/comment.php` — HTML stripped from comment body when admin edits a comment** —
  `clean_input($_POST['comment'])` stripped all HTML from the comment body. Since
  `process.php` converts BBCode to HTML (`[B]` → `<strong>`, `[URL]` → `<a>`, etc.),
  stored comments can contain HTML markup; editing and re-saving corrupted that markup.
  Fixed: `clean_input($_POST['comment'], true)`.

- **`admin/index.php` — `admin_email` unescaped in Top commenters SQL query** —
  interpolated directly into a SQL WHERE clause without escaping. Fixed (now a bound
  parameter as part of the PDO migration above).

- **`process.php` — loose comparisons replaced with strict** —
  `$ip_hostname == $_SERVER['REMOTE_ADDR']` → `===`; `$num_links == 0` → `=== 0`;
  `$clean['approved'] == 0` → `=== 0`. `getOpt('points_approval_threshold', true)` now
  cast to `(int)` before the `<` comparison so PHP does not silently coerce a string.

- **`admin/get-code.php` — `$subject` and `install_path` not HTML-escaped in textarea** —
  both now wrapped in `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

- **`class/SqlConnection.php` — `@` error suppressors removed from `Connect()`** —
  `@mysqli_connect()`, `@mysqli_select_db()`, and `@mysqli_set_charset()` used the `@`
  error-control operator. Replaced with explicit `mysqli_driver::report_mode` handling
  (superseded by the PDO migration above).

- **`admin/upload.php` — `install_path` option absence causes upload failure** —
  `rtrim((string) $fu->getOpt('install_path'), '/')` cast `false` to the empty string
  when the option was not yet saved in the database. Added a fallback to
  `dirname(__DIR__)` so the feature works even when the option is absent.

- **`admin/category.php` — fatal `Error: Call to undefined method SqlConnection::InsertID()`** —
  the correct method is `GetLastSequence()`. Fixed.

- **`process.php` — blacklisted comments submitted despite error message** —
  `$pass_blacklist` was initialised to `true` and never set to `false` when a
  blacklisted word was found (non-scoring mode); the `break` only exited the while
  loop. Fixed: `$pass_blacklist = false` added alongside `$fu->addErr()`.

- **`process.php` / `show-cat.php` — unnecessary reference assignment `=&` on singleton call** —
  PHP passes objects by handle, not value; assigning by reference from a
  non-reference-returning method produces an `E_NOTICE` in PHP 8. Both changed to
  `$fu = FanUpdate::instance()`.

- **`show-cat.php` — wrong-case method calls `AddOpt()`, `getDateForMatted()`, `getURL()`** —
  corrected to `addOpt()`, `getDateFormatted()`, and `getUrl()` per camelCase standard.

- **`process.php` — `AddOptFromDb()` wrong casing** — corrected to `addOptFromDb()`.

- **`process.php` — loose comparisons `!=` / `==` replaced with `!==` / `===`** —
  `$_POST['submit_comment'] != 'ajax'` and `$clean['remember_me'] == 'y'` both replaced
  with strict comparison.

- **`rss.php` — loose `$c == 0` replaced with `$c === 0`**.

- **`class/FanUpdate.php` — `@` error suppression in `checkUpdates()` removed** —
  `@$doc->load()` replaced with `libxml_use_internal_errors(true)` /
  `libxml_clear_errors()`.

- **`class/FanUpdate.php` — inefficient `SELECT *` comment count in `printBlog()`** —
  replaced with a single `SELECT COUNT(*)` via `GetFirstCell()`.

- **`admin/credits.php` — footer credits link gave 404 for unauthenticated users** —
  `printCredits()` emitted `<a href="credits.php">` as a relative URL, which resolved
  to the document root and produced a 404 under URL rewriting. Additionally,
  `admin/credits.php` called `$fu->login()`, forcing a login redirect even though
  credits are read-only public information. Fixed:
  - `printCredits()` now uses the absolute URL
    `rtrim($install_url, '/') . '/admin/credits.php'`.
  - A new `isLoggedIn(): bool` public method was added to `FanUpdate` — a
    non-redirecting cookie check safe to call before any output.
  - `admin/credits.php` no longer calls `$fu->login()`; the admin navigation bar is
    shown only when `$fu->isLoggedIn()` returns `true`.

- **`declare(strict_types=1)` added to all remaining PHP files** — `process.php`,
  `rss.php`, `rss-comments.php`, `show-blog.php`, `show-cat.php`, `install.php`,
  `class/FeedItem.php`, `class/FeedWriter.php`. Where `getOpt()` (which returns
  `mixed`) was passed directly to typed parameters, explicit casts were added at
  the same time.

- **`rss.php` / `rss-comments.php` — RSS feeds broken by undefined `FeedWriter` methods** —
  `$feed->setPubDate()` and `$feed->setBuildDate()` do not exist on `FeedWriter`,
  producing fatal errors. Replaced with
  `$feed->setChannelElement('pubDate', date('r', ...))` and
  `$feed->setChannelElement('lastBuildDate', date('r'))`.

- **`rss.php` / `rss-comments.php` — `addNamespace()` called with one argument** —
  `FeedWriter::addNamespace(string $prefix, string $uri)` requires two arguments; the
  calls for `'content'`, `'slash'`, and `'wfw'` omitted the URI. Fixed: explicit
  namespace URIs added to every call site.

### Removed

- **`blog-config.sample.php` — removed obsolete Flinx Collective and Fan Admin references** —
  the `$coltable['f']` and `$coltable['g']` entries and their associated comments are
  gone. Only `'e'` (Enthusiast) and `'n'` (standalone) remain.

### Notes

- This release covers Milestones 1–3 of `TODO security.md` end to end: CSRF
  protection on every administrative POST request, the full mysqli → PDO
  migration (all three phases: authentication/account/options, admin pages,
  remaining public-facing pages), the Milestone 2.5 database-layer internal
  consolidation, and the Milestone 3 nested-`<form>` / confirmation-view fixes.
  Remaining open items — transaction-handling review, a final security/code
  quality audit before the *next* release, and Milestone 4 (Architecture
  Modernisation) — are tracked as unchecked items in `TODO security.md`.

### Files changed

- `class/FanUpdate.php`
- `class/SqlConnection.php`
- `class/FeedItem.php`
- `class/FeedWriter.php`
- `admin/blog.php`
- `admin/comment.php`
- `admin/category.php`
- `admin/templates.php`
- `admin/smilies.php`
- `admin/blacklist.php`
- `admin/account.php`
- `admin/options.php`
- `admin/index.php`
- `admin/get-code.php`
- `admin/upload.php`
- `admin/header.php`
- `admin/credits.php`
- `show-blog.php`
- `show-cat.php`
- `rss.php`
- `rss-comments.php`
- `process.php`
- `install.php`
- `blog-config.sample.php`
- `assets/js/fanupdate-admin.js`
- `assets/js/fanupdate-post-admin.js`
- `assets/js/fanupdate-comment-admin.js`
- `docs/DATABASE.md` *(new)*

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
