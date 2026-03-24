<?php
/**
 * POST /api/vault_share.php
 *
 * Generate a new shortlink for a vault file
 * or share it with a specific registered username.
 *
 * Request JSON:
 * {
 *   "vault_id":    "vf-xxxxxxxxxx",
 *   "action":      "new_link" | "share_with" | "unshare",
 *   "username":    "alice"    // only for share_with / unshare
 * }
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    jsonResponse(['error' => 'Method not allowed'], 405);

$user = requireAuth();
rateLimit(getClientIp(), 30, 60);

$body    = json_decode(file_get_contents('php://input'), true);
$vaultId = trim($body['vault_id'] ?? '');
$action  = trim($body['action']   ?? '');
$target  = strtolower(trim($body['username'] ?? ''));

if (empty($vaultId) || !preg_match('/^vf-[23456789abcdefghjkmnpqrstuvwxyz]{10}$/', $vaultId)) {
    jsonResponse(['error' => 'Invalid vault_id'], 400);
}

$allowedActions = ['new_link', 'share_with', 'unshare'];
if (!in_array($action, $allowedActions)) {
    jsonResponse(['error' => 'Invalid action. Use: new_link, share_with, unshare'], 400);
}

try {
    $vaultCol = getCollection('vault_files');
    $doc      = $vaultCol->findOne(['_id' => $vaultId]);

    if (!$doc) jsonResponse(['error' => 'File not found'], 404);
    if ((string)$doc['owner'] !== $user['username']) jsonResponse(['error' => 'Access denied'], 403);

    $now = new \MongoDB\BSON\UTCDateTime(time() * 1000);

    // ── Generate new shortlink ─────────────────────────────────────────────
    if ($action === 'new_link') {
        $newShareId = shortId(8);

        // Add to vault shortlinks array
        $vaultCol->updateOne(
            ['_id' => $vaultId],
            [
                '$push' => ['shortlinks' => $newShareId],
                '$set'  => ['updated_at' => $now],
            ]
        );

        // Create message entry for /m/:id routing
        getCollection(COL_MESSAGES)->insertOne([
            '_id'             => $newShareId,
            'vault_id'        => $vaultId,
            'vault_file'      => true,
            'sender'          => $user['username'],
            'burn_after_read' => false,
            'read'            => false,
            'created_at'      => $now,
            'expires_at'      => null,
            'has_file'        => true,
            'file_id'         => (string)$doc['file_id'],
            'ciphertext'      => null,
        ]);

        jsonResponse([
            'ok'        => true,
            'action'    => 'new_link',
            'share_id'  => $newShareId,
            'shortlink' => BASE_URL . '/m/' . $newShareId,
        ]);
    }

    // ── Share with specific user ───────────────────────────────────────────
    if ($action === 'share_with') {
        if (empty($target)) jsonResponse(['error' => 'Username required'], 400);
        if ($target === $user['username']) jsonResponse(['error' => 'Cannot share with yourself'], 400);

        // Check target user exists
        $targetUser = getCollection(COL_USERS)->findOne(['_id' => $target]);
        if (!$targetUser) jsonResponse(['error' => "User '{$target}' not found on PQCServer"], 404);

        // Add to shared_with if not already there
        $sharedWith = (array)($doc['shared_with'] ?? []);
        if (in_array($target, $sharedWith)) {
            jsonResponse(['error' => "Already shared with '{$target}'"], 409);
        }

        $vaultCol->updateOne(
            ['_id' => $vaultId],
            [
                '$addToSet' => ['shared_with' => $target],
                '$set'      => ['updated_at'  => $now],
            ]
        );

        jsonResponse([
            'ok'          => true,
            'action'      => 'share_with',
            'shared_with' => $target,
            'display_name'=> (string)($targetUser['display_name'] ?? $target),
        ]);
    }

    // ── Unshare ────────────────────────────────────────────────────────────
    if ($action === 'unshare') {
        if (empty($target)) jsonResponse(['error' => 'Username required'], 400);

        $vaultCol->updateOne(
            ['_id' => $vaultId],
            [
                '$pull' => ['shared_with' => $target],
                '$set'  => ['updated_at'  => $now],
            ]
        );

        jsonResponse(['ok' => true, 'action' => 'unshare', 'unshared' => $target]);
    }

} catch (\Exception $e) {
    jsonResponse(['error' => 'Share operation failed: ' . $e->getMessage()], 500);
}
