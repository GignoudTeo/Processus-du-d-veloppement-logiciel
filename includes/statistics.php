<?php
// Statistiques des refus et des désinscriptions, pour les administrateurs (US8)
require_once __DIR__ . '/history.php';
require_once __DIR__ . '/planning.php';

const STAT_TYPES = ['refus' => 'Refus', 'desinscription' => 'Désinscriptions'];

// Filtres de la page : ['du' => ?DateTimeImmutable, 'au' => ?DateTimeImmutable (inclus), 'type' => null|'refus'|'desinscription']
function parseStatisticsFilters(array $input): array
{
    $type = $input['type'] ?? null;

    return parsePeriod($input) + ['type' => isset(STAT_TYPES[$type]) ? $type : null];
}

// Clause WHERE commune aux statistiques : refus et désinscriptions seulement, filtrés par période et par type
function statisticsWhere(array $filters): array
{
    $conditions = ["h.type IN ('refus', 'desinscription')"];
    $params     = [];

    if ($filters['type'] !== null) {
        $conditions[]   = 'h.type = :type';
        $params['type'] = $filters['type'];
    }
    if ($filters['du'] !== null) {
        $conditions[] = 'h.date_evenement >= :du';
        $params['du'] = toUtcString($filters['du']);
    }
    if ($filters['au'] !== null) {
        // "au" est inclus : jusqu'au lendemain minuit
        $conditions[] = 'h.date_evenement < :au';
        $params['au'] = toUtcString($filters['au']->modify('+1 day'));
    }

    return [' WHERE ' . implode(' AND ', $conditions), $params];
}

// Renvoie ['total' => ['refus' => n, 'desinscription' => n],
//          'par_motif' => [['motif', 'refus', 'desinscription', 'total'], ...] (du plus fréquent au moins fréquent),
//          'par_coach' => [['coach', 'refus', 'desinscription', 'total'], ...],
//          'evenements' => derniers événements (100 au plus)]
function getRefusalStatistics(PDO $db, array $filters): array
{
    [$where, $params] = statisticsWhere($filters);

    $grouped = function (string $column, string $alias) use ($db, $where, $params): array {
        $statement = $db->prepare(
            "SELECT $column AS $alias,
                    SUM(CASE WHEN h.type = 'refus' THEN 1 ELSE 0 END) AS refus,
                    SUM(CASE WHEN h.type = 'desinscription' THEN 1 ELSE 0 END) AS desinscription,
                    COUNT(*) AS total
             FROM historique_seances h
             $where
             GROUP BY $column
             ORDER BY total DESC, $alias"
        );
        $statement->execute($params);

        return array_map(fn (array $row) => [
            $alias           => $row[$alias],
            'refus'          => (int) $row['refus'],
            'desinscription' => (int) $row['desinscription'],
            'total'          => (int) $row['total'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    };

    $byReason = $grouped('h.motif', 'motif');
    // Coach supprimé entre-temps : son nom reste dans l'historique
    $byCoach  = $grouped("COALESCE(h.nom_coach, 'Coach inconnu')", 'coach');

    $statement = $db->prepare(
        "SELECT h.type, h.date_evenement, h.motif, h.titre_cours, h.date_cours, h.nom_coach, h.nom_adherent
         FROM historique_seances h
         $where
         ORDER BY h.date_evenement DESC, h.id_evenement DESC
         LIMIT 100"
    );
    $statement->execute($params);

    return [
        'total' => [
            'refus'          => array_sum(array_column($byReason, 'refus')),
            'desinscription' => array_sum(array_column($byReason, 'desinscription')),
        ],
        'par_motif'  => $byReason,
        'par_coach'  => $byCoach,
        'evenements' => $statement->fetchAll(PDO::FETCH_ASSOC),
    ];
}
