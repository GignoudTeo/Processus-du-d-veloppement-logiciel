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

// Mail envoyé à chaque administrateur quand un adhérent envoie une demande pour devenir coach
function buildNewCoachRequestMail(string $adminFirstname, string $applicantName, string $requestLink): array
{
    $greeting      = $adminFirstname === '' ? 'Bonjour,' : 'Bonjour ' . $adminFirstname . ',';
    $safeGreeting  = htmlspecialchars($greeting);
    $safeApplicant = htmlspecialchars($applicantName);
    $safeLink      = htmlspecialchars($requestLink);
    $button        = mailButton('Voir la demande', $requestLink);

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">{$safeGreeting}</p>
        <p style="font-size: 16px;">Une nouvelle demande pour devenir coach vient d'arriver :</p>
        <p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">
            <strong>{$safeApplicant}</strong>
        </p>
        <p style="font-size: 16px; margin-top: 20px;">Tu peux consulter ses réponses au questionnaire puis l'accepter ou la refuser.</p>
        <p style="margin: 28px 0 0;">{$button}</p>
        <p style="color: #a3a3a3; font-size: 13px; margin-top: 24px;">Si le bouton ne marche pas, copie ce lien dans ton navigateur :<br>{$safeLink}</p>
        HTML);

    $text = "{$greeting}\n\n"
        . "Une nouvelle demande pour devenir coach vient d'arriver : {$applicantName}.\n\n"
        . "Voir la demande : {$requestLink}";

    return [
        'subject' => 'Nouvelle demande coach : ' . $applicantName,
        'html'    => $html,
        'text'    => $text,
    ];
}

function sendNewCoachRequestMail(string $email, string $adminFirstname, string $applicantName, string $requestLink): bool
{
    return sendMail($email, $adminFirstname, buildNewCoachRequestMail($adminFirstname, $applicantName, $requestLink));
}

// Mail envoyé quand un participant est retiré d'une séance. $when : date et heure déjà formatées
function buildParticipantRemovedMail(string $firstname, string $courseTitle, string $when, string $reason = ''): array
{
    $safeName   = htmlspecialchars($firstname);
    $safeTitle  = htmlspecialchars($courseTitle);
    $safeWhen   = htmlspecialchars($when);
    $button     = mailButton('Voir le planning', APP_URL . '/planning.php');
    $reasonHtml = $reason === ''
        ? ''
        : '<p style="font-size: 16px; margin: 20px 0 4px;"><strong>Motif :</strong></p>'
          . '<p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">'
          . nl2br(htmlspecialchars($reason)) . '</p>';

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Tu as été retiré(e) de la liste des inscrits de cette séance :</p>
        <p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">
            <strong>{$safeTitle}</strong><br>{$safeWhen}
        </p>
        {$reasonHtml}
        <p style="font-size: 16px; margin-top: 20px;">Ta réservation pour cette séance n'est donc plus valable.</p>
        <p style="margin: 28px 0 0;">{$button}</p>
        HTML);

    $text = "Bonjour {$firstname},\n\n"
        . "Tu as été retiré(e) de la liste des inscrits de cette séance :\n"
        . "{$courseTitle}\n{$when}\n\n"
        . ($reason === '' ? '' : "Motif :\n{$reason}\n\n")
        . "Ta réservation pour cette séance n'est donc plus valable.\n"
        . "Voir le planning : " . APP_URL . "/planning.php\n\n"
        . "Une question ? Réponds simplement à ce mail.";

    return [
        'subject' => 'Tu as été retiré(e) d\'une séance ' . APP_NAME,
        'html'    => $html,
        'text'    => $text,
    ];
}

function sendParticipantRemovedMail(string $email, string $firstname, string $courseTitle, string $when, string $reason = ''): bool
{
    return sendMail($email, $firstname, buildParticipantRemovedMail($firstname, $courseTitle, $when, $reason));
}

// Mail envoyé à chaque inscrit quand le coach annule une séance. $when : date et heure déjà formatées
function buildCourseCancelledMail(string $firstname, string $courseTitle, string $when, string $reason): array
{
    $safeName   = htmlspecialchars($firstname);
    $safeTitle  = htmlspecialchars($courseTitle);
    $safeWhen   = htmlspecialchars($when);
    $safeReason = nl2br(htmlspecialchars($reason));
    $button     = mailButton('Trouver une autre séance', APP_URL . '/planning.php');

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Nous sommes désolés : la séance à laquelle tu étais inscrit(e) est <strong style="color: #ffd100;">annulée</strong>.</p>
        <p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">
            <strong>{$safeTitle}</strong><br>{$safeWhen}
        </p>
        <p style="font-size: 16px; margin: 20px 0 4px;"><strong>Motif de l'annulation :</strong></p>
        <p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">{$safeReason}</p>
        <p style="font-size: 16px; margin-top: 20px;">Ta réservation est donc annulée. Tu peux t'inscrire à une autre séance depuis le planning.</p>
        <p style="margin: 28px 0 0;">{$button}</p>
        HTML);

    $text = "Bonjour {$firstname},\n\n"
        . "La séance à laquelle tu étais inscrit(e) est annulée :\n"
        . "{$courseTitle}\n{$when}\n\n"
        . "Motif de l'annulation :\n{$reason}\n\n"
        . "Ta réservation est donc annulée. Tu peux t'inscrire à une autre séance depuis le planning.\n"
        . "Voir le planning : " . APP_URL . "/planning.php\n\n"
        . "Une question ? Réponds simplement à ce mail.";

    return [
        'subject' => 'Séance annulée : ' . $courseTitle . ' (' . $when . ')',
        'html'    => $html,
        'text'    => $text,
    ];
}

function sendCourseCancelledMail(string $email, string $firstname, string $courseTitle, string $when, string $reason): bool
{
    return sendMail($email, $firstname, buildCourseCancelledMail($firstname, $courseTitle, $when, $reason));
}

// Bloc « séance » commun aux mails d'inscription
function mailSessionBox(string $courseTitle, string $when): string
{
    return '<p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">'
        . '<strong>' . htmlspecialchars($courseTitle) . '</strong><br>' . htmlspecialchars($when) . '</p>';
}

// Décision du coach sur une demande d'inscription (US4). $reason : motif du refus
function buildBookingDecisionMail(string $firstname, string $courseTitle, string $when, bool $accepted, string $reason = ''): array
{
    $safeName = htmlspecialchars($firstname);
    $box      = mailSessionBox($courseTitle, $when);

    if ($accepted) {
        $buttons = mailButton('Voir mon historique', APP_URL . '/historique.php')
            . mailButton('Mon QR code', APP_URL . '/qrcode.php', false);
        $html = mailLayout(<<<HTML
            <p style="font-size: 16px;">Bonjour {$safeName},</p>
            <p style="font-size: 16px;">Bonne nouvelle : le coach a <strong style="color: #4ade80;">validé</strong> ton inscription. Ta place est réservée !</p>
            {$box}
            <p style="font-size: 16px; margin-top: 20px;">Pense à arriver quelques minutes avant le début de la séance.</p>
            <p style="margin: 28px 0 0;">{$buttons}</p>
            HTML);
        $text = "Bonjour {$firstname},\n\nLe coach a validé ton inscription, ta place est réservée :\n{$courseTitle}\n{$when}\n\n"
            . "Voir mon historique : " . APP_URL . "/historique.php\n\nUne question ? Réponds simplement à ce mail.";

        return ['subject' => 'Inscription validée : ' . $courseTitle . ' (' . $when . ')', 'html' => $html, 'text' => $text];
    }

    $safeReason = nl2br(htmlspecialchars($reason));
    $button     = mailButton('Trouver une autre séance', APP_URL . '/planning.php');
    $html       = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Le coach n'a pas pu retenir ta demande d'inscription à cette séance :</p>
        {$box}
        <p style="font-size: 16px; margin: 20px 0 4px;"><strong>Motif du refus :</strong></p>
        <p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">{$safeReason}</p>
        <p style="font-size: 16px; margin-top: 20px;">D'autres séances t'attendent dans le planning.</p>
        <p style="margin: 28px 0 0;">{$button}</p>
        HTML);
    $text = "Bonjour {$firstname},\n\nLe coach n'a pas pu retenir ta demande d'inscription à cette séance :\n{$courseTitle}\n{$when}\n\n"
        . "Motif du refus :\n{$reason}\n\n"
        . "Voir le planning : " . APP_URL . "/planning.php\n\nUne question ? Réponds simplement à ce mail.";

    return ['subject' => 'Inscription refusée : ' . $courseTitle . ' (' . $when . ')', 'html' => $html, 'text' => $text];
}

function sendBookingDecisionMail(string $email, string $firstname, string $courseTitle, string $when, bool $accepted, string $reason = ''): bool
{
    return sendMail($email, $firstname, buildBookingDecisionMail($firstname, $courseTitle, $when, $accepted, $reason));
}

// Une place s'est libérée : l'adhérent en tête de la liste d'attente est proposé au coach (US3)
function buildWaitingListPromotedMail(string $firstname, string $courseTitle, string $when): array
{
    $safeName = htmlspecialchars($firstname);
    $box      = mailSessionBox($courseTitle, $when);
    $button   = mailButton('Voir mon historique', APP_URL . '/historique.php');

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Une place s'est libérée ! Tu étais <strong style="color: #ffd100;">premier(ère) sur la liste d'attente</strong> de cette séance :</p>
        {$box}
        <p style="font-size: 16px; margin-top: 20px;">Ta demande vient d'être transmise au coach. Tu recevras un mail dès qu'il l'aura validée.</p>
        <p style="margin: 28px 0 0;">{$button}</p>
        HTML);
    $text = "Bonjour {$firstname},\n\nUne place s'est libérée ! Tu étais premier(ère) sur la liste d'attente de cette séance :\n"
        . "{$courseTitle}\n{$when}\n\nTa demande vient d'être transmise au coach. Tu recevras un mail dès qu'il l'aura validée.\n\n"
        . "Une question ? Réponds simplement à ce mail.";

    return ['subject' => 'Une place s\'est libérée : ' . $courseTitle, 'html' => $html, 'text' => $text];
}

function sendWaitingListPromotedMail(string $email, string $firstname, string $courseTitle, string $when): bool
{
    return sendMail($email, $firstname, buildWaitingListPromotedMail($firstname, $courseTitle, $when));
}

// Notification au coach : un adhérent s'est désinscrit, et éventuellement un autre a été proposé depuis la liste d'attente (US7, US3)
function buildCoachUnregisterMail(string $coachFirstname, string $courseTitle, string $when, string $memberName, string $reason, ?string $promotedName, string $link): array
{
    $safeName   = htmlspecialchars($coachFirstname);
    $safeMember = htmlspecialchars($memberName);
    $safeReason = nl2br(htmlspecialchars($reason));
    $box        = mailSessionBox($courseTitle, $when);
    $promoted   = $promotedName === null
        ? ''
        : '<p style="font-size: 16px; margin-top: 20px;"><strong>' . htmlspecialchars($promotedName)
          . '</strong>, premier(ère) sur la liste d\'attente, t\'est proposé(e) : sa demande t\'attend sur la page de validation.</p>';
    $button = mailButton('Gérer les inscriptions', $link);

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;"><strong>{$safeMember}</strong> s'est désinscrit(e) de ta séance :</p>
        {$box}
        <p style="font-size: 16px; margin: 20px 0 4px;"><strong>Motif :</strong></p>
        <p style="font-size: 15px; margin: 0; padding: 12px 16px; border-left: 4px solid #ffd100; background: #161616;">{$safeReason}</p>
        {$promoted}
        <p style="margin: 28px 0 0;">{$button}</p>
        HTML);
    $text = "Bonjour {$coachFirstname},\n\n{$memberName} s'est désinscrit(e) de ta séance :\n{$courseTitle}\n{$when}\n\nMotif :\n{$reason}\n\n"
        . ($promotedName === null ? '' : "{$promotedName}, premier(ère) sur la liste d'attente, t'est proposé(e).\n\n")
        . "Gérer les inscriptions : {$link}";

    return ['subject' => 'Désinscription : ' . $courseTitle . ' (' . $when . ')', 'html' => $html, 'text' => $text];
}

function sendCoachUnregisterMail(string $email, string $coachFirstname, string $courseTitle, string $when, string $memberName, string $reason, ?string $promotedName, string $link): bool
{
    return sendMail($email, $coachFirstname, buildCoachUnregisterMail($coachFirstname, $courseTitle, $when, $memberName, $reason, $promotedName, $link));
}

// Rappel 2 jours avant la séance au coach : des demandes sont encore en attente (US6)
function buildCoachReminderMail(string $coachFirstname, string $courseTitle, string $when, int $pendingCount, string $link): array
{
    $safeName = htmlspecialchars($coachFirstname);
    $box      = mailSessionBox($courseTitle, $when);
    $requests = $pendingCount === 1 ? '1 demande d\'inscription est' : $pendingCount . ' demandes d\'inscription sont';
    $button   = mailButton('Traiter les demandes', $link);

    $html = mailLayout(<<<HTML
        <p style="font-size: 16px;">Bonjour {$safeName},</p>
        <p style="font-size: 16px;">Ta séance approche et <strong style="color: #ffd100;">{$requests} encore en attente</strong> :</p>
        {$box}
        <p style="font-size: 16px; margin-top: 20px;">Pense à les valider ou à les refuser pour que les adhérents sachent s'ils peuvent venir. Rien n'est fait automatiquement.</p>
        <p style="margin: 28px 0 0;">{$button}</p>
        HTML);
    $text = "Bonjour {$coachFirstname},\n\nTa séance approche et {$requests} encore en attente :\n{$courseTitle}\n{$when}\n\n"
        . "Pense à les valider ou à les refuser. Rien n'est fait automatiquement.\n\nTraiter les demandes : {$link}";

    return [
        'subject' => 'Rappel : ' . $pendingCount . ' demande' . ($pendingCount > 1 ? 's' : '') . ' en attente pour ' . $courseTitle,
        'html'    => $html,
        'text'    => $text,
    ];
}

function sendCoachReminderMail(string $email, string $coachFirstname, string $courseTitle, string $when, int $pendingCount, string $link): bool
{
    return sendMail($email, $coachFirstname, buildCoachReminderMail($coachFirstname, $courseTitle, $when, $pendingCount, $link));
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
