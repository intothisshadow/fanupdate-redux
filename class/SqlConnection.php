<?php
/*****************************************************************************
 * SqlConnection Class for MySQL databases — PHP 8.2+ refactor
 *
 * Copyright (c) Jay Pipes
 * Copyright (c) 2008 by Jenny Ferenc (contributor) <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Refactored for PHP 8.2+ compatibility:
 *   - All dynamic properties replaced with explicit typed declarations
 *   - Removed get_magic_quotes_gpc() (removed in PHP 8.0)
 *   - Removed redundant mysqli_real_escape_string fallback (PHP 4 era)
 *   - Strict scalar type hints and return types throughout
 *   - mysqli_select_db() replaced with object-oriented mysqli
 *   - False-typed property $_Cnn changed to ?mysqli
 *   - $_Res property changed to mysqli_result|bool
 ******************************************************************************/

declare(strict_types=1);

class SqlConnection
{
    // Connection credentials — all declared explicitly (PHP 8.2: no dynamic props)
    private string $_host = '';
    private string $_user = '';
    private string $_pass = '';
    private string $_name = '';

    private ?mysqli $_Cnn = null;

    /** @var mysqli_result[] */
    private array $_Results = [];

    private mysqli_result|bool $_Res = false;
    private int $_NumQueries = 0;

    // Pagination state — explicitly declared
    public int $max_results = 15;
    public int $page = 1;
    public int $total_results = 0;
    public int $cur_row = 0;

    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Get the singleton SqlConnection instance.
     * Optionally pass (host, user, pass, name) on first call to configure.
     */
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

    public function GetLastResult(): mysqli_result|false
    {
        $result = end($this->_Results);
        return $result === false ? false : $result;
    }

    public function GetNumResults(): int
    {
        return count($this->_Results);
    }

    public function GetNumQueries(): int
    {
        return $this->_NumQueries;
    }

    /**
     * Connect to the MySQL database.
     */
    public function Connect(): bool
    {
        if ($this->_Cnn !== null) {
            return true;
        }

        $mysqli = @new mysqli($this->_host, $this->_user, $this->_pass, $this->_name);

        if ($mysqli->connect_errno) {
            trigger_error(
                'Could not connect to database server: ' . $mysqli->connect_error,
                E_USER_ERROR
            );
            return false;
        }

        $this->_Cnn = $mysqli;
        // Enforce UTF-8 on the connection
        $this->_Cnn->set_charset('utf8mb4');

        return true;
    }

    /**
     * Execute a SQL statement and push the result onto the results stack.
     */
    public function Execute(string $Sql, ?string $err_msg = 'Could not execute query.'): bool
    {
        if ($this->_Cnn === null) {
            $this->Connect();
        }

        $this->_NumQueries++;

        $result = $this->_Cnn->query($Sql);

        if ($result === false) {
            if ($err_msg !== null) {
                trigger_error(
                    $err_msg . ' ' . $this->_Cnn->error . ': ' . $Sql,
                    E_USER_WARNING
                );
            }
            return false;
        }

        if ($result instanceof mysqli_result) {
            $this->_Results[] = $result;
            $this->_Res = $result;
        } else {
            // INSERT/UPDATE/DELETE returns true
            $this->_Res = $result;
        }

        return true;
    }

    /**
     * Execute with server-side pagination support.
     */
    public function ExecutePaginate(string $Sql, int $per_page = 15): bool
    {
        $this->max_results = $per_page;

        // Count total rows
        $count_sql = 'SELECT COUNT(*) FROM (' . $Sql . ') AS _count_wrap';
        if ($this->_Cnn === null) {
            $this->Connect();
        }
        $count_res = $this->_Cnn->query($count_sql);
        if ($count_res instanceof mysqli_result) {
            $row = $count_res->fetch_row();
            $this->total_results = (int)($row[0] ?? 0);
            $count_res->free();
        }

        if (!isset($_GET['nopage'])) {
            $this->page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
            $offset = ($this->page - 1) * $per_page;
            $Sql .= ' LIMIT ' . $per_page . ' OFFSET ' . $offset;
        }

        $this->cur_row = 0;
        return $this->Execute($Sql);
    }

    /**
     * Read a single row from the paginated result.
     * Returns false when exhausted.
     */
    public function ReadRecordPaginate(): array|false
    {
        $result = $this->GetLastResult();
        if (!$result) {
            return false;
        }
        $row = $result->fetch_assoc();
        if ($row !== null) {
            $this->cur_row++;
        }
        return $row ?? false;
    }

    /**
     * Read the current record from the last result set.
     */
    public function ReadRecord(): array|false
    {
        $result = $this->GetLastResult();
        if (!$result) {
            return false;
        }
        return $result->fetch_assoc() ?? false;
    }

    /**
     * Return a single record and free the result.
     * Optionally pass SQL to execute first.
     */
    public function GetRecord(string $sql = ''): array
    {
        if ($sql !== '') {
            $this->Execute($sql);
        }

        $result = $this->GetLastResult();
        if (!$result) {
            return [];
        }

        $row = $result->fetch_assoc();
        $this->FreeResult();
        return $row ?? [];
    }

    /**
     * Return the first cell of the first row, then free result.
     * Optionally pass SQL to execute first.
     */
    public function GetFirstCell(string $sql = ''): mixed
    {
        if ($sql !== '') {
            $this->Execute($sql);
        }

        $result = $this->GetLastResult();
        if (!$result) {
            return false;
        }

        $row = $result->fetch_row();
        $this->FreeResult();
        return $row[0] ?? false;
    }

    /**
     * Return all rows as an array of associative arrays, then free result.
     * Optionally pass SQL to execute first.
     */
    public function GetAll(string $sql = ''): array
    {
        if ($sql !== '') {
            $this->Execute($sql);
        }

        $result = $this->GetLastResult();
        if (!$result) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $this->FreeResult();
        return $rows;
    }

    /**
     * Return the last auto-increment ID.
     */
    public function GetLastSequence(): int
    {
        return (int)$this->_Cnn?->insert_id;
    }

    /**
     * Number of rows in the last result set.
     */
    public function NumRows(): int
    {
        $result = $this->GetLastResult();
        if (!$result) {
            return 0;
        }
        return (int)$result->num_rows;
    }

    /**
     * Number of rows affected by the last DML statement.
     */
    public function AffectedRows(): int
    {
        return (int)($this->_Cnn?->affected_rows ?? 0);
    }

    /**
     * Free the most recent result.
     */
    public function FreeResult(): void
    {
        $result = array_pop($this->_Results);
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    /**
     * Escape a value for safe inclusion in a SQL statement.
     * get_magic_quotes_gpc() was removed in PHP 8.0 — stripslashes() call removed.
     */
    public function Escape(string $value): string
    {
        if ($this->_Cnn === null) {
            $this->Connect();
        }
        return trim($this->_Cnn->real_escape_string($value));
    }

    /**
     * Render pagination navigation links.
     */
    public function PrintPageNav(
        string $prevName = 'Prev',
        string $nextName = 'Next',
        bool $doNums = true
    ): void {
        $total_pages = (int)ceil($this->total_results / $this->max_results);
        if ($total_pages <= 1) {
            return;
        }

        $clean_uri = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
        if (!str_contains($clean_uri, '?')) {
            $clean_uri .= '?p=1';
        } elseif (!str_contains($clean_uri, 'p=')) {
            $clean_uri .= '&amp;p=1';
        }

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
                if (
                    $i < 1 + 3 ||
                    $i > $total_pages - 3 ||
                    ($i < $this->page + 3 && $i > $this->page - 3)
                ) {
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
