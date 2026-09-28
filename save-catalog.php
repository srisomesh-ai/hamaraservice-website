<?php
// GET  → current catalog.json (public)
// POST → overwrite catalog.json — ADMIN ONLY (X-Admin-Key header, or ?key=)
require_once __DIR__ . '/api/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Key');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$file = __DIR__ . '/catalog.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($file)) {
        echo file_get_contents($file);
    } else {
        echo '[]';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isAdminRequest()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 2 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'Catalog too large']);
        exit;
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || !isset($body['catalog']) || !is_array($body['catalog'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }
    if (file_put_contents($file, json_encode($body['catalog']), LOCK_EX) !== false) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write file — check folder permissions']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
