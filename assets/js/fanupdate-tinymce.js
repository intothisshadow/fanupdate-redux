/**
 * FanUpdate — fanupdate-tinymce.js
 *
 * Self-hosted TinyMCE 7 (assets/vendor/tinymce/; see
 * docs/THIRD-PARTY-LICENSES.md) for admin/blog.php's post-body textarea
 * (#body). Loaded only on the add/edit form, since TinyMCE is ~1.6 MB.
 *
 * Requires: tinymce.min.js (loaded first), fanupdate.js, and the fuCsrfToken
 * global from admin/header.php.
 *
 * Editor configuration:
 *  - license_key 'gpl' is required to self-host under the GPL without the
 *    "no valid API key" banner; nothing talks to Tiny Cloud.
 *  - Skin is chosen once at init from the saved fu_theme (localStorage).
 *    TinyMCE can't hot-swap skins, so the editor chrome follows a theme
 *    toggle only after a reload.
 *  - Autoresize reads the fuEditorMinHeight/fuEditorMaxHeight globals from
 *    admin/blog.php (editor_min_height/editor_max_height options, advanced
 *    settings not in Admin -> Options), defaulting to 300/800.
 *  - "paste" and "hr" are core behaviour in TinyMCE 6+, not plugins.
 *  - The toolbar is two fixed rows rather than TinyMCE's "More…" overflow
 *    drawer, which needs a manual close after every button (our fuimage
 *    force-closes it; see fuTinyCollapseToolbarOverflow()). List buttons
 *    live in a custom `fumore` menu, which auto-closes; the `lists` plugin
 *    still does the list work.
 *  - Images use a custom Upload New / Choose Existing dialog
 *    (fuOpenImageModal()) backed by admin/upload.php and admin/media.php,
 *    not the stock "image" plugin. Drag-and-drop and pasted images go
 *    through images_upload_handler.
 *  - No custom valid_elements: TinyMCE's bundled DOMPurify and default schema
 *    already strip <script>, on* handlers and javascript: URLs. That is
 *    client-side defence in depth only; the security boundary is unchanged
 *    (the body is stored via clean_input(..., allowtags: true) and escaped
 *    at render).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

// ── "Insert image" modal: Upload New / Choose Existing ───────────────────────
// Reuses the .fu-image-modal-overlay/.fu-image-modal/.media-grid-style CSS
// already defined in assets/css/style.css/style-night.css (built for the
// previous editor's image button — the markup/classes are generic, not
// specific to a textarea-based editor, so nothing there needed to change).

let _fuTinyImageModal   = null; // overlay element (created once, reused)
let _fuTinyUploadInput  = null; // <input type="file"> (created once, reused)
let _fuTinyEditor       = null; // the TinyMCE editor instance the modal was opened for
let _fuTinyMediaPage       = 1;
let _fuTinyMediaTotalPages = 1;

/** Inserts <a href="FULL" class="fu-lightbox"><img src="THUMB" alt="" /></a> at the caret. */
function fuTinyInsertImage(editor, fullUrl, thumbUrl) {
    editor.insertContent(
        '<a href="' + fullUrl + '" class="fu-lightbox"><img src="' + thumbUrl + '" alt="" /></a>'
    );
}

function fuTinyEnsureUploadInput() {
    if (_fuTinyUploadInput) { return; }

    _fuTinyUploadInput = document.createElement('input');
    _fuTinyUploadInput.type     = 'file';
    _fuTinyUploadInput.accept   = 'image/jpeg,image/png,image/gif,image/webp';
    _fuTinyUploadInput.multiple = true;
    _fuTinyUploadInput.style.cssText = 'position:absolute;left:-9999px;width:1px;height:1px;';
    _fuTinyUploadInput.setAttribute('aria-hidden', 'true');
    _fuTinyUploadInput.setAttribute('tabindex', '-1');
    document.body.appendChild(_fuTinyUploadInput);
}

function fuTinyBuildImageModal() {
    const overlay = document.createElement('div');
    overlay.className = 'fu-image-modal-overlay';
    overlay.style.display = 'none';

    overlay.innerHTML =
        '<div class="fu-image-modal" role="dialog" aria-modal="true" aria-label="Insert image">' +
            '<button type="button" class="button fu-image-modal-close" aria-label="Close">' +
                '<i class="ti ti-x" aria-hidden="true"></i>' +
            '</button>' +
            '<div class="fu-image-modal-tabs">' +
                '<button type="button" class="active" data-tab="upload">Upload New</button>' +
                '<button type="button" data-tab="browse">Choose Existing</button>' +
            '</div>' +
            '<div class="fu-image-modal-body">' +
                '<div data-panel="upload">' +
                    '<p><button type="button" class="button add" id="fu-tiny-choose-file">' +
                        '<i class="ti ti-upload" aria-hidden="true"></i> Choose File&hellip;' +
                    '</button></p>' +
                    '<p class="hint small">JPEG, PNG, GIF, or WebP.</p>' +
                '</div>' +
                '<div data-panel="browse" style="display:none;">' +
                    '<div class="fu-image-modal-grid"></div>' +
                    '<p class="paginate" style="display:flex; justify-content:space-between; align-items:center;">' +
                        '<button type="button" class="button" data-page-nav="prev">Previous</button>' +
                        '<span class="hint small" data-page-label></span>' +
                        '<button type="button" class="button" data-page-nav="next">Next</button>' +
                    '</p>' +
                '</div>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    overlay.querySelector('.fu-image-modal-close').addEventListener('click', fuTinyCloseImageModal);
    overlay.addEventListener('click', e => {
        if (e.target === overlay) { fuTinyCloseImageModal(); }
    });

    overlay.querySelectorAll('.fu-image-modal-tabs button').forEach(tabBtn => {
        tabBtn.addEventListener('click', () => fuTinySwitchImageModalTab(overlay, tabBtn.getAttribute('data-tab')));
    });

    overlay.querySelector('#fu-tiny-choose-file').addEventListener('click', e => {
        // Capture the button now, synchronously, while the click event is
        // still dispatching — e.currentTarget is only valid during dispatch
        // per the DOM spec and reverts to null immediately afterwards, so
        // reading it lazily inside the file input's onchange (which only
        // fires later, after the OS file picker closes) always returns null.
        const chooseFileBtn = e.currentTarget;
        fuTinyEnsureUploadInput();
        _fuTinyUploadInput.onchange = ev => fuTinyUploadImage(ev, chooseFileBtn);
        _fuTinyUploadInput.click();
    });

    overlay.querySelector('[data-page-nav="prev"]').addEventListener('click', () => {
        if (_fuTinyMediaPage > 1) { fuTinyLoadMediaPage(_fuTinyMediaPage - 1); }
    });
    overlay.querySelector('[data-page-nav="next"]').addEventListener('click', () => {
        if (_fuTinyMediaPage < _fuTinyMediaTotalPages) { fuTinyLoadMediaPage(_fuTinyMediaPage + 1); }
    });

    document.addEventListener('keydown', e => {
        if (overlay.style.display === 'none') { return; }
        if (e.key === 'Escape') {
            fuTinyCloseImageModal();
            return;
        }
        // ── basic focus trap: Tab/Shift+Tab cycle within the modal ──────────
        if (e.key === 'Tab') {
            const focusable = Array.from(
                overlay.querySelectorAll('button, [href], input, [tabindex]:not([tabindex="-1"])')
            ).filter(el => !el.disabled && el.offsetParent !== null);
            if (focusable.length === 0) { return; }

            const first = focusable[0];
            const last  = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    _fuTinyImageModal = overlay;
    return overlay;
}

function fuTinySwitchImageModalTab(overlay, tabName) {
    overlay.querySelectorAll('.fu-image-modal-tabs button').forEach(tabBtn => {
        tabBtn.classList.toggle('active', tabBtn.getAttribute('data-tab') === tabName);
    });
    overlay.querySelectorAll('[data-panel]').forEach(panel => {
        panel.style.display = panel.getAttribute('data-panel') === tabName ? '' : 'none';
    });

    if (tabName === 'browse') {
        fuTinyLoadMediaPage(1);
    }
}

/** Fetches admin/media.php?action=list&p=N and renders the thumbnail grid. */
function fuTinyLoadMediaPage(page) {
    const grid  = _fuTinyImageModal.querySelector('.fu-image-modal-grid');
    const label = _fuTinyImageModal.querySelector('[data-page-label]');

    grid.innerHTML = '<p class="hint small">Loading&hellip;</p>';

    fetch('media.php?action=list&p=' + page)
        .then(r => {
            if (!r.ok) { throw new Error('Server error ' + r.status); }
            return r.json();
        })
        .then(data => {
            _fuTinyMediaPage       = data.page;
            _fuTinyMediaTotalPages = data.totalPages;
            label.textContent      = 'Page ' + data.page + ' of ' + data.totalPages;

            grid.innerHTML = '';
            if (data.items.length === 0) {
                grid.innerHTML = '<p class="hint small">No images uploaded yet.</p>';
                return;
            }

            data.items.forEach(item => {
                const btn = document.createElement('button');
                btn.type  = 'button';
                btn.title = item.filename;
                const img = document.createElement('img');
                img.src = item.thumbUrl;
                img.alt = '';
                btn.appendChild(img);
                btn.addEventListener('click', () => {
                    fuTinyInsertImage(_fuTinyEditor, item.url, item.thumbUrl);
                    fuTinyCloseImageModal();
                });
                grid.appendChild(btn);
            });
        })
        .catch(err => {
            grid.innerHTML = '<p class="hint small">Could not load images: ' + err.message + '</p>';
        });
}

/**
 * fuTinyUploadImage — called when the file input fires a 'change' event.
 * POSTs each selected file to admin/upload.php sequentially (to spare shared
 * hosting and keep insertion order predictable), inserting each as soon as
 * it uploads. A failed file doesn't abort the queue; failures are reported
 * in one summary at the end.
 */
async function fuTinyUploadImage(e, uploadBtn) {
    const files = Array.from(e.target.files || []);
    if (files.length === 0) { return; }

    // Reset the input so the same file(s) can be re-selected after an error.
    e.target.value = '';

    const spinnerSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"/><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"/></svg> ';

    const savedHTML = uploadBtn.innerHTML;
    uploadBtn.style.pointerEvents = 'none';

    const setBusyLabel = (current, total) => {
        uploadBtn.innerHTML = spinnerSvg + 'Uploading' + (total > 1 ? ' ' + current + ' of ' + total : '') + '…';
    };

    const restore = () => {
        uploadBtn.innerHTML = savedHTML;
        uploadBtn.style.pointerEvents = '';
    };

    let successCount = 0;
    const failures = [];

    for (let i = 0; i < files.length; i++) {
        setBusyLabel(i + 1, files.length);

        const fd = new FormData();
        fd.append('image', files[i]);
        fd.append('csrf_token', typeof fuCsrfToken !== 'undefined' ? fuCsrfToken : '');

        try {
            const r = await fetch('upload.php', { method: 'POST', body: fd });
            if (!r.ok) { throw new Error('Server error ' + r.status); }
            const data = await r.json();

            if (data.ok) {
                // Insert immediately, in selection order — insertContent()
                // moves the caret after the inserted content, so sequential
                // inserts land in the right order without extra
                // cursor-tracking logic.
                fuTinyInsertImage(_fuTinyEditor, data.url, data.thumb);
                successCount++;
            } else {
                failures.push(files[i].name + ' (' + (data.error || 'Unknown error.') + ')');
            }
        } catch (err) {
            failures.push(files[i].name + ' (' + err.message + ')');
        }
    }

    restore();

    if (failures.length > 0) {
        alert(successCount + ' of ' + files.length + ' uploaded. Failed: ' + failures.join(', '));
    }

    // Close only after the whole batch finishes, not after the first file —
    // and only when at least one image actually made it into the editor,
    // matching the single-file behaviour of never closing on a bare
    // failure (so the admin can see the error and retry from an open modal).
    if (successCount > 0) {
        fuTinyCloseImageModal();
    }
}

/**
 * fuTinyCollapseToolbarOverflow — when the toolbar has more buttons than fit
 * on one line, TinyMCE shows a toggle (data-mce-name="overflow-button",
 * confirmed from live DOM — its accessible name is "Reveal or hide
 * additional toolbar items", not "More…") that opens a second overflow
 * row. Built-in toolbar buttons close that row automatically on click; our
 * custom "fuimage" button doesn't get that behaviour for free, so the row
 * was staying open and visually overlapping the Insert Image dialog.
 * Closed here by finding the toggle (if currently expanded) and clicking
 * it again — same DOM-level approach as the rest of this file, since
 * TinyMCE has no public API to close the overflow row directly.
 */
function fuTinyCollapseToolbarOverflow(editor) {
    const container = editor.getContainer();
    if (!container) { return; }

    const toggle = container.querySelector('button[data-mce-name="overflow-button"]');
    if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
        toggle.click();
    }
}

function fuTinyOpenImageModal(editor) {
    _fuTinyEditor = editor;

    fuTinyCollapseToolbarOverflow(editor);

    const overlay = _fuTinyImageModal || fuTinyBuildImageModal();
    fuTinySwitchImageModalTab(overlay, 'upload');
    overlay.style.display = 'flex';

    // Move keyboard focus into the dialog (its close button) rather than
    // leaving it stranded on the (now-hidden) TinyMCE toolbar button.
    const closeBtn = overlay.querySelector('.fu-image-modal-close');
    if (closeBtn) { closeBtn.focus(); }
}

function fuTinyCloseImageModal() {
    if (_fuTinyImageModal) {
        _fuTinyImageModal.style.display = 'none';
    }
    // Return focus to the editor content — TinyMCE's own toolbar button
    // isn't a stable DOM reference we can refocus directly (its onAction
    // callback doesn't receive the triggering element), so the editor body
    // is the next best landing spot for keyboard/screen-reader users.
    if (_fuTinyEditor) {
        _fuTinyEditor.focus();
    }
}

// ── Drag-and-drop / paste image uploads (images_upload_handler) ──────────────
// Core TinyMCE behaviour — no plugin required. Separate from the explicit
// "insert image" button above: this fires automatically whenever an image is
// dropped or pasted directly into the editor content.

/**
 * fuTinyUploadBlob — TinyMCE's images_upload_handler contract: takes a
 * BlobInfo + a progress callback, returns a Promise resolving to the
 * uploaded image's URL (or rejecting with an error message).
 */
function fuTinyUploadBlob(blobInfo, progress) {
    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('image', blobInfo.blob(), blobInfo.filename());
        fd.append('csrf_token', typeof fuCsrfToken !== 'undefined' ? fuCsrfToken : '');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload.php');

        xhr.upload.onprogress = e => {
            if (e.lengthComputable && typeof progress === 'function') {
                progress((e.loaded / e.total) * 100);
            }
        };

        xhr.onload = () => {
            if (xhr.status !== 200) {
                reject('HTTP error ' + xhr.status);
                return;
            }
            let data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (parseErr) {
                reject('Invalid server response.');
                return;
            }
            if (!data.ok) {
                reject(data.error || 'Upload failed.');
                return;
            }
            resolve(data.url);
        };

        xhr.onerror = () => reject('Image upload failed due to a network error.');
        xhr.send(fd);
    });
}

// ── TinyMCE init ───────────────────────────────────────────────────────────

/** Whether the saved theme preference (see themeToggleInit() in fanupdate-admin.js) is night mode. */
function fuTinyIsNightTheme() {
    try {
        return localStorage.getItem('fu_theme') === 'night';
    } catch (_) {
        return false;
    }
}

function fuInitTinyMce() {
    const textarea = document.getElementById('body');
    if (!textarea || typeof tinymce === 'undefined') { return; }

    const night = fuTinyIsNightTheme();

    tinymce.init({
        selector: '#body',
        license_key: 'gpl',
        base_url: '../assets/vendor/tinymce',
        suffix: '.min',

        skin: night ? 'oxide-dark' : 'oxide',
        content_css: night ? 'dark' : 'default',

        menubar: false,
        branding: false,
        promotion: false,

        // Keep URLs exactly as inserted (absolute): the body is displayed on
        // front-end pages, possibly on another domain, so URLs relative to
        // admin/blog.php would break.
        relative_urls: false,
        remove_script_host: false,
        convert_urls: false,

        min_height: typeof fuEditorMinHeight !== 'undefined' ? fuEditorMinHeight : 300,
        max_height: typeof fuEditorMaxHeight !== 'undefined' ? fuEditorMaxHeight : 800,
        autoresize_bottom_margin: 24,

        plugins: 'lists link table code fullscreen autoresize charmap',
        // Two fixed, always-visible rows (an array here means exactly that —
        // no TinyMCE overflow drawer for either row) rather than one row
        // plus a collapsible "More…" drawer. Requested live: the drawer
        // doesn't auto-close for anything except our own fuimage button
        // (which we force closed before opening its dialog — see
        // fuTinyCollapseToolbarOverflow()); every other item needed a
        // manual close, which felt "cumbersome and partially in the way".
        // Lists moved into the custom `fumore` dropdown menu below instead
        // of a toolbar button — a real TinyMCE menu, unlike the toolbar
        // drawer, already closes itself on selection with no hack needed.
        toolbar: [
            'undo redo | blocks | bold italic underline strikethrough | ' +
                'alignleft aligncenter alignright alignjustify',
            'fuimage table charmap | blockquote hr | link unlink | ' +
                'removeformat | fumore | code fullscreen',
        ],

        // Drag-and-drop/paste image uploads (core behaviour, no plugin).
        automatic_uploads: true,
        images_upload_handler: fuTinyUploadBlob,

        setup(editor) {
            editor.ui.registry.addButton('fuimage', {
                icon: 'image',
                tooltip: 'Insert image',
                onAction: () => fuTinyOpenImageModal(editor),
            });

            // Bullet/numbered list — a real dropdown menu (auto-closes on
            // its own) rather than two more toolbar buttons, since the
            // two fixed rows above already cover the common-case items.
            // The `lists` plugin still provides the actual list behaviour
            // (nested lists, Enter-key handling) behind these commands —
            // only its own toolbar buttons are replaced, not the plugin.
            editor.ui.registry.addMenuButton('fumore', {
                icon: 'unordered-list',
                tooltip: 'Lists',
                fetch: callback => {
                    callback([
                        {
                            type: 'menuitem',
                            text: 'Bullet list',
                            icon: 'unordered-list',
                            onAction: () => editor.execCommand('InsertUnorderedList'),
                        },
                        {
                            type: 'menuitem',
                            text: 'Numbered list',
                            icon: 'ordered-list',
                            onAction: () => editor.execCommand('InsertOrderedList'),
                        },
                    ]);
                },
            });
        },
    });

    // TinyMCE keeps #body's DOM node as the actual form field it writes back
    // to, but only on its own schedule (blur, periodic autosave-style sync) —
    // triggerSave() forces an immediate sync so the native form submission
    // always sees the current editor content, not stale text from before the
    // last blur.
    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            if (typeof tinymce !== 'undefined' && tinymce.get('body')) {
                tinymce.triggerSave();
            }
        });
    }
}

window.addEventListener('load', fuInitTinyMce);
