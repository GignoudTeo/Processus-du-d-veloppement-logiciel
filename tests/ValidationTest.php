<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    // ---------- validateProfile ----------

    public function testProfilValideSansErreur(): void
    {
        [, $errors] = validateProfile(validRegistrationInput());

        $this->assertSame([], $errors);
    }

    public function testProfilNettoieLesDonnees(): void
    {
        [$data] = validateProfile(validRegistrationInput([
            'nom'       => '  Martin  ',
            'email'     => '  Alex.Martin@Example.COM ',
            'telephone' => '06.12-34 56 78',
        ]));

        $this->assertSame('Martin', $data['nom']);
        $this->assertSame('alex.martin@example.com', $data['email']);
        $this->assertSame('0612345678', $data['telephone']);
    }

    public function testNomEtPrenomObligatoires(): void
    {
        [, $errors] = validateProfile(validRegistrationInput(['nom' => '   ', 'prenom' => '']));

        $this->assertArrayHasKey('nom', $errors);
        $this->assertArrayHasKey('prenom', $errors);
    }

    public function testNomTropLongRefuse(): void
    {
        [, $errors] = validateProfile(validRegistrationInput(['nom' => str_repeat('a', 101)]));

        $this->assertArrayHasKey('nom', $errors);
    }

    public function testNomAvecAccentsAccepteJusqua100Caracteres(): void
    {
        [, $errors] = validateProfile(validRegistrationInput(['nom' => str_repeat('é', 100)]));

        $this->assertArrayNotHasKey('nom', $errors);
    }

    public static function emailsInvalides(): array
    {
        return [
            'vide'            => [''],
            'sans arobase'    => ['alex.example.com'],
            'sans domaine'    => ['alex@'],
            'trop long'       => [str_repeat('a', 185) . '@ex.com'],
        ];
    }

    #[DataProvider('emailsInvalides')]
    public function testEmailInvalideRefuse(string $email): void
    {
        [, $errors] = validateProfile(validRegistrationInput(['email' => $email]));

        $this->assertArrayHasKey('email', $errors);
    }

    public static function telephones(): array
    {
        return [
            'français'          => ['0612345678', true],
            'international'     => ['+33612345678', true],
            'avec espaces'      => ['06 12 34 56 78', true],
            'trop court'        => ['06123', false],
            'avec des lettres'  => ['06AB345678', false],
            'trop long'         => ['+3361234567890123', false],
        ];
    }

    #[DataProvider('telephones')]
    public function testTelephone(string $phone, bool $valid): void
    {
        [, $errors] = validateProfile(validRegistrationInput(['telephone' => $phone]));

        $this->assertSame(!$valid, isset($errors['telephone']));
    }

    public static function datesDeNaissance(): array
    {
        return [
            'date normale'       => ['2000-05-15', true],
            'aujourd\'hui'       => [date('Y-m-d'), true],
            'dans le futur'      => [date('Y-m-d', strtotime('+1 day')), false],
            'avant 1900'         => ['1899-12-31', false],
            'jour inexistant'    => ['2023-02-30', false],
            'mauvais format'     => ['15/05/2000', false],
            'vide'               => ['', false],
        ];
    }

    #[DataProvider('datesDeNaissance')]
    public function testDateDeNaissance(string $date, bool $valid): void
    {
        [, $errors] = validateProfile(validRegistrationInput(['date_naissance' => $date]));

        $this->assertSame(!$valid, isset($errors['date_naissance']));
    }

    // ---------- validateNewPassword ----------

    public function testMotDePasseValide(): void
    {
        $this->assertSame([], validateNewPassword('MotDePasse123', 'MotDePasse123'));
    }

    public function testMotDePasseTropCourt(): void
    {
        $this->assertArrayHasKey('mot_de_passe', validateNewPassword('court', 'court'));
    }

    public function testMotDePasseDe8CaracteresAccepte(): void
    {
        $this->assertSame([], validateNewPassword('12345678', '12345678'));
    }

    public function testConfirmationDifferente(): void
    {
        $this->assertArrayHasKey('confirmation', validateNewPassword('MotDePasse123', 'MotDePasse124'));
    }

    // ---------- validateRegistration ----------

    public function testInscriptionValide(): void
    {
        [$data, $errors] = validateRegistration(validRegistrationInput());

        $this->assertSame([], $errors);
        $this->assertSame('MotDePasse123', $data['mot_de_passe']);
    }

    public function testInscriptionCumuleErreursProfilEtMotDePasse(): void
    {
        [, $errors] = validateRegistration(validRegistrationInput(['email' => 'faux', 'mot_de_passe' => 'court']));

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('mot_de_passe', $errors);
    }

    public function testInscriptionSansAucunChamp(): void
    {
        [, $errors] = validateRegistration([]);

        $this->assertEqualsCanonicalizing(
            ['nom', 'prenom', 'email', 'telephone', 'date_naissance', 'mot_de_passe'],
            array_keys($errors)
        );
    }

    // ---------- generateConfirmationToken ----------

    public function testJetonDeConfirmationFait64CaracteresHexadecimaux(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', generateConfirmationToken());
    }

    public function testJetonsDeConfirmationTousDifferents(): void
    {
        $this->assertNotSame(generateConfirmationToken(), generateConfirmationToken());
    }
}
