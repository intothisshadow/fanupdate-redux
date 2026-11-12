/**
 * FanUpdate — fanupdate-install.js
 *
 * install.php: pre-selects the browser's timezone when it is present in the
 * timezone <select>.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

(function () {
    'use strict';

    const tzSelect = document.getElementById('timezone');
    if (!tzSelect) { return; }

    try {
        const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const option   = tzSelect.querySelector('option[value="' + detected + '"]');
        if (option) {
            tzSelect.value = detected;
        }
    } catch (e) {
        // Intl unsupported or timezone lookup failed — leave default selected.
    }
})();
