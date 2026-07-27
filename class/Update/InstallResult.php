<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — InstallResult
 *
 * Immutable value object describing a successfully completed installation.
 * Produced only by InstallManager::installUpdate() — its existence means
 * the release archive was extracted, validated, and copied over the live
 * installation without error.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class InstallResult
{
    public function __construct(
        /** The version that was installed. */
        public readonly string $version,
        /** Number of files copied into the installation directory. */
        public readonly int $fileCount,
        /** ISO 8601 UTC timestamp the install completed at. */
        public readonly string $installedAt,
        /**
         * Number of obsolete files removed (files this tool itself
         * installed previously, per the manifest, that the new release
         * no longer ships). Zero when there was no prior manifest to
         * compare against (e.g. the first install performed through the
         * Admin Update Manager) or when nothing was obsolete.
         */
        public readonly int $removedFileCount = 0,
        /**
         * Whether install.php (shipped in every release) was deleted from
         * the live installation after this install — this flow copies
         * install.php over like any other file but never executes it, so
         * its own self-delete-on-success code never runs on its own.
         * False when the release didn't ship install.php, or the delete
         * itself failed (e.g. a permissions issue) — either way, the
         * Dashboard/Updates "install.php still present" notice covers it.
         */
        public readonly bool $installScriptRemoved = false,
    ) {
    }
}
