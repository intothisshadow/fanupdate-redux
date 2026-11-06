<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — SchemaMigrator
 *
 * Idempotent schema migrations shared by install.php and the admin panel:
 *  - standalone_category_table: created only when a collective script is
 *    configured (see CategoryRepository::STANDALONE_ID_OFFSET).
 *  - media_table and the event log table: created once a name is configured.
 *  - MyISAM -> InnoDB and latin1 -> utf8mb4, as one ALTER per table (either
 *    change alone rebuilds it). tableHasMisEncodedUtf8() decides between a
 *    plain CONVERT and a binary-intermediate reinterpretation.
 *
 * Table names are injected rather than read from the FanUpdate singleton,
 * so the class is unit-testable with a mock SqlConnection. Optional tables
 * are nullable so installs predating them are a no-op, and collective_table
 * is migrated only in standalone mode (otherwise it belongs to the
 * external script).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class SchemaMigrator
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $blogTable,
        private readonly string $commentsTable,
        private readonly string $collectiveTable,
        private readonly string $catjoinTable,
        private readonly string $optionsTable,
        private readonly string $blacklistTable,
        private readonly string $catoptionsTable,
        private readonly string $smiliesTable,
        private readonly string $colSubj,
        private readonly bool $collectiveScriptConfigured,
        private readonly ?string $standaloneCategoryTable,
        private readonly ?string $mediaTable = null,
        private readonly ?string $logTable = null,
    ) {
    }

    /** Read-only (one information_schema lookup per table): whether runPendingSchemaChanges() would do anything. */
    public function hasPendingSchemaChanges(): bool
    {
        if (
            $this->collectiveScriptConfigured
            && $this->standaloneCategoryTable !== null
            && $this->standaloneCategoryTable !== ''
            && !$this->tableExists($this->standaloneCategoryTable)
        ) {
            return true;
        }

        if (
            $this->mediaTable !== null
            && $this->mediaTable !== ''
            && !$this->tableExists($this->mediaTable)
        ) {
            return true;
        }

        if (
            $this->logTable !== null
            && $this->logTable !== ''
            && !$this->tableExists($this->logTable)
        ) {
            return true;
        }

        foreach ($this->migrationSteps() as $step) {
            if ($this->tableNeedsMigration($step['table'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates missing tables, then converts each managed table. Only tables
     * where a change was attempted (successfully or not) appear in the result.
     *
     * @param callable(string, int, int): void|null $onProgress Called as
     *        (message, step, totalSteps); must not throw.
     * @return list<array{table: string, ok: bool, message: string}>
     */
    public function runPendingSchemaChanges(?callable $onProgress = null): array
    {
        $this->liftExecutionLimits();

        $steps = [];

        $steps[] = [
            'label' => 'Checking Categories Support tables',
            'run'   => fn (): ?array => $this->ensureStandaloneCategoryTable(),
        ];

        $steps[] = [
            'label' => 'Checking Media table',
            'run'   => fn (): ?array => $this->ensureMediaTable(),
        ];

        $steps[] = [
            'label' => 'Checking Event Log table',
            'run'   => fn (): ?array => $this->ensureLogTable(),
        ];

        foreach ($this->migrationSteps() as $def) {
            $steps[] = [
                'label' => 'Checking table ' . $def['table'],
                'run'   => fn (): ?array => $this->migrateTable($def['table'], $def['textColumns'], $def['reinterpretSql']),
            ];
        }

        $total   = count($steps);
        $results = [];

        foreach ($steps as $i => $step) {
            $this->notifyProgress($onProgress, $step['label'], $i + 1, $total);

            $result = ($step['run'])();
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** Large tables can take minutes to convert; guard against timeouts and disconnects. */
    private function liftExecutionLimits(): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        ignore_user_abort(true);
    }

    /** Calls $onProgress if given (a nullable callable can't use `?->`). */
    private function notifyProgress(?callable $onProgress, string $message, int $step, int $total): void
    {
        if ($onProgress !== null) {
            $onProgress($message, $step, $total);
        }
    }

    // ── Categories Support: standalone_category_table ─────────────────────

    /** Creates standalone_category_table if a collective script is configured; null when nothing to do. */
    private function ensureStandaloneCategoryTable(): ?array
    {
        if (
            !$this->collectiveScriptConfigured
            || $this->standaloneCategoryTable === null
            || $this->standaloneCategoryTable === ''
            || $this->tableExists($this->standaloneCategoryTable)
        ) {
            return null;
        }

        $offsetPlusOne = CategoryRepository::STANDALONE_ID_OFFSET + 1;
        $query         = 'CREATE TABLE ' . $this->standaloneCategoryTable . " (
      `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL DEFAULT '',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=$offsetPlusOne";

        $ok = $this->db->Execute($query);

        return [
            'table'   => $this->standaloneCategoryTable,
            'ok'      => $ok,
            'message' => $ok
                ? 'Table ' . $this->standaloneCategoryTable . ' created.'
                : 'Table ' . $this->standaloneCategoryTable . ' not created!',
        ];
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->GetFirstCell("SHOW TABLES LIKE '" . $table . "'");
    }

    // ── Media gallery: media_table ──────────────────────────────────────────

    /** Creates the media table for every install (regardless of collective_script); null when unconfigured or present. */
    private function ensureMediaTable(): ?array
    {
        if (
            $this->mediaTable === null
            || $this->mediaTable === ''
            || $this->tableExists($this->mediaTable)
        ) {
            return null;
        }

        $query = 'CREATE TABLE ' . $this->mediaTable . " (
      `media_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `filename` varchar(191) NOT NULL DEFAULT '',
      `file_path` varchar(255) NOT NULL DEFAULT '',
      `thumb_path` varchar(255) NOT NULL DEFAULT '',
      `width` int(10) unsigned NOT NULL DEFAULT 0,
      `height` int(10) unsigned NOT NULL DEFAULT 0,
      `size` int(10) unsigned NOT NULL DEFAULT 0,
      `mime_type` varchar(50) NOT NULL DEFAULT '',
      `uploaded_at` datetime NOT NULL,
      PRIMARY KEY (`media_id`),
      KEY `uploaded_at` (`uploaded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $ok = $this->db->Execute($query);

        return [
            'table'   => $this->mediaTable,
            'ok'      => $ok,
            'message' => $ok
                ? 'Table ' . $this->mediaTable . ' created.'
                : 'Table ' . $this->mediaTable . ' not created!',
        ];
    }

    // ── Event log: log_table ────────────────────────────────────────

    /** Creates the admin event log table; a no-op when unconfigured or already present. */
    private function ensureLogTable(): ?array
    {
        if (
            $this->logTable === null
            || $this->logTable === ''
            || $this->tableExists($this->logTable)
        ) {
            return null;
        }

        $query = 'CREATE TABLE `' . $this->logTable . "` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `event_type` varchar(32) NOT NULL,
      `username` varchar(50) NOT NULL DEFAULT '',
      `ip` varchar(45) NOT NULL DEFAULT '',
      `message` varchar(500) NOT NULL DEFAULT '',
      `created_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `type_created` (`event_type`, `created_at`),
      KEY `created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        $ok = $this->db->Execute($query);

        return [
            'table'   => $this->logTable,
            'ok'      => $ok,
            'message' => $ok
                ? 'Table ' . $this->logTable . ' created.'
                : 'Table ' . $this->logTable . ' not created!',
        ];
    }

    // ── engine/charset migration ────────────────────────────────────────────

    /**
     * Every migratable table, with the text columns to scan for mis-encoded
     * UTF-8 and the binary-intermediate MODIFY statements to use if found.
     *
     * @return list<array{table: string, textColumns: list<string>, reinterpretSql: list<string>}>
     */
    private function migrationSteps(): array
    {
        $defs = [];

        $defs[] = [
            'table'          => $this->blogTable,
            'textColumns'    => ['title', 'body'],
            'reinterpretSql' => [
                "MODIFY `title` VARBINARY(50) NOT NULL DEFAULT '', MODIFY `body` BLOB NOT NULL",
                "MODIFY `title` VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL DEFAULT '', MODIFY `body` TEXT CHARACTER SET utf8mb4 NOT NULL",
            ],
        ];

        $defs[] = [
            'table'          => $this->commentsTable,
            'textColumns'    => ['name', 'email', 'url', 'comment'],
            'reinterpretSql' => [
                "MODIFY `name` VARBINARY(30) NOT NULL DEFAULT '', MODIFY `email` VARBINARY(100) NOT NULL DEFAULT '', MODIFY `url` VARBINARY(100) NOT NULL DEFAULT '', MODIFY `comment` BLOB NOT NULL",
                "MODIFY `name` VARCHAR(30) CHARACTER SET utf8mb4 NOT NULL DEFAULT '', MODIFY `email` VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL DEFAULT '', MODIFY `url` VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL DEFAULT '', MODIFY `comment` TEXT CHARACTER SET utf8mb4 NOT NULL",
            ],
        ];

        if (!$this->collectiveScriptConfigured) {
            $defs[] = [
                'table'          => $this->collectiveTable,
                'textColumns'    => [$this->colSubj],
                'reinterpretSql' => [
                    "MODIFY `{$this->colSubj}` VARBINARY(50) NOT NULL DEFAULT ''",
                    "MODIFY `{$this->colSubj}` VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL DEFAULT ''",
                ],
            ];
        }

        // Integer columns only: no mis-encoding risk, so plain CONVERT suffices.
        $defs[] = ['table' => $this->catjoinTable, 'textColumns' => [], 'reinterpretSql' => []];

        $defs[] = [
            'table'          => $this->optionsTable,
            'textColumns'    => ['optkey', 'optvalue', 'optdesc'],
            'reinterpretSql' => [
                'MODIFY `optkey` VARBINARY(30) NOT NULL, MODIFY `optvalue` BLOB NOT NULL, MODIFY `optdesc` BLOB NOT NULL',
                'MODIFY `optkey` VARCHAR(30) CHARACTER SET utf8mb4 NOT NULL, MODIFY `optvalue` TEXT CHARACTER SET utf8mb4 NOT NULL, MODIFY `optdesc` TEXT CHARACTER SET utf8mb4 NOT NULL',
            ],
        ];

        $defs[] = [
            'table'          => $this->blacklistTable,
            'textColumns'    => ['badword'],
            'reinterpretSql' => [
                'MODIFY `badword` VARBINARY(50) NOT NULL',
                'MODIFY `badword` VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL',
            ],
        ];

        $defs[] = [
            'table'          => $this->catoptionsTable,
            'textColumns'    => ['date_format', 'gravatar_default', 'gravatar_size', 'gravatar_rating', 'entry_template', 'comment_template'],
            'reinterpretSql' => [
                'MODIFY `date_format` VARBINARY(30) NULL, MODIFY `gravatar_default` VARBINARY(100) NULL, MODIFY `gravatar_size` VARBINARY(2) NULL, MODIFY `gravatar_rating` VARBINARY(2) NULL, MODIFY `entry_template` BLOB NULL, MODIFY `comment_template` BLOB NULL',
                'MODIFY `date_format` VARCHAR(30) CHARACTER SET utf8mb4 NULL, MODIFY `gravatar_default` VARCHAR(100) CHARACTER SET utf8mb4 NULL, MODIFY `gravatar_size` VARCHAR(2) CHARACTER SET utf8mb4 NULL, MODIFY `gravatar_rating` VARCHAR(2) CHARACTER SET utf8mb4 NULL, MODIFY `entry_template` TEXT CHARACTER SET utf8mb4 NULL, MODIFY `comment_template` TEXT CHARACTER SET utf8mb4 NULL',
            ],
        ];

        $defs[] = [
            'table'          => $this->smiliesTable,
            'textColumns'    => ['smiley', 'image'],
            'reinterpretSql' => [
                "MODIFY `smiley` VARBINARY(10) NOT NULL DEFAULT '', MODIFY `image` VARBINARY(100) NOT NULL DEFAULT ''",
                "MODIFY `smiley` VARCHAR(10) CHARACTER SET utf8mb4 NOT NULL DEFAULT '', MODIFY `image` VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL DEFAULT ''",
            ],
        ];

        if ($this->standaloneCategoryTable !== null && $this->standaloneCategoryTable !== '') {
            $defs[] = [
                'table'          => $this->standaloneCategoryTable,
                'textColumns'    => ['name'],
                'reinterpretSql' => [
                    "MODIFY `name` VARBINARY(100) NOT NULL DEFAULT ''",
                    "MODIFY `name` VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL DEFAULT ''",
                ],
            ];
        }

        return $defs;
    }

    /** Whether $table exists and is not yet both InnoDB and utf8mb4. False (no-op) if the table doesn't exist at all. */
    private function tableNeedsMigration(string $table): bool
    {
        $engine = (string) $this->db->GetFirstCell(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );

        if ($engine === '') {
            return false;
        }

        if (strcasecmp($engine, 'InnoDB') !== 0) {
            return true;
        }

        $collation = (string) $this->db->GetFirstCell(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );

        return stripos($collation, 'utf8mb4') === false;
    }

    /**
     * Converts $table to InnoDB/utf8mb4 in one ALTER (skipping whichever
     * half is done); null if missing or already converted. When mis-encoded
     * UTF-8 is detected, $reinterpretSql reinterprets the bytes instead,
     * since transcoding them again would produce mojibake.
     *
     * @param list<string> $textColumns
     * @param list<string> $reinterpretSql
     */
    private function migrateTable(string $table, array $textColumns, array $reinterpretSql): ?array
    {
        $engine = (string) $this->db->GetFirstCell(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );

        if ($engine === '') {
            return null;
        }

        $collation = (string) $this->db->GetFirstCell(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );

        $needsEngine  = strcasecmp($engine, 'InnoDB') !== 0;
        $needsCharset = stripos($collation, 'utf8mb4') === false;

        if (!$needsEngine && !$needsCharset) {
            return null;
        }

        if ($needsCharset && $this->tableHasMisEncodedUtf8($table, $textColumns)) {
            if ($needsEngine) {
                if (!$this->db->Execute('ALTER TABLE `' . $table . '` ENGINE=InnoDB')) {
                    return ['table' => $table, 'ok' => false, 'message' => 'Table ' . $table . ' could not be converted to InnoDB.'];
                }
            }

            // FULLTEXT indexes block the VARBINARY/BLOB step (error 1283): drop
            // them in the first ALTER, re-add in the last.
            $fulltext   = $this->fulltextIndexes($table);
            $lastClause = count($reinterpretSql) - 1;

            foreach ($reinterpretSql as $i => $alterClause) {
                if ($i === 0) {
                    $drops = array_map(static fn (array $ix): string => 'DROP INDEX `' . $ix['name'] . '`', $fulltext);
                    $alterClause = implode(', ', [...$drops, $alterClause]);
                }
                if ($i === $lastClause) {
                    $adds = array_map(
                        static fn (array $ix): string => 'ADD FULLTEXT KEY `' . $ix['name'] . '` (`' . implode('`, `', $ix['columns']) . '`)',
                        $fulltext
                    );
                    $alterClause = implode(', ', [$alterClause, ...$adds]);
                }

                if (!$this->db->Execute('ALTER TABLE `' . $table . '` ' . $alterClause)) {
                    return ['table' => $table, 'ok' => false, 'message' => 'Table ' . $table . ' charset reinterpretation step failed.'];
                }
            }

            // The MODIFYs leave the table default at latin1 (it would stay "pending");
            // columns are already utf8mb4, so this changes no bytes.
            if (!$this->db->Execute('ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci')) {
                return ['table' => $table, 'ok' => false, 'message' => 'Table ' . $table . ' could not be finalised as utf8mb4.'];
            }

            return [
                'table'   => $table,
                'ok'      => true,
                'message' => 'Table ' . $table . ' re-encoded to utf8mb4 '
                    . '(mis-encoded UTF-8 detected — existing bytes reinterpreted, not transcoded).',
            ];
        }

        $clauses = [];
        if ($needsEngine) {
            $clauses[] = 'ENGINE=InnoDB';
        }
        if ($needsCharset) {
            $clauses[] = 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci';
        }

        $ok = $this->db->Execute('ALTER TABLE `' . $table . '` ' . implode(', ', $clauses));

        return [
            'table'   => $table,
            'ok'      => $ok,
            'message' => $ok
                ? 'Table ' . $table . ' converted to InnoDB/utf8mb4.'
                : 'Table ' . $table . ' could not be converted to InnoDB/utf8mb4.',
        ];
    }

    /**
     * @return list<array{name: string, columns: list<string>}>
     */
    private function fulltextIndexes(string $table): array
    {
        $rows = $this->db->GetAll(
            "SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT'
              ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        $result = [];
        foreach ($indexes as $name => $columns) {
            $result[] = ['name' => (string) $name, 'columns' => $columns];
        }

        return $result;
    }

    /**
     * Scans up to $limit rows for a value that is both non-ASCII and valid
     * UTF-8: the signature of UTF-8 written into a latin1 column (genuine
     * latin1 almost never forms valid multi-byte sequences). False means a
     * plain CONVERT is safe.
     *
     * @param list<string> $columns
     */
    private function tableHasMisEncodedUtf8(string $table, array $columns, int $limit = 2000): bool
    {
        if (empty($columns)) {
            return false;
        }

        // CAST AS BINARY reads the stored bytes; a plain SELECT would convert
        // latin1 to UTF-8 and hide what is being tested for.
        $colList = implode(', ', array_map(static fn (string $c): string => 'CAST(`' . $c . '` AS BINARY) AS `' . $c . '`', $columns));
        $query   = 'SELECT ' . $colList . ' FROM `' . $table . '` LIMIT ' . $limit;

        if (!$this->db->Execute($query, null)) {
            return false;
        }

        $found = false;
        while ($row = $this->db->ReadRecord()) {
            foreach ($row as $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                if (preg_match('/[\x80-\xFF]/', $value) === 1 && mb_check_encoding($value, 'UTF-8')) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->db->FreeResult();

        return $found;
    }
}
