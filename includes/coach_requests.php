<?php
// Demandes d'adhérents pour devenir coach : questionnaire, puis acceptation ou refus par un admin
require_once __DIR__ . '/accounts.php';

const COACH_EXPERIENCE_LEVELS = [
    'moins_1_an' => 'Moins d\'un an',
    '1_3_ans'    => 'De 1 à 3 ans',
    'plus_3_ans' => 'Plus de 3 ans',
];

const COACH_REQUEST_TEXT_MAX = 2000;

// Renvoie [données nettoyées, erreurs par champ]. $validSportIds : sports qui existent
function validateCoachRequest(array $input, array $validSportIds): array
{
    $sportIds = array_map('intval', (array) ($input['sports'] ?? []));

    $data = [
        'sports'         => array_values(array_unique(array_intersect($sportIds, $validSportIds))),
        'experience'     => (string) ($input['experience'] ?? ''),
        'diplome'        => trim((string) ($input['diplome'] ?? '')),
        'types_seances'  => trim((string) ($input['types_seances'] ?? '')),
        'disponibilites' => trim((string) ($input['disponibilites'] ?? '')),
        'motivation'     => trim((string) ($input['motivation'] ?? '')),
    ];
    $errors = [];

    if ($data['sports'] === []) {
        $errors['sports'] = 'Choisis au moins un sport.';
    }
    if (!isset(COACH_EXPERIENCE_LEVELS[$data['experience']])) {
        $errors['experience'] = 'Indique ton expérience.';
    }
    if (mb_strlen($data['diplome']) > 255) {
        $errors['diplome'] = '255 caractères maximum.';
    }

    $requiredTexts = [
        'types_seances'  => 'Décris les séances que tu aimerais proposer.',
        'disponibilites' => 'Indique tes disponibilités.',
        'motivation'     => 'Explique ta motivation.',
    ];
    foreach ($requiredTexts as $field => $message) {
        if ($data[$field] === '') {
            $errors[$field] = $message;
        } elseif (mb_strlen($data[$field]) > COACH_REQUEST_TEXT_MAX) {
            $errors[$field] = COACH_REQUEST_TEXT_MAX . ' caractères maximum.';
        }
    }

    return [$data, $errors];
}

// Enregistre la demande et ses sports, renvoie id_demande
function createCoachRequest(PDO $db, int $accountId, array $data): int
{
    $db->beginTransaction();

    try {
        $statement = $db->prepare(
            'INSERT INTO demandes_coach (id_compte, experience, diplome, types_seances, disponibilites, motivation)
             VALUES (:compte, :experience, :diplome, :types, :dispos, :motivation)
             RETURNING id_demande'
        );
        $statement->execute([
            'compte'     => $accountId,
            'experience' => $data['experience'],
            'diplome'    => $data['diplome'] === '' ? null : $data['diplome'],
            'types'      => $data['types_seances'],
            'dispos'     => $data['disponibilites'],
            'motivation' => $data['motivation'],
        ]);
        $requestId = (int) $statement->fetchColumn();

        $statement = $db->prepare('INSERT INTO demandes_coach_sports (id_demande, id_sport) VALUES (:demande, :sport)');
        foreach ($data['sports'] as $sportId) {
            $statement->execute(['demande' => $requestId, 'sport' => $sportId]);
        }

        $db->commit();

        return $requestId;
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
}

// Dernière demande d'un compte (tous statuts), ou null
function getLatestCoachRequest(PDO $db, int $accountId): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM demandes_coach WHERE id_compte = :compte ORDER BY date_demande DESC, id_demande DESC LIMIT 1'
    );
    $statement->execute(['compte' => $accountId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Demandes avec le nom, l'email du candidat et ses sports. $status : 'en_attente', ou null pour les demandes traitées
function getCoachRequests(PDO $db, ?string $status = 'en_attente'): array
{
    $sql = 'SELECT d.*, c.email, a.nom, a.prenom
            FROM demandes_coach d
            INNER JOIN comptes c ON c.id_compte = d.id_compte
            LEFT JOIN adherents a ON a.id_compte = d.id_compte';
    $sql .= $status === null
        ? " WHERE d.statut <> 'en_attente' ORDER BY d.date_traitement DESC, d.id_demande DESC"
        : ' WHERE d.statut = :statut ORDER BY d.date_demande, d.id_demande';

    $statement = $db->prepare($sql);
    $statement->execute($status === null ? [] : ['statut' => $status]);
    $requests = $statement->fetchAll(PDO::FETCH_ASSOC);

    // Sports de toutes les demandes en une seule requête
    $sportsByRequest = [];
    if ($requests !== []) {
        $ids          = array_column($requests, 'id_demande');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement    = $db->prepare(
            "SELECT ds.id_demande, s.nom_sport
             FROM demandes_coach_sports ds
             INNER JOIN sports s ON s.id_sport = ds.id_sport
             WHERE ds.id_demande IN ($placeholders)
             ORDER BY s.nom_sport"
        );
        $statement->execute($ids);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sportsByRequest[$row['id_demande']][] = $row['nom_sport'];
        }
    }

    foreach ($requests as &$request) {
        $request['sports'] = $sportsByRequest[$request['id_demande']] ?? [];
    }

    return $requests;
}

// Comptes administrateurs à prévenir d'une nouvelle demande : [['email', 'prenom'], ...]. Rôle lu en base
function getAdminRecipients(PDO $db): array
{
    return $db->query(
        "SELECT c.email, COALESCE(ad.prenom, co.prenom, a.prenom, '') AS prenom
         FROM comptes c
         LEFT JOIN admins ad ON ad.id_compte = c.id_compte
         LEFT JOIN coachs co ON co.id_compte = c.id_compte
         LEFT JOIN adherents a ON a.id_compte = c.id_compte
         WHERE c.role = 'admin'
         ORDER BY c.id_compte"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Nom affiché du candidat : "Prénom Nom", ou son adresse mail s'il n'a pas de profil adhérent
function getApplicantName(PDO $db, int $accountId): string
{
    $statement = $db->prepare(
        'SELECT c.email, a.prenom, a.nom
         FROM comptes c
         LEFT JOIN adherents a ON a.id_compte = c.id_compte
         WHERE c.id_compte = :id'
    );
    $statement->execute(['id' => $accountId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return '';
    }

    return trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')) ?: $row['email'];
}

// Lien vers une demande dans l'espace admin (onglet choisi automatiquement par demandes-coach.php)
function coachRequestUrl(int $requestId): string
{
    return APP_URL . '/demandes-coach.php?demande=' . $requestId . '#demande-' . $requestId;
}

function countPendingCoachRequests(PDO $db): int
{
    return (int) $db->query("SELECT COUNT(*) FROM demandes_coach WHERE statut = 'en_attente'")->fetchColumn();
}

function getCoachRequest(PDO $db, int $requestId): ?array
{
    $statement = $db->prepare('SELECT * FROM demandes_coach WHERE id_demande = :id');
    $statement->execute(['id' => $requestId]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function markCoachRequest(PDO $db, int $requestId, string $status, int $adminId, ?string $reason = null): void
{
    $db->prepare(
        'UPDATE demandes_coach
         SET statut = :statut, motif_refus = :motif, date_traitement = CURRENT_TIMESTAMP, id_admin_traitement = :admin
         WHERE id_demande = :id'
    )->execute(['statut' => $status, 'motif' => $reason, 'admin' => $adminId, 'id' => $requestId]);
}

// Passe le candidat coach et clôture la demande.
// Renvoie 'accepted', 'not_found', 'already_processed', ou le code d'erreur de changeAccountRole()
function acceptCoachRequest(PDO $db, int $requestId, int $adminId): string
{
    $request = getCoachRequest($db, $requestId);
    if (!$request) {
        return 'not_found';
    }
    if ($request['statut'] !== 'en_attente') {
        return 'already_processed';
    }

    $result = changeAccountRole($db, (int) $request['id_compte'], 'coach', $adminId);
    // 'unchanged' : déjà coach (changé à la main entre-temps), la demande est quand même acceptée
    if ($result !== 'changed' && $result !== 'unchanged') {
        return $result;
    }

    markCoachRequest($db, $requestId, 'acceptee', $adminId);

    return 'accepted';
}

// Le motif est obligatoire : il est envoyé par mail au candidat.
// Renvoie 'refused', 'reason_required', 'not_found' ou 'already_processed'
function refuseCoachRequest(PDO $db, int $requestId, int $adminId, string $reason): string
{
    $request = getCoachRequest($db, $requestId);
    if (!$request) {
        return 'not_found';
    }
    if ($request['statut'] !== 'en_attente') {
        return 'already_processed';
    }

    $reason = trim($reason);
    if ($reason === '') {
        return 'reason_required';
    }

    markCoachRequest($db, $requestId, 'refusee', $adminId, mb_substr($reason, 0, COACH_REQUEST_TEXT_MAX));

    return 'refused';
}
