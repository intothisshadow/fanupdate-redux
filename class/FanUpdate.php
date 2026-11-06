<?php

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 by Ariane (contributor)
 *
 * Core application class: singleton holding options/config, the DB
 * connection, message queues and observers, CSRF tokens, admin cookie
 * authentication, smilies, and the public blog/comment rendering.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

// DON'T CHANGE THESE!
$fanupdate           = $fanupdate ?? [];
// $coltable is optional: collective settings live in the database now, and
// it is only a legacy fallback for configs that predate that move.
$coltable            = $coltable ?? [];
// Event log table: defaults so upgraded installs need no blog-config.php edit.
$fanupdate['log_table'] = $fanupdate['log_table'] ?? 'blog_log';
$fanupdate['tables'] = [
    $fanupdate['blog_table'],
    $fanupdate['collective_table'],
    $fanupdate['catjoin_table'],
    $fanupdate['comments_table'],
    $fanupdate['options_table'],
    $fanupdate['blacklist_table'],
    $fanupdate['catoptions_table'],
    $fanupdate['smilies_table'],
    // '?? ''' guards configs that predate these options.
    $fanupdate['standalone_category_table'] ?? '',
    $fanupdate['media_table'] ?? '',
    $fanupdate['log_table'],
];

class FanUpdate
{
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
        'url'     => FU_URL_REDUX,
        'newUrl'  => FU_URL_ROBOTESS,
    ];

    private static FanUpdate $instance;

    // ── constructor ─────────────────────────────────────────────────────────

    private function __construct()
    {
        global $fanupdate;

        $this->_cfg = array_merge($fanupdate, $this->config);

        // collective_script/col_id/col_subj are resolved later, in
        // resolveCollectiveScriptConfig(), once DB options are loaded.

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
            '<div class="fu-entry">' . "\n"
            . '<h2><a href="{{url}}" title="permanent link to this post">{{title}}</a></h2>' . "\n"
            . '<p class="catfile">Posted {{date}}. Filed under {{category}}. {{comment_link}}</p>' . "\n"
            . '{{body}}' . "\n"
            . '</div>';

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

    // ── event log ───────────────────────────────────────────────────

    /**
     * Records an audit event. $actor defaults to the logged-in admin's
     * username (empty for unauthenticated events). Never throws.
     */
    public function logEvent(string $eventType, string $message, ?string $actor = null): void
    {
        $actor ??= $this->isLoggedIn() ? (string) $this->getOpt('admin_username', true) : '';
        $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        $log = $this->logService();
        $log->log($eventType, $actor, $ip, $message);

        // Opportunistic retention pruning: no cron dependency on shared hosts.
        if (random_int(1, 50) === 1) {
            $log->prune($this->logRetentionDays());
        }
    }

    public function logService(): LogService
    {
        return new LogService(
            $this->db,
            (string) $this->getOpt('log_table'),
            (bool) $this->getOpt('log_anonymize_ips'),
            $this->timezone()
        );
    }

    /** The configured IANA timezone, or UTC when unset/invalid. */
    public function timezone(): string
    {
        return fu_resolve_timezone($this->getOpt(FU_TIMEZONE_OPTION_KEY, true));
    }

    /** 0 means keep forever; a missing/invalid option falls back to the default. */
    public function logRetentionDays(): int
    {
        $raw = $this->getOpt('log_retention_days', true);

        return is_numeric($raw) ? max(0, (int) $raw) : LogService::DEFAULT_RETENTION_DAYS;
    }

    // ── CSRF protection ─────────────────────────────────────────────────────

    /**
     * Cookie path scope for the CSRF session and auth cookies, derived from
     * install_url. Installs sharing a domain in different subdirectories
     * would otherwise overwrite each other's same-named cookies and appear
     * to log each other out. Falls back to '/' before install_url exists.
     */
    private function cookiePath(): string
    {
        $installUrl = (string) $this->getOpt('install_url');
        if ($installUrl === '') {
            return '/';
        }

        $path = parse_url($installUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return rtrim($path, '/') . '/';
    }

    /**
     * Start (or resume) the PHP session used solely for the CSRF token;
     * login state is tracked by the separate HMAC auth cookies.
     */
    private function ensureSession(): void
    {
        $status = session_status();

        if ($status === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($status === PHP_SESSION_DISABLED) {
            // No token can be stored; verifyCsrfToken() fails closed.
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $this->cookiePath(),
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name('fu_session');
        session_start();
    }

    /** Returns the session's CSRF token, generating it on first use. */
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

    /** Timing-safe token comparison; false when either side is missing. */
    public function verifyCsrfToken(?string $token): bool
    {
        $this->ensureSession();

        $stored = $_SESSION[self::CSRF_SESSION_KEY] ?? '';

        if (!is_string($stored) || $stored === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    /** Hidden <input> carrying the CSRF token; include in every admin POST form. */
    public function csrfField(): string
    {
        $token = htmlspecialchars($this->generateCsrfToken(), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="' . self::CSRF_FIELD_NAME . '" value="' . $token . '" />';
    }

    /**
     * Validates the CSRF token on POST requests; call before processing any
     * admin action. Non-POST requests pass.
     *
     * On failure, AJAX requests (mode=ajax) get a 403 and exit: their JS
     * callers only check response.ok, so a 200 error page would read as
     * success. Regular forms get a queued error and a false return.
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

        $this->resolveCollectiveScriptConfig();

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
     * Resolves collective_script/col_id/col_subj from the options table,
     * falling back (read-only) to blog-config.php's legacy values on
     * installs that haven't run the migration yet.
     */
    private function resolveCollectiveScriptConfig(): void
    {
        global $fanupdate, $coltable;

        $storedScript = $this->getOpt('collective_script', true);

        if ($storedScript !== false && $storedScript !== '') {
            $this->_cfg['collective_script'] = (string) $storedScript;

            $colId   = $this->getOpt('col_id', true);
            $colSubj = $this->getOpt('col_subj', true);

            $this->_cfg['col_id']   = ($colId !== false)   ? (string) $colId   : '';
            $this->_cfg['col_subj'] = ($colSubj !== false) ? (string) $colSubj : '';

            return;
        }

        $legacyShorthand = (string) ($fanupdate['collective_script'] ?? 'n');
        if ($legacyShorthand === '') {
            $legacyShorthand = 'n';
        }

        $this->_cfg['collective_script'] = (new CollectiveScriptNormalizer())->normalize($legacyShorthand);
        $this->_cfg['col_id']            = (string) ($coltable[$legacyShorthand]['id']      ?? '');
        $this->_cfg['col_subj']          = (string) ($coltable[$legacyShorthand]['subject'] ?? '');
    }

    /**
     * Whether a collective/fanlisting script is configured, as opposed to
     * standalone use. Needed because getOpt()'s y/n mapping would not treat
     * the stored value "none" as falsy.
     */
    public function hasCollectiveScript(): bool
    {
        $value = (string) $this->getOpt('collective_script', true);

        return $value !== '' && $value !== 'none';
    }

    /** Whether collective categories are listed too; true when the option is missing. */
    public function showCollectiveCategories(): bool
    {
        $value = $this->getOpt('show_collective_categories', true);

        return !in_array(strtolower((string) $value), ['n', 'no', '0'], true);
    }

    /** Re-issues the auth token after a username/password change so the admin stays logged in. */
    public function reissueToken(string $username): void
    {
        $storedHash = (string) $this->getOpt('admin_password', true);
        if ($storedHash === '') {
            return;
        }

        $token  = hash_hmac('sha256', $username, $storedHash);
        // Keep a session-length cookie as-is; otherwise persist 30 days.
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

    /** Prints a <select> of every image in assets/images/smilies/. */
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

    // ── credits ─────────────────────────────────────────────────────────────

    /** Renders the "Powered by FanUpdate" line; the credits link is absolute so URL rewriting can't break it. */
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

    /** True when the request carries valid admin cookies. Unlike login(), never redirects or renders. */
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

    /** True while InstallManager's {install_path}/.fu-maintenance flag file exists (update in progress). */
    public function isMaintenanceModeEnabled(): bool
    {
        $installPath = (string) ($this->getOpt('install_path') ?: '');
        if ($installPath === '') {
            $installPath = dirname(__DIR__);
        }

        return is_file(rtrim($installPath, '/') . '/.fu-maintenance');
    }

    // ── asset cache-busting ─────────────────────────────────────────────────

    /**
     * Appends `?v=<mtime>` so edited JS/CSS bypasses the long .htaccess
     * cache lifetime. $relativePath is relative to install_path; the URL is
     * returned unchanged if the file can't be stat'd.
     */
    private function assetUrl(string $baseUrl, string $relativePath): string
    {
        $installPath = (string) ($this->getOpt('install_path') ?: '');
        if ($installPath === '') {
            $installPath = dirname(__DIR__);
        }

        $mtime = @filemtime(rtrim($installPath, '/') . '/' . ltrim($relativePath, '/'));

        return $mtime !== false
            ? $baseUrl . '?v=' . $mtime
            : $baseUrl;
    }

    // ── cookie helper ────────────────────────────────────────────────────────

    /** Issues or expires an auth cookie (secure, httponly, SameSite=Strict, install-scoped path). */
    private function setAuthCookie(string $name, string $value, int $expire): void
    {
        setcookie($name, $value, [
            'expires'  => $expire,
            'path'     => $this->cookiePath(),
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
            if ($this->isLoggedIn()) {
                $this->logEvent(LogService::LOGOUT, 'Logged out.');
            }
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
                // Error already queued; fall through to the login form.
            } elseif ($rateLimiter->isLockedOut()) {
                // Credentials are not even checked while locked out.
                $this->addErr(
                    'Too many failed login attempts. '
                        . 'Please try again in ' . $rateLimiter->humanTimeRemaining() . '.'
                );
            } else {

                $username = clean_input($_POST['username'] ?? '');
                $password = clean_input($_POST['password'] ?? '');

                $usernameOk = ($username === (string) $this->getOpt('admin_username', true));
                $passwordOk = false;

                if ($usernameOk) {
                    $passwordOk = password_verify($password, $storedHash);
                }

                if ($usernameOk && $passwordOk) {
                    // ── success ───────────────────────────────────────────────
                    $rateLimiter->recordSuccess();
                    $this->logEvent(LogService::LOGIN_SUCCESS, 'Admin login succeeded.', $username);

                    $remember = !empty($_POST['remember_me']);
                    $expire   = $remember ? time() + 60 * 60 * 24 * 30 : 0;

                    $token = hash_hmac('sha256', $username, $storedHash);

                    $this->setAuthCookie('fu_username', $username, $expire);
                    $this->setAuthCookie('fu_token', $token, $expire);

                    $showform = false;
                } else {
                    // ── failure ───────────────────────────────────────────────
                    $rateLimiter->recordFailure();

                    // The attempted username is deliberately not logged: a
                    // mistyped password is often entered in that field.
                    $this->logEvent(LogService::LOGIN_FAILURE, 'Admin login failed.', '');
                    if ($rateLimiter->isLockedOut()) {
                        $this->logEvent(
                            LogService::LOGIN_LOCKOUT,
                            'Login locked out for ' . $rateLimiter->humanTimeRemaining() . '.',
                            ''
                        );
                    }

                    // Same message for either field so it doesn't leak which was wrong.
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
                    <button type="submit" name="action" value="login" class="submit" tabindex="4">Login</button>
                    <span>
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" tabindex="3" />
                        <label for="remember_me" class="checkbox">Remember?</label>
                    </span>
                </p>
                <p class="hint small"><a href="../reset-password.php" tabindex="5">Forgot your password?</a></p>
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
                            // Keep name="remember_me" in sync with fanupdate-comment-submit.js.
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

                        // Conditional blocks accept <!-- END X --> or <!-- /X --> as the closing marker.
                        if (!$this->getOpt('comment_moderation')) {
                            $form_tpl = preg_replace(
                                '/<!--\s*MODERATION\s*-->.*?<!--\s*(?:END\s+|\/)?MODERATION\s*-->/s',
                                '',
                                $form_tpl
                            );
                        }

                        if (!$this->getOpt('captcha_on')) {
                            $form_tpl = preg_replace(
                                '/<!--\s*CAPTCHA\s*-->.*?<!--\s*(?:END\s+|\/)?CAPTCHA\s*-->/s',
                                '',
                                $form_tpl
                            );
                        }

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

                        // Legacy comment_form_template values have no <form> tag;
                        // submitCmtInit() needs id="comments-form".
                        $has_form_tag = (bool) preg_match('/<form\b/i', $form_tpl);

                        if (!$has_form_tag) {
                            $form_tpl =
                                '<form action="' . $process_url . '" method="post" id="comments-form">'
                                . "\n" . $form_tpl . "\n"
                                . '</form>';
                        }

                        // Custom templates may lack these fields; without "entry",
                        // process.php discards the submission as spam.
                        $hidden_fields = '';
                        if (!preg_match('/name=["\']entry["\']/', $form_tpl)) {
                            $hidden_fields .=
                                '<input type="hidden" name="entry" value="' . htmlspecialchars($entry_id, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
                        }
                        if (!preg_match('/name=["\']returnto["\']/', $form_tpl)) {
                            $hidden_fields .=
                                '<input type="hidden" name="returnto" value="' . $returnto . '" />' . "\n";
                        }

                        // Spam guard, appended to every template: an off-screen honeypot
                        // field plus a render timestamp that process.php checks
                        // against FU_MIN_COMMENT_SECONDS.
                        $spam_guard_fields =
                            '<div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
                            . '<label for="fu_hp_fax">Leave this empty</label>'
                            . '<input type="text" id="fu_hp_fax" name="fax" value="" tabindex="-1" autocomplete="off" />'
                            . '</div>' . "\n"
                            . '<input type="hidden" name="fu_form_time" value="' . time() . '" />' . "\n";

                        $hidden_fields .= $spam_guard_fields;

                        if ($hidden_fields !== '') {
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

                        // Injected mid-page, after 'load' may have fired, so
                        // submitCmtInit() is called directly.
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
                        echo '<script src="' . $this->assetUrl($js_url . '/fanupdate.js', 'assets/js/fanupdate.js') . '"></script>' . "\n";
                        echo '<script src="' . $js_url . '/fanupdate-comment-submit.js"></script>' . "\n";
                        echo '<script>submitCmtInit();</script>' . "\n";

                    } else {
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

        // Emit fanupdate.js once per page (the static flag covers pages that
        // call printBlog() repeatedly). fuLightboxInit() is idempotent, so it
        // is called on every render.
        static $_lb_script_emitted = false;

        $js_base = htmlspecialchars(
            rtrim((string) $this->getOpt('install_url'), '/') . '/assets/js',
            ENT_QUOTES,
            'UTF-8'
        );

        if (!$_lb_script_emitted) {
            // Single-post pages already emitted it with the comment form.
            if (!$single_page) {
                echo '<script src="' . $this->assetUrl($js_base . '/fanupdate.js', 'assets/js/fanupdate.js') . '"></script>' . "\n";
            }
            $_lb_script_emitted = true;
        }

        echo '<script>fuLightboxInit();</script>' . "\n";
    }

    /**
     * Renders the footer_template option, substituting its {{...}} variables
     * (see README's "Footer template variables"). $cat_id scopes {{rss_url}}
     * to that category's feed.
     */
    public function printBlogFooter(?int $cat_id = null): void
    {
        $template = (string) $this->getOpt('footer_template');

        if ($template === '') {
            $template =
                '<div class="archivelink">' . "\n"
                . '<form action="{{main_url}}" method="get">' . "\n"
                . '<p>' . "\n"
                . '<a class="fu-nav-link" href="{{main_url}}">main</a> &middot;' . "\n"
                . '<a class="fu-nav-link" href="{{archive_url}}">archive</a> &middot;' . "\n"
                . '<a class="rss" href="{{rss_url}}">feed</a> &middot;' . "\n"
                . '<input type="text" name="q" value="" />' . "\n"
                . '<input type="submit" value="Search" class="button" />' . "\n"
                . '</p>' . "\n"
                . '</form>' . "\n"
                . '</div><!-- END .archivelink -->' . "\n\n"
                . '<div class="credit">' . "\n"
                . '<p>Powered by <a href="{{fanupdate_url}}" target="_blank" class="ext fu-credit-link">FanUpdate {{fanupdate_version}}</a></p>' . "\n"
                . '</div><!-- END .credit -->';
        }

        $mainUrl    = (string) ($this->getOpt('blog_page') ?: '');
        $archiveUrl = $mainUrl !== '' ? $mainUrl . '?view=archive' : '';
        $rssUrl     = rtrim((string) $this->getOpt('install_url'), '/') . '/rss.php'
            . ($cat_id !== null ? '?c=' . $cat_id : '');

        $template = str_replace('{{main_url}}', htmlspecialchars($mainUrl, ENT_QUOTES, 'UTF-8'), $template);
        $template = str_replace('{{archive_url}}', htmlspecialchars($archiveUrl, ENT_QUOTES, 'UTF-8'), $template);
        $template = str_replace('{{rss_url}}', htmlspecialchars($rssUrl, ENT_QUOTES, 'UTF-8'), $template);
        $template = str_replace('{{fanupdate_url}}', htmlspecialchars(FU_URL_REDUX, ENT_QUOTES, 'UTF-8'), $template);
        $template = str_replace(
            '{{fanupdate_version}}',
            htmlspecialchars((string) $this->getOpt('version'), ENT_QUOTES, 'UTF-8'),
            $template
        );

        echo $template . "\n";
    }

}
