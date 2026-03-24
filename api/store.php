<?php
/**
 * POST /api/store.php
 *
 * Saves encrypted message envelope to MongoDB and returns a shortlink.
 * No size limits — files are handled separately via GridFS (file_upload.php).
 * The server NEVER sees plaintext — only the encrypted JSON envelope.
 *
 * Request JSON:
 * {
 *   "ciphertext":     "JSON envelope string (ML-KEM + AES-256-GCM)",
 *   "file_id":        "ObjectId string (optional — from file_upload.php)",
 *   "recipient":      "username (optional)",
 *   "burn_after_read": true,
 *   "ttl_days":       30
 * }
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 60, 60);

// Text envelope only — file is already in GridFS via file_upload.php
// Still cap the envelope itself at 1MB (text + keys, no file binary)
$body = file_get_contents('php://input');
if (strlen($body) > 1 * 1024 * 1024) {
    jsonResponse(['error' => 'Message envelope too large (max 1MB). Files must be uploaded separately.'], 413);
}

$data = json_decode($body, true);
if (!$data) jsonResponse(['error' => 'Invalid JSON'], 400);

$ciphertext = trim($data['ciphertext']   ?? '');
$fileId     = trim($data['file_id']      ?? ''); // GridFS ObjectId (optional)
$recipient  = trim($data['recipient']    ?? '');
$burn       = (bool)($data['burn_after_read'] ?? true);
$ttlDays    = max(1, min((int)($data['ttl_days'] ?? 30), 365));

if (empty($ciphertext)) jsonResponse(['error' => 'Missing ciphertext'], 400);

// Validate envelope format
$envelope = json_decode($ciphertext, true);
if (!$envelope || !isset($envelope['v'], $envelope['kem'], $envelope['ct'])) {
    jsonResponse(['error' => 'Invalid ciphertext envelope format'], 400);
}

// Validate file_id if provided
if ($fileId && !preg_match('/^[a-f0-9]{24}$/', $fileId)) {
    jsonResponse(['error' => 'Invalid file_id format'], 400);
}

// Get sender if logged in (optional)
$sender = getSessionUser();

$id        = shortId(8);
$now       = new \MongoDB\BSON\UTCDateTime(time() * 1000);
$expiresAt = new \MongoDB\BSON\UTCDateTime((time() + ($ttlDays * 86400)) * 1000);

try {
    getCollection(COL_MESSAGES)->insertOne([
        '_id'             => $id,
        'ciphertext'      => $ciphertext,
        'file_id'         => $fileId ?: null,    // GridFS reference
        'has_file'        => !empty($fileId),
        'recipient'       => $recipient ?: null,
        'sender'          => $sender ? $sender['username'] : null,
        'burn_after_read' => $burn,
        'read'            => false,
        'created_at'      => $now,
        'expires_at'      => $expiresAt,
        'size_bytes'      => strlen($ciphertext),
    ]);

    jsonResponse([
        'ok'        => true,
        'id'        => $id,
        'shortlink' => BASE_URL . '/m/' . $id,
        'expires'   => date('Y-m-d', time() + ($ttlDays * 86400)),
        'burn'      => $burn,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Storage error. Please try again.'], 500);
}
