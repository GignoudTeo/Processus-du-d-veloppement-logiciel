<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/planning.php';
require_once __DIR__ . '/includes/sports.php';

requireLogin();

$pageTitle    = 'Planning';
$activePage   = 'planning';
$extraScripts = ['assets/js/planning.js?v=' . filemtime(__DIR__ . '/assets/js/planning.js')];

// Filtres choisis : ?sport=ID et/ou ?coach=ID
$filters = [];
foreach (['sport', 'coach'] as $key) {
    $id = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (is_int($id)) {
        $filters[$key] = $id;
    }
}
$sportId = $filters['sport'] ?? null;
$coachId = $filters['coach'] ?? null;

$now       = new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE));
$weekStart = parseWeekParam($_GET['semaine'] ?? null, $now);
$weekEnd   = $weekStart->modify('+7 days');

$sports        = [];
$coaches       = [];
$courses       = [];
$nextCourse    = null;
$databaseError = false;

try {
    $db      = getDatabaseConnection();
    $sports  = getSports($db);
    $coaches = getCoaches($db);
    $courses = array_map(
        fn (array $course) => prepareCourse($course, $now),
        getCourses($db, $filters + ['from' => $weekStart, 'to' => $weekEnd])
    );
    // Semaine vide : on propose de sauter au prochain cours
    if ($courses === []) {
        $nextCourse = getNextCourseDate($db, $weekEnd, $filters);
    }
} catch (PDOException $e) {
    error_log('Planning : ' . $e->getMessage());
    $databaseError = true;
}

$selectedSport = null;
foreach ($sports as $sport) {
    if ((int) $sport['id_sport'] === $sportId) {
        $selectedSport = $sport['nom_sport'];
    }
}
$selectedCoach = null;
foreach ($coaches as $coach) {
    if ((int) $coach['id_coach'] === $coachId) {
        $selectedCoach = $coach['prenom'] . ' ' . $coach['nom'];
    }
}

$coursesByDay           = array_map('layoutDay', groupCoursesByDay($courses));
[$firstHour, $lastHour] = getTimeRange($courses);

// Les colonnes de jour s'élargissent selon le nombre maximum de cours en même temps
$maxColumns = 1;
foreach ($coursesByDay as $dayCourses) {
    $maxColumns = max($maxColumns, ...array_column($dayCourses, 'nb_colonnes'));
}
$gridMinutes            = ($lastHour - $firstHour) * 60;
$today                  = $now->format('Y-m-d');
$activeDay              = getDefaultDay($weekStart, $now, $coursesByDay);

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDays[] = $weekStart->modify("+$i days");
}

// Position verticale dans la grille, en % de la hauteur
$percent = fn (DateTimeImmutable $time) => max(0, min(100,
    (((int) $time->format('G') - $firstHour) * 60 + (int) $time->format('i')) / $gridMinutes * 100
));

require __DIR__ . '/includes/header.php';
?>

<section class="sports-section">
    <div class="container">
        <div class="sports-heading">
            <div>
                <p class="tag">Cours collectifs</p>
                <h1>
                    <?php if ($selectedSport): ?>
                        Planning <span class="highlight"><?= htmlspecialchars($selectedSport) ?></span>
                    <?php else: ?>
                        Le <span class="highlight">planning</span>
                    <?php endif; ?>
                </h1>
                <?php if ($selectedCoach): ?>
                    <p class="tt-coach-filter">Avec <?= htmlspecialchars($selectedCoach) ?></p>
                <?php endif; ?>
            </div>

            <form class="sports-filter" method="get" action="planning.php">
                <input type="hidden" name="semaine" value="<?= $weekStart->format('Y-m-d') ?>">
                <label for="sport-filter">Sport</label>
                <select id="sport-filter" name="sport" onchange="this.form.submit()">
                    <option value="">Tous les sports</option>
                    <?php foreach ($sports as $sport): ?>
                        <option value="<?= (int) $sport['id_sport'] ?>" <?= (int) $sport['id_sport'] === $sportId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sport['nom_sport']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="coach-filter">Coach</label>
                <select id="coach-filter" name="coach" onchange="this.form.submit()">
                    <option value="">Tous les coachs</option>
                    <?php foreach ($coaches as $coach): ?>
                        <option value="<?= (int) $coach['id_coach'] ?>" <?= (int) $coach['id_coach'] === $coachId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($coach['prenom'] . ' ' . $coach['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline" type="submit">Filtrer</button>
            </form>
        </div>

        <nav class="tt-nav" aria-label="Changer de semaine">
            <a class="btn btn-outline btn-small" data-week="prev" href="<?= htmlspecialchars(planningUrl($weekStart->modify('-7 days'), $filters)) ?>">← Semaine précédente</a>
            <div class="tt-nav-center">
                <h2><?= formatWeekLabel($weekStart) ?></h2>
                <?php if ($weekStart != getWeekStart($now)): ?>
                    <a href="<?= htmlspecialchars(planningUrl(getWeekStart($now), $filters)) ?>">Revenir à cette semaine</a>
                <?php endif; ?>
            </div>
            <a class="btn btn-outline btn-small" data-week="next" href="<?= htmlspecialchars(planningUrl($weekEnd, $filters)) ?>">Semaine suivante →</a>
        </nav>

        <?php if ($sports !== []): ?>
            <div class="tt-legend" aria-label="Filtrer par sport">
                <a class="tt-chip<?= $sportId === null ? ' is-active' : '' ?>" href="<?= htmlspecialchars(planningUrl($weekStart, array_diff_key($filters, ['sport' => 0]))) ?>">Tous</a>
                <?php foreach ($sports as $sport): ?>
                    <a class="tt-chip<?= (int) $sport['id_sport'] === $sportId ? ' is-active' : '' ?>"
                       style="--sport-color: <?= sportColor((int) $sport['id_sport']) ?>"
                       href="<?= htmlspecialchars(planningUrl($weekStart, ['sport' => (int) $sport['id_sport']] + $filters)) ?>">
                        <?= htmlspecialchars($sport['nom_sport']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($databaseError): ?>
            <p class="sports-message" role="status">Le planning n'est pas disponible pour le moment.</p>
        <?php else: ?>
            <?php if ($courses === []): ?>
                <div class="tt-empty">
                    <p>Aucun cours cette semaine<?= $filters !== [] ? ' avec ces filtres' : '' ?>.</p>
                    <?php if ($nextCourse): ?>
                        <a class="btn" href="<?= htmlspecialchars(planningUrl(getWeekStart($nextCourse), $filters)) ?>">
                            Voir les prochains cours : <?= mb_strtolower(formatWeekLabel(getWeekStart($nextCourse))) ?> →
                        </a>
                    <?php else: ?>
                        <p class="muted-text">Aucun cours n'est prévu pour la suite.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>

            <!-- Onglets des jours, visibles seulement sur téléphone -->
            <div class="tt-tabs" role="tablist">
                <?php foreach ($weekDays as $day): ?>
                    <?php $key = $day->format('Y-m-d'); ?>
                    <button type="button" role="tab" data-day="<?= $key ?>"
                            class="tt-tab<?= $key === $activeDay ? ' is-active' : '' ?><?= $key === $today ? ' is-today' : '' ?>"
                            aria-selected="<?= $key === $activeDay ? 'true' : 'false' ?>">
                        <span><?= mb_substr(DAY_NAMES[(int) $day->format('w')], 0, 3) ?></span>
                        <strong><?= $day->format('j') ?></strong>
                        <?php if (!empty($coursesByDay[$key])): ?><i class="tt-tab-dot" aria-label="Cours prévus"></i><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="tt-wrapper">
                <div class="timetable" style="--tt-hours: <?= $lastHour - $firstHour ?>; --tt-columns: <?= $maxColumns ?>"
                     data-first-hour="<?= $firstHour ?>" data-last-hour="<?= $lastHour ?>">
                    <div class="tt-corner"></div>
                    <?php foreach ($weekDays as $day): ?>
                        <?php $key = $day->format('Y-m-d'); ?>
                        <div class="tt-day-head<?= $key === $today ? ' is-today' : '' ?><?= $key === $activeDay ? ' is-active' : '' ?>" data-day="<?= $key ?>">
                            <?= formatShortDayLabel($day) ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="tt-hours">
                        <?php for ($hour = $firstHour; $hour < $lastHour; $hour++): ?>
                            <span><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>:00</span>
                        <?php endfor; ?>
                    </div>

                    <?php foreach ($weekDays as $dayDate): ?>
                        <?php $day = $dayDate->format('Y-m-d'); ?>
                        <div class="tt-day<?= $day === $today ? ' is-today' : '' ?><?= $day === $activeDay ? ' is-active' : '' ?>" data-day="<?= $day ?>">
                            <?php if ($day === $today): ?>
                                <?php $nowVisible = (int) $now->format('G') >= $firstHour && (int) $now->format('G') < $lastHour; ?>
                                <div class="tt-now" style="top: <?= round($percent($now), 3) ?>%"<?= $nowVisible ? '' : ' hidden' ?> aria-label="Maintenant"></div>
                            <?php endif; ?>

                            <?php foreach ($coursesByDay[$day] ?? [] as $course): ?>
                                <?php
                                $top    = $percent($course['debut']);
                                // Un cours qui finit après minuit s'arrête en bas de la grille
                                $bottom = $course['fin']->format('Y-m-d') > $day ? 100 : $percent($course['fin']);
                                $coach  = $course['coach_prenom'] . ' ' . $course['coach_nom'];
                                $places = $course['nb_inscrits'] . ' / ' . (int) $course['nb_places_max'] . ' personnes';
                                $free   = placesStatus($course['nb_inscrits'], (int) $course['nb_places_max']);
                                $status = '';
                                if ($course['termine']) {
                                    $status = 'Terminé';
                                } elseif ($course['complet']) {
                                    $status = 'Complet';
                                }
                                ?>
                                <article class="tt-course<?= $course['termine'] ? ' is-past' : '' ?><?= $course['complet'] ? ' is-full' : '' ?><?= $course['minutes'] < 45 ? ' is-compact' : '' ?>"
                                         style="--sport-color: <?= sportColor((int) $course['id_sport']) ?>;
                                                top: <?= round($top, 3) ?>%; height: <?= round($bottom - $top, 3) ?>%;
                                                left: <?= round($course['colonne'] / $course['nb_colonnes'] * 100, 3) ?>%;
                                                width: <?= round(100 / $course['nb_colonnes'], 3) ?>%;">
                                    <p class="tt-course-time"><?= $course['debut']->format('H:i') ?> · <?= $course['duree'] ?></p>
                                    <h3><?= htmlspecialchars($course['titre']) ?></h3>
                                    <p class="tt-course-sport"><?= htmlspecialchars($course['nom_sport']) ?></p>
                                    <p><?= htmlspecialchars($coach) ?></p>
                                    <p class="tt-course-places">
                                        <?= $places ?>
                                        <?php if ($status): ?><span class="course-badge<?= $course['complet'] && !$course['termine'] ? ' is-full' : '' ?>"><?= $status ?></span><?php endif; ?>
                                    </p>
                                    <?php if (!$course['termine'] && !$course['complet']): ?>
                                        <p class="tt-course-free is-<?= $free['level'] ?>"><?= $free['label'] ?></p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
