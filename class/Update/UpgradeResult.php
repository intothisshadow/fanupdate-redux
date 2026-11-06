<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — UpgradeResult
 *
 * Immutable value object describing the outcome of an UpgradeRunner run.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class UpgradeResult
{
    /** @param array<int, string> $messages */
    public function __construct(
        /** The version migrations ran *from* — 'unknown' if `_db_version` was never set. */
        public readonly string $fromVersion,
        /** The version migrations ran *to*. */
        public readonly string $toVersion,
        /** Human-readable description of each migration actually applied — empty when nothing was pending. */
        public readonly array $messages,
        /** ISO 8601 UTC timestamp the run completed at. */
        public readonly string $ranAt,
    ) {
    }
}
