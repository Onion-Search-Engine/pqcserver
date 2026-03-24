<?php
/**
 * GET /api/notary_verify.php?id=NTR-xxxxxxxxxx
 *
 * Returns the full Notary Receipt for public verification.
 * No authentication required — receipts are public by design.
 * Anyone with the receipt ID can retrieve and verify it.
 */

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     jsonResponse(['error' => 'Method not allowed'], 405);

rateLimit(getClientIp(), 120, 60);

$id = trim($_GET['id'] ?? '');

// Validate receipt ID format: NTR- followed by 10 shortId chars
if (empty($id) || !preg_match('/^NTR-[23456789abcdefghjkmnpqrstuvwxyz]{10}$/', $id)) {
    jsonResponse(['error' => 'Invalid receipt ID format'], 400);
}

try {
    $doc = getCollection('notary_receipts')->findOne(['_id' => $id]);

    if (!$doc) {
        jsonResponse(['error' => 'Receipt not found', 'not_found' => true], 404);
    }

    // Return full receipt JSON
    $receipt = json_decode((string)$doc['receipt_json'], true);

    jsonResponse([
        'ok'         => true,
        'receipt_id' => $id,
        'receipt'    => $receipt,
        'issued_at'  => $doc['issued_at']
            ? date('Y-m-d H:i:s', (int)((string)$doc['issued_at']) / 1000) . ' UTC'
            : null,
    ]);

} catch (\Exception $e) {
    jsonResponse(['error' => 'Lookup error. Please try again.'], 500);
}
