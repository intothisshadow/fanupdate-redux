<?php

/*****************************************************************************
 * FanUpdate — MediaService
 *
 * Business logic for the Media gallery (TODO.md item "4. UI - Create an
 * Image/File Manager"): recording a new upload, listing/paginating existing
 * images, deleting an image (file + thumbnail + DB row), and shaping a raw
 * DB row into the display-ready array admin/media.php and the WYSIWYG
 * "choose existing image" picker (assets/js/fanupdate-admin.js) both
 * consume. Depends on MediaRepositoryInterface (constructor injection), not
 * a concrete repository or the FanUpdate singleton, so it can be unit
 * tested with a fake/mock repository — same pattern as every other Service
 * class in this codebase.
 *
 * installPath/installUrl are injected rather than read from
 * FanUpdate::getOpt() internally, for the same reason: no dependency on the
 * FanUpdate singleton. Stored file_path/thumb_path are relative to the
 * uploads/ directory (e.g. "images/2026/07/abc_photo.jpg", matching
 * admin/upload.php's existing directory layout), not absolute filesystem
 * paths or URLs — this keeps the table valid if install_path/install_url
 * ever change, and is why every filesystem/URL operation below joins them
 * with installPath/installUrl rather than storing the joined result.
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
     * Record a newly uploaded image. $relFilePath/$relThumbPath are paths
     * relative to the uploads/ directory (no leading slash), e.g.
     * "images/2026/07/abc_photo.jpg". Returns the new media_id, or 0 if the
     * insert failed — non-fatal for the caller (admin/upload.php): the file
     * itself is already saved and usable, it just won't appear in the
     * gallery.
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
     * Executes the paginated listing query. Callers read rows back via
     * SqlConnection::ReadRecordPaginate() on the same connection the
     * repository was constructed with (see MediaRepositoryInterface's
     * docblock) — same convention as BlogService::search()/admin/blog.php.
     */
    public function listPage(int $perPage): void
    {
        $this->media->listPage($perPage);
    }

    /**
     * Display-ready DTO for a single image, or null if it no longer exists
     * — used by admin/media.php's delete-confirmation view.
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
     * Delete an image: best-effort removal of the original file and its
     * thumbnail from disk, then the DB row. A missing file on disk (already
     * deleted manually, or never fully written) does not block the DB row
     * from being removed — the row is what the gallery and the WYSIWYG
     * picker actually list from. Returns false only when the DB row itself
     * could not be found or deleted.
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
     * Shape a raw media_table row into the display-ready array
     * admin/media.php's gallery and the WYSIWYG picker's JSON response
     * both consume.
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
     * Human-readable file size, e.g. "1.23 MB". Formats a byte count
     * already on hand (from the DB row) rather than FileHandler::
     * getHumanSize(), which re-stats a path on disk — unnecessary here and
     * wrong for a row whose file no longer exists.
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
