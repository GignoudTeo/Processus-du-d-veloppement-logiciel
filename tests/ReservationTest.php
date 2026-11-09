<?php

use PHPUnit\Framework\TestCase;

// Inscription (demande validée par le coach), liste d'attente et désinscription avec motif (US2, US3, US7)
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

    private function adherent(string $firstname = 'Alex'): int
    {
        [$accountId] = createTestAdherent($this->db, ['email' => uniqid('', true) . '@example.com', 'prenom' => $firstname]);

        return getAdherentIdByAccount($this->db, $accountId);
    }

    private function register(int $adherentId, int $courseId, string $now = self::NOW): array
    {
        return registerForCourse($this->db, $adherentId, $courseId, new DateTimeImmutable($now));
    }

    private function unregister(int $adherentId, int $courseId, string $reason = 'Empêchement personnel', string $now = self::NOW): array
    {
        return unregisterFromCourse($this->db, $adherentId, $courseId, new DateTimeImmutable($now), $reason);
    }

    private function statusOf(int $adherentId, int $courseId): string|false
    {
        return $this->db->query("SELECT statut FROM reservations WHERE id_adherent = $adherentId AND id_cours = $courseId")->fetchColumn();
    }

    // Le coach valide directement (pour remplir une séance dans les tests)
    private function validate(int $adherentId, int $courseId): void
    {
        $this->db->exec("UPDATE reservations SET statut = 'confirmee' WHERE id_adherent = $adherentId AND id_cours = $courseId");
    }

    // Adhérent inscrit et validé sur la séance 1 (2 places)
    private function validatedMember(string $firstname): int
    {
        $id = $this->adherent($firstname);
        $this->register($id, 1);
        $this->validate($id, 1);

        return $id;
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

    // ---------- Inscription (US2) ----------

    public function testInscriptionCreeUneDemandeEnAttente(): void
    {
        $this->assertSame('requested', $this->register($this->alex, 1)['status']);
        $this->assertSame('en_attente', $this->statusOf($this->alex, 1));
    }

    public function testUneDemandeNeReservePasDePlace(): void
    {
        $this->register($this->alex, 1);

        // Seuls les validés occupent une place
        $this->assertSame(0, (int) array_column(getCourses($this->db), 'nb_inscrits', 'id_cours')[1]);
    }

    public function testDejaInscrit(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('already_registered', $this->register($this->alex, 1)['status']);
        $this->validate($this->alex, 1);
        $this->assertSame('already_registered', $this->register($this->alex, 1)['status']);
    }

    public function testCoursIntrouvableOuCommence(): void
    {
        $this->assertSame('not_found', $this->register($this->alex, 999)['status']);
        $this->assertSame('started', $this->register($this->alex, 4)['status']);
        $this->assertSame('started', $this->register($this->alex, 1, '2026-11-10 17:00:00+00')['status']);
    }

    public function testChevauchementRefuse(): void
    {
        $this->register($this->alex, 1);

        $result = $this->register($this->alex, 2);

        $this->assertSame('overlap', $result['status']);
        $this->assertSame('HIIT 18h', $result['conflict']['titre']);
        $this->assertFalse($this->statusOf($this->alex, 2));
    }

    public function testChevauchementAussiAvecLaListeDAttente(): void
    {
        $this->validatedMember('Sam');
        $this->validatedMember('Léa');
        $this->register($this->alex, 1); // complet : liste d'attente

        $this->assertSame('overlap', $this->register($this->alex, 2)['status']);
    }

    public function testCoursQuiSeSuiventAcceptes(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('requested', $this->register($this->alex, 3)['status']);
    }

    public function testChevauchementSeulementPourSesPropresCours(): void
    {
        $this->register($this->adherent('Sam'), 1);

        $this->assertSame('requested', $this->register($this->alex, 2)['status']);
    }

    public function testDemandeRefuseeNePeutPasEtreRenvoyee(): void
    {
        $this->register($this->alex, 1);
        $this->db->exec("UPDATE reservations SET statut = 'rejetee' WHERE id_adherent = {$this->alex}");

        $this->assertSame('refused', $this->register($this->alex, 1)['status']);
    }

    public function testReinscriptionApresDesinscription(): void
    {
        $this->register($this->alex, 1);
        $this->unregister($this->alex, 1);

        $this->assertSame('requested', $this->register($this->alex, 1)['status']);
        $this->assertSame('en_attente', $this->statusOf($this->alex, 1));
        // Le motif de l'ancienne désinscription est effacé
        $this->assertNull($this->db->query("SELECT motif FROM reservations WHERE id_adherent = {$this->alex}")->fetchColumn());
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM reservations')->fetchColumn());
    }

    // ---------- Liste d'attente (US3) ----------

    public function testSeanceCompletePasseEnListeDAttente(): void
    {
        $this->validatedMember('Sam');
        $this->validatedMember('Léa');

        $result = $this->register($this->alex, 1);

        $this->assertSame('waitlisted', $result['status']);
        $this->assertSame(1, $result['position']);
        $this->assertSame('liste_attente', $this->statusOf($this->alex, 1));
    }

    public function testPositionsDansLaListeDAttente(): void
    {
        $this->validatedMember('Sam');
        $this->validatedMember('Léa');
        $first  = $this->adherent('Premier');
        $second = $this->adherent('Second');
        $this->register($first, 1);
        $this->register($second, 1);

        $this->assertSame(1, waitingListPosition($this->db, 1, $first));
        $this->assertSame(2, waitingListPosition($this->db, 1, $second));
        $this->assertNull(waitingListPosition($this->db, 1, $this->alex));
    }

    public function testQuitterLaListeDAttente(): void
    {
        $this->validatedMember('Sam');
        $this->validatedMember('Léa');
        $first = $this->adherent('Premier');
        $this->register($first, 1);
        $this->register($this->alex, 1);

        $this->assertTrue(leaveWaitingList($this->db, $first, 1));
        $this->assertFalse(leaveWaitingList($this->db, $first, 1));

        $this->assertSame('annulee', $this->statusOf($first, 1));
        // Alex remonte en première position
        $this->assertSame(1, waitingListPosition($this->db, 1, $this->alex));
    }

    public function testPlaceLibereeProposeLaPremierePersonneAuCoach(): void
    {
        $sam = $this->validatedMember('Sam');
        $this->validatedMember('Léa');
        $first  = $this->adherent('Premier');
        $second = $this->adherent('Second');
        $this->register($first, 1);
        $this->register($second, 1);

        $result = $this->unregister($sam, 1);

        $this->assertSame('Premier', $result['promoted']['prenom']);
        $this->assertSame('en_attente', $this->statusOf($first, 1));
        $this->assertSame('liste_attente', $this->statusOf($second, 1));
        $this->assertSame(1, waitingListPosition($this->db, 1, $second));
    }

    public function testDesinscriptionDUneDemandeNeLibereAucunePlace(): void
    {
        $this->validatedMember('Sam');
        $pending = $this->adherent('Demande');
        $this->register($pending, 1);
        $this->validatedMember('Léa'); // complet
        $this->register($this->alex, 1); // liste d'attente

        $result = $this->unregister($pending, 1);

        $this->assertFalse($result['was_confirmed']);
        $this->assertNull($result['promoted']);
        $this->assertSame('liste_attente', $this->statusOf($this->alex, 1));
    }

    public function testPasDePromotionSansListeDAttente(): void
    {
        $this->assertNull(promoteFromWaitingList($this->db, 1));
        $this->assertNull(promoteFromWaitingList($this->db, 999));
    }

    // ---------- Désinscription avec motif (US7) ----------

    public function testDesinscriptionAvecMotif(): void
    {
        $this->register($this->alex, 1);
        $this->validate($this->alex, 1);

        $result = $this->unregister($this->alex, 1, '  Raison de santé ');

        $this->assertSame('unregistered', $result['status']);
        $this->assertTrue($result['was_confirmed']);
        $this->assertSame('Martin', explode(' ', $result['course']['nom_coach'])[1]);

        $row = $this->db->query("SELECT statut, motif, date_statut FROM reservations WHERE id_adherent = {$this->alex}")->fetch();
        $this->assertSame('absent', $row['statut']);
        $this->assertSame('Raison de santé', $row['motif']);
        $this->assertNotNull($row['date_statut']);
    }

    public function testDesinscriptionEnregistreeDansLHistorique(): void
    {
        $this->register($this->alex, 1);

        $this->unregister($this->alex, 1, 'Contrainte professionnelle');

        $event = $this->db->query("SELECT * FROM historique_seances")->fetch();
        $this->assertSame('desinscription', $event['type']);
        $this->assertSame('Contrainte professionnelle', $event['motif']);
        $this->assertSame('HIIT 18h', $event['titre_cours']);
        $this->assertSame('Marc Martin', $event['nom_coach']);
        $this->assertSame('Studio 1', $event['nom_salle']);
        $this->assertSame($this->alex, (int) $event['id_adherent']);
    }

    public function testMotifObligatoire(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('reason_required', $this->unregister($this->alex, 1, "  \n ")['status']);
        $this->assertSame('en_attente', $this->statusOf($this->alex, 1));
    }

    public function testDesinscriptionSansInscription(): void
    {
        $this->assertSame('not_registered', $this->unregister($this->alex, 1)['status']);
        $this->assertSame('not_registered', $this->unregister($this->alex, 999)['status']);
    }

    public function testDoubleDesinscriptionNeCompteQuUneFois(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('unregistered', $this->unregister($this->alex, 1)['status']);
        $this->assertSame('not_registered', $this->unregister($this->alex, 1)['status']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM historique_seances')->fetchColumn());
    }

    public function testListeDAttenteNeSeDesinscritPasAvecUnMotif(): void
    {
        $this->validatedMember('Sam');
        $this->validatedMember('Léa');
        $this->register($this->alex, 1);

        // Sur liste d'attente, on quitte la liste (leaveWaitingList) : pas de désinscription
        $this->assertSame('not_registered', $this->unregister($this->alex, 1)['status']);
    }

    public function testDesinscriptionImpossibleUneFoisLeCoursCommence(): void
    {
        $this->register($this->alex, 1);

        $this->assertSame('started', $this->unregister($this->alex, 1, 'Empêchement', '2026-11-10 17:00:00+00')['status']);
        $this->assertSame('unregistered', $this->unregister($this->alex, 1, 'Empêchement', '2026-11-10 16:59:00+00')['status']);
    }

    // ---------- Statuts affichés ----------

    public function testStatutsDeLAdherentParCours(): void
    {
        $this->register($this->alex, 1);
        $this->register($this->alex, 3);
        $this->validate($this->alex, 3);

        $this->assertSame([1 => 'en_attente', 3 => 'confirmee'], getBookingStatuses($this->db, $this->alex, [1, 2, 3]));
        $this->assertSame([], getBookingStatuses($this->db, $this->alex, []));
    }

    public function testBoutonSelonLEtatDuCours(): void
    {
        $course = ['termine' => false, 'complet' => false];

        $this->assertSame('disponible', courseRegistrationState($course, null));
        $this->assertSame('liste_possible', courseRegistrationState(['complet' => true] + $course, null));
        $this->assertSame('disponible', courseRegistrationState($course, 'absent'));
        $this->assertSame('disponible', courseRegistrationState($course, 'annulee'));
        foreach (['en_attente', 'confirmee', 'liste_attente', 'rejetee'] as $status) {
            $this->assertSame($status, courseRegistrationState(['complet' => true] + $course, $status));
        }
        $this->assertNull(courseRegistrationState(['termine' => true] + $course, 'confirmee'));
        $this->assertNull(courseRegistrationState(['termine' => true] + $course, null));
    }
}
