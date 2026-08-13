/**
 * FanUpdate — fanupdate-post-admin.js
 *
 * Wires up "select all" behaviour for the entry list's bulk-delete form.
 * Requires: fanupdate.js (highlightChecked)
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation:
 *  - var → const / let
 *  - onclick assignment → addEventListener
 *  - button.parentNode.parentNode.parentNode → button.closest('tr')
 *
 * Milestone 3 — Admin UI Cleanup (nested forms):
 *  - Per-row delete used to be an AJAX action wired up here (deletePost),
 *    submitted from a <form> nested inside the entry list's outer select
 *    form — which is invalid HTML5 and silently broke the DOM structure of
 *    the table (see admin/blog.php for the full explanation). Per-row
 *    delete is now a plain link to a real server-side confirmation page, so
 *    there is nothing left for this file to intercept via AJAX. The old
 *    deletePost()/deletePostCallback() code has been removed.
 *  - The entry list's outer form (#blog-select-form) now has a real purpose:
 *    submitting the checked id[] values for bulk delete. This file wires up
 *    the "select all" checkbox in the table header so it toggles every
 *    row checkbox, and keeps itself in sync when rows are checked/unchecked
 *    individually.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

const fuPost = {

    init() {
        const selectAllBox = document.getElementById('select-all');
        if (!selectAllBox) { return; }

        const rowBoxes = () => Array.from(document.querySelectorAll('input[name="id[]"]'));

        selectAllBox.addEventListener('change', () => {
            rowBoxes().forEach(cb => {
                cb.checked = selectAllBox.checked;
                highlightChecked(cb);
            });
        });

        rowBoxes().forEach(cb => {
            cb.addEventListener('change', () => {
                const boxes = rowBoxes();
                selectAllBox.checked = boxes.length > 0 && boxes.every(b => b.checked);
            });
        });
    },
};

window.addEventListener('load', () => fuPost.init());
