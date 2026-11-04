<?php

declare(strict_types=1);

/*****************************************************************************
 * FanUpdate — ImageUploadValidator
 *
 * Decides whether an uploaded temp file is a supported image, independent of
 * the client-supplied name or Content-Type. Checks, in order: the MIME type
 * sniffed from the file's bytes (finfo), a getimagesize() header parse, a
 * pixel-count cap (decompression-bomb guard) and a real GD decode. The
 * decode step rejects polyglots such as "GIF89a<?php ...", which pass the
 * first two checks on their header bytes alone.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 ******************************************************************************/

class ImageUploadValidator
{
    /** Sniffed MIME type => extension applied to the stored file. */
    public const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /** Largest accepted width × height; bounds the memory GD needs to decode. */
    public const MAX_PIXELS = 50_000_000;

    /**
     * @return array{mime: string, ext: string, width: int, height: int}
     * @throws InvalidArgumentException With a message safe to show the admin.
     */
    public function validate(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('File does not appear to be a valid image.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === false || !isset(self::ALLOWED_TYPES[$mime])) {
            throw new InvalidArgumentException('File type not allowed. Accepted: JPEG, PNG, GIF, WebP, AVIF.');
        }

        $info = getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidArgumentException('File does not appear to be a valid image.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Image dimensions are too large.');
        }

        if (!$this->canDecode($mime)) {
            throw new InvalidArgumentException('This server cannot process ' . strtoupper(self::ALLOWED_TYPES[$mime]) . ' images.');
        }

        $image = $this->decode($path);
        if ($image === false) {
            throw new InvalidArgumentException('File does not appear to be a valid image.');
        }

        return ['mime' => $mime, 'ext' => self::ALLOWED_TYPES[$mime], 'width' => $width, 'height' => $height];
    }

    /** Whether the GD build can both read and write thumbnails for this type. */
    public function canDecode(string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'image/png'  => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            'image/gif'  => function_exists('imagecreatefromgif') && function_exists('imagegif'),
            'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            'image/avif' => function_exists('imagecreatefromavif') && function_exists('imageavif'),
            default      => false,
        };
    }

    private function decode(string $path): \GdImage|false
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return false;
        }

        // GD reports a malformed image as a warning; the false return is what we act on.
        set_error_handler(static fn (): bool => true);
        try {
            return imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
    }
}
