<?php
// Inscription et désinscription d'un adhérent à un cours
require_once __DIR__ . '/planning.php';

// id_adherent du compte, ou null s'il n'a pas de profil adhérent
function getAdherentIdByAccount(PDO $db, int $accountId): ?int
{
    $statement = $db->prepare('SELECT id_adherent FROM adherents WHERE id_compte = :compte');
    $statement->execute(['compte' => $accountId]);
    $adherentId = $statement->fetchColumn();

    return $adherentId === false ? null : (int) $adherentId;
}

// Parmi ces cours, ceux auxquels l'adhérent est inscrit
function getRegisteredCourseIds(PDO $db, int $adherentId, array $courseIds): array
{
    if ($courseIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($courseIds), '?'));
    $statement    = $db->prepare(
        "SELECT id_cours FROM reservations
         WHERE statut = 'confirmee' AND id_adherent = ? AND id_cours IN ($placeholders)"
    );
    $statement->execute([$adherentId, ...array_values($courseIds)]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

// Ce que la fenêtre de détail propose à un adhérent, pour un cours préparé (prepareCourse) :
// 'inscrit', 'complet', 'disponible', ou null si le cours a commencé
function courseRegistrationState(array $course, bool $registered): ?string
{
    if ($course['termine']) {
        return null;
    }
    if ($registered) {
        return 'inscrit';
    }

    return $course['complet'] ? 'complet' : 'disponible';
}

// Inscrit l'adhérent, ou réactive sa réservation s'il avait quitté ce cours.
// Renvoie ['status' => 'registered', 'not_found', 'started', 'already_registered', 'full' ou 'overlap',
//          'conflict' => cours qui chevauche ('titre', 'date_debut'), ou null]
function registerForCourse(PDO $db, int $adherentId, int $courseId, DateTimeImmutable $now): array
{
    $db->beginTransaction();

    try {
        // Verrouille l'adhérent et le cours jusqu'à la fin de la transaction : deux inscriptions
        // en même temps ne peuvent pas prendre la dernière place ou créer un chevauchement
        $db->prepare('UPDATE adherents SET id_compte = id_compte WHERE id_adherent = :id')->execute(['id' => $adherentId]);
        $db->prepare('UPDATE cours SET nb_places_max = nb_places_max WHERE id_cours = :id')->execute(['id' => $courseId]);

        $result = attemptCourseRegistration($db, $adherentId, $courseId, $now);
        $db->commit();

        return $result + ['conflict' => null];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
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

    if ($previousStatus === 'confirmee') {
        return ['status' => 'already_registered'];
    }
    if ((int) $course['nb_inscrits'] >= (int) $course['nb_places_max']) {
        return ['status' => 'full'];
    }

    // Deux cours qui se suivent ne se chevauchent pas
    $statement = $db->prepare(
        "SELECT c.titre, c.date_debut
         FROM reservations r
         INNER JOIN cours c ON c.id_cours = r.id_cours
         WHERE r.id_adherent = :adherent AND r.statut = 'confirmee' AND c.id_cours <> :cours
           AND c.date_debut < :fin AND c.date_fin > :debut
         ORDER BY c.date_debut"
    );
    $statement->execute([
        'adherent' => $adherentId,
        'cours'    => $courseId,
        'debut'    => toUtcString($start),
        'fin'      => toUtcString(new DateTimeImmutable($course['date_fin'])),
    ]);
    $conflict = $statement->fetch(PDO::FETCH_ASSOC);
    if ($conflict) {
        return ['status' => 'overlap', 'conflict' => $conflict];
    }

    // Une seule ligne par adhérent et par cours : on réutilise celle d'une ancienne réservation
    $sql = $previousStatus === false
        ? 'INSERT INTO reservations (id_cours, id_adherent) VALUES (:cours, :adherent)'
        : "UPDATE reservations SET statut = 'confirmee', date_reservation = CURRENT_TIMESTAMP
           WHERE id_cours = :cours AND id_adherent = :adherent";
    $db->prepare($sql)->execute(['cours' => $courseId, 'adherent' => $adherentId]);

    return ['status' => 'registered'];
}

// Libère la place. Le statut 'absent' fait compter la désinscription dans adherents.nb_absences (trigger de la base).
// Renvoie 'unregistered', 'not_registered' ou 'started'
function unregisterFromCourse(PDO $db, int $adherentId, int $courseId, DateTimeImmutable $now): string
{
    $statement = $db->prepare('SELECT date_debut FROM cours WHERE id_cours = :id');
    $statement->execute(['id' => $courseId]);
    $start = $statement->fetchColumn();

    if ($start === false) {
        return 'not_registered';
    }
    if (new DateTimeImmutable($start) <= $now) {
        return 'started';
    }

    // Condition sur le statut : un double clic ne compte qu'une seule absence
    $statement = $db->prepare(
        "UPDATE reservations SET statut = 'absent'
         WHERE id_cours = :cours AND id_adherent = :adherent AND statut = 'confirmee'"
    );
    $statement->execute(['cours' => $courseId, 'adherent' => $adherentId]);

    return $statement->rowCount() > 0 ? 'unregistered' : 'not_registered';
}
