<?php

/*****************************************************************************
 * FileHandler
 *
 * Copyright (c) Jenny Ferenc <jenny@prism-perfect.net>
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 * Copyright (c) 2026 refactored for PHP 8.2+ compatibility
 *
 * File/filename utility helpers: extension and MIME-type lookup, filename
 * sanitisation, image dimensions, human-readable file size, and
 * allowed-extension checks.
 *
 * Security note: no eval(), exec(), shell_exec(), system(), passthru(),
 * popen(), proc_open(), or base64_decode() obfuscation is present in this
 * file.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 ******************************************************************************/

declare(strict_types=1);

class FileHandler
{
    // ── explicitly declared property (PHP 8.2: no dynamic properties) ───────
    public array $_allowed_ext = [];

    // ── constructor ─────────────────────────────────────────────────────────

    public function __construct(array $allowed_ext = [])
    {
        if (!empty($allowed_ext)) {
            $this->_allowed_ext = $allowed_ext;
        }
    }

    // ── extension helpers ────────────────────────────────────────────────────

    public function getExtension(string $filename): string
    {
        return (strpos($filename, '.') === false)
            ? ''
            : strtolower(substr((string) strrchr($filename, '.'), 1));
    }

    // ── MIME type ────────────────────────────────────────────────────────────

    public function getMimeType(string $filename): string
    {
        $file_extension = $this->getExtension($filename);

        return match ($file_extension) {
            'mp3'                     => 'audio/mpeg',
            'm4a'                     => 'audio/x-m4a',
            'mp4'                     => 'video/mp4',
            'm4v'                     => 'video/x-m4v',
            'mov'                     => 'video/quicktime',
            'avi'                     => 'video/x-msvideo',
            'rtf'                     => 'application/rtf',
            'pdf'                     => 'application/pdf',
            'exe'                     => 'application/octet-stream',
            'zip'                     => 'application/x-zip-compressed',
            'doc'                     => 'application/msword',
            'xls'                     => 'application/vnd.ms-excel',
            'ppt'                     => 'application/vnd.ms-powerpoint',
            'gif'                     => 'image/gif',
            'png'                     => 'image/png',
            'jpe', 'jpeg', 'jpg'      => 'image/jpeg',
            default                   => 'application/force-download',
        };
    }

    // ── filename sanitisation ────────────────────────────────────────────────

    /**
     * Strip unsafe characters from a filename.
     * Spaces become underscores; anything outside [-_.0-9a-zA-Z] is removed.
     */
    public function fixFilename(string $orig_name): string
    {
        $orig_name = str_replace(' ', '_', $orig_name);
        return (string) preg_replace('/[^-_.0-9a-zA-Z]/', '', $orig_name);
    }

    // ── image / file info ────────────────────────────────────────────────────

    /**
     * Return image dimensions as a "WxH" string, e.g. "800x600".
     * Returns an empty string if getimagesize() fails.
     */
    public function getDimensions(string $path): string
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return '';
        }
        return $info[0] . 'x' . $info[1];
    }

    /**
     * Return raw file size in bytes, or 0 if the file does not exist.
     */
    public function getSize(string $path): int
    {
        $size = @filesize($path);
        return ($size !== false) ? $size : 0;
    }

    /**
     * Return a human-readable file size string, e.g. "1.23 MB".
     * Returns "File Not Found" if the path does not exist.
     */
    public function getHumanSize(string $path): string
    {
        if (!file_exists($path)) {
            return 'File Not Found';
        }

        $size = $this->getSize($path);

        if ($size <= 0) {
            return '0 Bytes';
        }

        $units = [' Bytes', ' KB', ' MB', ' GB', ' TB', ' PB', ' EB', ' ZB', ' YB'];
        $i     = (int) floor(log($size, 1024));

        return round($size / (1024 ** $i), 2) . $units[$i];
    }

    // ── extension check ──────────────────────────────────────────────────────

    /**
     * Return true if the file's extension is in the allowed-extensions list.
     */
    public function checkExtension(string $filename): bool
    {
        $ext = $this->getExtension($filename);

        foreach ($this->_allowed_ext as $allowed) {
            if ($ext === (string) $allowed) {
                return true;
            }
        }

        return false;
    }
}
