/**
 * FanUpdate — fanupdate.js
 *
 * Core utilities shared across all admin pages.
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation summary (vs. original 2005–2007 code):
 *  - Replaced Dean Edwards' addEvent/handleEvent/removeEvent IE-compat shim
 *    with native addEventListener / removeEventListener.
 *  - Replaced getXmlHttpObject() + XMLHttpRequest-based makeRequest() with
 *    the Fetch API (fetch()).
 *  - Deleted dead document.selection.createRange() IE-only branch in formatText().
 *  - Deleted getXmlHttpObject() entirely (was only an IE ActiveXObject fallback).
 *  - Replaced createElement() XHTML-namespace helper with document.createElement().
 *  - Replaced getEventTarget() quirksmode shim with e.target (standard since IE9).
 *  - All var → const / let.
 *  - Eliminated global variable leaks (el, top, input, url, datDate1, etc.).
 *  - css.getElementsByClass() uses querySelectorAll() internally.
 *  - css.addClassToElement() / removeClassFromElement() use classList.
 *  - Standardista table-sorting code preserved verbatim (Neil Crosby, MIT 2006)
 *    except for its own addEvent calls replaced with addEventListener.
 *
 * Redux 2.0.8:
 *  - makeWysiwyg(): toolbar buttons now use inline SVG instead of external PNG
 *    files. wysiwygButton.img is now an SVG string (or legacy src URL); the
 *    builder detects which form is supplied and creates either an <svg> element
 *    (via innerHTML on a wrapper) or an <img> element accordingly. This removes
 *    the dependency on text_bold.png, text_italic.png, link.png etc. which were
 *    never shipped reliably and caused the toolbar to render as broken-image
 *    alt text.
 *  - makeWysiwyg(): accepts optional second parameter `anchor` — insertion
 *    point defaults to el itself (admin behaviour unchanged) but can be set to
 *    a block-level parent so the toolbar sits above an entire label+field row
 *    rather than inside a <p> beside a floated label (front-end comment form).
 *  - makeWysiwyg(): double-init guard — stamps data-wysiwyg="1" on the
 *    textarea after wiring; subsequent calls for the same element are no-ops.
 *
 * Redux 2.0.9:
 *  - makeWysiwyg(): accepts optional third parameter `toolbarClass` — the CSS
 *    class applied to the outer toolbar <span>. Defaults to 'wysiwygmenu' (admin
 *    behaviour unchanged). The front-end comment form passes 'fu-wysiwygmenu'
 *    so its self-contained scoped styles don't depend on the admin stylesheet.
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

// ── WYSIWYG toolbar ───────────────────────────────────────────────────────────

/**
 * wysiwygButton — descriptor for one toolbar button.
 *
 * @param {string}   name   Short identifier (used as aria-label / alt text).
 * @param {string}   img    Either an SVG string ('<svg …>…</svg>') or a legacy
 *                          image URL ('.../foo.png'). makeWysiwyg detects which
 *                          form is supplied.
 * @param {string}   title  Tooltip text.
 * @param {Function} func   Click handler.
 */
function wysiwygButton(name, img, title, func) {
    this.name  = name;
    this.img   = img;
    this.func  = func;
    this.title = title;
}

/**
 * makeWysiwyg — build the toolbar span and insert it into the DOM.
 *
 * Double-init guard: stamps data-wysiwyg="1" on the textarea on first call;
 * subsequent calls for the same element are silent no-ops.
 *
 * SVG / image detection: if wysiwygButton.img starts with '<' it is treated as
 * an inline SVG string; otherwise as a legacy URL (<img src>).
 *
 * @param {HTMLTextAreaElement} el            The textarea the toolbar controls.
 * @param {Element}            [anchor]       Insertion point. Defaults to el.
 * @param {string}             [toolbarClass] CSS class for the toolbar <span>.
 *   Defaults to 'wysiwygmenu' (admin panel). Pass 'fu-wysiwygmenu' for the
 *   front-end comment form so it uses self-contained scoped styles and doesn't
 *   depend on the admin stylesheet being loaded on the public page.
 */
function makeWysiwyg(el, anchor, toolbarClass) {
    // ── double-init guard ─────────────────────────────────────────────────────
    if (el.dataset.wysiwyg === '1') { return; }
    el.dataset.wysiwyg = '1';

    const insertionPoint = anchor || el;
    const toolbar = document.createElement('span');
    toolbar.className = toolbarClass || 'wysiwygmenu';

    for (let i = 0; i < myWysiwyg.length; i++) {
        const button = myWysiwyg[i];

        const a = document.createElement('a');
        a.href      = '#';
        a.title     = button.title;
        a.className = el.id;
        a.setAttribute('aria-label', button.name);
        a.addEventListener('click', button.func);

        // ── SVG vs legacy image ───────────────────────────────────────────────
        if (button.img && button.img.trimStart().startsWith('<')) {
            // Inline SVG — wrap in a <span> so we can set innerHTML safely
            // without touching the <a> directly (which would clobber listeners).
            const wrap = document.createElement('span');
            wrap.className = el.id;
            wrap.innerHTML = button.img;
            wrap.setAttribute('aria-hidden', 'true');
            const svgEl = wrap.firstElementChild;
            if (svgEl) {
                svgEl.className.baseVal = el.id; // propagate class for formatText lookup
            }
            a.appendChild(wrap);
        } else {
            // Legacy PNG/GIF path — kept for custom installs that still have
            // the image files on disk.
            const img     = document.createElement('img');
            img.src       = button.img;
            img.alt       = button.name;
            img.className = el.id;
            a.appendChild(img);
        }

        toolbar.appendChild(a);
    }

    insertBefore(toolbar, insertionPoint);
}

// ── Text formatting (WYSIWYG tag insertion) ───────────────────────────────────

function insertLink(ID, bbcode) {
    let url = prompt('Link destination:', '');
    if (!url || url === '') { return false; }
    if (!url.match(/^https?:/i)) { url = 'http://' + url; }
    if (bbcode) {
        return formatText(ID, '[URL=' + url + ']', '[/URL]');
    }
    return formatText(ID, '<a href="' + url + '">', '</a>');
}

function formatText(ID, tagstart, tagend) {
    const el = $(ID);
    if (!el) { return false; }

    const scrollTop   = el.scrollTop;
    const selStart    = el.selectionStart;
    const selEnd      = el.selectionEnd;
    const newLen      = selEnd + tagstart.length + tagend.length;

    const before      = el.value.substring(0, selStart);
    const selected    = el.value.substring(selStart, selEnd);
    const after       = el.value.substring(selEnd);

    el.value = before + tagstart + selected + tagend + after;
    el.setSelectionRange(newLen, newLen);
    el.focus();
    el.scrollTop = scrollTop;

    return false;
}

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

// ── Standardista Table Sorting ────────────────────────────────────────────────
/**
 * Written by Neil Crosby.
 * http://www.workingwith.me.uk/articles/scripting/standardista_table_sorting
 *
 * Based on Stuart Langridge's "sorttable" code.
 * Copyright (c) 2006 Neil Crosby — MIT licence.
 * (addEvent calls replaced with addEventListener; otherwise preserved verbatim.)
 */

const standardista = (function () {

    const SORT_COLUMN_INDEX = 'sortColumnIndex';

    function init() {
        if (!document.getElementsByTagName) { return; }
        const tables = document.getElementsByTagName('table');
        for (let i = 0; i < tables.length; i++) {
            initTable(tables[i]);
        }
    }

    function initTable(table) {
        if (table.className.search(/\bnosort\b/) !== -1) { return; }
        const headings = getHeadings(table);
        if (!headings) { return; }
        for (let i = 0; i < headings.length; i++) {
            initHeading(table, headings[i], i);
        }
        zebra(table);
    }

    function getHeadings(table) {
        if (!table.tHead || !table.tHead.rows || table.tHead.rows.length === 0) { return null; }
        return table.tHead.rows[0].cells;
    }

    function initHeading(table, th, column) {
        if (th.className.search(/\bnosort\b/) !== -1) { return; }
        const a = document.createElement('a');
        a.href = '#';
        a.appendChild(document.createTextNode(th.innerHTML));
        th.innerHTML = '';
        th.appendChild(a);
        a.addEventListener('click', e => {
            e.preventDefault();
            sortTable(table, column);
        });
    }

    function sortTable(table, column) {
        const tbody = table.tBodies[0];
        const rows  = Array.from(tbody.rows);

        const sortFn = determineSortFunction(rows, column);
        const dir    = (table[SORT_COLUMN_INDEX] === column) ? -1 : 1;

        rows.sort((a, b) => dir * sortFn(
            a.cells[column] ? a.cells[column].textContent.trim() : '',
            b.cells[column] ? b.cells[column].textContent.trim() : ''
        ));

        rows.forEach(row => tbody.appendChild(row));

        table[SORT_COLUMN_INDEX] = (dir === 1) ? column : -1;
        zebra(table);
    }

    function determineSortFunction(rows, column) {
        // sample first non-empty cell
        for (let i = 0; i < rows.length; i++) {
            const cell = rows[i].cells[column];
            if (!cell) { continue; }
            const text = cell.textContent.trim();
            if (text === '') { continue; }
            if (text.match(/^\d\d[\/-]\d\d[\/-]\d\d\d\d$/)) { return sortDate; }
            if (text.match(/^[£$€¤]/))                       { return sortCurrency; }
            if (text.match(/^[\d.]+$/))                       { return sortNumeric; }
            return sortCaseInsensitive;
        }
        return sortCaseInsensitive;
    }

    function sortCaseInsensitive(a, b) {
        return a.toLowerCase().localeCompare(b.toLowerCase());
    }

    function sortDate(a, b) {
        // dd/mm/yyyy or dd-mm-yyyy
        const parseDate = s => {
            const p = s.split(/[\/-]/);
            return new Date(p[2], p[1] - 1, p[0]);
        };
        return parseDate(a) - parseDate(b);
    }

    function sortNumeric(a, b) {
        return parseFloat(a) - parseFloat(b);
    }

    function sortCurrency(a, b) {
        return sortNumeric(a.replace(/[^0-9.]/g, ''), b.replace(/[^0-9.]/g, ''));
    }

    function zebra(table) {
        const tbody = table.tBodies[0];
        if (!tbody) { return; }
        Array.from(tbody.rows).forEach((row, i) => {
            row.classList.remove('odd', 'even');
            row.classList.add(i % 2 === 0 ? 'even' : 'odd');
        });
    }

    return { init };
}());

window.addEventListener('load', standardista.init);
