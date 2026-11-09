<?php
// Reçoit la croix de la liste des inscrits (fenêtre de détail du planning), puis revient sur le planning
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_courses.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/reservations.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planning.php');
    exit;
}

$now     = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$week    = parseWeekParam($_POST['semaine'] ?? null, $now);
$filters = parsePlanningFilters($_POST);

$reservationId = filter_var($_POST['id_reservation'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
    setFlash('La session a expiré, recharge la page et réessaie.');
} elseif ($reservationId === false) {
    setFlash('Ce participant est introuvable.');
} else {
    try {
        $db     = getDatabaseConnection();
        $result = removeParticipant($db, $reservationId, $user['id_compte'], $now);

        if ($result['status'] === 'removed') {
            $reservation = $result['reservation'];
            $start       = (new DateTimeImmutable($reservation['date_debut']))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));
            $when        = formatLongDate($start) . ' à ' . $start->format('H:i');
            $mailSent    = sendParticipantRemovedMail($reservation['email'], $reservation['prenom'], $reservation['titre'], $when);

            // La place libérée est proposée à la première personne de la liste d'attente (US3)
            $courseId = (int) $db->query('SELECT id_cours FROM reservations WHERE id_reservation = ' . (int) $reservationId)->fetchColumn();
            $promoted = $courseId ? promoteFromWaitingList($db, $courseId) : null;
            if ($promoted) {
                sendWaitingListPromotedMail($promoted['email'], $promoted['prenom'], $reservation['titre'], $when);
            }
            setFlash($reservation['prenom'] . ' a été retiré(e) de la séance.'
                . ($mailSent ? ' Un mail l\'a prévenu(e).' : ' Le mail n\'a pas pu être envoyé : préviens cette personne toi-même.')
                . ($promoted ? ' ' . $promoted['prenom'] . ', premier(ère) sur la liste d\'attente, t\'est proposé(e).' : ''));
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
