<?php
// Reçoit les boutons de la fenêtre de détail du planning (et de l'historique) :
// s'inscrire, se désinscrire (avec motif) ou quitter la liste d'attente. Revient ensuite sur la page d'origine.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/reservations.php';
require_once __DIR__ . '/includes/reminders.php';

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: planning.php');
    exit;
}

$now      = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$week     = parseWeekParam($_POST['semaine'] ?? null, $now);
$filters  = parsePlanningFilters($_POST);
$courseId = filter_var($_POST['id_cours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action   = $_POST['action'] ?? '';
// Retour vers l'historique si la demande vient de là, sinon vers la semaine du planning
$returnTo = ($_POST['retour'] ?? '') === 'historique' ? 'historique.php' : planningUrl($week, $filters);

$formatWhen = function (string $date): string {
    $start = (new DateTimeImmutable($date))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));

    return formatLongDate($start) . ' à ' . $start->format('H:i');
};

if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
    setFlash('La session a expiré, recharge la page et réessaie.');
} elseif ($courseId === false || !in_array($action, ['inscrire', 'desinscrire', 'quitter_liste'], true)) {
    setFlash('Ce cours est introuvable.');
} else {
    try {
        $db         = getDatabaseConnection();
        $adherentId = getAdherentIdByAccount($db, $user['id_compte']);

        if ($adherentId === null) {
            setFlash('L\'inscription aux cours est réservée aux comptes avec un profil adhérent.');
        } elseif ($action === 'inscrire') {
            $result   = registerForCourse($db, $adherentId, $courseId, $now);
            $messages = [
                'requested'          => 'Ta demande d\'inscription est envoyée : le coach doit la valider. Tu recevras un mail avec sa décision.',
                'waitlisted'         => 'La séance est complète : tu es sur la liste d\'attente, en position ' . ($result['position'] ?? '?') . '. Tu seras prévenu(e) si une place se libère.',
                'not_found'          => 'Ce cours est introuvable.',
                'started'            => 'Ce cours a déjà commencé : l\'inscription n\'est plus possible.',
                'already_registered' => 'Tu es déjà inscrit(e) à ce cours.',
                'refused'            => 'Le coach a refusé ta demande pour ce cours : tu ne peux pas la renvoyer.',
            ];

            if ($result['status'] === 'overlap') {
                setFlash('Inscription impossible : ce cours chevauche « ' . $result['conflict']['titre'] . ' » ('
                    . $formatWhen($result['conflict']['date_debut']) . '), auquel tu es déjà inscrit(e).');
            } else {
                setFlash($messages[$result['status']]);
            }
        } elseif ($action === 'quitter_liste') {
            setFlash(leaveWaitingList($db, $adherentId, $courseId)
                ? 'Tu as quitté la liste d\'attente.'
                : 'Tu n\'es pas sur la liste d\'attente de ce cours.');
        } else {
            $reason = reasonFromPost($_POST, 'motif', UNREGISTER_REASONS);

            if ($reason['error'] !== null) {
                setFlash('Désinscription non enregistrée : ' . $reason['error']);
            } else {
                $result = unregisterFromCourse($db, $adherentId, $courseId, $now, $reason['motif']);

                if ($result['status'] === 'unregistered') {
                    $course = $result['course'];
                    $when   = $formatWhen($course['date_debut']);

                    // La personne en tête de la liste d'attente est prévenue qu'elle est proposée au coach
                    if ($result['promoted']) {
                        sendWaitingListPromotedMail($result['promoted']['email'], $result['promoted']['prenom'], $course['titre'], $when);
                    }

                    // Le coach est prévenu de la désinscription (et de la personne qui lui est proposée)
                    $memberName = $user['prenom'];
                    $promoted   = $result['promoted'] ? $result['promoted']['prenom'] . ' ' . $result['promoted']['nom'] : null;
                    sendCoachUnregisterMail($course['coach_email'], $course['coach_prenom'], $course['titre'], $when,
                        $memberName, $reason['motif'], $promoted, validationLink($courseId));

                    setFlash('Ta désinscription est enregistrée' . ($result['was_confirmed'] ? ', ta place est libérée.' : '.'));
                } else {
                    $messages = [
                        'not_registered'  => 'Tu n\'es pas inscrit(e) à ce cours.',
                        'started'         => 'Ce cours a déjà commencé : la désinscription n\'est plus possible.',
                        'reason_required' => 'Indique le motif de ta désinscription.',
                    ];
                    setFlash($messages[$result['status']]);
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Réservation : ' . $e->getMessage());
        setFlash('Impossible d\'enregistrer ta demande pour le moment, réessaie plus tard.');
    }
}

header('Location: ' . $returnTo);
exit;
