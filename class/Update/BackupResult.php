<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — BackupResult
 *
 * Immutable value object describing a single backup archive — either one
 * just created by BackupManager::createBackup() (fileCount populated from
 * the archive actually written) or one discovered on disk by
 * BackupManager::listBackups() (fileCount left null; re-opening every
 * archive just to count entries would be wasteful for a page that only
 * needs to list what already exists).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class BackupResult
{
    public function __construct(
        /** Absolute path to the backup ZIP file on disk. */
        public readonly string $filePath,
        /** Size of the backup file in bytes. */
        public readonly int $size,
        /** Number of files the archive contains, when known. */
        public readonly ?int $fileCount,
        /** ISO 8601 UTC timestamp the backup was created at. */
        public readonly string $createdAt,
        /** The installed FanUpdate Redux version this backup was taken from. */
        public readonly string $version,
    ) {
    }
}
