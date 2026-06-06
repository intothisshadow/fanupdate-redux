/**
 * FanUpdate — fanupdate-admin.js
 *
 * Admin-panel bootstrap: confirm-delete wiring and WYSIWYG toolbar init.
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
/* eslint-enable max-len */

const myWysiwyg = [
    new wysiwygButton('bold',  _SVG_BOLD,   'make bold',              e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<strong>', '</strong>');         return false; }),
    new wysiwygButton('italic',_SVG_ITALIC, 'make italic',            e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<em>', '</em>');                 return false; }),
    new wysiwygButton('h2',    _SVG_H2,     'make heading level 2',   e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<h2>', '</h2>');                 return false; }),
    new wysiwygButton('h3',    _SVG_H3,     'make heading level 3',   e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<h3>', '</h3>');                 return false; }),
    new wysiwygButton('quote', _SVG_QUOTE,  'make blockquote',        e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<blockquote>', '</blockquote>'); return false; }),
    new wysiwygButton('rm',    _SVG_MORE,   'insert read more break', e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '<!-- MORE -->', '');             return false; }),
    new wysiwygButton('link',  _SVG_LINK,   'insert link',            e => { e.preventDefault(); insertLink(getEventTarget(e).closest('a').className);                                 return false; }),
];

// ── Night theme toggle ────────────────────────────────────────────────────────

function themeToggleInit() {
    const btn  = document.getElementById('fu-theme-toggle');
    const link = document.getElementById('fu-theme-css');
    if (!btn || !link) return;

    const BASE  = '../assets/css/style.css';
    const NIGHT = '../assets/css/style-night.css';
    const KEY   = 'fu_theme';

    // Sync button state to whatever the inline script already applied
    const isNight = () => link.getAttribute('href') === NIGHT;

    function apply(night) {
        link.href = night ? NIGHT : BASE;
        btn.setAttribute('aria-pressed', night ? 'true' : 'false');
        btn.title = night ? 'Switch to day theme' : 'Toggle night theme';
        // Swap icon: sun when night is active, moon otherwise
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = night ? 'ti ti-sun' : 'ti ti-moon';
        }
        try { localStorage.setItem(KEY, night ? 'night' : 'day'); } catch (_) {}
    }

    // Set initial button state to match what the inline script loaded
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
