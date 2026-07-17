<?php

/*****************************************************************************
 * FanUpdate — BlacklistRepositoryInterface
 *
 * Data-access contract for the comment-spam blacklist. BlacklistService
 * (see class/Service/BlacklistService.php) depends on this interface
 * rather than the concrete BlacklistRepository, so unit tests can
 * substitute a test double without touching SqlConnection or a real
 * database connection. No business rules or presentation formatting
 * belong here — this is pure data access.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface BlacklistRepositoryInterface
{
    /**
     * Whether $word is already present in the blacklist.
     */
    public function exists(string $word): bool;

    public function insert(string $word): bool;

    public function delete(string $word): bool;

    /**
     * Every blacklisted word, ordered alphabetically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array;
}
