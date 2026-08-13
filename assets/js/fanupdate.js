/**
 * FanUpdate — fanupdate.js
 *
 * Core utilities shared across all admin pages.
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation summary (vs. original 2005–2007 code):
 *  - Replaced Dean Edwards' addEvent/handleEvent/removeEvent IE-compat shim
 *    with native addEventListener / removeEventListener.
 *  - Replaced getXmlHttpObject() + XMLHttpRequest-based makeRequest() with
 *    the Fetch API (fetch()).
 *  - Deleted getXmlHttpObject() entirely (was only an IE ActiveXObject fallback).
 *  - Replaced createElement() XHTML-namespace helper with document.createElement().
 *  - Replaced getEventTarget() quirksmode shim with e.target (standard since IE9).
 *  - All var → const / let.
 *  - Eliminated global variable leaks (el, top, input, url, datDate1, etc.).
 *  - css.getElementsByClass() uses querySelectorAll() internally (this
 *    utility object still traces back to Neil Crosby's standardista-
 *    table-sorting.js, MIT 2006 — see its own docblock below — even though
 *    the table-sorting code itself has since been replaced; see next line).
 *  - css.addClassToElement() / removeClassFromElement() use classList.
 *  - Standardista's table-sorting code (the actual sort/zebra logic, not
 *    the css utility object above) replaced with Tablesort — see
 *    docs/CHANGELOG.md.
 *
 * WYSIWYG toolbar (wysiwygButton()/makeWysiwyg()/formatText()/insertLink())
 * removed: it was shared infrastructure for admin/blog.php's post editor
 * (superseded by TinyMCE — TODO.md item "FP-18. Implement TinyMCE Editor",
 * see assets/js/fanupdate-tinymce.js) and the public comment form's
 * bold/italic/link toolbar (assets/js/fanupdate-comment-submit.js, removed
 * per a direct request — the comment form is now a plain <textarea>).
 * Confirmed via a full-codebase search that neither caller remained before
 * deleting.
 *
 * Lightbox:
 *  - fuLightboxInit(): scans document for <a class="fu-lightbox"> elements and
 *    wires a click handler that opens a full-screen overlay displaying the
 *    linked full-size image. The overlay (#fu-lightbox-overlay) is created once
 *    and reused. Keyboard: Escape closes; click outside the image closes.
 *    Self-contained scoped styles are injected into <head> on first call so the
 *    lightbox works on public pages without the admin stylesheet being loaded.
 *    Called by FanUpdate::printBlog() via an inline <script> after posts are
 *    rendered, mirroring the pattern used for the comment form scripts.
 *    Bug fix: overlay.style.display is set to 'none' immediately on creation
 *    so it is always hidden until explicitly opened, regardless of whether the
 *    admin stylesheet (which supplies display:flex via .fu-lb-visible) is loaded.
 *    Without this inline 'none', the injected CSS rule display:flex made the
 *    overlay a full-screen transparent layer covering the page from load time,
 *    intercepting mouse events and showing a zoom-out cursor over all content.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

// ── $ shorthand ──────────────────────────────────────────────────────────────

function $(id) {
    return document.getElementById(id);
}

// ── DOM helpers ───────────────────────────────────────────────────────────────

function insertAfter(node, referenceNode) {
    referenceNode.parentNode.insertBefore(node, referenceNode.nextSibling);
}

function insertBefore(node, referenceNode) {
    referenceNode.parentNode.insertBefore(node, referenceNode);
}

/**
 * addEvent / removeEvent — thin wrappers kept so all dependent files
 * (fanupdate-admin.js, select-all.js, etc.) continue to call the same API
 * without changes.  Internally they now use addEventListener.
 */
function addEvent(element, type, handler) {
    element.addEventListener(type, handler);
}

function removeEvent(element, type, handler) {
    element.removeEventListener(type, handler);
}

/**
 * getEventTarget — returns the element that triggered an event.
 * The original had IE srcElement and Safari nodeType==3 workarounds;
 * all modern browsers set e.target correctly.
 */
function getEventTarget(e) {
    let targ = e.target;
    // Walk up from text nodes (shouldn't happen in modern browsers but
    // kept as a cheap safety net).
    if (targ && targ.nodeType === 3) {
        targ = targ.parentNode;
    }
    return targ;
}

// ── AJAX helper ───────────────────────────────────────────────────────────────

/**
 * makeRequest — replaces the old XMLHttpRequest-based version.
 *
 * Signature kept identical so all callers (fanupdate-comment-admin.js,
 * fanupdate-post-admin.js, fanupdate-comment-submit.js) work unchanged.
 *
 * @param {string}   url        Target URL.
 * @param {*}        targetID   Passed through to callback as second argument.
 * @param {string}   method     'GET' or 'POST'.
 * @param {string}   qs         URL-encoded query string body (POST) or params.
 * @param {Function} callback   Called with (responseText, targetID, url).
 */
function makeRequest(url, targetID, method, qs, callback) {
    const options = {
        method: method.toUpperCase(),
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
    };

    if (options.method === 'POST') {
        options.body = qs;
    }

    const fullUrl = (options.method === 'GET' && qs)
        ? url + (url.includes('?') ? '&' : '?') + qs
        : url;

    fetch(fullUrl, options)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not OK: ' + response.status);
            }
            return response.text();
        })
        .then(text => callback(text, targetID, url))
        .catch(err => console.error('makeRequest error:', err));
}

// ── css utility object ────────────────────────────────────────────────────────

/**
 * css — class-manipulation and element-finding helpers.
 * Originally by Neil Crosby (standardista-table-sorting, MIT 2006).
 * getElementsByClass() now delegates to querySelectorAll().
 * addClassToElement() / removeClassFromElement() now use classList.
 */
const css = {

    /**
     * Returns an array of elements matching tag + className under node.
     */
    getElementsByClass(node, searchClass, tag) {
        const selector = (tag && tag !== '*')
            ? `${tag}.${searchClass}`
            : `.${searchClass}`;
        return Array.from(node.querySelectorAll(selector));
    },

    elementHasClass(el, classString) {
        if (!el || !el.classList) { return false; }
        return el.classList.contains(classString);
    },

    addClassToId(idString, classString) {
        this.addClassToElement($(idString), classString);
    },

    addClassToElement(el, classString) {
        if (el && el.classList) {
            el.classList.add(classString);
        }
    },

    removeClassFromId(idString, classString) {
        this.removeClassFromElement($(idString), classString);
    },

    removeClassFromElement(el, classString) {
        if (el && el.classList) {
            el.classList.remove(classString);
        }
    },
};

// ── Radio / checkbox helpers ──────────────────────────────────────────────────

// http://www.somacon.com/p143.php
function getCheckedValue(radioObj) {
    if (!radioObj) { return ''; }
    if (radioObj.length === undefined) {
        return radioObj.checked ? radioObj.value : '';
    }
    for (let i = 0; i < radioObj.length; i++) {
        if (radioObj[i].checked) { return radioObj[i].value; }
    }
    return '';
}

// ── Checkbox highlight ────────────────────────────────────────────────────────

function highlightChecked(e) {
    const el = (e instanceof Event) ? getEventTarget(e) : e;
    if (el.type === 'radio') {
        document.getElementsByName(el.name).forEach(input => {
            css.removeClassFromElement(input.parentNode, 'highlight');
        });
    }
    if (el.checked) {
        css.addClassToElement(el.parentNode, 'highlight');
    } else {
        css.removeClassFromElement(el.parentNode, 'highlight');
    }
}

window.addEventListener('load', () => {
    document.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(input => {
        input.addEventListener('click',  highlightChecked);
        input.addEventListener('change', highlightChecked);
        if (input.checked) {
            css.addClassToElement(input.parentNode, 'highlight');
        } else {
            css.removeClassFromElement(input.parentNode, 'highlight');
        }
    });
});

// ── Y/N toggle switch state label ────────────────────────────────────────────

/**
 * Updates a .fu-toggle switch's Yes/No text label live when clicked, and —
 * only on install.php, where the checkbox itself carries no `name` (see
 * that page's comment on why: key[]/value[] are positional arrays, so a
 * checkbox that only submits when checked would misalign every later
 * field) — mirrors the checked state into the separate always-present
 * hidden field named in data-yn-hidden. admin/options.php's toggles don't
 * set that attribute, so this part is simply a no-op there; the checkbox's
 * own `name`/`value` there already submits correctly on its own.
 * Shared by admin/options.php's Main list and install.php's fresh-install
 * form — both render the same .fu-toggle markup for boolean options (see
 * FU_YN_OPTION_KEYS in functions.php), so this only needs to live in one
 * place instead of a per-page inline <script>.
 */
window.addEventListener('load', () => {
    document.querySelectorAll('.fu-toggle__input').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const label = this.closest('.fu-toggle').querySelector('.fu-toggle__state-label');
            if (label) {
                label.textContent = this.checked ? 'Yes' : 'No';
            }

            if (this.dataset.ynHidden) {
                const hidden = document.getElementById(this.dataset.ynHidden);
                if (hidden) {
                    hidden.value = this.checked ? 'y' : 'n';
                }
            }
        });
    });
});

// ── Date-format preset dropdown ──────────────────────────────────────────────

/**
 * FP-21 — the 'date_format' option's <select> (admin/options.php's Main list and
 * install.php's fresh-install form — see FU_DATE_FORMAT_OPTION_KEY in
 * functions.php) is a preset picker only; it carries no `name` and never
 * submits. The actual submitted value always comes from the paired text
 * input named in data-target, which stays hidden — same self-contained
 * single-control look as the timezone dropdown above, no redundant "here's
 * what you picked" field shown underneath — unless "Custom..." (value
 * '__custom__' — must match FU_DATE_FORMAT_CUSTOM_VALUE in functions.php)
 * is selected, which reveals it for free-text entry. Choosing a preset
 * copies its value into the input and makes it read-only again.
 */
window.addEventListener('load', () => {
    document.querySelectorAll('.fu-date-format-select').forEach(function (select) {
        const target = document.getElementById(select.dataset.target);
        if (!target) {
            return;
        }
        select.addEventListener('change', function () {
            if (this.value === '__custom__') {
                target.readOnly = false;
                target.style.display = '';
                target.focus();
            } else {
                target.value = this.value;
                target.readOnly = true;
                target.style.display = 'none';
            }
        });
    });
});

// ── Date helpers ──────────────────────────────────────────────────────────────

/* strftime for Date, thanks ecmanaut */
const strftime_funks = {
    zeropad: n  => (n > 9 ? String(n) : '0' + n),
    a: t => strftime_funks.H(t) < 12 ? 'am' : 'pm',
    A: t => strftime_funks.H(t) < 12 ? 'AM' : 'PM',
    c: t => t.toString(),
    d: t => strftime_funks.zeropad(t.getDate()),
    D: t => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][t.getDay()],
    F: t => ['January','February','March','April','May','June',
             'July','August','September','October','November','December'][t.getMonth()],
    g: t => (t.getHours() + 12) % 12,
    G: t => t.getHours(),
    h: t => strftime_funks.zeropad((t.getHours() + 12) % 12),
    H: t => strftime_funks.zeropad(t.getHours()),
    i: t => strftime_funks.zeropad(t.getMinutes()),
    j: t => t.getDate(),
    l: t => ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][t.getDay()],
    m: t => strftime_funks.zeropad(t.getMonth() + 1),
    M: t => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][t.getMonth()],
    n: t => (t.getMonth() + 1),
    N: t => (t.getDay() + 1),
    // JS TZ sign is backwards
    O: t => {
        const o = 0 - (t.getTimezoneOffset() / 60);
        const f = (o >= 0) ? '+' : '-';
        return f + strftime_funks.zeropad(Math.abs(o)) + '00';
    },
    s: t => strftime_funks.zeropad(t.getSeconds()),
    w: t => t.getDay(),
    y: t => strftime_funks.zeropad(strftime_funks.Y(t) % 100),
    Y: t => t.getFullYear(),
    '%': () => '%',
};

Date.prototype.strftime = function (fmt) {
    let out = fmt;
    for (const s in strftime_funks) {
        if (s.length === 1) {
            out = out.replace('%' + s, strftime_funks[s](this));
        }
    }
    return out;
};

if (typeof TrimPath !== 'undefined') {
    TrimPath.parseTemplate_etc.modifierDef.strftime = (t, fmt) => new Date(t).strftime(fmt);
}

function dateDiff(date1, date2) {
    return Math.ceil((date1.getTime() - date2.getTime()) / (24 * 60 * 60 * 1000));
}

// ── Lightbox ──────────────────────────────────────────────────────────────────

/**
 * fuLightboxInit — wire lightbox behaviour onto all <a class="fu-lightbox"> links.
 *
 * Called by FanUpdate::printBlog() via an inline <script> after post output.
 * Safe to call multiple times on the same page (double-init guard on each link).
 *
 * The overlay and its styles are created once on first call and reused.
 * Self-contained: injects its own <style> block so the lightbox works on public
 * pages where the admin stylesheet is not loaded.
 *
 * Closing the lightbox:
 *  - Click anywhere on the dark backdrop (outside the image)
 *  - Press Escape
 */
function fuLightboxInit() {

    // ── inject scoped styles once ─────────────────────────────────────────────
    if (!document.getElementById('fu-lightbox-style')) {
        const style = document.createElement('style');
        style.id = 'fu-lightbox-style';
        style.textContent = [
            '#fu-lightbox-overlay {',
            '  position: fixed;',
            '  inset: 0;',
            '  background: rgba(0,0,0,0.88);',
            '  z-index: 99999;',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  cursor: zoom-out;',
            '  opacity: 0;',
            '  transition: opacity 0.2s ease;',
            '  -webkit-backdrop-filter: blur(2px);',
            '  backdrop-filter: blur(2px);',
            '}',
            '#fu-lightbox-overlay.fu-lb-visible {',
            '  opacity: 1;',
            '}',
            '#fu-lightbox-overlay img {',
            '  max-width: 92vw;',
            '  max-height: 90vh;',
            '  object-fit: contain;',
            '  border-radius: 6px;',
            '  box-shadow: 0 8px 40px rgba(0,0,0,0.6);',
            '  cursor: default;',
            '  display: block;',
            '  pointer-events: auto;',
            '}',
            '#fu-lightbox-close {',
            '  position: fixed;',
            '  top: 16px;',
            '  right: 20px;',
            '  width: 36px;',
            '  height: 36px;',
            '  border-radius: 50%;',
            '  background: rgba(255,255,255,0.12);',
            '  border: 1px solid rgba(255,255,255,0.22);',
            '  color: #fff;',
            '  font-size: 20px;',
            '  line-height: 36px;',
            '  text-align: center;',
            '  cursor: pointer;',
            '  user-select: none;',
            '  transition: background 0.15s;',
            '}',
            '#fu-lightbox-close:hover {',
            '  background: rgba(255,255,255,0.22);',
            '}',
            'a.fu-lightbox img {',
            '  cursor: zoom-in;',
            '  transition: opacity 0.15s;',
            '}',
            'a.fu-lightbox:hover img {',
            '  opacity: 0.88;',
            '}',
        ].join('\n');
        document.head.appendChild(style);
    }

    // ── create overlay once ───────────────────────────────────────────────────
    let overlay = document.getElementById('fu-lightbox-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'fu-lightbox-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Image viewer');

        // CRITICAL: hide before appending. The injected CSS sets display:flex on
        // #fu-lightbox-overlay, so without this inline style the overlay sits as
        // a full-screen transparent layer from the moment it's created, blocking
        // all mouse events and showing a zoom-out cursor over every element.
        overlay.style.display = 'none';

        const closeBtn = document.createElement('span');
        closeBtn.id = 'fu-lightbox-close';
        closeBtn.setAttribute('aria-label', 'Close image viewer');
        closeBtn.setAttribute('role', 'button');
        closeBtn.setAttribute('tabindex', '0');
        closeBtn.innerHTML = '&times;';

        const img = document.createElement('img');
        img.alt = '';
        img.id  = 'fu-lightbox-img';

        overlay.appendChild(closeBtn);
        overlay.appendChild(img);
        document.body.appendChild(overlay);

        // ── close handlers ────────────────────────────────────────────────────

        function fuLbClose() {
            overlay.classList.remove('fu-lb-visible');
            overlay.addEventListener('transitionend', function handler() {
                overlay.removeEventListener('transitionend', handler);
                overlay.style.display = 'none';
                img.src = '';
                img.alt = '';
                if (overlay._triggerLink) {
                    overlay._triggerLink.focus();
                    overlay._triggerLink = null;
                }
            });
        }

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { fuLbClose(); }
        });

        closeBtn.addEventListener('click', fuLbClose);
        closeBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fuLbClose(); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('fu-lb-visible')) {
                fuLbClose();
            }
        });

        img.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    const lbImg = document.getElementById('fu-lightbox-img');

    // ── wire each fu-lightbox link ────────────────────────────────────────────

    document.querySelectorAll('a.fu-lightbox').forEach(function (link) {
        if (link.dataset.lbWired === '1') { return; }
        link.dataset.lbWired = '1';

        link.addEventListener('click', function (e) {
            e.preventDefault();

            const fullUrl = link.getAttribute('href');
            const altText = link.querySelector('img') ? link.querySelector('img').getAttribute('alt') : '';

            overlay._triggerLink = link;
            lbImg.src = fullUrl;
            lbImg.alt = altText || '';

            overlay.style.display = 'flex';
            // Force a reflow so the transition runs from opacity:0.
            overlay.getBoundingClientRect();
            overlay.classList.add('fu-lb-visible');

            document.getElementById('fu-lightbox-close').focus();
        });
    });
}

// ── Sortable admin tables (Tablesort) ────────────────────────────────────────
/**
 * Replaces the previous hand-maintained Standardista Table Sorting code
 * (2006, Neil Crosby) with Tablesort (vendored:
 * assets/vendor/tablesort/tablesort.js, loaded before this file — see
 * admin/header.php) — see docs/CHANGELOG.md for why (a real date-sorting
 * bug plus missing aria-sort/keyboard accessibility).
 *
 * Tablesort itself handles click/Enter-key sort triggers, aria-sort,
 * role="columnheader", data-sort-method="none" columns, and the data-sort
 * attribute override each date cell now carries (see admin/blog.php) so
 * sorting is correct regardless of the configured date_format. It has no
 * concept of zebra-striping though, so that's the only thing left to wire
 * up here: row DOM order changes via appendChild during a sort, but each
 * row's own .odd/.even class doesn't move with it in a way that keeps the
 * alternation correct, so it's reapplied after every 'afterSort' event.
 */
window.addEventListener('load', () => {
    if (typeof Tablesort === 'undefined') { return; }

    document.querySelectorAll('table.sortable').forEach(function (table) {
        new Tablesort(table);

        table.addEventListener('afterSort', function () {
            const tbody = table.tBodies[0];
            if (!tbody) { return; }
            Array.from(tbody.rows).forEach((row, i) => {
                row.classList.remove('odd', 'even');
                row.classList.add(i % 2 === 0 ? 'even' : 'odd');
            });
        });
    });
});
