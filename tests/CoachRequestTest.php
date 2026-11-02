<?php

use PHPUnit\Framework\TestCase;

class CoachRequestTest extends TestCase
{
    private PDO $db;
    private int $memberId;
    private int $adminId = 900;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES (1, 'Cardio')");
        $this->db->exec("INSERT INTO sports (id_sport, id_categorie, nom_sport) VALUES (1, 1, 'Yoga'), (2, 1, 'HIIT'), (3, 1, 'Boxe')");
        $this->db->exec("INSERT INTO comptes (id_compte, email, mot_de_passe_hash, role, email_confirme)
                         VALUES (900, 'admin@example.com', 'x', 'admin', TRUE)");
        $this->db->exec("INSERT INTO admins (id_compte, nom, prenom) VALUES (900, 'Dupont', 'Admin')");

        [$this->memberId] = createTestAdherent($this->db);
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'sports'         => ['2', '1'],
            'experience'     => '1_3_ans',
            'diplome'        => 'BPJEPS',
            'types_seances'  => 'HIIT de 45 min pour débutants',
            'disponibilites' => 'Lundi et mercredi soir',
            'motivation'     => 'Partager ma passion',
        ], $overrides);
    }

    private function createRequest(array $overrides = [], ?int $accountId = null): int
    {
        [$data] = validateCoachRequest($this->input($overrides), [1, 2, 3]);

        return createCoachRequest($this->db, $accountId ?? $this->memberId, $data);
    }

    private function role(int $accountId): string
    {
        return $this->db->query("SELECT role FROM comptes WHERE id_compte = $accountId")->fetchColumn();
    }

    // ---------- validateCoachRequest ----------

    public function testQuestionnaireValide(): void
    {
        [$data, $errors] = validateCoachRequest($this->input(), [1, 2, 3]);

        $this->assertSame([], $errors);
        $this->assertSame([2, 1], $data['sports']);
    }

    public function testChampsObligatoires(): void
    {
        [, $errors] = validateCoachRequest([], [1, 2, 3]);

        $this->assertEqualsCanonicalizing(
            ['sports', 'experience', 'types_seances', 'disponibilites', 'motivation'],
            array_keys($errors)
        );
    }

    public function testDiplomeFacultatif(): void
    {
        [, $errors] = validateCoachRequest($this->input(['diplome' => '']), [1, 2, 3]);

        $this->assertSame([], $errors);
    }

    public function testSportsInexistantsIgnores(): void
    {
        [$data] = validateCoachRequest($this->input(['sports' => ['1', '99', '1', 'abc']]), [1, 2, 3]);
        $this->assertSame([1], $data['sports']);

        [, $errors] = validateCoachRequest($this->input(['sports' => ['99']]), [1, 2, 3]);
        $this->assertArrayHasKey('sports', $errors);
    }

    public function testExperienceInconnueRefusee(): void
    {
        [, $errors] = validateCoachRequest($this->input(['experience' => '10_ans']), [1, 2, 3]);

        $this->assertArrayHasKey('experience', $errors);
    }

    public function testTexteTropLong(): void
    {
        [, $errors] = validateCoachRequest($this->input(['motivation' => str_repeat('a', COACH_REQUEST_TEXT_MAX + 1)]), [1, 2, 3]);

        $this->assertArrayHasKey('motivation', $errors);
    }

    public function testTexteVideAvecEspacesRefuse(): void
    {
        [, $errors] = validateCoachRequest($this->input(['disponibilites' => "   \n "]), [1, 2, 3]);

        $this->assertArrayHasKey('disponibilites', $errors);
    }

    // ---------- createCoachRequest / getLatestCoachRequest ----------

    public function testCreationDeLaDemande(): void
    {
        $this->createRequest();

        $request = getLatestCoachRequest($this->db, $this->memberId);
        $this->assertSame('en_attente', $request['statut']);
        $this->assertSame('HIIT de 45 min pour débutants', $request['types_seances']);
        $this->assertSame('BPJEPS', $request['diplome']);
    }

    public function testDiplomeVideEnregistreCommeNull(): void
    {
        $this->createRequest(['diplome' => '']);

        $this->assertNull(getLatestCoachRequest($this->db, $this->memberId)['diplome']);
    }

    public function testPasDeDemande(): void
    {
        $this->assertNull(getLatestCoachRequest($this->db, $this->memberId));
    }

    public function testUneSeuleDemandeEnAttente(): void
    {
        $this->createRequest();

        $this->expectException(PDOException::class);
        $this->createRequest();
    }

    public function testNouvelleDemandePossibleApresUnRefus(): void
    {
        $first = $this->createRequest();
        refuseCoachRequest($this->db, $first, $this->adminId, 'Pas de créneau libre');

        $second = $this->createRequest();

        $this->assertSame($second, (int) getLatestCoachRequest($this->db, $this->memberId)['id_demande']);
    }

    // ---------- getCoachRequests / countPendingCoachRequests ----------

    public function testListeDesDemandesEnAttenteAvecSportsEtCandidat(): void
    {
        $this->createRequest();

        $requests = getCoachRequests($this->db);

        $this->assertCount(1, $requests);
        $this->assertSame('Alex', $requests[0]['prenom']);
        $this->assertSame('alex.martin@example.com', $requests[0]['email']);
        $this->assertSame(['HIIT', 'Yoga'], $requests[0]['sports']);
    }

    public function testDemandesTraiteesSepareesDesDemandesEnAttente(): void
    {
        [$otherId] = createTestAdherent($this->db, ['email' => 'sam@example.com']);
        $refused   = $this->createRequest();
        refuseCoachRequest($this->db, $refused, $this->adminId, 'Pas de créneau libre');
        $this->createRequest([], $otherId);

        $this->assertCount(1, getCoachRequests($this->db, 'en_attente'));
        $this->assertSame('refusee', getCoachRequests($this->db, null)[0]['statut']);
        $this->assertSame(1, countPendingCoachRequests($this->db));
    }

    public function testAucuneDemande(): void
    {
        $this->assertSame([], getCoachRequests($this->db));
        $this->assertSame(0, countPendingCoachRequests($this->db));
    }

    // ---------- acceptCoachRequest ----------

    public function testAcceptationPasseLeCompteCoach(): void
    {
        $requestId = $this->createRequest();

        $this->assertSame('accepted', acceptCoachRequest($this->db, $requestId, $this->adminId));

        $this->assertSame('coach', $this->role($this->memberId));
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM coachs WHERE id_compte = {$this->memberId}")->fetchColumn());

        $request = getCoachRequest($this->db, $requestId);
        $this->assertSame('acceptee', $request['statut']);
        $this->assertSame($this->adminId, (int) $request['id_admin_traitement']);
        $this->assertNotNull($request['date_traitement']);
    }

    public function testAcceptationDUneDemandeDejaTraitee(): void
    {
        $requestId = $this->createRequest();
        refuseCoachRequest($this->db, $requestId, $this->adminId, 'Pas de créneau libre');

        $this->assertSame('already_processed', acceptCoachRequest($this->db, $requestId, $this->adminId));
        $this->assertSame('adherent', $this->role($this->memberId));
    }

    public function testAcceptationDemandeInexistante(): void
    {
        $this->assertSame('not_found', acceptCoachRequest($this->db, 999, $this->adminId));
    }

    public function testAcceptationSiLeCompteEstDejaCoach(): void
    {
        $requestId = $this->createRequest();
        changeAccountRole($this->db, $this->memberId, 'coach', $this->adminId);

        $this->assertSame('accepted', acceptCoachRequest($this->db, $requestId, $this->adminId));
        $this->assertSame('acceptee', getCoachRequest($this->db, $requestId)['statut']);
    }

    // ---------- refuseCoachRequest ----------

    public function testRefusAvecMotif(): void
    {
        $requestId = $this->createRequest();

        $this->assertSame('refused', refuseCoachRequest($this->db, $requestId, $this->adminId, '  Pas assez de disponibilités  '));

        $request = getCoachRequest($this->db, $requestId);
        $this->assertSame('refusee', $request['statut']);
        $this->assertSame('Pas assez de disponibilités', $request['motif_refus']);
        $this->assertSame('adherent', $this->role($this->memberId));
    }

    public function testRefusSansRaisonImpossible(): void
    {
        $requestId = $this->createRequest();

        $this->assertSame('reason_required', refuseCoachRequest($this->db, $requestId, $this->adminId, "  \n "));

        // La demande reste en attente
        $request = getCoachRequest($this->db, $requestId);
        $this->assertSame('en_attente', $request['statut']);
        $this->assertNull($request['motif_refus']);
    }

    public function testRefusDUneDemandeDejaTraitee(): void
    {
        $requestId = $this->createRequest();
        acceptCoachRequest($this->db, $requestId, $this->adminId);

        $this->assertSame('already_processed', refuseCoachRequest($this->db, $requestId, $this->adminId, 'Trop tard'));
        $this->assertSame('acceptee', getCoachRequest($this->db, $requestId)['statut']);
    }

    public function testSuppressionDuCompteSupprimeSesDemandes(): void
    {
        $this->createRequest();

        deleteAccount($this->db, $this->memberId);

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM demandes_coach')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM demandes_coach_sports')->fetchColumn());
    }
}
