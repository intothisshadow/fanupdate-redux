<?php
declare(strict_types=1);

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Handles comment submission: spam gates (honeypot, time gate, per-IP rate
 * limit, duplicate guard), CSRF/captcha verification, blacklist filtering,
 * point-based spam scoring, and comment persistence.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/gpl-3.0.html>.
 ******************************************************************************/

// ── Spam-gate constants ───────────────────────────────────────────────────────
// Minimum seconds between form render and form submit.
// Real humans take at least 3 seconds; bots submit instantly.
define('FU_MIN_COMMENT_SECONDS', 3);

// Window in seconds for the duplicate-comment check (5 minutes).
define('FU_DUPE_WINDOW', 5 * 60);

$clean['entry'] = '';
$clean['name'] = '';
$clean['email'] = '';
$clean['url'] = '';
$clean['comment'] = '';

$show_comment = false;
$points = 0;

if (isset($_POST['submit_comment'])) {

    require_once('blog-config.php');
    require_once('functions.php');

    $fu = FanUpdate::instance();

    $clean = clean_input($_POST);

    $result_string = '';

    // ── SILENT BOT GATES ─────────────────────────────────────────────────────
    // These four checks run before any DB access or user-visible error messages.
    // On failure: produce a fake-success response so bots get no signal.

    $bot_detected = false;

    // 1. Honeypot — hidden field that real users never fill in
    if (!$bot_detected && !empty($_POST['fax'])) {
        $bot_detected = true;
    }

    // 2. Time gate — submission faster than FU_MIN_COMMENT_SECONDS is a bot
    if (!$bot_detected) {
        $form_time = (int) ($_POST['fu_form_time'] ?? 0);
        if ($form_time === 0 || (time() - $form_time) < FU_MIN_COMMENT_SECONDS) {
            $bot_detected = true;
        }
    }

    // 3. Per-IP rate limit
    if (!$bot_detected) {
        $commentRateLimiter = new CommentRateLimiter();
        if ($commentRateLimiter->isBlocked()) {
            $fu->logEvent(LogService::COMMENT_BLOCKED, 'Public comment blocked by rate limit.', '');
            $bot_detected = true;
        }
    }

    // 4. Duplicate guard — same IP, same comment text within FU_DUPE_WINDOW
    if (!$bot_detected) {
        $dupe_key  = 'fu_last_comment_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        $dupe_file = sys_get_temp_dir() . '/' . $dupe_key . '.json';
        if (is_file($dupe_file)) {
            $dupe_data = json_decode((string) file_get_contents($dupe_file), true);
            if (
                is_array($dupe_data)
                && isset($dupe_data['time'], $dupe_data['hash'])
                && (time() - (int) $dupe_data['time']) < FU_DUPE_WINDOW
                && $dupe_data['hash'] === md5($clean['comment'])
            ) {
                $bot_detected = true;
            }
        }
    }

    if ($bot_detected) {
        // Return a plausible fake-success so bots/scrapers get no signal.
        if ($_POST['submit_comment'] === 'ajax') {
            echo "<!-- SUCCESS do not remove -->\n";
            echo "<h3>Thank You</h3>\n";
            echo '<p>Thank you for your comment. In an effort to reduce spam, your comment has been held for moderation and will not appear on the site until it has been approved.</p>' . "\n";
            exit;
        }
        // Non-AJAX: redirect back silently
        $return_url = !empty($clean['returnto']) ? $clean['returnto'] : '/';
        [$return_page] = explode('#', $return_url);
        header('Location: ' . $return_page);
        exit;
    }

    // ── END SILENT BOT GATES ─────────────────────────────────────────────────

    // check for valid entry_id
    if (empty($clean['entry']) || !ctype_digit($clean['entry']) || $clean['entry'] <= 0) {

        $fu->addErr('Spam sucks, and you suck too.');

        // check for actual comment
    } elseif (empty($clean['comment'])) {

        $fu->addErr('Your comment is blank. You must have something to say!');

        // an update install may be swapping files right now — reject rather
        // than risk a request straddling old and new code mid-write
    } elseif ($fu->isMaintenanceModeEnabled()) {

        $fu->addErr('Comments are temporarily unavailable while the site is being updated. Please try again in a few minutes.');
    } else {

        $fu->addOptFromDb();

        // find entry — GetRecord() executes, fetches one row, and frees the
        // result atomically. Do NOT call NumRows() after GetRecord(); the
        // result is already freed by then and NumRows() would return 0.
        $sql_entry = (int)$clean['entry'];

        $query_check = 'SELECT * FROM ' . $fu->getOpt('blog_table') . '
          WHERE entry_id = ?
          LIMIT 1';

        $row = $fu->db->GetRecord($query_check, [$sql_entry]);

        if (!empty($row)) {

            $post = new FanUpdate_Post($row, $fu);

            // are comments even allowed?
            if ($post->allowComments()) {

                $pass_captcha = true;

                // check captcha, if enabled
                if ($fu->getOpt('captcha_on')) {

                    // Set Secure + SameSite=Lax before session_start() so the
                    // PHPSESSID cookie is sent correctly over HTTPS. Without
                    // Secure the cookie set by captcha.php (which also calls
                    // session_start() with these same params) won't arrive
                    // here when the page was originally loaded over HTTP and
                    // Firefox auto-upgraded the AJAX POST to HTTPS.
                    session_set_cookie_params([
                        'lifetime' => 0,
                        'path'     => '/',
                        'secure'   => true,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);

                    session_start();

                    $submitted_code = strtolower(trim((string) ($_POST['captcha'] ?? '')));

                    if (!isset($_SESSION['security_code']) || !hash_equals((string) $_SESSION['security_code'], $submitted_code)) {
                        $pass_captcha = false;
                        $fu->addErr('The text you entered does not match the captcha image. Please try again.');
                    } else {
                        // Only destroy the session on success — keeps the
                        // session alive so the user can retry on failure.
                        setcookie(session_name(), '', time() - 36000, '/');
                        $_SESSION = [];
                        session_destroy();
                    }
                }

                if ($pass_captcha) {

                    $pass_blacklist = true;

                    // check whole comment against blacklist
                    // normalize to lower case
                    $test_comment = strtolower($clean['name'] . ' ' . $clean['email'] . ' ' . $clean['url'] . ' ' . $clean['comment']);

                    $query_check = 'SELECT * FROM ' . $fu->getOpt('blacklist_table');
                    $fu->db->Execute($query_check);

                    while ($row = $fu->db->ReadRecord()) {
                        if (strpos($test_comment, $row['badword']) !== false) {
                            if ($fu->getOpt('points_scoring')) {
                                --$points;
                            } else {
                                $fu->addErr('Please remove all spammy words from your comment and try again.');
                                $pass_blacklist = false;
                                break;
                            }
                        }
                    }
                    $fu->db->FreeResult();

                    if ($pass_blacklist) {

                        $clean['name'] = preg_replace("/\r/", '', $clean['name']);
                        $clean['name'] = preg_replace("/\n/", '', $clean['name']);

                        $clean['email'] = preg_replace("/\r/", '', $clean['email']);
                        $clean['email'] = preg_replace("/\n/", '', $clean['email']);

                        if (!empty($clean['url']) && strpos($clean['url'], 'http') !== 0) {
                            $clean['url'] = 'http://' . $clean['url'];
                        }

                        if (!is_url($clean['url'])) { // bad URL, erase it
                            $clean['url'] = '';
                        }
                        if (!is_email($clean['email'])) { // bad email, erase it
                            $clean['email'] = '';
                        }

                        $ip_hostname = gethostbyaddr($_SERVER['REMOTE_ADDR']);

                        if ($fu->getOpt('points_scoring')) {

                            // no host name
                            if ($ip_hostname === $_SERVER['REMOTE_ADDR']) {
                                $points -= 2;
                            }

                            // language header
                            $lang = trim($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                            if (empty($lang)) {
                                $points -= 4;
                            }

                            // number of links in comment
                            // check un-cleaned comment (with tags intact)
                            $num_links = preg_match_all('!([\w]+?://|www\.)[\w#$%&~/.\-;:=,?@\[\]+]+!', $_POST['comment'], $matches);

                            if ($num_links > 2) {
                                $points -= $num_links;
                            } elseif ($num_links === 0) {
                                $points += 2;
                            }

                            // it's a link...
                            if (!empty($clean['url'])) {
                                --$points;
                                if (strlen($clean['url']) > 35) {
                                    $points -= 2;
                                }
                            }

                            // length of comment
                            $len = strlen($clean['comment']);
                            if (($len > 20) && ($num_links == 0)) {
                                ++$points;
                            } elseif ($len < 20) {
                                $points -= 2;
                            }

                            // previous comments from email
                            if (!empty($clean['email'])) {
                                $num_approved = $fu->db->GetFirstCell(
                                    'SELECT COUNT(*) FROM ' . $fu->getOpt('comments_table') . ' WHERE email = ? AND approved = 1 AND added < DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL -7 DAY)',
                                    [$clean['email']]
                                );
                                $num_spam = $fu->db->GetFirstCell(
                                    'SELECT COUNT(*) FROM ' . $fu->getOpt('comments_table') . ' WHERE email = ? AND approved = 0 AND added < DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL -7 DAY)',
                                    [$clean['email']]
                                );

                                $points += $num_approved;
                                $points -= $num_spam;
                            } else {
                                $points -= 2; // no email, penalize
                            }
                        } // end if use points

                        $clean['approved'] = 1;
                        $status = 'Approved';

                        if ($fu->getOpt('comment_moderation') || ($fu->getOpt('points_scoring') && $points < (int) $fu->getOpt('points_approval_threshold', true))) {
                            $clean['approved'] = 0;
                            $status = 'Pending';
                        }

                        $clean['comment'] = str_replace('[B]', '<strong>', $clean['comment']);
                        $clean['comment'] = str_replace('[/B]', '</strong>', $clean['comment']);
                        $clean['comment'] = str_replace('[I]', '<em>', $clean['comment']);
                        $clean['comment'] = str_replace('[/I]', '</em>', $clean['comment']);
                        $clean['comment'] = str_replace('[Q]', '<blockquote>', $clean['comment']);
                        $clean['comment'] = str_replace('[/Q]', '</blockquote>', $clean['comment']);

                        // Require that bbcode links be valid URLS -- no XSS -- thanks for the heads up, Jem!
                        $clean['comment'] = preg_replace("!\[URL=([\w]+?://[\w#$%&~/.\-;:=,?@\[\]+]+)](.*?)\[/URL]!", "<a href=\"\\1\">\\2</a>", $clean['comment']);
                        $clean['comment'] = preg_replace("!\[URL]([\w]+?://[\w#$%&~/.\-;:=,?@\[\]+]+)\[/URL]!", "<a href=\"\\1\">\\1</a>", $clean['comment']);

                        // Notes:
                        //  secure   = true   — site is HTTPS-only
                        //  httponly = false  — must stay JS-readable so the comment form can pre-fill
                        //                      name/email/url fields on return visits
                        //  samesite = Lax    — Lax not Strict: Strict would break cross-page remembrance

                        if ($clean['remember_me'] === 'y') {

                            $cookie_life = time() + 31536000; // Life of one year

                            $commenter_opts = [
                                'expires'  => $cookie_life,
                                'path'     => '/',
                                'secure'   => true,
                                'httponly' => false,
                                'samesite' => 'Lax',
                            ];

                            setcookie('fanuname',  $clean['name'],  $commenter_opts);
                            setcookie('fanuemail', $clean['email'], $commenter_opts);
                            setcookie('fanuurl',   $clean['url'],   $commenter_opts);
                        }


                        $clean['added'] = gmdate('Y-m-d H:i:s');

                        $sql_entry = (int) $clean['entry'];

                        $query = 'INSERT INTO ' . $fu->getOpt('comments_table') . '
	                      (entry_id, name, email, url, comment, approved, added, points)
	                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

                        $fu->db->Execute($query, 'Could not execute query.', [
                            $sql_entry,
                            $clean['name'],
                            $clean['email'],
                            $clean['url'],
                            $clean['comment'],
                            (int) $clean['approved'],
                            $clean['added'],
                            $points,
                        ]);

                        $clean['comment_id'] = $fu->db->GetLastSequence();

                        // ── Record this submission for duplicate and rate-limit tracking ──
                        // Do this AFTER a successful INSERT so partial/failed attempts are
                        // not counted. Both the dupe file and the rate limiter are updated.

                        // Duplicate guard: write the hash of this comment so identical
                        // resubmissions within FU_DUPE_WINDOW are silently discarded.
                        $dupe_key  = 'fu_last_comment_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
                        $dupe_file = sys_get_temp_dir() . '/' . $dupe_key . '.json';
                        file_put_contents(
                            $dupe_file,
                            json_encode(['time' => time(), 'hash' => md5($clean['comment'])]),
                            LOCK_EX
                        );

                        // Rate limiter: count this successful submission.
                        if (isset($commentRateLimiter)) {
                            $commentRateLimiter->recordSubmission();
                        } else {
                            (new CommentRateLimiter())->recordSubmission();
                        }

                        if ($fu->getOpt('email_new_comments')) {

                            $subject = strtoupper($status) . ' Comment on ' . $post->getTitle();

                            $msg = '';

                            $msg .= "Name:\t" . $clean['name'] . "\r\n";
                            $msg .= "Email:\t" . $clean['email'] . "\r\n";
                            $msg .= "URL:\t" . $clean['url'] . "\r\n\r\n";
                            $msg .= $clean['comment'] . "\r\n\r\n";

                            $msg .= "==========\r\n\r\n";

                            $msg .= "Status:\t" . $status . "\r\n";
                            if ($fu->getOpt('points_scoring')) {
                                $msg .= "Points:\t" . $points . "\r\n";
                            }
                            $msg .= "\r\n";

                            if ($clean['approved'] == 0) {
                                $msg .= "This comment has been held for moderation. Visit your admin panel to approve or delete it:\r\n";
                                $msg .= $fu->getOpt('install_url') . '/comment.php?id=' . $clean['comment_id'] . "\r\n\r\n";
                            } else {
                                $msg .= "This comment has been approved and can be seen here:\r\n";
                                $msg .= $fu->getOpt('blog_page') . '?id=' . $clean['entry'] . '#comment' . $clean['comment_id'] . "\r\n\r\n";

                                $msg .= "Admin link:\r\n";
                                $msg .= $fu->getOpt('install_url') . '/comment.php?id=' . $clean['comment_id'] . "\r\n\r\n";
                            }

                            $msg .= "==========\r\n\r\n";

                            $msg .= "Time:\t\t" . date('r') . "\r\n";
                            $msg .= "Referrer:\t" . ($_SERVER['HTTP_REFERER'] ?? '') . "\r\n";
                            $msg .= "Sender IP:\t" . $_SERVER['REMOTE_ADDR'] . "\r\n";
                            $msg .= "Hostname:\t" . $ip_hostname . "\r\n";
                            $msg .= "User Agent:\t" . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\r\n";

                            $mailheaders = 'From: "FanUpdate ' . $fu->getOpt('version') . '" <' . $fu->getOpt('admin_email') . ">\r\n";

                            if ($points >= $fu->getOpt('points_pending_threshold', true)) {
                                // Cast to string: getOpt() returns mixed; mail() expects string $to.
                                @mail((string)($fu->getOpt('admin_email') ?: ''), $subject, $msg, $mailheaders);
                            }
                        }

                        $result_string = "<!-- SUCCESS do not remove -->\n";

                        if ($clean['approved'] === 0) {

                            $result_string .= "<h3>Thank You</h3>\n";
                            $result_string .= '<p>Thank you for your comment. In an effort to reduce spam, your comment has been held for moderation and will not appear on the site until it has been approved.</p>' . "\n";
                        } else {

                            $show_comment = true;

                            $cmt = new FanUpdate_Comment($clean, $fu);

                            $result_string .= "<h3>Thank you for your comment:</h3>\n";
                        }
                    } // end pass_blacklist

                } // end pass_captcha

            } else {
                // comments are off
                $fu->reportErrors('Comments are currently not permitted on this post.');
            } // end allowComments

        } // end entry exists

    }

    if ($_POST['submit_comment'] !== 'ajax') {
        if (isset($clean['approved']) && $clean['approved'] > 0) {
            [$return] = explode('#', $clean['returnto']);
            header('Location: ' . $return . '#comment' . $clean['comment_id']);
        }

        $fu->getHeader('Comment Submitted');
    }

    $fu->reportErrors();

    echo $result_string;

    if ($show_comment) {
        $cmt->printComment();
    }

    if ($_POST['submit_comment'] !== 'ajax') {
        echo '<p><a href="' . $clean['returnto'] . '">Return to the previous page</a>.</p>' . "\n";
        $fu->getFooter(false);
    }
}
