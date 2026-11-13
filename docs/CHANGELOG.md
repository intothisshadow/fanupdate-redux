# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


---

## [Unreleased]

### Added

- **Download a backup from the Updates page (FP-50)** — each installation backup in **Admin → Updates → Backups** now has a Download button that saves the ZIP to your computer, named like `FanUpdateRedux-2026-11-03-172441.zip` (backup date and time), so you can keep an off-site copy. It is a signed-in, token-protected action, only serves backups FanUpdate itself created, and is recorded in the activity log. Backups include `blog-config.php`, so keep downloaded copies private.
- **Supported forks named** — the Collective Source dropdown and the README now say which forks are supported: Enthusiast (Erin's PHP 8 fork) and Listing Admin (Redux or Erin's PHP 8 fork).
- **Listing Admin integration (FP-39)** — **Admin → Options → Collective Source** now offers Listing Admin (`listingadmin`), filling in its main fanlistings table (`trex` by default; it is your Listing Admin table prefix without the underscore) and its `id`/`subject` columns. Fanlistings are read live, so new ones show up immediately, and removing one in Listing Admin raises the existing Dashboard notice and confirm-before-delete flow on **Admin → Categories**.

- **Enthusiast integration verified (FP-38)** — Enthusiast's `owned` table (`listingid`/`subject`) works with the live category list, post editor and removed-fanlisting notice and confirm flow, on both latin1 and utf8mb4 installs. No Enthusiast-specific sync is needed, since fanlistings are read live from `owned`.

### Fixed

- **Odd standalone category IDs** — when a collective script is configured, FanUpdate's own categories table was created with its counter already set to 1,000,001 and then offset by another 1,000,000, so the first category you added showed as ID 2,000,001. New tables now start at 1 (first ID 1,000,001). Existing tables keep their counters, since entries already reference those IDs.
- **Category list failed with Listing Admin, Enthusiast and other non-utf8mb4 tables** — when a collective script was configured, the combined category list forced the fanlisting names to `utf8mb4_general_ci`, which MySQL rejects for utf8mb3 tables such as Listing Admin's (and latin1 ones such as Enthusiast's). The names are now converted to utf8mb4 first, which works for any table character set.

- **Admin header icons blank under a strict policy** — the admin's navigation and button icons (account, credits, theme, logout, and the rest) loaded from the jsDelivr CDN (`@latest`), so a Content-Security-Policy that doesn't allow that host showed empty boxes. The 19 icons the admin uses are now self-hosted as a small font subset (about 4 KB) in `assets/vendor/tabler-icons/`, which also removes the unpinned CDN dependency. Tabler Icons (MIT) is added to the credits.

---

## [Redux 2.7.1] — November 12, 2026 — Strict-CSP Admin & Upload Fixes

### Changed

- **Admin works under a strict Content-Security-Policy (FP-49)** — the admin pages and `install.php` no longer contain inline scripts or `onclick`/`onsubmit` handlers, so a site-wide `script-src 'self'` policy no longer breaks them (the editor's image upload, confirm dialogs, theme toggle, update progress bars and the collective-source dropdown all work). The page's CSRF token and asset version now travel in `<meta>` tags read by a small external script, and the destructive-action confirm dialogs use a `data-confirm` attribute. The public blog output (`show-blog.php`, `show-cat.php`) is covered too: it no longer prints inline scripts, so the **image lightbox** and the **comment form** wire themselves from `fanupdate.js` / `fanupdate-comment-submit.js` and work on sites with a strict policy. The unused `fu_url` JavaScript global that the single-post page used to print was removed.

### Fixed

- **Oversized image upload reported as "Security check failed"** — when a file exceeded the server's `post_max_size`, PHP dropped the whole request and the upload endpoint misreported it as a CSRF failure. It now says the file exceeds the server upload limit and shows the limit.
- **Upload errors hidden behind "Server error 403"** — the post editor's image upload now shows the server's own message (for example the expired-session security check) instead of only the HTTP status.
- **Image upload validator deleted by host malware scanners** — a code comment in `ImageUploadValidator.php` contained a literal image-header-plus-PHP-tag string, a well-known web-shell signature, so some hosts' scanners quarantined the file after install and the upload endpoint failed with a "Failed opening required" error. The comment no longer contains the signature.

---

## [Redux 2.7.0] — November 6, 2026 — Activity & Error Logs, Hidden Collective Categories & More

### Added

- **AVIF image uploads** — AVIF joins JPEG, PNG, GIF and WebP in the post
  editor's upload and the Media gallery, with thumbnails, on servers whose
  PHP image library (GD) supports it; otherwise it is refused with a clear
  message.
- **Activity & error logs (FP-46)** — a new **Admin → Logs** page. The
  Activity tab records logins (successes, failures, lockouts), password and
  account changes, entry/category/comment/media/blacklist/settings changes,
  update actions, and public comments blocked by the rate limit, with
  filters for event type, text and date range. The Errors tab shows PHP
  warnings, errors and uncaught exceptions FanUpdate caught, newest first.
  Passwords, comment text, setting values and failed-login usernames are
  never stored. New settings **Log retention (days)** (default 90) and
  **Anonymise IPs in logs**; both logs have a Clear button. The activity log
  lives in a new `blog_log` table, created by the database update shown in
  Admin → Updates (or by a fresh install). Errors are written to
  `data/logs/error.log`, which rotates at 512 KB. Log times and the date
  filters use your configured timezone (FP-19) rather than UTC.

- **Hide collective categories (FP-37)** — a new "Show collective categories"
  toggle in Admin → Options → Main (`show_collective_categories`). Turn it off
  to list only FanUpdate's own categories on the Categories page and in the
  entry editor's category picker, while keeping your Enthusiast/Fanatically
  configuration. Defaults to on, so existing sites are unchanged; the setting
  appears in Options after your next version upgrade.

### Changed

- **Database upgrade no longer times out or aborts mid-run (FP-40)** — the
  InnoDB/utf8mb4 conversion now lifts PHP's time limit and keeps running if
  the browser disconnects. Converting a very large legacy site (hundreds of
  thousands of rows) can take a minute or more; ordinary sites finish in
  seconds. If a web-server timeout still cuts a run short, run the database
  update again from Admin → Updates — each table converts all-or-nothing.
- **Footer link hook classes restored (FP-30)** — the default footer
  template's main/archive links now carry `fu-nav-link`, and the credit link
  `fu-credit-link`, so skins can style them directly. A footer saved earlier
  keeps its stored copy; add the classes in Admin → Options → Templates →
  Footer (see the README's "Nav, category, comment-count, and credit links").
- **Larger admin text** — the admin panel's base font size went from 15px to
  20px in both the day and night themes; everything scales with it. Screens
  800px wide or narrower keep the original 15px.

### Fixed

- **Media delete could remove files outside the uploads folder** — if a
  media record's stored path had been tampered with (for example `../`), deleting
  the image would have deleted that file. Such paths are now ignored; only the
  database row is removed.
- **Disguised files accepted as images** — an upload only had to *start* like
  an image (e.g. `GIF89a` followed by PHP code). Uploads must now fully
  decode as a real image, and images over 50 megapixels are refused.
- **RSS feed host validation** — the server name used to turn root-relative
  links and images in entries into full URLs is now checked to be a valid
  hostname first; if it isn't, those links are left as they were.
- **Credits page styling** — the contributor and library blocks now use
  theme CSS classes instead of inline styles, in both day and night themes.
- **Admin navigation missing on phones** — below 800px wide the main
  links (Dashboard, Entries, Comments, Categories, Media, Options, Updates)
  were hidden with no way to reach them. A menu button now opens them as a
  dropdown under the top bar (closes on Escape or tapping elsewhere), in
  both the day and night themes.
- **Admin pages scrolling sideways on phones** — the Options sub-navigation
  (Options, Blacklist, Smilies, Templates, Collective Source, Privacy), the
  Comments filter/search bar, the Entries table and the Updates page were all
  wider than the screen. Sub-navigation now wraps, tables scroll within
  themselves, fieldsets can shrink, and the dashboard's Quick links card uses
  the full width.
- **Sub-navigation always highlighted its first tab** — on the Options pages
  and Comments, the first tab (Main / Approved) looked active in addition to
  the current one. Only the current page's tab is highlighted now.

## [Redux 2.6.1] — November 3, 2026 — Legacy Charset Upgrade Fixes

### Fixed

- **Upgrading a legacy site with non-ASCII content to InnoDB/utf8mb4 (FP-40)** —
  testing the conversion against real latin1 tables with accented text,
  curly quotes, Cyrillic and emoji found three bugs that never showed on
  sites with plain-ASCII content:
  - The check for UTF-8 text stored in latin1 columns read values through
    the app's utf8mb4 connection, where MySQL converts latin1 to UTF-8 on
    read, so any accented text looked "mis-encoded". It now reads the raw
    stored bytes.
  - Re-labelling mis-encoded columns failed with error 1283 because the
    entries and comments `FULLTEXT` indexes cover them; the indexes are now
    dropped and re-created around the step, so search keeps working.
  - A re-labelled table kept a latin1 default charset and was reported as
    pending forever; it now finishes with a utf8mb4 conversion (no bytes
    change) and the columns use the same collation as every other table.
  Fresh installs and sites without non-ASCII content were never affected.

## [Redux 2.6.0] — November 3, 2026 — Media Bulk Delete, Installer Redirect, Manual ZIP Upload & More

### Added

- **Automatic redirect to the installer (FP-45)** — while `install.php` is
  still present, opening the front page (`index.php`) or `admin/` now
  redirects (302) to `install.php` when `blog-config.php` is missing, the
  database can't be reached, or the options table doesn't exist yet,
  instead of failing with a fatal error. `install.php` itself now shows a
  short "copy `blog-config.sample.php` to `blog-config.php`" page, with no
  filesystem paths, when the config file is missing. Finished installs are
  unaffected and pay no cost: once `install.php` has deleted itself, the
  check ends after a single file test and never touches the database. An
  upgrade (tables already exist, `install.php` still uploaded) is not
  redirected.
- **Bulk delete on the Media page (FP-43)** — each image in **Admin →
  Media** now has a checkbox, with a "Select all on this page" toggle and a
  **Delete Selected** button. As with bulk-deleting blog entries, a
  confirmation screen lists the filenames before anything is removed;
  images that no longer exist are skipped and the success message reports
  how many were actually deleted.
- **Multiple image upload in the WYSIWYG "Insert Image" modal (FP-26)** —
  the "Upload New" tab's "Choose File…" button now accepts selecting
  several images at once (`<input type="file" multiple>`), uploading them
  to `admin/upload.php` one at a time (not in parallel, to avoid
  hammering shared hosting) and inserting each into the editor as soon as
  its own upload finishes, in selection order. The busy label shows
  "Uploading N of M…" for a multi-file batch (unchanged plain
  "Uploading…" for a single file). A per-file failure doesn't abort the
  rest of the batch — failures are collected and reported as one
  end-of-batch summary alert instead of one alert per failure; the modal
  stays open on a total failure (nothing succeeded) but closes once at
  least one image made it in, matching the previous single-file
  behavior's "never close on a bare failure" rule. No `admin/upload.php`
  changes — it still accepts exactly one file per POST, `fanupdate-tinymce.js`
  just calls it once per selected file now instead of once total.
  Drag-and-drop/paste into the editor body already supported multiple
  images before this (TinyMCE's own `images_upload_handler` contract) —
  this was specifically about the "Choose File…" button path.

- **"View" link on each entry in Admin → Entries (FP-32)** — every published
  row in the Entries table now links out to its real front-end page
  (`$post->getUrl()`, opened in a new tab), so an admin can jump straight
  from the admin listing to see how a post actually looks live, without
  hand-building the URL. Draft rows show no View link — `show-blog.php`'s
  queries are all scoped `WHERE is_public > 0`, so a draft's URL would just
  render an empty page rather than anything meaningful. The Actions column
  header's `colspan` was bumped from 2 to 3 to fit the new cell.

- **Manual ZIP upload option in the Admin Update Manager (FP-33)** — **Admin
  → Updates** now has an "Install from uploaded ZIP" section, always shown
  regardless of GitHub update-check status, for a server that can't reach
  GitHub over outbound HTTPS or to install a build that isn't published as
  a release. The uploaded file is validated the same way a GitHub-fetched
  release is (`InstallManager`'s existing `version.php`/`index.php`/
  `admin/`/`class/` checks), with its target version auto-detected from the
  archive's own `version.php` (root level, or one level down inside a
  single wrapping directory — so a GitHub auto-generated source ZIP
  uploaded manually is handled the same as one downloaded automatically),
  and installed through the exact same backup-first,
  automatic-rollback-on-failure pipeline the GitHub-download flow already
  uses — a failed install restores the pre-install backup automatically,
  same as before. `class/Service/UpdateManager.php`'s
  `installLatestRelease()` had its backup/install/rollback/logging logic
  extracted into a shared private `performInstall()`, now used by both the
  existing GitHub-download flow and the new
  `installUploadedRelease()`, so there's one pipeline instead of two
  near-duplicate copies. `class/Service/DownloadManager.php` gained
  `acceptUploadedFile()`, the upload counterpart to `download()` — stages
  the file into the same managed temp directory with the same naming
  convention and ZIP-integrity check, minus the HTTPS/host/
  expected-checksum steps that only apply to a network fetch.

- **Prev/Next navigation in the frontend lightbox** — when a post contains
  more than one `<a class="fu-lightbox">` image, opening one now groups it
  with every other `fu-lightbox` image inside the same `.fu-entry` (DOM
  order), and shows Prev/Next buttons plus a "N / total" counter; Left/Right
  arrow keys do the same while the overlay is open. Grouping falls back to
  every `fu-lightbox` link on the page when the clicked link has no
  `.fu-entry` ancestor, so the feature still works on templates that don't
  use `printBlog()`'s wrapper. No wrap-around (Prev disabled at the first
  image, Next at the last), and posts with only one image show no
  navigation at all — no behavior change for the existing single-image
  case. Adjacent images are preloaded via `new Image()` on every step so
  navigation feels instant. `fuLightboxInit()`'s initial-open code path and
  the new Prev/Next path both funnel through one shared `fuLbUpdateImage()`
  helper in `assets/js/fanupdate.js`, so focus-return-on-close
  (`overlay._triggerLink`) always tracks whichever image is currently
  displayed, not just the originally-clicked one. Audited all seven
  downstream skins listed in `CLAUDE.md`'s "Downstream Skins" section for
  CSS that positions `#fu-lightbox-overlay`'s children by DOM-order
  assumption (e.g. `:nth-child`) — none exist, so no skin updates were
  needed; the new buttons/counter are `position: fixed`, not flex/grid
  siblings, so their insertion into the DOM doesn't affect layout either
  way.

### Fixed

- **Dashboard "Top commenters" query failed under `ONLY_FULL_GROUP_BY`** —
  `admin/index.php` selected `c.*` while grouping by `c.name, c.email`
  (error 1055). It now selects only the grouped columns plus
  `MAX(c.url)` and the count.
- **Fresh install failed on MySQL/MariaDB strict mode** — `install.php`
  created `blog` and `blog_comments` with `DEFAULT '0000-00-00 00:00:00'`
  on `added` (rejected under `NO_ZERO_DATE`, error 1067) and
  `blog_catoptions` with a nullable `cat_id` primary key (error 1171).
  The zero-date defaults are removed (inserts always supply `added`) and
  `cat_id` is now `NOT NULL`.
- **Stray "WARNING: unlink(): No such file or directory" messages (FP-44)** —
  the error handler in `functions.php` didn't recognise PHP 8's `@`
  suppression, so harmless warnings from deliberately silenced file
  operations (cleaning up temporary files, media deletion, update/rollback
  housekeeping) could appear as red warning lines at the top of admin
  pages. Suppressed warnings are now silent again, as intended.

- `captcha.php` and `admin/upload.php`: removed three dead `imagedestroy()`
  calls, which have had no effect since PHP 8.0 (`GdImage` is a
  garbage-collected object) and started emitting an `E_DEPRECATED` warning
  under PHP 8.5.

- **`fu-lightbox`'s close handler could visibly animate the scroll-position
  restore instead of snapping back instantly**, on any downstream skin
  setting `scroll-behavior: smooth` on `html`/`body` — `fuLbClose()` in
  `assets/js/fanupdate.js` called `window.scrollTo(0, y)` with no explicit
  `behavior`, so it silently inherited the skin's CSS scroll-behavior. Now
  calls `window.scrollTo({ top: y, left: 0, behavior: 'instant' })`, whose
  explicit JS option always overrides the CSS property regardless of skin.

- **`show-cat.php` post title links pointed back at the category page
  instead of the configured Blog page**, making clicking a post title on a
  category listing reload the same category page with the post's `?id=`
  appended rather than taking the visitor to the real single-post view
  (Options -> "Blog page"). Root cause: `FanUpdate::printBlog()` (shared by
  `show-blog.php` and `show-cat.php`) built each `FanUpdate_Post` without
  passing an explicit permalink base, so it fell back to the current page's
  own URL — correct on `show-blog.php` itself, wrong everywhere else it's
  embedded. `printBlog()` now passes the `blog_page` option explicitly,
  matching every other `FanUpdate_Post`/`FanUpdate_Comment` call site
  (`rss.php`, `rss-comments.php`, `admin/index.php`, `admin/blog.php`,
  `admin/comment.php`).

### Changed

- Supported PHP range widened to include PHP 8.5, alongside the existing
  8.2–8.4 support. PHP 8.4 remains the primary development target.

- **Consistent Sentence-case button labels across the admin panel
  (FP-28)** — every `<input type="submit" name="action" value="…">`
  button previously displayed its raw lowercase action token verbatim
  (`add`, `update`, `delete`, `preview`, `check`, `login`,
  `confirm-delete-orphan`), inconsistent with every `<button>`/
  `<a class="button">` elsewhere in the admin panel, which are already
  Sentence case ("Cancel", "Get code", "Install now", etc.). All 25
  instances across `class/FanUpdate.php` (the login form) and 11
  `admin/*.php` files converted to `<button type="submit" name="action"
  value="…">Label</button>` — the `value` (and therefore every
  `$_POST['action']` comparison reading it back) is completely unchanged,
  since a `<button>`'s visible text is independent of its `value`
  attribute; only the tag changed and a Sentence-case label was added as
  inner content. No CSS/JS changes needed: `assets/css/style.css`/
  `style-night.css` already style bare `button` and the semantic classes
  (`.add`/`.update`/`.edit`/`.submit`/`.delete`) alongside every
  `input[type="submit"].*` rule, and `confirmDeleteInit()` in
  `assets/js/fanupdate-admin.js` already selects by class
  (`css.getElementsByClass(document, 'delete', '*')`), not by tag.

- **Added `.fu-nav-link`/`.fu-cat-link`/`.fu-comment-count-link`/
  `.fu-credit-link` to `admin/get-code.php`'s CSS starter snippet and
  documented all four in a new "Nav, category, comment-count, and credit
  links" section of `README.md`**, closing the documentation gap left by
  the CSS-hook-class work these four selectors were added for.

## [Redux 2.5.1] — August 14, 2026 — Fix FP-25 Migration Never Matching Real Installs

### Fixed

- **The FP-25 `entry_template` `.fu-entry` wrapper migration (shipped in
  2.5.0) never actually ran on real installations**, discovered live on a
  production site that updated to 2.5.0 through the Admin Update Manager
  yet showed no pending-upgrades banner and kept its old, unwrapped
  `entry_template`. Root cause: `install.php`'s interactive install form
  renders every `*template*` option's default value into a `<textarea>`
  for the admin to review before it's actually written to the database
  (`install.php`'s `<textarea name="value[]">` around the option-review
  screen) — and browsers normalize textarea content to CRLF (`\r\n`) line
  endings on submit, regardless of the LF (`\n`) the server originally
  sent. `UpgradeRunner`'s `entryTemplateMigrationPending()` compared the
  stored value against an LF-only constant with raw `===`, so it silently
  never matched on any interactively-installed site — which is most real
  installs, not an edge case. Fixed by comparing after a new
  `normalizeTemplateWhitespace()` helper (collapses CRLF/CR to LF, trims)
  is applied to both sides; the migration still writes the canonical
  LF-based new default, only the comparison is whitespace-tolerant. 2 new
  regression tests reproduce the exact CRLF-stock-default scenario.

## [Redux 2.5.0] — August 14, 2026 — `fu-` Naming Convention for Entries, Comments & Archives

### Changed

- **The default `entry_template` now wraps each post in
  `<div class="fu-entry">...</div>`** instead of printing `{{title}}`, the
  catfile line, and `{{body}}` directly into `div#fanupdate` with no
  container. This gives every install a hook for styling entries as
  individual cards (background, border, shadow) without having to hand-edit
  the template first. Updated in all three places the default lives:
  `FanUpdate::__construct()` (in-memory fallback), `FanUpdate_Post::printPost()`
  (empty-string fallback), and `install.php`'s seeded `entry_template` row for
  fresh installs. Added a matching `#fanupdate .fu-entry {}` starter rule to
  the CSS snippet on **Admin → Category → Get Code**, and updated the
  README's "Templates" section accordingly.
- **FP-25: existing installations are migrated to the new `.fu-entry`
  wrapper automatically, without ever touching a genuinely customized
  template.** `UpgradeRunner` gained an entry_template migration
  (`entryTemplateMigrationPending()`/`migrateLegacyEntryTemplateWrapper()`),
  following the same independent-of-`_db_version` pattern as the FP-19
  timezone migration: it runs whenever the stored `entry_template` option is
  byte-for-byte identical to the pre-FP-25 stock default, and does nothing
  otherwise — any admin edit, however small, permanently exempts that
  installation from the automatic swap. Detected on every admin page load
  via the existing pending-upgrades banner and applied the same way as the
  schema/collective-script/timezone migrations (Admin Update Manager's
  post-install step, or Dashboard's "Run Upgrades" action) — no new UI or
  separate mechanism needed.
- **`FanUpdate_Comment::printComment()`'s per-comment wrapper now also
  carries a `fu-comment` class**, following the same `fu-` prefix
  convention as `fu-entry`/`fu-lightbox`: `<div id="comment{{id}}"
  class="comment fu-comment">`. Unlike `entry_template`, this wrapper is
  hardcoded (not part of `comment_template`) and prints unconditionally on
  every installation immediately after updating — so, unlike the
  `entry_template` change above, the original `comment` class is kept on
  the same element permanently rather than migrated away, and no
  `UpgradeRunner` migration is needed or possible here. Added a matching
  `#fanupdate .fu-comment {}` starter rule (alongside the existing
  `div.comment` rules) to the CSS snippet on **Admin → Category → Get
  Code**, and updated the README's "Comment template variables" section.
- **The Archives view (`?view=archive`) now carries `fu-` prefixed classes
  too**, extending the same naming convention to the one remaining page
  that had none: `show-blog.php`'s Month/Category Archives lists and
  `show-cat.php`'s per-category year-grouped listing. Each `<ul>` gets
  `class="fu-archive-list"`, each `<li>` gets `class="fu-archive-item"`,
  and `show-blog.php`'s two lists additionally wrap their `"(N)"` entry
  count in `<span class="fu-archive-count">`. Like the comment wrapper,
  this markup is hardcoded rather than template-driven, so these classes
  are purely additive — no existing selector is renamed or removed, and no
  `UpgradeRunner` migration applies. Added matching (empty) starter rules
  to **Admin → Category → Get Code**'s CSS snippet and a new "Archives"
  subsection to the README.

## [Redux 2.4.1] — August 14, 2026 — Multi-Category Selection Fix

### Fixed

- **Blog entries could only be assigned one category, breaking multi-category
  tagging under the Fanatically integration (and any other collective-source
  setup).** An earlier admin-UI modernization pass replaced the original
  checkbox-list category picker in `admin/blog.php` with a single `<select>`,
  and narrowed `$_POST['cat']` handling and the whole
  `BlogService`/`BlogRepository` write path down to one `cat_id` per entry —
  even though the underlying `catjoin` table was always a proper many-to-many
  join and the display side (`FanUpdate_Post::getCatFromDb()`/
  `getCategoryString()`/`getCategoryLinks()`) never stopped supporting
  multiple categories. Restored the checkbox-list picker (`ul#categories`,
  reusing the existing `select_all`/`catlist` markup and
  `assets/js/select-all.js`'s "Select All/None" helper, which was already
  wired up for exactly this but unused since the regression), changed
  `BlogRepositoryInterface::findCategoryId(): ?int` to
  `findCategoryIds(): int[]`, and updated `BlogService::createEntry()`/
  `updateEntry()`/`getEntryForEdit()` to write/read every selected category
  instead of just the first one.

## [Redux 2.4.0] — August 13, 2026 — News Feed Widget, Emergency Password Reset & Table Sorting Fix

### Fixed

- **CAPTCHA comparison in `process.php` used loose (`!=`) comparison** between
  the submitted code and the session-stored code. Since the CAPTCHA alphabet
  includes digits, an all-digit code could theoretically be matched by a
  numeric-string variant (e.g. `0234689` == `234689`) due to PHP's
  numeric-string comparison rules. Switched to `hash_equals()` for a strict,
  timing-safe comparison, and normalized submitted input with
  `strtolower(trim())` to match the lowercase-only alphabet (fixes a case
  where typing the code in uppercase silently failed).
- **Self-inflicted, same-day: every post/comment render was briefly fatal**
  ("Call to undefined function `doLists()`"). While auditing Admin →
  Credits' "Libraries & third-party code" list (see below), the
  `doHeaders()`/`doLists()`/`outdent()`/`_processListItems()` etc.
  functions in `functions.php` (the "PHP Markdown (modified subset)",
  Michel Fortin) were deleted as "confirmed dead code" — that check only
  verified no file *outside* `functions.php` called them by name. It
  missed that `wpautop()`, a few lines above them in the same file, calls
  `doLists()` unconditionally and `doHeaders()` when `$allowHeading` is
  true — and `wpautop()` is called by `FanUpdate_Post::getBodyFormatted()`/
  `getTemplate()` and `FanUpdate_Comment::getBodyFormatted()` for every
  single post/comment display, admin and public alike. The 494-test suite
  stayed green throughout because nothing in it exercised `wpautop()` at
  all. Caught and fixed same-day, before release, while reviewing whether
  the WordPress-derived text functions were worth modernising — restored
  the deleted functions verbatim, restored the "PHP Markdown" credit in
  `FU_LIBRARIES` (it was accurate all along), and added
  `Unit/TextFormattingFunctionsTest.php` (12 tests) so `wpautop()` — and
  specifically the heading/list code paths that broke — has real
  regression coverage for the first time.

### Changed

- **Admin → Credits' "Libraries & third-party code" list (`FU_LIBRARIES`,
  `version.php`) was significantly out of date** — audited every entry
  against actual current usage, not just link liveness:
  - **Removed:** Silk icon set (Mark James) — not present anywhere in the
    codebase; the admin panel has used Tabler Icons (loaded via CDN in
    `admin/header.php`) for a while now, uncredited.
  - **Added:** Parsedown (Emanuil Rusev, MIT) — vendored at
    `class/Parsedown.php`, used by `class/Service/ReleaseNotesRenderer.php`
    for GitHub Flavored Markdown release notes in Admin → Updates. Already
    fully documented in `docs/THIRD-PARTY-LICENSES.md`; simply never made
    it onto the Credits page.
  - **Added:** TinyMCE (Tiny Technologies, Inc., GPL-2.0-or-later) —
    vendored at `assets/vendor/tinymce/`, the post-body editor in Admin →
    Entries (FP-18). Same situation: documented in
    `docs/THIRD-PARTY-LICENSES.md`, never added to the Credits page.
  - Kept (still genuinely used, verified): Standardista *(its `css` utility
    object only — see the Tablesort follow-up below; the table-sorting
    code it originally credited has since been replaced)*, WordPress
    text-parsing functions, PHP Markdown *(briefly and mistakenly removed
    as "dead" — see the Fixed section above; it's actually used by every
    post/comment render via `wpautop()`)*, CaptchaSecurityImages, the
    SqlConnection class lineage, FeedWriter/FeedItem.
  - Not added: Tabler Icons and Google Fonts (both CDN-loaded, not
    vendored/license-file-tracked like the entries above) — worth a
    follow-up decision on whether CDN-only UI dependencies belong on this
    list at all, rather than adding one without the other.
  - **Same-day follow-up (TODO.md item "FP-24. Replace Standardista Table
    Sorting with Tablesort"):** the "is this still used?" audit above led
    to actually checking Standardista's code, not just its credit —
    turned up a real bug: `sortDate()`'s regex only matched
    `dd/mm/yyyy`/`dd-mm-yyyy`, not ISO (`yyyy-mm-dd`), one of the presets
    FP-21's date-format dropdown offers, so picking it silently broke
    Admin → Entries' Date column sort (fell through to plain string
    comparison). Also missing `aria-sort`/keyboard-accessible sort state
    and a visual indicator. Replaced with **Tablesort**
    (github.com/tristen/tablesort, Tristen Brown, MIT, v5.7.1) — vendored
    at `assets/vendor/tablesort/tablesort.js` (exact upstream source,
    fetched and verified directly, not reconstructed from memory), loaded
    in `admin/header.php` before `fanupdate.js`. Its `data-sort` attribute
    is the actual fix for the date bug: `admin/blog.php`'s Date `<td>` now
    carries `data-sort="<raw stored UTC Y-m-d H:i:s>"`, so sorting is
    correct regardless of which `date_format` preset is configured — the
    display-format ambiguity is gone entirely, not just one regex pattern.
    `class="nosort"` → `data-sort-method="none"` (Tablesort's own marker)
    on the 2 columns that had it, both in `admin/blog.php` — every other
    column's sortability is unchanged, a faithful 1:1 translation rather
    than also auditing which columns *should* be sortable. New
    `afterSort`-event-driven zebra-restripe adapter in `fanupdate.js`
    replaces the deleted `standardista` IIFE (confirmed zero other
    callers before removal) — Tablesort itself has no concept of
    zebra-striping. `assets/css/style.css`'s sortable-header rules scoped
    away from `[data-sort-method="none"]` columns, plus a small
    `aria-sort` arrow indicator. Kept, not removed: the Neil Crosby
    "Standardista" credit in `FU_LIBRARIES` — rescoped to its `css`
    utility object (still used across several admin JS files), separate
    from the new Tablesort entry. New "## Tablesort" section in
    `docs/THIRD-PARTY-LICENSES.md`, matching the Parsedown/TinyMCE format.
  - **Same-day follow-up, caught live on deploy:** reported as literal
    `<input type="checkbox" ...>` markup rendering as visible text on
    Admin → Entries. Root cause: the admin's browser was still running the
    *old* cached `fanupdate.js` (containing the just-deleted Standardista
    code) against the *newly deployed* HTML — its `initHeading()` no
    longer saw `class="nosort"` on the checkbox-select header (replaced
    with `data-sort-method="none"`), so it processed that header and hit
    a pre-existing bug in that dead code
    (`a.appendChild(document.createTextNode(th.innerHTML))` — stringifies
    the cell's markup instead of preserving it). A hard refresh fixed it
    immediately, but the underlying gap — none of `admin/header.php`'s
    CSS/JS asset tags had cache-busting — meant every future deploy risked
    the same failure for any admin who doesn't manually clear cache.
    Fixed: `admin/header.php` now appends `?v=<FU_VERSION>` to
    `style.css`/`style-night.css`/`tablesort.js`/`fanupdate.js`/
    `fanupdate-admin.js`. A new `fuAssetVersion` JS global (alongside the
    existing `fuCsrfToken` one) keeps `fanupdate-admin.js`'s theme-toggle
    logic in sync — it does an exact string match against the CSS
    `<link>`'s href to detect the current theme, which would otherwise
    silently break the moment the href gained a query string.
  - **Also fixed while on that page:** two submit buttons whose visible
    text was literally their raw `$_POST['action']` value —
    `bulk-delete`/`bulk-delete-confirmed` on `admin/blog.php` — read as
    code slugs rather than words (unlike the page's other action values,
    which are at least real if lowercase English words). Swapped
    `<input type="submit">` for `<button type="submit">` for just these
    two, since a `<button>`'s visible text can differ from its submitted
    `value` — "Delete Selected"/"Permanently Delete" — with zero changes
    to the POST-handling dispatch logic (still the same
    `$_POST['action'] === 'bulk-delete'` checks).

- **`FU_URL_REDUX` (`version.php`) now points to
  `https://coding.unloved-heart.net/scripts/fanupdate-redux`**, replacing the
  previous `https://www.unloved-heart.net` placeholder.

- **Removed every reference to the original, now-defunct `prism-perfect.net`
  domain except Jenny Ferenc's own credited link in the Contributors
  section of Admin → Credits** (`FU_CONTRIBUTORS`, `version.php`) —
  explicitly kept, per direct request. Everywhere else:
  - The `<jenny@prism-perfect.net>` email dropped from ~27 files' copyright
    header comments, leaving the `Copyright (c) [year by] Jenny Ferenc
    [(contributor)]` attribution itself untouched — names stay, the dead
    mailbox doesn't.
  - `FU_URL_ORIGINAL` (`'http://prism-perfect.net/fanupdate'`) removed from
    `version.php` entirely. Its two usages: `FanUpdate::$config['url']`
    (feeds the Dashboard's "Documentation & FAQs" link and
    `admin/privacy.php`'s "documentation" link) now points at
    `FU_URL_REDUX` instead of a dead 2008-era page; `admin/credits.php`'s
    "Original project" link (in the Version section, separate from Jenny's
    own Contributors-section entry) is removed outright — no replacement
    URL exists for it, and Jenny's own credited link already covers
    original-author attribution.
  - `install.php`'s comment about removing defunct update-polling keys
    reworded to drop the bare domain mention while keeping the historical
    context.
  - **Same-day follow-up:** removing `admin/credits.php`'s "Original
    project" link surfaced a related gap — `FU_URL_REDUX` (this project's
    own canonical URL) was never actually shown on the Credits page at
    all. Added a "FanUpdate Redux" link (first in the Version section's
    link row, before "Robotess fork") pointing to it.
  - **Same-day follow-up:** Ariane's `FU_CONTRIBUTORS` entry (Contributors
    section, Admin → Credits) still described her work as just "Full PHP
    8.2+ compatibility pass" — accurate when first written, badly stale
    given everything shipped since (Admin Update Manager, Categories
    Support, Fanatically collective-script integration, Media/Image
    Manager, TinyMCE integration, the InnoDB/utf8mb4 migration, security
    hardening, and more). Updated to summarize the current, broader scope
    of ongoing work.
  - `README.md`'s credits section no longer links the domain (keeps
    "Original script by Jenny Ferenc" as plain text).
  - `PHP Test Suite/bootstrap.php` and `phpstan.neon`'s comments (which
    listed `version.php`'s declared constants by name) updated to drop
    `FU_URL_ORIGINAL` from that list, matching its removal.
  - **Deliberately left untouched:** `old-docs/README.md` (and the rest of
    `old-docs/`) — this directory exists specifically to preserve the
    original project's own shipped documentation verbatim for attribution
    (see `build-release.sh`'s packaging notes), so editing its content
    would work against its stated purpose.

### Added

- **New "FanUpdate Redux News" widget on the Dashboard (TODO.md item
  "FP-23. Official FanUpdate Redux News Feed on the Dashboard"),** sourced
  from a new `news_feed_url` option (Admin → Options → Main) — **not
  hardcoded**, defaults to
  `https://coding.unloved-heart.net/update-blog/rss.php?c=4` but fully
  editable, and blank hides the widget entirely. New
  `class/Repository/NewsFeedRepository.php` (JSON cache blob under a
  private `_news_feed_cache` option key) and
  `class/Service/NewsFeedService.php`/`NewsFeedFetcher.php` mirror the
  Admin Update Manager's cache-first architecture
  (`UpdateRepository`/`UpdateManager`) rather than fetching live on every
  Dashboard load: cached items render immediately, a live fetch only
  happens once the cache is stale (1 hour default), and any fetch failure
  (unreachable feed, bad XML, non-2xx status) falls back silently to
  whatever is cached — the Dashboard, the highest-traffic admin page, must
  never hang or show an error because an external site is down.
  `NewsFeedFetcher` is HTTPS-only (rejected outright before any request is
  attempted), cURL-first with a `file_get_contents()`+stream-context
  fallback and a short timeout — same safety posture as
  `class/Update/GitHubUpdateProvider.php`'s request handling, reimplemented
  generically since that logic is private and GitHub-API-coupled.
  `news_feed_url` is seeded for fresh installs via `install.php`'s
  `$setting[]` array and backfilled into existing installs via
  `UpgradeRunner::NEW_DEFAULT_OPTIONS` (the mechanism that constant exists
  for — previously empty for this release cycle). Deleted the old dead
  `FanUpdate::checkUpdates()` method (confirmed unused since the
  GitHub-based Admin Update Manager shipped — see `docs/HISTORY.md`) rather
  than leaving two remote-feed-fetching code paths in the codebase; its
  `<channel><item>`-parsing approach is reused in `NewsFeedFetcher`, fixing
  two real bugs along the way — fatal `->item(0)->nodeValue` access on any
  item missing an expected node is now null-safe, and failures are caught
  as `\Throwable` rather than only `\Exception`.
  Tests: new `NewsFeedRepositoryTest.php`/`NewsFeedServiceTest.php` (mocked
  repository/fetcher — no real database or network call); two new
  `UpgradeRunnerTest.php` cases cover the `news_feed_url` backfill.
  `admin/index.php`'s own rendering is untested, consistent with every
  other top-level admin page in this project (see `TEST_LOG.md`).
  - **Bug fix, same day, caught live before release:** the cache had no
    idea which feed URL it was fetched from, so changing `news_feed_url`
    didn't actually take effect until the previous URL's cache naturally
    expired (up to 1 hour later) — reported live after testing against the
    real feed URL, first with an empty category (correctly showed nothing)
    then switching to a populated one (still showed nothing, because the
    empty result from the first URL was still "fresh"). Fixed: the cache
    now also stores the `url` it was fetched from
    (`NewsFeedRepositoryInterface::saveCache()` gained a `$url` parameter);
    `NewsFeedService::refreshIfDue()` treats a cache belonging to a
    different URL than the one currently requested as a miss regardless of
    its age, forcing an immediate live fetch — and, on that fetch failing,
    returns an empty list rather than surfacing the abandoned URL's stale
    items (less confusing than silently showing another feed's leftovers).
    A pre-fix cache (missing the `url` field entirely) is likewise treated
    as invalid by `NewsFeedRepository::getCache()`, so this self-heals on
    any installation that already triggered the bug — no manual cache
    clear-out needed. Tests: `NewsFeedRepositoryTest` gained a
    missing-`url`-is-invalid case; `NewsFeedServiceTest` gained two cases
    covering a fresh-but-wrong-URL cache being ignored, both on the
    live-fetch-succeeds and live-fetch-fails paths.

- **The `date_format` option (Admin → Options → Main) is now a preset
  dropdown instead of a plain text input (TODO.md item "FP-21. Date Format
  Preset Dropdown").** Following the same pattern
  FP-19 used to replace the timezone option's free-text field with a
  `<select>`. Unlike timezone, there's no fixed enumerable domain of valid
  PHP `date()` format strings — any string is technically valid, since
  unrecognised characters just pass through literally — so a curated
  shortlist of common formats (`fu_date_format_presets()` in
  `functions.php`, each shown with a live example like `M j, Y (Aug 13,
  2026)` via the new `fu_date_format_option_label()`) sits alongside a
  "Custom..." option that unlocks a paired free-text field instead of
  restricting input outright. Rendered on both `admin/options.php`'s Main
  list and `install.php`'s fresh-install form — same two call sites as the
  timezone dropdown, same "the `<select>` is visual-only and the real
  submitted value comes from a paired input" trick `install.php`'s y/n
  toggle already used, needed there because that form's `key[]`/`value[]`
  are positional arrays. New `.fu-date-format-select` handler in
  `assets/js/fanupdate.js` keeps the paired text input in sync (copies the
  selected preset in and makes it read-only; "Custom" unlocks it). No
  database migration needed — `date_format` was always a plain string
  option, so every existing value (preset or not) still displays and works
  correctly via the "Custom" fallback if it doesn't match a preset.
  - **Same-day follow-up, matching timezone's look more closely:** the
    paired text input is now hidden (not just read-only) whenever a known
    preset is selected — same self-contained single-control appearance as
    the timezone `<select>`, no redundant "here's what you picked" field
    shown underneath. Only reveals itself for "Custom" entry. Also:
    `timezone` is now rendered immediately after `date_format` on both
    `admin/options.php`'s Main list (which otherwise sorts purely
    alphabetically — other option keys could land between the two) and
    `install.php`'s fresh-install form (moved `$setting[26]`'s definition
    to sit physically right after `$setting[6]`'s — array key order
    doesn't affect render order, only this insertion/source position
    does), since both are date/time settings that read more naturally
    next to each other.
  Tests: new `DateFormatFunctionsTest.php` covers the new `functions.php`
  helpers (`fu_date_format_presets()`, `fu_date_format_example()`,
  `fu_date_format_option_label()`, `fu_is_known_date_format_preset()`);
  the rendering branches themselves are untested for the same reason
  `admin/*.php`/`install.php` generally are throughout this project (see
  `TEST_LOG.md`).

- **New `reset-password.php` — emergency admin password reset with no login
  required (TODO.md item "FP-22. Emergency Admin Password Reset (Lost
  Password)").** `admin/account.php`'s password change requires the *current*
  password, so there was previously no way to recover a lost admin password
  short of hand-writing a bcrypt hash and running a manual `UPDATE` query
  (still documented in `docs/TROUBLESHOOTING.md` as a fallback). The login
  form had no link to anything of the kind either. The new script follows
  `install.php`'s exact trust model and lifecycle: unauthenticated by design
  (reaching it at all already requires file access next to
  `blog-config.php`), rate-limited by IP via `LoginRateLimiter` (own lockout
  store, separate from the login form's), and auto-deletes itself on
  success. The login form now links to it ("Forgot your password?").
  On any install where `admin_username`/`admin_password` rows already exist,
  reuses `AccountRepository::updatePasswordHash()` — the same code path
  `admin/account.php` uses — so there's exactly one place that writes the
  `admin_password` option row.
  - **Bug fix, same day, caught live before release:** an install that
    predates admin credentials moving into the database (see "Admin
    credentials moved from `blog-config.php` into the database" further
    down this file) has no `admin_password` row at all yet.
    `AccountRepository::updatePasswordHash()` is a plain `UPDATE`, which
    matches zero rows in that case — the page reported success, deleted
    itself, and changed nothing, with no error at any point. Fixed: the
    script now checks `SELECT COUNT(*) ... WHERE optkey = 'admin_password'`
    up front (a direct query, not `getOpt()`, so a row that exists with an
    empty `optvalue` — silently skipped by `addOptFromDb()` — still counts
    as present) and switches to an `INSERT` of both `admin_username` and
    `admin_password` when the row is missing, asking for a username in that
    case since there may be no existing one to keep. No PHPUnit coverage for
    this branch specifically — it's top-level script logic in
    `reset-password.php` itself, same as `install.php`/`admin/*.php`
    throughout this project, as established repeatedly in `TEST_LOG.md`.

- **README's Templates section now documents wrapping `entry_template` in a
  container `<div>` so each post can get its own card in host-page CSS.**
  The default template (`{{title}}`/catfile line/`{{body}}`) has no
  per-post wrapper element, so a host site previously had no documented way
  to give individual entries their own background/border/shadow without
  guessing at sibling-combinator CSS hacks. A one-field `entry_template`
  change from **Admin → Options → Templates** (no code) now gets the same
  result — surfaced live while restyling `coding.unloved-heart.net`'s own
  `/updates` embed.

### Fixed

- **`install.php`'s fresh-install form and its `$setting[]`-driven option
  fields (timezone, date format, every y/n toggle, every plain-text/
  textarea option, plus the `admin_username`/`admin_password`/
  `admin_password (confirm)` credential fields) showed raw option keys
  like `comments_on` and `date_format` as their visible labels**, instead
  of the friendlier "Comments on"/"Date format" styling
  `admin/options.php`'s Main list already got a few weeks back. Root
  cause: `fu_option_label()` (`ucfirst(str_replace('_', ' ', $key))`) only
  ever lived in `admin/options.php` — `install.php` never had access to
  it. Fixed by moving `fu_option_label()` into `functions.php` (removed
  from `admin/options.php`, which now uses the shared copy — same move
  `fu_is_yn_option()` made earlier) and applying it to every visible label
  in `install.php`'s render loop; the hardcoded `admin_username:`/
  `admin_password:`/`admin_password (confirm):` labels (not derived from
  a loop, so `fu_option_label()` doesn't apply) were hand-corrected to
  `Admin username:`/`Admin password:`/`Admin password (confirm):`
  directly. `id`/`name`/`for` attributes are untouched — only visible text
  changed. No test added for `fu_option_label()` itself, consistent with
  `fu_is_yn_option()` (an equally simple helper) also having none.
  - **Same-day follow-up:** `reset-password.php`'s submit button and
    `admin/account.php`'s three submit buttons (Username/Email/Password)
    had the identical problem one level down — the button's `value`
    attribute (its visible text) doubled as the internal action identifier
    checked in PHP, so the buttons literally read `reset_password`/
    `update_username`/`update_email`/`update_password`. Fixed by
    decoupling: each button's own `name` (e.g. `name="reset_password"`,
    checked via `isset($_POST['reset_password'])`) now carries the
    semantics — matching `install.php`'s own convention
    (`isset($_POST['fresh_install'])`) — freeing `value` to be a plain
    human label ("Reset Password", "Save Username", "Save Email", "Save
    Password"). `admin/account.php`'s POST handler was restructured
    slightly (a `match(true)` picking `$accountAction` from which button
    was pressed) since its three forms previously shared one generic
    `name="action"` field.

- **Admin sessions on one FanUpdate Redux install could silently log out
  admins on a *different* install on the same domain, and vice versa —
  reported live as "session keeps expiring" while actively using the admin
  panel.** Root cause: `FanUpdate::setAuthCookie()`/`ensureSession()`
  hardcoded every cookie (`fu_username`, `fu_token`, the `fu_session` CSRF
  cookie) to `path=/`. Two or more separate FanUpdate Redux installs living
  in different subdirectories of the same domain (e.g.
  `example.com/fanupdate-redux/demo/` and `example.com/update-blog/` — a
  normal shared-hosting layout) therefore all shared the exact same three
  cookie slots: logging into one instantly overwrote the cookie the other
  depended on. Fixed with a new `FanUpdate::cookiePath()`, derived from the
  stored `install_url` option (falls back to the previous hardcoded `/`
  when `install_url` isn't set yet, e.g. `install.php`'s very first page
  load, so behaviour is unchanged for that edge case). See
  `docs/TROUBLESHOOTING.md`'s new "I keep getting logged out while actively
  using the admin panel" entry — includes a note that an admin who already
  has an old `path=/` cookie in their browser may need to log out/in once
  (or clear cookies) for the new scoped cookie to fully take over.

- **`show-blog.php`'s category-filtered listing (`?c=`) threw "Duplicate
  column name 'entry_id'" instead of rendering (TODO.md item "FP-20. Fix
  show-blog.php Pagination Query Bug (Duplicate `entry_id` Column)").**
  Its entries query used
  bare `SELECT *` while joining `blog_catjoin` for the `?c=` filter — both
  `blog` and `blog_catjoin` have their own `entry_id` column, so the column
  list was ambiguous. That's normally harmless (MySQL just returns both),
  but `SqlConnection::ExecutePaginate()` wraps the query in
  `SELECT COUNT(*) FROM (...) AS _fu_count_sub` to get the pagination total,
  and a derived table can't have two columns with the same name — so the
  count query itself failed outright. `show-cat.php`'s equivalent query
  already used `SELECT b.*` and never hit this; `show-blog.php` just missed
  the same table qualifier. Reported live after switching
  `coding.unloved-heart.net`'s `/updates` page to `show-blog.php` (to show
  latest posts across all categories, not one at a time) and clicking a
  category link. Fixed: `SELECT *` → `SELECT b.*`.

- **Upgrading to Redux 2.3.0 gave no indication the new Media gallery
  existed at all.** `media_table` (Admin → Media's backing table) is read
  from `blog-config.php`, same as `dbhost`/`collective_table`/etc. — a
  fresh install already has it via `blog-config.sample.php`, but there was
  no way for an existing installation to pick it up on upgrade (unlike
  database-backed settings, which `UpgradeRunner` can migrate
  automatically, a config-file setting can't be safely added to a live PHP
  file by an update). The only place this was ever surfaced was
  `admin/media.php`'s own inline notice — invisible unless an admin
  happened to click into Admin → Media itself, which most wouldn't right
  after an update. Reported live after exactly that happened following a
  2.2.0 → 2.3.0 upgrade. Fixed: `admin/header.php` now shows a
  "Media gallery not configured" banner on every admin page (same
  established pattern as the "install.php still present"/"pending database
  updates" banners) until `media_table` is set and its table created.
  `README.md`'s new "Upgrading" subsection documents the one-line
  `blog-config.php` addition needed.

- **`install.php` was silently reappearing on the server after every
  Admin Update Manager install.** Every release ships `install.php` (it's
  part of the source tree `build-release.sh` packages), but
  `InstallManager::installUpdate()` only copies files onto the live
  installation — it never executes `install.php`, so its own
  self-delete-on-success code never got a chance to run. The result:
  right after installing an update via **Admin → Updates**, a fresh
  `install.php` would be sitting on the server again, quietly
  reintroducing the exact security exposure the Dashboard/Updates
  "install.php still present" notice exists to warn about — reported live
  after updating from 2.2.0 to 2.3.0 through the Admin Update Manager.
  Fixed: `InstallManager` now deletes `install.php` from the live
  installation immediately after copying files over, if the just-installed
  release shipped one. Surfaced in the post-install success message, the
  Updates page's "Last install" summary, and the update log
  (`InstallResult::$installScriptRemoved`, new `UpdateManager`/
  `admin/update.php` plumbing to match). Best-effort, same as the existing
  obsolete-file cleanup — a delete that fails (e.g. a permissions issue)
  is simply left for the existing notice to catch, rather than failing the
  whole install over it.

---

## [Redux 2.3.0] — July 27, 2026 — Database Architecture, Media Manager & TinyMCE Editor

### Added

- **Timezone selection (TODO.md item "FP-19. Replace Timezone Offset with
  Timezone Selection").** Replaces the legacy `timezone_offset` option (a
  fixed hour offset from GMT) with a proper **Timezone** IANA identifier
  (e.g. `Europe/Helsinki`), so displayed dates account for daylight saving
  time automatically instead of drifting by an hour every spring/autumn.
  - New `functions.php` helpers: `fu_timezone_identifiers_grouped()` (every
    identifier PHP's `DateTimeZone::listIdentifiers()` knows, grouped by
    region for `<optgroup>` rendering), `fu_timezone_label()` (human-friendly
    label with the current UTC offset, e.g. "Europe/Helsinki (UTC+02:00)"),
    and `fu_is_valid_timezone()`.
  - **Admin → Options** and `install.php`'s fresh-install form both render
    `timezone` as a searchable `<select>` grouped by region instead of a
    plain text field (same special-case pattern as the existing y/n toggle
    switches). `install.php`'s form pre-selects the browser's own detected
    zone via `Intl.DateTimeFormat().resolvedOptions().timeZone` when it's a
    recognised identifier; `admin/options.php` rejects an invalid value on
    save rather than silently falling back to UTC.
  - `FanUpdate_Post`/`FanUpdate_Comment::getDateFormatted()` now convert the
    stored UTC timestamp via `DateTime`/`DateTimeZone` instead of adding a
    fixed number of hours — this is what actually fixes the DST drift, since
    every date shown throughout the site (post dates, comment dates, the
    admin dashboard and entries list) goes through these two methods.
  - **Migration:** an existing installation's `timezone_offset` is migrated
    automatically, both via `install.php`'s interactive "upgrade from any
    version" block and via `UpgradeRunner` (the non-interactive path the
    Admin Update Manager uses) — whichever the installation actually uses.
    A new `fu_migrate_legacy_timezone_offset()` helper maps the old hour
    offset to a representative IANA zone using a best-effort lookup table
    (`FU_LEGACY_OFFSET_TIMEZONE_MAP`) covering whole-hour offsets -12..+14;
    an offset with no table entry (or no `timezone_offset` at all) defaults
    to `UTC`. Either way, a message asks the admin to check **Options →
    Timezone** afterwards — a fixed offset can't be mapped back to an exact
    zone with certainty, since several zones share any given offset and most
    also observe DST that `timezone_offset` never did. The old
    `timezone_offset` row is deleted once migrated.

- **`admin/get-code.php`'s "CSS Rules" snippet now includes the
  `a.fu-lightbox` image styles** — requested directly, so site owners
  copying this snippet into their own stylesheet get working styling
  (rounded corners, border, hover state, zoom cursor, and the
  `fu-img-left`/`fu-img-right`/`fu-img-center` alignment helpers) for
  images inserted via the post editor, instead of having to find and
  copy them from `assets/css/style.css` themselves. Each rule has a short
  comment explaining what it does. Also removed the snippet's stale
  `.wysiwygmenu {}`/`.wysiwygmenu a {}` stub, left over from the front-end
  comment form's WYSIWYG toolbar — since that toolbar was removed earlier
  this session, the stub no longer styles anything that exists.

- **TinyMCE post editor (TODO.md item "FP-18. Implement TinyMCE Editor").**
  **Admin → Entries**' post-body editor is now a self-hosted TinyMCE 7.9.3
  instance, replacing the previous hand-rolled WYSIWYG toolbar.
  - Vendored via `npm pack tinymce@7` into `assets/vendor/tinymce/`
    (~1.6 MB trimmed: core, the `silver` theme, `dom` model, `default`
    icons, `oxide`/`oxide-dark` UI skins, `default`/`dark` content skins,
    and the `lists`/`link`/`table`/`code`/`fullscreen`/`autoresize`/
    `charmap` plugins) — no CDN, no Tiny Cloud account, `license_key:
    'gpl'` for self-hosted GPL use. New `docs/THIRD-PARTY-LICENSES.md`
    entries for TinyMCE (GPL-2.0-or-later) and the DOMPurify it bundles
    internally (MPL-2.0/Apache-2.0).
  - New `assets/js/fanupdate-tinymce.js` initialises the editor on
    `admin/blog.php`'s `#body` textarea only — the only page that had the
    previous WYSIWYG toolbar. Toolbar/plugins cover every item TODO.md's
    FP-18 asked for; `paste`/`hr` have no separate plugin as of TinyMCE
    6+ (folded into core), so there was nothing to vendor for either.
  - A custom `fuimage` toolbar button (not the stock `image` plugin) opens
    an Upload New/Choose Existing dialog reusing `admin/upload.php` and
    `admin/media.php` (TODO.md item "4. UI - Create an Image/File
    Manager") — the same UI/CSS the previous editor's image button used.
    Drag-and-drop/pasted images are handled separately via
    `images_upload_handler`, core TinyMCE behaviour needing no plugin.
  - Editor skin (`oxide`/`oxide-dark`) and content CSS follow the saved
    night-theme preference at page-load time; height is configurable via
    new `editor_min_height`/`editor_max_height` options (advanced, not
    exposed in Admin → Options — same precedent as
    `upload_max_filesize`/`upload_thumb_width`).
  - `tinymce.triggerSave()` on the form's submit event keeps native form
    submission working exactly as before; with JavaScript disabled the
    real `<textarea id="body">` TinyMCE always leaves in the DOM behaves
    as a plain textarea, same as before this change — no separate
    fallback code needed.
  - **Removed** as dead code (no other callers, confirmed via a
    full-codebase search): the hand-rolled admin WYSIWYG toolbar
    (`myWysiwyg`/`fuImageButtonClick()`/`fuBuildImageModal()`/
    `fuUploadImage()`/etc., previously in `assets/js/fanupdate-admin.js`),
    `class/FanUpdate.php`'s `printWysiwyg()` no-op stub, and the
    `.wysiwygmenu` toolbar CSS in `assets/css/style.css`/`style-night.css`.
    `assets/js/fanupdate.js`'s shared `makeWysiwyg()`/`wysiwygButton()`/
    `formatText()`/`insertLink()` were kept — they're still used by the
    public comment form's own separate toolbar
    (`assets/js/fanupdate-comment-submit.js`), unrelated to this change.

- **Media gallery: browse, manage, and select uploaded images (TODO.md item
  "4. UI - Create an Image/File Manager").** New **Admin → Media**
  (`admin/media.php`): a paginated thumbnail grid of every image ever
  uploaded through the WYSIWYG editor, with per-image delete.
  - New `media_table` (`blog_media` by default), recorded in via new
    `MediaRepository`/`MediaRepositoryInterface`/`MediaService` classes.
    `admin/upload.php` now inserts one row per successful upload
    (filename, uploads/-relative file/thumb paths, dimensions, size, MIME
    type, timestamp) alongside its existing file-save/thumbnail-generation
    work — indexed DB lookups instead of a filesystem scan, to hold up
    under CLAUDE.md's "tens of thousands of images" performance target.
  - `SchemaMigrator::ensureMediaTable()` creates the table for existing
    installations (via Admin → Updates) the same way
    `ensureStandaloneCategoryTable()` already does for Categories Support,
    except unconditionally rather than gated on `collective_script` — every
    installation gets a Media gallery regardless of collective-script
    configuration.
  - Deletion removes the original file, its thumbnail, and the DB row, with
    a full confirm-then-POST view (same nested-form-avoidance pattern as
    `admin/blog.php`'s single-entry delete). Deliberately does not check
    whether the image is still referenced by a post — that would need a
    non-indexed `LIKE` scan over every post body; a post that already
    embedded a deleted image just ends up with a broken `<img>` src, same
    as manually deleting a file from `uploads/images/` always could.
  - The WYSIWYG "insert image" toolbar button now opens a modal with
    **Upload New** (the existing upload flow, unchanged) and **Choose
    Existing** tabs — the latter fetches `admin/media.php?action=list`
    (JSON, paginated) and inserts the selected image directly, no upload
    needed. New `.fu-image-modal*`/`.media-grid`/`.media-item` CSS in both
    `assets/css/style.css` and `style-night.css`.
  - New **Media** entry in the admin nav (`admin/header.php`), between
    Categories and Options.

- **Copy buttons for Display Code / CSS Rules output (TODO.md "3. UI / UX").**
  **Admin → Categories/Entries → "Get Code"** (`admin/get-code.php`) now
  shows a **Copy** button below both the PHP display-code snippet and the
  CSS rules block, so an admin can copy either directly to the clipboard
  instead of manually selecting the textarea contents. New generic
  `fuCopyButtonInit()`/`fuCopyButtonClick()` in
  `assets/js/fanupdate-admin.js` wire up any `.button.fu-copy-btn` element
  via its `data-copy-target` textarea id, using `navigator.clipboard
  .writeText()` where available (secure contexts) and falling back to
  `textarea.select()` + `document.execCommand('copy')` otherwise, with a
  brief "Copied!" label swap for feedback. Reusable as-is for any future
  textarea-output page.

- **Full GitHub Flavored Markdown rendering for release notes (TODO.md item
  "8. Implement Full Markdown Rendering for GitHub Release Notes").**
  **Admin → Updates**' "Latest release" panel previously rendered a
  release's raw GitHub Markdown body via `htmlspecialchars()` followed by
  the legacy WordPress-derived `wpautop()`/`doHeaders()`/`doLists()`
  formatter (`functions.php`) — that pipeline only ever produced
  paragraphs, headers, lists, and auto-linking, so `**bold**`, `_italic_`,
  inline code, fenced code blocks, tables, blockquotes, task lists,
  images, reference-style links, strikethrough, and horizontal rules all
  displayed as plain, unformatted text.
  - New `class/Parsedown.php` — Parsedown 1.8.0 vendored directly
    (unmodified, MIT-licensed — see `docs/THIRD-PARTY-LICENSES.md`), the
    same "embed the library's own file directly in `class/`" approach
    already used for `class/FeedWriter.php`'s Universal Feed Writer,
    since the production app assumes no Composer/CLI at runtime on
    shared hosting. Parsedown natively covers headers, emphasis, inline
    code, fenced code blocks with language identifiers, tables,
    blockquotes, links (including reference-style and bare-URL
    autolinking), images, horizontal rules, and strikethrough — i.e.
    everything the TODO item asked for except GFM task-list checkboxes,
    which aren't part of upstream Parsedown by the maintainer's own
    choice (outside the CommonMark spec it targets).
  - New `class/Service/ReleaseNotesRenderer.php` wraps Parsedown with
    **safe mode always enabled** (raw HTML in release notes is dropped
    entirely rather than passed through; link/image destinations outside
    a scheme allow-list have their `:` neutralised to `%3A` so a
    `javascript:` URL can never execute) and GFM-style single-newline
    line breaks. Adds GFM task-list checkboxes
    (`- [ ]`/`- [x]`/`- [X]`) as a small, safe HTML post-processing step
    of its own (`renderTaskListCheckboxes()`) rather than patching the
    vendored file — the replacement markup is a fixed literal the method
    controls, never built from the Markdown source, so it can't be used
    to smuggle attacker-controlled HTML past safe mode. Handles both
    "tight" and "loose" list rendering shapes. Malformed Markdown can't
    actually throw in Parsedown (it always produces a best-effort
    interpretation — unmatched brackets/asterisks fall back to literal
    text, an unterminated fence renders through the rest of the input,
    etc.), so the try/catch fallback to escaped plain text is defence in
    depth only, exercised in tests via an injectable stub.
  - `admin/update.php`'s release-notes block now calls
    `ReleaseNotesRenderer::renderToHtml()` directly on the raw (not
    pre-escaped) notes — the renderer does its own escaping internally.
    The container gained a `fu-release-notes` class for the new CSS
    below; nothing else about the page changed.
  - `assets/css/style.css`/`style-night.css`: new `.fu-release-notes`
    rules for blockquotes, inline-code background (distinct from the
    panel's own surface colour so it stays visible), constrained image
    width, table cell borders, and GFM task-list checkbox layout —
    mirrored identically in both stylesheets per this project's existing
    "night theme duplicates all structural rules" convention.
  - New `docs/THIRD-PARTY-LICENSES.md` recording Parsedown's MIT license
    text (required by the license) alongside a note on Universal Feed
    Writer's existing in-file attribution.
  - Deliberately unrelated to blog-post rendering: `functions.php`'s
    `wpautop()`/`doHeaders()`/`doLists()` are untouched and still used for
    actual blog post/comment display — this change only replaces how
    *GitHub release notes* are rendered on the Updates admin page.
  - Tests: new `PHP Test Suite/Unit/Service/ReleaseNotesRendererTest.php`
    — headers, bold/italic, inline code, fenced code with a language
    identifier, tables (including column alignment), task lists (tight
    and loose lists, both `[x]` and `[X]`), nested lists, blockquotes,
    links (inline, reference-style, and bare-URL autolinking), images,
    horizontal rules, strikethrough, a mixed multi-feature document,
    malformed Markdown (both Parsedown's own best-effort handling and the
    injectable-stub fallback path), several XSS payloads (`<script>`, an
    `onerror` attribute, `javascript:` URLs in both a link and an image),
    and a large-input performance check. **Confirmed passing on Ariane's
    machine on the first run: 351 tests, 1060 assertions, zero failures**
    (up from the 328/1008 baseline — +23 tests, +52 assertions, matching
    the new test methods) — see `PHP Test Suite/TEST_LOG.md`'s July 18,
    2026 entry.

- **Integrated Mode: Fanatically (TODO.md item "6. Categories Support" →
  "Integrated Mode: Fanatically").** Fanatically's `fanatic_listings`
  table (`id`, `subject`, among others — the fanlistings this collective
  actually owns) fits the generic collective-table shape (`col_id`/
  `col_subj`) the hybrid categories model already supports, so no
  Fanatically-specific query code was needed — category listing,
  selection, and presentation are already always live
  (`findById()`/`listAll()`/`listForDropdown()` query the collective
  table directly on every call). What this change actually adds is
  detecting and cleaning up the one thing that CAN go stale: a
  FanUpdate-side settings row (template/gravatar) for a category that's
  since been deleted upstream.
  - **Correction (caught live, same day):** this entry and the config
    it documented originally pointed at Fanatically's `fanatic_categories`
    table instead — that table is Fanatically's own site-wide list of
    every category across the whole directory (Actors, Movies, Music,
    ...), unrelated to which fanlistings any one collective actually
    owns. Pointing `collective_table` at it pulled in every category in
    the whole Fanatically directory instead of just this site's own
    fanlistings. Every reference below, and everywhere else in this
    codebase, has been corrected to `fanatic_listings`/`id`/`subject`.
  - `blog-config.sample.php` documents the Fanatically shorthand (`'f'`)
    and a new `$coltable['f']` entry (`id` = `id`, `subject` = `subject`)
    — `parent_id` (Fanatically's own subcategory support) is deliberately
    not read; every category is treated as a flat list, same as
    Enthusiast.
  - New `CategoryRepository::listOrphanedCollectiveCategoryIds(): array` —
    a single indexed `LEFT JOIN` (catoptions → collective table,
    excluding the standalone ID space) returning every collective-space
    `cat_id` with a settings row here but no matching row upstream.
    Returns `[]` without querying anything when no standalone table is
    configured (`collective_script = 'n'`), since there's no external
    source to fall out of sync with in that mode.
  - `CategoryService` gained `hasOrphanedCollectiveCategories()` (cheap
    boolean check, same "safe on every page load" bar as
    `SchemaMigrator::hasPendingSchemaChanges()`/`UpgradeRunner::
    hasPendingUpgrades()`), `listOrphanedCollectiveCategoryIds()`, and
    `confirmDeleteOrphanedCategory(int $catId): bool` — re-checks the
    category is genuinely still orphaned before deleting its settings
    row, so a stale/forged request can't remove a still-live category's
    settings. Deliberately does NOT touch `catjoin_table` (post-to-
    category assignments) — existing blog entries stay filed under the
    deleted category's ID, matching how a live collective-sourced
    category's deletion already leaves `catjoin_table` alone.
  - **`admin/index.php`** (Dashboard): new notice, same pattern as the
    existing update/pending-database-updates notices, shown whenever any
    category is orphaned — links to `admin/category.php#orphaned-categories`.
  - **`admin/category.php`**: new section (above the category list, only
    when a collective script is configured and something's orphaned)
    listing each orphaned category by ID (its name isn't known locally
    once it's gone upstream) with its own CSRF-protected "confirm delete"
    button and a JS `confirm()` prompt. New `confirm-delete-orphan` POST
    action.
  - Generic, not Fanatically-specific — the same mechanism will cover
    Enthusiast/Listing Admin's own Integrated Mode sections once those
    are picked up, since nothing here is tied to which script the
    collective table belongs to.
  - Tests: new `CategoryRepositoryTest`/`CategoryServiceTest` coverage —
    empty-array short-circuit without a standalone table, the LEFT JOIN
    query shape (including the standalone-ID-space exclusion), multiple
    orphaned IDs, `confirmDeleteOrphanedCategory()`'s re-check refusing a
    non-orphaned ID without touching the repository, and the delete-
    fails case. **Confirmed passing on Ariane's machine on the first
    run: 328 tests, 1008 assertions, zero failures** (up from the
    319/989 baseline) — see `PHP Test Suite/TEST_LOG.md`'s July 18,
    2026 entry.

- **Separate-database support for the collective script (TODO.md item
  "11. Database Architecture").** FanUpdate no longer strictly requires
  its collective/fanlisting script (Enthusiast, etc.) to live in the same
  database — `collective_table` can now be schema-qualified in
  `blog-config.php` (e.g. `enthusiast_db.owned`) for a collective script
  on a **different database on the same MySQL server**, which is the
  common shared-hosting case (one MySQL server, a small quota of separate
  databases) rather than needing one database per script.
  - No query-building changes were needed: every existing table
    reference is built via plain string concatenation with no
    backtick-wrapping of the whole identifier, so `otherdb.tablename`
    already works correctly in every `JOIN` as-is.
  - The one place that didn't already work unchanged: `install.php`'s
    collective-table existence check used `SHOW TABLES LIKE`, which is
    scoped to the connection's current database and would never find a
    schema-qualified table. New `fu_table_exists()` in `functions.php`
    replaces it with an `information_schema.TABLES` lookup when the
    configured name contains a `.`; a bare table name keeps using the
    original `SHOW TABLES LIKE` check, unchanged.
  - Requires the configured `dbuser` to have been **GRANTed privileges on
    the second database too** — a hosting-side prerequisite this can't
    automate, only surface a clearer error message for if it's missing.
  - `dbhost`/`dbuser`/`dbpass`/`dbname` are unaffected — FanUpdate still
    makes exactly one database connection; only `collective_table` can
    point at a different schema on that same server.
  - **Genuinely separate MySQL servers remain unsupported** — several
    places (`CategoryRepository::findById()`/`listAll()`,
    `show-blog.php`'s category-archive query) run direct SQL `JOIN`s
    between FanUpdate's own tables and the collective table in one
    query, which isn't possible across servers. Supporting that would
    mean breaking `SqlConnection`'s singleton design and rewriting those
    JOINs into separate application-level queries — a much larger,
    higher-risk change not undertaken here; see TODO.md's investigation
    notes for the full reasoning.
  - Documented in `blog-config.sample.php` (inline comment on
    `collective_table`) and `README.md`'s new "Separate-database
    support" subsection under Collective integration.
  - Not covered by automated tests — `functions.php` isn't loaded by
    the PHPUnit bootstrap (it registers a global `set_error_handler()`
    at file scope) and `install.php` has no PHPUnit coverage, same as
    every other change touching either file. Not yet manually verified
    live against a real second database.

- **Dashboard/Updates security notice when `install.php` is still present
  (TODO.md item "13. Dashboard").** `install.php` deletes itself
  automatically after a successful fresh install or upgrade, but that
  self-deletion can fail silently (e.g. a non-writable directory) — left
  in place, it's a real security risk, since anyone who finds the URL can
  attempt to run it against a live installation.
  - New `fu_install_script_exists(string $installPath): bool` in
    `functions.php` — a single `is_file()` check (guarded against an
    empty path), used by both pages below.
  - **`admin/index.php`** (Dashboard): a red notice at the very top of the
    page — above the update-available and pending-database-updates
    notices, since this is the highest-priority one — linking to
    `admin/update.php#install-php-notice` for the full detail.
  - **`admin/update.php`**: the matching detailed notice (naming the full
    resolved path) right after the success/error messages, before the
    existing maintenance-mode notice.
  - Both reuse the same `$install_path` resolution (`getOpt('install_path')`,
    falling back to `dirname(__DIR__)`) each page already computes for its
    other needs (backup/update-staging directories on `admin/update.php`;
    the legacy `collective_script` migration on `admin/index.php`).
  - Not covered by automated tests — `functions.php` isn't loaded by the
    PHPUnit bootstrap at all (it registers a global `set_error_handler()`
    at file scope, which would affect the whole test suite if required
    there), and `admin/*.php` pages have no PHPUnit coverage in this
    project, same as every other admin-page-only change. Not yet manually
    verified live.

- **Move collective-script configuration from `blog-config.php` into the
  database (TODO.md item "12. Move collective-script configuration from
  blog-config.php into the database").** `collective_script` (which
  collective/fanlisting script, if any, this installation is paired with)
  and its `col_id`/`col_subj` column-name mapping — previously hardcoded
  in `blog-config.php`'s `$fanupdate`/`$coltable` globals, requiring a
  file edit to change — now live in the options table as plain rows,
  editable via **Admin → Options** like every other runtime setting.
  `dbhost`/`dbuser`/`dbpass`/`dbname` and the table-name settings
  (`collective_table`, `blog_table`, etc.) are unchanged and stay in
  `blog-config.php`, since the database connection itself has to exist
  before any DB-stored config can be read.
  - **Descriptive identifier, not shorthand:** `collective_script` is now
    stored as `none`/`enthusiast`/`fanatically`/`listingadmin` (or a
    custom string for an unlisted integration) instead of the legacy
    single-letter shorthand (`n`/`e`/etc.). New
    `FanUpdate::normalizeCollectiveScriptIdentifier()` (public static, a
    pure string transform — same "no singleton dependency" precedent as
    `UpdateManager::normalizeVersion()`) converts the legacy shorthand;
    an already-descriptive value or an unrecognised custom identifier
    passes through unchanged.
  - **New `FanUpdate::hasCollectiveScript(): bool`** replaces every
    `getOpt('collective_script') !== false`/`(bool) getOpt('collective_script')`
    truthy check across `admin/category.php`, `admin/blog.php`,
    `admin/index.php`, `admin/update.php`, and `install.php` —
    `getOpt()`'s generic y/n-string truthy mapping only special-cases
    `'n'`/`'no'`/`'y'`/`'yes'`/`0`/`1`, so it would not have treated the
    new `'none'` value as falsy the way it always treated `'n'`.
  - **`class/FanUpdate.php`'s constructor no longer touches
    `blog-config.php`'s `$coltable` global at all.** A new private
    `resolveCollectiveScriptConfig()`, called from `addOptFromDb()` once
    DB-backed options are loaded, prefers the options-table-backed
    `collective_script`/`col_id`/`col_subj` values and falls back
    read-only to the legacy `blog-config.php` globals only for an
    installation that hasn't gone through the one-time migration below
    yet — so deploying this code doesn't silently break an
    Enthusiast-integrated site's category queries before that migration
    has actually run.
  - **Migration path for existing installations:** `UpgradeRunner`
    gained three new optional, nullable constructor parameters
    (`$legacyCollectiveScript`/`$legacyColId`/`$legacyColSubj` — fully
    backward compatible, every existing call site and test constructing
    it without them is unaffected) and a
    `collectiveScriptMigrationPending()`/`migrateLegacyCollectiveScript()`
    pair. This migration is independent of `_db_version`, the same as
    the item 10 schema migration — an installation already on the
    current version still needs it to run exactly once. `admin/update.php`
    and `admin/index.php` pass the raw `$fanupdate['collective_script']`/
    `$coltable` globals in (available there since `blog-config.php` is
    required at the top of both files), so `hasPendingUpgrades()`/
    `runPendingUpgrades()` pick this migration up alongside the schema
    migration and option/version housekeeping, through the same
    Dashboard notice and Database Updates section item 10 already built.
  - **Fresh installs** get the same three keys seeded directly:
    `install.php` gained `$setting[29..31]` (`collective_script`,
    `col_id`, `col_subj`, sourced from `blog-config.php`'s legacy values
    the same way every other setting is seeded), shown as editable
    fields on the install form and written into the options table
    through the existing generic settings-insertion loop — no special-
    cased write path needed.
  - **`blog-config.sample.php`'s `collective_script`/`$coltable` entries
    are marked deprecated in comments, not removed** — they're still
    read once (by `install.php` on a fresh install, or by
    `UpgradeRunner`'s migration for an existing one) but the running
    application never reads them again afterward.
  - Tests: 7 new tests in `PHP Test Suite/Unit/Service/UpgradeRunnerTest.php`
    covering `hasPendingUpgrades()`/`runPendingUpgrades()`'s new pending
    state (a legacy value supplied and not yet migrated), not-pending
    once migrated or when no legacy value was supplied at all (backward
    compatible with every pre-existing test in the file), the shorthand-
    to-descriptive normalisation, and that `col_id`/`col_subj` are only
    written when a legacy value for each was actually supplied.
    **Confirmed passing on Ariane's machine on the first run: 319 tests,
    989 assertions, zero failures** (up from the 312/977 baseline — +7
    tests, +12 assertions, matching the new test methods) — see
    `PHP Test Suite/TEST_LOG.md`'s July 18, 2026 entry. `FanUpdate`
    itself (the constructor change, `hasCollectiveScript()`,
    `resolveCollectiveScriptConfig()`, `normalizeCollectiveScriptIdentifier()`)
    and `install.php`'s new seeded settings are not covered by automated
    tests — same as every other top-level/singleton file in this
    codebase — and not yet manually verified live.

- **Admin-Panel Database Update Runner (TODO.md item "10. Admin-Panel
  Database Update Runner").** Manual file-upload deployments — uploading
  a zip instead of going through the Admin Update Manager's GitHub-release
  flow, exactly how the item 9 InnoDB/utf8mb4 migration was actually
  deployed to production — now have a persistent, discoverable way to run
  pending database migrations from the admin panel, instead of needing
  `install.php` (which self-deletes after a successful run) re-uploaded
  every time.
  - **`class/Service/SchemaMigrator.php` (new)** — the schema-migration
    logic previously inline in `install.php` (`fu_table_has_mis_encoded_utf8()`/
    `fu_migrate_table_engine_and_charset()`, and the Categories Support
    `standalone_category_table` creation block) extracted into a proper
    class, so there's exactly one copy shared between `install.php` and
    the admin panel. New `hasPendingSchemaChanges(): bool` — a cheap
    `information_schema`-only check, no writes — and
    `runPendingSchemaChanges(?callable $onProgress = null): array`,
    which accepts the same `callable(string, int, int): void` progress
    pattern `InstallManager`/`RollbackManager` already use.
  - **`UpgradeRunner`** gained an optional, nullable `SchemaMigrator`
    constructor dependency (fully backward compatible — every existing
    call site and test constructing it with just a repository is
    unaffected) and a new `hasPendingUpgrades(string $targetVersion):
    bool`, true when `_db_version` is behind *or* the injected
    `SchemaMigrator` reports pending schema changes. `runPendingUpgrades()`
    now runs whichever of the two is actually pending — critically,
    schema migration now runs even when `_db_version` is already current,
    which is exactly the case for a manual-upload deployment that never
    touched that option at all.
  - **`UpdateManager`** gained `runPendingUpgrades(UpgradeRunner
    $upgradeRunner, string $targetVersion): array`, wrapping the call
    with the same `reportProgress()`/`logEvent()` pattern
    `installLatestRelease()`/`restoreBackup()` already use (new `'upgrade'`
    log type) — so the existing `?action=install-progress` JSON endpoint
    and its client-side polling script needed no changes at all to work
    for this too.
  - **`admin/update.php`**: new "Database Updates" section (a CSRF-protected
    "Run pending database updates" button + the same progress-bar/polling
    markup pattern the confirm-install/confirm-rollback pages use),
    completely hidden when nothing is pending. The existing
    `?action=run-upgrades` redirect step (run automatically right after a
    GitHub-release install) now goes through `UpdateManager::
    runPendingUpgrades()` too, instead of calling `UpgradeRunner` directly
    — same behaviour, now also logged and progress-reported like every
    other update action.
  - **`admin/index.php`** (Dashboard): new notice, alongside the existing
    cached update-availability notice, shown whenever
    `UpgradeRunner::hasPendingUpgrades()` is true — links straight to
    `admin/update.php`'s new section. Read-only check
    (`information_schema` reads + one option read), same "cheap enough
    for every Dashboard load" bar as the existing notice.
  - `install.php` itself is unaffected in behaviour — it now calls the
    same extracted `SchemaMigrator` instead of duplicating the logic
    inline, but still handles fresh installs and the "upload install.php
    directly" path exactly as before. The admin-panel route is the new,
    additional way to reach the same migrations for an already-running
    installation.
  - Tests: new `PHP Test Suite/Unit/Service/SchemaMigratorTest.php`
    (hasPendingSchemaChanges()'s no-op/pending detection, the standalone-
    vs-collective-script branching, the mis-encoded-UTF-8 reinterpretation
    path, step/progress reporting), and new tests appended to
    `UpgradeRunnerTest`/`UpdateManagerTest` for the `SchemaMigrator`
    integration and `UpdateManager::runPendingUpgrades()` respectively —
    not yet confirmed passing on Ariane's machine; see
    `PHP Test Suite/TEST_LOG.md`'s pending entry.

- **Database Engine & Charset Migration: MyISAM → InnoDB, latin1 →
  utf8mb4 (TODO.md item "9. Database Engine & Charset Migration").**
  Every FanUpdate-owned table is now created as `ENGINE=InnoDB` with
  `utf8mb4`/`utf8mb4_general_ci` on a fresh install, and an existing
  installation is migrated to the same on its next upgrade run —
  combining both changes into one `ALTER TABLE` per table, since either
  change alone triggers a full table rebuild and doing them separately
  would rebuild every table twice for no benefit.
  - **Why it matters:** `MyISAM` (the engine every table used until now,
    on both fresh installs and confirmed via a live installation's
    schema) has no transaction support, so `CategoryService`'s and
    `BlogService`'s `beginTransaction()`/`commit()`/`rollBack()` calls
    (added during the Final Security & Code Quality Audit specifically
    for data-integrity reasons) were silent no-ops — every statement
    auto-committed immediately regardless of a later rollback. This was
    a contributing factor in the `catoptions`/`catjoin` bug fixed
    earlier the same day (see the entry below): the failed settings
    write should have rolled back the category's identity insert too,
    but MyISAM had no rollback to perform.
  - **Charset:** every table except `blog_category` defaulted to
    `latin1_swedish_ci` (confirmed via the same live installation), even
    though the project targets modern multi-byte content (emoji,
    non-Latin scripts). Folded into the same migration rather than a
    separate future pass, since both changes touch the same `ALTER
    TABLE` statement.
  - **Mis-encoded UTF-8 safety:** a plain `CONVERT TO CHARACTER SET` is
    only correct if the existing `latin1`-declared columns genuinely
    contain latin1 bytes. This codebase ran on raw `mysqli` before the
    Redux 2.1.0 PDO migration, and it's a common failure mode for an
    older PHP app's *connection* to have sent UTF-8 while the *column*
    stayed declared latin1 — the raw bytes get stored as-is, without
    transcoding. A plain `CONVERT` in that case would double-encode
    already-UTF-8 bytes into mojibake. `install.php`'s new
    `fu_table_has_mis_encoded_utf8()` scans each table's text columns
    (up to 2,000 rows) for a value that is both non-ASCII and valid
    UTF-8 — vanishingly unlikely to occur by chance in genuine latin1
    text — and when found, `fu_migrate_table_engine_and_charset()` uses
    a binary-intermediate reinterpretation (`MODIFY` to `VARBINARY`/
    `BLOB` first, then back to `VARCHAR`/`TEXT CHARACTER SET utf8mb4`)
    instead of a plain `CONVERT`, so the existing bytes are reinterpreted
    rather than transcoded a second time.
  - `fu_migrate_table_engine_and_charset()` is idempotent — it checks
    `information_schema.TABLES` first and is a safe no-op on a table
    that's already InnoDB/utf8mb4, or that doesn't exist at all (e.g.
    `standalone_category_table` on a standalone-only install). Runs on
    every fresh install and upgrade submission, same pattern as the
    Categories Support table-creation block.
  - `collective_table` is migrated only in standalone mode
    (`collective_script = 'n'`) — when an external collective script is
    configured, that table belongs to the external script, not to
    FanUpdate, and altering its engine/charset out from under it is out
    of scope.
  - `docs/DATABASE.md`'s transaction section now states the InnoDB
    requirement explicitly rather than leaving it an unstated
    assumption.
  - Not yet covered by automated tests — `install.php` has no dedicated
    PHPUnit coverage (it's a top-level procedural script, not a
    class/service), so this lands without a test-count change. See
    TODO.md's Testing section for the still-open live-database
    smoke-testing item this should eventually be verified against.

- **Categories Support — hybrid categories, standalone + collective side
  by side (TODO.md item "6. Categories Support").** Standalone
  (FanUpdate-managed) and collective-sourced (synced from an external
  collective script) categories now coexist in the same installation
  instead of "standalone" and "integrated" being mutually exclusive
  modes. This is the foundational data-model/service/UI increment; the
  per-script sync adapters (Fanatically/Enthusiast/Listing Admin —
  refresh-from-source, upstream-delete confirmation) are separate,
  not-yet-built follow-ups.
  - `CategoryRepository`/`CategoryRepositoryInterface` gained a hybrid
    design: a new, always-FanUpdate-owned `standalone_category_table`
    (only actually created/used when a collective script is configured)
    holds admin-created categories, with IDs shifted into their own space
    via the new `CategoryRepository::STANDALONE_ID_OFFSET` constant so
    they can never collide with the collective table's native IDs. When
    no collective script is configured, every new method
    (`insertStandalone()`/`updateStandaloneName()`/`deleteStandalone()`/
    `isStandaloneId()`) transparently delegates to the original
    single-table methods — a plain standalone installation's SQL and
    behaviour are unchanged from before this feature.
  - `CategoryService`'s business rule is now per-category rather than
    page-wide: `createCategory()` always creates a standalone category
    (regardless of whether a collective script is configured);
    `updateCategory()` only renames a category when it's standalone
    (settings like templates/gravatar stay editable for any category, as
    before); `deleteCategory()` refuses outright, touching nothing, for a
    collective-sourced category.
  - `admin/category.php`'s list shows a "Source" column
    (Standalone/collective-script badges) whenever a collective script is
    configured; the edit form shows the name field as read-only text for
    a collective-sourced category instead of an editable input.
  - `admin/blog.php`'s category dropdown/filter now delegate to the new
    `CategoryService::listForDropdown()` instead of querying the
    collective table directly, so both sources appear together there too
    (with a "(Standalone)"/"(Collective)" label suffix in hybrid mode).
  - New `standalone_category_table` option in `blog-config.sample.php`
    (default `blog_category_standalone`); `install.php` creates it
    idempotently (via a `SHOW TABLES LIKE` guard) on both fresh installs
    and upgrades whenever a collective script is configured, seeding its
    `AUTO_INCREMENT` past `STANDALONE_ID_OFFSET`.
  - Tests: `CategoryRepositoryTest`/`CategoryServiceTest` gained hybrid-mode
    coverage (ID-offset boundary, delegation-when-no-standalone-table,
    findById/listAll branching, dropdown array shape, per-category
    standalone/collective business-rule enforcement) alongside the
    original Milestone 4 tests, which continue to pass unmodified for the
    no-standalone-table (plain standalone installation) case.

### Changed

- **Admin panel header logo now reads "FanUpdate Redux"** (was
  "FanUpdate") — requested directly. `admin/header.php`'s `<h1>` gained
  " Redux" after the existing "Fan"/"Update" split; shows on every admin
  page including the login form, since they all share this one header.

- **"`install.php` still present" and "Pending database updates" now show
  as global banners on every admin page, not just the Dashboard.**
  Requested directly — previously an admin who logged in, missed the
  Dashboard's one-time notice, and went straight to e.g. Entries would
  never see either warning again until their next Dashboard visit.
  - New `fu_build_upgrade_runner(FanUpdate $fu): UpgradeRunner` in
    `functions.php` — extracts the SchemaMigrator + UpgradeRunner
    construction (including the legacy collective_script/col_id/col_subj/
    collective_table one-time-migration values) that `admin/index.php` and
    `admin/update.php` each used to duplicate, with comments on each
    pointing at the other's "identical construction" as the reason to keep
    them in sync by hand. Now built once, in one place; both callers (and
    the new global banner) use it.
  - `admin/header.php` (included by every `admin/*.php` page) renders both
    banners right after `<main>` opens, gated on `isLoggedIn()` — which
    never redirects, so it's safe to call unconditionally, and guarantees
    neither banner ever renders on the login form itself or during
    `install.php`'s own execution (which never has valid auth cookies), so
    these DB-touching checks never run before an admin is actually
    authenticated.
  - `admin/update.php` is excluded from the global banner: it already shows
    its own richer, detail-page version of both notices (exact install
    path, inline "Review & run now" action), so the generic summary banner
    would just duplicate it there.
  - `admin/index.php`'s own copies of both banners (and the SchemaMigrator/
    UpgradeRunner construction that powered the pending-upgrades one) are
    removed — the Dashboard now gets both from the same global banner as
    every other page, instead of a second, redundant rendering.
  - Full suite: **384 tests, 1143 assertions, all passing, zero failures**
    — identical count, as expected; none of `install.php`/`admin/header.php`/
    `admin/index.php`/`admin/update.php` have PHPUnit coverage (untested
    procedural pages, as established repeatedly in this log), and
    `fu_build_upgrade_runner()`'s construction is unchanged from what
    `UpgradeRunnerTest` already exercises via `UpgradeRunner` directly.

- **Admin credentials (`admin_username`/`admin_password`) moved from
  `blog-config.php` into the database — plain `options` table rows, same as
  every other setting.** Requested directly, and a clean design choice for a
  script not yet released (no existing installs to migrate): file-based
  credential storage was the underlying cause of the "logged out on every
  click after a fresh install" bug just above, and this removes that whole
  class of problem rather than just patching the specific regex that
  triggered it.
  - `install.php`'s fresh-install form still collects username/password the
    same way, but now writes them via a plain `INSERT` into the options
    table instead of regex-patching `blog-config.php`. The corresponding
    regex-writing code (and its bug-fix comments from the entry above) is
    gone entirely — nothing left to patch.
  - `FanUpdate::login()`'s legacy MD5-hash-in-`blog-config.php` upgrade path
    (which also rewrote the config file on a successful legacy login) is
    removed along with it — bcrypt-only now, matching a fresh install's
    actual credential format from day one.
  - `FanUpdate::updateConfigKey()` removed entirely — it was only ever
    called by `AccountRepository::updateUsername()`/`updatePasswordHash()`
    (Admin -> Account), both of which now do a plain `SqlConnection`
    `UPDATE` against the options table, exactly like `updateEmail()`
    already did. Both also call `FanUpdate::addOpt()` on success to keep
    the in-memory config in sync within the same request — essential, not
    cosmetic: `AccountService::updatePassword()` calls `reissueToken()`
    immediately after, which reads `getOpt('admin_password')` to compute
    the new session cookie token; without the sync it would use the
    stale, pre-change hash and log the admin out of the password change
    they just made.
  - `OptionRepository::listEditableOptions()`'s SQL now also excludes
    `admin_username`/`admin_password` (previously only `admin_email` was
    excluded there, since the other two never lived in the options table
    at all before this) — otherwise the bcrypt hash would show up as a
    plain editable text field on the generic Main Options list.
  - `blog-config.sample.php`'s entire ADMIN VARIABLES section (including
    the `password_hash('changeme', PASSWORD_BCRYPT)` line responsible for
    the bug above) is gone — nothing left in that file for a fresh install
    to get wrong.
  - Tests: `AccountRepositoryTest`'s four `updateConfigKey`-mocking tests
    rewritten against the new `SqlConnection`/`addOpt()` calls (mocking a
    method that no longer exists on the real class would otherwise fail
    outright); `OptionRepositoryTest`'s exact-SQL assertion updated for the
    new exclusions. Full suite: **384 tests, 1143 assertions, all passing,
    zero failures.**
  - README.md and docs/TROUBLESHOOTING.md updated throughout — every
    mention of admin credentials living in or being written to
    `blog-config.php`, including the now-nonexistent automatic MD5 → bcrypt
    upgrade path, corrected to describe database storage.

- **`install.php`'s seven y/n settings now render as the same on/off toggle
  switch as Admin → Options's Main list, instead of a plain text field
  where you typed `y` or `n` by hand.** Affects `comments_on`,
  `email_new_comments`, `comment_moderation`, `captcha_on`, `gravatar_on`,
  `points_scoring`, `ajax_comments`. The literal phrase "y or n" is removed
  from all seven descriptions — the toggle itself makes the choice obvious,
  so the text was now redundant (and would have read strangely next to a
  switch instead of a text box).
  - Boolean-option detection used to be admin/options.php sniffing the
    stored description for the substring "y or n" — fragile once
    install.php's own descriptions for these no longer say that. Replaced
    with `FU_YN_OPTION_KEYS`, a single explicit list in `functions.php`
    (shared by both pages), plus `fu_is_yn_option(string $optkey, string
    $desc): bool`, which checks the list first and still falls back to the
    "y or n" text sniff — so a custom option added later by hand, following
    the original convention, still renders as a toggle with no code change.
  - `admin/options.php`'s previously-local `fu_is_yn_option(string $desc)`
    removed in favour of the shared one; its one call site updated to pass
    `$optkey` too.
  - install.php's toggle can't reuse admin/options.php's "hidden `n` +
    checkbox `y` sharing one explicit array key" trick as-is: install.php's
    `key[]`/`value[]` are plain unindexed arrays, paired up purely by
    position in the parsing loop (`$clean['value'][$key]`) — a checkbox
    that only submits when checked would silently shift every later
    field's value out of alignment with its key. Instead the checkbox
    carries no `name` at all (purely visual/interactive) and a separate,
    always-present hidden field carries the actual submitted value[],
    kept in sync by a `data-yn-hidden`-driven listener.
  - The `.fu-toggle__input:checked + .fu-toggle__track` CSS rule uses an
    adjacent-sibling combinator, so `.fu-toggle__track` must be the
    checkbox's *immediately following* sibling — caught and fixed before
    finalizing: an earlier draft placed the hidden sync field between the
    checkbox and the track, which silently broke the switch's visual
    checked/unchecked styling. The hidden field now sits before the
    checkbox instead.
  - The Yes/No state-label update (and, on install.php only, the hidden
    field sync) moved out of admin/options.php's per-page inline `<script>`
    and into `assets/js/fanupdate.js` (loaded on every admin page and
    install.php via header.php already), so both pages share one
    implementation instead of duplicating it.

- **`install.php` no longer shows `collective_script`/`col_id`/`col_subj`/
  `collective_table` as editable fields on the fresh-install form.** These
  are meant to be configured AFTER installing, from Admin -> Options ->
  Collective Source, which has a proper `<select>` with auto-fill for known
  scripts (see the `collective_table` admin-UI fix and `blog-config.
  sample.php` cleanup above) — showing raw text fields for the same four
  settings here too, with none of that guidance, only invited picking a
  script without realizing `col_id`/`col_subj`/`collective_table` have to
  actually match its real schema.
  - Still seeded on fresh install with sane defaults (standalone/`none`,
    `fl_id`/`fl_subject`, `blog_category`) via hidden `<input>` fields —
    not skipped entirely, since `collective_table` in particular is needed
    immediately to create a standalone install's own categories table (see
    the `blog-config.sample.php` cleanup entry above for why it can't just
    be absent). Only the visible, editable form row is gone.
  - Implemented as an explicit key list (`$fuHiddenCollectiveSourceKeys`)
    checked alongside the existing underscore-prefix "private var" hiding
    convention, rather than renaming these to `_collective_script` etc. —
    their real `optkey` has to stay exactly `collective_script`/`col_id`/
    `col_subj`/`collective_table` for `getOpt()` to read them anywhere else
    in the app.

- **`install.php`'s `blog_page` field now auto-detects a suggested value,
  same as `install_path`/`install_url` already did.** Previously the only
  one of the three still defaulting to a hardcoded placeholder
  (`https://example.com/updates.php`) that had to be hand-edited on every
  fresh install. Since `show-blog.php` (the public blog listing) always
  lives at the project root right alongside `install.php`, the suggested
  value is now computed as `{install_url}/show-blog.php` — reusing the same
  protocol/host detection already done for `install_url` just above it.
  Still just a suggested default, always editable in the form; a
  pre-existing v1.6 installation's real `collective_updates_page` value
  (already read separately during version detection) continues to take
  priority, so upgrading never overwrites an already-customized blog page
  URL with this guess.

- **`blog-config.sample.php` cleanup: `collective_script` and the entire
  `$coltable['e'|'f'|'n']` block removed from the template.** Follow-up to
  the `collective_table` admin-UI fix above — with Admin -> Options ->
  Collective Source now fully capable of configuring a collective script
  from scratch (including auto-filling column names for a known script
  entirely client-side), pre-declaring these in a hand-edited config file
  before the database even exists added nothing but clutter. Verified every
  read site already null-coalesces to a sane default (`?? 'n'`, `?? ''`,
  `?? 'fl_id'`) except one — `install.php`'s standalone-mode table creation
  read `$coltable['n']['id']`/`['subject']` directly — which is now
  hardcoded to `'fl_id'`/`'fl_subject'` (FanUpdate's own fixed schema for
  standalone mode, not actually collective-script-specific data).
  - **Existing installations are unaffected**: `blog-config.php` is
    generated once at install time and never overwritten by updates, so
    this only changes what a brand-new install starts with — any site
    whose config still declares these keeps working exactly as before.
  - `collective_table` was deliberately NOT removed alongside the other
    two, despite looking similar at a glance: unlike `collective_script`/
    `col_id`/`col_subj`, `collective_table` is read completely unguarded on
    **every single page load** (`FanUpdate.php`'s `$fanupdate['tables']`
    array, built at file-load time before the class is even constructed)
    and is required to actually `CREATE TABLE` a fresh standalone
    install's own categories table, before the database has anywhere else
    to source a name from. Removing it would have broken every page load
    for a config that omitted it, and broken fresh standalone installs
    outright — caught before implementing, not after.
  - `class/FanUpdate.php` gains `$coltable = $coltable ?? [];` alongside
    the existing `$fanupdate = $fanupdate ?? [];` guard, so a config file
    that no longer declares `$coltable` at all doesn't throw an "Undefined
    variable" warning anywhere it's still read (the legacy-migration
    fallback paths in `install.php`/`admin/index.php`/`admin/update.php`,
    all already null-coalesced at the array-key level).
  - Verified live: a config array without `collective_script`/`$coltable`
    loads `class/FanUpdate.php` with zero warnings under `error_reporting
    (E_ALL)`.

- **`collective_script`/`col_id`/`col_subj` moved off the Main Options list
  onto their own subpage (TODO.md "UI: Move to a dedicated Options
  subpage").** These three settings control which collective/fanlisting
  script's categories appear at all (see Categories Support), but used to
  be listed on `admin/options.php` styled like any other setting (no
  different from `num_per_page`), with no room to explain accepted values.
  - New `admin/collective-source.php` ("Collective Source" tab in the
    Options subnav, added alongside it to `options.php`/`blacklist.php`/
    `smilies.php`/`templates.php`). Reuses `OptionService`/
    `OptionRepository` exactly as before — no new repository method.
  - `collective_script` now renders as a `<select>` (None / Enthusiast /
    Fanatically / Custom…) instead of a free-text field. Choosing a known
    script auto-fills `col_id`/`col_subj` from a small inline-JS lookup
    (Enthusiast: `listingid`/`subject`, Fanatically: `id`/`subject`, None:
    `fl_id`/`fl_subject`) mirroring `blog-config.sample.php`'s `$coltable`;
    `col_id`/`col_subj` stay freely editable regardless. Choosing
    Custom… reveals a plain text field for the identifier, so an
    existing self-integrated installation's value is never clobbered —
    the underlying storage is still three plain option rows and
    `FanUpdate::normalizeCollectiveScriptIdentifier()`'s
    passthrough-for-unknown-values behaviour is unchanged. `listingadmin`
    is intentionally left out of the dropdown until its Integrated Mode
    adapter (see TODO.md item 6) actually ships.
  - `OptionRepository::listEditableOptions()` now excludes these three
    keys from the Main list's query, the same way templates and
    `admin_email` already are.
  - Updated the `optdesc` text for all three keys (both `install.php`'s
    seeded defaults and `UpgradeRunner`'s legacy migration path) to name
    the currently supported scripts, explain `none` (standalone — no
    external script involved), and give the expected `col_id`/`col_subj`
    values per script.
  - UI relocation only — how the values are read/resolved
    (`FanUpdate::resolveCollectiveScriptConfig()`) is unchanged, so
    existing Enthusiast/Fanatically installations keep working unchanged
    after the move.

### Fixed

- **`footer_template` (Admin → Templates → Footer) was fully editable but
  never actually rendered anywhere** — discovered while auditing
  `admin/get-code.php`'s CSS snippet against what the public templates
  really emit (asked directly: "are there any other now dead css or new
  css that is actually missing"). `FanUpdate::printBlogFooter()` — called
  from both `show-blog.php` and `show-cat.php` — was a literal no-op stub
  ("Hook for theme customisation"), so the option had no effect on any
  public page. Fixed: `printBlogFooter()` now reads `footer_template`
  (falling back to the same built-in default `install.php` seeds on fresh
  installs) and substitutes its five documented variables —
  `{{main_url}}` (`blog_page` option), `{{archive_url}}` (`blog_page` +
  `?view=archive`, matching `show-blog.php`/`show-cat.php`'s own
  `?view=archive` handling), `{{rss_url}}` (`install_url` + `/rss.php`,
  scoped to `?c={{cat_id}}` when `show-cat.php` passes one),
  `{{fanupdate_url}}` (`FU_URL_REDUX`, same link `printCredits()` already
  uses for "Powered by"), and `{{fanupdate_version}}` (`getOpt('version')`,
  also matching `printCredits()`) — then echoes the result, same
  str_replace pattern `FanUpdate_Post::printPost()` already uses for
  `entry_template`/`comment_template`.

- **`admin/get-code.php`'s CSS snippet was missing several real, currently
  unstyled front-end elements, and still listed a few that render
  nothing** — same audit as above.
  - **Added**: `span.date` (entry date in `show-cat.php`'s archive
    listing), `p.comments-closed` (shown when an entry has comments
    turned off), `p.error`/`ul.error` (comment validation/spam-guard
    messages), `h3#postcomment`, `label.checkbox` ("Remember me"),
    `.submit` (comment form submit button), `#captcha-img`, and `p.hint`
    (moderation notice in the built-in fallback comment-form template).
  - **Removed** (matched nothing in the current codebase): `div.post`,
    `div.odd`, `div.author`, `div.comments-form`.
  - `a.rss`/`div.archivelink`/`div.credit` were flagged as dead too (tied
    to `footer_template`, which wasn't rendering) — kept, since fixing
    `printBlogFooter()` above (see the entry immediately above this one)
    made them real again.

- **Admin → Credits showed a dangling `(, released 2026-07-17)`** —
  reported live. `version.php`'s `FU_RELEASE_NAME` is genuinely optional
  (empty string for this release — no codename assigned), but
  `admin/credits.php` always printed
  `(<?php echo FU_RELEASE_NAME; ?>, released ...)` unconditionally,
  leaving a stray leading comma when it's blank. Fixed: the codename and
  its trailing `, ` are now only printed when `FU_RELEASE_NAME !== ''`,
  producing plain `(released 2026-07-17)` when unset and the original
  `(Codename, released 2026-07-17)` format when a codename is set.

- **TinyMCE's content-area focus ring used TinyMCE's own default blue/white
  instead of the admin theme's accent color** — reported live ("make match
  the theme better"). The vendored oxide/oxide-dark skins draw this ring
  via `.tox-edit-area::before` (an absolutely-positioned overlay shown
  while the editor has focus, not a plain CSS outline), hardcoded to
  `#006ce7` (day skin) / `#fff` (night skin). New override in both
  `assets/css/style.css` and `style-night.css` sets its `border-color` to
  `var(--fu-accent)` with `!important` — needed because TinyMCE injects
  its skin `<link>` after these stylesheets load, so plain selector-order
  alone couldn't guarantee the override wins.

- **TinyMCE's toolbar overflow row (the "Reveal or hide additional toolbar
  items" toggle) stayed open, overlapping the Insert Image dialog** —
  reported live ("cumbersome and partially in the way when the items are
  used"), through two follow-up rounds before landing on a proper fix.
  1. First attempt: `fuTinyCollapseToolbarOverflow()` guessed at the
     toggle's selector (`.tox-toolbar__overflow-toggle`/
     `aria-label^="More"`) and matched nothing — the row stayed open.
  2. Corrected the selector to `button[data-mce-name="overflow-button"]`
     (the real button, confirmed from a live DOM paste) — this closed the
     row, but only for our own `fuimage` button, which explicitly forces
     it closed before opening its dialog. Every *other* toolbar button
     (alignment, lists, etc.) still needed a manual close, because
     TinyMCE's overflow drawer turns out not to auto-close for any button
     by default in this configuration — the first fix compensated for our
     one custom button, not a general behaviour gap.
  3. **Final fix — a structural change, not another click-to-close hack**:
     `toolbar` is now an array of two fixed, always-visible rows (an
     array here means exactly that to TinyMCE — no overflow drawer for
     either row at all) instead of one row plus a collapsible drawer.
     Bullet/numbered list moved off the toolbar entirely into a new
     custom `fumore` dropdown menu — a real TinyMCE menu, unlike the
     toolbar drawer, closes itself automatically on selection with no
     hack needed. The `lists` plugin is still loaded and still provides
     the actual list behaviour (nested lists, Enter-key handling) behind
     `InsertUnorderedList`/`InsertOrderedList` — only its own toolbar
     buttons were replaced by the menu. `fuTinyCollapseToolbarOverflow()`
     was kept as a defensive fallback for the (now unlikely, since each
     row comfortably fits the editor width) case either row still needs
     to overflow on a narrow viewport.

- **Images inserted via the TinyMCE post editor were broken on the public
  front end** — reported live with a page-source paste showing
  `<img src="../uploads/images/2026/07/…">` instead of an absolute URL.
  Root cause: TinyMCE's default `relative_urls: true` rewrites absolute
  URLs to be relative to the *current page* — here, `admin/blog.php` — so
  the perfectly correct absolute URL `admin/upload.php` returned (built
  from `install_url`) got rewritten to a path relative to `admin/`, which
  resolves to something completely different (or nonexistent) on whatever
  front-end page actually displays the post body. Fixed by setting
  `relative_urls: false`, `remove_script_host: false`, and
  `convert_urls: false` in `assets/js/fanupdate-tinymce.js`'s
  `tinymce.init()` config, so inserted URLs are stored exactly as given.

- **TinyMCE's "Choose File…" upload button threw `Uncaught TypeError:
  uploadBtn is null` and silently did nothing** — reported live via the
  browser console after selecting a file in the OS picker. Root cause:
  `assets/js/fanupdate-tinymce.js`'s `#fu-tiny-choose-file` click handler
  read `e.currentTarget` lazily inside the hidden file input's `onchange`
  callback, but `Event.currentTarget` is only valid while an event is
  actively dispatching — per the DOM spec it reverts to `null` once
  dispatch finishes, which happens well before the user has even opened
  the OS file picker, let alone selected something. Fixed by capturing the
  button reference into a local variable synchronously, inside the click
  handler itself, before it's used in the later `onchange` closure.

- **Every admin page except Dashboard and Updates rendered as a blank page
  below the header** — reported live ("all pages except for dashboard and
  updates look like this"). Root cause: `admin/header.php`'s global
  "pending database updates" banner (shown on every logged-in admin page
  except `admin/update.php` itself) calls
  `fu_build_upgrade_runner($this)->hasPendingUpgrades(FU_VERSION)`, which
  can reach `UpgradeRunner::isVersionBehind()` →
  `UpdateManager::normalizeVersion()` — but `class/Service/UpdateManager.php`
  was only ever `require_once`'d by `admin/index.php` and `admin/update.php`
  (the two pages that happen to use `UpdateManager` directly for their own,
  unrelated reasons), not by `functions.php` or `admin/header.php`
  themselves. Every other admin page hit a fatal `Class "UpdateManager" not
  found` the instant that code path executed, with nothing surviving to
  render past that point in `<main id="main">` — and, since the server had
  `display_errors`/logging configured such that nothing showed up in the
  error log either, it looked like a silent, unexplained blank page.
  Diagnosed with a temporary try/catch added to `admin/header.php` (removed
  again once the trace pinpointed the exact call chain). Fixed at the
  source: `class/Service/UpgradeRunner.php` now `require_once`'s
  `class/Service/UpdateManager.php` itself — the class with a hard
  dependency loads it directly rather than relying on incidental load
  order from unrelated callers, same pattern as `class/FeedWriter.php`
  requiring `class/FeedItem.php`. This bug predates this session's Media
  gallery/TinyMCE work (the global banner itself, and the
  `UpdateManager`-requiring code paths in `UpgradeRunner`, were both added
  in an earlier pass) — it simply hadn't been triggered live until now.

- **Posts filed under a standalone (admin-created) category displayed with
  a blank date** — reported live ("fanatically cats are working fine, but
  the standalone cat is missing date"). Root cause: `show-cat.php` loads a
  category's `catoptions_table` row (per-category `date_format`/
  `comments_on`/template/gravatar overrides) and applies every non-empty
  column onto the site-wide options for that page — but the guard only
  checked for an empty *string* (`$value !== ''`), not SQL `NULL`.
  `CategoryRepository::insertSettings()` (called by every standalone
  category creation, via `CategoryService::createCategory()`) never sets
  `date_format`/`comments_on` at all — both columns are nullable with no
  `DEFAULT` — so a standalone category's row always has them as `NULL`,
  not `''`. That `NULL` sailed straight through the guard into
  `FanUpdate::addOpt('date_format', null)`, clobbering the site-wide
  `date_format` option for the rest of the request; `getDateFormatted()`
  then cast that to `''`, and `date('', $ts)` returns `''` — a blank date
  on every post filed under that category.
  - Fanatically/Enthusiast-synced categories never hit this: they only get
    a `catoptions_table` row at all once an admin explicitly saves
    per-category settings for one, so `show-cat.php`'s `$cat` lookup
    returns nothing and the whole override loop is skipped until then —
    exactly matching the live report that synced categories displayed
    correctly while the standalone one didn't.
  - Fixed by also excluding `null` in the guard
    (`$value !== '' && $value !== null`). Same fix incidentally also
    prevents the identical latent issue for `comments_on` (also nullable,
    also never set by `insertSettings()`) — `FanUpdate::getOpt()`'s
    `isset()` check already treats an in-memory `null` value as "not set"
    and falls back to `false`, so once `null` stops being written into
    `_cfg` at all, both columns behave correctly.

- **After a fresh install, logging in appeared to work but every subsequent
  page load immediately bounced back to the login form** — reported live.
  Root cause: `blog-config.sample.php`'s own default admin_password line is
  a *live expression*, `password_hash('changeme', PASSWORD_BCRYPT)`, not a
  static string — meant to be overwritten by `install.php`'s credential-
  writing step when you actually run the installer. That step's regex only
  matched `md5('...')`, a bare 32-hex-char string, or an already-bcrypt
  `'$2y$...'` string — never the `password_hash(...)` call itself, which is
  exactly what every fresh install's `blog-config.php` actually contains at
  that point (nobody hand-edits this line before running the installer).
  So the regex silently found no match and left the line completely
  unchanged: a live function call, re-evaluated on *every single request*,
  and bcrypt's random salt makes it produce a genuinely different hash
  string each time. `FanUpdate::login()`/`isLoggedIn()` derive the
  session's HMAC cookie token directly from this value
  (`hash_hmac('sha256', $username, $storedHash)`) — so the token computed
  at login time never matched the token expected on the very next request,
  logging the admin out unconditionally on every single click, for every
  fresh install, regardless of the password actually chosen.
  - Fixed `install.php`'s admin_password regex to also match
    `password_hash\([^)]*\)`, so the real bcrypt hash of the password
    actually entered during install gets written as a proper static string,
    exactly as the file's own top-of-file instructions already describe.
  - Found and fixed the identical gap in `FanUpdate::updateConfigKey()`
    (used by Admin -> Account to change the password later) — broadened
    only for the `admin_password` key specifically, since it's the one
    key that can legitimately arrive in any of these four forms; every
    other key stays on the original plain-quoted-string-only pattern.
    This one mattered doubly: it's the *only* self-service way to escape
    the broken state once install.php has already run, so it couldn't be
    allowed to silently fail the same way.
  - Verified both regexes directly against all four historical formats
    (`md5('...')`, bare 32-hex MD5, already-bcrypt, and the sample's live
    `password_hash(...)` default) with standalone scripts — each now
    matches and replaces correctly; `admin_username`'s unaffected plain-
    string path also re-verified unchanged.
  - **Action needed on any installation already caught by this** (including
    the one this was found on): `blog-config.php`'s `admin_password` line
    must be replaced with a static bcrypt string. Either log in with
    password `changeme` (the only password that happens to work against
    the broken default, since it's *hashing* "changeme" every time,
    just with a new salt) and immediately change the password from
    Admin -> Account, which now writes correctly — or hand-edit the line
    directly: run `php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"`
    and paste the result as `$fanupdate['admin_password'] = '...';`,
    replacing the `password_hash(...)` call entirely.

- **Login page's "Remember?" text was nearly unreadable** — dark text on
  the same accent-purple background it sat on (light mode; the night theme
  had the mirror-image bug, light text on light-ish background). Root
  cause: `#loginButton` (the `<p>` wrapping both the submit button and the
  "Remember?" `<span>`) sets its own background + text color for the
  submit button's benefit, but as an id rule it colors the *whole* element,
  including the span — and `label.checkbox`'s own `color: var(--fu-text)`
  (correct everywhere else it's used) targets the `<label>` directly, which
  always wins over an ancestor's inherited color regardless of the
  ancestor's specificity. Fixed with a dedicated `#loginButton label.
  checkbox` rule in both `style.css` (white) and `style-night.css`
  (matching that theme's own `#loginButton` text color, `#0D1117`).

- **`<ul class="success">`/`<ul class="error">` notice lists (rendered by
  `FanUpdate::reportSuccess()`/`reportErrors()` whenever there's more than
  one message — e.g. `install.php`'s line-by-line progress log) had their
  bullet markers and first/last line sitting almost flush against the box
  border.** Root cause: the bare `.success`/`.error` rule's `padding`
  shorthand wins over the global `ul, ol { padding-left: 1.4rem }` rule
  (higher specificity), effectively zeroing out the list's own indent.
  Added a dedicated `ul.success, ul.error` rule (both `style.css` and
  `style-night.css`) with more generous padding on all sides.

- **`install.php`'s page heading read "Install vRedux 2.2.0"** instead of
  something sensible. `FU_VERSION` (`version.php`) already stores the full
  human-readable string `"Redux 2.2.0"` (script name comes separately), so
  hardcoding a literal `v` immediately before it produced `vRedux 2.2.0`
  once concatenated. Fixed to `Install FanUpdate ` + the version string,
  matching how every other page in the app displays it (e.g. the
  "Powered by FanUpdate Redux 2.2.0" footer, `admin/credits.php`) — now
  reads `Install FanUpdate Redux 2.2.0`. Checked every other
  `getOpt('version')`/`FU_VERSION` display site in the codebase for the
  same mistake; this was the only one.

- **`install.php` rendered completely unstyled** — no theme CSS, icon font,
  favicon, or JS. Root cause: `admin/header.php` (shared by every
  `admin/*.php` page and, via `FanUpdate::getHeader()`, by `install.php`
  too) hardcoded `../assets/css/style.css`, `../assets/js/*.js`, and
  `../favicon.ico`. Those `../` paths are correct from an `admin/*.php`
  page's URL (one directory below `assets/`), but `install.php` lives at
  the project root, a **sibling** of `assets/` — from its URL, `../` walks
  up and out of the project directory entirely, so every asset 404s.
  - Fixed by computing `$fuAssetPrefix` in `admin/header.php`: compares the
    actually-requested script's directory (`$_SERVER['SCRIPT_FILENAME']`)
    against `header.php`'s own directory (always `admin/`) — `'../'` when
    they match (an `admin/*.php` caller), `''` otherwise (`install.php`).
    Every asset/favicon/script link now uses this instead of a hardcoded
    `../`.
  - Not caught earlier because rendering `install.php` in an actual browser
    isn't part of this codebase's automated test suite (it's a top-level
    procedural page with heavy `$_SERVER`/filesystem dependencies, same
    category as `admin/options.php`'s install-path auto-detect); confirmed
    the fix analytically by comparing `dirname($_SERVER['SCRIPT_FILENAME'])`
    against `admin/header.php`'s `__DIR__` for both an `admin/index.php`
    and an `install.php` request shape.

- **`CategoryRepository::listAll()`'s hybrid (standalone + collective)
  `UNION ALL` failed with `SQLSTATE[HY000]: General error: 1271 Illegal mix
  of collations for operation 'UNION'`** once `collective_table` (see the
  `collective_table` admin-UI fix directly below) was correctly pointed at a
  schema-qualified external database. Every table this codebase creates
  itself (including `standalone_category_table`) uses `utf8mb4_general_ci`,
  but Fanatically's own `fanatic_listings` table ships as
  `utf8mb4_unicode_ci` — MySQL refuses to `UNION` two string columns with
  incompatible collations. Discovered live immediately after fixing
  `collective_table` for a real Fanatically integration: Entries started
  working, but Categories still failed, now with this specific error
  instead of silently returning nothing.
  - Fixed by explicitly `COLLATE`-ing both `cname` columns in the `UNION`
    to `utf8mb4_general_ci` before the `UNION ALL` runs, regardless of
    what collation the external collective table (Enthusiast, Fanatically,
    or a custom integration) actually uses. Safe to do — these are
    read-only synced strings from the external script, and `COLLATE` only
    affects comparison/sort behaviour, never the stored bytes.
  - The plain (non-hybrid) branch of `listAll()`, `findById()`, and
    `FanUpdate_Post::getCatFromDb()` were not affected — none of them
    compare or `UNION` two differently-sourced string columns in the same
    query (their cross-table joins are all on integer `cat_id`/`col_id`
    columns), so no `COLLATE` is needed there.
  - Tests: new
    `CategoryRepositoryTest::testListAllUnionQueryCollatesBothCnameColumnsToAvoidMixedCollationError()`.

- **`collective_table` had no admin UI anywhere, so switching
  `collective_script` (e.g. standalone → Fanatically) via Admin → Options →
  Collective Source silently left Categories/Entries pointed at the wrong
  table.** Discovered live on a fresh Fanatically integration: the Categories
  page showed "No categories found" and the Entries page warned
  `Undefined array key "cat_id"` repeatedly, because `col_id`/`col_subj` had
  been correctly updated to Fanatically's `id`/`subject`, but `collective_table`
  — which only ever existed as a one-time read of blog-config.php's legacy
  `$fanupdate['collective_table']` — still pointed at the previous
  (standalone) categories table, whose columns don't match.
  - `admin/collective-source.php` gains a fourth field, `collective_table`,
    alongside `collective_script`/`col_id`/`col_subj` — auto-filled from the
    same known-script lookup (Enthusiast = `owned`, Fanatically =
    `fanatic_listings`, None = `blog_category`) and always freely
    editable, including schema-qualified (`other_db.table_name`) values.
  - `OptionRepository::updateOption()` now upserts (`INSERT ... ON
    DUPLICATE KEY UPDATE`) instead of a plain `UPDATE` — required for
    `collective_table` to actually persist on an installation that never
    had a row for it, and a strict improvement for every other key: a
    plain `UPDATE` against a nonexistent key silently affected 0 rows,
    indistinguishable from "value unchanged" to every caller.
  - `install.php` seeds a `collective_table` row (from blog-config.php's
    legacy value) on fresh installs and installer-driven upgrades, same as
    `col_id`/`col_subj`. `UpgradeRunner` gains an independent one-time
    `collective_table` migration (`collectiveTableMigrationPending()`/
    `migrateLegacyCollectiveTable()`), deliberately gated separately from
    the existing `collective_script` migration — an installation that had
    already migrated `collective_script` (by using the dropdown before this
    fix shipped) would otherwise never pick up `collective_table` at all.
  - `OptionRepository::listEditableOptions()`'s exclusion list now also
    excludes `collective_table`, so it doesn't also appear (and risk being
    edited out of context) on the generic Main Options list.
  - Tests: `PHP Test Suite/Unit/Repository/OptionRepositoryTest.php` updated
    for the upsert query; `PHP Test Suite/Unit/Service/UpgradeRunnerTest.php`
    gains coverage for the new independent `collective_table` migration gate,
    including the exact "collective_script already migrated, collective_table
    still isn't" scenario this bug was found in.

- **`admin/options.php` — `install_path` auto-detect only caught a
  *missing* stored path, not a stale-but-still-existing one.** Follow-up
  hardening from the `BackupManager` "empty archive" investigation
  below, whose real root cause was exactly this gap: a stale
  `install_path` left over from a domain move pointed at a real, but
  abandoned, directory, so the existing `!is_dir($storedInstallPath)`
  check never triggered the auto-correction.
  - New `fu_looks_like_install_path(string $dir): bool` replaces the
    plain `is_dir()` check: requires `version.php`/`index.php` present
    plus `admin/`/`class/` directories — the same validation
    `InstallManager` already performs on an extracted release before
    installing it over a live site, applied here to the *stored*
    install path instead. A directory that exists but isn't a real copy
    of this installation (stale, unrelated, or leftover) is now treated
    the same as a missing one and auto-corrected, exactly as a genuinely
    missing directory always was.
  - Not yet covered by automated tests — `admin/options.php` has no
    PHPUnit coverage, same as `install.php`, since both are top-level
    procedural pages rather than class/service code with an injectable
    `SqlConnection` to mock. Not yet manually re-verified live.

- **`BackupManager` — "Back up now" failed with "Backup produced an empty
  archive" (plus a `ZipArchive::close(): Can't remove file` warning) on a
  real shared-hosting deployment.** Discovered live after upgrading a
  test site from 2.0.1 to the current unreleased version. **Real root
  cause, confirmed live: a stale `install_path` option left over from a
  domain move weeks earlier** (`admin/options.php`'s existing
  install-path auto-detect only corrects a stored path that no longer
  resolves to *any* real directory — the old domain's directory still
  physically existed on disk, so the safety net never triggered).
  `BackupManager` was reading a real but abandoned, essentially empty
  directory the entire time, which also explains why `fu-backups`/
  `fu-update-tmp` appeared to be missing — they were being created under
  the stale path, not the one being looked at. Fixed by correcting the
  install path/URL options to the current domain; "Back up now" then
  succeeded immediately.
  - Two defensive code improvements were made alongside diagnosing this,
    both worth keeping even though neither was the actual cause here:
    - `walkSourceFiles()`/`writeArchive()` no longer call `realpath()`
      on the configured install path before handing file paths to
      `ZipArchive::addFile()`. On a host where the install path is a
      symlink, `open_basedir` is scoped to the symlink path, but
      `realpath()` resolves to the underlying target, which can sit
      outside that restriction — every `addFile()` call would then fail
      silently (the loop had no `else` branch to surface a failure),
      producing a zero-entry archive. A new `normalizedSourceDir()`
      helper (trailing-slash normalisation only, no `realpath()`) is
      now used consistently in both methods instead, matching how the
      same install path is already used successfully elsewhere in
      `update.php` (creating `fu-backups/`/`fu-update-tmp/`, their
      `.htaccess`/`index.html` guards).
    - `createBackup()`'s single generic "empty archive" message
      previously covered three genuinely different causes
      indistinguishably. New `describeEmptyBackupFailure()` now reports
      which actually applies: the source directory doesn't exist/isn't
      readable (bad `install_path`), the directory exists but is
      completely empty (`install_path` pointing at a real but wrong
      directory — exactly this bug), or the directory has entries but
      every one is an excluded name (`uploads`/`fu-update-tmp`/
      `fu-backups`/`.git`) with nothing left to back up. `writeArchive()`
      separately reports the case where files *are* found but
      `ZipArchive::addFile()` rejects every one of them (the
      open_basedir/symlink scenario above), pointing at `open_basedir`
      and file permissions.
  - **Follow-up identified, not yet actioned:** `admin/options.php`'s
    `install_path` auto-detect should also check that the stored path
    looks like *this* installation (e.g. `version.php`/`index.php`
    present), not just that it resolves to *some* real directory — a
    stale-but-still-existing path (this bug, exactly) currently defeats
    it silently. Tracked in TODO.md.
  - Confirmed passing on Ariane's machine across both code changes:
    **312 tests, 977 assertions, zero failures** (up from the 312/974
    baseline — no new test methods either time; existing
    `BackupManagerTest` coverage exercises both changed code paths).
    Confirmed resolved live on the actual hosting environment that
    surfaced this — see `PHP Test Suite/TEST_LOG.md`'s July 18, 2026
    entries.

- **`CategoryRepository` — category settings (entry/comment template,
  Gravatar options) were being written to and read from the wrong table.**
  `admin/category.php` constructed `CategoryRepository` passing
  `catjoin_table` as the settings-table argument for
  `insertSettings()`/`updateSettings()`/`deleteSettings()`/`findById()`'s
  settings join. But `catjoin_table`'s real schema
  (`entry_id`/`cat_id`/`collective_id`, one row per post-to-category
  assignment) has none of the `entry_template`/`comment_template`/
  `gravatar_*` columns those methods write to and read from — that's
  `catoptions_table`'s schema (one row per category, primary key
  `cat_id`), which is also what `show-cat.php` (the public side) reads
  its per-category overrides from. `listAll()`'s per-category entry-count
  join is unaffected — it genuinely needs the real catjoin table, and
  still uses it.
  - In practice this meant a category's template/Gravatar customisation
    entered via **Admin → Categories** never reached the table the
    front end actually reads, and the edit form's template fields always
    read back empty. Discovered by inspecting a live installation's SQL
    dump rather than any visible error: both `blog_category` and
    `blog_catjoin` are `ENGINE=MyISAM`, which doesn't support
    transactions, so `CategoryService`'s rollback-on-settings-failure
    never actually undid the identity insert either — a category could
    still end up created (and its name saved) even though its settings
    write silently failed against a table with no matching columns.
  - Fixed by giving `CategoryRepository` a distinct `$catoptionsTable`
    constructor dependency (new required parameter, positioned right
    after `$catjoinTable`), used by `insertSettings()`/`updateSettings()`/
    `deleteSettings()` and by `findById()`'s settings join in both the
    standalone and collective branches. `admin/category.php` and
    `admin/blog.php` now pass `(string) $fu->getOpt('catoptions_table')`
    for it. No schema changes needed — `catoptions_table` already exists
    on every installation (created by `install.php` since Milestone 4;
    read by `show-cat.php` since before that).
  - Tests: `CategoryRepositoryTest`'s `insertSettings()`/`updateSettings()`/
    `deleteSettings()` tests and all three `findById()` tests updated to
    assert against the new `catoptions_table` constant instead of
    `catjoin_table`; `listAll()`'s tests are unchanged, since that query's
    catjoin-table join was already correct.

### Removed

- **Public comment form WYSIWYG toolbar (Bold/Italic/Link buttons above the
  Comment textarea)** — removed per a direct request; the Comment field is
  now a plain `<textarea>`. `assets/js/fanupdate-comment-submit.js` no
  longer builds the toolbar, injects its scoped `<style>`, or pixel-aligns
  it to the textarea (`injectToolbarStyles()`/`alignToolbar()`/the local
  `myWysiwyg` array and its SVG icons were all deleted). Commenters can
  still type `[B]`/`[I]`/`[URL=...]` tags by hand if the comment renderer
  still parses them server-side — only the client-side insertion buttons
  and toolbar chrome are gone. Since this was the toolbar's last caller,
  `assets/js/fanupdate.js`'s shared `makeWysiwyg()`/`wysiwygButton()`/
  `formatText()`/`insertLink()` (kept alive earlier this session
  specifically for this caller — see FP-18's entry above) were deleted
  too, confirmed dead via a full-codebase search first. `install.php`'s
  default comment form template no longer claims "some formatting can be
  applied via the buttons above the textarea" (now-inaccurate copy) —
  existing installations with an already-seeded `comment_form_template`
  row keep their current wording until edited from
  **Admin → Templates → Comment Form**.

- **`class/FeedItem.php` — removed dead `toUtf8()`/`fromUtf8()` static
  methods (TODO.md item "FP-14. Refactor Legacy RSS Component for PHP
  8.2+").** Added in an earlier pass as `mb_convert_encoding()` replacements
  for the deprecated `utf8_encode()`/`utf8_decode()`, but never called from
  anywhere in the codebase — the app's `SqlConnection` DSN already forces
  `charset=utf8mb4`, so there was no live encoding-mismatch case for them to
  guard against. Confirmed via an FP-14 audit that the rest of the RSS
  component (`rss.php`, `rss-comments.php`, `class/FeedWriter.php`,
  `class/FeedItem.php`) already meets every PHP 8.2+ requirement — strict
  types, explicitly declared properties, no `utf8_encode()`/`utf8_decode()`
  calls, `htmlspecialchars(ENT_XML1 | ENT_QUOTES)` escaping — from earlier
  work; this was the only outstanding item.

- **`README.md` — removed lingering Flinx Collective / Fan Admin mention
  (TODO.md item "7. Cleanup / Technical Debt").** The Features list still
  named them ("works alongside Enthusiast 3, Flinx Collective, and Fan
  Admin, or fully standalone") even though the actual code references
  (`blog-config.sample.php`'s `$coltable['f']`/`$coltable['g']` entries)
  were already removed back in Redux 2.1.0. Re-audited the rest of the
  current codebase (`class/FanUpdate.php`, `install.php`,
  `admin/category.php`, `TODO-security.md`) and confirmed no other
  references remain. `old-docs/readme.txt`/`changelog.txt` (the preserved
  original 2008 documents) still mention them, left untouched as
  historical record, as is this changelog's own Redux 2.1.0 entry
  documenting the original removal.

---

## [Redux 2.2.0] — July 17, 2026 — Admin Update Manager

The entire Admin Update Manager milestone (TODO.md item "1. Admin Update
Manager"): a complete check → download → back up → install → rollback
pipeline, running entirely in PHP with no SSH, Composer, Git, cron, or
other command-line tooling assumed available — suited to typical shared
hosting. Also includes the automatic `install_path` discovery fix, the
full Milestone 4 architecture-modernisation pass (all seven admin
domains + the Code Cleanup / Code Review audits), and related legal/bug-fix
housekeeping items.

### Added

- **Admin Update Manager — remove obsolete files (TODO.md item "1. Admin
  Update Manager", Installation Engine "File Installation" > "Remove
  obsolete files (optional)").** An install can now clean up files left
  over from a previous version, scoped so it can never delete anything
  an admin added themselves.
  - `InstallManager` writes a manifest
    (`{install_path}/.fu-manifest.json`) listing every relative path it
    itself copies, after every successful install. On the *next*
    install, only paths present in the *previous* run's manifest but
    absent from the new release are ever candidates for removal — a
    path that was never recorded in any manifest (an admin-added file,
    or one that predates this mechanism) is never touched, regardless
    of whether the new release happens to ship a file at that path.
    When no prior manifest exists yet (the first install performed
    through the Admin Update Manager on an existing installation),
    nothing is removed that run; tracking simply begins from that point
    forward, so the very first run is always a safe no-op.
  - `RollbackManager` overwrites the same manifest with the restored
    file list after a successful restore, so a rollback followed by a
    later install can't leave the manifest describing a version that's
    no longer actually on disk. This can only ever cause
    *under*-removal of a stale leftover, never removal of something it
    shouldn't — Rollback itself never removes files, only Install does.
  - `InstallResult` gained `removedFileCount`; `UpdateManager` persists
    it into `lastInstall` state and folds it into the install log
    message. `admin/update.php` surfaces it in the success message, the
    "Last install" status line, and a note on the confirm-install page.
  - Tests: 6 new tests in `InstallManagerTest` (manifest is written
    correctly; a first install with no prior manifest removes nothing;
    a file recorded in the previous manifest but absent from the new
    release is removed; a file *not* recorded in any manifest is never
    removed, even if it isn't part of the new release either — the core
    safety requirement; a preserved-path entry is never removed even if
    it somehow ended up in a manifest; the manifest is fully overwritten
    on success), 1 in `RollbackManagerTest` (a restore overwrites the
    manifest with its own file list), and 1 in `UpdateManagerTest`
    (`removedFileCount` is surfaced in `installLatestRelease()`'s
    return value and persisted into `lastInstall` state).

- **Admin Update Manager — run upgrade scripts (TODO.md item "1. Admin
  Update Manager", Installation Engine "Post Install" > "Run upgrade
  scripts").** A non-interactive post-install database migration step
  now runs automatically after every install, rounding out the
  Installation Engine's Post Install phase.
  - `install.php`'s own version-gated migration blocks are interactive
    (they read `$_POST`-submitted values from a rendered form) and
    target version thresholds (pre-2.2b, pre-2.2b3, pre-2.2.1b2) over a
    decade before this project's PHP 8.2+ baseline — no installation
    capable of running this updater could still be that old, so those
    are deliberately not ported. What's genuinely ongoing is the
    pattern `install.php`'s final "upgrade from any version" block
    demonstrates: clean up obsolete option keys, and give any newly
    introduced option a sensible default automatically, since an
    unattended update can't pause and ask the admin to fill in a form.
  - `class/Service/UpgradeRunner.php` (new) — `runPendingUpgrades()`
    compares the stored `_db_version` option against the target
    version; if behind, it deletes a small list of known-obsolete
    option keys (idempotent — already-gone keys are silent no-ops),
    inserts a default for any option in a (currently empty, but tested
    and ready) list of newly-introduced options an upgrading install
    wouldn't have yet, then bumps `_db_version`. Always a no-op when
    already current, so it's safe to call unconditionally.
  - `class/Update/UpgradeResult.php` (new) — immutable value object
    (from/to version, list of human-readable messages, completed-at
    timestamp).
  - `class/Repository/UpgradeRepositoryInterface.php`/
    `UpgradeRepository.php` (new) — narrow data access (get/set/delete
    a single options-table row) backing `UpgradeRunner`, deliberately
    separate from `OptionRepositoryInterface`, which serves the
    admin-facing Options page instead.
  - **Architectural note:** `InstallManager::installUpdate()` overwrites
    this very file on disk mid-request, but PHP has no way to "hot
    reload" a class already loaded into the running process — so
    migrations can only correctly reflect what the *newly installed*
    release's own `UpgradeRunner.php` says if they run in a genuinely
    *fresh* HTTP request made after the file copy, not synchronously in
    the same request that performed it. `admin/update.php`'s `install`
    POST handler now redirects (carrying the install result via query
    parameters) to a new `?action=run-upgrades` GET step instead of
    rendering success directly, and that step is what actually
    constructs `UpgradeRunner` and runs it. Each future release is meant
    to extend `UpgradeRunner`'s two small lookup tables with whatever
    it newly introduces or retires.

- **Admin Update Manager — update validation (TODO.md item "1. Admin
  Update Manager", Security Hardening's "Update Validation").**
  `GitHubUpdateProvider` now validates that a decoded API response
  actually describes a genuine release of the expected repository
  before any of its fields are trusted, and tightens version-number
  parsing.
  - `assertIsGenuineRelease()` (new, private) — requires a numeric
    release `id` (every real GitHub release has one) and an `html_url`
    starting with `https://github.com/{owner}/{repo}/releases/tag/`.
    The request URL is already built exclusively from the fixed
    owner/repo constructor arguments, so this can't actually be fooled
    into fetching a different repository's data — it's defence in
    depth against a malformed or unexpected response reaching
    `normalise()`'s field reads, each of which has a `?? null`/`?? ''`
    fallback that would otherwise silently build a `ReleaseInfo` from
    missing data instead of producing a clear error.
  - `parseAndValidateVersion()` (new, private, replacing the inline
    check that used to live directly in `normalise()`) — the version
    regex is now fully anchored on both ends
    (`^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.]+)?$`) rather than just a
    prefix match, so a tag like `2.2.0/../../evil` is rejected outright
    instead of being silently truncated to `2.2.0` — the parsed
    version string ends up in backup filenames and log messages, so it
    must never carry a path separator or other unexpected character.
    A semver-style pre-release suffix (`-beta.1`, `-rc2`) is still
    accepted.
  - `PHP Test Suite/Unit/Update/GitHubUpdateProviderTest.php` (new) —
    the first test file for this class, invoking `normalise()` directly
    via Reflection with hand-built fixture arrays, since its network
    transport isn't behind an injectable callable like
    `DownloadManager`'s. `class/Update` was also added to `phpunit.xml`'s
    coverage `<source>` include, since it now has real tests.

- **Admin Update Manager — update progress (TODO.md item "1. Admin Update
  Manager", Update Progress).** The confirm-install and confirm-rollback
  pages now show a live progress bar and status message while the install/
  restore is running, and disable the submit button to prevent a
  double-submit.
  - No cron/background-job/queue mechanism is assumed available (see
    "Update System Assumptions"), so this works within a single
    synchronous request rather than a real async job: submitting a
    `<form>` (without `fetch`/`preventDefault`) doesn't unload the
    current page until the response actually arrives, so the browser
    keeps running a `setInterval` polling loop on that page for the
    whole time the install/rollback POST is in flight — no extra
    architecture needed.
  - `InstallManager::installUpdate()` and `RollbackManager::rollbackTo()`
    both gained an optional `callable(string $message, int $step, int
    $total) $onProgress` parameter, invoked at each phase boundary
    (enabling maintenance mode, staging, extracting, copying files,
    clearing cache, disabling maintenance mode).
  - `UpdateManager` wires this to a new `reportProgress()` method that
    writes into the same cached state `getStatusSummary()` reads —
    readable by a *separate* concurrent request because the DB
    connection auto-commits each write; no long-lived transaction spans
    the whole install. New `getProgress()` reads it back.
  - `admin/update.php` — a new `?action=install-progress` GET endpoint
    returns the current progress as JSON; the confirm-install/
    confirm-rollback pages each gained a progress bar + message panel
    and a small inline script that starts polling on submit.

- **Admin Update Manager — logging (TODO.md item "1. Admin Update
  Manager", Logging).** Every check, download, install, and rollback
  (automatic or manual) is now recorded in a persisted, admin-visible
  log — this also satisfies the previously-open "Display rollback
  logs" (Rollback UI) and "Update History" items, which now share the
  same underlying log rather than needing separate storage.
  - `UpdateRepositoryInterface`/`UpdateRepository` gained `getLog()`/
    `saveLog()`, a third JSON blob (`_update_log`) alongside the
    existing config/state ones, storing a list of
    `{timestamp, type, status, message}` entries.
  - `UpdateManager::logEvent()` (private) appends an entry — most
    recent first, capped at 200 — after every `checkForUpdates()`,
    `downloadLatestRelease()`, `installLatestRelease()` (the install
    itself, and separately any automatic rollback it triggers), and
    `restoreBackup()` call. Failures are logged as `status: 'error'`
    entries within their relevant `type` (e.g. a failed install is a
    `type: 'install', status: 'error'` entry) rather than a distinct
    failure category, since "what happened" and "did it succeed" are
    orthogonal. Logging itself is wrapped in its own `try`/`catch` and
    never throws — a logging problem is never the reason an update
    action is reported as failed. New `getRecentLogEntries()`/
    `clearLog()` round out the public API.
  - `admin/update.php` — a new "Update logs" section (right after
    System Status) lists the 50 most recent entries as a table
    (timestamp, event type, status badge, message), a "Download full
    log (JSON)" link (`?action=download-log`) streams the complete
    stored log as a downloadable file, and a CSRF-protected "Clear
    log" button empties it.

- **Admin Update Manager — System Status (TODO.md item "1. Admin Update
  Manager", System Status).** `admin/update.php` now shows a diagnostics
  panel reflecting this server's actual environment — useful both
  proactively (checking before an install whether anything looks likely
  to cause trouble) and after the fact (diagnosing why one failed).
  - `class/Service/SystemStatusChecker.php` — runs six checks: PHP
    version (against the project's PHP 8.2+ floor), ZIP extension
    (`ZipArchive` — backups/installs/rollbacks are all impossible
    without it), cURL availability (a warning, not an error, since
    `GitHubUpdateProvider`/`DownloadManager` both have a working
    stream-context fallback), file permissions (`is_writable()` on the
    install directory), available disk space (`disk_free_space()` —
    error under 50MB, warning under 200MB, with a warning rather than a
    hard failure when a host doesn't report it at all), and the update
    staging directory's existence/writability.
  - `class/Update/SystemStatusCheck.php` — immutable value object
    (name, `'pass'`/`'warning'`/`'error'` status, message) returned per
    check.
  - `admin/update.php` — a new "System status" section (right after
    Backups) renders all six as a table with colour-coded badges,
    reusing the same `badge-success`/`badge-warning`/`badge-danger`
    classes the rest of the page already uses.

- **Admin Update Manager — automatic checks (TODO.md item "1. Admin Update
  Manager", Automatic checks).** This is the seventh and final item in
  the "Implement in this order" list — the Admin Update Manager's core
  check → download → back up → install → rollback pipeline is now
  complete, and checking for updates no longer requires an admin to
  remember to click "Check for Updates."
  - `UpdateManager::runAutomaticCheckIfDue()` — the shared-hosting
    assumption throughout this milestone is that no cron is available,
    so this is opportunistic rather than scheduled: it runs a live check
    only when automatic checks are enabled (default on) and the
    configured interval has actually elapsed since the last check
    (manual or automatic) — otherwise it's exactly as cheap as the
    cached-only `getStatusSummary()` it replaces, with no network call.
    `isAutoCheckEnabled()`, `getCheckFrequencyHours()` (default 24,
    minimum 1), and `setAutoCheckPreferences()` back it, stored
    alongside the existing channel/token config.
  - `admin/index.php` — the Dashboard now calls
    `runAutomaticCheckIfDue()` instead of the cached-only
    `getStatusSummary()` for its update notice. The Dashboard is the
    highest-traffic admin page, so it's the natural place to
    opportunistically notice a check is due without a real cron job —
    the same pattern WordPress uses for its own pseudo-cron.
  - `admin/update.php` — Update Settings gained an "Automatically check
    for updates" checkbox and a check-frequency select (every 6/12
    hours, daily, every 3 days, weekly), both persisted via
    `setAutoCheckPreferences()` alongside the existing channel/token
    fields.

- **Admin Update Manager — rollback system (TODO.md item "1. Admin Update
  Manager", Rollback System).** `admin/update.php` can now restore a
  backup over the live installation two ways: automatically, if an
  install itself fails partway through (right after the safety-net
  backup `installLatestRelease()` already took), and manually, via a new
  "Restore" link on every row of the Backups table — the last piece of
  the "check → download → back up → install" pipeline this milestone set
  out to build.
  - `class/Service/RollbackManager.php` — restores a `BackupManager`
    archive over the live installation: validates the archive's ZIP
    integrity first (`ZipArchive::CHECKCONS`), extracts into a scratch
    staging directory with the same entry-name validation
    `InstallManager` uses (Zip Slip / absolute-path / `..` traversal
    rejection), confirms the result actually looks like a FanUpdate
    Redux installation, then copies every file back while skipping the
    same preserved paths (`uploads/`, `blog-config.php`,
    `fu-update-tmp/`, `fu-backups/`, `.git/`, `data/`) — a backup never
    contains these anyway, but the check stays as defence in depth.
    Wraps the whole operation in the same maintenance-mode flag file
    `InstallManager` uses, always cleared in a `finally` block. No
    wrapper-folder detection is needed here (unlike `InstallManager`)
    since `BackupManager` always writes paths flat, relative to the
    install directory's own top level.
  - `class/Update/RollbackResult.php` — immutable value object (backup
    path, version, file count, restored-at timestamp) returned by a
    successful rollback.
  - `UpdateManager::installLatestRelease()` — now catches an
    `InstallManager` failure, automatically restores the backup taken
    moments before via `RollbackManager`, and folds the outcome (restored
    OK / rollback itself also failed / no rollback manager available)
    into the single error message reported back to the admin, rather
    than leaving a half-installed site with only a backup file and no
    guidance.
  - `UpdateManager::restoreBackup()` — new method for a manual restore of
    any specific backup, independent of whether an install ever ran;
    records the outcome (`lastRollback`: version, file count,
    restored-at, backup path) in the same cached state used by
    `getStatusSummary()`.
  - `admin/update.php` — a "Restore" link on every Backups table row
    opens a dedicated confirmation page (`?action=confirm-rollback`)
    showing which version would be restored and warning that anything
    changed since that backup will be lost, with its own CSRF-protected
    form — same server-side-confirmation-page pattern as the install
    flow, rather than a JS `confirm()` dialog. A "Last restore" line
    joins "Last install" in the Update status panel, and the
    install-confirmation page's backup warning was updated now that a
    failed install no longer just leaves an unused backup behind.

- **Admin Update Manager — install engine (TODO.md item "1. Admin Update
  Manager", Installation Engine).** `admin/update.php` can now install a
  downloaded, verified release over the live installation — the last
  piece needed to go from "check" all the way to "running the new
  version" without SSH, Composer, Git, or any shell command. The backup
  created automatically before each install is the current recovery
  path if the install itself fails — the Rollback System above restores
  it automatically.
  - `class/Service/InstallManager.php` — extracts the release ZIP into a
    scratch staging directory under `fu-update-tmp/`, validating every
    entry name first (rejects absolute paths, Windows drive-letter
    paths, and any `..` traversal segment — Zip Slip protection before
    anything is written to disk). Auto-detects and strips a single
    GitHub-style wrapping root folder (`fanupdate-redux-2.2.0/`, as
    produced by `zipball_url`/tag archive links), then confirms the
    resolved tree actually contains `version.php`/`index.php` and
    `admin/`/`class/` before copying anything — a corrupt or unrelated
    archive is rejected outright. Copies every file over the live
    install, skipping `uploads/`, `blog-config.php`, `fu-update-tmp/`,
    `fu-backups/`, `.git/`, and `data/` so user content and local
    configuration are never touched, then resets OPcache (the only
    cache layer present in this codebase, and the one most likely to
    keep serving stale bytecode right after files are overwritten on
    disk). Wraps the whole operation in a maintenance-mode flag file
    (see below), always cleared in a `finally` block — a failed install
    can never leave the site stuck.
  - `class/Update/InstallResult.php` — immutable value object (version,
    file count, installed-at timestamp) returned by a successful install.
  - **Maintenance mode** — a simple flag file
    (`{install_path}/.fu-maintenance`), not a full public-facing
    takeover: `show-blog.php`/`show-cat.php`/`rss.php`/`rss-comments.php`
    are designed to be embedded inline in a host page's own template
    rather than rendered as standalone pages, so a page-wide maintenance
    banner doesn't fit their architecture. `process.php` (the one write
    path most likely to collide badly with an in-progress file swap)
    now checks the new `FanUpdate::isMaintenanceModeEnabled()` and
    rejects new comment submissions with a clear message while an
    install is running.
  - `UpdateManager::installLatestRelease()` — validates a verified
    download exists for the current latest version, creates a backup
    first via `BackupManager` as a safety net (aborting the install if
    the backup itself fails), calls `InstallManager`, then cleans up the
    consumed download and records the outcome (`lastInstall`: version,
    file count, timestamp) in the same cached state used by
    `getStatusSummary()`. Never throws — same error-capturing pattern as
    `checkForUpdates()`/`downloadLatestRelease()`.
  - `admin/update.php` — a "Review & install update" link (shown only
    once a verified download matching the latest version exists) opens
    a dedicated confirmation page (`?action=confirm-install`) showing the
    Installation Summary (current/target version, release date, update
    size) and warning about the automatic backup and brief comment-
    submission outage, with its own CSRF-protected "Install now" form —
    a full confirmation page rather than a JS `confirm()` dialog,
    consistent with this codebase's established preference for
    server-side confirmation views (see the `admin/blog.php` delete-
    confirmation history in the Redux 2.1.0 entry below). A banner (with
    a manual "Disable maintenance mode" button) appears if maintenance
    mode is ever found still enabled with no install running — e.g. after
    an earlier crashed run.

- **Admin Update Manager — backup manager (TODO.md item "1. Admin Update
  Manager", Installation Engine "Preparation" phase).** `admin/update.php`
  can now create, list, and delete backups of the current installation;
  restoring from one is the Rollback System's job (not yet built).
  - `class/Service/BackupManager.php` — creates a ZIP snapshot of the
    install directory's application code and configuration, excluding
    `uploads/` (user content an update never touches — backing it up on
    every update would be slow and wasteful on installs with tens of
    thousands of images), `fu-update-tmp/`, `fu-backups/` itself, and
    `.git/`. Checks free disk space first (estimated source size + 20%
    margin + 10MB buffer against `disk_free_space()`, skipped rather
    than blocking when a host can't report it), then validates the
    result (non-empty archive, `ZipArchive::CHECKCONS`) before reporting
    success. Keeps only the 3 most recent backups, deleting older ones
    automatically on each new backup — shared hosting has no cron to do
    housekeeping otherwise.
  - `class/Update/BackupResult.php` — immutable value object (path,
    size, file count, created-at timestamp, version) describing a
    backup; `fileCount` is populated for a freshly created backup and
    left `null` when just listing existing ones, since re-opening every
    archive on disk just to count entries would be wasteful for a page
    that only needs to list what already exists.
  - `admin/update.php` — a new "Backups" section: a CSRF-protected
    "Back up now" button, a table of existing backups (version, created
    date, size) each with its own CSRF-protected delete form. The
    backup directory (`{install_path}/fu-backups/`) gets the same
    fully-denied `.htaccess`/`index.html` guard as the update temp
    directory — not the script-execution-only guard `uploads/` uses —
    since it contains full copies of `blog-config.php` (database
    credentials included).

- **Admin Update Manager — download manager (TODO.md item "1. Admin Update
  Manager", Phase 4).** `admin/update.php` can now download a release's
  ZIP asset over HTTPS into a protected temporary directory, validate it,
  and report the result; extraction/install is not yet built (see
  TODO.md for the remaining phases).
  - `class/Service/DownloadManager.php` — downloads a release's ZIP
    asset (streamed straight to disk via `CURLOPT_FILE`/stream-copy, so
    memory use stays flat regardless of archive size — same
    shared-hosting concern as `GitHubUpdateProvider`), then validates
    the result: actual file size against the size the provider
    reported (1%/1KB tolerance) and ZIP integrity (`ZipArchive::CHECKCONS`,
    with a magic-byte fallback when ext-zip isn't loaded). HTTPS is
    enforced on the initial URL and every redirect hop; redirects are
    followed manually (`CURLOPT_FOLLOWLOCATION`/`follow_location`
    disabled) so each hop's host can be checked against the provider's
    allow-list before being followed, capped at 5 redirects — this is
    what stops a release payload from redirecting the download to an
    attacker-controlled host. The network transport itself is isolated
    behind an injectable callable so the validation logic is
    unit-testable without making real HTTP requests.
  - `class/Update/DownloadResult.php` — immutable value object
    (file path, size, SHA-256 digest) returned by a successful download.
  - `UpdateProviderInterface::getAllowedDownloadHosts()` — new
    provider-agnostic method returning the hostnames a release's
    download URL (and any redirect) is allowed to resolve to;
    `GitHubUpdateProvider` returns `github.com`, `codeload.github.com`,
    `objects.githubusercontent.com`, and `release-assets.githubusercontent.com`.
  - `UpdateManager::downloadLatestRelease()` — rebuilds a `ReleaseInfo`
    from cached check state (no live provider call), downloads it via
    `DownloadManager`, and persists the outcome (path, size, SHA-256,
    timestamp) into the same cached state used by `getStatusSummary()`;
    never throws, same pattern as `checkForUpdates()`. `checkForUpdates()`
    now also cleans up a previously downloaded ZIP (deletes the file,
    clears its state) whenever a check finds a different version than
    the one that was downloaded, since shared hosting has no cron to do
    this otherwise.
  - `admin/update.php` — a CSRF-protected "Download release"/
    "Re-download release" button shown whenever the latest known release
    has a download URL, plus a downloaded-status display (size, SHA-256,
    timestamp) once a download has been verified. The staging directory
    (`{install_path}/fu-update-tmp/`) is created on first use and
    guarded the same way `uploads/` is — `.htaccess` (fully denied, not
    just script-execution-blocked, since this holds release archives
    rather than servable user content) plus an `index.html` to prevent
    directory listing.

- **Admin Update Manager — update checking (TODO.md item "1. Admin Update
  Manager", Phase 1 UI + GitHub update check).** New `admin/update.php`
  page that checks GitHub for newer FanUpdate Redux releases and reports
  the result; no download/backup/install/rollback yet (see TODO.md for
  the remaining phases).
  - `class/Update/UpdateProviderInterface.php` — provider-agnostic
    contract (`getLatestRelease()`, `getProviderName()`,
    `getSourceLabel()`) so GitHub is only one implementation.
  - `class/Update/GitHubUpdateProvider.php` — queries the GitHub
    Releases API (`/releases/latest` for the stable channel,
    `/releases` for prerelease) over HTTPS only. No hard dependency on
    the cURL extension — uses it when available, falls back to
    `file_get_contents()` with a stream context otherwise, since
    neither can be assumed present on shared hosting. Detects and
    reports GitHub rate-limit (403) and not-found (404) responses with
    specific messages; an optional bearer token can be supplied for a
    higher authenticated rate limit.
  - `class/Update/ReleaseInfo.php` — immutable value object a provider
    normalises its response into (version, name, notes, URL, published
    date, prerelease flag, download URL/size).
  - `class/Repository/UpdateRepositoryInterface.php` /
    `UpdateRepository.php` — persistence for the manager's config
    (release channel, optional GitHub token) and cached check state,
    stored as two JSON blobs under private (`_`-prefixed) keys in the
    existing options table — no schema migration needed, and both keys
    are already excluded from `admin/options.php`'s editable list and
    `OptionRepository::listEditableOptions()`.
  - `class/Service/UpdateManager.php` — runs a live check against the
    configured provider and persists the result (never throws; provider
    failures are captured as an `'error'` status instead), and builds
    the display summary used by `admin/update.php` and the Dashboard
    notice from cached state alone — no GitHub call on every page load.
    `FU_VERSION` (e.g. `"Redux 2.1.0"`) is normalised to a bare version
    number before `version_compare()` is used to decide whether an
    update is available.
  - `admin/update.php` — Update Overview (installed version, status,
    source), a CSRF-protected "Check for Updates" button, a release-notes
    panel (title, version, publish date, prerelease badge, notes escaped
    then run through the existing `wpautop()`/`doHeaders()`/`doLists()`
    formatter for basic Markdown-like rendering — headers, lists,
    paragraphs, auto-linking; inline `**bold**`/`_italic_` is not
    converted), and an Update Settings form (release channel, optional
    GitHub token, both CSRF-protected).
  - `admin/index.php` — a dismissible Dashboard notice shown when a
    newer version is cached from the last check (reads cached state
    only, no live GitHub call on Dashboard load); dismissal is recorded
    per-version and automatically clears once a subsequent check finds
    a version newer than the one that was dismissed.
  - `admin/header.php` — new "Updates" nav item; `assets/css/style.css`
    / `style-night.css` — matching `#updates a.updates` active-state
    rule.
  - `version.php` — new `FU_UPDATE_PROVIDER_OWNER` /
    `FU_UPDATE_PROVIDER_REPO` constants (fixed, not user-editable — only
    the release channel and an optional token are configurable through
    the admin UI, so the update source can't be repointed through it).

### Reviewed

- **Full codebase audit against `CLAUDE.md` standards (TODO.md item
  "2. Code Review").** All 56 PHP files in `fanupdate-redux/` reviewed
  for deprecated patterns, legacy styles, missing type/return
  declarations, error handling consistency, direct-database-access
  violations, security concerns, input validation, output escaping,
  and documentation quality. No critical issues, security issues, or
  compliance violations found — the codebase already reflects the
  Milestone 4 refactor and Final Security & Code Quality Audit. One
  cosmetic fix applied (a misaligned comment character in
  `blog-config.sample.php`); no behavioural changes. Remaining items
  are documented as technical debt in TODO.md for a future decision
  (a few read-only queries that intentionally bypass the repository
  layer, and a possibly-orphaned legacy update-check method pending
  the planned Admin Update Manager).

### Fixed

- **Transaction handling for data integrity (Final Security & Code Quality
  Audit).** Two genuine partial-write risks in the category and blog
  domains are now fixed with real database transactions; a related
  CSRF re-verification pass across all eight admin pages found no
  issues.
  - `CategoryService::createCategory()` (standalone mode): a failed
    catjoin-settings insert after a successful collective-table insert
    used to leave a category that was fully live and visible in the
    category list with no template settings. Now wrapped in a
    transaction — a failed settings insert rolls back the collective
    insert too, so nothing half-created is left behind.
  - `CategoryService::deleteCategory()`: the collective-table row and
    catjoin settings row were deleted as two independent, unchecked
    statements. Now wrapped in a transaction and returns `bool`
    (previously `void`) — `admin/category.php` now reports a failed
    delete as an error instead of an unconditional "Category deleted."
    message.
  - `BlogService::createEntry()`/`updateEntry()`: the entry write and
    its catjoin category-link write(s) are now wrapped in a
    transaction — a failed category-link write (previously unchecked)
    now rolls back the whole operation instead of silently saving the
    entry with a stale or missing category.
  - `BlogRepository::delete()`: previously only the entry-table
    delete's result was reported, and the catjoin/comments cleanup ran
    regardless of its own success — a failure there could leave
    orphaned rows. Now atomic: all three deletes must succeed for the
    operation to commit, otherwise it rolls back entirely.
  - `BlogRepositoryInterface`/`CategoryRepositoryInterface` gained
    `beginTransaction()`/`commit()`/`rollBack()` methods (delegating to
    the shared `SqlConnection`), and `setCategory()`/`clearCategory()`
    on the blog interface now return `bool` instead of `void` so a
    failed link write is observable.

- **Dead comparison in `FanUpdate_Comment::getGravatarUrl()` (Final
  Security & Code Quality Audit, PHPStan level 6).** The Gravatar
  rating fallback checked `$rating === false` on a value already cast
  to `(string)` a few lines earlier, which can never be true. Removed —
  the existing `$rating === ''` check already covers the "option unset"
  case, so behaviour is unchanged.

- **Code style pass over `class/` (Final Security & Code Quality Audit,
  PHP-CS-Fixer, `@PSR12` + `strict_types`).** Whitespace/formatting only
  — no behavioural change. 12 files reformatted: blank line after
  `<?php`, single-quote conversion where no interpolation is needed,
  alignment removal on simple ternaries/assignments, brace-on-own-line
  for `SqlConnection`'s empty `__construct()`. `admin/` was deliberately
  left out of scope — PSR-12's indentation rules conflict with those
  pages' HTML-templated structure; see `TODO-security.md`.

- **Code Cleanup: removed changelog-style header comments from every PHP
  file.** Audited all 56 PHP files in `fanupdate-redux/` and replaced
  "Bug fix (Redux X.Y.Z)", "Milestone N — Architecture Modernisation",
  "Refactored for PHP 8.2+", and "HTML modernisation" style header blocks
  (which documented historical implementation changes rather than current
  behaviour) with concise present-tense docblocks describing what each
  file/class does and why. No behavioural change — comments only. Genuinely
  non-obvious rationale (e.g. why `UTC_TIMESTAMP()` is used instead of
  local-time arithmetic, the blog/category delete-transaction atomicity,
  the CSRF-session/HMAC-auth-cookie separation, standalone vs. integrated
  category mode) was preserved, just reworded to drop the "bug fix" /
  "previously ... now" framing. License headers and the `version.php`/
  `credits.php` contributor roster were left untouched.


### Added

- **Automatic `install_path` discovery (`admin/options.php`).** On every
  Options page load, the stored `install_path` option is now compared
  against this server's actual install directory. If the stored value no
  longer resolves to a real, existing directory — the telltale sign of a
  server move or migration where `install_path` was never manually
  updated — it's corrected automatically via `OptionService` and a
  success message is shown. A manually-set `install_path` is left alone
  as long as it still points to a real directory, so admins retain a
  manual override for non-standard setups (e.g. a symlinked path).

- **Options domain migrated to the repository/service layer
  (Milestone 4)** — the seventh and final Milestone 4 domain, completing
  the architecture modernisation pass across all admin pages. Mirrors the
  `admin/templates.php` → `TemplateService`/`TemplateRepository`
  refactor — the two domains cover complementary halves of the same
  options table (templates: `optkey LIKE '%template%'`; options: every
  other row except `admin_email`, which belongs to the account domain).
  - `class/Repository/OptionRepositoryInterface.php` /
    `OptionRepository.php` — data access for the general-options rows of
    the options table. Methods: `listEditableOptions()` (rows where
    `optkey NOT LIKE '%template%' AND optkey != 'admin_email'`, ordered
    alphabetically) and `updateOption()`, which returns the affected-row
    count (or `null` on query failure) rather than a plain bool — same
    three-way distinction as `TemplateRepository::updateOption()`. Every
    query is the same SQL, same bound parameters, same success/failure
    contract as the original inline code in `admin/options.php` — only
    the location moved.
  - `class/Service/OptionService.php` — thin orchestration layer
    (`listEditableOptions()`, `updateOption()`) depending on
    `OptionRepositoryInterface`, not the concrete repository or the
    `FanUpdate` singleton.
  - `admin/options.php` — now only parses/sanitises `$_POST`, enforces
    CSRF, applies the skip-private-keys rule and the explicit
    `admin_password`/`admin_username`/`admin_email`/`version` deny-list,
    calls `OptionService`, and renders. No behavioural change: the
    per-option failed/updated/unchanged message logic is preserved
    exactly, including the case where a successful update affects zero
    rows and produces no message at all. The `fu_is_yn_option()` and
    `fu_option_label()` presentation helpers stay as page-local functions
    exactly as before — pure formatting logic, not business logic or
    data access.

- **Account domain migrated to the repository/service layer
  (Milestone 4)** — mirrors the `admin/blog.php` →
  `BlogService`/`BlogRepository` refactor, with one architectural
  difference from every prior domain: account storage is split across
  two backends. The admin email lives in the options table (plain SQL,
  like every other domain); the admin username and password hash live in
  `blog-config.php`, written via `FanUpdate::updateConfigKey()` (not
  SqlConnection), and a username/password change also re-issues the HMAC
  session cookie via `FanUpdate::reissueToken()` so the admin stays
  logged in.
  - `class/Repository/AccountRepositoryInterface.php` /
    `AccountRepository.php` — data access for the admin account.
    Depends on `FanUpdate` directly (in addition to `SqlConnection`, for
    the email query), since the config-file write and token reissue are
    `FanUpdate` responsibilities, not SqlConnection ones. Methods:
    `getAdminEmail()`/`getAdminUsername()`/`getAdminPasswordHash()`
    (read-only accessors), `updateEmail()` (SQL update),
    `updateUsername()`/`updatePasswordHash()` (delegate to
    `updateConfigKey()`), and `reissueToken()`.
  - `class/Service/AccountService.php` — thin orchestration layer
    (`getCurrentEmail()`, `getCurrentUsername()`, `verifyCurrentPassword()`,
    `updateUsername()`, `updateEmail()`, `updatePassword()`) depending on
    `AccountRepositoryInterface`, not the concrete repository or the
    `FanUpdate` singleton. `verifyCurrentPassword()` moves the
    `password_verify()` call (previously inline in the admin page) into
    the service, since it's the one piece of real business logic in this
    domain. `updatePassword()` hashes with `password_hash()`/
    `PASSWORD_BCRYPT` before persisting, exactly as the original code did.
  - `admin/account.php` — now only parses/sanitises `$_POST`, enforces
    CSRF, applies the username-format / email-format /
    password-confirmation validation rules, calls `AccountService`, and
    renders. No behavioural change: notably, none of
    `updateUsername()`/`updateEmail()`/`updatePassword()` check whether
    the underlying write succeeded before showing a success message —
    the original code never checked `updateConfigKey()`'s or
    `Execute()`'s return value either, and that "fire and forget" pattern
    is preserved deliberately rather than silently hardened.

- **Templates domain migrated to the repository/service layer
  (Milestone 4)** — mirrors the `admin/blog.php` →
  `BlogService`/`BlogRepository` refactor:
  - `class/Repository/TemplateRepositoryInterface.php` /
    `TemplateRepository.php` — data access for the template-flavoured
    rows of the options table. Methods: `listTemplateOptions()` (rows
    where `optkey LIKE '%template%'`, ordered alphabetically) and
    `updateOption()`, which returns the affected-row count (or `null` on
    query failure) rather than a plain bool, since the admin page needs
    to distinguish three outcomes: query failed, query succeeded with a
    real change, and query succeeded with zero rows affected (e.g. the
    submitted value matched what was already stored). Every query is the
    same SQL, same bound parameters, same success/failure contract as the
    original inline code in `admin/templates.php` — only the location
    moved.
  - `class/Service/TemplateService.php` — thin orchestration layer
    (`listTemplateOptions()`, `updateOption()`) depending on
    `TemplateRepositoryInterface`, not the concrete repository or the
    `FanUpdate` singleton.
  - `admin/templates.php` — now only parses/sanitises `$_POST`, enforces
    CSRF, applies the skip-private-(underscore-prefixed)-keys rule, calls
    `TemplateService`, and renders. No behavioural change: the
    per-template failed/updated/unchanged message logic is preserved
    exactly, including the case where a successful update affects zero
    rows and produces no message at all.

- **Smilies domain migrated to the repository/service layer (Milestone 4)** —
  mirrors the `admin/blog.php` → `BlogService`/`BlogRepository` refactor:
  - `class/Repository/SmilieRepositoryInterface.php` / `SmilieRepository.php`
    — data access for the single-table smilies domain. Methods:
    `insert()`, `update()` (used by the bulk image-reassignment form),
    `delete()`, and `listAll()` (the smilies view has no pagination or
    ordering in the original code, so this runs a plain `Execute()`/
    `ReadRecord()` loop rather than `ExecutePaginate()`, same as
    `BlacklistRepository::listAll()`). Every query is the same SQL, same
    bound parameters, same success/failure contract as the original inline
    code in `admin/smilies.php` — only the location moved.
  - `class/Service/SmilieService.php` — thin orchestration layer
    (`addSmiley()`, `updateSmiley()`, `deleteSmiley()`, `listAll()`)
    depending on `SmilieRepositoryInterface`, not the concrete repository
    or the `FanUpdate` singleton. No branching business rules in this
    domain. `FanUpdate::makeSmiley()` (renders a smiley as an `<img>` tag)
    and `FanUpdate::printSmileyImgDropdown()` (lists available image files
    from disk) stay on `FanUpdate` exactly as before — those are
    presentation helpers, not data access or business logic.
  - `admin/smilies.php` — now only parses/sanitises `$_POST`, enforces
    CSRF, calls `SmilieService`, and renders. No behavioural change: the
    add/update/delete success and error messages, and the bulk
    update/delete actions' silent no-op on a failed individual row (no
    error message — matching the original exactly), are all preserved.

- **Blacklist domain migrated to the repository/service layer (Milestone 4)** —
  mirrors the `admin/blog.php` → `BlogService`/`BlogRepository` refactor:
  - `class/Repository/BlacklistRepositoryInterface.php` / `BlacklistRepository.php`
    — data access for the single-table blacklist domain. Methods:
    `exists()` (the duplicate-word check previously run as a `COUNT(*)`
    query inline), `insert()`, `delete()`, and `listAll()` (the blacklist
    view has no pagination in the original code, so this runs a plain
    `Execute()`/`ReadRecord()` loop rather than `ExecutePaginate()`, same
    as `CategoryRepository::listAll()`). Every query is the same SQL, same
    bound parameters, same success/failure contract as the original inline
    code in `admin/blacklist.php` — only the location moved.
  - `class/Service/BlacklistService.php` — thin orchestration layer
    (`wordExists()`, `addWord()`, `deleteWord()`, `listAll()`) depending on
    `BlacklistRepositoryInterface`, not the concrete repository or the
    `FanUpdate` singleton. No branching business rules in this domain —
    unlike Category, it's a straightforward single-table CRUD-plus-listing
    case.
  - `admin/blacklist.php` — now only parses/sanitises `$_POST`, enforces
    CSRF, calls `BlacklistService`, and renders. No behavioural change:
    the duplicate-word error, the add/delete success and error messages,
    and the bulk-delete action's silent no-op on a failed individual
    delete (no error message — matching the original exactly) are all
    preserved.

- **Category domain migrated to the repository/service layer (Milestone 4)** —
  mirrors the `admin/blog.php` → `BlogService`/`BlogRepository` refactor:
  - `class/Repository/CategoryRepositoryInterface.php` / `CategoryRepository.php`
    — data access for the category domain, which spans two tables: the
    "collective" table (category name/identity — either FanUpdate's own
    standalone table or an external collective script's, e.g. Enthusiast's)
    and the catjoin table (per-category settings: `entry_template`,
    `comment_template`, `gravatar_*`). The collective table's primary-key and
    name columns have configurable names (`col_id`/`col_subj`, resolved from
    `collective_script`), so — like table names elsewhere in this layer —
    they're injected via the constructor. Methods: `findById()`,
    `insertCollective()`, `updateCollectiveName()`, `deleteCollective()`,
    `insertSettings()`, `updateSettings()`, `deleteSettings()`, and
    `listAll()` (the category list has no pagination in the original code,
    so unlike `BlogRepository`/`CommentRepository`'s `search()`, this runs a
    plain `Execute()` rather than `ExecutePaginate()`). Every query is the
    same SQL, same bound parameters, same success/failure contract as the
    original inline code in `admin/category.php` — only the location moved.
    
  - `class/Service/CategoryService.php` — orchestration layer
    (`createCategory()`, `updateCategory()`, `deleteCategory()`,
    `getCategoryForEdit()`, `listAll()`) depending on
    `CategoryRepositoryInterface`, not the concrete repository or the
    `FanUpdate` singleton. Unlike Blog/Comment, this domain has one genuine
    branching business rule worth calling out: standalone vs. integrated
    mode (`collective_script`). The service preserves the original's exact
    (if slightly quirky) per-mode behaviour — see `CategoryService`'s class
    docblock for the full breakdown, but in short: `createCategory()` only
    attempts the catjoin settings insert when a collective-table row was
    actually created (standalone mode never runs it in integrated mode,
    since the add form submits no `cat_id` there — matching the original's
    silent no-op); `updateCategory()` attempts the collective-table name
    update in standalone mode but — as in the original — never checks its
    result, only the catjoin settings update's; `deleteCategory()` is a
    plain two-table delete, with callers (the admin page) responsible for
    only invoking it in standalone mode, exactly as the original gated the
    delete action (and hid the delete button) behind `!collective_script`.
    
  - `admin/category.php` — now only parses/sanitises `$_POST`/`$_GET`,
    enforces CSRF, calls `CategoryService`, and renders. No behavioural
    change: every success/error message and edge case (including the
    standalone/integrated branching above, and the unpaginated category
    list) is preserved exactly.
    
    
  
- **Comment domain migrated to the repository/service layer (Milestone 4)** —
  mirrors the `admin/blog.php` → `BlogService`/`BlogRepository` refactor:
  - `class/Repository/CommentRepositoryInterface.php` / `CommentRepository.php`
    — data access for the comments table: `findById()`, `insert()`, `update()`,
    `delete()`, and a `search()` method that runs the filtered/paginated
    listing (approved/pending/spam status + name/comment/email search) joined
    against the blog table for each comment's entry title. Every query is the
    same SQL, same bound parameters, same success/failure contract as the
    original inline code in `admin/comment.php` — only the location moved.
    
  - `class/Service/CommentService.php` — thin orchestration layer
    (`addComment()`, `updateComment()`, `deleteComment()`,
    `getCommentForEdit()`, `getDeleteConfirmation()`, `search()`) depending on
    `CommentRepositoryInterface`, not the concrete repository or the
    `FanUpdate` singleton. Deliberately does **not** construct
    `FanUpdate_Comment` domain objects (gravatar, abstract, formatted date) —
    that presentation logic depends on `FanUpdate`/`blog_page` context and
    stays in `admin/comment.php`, exactly as before this refactor.
    
  - `admin/comment.php` — now only parses/sanitises `$_POST`/`$_GET`,
    enforces CSRF, calls `CommentService`, and renders. No behavioural change:
    every success/error message and edge case (e.g. the delete-confirmation
    view's abstract/name lookup, the approved/pending/spam list filter) is
    preserved exactly.

### Fixed

- **`GitHubUpdateProvider::getAllowedDownloadHosts()` — `api.github.com`
  missing from the download host allow-list, breaking every download
  whose release has no dedicated `.zip` build asset.** When a release
  carries no such asset, `normalise()` falls back to GitHub's
  auto-generated `zipball_url`, whose *initial* request host is
  `api.github.com` (it only redirects to `codeload.github.com` after
  that first request) — `api.github.com` wasn't in the allow-list, so
  `DownloadManager` rejected the URL before ever sending the request,
  surfacing as "Download failed: Refusing to download from an
  unrecognised host: api.github.com" on `admin/update.php`. Fixed by
  adding `api.github.com` to the allow-list alongside `github.com`,
  `codeload.github.com`, `objects.githubusercontent.com`, and
  `release-assets.githubusercontent.com`.

### Files changed

- `admin/update.php` *(new)*
- `admin/index.php`
- `admin/header.php`
- `admin/options.php`
- `class/Update/` *(new directory: `UpdateProviderInterface.php`,
  `GitHubUpdateProvider.php`, `ReleaseInfo.php`, `DownloadResult.php`,
  `BackupResult.php`, `InstallResult.php`, `RollbackResult.php`,
  `SystemStatusCheck.php`, `UpgradeResult.php`)*
- `class/Service/UpdateManager.php` *(new)*
- `class/Service/DownloadManager.php` *(new)*
- `class/Service/BackupManager.php` *(new)*
- `class/Service/InstallManager.php` *(new)*
- `class/Service/RollbackManager.php` *(new)*
- `class/Service/SystemStatusChecker.php` *(new)*
- `class/Service/UpgradeRunner.php` *(new)*
- `class/Repository/UpdateRepositoryInterface.php` /
  `UpdateRepository.php` *(new)*
- `class/Repository/UpgradeRepositoryInterface.php` /
  `UpgradeRepository.php` *(new)*
- `class/Repository/OptionRepositoryInterface.php` / `OptionRepository.php` *(new)*
- `class/Repository/AccountRepositoryInterface.php` / `AccountRepository.php` *(new)*
- `class/Repository/TemplateRepositoryInterface.php` / `TemplateRepository.php` *(new)*
- `class/Repository/SmilieRepositoryInterface.php` / `SmilieRepository.php` *(new)*
- `class/Repository/BlacklistRepositoryInterface.php` / `BlacklistRepository.php` *(new)*
- `class/Repository/CategoryRepositoryInterface.php` / `CategoryRepository.php` *(new)*
- `class/Repository/CommentRepositoryInterface.php` / `CommentRepository.php` *(new)*
- `class/Service/OptionService.php`, `AccountService.php`,
  `TemplateService.php`, `SmilieService.php`, `BlacklistService.php`,
  `CategoryService.php`, `CommentService.php` *(new)*
- `admin/options.php`, `admin/account.php`, `admin/templates.php`,
  `admin/smilies.php`, `admin/blacklist.php`, `admin/category.php`,
  `admin/comment.php`
- `assets/css/style.css` / `style-night.css`
- `version.php`
- `blog-config.sample.php`

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

- **`admin/comment.php` — type mismatch on comment preview** — the preview block
  called `echo $cmt->printComment();`, but `FanUpdate_Comment::printComment()`
  is declared `void` — it echoes its own output directly and returns nothing.
  Wrapping the call in `echo` passed a `void` return where `string|Stringable|null`
  is expected (flagged by static analysis). Every other call site in the
  codebase (e.g. `FanUpdate::printBlog()`) already calls it as a bare
  statement. Fixed: `$cmt->printComment();` with no `echo`.
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
