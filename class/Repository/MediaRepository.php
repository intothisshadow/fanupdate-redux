<?php

/*****************************************************************************
 * FanUpdate — MediaRepository
 *
 * Data access for the Media gallery table (media_table). The table name is
 * injected via the constructor rather than read from FanUpdate::getOpt()
 * internally, so this class has no dependency on the FanUpdate singleton
 * and can be constructed with a mock SqlConnection in a unit test — same
 * pattern as every other Repository class in this codebase.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

declare(strict_types=1);

class MediaRepository implements MediaRepositoryInterface
{
    public function __construct(
        private readonly SqlConnection $db,
        private readonly string $mediaTable,
    ) {
    }

    public function insert(array $data): int
    {
        $ok = $this->db->Execute(
            'INSERT INTO ' . $this->mediaTable . '
                (filename, file_path, thumb_path, width, height, size, mime_type, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'Could not execute query.',
            [
                (string) $data['filename'],
                (string) $data['filePath'],
                (string) $data['thumbPath'],
                (int) $data['width'],
                (int) $data['height'],
                (int) $data['size'],
                (string) $data['mimeType'],
                (string) $data['uploadedAt'],
            ]
        );

        return $ok ? $this->db->GetLastSequence() : 0;
    }

    public function findById(int $id): ?array
    {
        $this->db->Execute(
            'SELECT * FROM ' . $this->mediaTable . ' WHERE media_id = ? LIMIT 1',
            'Could not execute query.',
            [$id]
        );
        $row = $this->db->ReadRecord();
        $this->db->FreeResult();

        return $row === false ? null : $row;
    }

    public function deleteById(int $id): bool
    {
        return $this->db->Execute(
            'DELETE FROM ' . $this->mediaTable . ' WHERE media_id = ?',
            'Could not execute query.',
            [$id]
        );
    }

    public function listPage(int $perPage): void
    {
        $this->db->FreeResult();
        $this->db->ExecutePaginate(
            'SELECT * FROM ' . $this->mediaTable . ' ORDER BY uploaded_at DESC, media_id DESC',
            $perPage
        );
    }
}
