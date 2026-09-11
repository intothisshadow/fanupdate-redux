<?php
declare(strict_types=1);
/*****************************************************************************
 * CaptchaSecurityImages
 * Copyright (c) 2006 by Simon Jarvis
 * Copyright (c) 2007 by Jenny Ferenc (contributor)
 * Copyright (c) 2020 by Ekaterina (contributor) http://scripts.robotess.net
 *
 * Generates a CAPTCHA image and stores the expected code in the session.
 * Session cookie params (Secure + SameSite=Lax) must match process.php so
 * both requests share the same session over HTTPS.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/gpl-3.0.html>.
 ******************************************************************************/

// Set Secure + SameSite=Lax before session_start() so the PHPSESSID cookie
// is transmitted correctly over HTTPS. Must match the params used in
// process.php; otherwise the two requests may create separate sessions and
// the security_code comparison will always fail.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

class CaptchaSecurityImages
{
    // Explicit property declaration (PHP 8.2: no dynamic properties).
    // Uses __DIR__ so the path resolves correctly regardless of the working
    // directory of the calling script.
    public string $font;

    public function __construct(int $width = 130, int $height = 40, int $characters = 6)
    {
        // Font lives in assets/fonts/ relative to the fanupdate root.
        // captcha.php is in the root, so __DIR__ points at the root.
        $this->font = __DIR__ . '/assets/fonts/VeraSe.ttf';

        $code = $this->generateCode($characters);

        // Store the code in the session before any image output so it is
        // always set even if execution is interrupted mid-image.
        $_SESSION['security_code'] = $code;

        // font size will be 60% of the image height
        $font_size = $height * 0.6;
        $image = @imagecreate($width, $height)
            or die('Cannot initialize new GD image stream');

        // set the colours
        $background_color = imagecolorallocate($image, 255, 255, 255);
        $text_color       = imagecolorallocate($image, 20, 40, 100);
        $noise_color      = imagecolorallocate($image, 100, 120, 180);

        // generate random dots in background
        $dot_count = (int) (($width * $height) / 3);
        for ($i = 0; $i < $dot_count; $i++) {
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                1,
                1,
                $noise_color
            );
        }

        // generate random lines in background
        $line_count = (int) (($width * $height) / 150);
        for ($i = 0; $i < $line_count; $i++) {
            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $noise_color
            );
        }

        // create textbox and add text
        $textbox = imagettfbbox($font_size, 0, $this->font, $code)
            or die('Error in imagettfbbox function');

        $x = ($width  - $textbox[4]) / 2;
        $y = ($height - $textbox[5]) / 2;

        imagettftext($image, $font_size, 0, (int) $x, (int) $y, $text_color, $this->font, $code)
            or die('Error in imagettftext function');

        // output captcha image to browser
        header('Content-Type: image/jpeg');
        imagejpeg($image);
    }

    public function generateCode(int $characters): string
    {
        /* list all possible characters; similar-looking chars and vowels removed */
        $possible = '23456789bcdfghjkmnpqrstvwxyz';
        $len      = strlen($possible) - 1;
        $code     = '';

        for ($i = 0; $i < $characters; $i++) {
            $code .= $possible[random_int(0, $len)];
        }

        return $code;
    }
}

$captcha = new CaptchaSecurityImages();
