<?php
// Contenu du QR code : <id_compte>:<signature>
// La signature change toutes les QR_VALIDITY secondes et dépend du mot de passe du compte :
// changer de mot de passe rend les anciens QR codes inutilisables.

require_once __DIR__ . '/config.php';

function getPasswordHash(PDO $db, int $accountId): ?string
{
    $statement = $db->prepare('SELECT mot_de_passe_hash FROM comptes WHERE id_compte = :id');
    $statement->execute(['id' => $accountId]);
    $hash = $statement->fetchColumn();

    return $hash === false ? null : $hash;
}

function qrSignature(int $accountId, int $timeWindow, string $passwordHash): string
{
    return hash_hmac('sha256', $accountId . '|' . $timeWindow . '|' . $passwordHash, QR_SECRET);
}

// null si le compte n'existe plus
function generateAccessToken(PDO $db, int $accountId): ?string
{
    $passwordHash = getPasswordHash($db, $accountId);
    if ($passwordHash === null) {
        return null;
    }

    $timeWindow = intdiv(time(), QR_VALIDITY);

    return $accountId . ':' . qrSignature($accountId, $timeWindow, $passwordHash);
}

// Pour la borne d'entrée : renvoie l'id du compte, ou null si le code est faux ou expiré.
// La minute précédente est acceptée aussi, sinon un code affiché juste avant le changement serait refusé.
function verifyAccessToken(PDO $db, string $token): ?int
{
    if (!preg_match('/^(\d+):([0-9a-f]{64})$/', $token, $matches)) {
        return null;
    }

    $accountId    = (int) $matches[1];
    $passwordHash = getPasswordHash($db, $accountId);
    if ($passwordHash === null) {
        return null;
    }

    $timeWindow = intdiv(time(), QR_VALIDITY);
    foreach ([$timeWindow, $timeWindow - 1] as $window) {
        if (hash_equals(qrSignature($accountId, $window, $passwordHash), $matches[2])) {
            return $accountId;
        }
    }

    return null;
}
