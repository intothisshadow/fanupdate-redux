<?php

/*****************************************************************************
 * FanUpdate — BlacklistService
 *
 * Business logic for managing the comment-spam blacklist: checking for an
 * existing word before adding one, adding/deleting a word, and listing
 * every blacklisted word. Depends on BlacklistRepositoryInterface
 * (constructor injection), not a concrete repository or the FanUpdate
 * singleton, so it can be unit tested with a fake/mock repository.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class BlacklistService
{
    public function __construct(
        private readonly BlacklistRepositoryInterface $blacklist,
    ) {
    }

    public function wordExists(string $word): bool
    {
        return $this->blacklist->exists($word);
    }

    public function addWord(string $word): bool
    {
        return $this->blacklist->insert($word);
    }

    public function deleteWord(string $word): bool
    {
        return $this->blacklist->delete($word);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->blacklist->listAll();
    }
}
