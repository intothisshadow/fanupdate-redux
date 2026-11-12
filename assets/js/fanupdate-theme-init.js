/**
 * FanUpdate — fanupdate-theme-init.js
 *
 * Loaded synchronously in <head> to apply the saved night theme before first
 * paint, avoiding a flash of the day theme. Swaps the stylesheet link's
 * file name only, so the path prefix and ?v= cache-buster are preserved.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

(function () {
    'use strict';

    var link = document.getElementById('fu-theme-css');
    if (!link) { return; }

    var saved = null;
    try { saved = localStorage.getItem('fu_theme'); } catch (e) { /* storage unavailable */ }

    if (saved === 'night') {
        link.setAttribute('href', link.getAttribute('href').replace('style.css', 'style-night.css'));
    }
}());
