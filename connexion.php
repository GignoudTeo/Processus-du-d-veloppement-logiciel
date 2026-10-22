<?php
require_once __DIR__ . '/includes/auth.php';

startSession();

if (currentUser()) {
    header('Location: index.php');
    exit;
}

$pageTitle  = 'Connexion';
$activePage = 'connexion';

$email = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['mot_de_passe'] ?? '';

    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'La session a expiré, recharge la page et réessaie.';
    } elseif ($email === '' || $password === '') {
        $error = 'Remplis ton adresse mail et ton mot de passe.';
    } else {
        try {
            $result = login(getDatabaseConnection(), $email, $password);

            if ($result === LOGIN_OK) {
                header('Location: index.php');
                exit;
            }
            // Même message que l'email existe ou non, pour ne pas révéler les comptes existants
            $error = $result === LOGIN_NOT_CONFIRMED
                ? 'Ton compte n\'est pas encore activé : clique sur le lien reçu par mail.'
                : 'Adresse mail ou mot de passe incorrect.';
        } catch (PDOException $e) {
            error_log('Connexion : ' . $e->getMessage());
            $error = 'Connexion impossible pour le moment, réessaie plus tard.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container form-page form-page-narrow">
        <p class="tag">Espace adhérent</p>
        <h1>Se <span class="highlight">connecter</span></h1>

        <form class="form-card" method="post" action="connexion.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <?php if ($error): ?>
                <p class="form-alert" role="alert"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <div class="form-grid form-grid-single">
                <div class="field">
                    <label for="email">Adresse mail</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>"
                           autocomplete="email" required autofocus>
                </div>

                <div class="field">
                    <label for="mot_de_passe">Mot de passe</label>
                    <input type="password" id="mot_de_passe" name="mot_de_passe"
                           autocomplete="current-password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-lg btn-block">Se connecter</button>

            <p class="form-switch">Pas encore de compte ? <a href="inscription.php">Créer un compte</a></p>
        </form>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
