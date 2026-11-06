<?php

/*****************************************************************************
 * FanUpdate — MediaRepositoryInterface
 *
 * Data-access contract for the Media gallery: one row per image uploaded
 * through admin/upload.php. MediaService depends on this interface so tests
 * can use a double. Pure data access (no business rules, URLs or
 * filesystem); stored paths are relative to uploads/.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

interface MediaRepositoryInterface
{
    /**
     * Insert one row and return its new media_id, or 0 on failure.
     *
     * @param array{filename: string, filePath: string, thumbPath: string,
     *              width: int, height: int, size: int, mimeType: string,
     *              uploadedAt: string} $data
     */
    public function insert(array $data): int;

    /**
     * @return array{media_id: int, filename: string, file_path: string,
     *               thumb_path: string, width: int, height: int, size: int,
     *               mime_type: string, uploaded_at: string}|null
     */
    public function findById(int $id): ?array;

    public function deleteById(int $id): bool;

    /**
     * Execute a paginated "every image, newest first" query via
     * SqlConnection::ExecutePaginate() — same pagination convention as
     * BlogRepository::search() (reads $_GET['p'], populates
     * SqlConnection::$total_results/PrintPaginate()). Callers read rows
     * back via SqlConnection::ReadRecordPaginate(), not a return value.
     */
    public function listPage(int $perPage): void;
}
