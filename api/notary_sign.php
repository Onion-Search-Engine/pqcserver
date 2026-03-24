<?php
/**
 * POST /api/notary_sign.php
 *
 * Receives document hash + user ML-DSA signature from browser.
 * Adds server timestamp + countersignature.
 * Returns the complete Notary Receipt JSON.
 *
 * The document NEVER reaches the server — only its hash.
 * Zero-knowledge: server cannot reconstruct the document from its hash.
 *
 * Request JSON:
 * {
 *   "document": {
 *     "hash_sha256": "hex string",
 *     "hash_sha512": "hex string",
 *     "filename":    "contract.pdf",
 *     "size_bytes":  245120,
 *     "mime_type":   "application/pdf"
 *   },
 *   "signature": {
 *     "algorithm":     "ML-DSA-65",
 *     "value":         "base64...",
 *     "signed_payload":"canonical JSON string that was signed",
 *     "signed_at":     "ISO 8601"
 *   },
 *   "signer": {
 *     "username":     "mario_rossi",   // optional if not logged in
 *     "public_key_dsa":"base64..."
 *   }
 * }
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/server_keys.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 30, 60);

$body = file_get_contents('php://input');
if (strlen($body) > 256 * 1024) {
    jsonResponse(['error' => 'Request too large'], 413);
}

$data = json_decode($body, true);
if (!$data) jsonResponse(['error' => 'Invalid JSON'], 400);

// ── Validate input ────────────────────────────────────────────────────────
$doc = $data['document'] ?? [];
$sig = $data['signature'] ?? [];
$sgn = $data['signer']    ?? [];

// Document fields
$hash256  = trim($doc['hash_sha256'] ?? '');
$hash512  = trim($doc['hash_sha512'] ?? '');
$filename = trim($doc['filename']    ?? 'document');
$sizeByt  = (int)($doc['size_bytes'] ?? 0);
$mimeType = trim($doc['mime_type']   ?? 'application/octet-stream');

// Signature fields
$sigAlgo    = trim($sig['algorithm']      ?? '');
$sigValue   = trim($sig['value']          ?? '');
$sigPayload = trim($sig['signed_payload'] ?? '');
$sigAt      = trim($sig['signed_at']      ?? '');

// Signer fields
$signerPubKey = trim($sgn['public_key_dsa'] ?? '');
$signerUser   = trim($sgn['username']       ?? '');

// Validations
if (!preg_match('/^[a-f0-9]{64}$/', $hash256)) jsonResponse(['error' => 'Invalid SHA-256 hash'], 400);
if (!preg_match('/^[a-f0-9]{128}$/', $hash512)) jsonResponse(['error' => 'Invalid SHA-512 hash'], 400);
if (empty($sigValue))   jsonResponse(['error' => 'Missing user signature'], 400);
if (empty($sigPayload)) jsonResponse(['error' => 'Missing signed payload'], 400);
if (empty($signerPubKey)) jsonResponse(['error' => 'Missing signer public key'], 400);

// If user is logged in, enrich signer info
$sessionUser = getSessionUser();
if ($sessionUser) {
    $signerUser = $sessionUser['username'];
    // Verify the public key matches what's in the database
    try {
        $userDoc = getCollection(COL_USERS)->findOne(['_id' => $signerUser]);
        if ($userDoc && !empty($userDoc['public_key_dsa'])) {
            if ((string)$userDoc['public_key_dsa'] !== $signerPubKey) {
                jsonResponse(['error' => 'Public key does not match your registered key'], 400);
            }
        }
    } catch (\Exception $e) { /* non-blocking */ }
}

// ── Server timestamp + countersignature ──────────────────────────────────
$receiptId  = 'NTR-' . shortId(10);
$issuedAt   = gmdate('Y-m-d\TH:i:s\Z');

// Canonical server payload (what the server signs)
$serverPayload = json_encode([
    'v'          => 'pqcnotary-server-1',
    'receipt_id' => $receiptId,
    'hash_sha256'=> $hash256,
    'hash_sha512'=> $hash512,
    'user_sig'   => $sigValue,
    'issued_at'  => $issuedAt,
    'server'     => parse_url(BASE_URL, PHP_URL_HOST),
], JSON_UNESCAPED_SLASHES);

// Server signs with its key (Ed25519 via sodium, or HMAC fallback)
$serverSig    = serverSign($serverPayload);
$serverPubKey = SERVER_DSA_PUBLIC;

// ── Build full Notary Receipt ─────────────────────────────────────────────
$receipt = [
    'v'          => 'pqcnotary-1',
    'receipt_id' => $receiptId,
    'document'   => [
        'hash_sha256' => $hash256,
        'hash_sha512' => $hash512,
        'filename'    => $filename,
        'size_bytes'  => $sizeByt,
        'mime_type'   => $mimeType,
    ],
    'signer' => [
        'username'       => $signerUser ?: null,
        'public_key_dsa' => $signerPubKey,
    ],
    'signature' => [
        'algorithm'     => $sigAlgo,
        'value'         => $sigValue,
        'signed_payload'=> $sigPayload,
        'signed_at'     => $sigAt ?: $issuedAt,
    ],
    'timestamp' => [
        'server'          => parse_url(BASE_URL, PHP_URL_HOST),
        'issued_at'       => $issuedAt,
        'server_signature'=> $serverSig,
        'server_public_key'=> $serverPubKey,
        'signed_payload'  => $serverPayload,
    ],
    'verify_url' => BASE_URL . '/verify/' . $receiptId,
];

// ── Save to MongoDB ───────────────────────────────────────────────────────
try {
    getCollection('notary_receipts')->insertOne([
        '_id'              => $receiptId,
        'hash_sha256'      => $hash256,
        'hash_sha512'      => $hash512,
        'filename'         => $filename,
        'size_bytes'       => $sizeByt,
        'mime_type'        => $mimeType,
        'signer_username'  => $signerUser ?: null,
        'signer_pubkey'    => $signerPubKey,
        'user_signature'   => $sigValue,
        'server_signature' => $serverSig,
        'issued_at'        => new \MongoDB\BSON\UTCDateTime(time() * 1000),
        'receipt_json'     => json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    ]);
} catch (\Exception $e) {
    jsonResponse(['error' => 'Failed to save receipt: ' . $e->getMessage()], 500);
}

jsonResponse([
    'ok'         => true,
    'receipt_id' => $receiptId,
    'receipt'    => $receipt,
    'verify_url' => BASE_URL . '/verify/' . $receiptId,
]);
