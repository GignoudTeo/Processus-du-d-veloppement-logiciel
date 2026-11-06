<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_requests.php';
require_once __DIR__ . '/includes/mailer.php';

$user = requireAdmin();

$pageTitle  = 'Demandes coach';
$activePage = 'demandes';

$db        = getDatabaseConnection();
$showDone  = ($_GET['onglet'] ?? '') === 'traitees';
$pageError = null;

// ?demande=ID : lien reçu par mail. Ouvre l'onglet où se trouve la demande (elle a pu être traitée entre-temps)
$linkedRequestId = filter_var($_GET['demande'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$linkedRequestId = is_int($linkedRequestId) ? $linkedRequestId : null;
if ($linkedRequestId !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $linkedRequest = getCoachRequest($db, $linkedRequestId);
        if ($linkedRequest) {
            $showDone = $linkedRequest['statut'] !== 'en_attente';
        } else {
            $pageError = 'Cette demande est introuvable.';
        }
    } catch (PDOException $e) {
        error_log('Demandes coach : ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_var($_POST['id_demande'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action    = $_POST['action'] ?? '';

    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $pageError = 'La session a expiré, recharge la page et réessaie.';
    } elseif ($requestId === false || !in_array($action, ['accepter', 'refuser'], true)) {
        $pageError = 'Demande ou action invalide.';
    } else {
        try {
            $request   = getCoachRequest($db, $requestId);
            $applicant = $request ? getAccountNotificationDetails($db, (int) $request['id_compte']) : null;

            if ($action === 'accepter') {
                $result = acceptCoachRequest($db, $requestId, (int) $user['id_compte']);
                // Le mail "Félicitations, tu deviens coach" part comme pour un changement de rôle
                $mailSent = $result === 'accepted' && $applicant
                    && sendRoleChangeMail($applicant['email'], $applicant['prenom'], $applicant['role'], 'coach');
                $success = 'La demande a été acceptée : ' . ($applicant['prenom'] ?? 'le candidat') . ' est maintenant coach.';
            } else {
                $reason   = (string) ($_POST['motif'] ?? '');
                $result   = refuseCoachRequest($db, $requestId, (int) $user['id_compte'], $reason);
                $mailSent = $result === 'refused' && $applicant
                    && sendCoachRequestRefusedMail($applicant['email'], $applicant['prenom'], trim($reason));
                $success = 'La demande a été refusée.';
            }

            if (in_array($result, ['accepted', 'refused'], true)) {
                setFlash($success . ($mailSent ? ' Un mail a été envoyé au candidat.' : ' Le mail au candidat n\'a pas pu être envoyé.'));
                header('Location: demandes-coach.php');
                exit;
            }

            $messages = [
                'not_found'         => 'Cette demande est introuvable.',
                'already_processed' => 'Cette demande a déjà été traitée.',
                'reason_required'   => 'Indique la raison du refus : elle est envoyée par mail au candidat.',
                'profile_missing'   => 'Le profil du candidat est incomplet, son rôle ne peut pas être modifié.',
                'self_change_forbidden' => 'Tu ne peux pas accepter ta propre demande.',
                'last_admin'        => 'Le dernier administrateur ne peut pas devenir coach.',
            ];
            $pageError = $messages[$result] ?? 'La demande n\'a pas pu être traitée.';
        } catch (PDOException $e) {
            error_log('Demandes coach : ' . $e->getMessage());
            $pageError = 'Une erreur est survenue pendant le traitement de la demande.';
        }
    }
}

try {
    $pendingCount = countPendingCoachRequests($db);
    $requests     = getCoachRequests($db, $showDone ? null : 'en_attente');
} catch (PDOException $e) {
    error_log('Demandes coach : ' . $e->getMessage());
    $pendingCount = 0;
    $requests     = [];
    $pageError    = 'Les demandes ne sont pas disponibles pour le moment.';
}

$formatDate = fn (?string $date) => $date
    ? (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i')
    : '';

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container admin-page">
        <p class="tag">Espace sécurisé</p>
        <h1>Demandes <span class="highlight">coach</span></h1>
        <p class="lead">Les adhérents qui souhaitent devenir coach. Accepter une demande fait passer le compte en coach.</p>

        <?php if ($pageError): ?>
            <p class="form-alert" role="alert"><?= htmlspecialchars($pageError) ?></p>
        <?php endif; ?>

        <nav class="request-tabs" aria-label="Filtrer les demandes">
            <a href="demandes-coach.php" class="<?= $showDone ? '' : 'is-active' ?>">
                En attente <span class="request-count"><?= $pendingCount ?></span>
            </a>
            <a href="demandes-coach.php?onglet=traitees" class="<?= $showDone ? 'is-active' : '' ?>">Traitées</a>
        </nav>

        <?php if ($requests === []): ?>
            <p class="sports-message" role="status">
                <?= $showDone ? 'Aucune demande traitée pour le moment.' : 'Aucune demande en attente.' ?>
            </p>
        <?php endif; ?>

        <?php foreach ($requests as $request): ?>
            <?php $name = trim(($request['prenom'] ?? '') . ' ' . ($request['nom'] ?? '')) ?: $request['email']; ?>
            <article class="form-card request-card<?= (int) $request['id_demande'] === $linkedRequestId ? ' is-linked' : '' ?>"
                     id="demande-<?= (int) $request['id_demande'] ?>">
                <header class="request-head">
                    <div>
                        <h2><?= htmlspecialchars($name) ?></h2>
                        <p class="muted-text"><?= htmlspecialchars($request['email']) ?> · demande du <?= $formatDate($request['date_demande']) ?></p>
                    </div>
                    <?php if ($request['statut'] === 'acceptee'): ?>
                        <span class="course-badge is-accepted">Acceptée</span>
                    <?php elseif ($request['statut'] === 'refusee'): ?>
                        <span class="course-badge is-full">Refusée</span>
                    <?php else: ?>
                        <span class="course-badge">En attente</span>
                    <?php endif; ?>
                </header>

                <dl class="request-answers">
                    <dt>Sports</dt>
                    <dd>
                        <?php foreach ($request['sports'] as $sportName): ?>
                            <span class="tt-chip"><?= htmlspecialchars($sportName) ?></span>
                        <?php endforeach; ?>
                    </dd>

                    <dt>Expérience</dt>
                    <dd><?= COACH_EXPERIENCE_LEVELS[$request['experience']] ?? htmlspecialchars($request['experience']) ?></dd>

                    <dt>Diplômes</dt>
                    <dd><?= $request['diplome'] ? htmlspecialchars($request['diplome']) : '<span class="muted-text">Non renseigné</span>' ?></dd>

                    <dt>Séances proposées</dt>
                    <dd><?= nl2br(htmlspecialchars($request['types_seances'])) ?></dd>

                    <dt>Disponibilités</dt>
                    <dd><?= nl2br(htmlspecialchars($request['disponibilites'])) ?></dd>

                    <dt>Motivation</dt>
                    <dd><?= nl2br(htmlspecialchars($request['motivation'])) ?></dd>

                    <?php if ($request['statut'] !== 'en_attente'): ?>
                        <dt>Traitée le</dt>
                        <dd><?= $formatDate($request['date_traitement']) ?></dd>
                        <?php if ($request['motif_refus']): ?>
                            <dt>Raison du refus</dt>
                            <dd><?= nl2br(htmlspecialchars($request['motif_refus'])) ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>

                <?php if ($request['statut'] === 'en_attente'): ?>
                    <div class="request-actions">
                        <form method="post" action="demandes-coach.php"
                              onsubmit="return confirm('Accepter cette demande ? Le compte passera coach.');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="id_demande" value="<?= (int) $request['id_demande'] ?>">
                            <input type="hidden" name="action" value="accepter">
                            <button type="submit" class="btn">Accepter</button>
                        </form>

                        <details class="request-refuse">
                            <summary class="btn btn-danger">Refuser</summary>
                            <form method="post" action="demandes-coach.php">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="id_demande" value="<?= (int) $request['id_demande'] ?>">
                                <input type="hidden" name="action" value="refuser">
                                <div class="field">
                                    <label for="motif-<?= (int) $request['id_demande'] ?>">
                                        Raison du refus <span class="optional">(envoyée par mail au candidat)</span>
                                    </label>
                                    <textarea id="motif-<?= (int) $request['id_demande'] ?>" name="motif" rows="3" required
                                              maxlength="<?= COACH_REQUEST_TEXT_MAX ?>"
                                              placeholder="Ex. Nous recherchons plutôt des coachs disponibles en semaine pour le moment."></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger">Confirmer le refus</button>
                            </form>
                        </details>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($linkedRequestId !== null): ?>
    <script>
        // Après la connexion, le #demande-ID du lien est perdu : on fait défiler jusqu'à la demande
        document.querySelector('.request-card.is-linked')?.scrollIntoView({ block: 'start' });
    </script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
