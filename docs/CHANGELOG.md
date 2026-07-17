# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


---

## [Unreleased]

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
