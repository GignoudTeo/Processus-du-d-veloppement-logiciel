<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanningTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();

        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'HIIT'), (2, 1, 'Cycling')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES
            (101, 'marc@example.com', 'x', 'coach'), (102, 'lea@example.com', 'x', 'coach')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (1, 101, 'Martin', 'Marc'), (2, 102, 'Durand', 'Léa')");
        $this->db->exec("INSERT INTO salles (id_salle, nom_salle, capacite) VALUES (1, 'Studio 1', 25)");

        // Insérés dans le désordre pour vérifier le tri
        $this->insertCourse(1, 'Cycling matin', 2, 2, '2026-11-03 07:00:00+00', '2026-11-03 07:45:00+00', 15);
        $this->insertCourse(2, 'HIIT du soir', 1, 1, '2026-11-02 18:00:00+00', '2026-11-02 19:00:00+00', 3);
        $this->insertCourse(3, 'HIIT express', 1, 2, '2026-11-02 12:00:00+00', '2026-11-02 12:30:00+00', 10);
    }

    private function insertCourse(int $id, string $title, int $sport, int $coach, string $start, string $end, int $max): void
    {
        $this->db->prepare(
            'INSERT INTO cours (id_cours, titre, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([$id, $title, $sport, $coach, $start, $end, $max]);
    }

    private function book(int $courseId, string $status = 'confirmee'): void
    {
        [$accountId] = createTestAdherent($this->db, ['email' => uniqid() . '@example.com']);
        $adherentId  = $this->db->query("SELECT id_adherent FROM adherents WHERE id_compte = $accountId")->fetchColumn();

        $this->db->prepare('INSERT INTO reservations (id_cours, id_adherent, statut) VALUES (?, ?, ?)')
            ->execute([$courseId, $adherentId, $status]);
    }

    private function courseRow(array $overrides = []): array
    {
        return array_merge([
            'titre'         => 'HIIT du soir',
            'date_debut'    => '2026-11-02 18:00:00+00',
            'date_fin'      => '2026-11-02 19:00:00+00',
            'nb_places_max' => 20,
            'nb_inscrits'   => '5',
        ], $overrides);
    }

    // ---------- getCourses ----------

    public function testTousLesCoursTriesParDate(): void
    {
        $titles = array_column(getCourses($this->db), 'titre');

        $this->assertSame(['HIIT express', 'HIIT du soir', 'Cycling matin'], $titles);
    }

    public function testCoursContientSportEtCoach(): void
    {
        $course = getCourses($this->db, ['sport' => 2])[0];

        $this->assertSame('Cycling matin', $course['titre']);
        $this->assertSame('Cycling', $course['nom_sport']);
        $this->assertSame('Léa', $course['coach_prenom']);
        $this->assertSame('Durand', $course['coach_nom']);
        $this->assertSame(15, (int) $course['nb_places_max']);
    }

    public function testFiltreParSport(): void
    {
        $titles = array_column(getCourses($this->db, ['sport' => 1]), 'titre');

        $this->assertSame(['HIIT express', 'HIIT du soir'], $titles);
    }

    public function testSportSansCours(): void
    {
        $this->assertSame([], getCourses($this->db, ['sport' => 999]));
    }

    public function testCompteSeulementLesReservationsConfirmees(): void
    {
        $this->book(2);
        $this->book(2);
        $this->book(2, 'annulee');
        $this->book(2, 'liste_attente');

        $courses = array_column(getCourses($this->db), null, 'id_cours');

        $this->assertSame(2, (int) $courses[2]['nb_inscrits']);
        $this->assertSame(0, (int) $courses[1]['nb_inscrits']);
    }

    // ---------- formatDuration ----------

    public static function durees(): array
    {
        return [
            [0, '0 min'],
            [45, '45 min'],
            [60, '1 h'],
            [65, '1 h 05'],
            [90, '1 h 30'],
            [120, '2 h'],
        ];
    }

    #[DataProvider('durees')]
    public function testFormatDeLaDuree(int $minutes, string $expected): void
    {
        $this->assertSame($expected, formatDuration($minutes));
    }

    // ---------- prepareCourse ----------

    public function testDureeCalculee(): void
    {
        $course = prepareCourse($this->courseRow(['date_fin' => '2026-11-02 18:45:00+00']), new DateTimeImmutable('2026-01-01'));

        $this->assertSame('45 min', $course['duree']);
    }

    public function testHeureAfficheeEnHeureDeParis(): void
    {
        // 18:00 UTC = 19:00 à Paris en novembre (heure d'hiver)
        $course = prepareCourse($this->courseRow(), new DateTimeImmutable('2026-01-01'));

        $this->assertSame('19:00', $course['debut']->format('H:i'));
        $this->assertSame('20:00', $course['fin']->format('H:i'));
    }

    public function testCoursAVenirPasTermine(): void
    {
        $course = prepareCourse($this->courseRow(), new DateTimeImmutable('2026-11-02 17:59:00+00'));

        $this->assertFalse($course['termine']);
    }

    public function testCoursCommenceEstTermine(): void
    {
        // Commencé mais pas fini : plus réservable
        $course = prepareCourse($this->courseRow(), new DateTimeImmutable('2026-11-02 18:30:00+00'));

        $this->assertTrue($course['termine']);
    }

    public function testCoursPasseEstTermine(): void
    {
        $course = prepareCourse($this->courseRow(), new DateTimeImmutable('2026-12-01'));

        $this->assertTrue($course['termine']);
    }

    public function testCoursComplet(): void
    {
        $now = new DateTimeImmutable('2026-01-01');

        $this->assertTrue(prepareCourse($this->courseRow(['nb_inscrits' => '20']), $now)['complet']);
        $this->assertFalse(prepareCourse($this->courseRow(['nb_inscrits' => '19']), $now)['complet']);
    }

    public function testNombreDInscritsConvertiEnEntier(): void
    {
        $course = prepareCourse($this->courseRow(['nb_inscrits' => '5']), new DateTimeImmutable('2026-01-01'));

        $this->assertSame(5, $course['nb_inscrits']);
    }

    // ---------- groupCoursesByDay ----------

    public function testRegroupementParJour(): void
    {
        $now     = new DateTimeImmutable('2026-01-01');
        $courses = array_map(fn ($c) => prepareCourse($c, $now), getCourses($this->db));

        $days = groupCoursesByDay($courses);

        $this->assertSame(['2026-11-02', '2026-11-03'], array_keys($days));
        $this->assertSame(['HIIT express', 'HIIT du soir'], array_column($days['2026-11-02'], 'titre'));
    }

    public function testCoursTardifRangeAuBonJourEnHeureDeParis(): void
    {
        // 23:30 UTC le 2 novembre = 00:30 le 3 novembre à Paris
        $course = prepareCourse(
            $this->courseRow(['date_debut' => '2026-11-02 23:30:00+00', 'date_fin' => '2026-11-03 00:30:00+00']),
            new DateTimeImmutable('2026-01-01')
        );

        $this->assertSame(['2026-11-03'], array_keys(groupCoursesByDay([$course])));
    }

    public function testAucunCours(): void
    {
        $this->assertSame([], groupCoursesByDay([]));
    }

    // ---------- getCourses : filtre par période ----------

    public function testCoursDUneSemaine(): void
    {
        $from = new DateTimeImmutable('2026-11-02 00:00', new DateTimeZone('Europe/Paris'));

        $titles = array_column(getCourses($this->db, ['from' => $from, 'to' => $from->modify('+1 day')]), 'titre');

        $this->assertSame(['HIIT express', 'HIIT du soir'], $titles);
    }

    public function testPeriodeEtSportCombines(): void
    {
        $from = new DateTimeImmutable('2026-11-02 00:00', new DateTimeZone('Europe/Paris'));

        $titles = array_column(getCourses($this->db, ['sport' => 2, 'from' => $from, 'to' => $from->modify('+7 days')]), 'titre');

        $this->assertSame(['Cycling matin'], $titles);
    }

    public function testFinDePeriodeExclue(): void
    {
        // Le cours de 12:00 UTC n'est pas compris si la période s'arrête à 12:00 UTC pile
        $titles = array_column(getCourses($this->db, [
            'from' => new DateTimeImmutable('2026-11-02 00:00:00+00'),
            'to'   => new DateTimeImmutable('2026-11-02 12:00:00+00'),
        ]), 'titre');

        $this->assertSame([], $titles);
    }

    // ---------- toUtcString ----------

    public function testConversionEnUtc(): void
    {
        $this->assertSame(
            '2026-11-02 23:00:00+00',
            toUtcString(new DateTimeImmutable('2026-11-03 00:00', new DateTimeZone('Europe/Paris')))
        );
    }

    // ---------- getWeekStart ----------

    public static function debutsDeSemaine(): array
    {
        return [
            'mercredi'           => ['2026-11-04 15:00', '2026-11-02'],
            'lundi'              => ['2026-11-02 00:00', '2026-11-02'],
            'dimanche soir'      => ['2026-11-08 23:59', '2026-11-02'],
            'semaine sur 2 mois' => ['2026-11-01 10:00', '2026-10-26'],
        ];
    }

    #[DataProvider('debutsDeSemaine')]
    public function testDebutDeSemaineEstLeLundiAMinuit(string $date, string $monday): void
    {
        $start = getWeekStart(new DateTimeImmutable($date, new DateTimeZone('Europe/Paris')));

        $this->assertSame($monday . ' 00:00', $start->format('Y-m-d H:i'));
        $this->assertSame('Europe/Paris', $start->getTimezone()->getName());
    }

    public function testDebutDeSemaineCalculeEnHeureDeParis(): void
    {
        // Dimanche 23:30 UTC = lundi 00:30 à Paris : c'est déjà la semaine suivante
        $start = getWeekStart(new DateTimeImmutable('2026-11-08 23:30:00+00'));

        $this->assertSame('2026-11-09', $start->format('Y-m-d'));
    }

    // ---------- parseWeekParam ----------

    public function testParametreSemaineValide(): void
    {
        $now = new DateTimeImmutable('2026-01-01');

        $this->assertSame('2026-11-02', parseWeekParam('2026-11-04', $now)->format('Y-m-d'));
    }

    public function testParametreSemaineAbsentOuInvalide(): void
    {
        $now = new DateTimeImmutable('2026-11-04 12:00', new DateTimeZone('Europe/Paris'));

        foreach ([null, '', 'abc', '2026-02-30', '04/11/2026'] as $value) {
            $this->assertSame('2026-11-02', parseWeekParam($value, $now)->format('Y-m-d'), var_export($value, true));
        }
    }

    // ---------- planningUrl ----------

    public function testLienAvecEtSansSport(): void
    {
        $week = new DateTimeImmutable('2026-11-02');

        $this->assertSame('planning.php?semaine=2026-11-02', planningUrl($week));
        $this->assertSame('planning.php?semaine=2026-11-02&sport=3', planningUrl($week, ['sport' => 3]));
        $this->assertSame('planning.php?semaine=2026-11-02&sport=3&coach=2', planningUrl($week, ['coach' => 2, 'sport' => 3]));
    }

    // ---------- formatWeekLabel / formatShortDayLabel ----------

    public static function semaines(): array
    {
        return [
            'même mois'   => ['2026-11-02', 'Semaine du 2 au 8 novembre 2026'],
            'deux mois'   => ['2026-10-26', 'Semaine du 26 octobre au 1 novembre 2026'],
            'deux années' => ['2026-12-28', 'Semaine du 28 décembre 2026 au 3 janvier 2027'],
        ];
    }

    #[DataProvider('semaines')]
    public function testLibelleDeLaSemaine(string $monday, string $expected): void
    {
        $this->assertSame($expected, formatWeekLabel(new DateTimeImmutable($monday)));
    }

    public function testLibelleCourtDuJour(): void
    {
        $this->assertSame('Lun 2 nov.', formatShortDayLabel(new DateTimeImmutable('2026-11-02')));
        $this->assertSame('Dim 16 août', formatShortDayLabel(new DateTimeImmutable('2026-08-16')));
        $this->assertSame('Mar 3 mars', formatShortDayLabel(new DateTimeImmutable('2026-03-03')));
    }

    // ---------- getTimeRange ----------

    private function prepared(string $start, string $end): array
    {
        return prepareCourse($this->courseRow(['date_debut' => $start, 'date_fin' => $end]), new DateTimeImmutable('2026-01-01'));
    }

    public function testPlageParDefautSansCours(): void
    {
        $this->assertSame([8, 20], getTimeRange([]));
    }

    public function testPlageNonReduiteParLesCours(): void
    {
        // 10:00 - 11:00 à Paris : reste dans 8 h - 20 h
        $this->assertSame([8, 20], getTimeRange([$this->prepared('2026-11-02 09:00:00+00', '2026-11-02 10:00:00+00')]));
    }

    public function testPlageElargieParUnCoursTot(): void
    {
        // 06:30 à Paris
        $this->assertSame([6, 20], getTimeRange([$this->prepared('2026-11-02 05:30:00+00', '2026-11-02 06:30:00+00')]));
    }

    public function testPlageElargieParUnCoursTardif(): void
    {
        // Finit à 21:15 à Paris : la grille va jusqu'à 22 h
        $this->assertSame([8, 22], getTimeRange([$this->prepared('2026-11-02 19:30:00+00', '2026-11-02 20:15:00+00')]));
    }

    public function testPlageJusquaMinuitSiLeCoursFinitLeLendemain(): void
    {
        // 23:00 - 00:30 à Paris
        $this->assertSame([8, 24], getTimeRange([$this->prepared('2026-11-02 22:00:00+00', '2026-11-02 23:30:00+00')]));
    }

    // ---------- layoutDay ----------

    private function columns(array $courses): array
    {
        $result = [];
        foreach (layoutDay($courses) as $course) {
            $result[$course['debut']->format('H:i')] = [$course['colonne'], $course['nb_colonnes']];
        }

        return $result;
    }

    public function testCoursSansChevauchementUneColonne(): void
    {
        $columns = $this->columns([
            $this->prepared('2026-11-02 09:00:00+00', '2026-11-02 10:00:00+00'),
            $this->prepared('2026-11-02 11:00:00+00', '2026-11-02 12:00:00+00'),
        ]);

        $this->assertSame(['10:00' => [0, 1], '12:00' => [0, 1]], $columns);
    }

    public function testCoursQuiSeSuiventSansSeChevaucher(): void
    {
        // Le 2e commence quand le 1er finit
        $columns = $this->columns([
            $this->prepared('2026-11-02 09:00:00+00', '2026-11-02 10:00:00+00'),
            $this->prepared('2026-11-02 10:00:00+00', '2026-11-02 11:00:00+00'),
        ]);

        $this->assertSame(['10:00' => [0, 1], '11:00' => [0, 1]], $columns);
    }

    public function testDeuxCoursEnMemeTempsCoteACote(): void
    {
        $columns = $this->columns([
            $this->prepared('2026-11-02 09:30:00+00', '2026-11-02 10:30:00+00'),
            $this->prepared('2026-11-02 09:00:00+00', '2026-11-02 10:00:00+00'),
        ]);

        $this->assertSame(['10:00' => [0, 2], '10:30' => [1, 2]], $columns);
    }

    public function testColonneLibereeReutilisee(): void
    {
        // A (9h-11h) chevauche B (10h-12h) et C (11h-12h), mais C commence quand A finit : C reprend la colonne de A
        $columns = $this->columns([
            $this->prepared('2026-11-02 08:00:00+00', '2026-11-02 10:00:00+00'),
            $this->prepared('2026-11-02 09:00:00+00', '2026-11-02 11:00:00+00'),
            $this->prepared('2026-11-02 10:00:00+00', '2026-11-02 11:00:00+00'),
        ]);

        $this->assertSame(['09:00' => [0, 2], '10:00' => [1, 2], '11:00' => [0, 2]], $columns);
    }

    public function testGroupesIndependants(): void
    {
        // Deux cours en même temps le matin, un seul l'après-midi : l'après-midi garde toute la largeur
        $columns = $this->columns([
            $this->prepared('2026-11-02 08:00:00+00', '2026-11-02 09:00:00+00'),
            $this->prepared('2026-11-02 08:30:00+00', '2026-11-02 09:30:00+00'),
            $this->prepared('2026-11-02 14:00:00+00', '2026-11-02 15:00:00+00'),
        ]);

        $this->assertSame([0, 1], $columns['15:00']);
        $this->assertSame(2, $columns['09:00'][1]);
    }

    public function testJourneeSansCours(): void
    {
        $this->assertSame([], layoutDay([]));
    }

    public function testDureeEnMinutes(): void
    {
        $this->assertSame(90, $this->prepared('2026-11-02 09:00:00+00', '2026-11-02 10:30:00+00')['minutes']);
    }

    // ---------- getNextCourseDate ----------

    public function testProchainCours(): void
    {
        $next = getNextCourseDate($this->db, new DateTimeImmutable('2026-10-01'));

        // 12:00 UTC le 2 novembre = 13:00 à Paris
        $this->assertSame('2026-11-02 13:00', $next->format('Y-m-d H:i'));
    }

    public function testProchainCoursDUnSport(): void
    {
        $next = getNextCourseDate($this->db, new DateTimeImmutable('2026-10-01'), ['sport' => 2]);

        $this->assertSame('2026-11-03', $next->format('Y-m-d'));
    }

    public function testProchainCoursApresUneDate(): void
    {
        $next = getNextCourseDate($this->db, new DateTimeImmutable('2026-11-02 13:00:00+00'));

        $this->assertSame('2026-11-02 19:00', $next->format('Y-m-d H:i'));
    }

    public function testPasDeProchainCours(): void
    {
        $this->assertNull(getNextCourseDate($this->db, new DateTimeImmutable('2027-01-01')));
        $this->assertNull(getNextCourseDate($this->db, new DateTimeImmutable('2026-01-01'), ['sport' => 999]));
    }

    // ---------- sportColor ----------

    public function testCouleurToujoursLaMemePourUnSport(): void
    {
        $this->assertSame(sportColor(3), sportColor(3));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', sportColor(3));
    }

    public function testCouleursDifferentesPourLesPremiersSports(): void
    {
        $colors = array_map('sportColor', range(1, 8));

        $this->assertCount(8, array_unique($colors));
    }

    public function testCouleurPourUnGrandNumeroDeSport(): void
    {
        $this->assertSame(sportColor(1), sportColor(9));
    }

    // ---------- getDefaultDay ----------

    public function testJourParDefautAujourdhui(): void
    {
        $week = new DateTimeImmutable('2026-11-02');

        $this->assertSame('2026-11-04', getDefaultDay($week, new DateTimeImmutable('2026-11-04 10:00'), []));
    }

    public function testJourParDefautPremierJourAvecDesCours(): void
    {
        $week = new DateTimeImmutable('2026-11-02');
        $now  = new DateTimeImmutable('2026-10-20');

        $this->assertSame('2026-11-05', getDefaultDay($week, $now, ['2026-11-05' => ['cours'], '2026-11-07' => ['cours']]));
    }

    public function testJourParDefautLundiSansCours(): void
    {
        $week = new DateTimeImmutable('2026-11-02');

        $this->assertSame('2026-11-02', getDefaultDay($week, new DateTimeImmutable('2026-10-20'), ['2026-11-05' => []]));
    }

    // ---------- Filtre par coach ----------

    public function testFiltreParCoach(): void
    {
        // Léa (coach 2) donne "Cycling matin" et "HIIT express"
        $titles = array_column(getCourses($this->db, ['coach' => 2]), 'titre');

        $this->assertSame(['HIIT express', 'Cycling matin'], $titles);
    }

    public function testFiltresSportEtCoachCombines(): void
    {
        $titles = array_column(getCourses($this->db, ['sport' => 1, 'coach' => 2]), 'titre');

        $this->assertSame(['HIIT express'], $titles);
    }

    public function testProchainCoursDUnCoach(): void
    {
        $next = getNextCourseDate($this->db, new DateTimeImmutable('2026-11-02 13:00:00+00'), ['coach' => 2]);

        $this->assertSame('2026-11-03', $next->format('Y-m-d'));
    }

    public function testCoursContientLIdDuCoach(): void
    {
        $this->assertSame(1, (int) getCourses($this->db, ['sport' => 1, 'coach' => 1])[0]['id_coach']);
    }

    // ---------- getCoaches ----------

    public function testCoachsAvecDesCoursTriesParPrenom(): void
    {
        $this->assertSame(['Léa', 'Marc'], array_column(getCoaches($this->db), 'prenom'));
    }

    public function testCoachSansCoursAbsent(): void
    {
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES (103, 'paul@example.com', 'x', 'coach')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (3, 103, 'Petit', 'Paul')");

        $this->assertNotContains('Paul', array_column(getCoaches($this->db), 'prenom'));
    }

    // ---------- placesStatus ----------

    public static function places(): array
    {
        return [
            'vide'               => [0, 20, '20 places restantes', 'ok'],
            'plus de 30 % libre' => [13, 20, '7 places restantes', 'ok'],
            'presque plein'      => [14, 20, '6 places restantes', 'low'],
            'une place'          => [19, 20, '1 place restante', 'low'],
            'complet'            => [20, 20, 'Complet', 'full'],
            'plus que complet'   => [22, 20, 'Complet', 'full'],
        ];
    }

    #[DataProvider('places')]
    public function testPlacesRestantes(int $registered, int $capacity, string $label, string $level): void
    {
        $this->assertSame(['label' => $label, 'level' => $level], placesStatus($registered, $capacity));
    }
}
