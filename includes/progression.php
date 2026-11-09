<?php
// Suivi de progression de l'adhérent : poids, taille, durée de séance et IMC calculé (US11)
require_once __DIR__ . '/config.php';

// Fourchettes réalistes acceptées : [min, max]
const PROGRESSION_LIMITS = [
    'poids'        => [20, 300],   // kg
    'taille'       => [100, 250],  // cm
    'duree_seance' => [1, 600],    // minutes
];

// IMC = poids (kg) ÷ taille (m)², arrondi à 0,1
function computeBmi(float $weight, float $heightCm): float
{
    $height = $heightCm / 100;

    return round($weight / ($height * $height), 1);
}

// Nombre saisi (virgule ou point), ou null s'il n'est pas valide
function parseDecimal(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $value = str_replace(',', '.', trim((string) $value));

    return is_numeric($value) ? (float) $value : null;
}

// Renvoie [données nettoyées, erreurs par champ]
function validateMeasurement(array $input, DateTimeImmutable $now): array
{
    $data   = ['date_mesure' => trim((string) ($input['date_mesure'] ?? ''))];
    $errors = [];

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['date_mesure']);
    if (!$date || $date->format('Y-m-d') !== $data['date_mesure']) {
        $errors['date_mesure'] = 'Indique une date valide.';
    } elseif ($data['date_mesure'] > $now->format('Y-m-d')) {
        $errors['date_mesure'] = 'La date ne peut pas être dans le futur.';
    } elseif ($data['date_mesure'] < '1900-01-01') {
        $errors['date_mesure'] = 'Indique une date valide.';
    }

    $labels = ['poids' => 'Le poids', 'taille' => 'La taille', 'duree_seance' => 'La durée'];
    $units  = ['poids' => 'kg', 'taille' => 'cm', 'duree_seance' => 'min'];
    foreach (PROGRESSION_LIMITS as $field => [$min, $max]) {
        $value = parseDecimal($input[$field] ?? '');
        if ($field === 'duree_seance' && $value !== null && floor($value) !== $value) {
            $value = null;
        }

        if ($value === null || $value <= 0) {
            $errors[$field] = $labels[$field] . ' doit être un nombre positif.';
        } elseif ($value < $min || $value > $max) {
            $errors[$field] = $labels[$field] . " doit être compris(e) entre $min et $max {$units[$field]}.";
        } else {
            $data[$field] = $field === 'duree_seance' ? (int) $value : round($value, 2);
        }
    }

    return [$data, $errors];
}

// Mesures de l'adhérent, de la plus ancienne à la plus récente, avec l'IMC
function getMeasurements(PDO $db, int $adherentId): array
{
    $statement = $db->prepare(
        'SELECT id_mesure, date_mesure, poids, taille, duree_seance FROM progression
         WHERE id_adherent = :adherent ORDER BY date_mesure, id_mesure'
    );
    $statement->execute(['adherent' => $adherentId]);

    return array_map(fn (array $row) => [
        'id_mesure'    => (int) $row['id_mesure'],
        'date_mesure'  => substr($row['date_mesure'], 0, 10),
        'poids'        => (float) $row['poids'],
        'taille'       => (float) $row['taille'],
        'duree_seance' => (int) $row['duree_seance'],
        'imc'          => computeBmi((float) $row['poids'], (float) $row['taille']),
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function createMeasurement(PDO $db, int $adherentId, array $data): int
{
    $statement = $db->prepare(
        'INSERT INTO progression (id_adherent, date_mesure, poids, taille, duree_seance)
         VALUES (:adherent, :date, :poids, :taille, :duree)
         RETURNING id_mesure'
    );
    $statement->execute([
        'adherent' => $adherentId,
        'date'     => $data['date_mesure'],
        'poids'    => $data['poids'],
        'taille'   => $data['taille'],
        'duree'    => $data['duree_seance'],
    ]);

    return (int) $statement->fetchColumn();
}

// false si la mesure n'existe pas ou appartient à un autre adhérent
function updateMeasurement(PDO $db, int $measurementId, int $adherentId, array $data): bool
{
    $statement = $db->prepare(
        'UPDATE progression SET date_mesure = :date, poids = :poids, taille = :taille, duree_seance = :duree
         WHERE id_mesure = :id AND id_adherent = :adherent'
    );
    $statement->execute([
        'date'     => $data['date_mesure'],
        'poids'    => $data['poids'],
        'taille'   => $data['taille'],
        'duree'    => $data['duree_seance'],
        'id'       => $measurementId,
        'adherent' => $adherentId,
    ]);

    return $statement->rowCount() > 0;
}

// false si la mesure n'existe pas ou appartient à un autre adhérent
function deleteMeasurement(PDO $db, int $measurementId, int $adherentId): bool
{
    $statement = $db->prepare('DELETE FROM progression WHERE id_mesure = :id AND id_adherent = :adherent');
    $statement->execute(['id' => $measurementId, 'adherent' => $adherentId]);

    return $statement->rowCount() > 0;
}
