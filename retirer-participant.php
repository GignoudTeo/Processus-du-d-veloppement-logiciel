<?php
// Reçoit la croix de la liste des inscrits (fenêtre de détail du planning), puis revient sur le planning.
// Comme pour un refus, le coach doit indiquer un motif, envoyé par mail au participant
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_validation.php';
require_once __DIR__ . '/includes/form.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planning.php');
    exit;
}

$now     = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$week    = parseWeekParam($_POST['semaine'] ?? null, $now);
$filters = parsePlanningFilters($_POST);

$reservationId = filter_var($_POST['id_reservation'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reason        = reasonFromPost($_POST, 'motif_retrait', REMOVAL_REASONS);

if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
    setFlash('La session a expiré, recharge la page et réessaie.');
} elseif ($reservationId === false) {
    setFlash('Ce participant est introuvable.');
} elseif ($reason['error'] !== null) {
    setFlash('Participant non retiré : ' . lcfirst($reason['error']));
} else {
    try {
        $db     = getDatabaseConnection();
        $result = removeParticipant($db, $reservationId, $user['id_compte'], $now, $reason['motif']);

        setFlash($result['status'] === 'removed'
            ? notifyParticipantRemoval($db, $result['reservation'])
            : REMOVAL_ERRORS[$result['status']]);
    } catch (PDOException $e) {
        error_log('Retrait participant : ' . $e->getMessage());
        setFlash('Impossible de retirer ce participant pour le moment, réessaie plus tard.');
    }
}

header('Location: ' . planningUrl($week, $filters));
exit;
