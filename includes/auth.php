<?php
require_once __DIR__ . '/config.php';

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Cookies désactivés pendant les tests unitaires (pas de navigateur)
    if (ini_get('session.use_cookies')) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    }
    session_start();
}

// Jeton anti-CSRF à mettre dans chaque formulaire POST
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrf(?string $token): bool
{
    return is_string($token) && hash_equals(csrfToken(), $token);
}

// Message affiché une seule fois sur la page suivante (après une redirection)
function setFlash(string $message): void
{
    startSession();
    $_SESSION['flash'] = $message;
}

function takeFlash(): ?string
{
    startSession();
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $message;
}

// Page réservée aux personnes connectées : renvoie l'utilisateur ou redirige vers la connexion
function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        // Mémorise la page demandée (ex. lien d'un mail) pour y revenir après la connexion
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['apres_connexion'] = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '')
                . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        }
        header('Location: connexion.php');
        exit;
    }

    return $user;
}

// Page où revenir après la connexion (mémorisée par requireLogin), ou index.php.
// Seulement une page du site ("page.php?..."), pour ne jamais rediriger vers un autre site
function pullReturnUrl(): string
{
    $url = $_SESSION['apres_connexion'] ?? '';
    unset($_SESSION['apres_connexion']);

    return is_string($url) && preg_match('/^[a-z0-9-]+\.php(\?[^\s\\\\]*)?$/i', $url) ? $url : 'index.php';
}

function requireAdmin(): array
{
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        setFlash('Cette page est réservée aux administrateurs.');
        header('Location: index.php');
        exit;
    }

    return $user;
}

// ['id_compte', 'email', 'role', 'prenom'] ou null si personne n'est connecté
function currentUser(): ?array
{
    startSession();

    return $_SESSION['user'] ?? null;
}

const LOGIN_OK            = 'ok';
const LOGIN_INVALID       = 'invalide';
const LOGIN_NOT_CONFIRMED = 'non_confirme';

// Renvoie LOGIN_OK (et ouvre la session), LOGIN_INVALID ou LOGIN_NOT_CONFIRMED
function login(PDO $db, string $email, string $password): string
{
    $statement = $db->prepare(
        'SELECT c.id_compte, c.email, c.mot_de_passe_hash, c.role, c.email_confirme,
                COALESCE(a.prenom, co.prenom, ad.prenom) AS prenom
         FROM comptes c
         LEFT JOIN adherents a ON a.id_compte = c.id_compte
         LEFT JOIN coachs co ON co.id_compte = c.id_compte
         LEFT JOIN admins ad ON ad.id_compte = c.id_compte
         WHERE c.email = :email'
    );
    $statement->execute(['email' => strtolower(trim($email))]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$account || !password_verify($password, $account['mot_de_passe_hash'])) {
        return LOGIN_INVALID;
    }
    // Vérifié après le mot de passe, pour ne pas révéler qu'un compte existe
    if (!$account['email_confirme']) {
        return LOGIN_NOT_CONFIRMED;
    }

    startSession();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id_compte' => (int) $account['id_compte'],
        'email'     => $account['email'],
        'role'      => $account['role'],
        'prenom'    => $account['prenom'] ?? '',
    ];

    return LOGIN_OK;
}

function logout(): void
{
    startSession();
    $_SESSION = [];

    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);

    session_destroy();
}
