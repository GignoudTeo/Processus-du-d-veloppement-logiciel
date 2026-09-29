<?php
define('APP_NAME', 'FitPass');

// Clé de signature des QR codes (à changer en prod)
define('QR_SECRET', getenv('QR_SECRET') ?: '419f670236d1302ff394668dec85b78ee915dc59893bf04be3125efbe19fca31');

// En secondes
define('QR_VALIDITY', 60);

// Fixe tant qu'il n'y a pas de comptes
define('MEMBER_ID', 1);
