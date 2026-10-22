<?php

use PHPUnit\Framework\TestCase;

class QrCodeTest extends TestCase
{
    private PDO $db;
    private int $accountId;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        [$this->accountId] = createTestAdherent($this->db);
    }

    // Jeton d'une autre minute que la minute en cours (-1 = minute précédente)
    private function tokenForWindow(int $offset): string
    {
        $window = intdiv(time(), QR_VALIDITY) + $offset;
        $hash   = getPasswordHash($this->db, $this->accountId);

        return $this->accountId . ':' . qrSignature($this->accountId, $window, $hash);
    }

    // ---------- getPasswordHash ----------

    public function testHashDuMotDePasse(): void
    {
        $this->assertTrue(password_verify('MotDePasse123', getPasswordHash($this->db, $this->accountId)));
    }

    public function testHashCompteInexistant(): void
    {
        $this->assertNull(getPasswordHash($this->db, 999));
    }

    // ---------- qrSignature ----------

    public function testSignatureToujoursLaMemePourLesMemesDonnees(): void
    {
        $this->assertSame(qrSignature(1, 100, 'hash'), qrSignature(1, 100, 'hash'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', qrSignature(1, 100, 'hash'));
    }

    public function testSignatureChangeAvecChaqueDonnee(): void
    {
        $signature = qrSignature(1, 100, 'hash');

        $this->assertNotSame($signature, qrSignature(2, 100, 'hash'));
        $this->assertNotSame($signature, qrSignature(1, 101, 'hash'));
        $this->assertNotSame($signature, qrSignature(1, 100, 'autre-hash'));
    }

    // ---------- generateAccessToken ----------

    public function testFormatDuJeton(): void
    {
        $token = generateAccessToken($this->db, $this->accountId);

        $this->assertMatchesRegularExpression('/^' . $this->accountId . ':[0-9a-f]{64}$/', $token);
    }

    public function testPasDeJetonPourUnCompteInexistant(): void
    {
        $this->assertNull(generateAccessToken($this->db, 999));
    }

    // ---------- verifyAccessToken ----------

    public function testJetonDuMomentAccepte(): void
    {
        $token = generateAccessToken($this->db, $this->accountId);

        $this->assertSame($this->accountId, verifyAccessToken($this->db, $token));
    }

    public function testJetonDeLaMinutePrecedenteAccepte(): void
    {
        $this->assertSame($this->accountId, verifyAccessToken($this->db, $this->tokenForWindow(-1)));
    }

    public function testJetonExpireRefuse(): void
    {
        $this->assertNull(verifyAccessToken($this->db, $this->tokenForWindow(-2)));
    }

    public function testJetonDuFuturRefuse(): void
    {
        $this->assertNull(verifyAccessToken($this->db, $this->tokenForWindow(2)));
    }

    public function testJetonModifieRefuse(): void
    {
        $token = generateAccessToken($this->db, $this->accountId);
        $last  = substr($token, -1);

        $this->assertNull(verifyAccessToken($this->db, substr($token, 0, -1) . ($last === '0' ? '1' : '0')));
    }

    public function testJetonDUnAutreCompteRefuse(): void
    {
        [$otherId] = createTestAdherent($this->db, ['email' => 'sam@example.com']);
        [, $signature] = explode(':', generateAccessToken($this->db, $this->accountId));

        $this->assertNull(verifyAccessToken($this->db, $otherId . ':' . $signature));
    }

    public function testJetonInvalideApresChangementDeMotDePasse(): void
    {
        $token = generateAccessToken($this->db, $this->accountId);

        updatePassword($this->db, $this->accountId, 'NouveauMdp456');

        $this->assertNull(verifyAccessToken($this->db, $token));
    }

    public function testJetonInvalideApresSuppressionDuCompte(): void
    {
        $token = generateAccessToken($this->db, $this->accountId);

        deleteAccount($this->db, $this->accountId);

        $this->assertNull(verifyAccessToken($this->db, $token));
    }

    public function testFormatsInvalidesRefuses(): void
    {
        foreach (['', 'abc', '1:', ':' . str_repeat('a', 64), '1:' . str_repeat('g', 64), 'FITPASS:1:123:abc'] as $token) {
            $this->assertNull(verifyAccessToken($this->db, $token), "Jeton accepté à tort : $token");
        }
    }
}
