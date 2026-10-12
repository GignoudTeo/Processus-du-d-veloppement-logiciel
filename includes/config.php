<?php
define('APP_NAME', 'FitPass');

// Clé de signature des QR codes (à changer en prod)
define('QR_SECRET', getenv('QR_SECRET') ?: '419f670236d1302ff394668dec85b78ee915dc59893bf04be3125efbe19fca31');

// En secondes
define('QR_VALIDITY', 60);

// Fixe tant qu'il n'y a pas de comptes
define('MEMBER_ID', 1);

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