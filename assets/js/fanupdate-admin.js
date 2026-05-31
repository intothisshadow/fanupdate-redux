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
 *  - Image paths updated: css/ → ../images/ (restructure to assets/)
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

const myWysiwyg = [
    new wysiwygButton('bold',  '../assets/images/text_bold.png',         'make bold',              e => { formatText(getEventTarget(e).className, '<strong>', '</strong>'); return false; }),
    new wysiwygButton('italic','../assets/images/text_italic.png',        'make italic',            e => { formatText(getEventTarget(e).className, '<em>', '</em>');         return false; }),
    new wysiwygButton('h2',    '../assets/images/text_heading_2.png',     'make heading level 2',   e => { formatText(getEventTarget(e).className, '<h2>', '</h2>');          return false; }),
    new wysiwygButton('h3',    '../assets/images/text_heading_3.png',     'make heading level 3',   e => { formatText(getEventTarget(e).className, '<h3>', '</h3>');          return false; }),
    new wysiwygButton('quote', '../assets/images/text_signature.png',     'make blockquote',        e => { formatText(getEventTarget(e).className, '<blockquote>', '</blockquote>'); return false; }),
    new wysiwygButton('rm',    '../assets/images/text_horizontalrule.png','insert read more break', e => { formatText(getEventTarget(e).className, '<!-- MORE -->', '');     return false; }),
    new wysiwygButton('link',  '../assets/images/link.png',               'insert link',            e => { insertLink(getEventTarget(e).className);                          return false; }),
];

window.addEventListener('load', () => {
    confirmDeleteInit();

    css.getElementsByClass(document, 'wysiwyg', 'textarea').forEach(ta => {
        makeWysiwyg(ta);
    });
});
