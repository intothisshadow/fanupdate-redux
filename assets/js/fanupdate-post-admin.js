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
 * The entry list's outer form (#blog-select-form) submits the checked id[]
 * values for bulk delete. This file makes the header checkbox toggle every
 * row checkbox and keeps it in sync as rows change. Per-row delete is a plain
 * link to a confirmation page, so nothing is intercepted via AJAX.
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
