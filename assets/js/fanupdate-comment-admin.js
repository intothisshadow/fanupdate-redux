/**
 * FanUpdate — fanupdate-comment-admin.js
 *
 * Wires up approve and delete actions for the comments admin table.
 * Requires: fanupdate.js (css, getEventTarget, makeRequest, addEvent)
 *           fanupdate-admin.js (confirmDelete)
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation:
 *  - var → const / let
 *  - onclick assignment → addEventListener
 *  - Removed re-declaration of cmt_id inside second for-loop (was shadowing
 *    outer let; now uses a fresh const per iteration)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

const fuComment = {

    init() {
        // Wire up "Approve" links next to each unapproved comment
        css.getElementsByClass(document, 'approve_comment', '*').forEach(el => {
            const cmt_id = parseInt(el.id.replace('ci_', ''), 10);

            const a = document.createElement('a');
            a.href  = '#';
            a.id    = 'approve_' + cmt_id;
            a.appendChild(document.createTextNode('Approve'));
            a.addEventListener('click', fuComment.approveCmt);

            insertAfter(a, el);
        });

        // Wire up delete buttons
        css.getElementsByClass(document, 'delete', '*').forEach(btn => {
            const cmt_id = btn.form.id.value;
            btn.id = 'delete_' + cmt_id;
            btn.addEventListener('click', fuComment.deleteCmt);
        });
    },

    approveCmt(e) {
        e.preventDefault();
        const linkEl = getEventTarget(e);
        const cmt_id = parseInt(linkEl.id.replace('approve_', ''), 10);
        const target = linkEl.parentNode;
        const qs     = 'id=' + encodeURIComponent(cmt_id) + '&action=approve&mode=ajax';
        makeRequest('comment.php', target, 'POST', qs, approveCmtCallback);
    },

    deleteCmt(e) {
        const button = getEventTarget(e);
        if (confirmDelete(e)) {
            if (css.elementHasClass(button, 'noajax')) {
                return true;
            }
            e.preventDefault();
            const tr     = button.closest('tr');
            const cmt_id = parseInt(button.id.replace('delete_', ''), 10);
            const qs     = 'id=' + encodeURIComponent(cmt_id) + '&action=delete&mode=ajax';
            makeRequest(button.form.attributes['action'].value, tr, 'POST', qs, deleteCmtCallback);
        }
        return false;
    },
};

function deleteCmtCallback(responseText, target) {
    target.parentNode.removeChild(target);
}

function approveCmtCallback(responseText, target) {
    target.innerHTML = responseText;
}

window.addEventListener('load', () => fuComment.init());
