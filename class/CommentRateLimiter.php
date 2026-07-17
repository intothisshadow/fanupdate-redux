<?php

declare(strict_types=1);
/*****************************************************************************
 * FanUpdate — class/CommentRateLimiter.php
 *
 * Copyright (c) 2026 Redux contributor
 *
 * File-based per-IP rate limiter for the public comment form.
 *
 * Design mirrors LoginRateLimiter: zero extra dependencies, file-based,
 * self-healing, shared-host friendly.
 *
 * Limits:
 *  - MAX_COMMENTS_PER_WINDOW comments from a single IP within WINDOW_SECONDS
 *    are allowed before the IP is temporarily blocked.
 *  - A blocked IP is refused for BLOCK_SECONDS (default 10 minutes).
 *    The block is NOT exponential — unlike brute-force login attacks, comment
 *    spam from real humans should be given a short cooldown, not a growing one.
 *
 * Lock-file format (JSON):
 *  {
 *    "count":       <int>,   // submissions this window
 *    "window_start": <int>,  // Unix timestamp when the current window began
 *    "blocked_until": <int>  // Unix timestamp; 0 = not currently blocked
 *  }
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class CommentRateLimiter
{
    // ── tuneable constants ──────────────────────────────────────────────────

    /** Maximum comments from one IP within WINDOW_SECONDS before blocking. */
    public const MAX_COMMENTS_PER_WINDOW = 5;

    /** Sliding window in seconds (1 hour). */
    public const WINDOW_SECONDS = 60 * 60;

    /** Block duration in seconds once the limit is hit (10 minutes). */
    public const BLOCK_SECONDS = 10 * 60;

    /** How often stale lock files are pruned (1 hour). */
    private const CLEANUP_INTERVAL = 60 * 60;

    // ── state ───────────────────────────────────────────────────────────────

    private string $storeDir;

    // ── constructor ─────────────────────────────────────────────────────────

    /**
     * @param string $storeDir  Absolute path to a writable, non-web-accessible
     *                          directory.  Defaults to
     *                          fanupdate/data/comment_rate/.
     */
    public function __construct(string $storeDir = '')
    {
        if ($storeDir !== '') {
            $this->storeDir = rtrim($storeDir, '/\\');
        } else {
            $this->storeDir = dirname(__DIR__) . '/data/comment_rate';
        }

        $this->ensureStoreDir();
    }

    // ── public API ──────────────────────────────────────────────────────────

    /**
     * Returns true when this IP is currently blocked.
     * Call BEFORE processing the comment form.
     */
    public function isBlocked(): bool
    {
        $state = $this->readState($this->currentIp());

        if ($state['blocked_until'] > 0 && time() < $state['blocked_until']) {
            return true;
        }

        return false;
    }

    /**
     * Record one successful comment submission.
     * Increments the count; triggers a block if the limit is reached.
     *
     * Returns true if the submission is allowed, false if it should be
     * blocked (caller should treat this identically to isBlocked()).
     */
    public function recordSubmission(): bool
    {
        $ip    = $this->currentIp();
        $state = $this->readState($ip);
        $now   = time();

        // Expire a previous block that has naturally lapsed
        if ($state['blocked_until'] > 0 && $now >= $state['blocked_until']) {
            $state['blocked_until'] = 0;
            $state['count']         = 0;
            $state['window_start']  = $now;
        }

        // Still blocked
        if ($state['blocked_until'] > 0 && $now < $state['blocked_until']) {
            return false;
        }

        // Roll the window if it has expired
        if (($now - $state['window_start']) > self::WINDOW_SECONDS) {
            $state['count']        = 0;
            $state['window_start'] = $now;
        }

        $state['count']++;

        if ($state['count'] > self::MAX_COMMENTS_PER_WINDOW) {
            $state['blocked_until'] = $now + self::BLOCK_SECONDS;
            $this->writeState($ip, $state);
            $this->maybeCleanup();
            return false;
        }

        $this->writeState($ip, $state);
        $this->maybeCleanup();
        return true;
    }

    // ── private helpers ─────────────────────────────────────────────────────

    private function currentIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '0.0.0.0';
        }
        return $ip;
    }

    private function lockFile(string $ip): string
    {
        return $this->storeDir . '/cr_' . md5($ip) . '.json';
    }

    /**
     * @return array{count: int, window_start: int, blocked_until: int}
     */
    private function readState(string $ip): array
    {
        $default = ['count' => 0, 'window_start' => time(), 'blocked_until' => 0];
        $file    = $this->lockFile($ip);

        if (!is_file($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }

        return [
            'count'         => (int) ($data['count']         ?? 0),
            'window_start'  => (int) ($data['window_start']  ?? time()),
            'blocked_until' => (int) ($data['blocked_until'] ?? 0),
        ];
    }

    /**
     * @param array{count: int, window_start: int, blocked_until: int} $state
     */
    private function writeState(string $ip, array $state): void
    {
        $file = $this->lockFile($ip);
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json !== false) {
            file_put_contents($file, $json, LOCK_EX);
        }
    }

    private function maybeCleanup(): void
    {
        $marker = $this->storeDir . '/.last_cleanup';
        $now    = time();

        if (is_file($marker) && ($now - (int) file_get_contents($marker)) < self::CLEANUP_INTERVAL) {
            return;
        }

        file_put_contents($marker, (string) $now, LOCK_EX);

        // Prune files not touched in 2× the window
        $maxAge = self::WINDOW_SECONDS * 2;
        foreach (glob($this->storeDir . '/cr_*.json') ?: [] as $file) {
            if (is_file($file) && ($now - (int) filemtime($file)) > $maxAge) {
                unlink($file);
            }
        }
    }

    private function ensureStoreDir(): void
    {
        if (!is_dir($this->storeDir)) {
            mkdir($this->storeDir, 0750, true);
        }

        $htaccess = $this->storeDir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents(
                $htaccess,
                "# Auto-generated by FanUpdate — do not delete\n"
                . "Require all denied\n",
                LOCK_EX
            );
        }
    }
}
