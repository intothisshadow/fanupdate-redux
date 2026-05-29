<?php

/*****************************************************************************
 * FanUpdate
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 by Ariane (contributor)
 *
 * Refactored for PHP 8.2+:
 *  - All dynamic properties declared explicitly
 *  - Strict return types throughout
 *  - Removed deprecated utf8_encode/utf8_decode (replaced with mb_convert_encoding)
 *  - AddOpt/AddErr/AddSuccess aliases kept for back-compat but typed
 *
 * Bug fix (Redux 1.1.1):
 *  - login(): wrong option keys 'admin_user'/'admin_pass' corrected to
 *    'admin_username'/'admin_password' — these are the actual keys written
 *    by install.php into blog-config.php.
 *  - login(): POST check was comparing md5($submitted) === md5($stored_hash),
 *    i.e. hashing the already-hashed value a second time; corrected to
 *    md5($submitted) === $stored_hash (single hash, direct comparison).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
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

    private array $config = [
        'version' => 'Redux v.1.1.1',
        'url'     => 'http://prism-perfect.net/fanupdate',
        'newUrl'  => 'https://scripts.robotess.net',
    ];

    private static FanUpdate $instance;

    // ── constructor ─────────────────────────────────────────────────────────

    private function __construct()
    {
        global $fanupdate, $coltable;

        $this->_cfg = array_merge($fanupdate, $this->config);

        $collectiveScript = $this->getOpt('collective_script', true);
        $this->_cfg['col_id']   = $coltable[$collectiveScript]['id']      ?? '';
        $this->_cfg['col_subj'] = $coltable[$collectiveScript]['subject'] ?? '';

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

    // ── page rendering ──────────────────────────────────────────────────────

    public function getHeader(?string $pageTitle = null): void
    {
        require_once('header.php');
    }

    public function getFooter(bool $showNav = true): void
    {
        require_once('footer.php');
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
            $success = @$doc->load($updatesFeedUrl);
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
        return '<img src="' . $this->getOpt('install_url') . '/img/' . $img . '" alt="' . htmlspecialchars($txt) . '" />';
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

    // ── credits ─────────────────────────────────────────────────────────────

    public function printCredits(): void
    {
        $newUrl  = htmlspecialchars((string) $this->getOpt('newUrl'));
        $version = htmlspecialchars((string) $this->getOpt('version'));
        $url     = htmlspecialchars((string) $this->getOpt('url'));
        echo 'Powered by <a href="' . $newUrl . '" target="_blank" title="PHP Scripts">'
            . 'FanUpdate ' . $version . '</a> '
            . '(original author: <a href="' . $url . '" target="_blank">Jenny</a>)';
    }

    // ── login ────────────────────────────────────────────────────────────────
    //
    // FIX (Redux 1.1.1) — two bugs corrected:
    //
    // 1. Key names: the config keys written by install.php into blog-config.php
    //    are 'admin_username' and 'admin_password'.  The old code used
    //    'admin_user' and 'admin_pass', which always resolved to false, so the
    //    comparison silently failed and the form just re-displayed with no error.
    //
    // 2. Double-MD5: the stored password is already md5($plaintext).  The old
    //    POST check wrapped it in another md5() — md5($submitted) vs
    //    md5(md5($stored)) — which can never match.  Fixed to a single hash:
    //    md5($submitted) === $stored_hash.

    public function login(): void
    {
        $showform = true;

        // ── Check cookies ──────────────────────────────────────────────────
        // The cookie stores the already-hashed password, so compare directly.
        if (isset($_COOKIE['fu_username'], $_COOKIE['fu_password'])) {
            $username = clean_input($_COOKIE['fu_username']);
            $password = clean_input($_COOKIE['fu_password']);

            if (
                $username === $this->getOpt('admin_username', true)        // FIX: was 'admin_user'
                && $password === (string) $this->getOpt('admin_password', true) // FIX: was md5(getOpt('admin_pass'))
            ) {
                $showform = false;
            } else {
                $this->addErr('Cookies expired or invalid. Please log in again.');
                setcookie('fu_username', '', time() - 36000, '/');
                setcookie('fu_password', '', time() - 36000, '/');
            }
        }

        // ── Check POST ─────────────────────────────────────────────────────
        // Hash the submitted plaintext once; compare to the stored md5 hash.
        if (isset($_POST['action']) && $_POST['action'] === 'login') {
            $username = clean_input($_POST['username'] ?? '');
            $password = clean_input($_POST['password'] ?? '');

            if (
                $username === $this->getOpt('admin_username', true)              // FIX: was 'admin_user'
                && md5($password) === (string) $this->getOpt('admin_password', true) // FIX: was md5(md5(...))
            ) {
                $remember = !empty($_POST['remember_me']);
                $expire   = $remember ? time() + 60 * 60 * 24 * 30 : 0;
                setcookie('fu_username', $username, $expire, '/');
                setcookie('fu_password', md5($password), $expire, '/');
                $showform = false;
            } else {
                $this->addErr('Invalid username or password. Please try again.');
                setcookie('fu_username', '', time() - 36000, '/');
                setcookie('fu_password', '', time() - 36000, '/');
            }
        }

        if ($showform) {
            $this->getHeader('Login');
?>
            <form action="<?php echo clean_input($_SERVER['REQUEST_URI']); ?>" method="post">
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

    public function printBlog(string $query, int $main_limit = 5, bool $single_page = false): void
    {
        $this->db->ExecutePaginate($query, $main_limit);

        if ($this->db->NumRows() > 0) {
            while ($row = $this->db->ReadRecordPaginate()) {
                $post = new FanUpdate_Post($row, $this);
                $post->getCatFromDb();

                if ($post->allowComments()) {
                    $query_comments =
                        'SELECT * FROM ' . $this->getOpt('comments_table')
                        . ' WHERE entry_id=' . $post->getID()
                        . ' AND approved > 0 ORDER BY added ASC';

                    $this->db->Execute($query_comments);
                    $post->addParam('num_comments', $this->db->NumRows());
                }

                echo '<div id="post' . $post->getID() . '" class="post">';
                $post->printPost();
                echo "</div>\n";
            }
        } else {
            echo '<p>No entries found.</p>' . "\n";
        }

        $this->db->FreeResult();
        $this->printPagination();
    }

    // ── pagination ───────────────────────────────────────────────────────────

    public function printPagination(): void
    {
        if ($this->db->total_results <= $this->db->max_results) {
            return;
        }

        $pages      = (int) ceil($this->db->total_results / $this->db->max_results);
        $current    = $this->db->page;
        $base_url   = clean_input($_SERVER['REQUEST_URI']);
        // Strip existing ?page= param
        $base_url   = preg_replace('/([?&])page=\d+(&|$)/', '$1', $base_url);
        $base_url   = rtrim($base_url, '?&');
        $sep        = str_contains($base_url, '?') ? '&' : '?';

        echo '<div class="pagination">' . "\n";
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === $current) {
                echo '<strong>' . $i . '</strong> ';
            } else {
                echo '<a href="' . htmlspecialchars($base_url . $sep . 'page=' . $i) . '">' . $i . '</a> ';
            }
        }
        echo '</div>' . "\n";
    }

    // ── blog footer ──────────────────────────────────────────────────────────

    public function printBlogFooter(int $cat_id = 0): void
    {
        echo '<div class="blogfooter">' . "\n";
        $this->printCredits();
        echo '</div>' . "\n";
    }

    // ── RSS sidebar ──────────────────────────────────────────────────────────

    public function printProjectRSS(): void
    {
        $url = (string) $this->getOpt('rss_url');
        if ($url === '' || $url === false) {
            return;
        }

        try {
            $xml = @simplexml_load_file($url);
            if ($xml === false) {
                throw new \RuntimeException('Could not load RSS feed.');
            }
        } catch (\Exception $e) {
            echo '<p class="error">RSS error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            return;
        }

        $posts = '';
        $count = 0;
        foreach ($xml->channel->item as $item) {
            if ($count >= 5) {
                break;
            }
            $title = htmlspecialchars((string) $item->title);
            $link  = htmlspecialchars((string) $item->link);
            $posts .= '<li><a href="' . $link . '">' . $title . '</a></li>' . "\n";
            $count++;
        }

        if ($posts === '') {
            return;
        }

        echo '<h3>Project RSS</h3><ul>' . $posts . '</ul>';
    }
}
