<?php

declare(strict_types=1);
/*****************************************************************************
 * FanUpdate — class/LoginRateLimiter.php
 *
 * Copyright (c) 2026 Redux contributor
 *
 * File-based IP rate limiter for the admin login form.
 *
 * Design goals:
 *  - Zero extra dependencies: no DB, no APCu, no Redis.
 *  - Self-healing: stale lock files are pruned automatically on every call.
 *  - Shared-host friendly: stores state in a writable tmp dir inside the
 *    FanUpdate install; falls back to sys_get_temp_dir() if that is not
 *    writable.
 *
 * Threat model:
 *  - Stops password-spraying and credential-stuffing from a single IP.
 *  - Does NOT protect against distributed attacks (many IPs, one attempt
 *    each) — that requires a WAF or upstream solution.
 *  - IPv6 /64 subnet grouping is intentionally omitted for simplicity; the
 *    admin login surface is low-traffic enough that per-address locking is
 *    sufficient.
 *
 * Lockout behaviour:
 *  - After MAX_ATTEMPTS failures the IP is locked for LOCKOUT_SECONDS.
 *  - Each subsequent lockout doubles the window (exponential backoff),
 *    capped at MAX_LOCKOUT_SECONDS.
 *  - A successful login resets the counter for that IP immediately.
 *
 * Lock-file format (JSON):
 *  {
 *    "attempts":  <int>,   // rolling failure count since last success/expiry
 *    "lockouts":  <int>,   // how many times this IP has been locked out
 *    "locked_until": <int> // Unix timestamp; 0 = not currently locked
 *  }
 *
 * Security note on file placement:
 *  Lock files are stored in a directory that MUST NOT be web-accessible.
 *  The default path (fanupdate/data/login_attempts/) is inside the install
 *  directory — if your web root IS the install directory, add a .htaccess
 *  rule denying access to data/, or move the store dir above the web root
 *  by passing a custom $storeDir to the constructor.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class LoginRateLimiter
{
    // ── tuneable constants ──────────────────────────────────────────────────

    /** Failures before the IP is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** Initial lockout in seconds (15 minutes). */
    public const LOCKOUT_SECONDS = 15 * 60;

    /** Hard ceiling for exponential backoff (24 hours). */
    public const MAX_LOCKOUT_SECONDS = 24 * 60 * 60;

    /** Interval at which old lock files are pruned (seconds). */
    private const CLEANUP_INTERVAL = 60 * 60; // 1 hour

    // ── state ───────────────────────────────────────────────────────────────

    private string $storeDir;

    // ── constructor ─────────────────────────────────────────────────────────

    /**
     * @param string $storeDir  Absolute path to a directory that is writable
     *                          and NOT web-accessible.  Pass an empty string to
     *                          use the default (fanupdate/data/login_attempts/).
     */
    public function __construct(string $storeDir = '')
    {
        if ($storeDir !== '') {
            $this->storeDir = rtrim($storeDir, '/\\');
        } else {
            // Default: <fanupdate-root>/data/login_attempts/
            // __DIR__ is fanupdate/class/, so go one level up.
            $this->storeDir = dirname(__DIR__) . '/data/login_attempts';
        }

        $this->ensureStoreDir();
    }

    // ── public API ──────────────────────────────────────────────────────────

    /**
     * Returns true when the IP is currently locked out.
     * Call this BEFORE processing the login form.
     */
    public function isLockedOut(): bool
    {
        $state = $this->readState($this->currentIp());
        if ($state['locked_until'] === 0) {
            return false;
        }
        return time() < $state['locked_until'];
    }

    /**
     * Returns the number of seconds remaining in the current lockout,
     * or 0 if the IP is not locked.
     */
    public function secondsRemaining(): int
    {
        $state = $this->readState($this->currentIp());
        if ($state['locked_until'] === 0) {
            return 0;
        }
        $remaining = $state['locked_until'] - time();
        return max(0, $remaining);
    }

    /**
     * Record a failed login attempt for the current IP.
     * Triggers a lockout if MAX_ATTEMPTS is reached.
     */
    public function recordFailure(): void
    {
        $ip    = $this->currentIp();
        $state = $this->readState($ip);

        // If a previous lockout has naturally expired, reset attempt count
        // but keep the lockouts counter so backoff remains accurate.
        if ($state['locked_until'] > 0 && time() >= $state['locked_until']) {
            $state['attempts']    = 0;
            $state['locked_until'] = 0;
        }

        $state['attempts']++;

        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            $state['lockouts']++;

            // Exponential backoff: LOCKOUT_SECONDS * 2^(lockouts-1), capped
            $multiplier           = min(
                (int) round(self::LOCKOUT_SECONDS * (2 ** ($state['lockouts'] - 1))),
                self::MAX_LOCKOUT_SECONDS
            );
            $state['locked_until'] = time() + $multiplier;
            $state['attempts']     = 0; // reset so the counter is fresh post-lockout
        }

        $this->writeState($ip, $state);
        $this->maybeCleanup();
    }

    /**
     * Clear the failure record for the current IP on a successful login.
     */
    public function recordSuccess(): void
    {
        $this->deleteState($this->currentIp());
    }

    /**
     * Human-readable time remaining string for display in the lockout message.
     * e.g. "14 minutes" or "1 hour 3 minutes".
     */
    public function humanTimeRemaining(): string
    {
        $secs = $this->secondsRemaining();
        if ($secs <= 0) {
            return '0 seconds';
        }

        $hours   = (int) floor($secs / 3600);
        $minutes = (int) floor(($secs % 3600) / 60);
        $seconds = $secs % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
        }
        if ($hours === 0 && $seconds > 0) {
            $parts[] = $seconds . ' ' . ($seconds === 1 ? 'second' : 'seconds');
        }

        return implode(' ', $parts);
    }

    // ── private helpers ─────────────────────────────────────────────────────

    /**
     * Returns the sanitised client IP address.
     * Uses REMOTE_ADDR only — never trusts X-Forwarded-For, which is
     * trivially spoofable and would let an attacker bypass rate limiting.
     */
    private function currentIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // Validate; fall back to a safe string if the value is garbage
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '0.0.0.0';
        }
        return $ip;
    }

    /**
     * Derive a safe filename from an IP address.
     * md5() is fine here — we are not using it for security, just for a
     * compact, filesystem-safe key.
     */
    private function lockFile(string $ip): string
    {
        return $this->storeDir . '/fu_' . md5($ip) . '.json';
    }

    /**
     * @return array{attempts: int, lockouts: int, locked_until: int}
     */
    private function readState(string $ip): array
    {
        $default = ['attempts' => 0, 'lockouts' => 0, 'locked_until' => 0];
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
            'attempts'     => (int) ($data['attempts']     ?? 0),
            'lockouts'     => (int) ($data['lockouts']     ?? 0),
            'locked_until' => (int) ($data['locked_until'] ?? 0),
        ];
    }

    /**
     * @param array{attempts: int, lockouts: int, locked_until: int} $state
     */
    private function writeState(string $ip, array $state): void
    {
        $file = $this->lockFile($ip);
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json !== false) {
            // LOCK_EX prevents a race condition where two simultaneous failed
            // logins from the same IP both read state 0 and both write state 1.
            file_put_contents($file, $json, LOCK_EX);
        }
    }

    private function deleteState(string $ip): void
    {
        $file = $this->lockFile($ip);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Remove lock files older than MAX_LOCKOUT_SECONDS.
     * Runs at most once per CLEANUP_INTERVAL to avoid stat() overhead on
     * every request.
     */
    private function maybeCleanup(): void
    {
        $marker = $this->storeDir . '/.last_cleanup';
        $now    = time();

        if (is_file($marker) && ($now - (int) file_get_contents($marker)) < self::CLEANUP_INTERVAL) {
            return;
        }

        file_put_contents($marker, (string) $now, LOCK_EX);

        $maxAge = self::MAX_LOCKOUT_SECONDS + 3600; // 1 h grace period
        foreach (glob($this->storeDir . '/fu_*.json') ?: [] as $file) {
            if (is_file($file) && ($now - (int) filemtime($file)) > $maxAge) {
                unlink($file);
            }
        }
    }

    /**
     * Create the store directory if it does not exist, and drop a protective
     * .htaccess in it so Apache does not serve the JSON files directly.
     */
    private function ensureStoreDir(): void
    {
        if (!is_dir($this->storeDir)) {
            // 0750: owner rwx, group r-x, world none
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
