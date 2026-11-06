/**
 * FanUpdate — fanupdate-admin.js
 *
 * Admin-panel bootstrap: confirm-delete wiring, night-theme toggle, and
 * copy-to-clipboard buttons.
 *
 * Requires: fanupdate.js (css, $, addEvent, getEventTarget)
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * The post editor is TinyMCE (assets/js/fanupdate-tinymce.js), loaded only
 * on admin/blog.php.
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
 * and admin/comment.php), so a second JS dialog would be redundant.
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

    // Cache-busting query string (see admin/header.php's fuAssetVersion) must
    // match exactly, or isNight()'s href comparison below silently breaks.
    const versionSuffix = typeof fuAssetVersion !== 'undefined' ? '?v=' + fuAssetVersion : '';
    const BASE  = '../assets/css/style.css' + versionSuffix;
    const NIGHT = '../assets/css/style-night.css' + versionSuffix;
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

// ── Mobile navigation toggle ──────────────────────────────────────────────────

function navToggleInit() {
    const btn = document.getElementById('fu-nav-toggle');
    const nav = document.getElementById('nav');
    if (!btn || !nav) return;

    const setOpen = (open) => {
        nav.classList.toggle('nav-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = open ? 'ti ti-x' : 'ti ti-menu-2';
        }
    };

    btn.addEventListener('click', () => setOpen(!nav.classList.contains('nav-open')));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { setOpen(false); }
    });
    document.addEventListener('click', (e) => {
        if (!nav.contains(e.target)) { setOpen(false); }
    });
}

window.addEventListener('load', () => {
    confirmDeleteInit();
    themeToggleInit();
    navToggleInit();
    fuCopyButtonInit();
});
