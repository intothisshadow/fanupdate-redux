<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — ReleaseInfo
 *
 * Immutable value object describing a single release, normalised into a
 * provider-agnostic shape. GitHubUpdateProvider is the only implementation
 * that produces these today, but UpdateManager and admin/update.php only
 * ever see this shape — a future GitLab/self-hosted provider builds the
 * same object and nothing above it needs to change.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class ReleaseInfo
{
    public function __construct(
        /** Normalised version number, no leading 'v' (e.g. "2.2.0"). */
        public readonly string $version,
        /** Release title as set on the provider (falls back to the tag). */
        public readonly string $name,
        /** Raw release notes body — markdown or plain text, not yet sanitised for output. */
        public readonly string $notes,
        /** HTML URL of the release page. */
        public readonly string $url,
        /** ISO 8601 UTC publish timestamp. */
        public readonly string $publishedAt,
        public readonly bool $prerelease,
        /** ZIP asset download URL, if the release has one. */
        public readonly ?string $downloadUrl,
        /** ZIP asset size in bytes, if known. */
        public readonly ?int $downloadSize,
        /**
         * Published SHA-256 checksum for the ZIP asset (lowercase hex), if the
         * release has a matching `.sha256`/`sha256sums.txt`/`checksums.txt`
         * asset. Null when none was found (normal for older releases).
         * Distinct from DownloadResult::$sha256, which is computed from the
         * downloaded file.
         */
        public readonly ?string $expectedSha256 = null,
    ) {
    }
}
