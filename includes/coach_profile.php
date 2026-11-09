<?php
// Profil coach : séances données, présents, incidents et séances annulées (US12)
require_once __DIR__ . '/member_history.php';

const INCIDENT_DESCRIPTION_MAX = 2000;

// Un adhérent validé est présent s'il a un passage pendant le créneau de la séance.
// Passage sans sortie : il compte s'il est entré au plus tôt 1 h avant le début.
function isPresentDuring(array $passage, DateTimeImmutable $start, DateTimeImmutable $end): bool
{
    if ($passage['entree'] >= $end) {
        return false;
    }
    if ($passage['sortie'] === null) {
        return $passage['entree'] >= $start->modify('-1 hour');
    }

    return $passage['sortie'] > $start;
}

// Séances du coach (hors annulées), de la plus récente à la plus ancienne, filtrées par période.
// Chaque séance : ['id_cours', 'titre', 'debut', 'fin', 'salle', 'capacite', 'valides', 'presents' (null si à venir),
//                  'statut' => 'realisee' ou 'a_venir']
function getCoachSessions(PDO $db, int $coachId, array $period, DateTimeImmutable $now): array
{
    $timezone = new DateTimeZone(PLANNING_TIMEZONE);
    [$range, $rangeParams] = periodCondition($period, 'c.date_debut');

    $statement = $db->prepare(
        "SELECT c.id_cours, c.titre, c.date_debut, c.date_fin, c.nb_places_max, s.nom_salle
         FROM cours c
         LEFT JOIN salles s ON s.id_salle = c.id_salle
         WHERE c.id_coach = :coach $range
         ORDER BY c.date_debut DESC, c.id_cours DESC"
    );
    $statement->execute(['coach' => $coachId] + $rangeParams);
    $courses = $statement->fetchAll(PDO::FETCH_ASSOC);

    if ($courses === []) {
        return [];
    }

    // Adhérents validés de ces séances
    $ids          = array_column($courses, 'id_cours');
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement    = $db->prepare(
        "SELECT id_cours, id_adherent FROM reservations WHERE statut = 'confirmee' AND id_cours IN ($placeholders)"
    );
    $statement->execute($ids);
    $validated = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $validated[(int) $row['id_cours']][] = (int) $row['id_adherent'];
    }

    // Passages de ces adhérents autour des séances (une seule requête)
    $passagesByMember = [];
    $memberIds        = array_values(array_unique(array_merge([], ...array_values($validated))));
    if ($memberIds !== []) {
        $starts        = array_map(fn ($c) => new DateTimeImmutable($c['date_debut']), $courses);
        $ends          = array_map(fn ($c) => new DateTimeImmutable($c['date_fin']), $courses);
        $memberHolders = implode(', ', array_fill(0, count($memberIds), '?'));
        $statement     = $db->prepare(
            "SELECT id_adherent, entree, sortie FROM passages
             WHERE id_adherent IN ($memberHolders) AND entree >= ? AND entree < ?"
        );
        $statement->execute([...$memberIds, toUtcString(min($starts)->modify('-1 day')), toUtcString(max($ends))]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $passagesByMember[(int) $row['id_adherent']][] = preparePassage($row);
        }
    }

    $sessions = [];
    foreach ($courses as $course) {
        $start   = (new DateTimeImmutable($course['date_debut']))->setTimezone($timezone);
        $end     = (new DateTimeImmutable($course['date_fin']))->setTimezone($timezone);
        $members = $validated[(int) $course['id_cours']] ?? [];
        $done    = $end <= $now;

        $present = 0;
        if ($done) {
            foreach ($members as $memberId) {
                foreach ($passagesByMember[$memberId] ?? [] as $passage) {
                    if (isPresentDuring($passage, $start, $end)) {
                        $present++;
                        break;
                    }
                }
            }
        }

        $sessions[] = [
            'id_cours' => (int) $course['id_cours'],
            'titre'    => $course['titre'],
            'debut'    => $start,
            'fin'      => $end,
            'salle'    => $course['nom_salle'],
            'capacite' => (int) $course['nb_places_max'],
            'valides'  => count($members),
            'presents' => $done ? $present : null,
            'statut'   => $done ? 'realisee' : 'a_venir',
        ];
    }

    return $sessions;
}

// Séances annulées par le coach (l'historique les garde après leur suppression), les plus récentes d'abord
function getCoachCancelledSessions(PDO $db, int $coachId, array $period): array
{
    [$range, $rangeParams] = periodCondition($period, 'h.date_cours');

    $statement = $db->prepare(
        "SELECT h.id_cours, h.titre_cours, h.date_cours, h.nom_salle, h.motif, h.date_evenement
         FROM historique_seances h
         WHERE h.id_coach = :coach AND h.type = 'annulation' AND h.id_adherent IS NULL $range
         ORDER BY h.date_cours DESC"
    );
    $statement->execute(['coach' => $coachId] + $rangeParams);

    $timezone = new DateTimeZone(PLANNING_TIMEZONE);

    return array_map(fn (array $row) => [
        'id_cours'      => (int) $row['id_cours'],
        'titre'         => $row['titre_cours'],
        'debut'         => (new DateTimeImmutable($row['date_cours']))->setTimezone($timezone),
        'salle'         => $row['nom_salle'],
        'motif'         => $row['motif'],
        'date_annulation' => (new DateTimeImmutable($row['date_evenement']))->setTimezone($timezone),
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

// Chiffres du profil : séances données (réalisées), venues (présents cumulés), places offertes et taux de présence
function coachProfileSummary(array $sessions): array
{
    $done      = array_filter($sessions, fn (array $s) => $s['statut'] === 'realisee');
    $presents  = array_sum(array_column($done, 'presents'));
    $validated = array_sum(array_column($done, 'valides'));

    return [
        'seances_donnees' => count($done),
        'venues'          => $presents,
        'valides'         => $validated,
        // Part des validés qui sont vraiment venus
        'taux_presence'   => $validated > 0 ? (int) round($presents / $validated * 100) : null,
    ];
}

// Présents par semaine ('semaine') ou par mois ('mois'), du plus ancien au plus récent : [['label', 'presents'], ...]
function presenceTimeline(array $sessions, string $unit = 'semaine'): array
{
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $points = [];

    foreach ($sessions as $session) {
        if ($session['statut'] !== 'realisee') {
            continue;
        }
        if ($unit === 'mois') {
            $key   = $session['debut']->format('Y-m');
            $label = $months[(int) $session['debut']->format('n') - 1] . ' ' . $session['debut']->format('Y');
        } else {
            $monday = $session['debut']->modify('monday this week');
            $key    = $monday->format('Y-m-d');
            $label  = 'Sem. du ' . $monday->format('d/m');
        }
        $points[$key] ??= ['label' => $label, 'presents' => 0];
        $points[$key]['presents'] += $session['presents'];
    }

    ksort($points);

    return array_values($points);
}

// Incidents signalés par le coach, les plus récents d'abord, filtrés par période (date de la séance)
function getCoachIncidents(PDO $db, int $coachId, array $period): array
{
    [$range, $rangeParams] = periodCondition($period, 'i.date_cours');

    $statement = $db->prepare(
        "SELECT i.id_incident, i.titre_cours, i.date_cours, i.date_signalement, i.description
         FROM incidents i
         WHERE i.id_coach = :coach $range
         ORDER BY i.date_cours DESC, i.id_incident DESC"
    );
    $statement->execute(['coach' => $coachId] + $rangeParams);

    $timezone = new DateTimeZone(PLANNING_TIMEZONE);

    return array_map(fn (array $row) => [
        'titre'       => $row['titre_cours'],
        'debut'       => (new DateTimeImmutable($row['date_cours']))->setTimezone($timezone),
        'description' => $row['description'],
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

// Signale un incident sur une séance commencée du coach.
// Renvoie 'created', 'not_found' (séance d'un autre coach ou inexistante), 'not_started' ou 'description_required'
function reportIncident(PDO $db, int $coachId, int $courseId, string $description, DateTimeImmutable $now): string
{
    $description = trim($description);
    if ($description === '' || mb_strlen($description) > INCIDENT_DESCRIPTION_MAX) {
        return 'description_required';
    }

    $statement = $db->prepare('SELECT titre, date_debut FROM cours WHERE id_cours = :id AND id_coach = :coach');
    $statement->execute(['id' => $courseId, 'coach' => $coachId]);
    $course = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        return 'not_found';
    }
    if (new DateTimeImmutable($course['date_debut']) > $now) {
        return 'not_started';
    }

    $db->prepare(
        'INSERT INTO incidents (id_coach, id_cours, titre_cours, date_cours, description)
         VALUES (:coach, :cours, :titre, :date, :description)'
    )->execute([
        'coach'       => $coachId,
        'cours'       => $courseId,
        'titre'       => $course['titre'],
        'date'        => $course['date_debut'],
        'description' => $description,
    ]);

    return 'created';
}
