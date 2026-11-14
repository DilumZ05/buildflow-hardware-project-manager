<?php
declare(strict_types=1);

const BF_MAX_FILE_BYTES = 5 * 1024 * 1024; // 5 MB

function ensure_upload_dirs(): void
{
    foreach (['receipts', 'documents', 'attachments'] as $bucket) {
        $dir = BF_UPLOAD_DIR . DIRECTORY_SEPARATOR . $bucket;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

function handle_upload(string $fieldName, string $bucket, bool $receiptOnly = false): ?array
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        json_response(['error' => 'Uploaded file exceeds the maximum allowed size (5 MB).'], 400);
    }
    if ($err !== UPLOAD_ERR_OK) {
        json_response(['error' => 'File upload failed. Please try again.'], 400);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > BF_MAX_FILE_BYTES) {
        json_response(['error' => 'File must be between 1 byte and 5 MB.'], 400);
    }

    $originalName = basename((string) ($file['name'] ?? 'upload.bin'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

    $detectedMime = (string) ($file['type'] ?? 'application/octet-stream');
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $probed = finfo_file($finfo, (string) $file['tmp_name']);
            finfo_close($finfo);
            if (is_string($probed) && $probed !== '') {
                $detectedMime = $probed;
            }
        }
    }

    $receiptMimes = ['image/jpeg', 'image/png', 'application/pdf'];
    $receiptExts = ['jpg', 'jpeg', 'png', 'pdf'];

    if ($receiptOnly) {
        if (!in_array($detectedMime, $receiptMimes, true) || !in_array($safeExt, $receiptExts, true)) {
            json_response(['error' => 'Receipts must be JPG, JPEG, PNG, or PDF files (max 5 MB).'], 400);
        }
    } else {
        $generalMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
            'text/plain', 'text/csv', 'application/zip', 'application/x-zip-compressed',
            'application/octet-stream',
        ];
        $generalExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt', 'csv', 'zip', 'md', 'stl', 'step'];
        if (!in_array($detectedMime, $generalMimes, true) && !in_array($safeExt, $generalExts, true)) {
            json_response(['error' => 'Unsupported file type. Allowed: images, PDF, TXT, CSV, ZIP, CAD (.stl/.step).'], 400);
        }
    }

    ensure_upload_dirs();
    $storedName = sprintf(
        '%s-%s-%s-%s-%s.%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6)),
        $safeExt
    );
    $destPath = BF_UPLOAD_DIR . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file((string) $file['tmp_name'], $destPath)) {
        json_response(['error' => 'Failed to save uploaded file on server.'], 500);
    }

    return [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $detectedMime,
        'size' => $size,
    ];
}

function resolve_safe_upload_path(string $bucket, string $storedName): ?string
{
    $clean = basename($storedName);
    if ($clean === '' || $clean !== $storedName) {
        return null;
    }
    $fullPath = BF_UPLOAD_DIR . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . $clean;
    if (!is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

function stream_protected_file(string $filePath, string $mimeType, string $originalName): never
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . rawurlencode($originalName) . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}
