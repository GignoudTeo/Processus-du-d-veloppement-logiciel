<?php

use PHPUnit\Framework\TestCase;

class AccountsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
    }

    private function account(int $accountId): array
    {
        $statement = $this->db->prepare('SELECT * FROM comptes WHERE id_compte = :id');
        $statement->execute(['id' => $accountId]);

        return $statement->fetch();
    }

    // ---------- createAdherentAccount ----------

    public function testCreationEnregistreCompteEtProfil(): void
    {
        [$accountId] = createTestAdherent($this->db, [], false);

        $account = $this->account($accountId);
        $this->assertSame('alex.martin@example.com', $account['email']);
        $this->assertSame('adherent', $account['role']);

        $profile = getAdherentProfile($this->db, $accountId);
        $this->assertSame('Martin', $profile['nom']);
        $this->assertSame('0612345678', $profile['telephone']);
        $this->assertSame('2000-05-15', $profile['date_naissance']);
    }

    public function testCreationChiffreLeMotDePasse(): void
    {
        [$accountId] = createTestAdherent($this->db, [], false);

        $hash = $this->account($accountId)['mot_de_passe_hash'];
        $this->assertNotSame('MotDePasse123', $hash);
        $this->assertTrue(password_verify('MotDePasse123', $hash));
    }

    public function testNouveauCompteNonConfirmeAvecHashDuJeton(): void
    {
        [$accountId, $token] = createTestAdherent($this->db, [], false);

        $account = $this->account($accountId);
        $this->assertFalse((bool) $account['email_confirme']);
        $this->assertSame(hash('sha256', $token), $account['jeton_confirmation']);
    }

    public function testCreationAnnuleeSiLeProfilEchoue(): void
    {
        [$data] = validateRegistration(validRegistrationInput());
        $data['nom'] = null; // NOT NULL dans adherents : la 2e requête échoue

        try {
            createAdherentAccount($this->db, $data, generateConfirmationToken());
            $this->fail('Une exception était attendue');
        } catch (PDOException) {
            // Le compte ne doit pas rester sans profil
            $this->assertFalse(emailExists($this->db, 'alex.martin@example.com'));
        }
    }

    // ---------- confirmEmail ----------

    public function testConfirmationAvecLeBonJeton(): void
    {
        [$accountId, $token] = createTestAdherent($this->db, [], false);

        $this->assertTrue(confirmEmail($this->db, $token));

        $account = $this->account($accountId);
        $this->assertTrue((bool) $account['email_confirme']);
        $this->assertNull($account['jeton_confirmation']);
    }

    public function testConfirmationAvecUnFauxJeton(): void
    {
        createTestAdherent($this->db, [], false);

        $this->assertFalse(confirmEmail($this->db, generateConfirmationToken()));
    }

    public function testLienDeConfirmationUtilisableUneSeuleFois(): void
    {
        [, $token] = createTestAdherent($this->db, [], false);

        $this->assertTrue(confirmEmail($this->db, $token));
        $this->assertFalse(confirmEmail($this->db, $token));
    }

    // ---------- emailExists ----------

    public function testEmailExiste(): void
    {
        createTestAdherent($this->db);

        $this->assertTrue(emailExists($this->db, 'alex.martin@example.com'));
        $this->assertFalse(emailExists($this->db, 'autre@example.com'));
    }

    public function testEmailExisteIgnoreSonPropreCompte(): void
    {
        [$accountId] = createTestAdherent($this->db);

        $this->assertFalse(emailExists($this->db, 'alex.martin@example.com', $accountId));
    }

    // ---------- getAdherentProfile ----------

    public function testProfilInexistant(): void
    {
        $this->assertNull(getAdherentProfile($this->db, 999));
    }

    public function testProfilNullPourUnCompteSansFicheAdherent(): void
    {
        $this->db->exec("INSERT INTO comptes (email, mot_de_passe_hash, role) VALUES ('admin@example.com', 'x', 'admin')");

        $this->assertNull(getAdherentProfile($this->db, (int) $this->db->lastInsertId()));
    }

    // ---------- administration des comptes ----------

    public function testListeDesComptesAdminAvecLeurProfilEtLeurRole(): void
    {
        [$accountId] = createTestAdherent($this->db);
        $accounts = getAccountsForAdmin($this->db);

        $this->assertCount(1, $accounts);
        $this->assertSame($accountId, (int) $accounts[0]['id_compte']);
        $this->assertSame('Alex', $accounts[0]['prenom']);
        $this->assertSame('Martin', $accounts[0]['nom']);
        $this->assertSame('adherent', $accounts[0]['role']);
    }

    public function testRechercheAdminFiltreParNomPrenomEtEmailSansTenirCompteDeLaCasse(): void
    {
        [$alexId] = createTestAdherent($this->db);
        createTestAdherent($this->db, ['nom' => 'Durand', 'prenom' => 'Camille', 'email' => 'camille@example.com']);

        $this->assertSame([$alexId], array_column(getAccountsForAdmin($this->db, 'MART'), 'id_compte'));
        $this->assertCount(1, getAccountsForAdmin($this->db, 'camille@EXAMPLE'));
        $this->assertCount(0, getAccountsForAdmin($this->db, 'inconnu'));
    }

    public function testRecuperationDesInformationsPourLaNotificationDeChangementDeRole(): void
    {
        [$accountId] = createTestAdherent($this->db);
        $details = getAccountNotificationDetails($this->db, $accountId);

        $this->assertSame('alex.martin@example.com', $details['email']);
        $this->assertSame('adherent', $details['role']);
        $this->assertSame('Alex', $details['prenom']);
        $this->assertNull(getAccountNotificationDetails($this->db, 999));
    }

    public function testChangementDuRoleCreeProfilEtChangeLeRole(): void
    {
        [$accountId] = createTestAdherent($this->db);

        $this->assertSame('changed', changeAccountRole($this->db, $accountId, 'coach', 999));
        $this->assertSame('coach', $this->account($accountId)['role']);

        $statement = $this->db->prepare('SELECT nom, prenom FROM coachs WHERE id_compte = :id');
        $statement->execute(['id' => $accountId]);
        $this->assertSame(['nom' => 'Martin', 'prenom' => 'Alex'], $statement->fetch());
    }

    public function testChangementDeCoachVersAdherentCreeLeProfilAdherent(): void
    {
        [$accountId] = createTestAdherent($this->db);
        $this->assertSame('changed', changeAccountRole($this->db, $accountId, 'coach', 999));
        $this->assertSame('changed', changeAccountRole($this->db, $accountId, 'adherent', 999));

        $this->assertSame('adherent', $this->account($accountId)['role']);
        $this->assertSame('Alex', getAdherentProfile($this->db, $accountId)['prenom']);
    }

    public function testChangementVersAdminCreeLeProfilAdmin(): void
    {
        [$accountId] = createTestAdherent($this->db);

        $this->assertSame('changed', changeAccountRole($this->db, $accountId, 'admin', 999));
        $this->assertSame('admin', $this->account($accountId)['role']);

        $statement = $this->db->prepare('SELECT nom, prenom FROM admins WHERE id_compte = :id');
        $statement->execute(['id' => $accountId]);
        $this->assertSame(['nom' => 'Martin', 'prenom' => 'Alex'], $statement->fetch());
    }

    public function testAdministrateurPeutChangerUnAutreAdminDeRole(): void
    {
        [$accountId] = createTestAdherent($this->db);
        $this->db->exec("INSERT INTO comptes (email, mot_de_passe_hash, role) VALUES ('autre-admin@example.com', 'x', 'admin')");
        $otherAdminId = (int) $this->db->lastInsertId();
        $this->db->exec("INSERT INTO admins (id_compte, nom, prenom) VALUES ($otherAdminId, 'Admin', 'Principal')");

        $this->assertSame('changed', changeAccountRole($this->db, $accountId, 'admin', $otherAdminId));
        $this->assertSame('changed', changeAccountRole($this->db, $accountId, 'coach', $otherAdminId));
        $this->assertSame('coach', $this->account($accountId)['role']);
        $this->assertSame('admin', $this->account($otherAdminId)['role']);
    }

    public function testChangementRefuseLaModificationDeSonPropreRole(): void
    {
        [$accountId] = createTestAdherent($this->db);

        $this->assertSame('self_change_forbidden', changeAccountRole($this->db, $accountId, 'coach', $accountId));
        $this->assertSame('adherent', $this->account($accountId)['role']);
    }

    public function testDernierAdministrateurNePeutPasEtreRetrograde(): void
    {
        $this->db->exec("INSERT INTO comptes (email, mot_de_passe_hash, role) VALUES ('admin@example.com', 'x', 'admin')");
        $accountId = (int) $this->db->lastInsertId();
        $this->db->exec("INSERT INTO admins (id_compte, nom, prenom) VALUES ($accountId, 'Dupont', 'Super')");

        $this->assertSame('last_admin', changeAccountRole($this->db, $accountId, 'adherent', 999));
        $this->assertSame('admin', $this->account($accountId)['role']);
    }

    // ---------- updateAdherentProfile ----------

    public function testModificationDuProfil(): void
    {
        [$accountId] = createTestAdherent($this->db);
        [$data] = validateProfile(validRegistrationInput([
            'prenom'    => 'Sam',
            'email'     => 'sam@example.com',
            'telephone' => '0700000000',
        ]));

        updateAdherentProfile($this->db, $accountId, $data);

        $profile = getAdherentProfile($this->db, $accountId);
        $this->assertSame('Sam', $profile['prenom']);
        $this->assertSame('sam@example.com', $profile['email']);
        $this->assertSame('0700000000', $profile['telephone']);
    }

    public function testModificationNeToucheQueSonCompte(): void
    {
        [$alexId] = createTestAdherent($this->db);
        [$samId]  = createTestAdherent($this->db, ['prenom' => 'Sam', 'email' => 'sam@example.com']);

        [$data] = validateProfile(validRegistrationInput(['prenom' => 'Alexandre']));
        updateAdherentProfile($this->db, $alexId, $data);

        $this->assertSame('Sam', getAdherentProfile($this->db, $samId)['prenom']);
    }

    public function testModificationAnnuleeSiEmailDejaPris(): void
    {
        [$alexId] = createTestAdherent($this->db);
        createTestAdherent($this->db, ['email' => 'sam@example.com']);

        [$data] = validateProfile(validRegistrationInput(['prenom' => 'Nouveau', 'email' => 'sam@example.com']));

        try {
            updateAdherentProfile($this->db, $alexId, $data);
            $this->fail('Une exception était attendue');
        } catch (PDOException) {
            // Rien ne doit avoir changé, même pas le prénom
            $this->assertSame('Alex', getAdherentProfile($this->db, $alexId)['prenom']);
        }
    }

    // ---------- checkPassword / updatePassword ----------

    public function testVerificationDuMotDePasse(): void
    {
        [$accountId] = createTestAdherent($this->db);

        $this->assertTrue(checkPassword($this->db, $accountId, 'MotDePasse123'));
        $this->assertFalse(checkPassword($this->db, $accountId, 'Faux'));
    }

    public function testVerificationMotDePasseCompteInexistant(): void
    {
        $this->assertFalse(checkPassword($this->db, 999, 'MotDePasse123'));
    }

    public function testChangementDeMotDePasse(): void
    {
        [$accountId] = createTestAdherent($this->db);

        updatePassword($this->db, $accountId, 'NouveauMdp456');

        $this->assertTrue(checkPassword($this->db, $accountId, 'NouveauMdp456'));
        $this->assertFalse(checkPassword($this->db, $accountId, 'MotDePasse123'));
    }

    // ---------- deleteAccount ----------

    public function testSuppressionDuCompteEtDuProfil(): void
    {
        [$accountId] = createTestAdherent($this->db);

        deleteAccount($this->db, $accountId);

        $this->assertFalse(emailExists($this->db, 'alex.martin@example.com'));
        $this->assertNull(getAdherentProfile($this->db, $accountId));
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM adherents')->fetchColumn());
    }

    public function testSuppressionNeToucheQueSonCompte(): void
    {
        [$alexId] = createTestAdherent($this->db);
        createTestAdherent($this->db, ['email' => 'sam@example.com']);

        deleteAccount($this->db, $alexId);

        $this->assertTrue(emailExists($this->db, 'sam@example.com'));
    }
}
