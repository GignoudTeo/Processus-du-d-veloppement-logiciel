<?php
require_once __DIR__ . '/includes/auth.php';

// POST uniquement : un simple lien ou une image ne peut pas déconnecter quelqu'un à son insu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isValidCsrf($_POST['csrf_token'] ?? null)) {
    logout();
}

header('Location: index.php');
exit;
