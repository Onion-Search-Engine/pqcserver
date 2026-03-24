<?php
// POST /api/update_keys.php — save/update public keys for logged-in user
// Requires authentication

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    jsonResponse(['error' => 'Method not allowed'], 405);

$user = requireAuth(); // 401 if not logged in

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonResponse(['error' => 'Invalid JSON'], 400);

$pubKeyKem   = trim($body['public_key_kem'] ?? '');
$pubKeyDsa   = trim($body['public_key_dsa'] ?? '');
$displayName = trim($body['display_name']   ?? '');

if (empty($pubKeyKem) || strlen($pubKeyKem) < 100) {
    jsonResponse(['error' => 'Invalid ML-KEM public key'], 400);
}
if (empty($pubKeyDsa) || strlen($pubKeyDsa) < 100) {
    jsonResponse(['error' => 'Invalid ML-DSA public key'], 400);
}

try {
    getCollection(COL_USERS)->updateOne(
        ['_id' => $user['username']],
        ['$set' => [
            'public_key_kem' => $pubKeyKem,
            'public_key_dsa' => $pubKeyDsa,
            'display_name'   => $displayName ?: $user['display_name'],
            'has_keys'       => true,
            'keys_updated_at'=> new \MongoDB\BSON\UTCDateTime(time() * 1000),
        ]]
    );

    jsonResponse([
        'ok'      => true,
        'profile' => BASE_URL . '/u/' . $user['username'],
    ]);
} catch (\Exception $e) {
    jsonResponse(['error' => 'Failed to save keys. Please try again.'], 500);
}
