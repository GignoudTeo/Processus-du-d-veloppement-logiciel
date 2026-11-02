<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_requests.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/sports.php';

$user = requireLogin();

$pageTitle  = 'Devenir coach';
$activePage = 'compte';

$db          = getDatabaseConnection();
$data        = [];
$errors      = [];
$sports      = [];
$latest      = null;
$unavailable = false;

try {
    $sports = getSports($db);
    $latest = getLatestCoachRequest($db, $user['id_compte']);
} catch (PDOException $e) {
    error_log('Demande coach : ' . $e->getMessage());
    $unavailable = true;
}

// Rôle relu en base : il a pu changer depuis la connexion (demande acceptée entre-temps)
$account = getAccountNotificationDetails($db, $user['id_compte']);
if ($account) {
    $_SESSION['user']['role'] = $user['role'] = $account['role'];
}

$isMember = $user['role'] === 'adherent';
$pending  = $latest && $latest['statut'] === 'en_attente';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$unavailable && $isMember && !$pending) {
    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $errors['global'] = 'La session a expiré, recharge la page et réessaie.';
    } else {
        [$data, $errors] = validateCoachRequest($_POST, array_map('intval', array_column($sports, 'id_sport')));
    }

    if ($errors === []) {
        try {
            createCoachRequest($db, $user['id_compte'], $data);
            setFlash('Ta demande a bien été envoyée, l\'équipe va l\'étudier.');
            header('Location: devenir-coach.php');
            exit;
        } catch (PDOException $e) {
            // 23505 : une demande est déjà en attente (envoyée deux fois)
            if ($e->getCode() !== '23505') {
                error_log('Demande coach : ' . $e->getMessage());
                $errors['global'] = 'Impossible d\'envoyer la demande pour le moment, réessaie plus tard.';
            } else {
                header('Location: devenir-coach.php');
                exit;
            }
        }
    }
}

$checkedSports = array_map('intval', $data['sports'] ?? []);

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container form-page">
        <p class="tag">Rejoins l'équipe</p>
        <h1>Devenir <span class="highlight">coach</span></h1>

        <?php if ($unavailable): ?>
            <p class="lead">Les demandes pour devenir coach ne sont pas disponibles pour le moment, réessaie plus tard.</p>

        <?php elseif (!$isMember): ?>
            <p class="lead">
                <?= $user['role'] === 'coach' ? 'Tu es déjà coach chez FitPass.' : 'La demande pour devenir coach est réservée aux adhérents.' ?>
            </p>

        <?php elseif ($pending): ?>
            <div class="form-card request-status">
                <span class="course-badge">En attente</span>
                <h2>Ta demande est en cours d'examen</h2>
                <p class="muted-text">
                    Envoyée le <?= (new DateTimeImmutable($latest['date_demande']))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i') ?>.
                    Tu recevras un mail dès qu'un administrateur l'aura traitée.
                </p>
            </div>

        <?php else: ?>
            <?php if ($latest && $latest['statut'] === 'refusee'): ?>
                <div class="form-card request-status">
                    <span class="course-badge is-full">Refusée</span>
                    <h2>Ta dernière demande n'a pas été acceptée</h2>
                    <?php if ($latest['motif_refus']): ?>
                        <p class="request-reason"><?= nl2br(htmlspecialchars($latest['motif_refus'])) ?></p>
                    <?php endif; ?>
                    <p class="muted-text">Tu peux faire une nouvelle demande avec le questionnaire ci-dessous.</p>
                </div>
            <?php endif; ?>

            <p class="lead">Tu as envie d'animer des cours ? Réponds à ce questionnaire, un administrateur étudiera ta demande.</p>

            <form class="form-card" method="post" action="devenir-coach.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <?php if (isset($errors['global'])): ?>
                    <p class="form-alert" role="alert"><?= htmlspecialchars($errors['global']) ?></p>
                <?php endif; ?>

                <fieldset class="field field-group">
                    <legend>Quels sports veux-tu encadrer ?</legend>
                    <div class="choice-grid">
                        <?php foreach ($sports as $sport): ?>
                            <label class="choice">
                                <input type="checkbox" name="sports[]" value="<?= (int) $sport['id_sport'] ?>"
                                    <?= in_array((int) $sport['id_sport'], $checkedSports, true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($sport['nom_sport']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= fieldError($errors, 'sports') ?>
                </fieldset>

                <fieldset class="field field-group">
                    <legend>Ton expérience de coach ou d'encadrant</legend>
                    <div class="choice-grid">
                        <?php foreach (COACH_EXPERIENCE_LEVELS as $value => $label): ?>
                            <label class="choice">
                                <input type="radio" name="experience" value="<?= $value ?>"
                                    <?= ($data['experience'] ?? '') === $value ? 'checked' : '' ?>>
                                <?= $label ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= fieldError($errors, 'experience') ?>
                </fieldset>

                <div class="field">
                    <label for="diplome">Diplômes ou certifications <span class="optional">(facultatif)</span></label>
                    <input type="text" id="diplome" name="diplome" value="<?= old($data, 'diplome') ?>" maxlength="255"
                           placeholder="Ex. BPJEPS AF, certification Les Mills…">
                    <?= fieldError($errors, 'diplome') ?>
                </div>

                <div class="field">
                    <label for="types_seances">Quel type de séances aimerais-tu proposer ?</label>
                    <textarea id="types_seances" name="types_seances" rows="4" maxlength="<?= COACH_REQUEST_TEXT_MAX ?>"
                              placeholder="Ex. HIIT de 45 min pour débutants, renforcement musculaire avec haltères…"><?= old($data, 'types_seances') ?></textarea>
                    <?= fieldError($errors, 'types_seances') ?>
                </div>

                <div class="field">
                    <label for="disponibilites">Tes disponibilités</label>
                    <textarea id="disponibilites" name="disponibilites" rows="3" maxlength="<?= COACH_REQUEST_TEXT_MAX ?>"
                              placeholder="Ex. lundi et mercredi soir après 18 h, samedi matin…"><?= old($data, 'disponibilites') ?></textarea>
                    <?= fieldError($errors, 'disponibilites') ?>
                </div>

                <div class="field">
                    <label for="motivation">Pourquoi veux-tu devenir coach ?</label>
                    <textarea id="motivation" name="motivation" rows="4" maxlength="<?= COACH_REQUEST_TEXT_MAX ?>"><?= old($data, 'motivation') ?></textarea>
                    <?= fieldError($errors, 'motivation') ?>
                </div>

                <button type="submit" class="btn btn-lg btn-block">Envoyer ma demande</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
