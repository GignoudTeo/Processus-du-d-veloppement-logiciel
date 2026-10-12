<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

// Renvoie false si le SMTP n'est pas configuré ou si l'envoi échoue
function sendAccountCreatedMail(string $email, string $firstname): bool
{
    if (SMTP_USER === '' || SMTP_PASSWORD === '') {
        error_log('Mail non envoyé : SMTP_USER / SMTP_PASSWORD vides dans includes/config.local.php');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASSWORD;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USER, APP_NAME);
        $mail->addAddress($email, $firstname);

        $safeName = htmlspecialchars($firstname);
        $mail->isHTML(true);
        $mail->Subject = 'Bienvenue chez ' . APP_NAME . ', ton compte est créé';
        $mail->Body = <<<HTML
            <div style="font-family: Arial, sans-serif; max-width: 520px; margin: auto; background: #0a0a0a; color: #ffffff; padding: 32px; border-radius: 12px;">
                <h1 style="margin: 0 0 16px; font-size: 28px;">FIT<span style="color: #ffd100;">PASS</span></h1>
                <p style="font-size: 16px;">Bonjour {$safeName},</p>
                <p style="font-size: 16px;">Ton compte a bien été créé. Bienvenue dans la salle !</p>
                <p style="font-size: 16px;">Tu peux dès maintenant afficher ton QR code pour entrer.</p>
                <p style="color: #a3a3a3; font-size: 13px; margin-top: 32px;">Si tu n'es pas à l'origine de cette inscription, ignore ce mail.</p>
            </div>
            HTML;
        $mail->AltBody = "Bonjour {$firstname},\n\nTon compte " . APP_NAME . " a bien été créé. Bienvenue dans la salle !\n\n"
            . "Si tu n'es pas à l'origine de cette inscription, ignore ce mail.";

        $mail->send();

        return true;
    } catch (Exception $e) {
        error_log('Envoi du mail impossible : ' . $mail->ErrorInfo);
        return false;
    }
}
