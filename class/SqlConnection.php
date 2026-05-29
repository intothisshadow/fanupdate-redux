<?php
/*****************************************************************************
 * SqlConnection Class for MySQL databases
 *
 * Copyright (c) Jay Pipes http://www.jpipes.com/
 * Copyright (c) 2008 by Jenny Ferenc (contributor) <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+ compatibility:
 *  - All dynamic properties declared explicitly
 *  - Removed get_magic_quotes_gpc() (removed in PHP 8.0)
 *  - Removed function_exists('mysqli_real_escape_string') fallback (always available)
 *  - Strict return types added throughout
 *  - #[\AllowDynamicProperties] NOT used — all properties declared instead
 *
 * Bug fix (Redux 1.1.2):
 *  - ExecutePaginate(): replaced broken NumRows()-after-GetFirstCell() count
 *    logic with a clean subquery approach:
 *      SELECT COUNT(*) FROM (...original query without ORDER BY...) AS _sub
 *    This correctly handles both plain queries and GROUP BY queries.
 *    The old `if ($numrows > 1)` branch was unreachable for COUNT(*) (which
 *    always returns exactly 1 row) and caused total_results to be set to 1
 *    instead of the real post count, limiting the blog to a single visible post.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class SqlConnection
{
    // ── connection credentials ──────────────────────────────────────────────
    private string $_host = '';
    private string $_user = '';
    private string $_pass = '';
    private string $_name = '';

    /** @var \mysqli|false */
    private $_Cnn = false;

    /** @var \mysqli_result[] */
    private array $_Results = [];

    /** @var \mysqli_result|bool */
    private $_Res = false;

    private int $_NumQueries = 0;

    // ── pagination state ────────────────────────────────────────────────────
    public int $max_results   = 15;
    public int $page          = 1;
    public int $total_results = 0;
    public int $cur_row       = 0;

    /** @var self */
    private static SqlConnection $instance;

    private function __construct() {}

    // ── singleton ───────────────────────────────────────────────────────────

    public static function instance(
        string $host = '',
        string $user = '',
        string $pass = '',
        string $name = ''
    ): self {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if ($host !== '') {
                self::$instance->SetConnOpt($host, $user, $pass, $name);
            }
        }
        return self::$instance;
    }

    // ── internal helpers ────────────────────────────────────────────────────

    private function SetConnOpt(
        string $host,
        string $user,
        string $pass,
        string $name
    ): void {
        $this->_host = $host;
        $this->_user = $user;
        $this->_pass = $pass;
        $this->_name = $name;
    }

    // ── connection ──────────────────────────────────────────────────────────

    /**
     * Connect to the database.
     * Pass four string args to connect to a different database on-the-fly.
     */
    public function Connect(
        string $host = '',
        string $user = '',
        string $pass = '',
        string $name = ''
    ): bool {
        if ($host !== '') {
            $this->SetConnOpt($host, $user, $pass, $name);
        }

        // Short-circuit when already connected (and no new credentials given).
        if ($this->_Cnn !== false && $host === '') {
            return true;
        }

        $cnn = @mysqli_connect($this->_host, $this->_user, $this->_pass);
        if ($cnn === false) {
            trigger_error('Could not connect to database server.', E_USER_ERROR);
            return false;
        }
        $this->_Cnn = $cnn;

        if (!@mysqli_select_db($this->_Cnn, $this->_name)) {
            trigger_error('Could not connect to specified database on server.', E_USER_ERROR);
            return false;
        }

        // Force UTF-8 at the connection level — eliminates need for encoding
        // conversion elsewhere, and replaces the deprecated utf8_encode/decode
        // pattern for data round-trips through the database.
        @mysqli_set_charset($this->_Cnn, 'utf8mb4');

        return true;
    }

    // ── query execution ─────────────────────────────────────────────────────

    public function Execute(string $Sql, ?string $err_msg = 'Could not execute query.'): bool
    {
        if (!$this->_Cnn) {
            $this->Connect();
        }

        $this->_NumQueries++;

        // PHP 8.0+: mysqli may throw mysqli_sql_exception in some configs.
        // Disable exception mode for the query and restore it after, so callers
        // can pass $err_msg=null to safely probe for table existence.
        $driver = new \mysqli_driver();
        $prev_report = $driver->report_mode;
        mysqli_report(MYSQLI_REPORT_OFF);

        $res = mysqli_query($this->_Cnn, $Sql);

        mysqli_report($prev_report);

        if ($res === false) {
            if ($err_msg !== null) {
                trigger_error(
                    $err_msg . ' ' . mysqli_error($this->_Cnn) . ': ' . $Sql,
                    E_USER_WARNING
                );
            }
            return false;
        }

        $this->_Res = $res;

        if ($this->_Res instanceof \mysqli_result) {
            $this->_Results[] = $this->_Res;
        }

        return true;
    }

    /**
     * Execute a query with pagination support.
     *
     * FIX (Redux 1.1.2): The previous implementation called GetFirstCell()
     * (which internally calls FreeResult(), popping the result off $_Results),
     * then called NumRows() which reads end($_Results) — now pointing at a
     * stale earlier result. The bogus $numrows value was then used in an
     * `if ($numrows > 1)` branch that could silently overwrite total_results
     * with 1, causing only one post to be shown.
     *
     * The fix wraps the base query in a subquery for counting:
     *   SELECT COUNT(*) FROM (...) AS _fu_count_sub
     * This always returns exactly one integer regardless of GROUP BY clauses,
     * and does not depend on the result stack state after GetFirstCell().
     */
    public function ExecutePaginate(string $Sql, int $max_results = 0): void
    {
        if ($max_results > 0) {
            $this->max_results = $max_results;
        }

        $this->page = isset($_GET['p']) ? (int) $_GET['p'] : 1;

        // Strip ORDER BY from the base query to build the count subquery.
        // Use a subquery wrapper so GROUP BY is counted correctly:
        //   SELECT COUNT(*) FROM (SELECT ... FROM ... WHERE ... GROUP BY ...) AS _fu_count_sub
        $sql_without_order = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $Sql);
        $count_sql = 'SELECT COUNT(*) FROM (' . $sql_without_order . ') AS _fu_count_sub';

        // GetFirstCell() executes and frees in one shot — no result stack side-effects.
        $this->total_results = (int) $this->GetFirstCell($count_sql);

        if (isset($_GET['nopage'])) {
            $this->max_results = $this->total_results;
        }

        $this->cur_row = 0;
        $this->Execute(
            $Sql . ' LIMIT ' . (($this->page - 1) * $this->max_results) . ', ' . $this->max_results
        );
    }

    // ── result navigation ───────────────────────────────────────────────────

    public function ReadRecord(): array|false
    {
        if (!$this->GetLastResult()) {
            return false;
        }
        return mysqli_fetch_assoc($this->GetLastResult()) ?: false;
    }

    public function ReadRecordPaginate(): array|false
    {
        if ($this->cur_row >= $this->max_results) {
            return false;
        }
        $this->cur_row++;
        return $this->ReadRecord();
    }

    public function GetRecord(string $sql = ''): array
    {
        if ($sql !== '') {
            $this->Execute($sql);
        }

        if (!$this->GetLastResult()) {
            return [];
        }

        $return = mysqli_fetch_assoc($this->GetLastResult());
        $this->FreeResult();
        return $return ?: [];
    }

    public function GetFirstCell(string $sql = ''): mixed
    {
        if ($sql !== '') {
            $this->Execute($sql);
        }

        if (!$this->GetLastResult()) {
            return false;
        }

        $row = mysqli_fetch_row($this->GetLastResult());
        $this->FreeResult();
        return $row[0] ?? false;
    }

    /**
     * Fetch all rows from the last (or a new) query as an array of associative
     * arrays, then free the result.
     *
     * @return array<int, array<string, mixed>>
     */
    public function GetAll(string $sql = ''): array
    {
        if ($sql !== '') {
            $this->Execute($sql);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return [];
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        $this->FreeResult();
        return $rows;
    }

    public function GetLastResult(): \mysqli_result|false
    {
        $result = end($this->_Results);
        return ($result instanceof \mysqli_result) ? $result : false;
    }

    // ── statistics ──────────────────────────────────────────────────────────

    public function GetNumResults(): int
    {
        return count($this->_Results);
    }

    public function GetNumQueries(): int
    {
        return $this->_NumQueries;
    }

    public function NumRows(): int
    {
        $res = $this->GetLastResult();
        return $res ? mysqli_num_rows($res) : 0;
    }

    public function AffectedRows(): int
    {
        return (int) mysqli_affected_rows($this->_Cnn);
    }

    public function GetLastSequence(): int
    {
        return (int) mysqli_insert_id($this->_Cnn);
    }

    // ── result management ───────────────────────────────────────────────────

    public function FreeResult(): void
    {
        $last = $this->GetLastResult();
        if ($last !== false) {
            mysqli_free_result(array_pop($this->_Results));
        }
    }

    // ── escaping ────────────────────────────────────────────────────────────

    /**
     * Escape a value for safe insertion into a SQL query.
     * get_magic_quotes_gpc() was removed in PHP 8.0; stripslashes() companion
     * call removed accordingly.
     */
    public function Escape(string $value): string
    {
        if (!$this->_Cnn) {
            $this->Connect();
        }

        return trim(mysqli_real_escape_string($this->_Cnn, $value));
    }

    // ── pagination output ───────────────────────────────────────────────────

    public function PrintPaginate(
        bool   $doNums   = true,
        string $nextName = 'Next',
        string $prevName = 'Previous'
    ): void {
        if ($this->total_results <= $this->max_results) {
            return;
        }

        $total_pages = (int) ceil($this->total_results / $this->max_results);
        $clean_uri   = htmlspecialchars(clean_input($_SERVER['REQUEST_URI']));

        if ($this->page === 1 && empty($_GET['p'])) {
            $clean_uri .= empty($_GET) ? '?p=1' : '&amp;p=1';
        }

        $clean_uri = str_replace('&amp;nopage=1', '', $clean_uri);

        if ($this->page > 1) {
            $prev = $this->page - 1;
            $url  = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1p=' . $prev, $clean_uri);
            echo '<a href="' . $url . '" class="prev">' . $prevName . '</a> ';
        }

        if ($doNums) {
            $sep_str = '';
            if ($this->page > 1) {
                echo '&middot; ';
            }

            for ($i = 1; $i <= $total_pages; $i++) {
                $near_start = $i < 4;
                $near_end   = $i > ($total_pages - 3);
                $near_cur   = ($i < $this->page + 3 && $i > $this->page - 3);

                if ($near_start || $near_end || $near_cur) {
                    echo $sep_str;
                    $sep_str = '';
                    if ($this->page === $i) {
                        echo '<strong class="here">' . $i . '</strong> ';
                    } else {
                        $url = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1p=' . $i, $clean_uri);
                        echo '<a href="' . $url . '">' . $i . '</a> ';
                    }
                } else {
                    $sep_str = ' ... ';
                }
            }

            $url = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1nopage=1', $clean_uri);
            echo '&middot; <a href="' . $url . '">All</a> ';

            if ($this->page < $total_pages) {
                echo '&middot; ';
            }
        }

        if ($this->page < $total_pages) {
            $next = $this->page + 1;
            $url  = preg_replace('/(\?|&amp;)p=[0-9]+/', '$1p=' . $next, $clean_uri);
            echo '<a href="' . $url . '" class="next">' . $nextName . '</a>';
        }
    }
}
