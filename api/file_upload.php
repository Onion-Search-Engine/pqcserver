<?php
/**
 * POST /api/file_upload.php
 *
 * Receives an encrypted file chunk and stores it in MongoDB GridFS.
 * The server receives only ciphertext — never the plaintext file.
 *
 * Flow:
 *   1. Browser encrypts file chunk with AES-256-GCM (shared secret from ML-KEM)
 *   2. Browser POSTs chunk as base64 JSON to this endpoint
 *   3. Server stores chunk in GridFS fs.chunks
 *   4. On final chunk, server assembles GridFS file entry in fs.files
 *   5. Server returns file_id to browser
 *   6. Browser includes file_id in the message envelope sent to store.php
 *
 * Request (JSON):
 *   {
 *     "upload_id":   "temp-session-id",   // client-generated UUID for this upload
 *     "chunk_index": 0,                   // 0-based chunk number
 *     "total_chunks": 5,                  // total number of chunks
 *     "chunk_data":  "base64...",         // encrypted chunk data (base64)
 *     "filename":    "document.pdf",      // original filename (first chunk only)
 *     "mime_type":   "application/pdf",   // original MIME type (first chunk only)
 *     "total_size":  1048576,             // original file size in bytes (first chunk only)
 *   }
 *
 * Response:
 *   { "ok": true, "received": 1, "total": 5 }           // intermediate chunk
 *   { "ok": true, "done": true, "file_id": "abc123..." } // final chunk assembled
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 200, 60); // generous limit for chunked uploads

$body = file_get_contents('php://input');
if (strlen($body) > 6 * 1024 * 1024) { // each chunk max ~4MB base64 encoded
    jsonResponse(['error' => 'Chunk too large. Max 4MB per chunk.'], 413);
}

$data = json_decode($body, true);
if (!$data) jsonResponse(['error' => 'Invalid JSON'], 400);

$uploadId    = trim($data['upload_id']    ?? '');
$chunkIndex  = (int)($data['chunk_index']  ?? -1);
$totalChunks = (int)($data['total_chunks'] ?? 0);
$chunkData   = trim($data['chunk_data']   ?? '');
$filename    = trim($data['filename']     ?? 'file');
$mimeType    = trim($data['mime_type']    ?? 'application/octet-stream');
$totalSize   = (int)($data['total_size']  ?? 0);

// Validate
if (empty($uploadId) || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $uploadId)) {
    jsonResponse(['error' => 'Invalid upload_id'], 400);
}
if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
    jsonResponse(['error' => 'Invalid chunk parameters'], 400);
}
if (empty($chunkData)) {
    jsonResponse(['error' => 'Missing chunk_data'], 400);
}

// Decode base64 chunk
$chunkBinary = base64_decode($chunkData, true);
if ($chunkBinary === false) {
    jsonResponse(['error' => 'Invalid base64 chunk data'], 400);
}

try {
    $db = getDatabase();

    // ── Store chunk in temporary collection ───────────────────────────────────
    // We use a temp collection keyed by upload_id + chunk_index
    // MongoDB GridFS handles final assembly
    $chunksCol = $db->selectCollection('upload_chunks');

    $chunksCol->replaceOne(
        ['upload_id' => $uploadId, 'chunk_index' => $chunkIndex],
        [
            'upload_id'    => $uploadId,
            'chunk_index'  => $chunkIndex,
            'total_chunks' => $totalChunks,
            'data'         => new \MongoDB\BSON\Binary($chunkBinary, \MongoDB\BSON\Binary::TYPE_GENERIC),
            'filename'     => $filename,
            'mime_type'    => $mimeType,
            'total_size'   => $totalSize,
            'created_at'   => new \MongoDB\BSON\UTCDateTime(time() * 1000),
            // TTL: auto-delete incomplete uploads after 2 hours
            'expires_at'   => new \MongoDB\BSON\UTCDateTime((time() + 7200) * 1000),
        ],
        ['upsert' => true]
    );

    // ── Check if all chunks received ──────────────────────────────────────────
    $receivedCount = $chunksCol->countDocuments([
        'upload_id'    => $uploadId,
        'total_chunks' => $totalChunks,
    ]);

    if ($receivedCount < $totalChunks) {
        // Not done yet
        jsonResponse([
            'ok'       => true,
            'received' => $receivedCount,
            'total'    => $totalChunks,
        ]);
    }

    // ── All chunks received — assemble into GridFS ────────────────────────────
    $chunks = $chunksCol->find(
        ['upload_id' => $uploadId],
        ['sort' => ['chunk_index' => 1]]
    );

    // Build complete binary from ordered chunks
    $fullData = '';
    foreach ($chunks as $chunk) {
        $fullData .= (string)$chunk['data'];
    }

    // Write to GridFS
    $bucket = $db->selectGridFSBucket(['bucketName' => 'encrypted_files']);
    $fileId = new \MongoDB\BSON\ObjectId();

    $stream = $bucket->openUploadStreamWithId(
        $fileId,
        $filename,
        [
            'metadata' => [
                'upload_id'  => $uploadId,
                'mime_type'  => $mimeType,
                'total_size' => $totalSize, // original file size (before encryption)
                'encrypted'  => true,       // always true — server never sees plaintext
                'created_at' => new \MongoDB\BSON\UTCDateTime(time() * 1000),
                // GridFS files expire after 30 days (same as messages)
                'expires_at' => new \MongoDB\BSON\UTCDateTime((time() + DEFAULT_TTL) * 1000),
            ]
        ]
    );

    fwrite($stream, $fullData);
    fclose($stream);

    // Clean up temp chunks
    $chunksCol->deleteMany(['upload_id' => $uploadId]);

    jsonResponse([
        'ok'      => true,
        'done'    => true,
        'file_id' => (string)$fileId,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Upload error: ' . $e->getMessage()], 500);
}
