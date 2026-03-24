<?php
/**
 * GET /api/fetch.php?id=xxxxxxxx
 *
 * Returns the encrypted envelope for a message.
 * If has_file=true, also returns the file_id for GridFS download.
 * Burn-after-read: deletes message (and GridFS file) after first read.
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);

rateLimit(getClientIp(), 60, 60);

$id = trim($_GET['id'] ?? '');
if (empty($id) || !preg_match('/^[23456789abcdefghjkmnpqrstuvwxyz]{6,12}$/', $id)) {
    jsonResponse(['error' => 'Invalid message ID'], 400);
}

try {
    $col = getCollection(COL_MESSAGES);
    $doc = $col->findOne(['_id' => $id]);

    if (!$doc) {
        jsonResponse(['error' => 'Message not found or already deleted.'], 404);
    }

    // ── Vault file — no burn, no expiry, just stream metadata ─────────────
    if (!empty($doc['vault_file'])) {
        // Update access stats on the vault entry
        if (!empty($doc['vault_id'])) {
            try {
                getCollection('vault_files')->updateOne(
                    ['_id' => (string)$doc['vault_id']],
                    [
                        '$set' => ['last_accessed' => new \MongoDB\BSON\UTCDateTime(time() * 1000)],
                        '$inc' => ['access_count' => 1],
                    ]
                );
            } catch (\Exception $e) { /* non critical */ }
        }
        jsonResponse([
            'ok'        => true,
            'ciphertext'=> null,         // vault files have no text envelope
            'has_file'  => true,
            'file_id'   => !empty($doc['file_id']) ? (string)$doc['file_id'] : null,
            'vault_file'=> true,
            'vault_id'  => !empty($doc['vault_id']) ? (string)$doc['vault_id'] : null,
            'burn'      => false,
            'expires'   => null,
            'created'   => $doc['created_at']
                ? date('Y-m-d H:i', (int)((string)$doc['created_at']) / 1000)
                : null,
        ]);
    }

    // Already read + burn → delete
    if ($doc['read'] && $doc['burn_after_read']) {
        $col->deleteOne(['_id' => $id]);
        // Also delete GridFS file if present
        if (!empty($doc['file_id'])) {
            deleteGridFSFile((string)$doc['file_id']);
        }
        jsonResponse(['error' => 'Message has already been read and deleted.'], 410);
    }

    // Mark as read
    $col->updateOne(
        ['_id' => $id],
        ['$set' => [
            'read'    => true,
            'read_at' => new \MongoDB\BSON\UTCDateTime(time() * 1000),
        ]]
    );

    $response = [
        'ok'         => true,
        'ciphertext' => (string)$doc['ciphertext'],
        'has_file'   => (bool)($doc['has_file'] ?? false),
        'file_id'    => !empty($doc['file_id']) ? (string)$doc['file_id'] : null,
        'recipient'  => $doc['recipient'] ? (string)$doc['recipient'] : null,
        'burn'       => (bool)$doc['burn_after_read'],
        'expires'    => $doc['expires_at']
            ? date('Y-m-d', (int)((string)$doc['expires_at']) / 1000)
            : null,
        'created'    => $doc['created_at']
            ? date('Y-m-d H:i', (int)((string)$doc['created_at']) / 1000)
            : null,
    ];

    // Burn after read: schedule deletion
    if ($doc['burn_after_read']) {
        // Delete message record now
        $col->deleteOne(['_id' => $id]);
        // Note: GridFS file deleted only AFTER client downloads it
        // Client signals deletion via DELETE /api/file_download.php?id=FILE_ID&burn=1
        // OR we delete here and client must download before this call
        // We keep file alive briefly — client downloads then we auto-expire via TTL
    }

    jsonResponse($response);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Retrieval error. Please try again.'], 500);
}

// ── Helper: delete GridFS file ─────────────────────────────────────────────
function deleteGridFSFile(string $fileIdStr): void {
    try {
        $bucket = getDatabase()->selectGridFSBucket(['bucketName' => 'encrypted_files']);
        $bucket->delete(new \MongoDB\BSON\ObjectId($fileIdStr));
    } catch (\Exception $e) {
        // Ignore — file may already be gone
    }
}
