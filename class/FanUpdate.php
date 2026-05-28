<?php
/*****************************************************************************
 * FanUpdate — PHP 8.2+ refactor
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+ compatibility:
 *   - declare(strict_types=1) added
 *   - All previously undeclared ("dynamic") public properties now explicitly typed
 *   - Strict return types on all methods
 *   - utf8_encode() / utf8_decode() removed (were already absent; guarded via mb_*)
 *   - md5() password check left as-is (existing behaviour preserved;
 *     note: for new installs, password_hash/password_verify is recommended)
 *   - Removed PHP 4/5-era func_num_args() variadic patterns where possible
 ******************************************************************************/

declare(strict_types=1);

// DON'T CHANGE THESE!
$fanupdate         = $fanupdate ?? [];
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

// requires SqlConnection class, clean_input()

class FanUpdate
{
    // All public properties declared explicitly — PHP 8.2 forbids dynamic props
    public array  $_cfg       = [];
    public string $_clean_self = '';
    public SqlConnection $db;
    public array  $errors     = [];
    public array  $success    = [];
    public array  $smilies    = [];
    public array  $smiley_imgs = [];
    public array  $observers  = [];

    private array $config = [
        'version' => '[Robotess Fork] 1.0',
        'url'     => 'http://prism-perfect.net/fanupdate',
        'newUrl'  => 'https://scripts.robotess.net',
    ];

    private static ?self $instance = null;

    private function __construct()
    {
        global $fanupdate, $coltable;

        $this->_cfg = array_merge($fanupdate, $this->config);
        $this->_cfg['col_id']   = $coltable[$this->getOpt('collective_script', true)]['id'];
        $this->_cfg['col_subj'] = $coltable[$this->getOpt('collective_script', true)]['subject'];

        $this->db = SqlConnection::instance(
            (string)$this->getOpt('dbhost'),
            (string)$this->getOpt('dbuser'),
            (string)$this->getOpt('dbpass'),
            (string)$this->getOpt('dbname')
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

    public static function instance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getCleanSelf(): string
    {
        return $this->_clean_self;
    }

    public function addOptFromDb(): bool
    {
        $query = 'SELECT * FROM ' . $this->getOpt('options_table');

        if ($this->db->Execute($query)) {
            while ($row = $this->db->ReadRecord()) {
                if ($row['optvalue'] !== '') {
                    $this->addOpt($row['optkey'], $row['optvalue']);
                }
            }
            $this->db->FreeResult();

            $query = 'SELECT * FROM ' . $this->getOpt('smilies_table');
            $this->db->Execute($query);
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

    /**
     * Retrieve a config option.
     * Returns false if not set, or if value represents a boolean-false.
     * Pass $returnLiteral = true to get the raw value.
     */
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

    public function getHeader(?string $pageTitle = null): void
    {
        require_once('header.php');
    }

    public function getFooter(bool $showNav = true): void
    {
        require_once('footer.php');
    }

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

    public function reportErrors(string $err = ''): void
    {
        if ($err !== '') {
            $this->addErr($err);
        }

        if (!empty($this->errors)) {
            if (count($this->errors) === 1 && empty($this->success)) {
                echo '<p class="error">' . array_pop($this->errors) . "</p>\n";
            } else {
                echo '<ul class="error">' . "\n";
                foreach ($this->errors as $msg) {
                    $msg = trim((string)$msg);
                    if ($msg !== '') {
                        echo '<li>' . $msg . "</li>\n";
                    }
                }
                echo "</ul>\n";
            }
            $this->errors = [];
        }
    }

    public function reportSuccess(string $success = ''): void
    {
        if ($success !== '') {
            $this->addSuccess($success);
        }

        if (!empty($this->success)) {
            if (count($this->success) === 1 && empty($this->errors)) {
                echo '<p class="success">' . array_pop($this->success) . "</p>\n";
            } else {
                echo '<ul class="success">' . "\n";
                foreach ($this->success as $msg) {
                    $msg = trim((string)$msg);
                    if ($msg !== '') {
                        echo '<li>' . $msg . "</li>\n";
                    }
                }
                echo "</ul>\n";
            }
            $this->success = [];
        }
    }

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
        return empty($this->errors);
    }

    // ------------------------------------------------------------------ LOGIN

    public function login(): void
    {
        session_start();
        header('Cache-control: private');

        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            $_SESSION = [];
            setcookie('fu_username', '', time() - 36000, '/');
            setcookie('fu_password', '', time() - 36000, '/');
            session_destroy();
            header('Location: ' . $this->getOpt('install_url') . '/index.php');
            exit;
        }

        $showform  = true;
        $test_user = '';
        $test_pass = '';

        if (isset($_POST['action']) && $_POST['action'] === 'login') {
            $test_user = (string)$_POST['username'];
            $test_pass = md5((string)$_POST['password']);
        } elseif (isset($_SESSION['username'], $_SESSION['password'])) {
            $test_user = (string)$_SESSION['username'];
            $test_pass = (string)$_SESSION['password'];
        } elseif (isset($_COOKIE['fu_username'], $_COOKIE['fu_password'])) {
            $test_user = (string)$_COOKIE['fu_username'];
            $test_pass = (string)$_COOKIE['fu_password'];
        }

        if ($test_user !== '') {
            if (
                $test_user === $this->getOpt('admin_username') &&
                $test_pass === $this->getOpt('admin_password')
            ) {
                $showform = false;
                $_SESSION['username'] = $test_user;
                $_SESSION['password'] = $test_pass;

                if (isset($_POST['remember_me'])) {
                    $cookie_life = time() + 31536000;
                    setcookie('fu_username', $test_user, $cookie_life, '/');
                    setcookie('fu_password', $test_pass, $cookie_life, '/');
                }
            } else {
                $showform = true;
                $this->addErr('Invalid login! Please try again.');
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
                    <input type="text" id="username" name="username" size="20" tabindex="1"/></p>
                <p><label for="password">Password:</label>
                    <input type="password" id="password" name="password" size="20" tabindex="2"/></p>
                <p id="loginButton">
                    <input type="submit" name="action" value="login" class="submit" tabindex="4"/>
                    <span>
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" tabindex="3"/>
                        <label for="remember_me" class="checkbox">Remember?</label>
                    </span>
                </p>
            </form>
            <script type="text/javascript">
                document.getElementById('username').focus();
            </script>
            <?php
            $this->getFooter(false);
            exit;
        }
    }

    // ---------------------------------------------------------------- CREDITS

    public function printCredits(): void
    {
        ?>
        Powered by <a href="<?= htmlspecialchars((string)$this->getOpt('newUrl'), ENT_QUOTES, 'UTF-8') ?>"
                      target="_blank"
                      title="PHP Scripts: Enthusiast, Siteskin, Codesort - ported to PHP 8">FanUpdate
            <?= htmlspecialchars((string)$this->getOpt('version'), ENT_QUOTES, 'UTF-8') ?></a>
        (original author:
        <a href="<?= htmlspecialchars((string)$this->getOpt('url'), ENT_QUOTES, 'UTF-8') ?>"
           target="_blank">Jenny</a>)
        <?php
    }

    // ---------------------------------------------------------- BLOG PRINTING

    public function printBlog(string $query, int $main_limit = 5, bool $single_page = false): void
    {
        $this->db->ExecutePaginate($query, $main_limit);

        if ($this->db->NumRows() > 0) {
            while ($row = $this->db->ReadRecordPaginate()) {
                $post = new FanUpdate_Post($row, $this);
                $post->getCatFromDb();

                if ($post->allowComments()) {
                    $query_comments = 'SELECT * FROM ' . $this->getOpt('comments_table') . '
                      WHERE entry_id=' . $post->getID() . ' AND approved > 0
                      ORDER BY added ASC';

                    $this->db->Execute($query_comments);
                    $num_comments = $this->db->NumRows();
                    $post->addParam('num_comments', $num_comments);
                }

                echo '<div id="post' . $post->getID() . '" class="post">' . "\n";
                $single_page ? $post->printPost() : $post->printPost(true);
                echo "</div><!-- END .post -->\n";

                if ($single_page && $post->allowComments()) {
                    echo '<div id="comments">';
                    $this->printComments($post->getID());
                    $this->printCommentForm($post->getID());
                    echo '</div><!-- END #comments -->';
                }
            }
        } else {
            echo '<p>No entries found.</p>' . "\n";
        }
    }

    public function printComments(int $entry_id): void
    {
        $query = 'SELECT * FROM ' . $this->getOpt('comments_table') . '
          WHERE entry_id=' . $entry_id . ' AND approved > 0
          ORDER BY added ASC';

        $this->db->Execute($query);

        while ($row = $this->db->ReadRecord()) {
            $comment = new FanUpdate_Comment($row, $this);
            $comment->printComment();
        }
        $this->db->FreeResult();
    }

    public function printCommentForm(?int $entry_id = null): void
    {
        $text = $this->getOpt('comment_form_template');

        $fanuname  = $_COOKIE['fanuname']  ?? '';
        $fanuemail = $_COOKIE['fanuemail'] ?? '';
        $fanuurl   = $_COOKIE['fanuurl']   ?? '';

        $text = str_replace(
            ['{{fanuname}}', '{{fanuemail}}', '{{fanuurl}}'],
            [htmlspecialchars($fanuname, ENT_QUOTES, 'UTF-8'),
             htmlspecialchars($fanuemail, ENT_QUOTES, 'UTF-8'),
             htmlspecialchars($fanuurl, ENT_QUOTES, 'UTF-8')],
            $text
        );

        if ($this->getOpt('captcha_on')) {
            $text = str_replace('{{captcha_image}}', $this->getOpt('install_url') . '/captcha.php', $text);
        } else {
            $text = preg_replace(
                '/<!(?:--[\s]*?CAPTCHA[\s]*?--\s*)?>[\s\S]*?<!(?:--[\s]*?END[\s]*?CAPTCHA[\s]*?--\s*)?>\s*/',
                '',
                $text
            );
        }

        if (!$this->getOpt('comment_moderation')) {
            $text = preg_replace(
                '/<!(?:--[\s]*?MODERATION[\s]*?--\s*)?>[\s\S]*?<!(?:--[\s]*?END[\s]*?MODERATION[\s]*?--\s*)?>\s*/',
                '',
                $text
            );
        }

        echo $text;
    }

    public function printBlogFooter(?int $listingid = null): void
    {
        $rss_url = $this->getOpt('install_url') . '/rss.php' . ($listingid !== null ? '?c=' . $listingid : '');

        $text = str_replace('{{main_url}}', $this->getCleanSelf(), (string)$this->getOpt('footer_template'));
        $text = str_replace(
            ['{{archive_url}}', '{{rss_url}}'],
            [$this->getCleanSelf() . '?view=archive', $rss_url],
            $text
        );
        $text = str_replace(
            ['{{fanupdate_url}}', '{{fanupdate_version}}', '{{fanupdate_original_url}}'],
            [(string)$this->getOpt('newUrl'), (string)$this->getOpt('version'), (string)$this->getOpt('url')],
            $text
        );

        echo $text;
    }

    // ----------------------------------------------------------- SMILIES

    public function replaceSmilies(string $text): string
    {
        if (empty($this->smilies)) {
            return $text;
        }

        foreach ($this->smilies as $smiley => $image) {
            $text = str_replace(
                $smiley,
                $this->makeSmiley($smiley, $image),
                $text
            );
        }

        return $text;
    }

    public function makeSmiley(string $smiley, string $image): string
    {
        return '<img src="' . $this->getOpt('install_url') . '/img/' . $image
            . '" alt="' . htmlspecialchars($smiley, ENT_QUOTES, 'UTF-8')
            . '" class="smiley" />';
    }
}
