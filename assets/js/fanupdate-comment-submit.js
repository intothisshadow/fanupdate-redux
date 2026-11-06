/**
 * FanUpdate — fanupdate-comment-submit.js
 *
 * Front-end comment submission via AJAX.
 * Requires: fanupdate.js (makeRequest, $, getEventTarget, addEvent)
 *
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * The captcha and remember_me fields are optional in some form templates, so
 * they are accessed with optional chaining; a TypeError would silently kill
 * the submit handler before the AJAX call.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

'use strict';

function submitCmt(e) {
    e.preventDefault();

    const f        = e.currentTarget;  // the <form>
    const targetID = 'newComment';

    const name        = f.name.value;
    const email       = f.email.value;
    const url         = f.url.value;
    const remember_me = f.remember_me?.checked ? 'y' : '';
    const comment     = f.comment.value;
    const captcha     = f.captcha ? f.captcha.value : '';
    const entry       = f.entry.value;

    if (comment.length === 0) {
        alert('Your comment is blank!');
        return false;
    }

    const qs = 'name='        + encodeURIComponent(name)
             + '&email='      + encodeURIComponent(email)
             + '&url='        + encodeURIComponent(url)
             + '&remember_me='+ encodeURIComponent(remember_me)
             + '&comment='    + encodeURIComponent(comment)
             + '&captcha='    + encodeURIComponent(captcha)
             + '&entry='      + encodeURIComponent(entry)
             + '&submit_comment=ajax';

    makeRequest(f.action, targetID, 'POST', qs, submitCmtCallback);

    location.hash = targetID;

    return false;
}

function submitCmtCallback(responseText, targetID, url) {
    const el = $(targetID);
    el.innerHTML = responseText;

    const success = /^<!-- SUCCESS/;

    if (responseText.match(success)) {
        const toRemove = $('comments-form');
        if (toRemove) {
            toRemove.parentNode.removeChild(toRemove);
        }
    } else {
        // failed — regenerate captcha if enabled
        const captchaImg = $('captcha-img');
        if (captchaImg) {
            const path = url.replace('process.php', '');
            captchaImg.src = path + 'captcha.php?' + Math.random();
        }
    }
}

// ── Form initialisation ─────────────────────────────────────────────────────

function submitCmtInit() {
    const form = $('comments-form');
    if (!form) { return; }

    if (!form.dataset.submitWired) {
        form.addEventListener('submit', submitCmt);
        form.dataset.submitWired = '1';
    }
}

window.addEventListener('load', submitCmtInit);
