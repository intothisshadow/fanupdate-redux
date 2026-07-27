/**
 * FanUpdate — fanupdate-admin.js
 *
 * Admin-panel bootstrap: confirm-delete wiring, night-theme toggle, and
 * copy-to-clipboard buttons.
 *
 * Requires: fanupdate.js (css, $, addEvent, getEventTarget)
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
 * TinyMCE editor (TODO.md item "FP-18. Implement TinyMCE Editor"):
 *  - The hand-rolled WYSIWYG toolbar (myWysiwyg/wysiwygButton SVG icons,
 *    fuImageButtonClick()/fuOpenImageModal()/fuBuildImageModal()/
 *    fuUploadImage()/fuInsertImage() and friends) that used to live in this
 *    file has been removed — admin/blog.php's post-body textarea (its only
 *    caller; confirmed via a full-codebase search before deleting) is now a
 *    self-hosted TinyMCE 7 instance instead, initialised by the new
 *    assets/js/fanupdate-tinymce.js (loaded only on that page, not
 *    globally, since TinyMCE's assets have no reason to load anywhere
 *    else). See that file for the replacement image-upload/Choose Existing
 *    picker logic and docs/CHANGELOG.md for the full removal note.
 *  - fanupdate.js's makeWysiwyg()/wysiwygButton()/formatText()/insertLink()
 *    were kept alive at first (still used by the public comment form's own
 *    lightweight BBCode-ish toolbar), but that toolbar was removed shortly
 *    after per a direct request — the comment form is now a plain
 *    <textarea> (see assets/js/fanupdate-comment-submit.js) — leaving those
 *    fanupdate.js functions with no callers anywhere, so they were deleted
 *    too.
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
        // addEventListener handlers can't cancel the default action just by
        // returning false (that only works for old-style onclick="" attributes)
        // — preventDefault() is required to actually stop the click/submit.
        e.preventDefault();
        return false;
    }
    return true;
}

/**
 * confirmDeleteInit — wire a JS confirm() dialog onto every '.delete'
 * element as a safeguard for pages that delete directly from a single form
 * (e.g. the comment/category/blacklist/smilies/templates edit forms).
 *
 * Elements carrying [data-skip-confirm] are skipped: these are pages where a
 * dedicated server-side confirmation view already exists (see admin/blog.php
 * and admin/comment.php, Milestone 3), so a second JS-only dialog on top of
 * it would just be redundant friction.
 */
function confirmDeleteInit() {
    css.getElementsByClass(document, 'delete', '*').forEach(el => {
        if (el.hasAttribute('data-skip-confirm')) { return; }
        el.addEventListener('click', confirmDelete);
    });
}

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

/**
 * fuCopyButtonClick — copies the text content of the textarea named by the
 * button's data-copy-target id to the clipboard, with a brief "Copied!"
 * label swap for feedback. Falls back to textarea.select() + execCommand()
 * on browsers/contexts where navigator.clipboard is unavailable (e.g. a
 * non-HTTPS admin install — Clipboard API is restricted to secure contexts).
 */
function fuCopyButtonClick(e) {
    const btn    = e.currentTarget;
    const target = $(btn.getAttribute('data-copy-target'));
    if (!target) { return; }

    const showCopied = () => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i> Copied!';
        setTimeout(() => { btn.innerHTML = original; }, 1500);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(target.value).then(showCopied, () => {
            target.select();
            document.execCommand('copy');
            showCopied();
        });
    } else {
        target.select();
        document.execCommand('copy');
        showCopied();
    }
}

function fuCopyButtonInit() {
    css.getElementsByClass(document, 'fu-copy-btn', 'button').forEach(btn => {
        btn.addEventListener('click', fuCopyButtonClick);
    });
}

window.addEventListener('load', () => {
    confirmDeleteInit();
    themeToggleInit();
    fuCopyButtonInit();
});
