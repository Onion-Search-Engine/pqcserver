<?php
/**
 * DELETE /api/vault_delete.php
 *
 * Permanently deletes a file from the user's vault.
 * Removes: vault_files entry + GridFS file + all shortlinks in messages collection.
 *
 * Request JSON:
 * { "vault_id": "vf-xxxxxxxxxx" }
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = requireAuth();
rateLimit(getClientIp(), 30, 60);

$body    = json_decode(file_get_contents('php://input'), true);
$vaultId = trim($body['vault_id'] ?? '');

if (empty($vaultId) || !preg_match('/^vf-[23456789abcdefghjkmnpqrstuvwxyz]{10}$/', $vaultId)) {
    jsonResponse(['error' => 'Invalid vault_id'], 400);
}

try {
    $vaultCol = getCollection('vault_files');

    // Find and verify ownership
    $doc = $vaultCol->findOne(['_id' => $vaultId]);
    if (!$doc) {
        jsonResponse(['error' => 'File not found'], 404);
    }
    if ((string)$doc['owner'] !== $user['username']) {
        jsonResponse(['error' => 'Access denied'], 403);
    }

    $fileId     = (string)$doc['file_id'];
    $shortlinks = (array)($doc['shortlinks'] ?? []);
    $sizeBytes  = (int)($doc['size_bytes'] ?? 0);

    // 1. Delete GridFS file
    try {
        $bucket = getDatabase()->selectGridFSBucket(['bucketName' => 'encrypted_files']);
        $bucket->delete(new \MongoDB\BSON\ObjectId($fileId));
    } catch (\Exception $e) {
        // File may already be gone — continue
    }

    // 2. Delete all shortlinks from messages collection
    if (!empty($shortlinks)) {
        getCollection(COL_MESSAGES)->deleteMany([
            '_id' => ['$in' => $shortlinks],
        ]);
    }

    // 3. Delete vault entry
    $vaultCol->deleteOne(['_id' => $vaultId]);

    // 4. Update user stats
    getCollection(COL_USERS)->updateOne(
        ['_id' => $user['username']],
        ['$inc' => ['vault_count' => -1, 'vault_bytes' => -$sizeBytes]]
    );

    jsonResponse(['ok' => true, 'deleted' => $vaultId]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Delete failed: ' . $e->getMessage()], 500);
}
