<?php
// POST /api/file.php         — upload encrypted file chunk by chunk (GridFS)
// GET  /api/file.php?id=xxx  — stream encrypted file from GridFS
// DELETE /api/file.php?id=xxx — delete file (called after burn-after-read)

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

// ─── GET: stream file ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    rateLimit(getClientIp(), 120, 60);

    $fileId = trim($_GET['id'] ?? '');
    if (empty($fileId)) jsonResponse(['error' => 'Missing file ID'], 400);

    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $client  = new \MongoDB\Client(MONGO_URI);
        $bucket  = new \MongoDB\GridFS\Bucket(
            $client->selectDatabase(MONGO_DB),
            ['bucketName' => 'encrypted_files']
        );

        $objectId = new \MongoDB\BSON\ObjectId($fileId);

        // Get file metadata
        $fileDoc = $bucket->findOne(['_id' => $objectId]);
        if (!$fileDoc) jsonResponse(['error' => 'File not found'], 404);

        $metadata = $fileDoc->metadata ?? null;
        $filename  = (string)($fileDoc->filename ?? 'encrypted_file');
        $length    = (int)($fileDoc->length ?? 0);

        // Stream file back as binary
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $length);
        header('X-PQC-Filename: ' . base64_encode($metadata->original_name ?? $filename));
        header('X-PQC-Mime: '     . base64_encode($metadata->mime_type    ?? 'application/octet-stream'));
        header('X-PQC-IV: '       . ($metadata->iv  ?? ''));
        header('Cache-Control: no-store, no-cache');

        $stream = $bucket->openDownloadStream($objectId);
        while (!feof($stream)) {
            echo fread($stream, 65536); // 64KB per read
            flush();
        }
        fclose($stream);
        exit;

    } catch (\MongoDB\Exception\GridFSFileNotFoundException $e) {
        jsonResponse(['error' => 'File not found'], 404);
    } catch (\Exception $e) {
        jsonResponse(['error' => 'File retrieval error'], 500);
    }
}

// ─── POST: upload encrypted file ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rateLimit(getClientIp(), 30, 60);

    // Expect multipart form data
    if (empty($_FILES['file'])) {
        jsonResponse(['error' => 'No file uploaded'], 400);
    }

    $uploadedFile = $_FILES['file'];
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Upload error: ' . $uploadedFile['error']], 400);
    }

    // Metadata from POST fields (all encrypted — server doesn't know original values)
    $iv           = trim($_POST['iv']            ?? '');  // AES-GCM IV (base64)
    $origName     = trim($_POST['original_name'] ?? 'file'); // encrypted filename
    $mimeType     = trim($_POST['mime_type']      ?? 'application/octet-stream');
    $messageId    = trim($_POST['message_id']     ?? '');  // optional link to message

    if (empty($iv)) jsonResponse(['error' => 'Missing IV'], 400);

    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $client  = new \MongoDB\Client(MONGO_URI);
        $bucket  = new \MongoDB\GridFS\Bucket(
            $client->selectDatabase(MONGO_DB),
            ['bucketName' => 'encrypted_files', 'chunkSizeBytes' => 261120] // 255KB chunks
        );

        $stream = fopen($uploadedFile['tmp_name'], 'rb');
        $objectId = $bucket->uploadFromStream(
            'encrypted_' . shortId(8),  // filename stored in GridFS
            $stream,
            [
                'metadata' => [
                    'iv'            => $iv,
                    'original_name' => $origName,
                    'mime_type'     => $mimeType,
                    'message_id'    => $messageId ?: null,
                    'uploaded_at'   => new \MongoDB\BSON\UTCDateTime(time() * 1000),
                    'ip'            => getClientIp(),
                ]
            ]
        );
        fclose($stream);

        jsonResponse([
            'ok'      => true,
            'file_id' => (string)$objectId,
        ]);

    } catch (\Exception $e) {
        jsonResponse(['error' => 'File storage error: ' . $e->getMessage()], 500);
    }
}

// ─── DELETE: remove file ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $fileId = trim($_GET['id'] ?? '');
    if (empty($fileId)) jsonResponse(['error' => 'Missing file ID'], 400);

    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $client = new \MongoDB\Client(MONGO_URI);
        $bucket = new \MongoDB\GridFS\Bucket(
            $client->selectDatabase(MONGO_DB),
            ['bucketName' => 'encrypted_files']
        );
        $bucket->delete(new \MongoDB\BSON\ObjectId($fileId));
        jsonResponse(['ok' => true]);
    } catch (\Exception $e) {
        jsonResponse(['error' => 'Delete error'], 500);
    }
}

jsonResponse(['error' => 'Method not allowed'], 405);
