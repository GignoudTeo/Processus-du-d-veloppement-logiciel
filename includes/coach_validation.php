<?php
// Validation par le coach des demandes d'inscription à ses séances (US4, US5)
require_once __DIR__ . '/reservations.php';

// Séances du coach qui ne sont pas terminées, avec leurs demandes, validés et liste d'attente.
// Une demande non traitée reste en attente : le coach peut la traiter jusqu'à la fin de la séance.
// Renvoie une liste de séances triées par date, chacune avec 'demandes', 'valides', 'liste_attente'
function getCoachBookingOverview(PDO $db, int $coachId, DateTimeImmutable $now, ?int $courseId = null): array
{
    $sql = "SELECT c.id_cours, c.titre, c.date_debut, c.date_fin, c.nb_places_max, s.nom_salle
            FROM cours c
            LEFT JOIN salles s ON s.id_salle = c.id_salle
            WHERE c.id_coach = :coach AND c.date_fin > :now";
    $params = ['coach' => $coachId, 'now' => toUtcString($now)];
    if ($courseId !== null) {
        $sql .= ' AND c.id_cours = :cours';
        $params['cours'] = $courseId;
    }
    $statement = $db->prepare($sql . ' ORDER BY c.date_debut, c.id_cours');
    $statement->execute($params);
    $courses = $statement->fetchAll(PDO::FETCH_ASSOC);

    if ($courses === []) {
        return [];
    }

    // Toutes les réservations utiles de ces séances, avec le profil de l'adhérent
    $ids          = array_column($courses, 'id_cours');
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement    = $db->prepare(
        "SELECT r.id_reservation, r.id_cours, r.statut, r.date_reservation,
                a.id_adherent, a.prenom, a.nom, a.date_naissance, a.poids, a.taille,
                a.nb_reservations, a.nb_reservations_rejetees, a.nb_absences,
                cpt.email, cpt.date_creation
         FROM reservations r
         INNER JOIN adherents a ON a.id_adherent = r.id_adherent
         INNER JOIN comptes cpt ON cpt.id_compte = a.id_compte
         WHERE r.id_cours IN ($placeholders) AND r.statut IN ('en_attente', 'confirmee', 'liste_attente')
         ORDER BY r.date_reservation, r.id_reservation"
    );
    $statement->execute($ids);

    $byCourse = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['age'] = memberAge($row['date_naissance'], $now);
        $byCourse[(int) $row['id_cours']][$row['statut']][] = $row;
    }

    foreach ($courses as &$course) {
        $bookings                = $byCourse[(int) $course['id_cours']] ?? [];
        $course['demandes']      = $bookings['en_attente'] ?? [];
        $course['valides']       = $bookings['confirmee'] ?? [];
        $course['liste_attente'] = $bookings['liste_attente'] ?? [];
        $course['nb_valides']    = count($course['valides']);
        $course['complet']       = $course['nb_valides'] >= (int) $course['nb_places_max'];
    }

    return $courses;
}

// Nombre de demandes en attente sur les séances non terminées du coach (pastille du menu)
function countCoachPendingRequests(PDO $db, int $coachId, DateTimeImmutable $now): int
{
    $statement = $db->prepare(
        "SELECT COUNT(*) FROM reservations r
         INNER JOIN cours c ON c.id_cours = r.id_cours
         WHERE c.id_coach = :coach AND c.date_fin > :now AND r.statut = 'en_attente'"
    );
    $statement->execute(['coach' => $coachId, 'now' => toUtcString($now)]);

    return (int) $statement->fetchColumn();
}

// Âge en années à partir de la date de naissance (AAAA-MM-JJ), ou null
function memberAge(?string $birthdate, DateTimeImmutable $now): ?int
{
    if (!$birthdate) {
        return null;
    }

    return (new DateTimeImmutable(substr($birthdate, 0, 10)))->diff($now)->y;
}

// Réservation d'une séance du coach, avec la séance et l'adhérent, ou null (introuvable ou autre coach)
function getCoachBooking(PDO $db, int $reservationId, int $coachId): ?array
{
    $statement = $db->prepare(
        "SELECT r.id_reservation, r.id_cours, r.id_adherent, r.statut,
                c.titre, c.date_debut, c.date_fin, c.nb_places_max, c.id_coach,
                a.prenom, a.nom, cpt.email
         FROM reservations r
         INNER JOIN cours c ON c.id_cours = r.id_cours
         INNER JOIN adherents a ON a.id_adherent = r.id_adherent
         INNER JOIN comptes cpt ON cpt.id_compte = a.id_compte
         WHERE r.id_reservation = :id AND c.id_coach = :coach"
    );
    $statement->execute(['id' => $reservationId, 'coach' => $coachId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function countValidated(PDO $db, int $courseId): int
{
    $statement = $db->prepare("SELECT COUNT(*) FROM reservations WHERE id_cours = :id AND statut = 'confirmee'");
    $statement->execute(['id' => $courseId]);

    return (int) $statement->fetchColumn();
}

// Valide une demande. Le nombre de validés ne peut pas dépasser la capacité de la séance.
// Renvoie ['status' => 'validated', 'not_found', 'not_pending', 'ended' ou 'full', 'booking' => réservation ou null]
function validateBooking(PDO $db, int $reservationId, int $coachId, DateTimeImmutable $now): array
{
    $db->beginTransaction();
    try {
        $booking = getCoachBooking($db, $reservationId, $coachId);
        if (!$booking) {
            $db->rollBack();
            return ['status' => 'not_found', 'booking' => null];
        }

        // Verrouille la séance : deux validations en même temps ne peuvent pas dépasser la capacité
        $db->prepare('UPDATE cours SET nb_places_max = nb_places_max WHERE id_cours = :id')->execute(['id' => $booking['id_cours']]);

        $status = null;
        if ($booking['statut'] !== 'en_attente') {
            $status = 'not_pending';
        } elseif (new DateTimeImmutable($booking['date_fin']) <= $now) {
            $status = 'ended';
        } elseif (countValidated($db, (int) $booking['id_cours']) >= (int) $booking['nb_places_max']) {
            $status = 'full';
        }

        if ($status === null) {
            $db->prepare(
                "UPDATE reservations SET statut = 'confirmee', motif = NULL, date_statut = CURRENT_TIMESTAMP
                 WHERE id_reservation = :id AND statut = 'en_attente'"
            )->execute(['id' => $reservationId]);
            $status = 'validated';
        }

        $db->commit();

        return ['status' => $status, 'booking' => $booking];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Refuse une demande en attente, avec un motif obligatoire. Le refus est enregistré dans l'historique.
// Renvoie ['status' => 'refused', 'not_found', 'not_pending' ou 'reason_required', 'booking' => réservation ou null]
function refuseBooking(PDO $db, int $reservationId, int $coachId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['status' => 'reason_required', 'booking' => null];
    }

    $db->beginTransaction();
    try {
        $booking = getCoachBooking($db, $reservationId, $coachId);
        if (!$booking) {
            $db->rollBack();
            return ['status' => 'not_found', 'booking' => null];
        }

        $refused = refusePendingBooking($db, $booking, $reason);
        $db->commit();

        return ['status' => $refused ? 'refused' : 'not_pending', 'booking' => $booking];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// Passe une demande « en attente » en refusée et l'enregistre dans l'historique. À appeler dans une transaction
function refusePendingBooking(PDO $db, array $booking, string $reason): bool
{
    $statement = $db->prepare(
        "UPDATE reservations SET statut = 'rejetee', motif = :motif, date_statut = CURRENT_TIMESTAMP
         WHERE id_reservation = :id AND statut = 'en_attente'"
    );
    $statement->execute(['motif' => $reason, 'id' => $booking['id_reservation']]);

    if ($statement->rowCount() === 0) {
        return false;
    }

    $course = getCourseForHistory($db, (int) $booking['id_cours']);
    logSessionEvent($db, 'refus', $reason, $course, [
        'id_adherent'  => (int) $booking['id_adherent'],
        'nom_adherent' => $booking['prenom'] . ' ' . $booking['nom'],
    ]);

    return true;
}

// Séance complète : refuse en une fois toutes les demandes encore en attente, chacune enregistrée à part.
// Renvoie ['status' => 'refused', 'not_found', 'not_full' ou 'reason_required', 'refused' => réservations refusées]
function refuseAllPending(PDO $db, int $courseId, int $coachId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['status' => 'reason_required', 'refused' => []];
    }

    $db->beginTransaction();
    try {
        $course = getCourseForHistory($db, $courseId);
        if (!$course || (int) $course['id_coach'] !== $coachId) {
            $db->rollBack();
            return ['status' => 'not_found', 'refused' => []];
        }
        // Le bouton n'existe que lorsque la séance est complète
        if (countValidated($db, $courseId) < (int) $course['nb_places_max']) {
            $db->rollBack();
            return ['status' => 'not_full', 'refused' => []];
        }

        $statement = $db->prepare(
            "SELECT r.id_reservation FROM reservations r
             WHERE r.id_cours = :cours AND r.statut = 'en_attente'
             ORDER BY r.date_reservation, r.id_reservation"
        );
        $statement->execute(['cours' => $courseId]);

        $refused = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $reservationId) {
            $booking = getCoachBooking($db, (int) $reservationId, $coachId);
            if ($booking && refusePendingBooking($db, $booking, $reason)) {
                $refused[] = $booking;
            }
        }

        $db->commit();

        return ['status' => 'refused', 'refused' => $refused];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
