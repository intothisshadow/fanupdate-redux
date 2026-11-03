<?php

/*****************************************************************************
 * FanUpdate — InstallDetector
 *
 * Decides whether a visitor should be sent to install.php. Deliberately
 * dependency-free (no FanUpdate singleton, no functions.php) because it runs
 * from index.php/admin/index.php before blog-config.php is guaranteed to
 * exist, and functions.php cannot be loaded without it.
 *
 * Healthy installs pay nothing: install.php deletes itself after a
 * successful install, so with the file gone this returns false after a
 * single is_file() and never touches the database.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

final class InstallDetector
{
    /** @var \Closure(array<string, mixed>): bool */
    private readonly \Closure $optionsTableProbe;

    /**
     * @param (\Closure(array<string, mixed>): bool)|null $optionsTableProbe
     *        Returns whether the options table exists for the given
     *        blog-config.php values. Defaults to a real database check.
     */
    public function __construct(?\Closure $optionsTableProbe = null)
    {
        $this->optionsTableProbe = $optionsTableProbe ?? $this->probeDatabase(...);
    }

    /**
     * @param array<string, mixed>|null $config The $fanupdate array from
     *        blog-config.php, or null when that file does not exist.
     */
    public function needsInstaller(string $rootDir, ?array $config): bool
    {
        if (!is_file(rtrim($rootDir, '/') . '/install.php')) {
            return false;
        }

        if ($config === null) {
            return true;
        }

        return !($this->optionsTableProbe)($config);
    }

    /**
     * A connection failure counts as "not installed": the installer is where
     * bad database credentials get reported properly.
     *
     * @param array<string, mixed> $config
     */
    private function probeDatabase(array $config): bool
    {
        $host = (string) ($config['dbhost'] ?? '');
        $port = '';
        if (str_contains($host, ':')) {
            [$host, $maybePort] = explode(':', $host, 2);
            if (ctype_digit($maybePort)) {
                $port = ';port=' . $maybePort;
            }
        }

        try {
            $pdo = new \PDO(
                'mysql:host=' . $host . $port . ';dbname=' . (string) ($config['dbname'] ?? '') . ';charset=utf8mb4',
                (string) ($config['dbuser'] ?? ''),
                (string) ($config['dbpass'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([(string) ($config['options_table'] ?? 'blog_options')]);

            return $stmt->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        }
    }
}
