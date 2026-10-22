<?php
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$user = requireAdmin();

$pageTitle  = 'Administration';
$activePage = 'administration';
$db         = getDatabaseConnection();
$pageError  = null;
$searchInput = $_GET['recherche'] ?? ($_POST['recherche'] ?? null);
$search      = is_string($searchInput) ? trim($searchInput) : '';
$search     = mb_substr($search, 0, 191);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $pageError = 'La session a expiré, recharge la page et réessaie.';
    } elseif (($_POST['action'] ?? '') === 'changer_role') {
        $accountId = filter_var($_POST['id_compte'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $newRole = $_POST['role'] ?? '';

        if ($accountId === false || !in_array($newRole, ['adherent', 'coach', 'admin'], true)) {
            $pageError = 'Le compte ou le rôle sélectionné est invalide.';
        } else {
            try {
                $accountDetails = getAccountNotificationDetails($db, $accountId);
                $result = changeAccountRole($db, $accountId, $newRole, (int) $user['id_compte']);
                if ($result === 'changed') {
                    if (!$accountDetails) {
                        error_log('Administration : compte modifié introuvable pour la notification.');
                        setFlash('Le rôle a été modifié, mais le mail de notification n’a pas pu être envoyé.');
                        header('Location: administration.php' . ($search !== '' ? '?recherche=' . rawurlencode($search) : ''));
                        exit;
                    }
                    $mailSent = sendRoleChangeMail(
                        $accountDetails['email'],
                        $accountDetails['prenom'],
                        $accountDetails['role'],
                        $newRole
                    );
                    setFlash($mailSent
                        ? 'Le rôle a été modifié et un mail de notification a été envoyé.'
                        : 'Le rôle a été modifié, mais le mail de notification n’a pas pu être envoyé.'
                    );
                    header('Location: administration.php' . ($search !== '' ? '?recherche=' . rawurlencode($search) : ''));
                    exit;
                }
                $messages = [
                    'not_found'           => 'Le compte sélectionné est introuvable.',
                    'unchanged'           => 'Ce compte possède déjà ce rôle.',
                    'self_change_forbidden' => 'Tu ne peux pas modifier ton propre rôle.',
                    'last_admin'          => 'Le dernier administrateur ne peut pas être rétrogradé.',
                    'profile_missing'     => 'Le profil de ce compte est incomplet et son rôle ne peut pas être modifié.',
                ];
                $pageError = $messages[$result] ?? 'Le rôle du compte n’a pas pu être modifié.';
            } catch (PDOException $e) {
                error_log('Administration : ' . $e->getMessage());
                $pageError = 'Une erreur est survenue pendant la modification du rôle.';
            }
        }
    }
}

$accounts = getAccountsForAdmin($db, $search);

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace sécurisé</p>
        <h1>Gestion des <span class="highlight">utilisateurs</span></h1>
        <p class="lead">Consulte les utilisateurs et gère leurs rôles d’adhérent, de coach ou d’administrateur.</p>

        <?php if ($pageError): ?>
            <p class="form-alert" role="alert"><?= htmlspecialchars($pageError) ?></p>
        <?php endif; ?>

        <form class="admin-search" method="get" action="administration.php">
            <label for="recherche">Rechercher un membre par nom, prénom ou adresse mail</label>
            <div class="admin-search-controls">
                <input type="search" id="recherche" name="recherche" value="<?= htmlspecialchars($search) ?>"
                       maxlength="191" placeholder="Ex. Alex Martin ou alex@example.com">
                <button type="submit" class="btn btn-small">Rechercher</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-outline btn-small" href="administration.php">Effacer</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Nom</th>
                        <th scope="col">Adresse mail</th>
                        <th scope="col">Rôle</th>
                        <th scope="col">Compte</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?= htmlspecialchars(trim(($account['prenom'] ?? '') . ' ' . ($account['nom'] ?? ''))) ?: '—' ?></td>
                            <td><?= htmlspecialchars($account['email']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($account['role'])) ?></td>
                            <td><?= $account['email_confirme'] ? 'Confirmé' : 'En attente' ?></td>
                            <td>
                                <?php if ((int) $account['id_compte'] === (int) $user['id_compte']): ?>
                                    <span class="muted-text">Compte connecté</span>
                                <?php else: ?>
                                    <form method="post" action="administration.php">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="action" value="changer_role">
                                        <input type="hidden" name="id_compte" value="<?= (int) $account['id_compte'] ?>">
                                        <input type="hidden" name="recherche" value="<?= htmlspecialchars($search) ?>">
                                        <select name="role" aria-label="Rôle de <?= htmlspecialchars($account['email']) ?>">
                                            <option value="adherent" <?= $account['role'] === 'adherent' ? 'selected' : '' ?>>Adhérent</option>
                                            <option value="coach" <?= $account['role'] === 'coach' ? 'selected' : '' ?>>Coach</option>
                                            <option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                        </select>
                                        <button type="submit" class="btn btn-outline btn-small">Enregistrer</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($accounts === []): ?>
                        <tr>
                            <td colspan="5" class="admin-empty">
                                <?= $search !== '' ? 'Aucun utilisateur ne correspond à cette recherche.' : 'Aucun utilisateur à afficher.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
