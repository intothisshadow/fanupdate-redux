/**
 * FanUpdate — fanupdate-admin-config.js
 *
 * Exposes the per-page values admin/header.php prints as <meta> tags as
 * globals for the other admin scripts. Kept external (no inline script) so
 * the admin works under a strict `script-src 'self'` Content-Security-Policy.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

function fuMetaContent(name) {
    const meta = document.querySelector('meta[name="' + name + '"]');
    return meta ? meta.getAttribute('content') || '' : '';
}

const fuCsrfToken    = fuMetaContent('fu-csrf-token');
const fuAssetVersion = fuMetaContent('fu-asset-version');
