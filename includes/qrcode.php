<?php
// Jeton : FITPASS:<id>:<expiration>:<signature>

require_once __DIR__ . '/config.php';

function generateAccessToken(int $userId): array
{
    $expiresAt = time() + QR_VALIDITY;
    $signature = hash_hmac('sha256', $userId . ':' . $expiresAt, QR_SECRET);

    return [
        'token'     => 'FITPASS:' . $userId . ':' . $expiresAt . ':' . $signature,
        'expiresAt' => $expiresAt,
    ];
}

// Renvoie l'id de l'adhérent, ou null si le code est faux ou expiré
function verifyAccessToken(string $token): ?int
{
    $parts = explode(':', $token);
    if (count($parts) !== 4 || $parts[0] !== 'FITPASS') {
        return null;
    }

    [, $userId, $expiresAt, $signature] = $parts;
    $expected = hash_hmac('sha256', $userId . ':' . $expiresAt, QR_SECRET);

    if (!hash_equals($expected, $signature) || (int) $expiresAt < time()) {
        return null;
    }

    return (int) $userId;
}
