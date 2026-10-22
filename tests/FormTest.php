<?php

use PHPUnit\Framework\TestCase;

class FormTest extends TestCase
{
    public function testOldRenvoieLaValeurSaisie(): void
    {
        $this->assertSame('Alex', old(['prenom' => 'Alex'], 'prenom'));
    }

    public function testOldRenvoieVideSiLeChampNExistePas(): void
    {
        $this->assertSame('', old([], 'prenom'));
    }

    public function testOldEchappeLeHtml(): void
    {
        $this->assertSame('&lt;script&gt;', old(['nom' => '<script>'], 'nom'));
    }

    public function testFieldErrorAfficheLeMessage(): void
    {
        $this->assertSame(
            '<p class="field-error">Adresse mail invalide.</p>',
            fieldError(['email' => 'Adresse mail invalide.'], 'email')
        );
    }

    public function testFieldErrorRenvoieVideSansErreur(): void
    {
        $this->assertSame('', fieldError(['nom' => 'Erreur'], 'email'));
    }

    public function testFieldErrorEchappeLeHtml(): void
    {
        $this->assertStringContainsString('&lt;b&gt;', fieldError(['nom' => '<b>'], 'nom'));
    }
}
