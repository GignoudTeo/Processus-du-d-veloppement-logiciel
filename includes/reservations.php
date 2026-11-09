<?php
// Inscription, liste d'attente et désinscription d'un adhérent à une séance.
// Statuts d'une réservation :
//   en_attente    : demande en attente de validation par le coach
//   confirmee     : validée par le coach (occupe une place)
//   liste_attente : séance complète, l'adhérent attend qu'une place se libère
//   rejetee       : refusée par le coach (motif dans reservations.motif)
//   absent        : désinscrit (motif dans reservations.motif)
//   annulee       : a quitté la liste d'attente
require_once __DIR__ . '/planning.php';
require_once __DIR__ . '/history.php';

// Statuts qui comptent comme « inscrit » : on ne peut pas se réinscrire, et ils bloquent les chevauchements
const BOOKING_ACTIVE_STATUSES = ['en_attente', 'confirmee', 'liste_attente'];

// id_adherent du compte, ou null s'il n'a pas de profil adhérent
function getAdherentIdByAccount(PDO $db, int $accountId): ?int
{
    $statement = $db->prepare('SELECT id_adherent FROM adherents WHERE id_compte = :compte');
    $statement->execute(['compte' => $accountId]);
    $adherentId = $statement->fetchColumn();

    return $adherentId === false ? null : (int) $adherentId;
}

// Statut de l'adhérent pour chacun de ces cours : [id_cours => statut]. Les cours sans réservation sont absents
function getBookingStatuses(PDO $db, int $adherentId, array $courseIds): array
{
    if ($courseIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($courseIds), '?'));
    $statement    = $db->prepare(
        "SELECT id_cours, statut FROM reservations WHERE id_adherent = ? AND id_cours IN ($placeholders)"
    );
    $statement->execute([$adherentId, ...array_values($courseIds)]);

    $statuses = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statuses[(int) $row['id_cours']] = $row['statut'];
    }

    return $statuses;
}

// Ce que la fenêtre de détail propose à un adhérent, pour un cours préparé (prepareCourse) et son statut :
// 'en_attente', 'confirmee', 'liste_attente', 'rejetee' (déjà une réservation),
// 'disponible' (inscription possible), 'liste_possible' (complet : liste d'attente), ou null si le cours a commencé
function courseRegistrationState(array $course, ?string $status): ?string
{
    if ($course['termine']) {
        return null;
    }
    if (in_array($status, ['en_attente', 'confirmee', 'liste_attente', 'rejetee'], true)) {
        return $status;
    }

    return $course['complet'] ? 'liste_possible' : 'disponible';
}

// Position dans la liste d'attente (1 = la prochaine personne proposée au coach), ou null
function waitingListPosition(PDO $db, int $courseId, int $adherentId): ?int
{
    $positions = waitingListPositions($db, $adherentId);

    return $positions[$courseId] ?? null;
}

// Positions de l'adhérent dans toutes ses listes d'attente : [id_cours => position]
function waitingListPositions(PDO $db, int $adherentId): array
{
    $statement = $db->prepare(
        "SELECT r.id_cours,
                (SELECT COUNT(*) FROM reservations avant
                 WHERE avant.id_cours = r.id_cours AND avant.statut = 'liste_attente'
                   AND (avant.date_reservation < r.date_reservation
                        OR (avant.date_reservation = r.date_reservation AND avant.id_reservation < r.id_reservation))
                ) + 1 AS position
         FROM reservations r
         WHERE r.id_adherent = :adherent AND r.statut = 'liste_attente'"
    );
    $statement->execute(['adherent' => $adherentId]);

    return array_map('intval', array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'position', 'id_cours'));
}

// Inscrit l'adhérent : demande « en attente » de validation, ou liste d'attente si la séance est complète.
// Renvoie ['status' => 'requested', 'waitlisted', 'not_found', 'started', 'already_registered', 'refused' ou 'overlap',
//          'position' => position dans la liste d'attente, ou null,
//          'conflict' => cours qui chevauche ('titre', 'date_debut'), ou null]
function registerForCourse(PDO $db, int $adherentId, int $courseId, DateTimeImmutable $now): array
{
    $db->beginTransaction();

    try {
        // Verrouille l'adhérent et le cours jusqu'à la fin de la transaction : deux inscriptions
        // en même temps ne peuvent pas créer un chevauchement ou un doublon
        $db->prepare('UPDATE adherents SET id_compte = id_compte WHERE id_adherent = :id')->execute(['id' => $adherentId]);
        $db->prepare('UPDATE cours SET nb_places_max = nb_places_max WHERE id_cours = :id')->execute(['id' => $courseId]);

        $result = attemptCourseRegistration($db, $adherentId, $courseId, $now);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $position = $result['status'] === 'waitlisted' ? waitingListPosition($db, $courseId, $adherentId) : null;

    return $result + ['conflict' => null, 'position' => $position];
}

function attemptCourseRegistration(PDO $db, int $adherentId, int $courseId, DateTimeImmutable $now): array
{
    $statement = $db->prepare(
        "SELECT c.date_debut, c.date_fin, c.nb_places_max,
                (SELECT COUNT(*) FROM reservations r
                 WHERE r.id_cours = c.id_cours AND r.statut = 'confirmee') AS nb_inscrits
         FROM cours c
         WHERE c.id_cours = :id"
    );
    $statement->execute(['id' => $courseId]);
    $course = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        return ['status' => 'not_found'];
    }
    $start = new DateTimeImmutable($course['date_debut']);
    if ($start <= $now) {
        return ['status' => 'started'];
    }

    $statement = $db->prepare('SELECT statut FROM reservations WHERE id_cours = :cours AND id_adherent = :adherent');
    $statement->execute(['cours' => $courseId, 'adherent' => $adherentId]);
    $previousStatus = $statement->fetchColumn();

    if (in_array($previousStatus, BOOKING_ACTIVE_STATUSES, true)) {
        return ['status' => 'already_registered'];
    }
    // Une demande refusée par le coach ne peut pas être renvoyée
    if ($previousStatus === 'rejetee') {
        return ['status' => 'refused'];
    }

    // Pas deux séances qui se chevauchent (deux séances qui se suivent ne se chevauchent pas)
    $placeholders = implode(', ', array_fill(0, count(BOOKING_ACTIVE_STATUSES), '?'));
    $statement    = $db->prepare(
        "SELECT c.titre, c.date_debut
         FROM reservations r
         INNER JOIN cours c ON c.id_cours = r.id_cours
         WHERE r.id_adherent = ? AND r.statut IN ($placeholders) AND c.id_cours <> ?
           AND c.date_debut < ? AND c.date_fin > ?
         ORDER BY c.date_debut"
    );
    $statement->execute([
        $adherentId,
        ...BOOKING_ACTIVE_STATUSES,
        $courseId,
        toUtcString(new DateTimeImmutable($course['date_fin'])),
        toUtcString($start),
    ]);
    $conflict = $statement->fetch(PDO::FETCH_ASSOC);
    if ($conflict) {
        return ['status' => 'overlap', 'conflict' => $conflict];
    }

    // Complet = autant de validés que de places : on propose la liste d'attente
    $newStatus = (int) $course['nb_inscrits'] >= (int) $course['nb_places_max'] ? 'liste_attente' : 'en_attente';

    // Une seule ligne par adhérent et par cours : on réutilise celle d'une ancienne réservation
    $sql = $previousStatus === false
        ? 'INSERT INTO reservations (id_cours, id_adherent, statut) VALUES (:cours, :adherent, :statut)'
        : 'UPDATE reservations
           SET statut = :statut, date_reservation = CURRENT_TIMESTAMP, motif = NULL, date_statut = NULL
           WHERE id_cours = :cours AND id_adherent = :adherent';
    $db->prepare($sql)->execute(['cours' => $courseId, 'adherent' => $adherentId, 'statut' => $newStatus]);

    return ['status' => $newStatus === 'liste_attente' ? 'waitlisted' : 'requested'];
}

// Désinscrit l'adhérent d'une séance à venir (demande en attente ou inscription validée), avec un motif obligatoire.
// La réservation passe en 'absent' (compté dans adherents.nb_absences par le trigger de la base).
// Si une place validée se libère, la première personne de la liste d'attente est proposée au coach.
// Renvoie ['status' => 'unregistered', 'not_registered', 'started' ou 'reason_required',
//          'was_confirmed' => bool, 'promoted' => adhérent passé de la liste d'attente à « en attente » (ou null),
//          'course' => séance (getCourseForHistory), pour prévenir le coach]
function unregisterFromCourse(PDO $db, int $adherentId, int $courseId, DateTimeImmutable $now, string $reason): array
{
    $result = ['status' => 'not_registered', 'was_confirmed' => false, 'promoted' => null, 'course' => null];

    if (trim($reason) === '') {
        return ['status' => 'reason_required'] + $result;
    }

    $course = getCourseForHistory($db, $courseId);
    if (!$course) {
        return $result;
    }
    $result['course'] = $course;
    if (new DateTimeImmutable($course['date_debut']) <= $now) {
        return ['status' => 'started'] + $result;
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            "SELECT r.statut, a.prenom || ' ' || a.nom AS nom_adherent
             FROM reservations r
             INNER JOIN adherents a ON a.id_adherent = r.id_adherent
             WHERE r.id_cours = :cours AND r.id_adherent = :adherent AND r.statut IN ('en_attente', 'confirmee')"
        );
        $statement->execute(['cours' => $courseId, 'adherent' => $adherentId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);

        // Condition sur le statut : un double clic ne compte qu'une seule désinscription
        $statement = $db->prepare(
            "UPDATE reservations SET statut = 'absent', motif = :motif, date_statut = CURRENT_TIMESTAMP
             WHERE id_cours = :cours AND id_adherent = :adherent AND statut IN ('en_attente', 'confirmee')"
        );
        $statement->execute(['motif' => trim($reason), 'cours' => $courseId, 'adherent' => $adherentId]);

        if (!$booking || $statement->rowCount() === 0) {
            $db->rollBack();
            return $result;
        }

        logSessionEvent($db, 'desinscription', trim($reason), $course, [
            'id_adherent'  => $adherentId,
            'nom_adherent' => $booking['nom_adherent'],
        ]);

        $result['status']        = 'unregistered';
        $result['was_confirmed'] = $booking['statut'] === 'confirmee';
        if ($result['was_confirmed']) {
            $result['promoted'] = promoteFromWaitingList($db, $courseId);
        }

        $db->commit();

        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Quitte la liste d'attente. Renvoie false si l'adhérent n'y était pas
function leaveWaitingList(PDO $db, int $adherentId, int $courseId): bool
{
    $statement = $db->prepare(
        "UPDATE reservations SET statut = 'annulee', date_statut = CURRENT_TIMESTAMP
         WHERE id_cours = :cours AND id_adherent = :adherent AND statut = 'liste_attente'"
    );
    $statement->execute(['cours' => $courseId, 'adherent' => $adherentId]);

    return $statement->rowCount() > 0;
}

// S'il reste une place, la première personne de la liste d'attente devient une demande « en attente » pour le coach.
// Renvoie ['id_reservation', 'id_adherent', 'prenom', 'nom', 'email'] de cette personne, ou null
function promoteFromWaitingList(PDO $db, int $courseId): ?array
{
    $statement = $db->prepare(
        "SELECT c.nb_places_max,
                (SELECT COUNT(*) FROM reservations r WHERE r.id_cours = c.id_cours AND r.statut = 'confirmee') AS nb_inscrits
         FROM cours c WHERE c.id_cours = :id"
    );
    $statement->execute(['id' => $courseId]);
    $course = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$course || (int) $course['nb_inscrits'] >= (int) $course['nb_places_max']) {
        return null;
    }

    $statement = $db->prepare(
        "SELECT r.id_reservation, r.id_adherent, a.prenom, a.nom, cpt.email
         FROM reservations r
         INNER JOIN adherents a ON a.id_adherent = r.id_adherent
         INNER JOIN comptes cpt ON cpt.id_compte = a.id_compte
         WHERE r.id_cours = :cours AND r.statut = 'liste_attente'
         ORDER BY r.date_reservation, r.id_reservation
         LIMIT 1"
    );
    $statement->execute(['cours' => $courseId]);
    $first = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$first) {
        return null;
    }

    $db->prepare(
        "UPDATE reservations SET statut = 'en_attente', date_statut = CURRENT_TIMESTAMP
         WHERE id_reservation = :id AND statut = 'liste_attente'"
    )->execute(['id' => $first['id_reservation']]);

    $first['id_reservation'] = (int) $first['id_reservation'];
    $first['id_adherent']    = (int) $first['id_adherent'];

    return $first;
}
