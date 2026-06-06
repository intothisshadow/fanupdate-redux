# Changelog — FanUpdate Redux

All notable changes to this project are documented here.  


---

## [Redux 2.0.1] — June 6, 2026

### Added

- `assets/css/style-night.css` — Midnight Night theme variant: deep navy/indigo dark palette as a consistently-dark alternative to the default Dusty Rose stylesheet. Identical structure to `style.css`; only the design tokens differ. To activate, point the `<link>` in `admin/header.php` at `style-night.css` instead of `style.css`.

- **Theme toggle button in the admin header** — a moon/sun icon button (`#fu-theme-toggle`) added to `#nav-end` in `admin/header.php` switches between `style.css` (Dusty Rose) and `style-night.css` (Midnight) without a page reload. Preference is persisted in `localStorage` (`fu_theme`) and applied via an inline `<script>` before first paint to eliminate any flash of the wrong theme on load. The button icon swaps between `ti-moon` (day mode) and `ti-sun` (night mode); `aria-pressed` and `title` attributes update accordingly. New JS function `themeToggleInit()` added to `fanupdate-admin.js`; new CSS rule `#fu-theme-toggle[aria-pressed="true"]` added to both stylesheets for the active/pressed visual state.

### Changed

- **`.nav-icon-btn`** — added `padding: 0`, `font-size: inherit`, `line-height: 1`, `cursor: pointer`, and `white-space: nowrap` to both stylesheets so that a `<button>` element carrying this class renders identically to an `<a>` element with the same class (browser button defaults previously overrode the flex sizing).

- **Options page — y/n fields rendered as toggle switches** — boolean options (those whose description contains "y or n") are now displayed as animated pill toggle switches instead of plain text inputs. The option key is shown as an inline label on the left; the toggle and a live Yes/No state label sit on the right. Clicking the toggle immediately updates the label text. The form still submits `y` or `n` to the existing POST handler unchanged — no backend changes required. New CSS classes: `.option-yn-row`, `.option-yn-label`, `.option-yn-hint`, `.fu-toggle`, `.fu-toggle__input`, `.fu-toggle__track`, `.fu-toggle__thumb`, `.fu-toggle__state-label`. Dark mode supported.

- **Options page — option key labels sentence-cased** — option key names in labels now have underscores replaced with spaces and are sentence-cased (e.g. `abstract_word_count` → `Abstract word count`). The raw key is still used internally in hidden inputs and POST processing; only the visible label changes. `fu_option_label()` updated to wrap `str_replace` in `ucfirst()`.

- **Admin panel content centering** — `#main` was left-aligned on wide viewports because it had `max-width: 1100px` but no horizontal centering. Added `margin: 0 auto` to `assets/css/style.css` so the content area is centered on all screen widths. The header/nav bar remains full-width as before.

### Files changed

- `admin/header.php`
- `admin/options.php`
- `assets/js/fanupdate-admin.js`
- `assets/css/style.css`
- `assets/css/style-night.css`


---

## [Redux 2.0.1] — June 5, 2026

### Removed

- **`assets/images/` — 25 obsolete image files deleted.** All were replaced in earlier Redux releases and confirmed unreferenced across every PHP, JS, and CSS file in the codebase:
  - Replaced by inline SVG (Redux 2.0.8): `link.png`, `text_heading_1.png`, `text_heading_4.png`, `text_heading_5.png`, `text_heading_6.png`, `text_horizontalrule.png`, `text_italic.png`, `text_list_bullets.png`, `text_list_numbers.png`, `text_signature.png`, `text_strikethrough.png`, `text_subscript.png`, `text_superscript.png`, `text_underline.png`
  - Replaced by CSS `linear-gradient()` (Redux 2.0.0): `gradient-black.png`, `gradient-gray.png`, `gradient-green.png`, `gradient-red.png`, `gradient-teal.png`, `gradient.xcf`
  - Replaced by CSS button / badge (Redux 2.0.0): `cross.png`, `tick.png`, `new-entry-button.png`, `new-entry-button.svg`
  - Unreferenced miscellaneous: `feed.png`
  - **Kept:** `fanupdate.png`, `fanupdate.svg`, `fanupdate-logo.svg`, `gravatar.png`, `smileys/`

### Fixed

- **WYSIWYG toolbar: toolbar-to-textarea gap** — the host theme's `p { margin-top }` applied to the `<p>` immediately following the toolbar, creating a visible gap between the toolbar's bottom border and the textarea top. Fixed by adding `#fanupdate .fu-wysiwygmenu + p { margin-top: 0 !important; }` to the injected scoped stylesheet.

- **WYSIWYG toolbar: `alignToolbar()` offset calculation** — the previous implementation subtracted `toolbar.offsetParent.getBoundingClientRect().left` from the textarea's viewport left. `offsetParent` is the nearest *positioned* ancestor, which in some themes is not the form — giving an incorrect delta and placing the toolbar too far right. Fixed by resetting `margin-left` to `0` first, then reading `toolbar.getBoundingClientRect().left` and `textarea.getBoundingClientRect().left` (both in viewport coordinates against the same origin) and applying the difference. Single-pass, no offsetParent dependency.

- **WYSIWYG toolbar: full-width stretch** — the toolbar `<span>` was inserted as a sibling before a block-level `<p>`, placing it in a block formatting context. The browser promotes any element in that context to block-level, so `display: flex` and `display: inline-flex` both caused the toolbar to stretch to fill the full available width. Fixed by adding `width: fit-content` (with `-moz-` and `-webkit-` prefixes) to the injected `.fu-wysiwygmenu` rule.

- **WYSIWYG toolbar: unstyled, misaligned on the front-end comment form** — the toolbar rendered as three tiny bare SVG icons floating at the left margin with no background, border, or visual grouping, misaligned with the textarea. Root cause: `style.css` is the *admin* stylesheet — the public-facing page loads whatever the site owner's theme provides, which has no knowledge of `.wysiwygmenu`. Three changes fix this:

  1. **Self-contained scoped styles** — `submitCmtInit()` now calls `injectToolbarStyles()` on first run, which appends a small `<style id="fu-wysiwyg-style">` block to `<head>`. All rules are scoped under `#fanupdate` so they cannot bleed into the host page. The styles include the toolbar container (background, border, rounded top corners), button appearance, hover/focus states, SVG sizing, and a `prefers-color-scheme: dark` block. Injected once; subsequent calls are no-ops.

  2. **Prefixed class name** — the front-end toolbar now uses class `fu-wysiwygmenu` instead of the bare `wysiwygmenu`. This avoids any conflict with host-site CSS. `makeWysiwyg()` accepts a new optional third parameter `toolbarClass` (defaults to `'wysiwygmenu'` so the admin panel is unchanged); `submitCmtInit()` passes `'fu-wysiwygmenu'`.

  3. **Pixel-accurate alignment** — `alignToolbar()` measures the textarea's actual rendered left position via `getBoundingClientRect()` after a layout tick and sets `margin-left` in pixels, replacing the earlier `9.5em` static guess.

- **WYSIWYG toolbar: double toolbar and broken/missing icons** — two bugs visible in the front-end comment form:

  1. **Double toolbar** — `submitCmtInit()` was called both by the mid-page inline `<script>submitCmtInit();</script>` and the `window 'load'` listener, inserting the toolbar twice. Fixed by removing the inline `<script>` call from `FanUpdate::printBlog()` and relying solely on `window.addEventListener('load', submitCmtInit)` in `fanupdate-comment-submit.js`.

  2. **Broken/missing icons** — SVG icon paths in `makeWysiwyg()` used `../assets/images/` relative URLs which resolve correctly in the admin panel but produce 404s on any public page outside the `admin/` directory. Fixed by switching to inline SVG `<svg>` markup instead of `<img src="...">` tags, so no external image requests are made.

### Files changed

- `assets/js/fanupdate-comment-submit.js`
- `assets/js/fanupdate.js`
- `class/FanUpdate.php`


---

## [Redux 2.0.1] — June 4, 2026

### Fixed

- **`admin/blog.php` — "Allow comments" toggle missing from new-entry and edit-entry forms** — the `blog` table has a `comments_on` column and `FanUpdate_Post::commentsOn()` reads it, but the admin form never exposed it. Every entry was saved with `comments_on = 0` (comments off) because the field was absent from the form and therefore absent from both `INSERT` and `UPDATE` queries. Added a "Allow comments" `<select>` (Yes / No, defaulting to Yes) to the Settings fieldset; the POST handler now reads `comments_on` and includes it in both queries.

- **`class/FanUpdate.php` — comments and comment form missing on single-post pages** — `printBlog()` only rendered the entry body when `$single_page = true`; approved comments and the comment form from `comment_form_template` were never emitted. Added the comments list (via `FanUpdate_Comment::printComment()`) and the form after the entry body on single-post view. Form `id` corrected from `commentform` to `comments-form` (JS looks for the latter). `<div id="newComment">` added before the form as the AJAX response target. `fanupdate.js` and `fanupdate-comment-submit.js` script tags with inline `const fu_url` emitted after the form.

- **`assets/js/fanupdate-comment-submit.js` — `submitCmtInit()` not callable directly** — the init logic was wrapped in an anonymous `window.addEventListener('load', () => {...})` handler, making it impossible to call when scripts are injected mid-page (the `load` event has already fired). Extracted into a named `submitCmtInit()` function so it can be invoked directly.

- **`process.php` — comments never saved** — entry lookup used `Execute()` + `NumRows()` + `GetRecord()` in sequence. `GetRecord()` internally frees the result, so `NumRows()` always returned 0 — meaning the entry was never found and no comment was ever inserted. Replaced with a single `GetRecord($query)` call. `$result_string` initialised to `''` at top of POST block to prevent undefined variable notice.

- **`captcha.php` — session cookie mismatch** — `session_set_cookie_params()` with `secure: true` and `samesite: Lax` added before `session_start()`, matching `process.php` exactly. `$_SESSION['security_code']` moved to before the image output headers so the value is committed before any output that could interrupt execution.

### Files changed

- `admin/blog.php`
- `assets/js/fanupdate-comment-submit.js`
- `class/FanUpdate.php`
- `process.php`
- `captcha.php`


---

## [Redux 2.0] — June 2, 2026 — Admin UI Redesign & HTML Modernisation

Complete redesign of the admin panel UI with a modern aesthetic and full HTML
modernisation pass across every admin file. All IE-era CSS hacks, deprecated
HTML attributes, and old layout techniques removed and replaced with current
standards.

### Added

- **Theme: Dusty Rose** — new admin panel visual identity:
  - Colour palette: warm rose/mauve backgrounds, deep plum accent (`#8B3A7E`),
    teal success states, full CSS custom property (`--fu-*`) token system
  - **Dark mode** via `prefers-color-scheme: dark` — automatic, no toggle
    required; all tokens remap to a dark charcoal shell with a lighter lavender
    accent
  - **Google Fonts** — Inter (body) + Playfair Display (headings) loaded via
    `<link>` in `header.php`; system font fallbacks defined throughout
  - **Tabler Icons** — icon font loaded via jsDelivr CDN; all nav items and
    action buttons carry icons
- **Top-bar navigation** — sticky `<header>` with inline nav replacing the old
  `<div id="nav">` footer block; active page highlighted via `body[id]` +
  anchor class CSS matching (no JavaScript required)
- **Semantic HTML elements** throughout — `<header>`, `<main>`, `<nav>`,
  `<footer>` replace the old `<div>`-only layout
- **`<meta name="viewport">`** added to `header.php` — admin panel now renders
  correctly on mobile and tablet
- **New entry button** — pure CSS/HTML `<a id="new-entry-btn">` replacing the
  old `new-entry-button.png` sprite; PNG deleted from `assets/images/`
- **Status badges** — `.badge-success`, `.badge-muted`, `.badge-warning`,
  `.badge-danger` CSS classes replace the old `tick.png` / `cross.png` images;
  both PNGs deleted
- **Stat grid** on dashboard — `.stat-grid` / `.stat-card` layout showing
  published entry count, approved comment count, and pending comment count
- **Three-column dashboard** — `.dash-grid` CSS Grid layout for Last entries,
  Last comments, and Top entries/commenters
- **`.subnav-bar`** wrapper for Options / Blacklist / Smilies / Templates
  sub-navigation
- **CSS Grid column layouts** — `.col-layout-2`, `.col-layout-12`,
  `.col-layout-3` replacing all float-based column hacks
- **`display: flow-root`** replaces the 2005 micro-clearfix (`content: "."`, `visibility: hidden`) on all formerly-floated containers

### Changed

- **`assets/css/style.css`** — complete rewrite:
  - All IE-mac hack blocks deleted (IE for Mac discontinued 2003)
  - `* { outline: 0 }` global outline removal replaced with
    `*:focus:not(:focus-visible) { outline: none }` — preserves keyboard
    accessibility (WCAG 2.1 compliance)
  - All four PNG gradient button images (`gradient-*.png`) replaced with
    `linear-gradient()` CSS; PNG files deleted from `assets/images/`
  - `img { border: 0 }` reset removed (IE linked-image fix, irrelevant since IE8)
  - Float-based `#nav` replaced with flexbox
  - Pagination reimplemented as flex row with proper button styling
- **`admin/header.php`** — full rewrite:
  - `<div id="header">` → `<header id="header">`
  - `<div id="main">` → `<main id="main">`
  - Navigation moved from `footer.php` into `header.php` as sticky top bar
  - `type="text/javascript"` removed from all `<script>` tags
  - `$pageTitle`, `$pageID`, and `$showNav` resolved via `$GLOBALS` fallback
    so they work correctly when `include`d inside a class method scope
- **`admin/footer.php`** — full rewrite:
  - `</div>` → `</main>` and semantic closing tags throughout
  - Old `<div id="nav">` navigation block removed (now in `header.php`)
  - Credit line wrapped in `<footer class="credit">`
- **`admin/index.php`** — full rewrite with stat grid and dash-grid layout
- **`admin/blog.php`** — full rewrite:
  - `<table summary="">` → `<table aria-label="">`
  - `type="text/javascript"` removed from `<script>` tags
  - `printCategoryDropdown()` calls replaced with inline `<select>` queries —
    the method crashes silently when the `SqlConnection` result stack is not
    freed after the dropdown query; inline replacement calls `FreeResult()`
    explicitly and avoids the issue entirely
  - `class="nosort"` added to checkbox and Actions `<th>` elements — prevents
    the standardista table sorter wrapping `<input>` elements in `<a>` tags,
    which caused browsers to render the checkbox HTML as escaped text
  - `ExecutePaginate()` + `total_results` used for entry list — `NumRows()`
    after `ExecutePaginate` was unreliable due to result stack state
  - `FreeResult()` called before `ExecutePaginate` to clear any stale results
- **`admin/comment.php`** — full rewrite:
  - `.subnav-bar` filter tabs (Approved / Pending / Spam)
  - `aria-label` on table; `match` expression for status badges
  - `htmlspecialchars()` applied to Gravatar URL in inline `style` attribute
  - `type="text/javascript"` removed from `<script>` tag
  - `ExecutePaginate()` + `ReadRecordPaginate()` replacing `Execute()` + `ReadRecord()`
- **`admin/category.php`** — `<table summary="">` → `aria-label`; `.subnav-bar`
- **`admin/blacklist.php`** — `<table summary="">` → `aria-label`; `.subnav-bar`
- **`admin/smilies.php`** — `<table summary="">` → `aria-label`; `.subnav-bar`
- **`admin/options.php`** — `.subnav-bar`; `strpos(...) != 0` →
  `str_starts_with()` for private-key detection
- **`admin/templates.php`** — `.subnav-bar`; `strpos(...) != 0` →
  `str_starts_with()`; `type="text/javascript"` removed
- **`admin/account.php`** — `.col-layout-3` three-form grid; `type="email"`
  with `autocomplete` attributes
- **`admin/credits.php`** — inline `style=""` attributes replaced with
  `.credits-section` / `.credits-meta` CSS classes

### Fixed

- **`class/FanUpdate.php` — `require_once` → `include` in `getHeader()` and
  `getFooter()`** — `require_once` tracks loaded paths per PHP process lifetime;
  on PHP-FPM persistent workers, `header.php` and `footer.php` were silently
  skipped on every request after the first, producing blank white pages.
  `include()` loads the file fresh on every call as template inclusion requires.

### Additional fixes

- **`admin/get-code.php`** — `$showNav = true` was missing, causing the nav bar
  to be absent from the Display Code page. Added. Version string removed from
  the generated snippet comment.
- **`admin/comment.php`** — `$showform` defaulted to `true`, always showing the
  add form instead of the comment list. Changed default to `false`; form only
  shown when `?id=` is set or after certain POST actions.
- **`class/FanUpdate.php`** — `printWysiwyg()`, `printSmileyImgDropdown()`,
  `updateConfigKey()`, and `reissueToken()` methods were missing from the class,
  causing fatal errors on the new-entry form and account page. All four methods
  recovered from call sites and implemented.
- **`class/FanUpdate.php`** — duplicate `AddOpt()`, `AddErr()`, and
  `AddSuccess()` method declarations causing `Cannot redeclare` fatals. Removed.
- **`class/FanUpdate.php`** — `isLocked()` renamed to `isLockedOut()` to match
  `LoginRateLimiter` API; the old name caused blank admin on login.
- **`admin/blog.php`** — `cat_id` column error on save; `InsertID()` →
  `GetLastSequence()`.

### Removed

- `assets/images/new-entry-button.png` — replaced by CSS button
- `assets/images/new-entry-button-active.png` — replaced by CSS `:hover`
- `assets/images/tick.png` — replaced by `.badge-success` CSS class
- `assets/images/cross.png` — replaced by `.badge-danger` CSS class
- `assets/images/gradient-green.png` — replaced by `linear-gradient()`
- `assets/images/gradient-teal.png` — replaced by `linear-gradient()`
- `assets/images/gradient-red.png` — replaced by `linear-gradient()`
- `assets/images/gradient-black.png` — replaced by `linear-gradient()`

### Files changed

- `assets/css/style.css`
- `admin/header.php`
- `admin/footer.php`
- `admin/index.php`
- `admin/blog.php`
- `admin/comment.php`
- `admin/category.php`
- `admin/blacklist.php`
- `admin/smilies.php`
- `admin/options.php`
- `admin/templates.php`
- `admin/account.php`
- `admin/credits.php`
- `admin/get-code.php`
- `class/FanUpdate.php`


---

## [Redux 1.5–1.9: Modernisation] — May 29–June 1, 2026

Five iterations: PHP 8.2+ compatibility pass, JS modernisation, directory
restructure, email/options cleanup, FileHandler refactor.

---

## [Redux 1.1–1.4: Foundation] — May 28–29, 2026

Seven iterations: PHP 8.2+, bcrypt passwords, HMAC tokens, rate limiting,
admin account page.

---

## [Robotess Fork 1.0] — 2020 — PHP 7 Port

`mysql_*` → `mysqli_*`, `ereg_*` → `preg_*`, null-coalescing operators.

---

## [2.2.1] — 2008-06-08 through [2.0] — 2007-06-20

Original Jenny Ferenc releases. See git history for details.

---

## [1.x] — 2005–2007

Original versions by Jenny Ferenc.
