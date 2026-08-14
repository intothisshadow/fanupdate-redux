# History — Completed Work Archive

This file archives completed `TODO.md` items once they have shipped in a
release. See `docs/CHANGELOG.md` for the full user-facing description of
each change, and `PHP Test Suite/TEST_LOG.md` for verified test runs.

---

## [Redux 2.5.1] — August 14, 2026 — Fix FP-25 Migration Never Matching Real Installs

### FP-25 Migration CRLF Fix

- [x] `UpgradeRunner::entryTemplateMigrationPending()` compared the stored
      `entry_template` option against an LF-only constant with raw `===`.
      `install.php`'s interactive install form round-trips every
      `*template*` default through a `<textarea>` the browser submits back
      with CRLF line endings, so real installations' stored value never
      byte-matched — the migration silently never ran on any
      interactively-installed site. Discovered live on a production
      install that updated to 2.5.0 but showed no pending-upgrades banner
      and kept the old unwrapped template. Fixed with a new
      `normalizeTemplateWhitespace()` helper (CRLF/CR → LF, trim) applied
      to both sides of the comparison before matching.

---

## [Redux 2.5.0] — August 14, 2026 — `fu-` Naming Convention for Entries, Comments & Archives

### `fu-entry` Default Entry Wrapper

- [x] The default `entry_template` now wraps each post in
      `<div class="fu-entry">...</div>` instead of printing the title,
      catfile line, and body directly into `div#fanupdate` with no
      container — updated in `FanUpdate::__construct()`,
      `FanUpdate_Post::printPost()`, and `install.php`'s fresh-install seed.
      Added a matching `#fanupdate .fu-entry {}` starter rule to the
      **Admin → Category → Get Code** CSS snippet.

### FP-25. Existing Installs Don't Get the New `.fu-entry` Wrapper

- [x] Implemented as an `UpgradeRunner` migration
      (`entryTemplateMigrationPending()`/`migrateLegacyEntryTemplateWrapper()`),
      following the FP-19 timezone migration's pattern: no
      constructor-injected legacy value, gated on a plain string-equality
      check between the stored `entry_template` option and the exact
      pre-FP-25 stock default. Any installation whose `entry_template` has
      ever been edited — even by one character — is never touched. Detected
      on every admin page load via the existing pending-upgrades banner,
      applied via the same "Run Upgrades" path as the schema/
      collective-script/timezone migrations.

### `fu-comment` Naming Convention

- [x] `FanUpdate_Comment::printComment()`'s always-present per-comment
      wrapper now also carries a `fu-comment` class:
      `<div id="comment{{id}}" class="comment fu-comment">`. Unlike
      `entry_template`, this wrapper is hardcoded, not part of
      `comment_template` — so the original `comment` class is kept
      permanently rather than migrated away, and no `UpgradeRunner`
      migration applies here.

### Archives (`?view=archive`) `fu-` Classes

- [x] Extended the same naming convention to the one remaining page that
      had none: `show-blog.php`'s Month/Category Archives lists and
      `show-cat.php`'s per-category year-grouped listing now carry
      `fu-archive-list` (each `<ul>`), `fu-archive-item` (each `<li>`), and
      (on `show-blog.php`'s two lists only) `fu-archive-count` around the
      `"(N)"` entry count. Hardcoded markup, purely additive — no existing
      selector renamed or removed.

---

## [Redux 2.4.0] — August 13, 2026 — News Feed Widget, Emergency Password Reset & Table Sorting Fix

### FP-20. Fix show-blog.php Pagination Query Bug (Duplicate `entry_id` Column)

- [x] `show-blog.php`'s category-filtered listing (`?c=`) built its entries
      query as bare `SELECT *` while joined to `blog_catjoin` — both tables
      have their own `entry_id` column, which broke
      `SqlConnection::ExecutePaginate()`'s derived-table pagination count
      query with "Duplicate column name 'entry_id'". Fixed: `SELECT *` →
      `SELECT b.*`, matching `show-cat.php`'s already-correct pattern.

### FP-21. Date Format Preset Dropdown

- [x] The `date_format` option (Admin → Options → Main, and `install.php`'s
      fresh-install form) is now a `<select>` of common presets, each
      labelled with a live example (e.g. `M j, Y (Aug 13, 2026)`), plus a
      "Custom..." option that unlocks a paired free-text field for anything
      not on the shortlist.

### FP-22. Emergency Admin Password Reset (Lost Password)

- [x] New `reset-password.php` (project root) — unauthenticated by design
      (same trust model as `install.php`), rate-limited by IP via
      `LoginRateLimiter`, auto-deletes itself on success. Handles installs
      that predate admin credentials moving into the database by switching
      to an `INSERT` of both username and password. The login form links to
      it ("Forgot your password?").

### FP-23. Official FanUpdate Redux News Feed on the Dashboard

- [x] New "FanUpdate Redux News" widget (Admin → Dashboard), sourced from a
      new `news_feed_url` option (Admin → Options → Main, editable, blank
      hides the widget). New `NewsFeedRepository`/`NewsFeedService`/
      `NewsFeedFetcher` mirror the Admin Update Manager's cache-first
      architecture so a slow or unreachable feed never hangs the Dashboard.
      Removed the old dead `FanUpdate::checkUpdates()` method.

### FP-24. Replace Standardista Table Sorting with Tablesort

- [x] Replaced the vendored Standardista table-sorting code — whose
      `sortDate()` regex silently mis-sorted ISO-format dates — with
      Tablesort (github.com/tristen/tablesort, MIT, v5.7.1). Its
      `data-sort` attribute carries the raw UTC timestamp so sorting is
      correct regardless of the configured `date_format`. Added
      `aria-sort` and cache-busting query strings on admin CSS/JS assets as
      same-day follow-ups. Kept, not removed: the Standardista credit,
      rescoped to its still-used `css` utility object.

---

## [Redux 2.3.0] — July 27, 2026

### 1. Admin Update Manager

- [x] Update System Assumptions — confirmed the full pipeline (check,
      download, backup, install, rollback) runs entirely in pure PHP, no
      SSH/Composer/Git/cron/shell dependency anywhere; GitHub Releases API
      as the update source, behind an `UpdateProviderInterface` so GitHub
      is one implementation, not hardcoded.
- [x] Preserve uploads — confirmed always true by design
      (`BackupManager::DEFAULT_EXCLUDE_DIRS` excludes `uploads/` from
      every backup; `InstallManager` never writes into it).
- [x] "GitHub" future provider — already real via `GitHubUpdateProvider`.
- [x] Documentation — refreshed the `README.md` file structure table
      (added `admin/media.php`, `favicon.ico`, `LICENSE.md`, fixed the
      `docs/`/`old-docs/` split which had drifted out of sync with the
      real filesystem) and replaced the Theming section's stale
      manual-`<link>`-tag instructions with the actual live in-admin
      night-theme toggle button. No screenshots exist in `README.md` to
      go stale in the first place.

### 3. UI / UX

- [x] Copy button on `admin/get-code.php`'s Display Code and CSS Rules
      output — generic `.fu-copy-btn`/`fuCopyButtonInit()` in
      `assets/js/fanupdate-admin.js`, reusable for any future
      textarea-output page.

### 4. UI — Image/File Manager

- [x] New **Admin → Media** gallery (`admin/media.php`), backed by a
      DB-indexed `media_table` (`MediaRepository`/`MediaService`,
      `SchemaMigrator::ensureMediaTable()`). Paginated browse, per-image
      delete, and a "Choose Existing" picker wired into both the TinyMCE
      image button and the legacy upload flow.

### 5. Code Cleanup

- [x] Fixed `admin/options.php`'s `install_path` auto-detect to also catch
      a stale-but-still-existing path (e.g. after a domain move), not just
      a missing one — root cause of a live `BackupManager` "empty archive"
      failure (2026-07-18).

### 6. Categories Support

- [x] Hybrid categories: a category can be standalone (FanUpdate-owned) or
      collective-sourced, coexisting in one installation
      (`standalone_category_table`, ID-offset scheme, source badges in
      `admin/category.php`/`admin/blog.php`).
- [x] Integrated Mode: Fanatically — live category listing (no sync
      step), orphaned-category detection with an admin confirm-delete
      flow when a category is deleted upstream.
- [x] Requirements: standalone and Fanatically installations both
      supported, alongside each other, on the same modular
      `CategoryRepositoryInterface`.
- Still open (left in `TODO.md`): per-installation toggle to hide
  collective categories; Enthusiast and Listing Admin Integrated Mode
  adapters.

### 7. Cleanup / Technical Debt

- [x] Removed every remaining reference to the obsolete Flinx Collective
      and Fan Admin collective scripts (code, config, docs, UI, upgrade
      paths) — confirmed nothing remained beyond the Redux 2.1.0 removal.

### 8. Full Markdown Rendering for GitHub Release Notes

- [x] Replaced the legacy `wpautop()`/`doHeaders()`/`doLists()` formatter
      with Parsedown 1.8.0 (vendored, zero dependencies) for GitHub
      release notes in `admin/update.php` — safe mode on, scheme
      allow-list on links/images, task lists, tables, fenced code, and
      every other GFM feature the legacy formatter couldn't render.
      `ReleaseNotesRendererTest.php` covers headers through XSS payloads
      and a ~200KB large-document performance case. Blog post/comment
      rendering (`functions.php`'s formatter) is untouched — out of scope.

### 9. Database Engine & Charset Migration (MyISAM → InnoDB, latin1 → utf8mb4)

- [x] Every FanUpdate-owned table (fresh install and upgrade path) now
      migrates to `ENGINE=InnoDB`/`utf8mb4_general_ci` in one combined
      `ALTER TABLE` pass per table, idempotent and resumable if a
      shared-hosting execution-time limit interrupts a run partway
      through. Automatic mis-encoded-UTF8-as-latin1 detection picks the
      correct conversion method (plain `CONVERT` vs. binary-intermediate
      reinterpretation) per table rather than assuming either way.
      Verified live on production: all 8 tables converted with zero
      errors, front end and admin panel confirmed still working
      afterward.
- Still open (left in `TODO.md`): FULLTEXT index behavior re-verification
  under InnoDB, transaction-rollback re-verification against a real
  (not mocked) InnoDB database, and non-ASCII/multi-byte content
  round-trip testing post-conversion.

### 10. Admin-Panel Database Update Runner

- [x] Extracted the schema-migration logic (mis-encoding detection,
      InnoDB/utf8mb4 conversion, `standalone_category_table`/
      `media_table` creation) out of `install.php` into a standalone
      `SchemaMigrator` class. `UpgradeRunner` now reports/runs pending
      schema changes alongside its existing option housekeeping, so a
      manual zip-upload deployment (no GitHub release involved) can catch
      its database up entirely from **Admin → Updates**, without ever
      re-uploading `install.php`. New Dashboard notice when anything is
      pending; logged through the existing update log.

### 11. Database Architecture

- [x] Reviewed and confirmed same-server/different-schema installations
      are supported: `collective_table` can be schema-qualified (e.g.
      `enthusiast_db.owned`) for a collective script living in a
      different database on the same MySQL server. Genuinely separate
      MySQL servers remain unsupported (would require replacing the
      single-PDO-connection architecture). Documented in
      `blog-config.sample.php`/`README.md`.

### 12. Move collective-script configuration from blog-config.php into the database

- [x] `collective_script`/`col_id`/`col_subj` moved from
      `blog-config.php`'s hardcoded array into the options table
      (descriptive identifiers — `enthusiast`/`fanatically`/`none` —
      instead of single-letter shorthand), with a one-time migration for
      existing installs (`UpgradeRunner`) and a dedicated **Admin →
      Options → Collective Source** subpage (`<select>` with
      known-script auto-fill + a Custom… escape hatch for unlisted
      scripts). Live-verified: subpage saves correctly, Main Options no
      longer lists these three keys, existing installations keep working
      unchanged.
- Still open (left in `TODO.md`): `FanUpdate`'s own resolution methods
  have no PHPUnit coverage (no admin-page/singleton coverage in this
  project generally), and the fresh-install path for these three
  settings hasn't been exercised against a real install.

### 13. Dashboard

- [x] Added a Dashboard + Updates-page notice when `install.php` is still
      present on the server, prompting deletion for security.

### FP-14. Refactor Legacy RSS Component for PHP 8.2+

- [x] Audited `rss.php`/`rss-comments.php`/`FeedWriter`/`FeedItem` — the
      PHP 8.2+ refactor itself (strict types, explicit properties,
      `utf8_encode()`/`utf8_decode()` removal) had already shipped in an
      earlier session; closed the two remaining gaps: removed dead
      `FeedItem::toUtf8()`/`fromUtf8()`, and added the first PHPUnit
      coverage either class had (`FeedWriterTest.php`/`FeedItemTest.php`).

### FP-15. Opt-In Anonymous Install Ping

- [x] New disabled-by-default, fully independent install ping
      (`InstallPingService`, own transport, no code shared with the
      update checker) — sends only `install_uuid`/`version`/
      `php_version`, roughly once every 30 days, fails silently on any
      network error. New **Admin → Options → Privacy** subpage with a
      "Send a test ping now" button; documented in `README.md`.

### FP-16. Curated Release ZIP for GitHub Updates

- [x] `build-release.sh` builds a clean, curated
      `FanUpdateRedux-v{version}.zip` (+ `.sha256`) excluding `.git*`, a
      real `blog-config.php`, and OS/editor cruft, keeping `old-docs/`
      for attribution; writes to `Releases/`, refusing to overwrite an
      already-built version. No `GitHubUpdateProvider` code change
      needed — it already prefers the first `.zip` release asset over
      the raw tag archive. Wired into `CLAUDE.md`'s Release process as
      step 10.
- Still open (left in `TODO.md`): confirming the live Updates page picks
  up an actual uploaded GitHub release asset — needs a real release,
  which this version bump provides the first opportunity for.

### FP-17. Verify Downloaded Release ZIP Against a Published Checksum

- [x] `DownloadManager` now verifies a downloaded release ZIP's SHA-256
      against a published `.sha256`/`sha256sums.txt` release asset when
      one exists (via new `GitHubUpdateProvider::findChecksum()`/
      `ReleaseInfo::$expectedSha256`), refusing to install on a mismatch
      and cleaning up the temp file. An absent checksum (every
      pre-FP-16 release) is allowed with a visible "not published"
      notice, not blocked. Verification status surfaced throughout the
      admin Updates UI.

### FP-18. Implement TinyMCE Editor

- [x] Replaced the hand-rolled admin WYSIWYG toolbar with self-hosted
      TinyMCE 7.9.3 (`assets/vendor/tinymce/`, no CDN/Tiny Cloud account)
      on `admin/blog.php`'s post editor — Undo/Redo, headings, standard
      formatting, tables, fullscreen, a custom Upload New/Choose Existing
      image dialog (reusing item 4's Media Manager), autoresize, and
      night-theme-aware skin/content CSS. Graceful automatic fallback to
      a plain textarea with JavaScript disabled. Dead code removed:
      `myWysiwyg`/`fuImageButtonClick()`/etc. and
      `FanUpdate::printWysiwyg()`.
- Still open (left in `TODO.md`): default alignment buttons emit inline
  `style="text-align: …"` rather than a CSS class — accepted as
  TinyMCE's standard default rather than a scope-creeping redesign.

### FP-19. Replace Timezone Offset with Timezone Selection

- [x] Replaced the legacy fixed-hour `timezone_offset` option with a real
      IANA `timezone` identifier, so displayed dates account for
      daylight saving time automatically. Region-grouped searchable
      `<select>` in both `install.php` and **Admin → Options** (with
      human-friendly UTC-offset labels and save-time validation);
      one-time migration of an existing `timezone_offset` to the closest
      matching zone (best-effort lookup table, defaulting to UTC with an
      admin notice when no reliable mapping exists), via both
      `install.php`'s interactive upgrade path and `UpgradeRunner` for
      the Admin Update Manager.
- Still open (left in `TODO.md`): the entry-scheduling date field is
  still UTC-only (no local-time input/conversion), and there's no
  logging system yet for a configured timezone to apply to.

---

## [Redux 2.2.0] — July 17, 2026

### 1. Admin Update Manager

Full pipeline implemented and verified: check → download → back up →
install → rollback, running entirely in PHP with no SSH, Composer, Git,
cron, or other command-line tooling assumed available.

- [x] Create `update.php` UI
- [x] GitHub update check
- [x] Download manager
- [x] Backup manager
- [x] Install engine
- [x] Rollback system
- [x] Automatic checks

#### Phase 1 — Create Update Page

- [x] Create `admin/update.php`
- [x] Add page title
- [x] Add breadcrumb/navigation consistency
- [x] Restrict access to administrators
- [x] Add CSRF protection for all actions
- [x] Add "Updates" menu item
- [x] Add update/system icon
- [x] Highlight active menu state
- [x] Hide menu item from unauthorized users

#### Phase 2 — Update Overview Panel

- [x] Display current FanUpdate Redux version
- [x] Display build/version source
- [x] Display installation path
- [x] Display current status (Up to date / Update available / Never
      checked / Check failed)
- [x] Display latest available version
- [x] Display update channel
- [x] Display release date
- [x] Display last checked timestamp
- [x] Display configured update provider
- [x] Display repository information
- [x] Display release channel

#### Phase 3 — Update Check

- [x] Add "Check for Updates" button
- [x] Validate permissions
- [x] Validate CSRF token
- [x] Perform update check
- [x] Save result
- [x] Display success/error message
- [x] Display latest version
- [x] Display release date
- [x] Display release notes summary
- [x] Display download size (if available)
- [x] Display changelog link
- [x] Display release URL

#### Admin Dashboard

- [x] Display a prominent dashboard notice when a new version is
      available (current + latest version, release summary, "View
      Release Notes" link, "Update Now" button, dismissible until the
      next check)

#### Release Notes

- [x] Display release title, version number, publication date,
      prerelease status
- [x] Render release notes
- [x] Support Markdown (partial — via existing `wpautop()`/`doHeaders()`/
      `doLists()` formatter)
- [x] Sanitize HTML output
- [x] Preserve formatting
- [x] Support long changelogs

#### Update Installation

- [x] Show install button only when update exists
- [x] Disable install button during update
- [x] Display confirmation dialog (full server-side confirmation page)
- [x] Show installation summary (current version, target version,
      release date, update size)
- [x] Warn about backups
- [x] Warn about maintenance mode

#### Update Progress

- [x] Create progress section
- [x] Show current operation
- [x] Show percentage complete
- [x] Show installation log

#### Update History

- [x] Display previous updates, version changes, install date, update
      status, rollback events (all via the "Update logs" section)

#### Update Settings

- [x] Enable/disable automatic update checks
- [x] Configure check frequency
- [x] Configure update channel (Stable / Prerelease)

#### System Status

- [x] PHP version, ZIP extension, cURL availability, file permissions,
      available disk space, temporary directory writable — all six
      requirement checks
- [x] Status display (Pass / Warning / Error)

#### GitHub Update Provider

- [x] Repository owner / repository name (fixed constants)
- [x] Release channel (user-selectable)
- [x] Optional GitHub token
- [x] Query GitHub Releases API
- [x] Support stable releases and prereleases
- [x] Cache API responses
- [x] Handle rate limits
- [x] Retrieve latest release, release notes, download URL, release
      metadata

#### Phase 4 — Download Manager

- [x] Download release ZIP
- [x] Store in temporary directory
- [x] Validate download
- [x] Verify archive integrity
- [x] HTTPS only
- [x] Validate repository source
- [x] Validate response content
- [x] Prevent unexpected redirects

#### Installation Engine

- [x] Enable maintenance mode
- [x] Create backup
- [x] Validate backup success
- [x] Verify free disk space
- [x] Extract archive
- [x] Validate archive structure
- [x] Reject invalid archives
- [x] Reject unexpected root folders
- [x] Copy new files / replace updated files
- [x] Preserve configured directories
- [x] Remove obsolete files (optional) — manifest-based, scoped so it
      can never delete an admin-added file
- [x] Run upgrade scripts
- [x] Clear caches
- [x] Disable maintenance mode
- [x] Delete temporary files

#### Rollback System

- [x] Detect installation failure
- [x] Restore backup
- [x] Restore previous version
- [x] Restore maintenance state
- [x] Display rollback status, logs, failure reason

#### Security Hardening

- [x] Prevent Zip Slip attacks
- [x] Reject absolute paths
- [x] Reject directory traversal entries
- [x] Validate expected file structure
- [x] Verify source repository
- [x] Validate release metadata
- [x] Validate version numbers

#### Logging

- [x] Log update checks, downloads, installations, rollbacks, failures
- [x] View recent update logs
- [x] Download logs
- [x] Clear logs

#### Architecture

- [x] Abstract the update provider behind an `UpdateProviderInterface`
- [x] Create `UpdateProviderInterface`, `GitHubUpdateProvider`,
      `UpdateManager`, `DownloadManager`, `BackupManager`,
      `InstallManager`, `RollbackManager`, `UpgradeRunner`

### 2. Add automatic path discovery to `options.php`

- [x] Automatically detect and update the **Install path** option when
      the installation location changes, while still allowing manual
      override.

### 5. Code Cleanup

- [x] Audit all PHP files and remove unnecessary header comments
      documenting historical bug fixes or implementation changes. All 56
      PHP files in `fanupdate-redux/` audited (root, `admin/`, `class/`,
      `class/Repository/`, `class/Service/`).

### 2. Code Review — Audit Existing Code Against Updated PHP Development Standards

Full audit of all 56 PHP files against `CLAUDE.md` standards. No critical
issues, no security issues, no deprecated/legacy patterns found. One
cosmetic fix applied (`blog-config.sample.php` comment alignment).
Remaining items documented as technical debt for a future decision:

- A handful of read-only queries bypass the repository layer (category
  dropdowns in `admin/blog.php`/`admin/category.php`, dashboard
  stat/listing queries in `admin/index.php`).
- `FanUpdate::checkUpdates()` (legacy `scripts.robotess.net` Atom feed
  check) confirmed dead code now that the GitHub-based Admin Update
  Manager has shipped.
- Public-facing front-end scripts (`show-blog.php`, `show-cat.php`,
  `rss.php`, `rss-comments.php`) still build SQL inline rather than
  through a repository — out of scope for the Milestone 4 admin-domain
  migration.

- [x] Deprecated patterns — none found
- [x] Legacy coding styles — none found (outside root front-end scripts,
      by design)
- [x] Naming convention violations — none found
- [x] Missing type declarations — none found
- [x] Missing return types — none found
- [x] Inconsistent error handling — none found (documented split is
      intentional)
- [x] Direct database access patterns that violate current architecture
      — documented as technical debt above
- [x] Security concerns — none found
- [x] Input validation issues — none found
- [x] Output escaping issues — none found
- [x] Documentation deficiencies — none found beyond one cosmetic fix
- [x] Any code that conflicts with current project guidelines — none
      found
- [x] Report grouped by critical/recommended/style/technical-debt —
      produced
- [x] Apply straightforward low-risk fixes automatically
- [x] Preserve existing behavior
- [x] Do not introduce breaking changes
- [x] Document architectural-decision items without implementing
      immediately
- [x] Update affected files as needed
- [x] Verify that all tests continue to pass
- [x] Provide summary of files reviewed / modified / remaining issues /
      recommended future cleanup

### Legal

- [x] Add GPLv3 license
- [x] Add developer credits

### Bug Fixes

- [x] Footer credits links leading to a 404 page for guests —
      investigated and fixed
- [x] Add `declare(strict_types=1)` to all PHP files, review for
      type-related issues, resolve compatibility problems, verify
      application still functions correctly

### Categories Support — Standalone Mode

- [x] Use FanUpdate's own category system
- [x] Allow category creation, editing, and deletion within FanUpdate

### Create maintenance mode

- [x] Implemented as part of the Install engine — a flag file
      (`{install_path}/.fu-maintenance`), not a full public-facing
      takeover; `process.php` rejects new comment submissions while set.

### Testing

- [x] Test compatibility with PHP 8.2, 8.3, and 8.4 — confirmed via the
      Docker multi-version harness, PASS on all three (154 tests / 401
      assertions, zero failures, 2026-07-10 baseline; full Admin Update
      Manager pipeline later verified at 270 tests / 811 assertions).
