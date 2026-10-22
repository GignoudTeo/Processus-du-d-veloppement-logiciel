<?php
require_once __DIR__ . '/config.php';

const PLANNING_TIMEZONE = 'Europe/Paris';

const DAY_NAMES   = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
const MONTH_NAMES = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                     'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// Cours triés par date. Filtres possibles :
// 'sport' et 'coach' (id), 'from' / 'to' (cours qui commencent dans [from, to[)
function getCourses(PDO $db, array $filters = []): array
{
    $sql = "SELECT c.id_cours, c.titre, c.date_debut, c.date_fin, c.nb_places_max,
                   s.id_sport, s.nom_sport,
                   co.id_coach, co.prenom AS coach_prenom, co.nom AS coach_nom,
                   (SELECT COUNT(*) FROM reservations r
                    WHERE r.id_cours = c.id_cours AND r.statut = 'confirmee') AS nb_inscrits
            FROM cours c
            INNER JOIN sports s ON s.id_sport = c.id_sport
            INNER JOIN coachs co ON co.id_coach = c.id_coach";

    [$where, $params] = buildCourseFilters($filters);
    $statement = $db->prepare($sql . $where . ' ORDER BY c.date_debut, c.titre');
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

// Début du prochain cours à partir de $after (heure de Paris), avec les mêmes filtres sport / coach, ou null
function getNextCourseDate(PDO $db, DateTimeInterface $after, array $filters = []): ?DateTimeImmutable
{
    [$where, $params] = buildCourseFilters(['from' => $after] + $filters);

    $statement = $db->prepare('SELECT MIN(c.date_debut) FROM cours c' . $where);
    $statement->execute($params);
    $date = $statement->fetchColumn();

    return $date ? (new DateTimeImmutable($date))->setTimezone(new DateTimeZone(PLANNING_TIMEZONE)) : null;
}

// Clause WHERE et paramètres pour les filtres de getCourses()
function buildCourseFilters(array $filters): array
{
    $conditions = [];
    $params     = [];

    if (isset($filters['sport'])) {
        $conditions[]    = 'c.id_sport = :sport';
        $params['sport'] = $filters['sport'];
    }
    if (isset($filters['coach'])) {
        $conditions[]    = 'c.id_coach = :coach';
        $params['coach'] = $filters['coach'];
    }
    // Dates converties en UTC, au même format que celles de la base
    if (isset($filters['from'])) {
        $conditions[]   = 'c.date_debut >= :from';
        $params['from'] = toUtcString($filters['from']);
    }
    if (isset($filters['to'])) {
        $conditions[] = 'c.date_debut < :to';
        $params['to'] = toUtcString($filters['to']);
    }

    return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
}

// Coachs qui ont au moins un cours, triés par prénom
function getCoaches(PDO $db): array
{
    return $db->query(
        'SELECT DISTINCT co.id_coach, co.prenom, co.nom
         FROM coachs co
         INNER JOIN cours c ON c.id_coach = co.id_coach
         ORDER BY co.prenom, co.nom'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// "3 places restantes" et son niveau : 'ok' (plus de 30 % de libre), 'low' (presque plein) ou 'full'
function placesStatus(int $registered, int $capacity): array
{
    $remaining = max(0, $capacity - $registered);

    if ($remaining === 0) {
        return ['label' => 'Complet', 'level' => 'full'];
    }

    return [
        'label' => $remaining . ($remaining === 1 ? ' place restante' : ' places restantes'),
        'level' => $remaining / max(1, $capacity) > 0.3 ? 'ok' : 'low',
    ];
}

// Couleur d'un sport, toujours la même pour un même sport
function sportColor(int $sportId): string
{
    $palette = ['#ffd100', '#38bdf8', '#f472b6', '#4ade80', '#fb923c', '#a78bfa', '#f87171', '#2dd4bf'];

    return $palette[($sportId - 1) % count($palette)];
}

// Jour affiché en premier sur téléphone : aujourd'hui s'il est dans la semaine, sinon le premier jour avec des cours, sinon lundi
function getDefaultDay(DateTimeImmutable $weekStart, DateTimeImmutable $now, array $coursesByDay): string
{
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = $weekStart->modify("+$i days")->format('Y-m-d');
    }

    if (in_array($now->format('Y-m-d'), $days, true)) {
        return $now->format('Y-m-d');
    }
    foreach ($days as $day) {
        if (!empty($coursesByDay[$day])) {
            return $day;
        }
    }

    return $days[0];
}

function toUtcString(DateTimeInterface $date): string
{
    return DateTimeImmutable::createFromInterface($date)
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s') . '+00';
}

// Lundi 00:00 (heure de Paris) de la semaine qui contient $date
function getWeekStart(DateTimeInterface $date): DateTimeImmutable
{
    $local = DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone(PLANNING_TIMEZONE));

    return $local->modify('monday this week')->setTime(0, 0);
}

// Paramètre ?semaine=AAAA-MM-JJ (n'importe quel jour de la semaine). Semaine en cours si absent ou invalide
function parseWeekParam(?string $value, DateTimeImmutable $now): DateTimeImmutable
{
    $date = is_string($value)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(PLANNING_TIMEZONE))
        : false;

    if (!$date || $date->format('Y-m-d') !== $value) {
        $date = $now;
    }

    return getWeekStart($date);
}

// Lien vers le planning d'une semaine, en gardant les filtres sport / coach choisis
function planningUrl(DateTimeImmutable $weekStart, array $filters = []): string
{
    $params = ['semaine' => $weekStart->format('Y-m-d')];
    foreach (['sport', 'coach'] as $key) {
        if (isset($filters[$key])) {
            $params[$key] = $filters[$key];
        }
    }

    return 'planning.php?' . http_build_query($params);
}

// "Semaine du 2 au 8 novembre 2026", "du 26 octobre au 1 novembre 2026", "du 28 décembre 2026 au 3 janvier 2027"
function formatWeekLabel(DateTimeImmutable $weekStart): string
{
    $weekEnd = $weekStart->modify('+6 days');
    $end     = $weekEnd->format('j') . ' ' . MONTH_NAMES[(int) $weekEnd->format('n') - 1] . ' ' . $weekEnd->format('Y');

    $start = $weekStart->format('j');
    if ($weekStart->format('n') !== $weekEnd->format('n')) {
        $start .= ' ' . MONTH_NAMES[(int) $weekStart->format('n') - 1];
    }
    if ($weekStart->format('Y') !== $weekEnd->format('Y')) {
        $start .= ' ' . $weekStart->format('Y');
    }

    return "Semaine du $start au $end";
}

// "Lun 2 nov."
function formatShortDayLabel(DateTimeInterface $date): string
{
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    return mb_substr(DAY_NAMES[(int) $date->format('w')], 0, 3) . ' ' . $date->format('j') . ' '
        . $months[(int) $date->format('n') - 1];
}

// 45 -> "45 min", 60 -> "1 h", 90 -> "1 h 30"
function formatDuration(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours = intdiv($minutes, 60);
    $rest  = $minutes % 60;

    return $hours . ' h' . ($rest > 0 ? ' ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
}

// Ajoute à une ligne de getCourses() : debut, fin (heure de Paris), minutes, duree, nb_inscrits (int), termine, complet
function prepareCourse(array $course, DateTimeImmutable $now): array
{
    $timezone = new DateTimeZone(PLANNING_TIMEZONE);
    $start    = (new DateTimeImmutable($course['date_debut']))->setTimezone($timezone);
    $end      = (new DateTimeImmutable($course['date_fin']))->setTimezone($timezone);

    $course['debut']       = $start;
    $course['fin']         = $end;
    $course['minutes']     = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
    $course['duree']       = formatDuration($course['minutes']);
    $course['nb_inscrits'] = (int) $course['nb_inscrits'];
    // Un cours commencé n'est plus réservable
    $course['termine']     = $start <= $now;
    $course['complet']     = $course['nb_inscrits'] >= (int) $course['nb_places_max'];

    return $course;
}

// Regroupe les cours préparés par jour : ['2026-11-02' => [cours, ...], ...]
function groupCoursesByDay(array $courses): array
{
    $days = [];
    foreach ($courses as $course) {
        $days[$course['debut']->format('Y-m-d')][] = $course;
    }

    return $days;
}

// Heures de début et de fin de la grille : [8, 20] par défaut, élargi pour contenir tous les cours
function getTimeRange(array $courses, int $defaultStart = 8, int $defaultEnd = 20): array
{
    $first = $defaultStart;
    $last  = $defaultEnd;

    foreach ($courses as $course) {
        $first = min($first, (int) $course['debut']->format('G'));

        // Fin arrondie à l'heure suivante ; un cours qui finit le lendemain étend la grille jusqu'à 24 h
        $endHour = (int) $course['fin']->format('G');
        if ($course['fin']->format('i') !== '00') {
            $endHour++;
        }
        if ($course['fin']->format('Y-m-d') > $course['debut']->format('Y-m-d')) {
            $endHour = 24;
        }
        $last = max($last, $endHour);
    }

    return [$first, $last];
}

// Cours d'une même journée qui se chevauchent : ajoute 'colonne' (0, 1...) et 'nb_colonnes' pour les afficher côte à côte
function layoutDay(array $courses): array
{
    usort($courses, fn ($a, $b) => $a['debut'] <=> $b['debut']);

    $result    = [];
    $group     = [];  // cours qui se chevauchent entre eux
    $columnEnd = [];  // heure de fin du dernier cours de chaque colonne
    $groupEnd  = null;

    $closeGroup = function () use (&$result, &$group, &$columnEnd) {
        foreach ($group as $course) {
            $course['nb_colonnes'] = count($columnEnd);
            $result[] = $course;
        }
        $group     = [];
        $columnEnd = [];
    };

    foreach ($courses as $course) {
        if ($groupEnd !== null && $course['debut'] >= $groupEnd) {
            $closeGroup();
            $groupEnd = null;
        }

        // Première colonne libre, sinon une nouvelle
        $column = 0;
        while (isset($columnEnd[$column]) && $columnEnd[$column] > $course['debut']) {
            $column++;
        }
        $columnEnd[$column] = $course['fin'];
        $course['colonne']  = $column;
        $group[]            = $course;
        $groupEnd           = $groupEnd === null ? $course['fin'] : max($groupEnd, $course['fin']);
    }
    $closeGroup();

    return $result;
}
