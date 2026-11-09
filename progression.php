<?php
// Suivi de progression de l'adhérent : saisie, graphique par indicateur, modification et suppression (US11)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/progression.php';
require_once __DIR__ . '/includes/reservations.php';

$user = requireLogin();

$pageTitle    = 'Ma progression';
$activePage   = 'compte';
$extraScripts = [
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
    'assets/js/progression.js?v=' . filemtime(__DIR__ . '/assets/js/progression.js'),
];

$adherentId  = null;
$measures    = [];
$unavailable = false;

try {
    $db         = getDatabaseConnection();
    $adherentId = getAdherentIdByAccount($db, $user['id_compte']);
    if ($adherentId !== null) {
        $measures = getMeasurements($db, $adherentId);
    }
} catch (PDOException $e) {
    error_log('Progression : ' . $e->getMessage());
    $unavailable = true;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('Y-m-d');

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace adhérent</p>
        <h1>Ma <span class="highlight">progression</span></h1>

        <?php $accountTab = 'progression'; require __DIR__ . '/includes/account_tabs.php'; ?>

        <?php if ($unavailable): ?>
            <p class="lead">Le suivi n'est pas disponible pour le moment, réessaie plus tard.</p>
        <?php elseif ($adherentId === null): ?>
            <p class="lead">Le suivi de progression est réservé aux comptes avec un profil adhérent.</p>
        <?php else: ?>
            <div class="progression" id="progression"
                 data-mesures="<?= htmlspecialchars(json_encode($measures), ENT_QUOTES) ?>"
                 data-csrf="<?= csrfToken() ?>">

                <form class="form-card progression-form" novalidate>
                    <h2 class="progression-form-title">Nouvelle mesure</h2>
                    <input type="hidden" name="id_mesure">
                    <p class="form-alert" role="alert" hidden></p>

                    <div class="progression-fields">
                        <div class="field">
                            <label for="date_mesure">Date</label>
                            <input type="date" id="date_mesure" name="date_mesure" value="<?= $today ?>" max="<?= $today ?>" required>
                            <p class="field-error" data-error="date_mesure" hidden></p>
                        </div>
                        <div class="field">
                            <label for="poids">Poids (kg)</label>
                            <input type="number" id="poids" name="poids" step="0.1" min="<?= PROGRESSION_LIMITS['poids'][0] ?>" max="<?= PROGRESSION_LIMITS['poids'][1] ?>" required>
                            <p class="field-error" data-error="poids" hidden></p>
                        </div>
                        <div class="field">
                            <label for="taille">Taille (cm)</label>
                            <input type="number" id="taille" name="taille" step="0.5" min="<?= PROGRESSION_LIMITS['taille'][0] ?>" max="<?= PROGRESSION_LIMITS['taille'][1] ?>" required>
                            <p class="field-error" data-error="taille" hidden></p>
                        </div>
                        <div class="field">
                            <label for="duree_seance">Durée de la séance (min)</label>
                            <input type="number" id="duree_seance" name="duree_seance" step="1" min="<?= PROGRESSION_LIMITS['duree_seance'][0] ?>" max="<?= PROGRESSION_LIMITS['duree_seance'][1] ?>" required>
                            <p class="field-error" data-error="duree_seance" hidden></p>
                        </div>
                    </div>
                    <p class="muted-text field-hint">L'IMC est calculé automatiquement : poids ÷ taille².</p>

                    <div class="progression-buttons">
                        <button type="submit" class="btn">Enregistrer</button>
                        <button type="button" class="btn btn-outline progression-cancel" hidden>Annuler la modification</button>
                    </div>
                </form>

                <div class="form-card">
                    <div class="progression-chart-head">
                        <h2>Mon évolution</h2>
                        <div class="request-tabs progression-indicators" role="tablist">
                            <button type="button" class="is-active" data-indicator="poids">Poids</button>
                            <button type="button" data-indicator="imc">IMC</button>
                            <button type="button" data-indicator="duree_seance">Durée</button>
                        </div>
                    </div>
                    <p class="sports-message progression-empty" role="status" hidden>
                        Tu n'as encore saisi aucune mesure. Ajoute ta première mesure avec le formulaire ci-dessus pour voir ton évolution.
                    </p>
                    <div class="progression-chart"><canvas id="progression-chart" aria-label="Graphique de progression"></canvas></div>
                </div>

                <div class="form-card progression-history">
                    <h2>Mes mesures</h2>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Poids</th>
                                    <th scope="col">Taille</th>
                                    <th scope="col">IMC</th>
                                    <th scope="col">Durée</th>
                                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
