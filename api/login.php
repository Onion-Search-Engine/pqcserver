<?php
// POST /api/login.php  — login with username/email + password
// GET  /api/login.php  — return current session status

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

// ─── GET: session status ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = getSessionUser();
    if ($user) {
        jsonResponse(['ok' => true, 'user' => $user]);
    } else {
        jsonResponse(['ok' => false]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 10, 60); // max 10 login attempts per minute

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonResponse(['error' => 'Invalid JSON'], 400);

$login    = strtolower(trim($body['login']    ?? '')); // username or email
$password = trim($body['password'] ?? '');

if (!$login)    jsonResponse(['error' => 'Username or email required'], 400);
if (!$password) jsonResponse(['error' => 'Password required'], 400);

try {
    $col = getCollection(COL_USERS);

    // Find by username or email
    $user = $col->findOne([
        '$or' => [
            ['_id'   => $login],
            ['email' => $login],
        ]
    ]);

    if (!$user) {
        // Timing-safe: still run verify to prevent user enumeration
        password_verify($password, '$2y$12$invalidhashinvalidhashinvalidhash');
        jsonResponse(['error' => 'Invalid username or password'], 401);
    }

    if (!password_verify($password, (string)$user['password'])) {
        jsonResponse(['error' => 'Invalid username or password'], 401);
    }

    // Update last login
    $col->updateOne(
        ['_id' => $user['_id']],
        ['$set' => ['last_login' => new \MongoDB\BSON\UTCDateTime(time() * 1000)]]
    );

    $userData = [
        'username'     => (string)$user['_id'],
        'display_name' => (string)($user['display_name'] ?? $user['_id']),
        'email'        => (string)$user['email'],
    ];

    createSession($userData);

    jsonResponse([
        'ok'       => true,
        'user'     => $userData,
        'has_keys' => (bool)($user['has_keys'] ?? false),
        'profile'  => BASE_URL . '/u/' . $user['_id'],
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Login failed. Please try again.'], 500);
}
