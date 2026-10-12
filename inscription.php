<?php
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/mailer.php';

startSession();

if (currentUser()) {
    header('Location: index.php');
    exit;
}

$pageTitle  = 'Inscription';
$activePage = 'inscription';

$data   = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $errors['global'] = 'La session a expiré, recharge la page et réessaie.';
    } else {
        [$data, $errors] = validateRegistration($_POST);
    }

    if ($errors === []) {
        try {
            $db = getDatabaseConnection();

            if (emailExists($db, $data['email'])) {
                $errors['email'] = 'Un compte existe déjà avec cette adresse mail.';
            } else {
                createAdherentAccount($db, $data);

                // Redirection pour éviter une double inscription si on rafraîchit la page
                $_SESSION['inscription'] = [
                    'prenom'   => $data['prenom'],
                    'email'    => $data['email'],
                    'mailSent' => sendAccountCreatedMail($data['email'], $data['prenom']),
                ];
                header('Location: inscription.php');
                exit;
            }
        } catch (PDOException $e) {
            // 23505 : adresse mail déjà prise (contrainte unique)
            if ($e->getCode() === '23505') {
                $errors['email'] = 'Un compte existe déjà avec cette adresse mail.';
            } else {
                error_log('Inscription : ' . $e->getMessage());
                $errors['global'] = 'Impossible de créer le compte pour le moment, réessaie plus tard.';
            }
        }
    }
}

$success = $_SESSION['inscription'] ?? null;
unset($_SESSION['inscription']);

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container form-page">
        <?php if ($success): ?>
            <div class="form-card success-card">
                <p class="tag">Compte créé</p>
                <h1>Bienvenue <span class="highlight"><?= htmlspecialchars($success['prenom']) ?></span></h1>
                <?php if ($success['mailSent']): ?>
                    <p class="lead">Un mail de confirmation vient d'être envoyé à <strong><?= htmlspecialchars($success['email']) ?></strong>.</p>
                <?php else: ?>
                    <p class="lead">Ton compte est bien créé, mais le mail de confirmation n'a pas pu être envoyé.</p>
                <?php endif; ?>
                <a href="connexion.php" class="btn btn-lg">Se connecter</a>
            </div>
        <?php else: ?>
            <p class="tag">Rejoins la salle</p>
            <h1>Créer mon <span class="highlight">compte</span></h1>

            <form class="form-card" method="post" action="inscription.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <?php if (isset($errors['global'])): ?>
                    <p class="form-alert" role="alert"><?= htmlspecialchars($errors['global']) ?></p>
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label for="nom">Nom</label>
                        <input type="text" id="nom" name="nom" value="<?= old($data, 'nom') ?>"
                               autocomplete="family-name" maxlength="100" required>
                        <?= fieldError($errors, 'nom') ?>
                    </div>

                    <div class="field">
                        <label for="prenom">Prénom</label>
                        <input type="text" id="prenom" name="prenom" value="<?= old($data, 'prenom') ?>"
                               autocomplete="given-name" maxlength="100" required>
                        <?= fieldError($errors, 'prenom') ?>
                    </div>

                    <div class="field">
                        <label for="email">Adresse mail</label>
                        <input type="email" id="email" name="email" value="<?= old($data, 'email') ?>"
                               autocomplete="email" maxlength="191" required>
                        <?= fieldError($errors, 'email') ?>
                    </div>

                    <div class="field">
                        <label for="telephone">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone" value="<?= old($data, 'telephone') ?>"
                               autocomplete="tel" placeholder="06 12 34 56 78" required>
                        <?= fieldError($errors, 'telephone') ?>
                    </div>

                    <div class="field field-full">
                        <label for="date_naissance">Date de naissance</label>
                        <input type="date" id="date_naissance" name="date_naissance"
                               value="<?= old($data, 'date_naissance') ?>" max="<?= date('Y-m-d') ?>"
                               autocomplete="bday" required>
                        <?= fieldError($errors, 'date_naissance') ?>
                    </div>

                    <div class="field">
                        <label for="mot_de_passe">Mot de passe</label>
                        <input type="password" id="mot_de_passe" name="mot_de_passe"
                               autocomplete="new-password" minlength="8" required>
                        <?= fieldError($errors, 'mot_de_passe') ?>
                    </div>

                    <div class="field">
                        <label for="confirmation">Confirmer le mot de passe</label>
                        <input type="password" id="confirmation" name="confirmation"
                               autocomplete="new-password" minlength="8" required>
                        <?= fieldError($errors, 'confirmation') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-lg btn-block">Créer mon compte</button>

                <p class="form-switch">Déjà un compte ? <a href="connexion.php">Se connecter</a></p>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
