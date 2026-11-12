/**
 * FanUpdate — fanupdate-progress.js
 *
 * admin/update.php: on submit of any form marked [data-fu-progress], disables
 * its submit button, reveals the progress panel and polls
 * update.php?action=install-progress until the response replaces the page.
 * The element ids come from the form's data-panel, data-message and data-bar
 * attributes, and the button text from data-busy-label.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

(function () {
    'use strict';

    const POLL_MS = 800;

    function pollProgress(message, bar) {
        fetch('update.php?action=install-progress', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.message) { return; }
                if (message) { message.textContent = data.message + '…'; }
                if (bar && data.total) {
                    bar.style.width = Math.round((data.step / data.total) * 100) + '%';
                }
            })
            .catch(function () { /* ignore transient poll failures */ });
    }

    document.querySelectorAll('form[data-fu-progress]').forEach(function (form) {
        form.addEventListener('submit', function () {
            const panel   = document.getElementById(form.dataset.panel);
            const message = document.getElementById(form.dataset.message);
            const bar     = document.getElementById(form.dataset.bar);
            const button  = form.querySelector('input[type="submit"]');

            if (button) {
                button.disabled = true;
                button.value    = form.dataset.busyLabel || button.value;
            }
            if (panel) { panel.style.display = 'block'; }

            setInterval(function () { pollProgress(message, bar); }, POLL_MS);
        });
    });
})();
