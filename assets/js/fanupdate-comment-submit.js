/**
 * FanUpdate — fanupdate-comment-submit.js
 *
 * Front-end comment submission via AJAX.
 * Requires: fanupdate.js (makeRequest, $, makeWysiwyg, wysiwygButton,
 *           formatText, insertLink, getEventTarget, addEvent)
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 modernised for current browsers
 *
 * Modernisation:
 *  - var → const / let
 *  - Removed implicit global leaks (captcha, success, toRemove)
 *  - form.submit listener uses addEventListener
 *  - captcha element check uses optional chaining
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
    const remember_me = f.remember_me.checked ? 'y' : '';
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

// ── Front-end WYSIWYG toolbar for the comment textarea ────────────────────────

const myWysiwyg = [
    new wysiwygButton('bold',   fu_url + '/css/text_bold.png',   'make bold',    e => { formatText(getEventTarget(e).className, '[B]', '[/B]');              return false; }),
    new wysiwygButton('italic', fu_url + '/css/text_italic.png', 'make italic',  e => { formatText(getEventTarget(e).className, '[I]', '[/I]');              return false; }),
    new wysiwygButton('link',   fu_url + '/css/link.png',        'insert link',  e => { insertLink(getEventTarget(e).className, true);                       return false; }),
];

window.addEventListener('load', () => {
    const form = $('comments-form');
    if (!form) { return; }

    form.addEventListener('submit', submitCmt);

    const ta = form.getElementsByTagName('textarea')[0];
    if (ta) {
        makeWysiwyg(ta);
    }
});
