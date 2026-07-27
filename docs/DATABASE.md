# Database Layer (`class/SqlConnection.php`)

`SqlConnection` is a singleton wrapper around PDO (`pdo_mysql`). It is
accessed via `$fu->db` from anywhere holding a `FanUpdate` instance, or
directly via `SqlConnection::instance()`.

PDO is configured with:

- `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_EMULATE_PREPARES = false` — every query is a real server-side
  prepared statement, including the legacy API described below.
- `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = true` — preserves the old mysqli
  behaviour that `NumRows()`/`AffectedRows()` and running further queries
  before a result set is fully consumed depend on.

## Two APIs, one engine

There are two parallel method families. Both go through the same internal
`prepare()`/`execute()` call — they differ only in **error handling** and
**call shape**, not in how the query actually runs.

| Legacy (pre-Redux, kept for compatibility) | Modern (bound-parameter) | Error handling |
|---|---|---|
| `Execute($sql, $err_msg, $params)` | `query($sql, $params)` | Legacy: `trigger_error()`, returns `false`. Modern: throws `PDOException`. |
| `GetRecord($sql, $params)` | `fetch($sql, $params)` | Same as above |
| `GetFirstCell($sql, $params)` | `fetchColumn($sql, $params)` | Same as above |
| `GetAll($sql, $params)` | `fetchAll($sql, $params)` | Same as above |

**Use the modern API (`query`/`fetch`/`fetchAll`/`fetchColumn`) in all new
code.** It lets exceptions propagate so failures are never silently
swallowed. Wrap calls in `try`/`catch (\PDOException $e)` where a
user-facing error message is required; let it propagate to the top-level
error handler otherwise.

The legacy API (`Execute`/`GetRecord`/`GetFirstCell`/`GetAll`) exists only
so pre-Redux call sites keep working unmodified. Do not use it in new code.

## Bound parameters (always — no manual escaping)

Every method above accepts an optional trailing `array $params` of bound
values for `?` placeholders. There is no `Escape()` method (removed in
Milestone 2) — build every query with placeholders instead of interpolating
user input into the SQL string.

```php
// Recommended (modern API):
$row = $fu->db->fetch(
    'SELECT * FROM ' . $fu->getOpt('blog_table') . ' WHERE entry_id = ?',
    [$entryId]
);

// Multiple rows:
$rows = $fu->db->fetchAll(
    'SELECT * FROM ' . $fu->getOpt('comments_table')
        . ' WHERE entry_id = ? AND approved = ?',
    [$entryId, 1]
);

// A single scalar value:
$count = (int) $fu->db->fetchColumn(
    'SELECT COUNT(*) FROM ' . $fu->getOpt('comments_table')
        . ' WHERE entry_id = ? AND approved = 1',
    [$entryId]
);

// INSERT/UPDATE/DELETE — no rows to fetch, use query():
$fu->db->query(
    'UPDATE ' . $fu->getOpt('blog_table') . ' SET title = ? WHERE entry_id = ?',
    [$title, $entryId]
);
$newId = $fu->db->GetLastSequence();
```

> Table/column names themselves can never be bound parameters in PDO — they
> are interpolated as before via `$fu->getOpt('...table')`, which returns
> configuration values, not user input. Only *values* (the right-hand side
> of `WHERE`, `SET`, etc.) should come from bound parameters.

## Paginated queries

`ExecutePaginate($sql, $maxResults, $params)` is the pagination-aware
counterpart to `Execute()`. It computes `total_results` via a `COUNT(*)`
subquery, applies `LIMIT`, and populates `page`/`cur_row` from `$_GET['p']`.
Read rows with `ReadRecordPaginate()`, and render page links with
`PrintPaginate()`. There is no modern-API equivalent yet — this remains
legacy-only because it manages iteration state (`cur_row`, `max_results`)
as part of the query itself.

```php
$fu->db->ExecutePaginate(
    'SELECT * FROM ' . $fu->getOpt('blog_table') . ' WHERE is_public = 1 ORDER BY added DESC',
    5
);
while ($row = $fu->db->ReadRecordPaginate()) {
    // ...
}
$fu->db->PrintPaginate();
```

## Transactions

**Requires `ENGINE=InnoDB`.** Every FanUpdate-owned table is InnoDB as of
the Database Engine & Charset Migration (see `install.php`'s
`fu_migrate_table_engine_and_charset()` and TODO.md item "9. Database
Engine & Charset Migration" for the migration itself). On `ENGINE=MyISAM`
(the default on every installation before that migration ran)
`beginTransaction()`/`commit()`/`rollBack()` are silent no-ops — every
statement auto-commits immediately regardless of a later rollback — so the
example below only actually provides atomicity once the tables it touches
are InnoDB.

```php
$fu->db->beginTransaction();
try {
    $fu->db->query('INSERT INTO ...', [...]);
    $fu->db->query('UPDATE ...', [...]);
    $fu->db->commit();
} catch (\PDOException $e) {
    $fu->db->rollBack();
    throw $e; // or handle/log as appropriate
}
```

`inTransaction()` is available to check state before nesting.

## Escape hatch

`$fu->db->pdo()` returns the underlying `\PDO` instance for anything not
wrapped by this class (e.g. `PDO::quote()` for the rare case where a bound
parameter genuinely cannot be used).

## Internals (for maintainers)

Both API families are thin wrappers around two private helpers so the
actual `prepare()`/`execute()`/fetch logic exists in exactly one place each:

- `runStatement(string $sql, array $params): \PDOStatement` — prepares,
  executes, and registers the statement as the "current" result (used by
  `NumRows()`, `AffectedRows()`, `GetLastResult()`, `FreeResult()`).
  `Execute()` catches `PDOException` here and calls `trigger_error()`;
  `query()` lets it propagate.
- `fetchOneRow(\PDOStatement $stmt, int $fetchMode): array|false` and
  `fetchAllRows(\PDOStatement $stmt): array` — fetch-and-free logic shared
  by `GetRecord`/`fetch`, `GetFirstCell`/`fetchColumn`, and `GetAll`/`fetchAll`.

When adding a new query helper, extend one of these two private methods
rather than writing a third parallel prepare/execute or fetch/free block.
