<?php
// Chargé avant les tests (voir phpunit.xml)

require_once __DIR__ . '/../includes/accounts.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/coach_courses.php';
require_once __DIR__ . '/../includes/coach_profile.php';
require_once __DIR__ . '/../includes/coach_validation.php';
require_once __DIR__ . '/../includes/course_slots.php';
require_once __DIR__ . '/../includes/history.php';
require_once __DIR__ . '/../includes/member_history.php';
require_once __DIR__ . '/../includes/progression.php';
require_once __DIR__ . '/../includes/reminders.php';
require_once __DIR__ . '/../includes/statistics.php';
require_once __DIR__ . '/../includes/coach_requests.php';
require_once __DIR__ . '/../includes/form.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/planning.php';
require_once __DIR__ . '/../includes/qrcode.php';
require_once __DIR__ . '/../includes/reservations.php';
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
        date_naissance DATE DEFAULT NULL,
        nb_reservations INTEGER NOT NULL DEFAULT 0,
        nb_reservations_rejetees INTEGER NOT NULL DEFAULT 0,
        nb_absences INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE coachs (
        id_coach INTEGER PRIMARY KEY AUTOINCREMENT,
        id_compte INTEGER NOT NULL UNIQUE REFERENCES comptes(id_compte) ON DELETE CASCADE,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        specialite VARCHAR(150) DEFAULT NULL,
        bio TEXT DEFAULT NULL
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
    $db->exec('CREATE TABLE salles (
        id_salle INTEGER PRIMARY KEY AUTOINCREMENT,
        nom_salle VARCHAR(100) NOT NULL,
        capacite SMALLINT NOT NULL,
        adresse VARCHAR(255) DEFAULT NULL
    )');
    $db->exec('CREATE TABLE cours (
        id_cours INTEGER PRIMARY KEY AUTOINCREMENT,
        titre VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        id_sport INTEGER NOT NULL REFERENCES sports(id_sport),
        id_coach INTEGER NOT NULL REFERENCES coachs(id_coach),
        id_salle INTEGER NOT NULL REFERENCES salles(id_salle),
        date_debut TIMESTAMP NOT NULL,
        date_fin TIMESTAMP NOT NULL,
        nb_places_max SMALLINT NOT NULL,
        rappel_envoye_le TIMESTAMP DEFAULT NULL
    )');
    $db->exec("CREATE TABLE reservations (
        id_reservation INTEGER PRIMARY KEY AUTOINCREMENT,
        id_cours INTEGER NOT NULL REFERENCES cours(id_cours) ON DELETE CASCADE,
        id_adherent INTEGER NOT NULL REFERENCES adherents(id_adherent) ON DELETE CASCADE,
        date_reservation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        statut VARCHAR(20) NOT NULL DEFAULT 'en_attente',
        motif TEXT DEFAULT NULL,
        date_statut TIMESTAMP DEFAULT NULL,
        UNIQUE (id_cours, id_adherent)
    )");
    $db->exec("CREATE TABLE historique_seances (
        id_evenement INTEGER PRIMARY KEY AUTOINCREMENT,
        type VARCHAR(20) NOT NULL CHECK (type IN ('refus', 'desinscription', 'annulation')),
        date_evenement TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        motif TEXT NOT NULL,
        id_cours INTEGER NOT NULL,
        titre_cours VARCHAR(150) NOT NULL,
        date_cours TIMESTAMP NOT NULL,
        nom_salle VARCHAR(100) DEFAULT NULL,
        id_coach INTEGER DEFAULT NULL REFERENCES coachs(id_coach) ON DELETE SET NULL,
        nom_coach VARCHAR(201) DEFAULT NULL,
        id_adherent INTEGER DEFAULT NULL REFERENCES adherents(id_adherent) ON DELETE SET NULL,
        nom_adherent VARCHAR(201) DEFAULT NULL
    )");
    $db->exec('CREATE TABLE passages (
        id_passage INTEGER PRIMARY KEY AUTOINCREMENT,
        id_adherent INTEGER NOT NULL REFERENCES adherents(id_adherent) ON DELETE CASCADE,
        entree TIMESTAMP NOT NULL,
        sortie TIMESTAMP DEFAULT NULL CHECK (sortie IS NULL OR sortie > entree)
    )');
    $db->exec('CREATE TABLE progression (
        id_mesure INTEGER PRIMARY KEY AUTOINCREMENT,
        id_adherent INTEGER NOT NULL REFERENCES adherents(id_adherent) ON DELETE CASCADE,
        date_mesure DATE NOT NULL,
        poids DECIMAL(5,2) NOT NULL,
        taille DECIMAL(5,2) NOT NULL,
        duree_seance SMALLINT NOT NULL
    )');
    $db->exec('CREATE TABLE incidents (
        id_incident INTEGER PRIMARY KEY AUTOINCREMENT,
        id_coach INTEGER NOT NULL REFERENCES coachs(id_coach) ON DELETE CASCADE,
        id_cours INTEGER NOT NULL,
        titre_cours VARCHAR(150) NOT NULL,
        date_cours TIMESTAMP NOT NULL,
        date_signalement TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        description TEXT NOT NULL
    )');
    $db->exec("CREATE TABLE demandes_coach (
        id_demande INTEGER PRIMARY KEY AUTOINCREMENT,
        id_compte INTEGER NOT NULL REFERENCES comptes(id_compte) ON DELETE CASCADE,
        experience VARCHAR(20) NOT NULL,
        diplome VARCHAR(255) DEFAULT NULL,
        types_seances TEXT NOT NULL,
        disponibilites TEXT NOT NULL,
        motivation TEXT NOT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'en_attente' CHECK (statut IN ('en_attente', 'acceptee', 'refusee')),
        motif_refus TEXT DEFAULT NULL,
        date_demande TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_traitement TIMESTAMP DEFAULT NULL,
        id_admin_traitement INTEGER DEFAULT NULL REFERENCES comptes(id_compte) ON DELETE SET NULL
    )");
    $db->exec("CREATE UNIQUE INDEX idx_demandes_coach_une_en_attente ON demandes_coach(id_compte) WHERE statut = 'en_attente'");
    $db->exec('CREATE TABLE demandes_coach_sports (
        id_demande INTEGER NOT NULL REFERENCES demandes_coach(id_demande) ON DELETE CASCADE,
        id_sport INTEGER NOT NULL REFERENCES sports(id_sport) ON DELETE CASCADE,
        PRIMARY KEY (id_demande, id_sport)
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
