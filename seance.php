<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/coach_courses.php';
require_once __DIR__ . '/includes/form.php';
require_once __DIR__ . '/includes/sports.php';

$user = requireLogin();

// ?id=ID : modification d'une séance. Sans id : création.
// Un coach gère ses propres séances, un administrateur gère toutes les séances
$courseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$courseId = is_int($courseId) ? $courseId : null;

$activePage  = 'planning';
$pageTitle   = $courseId ? 'Modifier la séance' : 'Nouvelle séance';
$formAction  = 'seance.php' . ($courseId ? '?id=' . $courseId : '');
$course      = null;
$coachId     = null;
$managesAllCourses = false;
$coaches     = [];  // liste de choix du coach, pour un administrateur qui crée une séance
$rooms       = [];
$sports      = [];
$data        = [];
$errors      = [];
$unavailable = false;

try {
    $db      = getDatabaseConnection();
    $coachId = getCoachIdByAccount($db, $user['id_compte']);
    $managesAllCourses = isAdminAccount($db, $user['id_compte']);
    if ($managesAllCourses) {
        $coaches = getAssignableCoaches($db);
        $course  = $courseId ? getCourse($db, $courseId) : null;
    } elseif ($coachId !== null) {
        $course = $courseId ? getCoachCourse($db, $courseId, $coachId) : null;
    }
    if ($managesAllCourses || $coachId !== null) {
        $rooms  = getRooms($db);
        $sports = getSports($db);
    }
} catch (PDOException $e) {
    error_log('Séance : ' . $e->getMessage());
    $unavailable = true;
}

if (!$unavailable && !$managesAllCourses && $coachId === null) {
    setFlash('Cette page est réservée aux coachs et aux administrateurs.');
    header('Location: planning.php');
    exit;
}
if (!$unavailable && $courseId && !$course) {
    setFlash('Cette séance est introuvable, ou elle appartient à un autre coach.');
    header('Location: planning.php');
    exit;
}

if ($course) {
    $data = courseToFormData($course);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$unavailable) {
    $action = $_POST['action'] ?? 'enregistrer';

    if (!isValidCsrf($_POST['csrf_token'] ?? null)) {
        $errors['global'] = 'La session a expiré, recharge la page et réessaie.';
    } elseif ($action === 'supprimer' && $course) {
        try {
            $week = getWeekStart(new DateTimeImmutable($course['date_debut']));
            setFlash(deleteCourse($db, $courseId, (int) $course['id_coach']) ? 'La séance a été supprimée.' : 'Cette séance a déjà été supprimée.');
            header('Location: ' . planningUrl($week));
            exit;
        } catch (PDOException $e) {
            error_log('Séance : ' . $e->getMessage());
            $errors['global'] = 'Impossible de supprimer la séance pour le moment, réessaie plus tard.';
        }
    } else {
        [$data, $errors] = validateCourse($_POST, $rooms, array_map('intval', array_column($sports, 'id_sport')));

        // Une séance garde son coach. À la création, un administrateur le choisit
        $ownerId = $course ? (int) $course['id_coach'] : $coachId;
        if ($managesAllCourses && !$course) {
            $data['id_coach'] = trim((string) ($_POST['id_coach'] ?? ''));
            $ownerId          = validateCourseCoach($data['id_coach'], $coaches);
            if ($ownerId === null) {
                $errors['id_coach'] = 'Choisis un coach.';
            }
        }

        if ($errors === []) {
            try {
                $errors = findCourseConflicts($db, $data, $ownerId, $courseId);

                if ($errors === []) {
                    if ($course) {
                        $saved = updateCourse($db, $courseId, $ownerId, $data);
                        setFlash($saved ? 'La séance a été modifiée.' : 'Cette séance a été supprimée entre-temps.');
                    } else {
                        createCourse($db, $ownerId, $data);
                        setFlash('La séance a été créée, elle apparaît dans le planning.');
                    }
                    header('Location: ' . planningUrl(getWeekStart($data['debut'])));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Séance : ' . $e->getMessage());
                $errors['global'] = 'Impossible d\'enregistrer la séance pour le moment, réessaie plus tard.';
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="form-section">
    <div class="container form-page">
        <p class="tag"><?= $managesAllCourses ? 'Espace administrateur' : 'Espace coach' ?></p>
        <h1><?= $course ? 'Modifier la' : 'Nouvelle' ?> <span class="highlight">séance</span></h1>

        <?php if ($unavailable): ?>
            <p class="lead">La gestion des séances n'est pas disponible pour le moment, réessaie plus tard.</p>
        <?php else: ?>
            <form class="form-card" method="post" action="<?= htmlspecialchars($formAction) ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="enregistrer">

                <?php if (isset($errors['global'])): ?>
                    <p class="form-alert" role="alert"><?= htmlspecialchars($errors['global']) ?></p>
                <?php endif; ?>

                <div class="form-grid">
                    <?php if ($managesAllCourses && $course): ?>
                        <p class="field-full muted-text">Séance de <?= htmlspecialchars($course['coach_prenom'] . ' ' . $course['coach_nom']) ?></p>
                    <?php elseif ($managesAllCourses): ?>
                        <div class="field field-full">
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

                    <div class="field field-full">
                        <label for="titre">Titre</label>
                        <input type="text" id="titre" name="titre" value="<?= old($data, 'titre') ?>" maxlength="<?= COURSE_TITLE_MAX ?>" required>
                        <?= fieldError($errors, 'titre') ?>
                    </div>

                    <div class="field field-full">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" value="<?= old($data, 'date') ?>" required>
                        <?= fieldError($errors, 'date') ?>
                    </div>

                    <div class="field">
                        <label for="heure_debut">Heure de début</label>
                        <input type="time" id="heure_debut" name="heure_debut" value="<?= old($data, 'heure_debut') ?>" required>
                        <?= fieldError($errors, 'heure_debut') ?>
                    </div>

                    <div class="field">
                        <label for="heure_fin">Heure de fin</label>
                        <input type="time" id="heure_fin" name="heure_fin" value="<?= old($data, 'heure_fin') ?>" required>
                        <?= fieldError($errors, 'heure_fin') ?>
                    </div>

                    <div class="field">
                        <label for="id_salle">Salle</label>
                        <select id="id_salle" name="id_salle" required>
                            <option value="">Choisir une salle</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?= (int) $room['id_salle'] ?>" <?= (string) $room['id_salle'] === ($data['id_salle'] ?? '') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($room['nom_salle']) ?> (<?= (int) $room['capacite'] ?> places)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= fieldError($errors, 'id_salle') ?>
                    </div>

                    <div class="field">
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
                        <label for="nb_places_max">Nombre de places</label>
                        <input type="number" id="nb_places_max" name="nb_places_max" value="<?= old($data, 'nb_places_max') ?>" min="1" required>
                        <?= fieldError($errors, 'nb_places_max') ?>
                    </div>

                    <div class="field field-full">
                        <label for="description">Description <span class="optional">(facultatif)</span></label>
                        <textarea id="description" name="description" rows="4" maxlength="<?= COURSE_DESCRIPTION_MAX ?>"><?= old($data, 'description') ?></textarea>
                        <?= fieldError($errors, 'description') ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-lg btn-block"><?= $course ? 'Enregistrer les modifications' : 'Créer la séance' ?></button>
            </form>

            <?php if ($course): ?>
                <?php $registered = (int) $course['nb_inscrits']; ?>
                <form class="form-card" method="post" action="<?= htmlspecialchars($formAction) ?>"
                      onsubmit="return confirm('Supprimer cette séance ?<?= $registered > 0 ? ' Les réservations de ses ' . $registered . ' inscrit(s) seront aussi supprimées.' : '' ?>');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="supprimer">
                    <h2>Supprimer la séance</h2>
                    <p class="muted-text">
                        <?= $registered > 0 ? 'Cette séance a ' . $registered . ' inscrit(s) : leurs réservations seront aussi supprimées.' : 'Personne n\'est inscrit à cette séance.' ?>
                    </p>
                    <button type="submit" class="btn btn-danger">Supprimer la séance</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
