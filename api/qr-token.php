<?php
require_once __DIR__ . '/../includes/qrcode.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(generateAccessToken(MEMBER_ID));
