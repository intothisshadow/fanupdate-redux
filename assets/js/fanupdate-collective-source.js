/**
 * FanUpdate — fanupdate-collective-source.js
 *
 * admin/collective-source.php: auto-fills the other fields and toggles the
 * Custom… field when the script dropdown changes. UI only: values stay
 * editable and submit as plain text. Presets arrive as JSON in
 * #fu-collective-presets[data-presets].
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

(function () {
    'use strict';

    const presetsEl  = document.getElementById('fu-collective-presets');
    const select     = document.getElementById('collective_script');
    const customWrap = document.getElementById('collective_script_custom_wrap');
    const colId      = document.getElementById('col_id');
    const colSubj    = document.getElementById('col_subj');
    const colTable   = document.getElementById('collective_table');
    if (!presetsEl || !select || !customWrap || !colId || !colSubj || !colTable) { return; }

    let presets = {};
    try { presets = JSON.parse(presetsEl.dataset.presets || '{}'); } catch (e) { /* no presets */ }

    select.addEventListener('change', function () {
        customWrap.style.display = (this.value === 'custom') ? 'block' : 'none';

        const preset = presets[this.value];
        if (preset) {
            colId.value    = preset.id;
            colSubj.value  = preset.subject;
            colTable.value = preset.table;
        }
    });
})();
