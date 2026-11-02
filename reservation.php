<?php
// Reçoit les boutons « S'inscrire » et « Se désinscrire » de la fenêtre de détail du planning, puis revient sur le planning
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reservations.php';

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

if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
    setFlash('La session a expiré, recharge la page et réessaie.');
} elseif ($courseId === false || !in_array($action, ['inscrire', 'desinscrire'], true)) {
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
                'registered'         => 'Ton inscription est confirmée, ta place est réservée.',
                'not_found'          => 'Ce cours est introuvable.',
                'started'            => 'Ce cours a déjà commencé : l\'inscription n\'est plus possible.',
                'already_registered' => 'Tu es déjà inscrit(e) à ce cours.',
                'full'               => 'Ce cours est complet : l\'inscription n\'est plus possible.',
            ];

            if ($result['status'] === 'overlap') {
                $start = (new DateTimeImmutable($result['conflict']['date_debut']))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));
                setFlash('Inscription impossible : ce cours chevauche « ' . $result['conflict']['titre'] . ' » ('
                    . formatLongDate($start) . ' à ' . $start->format('H:i') . '), auquel tu es déjà inscrit(e).');
            } else {
                setFlash($messages[$result['status']]);
            }
        } else {
            $messages = [
                'unregistered'   => 'Ta désinscription est enregistrée, ta place est libérée.',
                'not_registered' => 'Tu n\'es pas inscrit(e) à ce cours.',
                'started'        => 'Ce cours a déjà commencé : la désinscription n\'est plus possible.',
            ];
            setFlash($messages[unregisterFromCourse($db, $adherentId, $courseId, $now)]);
        }
    } catch (PDOException $e) {
        error_log('Réservation : ' . $e->getMessage());
        setFlash('Impossible d\'enregistrer ta demande pour le moment, réessaie plus tard.');
    }
}

header('Location: ' . planningUrl($week, $filters));
exit;
