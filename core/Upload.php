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

    /**
     * Clinical documents (CNIC scans, referral letters, outside reports,
     * X-ray images, intra-oral photos): PDF or image, stored OUTSIDE the web
     * root in storage/files and served only through a permission-checked route.
     * @return array{ok:bool, path?:string, mime?:string, size?:int, message?:string}  path relative to storage/files
     */
    public static function document(?array $file, string $folder): array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Choose a file to upload.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Upload failed (error code ' . (int) $file['error'] . ').'];
        }
        $maxMb = (int) config('uploads.max_document_mb', 10);
        if ($file['size'] > $maxMb * 1024 * 1024) {
            return ['ok' => false, 'message' => 'File must be smaller than ' . $maxMb . ' MB.'];
        }
        $ext = null;
        $mime = null;
        $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($head === '%PDF-') {
            $ext = 'pdf';
            $mime = 'application/pdf';
        } elseif (($info = @getimagesize($file['tmp_name'])) && isset(self::TYPES[$info[2]])) {
            $ext = self::TYPES[$info[2]];
            $mime = $info['mime'];
        }
        if ($ext === null) {
            return ['ok' => false, 'message' => 'Only PDF, PNG, JPG, GIF or WEBP files are allowed.'];
        }
        $folder = preg_replace('/[^a-z0-9_\-\/]/', '', strtolower($folder));
        $dir = STORAGE_PATH . '/files/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Document folder is not writable.'];
        }
        $name = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $dir . '/' . $name)
            : rename($file['tmp_name'], $dir . '/' . $name);
        if (!$moved) {
            return ['ok' => false, 'message' => 'Could not save the uploaded file.'];
        }
        return ['ok' => true, 'path' => $folder . '/' . $name, 'mime' => $mime, 'size' => (int) $file['size']];
    }

    /** Streams a stored document (after the caller checked permissions). */
    public static function send(string $relative, string $downloadName = ''): void
    {
        $relative = str_replace('\\', '/', $relative);
        if (str_contains($relative, '..')) {
            abort(404);
        }
        $file = STORAGE_PATH . '/files/' . $relative;
        if (!is_file($file)) {
            abort(404, 'File not found.');
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._\-]/', '_', $downloadName ?: basename($file)) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
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
