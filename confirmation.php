<?php
// Page ouverte par le lien du mail de confirmation : confirmation.php?jeton=...
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/auth.php';

$token = $_GET['jeton'] ?? '';

if (preg_match('/^[0-9a-f]{64}$/', $token) && confirmEmail(getDatabaseConnection(), $token)) {
    setFlash('Ton adresse mail est confirmée, tu peux te connecter.');
} else {
    setFlash('Ce lien de confirmation est invalide ou a déjà été utilisé.');
}

header('Location: connexion.php');
exit;
