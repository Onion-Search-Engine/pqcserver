<?php
// GET /api/session.php — return current authenticated user info
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

$user = getSessionUser();
if (!$user) {
    jsonResponse(['ok' => false, 'user' => null]);
}

// Also return whether user has keys set up
try {
    $doc = getCollection(COL_USERS)->findOne(
        ['_id' => $user['username']],
        ['projection' => ['has_keys' => 1, 'public_key_kem' => 1]]
    );
    $hasKeys = (bool)($doc['has_keys'] ?? false);
} catch (\Exception $e) {
    $hasKeys = false;
}

jsonResponse([
    'ok'       => true,
    'user'     => $user,
    'has_keys' => $hasKeys,
    'profile'  => BASE_URL . '/u/' . $user['username'],
]);
