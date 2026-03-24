<?php
// POST /api/register.php  — create new account (username + email + password)
// GET  /api/register.php?check=username — check username availability

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

// ─── GET: check username availability ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $username = strtolower(trim($_GET['check'] ?? ''));
    if (empty($username)) jsonResponse(['error' => 'Missing username'], 400);
    $col    = getCollection(COL_USERS);
    $exists = $col->countDocuments(['_id' => $username]) > 0;
    jsonResponse(['available' => !$exists]);
}

// ─── POST: register new account ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 5, 60);

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) jsonResponse(['error' => 'Invalid JSON'], 400);

$username    = strtolower(trim($body['username']     ?? ''));
$email       = strtolower(trim($body['email']        ?? ''));
$password    = trim($body['password']                ?? '');
$displayName = trim($body['display_name']            ?? '');

// Validate username
if (empty($username) || !preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
    jsonResponse(['error' => 'Username must be 3–32 characters: letters, numbers, underscore only'], 400);
}

// Validate email
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'A valid email address is required'], 400);
}

// Validate password
if (strlen($password) < 8) {
    jsonResponse(['error' => 'Password must be at least 8 characters'], 400);
}

// Reserved usernames
$reserved = ['admin','root','support','help','api','www','mail','info','abuse','security','pqc','system','pqcserver'];
if (in_array($username, $reserved)) {
    jsonResponse(['error' => 'This username is reserved'], 400);
}

try {
    $col = getCollection(COL_USERS);

    // Check username not taken
    if ($col->countDocuments(['_id' => $username]) > 0) {
        jsonResponse(['error' => 'Username already taken'], 409);
    }

    // Check email not already registered
    if ($col->countDocuments(['email' => $email]) > 0) {
        jsonResponse(['error' => 'An account with this email already exists'], 409);
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $col->insertOne([
        '_id'          => $username,
        'email'        => $email,
        'password'     => $passwordHash,
        'display_name' => $displayName ?: $username,
        'has_keys'     => false,
        'public_key_kem' => null,
        'public_key_dsa' => null,
        'created_at'   => new \MongoDB\BSON\UTCDateTime(time() * 1000),
        'last_login'   => null,
    ]);

    // Auto-login after registration
    $userData = [
        'username'     => $username,
        'display_name' => $displayName ?: $username,
        'email'        => $email,
    ];
    createSession($userData);

    jsonResponse([
        'ok'       => true,
        'username' => $username,
        'redirect' => '/keygen.html',  // go generate keys next
    ]);

} catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
    jsonResponse(['error' => 'Username already taken'], 409);
} catch (\Exception $e) {
    jsonResponse(['error' => 'Registration failed. Please try again.'], 500);
}
