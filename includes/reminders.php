<?php
// Rappel 2 jours avant la séance aux coachs qui ont encore des demandes en attente (US6).
// Aucune demande n'est traitée automatiquement.
require_once __DIR__ . '/planning.php';

const REMINDER_DAYS_BEFORE = 2;

// Séances à venir qui commencent dans 2 jours ou moins, sans rappel envoyé, avec au moins une demande en attente.
// Un seul rappel par séance : cours.rappel_envoye_le est rempli dès qu'il part (vide = pas encore rappelé).
// Si personne n'a ouvert le site pile 2 jours avant, le rappel part à la visite suivante, tant que la séance n'a pas commencé.
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
           AND c.date_debut > :maintenant AND c.date_debut <= :limite
         ORDER BY c.date_debut"
    );
    $statement->execute([
        'maintenant' => toUtcString($now),
        'limite'     => toUtcString($now->modify('+' . REMINDER_DAYS_BEFORE . ' days')),
    ]);

    $courses = array_filter($statement->fetchAll(PDO::FETCH_ASSOC), fn (array $c) => (int) $c['nb_demandes'] > 0);

    return array_values(array_map(fn (array $c) => ['nb_demandes' => (int) $c['nb_demandes']] + $c, $courses));
}

function markReminderSent(PDO $db, int $courseId): void
{
    $db->prepare('UPDATE cours SET rappel_envoye_le = CURRENT_TIMESTAMP WHERE id_cours = :id')->execute(['id' => $courseId]);
}

// Réserve le rappel d'une séance avant de l'envoyer. Renvoie false si un autre passage l'a déjà pris
// (deux visites au même moment, ou le site et le script lancés en même temps) : un seul mail part
function claimReminder(PDO $db, int $courseId): bool
{
    $statement = $db->prepare(
        'UPDATE cours SET rappel_envoye_le = CURRENT_TIMESTAMP WHERE id_cours = :id AND rappel_envoye_le IS NULL'
    );
    $statement->execute(['id' => $courseId]);

    return $statement->rowCount() > 0;
}

// L'envoi a échoué : la séance redevient « à rappeler » pour le prochain passage
function releaseReminder(PDO $db, int $courseId): void
{
    $db->prepare('UPDATE cours SET rappel_envoye_le = NULL WHERE id_cours = :id')->execute(['id' => $courseId]);
}

// Envoie les rappels dus et renvoie une ligne de compte rendu par séance.
// $testTo : envoie tout à cette adresse sans marquer les séances. $dryRun : n'envoie rien et ne marque rien
function sendDueReminders(PDO $db, DateTimeImmutable $now, ?string $testTo = null, bool $dryRun = false): array
{
    require_once __DIR__ . '/mailer.php';

    $lines = [];
    foreach (getCoursesNeedingReminder($db, $now) as $course) {
        $courseId = (int) $course['id_cours'];
        $start    = (new DateTimeImmutable($course['date_debut']))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));
        $when     = formatLongDate($start) . ' à ' . $start->format('H:i');
        $to       = $testTo ?? $course['coach_email'];
        $line     = "{$course['titre']} ({$when}), {$course['nb_demandes']} demande(s) -> {$to}";

        if ($dryRun) {
            $lines[] = $line . ' [non envoyé : --dry-run]';
            continue;
        }
        // En mode test, la séance n'est pas marquée : le vrai coach recevra son rappel au prochain passage
        if ($testTo === null && !claimReminder($db, $courseId)) {
            continue;
        }

        $sent = sendCoachReminderMail($to, $course['coach_prenom'], $course['titre'], $when, $course['nb_demandes'], validationLink($courseId));
        if (!$sent && $testTo === null) {
            releaseReminder($db, $courseId);
        }
        $lines[] = $line . ($sent ? ' [envoyé]' : ' [ÉCHEC de l\'envoi]');
    }

    return $lines;
}

// Fichier qui retient l'heure du dernier passage automatique (un par poste : chacun fait tourner le site en local)
const REMINDER_LAST_RUN_FILE = __DIR__ . '/../cron/dernier-passage.txt';

// Envoi automatique, sans tâche planifiée : appelé à chaque page (footer), il ne fait réellement le travail
// qu'au premier chargement de chaque heure. Si personne n'ouvre le site à ce moment-là, les rappels partent à la visite suivante
// (tant que la séance n'a pas commencé). Ne lève jamais d'erreur : la page reste affichée.
function runRemindersIfDue(DateTimeImmutable $now): void
{
    $currentHour  = $now->format('Y-m-d H');  // une vérification par heure
    $handle = @fopen(REMINDER_LAST_RUN_FILE, 'c+');
    if (!$handle) {
        error_log('Rappels automatiques : impossible d\'ouvrir ' . REMINDER_LAST_RUN_FILE);
        return;
    }

    try {
        // Verrou non bloquant : si une autre visite est déjà en train de vérifier, on ne l'attend pas
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            return;
        }
        if (trim((string) stream_get_contents($handle)) === $currentHour) {
            return;
        }

        // Le jour est noté avant l'envoi : une erreur ne relance pas la vérification à chaque page
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $currentHour);
        fflush($handle);

        foreach (sendDueReminders(getDatabaseConnection(), $now) as $line) {
            error_log('Rappel automatique : ' . $line);
        }
    } catch (Throwable $e) {
        error_log('Rappels automatiques : ' . $e->getMessage());
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// Lien direct vers la page de validation de cette séance
function validationLink(int $courseId): string
{
    return APP_URL . '/validations.php?cours=' . $courseId . '#cours-' . $courseId;
}
