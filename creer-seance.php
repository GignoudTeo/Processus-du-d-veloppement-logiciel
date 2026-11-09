<?php
// Création d'une séance : la salle d'abord, puis un créneau libre (US1).
// ?type=classique : séance classique, par un coach (à son nom) ou un administrateur ; nombre de places choisi,
//                   au plus la capacité de la salle.
// ?type=privee    : séance privatisée, seulement par un administrateur ; places réduites (capacité de la salle ÷ 5).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_courses.php';
require_once __DIR__ . '/includes/course_slots.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/sports.php';

$user = requireLogin();

$timezone = new DateTimeZone(PLANNING_TIMEZONE);
$now      = new DateTimeImmutable('now', $timezone);
$source   = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$private  = ($source['type'] ?? '') === 'privee';
$day      = parseSlotDate($source['date'] ?? null) ?? $now->setTime(0, 0);
$roomId   = filter_var($source['salle'] ?? ($source['id_salle'] ?? null), FILTER_VALIDATE_INT) ?: null;

$db      = getDatabaseConnection();
$isAdmin = isAdminAccount($db, $user['id_compte']);
$coachId = getCoachIdByAccount($db, $user['id_compte']);

if ($private && !$isAdmin) {
    setFlash('Les séances privatisées sont réservées aux administrateurs.');
    header('Location: creer-seance.php?type=classique');
    exit;
}
if (!$isAdmin && $coachId === null) {
    setFlash('La création de séances est réservée aux coachs et aux administrateurs.');
    header('Location: planning.php');
    exit;
}

$type       = $private ? 'privee' : 'classique';
$pageTitle  = $private ? 'Créer une séance privatisée' : 'Créer une séance';
$activePage = $private ? 'creer-seance' : 'planning';

// Un administrateur choisit le coach. Un coach crée ses propres séances
$coaches = $isAdmin
    ? getAssignableCoaches($db)
    : array_values(array_filter(getAssignableCoaches($db), fn (array $coach) => (int) $coach['id_coach'] === $coachId));

$rooms  = getRoomsAvailability($db, $day, $now);
$sports = getSports($db);
$room   = null;
foreach ($rooms as $candidate) {
    if ((int) $candidate['id_salle'] === $roomId) {
        $room = $candidate;
    }
}

$data = [
    'creneau'       => '',
    'id_coach'      => $coachId !== null ? (string) $coachId : '',  // l'administrateur avec un profil coach : présélectionné
    'id_sport'      => '',
    'titre'         => '',
    'description'   => '',
    'nb_places_max' => $room ? (string) $room['capacite'] : '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    if (!$isAdmin) {
        $input['id_coach'] = (string) $coachId;
    }

    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $errors['global'] = 'La session a expiré, recharge la page et réessaie.';
    } else {
        [$data, $errors] = validateSessionForm($input, parseSlotDate($_POST['date'] ?? null), $rooms, $coaches,
            array_map('intval', array_column($sports, 'id_sport')), $private);
    }

    if ($errors === []) {
        try {
            // Le coach ne peut pas animer deux séances en même temps (la salle est revérifiée par createSession)
            $errors = array_intersect_key(findCourseConflicts($db, $data, (int) $data['id_coach']), ['heure_debut' => true]);
            if ($errors !== []) {
                $errors = ['creneau' => 'Ce coach a déjà une séance sur ce créneau, choisis-en un autre.'];
            } elseif (createSession($db, $data) === null) {
                $errors['creneau'] = 'Ce créneau vient d\'être pris, choisis-en un autre.';
                $rooms = getRoomsAvailability($db, $day, $now);
            } else {
                setFlash('La séance ' . ($private ? 'privatisée ' : '') . '« ' . $data['titre'] . ' » est créée ('
                    . $data['nb_places_max'] . ' places) : elle apparaît dans le planning.');
                header('Location: ' . planningUrl(getWeekStart($data['debut'])));
                exit;
            }
        } catch (PDOException $e) {
            error_log('Création de séance : ' . $e->getMessage());
            $errors['global'] = 'Impossible de créer la séance pour le moment, réessaie plus tard.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container form-page">
        <p class="tag"><?= $isAdmin ? 'Espace administrateur' : 'Espace coach' ?></p>
        <?php if ($private): ?>
            <h1>Créer une séance <span class="highlight">privatisée</span></h1>
            <p class="lead">Séance à places réduites (capacité de la salle ÷ 5), réservée aux administrateurs.
                Pour une séance classique : <a href="creer-seance.php?type=classique">Créer une séance</a>.</p>
        <?php else: ?>
            <h1>Créer une <span class="highlight">séance</span></h1>
            <p class="lead">Choisis la salle, puis un créneau libre.
                <?php if ($isAdmin): ?>Pour une séance à places réduites : <a href="creer-seance.php?type=privee">Créer une séance privatisée</a>.<?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (isset($errors['global'])): ?>
            <p class="form-alert" role="alert"><?= htmlspecialchars($errors['global']) ?></p>
        <?php endif; ?>

        <!-- Étape 1 : la date et la salle -->
        <form class="form-card" method="get" action="creer-seance.php">
            <input type="hidden" name="type" value="<?= $type ?>">
            <h2>1. La date et la salle</h2>
            <div class="field">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?= $day->format('Y-m-d') ?>" min="<?= $now->format('Y-m-d') ?>"
                       onchange="this.form.submit()" required>
            </div>

            <div class="room-grid">
                <?php foreach ($rooms as $candidate): ?>
                    <?php $selected = $room && (int) $room['id_salle'] === (int) $candidate['id_salle']; ?>
                    <button type="submit" name="salle" value="<?= (int) $candidate['id_salle'] ?>"
                            class="room-card<?= $selected ? ' is-selected' : '' ?>" <?= $candidate['complete'] ? 'disabled' : '' ?>>
                        <strong><?= htmlspecialchars($candidate['nom_salle']) ?></strong>
                        <span><?= $candidate['complete'] ? 'Aucun créneau libre' : $candidate['creneaux_libres'] . ' créneau(x) libre(s)' ?></span>
                        <small>
                            Salle de <?= (int) $candidate['capacite'] ?>
                            <?= $private ? ' · séance de ' . $candidate['capacite_seance'] . ' place(s)' : '' ?>
                        </small>
                    </button>
                <?php endforeach; ?>
            </div>
            <noscript><button type="submit" class="btn btn-outline">Afficher les salles</button></noscript>
        </form>

        <?php if ($room): ?>
            <!-- Étape 2 : le créneau et la séance -->
            <form class="form-card" method="post" action="creer-seance.php" novalidate>
                <h2>2. Le créneau et la séance</h2>
                <p class="muted-text"><?= htmlspecialchars($room['nom_salle']) ?>, <?= formatLongDate($day) ?></p>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="type" value="<?= $type ?>">
                <input type="hidden" name="date" value="<?= $day->format('Y-m-d') ?>">
                <input type="hidden" name="id_salle" value="<?= (int) $room['id_salle'] ?>">

                <fieldset class="field field-group">
                    <legend>Créneau (1 h)</legend>
                    <div class="slot-grid">
                        <?php foreach ($room['creneaux'] as $slot): ?>
                            <?php $value = $slot['debut']->format('H:i'); ?>
                            <label class="slot<?= $slot['libre'] ? '' : ' is-taken' ?>"
                                   title="<?= $slot['libre'] ? 'Libre' : ($slot['raison'] === 'passe' ? 'Créneau passé' : 'Salle déjà réservée') ?>">
                                <input type="radio" name="creneau" value="<?= $value ?>" <?= $slot['libre'] ? '' : 'disabled' ?>
                                    <?= ($data['creneau'] ?? '') === $value ? 'checked' : '' ?> required>
                                <?= $value ?> – <?= $slot['fin']->format('H:i') ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= fieldError($errors, 'creneau') ?>
                </fieldset>

                <div class="form-grid">
                    <?php if ($isAdmin): ?>
                        <div class="field">
                            <label for="id_coach">Coach</label>
                            <select id="id_coach" name="id_coach" required>
                                <option value="">Choisir un coach</option>
                                <?php foreach ($coaches as $coach): ?>
                                    <option value="<?= (int) $coach['id_coach'] ?>" <?= (string) $coach['id_coach'] === ($data['id_coach'] ?? '') ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($coach['prenom'] . ' ' . $coach['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= fieldError($errors, 'id_coach') ?>
                        </div>
                    <?php endif; ?>

                    <div class="field<?= $isAdmin ? '' : ' field-full' ?>">
                        <label for="id_sport">Sport</label>
                        <select id="id_sport" name="id_sport" required>
                            <option value="">Choisir un sport</option>
                            <?php foreach ($sports as $sport): ?>
                                <option value="<?= (int) $sport['id_sport'] ?>" <?= (string) $sport['id_sport'] === ($data['id_sport'] ?? '') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sport['nom_sport']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= fieldError($errors, 'id_sport') ?>
                    </div>

                    <div class="field field-full">
                        <label for="titre">Titre</label>
                        <input type="text" id="titre" name="titre" value="<?= old($data, 'titre') ?>" maxlength="150" required>
                        <?= fieldError($errors, 'titre') ?>
                    </div>

                    <?php if ($private): ?>
                        <div class="field field-full">
                            <label for="capacite">Capacité de la séance</label>
                            <input type="text" id="capacite" value="<?= $room['capacite_seance'] ?> place(s)" readonly>
                            <p class="muted-text field-hint">Calculée automatiquement : capacité de la salle (<?= (int) $room['capacite'] ?>) ÷ 5, au moins 1.</p>
                        </div>
                    <?php else: ?>
                        <div class="field field-full">
                            <label for="nb_places_max">Nombre de places</label>
                            <input type="number" id="nb_places_max" name="nb_places_max" value="<?= old($data, 'nb_places_max') ?>"
                                   min="1" max="<?= (int) $room['capacite'] ?>" required>
                            <p class="muted-text field-hint">Au plus la capacité de la salle (<?= (int) $room['capacite'] ?>).</p>
                            <?= fieldError($errors, 'nb_places_max') ?>
                        </div>
                    <?php endif; ?>

                    <div class="field field-full">
                        <label for="description">Description <span class="optional">(facultatif)</span></label>
                        <textarea id="description" name="description" rows="3" maxlength="2000"><?= old($data, 'description') ?></textarea>
                        <?= fieldError($errors, 'description') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-lg btn-block"><?= $private ? 'Créer la séance privatisée' : 'Créer la séance' ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
