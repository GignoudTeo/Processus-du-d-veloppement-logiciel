<?php
// Reçoit la croix de la liste des inscrits (fenêtre de détail du planning), puis revient sur le planning
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_courses.php';
require_once __DIR__ . '/includes/mailer.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planning.php');
    exit;
}

$now  = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$week = parseWeekParam($_POST['semaine'] ?? null, $now);

// Mêmes filtres qu'avant le retrait
$filters = [];
foreach (['sport', 'coach'] as $key) {
    $id = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (is_int($id)) {
        $filters[$key] = $id;
    }
}

$reservationId = filter_var($_POST['id_reservation'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
    setFlash('La session a expiré, recharge la page et réessaie.');
} elseif ($reservationId === false) {
    setFlash('Ce participant est introuvable.');
} else {
    try {
        $result = removeParticipant(getDatabaseConnection(), $reservationId, $user['id_compte'], $now);

        if ($result['status'] === 'removed') {
            $reservation = $result['reservation'];
            $start       = (new DateTimeImmutable($reservation['date_debut']))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));
            $mailSent    = sendParticipantRemovedMail(
                $reservation['email'],
                $reservation['prenom'],
                $reservation['titre'],
                formatLongDate($start) . ' à ' . $start->format('H:i')
            );
            setFlash($reservation['prenom'] . ' a été retiré(e) de la séance.'
                . ($mailSent ? ' Un mail l\'a prévenu(e).' : ' Le mail n\'a pas pu être envoyé : préviens cette personne toi-même.'));
        } else {
            $messages = [
                'not_found'       => 'Ce participant est introuvable, ou la séance appartient à un autre coach.',
                'started'         => 'Cette séance a déjà commencé : ses participants ne peuvent plus être retirés.',
                'already_removed' => 'Ce participant a déjà été retiré de la séance.',
            ];
            setFlash($messages[$result['status']]);
        }
    } catch (PDOException $e) {
        error_log('Retrait participant : ' . $e->getMessage());
        setFlash('Impossible de retirer ce participant pour le moment, réessaie plus tard.');
    }
}

header('Location: ' . planningUrl($week, $filters));
exit;
