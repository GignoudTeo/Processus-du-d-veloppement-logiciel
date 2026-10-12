<?php
define('APP_NAME', 'FitPass');

define('QR_SECRET', getenv('QR_SECRET') ?: '419f670236d1302ff394668dec85b78ee915dc59893bf04be3125efbe19fca31');

define('QR_VALIDITY', 60);

// Identifiants SMTP (Gmail) dans config.local.php, non versionné
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}
defined('SMTP_HOST') || define('SMTP_HOST', 'smtp.gmail.com');
defined('SMTP_PORT') || define('SMTP_PORT', 587);
defined('SMTP_USER') || define('SMTP_USER', '');
defined('SMTP_PASSWORD') || define('SMTP_PASSWORD', '');

function getDatabaseConnection(): PDO
{
    // Hôte Supabase utilisant le Connection Pooler pour la région eu-west-1
    // (Alternative de connexion directe : 'db.rncillqcjbzxbebcuvfh.supabase.co')
    $host = getenv('DB_HOST') ?: 'aws-0-eu-west-1.pooler.supabase.com'; 
    
    // Port 6543 pour le pooler (ou 5432 pour la connexion directe)
    $port = getenv('DB_PORT') ?: '6543'; 
    
    // Le nom de la base de données est par défaut 'postgres' sur Supabase
    $database = getenv('DB_NAME') ?: 'postgres'; 
    
    // Avec le pooler, l'utilisateur prend la forme postgres.[project-id]
    // (Si connexion directe port 5432, utiliser seulement 'postgres')
    $username = getenv('DB_USER') ?: 'postgres.rncillqcjbzxbebcuvfh'; 
    
    // Ton mot de passe
    $password = getenv('DB_PASSWORD') ?: 'ProcesDev84';

    try {
        // Connexion avec le driver PostgreSQL (pgsql)
        $connection = new PDO(
            "pgsql:host=$host;port=$port;dbname=$database",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC 
            ]
        );

        return $connection;
    } catch (PDOException $e) {
        die("Erreur de connexion à la base de données : " . $e->getMessage());
    }
}
##sb_publishable_YiOREdFE2YbHf7NqCZ-zvQ_gYuZw7H2
#https://rncillqcjbzxbebcuvfh.supabase.co/rest/v1/