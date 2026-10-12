<?php
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form.php';

$user = requireLogin();

$pageTitle  = 'Mon compte';
$activePage = 'compte';

$accountId = $user['id_compte'];
$db        = getDatabaseConnection();
$profile   = getAdherentProfile($db, $accountId);

$data           = $profile ?? [];
$profileErrors  = [];
$passwordErrors = [];
$deleteErrors   = [];
$globalError    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
    $action = $_POST['action'] ?? '';

    try {
        if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
            $globalError = 'La session a expiré, recharge la page et réessaie.';
        } elseif ($action === 'profil') {
            [$data, $profileErrors] = validateProfile($_POST);

            if ($profileErrors === [] && emailExists($db, $data['email'], $accountId)) {
                $profileErrors['email'] = 'Un compte existe déjà avec cette adresse mail.';
            }

            if ($profileErrors === []) {
                updateAdherentProfile($db, $accountId, $data);
                $_SESSION['user']['prenom'] = $data['prenom'];
                $_SESSION['user']['email']  = $data['email'];

                setFlash('Tes informations ont été mises à jour.');
                header('Location: compte.php');
                exit;
            }
        } elseif ($action === 'mot_de_passe') {
            if (!checkPassword($db, $accountId, $_POST['actuel'] ?? '')) {
                $passwordErrors['actuel'] = 'Mot de passe actuel incorrect.';
            } else {
                $passwordErrors = validateNewPassword($_POST['mot_de_passe'] ?? '', $_POST['confirmation'] ?? '');
            }

            if ($passwordErrors === []) {
                updatePassword($db, $accountId, $_POST['mot_de_passe']);

                setFlash('Ton mot de passe a été modifié.');
                header('Location: compte.php');
                exit;
            }
        } elseif ($action === 'suppression') {
            if (!checkPassword($db, $accountId, $_POST['mot_de_passe_suppression'] ?? '')) {
                $deleteErrors['mot_de_passe_suppression'] = 'Mot de passe incorrect.';
            } else {
                deleteAccount($db, $accountId);
                logout();

                startSession();
                session_regenerate_id(true);
                setFlash('Ton compte a bien été supprimé.');
                header('Location: index.php');
                exit;
            }
        }
    } catch (PDOException $e) {
        // 23505 : adresse mail prise entre la vérification et l'enregistrement
        if ($e->getCode() === '23505') {
            $profileErrors['email'] = 'Un compte existe déjà avec cette adresse mail.';
        } else {
            error_log('Mon compte : ' . $e->getMessage());
            $globalError = 'Une erreur est survenue, réessaie plus tard.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container form-page">
        <p class="tag">Espace adhérent</p>
        <h1>Mon <span class="highlight">compte</span></h1>

        <?php if (!$profile): ?>
            <p class="lead">La gestion du compte est réservée aux adhérents.</p>
        <?php else: ?>
            <?php if ($globalError): ?>
                <p class="form-alert" role="alert"><?= htmlspecialchars($globalError) ?></p>
            <?php endif; ?>

            <form class="form-card" method="post" action="compte.php" novalidate>
                <h2>Mes informations</h2>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="profil">

                <div class="form-grid">
                    <div class="field">
                        <label for="nom">Nom</label>
                        <input type="text" id="nom" name="nom" value="<?= old($data, 'nom') ?>"
                               autocomplete="family-name" maxlength="100" required>
                        <?= fieldError($profileErrors, 'nom') ?>
                    </div>

                    <div class="field">
                        <label for="prenom">Prénom</label>
                        <input type="text" id="prenom" name="prenom" value="<?= old($data, 'prenom') ?>"
                               autocomplete="given-name" maxlength="100" required>
                        <?= fieldError($profileErrors, 'prenom') ?>
                    </div>

                    <div class="field">
                        <label for="email">Adresse mail</label>
                        <input type="email" id="email" name="email" value="<?= old($data, 'email') ?>"
                               autocomplete="email" maxlength="191" required>
                        <?= fieldError($profileErrors, 'email') ?>
                    </div>

                    <div class="field">
                        <label for="telephone">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone" value="<?= old($data, 'telephone') ?>"
                               autocomplete="tel" required>
                        <?= fieldError($profileErrors, 'telephone') ?>
                    </div>

                    <div class="field field-full">
                        <label for="date_naissance">Date de naissance</label>
                        <input type="date" id="date_naissance" name="date_naissance"
                               value="<?= old($data, 'date_naissance') ?>" max="<?= date('Y-m-d') ?>"
                               autocomplete="bday" required>
                        <?= fieldError($profileErrors, 'date_naissance') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-block">Enregistrer</button>
            </form>

            <form class="form-card" method="post" action="compte.php" novalidate>
                <h2>Changer mon mot de passe</h2>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="mot_de_passe">

                <div class="form-grid">
                    <div class="field field-full">
                        <label for="actuel">Mot de passe actuel</label>
                        <input type="password" id="actuel" name="actuel" autocomplete="current-password" required>
                        <?= fieldError($passwordErrors, 'actuel') ?>
                    </div>

                    <div class="field">
                        <label for="mot_de_passe">Nouveau mot de passe</label>
                        <input type="password" id="mot_de_passe" name="mot_de_passe"
                               autocomplete="new-password" minlength="8" required>
                        <?= fieldError($passwordErrors, 'mot_de_passe') ?>
                    </div>

                    <div class="field">
                        <label for="confirmation">Confirmer</label>
                        <input type="password" id="confirmation" name="confirmation"
                               autocomplete="new-password" minlength="8" required>
                        <?= fieldError($passwordErrors, 'confirmation') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-outline btn-block">Changer le mot de passe</button>
            </form>

            <form class="form-card danger-card" method="post" action="compte.php" novalidate
                  onsubmit="return confirm('Supprimer définitivement ton compte ? Cette action est irréversible.');">
                <h2>Supprimer mon compte</h2>
                <p class="muted-text">Ton compte, tes informations et tes réservations seront supprimés définitivement.</p>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="suppression">

                <div class="form-grid form-grid-single">
                    <div class="field">
                        <label for="mot_de_passe_suppression">Confirme avec ton mot de passe</label>
                        <input type="password" id="mot_de_passe_suppression" name="mot_de_passe_suppression"
                               autocomplete="current-password" required>
                        <?= fieldError($deleteErrors, 'mot_de_passe_suppression') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger btn-block">Supprimer mon compte</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
