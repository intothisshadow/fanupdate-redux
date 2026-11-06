<?php

/*****************************************************************************
 * FanUpdate — MediaService
 *
 * Business logic for the Media gallery: recording uploads, listing and
 * paginating images, deleting (file + thumbnail + row), and shaping rows for
 * admin/media.php and the WYSIWYG image picker.
 *
 * Depends on MediaRepositoryInterface and an injected installPath/installUrl
 * (not the FanUpdate singleton), so it is unit-testable. Stored
 * file_path/thumb_path are relative to uploads/, so the table stays valid if
 * install_path/install_url change; they are joined at use, never stored joined.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class MediaService
{
    public function __construct(
        private readonly MediaRepositoryInterface $media,
        private readonly string $installPath,
        private readonly string $installUrl,
    ) {
    }

    /**
     * Records an uploaded image; paths are relative to uploads/ (no leading
     * slash). Returns the new media_id, or 0 on failure, which is non-fatal:
     * the file is saved and just won't show in the gallery.
     */
    public function recordUpload(
        string $relFilePath,
        string $relThumbPath,
        string $filename,
        int $width,
        int $height,
        int $size,
        string $mimeType,
    ): int {
        return $this->media->insert([
            'filename'   => $filename,
            'filePath'   => $relFilePath,
            'thumbPath'  => $relThumbPath,
            'width'      => $width,
            'height'     => $height,
            'size'       => $size,
            'mimeType'   => $mimeType,
            'uploadedAt' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Executes the paginated listing query; callers read rows back with
     * SqlConnection::ReadRecordPaginate() (as with BlogService::search()).
     */
    public function listPage(int $perPage): void
    {
        $this->media->listPage($perPage);
    }

    /**
     * Display-ready DTO for one image, or null if it no longer exists.
     *
     * @return array{id: int, url: string, thumbUrl: string, filename: string,
     *               width: int, height: int, size: int, humanSize: string,
     *               uploadedAt: string}|null
     */
    public function findDto(int $id): ?array
    {
        $row = $this->media->findById($id);

        return $row === null ? null : $this->rowToDto($row);
    }

    /**
     * Deletes an image: best-effort file and thumbnail removal, then the row.
     * A missing file doesn't block row deletion, since the row is what the
     * gallery lists. False only if the row couldn't be found or deleted.
     */
    public function deleteImage(int $id): bool
    {
        $row = $this->media->findById($id);
        if ($row === null) {
            return false;
        }

        foreach ([$row['file_path'], $row['thumb_path']] as $relPath) {
            $absPath = $this->resolveUploadPath((string) $relPath);
            if ($absPath !== null && is_file($absPath)) {
                unlink($absPath);
            }
        }

        return $this->media->deleteById($id);
    }

    /**
     * Absolute path for an uploads/-relative path from the database, or null
     * if it could escape uploads/ (traversal segments, absolute or Windows
     * paths, NUL bytes). The DB is trusted less than code, so a tampered row
     * must never turn a delete into an arbitrary-file delete.
     */
    private function resolveUploadPath(string $relPath): ?string
    {
        if ($relPath === '' || str_contains($relPath, "\0") || str_contains($relPath, '\\') || str_starts_with($relPath, '/')) {
            return null;
        }

        foreach (explode('/', $relPath) as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                return null;
            }
        }

        return rtrim($this->installPath, '/') . '/uploads/' . $relPath;
    }

    /**
     * Delete several images, skipping any that no longer exist.
     *
     * @param int[] $ids
     * @return int Number of images actually deleted.
     */
    public function deleteImages(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->deleteImage((int) $id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * DTOs for the bulk delete-confirmation view, in the order given.
     * Ids that no longer exist are omitted.
     *
     * @param int[] $ids
     * @return list<array{id: int, url: string, thumbUrl: string, filename: string,
     *                    width: int, height: int, size: int, humanSize: string,
     *                    uploadedAt: string}>
     */
    public function getBulkDeleteConfirmation(array $ids): array
    {
        $dtos = [];
        foreach ($ids as $id) {
            $dto = $this->findDto((int) $id);
            if ($dto !== null) {
                $dtos[] = $dto;
            }
        }

        return $dtos;
    }

    /**
     * Shapes a raw media_table row for the gallery and the picker's JSON.
     *
     * @param array{media_id: int, filename: string, file_path: string,
     *              thumb_path: string, width: int, height: int, size: int,
     *              mime_type: string, uploaded_at: string} $row
     * @return array{id: int, url: string, thumbUrl: string, filename: string,
     *               width: int, height: int, size: int, humanSize: string,
     *               uploadedAt: string}
     */
    public function rowToDto(array $row): array
    {
        $uploadsUrl = rtrim($this->installUrl, '/') . '/uploads/';

        return [
            'id'         => (int) $row['media_id'],
            'url'        => $uploadsUrl . $row['file_path'],
            'thumbUrl'   => $uploadsUrl . $row['thumb_path'],
            'filename'   => (string) $row['filename'],
            'width'      => (int) $row['width'],
            'height'     => (int) $row['height'],
            'size'       => (int) $row['size'],
            'humanSize'  => $this->formatBytes((int) $row['size']),
            'uploadedAt' => (string) $row['uploaded_at'],
        ];
    }

    /**
     * Human-readable size, e.g. "1.23 MB", from the row's byte count (unlike
     * FileHandler::getHumanSize(), which stats the disk and fails for a
     * missing file).
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 Bytes';
        }

        $units = [' Bytes', ' KB', ' MB', ' GB', ' TB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2) . $units[$i];
    }
}
