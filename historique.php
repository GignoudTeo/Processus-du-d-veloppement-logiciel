<?php
// Historique de l'adhérent : ses séances avec leur statut (US10) et ses venues en salle (US9)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/member_history.php';

$user = requireLogin();

$tab        = ($_GET['onglet'] ?? '') === 'venues' ? 'venues' : 'seances';
$pageTitle  = $tab === 'venues' ? 'Mes venues' : 'Mes séances';
$activePage = 'compte';

$now        = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$period     = parsePeriod($_GET);
$sessions   = [];
$passages   = [];
$adherentId = null;
$unavailable = false;

try {
    $db         = getDatabaseConnection();
    $adherentId = getAdherentIdByAccount($db, $user['id_compte']);
    if ($adherentId !== null) {
        // L'adhérent ne voit que ses propres données
        $sessions = $tab === 'seances' ? getMemberSessions($db, $adherentId, $period, $now) : [];
        $passages = $tab === 'venues' ? getMemberPassages($db, $adherentId, $period) : [];
    }
} catch (PDOException $e) {
    error_log('Historique : ' . $e->getMessage());
    $unavailable = true;
}

// Durée totale des venues complètes de la période
$totalMinutes = array_sum(array_filter(array_column($passages, 'minutes')));

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace adhérent</p>
        <h1>Mon <span class="highlight">historique</span></h1>

        <?php $accountTab = $tab; require __DIR__ . '/includes/account_tabs.php'; ?>

        <?php if ($adherentId === null && !$unavailable): ?>
            <p class="lead">L'historique est réservé aux comptes avec un profil adhérent.</p>
        <?php elseif ($unavailable): ?>
            <p class="lead">L'historique n'est pas disponible pour le moment, réessaie plus tard.</p>
        <?php else: ?>
            <form class="period-filter" method="get" action="historique.php">
                <?php if ($tab === 'venues'): ?><input type="hidden" name="onglet" value="venues"><?php endif; ?>
                <div class="field">
                    <label for="du">Du</label>
                    <input type="date" id="du" name="du" value="<?= $period['du'] ? $period['du']->format('Y-m-d') : '' ?>">
                </div>
                <div class="field">
                    <label for="au">Au</label>
                    <input type="date" id="au" name="au" value="<?= $period['au'] ? $period['au']->format('Y-m-d') : '' ?>">
                </div>
                <button type="submit" class="btn btn-small">Filtrer</button>
                <?php if ($period['du'] || $period['au']): ?>
                    <a class="btn btn-outline btn-small" href="historique.php<?= $tab === 'venues' ? '?onglet=venues' : '' ?>">Tout afficher</a>
                <?php endif; ?>
            </form>

            <?php if ($tab === 'seances'): ?>
                <?php if ($sessions === []): ?>
                    <p class="sports-message" role="status">Aucune séance sur cette période. <a href="planning.php">Voir le planning</a></p>
                <?php else: ?>
                    <div class="admin-table-wrapper">
                        <table class="admin-table history-table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Séance</th>
                                    <th scope="col">Salle</th>
                                    <th scope="col">Coach</th>
                                    <th scope="col">Statut</th>
                                    <th scope="col"><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $session): ?>
                                    <tr class="<?= $session['a_venir'] ? 'is-upcoming' : '' ?>">
                                        <td>
                                            <?= $session['debut']->format('d/m/Y') ?><br>
                                            <span class="muted-text"><?= $session['debut']->format('H:i') ?><?= $session['fin'] ? ' – ' . $session['fin']->format('H:i') : '' ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($session['titre']) ?></td>
                                        <td><?= htmlspecialchars($session['salle'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($session['coach'] ?? '—') ?></td>
                                        <td>
                                            <span class="status-pill is-<?= $session['statut'] ?>">
                                                <?= SESSION_STATUS_LABELS[$session['statut']] ?? $session['statut'] ?>
                                                <?= $session['statut'] === 'liste_attente' && $session['position'] ? ' (position ' . $session['position'] . ')' : '' ?>
                                            </span>
                                            <?php if ($session['motif'] && in_array($session['statut'], ['rejetee', 'absent', 'seance_annulee'], true)): ?>
                                                <p class="muted-text history-reason">Motif : <?= htmlspecialchars($session['motif']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($session['modifiable']): ?>
                                                <?php $leaving = $session['statut'] === 'liste_attente'; ?>
                                                <details class="request-refuse history-action">
                                                    <summary class="btn btn-outline btn-small"><?= $leaving ? 'Quitter la liste' : 'Se désinscrire' ?></summary>
                                                    <form method="post" action="reservation.php">
                                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                                        <input type="hidden" name="id_cours" value="<?= $session['id_cours'] ?>">
                                                        <input type="hidden" name="action" value="<?= $leaving ? 'quitter_liste' : 'desinscrire' ?>">
                                                        <input type="hidden" name="retour" value="historique">
                                                        <?php if (!$leaving): ?>
                                                            <?= reasonFields('motif', 'Motif de la désinscription', UNREGISTER_REASONS, null, null, '-' . $session['id_cours']) ?>
                                                        <?php endif; ?>
                                                        <button type="submit" class="btn btn-danger btn-small">Confirmer</button>
                                                    </form>
                                                </details>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($passages === []): ?>
                    <p class="sports-message" role="status">Aucune venue enregistrée sur cette période.</p>
                <?php else: ?>
                    <p class="history-summary">
                        <strong><?= count($passages) ?></strong> venue(s)
                        <?php if ($totalMinutes > 0): ?> · <strong><?= formatDuration($totalMinutes) ?></strong> en salle au total<?php endif; ?>
                    </p>
                    <div class="admin-table-wrapper">
                        <table class="admin-table history-table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Entrée</th>
                                    <th scope="col">Sortie</th>
                                    <th scope="col">Durée</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($passages as $passage): ?>
                                    <tr>
                                        <td><?= formatLongDate($passage['entree']) ?></td>
                                        <td><?= $passage['entree']->format('H:i') ?></td>
                                        <td><?= $passage['sortie'] ? $passage['sortie']->format('H:i') : '—' ?></td>
                                        <td>
                                            <?php if ($passage['minutes'] === null): ?>
                                                <span class="status-pill is-incomplete">Incomplet</span>
                                            <?php else: ?>
                                                <?= $passage['duree'] ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
