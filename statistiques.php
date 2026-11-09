<?php
// Statistiques des refus et des désinscriptions, réservées aux administrateurs (US8)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/statistics.php';

requireAdmin();

$pageTitle  = 'Statistiques';
$activePage = 'statistiques';

$filters     = parseStatisticsFilters($_GET);
$stats       = null;
$unavailable = false;

try {
    $stats = getRefusalStatistics(getDatabaseConnection(), $filters);
} catch (PDOException $e) {
    error_log('Statistiques : ' . $e->getMessage());
    $unavailable = true;
}

$timezone   = new DateTimeZone(PLANNING_TIMEZONE);
$formatDate = fn (string $date, string $format = 'd/m/Y') => (new DateTimeImmutable($date))->setTimezone($timezone)->format($format);
$typeLabels = ['refus' => 'Refus', 'desinscription' => 'Désinscription'];

// Barre horizontale proportionnelle au plus grand total du tableau
$bar = function (int $value, int $max): string {
    $width = $max > 0 ? round($value / $max * 100) : 0;

    return '<div class="stat-bar" aria-hidden="true"><div style="width: ' . $width . '%"></div></div>';
};

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace administrateur</p>
        <h1>Refus et <span class="highlight">désinscriptions</span></h1>
        <p class="lead">Repère les causes qui reviennent souvent. Les données restent disponibles même si la séance a été supprimée depuis.</p>

        <form class="period-filter" method="get" action="statistiques.php">
            <div class="field">
                <label for="du">Du</label>
                <input type="date" id="du" name="du" value="<?= $filters['du'] ? $filters['du']->format('Y-m-d') : '' ?>">
            </div>
            <div class="field">
                <label for="au">Au</label>
                <input type="date" id="au" name="au" value="<?= $filters['au'] ? $filters['au']->format('Y-m-d') : '' ?>">
            </div>
            <div class="field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">Refus et désinscriptions</option>
                    <?php foreach (STAT_TYPES as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-small">Filtrer</button>
            <?php if ($filters['du'] || $filters['au'] || $filters['type']): ?>
                <a class="btn btn-outline btn-small" href="statistiques.php">Réinitialiser</a>
            <?php endif; ?>
        </form>

        <?php if ($unavailable): ?>
            <p class="sports-message" role="status">Les statistiques ne sont pas disponibles pour le moment.</p>
        <?php else: ?>
            <div class="stat-cards">
                <?php if ($filters['type'] !== 'desinscription'): ?>
                    <div class="stat-card"><strong><?= $stats['total']['refus'] ?></strong><span>refus</span></div>
                <?php endif; ?>
                <?php if ($filters['type'] !== 'refus'): ?>
                    <div class="stat-card"><strong><?= $stats['total']['desinscription'] ?></strong><span>désinscriptions</span></div>
                <?php endif; ?>
            </div>

            <?php if ($stats['evenements'] === []): ?>
                <p class="sports-message" role="status">Aucun refus ni désinscription sur cette période.</p>
            <?php else: ?>
                <?php foreach (['par_motif' => ['Par motif', 'motif'], 'par_coach' => ['Par coach', 'coach']] as $key => [$title, $column]): ?>
                    <?php $max = max(array_column($stats[$key], 'total')); ?>
                    <div class="form-card stat-section">
                        <h2><?= $title ?></h2>
                        <div class="admin-table-wrapper">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?= $column === 'motif' ? 'Motif' : 'Coach' ?></th>
                                        <?php if ($filters['type'] !== 'desinscription'): ?><th scope="col">Refus</th><?php endif; ?>
                                        <?php if ($filters['type'] !== 'refus'): ?><th scope="col">Désinscriptions</th><?php endif; ?>
                                        <th scope="col">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats[$key] as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row[$column]) ?></td>
                                            <?php if ($filters['type'] !== 'desinscription'): ?><td><?= $row['refus'] ?></td><?php endif; ?>
                                            <?php if ($filters['type'] !== 'refus'): ?><td><?= $row['desinscription'] ?></td><?php endif; ?>
                                            <td class="stat-total"><strong><?= $row['total'] ?></strong><?= $bar($row['total'], $max) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="form-card stat-section">
                    <h2>Détail <span class="muted-text">(<?= count($stats['evenements']) ?> derniers)</span></h2>
                    <div class="admin-table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Motif</th>
                                    <th scope="col">Séance</th>
                                    <th scope="col">Coach</th>
                                    <th scope="col">Adhérent</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['evenements'] as $event): ?>
                                    <tr>
                                        <td><?= $formatDate($event['date_evenement']) ?></td>
                                        <td><span class="status-pill is-<?= $event['type'] === 'refus' ? 'rejetee' : 'absent' ?>"><?= $typeLabels[$event['type']] ?></span></td>
                                        <td><?= htmlspecialchars($event['motif']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($event['titre_cours']) ?><br>
                                            <span class="muted-text"><?= $formatDate($event['date_cours'], 'd/m/Y H:i') ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($event['nom_coach'] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($event['nom_adherent'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
