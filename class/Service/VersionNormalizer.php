<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — VersionNormalizer
 *
 * Reduces a version string such as FU_VERSION's "Redux 2.1.0" to a plain
 * number version_compare() understands. Injected into the services that
 * compare versions so none of them depend on another service's static API.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class VersionNormalizer
{
    /** Returns the input unchanged if no numeric version can be found. */
    public function normalize(string $raw): string
    {
        if (preg_match('/\d+(?:\.\d+){1,3}/', $raw, $m)) {
            return $m[0];
        }

        return $raw;
    }
}
