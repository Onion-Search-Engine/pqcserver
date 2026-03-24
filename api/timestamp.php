<?php
/**
 * POST /api/timestamp.php
 * Receives a document hash + ML-DSA signature, stores a verifiable timestamp record.
 * The server never receives the document itself — only its SHA-256 hash.
 *
 * GET /api/timestamp.php?id=ts_xxxxxxxx
 * Returns the full timestamp certificate for verification.
 *
 * Request (POST):
 * {
 *   "document_hash":  "hex SHA-256 of document",
 *   "signature":      "base64 ML-DSA signature",
 *   "public_key_dsa": "base64 ML-DSA public key",
 *   "display_name":   "Alice Smith (optional)",
 *   "filename":       "contract.pdf (optional)",
 *   "file_size":      102400 (optional, bytes)
 * }
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

// ── GET: retrieve certificate ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) jsonResponse(['error' => 'Missing id'], 400);

    try {
        $doc = getCollection('timestamps')->findOne(['_id' => $id]);
        if (!$doc) jsonResponse(['error' => 'Certificate not found'], 404);

        jsonResponse([
            'ok'          => true,
            'id'          => (string)$doc['_id'],
            'document_hash'  => (string)$doc['document_hash'],
            'signature'      => (string)$doc['signature'],
            'public_key_dsa' => (string)$doc['public_key_dsa'],
            'display_name'   => (string)($doc['display_name'] ?? ''),
            'signer'         => (string)($doc['signer'] ?? ''),
            'filename'       => (string)($doc['filename'] ?? ''),
            'file_size'      => (int)($doc['file_size'] ?? 0),
            'server_timestamp' => (string)$doc['server_timestamp'],
            'created_at'     => date('Y-m-d H:i:s T', (int)((string)$doc['created_at']) / 1000),
            'verify_url'     => BASE_URL . '/verify.html?id=' . $id,
            'certificate'    => (string)($doc['certificate'] ?? ''),
        ]);
    } catch (\Exception $e) {
        jsonResponse(['error' => 'Retrieval error'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 20, 60);

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonResponse(['error' => 'Invalid JSON'], 400);

$docHash   = strtolower(trim($body['document_hash']  ?? ''));
$signature = trim($body['signature']      ?? '');
$pubKeyDsa = trim($body['public_key_dsa'] ?? '');
$dispName  = trim($body['display_name']   ?? '');
$filename  = trim($body['filename']       ?? '');
$fileSize  = (int)($body['file_size']     ?? 0);

// Validate hash (SHA-256 = 64 hex chars)
if (empty($docHash) || !preg_match('/^[a-f0-9]{64}$/', $docHash)) {
    jsonResponse(['error' => 'Invalid document_hash. Expected SHA-256 hex string.'], 400);
}

// Validate signature and key
if (empty($signature) || strlen($signature) < 100) {
    jsonResponse(['error' => 'Invalid or missing ML-DSA signature'], 400);
}
if (empty($pubKeyDsa) || strlen($pubKeyDsa) < 100) {
    jsonResponse(['error' => 'Invalid or missing ML-DSA public key'], 400);
}

// Get signer if logged in
$signer = getSessionUser();

// Generate timestamp record ID
$id        = 'ts_' . shortId(10);
$nowMs     = (int)(microtime(true) * 1000);
$isoTime   = gmdate('Y-m-d\TH:i:s\Z');
$unixTs    = time();

// Build verifiable certificate bundle (JSON, signed concept)
$certificate = json_encode([
    'version'        => '1',
    'id'             => $id,
    'service'        => 'PQCServer Cryptographic Timestamp',
    'service_url'    => BASE_URL,
    'document_hash'  => $docHash,
    'hash_algorithm' => 'SHA-256',
    'signature'      => $signature,
    'public_key_dsa' => $pubKeyDsa,
    'algorithm'      => 'ML-DSA (NIST FIPS-204)',
    'signer_name'    => $dispName ?: ($signer ? $signer['display_name'] : 'Anonymous'),
    'signer_username'=> $signer ? $signer['username'] : null,
    'filename'       => $filename ?: null,
    'file_size_bytes'=> $fileSize ?: null,
    'timestamp_utc'  => $isoTime,
    'timestamp_unix' => $unixTs,
    'verify_url'     => BASE_URL . '/verify.html?id=' . $id,
    'note'           => 'This certificate proves that the document with the above SHA-256 hash was signed with the above ML-DSA key at the recorded timestamp. Verification is cryptographic and does not require trusting PQCServer.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

try {
    getCollection('timestamps')->insertOne([
        '_id'            => $id,
        'document_hash'  => $docHash,
        'signature'      => $signature,
        'public_key_dsa' => $pubKeyDsa,
        'display_name'   => $dispName ?: ($signer ? $signer['display_name'] : ''),
        'signer'         => $signer ? $signer['username'] : null,
        'filename'       => $filename ?: null,
        'file_size'      => $fileSize ?: null,
        'server_timestamp' => $isoTime,
        'created_at'     => new \MongoDB\BSON\UTCDateTime($nowMs),
        'certificate'    => $certificate,
        'ip'             => getClientIp(),
    ]);

    jsonResponse([
        'ok'          => true,
        'id'          => $id,
        'timestamp'   => $isoTime,
        'verify_url'  => BASE_URL . '/verify.html?id=' . $id,
        'certificate' => $certificate,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Failed to store timestamp. Please try again.'], 500);
}
