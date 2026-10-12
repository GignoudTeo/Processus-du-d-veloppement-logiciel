<?php
require_once __DIR__ . '/config.php';

// Renvoie [données nettoyées, erreurs par champ]
function validateRegistration(array $input): array
{
    $data = [
        'nom'            => trim($input['nom'] ?? ''),
        'prenom'         => trim($input['prenom'] ?? ''),
        'email'          => strtolower(trim($input['email'] ?? '')),
        'telephone'      => preg_replace('/[\s.\-]/', '', $input['telephone'] ?? ''),
        'date_naissance' => trim($input['date_naissance'] ?? ''),
        'mot_de_passe'   => $input['mot_de_passe'] ?? '',
        'confirmation'   => $input['confirmation'] ?? '',
    ];
    $errors = [];

    if ($data['nom'] === '' || mb_strlen($data['nom']) > 100) {
        $errors['nom'] = 'Indique ton nom.';
    }
    if ($data['prenom'] === '' || mb_strlen($data['prenom']) > 100) {
        $errors['prenom'] = 'Indique ton prénom.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 191) {
        $errors['email'] = 'Adresse mail invalide.';
    }
    if (!preg_match('/^\+?[0-9]{10,15}$/', $data['telephone'])) {
        $errors['telephone'] = 'Numéro de téléphone invalide.';
    }

    $birthdate = DateTime::createFromFormat('!Y-m-d', $data['date_naissance']);
    if (!$birthdate || $birthdate->format('Y-m-d') !== $data['date_naissance']
        || $birthdate > new DateTime('today') || $birthdate < new DateTime('1900-01-01')) {
        $errors['date_naissance'] = 'Date de naissance invalide.';
    }

    if (strlen($data['mot_de_passe']) < 8) {
        $errors['mot_de_passe'] = '8 caractères minimum.';
    } elseif ($data['mot_de_passe'] !== $data['confirmation']) {
        $errors['confirmation'] = 'Les mots de passe ne correspondent pas.';
    }

    return [$data, $errors];
}

function emailExists(PDO $db, string $email): bool
{
    $statement = $db->prepare('SELECT 1 FROM comptes WHERE email = :email');
    $statement->execute(['email' => $email]);

    return (bool) $statement->fetchColumn();
}

// Crée le compte (table comptes) et le profil adhérent (table adherents), renvoie id_compte
function createAdherentAccount(PDO $db, array $data): int
{
    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            "INSERT INTO comptes (email, mot_de_passe_hash, role)
             VALUES (:email, :hash, 'adherent')
             RETURNING id_compte"
        );
        $statement->execute([
            'email' => $data['email'],
            'hash'  => password_hash($data['mot_de_passe'], PASSWORD_DEFAULT),
        ]);
        $accountId = (int) $statement->fetchColumn();

        $statement = $db->prepare(
            'INSERT INTO adherents (id_compte, nom, prenom, telephone, date_naissance)
             VALUES (:id_compte, :nom, :prenom, :telephone, :date_naissance)'
        );
        $statement->execute([
            'id_compte'      => $accountId,
            'nom'            => $data['nom'],
            'prenom'         => $data['prenom'],
            'telephone'      => $data['telephone'],
            'date_naissance' => $data['date_naissance'],
        ]);

        $db->commit();

        return $accountId;
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}
