<?php

/*****************************************************************************
 * FanUpdate — SmilieService
 *
 * Business logic for managing smilies: adding a smiley, bulk-updating
 * smiley images, deleting a smiley, and listing every smiley. Depends on
 * SmilieRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class SmilieService
{
    public function __construct(
        private readonly SmilieRepositoryInterface $smilies,
    ) {
    }

    public function addSmiley(string $smiley, string $image): bool
    {
        return $this->smilies->insert($smiley, $image);
    }

    public function updateSmiley(string $smiley, string $image): bool
    {
        return $this->smilies->update($smiley, $image);
    }

    public function deleteSmiley(string $smiley): bool
    {
        return $this->smilies->delete($smiley);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->smilies->listAll();
    }
}
