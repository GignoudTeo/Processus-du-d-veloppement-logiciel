<?php

use PHPUnit\Framework\TestCase;

// Validation des demandes par le coach (US4), refus groupé (US5), motifs et historique
class CoachValidationTest extends TestCase
{
    private const NOW = '2026-11-09 12:00:00+00';

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'HIIT')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role) VALUES
            (101, 'marc@example.com', 'x', 'coach'), (102, 'lea@example.com', 'x', 'coach')");
        $this->db->exec("INSERT INTO coachs (id_coach, id_compte, nom, prenom) VALUES (1, 101, 'Martin', 'Marc'), (2, 102, 'Durand', 'Léa')");
        $this->db->exec("INSERT INTO salles (id_salle, nom_salle, capacite) VALUES (1, 'Studio 1', 10)");
        // Séance 1 : 2 places, coach 1. Séance 2 : coach 2. Séance 3 : terminée
        $this->db->exec("INSERT INTO cours (id_cours, titre, id_sport, id_coach, id_salle, date_debut, date_fin, nb_places_max) VALUES
            (1, 'HIIT 18h', 1, 1, 1, '2026-11-12 17:00:00+00', '2026-11-12 18:00:00+00', 2),
            (2, 'Yoga', 1, 2, 1, '2026-11-12 17:00:00+00', '2026-11-12 18:00:00+00', 2),
            (3, 'Passé', 1, 1, 1, '2026-11-01 17:00:00+00', '2026-11-01 18:00:00+00', 2)");
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    // Crée un adhérent avec une réservation et renvoie id_reservation
    private function booking(int $courseId, string $firstname, string $status = 'en_attente'): int
    {
        [$accountId] = createTestAdherent($this->db, ['email' => uniqid('', true) . '@example.com', 'prenom' => $firstname, 'date_naissance' => '2000-05-15']);
        $adherentId  = getAdherentIdByAccount($this->db, $accountId);
        $this->db->prepare('INSERT INTO reservations (id_cours, id_adherent, statut) VALUES (?, ?, ?)')->execute([$courseId, $adherentId, $status]);

        return (int) $this->db->lastInsertId();
    }

    private function statusOf(int $reservationId): string
    {
        return $this->db->query("SELECT statut FROM reservations WHERE id_reservation = $reservationId")->fetchColumn();
    }

    // ---------- validateReason ----------

    public function testMotifDeLaListe(): void
    {
        $this->assertSame(['motif' => 'Séance complète', 'error' => null], validateReason('Séance complète', '', REFUSAL_REASONS));
    }

    public function testMotifAutre(): void
    {
        $this->assertSame(['motif' => 'Tenue inadaptée', 'error' => null], validateReason(REASON_OTHER, '  Tenue inadaptée ', REFUSAL_REASONS));
    }

    public function testMotifObligatoire(): void
    {
        $this->assertNotNull(validateReason('', '', REFUSAL_REASONS)['error']);
        $this->assertNotNull(validateReason('Motif inventé', '', REFUSAL_REASONS)['error']);
        $this->assertNotNull(validateReason(REASON_OTHER, '   ', REFUSAL_REASONS)['error']);
        $this->assertNotNull(validateReason(REASON_OTHER, str_repeat('a', REASON_MAX + 1), REFUSAL_REASONS)['error']);
    }

    // ---------- getCoachBookingOverview ----------

    public function testVueDesDemandesDuCoach(): void
    {
        $this->booking(1, 'Alex');
        $this->booking(1, 'Sam', 'confirmee');
        $this->booking(1, 'Zoé', 'liste_attente');
        $this->booking(1, 'Refusé', 'rejetee');
        $this->booking(2, 'Autre coach');

        $courses = getCoachBookingOverview($this->db, 1, $this->now());

        // Seulement les séances non terminées du coach
        $this->assertSame([1], array_map('intval', array_column($courses, 'id_cours')));
        $this->assertSame(['Alex'], array_column($courses[0]['demandes'], 'prenom'));
        $this->assertSame(['Sam'], array_column($courses[0]['valides'], 'prenom'));
        $this->assertSame(['Zoé'], array_column($courses[0]['liste_attente'], 'prenom'));
        $this->assertFalse($courses[0]['complet']);
    }

    public function testProfilDeLAdherentDansLaDemande(): void
    {
        $this->booking(1, 'Alex');

        $request = getCoachBookingOverview($this->db, 1, $this->now())[0]['demandes'][0];

        $this->assertSame(26, $request['age']);
        $this->assertStringEndsWith('@example.com', $request['email']);
        $this->assertArrayHasKey('nb_absences', $request);
    }

    public function testNombreDeDemandesEnAttente(): void
    {
        $this->booking(1, 'Alex');
        $this->booking(1, 'Sam');
        $this->booking(1, 'Zoé', 'confirmee');
        $this->booking(2, 'Autre coach');

        $this->assertSame(2, countCoachPendingRequests($this->db, 1, $this->now()));
    }

    // ---------- Administrateur : mêmes droits qu'un coach, sur toutes les séances (coach null) ----------

    public function testUnAdministrateurVoitLesDemandesDeToutesLesSeances(): void
    {
        $this->booking(1, 'Alex');
        $this->booking(2, 'Sam');

        $courses = getCoachBookingOverview($this->db, null, $this->now());

        $this->assertSame([1, 2], array_map('intval', array_column($courses, 'id_cours')));
        $this->assertSame('Léa Durand', $courses[1]['nom_coach']);
        $this->assertSame(2, countCoachPendingRequests($this->db, null, $this->now()));
    }

    public function testUnAdministrateurValideEtRefuseSurNImporteQuelleSeance(): void
    {
        $validated = $this->booking(2, 'Alex');
        $refused   = $this->booking(2, 'Sam');

        $this->assertSame('validated', validateBooking($this->db, $validated, null, $this->now())['status']);
        $this->assertSame('refused', refuseBooking($this->db, $refused, null, 'Profil incomplet')['status']);
    }

    // ---------- validateBooking ----------

    public function testValidation(): void
    {
        $id = $this->booking(1, 'Alex');

        $this->assertSame('validated', validateBooking($this->db, $id, 1, $this->now())['status']);
        $this->assertSame('confirmee', $this->statusOf($id));
    }

    public function testValidationLimiteeALaCapacite(): void
    {
        $this->booking(1, 'Sam', 'confirmee');
        $this->booking(1, 'Léa', 'confirmee');
        $id = $this->booking(1, 'Alex');

        $this->assertSame('full', validateBooking($this->db, $id, 1, $this->now())['status']);
        $this->assertSame('en_attente', $this->statusOf($id));
    }

    public function testValidationDUneSeanceDUnAutreCoach(): void
    {
        $id = $this->booking(2, 'Alex');

        $this->assertSame('not_found', validateBooking($this->db, $id, 1, $this->now())['status']);
        $this->assertSame('en_attente', $this->statusOf($id));
    }

    public function testValidationDUneDemandeDejaTraitee(): void
    {
        $id = $this->booking(1, 'Alex', 'rejetee');

        $this->assertSame('not_pending', validateBooking($this->db, $id, 1, $this->now())['status']);
    }

    public function testDemandeNonTraiteeResteEnAttenteEtTraitableJusquALaFin(): void
    {
        $id = $this->booking(1, 'Alex');

        // Pendant la séance : encore possible
        $this->assertSame('validated', validateBooking($this->db, $id, 1, new DateTimeImmutable('2026-11-12 17:30:00+00'))['status']);

        $late = $this->booking(1, 'Sam');
        $this->assertSame('ended', validateBooking($this->db, $late, 1, new DateTimeImmutable('2026-11-12 18:00:00+00'))['status']);
        $this->assertSame('en_attente', $this->statusOf($late));
    }

    // ---------- refuseBooking ----------

    public function testRefusAvecMotifEnregistreDansLHistorique(): void
    {
        $id = $this->booking(1, 'Alex');

        $result = refuseBooking($this->db, $id, 1, 'Niveau non adapté à la séance');

        $this->assertSame('refused', $result['status']);
        $this->assertSame('Alex', $result['booking']['prenom']);
        $row = $this->db->query("SELECT statut, motif FROM reservations WHERE id_reservation = $id")->fetch();
        $this->assertSame(['statut' => 'rejetee', 'motif' => 'Niveau non adapté à la séance'], $row);

        $event = $this->db->query('SELECT * FROM historique_seances')->fetch();
        $this->assertSame('refus', $event['type']);
        $this->assertSame('Niveau non adapté à la séance', $event['motif']);
        $this->assertSame('Marc Martin', $event['nom_coach']);
        $this->assertSame('Alex Martin', $event['nom_adherent']);
    }

    public function testRefusSansMotifImpossible(): void
    {
        $id = $this->booking(1, 'Alex');

        $this->assertSame('reason_required', refuseBooking($this->db, $id, 1, '  ')['status']);
        $this->assertSame('en_attente', $this->statusOf($id));
    }

    public function testRefusDUneSeanceDUnAutreCoach(): void
    {
        $id = $this->booking(2, 'Alex');

        $this->assertSame('not_found', refuseBooking($this->db, $id, 1, 'Profil incomplet')['status']);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM historique_seances')->fetchColumn());
    }

    public function testRefusDUnValideImpossible(): void
    {
        $id = $this->booking(1, 'Alex', 'confirmee');

        $this->assertSame('not_pending', refuseBooking($this->db, $id, 1, 'Profil incomplet')['status']);
        $this->assertSame('confirmee', $this->statusOf($id));
    }

    // ---------- refuseAllPending (US5) ----------

    public function testRefusGroupeSeulementSiComplet(): void
    {
        $this->booking(1, 'Sam', 'confirmee');
        $pending = $this->booking(1, 'Alex');

        $this->assertSame('not_full', refuseAllPending($this->db, 1, 1, 'Séance complète')['status']);
        $this->assertSame('en_attente', $this->statusOf($pending));
    }

    public function testRefusGroupeRefuseChaqueDemandeAPart(): void
    {
        $this->booking(1, 'Sam', 'confirmee');
        $this->booking(1, 'Léa', 'confirmee');
        $alex    = $this->booking(1, 'Alex');
        $zoe     = $this->booking(1, 'Zoé');
        $waiting = $this->booking(1, 'Attente', 'liste_attente');

        $result = refuseAllPending($this->db, 1, 1, 'Séance complète');

        $this->assertSame('refused', $result['status']);
        $this->assertSame(['Alex', 'Zoé'], array_column($result['refused'], 'prenom'));
        $this->assertSame('rejetee', $this->statusOf($alex));
        $this->assertSame('rejetee', $this->statusOf($zoe));
        // La liste d'attente n'est pas touchée
        $this->assertSame('liste_attente', $this->statusOf($waiting));
        // Un refus par adhérent dans l'historique (pour les statistiques)
        $this->assertSame(2, (int) $this->db->query("SELECT COUNT(*) FROM historique_seances WHERE type = 'refus' AND motif = 'Séance complète'")->fetchColumn());
    }

    public function testRefusGroupeMotifObligatoireEtSeancesDuCoach(): void
    {
        $this->assertSame('reason_required', refuseAllPending($this->db, 1, 1, ' ')['status']);
        $this->assertSame('not_found', refuseAllPending($this->db, 2, 1, 'Séance complète')['status']);
    }
}
