/**
 * FanUpdate — fanupdate-comment-submit.js
 *
 * Front-end comment submission via AJAX.
 * Requires: fanupdate.js (makeRequest, $, makeWysiwyg, wysiwygButton,
 *           formatText, insertLink, getEventTarget, addEvent)
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation:
 *  - var → const / let
 *  - Removed implicit global leaks (captcha, success, toRemove)
 *  - form.submit listener uses addEventListener
 *  - captcha element check uses optional chaining
 *  - remember_me element check uses optional chaining (field is optional in
 *    some form templates; accessing .checked on undefined threw a TypeError
 *    that silently killed the submit handler before the AJAX call was made)
 *
 * Bug fix (Redux 2.0.7):
 *  - submitCmtInit(): WYSIWYG toolbar was appearing beside the Comment label
 *    instead of above the textarea. Fixed by passing ta.closest('p, div, li')
 *    as the insertion anchor so the toolbar is placed before the entire row.
 *
 * Redux 2.0.8:
 *  - myWysiwyg buttons now use inline SVG strings instead of external PNG
 *    files. No external image files required.
 *  - submitCmtInit() double-init guard via data-wysiwyg on the textarea.
 *
 * Redux 2.0.9:
 *  - Toolbar uses prefixed class "fu-wysiwygmenu" to avoid host CSS collisions.
 *  - Scoped <style> injected into <head> on first run.
 *
 * Redux 2.0.10:
 *  - width:fit-content prevents the block-promoted toolbar from stretching
 *    full-width.
 *  - alignToolbar() measures the textarea's actual left pixel offset after
 *    a layout tick and sets margin-left exactly.
 *
 * Redux 2.0.11:
 *  - alignToolbar() now also zeroes the margin-top of the <p> immediately
 *    following the toolbar so the toolbar bottom border touches the textarea
 *    top with no gap. The gap was caused by the host theme's p { margin-top }
 *    on the Comment label+textarea <p>.
 *  - Fixed alignToolbar(): toolbar.offsetParent changes after margin-left is
 *    applied (if it triggers a reflow that shifts a positioned ancestor).
 *    Replaced offsetParent subtraction with a direct comparison: reset
 *    margin-left to 0 first, then measure toolbar.getBoundingClientRect().left
 *    vs textarea.getBoundingClientRect().left and apply the difference.
 *    This is a single-pass measurement that doesn't depend on offsetParent.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

function submitCmt(e) {
    e.preventDefault();

    const f        = e.currentTarget;  // the <form>
    const targetID = 'newComment';

    const name        = f.name.value;
    const email       = f.email.value;
    const url         = f.url.value;
    const remember_me = f.remember_me?.checked ? 'y' : '';
    const comment     = f.comment.value;
    const captcha     = f.captcha ? f.captcha.value : '';
    const entry       = f.entry.value;

    if (comment.length === 0) {
        alert('Your comment is blank!');
        return false;
    }

    const qs = 'name='        + encodeURIComponent(name)
             + '&email='      + encodeURIComponent(email)
             + '&url='        + encodeURIComponent(url)
             + '&remember_me='+ encodeURIComponent(remember_me)
             + '&comment='    + encodeURIComponent(comment)
             + '&captcha='    + encodeURIComponent(captcha)
             + '&entry='      + encodeURIComponent(entry)
             + '&submit_comment=ajax';

    makeRequest(f.action, targetID, 'POST', qs, submitCmtCallback);

    location.hash = targetID;

    return false;
}

function submitCmtCallback(responseText, targetID, url) {
    const el = $(targetID);
    el.innerHTML = responseText;

    const success = /^<!-- SUCCESS/;

    if (responseText.match(success)) {
        const toRemove = $('comments-form');
        if (toRemove) {
            toRemove.parentNode.removeChild(toRemove);
        }
    } else {
        // failed — regenerate captcha if enabled
        const captchaImg = $('captcha-img');
        if (captchaImg) {
            const path = url.replace('process.php', '');
            captchaImg.src = path + 'captcha.php?' + Math.random();
        }
    }
}

// ── Inline styles for the front-end toolbar ───────────────────────────────────

const _FU_TOOLBAR_STYLE_ID = 'fu-wysiwyg-style';

function injectToolbarStyles() {
    if (document.getElementById(_FU_TOOLBAR_STYLE_ID)) { return; }

    const style = document.createElement('style');
    style.id = _FU_TOOLBAR_STYLE_ID;
    /* eslint-disable */
    style.textContent = `
#fanupdate .fu-wysiwygmenu {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    width: -moz-fit-content;
    width: -webkit-fit-content;
    width: fit-content;
    margin-bottom: 0;
    padding: 5px 7px;
    background: #f3ecf1;
    border: 1px solid rgba(150,100,140,.3);
    border-radius: 6px 6px 0 0;
    border-bottom: none;
    line-height: 1;
    box-sizing: border-box;
}
#fanupdate .fu-wysiwygmenu + p {
    margin-top: 0 !important;
}
#fanupdate .fu-wysiwygmenu + p textarea,
#fanupdate .fu-wysiwygmenu + textarea {
    border-top-left-radius: 0;
    border-top-right-radius: 0;
}
#fanupdate .fu-wysiwygmenu a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 3px;
    border: 1px solid rgba(150,100,140,.3);
    border-radius: 4px;
    background: #faf7f9;
    color: #7a5872;
    text-decoration: none;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
    box-sizing: border-box;
}
#fanupdate .fu-wysiwygmenu a:hover,
#fanupdate .fu-wysiwygmenu a:focus {
    background: #f5e5f3;
    border-color: #8b3a7e;
    color: #8b3a7e;
    text-decoration: none;
}
#fanupdate .fu-wysiwygmenu a svg {
    display: block;
    width: 16px;
    height: 16px;
    pointer-events: none;
    flex-shrink: 0;
}
#fanupdate .fu-wysiwygmenu a > span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}
@media (prefers-color-scheme: dark) {
    #fanupdate .fu-wysiwygmenu {
        background: #251b22;
        border-color: rgba(255,200,240,.15);
    }
    #fanupdate .fu-wysiwygmenu a {
        background: #1c1519;
        border-color: rgba(255,200,240,.15);
        color: #c4a0bb;
    }
    #fanupdate .fu-wysiwygmenu a:hover,
    #fanupdate .fu-wysiwygmenu a:focus {
        background: rgba(212,126,202,.15);
        border-color: #d47eca;
        color: #d47eca;
    }
}
`;
    /* eslint-enable */
    document.head.appendChild(style);
}

/**
 * alignToolbar — pixel-align the toolbar's left edge to the textarea.
 *
 * Strategy: reset margin-left to 0, read both elements' viewport left via
 * getBoundingClientRect(), apply the difference. This is a clean single-pass
 * measurement that doesn't depend on offsetParent or any previously-set margin.
 *
 * @param {HTMLElement} toolbar  The .fu-wysiwygmenu element.
 * @param {HTMLElement} textarea The textarea the toolbar sits above.
 */
function alignToolbar(toolbar, textarea) {
    // Reset first so our own margin doesn't skew the measurement.
    toolbar.style.marginLeft = '0';

    const toolbarLeft  = toolbar.getBoundingClientRect().left;
    const textareaLeft = textarea.getBoundingClientRect().left;
    const delta        = textareaLeft - toolbarLeft;

    if (delta !== 0) {
        toolbar.style.marginLeft = delta + 'px';
    }
}

// ── Front-end WYSIWYG toolbar ─────────────────────────────────────────────────

/* eslint-disable max-len */
const _SVG_BOLD   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 5h6a3.5 3.5 0 0 1 0 7H7z"/><path d="M13 12h1a3.5 3.5 0 0 1 0 7H7V5"/></svg>';
const _SVG_ITALIC = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="5" x2="15" y2="5"/><line x1="9" y1="19" x2="5" y2="19"/><line x1="15" y1="5" x2="9" y2="19"/></svg>';
const _SVG_LINK   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
/* eslint-enable max-len */

const myWysiwyg = [
    new wysiwygButton('bold',   _SVG_BOLD,   'make bold',   e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '[B]', '[/B]');  return false; }),
    new wysiwygButton('italic', _SVG_ITALIC, 'make italic', e => { e.preventDefault(); formatText(getEventTarget(e).closest('a').className, '[I]', '[/I]');  return false; }),
    new wysiwygButton('link',   _SVG_LINK,   'insert link', e => { e.preventDefault(); insertLink(getEventTarget(e).closest('a').className, true);            return false; }),
];

// ── Form initialisation ─────────────────────────────────────────────────────

function submitCmtInit() {
    const form = $('comments-form');
    if (!form) { return; }

    injectToolbarStyles();

    if (!form.dataset.submitWired) {
        form.addEventListener('submit', submitCmt);
        form.dataset.submitWired = '1';
    }

    const ta = form.getElementsByTagName('textarea')[0];
    if (ta) {
        const anchor = ta.closest('p, div, li') || ta;
        makeWysiwyg(ta, anchor, 'fu-wysiwygmenu');

        // Align after a layout tick so getBoundingClientRect() is accurate.
        const toolbar = anchor.previousElementSibling;
        if (toolbar && toolbar.classList.contains('fu-wysiwygmenu')) {
            setTimeout(() => alignToolbar(toolbar, ta), 0);
        }
    }
}

window.addEventListener('load', submitCmtInit);
