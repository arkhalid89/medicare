<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Image uploads (logo, prescription header image) into public/uploads.
 * Only real raster images are accepted — never SVG/HTML — so an upload can
 * not smuggle script into the page.
 */
final class Upload
{
    private const TYPES = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * @return array{ok:bool, path?:string, message?:string}  path is relative to public/uploads
     */
    public static function image(?array $file, string $folder): array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => ''];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed (error code ' . (int) $file['error'] . ').'];
        }
        $maxKb = (int) config('uploads.max_image_kb', 2048);
        if ($file['size'] > $maxKb * 1024) {
            return ['ok' => false, 'message' => 'Image must be smaller than ' . $maxKb . ' KB.'];
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            return ['ok' => false, 'message' => 'Only PNG, JPG, GIF or WEBP images are allowed.'];
        }
        $folder = preg_replace('/[^a-z0-9_\-]/', '', strtolower($folder));
        $dir = PUBLIC_PATH . '/uploads/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Upload folder is not writable.'];
        }
        $name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . self::TYPES[$info[2]];
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $dir . '/' . $name)
            : rename($file['tmp_name'], $dir . '/' . $name);
        if (!$moved) {
            return ['ok' => false, 'message' => 'Could not save the uploaded image.'];
        }
        return ['ok' => true, 'path' => $folder . '/' . $name];
    }

    public static function delete(?string $relative): void
    {
        if (!$relative || str_contains($relative, '..')) {
            return;
        }
        $file = PUBLIC_PATH . '/uploads/' . $relative;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
