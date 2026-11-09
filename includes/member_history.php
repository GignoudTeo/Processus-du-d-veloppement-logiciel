<?php
// Historique de l'adhérent : ses séances (US10) et ses passages en salle (US9)
require_once __DIR__ . '/reservations.php';

// Libellés des statuts affichés dans l'historique
const SESSION_STATUS_LABELS = [
    'en_attente'     => 'En attente de validation',
    'confirmee'      => 'Validée',
    'liste_attente'  => 'Liste d\'attente',
    'rejetee'        => 'Refusée',
    'absent'         => 'Désinscrite',
    'annulee'        => 'Liste d\'attente quittée',
    'seance_annulee' => 'Séance annulée',
];

// Séances de l'adhérent, de la plus récente à la plus ancienne, filtrées par période (date de la séance).
// Chaque séance : ['id_cours', 'titre', 'debut', 'fin', 'salle', 'coach', 'sport', 'statut', 'motif',
//                  'position' (liste d'attente), 'a_venir' (bool), 'modifiable' (désinscription ou sortie de liste possible)]
// Les séances annulées (supprimées) viennent de l'historique.
function getMemberSessions(PDO $db, int $adherentId, array $period, DateTimeImmutable $now): array
{
    $timezone = new DateTimeZone(PLANNING_TIMEZONE);
    [$range, $rangeParams] = periodCondition($period, 'c.date_debut');

    $statement = $db->prepare(
        "SELECT c.id_cours, c.titre, c.date_debut, c.date_fin, s.nom_salle, sp.nom_sport,
                co.prenom || ' ' || co.nom AS coach, r.statut, r.motif
         FROM reservations r
         INNER JOIN cours c ON c.id_cours = r.id_cours
         INNER JOIN coachs co ON co.id_coach = c.id_coach
         INNER JOIN sports sp ON sp.id_sport = c.id_sport
         LEFT JOIN salles s ON s.id_salle = c.id_salle
         WHERE r.id_adherent = :adherent $range"
    );
    $statement->execute(['adherent' => $adherentId] + $rangeParams);
    $positions = waitingListPositions($db, $adherentId);

    $sessions = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $start  = (new DateTimeImmutable($row['date_debut']))->setTimezone($timezone);
        $future = $start > $now;
        $sessions[] = [
            'id_cours'   => (int) $row['id_cours'],
            'titre'      => $row['titre'],
            'debut'      => $start,
            'fin'        => (new DateTimeImmutable($row['date_fin']))->setTimezone($timezone),
            'salle'      => $row['nom_salle'],
            'coach'      => $row['coach'],
            'sport'      => $row['nom_sport'],
            'statut'     => $row['statut'],
            'motif'      => $row['motif'],
            'position'   => $positions[(int) $row['id_cours']] ?? null,
            'a_venir'    => $future,
            'modifiable' => $future && in_array($row['statut'], BOOKING_ACTIVE_STATUSES, true),
        ];
    }

    // Séances annulées par le coach : elles n'existent plus, l'historique garde leurs infos
    [$range, $rangeParams] = periodCondition($period, 'h.date_cours');
    $statement = $db->prepare(
        "SELECT h.id_cours, h.titre_cours, h.date_cours, h.nom_salle, h.nom_coach, h.motif
         FROM historique_seances h
         WHERE h.id_adherent = :adherent AND h.type = 'annulation' $range"
    );
    $statement->execute(['adherent' => $adherentId] + $rangeParams);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $start      = (new DateTimeImmutable($row['date_cours']))->setTimezone($timezone);
        $sessions[] = [
            'id_cours'   => (int) $row['id_cours'],
            'titre'      => $row['titre_cours'],
            'debut'      => $start,
            'fin'        => null,
            'salle'      => $row['nom_salle'],
            'coach'      => $row['nom_coach'],
            'sport'      => null,
            'statut'     => 'seance_annulee',
            'motif'      => $row['motif'],
            'position'   => null,
            'a_venir'    => $start > $now,
            'modifiable' => false,
        ];
    }

    usort($sessions, fn (array $a, array $b) => $b['debut'] <=> $a['debut']);

    return $sessions;
}

// Passages en salle de l'adhérent, du plus récent au plus ancien, filtrés par période (date d'entrée).
// Chaque passage : ['entree', 'sortie' (null si incomplet), 'minutes' (null si incomplet), 'duree' (texte)]
function getMemberPassages(PDO $db, int $adherentId, array $period): array
{
    [$range, $rangeParams] = periodCondition($period, 'p.entree');

    $statement = $db->prepare(
        "SELECT p.entree, p.sortie FROM passages p
         WHERE p.id_adherent = :adherent $range
         ORDER BY p.entree DESC"
    );
    $statement->execute(['adherent' => $adherentId] + $rangeParams);

    return array_map('preparePassage', $statement->fetchAll(PDO::FETCH_ASSOC));
}

// Passage de la base -> dates en heure de Paris et durée calculée (rien si la sortie manque)
function preparePassage(array $row): array
{
    $timezone = new DateTimeZone(PLANNING_TIMEZONE);
    $entry    = (new DateTimeImmutable($row['entree']))->setTimezone($timezone);
    $exit     = $row['sortie'] ? (new DateTimeImmutable($row['sortie']))->setTimezone($timezone) : null;
    $minutes  = $exit ? intdiv($exit->getTimestamp() - $entry->getTimestamp(), 60) : null;

    return [
        'entree'  => $entry,
        'sortie'  => $exit,
        'minutes' => $minutes,
        'duree'   => $minutes === null ? 'Incomplet' : formatDuration($minutes),
    ];
}

// Condition SQL sur une colonne de date pour une période (parsePeriod), "au" inclus
function periodCondition(array $period, string $column): array
{
    $sql    = '';
    $params = [];
    if (($period['du'] ?? null) !== null) {
        $sql         .= " AND $column >= :du";
        $params['du'] = toUtcString($period['du']);
    }
    if (($period['au'] ?? null) !== null) {
        $sql         .= " AND $column < :au";
        $params['au'] = toUtcString($period['au']->modify('+1 day'));
    }

    return [$sql, $params];
}
