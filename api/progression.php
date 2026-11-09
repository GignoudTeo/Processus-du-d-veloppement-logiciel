<?php
// API JSON du suivi de progression : la page met le graphique à jour sans se recharger.
// GET : liste des mesures. POST (corps JSON, en-tête X-CSRF-Token) : action create / update / delete
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/progression.php';
require_once __DIR__ . '/../includes/reservations.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$user = currentUser();
if (!$user) {
    respond(401, ['error' => 'Non connecté']);
}

try {
    $db         = getDatabaseConnection();
    $adherentId = getAdherentIdByAccount($db, $user['id_compte']);
    if ($adherentId === null) {
        respond(403, ['error' => 'Le suivi de progression est réservé aux adhérents.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['mesures' => getMeasurements($db, $adherentId)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['error' => 'Méthode non autorisée']);
    }
    if (!isValidCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        respond(403, ['error' => 'La session a expiré, recharge la page et réessaie.']);
    }

    $input  = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $action = $input['action'] ?? '';
    $id     = filter_var($input['id_mesure'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($action === 'delete') {
        if ($id === false || !deleteMeasurement($db, $id, $adherentId)) {
            respond(404, ['error' => 'Cette mesure est introuvable.']);
        }
        respond(200, ['mesures' => getMeasurements($db, $adherentId)]);
    }

    if (!in_array($action, ['create', 'update'], true)) {
        respond(400, ['error' => 'Action inconnue.']);
    }

    [$data, $errors] = validateMeasurement($input, new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')));
    if ($errors !== []) {
        respond(422, ['errors' => $errors]);
    }

    if ($action === 'create') {
        createMeasurement($db, $adherentId, $data);
    } elseif ($id === false || !updateMeasurement($db, $id, $adherentId, $data)) {
        respond(404, ['error' => 'Cette mesure est introuvable.']);
    }

    respond(200, ['mesures' => getMeasurements($db, $adherentId)]);
} catch (PDOException $e) {
    error_log('API progression : ' . $e->getMessage());
    respond(500, ['error' => 'Impossible d\'enregistrer pour le moment, réessaie plus tard.']);
}
