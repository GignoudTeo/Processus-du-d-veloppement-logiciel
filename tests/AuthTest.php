<?php

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        startSession();
        $_SESSION = [];
    }

    // ---------- startSession ----------

    public function testSessionDemarree(): void
    {
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testDemarrerDeuxFoisNeChangePasLaSession(): void
    {
        $id = session_id();
        startSession();

        $this->assertSame($id, session_id());
    }

    // ---------- csrfToken / isValidCsrf ----------

    public function testJetonCsrfStableDansLaSession(): void
    {
        $token = csrfToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, csrfToken());
    }

    public function testJetonCsrfValide(): void
    {
        $this->assertTrue(isValidCsrf(csrfToken()));
    }

    public function testJetonCsrfInvalide(): void
    {
        csrfToken();

        $this->assertFalse(isValidCsrf('faux'));
        $this->assertFalse(isValidCsrf(''));
        $this->assertFalse(isValidCsrf(null));
    }

    // ---------- setFlash / takeFlash ----------

    public function testMessageFlashLuUneSeuleFois(): void
    {
        setFlash('Compte supprimé');

        $this->assertSame('Compte supprimé', takeFlash());
        $this->assertNull(takeFlash());
    }

    public function testPasDeMessageFlash(): void
    {
        $this->assertNull(takeFlash());
    }

    // ---------- login ----------

    public function testConnexionReussie(): void
    {
        [$accountId] = createTestAdherent($this->db);

        $this->assertSame(LOGIN_OK, login($this->db, 'alex.martin@example.com', 'MotDePasse123'));
        $this->assertSame([
            'id_compte' => $accountId,
            'email'     => 'alex.martin@example.com',
            'role'      => 'adherent',
            'prenom'    => 'Alex',
        ], currentUser());
    }

    public function testConnexionIgnoreMajusculesEtEspacesDeLEmail(): void
    {
        createTestAdherent($this->db);

        $this->assertSame(LOGIN_OK, login($this->db, '  Alex.Martin@EXAMPLE.com ', 'MotDePasse123'));
    }

    public function testConnexionMauvaisMotDePasse(): void
    {
        createTestAdherent($this->db);

        $this->assertSame(LOGIN_INVALID, login($this->db, 'alex.martin@example.com', 'Faux'));
        $this->assertNull(currentUser());
    }

    public function testConnexionEmailInconnu(): void
    {
        $this->assertSame(LOGIN_INVALID, login($this->db, 'inconnu@example.com', 'MotDePasse123'));
    }

    public function testConnexionRefuseeSiCompteNonConfirme(): void
    {
        createTestAdherent($this->db, [], false);

        $this->assertSame(LOGIN_NOT_CONFIRMED, login($this->db, 'alex.martin@example.com', 'MotDePasse123'));
        $this->assertNull(currentUser());
    }

    public function testCompteNonConfirmeAvecMauvaisMotDePasseNeRevelePasLeCompte(): void
    {
        createTestAdherent($this->db, [], false);

        $this->assertSame(LOGIN_INVALID, login($this->db, 'alex.martin@example.com', 'Faux'));
    }

    public function testConnexionChangeLIdentifiantDeSession(): void
    {
        createTestAdherent($this->db);
        $before = session_id();

        login($this->db, 'alex.martin@example.com', 'MotDePasse123');

        $this->assertNotSame($before, session_id());
    }

    public function testConnexionAdminRecupereSonPrenom(): void
    {
        $this->db->exec("INSERT INTO comptes (email, mot_de_passe_hash, role, email_confirme)
                         VALUES ('admin@example.com', '" . password_hash('Admin1234', PASSWORD_DEFAULT) . "', 'admin', TRUE)");
        $this->db->exec("INSERT INTO admins (id_compte, nom, prenom) VALUES (" . $this->db->lastInsertId() . ", 'Dupont', 'Super')");

        $this->assertSame(LOGIN_OK, login($this->db, 'admin@example.com', 'Admin1234'));
        $this->assertSame('admin', currentUser()['role']);
        $this->assertSame('Super', currentUser()['prenom']);
    }

    // ---------- currentUser / requireLogin / logout ----------

    public function testPersonneNEstConnecte(): void
    {
        $this->assertNull(currentUser());
    }

    public function testRequireLoginRenvoieLUtilisateurConnecte(): void
    {
        createTestAdherent($this->db);
        login($this->db, 'alex.martin@example.com', 'MotDePasse123');

        $this->assertSame('Alex', requireLogin()['prenom']);
    }

    public function testDeconnexion(): void
    {
        createTestAdherent($this->db);
        login($this->db, 'alex.martin@example.com', 'MotDePasse123');

        @logout(); // @ : setcookie() n'a pas de navigateur à qui répondre pendant les tests

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame([], $_SESSION);
        $this->assertNull(currentUser());
    }
}
