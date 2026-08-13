<?php

/*****************************************************************************
 * SqlConnection Class for MySQL databases
 *
 * Copyright (c) Jay Pipes http://www.jpipes.com/
 * Copyright (c) 2008 by Jenny Ferenc (contributor)
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 by Ariane (contributor)
 *
 * PDO (pdo_mysql) database abstraction layer. Configured with
 * ATTR_ERRMODE = ERRMODE_EXCEPTION and ATTR_EMULATE_PREPARES = false, so
 * every query — including the legacy Execute()/GetRecord()/GetFirstCell()/
 * GetAll() call sites — goes through a real server-side prepared statement.
 *
 * Two API families are exposed:
 *  - Legacy (Execute, GetRecord, GetFirstCell, GetAll, ReadRecord,
 *    ReadRecordPaginate, ExecutePaginate, NumRows, AffectedRows,
 *    GetLastSequence, FreeResult, PrintPaginate): preserves the original
 *    signatures/behaviour, including trigger_error() (or silent failure)
 *    on query errors rather than throwing, so existing call sites work
 *    unmodified. Each method accepts an optional trailing `array $params`
 *    for bound parameters.
 *  - New bound-parameter convenience API (query(), fetch(), fetchAll(),
 *    fetchColumn()): lets PDOException propagate to the caller instead of
 *    calling trigger_error(). Prefer these for newly written code; wrap in
 *    try/catch where a graceful user-facing error is required.
 *
 * Transaction helpers: beginTransaction(), commit(), rollBack(),
 * inTransaction().
 *
 * There is no manual-escaping helper (Escape()) — every call site uses
 * bound parameters via query()/fetch()/fetchAll()/Execute() instead; do not
 * re-add one.
 *
 * Query execution is consolidated behind one private helper
 * (runStatement()), used by both Execute() and query(). Row-fetching is
 * consolidated behind two private helpers (fetchOneRow(), fetchAllRows())
 * shared by the GetRecord/fetch, GetFirstCell/fetchColumn, and
 * GetAll/fetchAll pairs — each public method differs only in how it obtains
 * the PDOStatement and its error-handling contract (legacy silent-failure
 * vs. exception).
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

    private ?\PDO $_pdo = null;

    /** @var \PDOStatement[] */
    private array $_Results = [];

    private ?\PDOStatement $_Res = null;

    private int $_NumQueries = 0;

    // ── pagination state ────────────────────────────────────────────────────
    public int $max_results   = 15;
    public int $page          = 1;
    public int $total_results = 0;
    public int $cur_row       = 0;

    /** @var self */
    private static SqlConnection $instance;

    private function __construct()
    {
    }

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

    /** Connect lazily on first query if a caller never called Connect() explicitly. */
    private function ensureConnected(): void
    {
        if ($this->_pdo === null) {
            $this->Connect();
        }
    }

    /**
     * Escape hatch to the underlying PDO instance for code that needs
     * PDO-native functionality not wrapped by this class.
     */
    public function pdo(): \PDO
    {
        $this->ensureConnected();

        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo;
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
        if ($this->_pdo !== null && $host === '') {
            return true;
        }

        // Shared-hosting configs sometimes specify dbhost as "host:port" (the
        // format mysqli_connect() also accepted). PDO's mysql driver needs
        // the port as a separate DSN segment, so split it out here.
        $dsnHost = $this->_host;
        $dsnPort = '';
        if (str_contains($dsnHost, ':')) {
            [$dsnHost, $maybePort] = explode(':', $dsnHost, 2);
            if (ctype_digit($maybePort)) {
                $dsnPort = ';port=' . $maybePort;
            }
        }

        $dsn = 'mysql:host=' . $dsnHost . $dsnPort . ';dbname=' . $this->_name . ';charset=utf8mb4';

        try {
            $this->_pdo = new \PDO($dsn, $this->_user, $this->_pass, [
                \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES         => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE       => \PDO::FETCH_ASSOC,
                // Buffered queries preserve the old mysqli behaviour that the
                // rest of this class (and NumRows()/AffectedRows() callers)
                // depend on — e.g. rowCount() being meaningful for SELECTs,
                // and being able to run further queries before a result set
                // is fully consumed.
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (\PDOException $e) {
            trigger_error('Could not connect to database server: ' . $e->getMessage(), E_USER_ERROR);
            return false;
        }

        return true;
    }

    // ── query execution ─────────────────────────────────────────────────────

    /**
     * Prepare and execute a statement, tracking it as the "current" result
     * for GetLastResult()/NumRows()/AffectedRows()/FreeResult().
     *
     * This is the single place that talks to PDO::prepare()/execute() for
     * both the legacy Execute()-family API and the new query()-family API —
     * everything else in this class goes through here.
     *
     * @param array<int|string, mixed> $params
     * @throws \PDOException on prepare or execute failure.
     */
    private function runStatement(string $sql, array $params = []): \PDOStatement
    {
        $this->ensureConnected();
        $this->_NumQueries++;

        /** @var \PDO $pdo */
        $pdo  = $this->_pdo;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $this->_Res       = $stmt;
        $this->_Results[] = $stmt;

        return $stmt;
    }

    /**
     * Fetch a single row from a statement and free it.
     *
     * Shared by GetRecord()/fetch() (FETCH_ASSOC) and GetFirstCell()/
     * fetchColumn() (FETCH_NUM).
     */
    private function fetchOneRow(\PDOStatement $stmt, int $fetchMode): array|false
    {
        $row = $stmt->fetch($fetchMode);
        $this->FreeResult();
        return $row === false ? false : $row;
    }

    /**
     * Fetch every remaining row from a statement and free it.
     *
     * Shared by GetAll() and fetchAll().
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllRows(\PDOStatement $stmt): array
    {
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->FreeResult();
        return $rows ?: [];
    }

    /**
     * Execute a query. Preserves the legacy signature/behaviour (returns
     * bool, reports failures via trigger_error()) so existing call sites
     * keep working unmodified.
     *
     * Bound parameters are supported via the optional trailing $params
     * array — pass `?` placeholders in $Sql and the values here instead of
     * building the query string manually. This is the recommended way to
     * migrate an existing Execute() call site to prepared statements
     * without changing its shape.
     *
     * Does NOT throw — a failed query is reported via trigger_error() (or
     * silently, when $err_msg is null) and the method returns false, so
     * that legacy call sites written before Milestone 2 keep working
     * without a try/catch. New code that wants exceptions should use
     * query() instead.
     *
     * @param array<int|string, mixed> $params
     */
    public function Execute(string $Sql, ?string $err_msg = 'Could not execute query.', array $params = []): bool
    {
        try {
            $this->runStatement($Sql, $params);
        } catch (\PDOException $e) {
            if ($err_msg !== null) {
                trigger_error(
                    $err_msg . ' ' . $e->getMessage() . ': ' . $Sql,
                    E_USER_WARNING
                );
            }
            return false;
        }

        return true;
    }

    /**
     * Execute a query with pagination support. Count is computed via a
     * COUNT(*) subquery over the base query (ORDER BY stripped), which
     * correctly handles both plain queries and GROUP BY queries.
     *
     * @param array<int|string, mixed> $params
     */
    public function ExecutePaginate(string $Sql, int $max_results = 0, array $params = []): void
    {
        if ($max_results > 0) {
            $this->max_results = $max_results;
        }

        $this->page = isset($_GET['p']) ? (int) $_GET['p'] : 1;

        // Strip ORDER BY from the base query to build the count subquery.
        $sql_without_order = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $Sql);
        $count_sql = 'SELECT COUNT(*) FROM (' . $sql_without_order . ') AS _fu_count_sub';

        $this->total_results = (int) $this->GetFirstCell($count_sql, $params);

        if (isset($_GET['nopage'])) {
            $this->max_results = $this->total_results;
        }

        $this->cur_row = 0;
        $this->Execute(
            $Sql . ' LIMIT ' . (($this->page - 1) * $this->max_results) . ', ' . $this->max_results,
            'Could not execute query.',
            $params
        );
    }

    // ── result navigation ───────────────────────────────────────────────────

    public function ReadRecord(): array|false
    {
        $res = $this->GetLastResult();
        if ($res === false) {
            return false;
        }
        $row = $res->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? false : $row;
    }

    public function ReadRecordPaginate(): array|false
    {
        if ($this->cur_row >= $this->max_results) {
            return false;
        }
        $this->cur_row++;
        return $this->ReadRecord();
    }

    /**
     * Run $sql (if given) and return the first row as an associative array,
     * or [] on no rows / query failure. See Execute() for the legacy
     * silent-failure contract this preserves.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     */
    public function GetRecord(string $sql = '', array $params = []): array
    {
        if ($sql !== '') {
            $this->Execute($sql, 'Could not execute query.', $params);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return [];
        }

        $row = $this->fetchOneRow($res, \PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    /**
     * Run $sql (if given) and return the first column of the first row, or
     * false on no rows / query failure.
     *
     * @param array<int|string, mixed> $params
     * @return int|float|string|bool|null Actual column value on success;
     *         `false` specifically signals "no row" / "query failed" and is
     *         indistinguishable from a column value of `false` — callers
     *         that store literal boolean `false` in a column should use
     *         fetchColumn() (which has the same caveat) with a NULL check
     *         on the raw PDOStatement instead.
     */
    public function GetFirstCell(string $sql = '', array $params = []): mixed
    {
        if ($sql !== '') {
            $this->Execute($sql, 'Could not execute query.', $params);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return false;
        }

        $row = $this->fetchOneRow($res, \PDO::FETCH_NUM);
        return $row === false ? false : ($row[0] ?? false);
    }

    /**
     * Run $sql (if given) and return every row as an array of associative
     * arrays, or [] on no rows / query failure.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function GetAll(string $sql = '', array $params = []): array
    {
        if ($sql !== '') {
            $this->Execute($sql, 'Could not execute query.', $params);
        }

        $res = $this->GetLastResult();
        if ($res === false) {
            return [];
        }

        return $this->fetchAllRows($res);
    }

    public function GetLastResult(): \PDOStatement|false
    {
        $result = end($this->_Results);
        return ($result instanceof \PDOStatement) ? $result : false;
    }

    // ── new bound-parameter convenience API (Milestone 2) ──────────────────
    //
    // Unlike the legacy Execute()/GetRecord()/GetFirstCell()/GetAll() family
    // above (which trigger_error() on failure to preserve old behaviour),
    // these methods let PDOException propagate to the caller, per CLAUDE.md's
    // request to "improve database error handling using PDO exceptions" for
    // newly written code. Wrap calls in try/catch where a graceful
    // user-facing error is required.
    //
    // NOTE: a lowercase execute() alias was deliberately NOT added — PHP
    // method names are case-insensitive, so `execute()` would collide with
    // the legacy `Execute()` above and fail to compile. Use Execute() (with
    // bound $params) for statements that don't return rows.

    /**
     * Run a bound-parameter query and return the raw PDOStatement for
     * callers that need full control (e.g. streaming a large result set
     * without GetAll()'s full fetchAll()).
     *
     * @param array<int|string, mixed> $params
     * @throws \PDOException on prepare or execute failure.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        return $this->runStatement($sql, $params);
    }

    /**
     * Run a bound-parameter query and return the first row (or []).
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     * @throws \PDOException on prepare or execute failure.
     */
    public function fetch(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $row  = $this->fetchOneRow($stmt, \PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    /**
     * Run a bound-parameter query and return every row.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws \PDOException on prepare or execute failure.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $this->fetchAllRows($stmt);
    }

    /**
     * Run a bound-parameter query and return the first column of the first
     * row, or false when there are no rows.
     *
     * @param array<int|string, mixed> $params
     * @return int|float|string|bool|null See GetFirstCell() for the same
     *         "false means no row" caveat.
     * @throws \PDOException on prepare or execute failure.
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->query($sql, $params);
        $row  = $this->fetchOneRow($stmt, \PDO::FETCH_NUM);
        return $row === false ? false : ($row[0] ?? false);
    }

    // ── transactions ─────────────────────────────────────────────────────────

    /** @throws \PDOException if a transaction is already active. */
    public function beginTransaction(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->beginTransaction();
    }

    /** @throws \PDOException if there is no active transaction. */
    public function commit(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->commit();
    }

    /** @throws \PDOException if there is no active transaction. */
    public function rollBack(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return $pdo->inTransaction();
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
        return $res ? $res->rowCount() : 0;
    }

    public function AffectedRows(): int
    {
        return $this->_Res !== null ? $this->_Res->rowCount() : 0;
    }

    public function GetLastSequence(): int
    {
        $this->ensureConnected();
        /** @var \PDO $pdo */
        $pdo = $this->_pdo;
        return (int) $pdo->lastInsertId();
    }

    // ── result management ───────────────────────────────────────────────────

    public function FreeResult(): void
    {
        $last = $this->GetLastResult();
        if ($last !== false) {
            $stmt = array_pop($this->_Results);
            $stmt->closeCursor();
        }
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
