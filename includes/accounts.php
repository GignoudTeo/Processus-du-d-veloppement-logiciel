<?php
require_once __DIR__ . '/config.php';

// Nom, prénom, email, téléphone, date de naissance. Renvoie [données nettoyées, erreurs par champ]
function validateProfile(array $input): array
{
    $data = [
        'nom'            => trim($input['nom'] ?? ''),
        'prenom'         => trim($input['prenom'] ?? ''),
        'email'          => strtolower(trim($input['email'] ?? '')),
        'telephone'      => preg_replace('/[\s.\-]/', '', $input['telephone'] ?? ''),
        'date_naissance' => trim($input['date_naissance'] ?? ''),
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

    return [$data, $errors];
}

// Renvoie les erreurs par champ (vide si le nouveau mot de passe est valide)
function validateNewPassword(string $password, string $confirmation): array
{
    if (strlen($password) < 8) {
        return ['mot_de_passe' => '8 caractères minimum.'];
    }
    if ($password !== $confirmation) {
        return ['confirmation' => 'Les mots de passe ne correspondent pas.'];
    }

    return [];
}

function validateRegistration(array $input): array
{
    [$data, $errors] = validateProfile($input);
    $data['mot_de_passe'] = $input['mot_de_passe'] ?? '';
    $errors += validateNewPassword($data['mot_de_passe'], $input['confirmation'] ?? '');

    return [$data, $errors];
}

// $exceptAccountId : pour ignorer son propre compte quand on modifie son email
function emailExists(PDO $db, string $email, ?int $exceptAccountId = null): bool
{
    $statement = $db->prepare('SELECT 1 FROM comptes WHERE email = :email AND id_compte <> :except');
    $statement->execute(['email' => $email, 'except' => $exceptAccountId ?? 0]);

    return (bool) $statement->fetchColumn();
}

// Code secret du lien de confirmation envoyé par mail
function generateConfirmationToken(): string
{
    return bin2hex(random_bytes(32));
}

// Crée le compte non confirmé (table comptes) et le profil adhérent (table adherents), renvoie id_compte.
// Seul le hash du jeton est enregistré, comme pour un mot de passe.
function createAdherentAccount(PDO $db, array $data, string $confirmationToken): int
{
    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            "INSERT INTO comptes (email, mot_de_passe_hash, role, email_confirme, jeton_confirmation)
             VALUES (:email, :hash, 'adherent', FALSE, :jeton)
             RETURNING id_compte"
        );
        $statement->execute([
            'email' => $data['email'],
            'hash'  => password_hash($data['mot_de_passe'], PASSWORD_DEFAULT),
            'jeton' => hash('sha256', $confirmationToken),
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

// Valide le compte lié au jeton du lien. false si le lien est faux ou déjà utilisé
function confirmEmail(PDO $db, string $confirmationToken): bool
{
    $statement = $db->prepare(
        'UPDATE comptes
         SET email_confirme = TRUE, jeton_confirmation = NULL
         WHERE jeton_confirmation = :jeton AND email_confirme = FALSE'
    );
    $statement->execute(['jeton' => hash('sha256', $confirmationToken)]);

    return $statement->rowCount() === 1;
}

// null si le compte n'a pas de profil adhérent
function getAdherentProfile(PDO $db, int $accountId): ?array
{
    $statement = $db->prepare(
        'SELECT c.email, a.nom, a.prenom, a.telephone, a.date_naissance
         FROM comptes c
         INNER JOIN adherents a ON a.id_compte = c.id_compte
         WHERE c.id_compte = :id'
    );
    $statement->execute(['id' => $accountId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getAccountsForAdmin(PDO $db, string $search = ''): array
{
    $sql =
        'SELECT c.id_compte, c.email, c.role, c.email_confirme,
                COALESCE(a.nom, co.nom, ad.nom) AS nom,
                COALESCE(a.prenom, co.prenom, ad.prenom) AS prenom
         FROM comptes c
         LEFT JOIN adherents a ON a.id_compte = c.id_compte
         LEFT JOIN coachs co ON co.id_compte = c.id_compte
         LEFT JOIN admins ad ON ad.id_compte = c.id_compte';

    if ($search !== '') {
        $sql .=
            " WHERE LOWER(c.email) LIKE LOWER(:email_search)
                OR LOWER(COALESCE(a.nom, co.nom, ad.nom, '')) LIKE LOWER(:nom_search)
                OR LOWER(COALESCE(a.prenom, co.prenom, ad.prenom, '')) LIKE LOWER(:prenom_search)";
    }

    $sql .= ' ORDER BY c.date_creation DESC, c.id_compte DESC';
    $statement = $db->prepare($sql);
    if ($search !== '') {
        $term = '%' . $search . '%';
        $statement->execute([
            'email_search'  => $term,
            'nom_search'    => $term,
            'prenom_search' => $term,
        ]);
    } else {
        $statement->execute();
    }
    return $statement->fetchAll();
}

function getAccountNotificationDetails(PDO $db, int $accountId): ?array
{
    $statement = $db->prepare(
        'SELECT c.email, c.role,
                COALESCE(a.prenom, co.prenom, ad.prenom, \'\') AS prenom
         FROM comptes c
         LEFT JOIN adherents a ON a.id_compte = c.id_compte
         LEFT JOIN coachs co ON co.id_compte = c.id_compte
         LEFT JOIN admins ad ON ad.id_compte = c.id_compte
         WHERE c.id_compte = :id'
    );
    $statement->execute(['id' => $accountId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function changeAccountRole(PDO $db, int $accountId, string $newRole, int $actingAdminId): string
{
    $roleTables = [
        'adherent' => 'adherents',
        'coach'    => 'coachs',
        'admin'    => 'admins',
    ];
    if (!isset($roleTables[$newRole])) {
        throw new InvalidArgumentException('Rôle de compte invalide.');
    }

    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            'SELECT c.role,
                    COALESCE(a.nom, co.nom, ad.nom) AS nom,
                    COALESCE(a.prenom, co.prenom, ad.prenom) AS prenom,
                    a.id_adherent, co.id_coach, ad.id_admin
             FROM comptes c
             LEFT JOIN adherents a ON a.id_compte = c.id_compte
             LEFT JOIN coachs co ON co.id_compte = c.id_compte
             LEFT JOIN admins ad ON ad.id_compte = c.id_compte
             WHERE c.id_compte = :id'
        );
        $statement->execute(['id' => $accountId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            $db->commit();
            return 'not_found';
        }

        if ($account['role'] === $newRole) {
            $db->commit();
            return 'unchanged';
        }

        if ($accountId === $actingAdminId) {
            $db->commit();
            return 'self_change_forbidden';
        }

        if ($account['role'] === 'admin' && $newRole !== 'admin') {
            $adminCount = (int) $db->query("SELECT COUNT(*) FROM comptes WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                $db->commit();
                return 'last_admin';
            }
        }

        if ($account['nom'] === null || $account['prenom'] === null) {
            $db->commit();
            return 'profile_missing';
        }

        $profileIdColumn = [
            'adherent' => 'id_adherent',
            'coach'    => 'id_coach',
            'admin'    => 'id_admin',
        ][$newRole];

        if ($account[$profileIdColumn] === null) {
            $profileTable = $roleTables[$newRole];
            $statement = $db->prepare(
                "INSERT INTO $profileTable (id_compte, nom, prenom)
                 VALUES (:id, :nom, :prenom)"
            );
            $statement->execute([
                'id'     => $accountId,
                'nom'    => $account['nom'],
                'prenom' => $account['prenom'],
            ]);
        }

        $statement = $db->prepare('UPDATE comptes SET role = :role WHERE id_compte = :id');
        $statement->execute(['role' => $newRole, 'id' => $accountId]);

        $db->commit();
        return 'changed';
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function updateAdherentProfile(PDO $db, int $accountId, array $data): void
{
    $db->beginTransaction();

    try {
        $db->prepare('UPDATE comptes SET email = :email WHERE id_compte = :id')
            ->execute(['email' => $data['email'], 'id' => $accountId]);

        $db->prepare(
            'UPDATE adherents
             SET nom = :nom, prenom = :prenom, telephone = :telephone, date_naissance = :date_naissance
             WHERE id_compte = :id'
        )->execute([
            'nom'            => $data['nom'],
            'prenom'         => $data['prenom'],
            'telephone'      => $data['telephone'],
            'date_naissance' => $data['date_naissance'],
            'id'             => $accountId,
        ]);

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

function checkPassword(PDO $db, int $accountId, string $password): bool
{
    $statement = $db->prepare('SELECT mot_de_passe_hash FROM comptes WHERE id_compte = :id');
    $statement->execute(['id' => $accountId]);
    $hash = $statement->fetchColumn();

    return $hash !== false && password_verify($password, $hash);
}

function updatePassword(PDO $db, int $accountId, string $password): void
{
    $db->prepare('UPDATE comptes SET mot_de_passe_hash = :hash WHERE id_compte = :id')
        ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $accountId]);
}

// Supprime aussi le profil adhérent et ses réservations (ON DELETE CASCADE)
function deleteAccount(PDO $db, int $accountId): void
{
    $db->prepare('DELETE FROM comptes WHERE id_compte = :id')->execute(['id' => $accountId]);
}
