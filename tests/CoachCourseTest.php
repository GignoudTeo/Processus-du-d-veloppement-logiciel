<?php

use PHPUnit\Framework\TestCase;

class CoachCourseTest extends TestCase
{
    private PDO $db;
    private array $rooms;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();

        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'HIIT'), (2, 1, 'Cycling')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES
            (101, 'marc@example.com', 'x', 'coach'), (102, 'lea@example.com', 'x', 'coach'), (103, 'ancien@example.com', 'x', 'adherent')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (1, 101, 'Martin', 'Marc'), (2, 102, 'Durand', 'Léa'), (3, 103, 'Petit', 'Paul')");
        $this->db->exec("INSERT INTO salles (id_salle, nom_salle, capacite) VALUES (1, 'Studio 1', 25), (2, 'Studio 2', 10)");

        $this->rooms = getRooms($this->db);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'titre'         => 'HIIT du soir',
            'date'          => '2026-11-10',
            'heure_debut'   => '18:00',
            'heure_fin'     => '19:00',
            'id_salle'      => '1',
            'id_sport'      => '1',
            'nb_places_max' => '20',
            'description'   => 'Apporte une serviette',
        ], $overrides);
    }

    private function errors(array $overrides): array
    {
        return validateCourse($this->input($overrides), $this->rooms, [1, 2])[1];
    }

    private function create(int $coachId = 1, array $overrides = []): int
    {
        [$data, $errors] = validateCourse($this->input($overrides), $this->rooms, [1, 2]);
        $this->assertSame([], $errors);

        return createCourse($this->db, $coachId, $data);
    }

    private function conflicts(int $coachId, array $overrides = [], ?int $ignored = null): array
    {
        [$data] = validateCourse($this->input($overrides), $this->rooms, [1, 2]);

        return findCourseConflicts($this->db, $data, $coachId, $ignored);
    }

    // ---------- getCoachIdByAccount ----------

    public function testCompteCoach(): void
    {
        $this->assertSame(2, getCoachIdByAccount($this->db, 102));
    }

    public function testCompteSansProfilCoach(): void
    {
        $this->assertNull(getCoachIdByAccount($this->db, 999));
    }

    public function testAncienCoachQuiNEstPlusCoach(): void
    {
        // Le profil reste en base après un changement de rôle, mais il ne donne plus aucun droit
        $this->assertNull(getCoachIdByAccount($this->db, 103));
    }

    // ---------- Administrateurs ----------

    public function testCompteAdministrateur(): void
    {
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES (104, 'admin@example.com', 'x', 'admin')");

        $this->assertTrue(isAdminAccount($this->db, 104));
        $this->assertFalse(isAdminAccount($this->db, 101));
        $this->assertFalse(isAdminAccount($this->db, 999));
    }

    public function testCoachsProposesAUnAdministrateur(): void
    {
        // Compte admin avec un profil coach : proposé. Paul (103) n'est plus coach : pas proposé
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES (104, 'admin@example.com', 'x', 'admin')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (4, 104, 'Admin', 'Zoé')");

        $this->assertSame(['Léa', 'Marc', 'Zoé'], array_column(getAssignableCoaches($this->db), 'prenom'));
    }

    public function testChoixDuCoach(): void
    {
        $coaches = getAssignableCoaches($this->db);

        $this->assertSame(2, validateCourseCoach('2', $coaches));
        foreach (['', 'abc', '3', '99'] as $value) {
            $this->assertNull(validateCourseCoach($value, $coaches), $value);
        }
    }

    public function testAdministrateurLitLaSeanceDeNImporteQuelCoach(): void
    {
        $id     = $this->create(2);
        $course = getCourse($this->db, $id);

        $this->assertSame(2, (int) $course['id_coach']);
        $this->assertSame('Léa', $course['coach_prenom']);
        $this->assertNull(getCourse($this->db, 999));
    }

    public function testSeanceModifieeParUnAdministrateurGardeSonCoach(): void
    {
        $id = $this->create(2);
        [$data] = validateCourse($this->input(['titre' => 'Corrigé']), $this->rooms, [1, 2]);

        $this->assertTrue(updateCourse($this->db, $id, (int) getCourse($this->db, $id)['id_coach'], $data));

        $course = getCourse($this->db, $id);
        $this->assertSame('Corrigé', $course['titre']);
        $this->assertSame(2, (int) $course['id_coach']);
    }

    // ---------- validateCourse ----------

    public function testSeanceValide(): void
    {
        [$data, $errors] = validateCourse($this->input(), $this->rooms, [1, 2]);

        $this->assertSame([], $errors);
        $this->assertSame('2026-11-10 18:00', $data['debut']->format('Y-m-d H:i'));
        $this->assertSame('Europe/Paris', $data['debut']->getTimezone()->getName());
    }

    public function testDescriptionFacultative(): void
    {
        $this->assertSame([], $this->errors(['description' => '   ']));
    }

    public function testDescriptionTropLongue(): void
    {
        $this->assertArrayHasKey('description', $this->errors(['description' => str_repeat('a', COURSE_DESCRIPTION_MAX + 1)]));
    }

    public function testTitreObligatoire(): void
    {
        $this->assertArrayHasKey('titre', $this->errors(['titre' => '  ']));
    }

    public function testTitreTropLong(): void
    {
        $this->assertArrayHasKey('titre', $this->errors(['titre' => str_repeat('a', COURSE_TITLE_MAX + 1)]));
    }

    public function testDateInvalide(): void
    {
        foreach (['', 'abc', '2026-02-30'] as $date) {
            $this->assertArrayHasKey('date', $this->errors(['date' => $date]), $date);
        }
    }

    public function testHeuresInvalides(): void
    {
        $this->assertArrayHasKey('heure_debut', $this->errors(['heure_debut' => '25:00']));
        $this->assertArrayHasKey('heure_fin', $this->errors(['heure_fin' => '']));
    }

    public function testHeureInexistanteAuPassageALHeureDEte(): void
    {
        // Le 28 mars 2027, à Paris, on passe directement de 2 h à 3 h
        $this->assertArrayHasKey('heure_debut', $this->errors(['date' => '2027-03-28', 'heure_debut' => '02:30', 'heure_fin' => '04:00']));
    }

    public function testFinApresLeDebut(): void
    {
        $this->assertArrayHasKey('heure_fin', $this->errors(['heure_fin' => '18:00']));
        $this->assertArrayHasKey('heure_fin', $this->errors(['heure_fin' => '17:00']));
    }

    public function testSalleEtSportDoiventExister(): void
    {
        $this->assertArrayHasKey('id_salle', $this->errors(['id_salle' => '99']));
        $this->assertArrayHasKey('id_salle', $this->errors(['id_salle' => '']));
        $this->assertArrayHasKey('id_sport', $this->errors(['id_sport' => '99']));
    }

    public function testAuMoinsUnePlace(): void
    {
        foreach (['0', '-3', '', 'abc'] as $places) {
            $this->assertArrayHasKey('nb_places_max', $this->errors(['nb_places_max' => $places]), $places);
        }
    }

    public function testPlacesLimiteesParLaCapaciteDeLaSalle(): void
    {
        $this->assertSame([], $this->errors(['id_salle' => '2', 'nb_places_max' => '10']));
        $this->assertArrayHasKey('nb_places_max', $this->errors(['id_salle' => '2', 'nb_places_max' => '11']));
    }

    // ---------- findCourseConflicts ----------

    public function testSalleDejaOccupee(): void
    {
        $this->create();

        $errors = $this->conflicts(2, ['heure_debut' => '18:30', 'heure_fin' => '19:30']);

        $this->assertArrayHasKey('id_salle', $errors);
        $this->assertArrayNotHasKey('heure_debut', $errors);
    }

    public function testCoachDejaOccupeDansUneAutreSalle(): void
    {
        $this->create();

        $errors = $this->conflicts(1, ['id_salle' => '2', 'nb_places_max' => '5', 'heure_debut' => '17:30', 'heure_fin' => '18:30']);

        $this->assertArrayHasKey('heure_debut', $errors);
        $this->assertArrayNotHasKey('id_salle', $errors);
    }

    public function testSeancesQuiSeSuiventSansConflit(): void
    {
        $this->create();

        $this->assertSame([], $this->conflicts(1, ['heure_debut' => '19:00', 'heure_fin' => '20:00']));
        $this->assertSame([], $this->conflicts(1, ['heure_debut' => '17:00', 'heure_fin' => '18:00']));
    }

    public function testAutreCoachAutreSalleSansConflit(): void
    {
        $this->create();

        $this->assertSame([], $this->conflicts(2, ['id_salle' => '2', 'nb_places_max' => '5']));
    }

    public function testUneSeanceNeSeGenePasElleMeme(): void
    {
        $id = $this->create();

        $this->assertSame([], $this->conflicts(1, ['heure_fin' => '19:30'], $id));
    }

    // ---------- Création : visible dans le planning de tout le monde ----------

    public function testSeanceCreeeApparaitDansLePlanning(): void
    {
        $id     = $this->create(2);
        $course = array_column(getCourses($this->db), null, 'id_cours')[$id];

        $this->assertSame('HIIT du soir', $course['titre']);
        $this->assertSame('Léa', $course['coach_prenom']);
        $this->assertSame('Studio 1', $course['nom_salle']);
        $this->assertSame('Apporte une serviette', $course['description']);
        // 18:00 à Paris = 17:00 UTC en novembre
        $this->assertSame('2026-11-10 17:00:00+00', $course['date_debut']);
    }

    public function testDescriptionVideEnregistreeSansValeur(): void
    {
        $id = $this->create(1, ['description' => '']);

        $this->assertNull(getCoachCourse($this->db, $id, 1)['description']);
    }

    // ---------- Un coach ne touche qu'à ses propres séances ----------

    public function testLectureDeSaSeanceSeulement(): void
    {
        $id = $this->create(1);

        $this->assertSame($id, (int) getCoachCourse($this->db, $id, 1)['id_cours']);
        $this->assertNull(getCoachCourse($this->db, $id, 2));
    }

    public function testModificationDeSaSeance(): void
    {
        $id = $this->create(1);
        [$data] = validateCourse($this->input(['titre' => 'Nouveau titre', 'id_sport' => '2', 'description' => '']), $this->rooms, [1, 2]);

        $this->assertTrue(updateCourse($this->db, $id, 1, $data));

        $course = getCoachCourse($this->db, $id, 1);
        $this->assertSame('Nouveau titre', $course['titre']);
        $this->assertSame(2, (int) $course['id_sport']);
        $this->assertNull($course['description']);
    }

    public function testModificationDeLaSeanceDUnAutreCoachRefusee(): void
    {
        $id = $this->create(1);
        [$data] = validateCourse($this->input(['titre' => 'Piraté']), $this->rooms, [1, 2]);

        $this->assertFalse(updateCourse($this->db, $id, 2, $data));
        $this->assertSame('HIIT du soir', getCoachCourse($this->db, $id, 1)['titre']);
    }

    public function testSuppressionDeSaSeance(): void
    {
        $id = $this->create(1);

        $this->assertTrue(deleteCourse($this->db, $id, 1));
        $this->assertNull(getCoachCourse($this->db, $id, 1));
    }

    public function testSuppressionDeLaSeanceDUnAutreCoachRefusee(): void
    {
        $id = $this->create(1);

        $this->assertFalse(deleteCourse($this->db, $id, 2));
        $this->assertNotNull(getCoachCourse($this->db, $id, 1));
    }

    public function testSuppressionEffaceLesReservations(): void
    {
        $id = $this->create(1);
        [$accountId] = createTestAdherent($this->db);
        $adherentId  = $this->db->query("SELECT id_adherent FROM adherents WHERE id_compte = $accountId")->fetchColumn();
        $this->db->prepare('INSERT INTO reservations (id_cours, id_adherent) VALUES (?, ?)')->execute([$id, $adherentId]);

        $this->assertSame(1, (int) getCoachCourse($this->db, $id, 1)['nb_inscrits']);

        deleteCourse($this->db, $id, 1);

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
    }

    // ---------- courseToFormData ----------

    public function testFormulairePreRempliEnHeureDeParis(): void
    {
        $id   = $this->create(1);
        $form = courseToFormData(getCoachCourse($this->db, $id, 1));

        $this->assertSame('2026-11-10', $form['date']);
        $this->assertSame('18:00', $form['heure_debut']);
        $this->assertSame('19:00', $form['heure_fin']);
        $this->assertSame('1', $form['id_salle']);
        $this->assertSame('20', $form['nb_places_max']);
        $this->assertSame('Apporte une serviette', $form['description']);
    }
}
