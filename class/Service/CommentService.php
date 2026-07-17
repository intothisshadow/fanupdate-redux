<?php

/*****************************************************************************
 * FanUpdate — CommentService
 *
 * Business logic for managing comments: adding/updating/deleting a comment
 * and running the filtered/paginated listing. Depends on
 * CommentRepositoryInterface (constructor injection), not a concrete
 * repository or the FanUpdate singleton, so it can be unit tested with a
 * fake/mock repository. Deliberately does NOT construct FanUpdate_Comment
 * domain objects (abstracts, gravatars, formatted dates) — that
 * presentation logic depends on FanUpdate/blog_page context and stays in
 * the admin page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class CommentService
{
    public function __construct(
        private readonly CommentRepositoryInterface $comments,
    ) {
    }

    /**
     * @param array{name:string,email:string,url:string,comment:string,entry_id:int,date:string,approved:int} $clean
     */
    public function addComment(array $clean): bool
    {
        return $this->comments->insert($clean);
    }

    /**
     * @param array{name:string,email:string,url:string,comment:string,entry_id:int,date:string,approved:int} $clean
     */
    public function updateComment(int $commentId, array $clean): bool
    {
        return $this->comments->update($commentId, $clean);
    }

    public function deleteComment(int $commentId): bool
    {
        return $this->comments->delete($commentId);
    }

    /**
     * @return array<string, mixed>|null The comment row, or null when it doesn't exist.
     */
    public function getCommentForEdit(int $commentId): ?array
    {
        return $this->comments->findById($commentId);
    }

    /**
     * @return array<string, mixed>|null The comment row, or null when it doesn't exist.
     */
    public function getDeleteConfirmation(int $commentId): ?array
    {
        return $this->comments->findById($commentId);
    }

    /**
     * Run a filtered, paginated comment listing. Results are read
     * afterwards via the shared SqlConnection's ReadRecordPaginate()/
     * PrintPaginate() cursor, exactly as before this refactor — only the
     * query construction moved into the repository.
     *
     * @param 'pending'|'spam'|'' $show
     */
    public function search(string $show, string $searchTerm, int $perPage): void
    {
        $this->comments->search($show, $searchTerm, $perPage);
    }
}
