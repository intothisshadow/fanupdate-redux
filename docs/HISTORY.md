# History — Completed Work Archive

This file archives completed `TODO.md` items once they have shipped in a
release. See `docs/CHANGELOG.md` for the full user-facing description of
each change, and `PHP Test Suite/TEST_LOG.md` for verified test runs.

---

## [Redux 2.6.0] — November 3, 2026 — Media Bulk Delete, Installer Redirect, Manual ZIP Upload & More

## FP-18. Implement TinyMCE Editor

**Status:** Complete — shipped in Redux 2.6.0
*(no remaining checklist items — the one open item was resolved via
decline, see below)*

Self-hosted TinyMCE 7.9.3 integration is complete — toolbar, plugins,
configuration, and deliverables all shipped — see `docs/HISTORY.md`'s
"Redux 2.3.0" entry. Remaining:

### HTML Rules

- ~~Avoid inline styles where possible.~~ — **Declined (partial):**
TinyMCE's default alignment buttons emit `style="text-align: …"` rather
than a CSS class; switching to class-based alignment would need new
public-facing CSS classes the site's templates don't currently define,
which felt like scope creep for this ticket. Left as TinyMCE's standard,
common default rather than force a redesign.

---

## FP-26. Multiple Image Upload in WYSIWYG "Insert Image" Modal

**Status:** Complete — shipped in Redux 2.6.0

### Background

`admin/upload.php` accepts one file per POST (field name `image`) by
design — see its docblock. The "Insert Image" modal's "Upload New" tab
(`fanupdate-tinymce.js::fuTinyBuildImageModal()`/`fuTinyEnsureUploadInput()`)
uses a plain `<input type="file">` with no `multiple` attribute, and
`fuTinyUploadImage()` only ever reads `e.target.files[0]` — so picking
several files in the OS file dialog silently uploads/inserts just the
first one. Drag-and-drop/paste directly into the editor body already
supports multiple images today (TinyMCE's `images_upload_handler`
contract calls `fuTinyUploadBlob()` once per dropped/pasted file) — this
ticket is specifically about the "Choose File…" button path.

### Goal

Let an admin select multiple images at once from "Choose File…" and have
all of them uploaded and inserted into the editor, instead of one-by-one.

### Approach

Client-only change — no changes to `admin/upload.php`'s single-file
contract, validation, or security model:

- [x] Add `multiple` to `_fuTinyUploadInput` (and to `accept`'s existing
MIME list — unchanged) in `fuTinyEnsureUploadInput()`.
- [x] Rework `fuTinyUploadImage()` to iterate `e.target.files` and POST
each file to `upload.php` **sequentially** (not parallel — avoids
hammering shared hosting with concurrent uploads and keeps upload
order == insertion order predictable). Made `async`, uses a plain
`for` loop with `await fetch(...)` per file rather than chained
`.then()`s.
- [x] Update the "Choose File…" button's busy state to show progress,
e.g. "Uploading 2 of 5…", instead of the current static
"Uploading…". Only appends the "N of M" suffix when `files.length >
1`, so a single-file selection still shows the original plain
"Uploading…" text.
- [x] Insert each successfully uploaded image into the editor at the
caret as its upload completes, in selection order (reuse
`fuTinyInsertImage()` per file — TinyMCE's `insertContent()` moves
the caret after inserted content, so sequential inserts land in the
right order without extra cursor-tracking logic).
- [x] On a per-file failure, do not abort the remaining queue — continue
uploading the rest, then show a single end-of-batch summary (e.g.
"4 of 5 uploaded. Failed: bad-file.png (File type not allowed)")
rather than one `alert()` per failure.
- [x] Close the modal only after the whole batch finishes (success or
failure), not after the first file. Decided: close only once the
batch finishes **and** at least one file actually succeeded — a
total failure (0 successes) leaves the modal open, same as the
original single-file behavior never closing on a bare failure so the
admin can see the error and retry.
- [x] Manually verify: single-file selection still behaves identically
(no regression), multi-file selection uploads/inserts all of them
in order, and a mixed batch (some valid, some rejected by
`upload.php`'s existing MIME/size checks) reports the right partial
summary.

### Testing

- [x] No `admin/upload.php` PHP changes, so no new PHP Test Suite
coverage needed there. `fanupdate-tinymce.js` has no existing test
harness in this project (browser-only, DOM-driven) — verify manually
per the checklist above. Verified live in a standalone browser
harness (real `fanupdate-tinymce.js`, unmodified, with `fetch()`
mocked per-filename and a fake TinyMCE editor object): an all-success
3-file batch inserted all three in selection order with zero alerts
and closed the modal; a mixed 4-file batch (2 good, 1 server-rejected,
1 transport-error) inserted exactly the 2 successful ones, raised a
single combined-summary alert ("2 of 4 uploaded. Failed: bad.png (File
type not allowed), servererror.png (Server error 500)"), and still
closed the modal; an all-failure 1-file batch inserted nothing, raised
one alert, and left the modal open; a single-file selection showed the
busy label with no "N of M" suffix mid-flight, confirming no
regression in that case.

---

## FP-27. Prev/Next Navigation in Frontend Lightbox

**Status:** Complete — shipped in Redux 2.6.0

### Background

`fuLightboxInit()` (`assets/js/fanupdate.js`, "Lightbox" section) wires
every `<a class="fu-lightbox">` link on the page independently — each
click just swaps `#fu-lightbox-img`'s `src` to that one link's `href`
and shows the overlay. There is no concept of a gallery: with several
images in one blog entry (or across an archive page), the visitor has to
close the lightbox and click the next thumbnail manually to see the next
image, one at a time.

### Goal

Let a visitor move to the next/previous image without leaving the
lightbox — arrow buttons plus Left/Right arrow-key support — when more
than one `fu-lightbox` image is available in the current context.

### Approach

- [x] Group images by nearest ancestor `.fu-entry` (each blog post
already renders inside one — see `FanUpdate::printBlog()`) at click
time: `link.closest('.fu-entry')`, then
`querySelectorAll('a.fu-lightbox')` within it, in DOM order. Falls
back to every `a.fu-lightbox` on the whole page when the clicked
link has no `.fu-entry` ancestor (e.g. a template that doesn't use
`printBlog()`'s wrapper), so the feature still works rather than
silently doing nothing.
- [x] Compute the clicked link's index in that group; store the group +
index on the overlay (mirrors the existing `overlay._triggerLink`
pattern) so Prev/Next handlers don't need to re-query the DOM on
every step. Implemented as `overlay._group`/`overlay._index`.
- [x] Add Prev/Next buttons to the overlay markup (same self-contained
"inject once" pattern as `#fu-lightbox-close` — no separate
stylesheet dependency, so the lightbox keeps working on public
pages without the admin CSS loaded). Hide both when the group has
only one image (no regression for today's single-image case).
- [x] Wire Left/Right arrow keys to the same Prev/Next actions while the
overlay is open (Escape-to-close already exists as the precedent).
- [x] Disable (not wrap-around, to start) Prev at the first image and
Next at the last — matches the existing "no surprises" close
behaviour; wrap-around can be a later refinement if requested.
Implemented via `aria-disabled` (styled with reduced opacity +
`pointer-events: none`) plus a bounds check in `fuLbStep()` that
also guards the keyboard path.
- [x] Update `lbImg.src`/`alt` on navigation the same way the initial
open does; update `overlay._triggerLink` so focus-return-on-close
still lands on the correct originating thumbnail. Both the initial
open and every Prev/Next step now go through one shared
`fuLbUpdateImage()` helper, so this is guaranteed rather than
duplicated across two code paths.
- [x] Optional/nice-to-have: an image-count label ("3 / 7") next to the
close button, and preloading the adjacent image(s) so Next/Prev
feels instant rather than showing a flash of the loading state on
a slow connection. Both implemented — `#fu-lightbox-counter` shows
"N / total", and `fuLbUpdateImage()` preloads `group[index-1]`/
`group[index+1]` via `new Image()` on every step.
- [x] Since the lightbox markup/behaviour is a documented CSS hook
consumed by every real skin (see CLAUDE.md's "Downstream Skins"
section — `fu-lightbox` and its variants are explicitly listed),
check each skin under every `/skins/` directory listed there for
any `style.css` that positions/sizes `#fu-lightbox-overlay`'s
children by assumption (e.g. `nth-child`) and would be thrown off
by the new button elements; update as needed. Audited all seven
skins (`1-reverie`, `2-solstice`, `3-wingedreverie`, `startrektng/1`,
`ardeth/1`, `jeri/1`, `seven/1`) — none target `#fu-lightbox-overlay`
or its children at all (`:nth-child` usage elsewhere in these
files is unrelated — `.header-nav a`/table columns), so no skin
updates were needed. The new Prev/Next buttons and counter are all
`position: fixed` (not flex/grid siblings relying on order), so
inserting them before `#fu-lightbox-img` in the DOM doesn't affect
any layout a skin could have been assuming.

### Testing

- [x] Manual verification only — `fanupdate.js` has no existing test
harness in this project (browser-only, DOM-driven), same as
`fanupdate-tinymce.js`. Verify: single-image entries show no
Prev/Next (no regression), multi-image entries navigate correctly
in both directions, keyboard arrows work, focus returns to the
correct thumbnail on close after navigating away from the
originally-clicked image, and archive pages with multiple entries
don't let Next/Prev leak from one entry's images into another's.
Verified live in a standalone browser harness (three `.fu-entry`
blocks: 1 image, 3 images, 2 images) loading the real
`assets/js/fanupdate.js` unmodified: single-image entry opened with
Prev/Next/counter all `display: none`; the 3-image entry opened at
index 0 with Prev disabled/Next enabled/counter "1 / 3"; Right-arrow
stepped through to index 1 ("2 / 3", both enabled) then index 2
("3 / 3", Next disabled), and a further Right-arrow press at the
last image left the index unchanged (no wrap-around, no overshoot);
closing there and inspecting focus-restore confirmed
`overlay._triggerLink` pointed at the third thumbnail (the one
actually displayed), not the first (originally clicked) one; opening
the separate 2-image entry produced a fresh group of exactly its own
2 links (`["...text=A", "...text=B"]`) with no bleed-through from the
first entry's 3-image group.

---

## FP-28. Consistent Button Label Capitalization

**Status:** Complete — shipped in Redux 2.6.0

### Background

Codebase-wide audit found two visible-text conventions for buttons that
don't match:

- `<a class="button">` links and `<button>` elements with real inner
text are consistently **Sentence case** already — "Cancel", "Clear",
"Get code", "Install now", "Send a test ping now", "Back up now",
"Delete" (`update.php`), "Permanently Delete", "Delete Selected", etc.
- Every `<input type="submit" name="action" value="…">` button, by
contrast, displays whatever machine-readable action string was put in
`value=` verbatim — and those are all-lowercase action tokens:
`value="add"`, `value="update"`, `value="delete"`, `value="preview"`,
`value="check"`, `value="confirm-delete-orphan"`, and the admin
**login button** itself (`class/FanUpdate.php:835`,
`value="login"`). 25 instances across 11 `admin/*.php` files plus the
login form — full list gathered via
`grep -rn 'type="submit"' --include=*.php . | grep -E 'value="[a-z]'`.

### Why this needs care, not a find/replace

For `<input type="submit">`, `value=` is **both** the visible label
**and** the actual value PHP reads back via `$_POST['action']` (each
target file branches on it, e.g. `if ($_POST['action'] === 'delete')`).
Simply capitalizing `value="delete"` to `value="Delete"` would silently
change what every one of those `$_POST['action']` comparisons needs to
match — a behavior change disguised as a cosmetic one, and one that's
easy to miss an instance of across 11 files.

**Fix pattern (already precedented in this exact codebase):**
`admin/blog.php:432` and `:492` solve the identical problem today by
using `<button type="submit" name="action" value="bulk-delete-confirmed" …>Permanently Delete</button>` — a `<button>` element's
visible text is its own inner content, completely independent of its
`value` attribute. Converting each affected `<input type="submit">` to
the equivalent `<button type="submit" name="…" value="…" class="…" title="…" accesskey="…">Label</button>` (preserving every existing
attribute unchanged) fixes the capitalization with **zero PHP/backend
changes** — the submitted `value` string, and therefore every
`$_POST['action']` comparison, stays exactly as it is today.

### Target convention

Sentence case (first letter capitalized, rest lowercase), matching the
majority of existing buttons/links — not Title Case. E.g. `add` → "Add",
`update` → "Update", `delete` → "Delete", `preview` → "Preview",
`login` → "Login", `check` → "Check", `confirm-delete-orphan` → "Confirm
delete".

### Approach

- [x] Convert each of the following `<input type="submit">` elements to
  a `<button type="submit">` with identical `name`/`value`/`class`/
  `title`/`accesskey`/`data-skip-confirm` attributes, giving it
  Sentence-case inner text:
  - `class/FanUpdate.php:835` — `value="login"` → "Login" (admin login
  form — verify no CSS/JS selects this element by tag name `input`
  specifically before converting, since it's outside `admin/`)
  - `admin/comment.php:302,306,307,309,337` — preview/update/delete/
  add/delete
  - `admin/privacy.php:145` — update
  - `admin/templates.php:119` — update
  - `admin/smilies.php:117,171,172` — add/update/delete
  - `admin/blog.php:325,329,332,397` — preview/update/add/delete
  - `admin/media.php:169` — delete
  - `admin/options.php:345` — update
  - `admin/category.php:217,294,297,303,359` —
  confirm-delete-orphan/update/add/delete/delete (`:359` is built via
  PHP string concatenation, not a plain template literal — read the
  surrounding function fully before editing)
  - `admin/collective-source.php:244` — update
  - `admin/update.php:756` — check
  - `admin/blacklist.php:103,142` — add/delete
  All 25 converted (line numbers had drifted slightly by the time this
  ran — re-located each by content, not by the stale line numbers above
  — `admin/update.php`'s `check` button in particular had moved to
  `:805` after FP-33 added a new section earlier on that page).
- [x] Confirm no CSS in `assets/css/style.css`/`style-night.css` targets
`input[type="submit"]` specifically (as opposed to `.button`/`.add`/
`.update`/`.delete` classes, which apply equally to `<button>`) in a
way that would stop applying once these become `<button>` elements.
Checked both files: every button-styling rule already lists a bare
`button` selector and the semantic class (`.add`/`.update`/`.edit`/
`.submit`/`.delete`) alongside `input[type="submit"].*` — e.g.
`input[type="submit"], button, .button { ... }` — so nothing depends on
the `input` tag exclusively. `#loginButton`'s accent-purple styling is
on the wrapping `<p id="loginButton">`, not the input/button itself, so
unaffected either way.
- [x] Confirm no JS (`assets/js/fanupdate-admin.js` and friends) selects
any of these by tag (`input[type=submit]`) rather than by class/id —
same risk as the CSS check above, opposite direction. None found —
`confirmDeleteInit()` (the only handler wired onto these buttons) uses
`css.getElementsByClass(document, 'delete', '*')`, tag-agnostic by
design (`'*'` wildcard), and reads `button.title` the same way for
`<button>` as it did for `<input>`. Searched all of `assets/js/*.js` for
any `input[type="submit"]`/`input.delete`-style tag-scoped selector —
none exist.
- [x] Manually verify each converted form still submits the same
`action` value and triggers the same PHP branch as before (e.g. the
smilies "add" button still hits the `add` branch in
`admin/smilies.php`), and that `accesskey`/`title`/tooltip behavior
is unchanged. Every conversion preserved `name`/`value`/`class`/
`title`/`accesskey`/`data-skip-confirm`/`onclick` attributes exactly
byte-for-byte, only swapping the tag and moving the label from `value=`
into inner text — no PHP file was touched, so every `$_POST['action']`
comparison still matches the same submitted string it always did.
Not exercised in a live browser this pass (no running install in this
environment) — see Testing below.

### Testing

- [x] No PHP logic changes, so no new PHP Test Suite coverage needed.
Verify manually per the checklist above — this is a template/markup
change across many admin pages, not something the existing PHPUnit
suite (which doesn't cover `admin/*.php` markup at all — see TODO.md
item 12's testing notes for the same caveat on other `admin/*.php`
work) can catch. Full suite re-run after the conversion regardless, to
confirm nothing else regressed: **531 tests, 1504 assertions, 0
failures** (unchanged from before this ticket — expected, since no PHP
was modified).

---

## FP-29. show-cat.php Post Title Links Pointed Back at the Category Page

**Status:** Complete — shipped in Redux 2.6.0

### Background

`show-cat.php` (embedded on a category page to list that category's
posts) called `$fu->printBlog(...)`, which constructed each
`FanUpdate_Post` without an explicit `$self` argument. `FanUpdate_Post`'s
constructor then fell back to `$this->fu->getCleanSelf()` (the URL of
the page currently rendering) for the post's permalink. On
`show-blog.php` that's correct — it *is* the blog page. On
`show-cat.php` it's wrong: a post's title link (and comment link, and
the `{{url}}` post-template variable) pointed back at the very category
page the list was already showing, so clicking a title just reloaded
the same page with `?id=` appended instead of taking the visitor to the
real single-post view on the Blog page (Options -> "Blog page",
`blog_page` option).

Every other `FanUpdate_Post`/`FanUpdate_Comment` call site already
passes `$fu->getOpt('blog_page')` explicitly for this exact reason —
`rss.php`, `rss-comments.php`, `admin/index.php`, `admin/blog.php`,
`admin/comment.php`. `printBlog()` (shared by both `show-blog.php` and
`show-cat.php`) was the one place that didn't.

### Fix

- [x] `class/FanUpdate.php`'s `printBlog()` now resolves `blog_page` once
and passes it as `FanUpdate_Post`'s `$self` argument, matching every
other call site. When `blog_page` is unset/empty it passes `null`,
preserving the previous `getCleanSelf()` fallback behavior (e.g. during
install, before the option has a value).

### Testing

- [x] `PHP Test Suite/Unit/FanUpdate_PostTest.php` —
`testUrlPrefersExplicitSelfOverCurrentPage()` regression test:
constructs a `FanUpdate_Post` with an explicit `$self` and asserts
`getUrl()` uses it rather than `getCleanSelf()`.

---

## FP-31. Frontend Lightbox Renders Off-Screen on Long Mobile Pages

**Status:** Complete — shipped in Redux 2.6.0

### Background

Reported live on `coding.unloved-heart.net`'s `/updates` page (a long,
multi-entry `show-blog.php` page, ~10,500px tall): on mobile, tapping a
`fu-lightbox` thumbnail opened the dark backdrop and close button as
expected, but the enlarged image itself was nowhere on screen — just a
blank dark viewport with a stray close button, matching a real-device
screenshot (Android Chrome).

Root-caused via the in-app browser's mobile emulation: on this specific
long, scrollable page, `window.innerWidth`/`innerHeight` read ~957×2073
while `document.documentElement.clientWidth`/`clientHeight` (the real,
visible viewport) correctly read 375×812 — a mobile-browser quirk where a
scrollable document behind a `position: fixed; inset: 0` overlay lets the
*layout* viewport (what `position: fixed` sizes/centers against) drift out
of sync with the *visual* viewport, on a page long/complex enough to
trigger it. `#fu-lightbox-overlay`'s flexbox centering (`align-items:
center; justify-content: center`) then centered `#fu-lightbox-img` against
the inflated 957×2073 box, landing it far outside the visible 375×812
screen. Confirmed by direct experiment in the live DOM: manually setting
`document.body.style.position = 'fixed'` (locking scroll) immediately
collapsed `window.innerWidth`/`scrollWidth` back to the correct 375, and
releasing it reproduced the 957 inflation again — repeatable on demand.

A related but distinct issue was found and fixed in the same pass:
`coding.unloved-heart.net`'s post bodies contained long unbroken raw URLs
rendered as plain link text with no `overflow-wrap`, which forced *that*
page horizontally wider than the viewport on mobile — a separate route to
the same class of layout-viewport-vs-visual-viewport drift. Fixed
site-side (not in this repo) by adding `overflow-wrap: break-word` to that
site's `#fanupdate .fu-entry` rule, but calling it out here since any
`fu-lightbox`-using site with long unbroken strings in post content is
exposed to it too — see Fix below for the corresponding starter-CSS
update.

### Fix

- [x] `assets/js/fanupdate.js`'s `fuLightboxInit()` — lock `document.body`
  scroll (`position: fixed` + negative `top` offset from the current
  `scrollY`, `width: 100%`) when the overlay opens, and restore the
  original scroll position when `fuLbClose()` finishes its
  `transitionend` handler. Verified live in the browser: with the lock
  applied, `window.innerWidth`/`scrollWidth` read the correct 375 (not
  957) and `#fu-lightbox-img`'s `getBoundingClientRect()` centers within
  the real 812px-tall viewport instead of a phantom 2073px one.
- [x] `admin/get-code.php`'s CSS starter snippet — added
  `overflow-wrap: break-word;` to `#fanupdate .fu-entry {}` so long
  unbroken strings in post bodies (raw URLs, file paths) can't force
  horizontal page overflow on mobile in the first place, for any site
  using the default templates.

**Second root cause found after the above shipped:** deploying the fixed
`fanupdate.js` to `coding.unloved-heart.net` did not fix the bug on a real
device — the site's hosting serves `assets/js/*.js` with
`Cache-Control: public, max-age=604800` (confirmed via `curl -I`), and
`class/FanUpdate.php` emitted `<script src=".../fanupdate.js">` with no
cache-busting query string. Any browser that had already loaded the
`/updates` page before the fix shipped — including the reporter's own
phone, from testing the original bug — kept serving its week-old cached
copy of the old, unfixed JS from disk, never re-requesting the server at
all. Confirmed directly: `fetch(..., {cache: 'no-store'})` returned the
new file, but the page's normal `<script src>` load (no cache directive
override) executed the stale one — `document.body.style.position` stayed
empty after clicking a thumbnail, proving the old code paths, not the new
scroll-lock, ran.

- [x] `class/FanUpdate.php` — added a private `assetUrl(string $baseUrl,
  string $relativePath): string` helper that appends `?v=<filemtime>` to
  an asset URL (falls back to no query string if the file can't be
  stat'd). Both `<script src="…/fanupdate.js">` emission points in
  `printBlog()` now go through it, so editing `assets/js/fanupdate.js`
  changes the emitted URL and forces every browser — even ones with a
  week-old cached copy — to fetch the new file on next page load, instead
  of silently continuing to run stale JS until the cache header expires.

**Third issue, unrelated to the code above but what actually caused the
worst pain in this ticket:** deploying the `assetUrl()` fix to
`coding.unloved-heart.net` produced an immediate site-wide 500 on every
`/update-blog` route (not just `/updates` — `admin/index.php`, `rss.php`,
all of it), with nothing in the host's PHP error log (checked both the
DirectAdmin "Error log" page — Tail and Full, both empty — and RAW).
`php -l` and an isolated standalone run of the exact `assetUrl()` method
both succeeded, so the method itself was never the problem. Rolling back
to a version of `class/FanUpdate.php` with `assetUrl()` fully removed
*also* still 500'd, which was the real tell: the repo checkout used to
build that "rollback" was itself stale relative to what had actually been
running in production (missing unrelated fixes made directly against the
live/dev install — e.g. `cookiePath()`-scoped auth/CSRF cookies — that
never made it back into this checkout). Every upload built from that
stale checkout was silently downgrading production, not restoring it,
regardless of whether it carried the cache-busting change. Resolved by
copying the actual known-good `class/FanUpdate.php` back from the live
dev install, reapplying just the `assetUrl()` diff on top of *that*, and
confirming the diff against the restored file touched nothing else.
**Lesson for next time:** if a deploy from this checkout ever breaks
something with zero PHP error log output, suspect the checkout being
stale before suspecting the new change — diff the "known-good" rollback
candidate against what's actually live (or ask for a copy of the live
file) before trusting it as a rollback target.

### Testing

- [x] Verified live on `coding.unloved-heart.net`'s `/updates` page from a
  real device (Samsung/Android Chrome) after the cache-busting fix
  shipped: lightbox image renders centered and fully visible, confirming
  both the scroll-lock fix and the `?v=` cache-buster (the device had a
  cached pre-fix copy of `fanupdate.js` from earlier testing, so this also
  confirms the new URL was actually fetched instead of serving the stale
  cached one).
- [x] Confirm the scroll-lock doesn't fight with `scroll-behavior: smooth`
  (set on `html` in at least one downstream skin) when scroll position is
  restored via `window.scrollTo()` on close. *(Not exercised by the
  coding.unloved-heart.net verification above — that site's `html` doesn't
  set `scroll-behavior: smooth`.)*
  Root cause confirmed by code review: `fuLbClose()`'s restore call was a
  bare `window.scrollTo(0, y)`, which inherits `scroll-behavior` from
  `html`/`body` per spec — on a smooth-scroll skin this would visibly
  animate back to `y` instead of snapping there instantly, unlike the
  scroll-lock's own capture/lock step which never went through CSS
  scrolling at all. Fixed in `assets/js/fanupdate.js`'s `fuLbClose()` by
  switching to `window.scrollTo({ top: y, left: 0, behavior: 'instant' })`
  — an explicit JS `behavior` always overrides the CSS property, so the
  restore now snaps regardless of the skin's `scroll-behavior` setting.
- [x] Page scroll position is preserved after closing the lightbox (not
  reset to top), and repeated open/close doesn't accumulate a scroll
  offset drift — not explicitly re-checked after the final fix, only that
  the image renders correctly. Low risk (straightforward save/restore of
  `scrollY`) but worth a quick manual pass.
  Verified by code review: each open reads `window.scrollY` fresh at
  click time (not from any stale/accumulated state) and stores it in
  `body.style.top`; each close reads that same value back via
  `-parseInt(body.style.top)`, clears `position`/`top`/`width`, then
  restores via `scrollTo` — no counter or running total involved, so
  repeated open/close cycles can't drift. No live-device re-test performed
  this pass; flagging that a manual on-device check is still worthwhile
  before considering this fully closed.

---

## FP-32. Link Each Post's Published Front-End Page from the Entries List

**Status:** Complete — shipped in Redux 2.6.0

### Background

`admin/blog.php`'s Entries table (the `<table class="sortable" aria-label="Blog
entries">` listing) links a post's Title to `blog.php?id=…` (the admin editor)
and its Comments count to `comment.php?entry=…`, but has no link out to the
post's actual live, public-facing page — an admin who wants to see how a
published entry looks on the real site has to leave Admin entirely and either
browse the blog manually or hand-build the URL. `FanUpdate_Post` already
computes this: `getUrl()` returns the permalink built from the `blog_page`
option passed in at construction (`new FanUpdate_Post($row, $fu,
$fu->getOpt('blog_page'))`, same call already in `admin/blog.php` — see
FP-29's fix for `printBlog()`'s equivalent call).

### Goal

Add a "View" link per row in the Entries table that opens the post's real
front-end page, for published entries.

### Approach

- [x] Add a link using `$post->getUrl()` to each row, alongside the existing
Edit/Delete action links (or as its own column) — see the existing loop in
`admin/blog.php` around the `<tbody>` block. Added as its own "View" cell
in the Actions group, before Edit/Delete (`admin/blog.php:540-542`);
`<th ... colspan="2">Actions</th>` bumped to `colspan="3"` to match.
- [x] Only show it when `$post->isPublic()` — a draft has no meaningful
public URL to view (it won't render on the front end at all, or will 404/
redirect depending on how `show-blog.php` handles an unpublished `id`;
confirm which before deciding whether to hide the link entirely for
drafts or show it disabled with a tooltip explaining why). Confirmed:
every `show-blog.php` query (`?view=archive`'s two lists and the main
listing) is scoped `WHERE is_public > 0 AND added <= UTC_TIMESTAMP()`,
so a draft's `?id=` simply matches no row — an empty page, not a real
404. Decided to hide the link entirely for drafts rather than show a
disabled one, since there's no genuinely reachable URL to point at
(matches the ticket's own framing of the choice). The `<td>` is still
rendered empty (not omitted) for a draft row, to keep every row's
column count matching the header's `colspan="3"`.
- [x] Open in a new tab (`target="_blank" rel="noopener noreferrer"`) so
following it doesn't navigate the admin away from the Entries list.
- [x] Escape the URL the same way other dynamic `href`s in this file are
escaped (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).

### Testing

- [x] No PHPUnit coverage needed — this is plain markup in
non-injectable admin-page code, same category as FP-28's button-label
work. Verify manually: a published entry's View link opens the correct
live front-end URL in a new tab; a draft entry either shows no View link
or a clearly-disabled one, per whatever the Approach step above decides.
Full suite re-run regardless to confirm nothing else regressed: **531
tests, 1504 assertions, 0 failures** (unchanged — no PHP logic touched,
same as FP-28). Not exercised in a live browser this pass (no running
install in this environment).

---

## FP-33. Manual ZIP Upload Option in the Admin Update Manager

**Status:** Complete — shipped in Redux 2.6.0

### Background

`admin/update.php`'s Admin Update Manager can only install a release it
downloaded itself from the configured GitHub provider
(`UpdateManager::downloadLatestRelease()` → `installLatestRelease()`) — an
admin whose server can't reach GitHub over outbound HTTPS, or who wants to
install a specific build that isn't published as a GitHub release, has no
in-panel option at all; they'd have to replace files by hand over FTP (the
existing "Database updates" section already anticipates *that* scenario, for
running pending migrations afterward — see its fieldset's copy — but nothing
existing actually performs the file replacement itself).

Lumora Gallery (`class/Service/UpdaterService.php`'s
`acquireLockFromUpload()`, `admin/ajax_update_upload.php`, LG-042) already
solved this for a sibling project: an "Install from Uploaded ZIP" card lets
an admin upload a release ZIP directly; it's validated (structure, unsafe
paths, entry-count/size caps, version detected from the archive's own
`version.php`) and then fed into the exact same install/backup/rollback
pipeline a provider-fetched release goes through — no separate code path to
maintain in parallel. FanUpdateRedux's own updater is architected
differently (synchronous full-page POST + `?action=install-progress`
polling, not Lumora's multi-stage AJAX pipeline), so the *mechanics* won't
match 1:1, but the same principle applies: reuse `InstallManager`/
`BackupManager`/`RollbackManager` unchanged, only the *source* of the ZIP
differs from the GitHub-download flow.

### Goal

Add an "Install from Uploaded ZIP" section to `admin/update.php` that lets
an admin with `site_configuration`-equivalent access (this project's
`login()` gate) upload a `.zip` release directly and install it through the
same validated pipeline as a GitHub-fetched release, including the same
automatic backup and rollback-on-failure behavior.

### Approach

- [x] `class/Service/UpdateManager.php` — extract the shared backup +
install + auto-rollback-on-failure + manifest/log bookkeeping out of
`installLatestRelease()` into a private `performInstall(string $zipPath,
string $version): array`, so both the existing GitHub-download flow and
the new upload flow run through one pipeline rather than two
near-duplicate copies of that logic.
- [x] `class/Service/UpdateManager.php` — new public
`installUploadedRelease(string $uploadedTmpPath): array`: opens the
uploaded file directly at its PHP-assigned tmp path with `ZipArchive` to
confirm it's a valid archive and locate `version.php` (root level, or one
level down inside a single wrapping directory — mirrors
`InstallManager::extractAndValidate()`'s own wrapper-stripping so a
GitHub auto-generated source ZIP uploaded manually works identically to
one downloaded automatically), parses `FU_VERSION` out of it via the same
`const\s+FU_VERSION\s*=\s*'...'` pattern `version.php` actually uses, and
normalizes it with the existing `normalizeVersion()`. Then hands off to
`DownloadManager` to move the file into the managed temp directory and
compute its SHA-256 (see next item), and finally to the new
`performInstall()`.
- [x] `class/Service/DownloadManager.php` — new public
`acceptUploadedFile(string $uploadedTmpPath, string $version):
DownloadResult`: moves (`rename()`, falling back to `copy()` + `unlink()`
across filesystem boundaries — same fallback `InstallManager` already
uses elsewhere) the uploaded file into `$tempDir` using the same
`release-<version>-<random>.zip` naming `download()` uses, confirms ZIP
integrity via the existing private `isValidZip()`, and computes SHA-256 —
reusing exactly the validation `download()` already does post-write,
just without the HTTPS/host/expected-checksum steps that don't apply to
a local upload. `DownloadResult::checksumVerified` is always `false` for
an upload (there is no published checksum to verify against — distinct
from a mismatch, same distinction the class docblock already documents
for the no-checksum-published case).
- [x] `admin/update.php` — new `elseif ($action === 'upload_install')`
handler: validates `$_FILES['update_zip']` the same way
`admin/upload.php` validates `$_FILES['image']` (missing-file/
`UPLOAD_ERR_*` mapping/`is_uploaded_file()` check), then calls
`$updateManager->installUploadedRelease(...)`. On success, redirects to
`update.php?action=run-upgrades&...` with the exact same query
parameters the existing `action === 'install'` handler already builds
(installed_version/installed_files/removed_files/
install_script_removed) — same rationale as that handler's own comment:
a fresh request is needed so the just-installed files (not the
already-loaded ones from this request) run the post-install DB-migration
step.
- [x] `admin/update.php` — new "Install from Uploaded ZIP" fieldset
(plain `<form enctype="multipart/form-data">`, no AJAX — matches every
other action on this page, not Lumora's fetch-driven approach), always
rendered regardless of GitHub update-check status (an admin may want to
install a specific build even when already "up to date" per the
provider), with a `.zip` file input and a short explanation that it goes
through the same validated backup/rollback pipeline as a GitHub-fetched
install.
- [x] No size cap beyond PHP's own `upload_max_filesize`/`post_max_size`
— unlike Lumora's explicit entry-count/uncompressed-size caps, this
project's `InstallManager::extractAndValidate()` doesn't impose one for
the GitHub-download path either, so the upload path doesn't need to be
stricter than the path it's mirroring.

### Testing

- [x] No PHPUnit coverage for the `admin/update.php` form/handler itself
(plain POST-handling admin-page code, same category as FP-28/FP-32).
`class/Service/UpdateManager.php`'s `performInstall()` extraction and
`installUploadedRelease()`'s version-detection regex/wrapper-stripping
logic are unit-testable in isolation (fakes for
`DownloadManager`/`BackupManager`/`InstallManager`/`RollbackManager`,
same pattern the existing `UpdateManager` test suite already uses) —
add coverage there if a `UpdateManagerTest.php` (or equivalent) already
exists; otherwise this is manual-verification-only, matching how the
original GitHub-based install flow was verified.
`UpdateManagerTest.php` already existed — added
`testInstallUploadedReleaseReturnsErrorWhenNo{Install,Backup,Download}ManagerProvided`,
`testInstallUploadedReleaseReturnsErrorWhenFileIsMissing`,
`testInstallUploadedReleaseReturnsErrorWhenVersionCannotBeDetected`,
`testInstallUploadedReleaseDetectsVersionInsideASingleWrapperDirectory`
(a real ZIP fixture via `ZipArchive`, mirroring the GitHub-auto-zip case),
`testInstallUploadedReleaseSucceedsAndPersistsStateAndCleansUpStagedCopy`,
and `testInstallUploadedReleaseAutomaticallyRollsBackOnInstallFailure`.
`DownloadManagerTest.php` got
`testAcceptUploadedFileMovesAndValidatesAGenuineZip`,
`testAcceptUploadedFileSanitizesTheVersionInTheDestinationFilename`,
`testAcceptUploadedFileThrowsAndCleansUpWhenNotAValidZip`, and
`testAcceptUploadedFileThrowsWhenSourceFileIsMissing`. Full suite run:
**531 tests, 1504 assertions, 0 failures** — see `PHP Test Suite/
TEST_LOG.md`'s "August 18, 2026 (FP-33 …)" entry.
- [x] *(Confirmed by Ariane 2026-11-03: manual ZIP upload tested on the
production site and works.)* Manually verify: uploading a valid release ZIP installs it and runs
pending migrations exactly like a GitHub-fetched install; uploading a
non-ZIP file or a ZIP missing `version.php`/`index.php`/`admin/`/
`class/` is rejected with a clear error and touches nothing; an upload
that fails partway through the install still triggers the same
automatic-rollback-to-backup behavior `installLatestRelease()` already
has; a GitHub auto-generated source ZIP (single wrapping folder) uploaded
manually installs correctly, matching the download path's own
wrapper-handling.

---

## FP-43. Media Page Bulk Actions

**Status:** Complete — shipped in Redux 2.6.0

Add Bulk functions to Admin media page: Delete

Mirrors the blog list's bulk delete (checkboxes → `bulk-delete` confirm
view → `bulk-delete-confirmed`). Logic lives in
`MediaService::deleteImages()`/`getBulkDeleteConfirmation()`; selection
reuses `fanupdate-post-admin.js` for the select-all toggle.

### Checklist

- [x] `MediaService::deleteImages()` / `getBulkDeleteConfirmation()`
- [x] `admin/media.php`: checkboxes, select-all, Delete Selected, confirm view (CSRF-protected)
- [x] Styles in `style.css` and `style-night.css`; unit tests; README/CHANGELOG
- [x] Dev install synced
- [x] Click-through on the dev install (2026-11-03): select-all sync, confirm view, delete, empty-selection error all verified. Seeded rows had no files on disk, so the real-file unlink path is covered by unit tests only.

---

## FP-44. `myErrorHandler()` Ignores the `@` Operator on PHP 8+

**Status:** Complete — shipped in Redux 2.6.0

### Background

Found while adding PHP 8.6 to the test matrix. `functions.php`'s
`myErrorHandler()` detected `@` suppression with `error_reporting() === 0`.
Since PHP 8.0, `@` narrows the mask to fatal-class errors (4437) instead of
zeroing it, so the check never matched and every suppressed warning —
`@unlink()`, `@rmdir()` in `MediaService`, `InstallManager`,
`RollbackManager`, `BackupManager`, `DownloadManager`, `reset-password.php`
— was echoed into the page as a `<p class="error"><strong>WARNING:</strong>`
paragraph. It affects every supported PHP version, not just 8.6; PHPUnit
reported "OK" regardless because the output was echoed, not raised.

### Checklist

- [x] Replace the `=== 0` check with `!(error_reporting() & $errno)`
- [x] Regression tests (`Unit/ErrorHandlerFunctionsTest.php`) — verified red
      against the old check, green against the fix
- [x] Matrix harness now passes `--display-warnings --display-deprecations
      --display-phpunit-deprecations --display-notices` and fails a version
      on "OK, but there were issues", echoed `WARNING:` output, or
      `Warning:`/`Deprecated:`/`Notice:` lines

---

## FP-45. Redirect to the Installer When FanUpdate Redux Isn't Installed Yet

**Status:** Complete — shipped in Redux 2.6.0

### Background

`index.php` unconditionally 301-redirects to `admin/index.php`, which starts
with `require_once('../blog-config.php')`. On a fresh upload (no
`blog-config.php` yet, or one whose database tables haven't been created),
a visitor to the front page therefore lands on a fatal error / blank page
instead of being pointed at `install.php`.

### Goal

When someone opens the install's front page (`index.php`) and FanUpdate
Redux isn't installed, send them to `install.php` automatically.

Implemented as `class/Service/InstallDetector.php` (dependency-free, since
`functions.php` can't load without `blog-config.php`). "Not installed" =
`install.php` present AND (no `blog-config.php`, DB unreachable, or options
table missing); the DB probe only runs while `install.php` exists, so
finished installs pay one `is_file()`. Verified with a scratch copy on
PHP's built-in server (all six states) plus unit tests.

### Checklist

- [x] Decide the "not installed" test — at minimum `blog-config.php`
      missing; consider also "config present but options table missing"
      (must not fire on a healthy install, and must be cheap since
      `index.php` is hit on every visit)
- [x] Only redirect when `install.php` still exists; once it has deleted
      itself, fall through to the normal `admin/index.php` redirect
- [x] Use a temporary redirect (302), not the current permanent 301, so
      browsers don't cache "front page → installer" after install finishes
- [x] Consider the same guard at the top of `admin/index.php` (the most
      likely direct entry point) so `…/admin/` also lands on the installer
      instead of failing on the missing config
- [x] Never expose filesystem paths or stack traces in any failure path
- [x] Unit/regression tests for the "not installed" detection (extract it
      into a small testable function in `functions.php` or a class)
- [x] README (Installation) and CHANGELOG `[Unreleased]` entries

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
