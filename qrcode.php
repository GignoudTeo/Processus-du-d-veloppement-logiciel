<?php
session_start();
require_once __DIR__ . '/includes/config.php';

$pageTitle    = 'Mon QR code';
$activePage   = 'qrcode';
$extraScripts = [
    'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    'assets/js/qrcode.js',
];

// 1. Récupération de l'ID utilisateur (depuis la session, ou MEMBER_ID par défaut)
$userId = $_SESSION['user_id'] ?? MEMBER_ID;

// 2. Récupération du hash du mot de passe en base de données
try {
    $db = getDatabaseConnection();
    // On va chercher le hash dans la table centrale 'comptes'
    $stmt = $db->prepare('SELECT mot_de_passe_hash FROM comptes WHERE id_compte = :id');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        die("Erreur : Utilisateur introuvable.");
    }
} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}

// 3. Création du Token unique (Heure + Fragment du mot de passe)
// timeWindow change toutes les 60 secondes (QR_VALIDITY)
$timeWindow = floor(time() / QR_VALIDITY); 
// On prend les 10 premiers caractères du hash du mot de passe comme "secret" utilisateur
$secretPart = substr($user['mot_de_passe_hash'], 0, 10); 

// On assemble les données et on les sécurise avec la clé globale de l'application
$dataToHash = $userId . '|' . $timeWindow . '|' . $secretPart;
$token = hash_hmac('sha256', $dataToHash, QR_SECRET);

// Le payload final qui sera lu par le lecteur de la salle (ID + Token de vérification)
$qrPayload = $userId . ':' . $token;

require __DIR__ . '/includes/header.php';
?>

<section class="qr-section">
    <div class="container qr-page">
        <p class="tag">Accès salle</p>
        <h1>Mon QR code</h1>
        <p class="lead">Présente ce code devant la borne à l'entrée de la salle.</p>

        <div class="qr-card"
             id="qr-card"
             data-token="<?= htmlspecialchars($qrPayload) ?>"
             data-validity="<?= QR_VALIDITY ?>">
            <div class="qr-card-head">
                <span class="logo logo-small">FIT<span>PASS</span></span>
                <span class="qr-member">Adhérent n° <?= htmlspecialchars($userId) ?></span>
            </div>

            <div id="qr-code" class="qr-code" aria-label="QR code d'accès"></div>

            <div class="qr-progress">
                <div class="qr-progress-bar" id="qr-progress-bar"></div>
            </div>
            <p class="qr-timer">
                Nouveau code dans <strong id="qr-countdown"><?= QR_VALIDITY ?></strong> s
            </p>

            <button type="button" class="btn btn-outline btn-block" id="qr-refresh">Régénérer maintenant</button>
        </div>

        <p class="qr-help">
            Pour des raisons de sécurité, le code change automatiquement toutes les <?= QR_VALIDITY ?> secondes.
            Une capture d'écran ne fonctionnera donc pas.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>