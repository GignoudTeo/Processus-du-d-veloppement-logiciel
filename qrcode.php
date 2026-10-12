<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qrcode.php';

$user = requireLogin();

$pageTitle    = 'Mon QR code';
$activePage   = 'qrcode';
$extraScripts = [
    'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    'assets/js/qrcode.js?v=' . filemtime(__DIR__ . '/assets/js/qrcode.js'),
];

$userId    = $user['id_compte'];
$qrPayload = generateAccessToken(getDatabaseConnection(), $userId);

// Compte supprimé entre-temps : on vide la session
if ($qrPayload === null) {
    logout();
    header('Location: connexion.php');
    exit;
}

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
                <span class="qr-member"><?= htmlspecialchars($user['prenom']) ?></span>
            </div>

            <div id="qr-code" class="qr-code" aria-label="QR code d'accès"></div>

            <div class="qr-progress">
                <div class="qr-progress-bar" id="qr-progress-bar"></div>
            </div>
            <p class="qr-timer">
                Nouveau code dans <strong id="qr-countdown"><?= QR_VALIDITY ?></strong> s
            </p>
        </div>

        <p class="qr-help">
            Pour des raisons de sécurité, le code change automatiquement toutes les <?= QR_VALIDITY ?> secondes.
            Une capture d'écran ne fonctionnera donc pas.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>