<?php
// Rappel J-3 aux coachs qui ont encore des demandes en attente (US6). Aucune demande n'est traitée automatiquement.
require_once __DIR__ . '/planning.php';

// Séances qui commencent dans 2 à 3 jours, sans rappel envoyé, avec au moins une demande en attente.
// Lancé une fois par jour, chaque séance passe une seule fois dans cette fenêtre de 24 h.
// Renvoie [['id_cours', 'titre', 'date_debut', 'coach_prenom', 'coach_email', 'nb_demandes'], ...]
function getCoursesNeedingReminder(PDO $db, DateTimeImmutable $now): array
{
    $statement = $db->prepare(
        "SELECT c.id_cours, c.titre, c.date_debut, co.prenom AS coach_prenom, cpt.email AS coach_email,
                (SELECT COUNT(*) FROM reservations r WHERE r.id_cours = c.id_cours AND r.statut = 'en_attente') AS nb_demandes
         FROM cours c
         INNER JOIN coachs co ON co.id_coach = c.id_coach
         INNER JOIN comptes cpt ON cpt.id_compte = co.id_compte
         WHERE c.rappel_envoye_le IS NULL
           AND c.date_debut >= :debut AND c.date_debut < :fin
         ORDER BY c.date_debut"
    );
    $statement->execute([
        'debut' => toUtcString($now->modify('+2 days')),
        'fin'   => toUtcString($now->modify('+3 days')),
    ]);

    $courses = array_filter($statement->fetchAll(PDO::FETCH_ASSOC), fn (array $c) => (int) $c['nb_demandes'] > 0);

    return array_values(array_map(fn (array $c) => ['nb_demandes' => (int) $c['nb_demandes']] + $c, $courses));
}

function markReminderSent(PDO $db, int $courseId): void
{
    $db->prepare('UPDATE cours SET rappel_envoye_le = CURRENT_TIMESTAMP WHERE id_cours = :id')->execute(['id' => $courseId]);
}

// Lien direct vers la page de validation de cette séance
function validationLink(int $courseId): string
{
    return APP_URL . '/validations.php?cours=' . $courseId . '#cours-' . $courseId;
}
