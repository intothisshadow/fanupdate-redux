<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — ErrorLogService (FP-46)
 *
 * File-based application error log, one JSON object per line in
 * {logDir}/error.log. Deliberately NOT database-backed: database failures
 * are exactly when a DB log would be unavailable.
 *
 * Entries never contain stack traces, and filesystem paths are reduced to a
 * path relative to the install root. Control characters are stripped from
 * every value so a hostile request cannot forge extra log lines. The file
 * rotates at a fixed size, so it cannot grow without bound, and every method
 * fails quietly: a logging problem must never break the request being logged.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class ErrorLogService
{
    private const FILE_NAME         = 'error.log';
    private const MAX_BYTES         = 524288;
    private const MAX_MESSAGE_CHARS = 1000;

    public function __construct(
        private readonly string $logDirectory,
        private readonly string $installRoot = '',
    ) {
    }

    public function record(string $level, string $message, string $file = '', int $line = 0): void
    {
        if (!$this->ensureDirectory()) {
            return;
        }

        $entry = json_encode([
            'time'    => gmdate('Y-m-d H:i:s'),
            'level'   => $this->sanitize($level, 20),
            'message' => $this->sanitize($this->scrubPaths($message), self::MAX_MESSAGE_CHARS),
            'file'    => $this->sanitize($this->relativePath($file), 200),
            'line'    => max(0, $line),
        ]);

        if ($entry === false) {
            return;
        }

        $this->rotateIfNeeded();
        file_put_contents($this->path(), $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<array{time: string, level: string, message: string, file: string, line: int}> newest first
     */
    public function recent(int $limit = 50): array
    {
        $lines = $this->readLines();
        $rows  = [];

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                continue;
            }

            $rows[] = [
                'time'    => (string) ($decoded['time'] ?? ''),
                'level'   => (string) ($decoded['level'] ?? ''),
                'message' => (string) ($decoded['message'] ?? ''),
                'file'    => (string) ($decoded['file'] ?? ''),
                'line'    => (int) ($decoded['line'] ?? 0),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function count(): int
    {
        return count($this->readLines());
    }

    public function fileSize(): int
    {
        return is_file($this->path()) ? (int) filesize($this->path()) : 0;
    }

    public function clear(): bool
    {
        foreach ([$this->path(), $this->path() . '.1'] as $file) {
            if (is_file($file) && !unlink($file)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function readLines(): array
    {
        if (!is_file($this->path())) {
            return [];
        }

        // Bounded by MAX_BYTES, so reading the whole file is cheap.
        $lines = file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : array_values($lines);
    }

    private function path(): string
    {
        return rtrim($this->logDirectory, '/\\') . '/' . self::FILE_NAME;
    }

    private function rotateIfNeeded(): void
    {
        if (is_file($this->path()) && filesize($this->path()) >= self::MAX_BYTES) {
            rename($this->path(), $this->path() . '.1');
        }
    }

    /** Creates the directory with a deny-all .htaccess; false when it is unusable. */
    private function ensureDirectory(): bool
    {
        if (!is_dir($this->logDirectory)) {
            // Checked up front so an unusable path fails quietly instead of via a mkdir() warning.
            $ancestor = $this->logDirectory;
            while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
                $ancestor = dirname($ancestor);
            }

            if (!is_dir($ancestor) || !is_writable($ancestor) || (!mkdir($this->logDirectory, 0750, true) && !is_dir($this->logDirectory))) {
                return false;
            }
        }

        if (!is_writable($this->logDirectory)) {
            return false;
        }

        $htaccess = rtrim($this->logDirectory, '/\\') . '/.htaccess';

        if (!is_file($htaccess)) {
            file_put_contents(
                $htaccess,
                "# Auto-generated by FanUpdate — do not delete\nRequire all denied\n",
                LOCK_EX
            );
        }

        return true;
    }

    private function relativePath(string $file): string
    {
        if ($file === '') {
            return '';
        }

        $root = rtrim($this->installRoot, '/\\');

        if ($root !== '' && str_starts_with($file, $root . '/')) {
            return substr($file, strlen($root) + 1);
        }

        return basename($file);
    }

    /** PHP and PDO messages often embed absolute paths; reduce them to install-relative ones. */
    private function scrubPaths(string $message): string
    {
        $root = rtrim($this->installRoot, '/\\');

        return $root === '' ? $message : str_replace($root . '/', '', $message);
    }

    private function sanitize(string $value, int $maxLength): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        return mb_substr(trim($value), 0, $maxLength);
    }
}
