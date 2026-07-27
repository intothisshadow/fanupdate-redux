<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — DownloadResult
 *
 * Immutable value object describing a successfully downloaded and validated
 * release archive. Produced only by DownloadManager::download() — its mere
 * existence means the ZIP was fully written to disk, its size matched what
 * the provider reported (within tolerance, when known), and it passed a ZIP
 * integrity check. Consumed by UpdateManager, which persists these fields
 * into the cached update state, and later by the Installation Engine
 * (see TODO.md, not yet built).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class DownloadResult
{
    public function __construct(
        /** Absolute path to the downloaded ZIP file on disk. */
        public readonly string $filePath,
        /** Actual size of the downloaded file in bytes. */
        public readonly int $size,
        /** SHA-256 hex digest of the downloaded file, for later verification/logging. */
        public readonly string $sha256,
        /**
         * FP-17: true when the release published a checksum and $sha256
         * matched it. False means no checksum was published for this
         * release to verify against — never false due to a mismatch, since
         * DownloadManager::download() throws instead of returning a result
         * in that case.
         */
        public readonly bool $checksumVerified = false,
    ) {
    }
}
