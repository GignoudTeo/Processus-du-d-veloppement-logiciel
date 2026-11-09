<?php
// Rappel aux coachs qui ont encore des demandes en attente 2 jours avant leur séance (un seul rappel par séance) (US6).
//
// Les rappels partent tout seuls : le site fait la vérification au premier chargement d'une page de chaque heure
// (runRemindersIfDue, appelée par includes/footer.php). Ce script sert à la lancer à la main, ou par une tâche planifiée :
//   C:\xampp\php\php.exe cron\rappel-coachs.php
//
// Options :
//   --dry-run        affiche les rappels sans envoyer de mail ni les marquer comme envoyés
//   --to=adresse     envoie tous les rappels à cette adresse (boîte mail de test), sans les marquer comme envoyés
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/reminders.php';

$options = getopt('', ['dry-run', 'to:']);
$now     = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$lines   = sendDueReminders(getDatabaseConnection(), $now, $options['to'] ?? null, isset($options['dry-run']));

echo $now->format('Y-m-d H:i') . ' : ' . count($lines) . " séance(s) à rappeler\n";
foreach ($lines as $line) {
    echo '- ' . $line . "\n";
}
