<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

// Contenu du mail de confirmation : ['subject', 'html', 'text']
function buildConfirmationMail(string $firstname, string $confirmationLink): array
{
    $safeName = htmlspecialchars($firstname);
    $safeLink = htmlspecialchars($confirmationLink);

    $html = <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 520px; margin: auto; background: #0a0a0a; color: #ffffff; padding: 32px; border-radius: 12px;">
            <h1 style="margin: 0 0 16px; font-size: 28px;">FIT<span style="color: #ffd100;">PASS</span></h1>
            <p style="font-size: 16px;">Bonjour {$safeName},</p>
            <p style="font-size: 16px;">Ton compte a bien été créé. Il ne reste plus qu'à confirmer ton adresse mail pour l'activer :</p>
            <p style="text-align: center; margin: 28px 0;">
                <a href="{$safeLink}" style="display: inline-block; padding: 14px 28px; background: #ffd100; color: #0a0a0a; font-weight: bold; text-decoration: none; border-radius: 999px;">Confirmer mon adresse mail</a>
            </p>
            <p style="color: #a3a3a3; font-size: 13px;">Si le bouton ne marche pas, copie ce lien dans ton navigateur :<br>{$safeLink}</p>
            <p style="color: #a3a3a3; font-size: 13px; margin-top: 32px;">Si tu n'es pas à l'origine de cette inscription, ignore ce mail.</p>
        </div>
        HTML;

    $text = "Bonjour {$firstname},\n\nTon compte " . APP_NAME . " a bien été créé. "
        . "Pour l'activer, ouvre ce lien :\n{$confirmationLink}\n\n"
        . "Si tu n'es pas à l'origine de cette inscription, ignore ce mail.";

    return [
        'subject' => 'Confirme ton adresse mail pour activer ton compte ' . APP_NAME,
        'html'    => $html,
        'text'    => $text,
    ];
}

// Renvoie false si le SMTP n'est pas configuré ou si l'envoi échoue
function sendConfirmationMail(string $email, string $firstname, string $confirmationLink): bool
{
    if (SMTP_USER === '' || SMTP_PASSWORD === '') {
        error_log('Mail non envoyé : SMTP_USER / SMTP_PASSWORD vides dans includes/config.local.php');
        return false;
    }

    $mail    = new PHPMailer(true);
    $content = buildConfirmationMail($firstname, $confirmationLink);

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

        $mail->isHTML(true);
        $mail->Subject = $content['subject'];
        $mail->Body    = $content['html'];
        $mail->AltBody = $content['text'];

        $mail->send();

        return true;
    } catch (Exception $e) {
        error_log('Envoi du mail impossible : ' . $mail->ErrorInfo);
        return false;
    }
}
