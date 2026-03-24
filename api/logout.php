<?php
// POST /api/logout.php — destroy session
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') jsonResponse([]);
destroySession();
jsonResponse(['ok' => true]);
