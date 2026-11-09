<?php
// Motifs (refus, désinscription, annulation) et historique des séances
require_once __DIR__ . '/config.php';

const REASON_OTHER = 'autre';
const REASON_MAX   = 1000;

// Motifs proposés au coach pour refuser une demande d'inscription
const REFUSAL_REASONS = [
    'Séance complète',
    'Niveau non adapté à la séance',
    'Profil incomplet',
    'Séance réservée à un autre groupe',
];

// Motifs proposés à l'adhérent qui se désinscrit
const UNREGISTER_REASONS = [
    'Empêchement personnel',
    'Raison de santé',
    'Contrainte professionnelle',
    'Je me suis trompé(e) de séance',
];

// Motifs proposés au coach qui annule une séance
const CANCELLATION_REASONS = [
    'Coach indisponible',
    'Salle indisponible',
    'Pas assez d\'inscrits',
    'Problème de matériel',
];

// Motif choisi dans une liste, ou écrit dans le champ « Autre ». Renvoie ['motif' => string, 'error' => null|string]
function validateReason(string $choice, string $other, array $reasons): array
{
    $choice = trim($choice);
    $other  = trim($other);

    if ($choice === REASON_OTHER) {
        if ($other === '') {
            return ['motif' => '', 'error' => 'Précise le motif dans le champ « Autre ».'];
        }
        if (mb_strlen($other) > REASON_MAX) {
            return ['motif' => $other, 'error' => REASON_MAX . ' caractères maximum.'];
        }

        return ['motif' => $other, 'error' => null];
    }

    if (!in_array($choice, $reasons, true)) {
        return ['motif' => '', 'error' => 'Choisis un motif.'];
    }

    return ['motif' => $choice, 'error' => null];
}

// Enregistre un refus, une désinscription ou une annulation.
// $course : id_cours, titre, date_debut, nom_salle (facultatif), id_coach, nom_coach (facultatif).
// $adherent : ['id_adherent', 'nom_adherent'], ou null pour la ligne « séance annulée » vue côté coach.
function logSessionEvent(PDO $db, string $type, string $reason, array $course, ?array $adherent = null): void
{
    if (!in_array($type, ['refus', 'desinscription', 'annulation'], true)) {
        throw new InvalidArgumentException('Type d\'événement inconnu : ' . $type);
    }

    $db->prepare(
        'INSERT INTO historique_seances
            (type, motif, id_cours, titre_cours, date_cours, nom_salle, id_coach, nom_coach, id_adherent, nom_adherent)
         VALUES (:type, :motif, :cours, :titre, :date_cours, :salle, :coach, :nom_coach, :adherent, :nom_adherent)'
    )->execute([
        'type'         => $type,
        'motif'        => $reason,
        'cours'        => (int) $course['id_cours'],
        'titre'        => $course['titre'],
        'date_cours'   => $course['date_debut'],
        'salle'        => $course['nom_salle'] ?? null,
        'coach'        => isset($course['id_coach']) ? (int) $course['id_coach'] : null,
        'nom_coach'    => $course['nom_coach'] ?? null,
        'adherent'     => $adherent ? (int) $adherent['id_adherent'] : null,
        'nom_adherent' => $adherent['nom_adherent'] ?? null,
    ]);
}

// Période choisie dans un formulaire (?du=AAAA-MM-JJ&au=AAAA-MM-JJ, heure de Paris, "au" inclus).
// Une date absente ou invalide est ignorée. Renvoie ['du' => ?DateTimeImmutable, 'au' => ?DateTimeImmutable]
function parsePeriod(array $input): array
{
    $date = function (string $key) use ($input): ?DateTimeImmutable {
        $value = $input[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Paris'));

        return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : null;
    };

    return ['du' => $date('du'), 'au' => $date('au')];
}

// Infos d'une séance à recopier dans l'historique (null si elle n'existe pas)
function getCourseForHistory(PDO $db, int $courseId): ?array
{
    $statement = $db->prepare(
        "SELECT c.id_cours, c.titre, c.date_debut, c.date_fin, c.nb_places_max, c.id_coach, s.nom_salle,
                co.prenom || ' ' || co.nom AS nom_coach, co.prenom AS coach_prenom, cpt.email AS coach_email
         FROM cours c
         INNER JOIN coachs co ON co.id_coach = c.id_coach
         INNER JOIN comptes cpt ON cpt.id_compte = co.id_compte
         LEFT JOIN salles s ON s.id_salle = c.id_salle
         WHERE c.id_cours = :id"
    );
    $statement->execute(['id' => $courseId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}
