<?php
// Création d'une séance par un administrateur : la salle d'abord, puis un créneau libre (US1)
require_once __DIR__ . '/planning.php';

// Créneaux d'une heure : le premier commence à 8 h, le dernier à 20 h (fin à 21 h)
const SLOT_FIRST_HOUR = 8;
const SLOT_LAST_HOUR  = 20;
const SLOT_MINUTES    = 60;

// Capacité d'une séance : capacité de la salle ÷ 5, arrondie à l'entier inférieur, au moins 1
function sessionCapacity(int $roomCapacity): int
{
    return max(1, intdiv(max(0, $roomCapacity), 5));
}

// Date du formulaire (AAAA-MM-JJ, heure de Paris), ou null si elle n'existe pas
function parseSlotDate(?string $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(PLANNING_TIMEZONE));

    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

// Séances déjà prévues ce jour-là : [id_salle => [['debut', 'fin'], ...]] (dates en heure de Paris)
function getRoomBookings(PDO $db, DateTimeImmutable $day): array
{
    $statement = $db->prepare(
        'SELECT id_salle, date_debut, date_fin FROM cours
         WHERE date_debut < :fin AND date_fin > :debut'
    );
    $statement->execute(['debut' => toUtcString($day), 'fin' => toUtcString($day->modify('+1 day'))]);

    $timezone = new DateTimeZone(PLANNING_TIMEZONE);
    $bookings = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bookings[(int) $row['id_salle']][] = [
            'debut' => (new DateTimeImmutable($row['date_debut']))->setTimezone($timezone),
            'fin'   => (new DateTimeImmutable($row['date_fin']))->setTimezone($timezone),
        ];
    }

    return $bookings;
}

// Créneaux de la journée pour une salle : [['debut', 'fin', 'libre' => bool, 'raison' => null|'occupe'|'passe'], ...]
// $roomBookings : séances de cette salle ce jour-là (getRoomBookings)
function getDaySlots(DateTimeImmutable $day, array $roomBookings, DateTimeImmutable $now): array
{
    $slots = [];
    for ($hour = SLOT_FIRST_HOUR; $hour <= SLOT_LAST_HOUR; $hour++) {
        $start = $day->setTime($hour, 0);
        $end   = $start->modify('+' . SLOT_MINUTES . ' minutes');

        $reason = null;
        if ($start <= $now) {
            $reason = 'passe';
        } else {
            foreach ($roomBookings as $booking) {
                // Deux séances qui se suivent ne se chevauchent pas
                if ($booking['debut'] < $end && $booking['fin'] > $start) {
                    $reason = 'occupe';
                    break;
                }
            }
        }

        $slots[] = ['debut' => $start, 'fin' => $end, 'libre' => $reason === null, 'raison' => $reason];
    }

    return $slots;
}

// Salles avec leur capacité de séance et leur nombre de créneaux libres ce jour-là ('complete' : aucun créneau libre)
function getRoomsAvailability(PDO $db, DateTimeImmutable $day, DateTimeImmutable $now): array
{
    $rooms    = $db->query('SELECT id_salle, nom_salle, capacite, adresse FROM salles ORDER BY nom_salle')->fetchAll(PDO::FETCH_ASSOC);
    $bookings = getRoomBookings($db, $day);

    foreach ($rooms as &$room) {
        $slots                     = getDaySlots($day, $bookings[(int) $room['id_salle']] ?? [], $now);
        $room['capacite_seance']   = sessionCapacity((int) $room['capacite']);
        $room['creneaux_libres']   = count(array_filter($slots, fn (array $slot) => $slot['libre']));
        $room['complete']          = $room['creneaux_libres'] === 0;
        $room['creneaux']          = $slots;
    }

    return $rooms;
}

// Vérifie le formulaire de création. Renvoie [données, erreurs par champ].
// $rooms : getRoomsAvailability() pour la date choisie ; $coaches : getAssignableCoaches()
function validateSessionForm(array $input, ?DateTimeImmutable $day, array $rooms, array $coaches, array $validSportIds): array
{
    $data = [
        'date'        => trim((string) ($input['date'] ?? '')),
        'id_salle'    => trim((string) ($input['id_salle'] ?? '')),
        'creneau'     => trim((string) ($input['creneau'] ?? '')),
        'id_coach'    => trim((string) ($input['id_coach'] ?? '')),
        'id_sport'    => trim((string) ($input['id_sport'] ?? '')),
        'titre'       => trim((string) ($input['titre'] ?? '')),
        'description' => trim((string) ($input['description'] ?? '')),
    ];
    $errors = [];

    if (!$day) {
        $errors['date'] = 'Choisis une date.';
    }

    $room = null;
    foreach ($rooms as $candidate) {
        if ((string) $candidate['id_salle'] === $data['id_salle']) {
            $room = $candidate;
        }
    }
    if (!$room) {
        $errors['id_salle'] = 'Choisis une salle.';
    }

    // Le créneau doit faire partie des créneaux libres de cette salle (vérifié de nouveau à l'enregistrement)
    $slot = null;
    foreach ($room['creneaux'] ?? [] as $candidate) {
        if ($candidate['debut']->format('H:i') === $data['creneau']) {
            $slot = $candidate;
        }
    }
    if ($room && !$slot) {
        $errors['creneau'] = 'Choisis un créneau.';
    } elseif ($slot && !$slot['libre']) {
        $errors['creneau'] = 'Ce créneau n\'est plus libre, choisis-en un autre.';
    }

    if (!in_array((int) $data['id_coach'], array_map('intval', array_column($coaches, 'id_coach')), true)) {
        $errors['id_coach'] = 'Choisis un coach.';
    }
    if (!in_array((int) $data['id_sport'], $validSportIds, true)) {
        $errors['id_sport'] = 'Choisis un sport.';
    }
    if ($data['titre'] === '') {
        $errors['titre'] = 'Indique un titre.';
    } elseif (mb_strlen($data['titre']) > 150) {
        $errors['titre'] = '150 caractères maximum.';
    }
    if (mb_strlen($data['description']) > 2000) {
        $errors['description'] = '2000 caractères maximum.';
    }

    if ($errors === []) {
        $data['debut']         = $slot['debut'];
        $data['fin']           = $slot['fin'];
        $data['nb_places_max'] = $room['capacite_seance'];
    }

    return [$data, $errors];
}

// Crée la séance si la salle est toujours libre sur ce créneau (deux admins en même temps).
// Renvoie id_cours, ou null si le créneau a été pris entre-temps
function createSession(PDO $db, array $data): ?int
{
    $db->beginTransaction();
    try {
        // Verrouille la salle jusqu'à la fin de la transaction
        $db->prepare('UPDATE salles SET capacite = capacite WHERE id_salle = :id')->execute(['id' => (int) $data['id_salle']]);

        $statement = $db->prepare(
            'SELECT COUNT(*) FROM cours WHERE id_salle = :salle AND date_debut < :fin AND date_fin > :debut'
        );
        $statement->execute([
            'salle' => (int) $data['id_salle'],
            'debut' => toUtcString($data['debut']),
            'fin'   => toUtcString($data['fin']),
        ]);
        if ((int) $statement->fetchColumn() > 0) {
            $db->rollBack();
            return null;
        }

        $statement = $db->prepare(
            'INSERT INTO cours (titre, description, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max)
             VALUES (:titre, :description, :sport, :coach, :salle, :debut, :fin, :places)
             RETURNING id_cours'
        );
        $statement->execute([
            'titre'       => $data['titre'],
            'description' => $data['description'] === '' ? null : $data['description'],
            'sport'       => (int) $data['id_sport'],
            'coach'       => (int) $data['id_coach'],
            'salle'       => (int) $data['id_salle'],
            'debut'       => toUtcString($data['debut']),
            'fin'         => toUtcString($data['fin']),
            'places'      => (int) $data['nb_places_max'],
        ]);
        $courseId = (int) $statement->fetchColumn();
        $statement->closeCursor();

        $db->commit();

        return $courseId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
