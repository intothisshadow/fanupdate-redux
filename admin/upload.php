<?php
/*****************************************************************************
 * FanUpdate — admin/upload.php
 *
 * Image upload endpoint for the admin WYSIWYG editor.
 *
 * Accepts a multipart POST with a single file field named "image".
 * Validates MIME type via finfo (not $_FILES['type']), sanitises the
 * filename via FileHandler::fixFilename(), moves the file into a
 * date-partitioned directory under uploads/images/YYYY/MM/, generates
 * a constrained-fit thumbnail with GD into uploads/images/YYYY/MM/thumbs/,
 * and returns a JSON response:
 *
 *   {"ok":true,"url":"https://…/uploads/images/2026/06/abc_photo.jpg",
 *              "thumb":"https://…/uploads/images/2026/06/thumbs/abc_photo.jpg",
 *              "filename":"abc_photo.jpg"}
 *
 * On any error:
 *   {"ok":false,"error":"Human-readable message"}
 *
 * Security:
 *  - Only authenticated admins can reach this endpoint (login() exits if not)
 *  - CSRF token (csrf_token field, appended to the FormData by
 *    fanupdate-admin.js) required and verified before any file is processed
 *  - MIME type validated from file bytes, not Content-Type header
 *  - PHP execution blocked in uploads/ via .htaccess
 *  - Filename sanitised: spaces→underscores, non-[a-zA-Z0-9._-] removed,
 *    plus a uniqid() prefix to prevent overwrite collisions
 *  - getimagesize() used as a second-pass image validity check
 *  - Max filesize enforced in PHP (independent of php.ini upload_max_filesize)
 *
 * Also records the upload in the Media gallery via MediaService (skipped,
 * not fatal, if media_table isn't configured).
 *
 * Copyright (c) 2026 FanUpdate Redux
 * GNU General Public License v3 or later.
 ******************************************************************************/

declare(strict_types=1);

require_once('../blog-config.php');
require_once('../functions.php');
require_once('../class/Repository/MediaRepositoryInterface.php');
require_once('../class/Repository/MediaRepository.php');
require_once('../class/Service/MediaService.php');
require_once('../class/Service/ImageUploadValidator.php');

$fu = FanUpdate::instance();
$fu->addOptFromDb();
$fu->login();   // exits with login form if not authenticated

header('Content-Type: application/json; charset=UTF-8');

// ── helpers ───────────────────────────────────────────────────────────────────

function upload_json_error(string $msg): never
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function upload_json_ok(string $url, string $thumb, string $filename): never
{
    echo json_encode(['ok' => true, 'url' => $url, 'thumb' => $thumb, 'filename' => $filename]);
    exit;
}

// ── guard: must be POST ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_json_error('Method not allowed.');
}

// ── guard: CSRF token ─────────────────────────────────────────────────────────
// This endpoint always returns JSON, so CSRF failure is reported the same way
// as any other upload error rather than via verifyCsrfOrFail()'s plain-text
// 403 body — the WYSIWYG upload JS only understands the {"ok":...} shape.

if (!$fu->verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    http_response_code(403);
    upload_json_error('Security check failed. Please refresh the page and try again.');
}

// ── guard: file present ───────────────────────────────────────────────────────

if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
    upload_json_error('No file received.');
}

if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $php_err_map = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload stopped by server extension.',
    ];
    upload_json_error($php_err_map[$_FILES['image']['error']] ?? 'Upload error.');
}

$tmp = $_FILES['image']['tmp_name'];

// ── size limit ────────────────────────────────────────────────────────────────
// Default 2 MB; overridable via the upload_max_filesize option (bytes).

$raw_limit = $fu->getOpt('upload_max_filesize');
$max_bytes  = ($raw_limit !== false && (int) $raw_limit > 0)
    ? (int) $raw_limit
    : 2 * 1024 * 1024;

if ($_FILES['image']['size'] > $max_bytes) {
    upload_json_error('File is too large. Maximum allowed: ' . round($max_bytes / 1048576, 1) . ' MB.');
}

// ── image validation (sniffed MIME, header parse, size cap, real decode) ─────

$validator = new ImageUploadValidator();

try {
    $image = $validator->validate($tmp);
} catch (InvalidArgumentException $e) {
    upload_json_error($e->getMessage());
}

$mime_type = $image['mime'];

// ── filename sanitisation ─────────────────────────────────────────────────────

require_once('../class/FileHandler.php');
$fh = new FileHandler();

$safe_name = $fh->fixFilename($_FILES['image']['name']);

// Re-apply an extension derived from the validated MIME type to defeat tricks
// like "evil.php.jpg".
$base_name  = pathinfo($safe_name, PATHINFO_FILENAME);
$ext        = $image['ext'];
$safe_name  = $base_name . '.' . $ext;

// Unique prefix prevents overwrite collisions.
$unique     = uniqid('', true);
$unique     = preg_replace('/[^a-zA-Z0-9]/', '', $unique);
$final_name = $unique . '_' . $safe_name;

// ── destination directories ───────────────────────────────────────────────────

$install_path_opt = $fu->getOpt('install_path');
$install_path = ($install_path_opt !== false && (string) $install_path_opt !== '')
    ? rtrim((string) $install_path_opt, '/')
    : dirname(__DIR__);   // fallback: resolve from upload.php's own location
$install_url  = rtrim((string) $fu->getOpt('install_url'),  '/');

$year  = date('Y');
$month = date('m');

$img_dir   = $install_path . '/uploads/images/' . $year . '/' . $month;
$thumb_dir = $img_dir . '/thumbs';

foreach ([$img_dir, $thumb_dir] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            upload_json_error('Could not create upload directory.');
        }
        // Prevents directory listing where Options -Indexes isn't set globally.
        @file_put_contents($dir . '/index.html', '');
    }
}

// ── write uploads/.htaccess if missing ───────────────────────────────────────
// Blocks PHP (and other script) execution inside the uploads tree.
// Written once; subsequent uploads skip it.

$uploads_dir    = $install_path . '/uploads';
$htaccess_path  = $uploads_dir . '/.htaccess';

if (is_dir($uploads_dir) && !file_exists($htaccess_path)) {
    $htaccess = <<<'HTACCESS'
# FanUpdate — uploads directory
# Block execution of any server-side script uploaded here.
Options -ExecCGI
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .pl .py .cgi .shtml
AddType text/plain .php .php3 .php4 .php5 .php7 .php8 .phtml
<FilesMatch "\.(php|php3|php4|php5|php7|php8|phtml|pl|py|cgi|shtml)$">
    Require all denied
</FilesMatch>
HTACCESS;
    @file_put_contents($htaccess_path, $htaccess);
}

// ── move uploaded file ────────────────────────────────────────────────────────

$dest_path = $img_dir . '/' . $final_name;

if (!move_uploaded_file($tmp, $dest_path)) {
    upload_json_error('Failed to save uploaded file.');
}

// ── thumbnail generation ──────────────────────────────────────────────────────

$thumb_w_opt = $fu->getOpt('upload_thumb_width');
$thumb_h_opt = $fu->getOpt('upload_thumb_height');
$max_thumb_w = ($thumb_w_opt !== false && (int) $thumb_w_opt > 0) ? (int) $thumb_w_opt : 300;
$max_thumb_h = ($thumb_h_opt !== false && (int) $thumb_h_opt > 0) ? (int) $thumb_h_opt : 300;

$src_w = $image['width'];
$src_h = $image['height'];

// Constrained fit: preserve aspect ratio, no crop.
if ($src_w <= $max_thumb_w && $src_h <= $max_thumb_h) {
    $thumb_w = $src_w;
    $thumb_h = $src_h;
} else {
    $ratio   = min($max_thumb_w / $src_w, $max_thumb_h / $src_h);
    $thumb_w = (int) round($src_w * $ratio);
    $thumb_h = (int) round($src_h * $ratio);
}

$thumb_path = $thumb_dir . '/' . $final_name;
$thumb_ok   = false;

// Load source image with the appropriate GD function for the MIME type.
$src_img = match ($mime_type) {
    'image/jpeg' => @imagecreatefromjpeg($dest_path),
    'image/png'  => @imagecreatefrompng($dest_path),
    'image/gif'  => @imagecreatefromgif($dest_path),
    'image/webp' => @imagecreatefromwebp($dest_path),
    'image/avif' => @imagecreatefromavif($dest_path),
    default      => false,
};

if ($src_img !== false) {
    $dst_img = imagecreatetruecolor($thumb_w, $thumb_h);

    if ($dst_img !== false) {
        // Preserve alpha channel for PNG and GIF.
        if ($mime_type === 'image/png' || $mime_type === 'image/gif') {
            imagealphablending($dst_img, false);
            imagesavealpha($dst_img, true);
            $transparent = imagecolorallocatealpha($dst_img, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst_img, 0, 0, $thumb_w, $thumb_h, $transparent);
            }
        }

        imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $src_w, $src_h);

        $thumb_ok = match ($mime_type) {
            'image/jpeg' => imagejpeg($dst_img, $thumb_path, 85),
            'image/png'  => imagepng($dst_img, $thumb_path, 7),
            'image/gif'  => imagegif($dst_img, $thumb_path),
            'image/webp' => imagewebp($dst_img, $thumb_path, 85),
            'image/avif' => imageavif($dst_img, $thumb_path, 60),
            default      => false,
        };
    }
}

// If GD fails, use the original as the thumbnail (unresized).
if (!$thumb_ok || !file_exists($thumb_path)) {
    @copy($dest_path, $thumb_path);
}

// ── build URLs ────────────────────────────────────────────────────────────────

$url   = $install_url . '/uploads/images/' . $year . '/' . $month . '/'         . $final_name;
$thumb = $install_url . '/uploads/images/' . $year . '/' . $month . '/thumbs/' . $final_name;

// ── record in the Media gallery (Admin -> Media) ──────────────────────────────
// Non-fatal on failure: the file is saved and usable, it just won't be in the gallery.
// Stored paths are relative to uploads/.

$mediaTableOpt = (string) $fu->getOpt('media_table');
if ($mediaTableOpt !== '') {
    $mediaService = new MediaService(
        new MediaRepository($fu->db, $mediaTableOpt),
        $install_path,
        $install_url
    );
    $mediaService->recordUpload(
        'images/' . $year . '/' . $month . '/' . $final_name,
        'images/' . $year . '/' . $month . '/thumbs/' . $final_name,
        $final_name,
        $src_w,
        $src_h,
        (int) $_FILES['image']['size'],
        $mime_type
    );
}

upload_json_ok($url, $thumb, $final_name);
