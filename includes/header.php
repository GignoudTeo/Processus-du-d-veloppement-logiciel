<?php
require_once __DIR__ . '/config.php';

$pageTitle  = $pageTitle ?? APP_NAME;
$activePage = $activePage ?? '';

$navLinks = [
    'accueil' => ['label' => 'Accueil', 'href' => 'index.php'],
    'qrcode'  => ['label' => 'Mon QR code', 'href' => 'qrcode.php'],
];
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
    <link rel="stylesheet" href="assets/css/style.css">
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
        </nav>
    </div>
</header>
<main>
