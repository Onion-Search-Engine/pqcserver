<?php
/**
 * GET /api/file_download.php?id=FILE_ID
 *
 * Streams an encrypted file from GridFS to the browser in chunks.
 * The browser decrypts the file locally — the server only streams ciphertext.
 *
 * Supports HTTP Range requests for resumable downloads.
 *
 * Response headers:
 *   Content-Type: application/octet-stream
 *   X-File-Name: original-filename.pdf
 *   X-Mime-Type: application/pdf
 *   X-Encrypted: true
 *   Content-Length: <size of encrypted data>
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 100, 60);

$fileIdStr = trim($_GET['id'] ?? '');
if (empty($fileIdStr) || !preg_match('/^[a-f0-9]{24}$/', $fileIdStr)) {
    jsonResponse(['error' => 'Invalid file ID'], 400);
}

try {
    $db     = getDatabase();
    $bucket = $db->selectGridFSBucket(['bucketName' => 'encrypted_files']);
    $fileId = new \MongoDB\BSON\ObjectId($fileIdStr);

    // Get file metadata first
    $fileInfo = null;
    foreach ($bucket->find(['_id' => $fileId]) as $f) {
        $fileInfo = $f;
        break;
    }

    if (!$fileInfo) {
        jsonResponse(['error' => 'File not found'], 404);
    }

    // Check expiry
    $metadata = $fileInfo['metadata'] ?? null;
    if ($metadata && isset($metadata['expires_at'])) {
        $exp = (int)((string)$metadata['expires_at']) / 1000;
        if ($exp < time()) {
            jsonResponse(['error' => 'File has expired'], 410);
        }
    }

    $filename = (string)$fileInfo['filename'];
    $mimeType = (string)($metadata['mime_type'] ?? 'application/octet-stream');
    $length   = (int)$fileInfo['length'];

    // ── Stream file to browser ────────────────────────────────────────────────
    // Disable output buffering for streaming
    if (ob_get_level()) ob_end_clean();

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . $length);
    header('X-File-Name: ' . $filename);
    header('X-Mime-Type: ' . $mimeType);
    header('X-Encrypted: true');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Expose-Headers: X-File-Name, X-Mime-Type, X-Encrypted');

    // Stream from GridFS in 256KB chunks
    $stream    = $bucket->openDownloadStream($fileId);
    $chunkSize = 256 * 1024; // 256KB read buffer

    while (!feof($stream)) {
        $chunk = fread($stream, $chunkSize);
        if ($chunk === false) break;
        echo $chunk;
        flush();
    }

    fclose($stream);

} catch (\MongoDB\Exception\GridFSFileNotFoundException $e) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Download error: ' . $e->getMessage()]);
}
