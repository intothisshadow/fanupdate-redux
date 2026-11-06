/**
 * FanUpdate — fanupdate.js
 *
 * Core utilities shared across all admin pages.
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernised from the original 2005–2007 code (native events and fetch(), no
 * IE shims). The `css` utility object still traces back to Neil Crosby's
 * standardista-table-sorting.js (MIT, 2006); table sorting itself is Tablesort.
 *
 * Lightbox: fuLightboxInit() wires every <a class="fu-lightbox"> to a
 * full-screen overlay (#fu-lightbox-overlay, created once and reused).
 * Escape or a click outside the image closes it; arrow keys and Prev/Next
 * buttons step through the images in the same .fu-entry (hidden for a single
 * image). Scoped styles are injected into <head> so it works on public pages
 * without the admin stylesheet. Called by FanUpdate::printBlog() after posts
 * render.
 *
 * The overlay is set to display:none inline on creation: otherwise the
 * injected display:flex rule would cover the page from load, intercepting
 * mouse events.
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
 * The date_format <select> (admin/options.php, install.php) is a preset picker
 * only and never submits. The value comes from the paired text input named in
 * data-target, hidden unless "Custom..." ('__custom__', matching
 * FU_DATE_FORMAT_CUSTOM_VALUE in functions.php) is selected. Choosing a preset
 * copies it into the input and makes it read-only again.
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
 *
 * Prev/Next navigation: images sharing a .fu-entry ancestor (falls back to
 * every fu-lightbox link on the page if there's no .fu-entry ancestor) form
 * a group; Prev/Next buttons and Left/Right arrow keys step through it. No
 * wrap-around — Prev is a no-op at the first image, Next at the last. Groups
 * of one show no navigation at all.
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
            '#fu-lightbox-prev, #fu-lightbox-next {',
            '  position: fixed;',
            '  top: 50%;',
            '  transform: translateY(-50%);',
            '  width: 44px;',
            '  height: 44px;',
            '  border-radius: 50%;',
            '  background: rgba(255,255,255,0.12);',
            '  border: 1px solid rgba(255,255,255,0.22);',
            '  color: #fff;',
            '  font-size: 26px;',
            '  line-height: 42px;',
            '  text-align: center;',
            '  cursor: pointer;',
            '  user-select: none;',
            '  transition: background 0.15s, opacity 0.15s;',
            '}',
            '#fu-lightbox-prev { left: 20px; }',
            '#fu-lightbox-next { right: 20px; }',
            '#fu-lightbox-prev:hover, #fu-lightbox-next:hover {',
            '  background: rgba(255,255,255,0.22);',
            '}',
            '#fu-lightbox-prev[aria-disabled="true"], #fu-lightbox-next[aria-disabled="true"] {',
            '  opacity: 0.3;',
            '  cursor: default;',
            '  pointer-events: none;',
            '}',
            '#fu-lightbox-counter {',
            '  position: fixed;',
            '  bottom: 20px;',
            '  left: 50%;',
            '  transform: translateX(-50%);',
            '  color: rgba(255,255,255,0.85);',
            '  font-size: 13px;',
            '  letter-spacing: 0.04em;',
            '  background: rgba(0,0,0,0.35);',
            '  padding: 4px 12px;',
            '  border-radius: 999px;',
            '  pointer-events: none;',
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

    // ── Prev/Next navigation helpers ──────────────────────────────────────────
    // Re-look-up elements by id each time rather than closing over them, so
    // these work correctly whether called from this invocation of
    // fuLightboxInit() or a later one (the overlay is created once and reused
    // across calls — see the doc comment above).

    function fuLbUpdateImage() {
        const ov = document.getElementById('fu-lightbox-overlay');
        const group = ov._group;
        const index = ov._index;
        const link = group[index];
        const imgEl = document.getElementById('fu-lightbox-img');
        const prevBtn = document.getElementById('fu-lightbox-prev');
        const nextBtn = document.getElementById('fu-lightbox-next');
        const counterEl = document.getElementById('fu-lightbox-counter');

        const fullUrl = link.getAttribute('href');
        const linkImg = link.querySelector('img');
        const altText = linkImg ? linkImg.getAttribute('alt') : '';

        imgEl.src = fullUrl;
        imgEl.alt = altText || '';
        ov._triggerLink = link;

        const multi = group.length > 1;
        prevBtn.style.display = multi ? '' : 'none';
        nextBtn.style.display = multi ? '' : 'none';
        counterEl.style.display = multi ? '' : 'none';

        if (!multi) { return; }

        counterEl.textContent = (index + 1) + ' / ' + group.length;

        const atFirst = index === 0;
        const atLast = index === group.length - 1;
        prevBtn.setAttribute('aria-disabled', String(atFirst));
        nextBtn.setAttribute('aria-disabled', String(atLast));

        // Preload the adjacent images so stepping feels instant instead of
        // showing a flash of the loading state on a slow connection.
        if (group[index - 1]) { new Image().src = group[index - 1].getAttribute('href'); }
        if (group[index + 1]) { new Image().src = group[index + 1].getAttribute('href'); }
    }

    function fuLbStep(delta) {
        const ov = document.getElementById('fu-lightbox-overlay');
        if (!ov || !ov._group) { return; }
        const newIndex = ov._index + delta;
        // No wrap-around: Prev is a no-op at the first image, Next at the last.
        if (newIndex < 0 || newIndex >= ov._group.length) { return; }
        ov._index = newIndex;
        fuLbUpdateImage();
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

        // Prev/Next only become visible (see fuLbUpdateImage()) when the
        // clicked image's .fu-entry contains more than one fu-lightbox link —
        // hidden by default so single-image entries show no navigation.
        const prevBtn = document.createElement('span');
        prevBtn.id = 'fu-lightbox-prev';
        prevBtn.style.display = 'none';
        prevBtn.setAttribute('aria-label', 'Previous image');
        prevBtn.setAttribute('role', 'button');
        prevBtn.setAttribute('tabindex', '0');
        prevBtn.innerHTML = '&#8249;';

        const nextBtn = document.createElement('span');
        nextBtn.id = 'fu-lightbox-next';
        nextBtn.style.display = 'none';
        nextBtn.setAttribute('aria-label', 'Next image');
        nextBtn.setAttribute('role', 'button');
        nextBtn.setAttribute('tabindex', '0');
        nextBtn.innerHTML = '&#8250;';

        const counter = document.createElement('span');
        counter.id = 'fu-lightbox-counter';
        counter.style.display = 'none';
        counter.setAttribute('aria-hidden', 'true');

        const img = document.createElement('img');
        img.alt = '';
        img.id  = 'fu-lightbox-img';

        overlay.appendChild(closeBtn);
        overlay.appendChild(prevBtn);
        overlay.appendChild(nextBtn);
        overlay.appendChild(counter);
        overlay.appendChild(img);
        document.body.appendChild(overlay);

        prevBtn.addEventListener('click', function (e) { e.stopPropagation(); fuLbStep(-1); });
        nextBtn.addEventListener('click', function (e) { e.stopPropagation(); fuLbStep(1); });
        prevBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fuLbStep(-1); }
        });
        nextBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fuLbStep(1); }
        });

        // ── close handlers ────────────────────────────────────────────────────

        function fuLbClose() {
            overlay.classList.remove('fu-lb-visible');
            overlay.addEventListener('transitionend', function handler() {
                overlay.removeEventListener('transitionend', handler);
                overlay.style.display = 'none';
                img.src = '';
                img.alt = '';

                // Undo the scroll lock applied in the click handler below and
                // restore the page to where it was scrolled to. behavior:
                // 'instant' overrides any scroll-behavior: smooth set on
                // html/body by the skin — without it, a smooth-scroll skin
                // would visibly animate back to y instead of snapping there,
                // since scrollTo() otherwise inherits that CSS property.
                const y = -parseInt(document.body.style.top || '0', 10);
                document.body.style.position = '';
                document.body.style.top = '';
                document.body.style.width = '';
                window.scrollTo({ top: y, left: 0, behavior: 'instant' });

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
            if (!overlay.classList.contains('fu-lb-visible')) { return; }
            if (e.key === 'Escape') {
                fuLbClose();
            } else if (e.key === 'ArrowLeft') {
                fuLbStep(-1);
            } else if (e.key === 'ArrowRight') {
                fuLbStep(1);
            }
        });

        img.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    // ── wire each fu-lightbox link ────────────────────────────────────────────

    document.querySelectorAll('a.fu-lightbox').forEach(function (link) {
        if (link.dataset.lbWired === '1') { return; }
        link.dataset.lbWired = '1';

        link.addEventListener('click', function (e) {
            e.preventDefault();

            // Group images by nearest ancestor .fu-entry so Prev/Next only
            // steps through images belonging to the same blog post — falls
            // back to every fu-lightbox link on the page when there's no
            // .fu-entry ancestor (a template that doesn't use printBlog()'s
            // wrapper), so the feature still works rather than silently
            // doing nothing.
            const entry = link.closest('.fu-entry');
            const group = entry
                ? Array.from(entry.querySelectorAll('a.fu-lightbox'))
                : Array.from(document.querySelectorAll('a.fu-lightbox'));
            const index = group.indexOf(link);

            overlay._group = group;
            overlay._index = index === -1 ? 0 : index;

            fuLbUpdateImage();

            // Lock body scroll; on mobile a scrollable page behind a fixed overlay can
            // desync the layout and visual viewports, pushing the image off-screen.
            const scrollY = window.scrollY;
            document.body.style.position = 'fixed';
            document.body.style.top = `-${scrollY}px`;
            document.body.style.width = '100%';

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
 * Uses the vendored Tablesort (assets/vendor/tablesort/tablesort.js, loaded
 * before this file by admin/header.php).
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
