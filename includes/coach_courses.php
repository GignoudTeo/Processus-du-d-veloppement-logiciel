<?php
// Séances gérées par les coachs (leurs propres cours) et les administrateurs (tous les cours)
require_once __DIR__ . '/planning.php';
require_once __DIR__ . '/history.php';

const COURSE_TITLE_MAX       = 150;
const COURSE_DESCRIPTION_MAX = 2000;

// id_coach du compte, ou null s'il n'est pas coach. Rôle lu en base : il a pu changer depuis la connexion
function getCoachIdByAccount(PDO $db, int $accountId): ?int
{
    $statement = $db->prepare(
        "SELECT co.id_coach
         FROM coachs co
         INNER JOIN comptes c ON c.id_compte = co.id_compte
         WHERE co.id_compte = :compte AND c.role = 'coach'"
    );
    $statement->execute(['compte' => $accountId]);
    $coachId = $statement->fetchColumn();

    return $coachId === false ? null : (int) $coachId;
}

// Rôle lu en base : il a pu changer depuis la connexion
function isAdminAccount(PDO $db, int $accountId): bool
{
    $statement = $db->prepare("SELECT COUNT(*) FROM comptes WHERE id_compte = :compte AND role = 'admin'");
    $statement->execute(['compte' => $accountId]);

    return (int) $statement->fetchColumn() > 0;
}

// Coachs à qui un administrateur peut attribuer une séance : profil coach d'un compte coach ou admin, triés par prénom
function getAssignableCoaches(PDO $db): array
{
    return $db->query(
        "SELECT co.id_coach, co.prenom, co.nom
         FROM coachs co
         INNER JOIN comptes c ON c.id_compte = co.id_compte
         WHERE c.role IN ('coach', 'admin')
         ORDER BY co.prenom, co.nom"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// id_coach choisi dans le formulaire, ou null s'il ne fait pas partie des coachs proposés
function validateCourseCoach(string $value, array $coaches): ?int
{
    $coachId = filter_var($value, FILTER_VALIDATE_INT);

    return in_array($coachId, array_map('intval', array_column($coaches, 'id_coach')), true) ? $coachId : null;
}

function getRooms(PDO $db): array
{
    return $db->query('SELECT id_salle, nom_salle, capacite FROM salles ORDER BY nom_salle')->fetchAll(PDO::FETCH_ASSOC);
}

// Cours avec son coach et son nombre d'inscrits, ou null s'il n'existe pas
function getCourse(PDO $db, int $courseId): ?array
{
    $statement = $db->prepare(
        "SELECT c.*, co.prenom AS coach_prenom, co.nom AS coach_nom,
                (SELECT COUNT(*) FROM reservations r
                 WHERE r.id_cours = c.id_cours AND r.statut = 'confirmee') AS nb_inscrits
         FROM cours c
         INNER JOIN coachs co ON co.id_coach = c.id_coach
         WHERE c.id_cours = :id"
    );
    $statement->execute(['id' => $courseId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Comme getCourse(), mais null si le cours appartient à un autre coach
function getCoachCourse(PDO $db, int $courseId, int $coachId): ?array
{
    $course = getCourse($db, $courseId);

    return $course && (int) $course['id_coach'] === $coachId ? $course : null;
}

// Inscrits confirmés de plusieurs cours : [id_cours => [['id_reservation', 'prenom', 'nom'], ...]], triés par nom
function getCourseParticipants(PDO $db, array $courseIds): array
{
    if ($courseIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($courseIds), '?'));
    $statement    = $db->prepare(
        "SELECT r.id_cours, r.id_reservation, a.prenom, a.nom
         FROM reservations r
         INNER JOIN adherents a ON a.id_adherent = r.id_adherent
         WHERE r.statut = 'confirmee' AND r.id_cours IN ($placeholders)
         ORDER BY a.nom, a.prenom"
    );
    $statement->execute(array_values($courseIds));

    $participants = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $participants[(int) $row['id_cours']][] = [
            'id_reservation' => (int) $row['id_reservation'],
            'prenom'         => $row['prenom'],
            'nom'            => $row['nom'],
        ];
    }

    return $participants;
}

// Retire un participant d'une séance à venir : la réservation passe en 'rejetee' et la place se libère.
// Permis au coach de la séance et aux administrateurs. Droits lus en base.
// Renvoie ['status' => 'removed', 'not_found' (ou séance d'un autre coach), 'started' ou 'already_removed',
//          'reservation' => infos pour le mail du participant, ou null]
function removeParticipant(PDO $db, int $reservationId, int $accountId, DateTimeImmutable $now): array
{
    $statement = $db->prepare(
        'SELECT r.statut, c.id_coach, c.titre, c.date_debut, a.prenom, co.email
         FROM reservations r
         INNER JOIN cours c ON c.id_cours = r.id_cours
         INNER JOIN adherents a ON a.id_adherent = r.id_adherent
         INNER JOIN comptes co ON co.id_compte = a.id_compte
         WHERE r.id_reservation = :id'
    );
    $statement->execute(['id' => $reservationId]);
    $reservation = $statement->fetch(PDO::FETCH_ASSOC);

    $allowed = $reservation && (
        isAdminAccount($db, $accountId) || (int) $reservation['id_coach'] === getCoachIdByAccount($db, $accountId)
    );
    if (!$allowed) {
        return ['status' => 'not_found', 'reservation' => null];
    }
    if (new DateTimeImmutable($reservation['date_debut']) <= $now) {
        return ['status' => 'started', 'reservation' => $reservation];
    }

    // Condition sur le statut : deux clics en même temps n'envoient qu'un seul mail
    $statement = $db->prepare("UPDATE reservations SET statut = 'rejetee' WHERE id_reservation = :id AND statut = 'confirmee'");
    $statement->execute(['id' => $reservationId]);

    return ['status' => $statement->rowCount() > 0 ? 'removed' : 'already_removed', 'reservation' => $reservation];
}

// Date et heure saisies (heure de Paris), ou null si elles n'existent pas (31 février, passage à l'heure d'été...)
function parseCourseDateTime(string $date, string $time): ?DateTimeImmutable
{
    $value    = $date . ' ' . $time;
    $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new DateTimeZone(PLANNING_TIMEZONE));

    return $dateTime && $dateTime->format('Y-m-d H:i') === $value ? $dateTime : null;
}

// Renvoie [données nettoyées, erreurs par champ]. Si le créneau est valide, les données contiennent aussi 'debut' et 'fin'
function validateCourse(array $input, array $rooms, array $validSportIds): array
{
    $data = [
        'titre'         => trim((string) ($input['titre'] ?? '')),
        'date'          => trim((string) ($input['date'] ?? '')),
        'heure_debut'   => trim((string) ($input['heure_debut'] ?? '')),
        'heure_fin'     => trim((string) ($input['heure_fin'] ?? '')),
        'id_salle'      => trim((string) ($input['id_salle'] ?? '')),
        'id_sport'      => trim((string) ($input['id_sport'] ?? '')),
        'nb_places_max' => trim((string) ($input['nb_places_max'] ?? '')),
        'description'   => trim((string) ($input['description'] ?? '')),
    ];
    $errors = [];

    if ($data['titre'] === '') {
        $errors['titre'] = 'Indique un titre.';
    } elseif (mb_strlen($data['titre']) > COURSE_TITLE_MAX) {
        $errors['titre'] = COURSE_TITLE_MAX . ' caractères maximum.';
    }

    $start = parseCourseDateTime($data['date'], $data['heure_debut']);
    $end   = parseCourseDateTime($data['date'], $data['heure_fin']);
    if (!parseCourseDateTime($data['date'], '12:00')) {
        $errors['date'] = 'Indique une date valide.';
    } elseif (!$start) {
        $errors['heure_debut'] = 'Indique une heure de début valide.';
    } elseif (!$end) {
        $errors['heure_fin'] = 'Indique une heure de fin valide.';
    } elseif ($end <= $start) {
        $errors['heure_fin'] = 'L\'heure de fin doit être après l\'heure de début.';
    } else {
        $data['debut'] = $start;
        $data['fin']   = $end;
    }

    $capacities = array_column($rooms, 'capacite', 'id_salle');
    $roomId     = filter_var($data['id_salle'], FILTER_VALIDATE_INT);
    $capacity   = $capacities[$roomId] ?? null;
    if ($capacity === null) {
        $errors['id_salle'] = 'Choisis une salle.';
    }

    if (!in_array(filter_var($data['id_sport'], FILTER_VALIDATE_INT), $validSportIds, true)) {
        $errors['id_sport'] = 'Choisis un sport.';
    }

    $places = filter_var($data['nb_places_max'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($places === false) {
        $errors['nb_places_max'] = 'Indique un nombre de places d\'au moins 1.';
    } elseif ($capacity !== null && $places > (int) $capacity) {
        $errors['nb_places_max'] = 'Cette salle ne peut accueillir que ' . (int) $capacity . ' personnes.';
    }

    if (mb_strlen($data['description']) > COURSE_DESCRIPTION_MAX) {
        $errors['description'] = COURSE_DESCRIPTION_MAX . ' caractères maximum.';
    }

    return [$data, $errors];
}

// Erreurs par champ si la salle ou le coach est déjà pris sur ce créneau. Deux cours qui se suivent ne se chevauchent pas
function findCourseConflicts(PDO $db, array $data, int $coachId, ?int $ignoredCourseId = null): array
{
    $isTaken = function (string $column, int $id) use ($db, $data, $ignoredCourseId): bool {
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM cours
             WHERE $column = :id AND id_cours <> :ignored AND date_debut < :fin AND date_fin > :debut"
        );
        $statement->execute([
            'id'      => $id,
            'ignored' => $ignoredCourseId ?? 0,
            'debut'   => toUtcString($data['debut']),
            'fin'     => toUtcString($data['fin']),
        ]);

        return (int) $statement->fetchColumn() > 0;
    };

    $errors = [];
    if ($isTaken('id_salle', (int) $data['id_salle'])) {
        $errors['id_salle'] = 'Cette salle est déjà occupée sur ce créneau.';
    }
    if ($isTaken('id_coach', $coachId)) {
        $errors['heure_debut'] = 'Ce coach a déjà une séance sur ce créneau.';
    }

    return $errors;
}

// Valeurs de la base (cours et coach mis à part) pour une ligne de cours validée
function courseParams(array $data): array
{
    return [
        'titre'       => $data['titre'],
        'sport'       => (int) $data['id_sport'],
        'salle'       => (int) $data['id_salle'],
        'debut'       => toUtcString($data['debut']),
        'fin'         => toUtcString($data['fin']),
        'places'      => (int) $data['nb_places_max'],
        'description' => $data['description'] === '' ? null : $data['description'],
    ];
}

// Renvoie id_cours
function createCourse(PDO $db, int $coachId, array $data): int
{
    $statement = $db->prepare(
        'INSERT INTO cours (titre, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max, description)
         VALUES (:titre, :sport, :coach, :salle, :debut, :fin, :places, :description)
         RETURNING id_cours'
    );
    $statement->execute(courseParams($data) + ['coach' => $coachId]);

    return (int) $statement->fetchColumn();
}

// Renvoie false si le cours n'existe pas ou appartient à un autre coach
function updateCourse(PDO $db, int $courseId, int $coachId, array $data): bool
{
    $statement = $db->prepare(
        'UPDATE cours
         SET titre = :titre, id_sport = :sport, id_salle = :salle, date_debut = :debut, date_fin = :fin,
             nb_places_max = :places, description = :description
         WHERE id_cours = :id AND id_coach = :coach'
    );
    $statement->execute(courseParams($data) + ['id' => $courseId, 'coach' => $coachId]);

    return $statement->rowCount() > 0;
}

// Les réservations de la séance sont supprimées avec elle (ON DELETE CASCADE).
// Renvoie false si le cours n'existe pas ou appartient à un autre coach
function deleteCourse(PDO $db, int $courseId, int $coachId): bool
{
    $statement = $db->prepare('DELETE FROM cours WHERE id_cours = :id AND id_coach = :coach');
    $statement->execute(['id' => $courseId, 'coach' => $coachId]);

    return $statement->rowCount() > 0;
}

const COURSE_CANCEL_REASON_MAX = 1000;

// Motif d'annulation nettoyé, ou message d'erreur : ['motif' => ..., 'error' => null|string]
function validateCancellationReason(string $reason): array
{
    $reason = trim($reason);

    if ($reason === '') {
        return ['motif' => $reason, 'error' => 'Indique le motif de l\'annulation : il est envoyé par mail aux inscrits.'];
    }
    if (mb_strlen($reason) > COURSE_CANCEL_REASON_MAX) {
        return ['motif' => $reason, 'error' => COURSE_CANCEL_REASON_MAX . ' caractères maximum.'];
    }

    return ['motif' => $reason, 'error' => null];
}

// Annule une séance à venir : elle est supprimée, après avoir été enregistrée dans l'historique
// (une ligne pour le coach, une par inscrit pour leur historique). Renvoie les inscrits à prévenir
// (validés, en attente et en liste d'attente) : [['prenom', 'email', 'statut'], ...].
// Inscrits lus, historique écrit et séance supprimée dans la même transaction : personne n'est oublié.
// Renvoie null si le cours n'existe pas, appartient à un autre coach ou a déjà commencé
function cancelCourse(PDO $db, int $courseId, int $coachId, string $reason, ?DateTimeImmutable $now = null): ?array
{
    $now = $now ?? new DateTimeImmutable();

    $db->beginTransaction();
    try {
        $course = getCourseForHistory($db, $courseId);
        if (!$course || (int) $course['id_coach'] !== $coachId || new DateTimeImmutable($course['date_debut']) <= $now) {
            $db->rollBack();
            return null;
        }

        $statement = $db->prepare(
            "SELECT a.id_adherent, a.prenom, a.nom, co.email, r.statut
             FROM reservations r
             INNER JOIN adherents a ON a.id_adherent = r.id_adherent
             INNER JOIN comptes co ON co.id_compte = a.id_compte
             WHERE r.id_cours = :id AND r.statut IN ('en_attente', 'confirmee', 'liste_attente')
             ORDER BY a.nom, a.prenom"
        );
        $statement->execute(['id' => $courseId]);
        $participants = $statement->fetchAll(PDO::FETCH_ASSOC);

        logSessionEvent($db, 'annulation', $reason, $course);
        foreach ($participants as $participant) {
            logSessionEvent($db, 'annulation', $reason, $course, [
                'id_adherent'  => (int) $participant['id_adherent'],
                'nom_adherent' => $participant['prenom'] . ' ' . $participant['nom'],
            ]);
        }

        deleteCourse($db, $courseId, $coachId);
        $db->commit();

        return array_map(fn (array $p) => ['prenom' => $p['prenom'], 'email' => $p['email'], 'statut' => $p['statut']], $participants);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Ligne de la base -> valeurs du formulaire, en heure de Paris
function courseToFormData(array $course): array
{
    $timezone = new DateTimeZone(PLANNING_TIMEZONE);
    $start    = (new DateTimeImmutable($course['date_debut']))->setTimezone($timezone);
    $end      = (new DateTimeImmutable($course['date_fin']))->setTimezone($timezone);

    return [
        'titre'         => $course['titre'],
        'date'          => $start->format('Y-m-d'),
        'heure_debut'   => $start->format('H:i'),
        'heure_fin'     => $end->format('H:i'),
        'id_salle'      => (string) $course['id_salle'],
        'id_sport'      => (string) $course['id_sport'],
        'nb_places_max' => (string) $course['nb_places_max'],
        'description'   => $course['description'] ?? '',
    ];
}
