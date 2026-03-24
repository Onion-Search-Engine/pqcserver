<?php
/**
 * POST /api/vault_upload.php
 *
 * Stores an encrypted file permanently in the authenticated user's vault.
 * Files remain until the user explicitly deletes them (no TTL).
 *
 * Two-step process:
 *   Step 1 — Browser encrypts file with ML-KEM shared secret + AES-256-GCM
 *             and uploads chunks via /api/file_upload.php → gets file_id
 *   Step 2 — Browser POSTs metadata + file_id to this endpoint → gets vault entry
 *
 * Request JSON:
 * {
 *   "file_id":     "ObjectId from GridFS",
 *   "filename":    "document.pdf",
 *   "mime_type":   "application/pdf",
 *   "size_bytes":  245120,
 *   "public_key_kem_used": "base64 pubkey used to encrypt",
 *   "tags":        ["work", "2026"],
 *   "note":        "Optional description"
 * }
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    jsonResponse(['error' => 'Method not allowed'], 405);

$user = requireAuth();

rateLimit(getClientIp(), 30, 60);

$body = file_get_contents('php://input');
if (strlen($body) > 64 * 1024) {
    jsonResponse(['error' => 'Request too large'], 413);
}

$data = json_decode($body, true);
if (!$data) jsonResponse(['error' => 'Invalid JSON'], 400);

$fileId    = trim($data['file_id']    ?? '');
$filename  = trim($data['filename']   ?? 'file');
$mimeType  = trim($data['mime_type']  ?? 'application/octet-stream');
$sizeBytes = (int)($data['size_bytes'] ?? 0);
$pubKeyUsed = trim($data['public_key_kem_used'] ?? '');
$tags      = array_slice(array_map('trim', (array)($data['tags'] ?? [])), 0, 10);
$note      = trim(substr($data['note'] ?? '', 0, 500));

// Validate file_id
if (empty($fileId) || !preg_match('/^[a-f0-9]{24}$/', $fileId)) {
    jsonResponse(['error' => 'Invalid file_id'], 400);
}

// Check file exists in GridFS
try {
    $bucket = getDatabase()->selectGridFSBucket(['bucketName' => 'encrypted_files']);
    $fileInfo = null;
    foreach ($bucket->find(['_id' => new \MongoDB\BSON\ObjectId($fileId)]) as $f) {
        $fileInfo = $f;
        break;
    }
    if (!$fileInfo) {
        jsonResponse(['error' => 'File not found in storage. Upload the file first via /api/file_upload.php'], 404);
    }
    $encryptedSize = (int)$fileInfo['length'];
} catch (\Exception $e) {
    jsonResponse(['error' => 'Could not verify file: ' . $e->getMessage()], 500);
}

// Check user doesn't already have this file_id in vault (prevent duplicates)
try {
    $vaultCol = getCollection('vault_files');
    $existing = $vaultCol->findOne([
        'owner'   => $user['username'],
        'file_id' => $fileId,
    ]);
    if ($existing) {
        jsonResponse(['error' => 'This file is already in your vault', 'vault_id' => (string)$existing['_id']], 409);
    }
} catch (\Exception $e) {
    // Continue — not critical
}

// Generate vault entry ID
$vaultId = 'vf-' . shortId(10);
$now     = new \MongoDB\BSON\UTCDateTime(time() * 1000);

// Generate initial shortlink for sharing
$shareId = shortId(8);

try {
    $vaultCol = getCollection('vault_files');

    $vaultCol->insertOne([
        '_id'                 => $vaultId,
        'owner'               => $user['username'],
        'file_id'             => $fileId,
        'filename'            => $filename,
        'mime_type'           => $mimeType,
        'size_bytes'          => $sizeBytes,
        'size_encrypted'      => $encryptedSize,
        'public_key_kem_used' => $pubKeyUsed,
        'shortlinks'          => [$shareId],  // can generate more
        'shared_with'         => [],          // usernames with explicit access
        'tags'                => $tags,
        'note'                => $note,
        'created_at'          => $now,
        'updated_at'          => $now,
        'last_accessed'       => null,
        'access_count'        => 0,
        'ttl'                 => null,        // null = permanent
    ]);

    // Also store shortlink in messages collection for /m/:id routing
    // so existing decrypt page works for vault files too
    getCollection(COL_MESSAGES)->insertOne([
        '_id'             => $shareId,
        'vault_id'        => $vaultId,
        'vault_file'      => true,
        'recipient'       => null,
        'sender'          => $user['username'],
        'burn_after_read' => false,   // vault files are NOT burned
        'read'            => false,
        'created_at'      => $now,
        'expires_at'      => null,    // never expires
        'has_file'        => true,
        'file_id'         => $fileId,
        'ciphertext'      => null,    // no text envelope for vault-only files
    ]);

    // Update user vault stats
    getCollection(COL_USERS)->updateOne(
        ['_id' => $user['username']],
        [
            '$inc' => ['vault_count' => 1, 'vault_bytes' => $sizeBytes],
            '$set' => ['vault_updated_at' => $now],
        ]
    );

    jsonResponse([
        'ok'         => true,
        'vault_id'   => $vaultId,
        'share_id'   => $shareId,
        'shortlink'  => BASE_URL . '/m/' . $shareId,
        'filename'   => $filename,
        'size_bytes' => $sizeBytes,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Failed to save to vault: ' . $e->getMessage()], 500);
}
