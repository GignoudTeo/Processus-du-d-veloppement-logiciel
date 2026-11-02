<?php
require_once __DIR__ . '/auth.php';

$pageTitle  = $pageTitle ?? APP_NAME;
$activePage = $activePage ?? '';
$user       = currentUser();
$flash      = takeFlash();

$navLinks = [
    'accueil' => ['label' => 'Accueil', 'href' => 'index.php'],
    'sports'  => ['label' => 'Nos sports', 'href' => 'sports.php'],
];
if ($user) {
    $navLinks['planning'] = ['label' => 'Planning', 'href' => 'planning.php'];
}
$navLinks['qrcode'] = ['label' => 'Mon QR code', 'href' => 'qrcode.php'];

$isAdmin = $user && $user['role'] === 'admin';
$roleLabels = ['adherent' => 'Adhérent', 'coach' => 'Coach', 'admin' => 'Administrateur'];

// Pastille du menu Admin : nombre de demandes coach en attente
$pendingRequests = 0;
if ($isAdmin) {
    require_once __DIR__ . '/coach_requests.php';
    try {
        $pendingRequests = countPendingCoachRequests(getDatabaseConnection());
    } catch (PDOException $e) {
        error_log('Header : ' . $e->getMessage());
    }
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

        <nav class="main-nav" aria-label="Menu principal">
            <div class="nav-links">
                <?php foreach ($navLinks as $key => $link): ?>
                    <a href="<?= $link['href'] ?>" class="<?= $key === $activePage ? 'active' : '' ?>"><?= $link['label'] ?></a>
                <?php endforeach; ?>
            </div>

            <div class="nav-actions">
                <?php if ($isAdmin): ?>
                    <details class="nav-dropdown<?= in_array($activePage, ['administration', 'demandes'], true) ? ' is-current' : '' ?>">
                        <summary>
                            Admin
                            <?php if ($pendingRequests > 0): ?><span class="nav-badge"><?= $pendingRequests ?></span><?php endif; ?>
                        </summary>
                        <div class="nav-dropdown-menu">
                            <p class="nav-dropdown-title">Administration</p>
                            <a href="administration.php" class="<?= $activePage === 'administration' ? 'active' : '' ?>">Utilisateurs</a>
                            <a href="demandes-coach.php" class="<?= $activePage === 'demandes' ? 'active' : '' ?>">
                                Demandes coach
                                <?php if ($pendingRequests > 0): ?><span class="nav-badge"><?= $pendingRequests ?></span><?php endif; ?>
                            </a>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($user): ?>
                    <details class="nav-dropdown nav-account<?= $activePage === 'compte' ? ' is-current' : '' ?>">
                        <summary>
                            <span class="nav-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['prenom'] ?: '?', 0, 1))) ?></span>
                            <?= htmlspecialchars($user['prenom']) ?>
                        </summary>
                        <div class="nav-dropdown-menu">
                            <p class="nav-dropdown-title"><?= $roleLabels[$user['role']] ?? htmlspecialchars($user['role']) ?></p>
                            <a href="compte.php" class="<?= $activePage === 'compte' ? 'active' : '' ?>">Mon compte</a>
                            <?php if ($user['role'] === 'adherent'): ?>
                                <a href="devenir-coach.php">Devenir coach</a>
                            <?php endif; ?>
                            <form method="post" action="deconnexion.php" class="nav-logout">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <button type="submit">Déconnexion</button>
                            </form>
                        </div>
                    </details>
                <?php else: ?>
                    <a href="connexion.php" class="nav-login<?= $activePage === 'connexion' ? ' active' : '' ?>">Connexion</a>
                    <a href="inscription.php" class="btn btn-small">Inscription</a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>
<main>
<?php if ($flash): ?>
    <div class="flash" role="status">
        <div class="container"><?= htmlspecialchars($flash) ?></div>
    </div>
<?php endif; ?>
