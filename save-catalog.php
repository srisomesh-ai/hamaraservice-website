<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
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
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!$body || !isset($body['catalog'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }
    if (file_put_contents($file, json_encode($body['catalog'])) !== false) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot write file — check folder permissions']);
    }
    exit;
}
