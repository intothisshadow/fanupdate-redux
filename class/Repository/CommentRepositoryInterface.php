<?php

/*****************************************************************************
 * FanUpdate — CommentRepositoryInterface
 *
 * Data-access contract for blog comments. CommentService (see
 * class/Service/CommentService.php) depends on this interface rather than
 * the concrete CommentRepository, so unit tests can substitute a test
 * double without touching SqlConnection or a real database connection. No
 * business rules or presentation formatting belong here — this is pure
 * data access. Building a FanUpdate_Comment domain object for display
 * (e.g. an abstract/gravatar) stays in the caller.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface CommentRepositoryInterface
{
    /**
     * @return array<string, mixed>|null The comment row, or null if it doesn't exist.
     */
    public function findById(int $commentId): ?array;

    /**
     * @param array{name:string,email:string,url:string,comment:string,entry_id:int,date:string,approved:int} $data
     */
    public function insert(array $data): bool;

    /**
     * @param array{name:string,email:string,url:string,comment:string,entry_id:int,date:string,approved:int} $data
     */
    public function update(int $commentId, array $data): bool;

    public function delete(int $commentId): bool;

    /**
     * Run a filtered, paginated comment listing (joined against the blog
     * table for each comment's entry title) on the underlying
     * SqlConnection. Results are read afterwards via the connection's
     * existing ReadRecordPaginate()/PrintPaginate()/total_results cursor —
     * the same pagination pattern BlogRepository::search() uses — rather
     * than being returned directly, so callers don't have to change how
     * they iterate paginated results.
     *
     * @param 'pending'|'spam'|'' $show
     */
    public function search(string $show, string $searchTerm, int $perPage): void;
}
