<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — SystemStatusCheck
 *
 * Immutable value object describing the result of a single System Status
 * diagnostic (see SystemStatusChecker). $status is one of 'pass',
 * 'warning', or 'error' — never any other value, since SystemStatusChecker
 * is the only producer of these.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class SystemStatusCheck
{
    public function __construct(
        /** Short human-readable label, e.g. "PHP version". */
        public readonly string $name,
        /** 'pass' | 'warning' | 'error'. */
        public readonly string $status,
        /** Human-readable detail explaining the status. */
        public readonly string $message,
    ) {
    }
}
