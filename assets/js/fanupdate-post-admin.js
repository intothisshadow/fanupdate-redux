/**
 * FanUpdate — fanupdate-post-admin.js
 *
 * Wires up delete actions for the posts admin table.
 * Requires: fanupdate.js (css, getEventTarget, makeRequest)
 *           fanupdate-admin.js (confirmDelete)
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation:
 *  - var → const / let
 *  - onclick assignment → addEventListener
 *  - button.parentNode.parentNode.parentNode → button.closest('tr')
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

const fuPost = {

    init() {
        css.getElementsByClass(document, 'delete', '*').forEach(btn => {
            const post_id = btn.form.id.value;
            btn.id = 'delete_' + post_id;
            btn.addEventListener('click', fuPost.deletePost);
        });
    },

    deletePost(e) {
        e.preventDefault();
        const button  = getEventTarget(e);
        const tr      = button.closest('tr');
        const post_id = parseInt(button.id.replace('delete_', ''), 10);

        if (confirmDelete(e)) {
            const qs = 'id=' + encodeURIComponent(post_id) + '&action=delete&mode=ajax';
            makeRequest(button.form.attributes['action'].value, tr, 'POST', qs, deletePostCallback);
        }

        return false;
    },
};

function deletePostCallback(responseText, target) {
    target.parentNode.removeChild(target);
}

window.addEventListener('load', () => fuPost.init());
