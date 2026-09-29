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
	$host = getenv('DB_HOST') ?: '127.0.0.1';
	$database = getenv('DB_NAME') ?: 'fitpass';
	$username = getenv('DB_USER') ?: 'root';
	$password = getenv('DB_PASSWORD') ?: '';

	$connection = new PDO(
		"mysql:host=$host;dbname=$database;charset=utf8mb4",
		$username,
		$password,
		[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
	);

	return $connection;
}
