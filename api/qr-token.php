<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/qrcode.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Non connecté']);
    exit;
}

$token = generateAccessToken(getDatabaseConnection(), $user['id_compte']);
if ($token === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Compte introuvable']);
    exit;
}

echo json_encode(['token' => $token]);
