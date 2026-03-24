<?php
// GET /api/pubkey.php?u=username — return public keys for a user
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

rateLimit(getClientIp(), 100, 60);

$username = strtolower(trim($_GET['u'] ?? ''));
if (!$username || !preg_match('/^[a-z0-9_]{3,32}$/', $username))
    jsonResponse(['error' => 'Invalid username'], 400);

try {
    $doc = getCollection(COL_USERS)->findOne(
        ['_id' => $username],
        ['projection' => ['password' => 0, 'email' => 0]] // never expose password or email
    );

    if (!$doc) jsonResponse(['error' => 'User not found', 'not_found' => true], 404);

    if (empty($doc['public_key_kem']))
        jsonResponse(['error' => 'User has not set up their public keys yet.', 'no_keys' => true], 404);

    jsonResponse([
        'ok'             => true,
        'username'       => $username,
        'display_name'   => (string)($doc['display_name'] ?? $username),
        'public_key_kem' => (string)$doc['public_key_kem'],
        'public_key_dsa' => (string)($doc['public_key_dsa'] ?? ''),
        'profile_url'    => BASE_URL . '/u/' . $username,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Lookup error. Please try again.'], 500);
}
