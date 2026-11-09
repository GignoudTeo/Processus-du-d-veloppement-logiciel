<?php
// Profil coach : statistiques, présents, historique des séances, séances annulées et incidents (US12)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_courses.php';
require_once __DIR__ . '/includes/coach_profile.php';
require_once __DIR__ . '/includes/form.php';

$user = requireLogin();

$pageTitle    = 'Profil coach';
$activePage   = 'profil-coach';
$extraScripts = [
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
    'assets/js/coach-profile.js?v=' . filemtime(__DIR__ . '/assets/js/coach-profile.js'),
];

$now     = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$db      = getDatabaseConnection();
$coachId = getCoachIdByAccount($db, $user['id_compte']);

// Seul le coach connecté voit son profil, et seulement ses données
if ($coachId === null) {
    setFlash('Cette page affiche les statistiques d\'un profil coach : ton compte n\'en a pas.');
    header('Location: index.php');
    exit;
}

$period = parsePeriod($_GET);
$unit   = ($_GET['unite'] ?? '') === 'mois' ? 'mois' : 'semaine';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'incident') {
    $courseId = filter_var($_POST['id_cours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $errors['incident'] = 'La session a expiré, recharge la page et réessaie.';
    } elseif (!$courseId) {
        $errors['incident'] = 'Choisis la séance concernée.';
    } else {
        $result   = reportIncident($db, $coachId, $courseId, (string) ($_POST['description'] ?? ''), $now);
        $messages = [
            'not_found'            => 'Cette séance est introuvable.',
            'not_started'          => 'Un incident ne peut être signalé que sur une séance commencée.',
            'description_required' => 'Décris l\'incident (' . INCIDENT_DESCRIPTION_MAX . ' caractères maximum).',
        ];
        if ($result === 'created') {
            setFlash('L\'incident est enregistré.');
            header('Location: profil-coach.php#incidents');
            exit;
        }
        $errors['incident'] = $messages[$result];
    }
}

$sessions  = getCoachSessions($db, $coachId, $period, $now);
$cancelled = getCoachCancelledSessions($db, $coachId, $period);
$incidents = getCoachIncidents($db, $coachId, $period);
$summary   = coachProfileSummary($sessions);
$timeline  = presenceTimeline($sessions, $unit);

// Historique complet : séances (réalisées ou à venir) et séances annulées, des plus récentes aux plus anciennes
$history = array_merge($sessions, array_map(fn (array $c) => $c + ['statut' => 'annulee', 'capacite' => null, 'presents' => null, 'valides' => null], $cancelled));
usort($history, fn (array $a, array $b) => $b['debut'] <=> $a['debut']);

// Séances sur lesquelles un incident peut être signalé : celles déjà commencées
$reportable = array_filter($sessions, fn (array $s) => $s['debut'] <= $now);
$statusLabels = ['realisee' => 'Réalisée', 'a_venir' => 'À venir', 'annulee' => 'Annulée'];
// Lien qui garde la période choisie ; $extra remplace un paramètre (ex. ['unite' => 'mois'])
$query = fn (array $extra) => 'profil-coach.php?' . http_build_query(array_filter($extra + [
    'du' => $period['du'] ? $period['du']->format('Y-m-d') : null,
    'au' => $period['au'] ? $period['au']->format('Y-m-d') : null,
]));

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace coach</p>
        <h1>Profil <span class="highlight">coach</span></h1>

        <form class="period-filter" method="get" action="profil-coach.php">
            <input type="hidden" name="unite" value="<?= $unit ?>">
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
                <a class="btn btn-outline btn-small" href="profil-coach.php?unite=<?= $unit ?>">Toute la période</a>
            <?php endif; ?>
        </form>

        <div class="stat-cards">
            <div class="stat-card"><strong><?= $summary['seances_donnees'] ?></strong><span>séance(s) donnée(s)</span></div>
            <div class="stat-card"><strong><?= $summary['venues'] ?></strong><span>venue(s) d'adhérents</span></div>
            <div class="stat-card">
                <strong><?= $summary['taux_presence'] === null ? '—' : $summary['taux_presence'] . ' %' ?></strong>
                <span>des validés présents</span>
            </div>
            <div class="stat-card"><strong><?= count($cancelled) ?></strong><span>séance(s) annulée(s)</span></div>
        </div>

        <div class="form-card stat-section">
            <div class="progression-chart-head">
                <h2>Présents à mes cours</h2>
                <nav class="request-tabs" aria-label="Regrouper par">
                    <a href="<?= htmlspecialchars($query(['unite' => 'semaine'])) ?>" class="<?= $unit === 'semaine' ? 'is-active' : '' ?>">Par semaine</a>
                    <a href="<?= htmlspecialchars($query(['unite' => 'mois'])) ?>" class="<?= $unit === 'mois' ? 'is-active' : '' ?>">Par mois</a>
                </nav>
            </div>
            <p class="muted-text">Un adhérent est compté présent s'il était validé sur la séance et qu'un passage en salle est enregistré pendant le créneau.</p>
            <?php if ($timeline === []): ?>
                <p class="sports-message" role="status">Pas encore de séance réalisée sur cette période.</p>
            <?php else: ?>
                <div class="progression-chart">
                    <canvas id="presence-chart" data-timeline="<?= htmlspecialchars(json_encode($timeline), ENT_QUOTES) ?>" aria-label="Évolution du nombre de présents"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-card stat-section">
            <h2>Historique de mes séances</h2>
            <?php if ($history === []): ?>
                <p class="muted-text">Aucune séance sur cette période.</p>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Séance</th>
                                <th scope="col">Salle</th>
                                <th scope="col">Présents / capacité</th>
                                <th scope="col">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $session): ?>
                                <tr>
                                    <td><?= $session['debut']->format('d/m/Y H:i') ?></td>
                                    <td><?= htmlspecialchars($session['titre']) ?></td>
                                    <td><?= htmlspecialchars($session['salle'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($session['statut'] === 'realisee'): ?>
                                            <?= $session['presents'] ?> / <?= $session['capacite'] ?>
                                            <span class="muted-text">(<?= $session['valides'] ?> validé(s))</span>
                                        <?php elseif ($session['statut'] === 'a_venir'): ?>
                                            <span class="muted-text"><?= $session['valides'] ?> validé(s) / <?= $session['capacite'] ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-pill is-<?= $session['statut'] ?>"><?= $statusLabels[$session['statut']] ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-card stat-section">
            <h2>Séances annulées</h2>
            <?php if ($cancelled === []): ?>
                <p class="muted-text">Aucune séance annulée sur cette période.</p>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr><th scope="col">Date</th><th scope="col">Séance</th><th scope="col">Motif</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cancelled as $session): ?>
                                <tr>
                                    <td><?= $session['debut']->format('d/m/Y H:i') ?></td>
                                    <td><?= htmlspecialchars($session['titre']) ?></td>
                                    <td><?= htmlspecialchars($session['motif']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-card stat-section" id="incidents">
            <h2>Incidents de cours</h2>
            <?php if ($incidents === []): ?>
                <p class="muted-text">Aucun incident signalé sur cette période.</p>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr><th scope="col">Date</th><th scope="col">Séance</th><th scope="col">Description</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incidents as $incident): ?>
                                <tr>
                                    <td><?= $incident['debut']->format('d/m/Y H:i') ?></td>
                                    <td><?= htmlspecialchars($incident['titre']) ?></td>
                                    <td><?= nl2br(htmlspecialchars($incident['description'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($reportable !== []): ?>
                <details class="request-refuse incident-form" <?= isset($errors['incident']) ? 'open' : '' ?>>
                    <summary class="btn btn-outline btn-small">Signaler un incident</summary>
                    <form method="post" action="profil-coach.php#incidents">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="incident">
                        <?php if (isset($errors['incident'])): ?>
                            <p class="form-alert" role="alert"><?= htmlspecialchars($errors['incident']) ?></p>
                        <?php endif; ?>
                        <div class="field">
                            <label for="incident-cours">Séance</label>
                            <select id="incident-cours" name="id_cours" required>
                                <option value="">Choisir une séance</option>
                                <?php foreach ($reportable as $session): ?>
                                    <option value="<?= $session['id_cours'] ?>"><?= $session['debut']->format('d/m/Y H:i') ?> · <?= htmlspecialchars($session['titre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="incident-description">Description</label>
                            <textarea id="incident-description" name="description" rows="3" maxlength="<?= INCIDENT_DESCRIPTION_MAX ?>" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-small">Enregistrer l'incident</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
