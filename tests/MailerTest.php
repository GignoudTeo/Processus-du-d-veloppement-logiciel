<?php

use PHPUnit\Framework\TestCase;

// Seul le contenu du mail est testé : sendConfirmationMail() enverrait un vrai mail via Gmail
class MailerTest extends TestCase
{
    private const LINK = 'http://localhost:8082/confirmation.php?jeton=abc123';

    public function testMailDeRetraitDUneSeance(): void
    {
        $mail = buildParticipantRemovedMail('Alex', 'HIIT du soir', 'Mardi 10 novembre 2026 à 18:00');

        $this->assertStringContainsString('retiré', $mail['subject']);
        foreach (['html', 'text'] as $format) {
            $this->assertStringContainsString('Bonjour Alex', $mail[$format]);
            $this->assertStringContainsString('HIIT du soir', $mail[$format]);
            $this->assertStringContainsString('Mardi 10 novembre 2026 à 18:00', $mail[$format]);
        }
    }

    public function testMailDeRetraitEchappeLeHtml(): void
    {
        $mail = buildParticipantRemovedMail('<b>Alex</b>', '<script>x</script>', '<i>demain</i>');

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringNotContainsString('<b>Alex', $mail['html']);
        $this->assertStringNotContainsString('<i>demain', $mail['html']);
    }

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
        $mail = buildRoleChangeMail('Alex', 'adherent', 'admin');

        $this->assertStringContainsString('Modification du rôle', $mail['subject']);
        $this->assertStringContainsString('Adhérent', $mail['html']);
        $this->assertStringContainsString('Admin', $mail['html']);
        $this->assertStringContainsString('Adhérent devient Admin', $mail['text']);
    }

    public function testNomEtRolesSontEchappesDansLeMailDeChangement(): void
    {
        $mail = buildRoleChangeMail('<script>alert(1)</script>', 'adherent', '<b>admin</b>');

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;', $mail['html']);
        $this->assertStringContainsString('&lt;b&gt;admin&lt;/b&gt;', $mail['html']);
    }

    // ---------- Choix du mail selon le changement de rôle ----------

    public function testDevenirCoachEnvoieLeMailDeBienvenue(): void
    {
        $this->assertSame(buildCoachWelcomeMail('Alex'), buildRoleChangeMail('Alex', 'adherent', 'coach'));
        $this->assertSame(buildCoachWelcomeMail('Alex'), buildRoleChangeMail('Alex', 'admin', 'coach'));
    }

    public function testCoachQuiRedevientAdherentRecoitLeMailDeFin(): void
    {
        $this->assertSame(buildCoachToMemberMail('Alex'), buildRoleChangeMail('Alex', 'coach', 'adherent'));
    }

    public function testAutresChangementsGardentLeMailGenerique(): void
    {
        $this->assertStringContainsString('Modification du rôle', buildRoleChangeMail('Alex', 'coach', 'admin')['subject']);
        $this->assertStringContainsString('Modification du rôle', buildRoleChangeMail('Alex', 'admin', 'adherent')['subject']);
    }

    // ---------- Mail de bienvenue coach ----------

    public function testBienvenueCoachFelicite(): void
    {
        $mail = buildCoachWelcomeMail('Alex');

        $this->assertStringContainsString('Félicitations', $mail['subject']);
        $this->assertStringContainsString('Félicitations Alex', $mail['html']);
        $this->assertStringContainsString('Félicitations Alex', $mail['text']);
    }

    public function testBienvenueCoachContientToutesLesRegles(): void
    {
        $mail = buildCoachWelcomeMail('Alex');

        foreach (COACH_CHARTER as $rule => $description) {
            $this->assertStringContainsString($rule, $mail['html']);
            $this->assertStringContainsString($description, $mail['text']);
        }
    }

    public function testBienvenueCoachContientLesBoutons(): void
    {
        $mail = buildCoachWelcomeMail('Alex');

        $this->assertStringContainsString('href="' . APP_URL . '/planning.php"', $mail['html']);
        $this->assertStringContainsString('href="' . APP_URL . '/compte.php"', $mail['html']);
        $this->assertStringContainsString(APP_URL . '/planning.php', $mail['text']);
    }

    public function testBienvenueCoachEchappeLePrenom(): void
    {
        $mail = buildCoachWelcomeMail('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $mail['html']);
    }

    // ---------- Mail coach -> adhérent ----------

    public function testFinDeCoachExpliqueLeChangement(): void
    {
        $mail = buildCoachToMemberMail('Alex');

        $this->assertStringContainsString('coach', $mail['subject']);
        $this->assertStringContainsString('Bonjour Alex', $mail['html']);
        $this->assertStringContainsString('Ce qui change', $mail['html']);
        $this->assertStringContainsString('Ce qui ne change pas', $mail['html']);
        $this->assertStringContainsString('CE QUI NE CHANGE PAS', $mail['text']);
    }

    public function testFinDeCoachContientLesBoutons(): void
    {
        $mail = buildCoachToMemberMail('Alex');

        $this->assertStringContainsString('href="' . APP_URL . '/qrcode.php"', $mail['html']);
        $this->assertStringContainsString('href="' . APP_URL . '/planning.php"', $mail['html']);
    }

    public function testFinDeCoachEchappeLePrenom(): void
    {
        $mail = buildCoachToMemberMail('<b>Alex</b>');

        $this->assertStringContainsString('&lt;b&gt;Alex&lt;/b&gt;', $mail['html']);
    }

    // ---------- mailButton ----------

    public function testBoutonEchappeLibelleEtLien(): void
    {
        $button = mailButton('<Voir>', 'http://localhost/?a=1&b=2');

        $this->assertStringContainsString('href="http://localhost/?a=1&amp;b=2"', $button);
        $this->assertStringContainsString('&lt;Voir&gt;', $button);
    }

    // ---------- Refus d'une demande pour devenir coach ----------

    public function testRefusDeDemandeAvecMotif(): void
    {
        $mail = buildCoachRequestRefusedMail('Alex', "Pas assez de\ndisponibilités");

        $this->assertStringContainsString('devenir coach', $mail['subject']);
        $this->assertStringContainsString('Bonjour Alex', $mail['html']);
        $this->assertStringContainsString("Pas assez de<br />\ndisponibilités", $mail['html']);
        $this->assertStringContainsString('Raison du refus', $mail['html']);
        $this->assertStringContainsString("Pas assez de\ndisponibilités", $mail['text']);
        $this->assertStringContainsString('href="' . APP_URL . '/devenir-coach.php"', $mail['html']);
    }

    public function testRefusDeDemandeSansMotif(): void
    {
        $mail = buildCoachRequestRefusedMail('Alex');

        $this->assertStringNotContainsString('Raison du refus', $mail['html']);
        $this->assertStringNotContainsString('Raison du refus', $mail['text']);
    }

    public function testRefusDeDemandeEchappeLeMotif(): void
    {
        $mail = buildCoachRequestRefusedMail('Alex', '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $mail['html']);
    }
}
