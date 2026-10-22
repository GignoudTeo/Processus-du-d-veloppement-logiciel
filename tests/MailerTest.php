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
}
