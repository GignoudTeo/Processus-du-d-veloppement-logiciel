<?php
// Chargé avant les tests (voir phpunit.xml)

require_once __DIR__ . '/../includes/accounts.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/form.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/qrcode.php';
require_once __DIR__ . '/../includes/sports.php';

// Base SQLite en mémoire avec les mêmes tables que Supabase : les tests ne touchent jamais la vraie base
function createTestDatabase(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec("CREATE TABLE comptes (
        id_compte INTEGER PRIMARY KEY AUTOINCREMENT,
        email VARCHAR(191) NOT NULL UNIQUE,
        mot_de_passe_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'adherent',
        date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        email_confirme BOOLEAN NOT NULL DEFAULT FALSE,
        jeton_confirmation CHAR(64) DEFAULT NULL
    )");
    $db->exec('CREATE TABLE adherents (
        id_adherent INTEGER PRIMARY KEY AUTOINCREMENT,
        id_compte INTEGER NOT NULL UNIQUE REFERENCES comptes(id_compte) ON DELETE CASCADE,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        adresse VARCHAR(255) DEFAULT NULL,
        poids DECIMAL(5,2) DEFAULT NULL,
        taille DECIMAL(5,2) DEFAULT NULL,
        telephone VARCHAR(20) DEFAULT NULL,
        date_naissance DATE DEFAULT NULL
    )');
    $db->exec('CREATE TABLE coachs (
        id_coach INTEGER PRIMARY KEY AUTOINCREMENT,
        id_compte INTEGER NOT NULL UNIQUE REFERENCES comptes(id_compte) ON DELETE CASCADE,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL
    )');
    $db->exec('CREATE TABLE admins (
        id_admin INTEGER PRIMARY KEY AUTOINCREMENT,
        id_compte INTEGER NOT NULL UNIQUE REFERENCES comptes(id_compte) ON DELETE CASCADE,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL
    )');
    $db->exec('CREATE TABLE categories (
        id_categorie INTEGER PRIMARY KEY AUTOINCREMENT,
        nom_categorie VARCHAR(100) NOT NULL UNIQUE
    )');
    $db->exec('CREATE TABLE sports (
        id_sport INTEGER PRIMARY KEY AUTOINCREMENT,
        id_categorie INTEGER NOT NULL REFERENCES categories(id_categorie),
        nom_sport VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL
    )');

    return $db;
}

// Données d'inscription valides, à modifier champ par champ dans les tests
function validRegistrationInput(array $overrides = []): array
{
    return array_merge([
        'nom'            => 'Martin',
        'prenom'         => 'Alex',
        'email'          => 'alex.martin@example.com',
        'telephone'      => '06 12 34 56 78',
        'date_naissance' => '2000-05-15',
        'mot_de_passe'   => 'MotDePasse123',
        'confirmation'   => 'MotDePasse123',
    ], $overrides);
}

// Crée un adhérent et renvoie [id_compte, jeton de confirmation]
function createTestAdherent(PDO $db, array $overrides = [], bool $confirmed = true): array
{
    [$data] = validateRegistration(validRegistrationInput($overrides));
    $token     = generateConfirmationToken();
    $accountId = createAdherentAccount($db, $data, $token);

    if ($confirmed) {
        confirmEmail($db, $token);
    }

    return [$accountId, $token];
}
