/**
 * FanUpdate — select-all.js
 *
 * "Select All / Select None" links for category checkbox lists,
 * dynamic new-category input rows, and the "now" timestamp toggle.
 *
 * Requires: fanupdate.js (css, $, addEvent, getEventTarget,
 *           insertAfter, highlightChecked)
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

const selectAll = (function () {

    let checkboxes_container = [];

    function getCheckboxes(containerIndex) {
        return Array.from(
            checkboxes_container[containerIndex].getElementsByTagName('input')
        ).filter(el => el.type === 'checkbox');
    }

    function doSelectAll(e) {
        e.preventDefault();
        const j = parseInt(getEventTarget(e).getAttribute('j'), 10);
        getCheckboxes(j).forEach(cb => {
            cb.checked = true;
            highlightChecked(cb);
        });
    }

    function doSelectNone(e) {
        e.preventDefault();
        const j = parseInt(getEventTarget(e).getAttribute('j'), 10);
        getCheckboxes(j).forEach(cb => {
            cb.checked = false;
            highlightChecked(cb);
        });
    }

    function init() {
        checkboxes_container = css.getElementsByClass(document, 'select_all', '*');

        checkboxes_container.forEach((container, i) => {
            const all  = document.createElement('a');
            const none = document.createElement('a');

            all.setAttribute('j', i);
            none.setAttribute('j', i);

            all.className  = 'all';
            none.className = 'none';
            all.href       = '#';
            none.href      = '#';

            all.addEventListener('click',  doSelectAll);
            none.addEventListener('click', doSelectNone);

            all.appendChild(document.createTextNode('Select All'));
            none.appendChild(document.createTextNode('Select None'));

            const list    = document.createElement('ul');
            list.className = 'catlist';
            const li_all  = document.createElement('li');
            const li_none = document.createElement('li');

            li_all.appendChild(all);
            li_none.appendChild(none);
            list.appendChild(li_all);
            list.appendChild(li_none);

            insertAfter(list, container);
        });
    }

    return { init };
}());

window.addEventListener('load', () => selectAll.init());

// ── Dynamic new-category input rows ───────────────────────────────────────────

(function () {
    const categories = $('categories');
    if (!categories) { return; }

    function addCat(lastInput) {
        if (lastInput) {
            lastInput.removeEventListener('change', handleChange);
        }
        const input       = document.createElement('input');
        input.type        = 'text';
        input.name        = 'new_cat[]';
        input.value       = '';
        input.className   = 'new';
        input.addEventListener('change', handleChange);

        const li = document.createElement('li');
        li.appendChild(input);
        categories.appendChild(li);
    }

    function handleChange() {
        addCat(this);
    }

    addCat(null);
}());

// ── "Now" timestamp toggle ────────────────────────────────────────────────────

(function () {
    const nowCheckbox = $('now');
    if (!nowCheckbox) { return; }

    let timer = null;

    function setNow() {
        if (nowCheckbox.checked) {
            const nd = new Date();
            $('added').value = nd.strftime('%Y-%m-%d %g:%i %a %O');
            timer = setTimeout(setNow, 1000);
        } else {
            clearTimeout(timer);
            timer = null;
        }
    }

    nowCheckbox.addEventListener('click', setNow);
}());
