<?php

/*****************************************************************************
 * FanUpdate — SmilieRepositoryInterface
 *
 * Data-access contract for smilies. SmilieService (see
 * class/Service/SmilieService.php) depends on this interface rather than
 * the concrete SmilieRepository, so unit tests can substitute a test
 * double without touching SqlConnection or a real database connection. No
 * business rules or presentation formatting belong here — this is pure
 * data access. Rendering a smiley as an <img> tag (makeSmiley()) and
 * listing available image files (printSmileyImgDropdown()) stay on
 * FanUpdate — those are presentation helpers, not data access.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface SmilieRepositoryInterface
{
    public function insert(string $smiley, string $image): bool;

    public function update(string $smiley, string $image): bool;

    public function delete(string $smiley): bool;

    /**
     * Every smiley row, in table order (no ORDER BY in the original code).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array;
}
