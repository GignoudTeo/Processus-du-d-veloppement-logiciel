<?php
require_once __DIR__ . '/auth.php';

$pageTitle  = $pageTitle ?? APP_NAME;
$activePage = $activePage ?? '';
$user       = currentUser();
$flash      = takeFlash();

$navLinks = [
    'accueil' => ['label' => 'Accueil', 'href' => 'index.php'],
    'sports'  => ['label' => 'Nos sports', 'href' => 'sports.php'],
    'qrcode'  => ['label' => 'Mon QR code', 'href' => 'qrcode.php'],
];
if ($user) {
    $navLinks['planning'] = ['label' => 'Planning', 'href' => 'planning.php'];
    $navLinks['compte']   = ['label' => 'Mon compte', 'href' => 'compte.php'];
    if ($user['role'] === 'admin') {
        $navLinks['administration'] = ['label' => 'Administration', 'href' => 'administration.php'];
    }
} else {
    $navLinks['connexion']   = ['label' => 'Connexion', 'href' => 'connexion.php'];
    $navLinks['inscription'] = ['label' => 'Inscription', 'href' => 'inscription.php'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="index.php" class="logo">
            FIT<span>PASS</span>
        </a>

        <button class="nav-toggle" aria-label="Ouvrir le menu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>

        <nav class="main-nav">
            <?php foreach ($navLinks as $key => $link): ?>
                <a href="<?= $link['href'] ?>" class="<?= $key === $activePage ? 'active' : '' ?>">
                    <?= $link['label'] ?>
                </a>
            <?php endforeach; ?>

            <?php if ($user): ?>
                <span class="nav-user">Bonjour <?= htmlspecialchars($user['prenom']) ?></span>
                <form method="post" action="deconnexion.php" class="nav-logout">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-outline btn-small">Déconnexion</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
<?php if ($flash): ?>
    <div class="flash" role="status">
        <div class="container"><?= htmlspecialchars($flash) ?></div>
    </div>
<?php endif; ?>
