/**
 * FanUpdate — fanupdate-comment-admin.js
 *
 * Wires up the "Approve" action for the comments admin table.
 * Requires: fanupdate.js (css, getEventTarget, makeRequest, addEvent)
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * approveCmt() has no enclosing <form> (it's a bare link), so it sends the
 * page-level `fuCsrfToken` global emitted by admin/header.php.
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
    },

    approveCmt(e) {
        e.preventDefault();
        const linkEl = getEventTarget(e);
        const cmt_id = parseInt(linkEl.id.replace('approve_', ''), 10);
        const target = linkEl.parentNode;
        const csrfToken = typeof fuCsrfToken !== 'undefined' ? fuCsrfToken : '';
        const qs     = 'id=' + encodeURIComponent(cmt_id) + '&action=approve&mode=ajax'
            + '&csrf_token=' + encodeURIComponent(csrfToken);
        makeRequest('comment.php', target, 'POST', qs, approveCmtCallback);
    },
};

function approveCmtCallback(responseText, target) {
    target.innerHTML = responseText;
}

window.addEventListener('load', () => fuComment.init());
