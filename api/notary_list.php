<?php
/**
 * GET /api/notary_list.php
 *
 * Returns the list of Notary Receipts for the authenticated user.
 * Requires login.
 *
 * Query params:
 *   ?limit=20&skip=0
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     jsonResponse(['error' => 'Method not allowed'], 405);

$user = requireAuth();

$limit = min((int)($_GET['limit'] ?? 20), 100);
$skip  = max((int)($_GET['skip']  ?? 0), 0);

try {
    $col     = getCollection('notary_receipts');
    $total   = $col->countDocuments(['signer_username' => $user['username']]);
    $cursor  = $col->find(
        ['signer_username' => $user['username']],
        [
            'sort'       => ['issued_at' => -1],
            'limit'      => $limit,
            'skip'       => $skip,
            'projection' => [
                '_id'        => 1,
                'filename'   => 1,
                'size_bytes' => 1,
                'mime_type'  => 1,
                'issued_at'  => 1,
                'hash_sha256'=> 1,
            ],
        ]
    );

    $items = [];
    foreach ($cursor as $doc) {
        $items[] = [
            'receipt_id' => (string)$doc['_id'],
            'filename'   => (string)($doc['filename']   ?? 'document'),
            'size_bytes' => (int)($doc['size_bytes']    ?? 0),
            'mime_type'  => (string)($doc['mime_type']  ?? ''),
            'hash_sha256'=> (string)($doc['hash_sha256']?? ''),
            'issued_at'  => $doc['issued_at']
                ? date('Y-m-d H:i', (int)((string)$doc['issued_at']) / 1000) . ' UTC'
                : null,
            'verify_url' => BASE_URL . '/verify/' . $doc['_id'],
        ];
    }

    jsonResponse([
        'ok'    => true,
        'total' => $total,
        'items' => $items,
        'limit' => $limit,
        'skip'  => $skip,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Could not retrieve receipts.'], 500);
}
