<?php

use PHPUnit\Framework\TestCase;

class ReservationTest extends TestCase
{
    private const NOW = '2026-11-09 12:00:00+00';

    private PDO $db;
    private int $alex;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();

        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'HIIT')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES (101, 'marc@example.com', 'x', 'coach')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (1, 101, 'Martin', 'Marc')");
        $this->db->exec("INSERT INTO salles (id_salle, nom_salle, capacite) VALUES (1, 'Studio 1', 25)");

        $this->insertCourse(1, 'HIIT 18h', '2026-11-10 17:00:00+00', '2026-11-10 18:00:00+00', 2);
        $this->insertCourse(2, 'Chevauche le 18h', '2026-11-10 17:30:00+00', '2026-11-10 18:30:00+00', 10);
        $this->insertCourse(3, 'Juste après le 18h', '2026-11-10 18:00:00+00', '2026-11-10 19:00:00+00', 10);
        $this->insertCourse(4, 'Passé', '2026-11-01 17:00:00+00', '2026-11-01 18:00:00+00', 10);

        $this->alex = $this->adherent();
    }

    private function insertCourse(int $id, string $title, string $start, string $end, int $max): void
    {
        $this->db->prepare(
            'INSERT INTO cours (id_cours, titre, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max)
             VALUES (?, ?, 1, 1, 1, ?, ?, ?)'
        )->execute([$id, $title, $start, $end, $max]);
    }

    private function adherent(): int
    {
        [$accountId] = createTestAdherent($this->db, ['email' => uniqid('', true) . '@example.com']);

        return getAdherentIdByAccount($this->db, $accountId);
    }

    private function register(int $adherentId, int $courseId, string $now = self::NOW): array
    {
        return registerForCourse($this->db, $adherentId, $courseId, new DateTimeImmutable($now));
    }

    private function unregister(int $adherentId, int $courseId, string $now = self::NOW): string
    {
        return unregisterFromCourse($this->db, $adherentId, $courseId, new DateTimeImmutable($now));
    }

    private function statusOf(int $adherentId, int $courseId): string|false
    {
        return $this->db->query("SELECT statut FROM reservations WHERE id_adherent = $adherentId AND id_cours = $courseId")->fetchColumn();
    }

    private function places(int $courseId): int
    {
        return (int) array_column(getCourses($this->db), 'nb_inscrits', 'id_cours')[$courseId];
    }

    // ---------- Profil adhérent ----------

    public function testCompteSansProfilAdherent(): void
    {
        $this->assertNull(getAdherentIdByAccount($this->db, 101));
        $this->assertNull(getAdherentIdByAccount($this->db, 999));
    }

    public function testAdminAvecProfilAdherent(): void
    {
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES (104, 'admin@example.com', 'x', 'admin')");
        $this->db->exec("INSERT INTO adherents (id_adherent, id_compte, nom, prenom) VALUES (50, 104, 'Admin', 'Zoé')");

        $this->assertSame(50, getAdherentIdByAccount($this->db, 104));
    }

    // ---------- Inscription ----------

    public function testInscriptionImmediateSIlResteDesPlaces(): void
    {
        $result = $this->register($this->alex, 1);

        $this->assertSame('registered', $result['status']);
        $this->assertNull($result['conflict']);
        $this->assertSame('confirmee', $this->statusOf($this->alex, 1));
        $this->assertSame(1, $this->places(1));
    }

    public function testCoursComplet(): void
    {
        $this->register($this->adherent(), 1);
        $this->register($this->adherent(), 1);

        $this->assertSame('full', $this->register($this->alex, 1)['status']);
        $this->assertFalse($this->statusOf($this->alex, 1));
        $this->assertSame(2, $this->places(1));
    }

    public function testDerniereplacePrise(): void
    {
        $this->register($this->adherent(), 1);

        $this->assertSame('registered', $this->register($this->alex, 1)['status']);
    }

    public function testDejaInscrit(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('already_registered', $this->register($this->alex, 1)['status']);
        $this->assertSame(1, $this->places(1));
    }

    public function testCoursIntrouvable(): void
    {
        $this->assertSame('not_found', $this->register($this->alex, 999)['status']);
    }

    public function testCoursDejaCommence(): void
    {
        $this->assertSame('started', $this->register($this->alex, 4)['status']);
        $this->assertSame('started', $this->register($this->alex, 1, '2026-11-10 17:00:00+00')['status']);
    }

    // ---------- Chevauchement ----------

    public function testDeuxCoursQuiSeChevauchent(): void
    {
        $this->register($this->alex, 1);

        $result = $this->register($this->alex, 2);

        $this->assertSame('overlap', $result['status']);
        $this->assertSame('HIIT 18h', $result['conflict']['titre']);
        $this->assertFalse($this->statusOf($this->alex, 2));
    }

    public function testCoursQuiSeSuiventAcceptes(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('registered', $this->register($this->alex, 3)['status']);
    }

    public function testCoursQuitteNeBloquePlusLeCreneau(): void
    {
        $this->register($this->alex, 1);
        $this->unregister($this->alex, 1);

        $this->assertSame('registered', $this->register($this->alex, 2)['status']);
    }

    public function testChevauchementSeulementPourSesPropresCours(): void
    {
        $this->register($this->adherent(), 1);

        $this->assertSame('registered', $this->register($this->alex, 2)['status']);
    }

    // ---------- Désinscription ----------

    public function testDesinscriptionLibereLaPlaceEtCompteEnAbsence(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('unregistered', $this->unregister($this->alex, 1));
        // Le trigger de la base compte ce statut dans adherents.nb_absences
        $this->assertSame('absent', $this->statusOf($this->alex, 1));
        $this->assertSame(0, $this->places(1));
    }

    public function testPlaceLibereeReprisParUnAutre(): void
    {
        $other = $this->adherent();
        $this->register($this->adherent(), 1);
        $this->register($other, 1);
        $this->assertSame('full', $this->register($this->alex, 1)['status']);

        $this->unregister($other, 1);

        $this->assertSame('registered', $this->register($this->alex, 1)['status']);
    }

    public function testDesinscriptionSansInscription(): void
    {
        $this->assertSame('not_registered', $this->unregister($this->alex, 1));
        $this->assertSame('not_registered', $this->unregister($this->alex, 999));
    }

    public function testDoubleDesinscription(): void
    {
        $this->register($this->alex, 1);
        $this->unregister($this->alex, 1);

        $this->assertSame('not_registered', $this->unregister($this->alex, 1));
    }

    public function testDesinscriptionImpossibleUneFoisLeCoursCommence(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('started', $this->unregister($this->alex, 1, '2026-11-10 17:00:00+00'));
        $this->assertSame('confirmee', $this->statusOf($this->alex, 1));
    }

    public function testDesinscriptionPossibleJusquAuDebut(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('unregistered', $this->unregister($this->alex, 1, '2026-11-10 16:59:00+00'));
    }

    // ---------- Réinscription ----------

    public function testReinscriptionApresDesinscription(): void
    {
        $this->register($this->alex, 1);
        $this->unregister($this->alex, 1);

        $this->assertSame('registered', $this->register($this->alex, 1)['status']);
        $this->assertSame('confirmee', $this->statusOf($this->alex, 1));
        $this->assertSame(1, $this->places(1));
    }

    public function testReinscriptionApresRetraitParLeCoach(): void
    {
        $this->register($this->alex, 1);
        $this->db->exec("UPDATE reservations SET statut = 'rejetee' WHERE id_cours = 1 AND id_adherent = {$this->alex}");

        $this->assertSame('registered', $this->register($this->alex, 1)['status']);
        $this->assertSame('confirmee', $this->statusOf($this->alex, 1));
    }

    public function testReinscriptionImpossibleSiCompletEntreTemps(): void
    {
        $this->register($this->alex, 1);
        $this->unregister($this->alex, 1);
        $this->register($this->adherent(), 1);
        $this->register($this->adherent(), 1);

        $this->assertSame('full', $this->register($this->alex, 1)['status']);
        $this->assertSame('absent', $this->statusOf($this->alex, 1));
    }

    // ---------- Cours affichés ----------

    public function testCoursDeLAdherent(): void
    {
        $this->register($this->alex, 1);
        $this->register($this->alex, 3);
        $this->unregister($this->alex, 3);

        $this->assertSame([1], getRegisteredCourseIds($this->db, $this->alex, [1, 2, 3]));
        $this->assertSame([], getRegisteredCourseIds($this->db, $this->alex, [2]));
        $this->assertSame([], getRegisteredCourseIds($this->db, $this->alex, []));
    }

    public function testBoutonSelonLEtatDuCours(): void
    {
        $course = ['termine' => false, 'complet' => false];

        $this->assertSame('disponible', courseRegistrationState($course, false));
        $this->assertSame('complet', courseRegistrationState(['complet' => true] + $course, false));
        $this->assertSame('inscrit', courseRegistrationState($course, true));
        // Déjà inscrit à un cours complet : il doit pouvoir se désinscrire
        $this->assertSame('inscrit', courseRegistrationState(['complet' => true] + $course, true));
        $this->assertNull(courseRegistrationState(['termine' => true] + $course, true));
        $this->assertNull(courseRegistrationState(['termine' => true] + $course, false));
    }

    // ---------- Filtres ----------

    public function testFiltresDuPlanningValides(): void
    {
        $this->assertSame(['sport' => 3, 'coach' => 2], parsePlanningFilters(['sport' => '3', 'coach' => '2', 'autre' => '9']));
        $this->assertSame([], parsePlanningFilters(['sport' => 'abc', 'coach' => '0']));
    }
}
