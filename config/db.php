<?php
// ─── PQCServer — Configuration ────────────────────────────────────────────────
define('MONGO_URI',    getenv('MONGO_URI') ?: 'mongodb://127.0.0.1:27017');
define('MONGO_DB',     getenv('MONGO_DB') ?: 'pqcserver');
define('COL_MESSAGES',   'messages');
define('COL_USERS',      'users');
define('COL_TIMESTAMPS', 'timestamps');
define('COL_SESSIONS', 'sessions');
// ─── Notary collection ────────────────────────────────────────────────────────
define('COL_NOTARY', 'notary_receipts');
define('DEFAULT_TTL',  30 * 24 * 60 * 60);  // 30 days default message TTL (no limits)
define('MAX_TTL',       0);                  // 0 = no maximum TTL enforced
define('SESSION_TTL',   7 * 24 * 60 * 60);  // 7 days session
define('BASE_URL',     getenv('BASE_URL') ?: 'https://pqcserver.com');

// ─── MongoDB connection ────────────────────────────────────────────────────────
function getMongoClient(): \MongoDB\Client {
    static $client = null;
    if ($client === null) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $client = new \MongoDB\Client(MONGO_URI);
    }
    return $client;
}

function getDatabase(): \MongoDB\Database {
    return getMongoClient()->selectDatabase(MONGO_DB);
}

function getCollection(string $name): \MongoDB\Collection {
    return getMongoClient()->selectCollection(MONGO_DB, $name);
}

// ─── JSON response helper ─────────────────────────────────────────────────────
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
    echo json_encode($data);
    exit;
}

// ─── Rate limiting (file-based) ───────────────────────────────────────────────
function rateLimit(string $key, int $max = 30, int $window = 60): void {
    $dir  = sys_get_temp_dir() . '/pqcserver_rl';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $file = $dir . '/' . md5($key);
    $now  = time();
    $data = file_exists($file)
        ? json_decode(file_get_contents($file), true)
        : ['count' => 0, 'reset' => $now + $window];
    if ($now > $data['reset']) $data = ['count' => 0, 'reset' => $now + $window];
    $data['count']++;
    file_put_contents($file, json_encode($data));
    if ($data['count'] > $max) jsonResponse(['error' => 'Rate limit exceeded. Please wait.'], 429);
}

// ─── Short ID generator ───────────────────────────────────────────────────────
function shortId(int $len = 8): string {
    $chars = '23456789abcdefghjkmnpqrstuvwxyz';
    $id = '';
    for ($i = 0; $i < $len; $i++) $id .= $chars[random_int(0, strlen($chars) - 1)];
    return $id;
}

// ─── Get client IP (Cloudflare-aware) ────────────────────────────────────────
function getClientIp(): string {
    return $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
}

// ─── Session management ───────────────────────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TTL,
            'path'     => '/',
            'secure'   => false,   // Cloudflare Flexible: HTTP between CF and server
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('PQCSESS');
        session_start();
    }
}

function getSessionUser(): ?array {
    startSession();
    if (empty($_SESSION['session_token'])) return null;
    $token = $_SESSION['session_token'];
    try {
        $sess = getCollection(COL_SESSIONS)->findOne(['_id' => $token]);
        if (!$sess) { session_destroy(); return null; }
        $exp = (int)((string)$sess['expires_at']) / 1000;
        if ($exp < time()) {
            getCollection(COL_SESSIONS)->deleteOne(['_id' => $token]);
            session_destroy();
            return null;
        }
        return [
            'username'     => (string)$sess['username'],
            'display_name' => (string)$sess['display_name'],
            'email'        => (string)$sess['email'],
        ];
    } catch (\Exception $e) {
        return null;
    }
}

function requireAuth(): array {
    $user = getSessionUser();
    if (!$user) jsonResponse(['error' => 'Not authenticated', 'redirect' => '/login.html'], 401);
    return $user;
}

function createSession(array $user): void {
    $token = bin2hex(random_bytes(32));
    getCollection(COL_SESSIONS)->insertOne([
        '_id'          => $token,
        'username'     => $user['username'],
        'display_name' => $user['display_name'] ?? $user['username'],
        'email'        => $user['email'],
        'ip'           => getClientIp(),
        'created_at'   => new \MongoDB\BSON\UTCDateTime(time() * 1000),
        'expires_at'   => new \MongoDB\BSON\UTCDateTime((time() + SESSION_TTL) * 1000),
    ]);
    startSession();
    $_SESSION['session_token'] = $token;
}

function destroySession(): void {
    startSession();
    $token = $_SESSION['session_token'] ?? '';
    if ($token) {
        try { getCollection(COL_SESSIONS)->deleteOne(['_id' => $token]); } catch (\Exception $e) {}
    }
    session_unset();
    session_destroy();
}
