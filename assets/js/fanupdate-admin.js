/**
 * FanUpdate — fanupdate-admin.js
 *
 * Admin-panel bootstrap: confirm-delete wiring, WYSIWYG toolbar init,
 * night-theme toggle, and image upload support.
 *
 * Requires: fanupdate.js (css, addEvent, getEventTarget, makeWysiwyg,
 *           wysiwygButton, formatText, insertLink)
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation:
 *  - var → const / let
 *  - Replaced for loop with querySelectorAll + forEach
 *  - onclick assignment → addEventListener
 *  - new Array() → array literal
 *
 * Redux 2.0.8:
 *  - myWysiwyg buttons now use inline SVG strings instead of external PNG
 *    files. Removes the dependency on text_bold.png, text_italic.png,
 *    text_heading_2.png, text_heading_3.png, text_signature.png,
 *    text_horizontalrule.png and link.png which were frequently missing
 *    from deployed installs and caused the toolbar to render as broken-image
 *    alt text. The PNG files can be removed from assets/images/ at any time.
 *
 * Image upload (added this release):
 *  - "Insert image" button added to the WYSIWYG toolbar.
 *  - Clicking it triggers a hidden <input type="file"> picker filtered to
 *    JPEG, PNG, GIF and WebP.
 *  - The selected file is POSTed to admin/upload.php via fetch() + FormData.
 *  - A small spinner badge is shown on the toolbar button while the upload
 *    is in progress.
 *  - On success the endpoint returns JSON {"ok":true,"url":"…","thumb":"…"}.
 *    The JS inserts:
 *      <a href="FULL_URL" class="fu-lightbox"><img src="THUMB_URL" alt="" /></a>
 *    at the cursor position that was active when the image button was clicked
 *    (saved before the file picker opens, since opening the picker loses focus
 *    and resets selectionStart to 0 in most browsers).
 *  - On failure the error message from the endpoint is shown in an alert().
 *  - The hidden file input is shared across all WYSIWYG instances on the page
 *    (there is only ever one on blog.php) and reused on subsequent uploads.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

function confirmDelete(e) {
    const button = getEventTarget(e);
    if (!confirm(button.title + '?')) {
        return false;
    }
    return true;
}

function confirmDeleteInit() {
    css.getElementsByClass(document, 'delete', '*').forEach(el => {
        el.addEventListener('click', confirmDelete);
    });
}

// ── Admin WYSIWYG toolbar icons (inline SVG, Tabler style) ───────────────────
// 24×24 viewport, stroke-only, currentColor — no external files needed.

/* eslint-disable max-len */
const _SVG_BOLD      = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 5h6a3.5 3.5 0 0 1 0 7H7z"/><path d="M13 12h1a3.5 3.5 0 0 1 0 7H7V5"/></svg>';
const _SVG_ITALIC    = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="5" x2="15" y2="5"/><line x1="9" y1="19" x2="5" y2="19"/><line x1="15" y1="5" x2="9" y2="19"/></svg>';
const _SVG_H2        = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6v12"/><path d="M4 12h8"/><path d="M12 6v12"/><path d="M17 12a2 2 0 1 1 4 0c0 .591-.417 1.318-.816 1.858L17 19h4"/></svg>';
const _SVG_H3        = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6v12"/><path d="M4 12h8"/><path d="M12 6v12"/><path d="M17.5 10.5a2 2 0 1 1 0 3 2 2 0 0 1 0 3a2 2 0 1 1 0-3"/></svg>';
const _SVG_QUOTE     = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/></svg>';
const _SVG_MORE      = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="12" x2="21" y2="12"/><polyline points="8 8 12 4 16 8"/><polyline points="16 16 12 20 8 16"/></svg>';
const _SVG_LINK      = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
const _SVG_IMAGE     = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
/* eslint-enable max-len */

// ── Image upload state ────────────────────────────────────────────────────────

// The hidden file input and the textarea/cursor state it was opened for.
let _fuUploadInput     = null;  // <input type="file"> (created once, reused)
let _fuUploadTextarea  = null;  // the textarea the upload button belongs to
let _fuUploadCursorPos = 0;     // selectionStart saved BEFORE the picker opens

/**
 * fuInsertImage — called when the upload.php response arrives successfully.
 * Inserts <a href="FULL" class="fu-lightbox"><img src="THUMB" alt="" /></a>
 * at the cursor position that was saved when the image button was clicked.
 *
 * We cannot use textarea.selectionStart at response time because opening the
 * OS file picker causes the textarea to lose focus, which resets selectionStart
 * to 0 in most browsers. Instead we save the position in _fuUploadCursorPos
 * immediately on button click (before the picker is shown) and use it here.
 */
function fuInsertImage(textarea, fullUrl, thumbUrl) {
    const snippet =
        '<a href="' + fullUrl + '" class="fu-lightbox">' +
        '<img src="' + thumbUrl + '" alt="" /></a>';

    const pos    = _fuUploadCursorPos;
    const before = textarea.value.substring(0, pos);
    const after  = textarea.value.substring(pos);

    textarea.value = before + snippet + after;

    // Place cursor after the inserted snippet and restore focus.
    const newPos = pos + snippet.length;
    textarea.focus();
    textarea.setSelectionRange(newPos, newPos);
}

/**
 * fuUploadImage — called when the file input fires a 'change' event.
 * POSTs to admin/upload.php and handles the JSON response.
 */
function fuUploadImage(e, uploadBtn) {
    const file = e.target.files[0];
    if (!file) { return; }

    const ta = _fuUploadTextarea;
    if (!ta) { return; }

    // Reset the input so the same file can be re-selected after an error.
    e.target.value = '';

    // Show spinner: replace button icon with a loading SVG.
    const iconWrap = uploadBtn.querySelector('span');
    let savedHTML = null;
    if (iconWrap) {
        savedHTML = iconWrap.innerHTML;
        iconWrap.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg>';
    }
    uploadBtn.style.pointerEvents = 'none';
    uploadBtn.title = 'Uploading…';

    const restore = () => {
        if (iconWrap && savedHTML !== null) { iconWrap.innerHTML = savedHTML; }
        uploadBtn.style.pointerEvents = '';
        uploadBtn.title = 'insert image';
    };

    const fd = new FormData();
    fd.append('image', file);

    fetch('upload.php', { method: 'POST', body: fd })
        .then(r => {
            if (!r.ok) { throw new Error('Server error ' + r.status); }
            return r.json();
        })
        .then(data => {
            restore();
            if (data.ok) {
                fuInsertImage(ta, data.url, data.thumb);
            } else {
                alert('Upload failed: ' + (data.error || 'Unknown error.'));
            }
        })
        .catch(err => {
            restore();
            alert('Upload error: ' + err.message);
        });
}

/**
 * fuImageButtonClick — handler for the toolbar "insert image" button.
 *
 * Saves the textarea reference and the current cursor position BEFORE opening
 * the file picker. Once the OS file dialog opens the textarea loses focus and
 * selectionStart resets — the saved position is used by fuInsertImage() later.
 */
function fuImageButtonClick(e, textarea) {
    e.preventDefault();

    _fuUploadTextarea  = textarea;
    // Save cursor position now — before focus is lost to the file picker.
    _fuUploadCursorPos = textarea.selectionStart;

    // Create the hidden file input once per page.
    if (!_fuUploadInput) {
        _fuUploadInput = document.createElement('input');
        _fuUploadInput.type   = 'file';
        _fuUploadInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
        _fuUploadInput.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;';
        _fuUploadInput.setAttribute('aria-hidden', 'true');
        _fuUploadInput.setAttribute('tabindex', '-1');
        document.body.appendChild(_fuUploadInput);
    }

    // Re-bind the change handler each time so it captures the current button.
    const btn = e.currentTarget || getEventTarget(e);
    _fuUploadInput.onchange = ev => fuUploadImage(ev, btn);

    _fuUploadInput.click();

    return false;
}

const myWysiwyg = [
    new wysiwygButton('bold',  _SVG_BOLD,   'make bold',              e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<strong>', '</strong>');         return false; }),
    new wysiwygButton('italic',_SVG_ITALIC, 'make italic',            e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<em>', '</em>');                 return false; }),
    new wysiwygButton('h2',    _SVG_H2,     'make heading level 2',   e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<h2>', '</h2>');                 return false; }),
    new wysiwygButton('h3',    _SVG_H3,     'make heading level 3',   e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<h3>', '</h3>');                 return false; }),
    new wysiwygButton('quote', _SVG_QUOTE,  'make blockquote',        e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<blockquote>', '</blockquote>'); return false; }),
    new wysiwygButton('rm',    _SVG_MORE,   'insert read more break', e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<!-- MORE -->', '');             return false; }),
    new wysiwygButton('link',  _SVG_LINK,   'insert link',            e => { e.preventDefault(); insertLink(getEventTarget(e).closest('a').className);                                 return false; }),
    // Image upload — handler is set dynamically in makeWysiwyg() because it
    // needs a reference to the specific textarea instance. The func here is a
    // placeholder that makeWysiwyg() replaces with a closure.
    new wysiwygButton('image', _SVG_IMAGE,  'insert image',           null),
];

// ── Night theme toggle ────────────────────────────────────────────────────────

function themeToggleInit() {
    const btn  = document.getElementById('fu-theme-toggle');
    const link = document.getElementById('fu-theme-css');
    if (!btn || !link) return;

    const BASE  = '../assets/css/style.css';
    const NIGHT = '../assets/css/style-night.css';
    const KEY   = 'fu_theme';

    const isNight = () => link.getAttribute('href') === NIGHT;

    function apply(night) {
        link.href = night ? NIGHT : BASE;
        btn.setAttribute('aria-pressed', night ? 'true' : 'false');
        btn.title = night ? 'Switch to day theme' : 'Toggle night theme';
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = night ? 'ti ti-sun' : 'ti ti-moon';
        }
        try { localStorage.setItem(KEY, night ? 'night' : 'day'); } catch (_) {}
    }

    apply(isNight());

    btn.addEventListener('click', () => apply(!isNight()));
}

window.addEventListener('load', () => {
    confirmDeleteInit();
    themeToggleInit();

    css.getElementsByClass(document, 'wysiwyg', 'textarea').forEach(ta => {
        makeWysiwyg(ta);
    });
});
