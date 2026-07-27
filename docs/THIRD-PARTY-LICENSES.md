# Third-Party Licenses

FanUpdate Redux is licensed under the GNU GPL v3 (see `LICENSE.md`). It vendors
a small number of third-party libraries directly into `class/` rather than via
Composer, since the production application assumes no CLI/Composer access on
typical shared hosting (see `CLAUDE.md`). This file records their own licenses,
as required by each.

---

## Parsedown

- **File:** `class/Parsedown.php`
- **Version:** 1.8.0 (unmodified)
- **Source:** https://github.com/erusev/parsedown
- **Used for:** Rendering GitHub Flavored Markdown release notes in
  **Admin → Updates** (`class/Service/ReleaseNotesRenderer.php`).
- **License:** MIT

> The MIT License (MIT)
>
> Copyright (c) 2013-2018 Emanuil Rusev, erusev.com
>
> Permission is hereby granted, free of charge, to any person obtaining a copy of
> this software and associated documentation files (the "Software"), to deal in
> the Software without restriction, including without limitation the rights to
> use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
> the Software, and to permit persons to whom the Software is furnished to do so,
> subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all
> copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
> FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
> COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
> IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
> CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

## Universal Feed Writer

- **File:** `class/FeedWriter.php` / `class/FeedItem.php`
- **Source:** https://github.com/mibe/FeedWriter (originally by Anis uddin Ahmad)
- **Used for:** RSS 2.0 / Atom feed generation (`rss.php`, `rss-comments.php`).
- **License:** See the copyright header at the top of `class/FeedWriter.php`.
  Recorded here for completeness now that this file also exists; no license
  terms have changed.

---

## TinyMCE

- **Files:** `assets/vendor/tinymce/` (vendored via `npm pack tinymce@7`,
  unmodified upstream `.min.js`/`.min.css` files only — no source `.js`/
  `.css`, `.d.ts`, ES-module `index.js` wrappers, or shadow-DOM variants,
  since this project loads it the classic way via a single
  `<script src="tinymce.min.js">`, not as an npm/webpack dependency)
- **Version:** 7.9.3
- **Source:** https://github.com/tinymce/tinymce
- **Used for:** The post-body editor in **Admin → Entries**
  (`admin/blog.php`, TODO.md item "FP-18. Implement TinyMCE Editor"),
  replacing the previous hand-rolled WYSIWYG toolbar.
- **Trimmed to:** core (`tinymce.min.js`), the `silver` theme, the `dom`
  model, the `default` icon pack, the `oxide`/`oxide-dark` UI skins and
  `default`/`dark` content skins (day/night theme parity — see
  `assets/js/fanupdate-admin.js`'s theme-toggle wiring), and only the
  plugins TODO.md's FP-18 asked for that still exist as separate plugins in
  TinyMCE 7: `lists`, `link`, `table`, `code`, `fullscreen`, `autoresize`,
  `charmap`. FP-18 also listed `paste` and `hr` — both are core editor
  behaviour as of TinyMCE 6+ (paste handling and horizontal-rule insertion
  no longer have dedicated plugins), so there is nothing separate to
  vendor for either. Image insertion is deliberately NOT the stock `image`
  plugin — see `class/Service/MediaService.php`'s docblock and
  `assets/js/fanupdate-admin.js` for the custom Upload/Choose Existing
  toolbar button wired to `admin/upload.php`/`admin/media.php` instead.
- **License:** GPL-2.0-or-later. Upstream's own license notice is vendored
  alongside the code at `assets/vendor/tinymce/license.md`; full GPL v2 text
  at http://www.gnu.org/licenses/gpl.html.

> Copyright (c) 2024, Ephox Corporation DBA Tiny Technologies, Inc.
> Licensed under the terms of the GNU General Public License Version 2 or
> later (http://www.gnu.org/licenses/gpl.html).

### DOMPurify (bundled inside `tinymce.min.js`)

TinyMCE's core bundle statically includes DOMPurify for its own internal
HTML sanitization (pasted content, `valid_elements` enforcement) — not a
separate file in this project, but disclosed here since its license differs
from TinyMCE's own.

- **Source:** https://github.com/cure53/DOMPurify
- **Version:** 3.2.6 (as bundled by TinyMCE 7.9.3 — see
  `assets/vendor/tinymce/notices.txt`)
- **License:** MPL-2.0 OR Apache-2.0 (dual-licensed; either applies)
