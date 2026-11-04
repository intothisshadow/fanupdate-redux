<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — CollectiveScriptNormalizer
 *
 * Converts the legacy single-letter collective_script shorthand ('n', 'e')
 * to the descriptive identifier stored today ('none', 'enthusiast').
 * A custom identifier with no shorthand passes through lower-cased/trimmed.
 * Standalone so UpgradeRunner, install.php and the admin pages don't need a
 * FanUpdate instance for a pure string transform.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

final class CollectiveScriptNormalizer
{
    public function normalize(string $raw): string
    {
        return match (strtolower(trim($raw))) {
            '', 'n', 'no', 'none' => 'none',
            'e', 'enthusiast'     => 'enthusiast',
            'f', 'fanatically'    => 'fanatically',
            'l', 'listingadmin'   => 'listingadmin',
            default               => strtolower(trim($raw)),
        };
    }
}
