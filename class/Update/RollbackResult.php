<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — RollbackResult
 *
 * Immutable value object describing a successfully completed rollback.
 * Produced only by RollbackManager::rollbackTo() — its existence means a
 * backup archive was extracted, validated, and copied back over the live
 * installation without error.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class RollbackResult
{
    public function __construct(
        /** Absolute path to the backup archive that was restored. */
        public readonly string $backupPath,
        /** The version label recorded on that backup (see BackupResult::$version). */
        public readonly string $version,
        /** Number of files copied back into the installation directory. */
        public readonly int $fileCount,
        /** ISO 8601 UTC timestamp the rollback completed at. */
        public readonly string $restoredAt,
    ) {
    }
}
