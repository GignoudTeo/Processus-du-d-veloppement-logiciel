<?php

use PHPUnit\Framework\TestCase;

class SportsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = createTestDatabase();
        $this->db->exec("INSERT INTO categories (id_categorie, nom_categorie) VALUES
            (1, 'Renforcement musculaire'),
            (2, 'Cardio Training'),
            (3, 'Bien-être')");
        $this->db->exec("INSERT INTO sports (id_categorie, nom_sport, description) VALUES
            (2, 'HIIT', 'Intervalles'),
            (1, 'Body Pump', 'Barre et poids'),
            (2, 'Cycling', NULL),
            (1, 'Crossfit', 'Fonctionnel')");
    }

    public function testCategoriesTrieesParNom(): void
    {
        $names = array_column(getCategories($this->db), 'nom_categorie');

        $this->assertSame(['Bien-être', 'Cardio Training', 'Renforcement musculaire'], $names);
    }

    public function testCategoriesVides(): void
    {
        $this->assertSame([], getCategories(createTestDatabase()));
    }

    public function testTousLesSportsTriesParCategoriePuisNom(): void
    {
        $names = array_column(getSports($this->db), 'nom_sport');

        $this->assertSame(['Cycling', 'HIIT', 'Body Pump', 'Crossfit'], $names);
    }

    public function testSportContientSaCategorie(): void
    {
        $sport = getSports($this->db, 2)[0];

        $this->assertSame('Cardio Training', $sport['nom_categorie']);
        $this->assertNull($sport['description']);
    }

    public function testFiltreParCategorie(): void
    {
        $names = array_column(getSports($this->db, 1), 'nom_sport');

        $this->assertSame(['Body Pump', 'Crossfit'], $names);
    }

    public function testCategorieSansSport(): void
    {
        $this->assertSame([], getSports($this->db, 3));
    }

    public function testCategorieInexistante(): void
    {
        $this->assertSame([], getSports($this->db, 999));
    }
}
