<?php

use PHPUnit\Framework\TestCase;

// Seul le contenu du mail est testé : sendConfirmationMail() enverrait un vrai mail via Gmail
class MailerTest extends TestCase
{
    private const LINK = 'http://localhost:8082/confirmation.php?jeton=abc123';

    public function testSujet(): void
    {
        $mail = buildConfirmationMail('Alex', self::LINK);

        $this->assertStringContainsString('Confirme ton adresse mail', $mail['subject']);
        $this->assertStringContainsString(APP_NAME, $mail['subject']);
    }

    public function testLeMailContientLeLienEtLePrenom(): void
    {
        $mail = buildConfirmationMail('Alex', self::LINK);

        $this->assertStringContainsString('href="' . self::LINK . '"', $mail['html']);
        $this->assertStringContainsString('Bonjour Alex', $mail['html']);
        $this->assertStringContainsString(self::LINK, $mail['text']);
        $this->assertStringContainsString('Bonjour Alex', $mail['text']);
    }

    public function testPrenomEchappeDansLeHtml(): void
    {
        $mail = buildConfirmationMail('<script>alert(1)</script>', self::LINK);

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;', $mail['html']);
    }

    public function testLienEchappeDansLeHtml(): void
    {
        $mail = buildConfirmationMail('Alex', 'http://localhost/?a=1&b="x"');

        $this->assertStringContainsString('a=1&amp;b=&quot;x&quot;', $mail['html']);
    }

    public function testMailDeChangementDeRoleContientLesRoles(): void
    {
        $mail = buildRoleChangeMail('Alex', 'adherent', 'coach');

        $this->assertStringContainsString('Modification du rôle', $mail['subject']);
        $this->assertStringContainsString('Adhérent', $mail['html']);
        $this->assertStringContainsString('Coach', $mail['html']);
        $this->assertStringContainsString('Adhérent devient Coach', $mail['text']);
    }

    public function testNomEtRolesSontEchappesDansLeMailDeChangement(): void
    {
        $mail = buildRoleChangeMail('<script>alert(1)</script>', 'adherent', '<b>admin</b>');

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;', $mail['html']);
        $this->assertStringContainsString('&lt;b&gt;admin&lt;/b&gt;', $mail['html']);
    }
}
