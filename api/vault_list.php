<?php
/**
 * GET /api/vault_list.php
 *
 * Returns the authenticated user's vault file list.
 * Supports pagination and filtering.
 *
 * Query params:
 *   page    = 1 (1-based)
 *   limit   = 20 (max 50)
 *   tag     = filter by tag
 *   search  = search in filename/note
 *   sort    = created_at|filename|size_bytes (default: created_at)
 *   order   = desc|asc (default: desc)
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     jsonResponse(['error' => 'Method not allowed'], 405);

$user = requireAuth();

rateLimit(getClientIp(), 120, 60);

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$tag    = trim($_GET['tag']    ?? '');
$search = trim($_GET['search'] ?? '');
$sort   = in_array($_GET['sort'] ?? '', ['created_at','filename','size_bytes'])
            ? $_GET['sort'] : 'created_at';
$order  = ($_GET['order'] ?? 'desc') === 'asc' ? 1 : -1;

$skip = ($page - 1) * $limit;

// Build query
$query = ['owner' => $user['username']];
if ($tag)    $query['tags']     = $tag;
if ($search) $query['filename'] = ['$regex' => preg_quote($search), '$options' => 'i'];

try {
    $col   = getCollection('vault_files');
    $total = $col->countDocuments($query);

    $cursor = $col->find(
        $query,
        [
            'sort'       => [$sort => $order],
            'skip'       => $skip,
            'limit'      => $limit,
            'projection' => [
                '_id'            => 1,
                'filename'       => 1,
                'mime_type'      => 1,
                'size_bytes'     => 1,
                'size_encrypted' => 1,
                'shortlinks'     => 1,
                'shared_with'    => 1,
                'tags'           => 1,
                'note'           => 1,
                'created_at'     => 1,
                'last_accessed'  => 1,
                'access_count'   => 1,
            ]
        ]
    );

    $files = [];
    foreach ($cursor as $doc) {
        $shortlinks = (array)($doc['shortlinks'] ?? []);
        $files[] = [
            'vault_id'      => (string)$doc['_id'],
            'filename'      => (string)$doc['filename'],
            'mime_type'     => (string)$doc['mime_type'],
            'size_bytes'    => (int)$doc['size_bytes'],
            'size_encrypted'=> (int)($doc['size_encrypted'] ?? 0),
            'shortlink'     => !empty($shortlinks)
                                ? BASE_URL . '/m/' . $shortlinks[0]
                                : null,
            'share_id'      => !empty($shortlinks) ? $shortlinks[0] : null,
            'shared_with'   => (array)($doc['shared_with'] ?? []),
            'tags'          => (array)($doc['tags'] ?? []),
            'note'          => (string)($doc['note'] ?? ''),
            'created_at'    => $doc['created_at']
                ? date('Y-m-d H:i', (int)((string)$doc['created_at']) / 1000)
                : null,
            'last_accessed' => $doc['last_accessed']
                ? date('Y-m-d H:i', (int)((string)$doc['last_accessed']) / 1000)
                : null,
            'access_count'  => (int)($doc['access_count'] ?? 0),
        ];
    }

    // Get vault stats
    $statsResult = $col->aggregate([
        ['$match'  => ['owner' => $user['username']]],
        ['$group'  => [
            '_id'        => null,
            'total_files'=> ['$sum' => 1],
            'total_bytes'=> ['$sum' => '$size_bytes'],
        ]],
    ])->toArray();

    $stats = $statsResult[0] ?? ['total_files' => 0, 'total_bytes' => 0];

    jsonResponse([
        'ok'    => true,
        'files' => $files,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
            'has_more'    => ($skip + $limit) < $total,
        ],
        'stats' => [
            'total_files' => (int)($stats['total_files'] ?? 0),
            'total_bytes' => (int)($stats['total_bytes'] ?? 0),
        ],
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Could not load vault: ' . $e->getMessage()], 500);
}
