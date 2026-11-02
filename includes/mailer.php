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

// Cadre commun des mails : fond noir, logo, contenu
function mailLayout(string $content): string
{
    return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 560px; margin: auto; background: #0a0a0a; color: #ffffff; padding: 32px; border-radius: 12px; line-height: 1.5;">
            <h1 style="margin: 0 0 24px; font-size: 28px;">FIT<span style="color: #ffd100;">PASS</span></h1>
            {$content}
            <p style="color: #a3a3a3; font-size: 13px; margin-top: 32px; border-top: 1px solid #2a2a2a; padding-top: 16px;">
                Une question ? Réponds simplement à ce mail, l'équipe FitPass te répondra.
            </p>
        </div>
        HTML;
}

// Bouton cliquable : jaune (principal) ou contour jaune (secondaire)
function mailButton(string $label, string $url, bool $primary = true): string
{
    $style = $primary
        ? 'background: #ffd100; color: #0a0a0a; border: 2px solid #ffd100;'
        : 'background: transparent; color: #ffd100; border: 2px solid #ffd100;';

    return '<a href="' . htmlspecialchars($url) . '" style="display: inline-block; margin: 4px 8px 4px 0; padding: 12px 24px; '
        . $style . ' font-weight: bold; text-decoration: none; border-radius: 999px;">' . htmlspecialchars($label) . '</a>';
}

// Règles que chaque coach s'engage à respecter, reprises dans le mail de bienvenue
const COACH_CHARTER = [
    'Ponctualité' => 'Arrive au moins 10 minutes avant chaque cours pour préparer la salle et accueillir les adhérents.',
    'Sécurité'    => 'Commence toujours par un échauffement, adapte les exercices au niveau de chacun et signale tout incident à l\'équipe.',
    'Capacité'    => 'Respecte le nombre maximum de participants prévu pour chaque cours.',
    'Respect'     => 'Sois bienveillant et respectueux avec tous les adhérents, sans aucune discrimination.',
    'Matériel'    => 'Laisse la salle propre et range le matériel après chaque séance.',
    'Absence'     => 'En cas d\'empêchement, préviens l\'équipe au moins 48 heures à l\'avance pour qu\'elle trouve une solution.',
    'Confidentialité' => 'Les informations des adhérents restent privées : ne les partage jamais en dehors de FitPass.',
];

// Mail envoyé quand un compte devient coach
function buildCoachWelcomeMail(string $firstname): array
{
    $safeName = htmlspecialchars($firstname);

    $charterHtml = '';
    $charterText = '';
    $number      = 1;
    foreach (COACH_CHARTER as $rule => $description) {
        $charterHtml .= '<li style="margin-bottom: 10px;"><strong style="color: #ffd100;">' . $rule . '</strong> : ' . $description . '</li>';
        $charterText .= $number++ . '. ' . $rule . ' : ' . $description . "\n";
    }

    $buttons = mailButton('Voir le planning', APP_URL . '/planning.php')
        . mailButton('Mon compte', APP_URL . '/compte.php', false);

    $html = mailLayout(<<<HTML
        <p style="font-size: 22px; font-weight: bold; margin: 0 0 8px;">Félicitations {$safeName} ! 🎉</p>
        <p style="font-size: 16px;">Tu fais maintenant partie de l'équipe des <strong style="color: #ffd100;">coachs FitPass</strong>. Merci pour ta confiance, on est ravis de t'accueillir.</p>

        <h2 style="font-size: 18px; margin: 28px 0 8px;">Ton rôle de coach</h2>
        <ul style="padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 6px;">Animer les cours collectifs qui te sont attribués.</li>
            <li style="margin-bottom: 6px;">Accompagner et motiver les adhérents pendant leurs séances.</li>
            <li style="margin-bottom: 6px;">Veiller à la sécurité de chacun et donner l'exemple.</li>
        </ul>

        <h2 style="font-size: 18px; margin: 28px 0 8px;">La charte du coach</h2>
        <p style="font-size: 14px; color: #a3a3a3; margin: 0 0 12px;">En tant que coach, tu t'engages à respecter ces règles :</p>
        <ol style="padding-left: 20px; margin: 0; font-size: 14px;">{$charterHtml}</ol>

        <p style="margin: 28px 0 0;">{$buttons}</p>
        HTML);

    $text = "Félicitations {$firstname} !\n\n"
        . "Tu fais maintenant partie de l'équipe des coachs " . APP_NAME . ". Merci pour ta confiance.\n\n"
        . "TON RÔLE DE COACH\n"
        . "- Animer les cours collectifs qui te sont attribués.\n"
        . "- Accompagner et motiver les adhérents pendant leurs séances.\n"
        . "- Veiller à la sécurité de chacun et donner l'exemple.\n\n"
        . "LA CHARTE DU COACH\n" . $charterText . "\n"
        . "Voir le planning : " . APP_URL . "/planning.php\n"
        . "Mon compte : " . APP_URL . "/compte.php\n\n"
        . "Une question ? Réponds simplement à ce mail.";

    return [
        'subject' => 'Félicitations, tu deviens coach chez ' . APP_NAME . ' !',
        'html'    => $html,
        'text'    => $text,
    ];
}

// Mail envoyé quand un coach redevient adhérent
function buildCoachToMemberMail(string $firstname): array
{
    $safeName = htmlspecialchars($firstname);
    $buttons  = mailButton('Mon QR code', APP_URL . '/qrcode.php')
        . mailButton('Voir le planning', APP_URL . '/planning.php', false);

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Ton rôle de <strong>coach</strong> chez FitPass a pris fin, ton compte est maintenant un compte <strong style="color: #ffd100;">adhérent</strong>.</p>
        <p style="font-size: 16px;">Merci pour tout le temps et l'énergie que tu as consacrés aux adhérents pendant tes cours !</p>

        <h2 style="font-size: 18px; margin: 28px 0 8px;">Ce qui change</h2>
        <ul style="padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 6px;">Tu n'as plus de cours à animer en tant que coach.</li>
            <li style="margin-bottom: 6px;">Si des cours étaient encore prévus avec toi, l'équipe s'occupe de la suite.</li>
        </ul>

        <h2 style="font-size: 18px; margin: 28px 0 8px;">Ce qui ne change pas</h2>
        <ul style="padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 6px;">Ton compte reste actif, avec les mêmes identifiants.</li>
            <li style="margin-bottom: 6px;">Ton QR code te permet toujours d'entrer dans la salle.</li>
            <li style="margin-bottom: 6px;">Tu peux consulter le planning et participer aux cours comme tous les adhérents.</li>
        </ul>

        <p style="margin: 28px 0 0;">{$buttons}</p>
        <p style="color: #a3a3a3; font-size: 13px; margin-top: 24px;">Si tu penses qu'il s'agit d'une erreur, réponds à ce mail et nous regarderons ça rapidement.</p>
        HTML);

    $text = "Bonjour {$firstname},\n\n"
        . "Ton rôle de coach chez " . APP_NAME . " a pris fin, ton compte est maintenant un compte adhérent.\n"
        . "Merci pour tout le temps et l'énergie que tu as consacrés aux adhérents pendant tes cours !\n\n"
        . "CE QUI CHANGE\n"
        . "- Tu n'as plus de cours à animer en tant que coach.\n"
        . "- Si des cours étaient encore prévus avec toi, l'équipe s'occupe de la suite.\n\n"
        . "CE QUI NE CHANGE PAS\n"
        . "- Ton compte reste actif, avec les mêmes identifiants.\n"
        . "- Ton QR code te permet toujours d'entrer dans la salle.\n"
        . "- Tu peux consulter le planning et participer aux cours comme tous les adhérents.\n\n"
        . "Mon QR code : " . APP_URL . "/qrcode.php\n"
        . "Voir le planning : " . APP_URL . "/planning.php\n\n"
        . "Si tu penses qu'il s'agit d'une erreur, réponds simplement à ce mail.";

    return [
        'subject' => 'Ton rôle de coach chez ' . APP_NAME . ' a pris fin',
        'html'    => $html,
        'text'    => $text,
    ];
}

// Mail envoyé quand un admin refuse une demande pour devenir coach. $reason : motif facultatif
function buildCoachRequestRefusedMail(string $firstname, string $reason = ''): array
{
    $safeName   = htmlspecialchars($firstname);
    $reasonHtml = $reason === ''
        ? ''
        : '<p style="font-size: 16px; margin: 0 0 4px;"><strong>Raison du refus :</strong></p>'
          . '<p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">'
          . nl2br(htmlspecialchars($reason)) . '</p>';
    $buttons = mailButton('Faire une nouvelle demande', APP_URL . '/devenir-coach.php')
        . mailButton('Voir le planning', APP_URL . '/planning.php', false);

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Merci pour ta demande pour devenir coach chez FitPass et pour le temps que tu as pris à remplir le questionnaire.</p>
        <p style="font-size: 16px;">Après étude, l'équipe ne peut pas l'accepter pour le moment.</p>
        {$reasonHtml}
        <p style="font-size: 16px; margin-top: 20px;">Ce n'est pas définitif : tu pourras refaire une demande plus tard, par exemple avec plus d'expérience ou d'autres disponibilités.</p>
        <p style="margin: 28px 0 0;">{$buttons}</p>
        HTML);

    $text = "Bonjour {$firstname},\n\n"
        . "Merci pour ta demande pour devenir coach chez " . APP_NAME . ".\n"
        . "Après étude, l'équipe ne peut pas l'accepter pour le moment.\n\n"
        . ($reason === '' ? '' : "Raison du refus :\n{$reason}\n\n")
        . "Ce n'est pas définitif : tu pourras refaire une demande plus tard.\n"
        . "Faire une nouvelle demande : " . APP_URL . "/devenir-coach.php\n\n"
        . "Une question ? Réponds simplement à ce mail.";

    return [
        'subject' => 'Ta demande pour devenir coach chez ' . APP_NAME,
        'html'    => $html,
        'text'    => $text,
    ];
}

function sendCoachRequestRefusedMail(string $email, string $firstname, string $reason = ''): bool
{
    return sendMail($email, $firstname, buildCoachRequestRefusedMail($firstname, $reason));
}

// Choisit le mail selon le changement : bienvenue coach, fin de coach, ou mail générique
function buildRoleChangeMail(string $firstname, string $oldRole, string $newRole): array
{
    if ($newRole === 'coach') {
        return buildCoachWelcomeMail($firstname);
    }
    if ($oldRole === 'coach' && $newRole === 'adherent') {
        return buildCoachToMemberMail($firstname);
    }

    return buildGenericRoleChangeMail($firstname, $oldRole, $newRole);
}

function buildGenericRoleChangeMail(string $firstname, string $oldRole, string $newRole): array
{
    $roleLabels = [
        'adherent' => 'Adhérent',
        'coach'    => 'Coach',
        'admin'    => 'Admin',
    ];
    $oldRoleLabel = $roleLabels[$oldRole] ?? ucfirst($oldRole);
    $newRoleLabel = $roleLabels[$newRole] ?? ucfirst($newRole);
    $safeName = htmlspecialchars($firstname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeOldRole = htmlspecialchars($oldRoleLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeNewRole = htmlspecialchars($newRoleLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 520px; margin: auto; background: #0a0a0a; color: #ffffff; padding: 32px; border-radius: 12px;">
            <h1 style="margin: 0 0 16px; font-size: 28px;">FIT<span style="color: #ffd100;">PASS</span></h1>
            <p style="font-size: 16px;">Bonjour {$safeName},</p>
            <p style="font-size: 16px;">Le rôle associé à ton compte {$safeName} a été modifié :</p>
            <p style="font-size: 16px;"><strong>{$safeOldRole}</strong> devient <strong>{$safeNewRole}</strong>.</p>
            <p style="color: #a3a3a3; font-size: 13px; margin-top: 32px;">Si tu ne t'attendais pas à ce changement, contacte l'équipe FitPass.</p>
        </div>
        HTML;

    $text = "Bonjour {$firstname},\n\nLe rôle associé à ton compte " . APP_NAME . " a été modifié : "
        . $oldRoleLabel . ' devient ' . $newRoleLabel . ".\n\n"
        . "Si tu ne t'attendais pas à ce changement, contacte l'équipe FitPass.";

    return [
        'subject' => 'Modification du rôle de ton compte ' . APP_NAME,
        'html'    => $html,
        'text'    => $text,
    ];
}

// Renvoie false si le SMTP n'est pas configuré ou si l'envoi échoue
function sendConfirmationMail(string $email, string $firstname, string $confirmationLink): bool
{
    return sendMail($email, $firstname, buildConfirmationMail($firstname, $confirmationLink));
}

function sendRoleChangeMail(
    string $email,
    string $firstname,
    string $oldRole,
    string $newRole
): bool {
    return sendMail($email, $firstname, buildRoleChangeMail($firstname, $oldRole, $newRole));
}

function sendMail(string $email, string $firstname, array $content): bool
{
    if (SMTP_USER === '' || SMTP_PASSWORD === '') {
        error_log('Mail non envoyé : SMTP_USER / SMTP_PASSWORD vides dans includes/config.local.php');
        return false;
    }

    $mail = null;
    try {
        $mail = new PHPMailer(true);
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
        error_log('Envoi du mail impossible : ' . ($mail ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}
