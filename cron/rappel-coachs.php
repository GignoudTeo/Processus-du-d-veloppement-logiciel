<?php
// Tâche planifiée (US6) : rappel aux coachs qui ont encore des demandes en attente 2 à 3 jours avant leur séance.
//
// À lancer une fois par jour, par exemple à 8 h avec cron (crontab -e) :
//   0 8 * * * /Applications/MAMP/bin/php/php8.2.26/bin/php "/chemin/vers/le/projet/cron/rappel-coachs.php"
//
// Options :
//   --dry-run        affiche les rappels sans envoyer de mail ni les marquer comme envoyés
//   --to=adresse     envoie tous les rappels à cette adresse (boîte mail de test), sans les marquer comme envoyés
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/reminders.php';
require_once __DIR__ . '/../includes/mailer.php';

$options = getopt('', ['dry-run', 'to:']);
$dryRun  = isset($options['dry-run']);
$testTo  = $options['to'] ?? null;

$now     = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$db      = getDatabaseConnection();
$courses = getCoursesNeedingReminder($db, $now);

echo $now->format('Y-m-d H:i') . ' : ' . count($courses) . " séance(s) à rappeler\n";

foreach ($courses as $course) {
    $start = (new DateTimeImmutable($course['date_debut']))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));
    $when  = formatLongDate($start) . ' à ' . $start->format('H:i');
    $to    = $testTo ?? $course['coach_email'];
    $line  = "- {$course['titre']} ({$when}), {$course['nb_demandes']} demande(s) -> {$to}";

    if ($dryRun) {
        echo $line . " [non envoyé : --dry-run]\n";
        continue;
    }

    $sent = sendCoachReminderMail($to, $course['coach_prenom'], $course['titre'], $when, $course['nb_demandes'], validationLink((int) $course['id_cours']));
    echo $line . ($sent ? ' [envoyé]' : ' [ÉCHEC de l\'envoi]') . "\n";

    // En mode test, le rappel n'est pas marqué : le vrai coach le recevra au prochain passage
    if ($sent && $testTo === null) {
        markReminderSent($db, (int) $course['id_cours']);
    }
}
