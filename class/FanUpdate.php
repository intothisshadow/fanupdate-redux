<?php

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 by Ariane (contributor)
 *
 * Core application class: singleton holding site options/config, the DB
 * connection, error/success message queues, the observer pattern used to
 * notify listeners on errors, session-backed CSRF token generation and
 * verification, cookie-based admin authentication (HMAC token + rate
 * limiting), smiley rendering, and printBlog() / printBlogFooter() which
 * render the public blog listing, single-post comments, and comment form
 * (including anti-spam honeypot/time-gate fields and lightbox init).
 *
 * Security note: no eval(), base64_decode() payloads, shell_exec(),
 * system(), passthru(), or preg_replace() /e modifier are used anywhere in
 * this class; file writes are limited to blog-config.php via
 * updateConfigKey() and the legacy-MD5-to-bcrypt upgrade path in login().
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

// DON'T CHANGE THESE!
$fanupdate           = $fanupdate ?? [];
$fanupdate['tables'] = [
    $fanupdate['blog_table'],
    $fanupdate['collective_table'],
    $fanupdate['catjoin_table'],
    $fanupdate['comments_table'],
    $fanupdate['options_table'],
    $fanupdate['blacklist_table'],
    $fanupdate['catoptions_table'],
    $fanupdate['smilies_table'],
];

// requires SqlConnection class, clean_input

class FanUpdate
{
    // ── explicitly declared properties (PHP 8.2: no dynamic properties) ────
    public array  $_cfg        = [];
    public string $_clean_self = '';

    public SqlConnection $db;

    public array $errors      = [];
    public array $success     = [];
    public array $smilies     = [];
    public array $smiley_imgs = [];
    public array $observers   = [];

    // ── CSRF protection ─────────────────────────────────────────────────────
    private const CSRF_SESSION_KEY = 'fu_csrf_token';
    public const  CSRF_FIELD_NAME  = 'csrf_token';

    private array $config = [
        'version' => FU_VERSION,
        'url'     => FU_URL_ORIGINAL,
        'newUrl'  => FU_URL_ROBOTESS,
    ];

    private static FanUpdate $instance;

    // ── constructor ─────────────────────────────────────────────────────────

    private function __construct()
    {
        global $fanupdate, $coltable;

        $this->_cfg = array_merge($fanupdate, $this->config);

        // getOpt(..., true) returns false when collective_script is not set at
        // all — $coltable[false] doesn't exist, leaving col_id/col_subj empty
        // and breaking every category SQL query. Fall back to 'n' (standalone).
        $collectiveScript = $this->getOpt('collective_script', true);
        if ($collectiveScript === false || $collectiveScript === '') {
            $collectiveScript = 'n';
        }
        $this->_cfg['col_id']   = $coltable[$collectiveScript]['id']      ?? '';
        $this->_cfg['col_subj'] = $coltable[$collectiveScript]['subject'] ?? '';

        // Populate smiley_imgs from the smilies directory so printSmileyImgDropdown
        // works even before addOptFromDb() is called.
        $smiliesDir = __DIR__ . '/../assets/images/smilies/';
        if (is_dir($smiliesDir)) {
            $files = glob($smiliesDir . '*.{gif,png,jpg}', GLOB_BRACE) ?: [];
            foreach ($files as $file) {
                $this->smiley_imgs[] = basename($file);
            }
            sort($this->smiley_imgs);
        }

        $this->db = SqlConnection::instance(
            (string) $this->getOpt('dbhost'),
            (string) $this->getOpt('dbuser'),
            (string) $this->getOpt('dbpass'),
            (string) $this->getOpt('dbname')
        );

        $this->_clean_self = clean_input($_SERVER['PHP_SELF']);

        $this->_cfg['entry_template'] =
            '<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>' . "\n"
            . '<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>' . "\n"
            . '{{body}}';

        $this->_cfg['comment_template'] =
            '{{gravatar}}' . "\n"
            . '<p class="commenter">On '
            . '<a href="#comment{{id}}" title="permanent link to this comment">{{date}}</a> '
            . '{{name}} said:</p>' . "\n"
            . '{{body}}';
    }

    // ── singleton ───────────────────────────────────────────────────────────

    public static function instance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── accessors ───────────────────────────────────────────────────────────

    public function getCleanSelf(): string
    {
        return $this->_clean_self;
    }

    // ── CSRF protection ─────────────────────────────────────────────────────

    /**
     * Start (or resume) the PHP session used to store the CSRF token.
     *
     * This is intentionally separate from the HMAC auth cookies
     * (fu_username/fu_token) that control login state — the session here is
     * used solely for CSRF and has no bearing on authentication.
     *
     * The session cookie mirrors the security attributes already used for
     * the auth cookies (secure, httponly, SameSite=Strict).
     */
    private function ensureSession(): void
    {
        $status = session_status();

        if ($status === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($status === PHP_SESSION_DISABLED) {
            // Sessions unavailable on this host — CSRF tokens cannot be
            // stored. verifyCsrfToken() will safely fail closed in this case.
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name('fu_session');
        session_start();
    }

    /**
     * Return the current CSRF token for this session, generating one on
     * first use. Safe to call multiple times per request — the token is
     * reused for the lifetime of the session so multiple forms on the same
     * page (and subsequent page loads) share one valid token.
     */
    public function generateCsrfToken(): string
    {
        $this->ensureSession();

        if (
            empty($_SESSION[self::CSRF_SESSION_KEY])
            || !is_string($_SESSION[self::CSRF_SESSION_KEY])
        ) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * Compare a submitted token against the session's stored token using a
     * timing-safe comparison. Returns false (never throws) when no token is
     * stored yet or the submitted value is missing/empty.
     */
    public function verifyCsrfToken(?string $token): bool
    {
        $this->ensureSession();

        $stored = $_SESSION[self::CSRF_SESSION_KEY] ?? '';

        if (!is_string($stored) || $stored === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /**
     * Render a hidden <input> carrying the current CSRF token. Include this
     * inside every admin <form method="post">.
     */
    public function csrfField(): string
    {
        $token = htmlspecialchars($this->generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="' . self::CSRF_FIELD_NAME . '" value="' . $token . '" />';
    }

    /**
     * Validate the CSRF token on the current request. Call this as the very
     * first thing inside an admin page's POST handler, before any action is
     * processed.
     *
     * Behaviour on failure:
     *  - AJAX requests (POST field mode=ajax, used by the post/comment
     *    delete buttons and the approve-comment link) receive an immediate
     *    403 response with a short plain-text body and the script exits.
     *    This is necessary because those callers only check response.ok —
     *    a 200 response re-rendering the page with an error banner would be
     *    silently treated as "delete succeeded" by the JS callback.
     *  - Regular (non-AJAX) form submissions get an error message queued via
     *    addErr() and this method returns false so the caller can skip
     *    processing the submitted action while still rendering the normal
     *    page (with the error shown via reportErrors()).
     *
     * Non-POST requests always return true — there is nothing to verify.
     */
    public function verifyCsrfOrFail(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }

        $token = $_POST[self::CSRF_FIELD_NAME] ?? null;

        if ($this->verifyCsrfToken(is_string($token) ? $token : null)) {
            return true;
        }

        if (($_POST['mode'] ?? '') === 'ajax') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Security check failed. Please refresh the page and try again.';
            exit;
        }

        $this->addErr('Security check failed (your session may have expired). Please try again.');

        return false;
    }

    // ── options ─────────────────────────────────────────────────────────────

    public function addOptFromDb(): bool
    {
        $query = 'SELECT * FROM ' . $this->getOpt('options_table');

        // Pass null as $err_msg so a missing table (fresh install) is silent.
        if ($this->db->Execute($query, null)) {
            while ($row = $this->db->ReadRecord()) {
                if ($row['optvalue'] !== '') {
                    $this->addOpt($row['optkey'], $row['optvalue']);
                }
            }
            $this->db->FreeResult();

            $query = 'SELECT * FROM ' . $this->getOpt('smilies_table');
            $this->db->Execute($query, null);
            while ($row = $this->db->ReadRecord()) {
                $this->smilies[$row['smiley']] = $row['image'];
            }
            $this->db->FreeResult();
        }

        return false;
    }

    public function addOpt(string $key, mixed $value): void
    {
        $this->_cfg[$key] = $value;
    }

    public function getOpt(string $opt_name, bool $returnLiteral = false): mixed
    {
        if (!isset($this->_cfg[$opt_name])) {
            return false;
        }

        $opt = $this->_cfg[$opt_name];

        if (!$returnLiteral) {
            if ($opt === 0 || $opt === 'n' || $opt === 'no') {
                return false;
            }
            if ($opt === 1 || $opt === 'y' || $opt === 'yes') {
                return true;
            }
        }

        return $opt;
    }

    /**
     * Write a key=value pair into blog-config.php.
     * Only updates keys that already exist in the file — never injects new lines.
     * Uses addcslashes($value, '\\$\'') so bcrypt hashes and single-quotes
     * cannot escape the string literal or introduce new PHP code.
     */
    public function updateConfigKey(string $key, string $value): bool
    {
        $configFile = __DIR__ . '/../blog-config.php';
        if (!is_readable($configFile) || !is_writable($configFile)) {
            return false;
        }

        $content      = (string) file_get_contents($configFile);
        $escapedValue = addcslashes($value, '\\$\'');
        $pattern      = "/(\\\$fanupdate\['" . preg_quote($key, '/') . "'\]\s*=\s*)'[^']*'/";
        $replacement  = "\$1'" . $escapedValue . "'";

        $newContent = preg_replace($pattern, $replacement, $content);
        if ($newContent === null || $newContent === $content) {
            return false;
        }

        $this->_cfg[$key] = $value;

        return file_put_contents($configFile, $newContent) !== false;
    }

    /**
     * Re-issue the HMAC session token after a username or password change
     * so the admin stays logged in without re-entering credentials.
     */
    public function reissueToken(string $username): void
    {
        $storedHash = (string) $this->getOpt('admin_password', true);
        if ($storedHash === '') {
            return;
        }

        $token  = hash_hmac('sha256', $username, $storedHash);
        // If there is already a session cookie use 0 (session), otherwise 30 days.
        $expire = (isset($_COOKIE['fu_username']) && $_COOKIE['fu_username'] !== '')
            ? 0
            : time() + 60 * 60 * 24 * 30;

        $this->setAuthCookie('fu_username', $username, $expire);
        $this->setAuthCookie('fu_token', $token, $expire);
    }

    // ── page rendering ──────────────────────────────────────────────────────

    public function getHeader(?string $pageTitle = null): void
    {
        include(__DIR__ . '/../admin/header.php');
    }

    public function getFooter(bool $showNav = true): void
    {
        include(__DIR__ . '/../admin/footer.php');
    }

    // ── observer pattern ────────────────────────────────────────────────────

    public function attach(object $observer_in, string $type = ''): void
    {
        $this->observers[$type] = $observer_in;
    }

    public function detach(object $observer_in): void
    {
        foreach ($this->observers as $okey => $oval) {
            if ($oval === $observer_in) {
                unset($this->observers[$okey]);
            }
        }
    }

    public function notify(string $type = ''): void
    {
        foreach ($this->observers as $key => $obs) {
            if ($type === '' || $type === $key) {
                $obs->update($this);
            }
        }
    }

    // ── errors / success ────────────────────────────────────────────────────

    public function addErr(string $msg, mixed $key = null, string $type = ''): void
    {
        $this->errors[] = $msg;
        $this->notify($type);
    }

    public function getLastErr(): array
    {
        $value = end($this->errors);
        $key   = key($this->errors);
        return [$value, $key];
    }

    public function addSuccess(string $msg, mixed $key = null): void
    {
        $this->success[] = $msg;
    }

    public function noErr(): bool
    {
        return count($this->errors) === 0;
    }

    // ── update check ────────────────────────────────────────────────────────

    public function checkUpdates(): void
    {
        $updatesFeedUrl = 'https://scripts.robotess.net/projects/fanupdate/atom.xml';
        $posts          = '';

        try {
            $doc     = new DOMDocument();
            libxml_use_internal_errors(true);
            $success = $doc->load($updatesFeedUrl);
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            if (!$success) {
                throw new \RuntimeException('Was not able to retrieve updates from remote server');
            }

            $domChannel = $doc->getElementsByTagName('channel');
            if ($domChannel->length !== 1) {
                echo '<p class="success">Feed is empty</p>';
                return;
            }

            if ($domChannel->item(0)->getElementsByTagName('item')->length === 0) {
                echo '<p class="success">Feed is empty</p>';
                return;
            }

            /** @var DOMElement $node */
            foreach ($domChannel->item(0)->getElementsByTagName('item') as $node) {
                $title       = $node->getElementsByTagName('title')->item(0)->nodeValue;
                $link        = $node->getElementsByTagName('link')->item(0)->nodeValue;
                $pubdate     = $node->getElementsByTagName('pubDate')->item(0)->nodeValue;
                $description = $node->getElementsByTagName('description')->item(0)->nodeValue;

                $timestamp = strtotime($pubdate);
                $daylong   = date('l', $timestamp);
                $monlong   = date('F', $timestamp);
                $yyyy      = date('Y', $timestamp);
                $dth       = date('jS', $timestamp);
                $min       = date('i', $timestamp);
                $_24hh     = date('H', $timestamp);

                $posts .= '<li><a href="' . htmlspecialchars($link) . '" target="_blank">'
                    . htmlspecialchars($title) . '</a><br>'
                    . $daylong . ', ' . $dth . ' ' . $monlong . ' ' . $yyyy . ', ' . $_24hh . ':' . $min
                    . '<br>' . htmlspecialchars($description) . '</li>' . "\n";
            }
        } catch (\Exception $e) {
            echo '<p class="error">Was not able to connect to feed: '
                . htmlspecialchars($e->getMessage()) . '</p>';
            return;
        }

        if ($posts !== '') {
            echo '<h3>Project RSS</h3><ul>' . $posts . '</ul>';
        }
    }

    public function reportErrors(string $err = ''): void
    {
        if ($err !== '') {
            $this->addErr($err);
        }

        if (empty($this->errors)) {
            return;
        }

        if (count($this->errors) === 1 && empty($this->success)) {
            echo '<p class="error">' . array_pop($this->errors) . "</p>\n";
        } else {
            echo '<ul class="error">' . "\n";
            foreach ($this->errors as $msg) {
                $msg = trim($msg);
                if ($msg !== '') {
                    echo '<li>' . $msg . "</li>\n";
                }
            }
            echo "</ul>\n";
        }

        $this->errors = [];
    }

    public function reportSuccess(string $success = ''): void
    {
        if ($success !== '') {
            $this->addSuccess($success);
        }

        if (empty($this->success)) {
            return;
        }

        if (count($this->success) === 1 && empty($this->errors)) {
            echo '<p class="success">' . array_pop($this->success) . "</p>\n";
        } else {
            echo '<ul class="success">' . "\n";
            foreach ($this->success as $msg) {
                $msg = trim($msg);
                if ($msg !== '') {
                    echo '<li>' . $msg . "</li>\n";
                }
            }
            echo "</ul>\n";
        }

        $this->success = [];
    }

    // ── smilies ─────────────────────────────────────────────────────────────

    public function makeSmiley(string $txt, string $img): string
    {
        return '<img src="' . $this->getOpt('install_url') . '/assets/images/smilies/' . $img . '" alt="' . htmlspecialchars($txt) . '" />';
    }

    public function replaceSmilies(string $txt): string
    {
        foreach ($this->smilies as $key => $value) {
            $pat = '!(^|\s)(' . preg_quote($key, '!') . ')(\s|$)!m';
            $txt = preg_replace_callback($pat, function (array $m) use ($value): string {
                return $m[1] . $this->makeSmiley($m[2], $value) . $m[3];
            }, $txt);
        }
        return $txt;
    }

    /**
     * Print a <select> dropdown of every image file in assets/images/smilies/.
     * Used by admin/smilies.php.
     */
    public function printSmileyImgDropdown(string $name, string $selected = ''): void
    {
        echo '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . "\n";

        if (empty($this->smiley_imgs)) {
            echo '<option value="">(no images found)</option>' . "\n";
        } else {
            foreach ($this->smiley_imgs as $file) {
                $sel = ($file === $selected) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                    . htmlspecialchars($file, ENT_QUOTES, 'UTF-8')
                    . '</option>' . "\n";
            }
        }

        echo '</select>' . "\n";
    }

    // ── WYSIWYG toolbar ──────────────────────────────────────────────────────

    /**
     * No-op placeholder — the WYSIWYG toolbar is built entirely in JavaScript
     * by fanupdate-admin.js (makeWysiwyg targets textarea.wysiwyg).
     * This method exists so admin/blog.php compiles without a fatal error.
     */
    public function printWysiwyg(string $field_id): void
    {
        // Intentionally empty. JS handles toolbar insertion.
    }

    // ── credits ─────────────────────────────────────────────────────────────

    /**
     * Render the "Powered by FanUpdate — credits" footer line.
     *
     * Uses an absolute URL for the credits link so it resolves correctly
     * regardless of how the admin panel is routed (with or without an explicit
     * admin/ path segment). The relative "credits.php" previously produced a
     * 404 when URL rewriting stripped the directory from the request URI.
     */
    public function printCredits(): void
    {
        $version    = htmlspecialchars((string) $this->getOpt('version'), ENT_QUOTES, 'UTF-8');
        $reduxUrl   = htmlspecialchars(FU_URL_REDUX, ENT_QUOTES, 'UTF-8');
        $creditsUrl = htmlspecialchars(
            rtrim((string) $this->getOpt('install_url'), '/') . '/admin/credits.php',
            ENT_QUOTES,
            'UTF-8'
        );

        echo 'Powered by <a href="' . $reduxUrl . '" target="_blank" title="FanUpdate Redux">'
            . 'FanUpdate ' . $version . '</a>'
            . ' &mdash; <a href="' . $creditsUrl . '">credits</a>';
    }

    // ── auth check (non-redirecting) ─────────────────────────────────────────

    /**
     * Returns true when the current request carries valid admin session cookies.
     * Unlike login(), this never redirects or renders a login form — it is safe
     * to call on pages that do not require authentication (e.g. credits.php).
     */
    public function isLoggedIn(): bool
    {
        if (!isset($_COOKIE['fu_username'], $_COOKIE['fu_token'])) {
            return false;
        }

        $cookieUser  = clean_input($_COOKIE['fu_username']);
        $cookieToken = clean_input($_COOKIE['fu_token']);
        $storedHash  = (string) $this->getOpt('admin_password', true);

        if ($storedHash === '') {
            return false;
        }

        $expectedToken = hash_hmac('sha256', $cookieUser, $storedHash);

        return $cookieUser === (string) $this->getOpt('admin_username', true)
            && hash_equals($expectedToken, $cookieToken);
    }

    // ── maintenance mode ────────────────────────────────────────────

    /**
     * True while InstallManager has an update install in progress.
     *
     * This is a read-only check against a flag file
     * ({install_path}/.fu-maintenance) that only InstallManager itself
     * writes/removes — see its class docblock for why this is a simple
     * flag rather than a full public-facing maintenance page. process.php
     * uses this to reject new comment submissions while a file swap is
     * in progress, since that's the one write path most likely to collide
     * badly with it.
     */
    public function isMaintenanceModeEnabled(): bool
    {
        $installPath = (string) ($this->getOpt('install_path') ?: '');
        if ($installPath === '') {
            $installPath = dirname(__DIR__);
        }

        return is_file(rtrim($installPath, '/') . '/.fu-maintenance');
    }

    // ── cookie helper ────────────────────────────────────────────────────────

    /**
     * Issue or expire a session cookie with the full security attribute set.
     *
     * Flags:
     *  secure   = true   — never sent over plain HTTP
     *  httponly = true   — inaccessible to JavaScript (XSS mitigation)
     *  samesite = Strict — not sent on cross-site requests (CSRF mitigation)
     */
    private function setAuthCookie(string $name, string $value, int $expire): void
    {
        setcookie($name, $value, [
            'expires'  => $expire,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    // ── login ────────────────────────────────────────────────────────────────

    public function login(): void
    {
        // ── logout ──────────────────────────────────────────────────────────
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            $this->setAuthCookie('fu_username', '', time() - 36000);
            $this->setAuthCookie('fu_token', '', time() - 36000);
            header('Location: ' . clean_input($_SERVER['PHP_SELF']));
            exit;
        }

        $storedHash  = (string) $this->getOpt('admin_password', true);
        $showform    = true;
        $rateLimiter = new LoginRateLimiter();

        // ── cookie check ─────────────────────────────────────────────────────
        if (isset($_COOKIE['fu_username'], $_COOKIE['fu_token'])) {
            $cookieUser  = clean_input($_COOKIE['fu_username']);
            $cookieToken = clean_input($_COOKIE['fu_token']);

            $expectedToken = hash_hmac('sha256', $cookieUser, $storedHash);

            if (
                $cookieUser === (string) $this->getOpt('admin_username', true)
                && hash_equals($expectedToken, $cookieToken)
            ) {
                $showform = false;
            } else {
                $this->addErr('Session expired or invalid. Please log in again.');
                $this->setAuthCookie('fu_username', '', time() - 36000);
                $this->setAuthCookie('fu_token', '', time() - 36000);
            }
        }

        // ── POST check ───────────────────────────────────────────────────────
        if ($showform && isset($_POST['action']) && $_POST['action'] === 'login') {

            if (!$this->verifyCsrfOrFail()) {
                // Invalid/missing CSRF token — error already queued by
                // verifyCsrfOrFail(); fall through to render the login form.
            } elseif ($rateLimiter->isLockedOut()) {
                $this->addErr(
                    'Too many failed login attempts. '
                        . 'Please try again in ' . $rateLimiter->humanTimeRemaining() . '.'
                );
                // Fall through to render the login form with the error; do not
                // process the submitted credentials at all.
            } else {

                $username = clean_input($_POST['username'] ?? '');
                $password = clean_input($_POST['password'] ?? '');

                $usernameOk = ($username === (string) $this->getOpt('admin_username', true));
                $passwordOk = false;

                if ($usernameOk) {
                    // ── bcrypt path (normal) ──────────────────────────────────
                    if (str_starts_with($storedHash, '$2')) {
                        $passwordOk = password_verify($password, $storedHash);

                        // ── MD5 legacy fallback ───────────────────────────────────
                    } elseif (preg_match('/^[0-9a-f]{32}$/', $storedHash) && md5($password) === $storedHash) {
                        $passwordOk = true;

                        $newHash    = password_hash($password, PASSWORD_BCRYPT);
                        $configFile = __DIR__ . '/../blog-config.php';

                        if (is_writable($configFile)) {
                            $configContent = (string) file_get_contents($configFile);

                            $newContent = preg_replace(
                                "/(\\\$fanupdate\['admin_password'\]\s*=\s*)(?:md5\('[^']*'\)|'[0-9a-f]{32}')/",
                                "\$1'" . addcslashes($newHash, '\\$') . "'",
                                $configContent
                            );

                            if ($newContent !== null) {
                                file_put_contents($configFile, $newContent);
                                $this->addOpt('admin_password', $newHash);
                                $storedHash = $newHash;
                            }
                        }
                    }
                }

                if ($usernameOk && $passwordOk) {
                    // ── success ───────────────────────────────────────────────
                    $rateLimiter->recordSuccess();

                    $remember = !empty($_POST['remember_me']);
                    $expire   = $remember ? time() + 60 * 60 * 24 * 30 : 0;

                    $token = hash_hmac('sha256', $username, $storedHash);

                    $this->setAuthCookie('fu_username', $username, $expire);
                    $this->setAuthCookie('fu_token', $token, $expire);

                    $showform = false;
                } else {
                    // ── failure ───────────────────────────────────────────────
                    $rateLimiter->recordFailure();

                    // Deliberate: same message for wrong username and wrong
                    // password — do not leak which one was incorrect.
                    $this->addErr('Invalid username or password. Please try again.');
                    $this->setAuthCookie('fu_username', '', time() - 36000);
                    $this->setAuthCookie('fu_token', '', time() - 36000);
                }
            } // end rate-limit else
        }

        if ($showform) {
            $this->getHeader('Login');
            ?>
            <form action="<?php echo clean_input($_SERVER['REQUEST_URI']); ?>" method="post">
                <?php echo $this->csrfField(); ?>
                <?php $this->reportErrors(); ?>
                <p><label for="username">Username:</label>
                    <input type="text" id="username" name="username" size="20" tabindex="1" />
                </p>
                <p><label for="password">Password:</label>
                    <input type="password" id="password" name="password" size="20" tabindex="2" />
                </p>
                <p id="loginButton">
                    <input type="submit" name="action" value="login" class="submit" tabindex="4" />
                    <span>
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" tabindex="3" />
                        <label for="remember_me" class="checkbox">Remember?</label>
                    </span>
                </p>
            </form>
            <script type="text/javascript">
                document.getElementById('username')?.focus();
            </script>
<?php
            $this->getFooter(false);
            exit;
        }
    }


    // ── blog rendering ──────────────────────────────────────────────────────

    public function printBlog(string $query, int $main_limit = 5, bool $single_page = false, array $params = []): void
    {
        $this->db->ExecutePaginate($query, $main_limit, $params);

        if ($this->db->NumRows() > 0) {
            while ($row = $this->db->ReadRecordPaginate()) {
                $post = new FanUpdate_Post($row, $this);
                $post->getCatFromDb();

                if ($post->allowComments()) {
                    $num_comments = (int) $this->db->GetFirstCell(
                        'SELECT COUNT(*) FROM ' . $this->getOpt('comments_table')
                        . ' WHERE entry_id=' . $post->getID() . ' AND approved=1'
                    );
                    $post->addParam('num_comments', $num_comments);
                }

                $post->printPost();

                // ── Single-post page: render comments + comment form ─────────
                if ($single_page) {
                    echo '<div id="comments">' . "\n";

                    if ($post->allowComments()) {

                        // ── approved comments list ───────────────────────────
                        $query_comments =
                            'SELECT * FROM ' . $this->getOpt('comments_table')
                            . ' WHERE entry_id=' . $post->getID()
                            . ' AND approved=1'
                            . ' ORDER BY added ASC';
                        $this->db->Execute($query_comments, null);

                        $has_comments = false;
                        while ($cmt_row = $this->db->ReadRecord()) {
                            if (!$has_comments) {
                                echo '<h3>Comments</h3>' . "\n";
                                $has_comments = true;
                            }
                            $cmt = new FanUpdate_Comment($cmt_row, $this);
                            $cmt->printComment();
                        }
                        $this->db->FreeResult();

                        // ── comment form ─────────────────────────────────────
                        $form_tpl = (string) $this->getOpt('comment_form_template');

                        if ($form_tpl === '') {
                            // Built-in default form — no option set yet.
                            // This template includes its own <form> wrapper.
                            // IMPORTANT: keep name="remember_me" in sync with
                            // fanupdate-comment-submit.js which reads f.remember_me?.checked.
                            $form_tpl =
                                '<form action="{{process_url}}" method="post" id="comments-form">' . "\n"
                                . '<h3>Leave a comment</h3>' . "\n"
                                . '<p><label for="cname">Name</label>'
                                . '<input type="text" id="cname" name="name" value="{{fanuname}}" /></p>' . "\n"
                                . '<p><label for="cemail">Email</label>'
                                . '<input type="email" id="cemail" name="email" value="{{fanuemail}}" /></p>' . "\n"
                                . '<p><label for="curl">Website</label>'
                                . '<input type="url" id="curl" name="url" value="{{fanuurl}}" /></p>' . "\n"
                                . '<!-- CAPTCHA --><p><label for="ccaptcha">CAPTCHA</label>'
                                . '<img src="{{captcha_image}}" alt="CAPTCHA" id="captcha-img" />'
                                . '<input type="text" id="ccaptcha" name="captcha" /></p><!-- END CAPTCHA -->' . "\n"
                                . '<p><label for="ccomment">Comment</label>'
                                . '<textarea id="ccomment" name="comment" rows="8"></textarea></p>' . "\n"
                                . '<!-- MODERATION --><p class="hint">Comments are moderated.</p><!-- END MODERATION -->' . "\n"
                                . '<p>'
                                . '<input type="checkbox" id="cremember" name="remember_me" value="y" />'
                                . '<label for="cremember">Remember me</label>'
                                . '</p>' . "\n"
                                . '<p>'
                                . '<input type="hidden" name="entry" value="{{entry_id}}" />'
                                . '<input type="hidden" name="returnto" value="{{returnto}}" />'
                                . '<input type="submit" name="submit_comment" value="Post comment" />'
                                . '</p>' . "\n"
                                . '</form>';
                        }

                        // ── Strip conditional blocks ──────────────────────────
                        // Accepts both <!-- END X --> (original FanUpdate style)
                        // and <!-- /X --> (alternate style) as closing markers.

                        // Strip <!-- MODERATION --> block when auto-approve is on
                        // (comment_moderation option false means auto-approve)
                        if (!$this->getOpt('comment_moderation')) {
                            $form_tpl = preg_replace(
                                '/<!--\s*MODERATION\s*-->.*?<!--\s*(?:END\s+|\/)?MODERATION\s*-->/s',
                                '',
                                $form_tpl
                            );
                        }

                        // Strip <!-- CAPTCHA --> block when CAPTCHA is disabled
                        if (!$this->getOpt('captcha_on')) {
                            $form_tpl = preg_replace(
                                '/<!--\s*CAPTCHA\s*-->.*?<!--\s*(?:END\s+|\/)?CAPTCHA\s*-->/s',
                                '',
                                $form_tpl
                            );
                        }

                        // ── Populate token values ─────────────────────────────
                        $fanuname  = htmlspecialchars((string) ($_COOKIE['fanuname']  ?? ''), ENT_QUOTES, 'UTF-8');
                        $fanuemail = htmlspecialchars((string) ($_COOKIE['fanuemail'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $fanuurl   = htmlspecialchars((string) ($_COOKIE['fanuurl']   ?? ''), ENT_QUOTES, 'UTF-8');

                        $install_url = rtrim((string) $this->getOpt('install_url'), '/');
                        $process_url = htmlspecialchars($install_url . '/process.php', ENT_QUOTES, 'UTF-8');
                        $returnto    = htmlspecialchars($this->getCleanSelf() . '?id=' . $post->getID(), ENT_QUOTES, 'UTF-8');
                        $captcha_url = htmlspecialchars($install_url . '/captcha.php', ENT_QUOTES, 'UTF-8');
                        $entry_id    = (string) $post->getID();

                        $form_tpl = str_replace('{{fanuname}}', $fanuname, $form_tpl);
                        $form_tpl = str_replace('{{fanuemail}}', $fanuemail, $form_tpl);
                        $form_tpl = str_replace('{{fanuurl}}', $fanuurl, $form_tpl);
                        $form_tpl = str_replace('{{process_url}}', $process_url, $form_tpl);
                        $form_tpl = str_replace('{{entry_id}}', $entry_id, $form_tpl);
                        $form_tpl = str_replace('{{returnto}}', $returnto, $form_tpl);
                        $form_tpl = str_replace('{{captcha_image}}', $captcha_url, $form_tpl);

                        // ── Ensure the template is wrapped in a <form> ────────
                        // The original FanUpdate comment_form_template option
                        // stores only the inner content — no <form> tag. Wrap it
                        // so submitCmtInit() can find id="comments-form".
                        $has_form_tag = (bool) preg_match('/<form\b/i', $form_tpl);

                        if (!$has_form_tag) {
                            $form_tpl =
                                '<form action="' . $process_url . '" method="post" id="comments-form">'
                                . "\n" . $form_tpl . "\n"
                                . '</form>';
                        }

                        // ── Inject missing hidden fields ──────────────────────
                        // The stored template may not contain {{entry_id}} or
                        // {{returnto}} tokens. Without entry, process.php treats
                        // the submission as spam and discards it.
                        $hidden_fields = '';
                        if (!preg_match('/name=["\']entry["\']/', $form_tpl)) {
                            $hidden_fields .=
                                '<input type="hidden" name="entry" value="' . htmlspecialchars($entry_id, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
                        }
                        if (!preg_match('/name=["\']returnto["\']/', $form_tpl)) {
                            $hidden_fields .=
                                '<input type="hidden" name="returnto" value="' . $returnto . '" />' . "\n";
                        }

                        // ── Spam guard: honeypot + time gate ──────────────────
                        // Honeypot: a visually hidden text field that real users
                        // never fill in. Bots fill in all fields automatically.
                        // Name chosen to look like a generic form field to bots.
                        // CSS hides it from humans; aria-hidden removes it from
                        // screen readers so it causes no accessibility issues.
                        // Time gate: a hidden Unix timestamp set when the page
                        // renders. process.php rejects submissions that arrive
                        // faster than FU_MIN_COMMENT_SECONDS (3 seconds).
                        // Both fields are always appended so they work with any
                        // comment form template without requiring template edits.
                        $spam_guard_fields =
                            '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
                            . '<label for="fu_hp_fax">Leave this empty</label>'
                            . '<input type="text" id="fu_hp_fax" name="fax" value="" tabindex="-1" autocomplete="off" />'
                            . '</div>' . "\n"
                            . '<input type="hidden" name="fu_form_time" value="' . time() . '" />' . "\n";

                        $hidden_fields .= $spam_guard_fields;

                        if ($hidden_fields !== '') {
                            // Insert before </form> if present, otherwise append.
                            if (stripos($form_tpl, '</form>') !== false) {
                                $form_tpl = preg_replace(
                                    '/<\/form>/i',
                                    $hidden_fields . '</form>',
                                    $form_tpl,
                                    1
                                );
                            } else {
                                $form_tpl .= "\n" . $hidden_fields;
                            }
                        }

                        echo '<div id="newComment"></div>' . "\n";
                        echo $form_tpl . "\n";

                        // Scripts are injected mid-page so window 'load' has
                        // already fired — submitCmtInit() is called directly
                        // after both files are parsed instead of relying on
                        // the load listener in fanupdate-comment-submit.js.
                        $js_url = htmlspecialchars(
                            rtrim((string) $this->getOpt('install_url'), '/') . '/assets/js',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        $fu_url = htmlspecialchars(
                            rtrim((string) $this->getOpt('install_url'), '/'),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        echo '<script>const fu_url = \'' . $fu_url . '\';</script>' . "\n";
                        echo '<script src="' . $js_url . '/fanupdate.js"></script>' . "\n";
                        echo '<script src="' . $js_url . '/fanupdate-comment-submit.js"></script>' . "\n";
                        echo '<script>submitCmtInit();</script>' . "\n";

                    } else {
                        // comments_on = 0 for this entry
                        echo '<p class="comments-closed">Comments are currently closed.</p>' . "\n";
                    }

                    echo '</div><!-- #comments -->' . "\n";
                }
                // ── end single-post comments ─────────────────────────────────
            }

            if (!$single_page) {
                $this->db->PrintPaginate();
            }
        } else {
            echo '<p>No entries found.</p>' . "\n";
        }

        $this->db->FreeResult();

        // ── Lightbox init ─────────────────────────────────────────────────────
        // Emit fanupdate.js (once per page) and call fuLightboxInit() so every
        // <a class="fu-lightbox"> in the post output gets a click handler.
        //
        // The static flag $_lb_script_emitted prevents double-emitting the
        // <script src> on pages that embed both show-blog.php and show-cat.php,
        // or that call printBlog() more than once (e.g. featured + archive).
        //
        // fuLightboxInit() itself is idempotent (double-init guard on each link)
        // so calling it multiple times is safe — it is always emitted so newly
        // paginated/AJAX-injected posts are wired on the next call too.
        //
        // This block is skipped on single-post pages that already emitted
        // fanupdate.js above via the comment-form script injection; in that
        // case fuLightboxInit() is still called (second inline <script>) because
        // the comment-form injection emits the script before the posts are
        // rendered, and fuLightboxInit() must run after.
        static $_lb_script_emitted = false;

        $js_base = htmlspecialchars(
            rtrim((string) $this->getOpt('install_url'), '/') . '/assets/js',
            ENT_QUOTES,
            'UTF-8'
        );

        if (!$_lb_script_emitted) {
            // On single-post pages fanupdate.js is already emitted by the
            // comment-form block above, so we only emit it here on list pages.
            if (!$single_page) {
                echo '<script src="' . $js_base . '/fanupdate.js"></script>' . "\n";
            }
            $_lb_script_emitted = true;
        }

        echo '<script>fuLightboxInit();</script>' . "\n";
        // ── end lightbox init ─────────────────────────────────────────────────
    }

    public function printBlogFooter(?int $cat_id = null): void
    {
        // Hook for theme customisation; no-op in the default implementation.
    }

}
