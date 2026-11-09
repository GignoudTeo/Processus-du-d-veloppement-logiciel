<?php
// Le coach valide ou refuse les demandes d'inscription à ses séances (US4) et peut tout refuser quand c'est complet (US5)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_courses.php';
require_once __DIR__ . '/includes/coach_validation.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/mailer.php';

$user = requireLogin();

$pageTitle  = 'Inscriptions à valider';
$activePage = 'validations';

$now      = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$db       = getDatabaseConnection();
$coachId  = getCoachIdByAccount($db, $user['id_compte']);
$courseId = filter_var($_GET['cours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$errors   = [];  // [id_reservation ou 'tous-ID' => message]

if ($coachId === null) {
    setFlash('Cette page est réservée aux coachs.');
    header('Location: planning.php');
    exit;
}

$when = function (string $date): string {
    $start = (new DateTimeImmutable($date))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));

    return formatLongDate($start) . ' à ' . $start->format('H:i');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action        = $_POST['action'] ?? '';
    $reservationId = filter_var($_POST['id_reservation'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $postCourseId  = filter_var($_POST['id_cours'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $anchor        = $postCourseId ? '#cours-' . $postCourseId : '';

    try {
        if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
            setFlash('La session a expiré, recharge la page et réessaie.');
        } elseif ($action === 'valider' && $reservationId) {
            $result   = validateBooking($db, $reservationId, $coachId, $now);
            $booking  = $result['booking'];
            $messages = [
                'not_found'   => 'Cette demande est introuvable.',
                'not_pending' => 'Cette demande a déjà été traitée.',
                'ended'       => 'Cette séance est terminée.',
                'full'        => 'La séance est complète : refuse cette demande ou libère une place avant de la valider.',
            ];
            if ($result['status'] === 'validated') {
                $sent = sendBookingDecisionMail($booking['email'], $booking['prenom'], $booking['titre'], $when($booking['date_debut']), true);
                setFlash('L\'inscription de ' . $booking['prenom'] . ' est validée.' . ($sent ? ' Un mail l\'a prévenu(e).' : ' Le mail n\'a pas pu être envoyé.'));
            } else {
                setFlash($messages[$result['status']]);
            }
        } elseif ($action === 'refuser' && $reservationId) {
            $reason = reasonFromPost($_POST, 'motif', REFUSAL_REASONS);
            if ($reason['error'] !== null) {
                $errors[$reservationId] = $reason['error'];
            } else {
                $result  = refuseBooking($db, $reservationId, $coachId, $reason['motif']);
                $booking = $result['booking'];
                if ($result['status'] === 'refused') {
                    $sent = sendBookingDecisionMail($booking['email'], $booking['prenom'], $booking['titre'], $when($booking['date_debut']), false, $reason['motif']);
                    setFlash('La demande de ' . $booking['prenom'] . ' est refusée.' . ($sent ? ' Un mail lui indique le motif.' : ' Le mail n\'a pas pu être envoyé.'));
                } else {
                    setFlash($result['status'] === 'not_pending' ? 'Cette demande a déjà été traitée.' : 'Cette demande est introuvable.');
                }
            }
        } elseif ($action === 'refuser_tout' && $postCourseId) {
            $reason = reasonFromPost($_POST, 'motif_tous', REFUSAL_REASONS);
            if ($reason['error'] !== null) {
                $errors['tous-' . $postCourseId] = $reason['error'];
            } else {
                $result = refuseAllPending($db, $postCourseId, $coachId, $reason['motif']);
                if ($result['status'] === 'refused') {
                    $failed = 0;
                    foreach ($result['refused'] as $booking) {
                        if (!sendBookingDecisionMail($booking['email'], $booking['prenom'], $booking['titre'], $when($booking['date_debut']), false, $reason['motif'])) {
                            $failed++;
                        }
                    }
                    $count = count($result['refused']);
                    setFlash($count === 0 ? 'Il n\'y avait plus de demande en attente.'
                        : $count . ' demande(s) refusée(s), chaque adhérent a été prévenu' . ($failed ? ' (sauf ' . $failed . ' mail(s) en échec).' : '.'));
                } else {
                    setFlash($result['status'] === 'not_full'
                        ? 'La séance n\'est pas encore complète : les demandes restantes ne peuvent pas être refusées en une fois.'
                        : 'Cette séance est introuvable.');
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Validations : ' . $e->getMessage());
        setFlash('Impossible d\'enregistrer ta décision pour le moment, réessaie plus tard.');
    }

    // Pas d'erreur de saisie : on revient sur la page (évite un double envoi si on actualise)
    if ($errors === []) {
        header('Location: validations.php' . ($courseId ? '?cours=' . $courseId : '') . $anchor);
        exit;
    }
}

$courses = getCoachBookingOverview($db, $coachId, $now);

$formatDate = fn (string $date) => (new DateTimeImmutable($date))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace coach</p>
        <h1>Inscriptions <span class="highlight">à valider</span></h1>
        <p class="lead">Valide ou refuse les demandes pour tes séances à venir. Une demande non traitée reste en attente : tu peux la traiter jusqu'à la fin de la séance.</p>

        <?php if ($courses === []): ?>
            <p class="sports-message" role="status">Tu n'as aucune séance à venir.</p>
        <?php endif; ?>

        <?php foreach ($courses as $course): ?>
            <?php $id = (int) $course['id_cours']; $start = $formatDate($course['date_debut']); ?>
            <article class="form-card validation-course<?= $id === $courseId ? ' is-highlighted' : '' ?>" id="cours-<?= $id ?>">
                <header class="request-head">
                    <div>
                        <h2><?= htmlspecialchars($course['titre']) ?></h2>
                        <p class="muted-text">
                            <?= formatLongDate($start) ?> à <?= $start->format('H:i') ?>
                            <?= $course['nom_salle'] ? ' · ' . htmlspecialchars($course['nom_salle']) : '' ?>
                        </p>
                    </div>
                    <span class="course-badge<?= $course['complet'] ? ' is-full' : ' is-accepted' ?>">
                        <?= $course['nb_valides'] ?> / <?= (int) $course['nb_places_max'] ?> validé(s)<?= $course['complet'] ? ' · complet' : '' ?>
                    </span>
                </header>

                <h3 class="validation-title">Demandes en attente (<?= count($course['demandes']) ?>)</h3>
                <?php if ($course['demandes'] === []): ?>
                    <p class="muted-text">Aucune demande en attente.</p>
                <?php endif; ?>

                <?php foreach ($course['demandes'] as $request): ?>
                    <?php $rid = (int) $request['id_reservation']; ?>
                    <div class="validation-request">
                        <div class="member-profile">
                            <p class="member-name"><?= htmlspecialchars($request['prenom'] . ' ' . $request['nom']) ?></p>
                            <ul class="member-facts">
                                <?php if ($request['age'] !== null): ?><li><?= $request['age'] ?> ans</li><?php endif; ?>
                                <?php if ($request['poids']): ?><li><?= rtrim(rtrim(number_format((float) $request['poids'], 1, ',', ''), '0'), ',') ?> kg</li><?php endif; ?>
                                <?php if ($request['taille']): ?><li><?= (int) $request['taille'] ?> cm</li><?php endif; ?>
                                <li>Membre depuis le <?= $formatDate($request['date_creation'])->format('d/m/Y') ?></li>
                            </ul>
                            <ul class="member-facts is-stats">
                                <li><strong><?= (int) $request['nb_reservations'] ?></strong> réservation(s)</li>
                                <li><strong><?= (int) $request['nb_reservations_rejetees'] ?></strong> refus</li>
                                <li><strong><?= (int) $request['nb_absences'] ?></strong> désinscription(s)</li>
                            </ul>
                            <p class="muted-text member-date">Demande du <?= $formatDate($request['date_reservation'])->format('d/m/Y à H:i') ?></p>
                        </div>

                        <div class="request-actions">
                            <form method="post" action="validations.php<?= $courseId ? '?cours=' . $courseId : '' ?>">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="valider">
                                <input type="hidden" name="id_reservation" value="<?= $rid ?>">
                                <input type="hidden" name="id_cours" value="<?= $id ?>">
                                <button type="submit" class="btn" <?= $course['complet'] ? 'disabled title="Séance complète"' : '' ?>>Valider</button>
                            </form>

                            <details class="request-refuse" <?= isset($errors[$rid]) ? 'open' : '' ?>>
                                <summary class="btn btn-danger">Refuser</summary>
                                <form method="post" action="validations.php<?= $courseId ? '?cours=' . $courseId : '' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="refuser">
                                    <input type="hidden" name="id_reservation" value="<?= $rid ?>">
                                    <input type="hidden" name="id_cours" value="<?= $id ?>">
                                    <?= reasonFields('motif', 'Motif du refus (envoyé par mail)', REFUSAL_REASONS, $errors[$rid] ?? null, null, '-' . $rid) ?>
                                    <button type="submit" class="btn btn-danger">Confirmer le refus</button>
                                </form>
                            </details>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($course['complet'] && $course['demandes'] !== []): ?>
                    <details class="request-refuse refuse-all" <?= isset($errors['tous-' . $id]) ? 'open' : '' ?>>
                        <summary class="btn btn-danger">Refuser toutes les demandes restantes</summary>
                        <form method="post" action="validations.php<?= $courseId ? '?cours=' . $courseId : '' ?>"
                              onsubmit="return confirm('Refuser les <?= count($course['demandes']) ?> demande(s) restante(s) ? Chaque adhérent recevra un mail avec le motif.');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="refuser_tout">
                            <input type="hidden" name="id_cours" value="<?= $id ?>">
                            <?= reasonFields('motif_tous', 'Motif (envoyé à chaque adhérent)', REFUSAL_REASONS, $errors['tous-' . $id] ?? null, 'Séance complète', '-' . $id) ?>
                            <button type="submit" class="btn btn-danger">Refuser les <?= count($course['demandes']) ?> demande(s)</button>
                        </form>
                    </details>
                <?php endif; ?>

                <div class="validation-lists">
                    <div>
                        <h3 class="validation-title">Validés (<?= $course['nb_valides'] ?>)</h3>
                        <?php if ($course['valides'] === []): ?>
                            <p class="muted-text">Personne pour le moment.</p>
                        <?php else: ?>
                            <ul class="validation-names">
                                <?php foreach ($course['valides'] as $member): ?>
                                    <li><?= htmlspecialchars($member['prenom'] . ' ' . $member['nom']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 class="validation-title">Liste d'attente (<?= count($course['liste_attente']) ?>)</h3>
                        <?php if ($course['liste_attente'] === []): ?>
                            <p class="muted-text">Personne en attente.</p>
                        <?php else: ?>
                            <ol class="validation-names">
                                <?php foreach ($course['liste_attente'] as $member): ?>
                                    <li><?= htmlspecialchars($member['prenom'] . ' ' . $member['nom']) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
