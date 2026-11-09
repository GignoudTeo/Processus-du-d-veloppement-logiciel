<?php

use PHPUnit\Framework\TestCase;

// Statistiques admin (US8), passages (US9), historique adhérent (US10), progression (US11),
// profil coach (US12) et rappel J-3 (US6)
class MemberFollowUpTest extends TestCase
{
    private const NOW = '2026-11-09 12:00:00+00';

    private PDO $db;
    private int $alex;
    private int $sam;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'HIIT')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES
            (101, 'marc@example.com', 'x', 'coach'), (102, 'lea@example.com', 'x', 'coach')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (1, 101, 'Martin', 'Marc'), (2, 102, 'Durand', 'Léa')");
        $this->db->exec("INSERT INTO salles (id_salle, nom_salle, capacite) VALUES (1, 'Studio 1', 25)");
        // 1 : passé (coach 1) ; 2 : à venir (coach 1) ; 3 : passé (coach 2) ; 4 : dans 2 jours et demi (coach 1)
        $this->db->exec("INSERT INTO cours (id_cours, titre, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max) VALUES
            (1, 'HIIT lundi', 1, 1, 1, '2026-11-02 17:00:00+00', '2026-11-02 18:00:00+00', 5),
            (2, 'HIIT jeudi', 1, 1, 1, '2026-11-12 17:00:00+00', '2026-11-12 18:00:00+00', 5),
            (3, 'Yoga', 1, 2, 1, '2026-10-20 17:00:00+00', '2026-10-20 18:00:00+00', 5),
            (4, 'HIIT J-3', 1, 1, 1, '2026-11-12 00:00:00+00', '2026-11-12 01:00:00+00', 5)");

        $this->alex = $this->member('Alex');
        $this->sam  = $this->member('Sam');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function member(string $firstname): int
    {
        [$accountId] = createTestAdherent($this->db, ['email' => uniqid('', true) . '@example.com', 'prenom' => $firstname]);

        return getAdherentIdByAccount($this->db, $accountId);
    }

    private function book(int $adherentId, int $courseId, string $status, ?string $reason = null): void
    {
        $this->db->prepare('INSERT INTO reservations (id_cours, id_adherent, statut, motif) VALUES (?, ?, ?, ?)')
            ->execute([$courseId, $adherentId, $status, $reason]);
    }

    private function passage(int $adherentId, string $entry, ?string $exit): void
    {
        $this->db->prepare('INSERT INTO passages (id_adherent, entree, sortie) VALUES (?, ?, ?)')->execute([$adherentId, $entry, $exit]);
    }

    private function event(string $type, string $reason, int $coachId, string $date, string $coachName = 'Marc Martin'): void
    {
        $this->db->prepare(
            "INSERT INTO historique_seances (type, motif, id_cours, titre_cours, date_cours, id_coach, nom_coach, id_adherent, nom_adherent, date_evenement)
             VALUES (?, ?, 99, 'Séance', '2026-11-01 17:00:00+00', ?, ?, ?, 'Alex Martin', ?)"
        )->execute([$type, $reason, $coachId, $coachName, $this->alex, $date]);
    }

    // ---------- Statistiques des refus et désinscriptions (US8) ----------

    public function testStatistiquesParMotifEtParCoach(): void
    {
        $this->event('refus', 'Séance complète', 1, '2026-11-01 10:00:00+00');
        $this->event('refus', 'Séance complète', 2, '2026-11-02 10:00:00+00', 'Léa Durand');
        $this->event('desinscription', 'Raison de santé', 1, '2026-11-03 10:00:00+00');
        $this->event('annulation', 'Coach malade', 1, '2026-11-03 10:00:00+00');

        $stats = getRefusalStatistics($this->db, parseStatisticsFilters([]));

        // Les annulations de séance ne sont pas comptées
        $this->assertSame(['refus' => 2, 'desinscription' => 1], $stats['total']);
        $this->assertSame(
            [['motif' => 'Séance complète', 'refus' => 2, 'desinscription' => 0, 'total' => 2],
             ['motif' => 'Raison de santé', 'refus' => 0, 'desinscription' => 1, 'total' => 1]],
            $stats['par_motif']
        );
        $this->assertSame(['Marc Martin', 'Léa Durand'], array_column($stats['par_coach'], 'coach'));
        $this->assertCount(3, $stats['evenements']);
    }

    public function testFiltresTypeEtPeriode(): void
    {
        $this->event('refus', 'Séance complète', 1, '2026-10-15 10:00:00+00');
        $this->event('refus', 'Profil incomplet', 1, '2026-11-02 10:00:00+00');
        $this->event('desinscription', 'Raison de santé', 1, '2026-11-02 10:00:00+00');

        $refusals = getRefusalStatistics($this->db, parseStatisticsFilters(['type' => 'refus']));
        $this->assertSame(['refus' => 2, 'desinscription' => 0], $refusals['total']);

        $november = getRefusalStatistics($this->db, parseStatisticsFilters(['du' => '2026-11-01', 'au' => '2026-11-02']));
        $this->assertSame(['refus' => 1, 'desinscription' => 1], $november['total']);

        $invalid = parseStatisticsFilters(['type' => 'annulation', 'du' => '2026-02-30']);
        $this->assertNull($invalid['type']);
        $this->assertNull($invalid['du']);
    }

    public function testHistoriqueConserveApresSuppressionDeLaSeanceEtDuCoach(): void
    {
        $this->book($this->alex, 2, 'en_attente');
        $booking = getCoachBooking($this->db, (int) $this->db->lastInsertId(), 1);
        $this->db->beginTransaction();
        refusePendingBooking($this->db, $booking, 'Profil incomplet');
        $this->db->commit();

        $this->db->exec('DELETE FROM cours WHERE id_cours = 2');
        $this->db->exec('PRAGMA foreign_keys = OFF');
        $this->db->exec('DELETE FROM coachs WHERE id_coach = 1');

        $stats = getRefusalStatistics($this->db, parseStatisticsFilters([]));
        $this->assertSame(1, $stats['total']['refus']);
        $this->assertSame('HIIT jeudi', $stats['evenements'][0]['titre_cours']);
        $this->assertSame('Marc Martin', $stats['par_coach'][0]['coach']);
    }

    // ---------- Passages en salle (US9) ----------

    public function testDureeDesPassagesEtPassageIncomplet(): void
    {
        $this->passage($this->alex, '2026-11-02 16:50:00+00', '2026-11-02 18:20:00+00');
        $this->passage($this->alex, '2026-11-04 16:50:00+00', null);
        $this->passage($this->sam, '2026-11-04 10:00:00+00', '2026-11-04 11:00:00+00');

        $passages = getMemberPassages($this->db, $this->alex, parsePeriod([]));

        // Seulement les siens, du plus récent au plus ancien
        $this->assertCount(2, $passages);
        $this->assertNull($passages[0]['sortie']);
        $this->assertNull($passages[0]['minutes']);
        $this->assertSame('Incomplet', $passages[0]['duree']);
        $this->assertSame(90, $passages[1]['minutes']);
        $this->assertSame('1 h 30', $passages[1]['duree']);
        $this->assertSame('17:50', $passages[1]['entree']->format('H:i'));
    }

    public function testPassagesFiltresParPeriode(): void
    {
        $this->passage($this->alex, '2026-10-20 16:00:00+00', '2026-10-20 17:00:00+00');
        $this->passage($this->alex, '2026-11-02 16:00:00+00', '2026-11-02 17:00:00+00');

        $this->assertCount(1, getMemberPassages($this->db, $this->alex, parsePeriod(['du' => '2026-11-01'])));
        $this->assertCount(1, getMemberPassages($this->db, $this->alex, parsePeriod(['au' => '2026-10-20'])));
    }

    // ---------- Historique des séances (US10) ----------

    public function testHistoriqueDesSeancesAvecStatutsEtMotifs(): void
    {
        $this->book($this->alex, 1, 'confirmee');
        $this->book($this->alex, 2, 'rejetee', 'Profil incomplet');
        $this->book($this->alex, 3, 'absent', 'Raison de santé');
        $this->book($this->sam, 1, 'confirmee');
        // Séance annulée et supprimée : elle vient de l'historique
        $this->db->exec("INSERT INTO historique_seances (type, motif, id_cours, titre_cours, date_cours, nom_coach, id_adherent)
                         VALUES ('annulation', 'Coach malade', 50, 'Boxe', '2026-11-20 17:00:00+00', 'Marc Martin', {$this->alex})");

        $sessions = getMemberSessions($this->db, $this->alex, parsePeriod([]), $this->now());

        // Triées de la plus récente à la plus ancienne
        $this->assertSame(['Boxe', 'HIIT jeudi', 'HIIT lundi', 'Yoga'], array_column($sessions, 'titre'));
        $this->assertSame(['seance_annulee', 'rejetee', 'confirmee', 'absent'], array_column($sessions, 'statut'));
        $this->assertSame('Coach malade', $sessions[0]['motif']);
        $this->assertSame('Profil incomplet', $sessions[1]['motif']);
        $this->assertSame('Marc Martin', $sessions[2]['coach']);
        $this->assertTrue($sessions[1]['a_venir']);
        $this->assertFalse($sessions[1]['modifiable']); // refusée : rien à faire
    }

    public function testHistoriqueFiltreParPeriodeEtPositionDAttente(): void
    {
        $this->book($this->alex, 1, 'confirmee');
        $this->book($this->sam, 2, 'liste_attente');
        $this->book($this->alex, 2, 'liste_attente');

        $sessions = getMemberSessions($this->db, $this->alex, parsePeriod(['du' => '2026-11-10']), $this->now());

        $this->assertSame(['HIIT jeudi'], array_column($sessions, 'titre'));
        $this->assertSame(2, $sessions[0]['position']);
        $this->assertTrue($sessions[0]['modifiable']);
    }

    // ---------- Progression (US11) ----------

    public function testImc(): void
    {
        $this->assertSame(22.9, computeBmi(70, 175));
        $this->assertSame(24.2, computeBmi(78.5, 180));
    }

    public function testMesureValide(): void
    {
        [$data, $errors] = validateMeasurement(['date_mesure' => '2026-11-08', 'poids' => '72,5', 'taille' => '175', 'duree_seance' => '45'], $this->now());

        $this->assertSame([], $errors);
        $this->assertSame(['date_mesure' => '2026-11-08', 'poids' => 72.5, 'taille' => 175.0, 'duree_seance' => 45], $data);
    }

    public function testMesuresHorsFourchette(): void
    {
        $errors = validateMeasurement(['date_mesure' => '2027-01-01', 'poids' => '-5', 'taille' => '300', 'duree_seance' => '12.5'], $this->now())[1];

        $this->assertEqualsCanonicalizing(['date_mesure', 'poids', 'taille', 'duree_seance'], array_keys($errors));
        $this->assertArrayHasKey('poids', validateMeasurement(['poids' => 'abc'], $this->now())[1]);
        $this->assertArrayHasKey('poids', validateMeasurement(['poids' => '19'], $this->now())[1]);
    }

    public function testAjoutModificationSuppressionDUneMesure(): void
    {
        $data = ['date_mesure' => '2026-11-08', 'poids' => 72.5, 'taille' => 175.0, 'duree_seance' => 45];
        $id   = createMeasurement($this->db, $this->alex, $data);
        createMeasurement($this->db, $this->alex, ['date_mesure' => '2026-11-01'] + $data);

        $measures = getMeasurements($this->db, $this->alex);
        $this->assertSame(['2026-11-01', '2026-11-08'], array_column($measures, 'date_mesure'));
        $this->assertSame(23.7, $measures[1]['imc']);

        $this->assertTrue(updateMeasurement($this->db, $id, $this->alex, ['poids' => 70.0] + $data));
        $this->assertSame(70.0, getMeasurements($this->db, $this->alex)[1]['poids']);

        // Un autre adhérent ne peut ni modifier ni supprimer
        $this->assertFalse(updateMeasurement($this->db, $id, $this->sam, $data));
        $this->assertFalse(deleteMeasurement($this->db, $id, $this->sam));
        $this->assertSame([], getMeasurements($this->db, $this->sam));

        $this->assertTrue(deleteMeasurement($this->db, $id, $this->alex));
        $this->assertCount(1, getMeasurements($this->db, $this->alex));
    }

    // ---------- Profil coach (US12) ----------

    public function testPresentsSiValideEtPassagePendantLeCreneau(): void
    {
        $this->book($this->alex, 1, 'confirmee');
        $this->book($this->sam, 1, 'confirmee');
        $absent = $this->member('Absent');
        $this->book($absent, 1, 'confirmee');
        $waiting = $this->member('Attente');
        $this->book($waiting, 1, 'en_attente');
        // Séance 1 : 17:00-18:00 UTC
        $this->passage($this->alex, '2026-11-02 16:50:00+00', '2026-11-02 18:10:00+00');
        $this->passage($this->sam, '2026-11-02 16:55:00+00', null);       // sans sortie mais arrivé juste avant
        $this->passage($absent, '2026-11-02 10:00:00+00', '2026-11-02 11:00:00+00'); // venu le matin
        $this->passage($waiting, '2026-11-02 17:00:00+00', '2026-11-02 18:00:00+00'); // pas validé

        $sessions = array_column(getCoachSessions($this->db, 1, parsePeriod([]), $this->now()), null, 'id_cours');

        $this->assertSame(3, $sessions[1]['valides']);
        $this->assertSame(2, $sessions[1]['presents']);
        $this->assertSame('realisee', $sessions[1]['statut']);
        $this->assertNull($sessions[2]['presents']);
        $this->assertSame('a_venir', $sessions[2]['statut']);
        // Seulement les séances du coach
        $this->assertArrayNotHasKey(3, $sessions);
    }

    public function testResumeEtCourbeDesPresents(): void
    {
        $sessions = [
            ['statut' => 'realisee', 'presents' => 3, 'valides' => 4, 'debut' => new DateTimeImmutable('2026-11-02 18:00')],
            ['statut' => 'realisee', 'presents' => 1, 'valides' => 4, 'debut' => new DateTimeImmutable('2026-11-04 18:00')],
            ['statut' => 'realisee', 'presents' => 2, 'valides' => 2, 'debut' => new DateTimeImmutable('2026-10-20 18:00')],
            ['statut' => 'a_venir', 'presents' => null, 'valides' => 5, 'debut' => new DateTimeImmutable('2026-11-12 18:00')],
        ];

        $this->assertSame(['seances_donnees' => 3, 'venues' => 6, 'valides' => 10, 'taux_presence' => 60], coachProfileSummary($sessions));
        $this->assertSame(
            [['label' => 'Sem. du 19/10', 'presents' => 2], ['label' => 'Sem. du 02/11', 'presents' => 4]],
            presenceTimeline($sessions, 'semaine')
        );
        $this->assertSame(
            [['label' => 'oct. 2026', 'presents' => 2], ['label' => 'nov. 2026', 'presents' => 4]],
            presenceTimeline($sessions, 'mois')
        );
        $this->assertNull(coachProfileSummary([])['taux_presence']);
    }

    public function testSeancesAnnuleesDuCoach(): void
    {
        $course = getCourseForHistory($this->db, 2);
        logSessionEvent($this->db, 'annulation', 'Coach malade', $course);
        logSessionEvent($this->db, 'annulation', 'Coach malade', $course, ['id_adherent' => $this->alex, 'nom_adherent' => 'Alex']);

        $cancelled = getCoachCancelledSessions($this->db, 1, parsePeriod([]));

        // Une seule ligne par séance (pas celles des inscrits), seulement pour ce coach
        $this->assertCount(1, $cancelled);
        $this->assertSame('HIIT jeudi', $cancelled[0]['titre']);
        $this->assertSame('Coach malade', $cancelled[0]['motif']);
        $this->assertSame([], getCoachCancelledSessions($this->db, 2, parsePeriod([])));
    }

    public function testIncidents(): void
    {
        $this->assertSame('created', reportIncident($this->db, 1, 1, '  Tapis abîmé ', $this->now()));
        $this->assertSame('not_started', reportIncident($this->db, 1, 2, 'Trop tôt', $this->now()));
        $this->assertSame('not_found', reportIncident($this->db, 1, 3, 'Séance d\'un autre coach', $this->now()));
        $this->assertSame('description_required', reportIncident($this->db, 1, 1, '  ', $this->now()));

        $incidents = getCoachIncidents($this->db, 1, parsePeriod([]));
        $this->assertCount(1, $incidents);
        $this->assertSame('Tapis abîmé', $incidents[0]['description']);
        $this->assertSame('HIIT lundi', $incidents[0]['titre']);
        $this->assertSame([], getCoachIncidents($this->db, 2, parsePeriod([])));
    }

    // ---------- Rappel J-3 (US6) ----------

    public function testRappelSeulementAvecDesDemandesEnAttente(): void
    {
        // Séance 4 : dans 2 jours et 12 h
        $this->assertSame([], getCoursesNeedingReminder($this->db, $this->now()));

        $this->book($this->alex, 4, 'en_attente');
        $this->book($this->sam, 4, 'confirmee');

        $courses = getCoursesNeedingReminder($this->db, $this->now());
        $this->assertCount(1, $courses);
        $this->assertSame('HIIT J-3', $courses[0]['titre']);
        $this->assertSame(1, $courses[0]['nb_demandes']);
        $this->assertSame('marc@example.com', $courses[0]['coach_email']);
    }

    public function testRappelEnvoyeUneSeuleFois(): void
    {
        $this->book($this->alex, 4, 'en_attente');
        markReminderSent($this->db, 4);

        $this->assertSame([], getCoursesNeedingReminder($this->db, $this->now()));
    }

    public function testRappelHorsFenetre(): void
    {
        // Séance 2 : dans 3 jours et 5 h, hors fenêtre de 2 à 3 jours
        $this->book($this->alex, 2, 'en_attente');

        $this->assertSame([], getCoursesNeedingReminder($this->db, $this->now()));
        // Le lendemain, elle entre dans la fenêtre
        $this->assertCount(1, getCoursesNeedingReminder($this->db, $this->now()->modify('+1 day')));
    }

    public function testRappelNeTraiteAucuneDemande(): void
    {
        $this->book($this->alex, 4, 'en_attente');
        getCoursesNeedingReminder($this->db, $this->now());
        markReminderSent($this->db, 4);

        $this->assertSame('en_attente', $this->db->query("SELECT statut FROM reservations WHERE id_adherent = {$this->alex}")->fetchColumn());
    }

    public function testLienDeValidation(): void
    {
        $this->assertSame(APP_URL . '/validations.php?cours=4#cours-4', validationLink(4));
    }
}
