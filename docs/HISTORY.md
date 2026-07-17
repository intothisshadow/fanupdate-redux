# History — Completed Work Archive

This file archives completed `TODO.md` items once they have shipped in a
release. See `docs/CHANGELOG.md` for the full user-facing description of
each change, and `PHP Test Suite/TEST_LOG.md` for verified test runs.

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
