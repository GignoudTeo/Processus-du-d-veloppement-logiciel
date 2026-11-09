<?php
// Profil d'un adhérent vu par le coach (page des validations) : infos, statistiques et derniers motifs
// de refus / désinscription. Variables attendues : $member (ligne de getCoachBookingOverview),
// $memberDateLabel (ex. « Demande du »), $formatDate (date de la base -> DateTimeImmutable en heure de Paris)
$memberReasons = $member['historique_motifs'] ?? [];
$reasonLabels  = ['refus' => 'Refus', 'desinscription' => 'Désinscription'];
?>
<div class="member-profile">
    <p class="member-name"><?= htmlspecialchars($member['prenom'] . ' ' . $member['nom']) ?></p>
    <ul class="member-facts">
        <?php if ($member['age'] !== null): ?><li><?= $member['age'] ?> ans</li><?php endif; ?>
        <?php if ($member['poids']): ?><li><?= rtrim(rtrim(number_format((float) $member['poids'], 1, ',', ''), '0'), ',') ?> kg</li><?php endif; ?>
        <?php if ($member['taille']): ?><li><?= (int) $member['taille'] ?> cm</li><?php endif; ?>
        <li>Membre depuis le <?= $formatDate($member['date_creation'])->format('d/m/Y') ?></li>
    </ul>
    <ul class="member-facts is-stats">
        <li><strong><?= (int) $member['nb_reservations'] ?></strong> réservation(s)</li>
        <li><strong><?= (int) $member['nb_reservations_rejetees'] ?></strong> refus</li>
        <li><strong><?= (int) $member['nb_absences'] ?></strong> désinscription(s)</li>
    </ul>

    <?php if ($memberReasons !== []): ?>
        <details class="member-reasons">
            <summary>Derniers motifs (<?= count($memberReasons) ?>)</summary>
            <ul>
                <?php foreach ($memberReasons as $event): ?>
                    <li>
                        <span class="course-badge<?= $event['type'] === 'refus' ? ' is-full' : ' is-pending' ?>"><?= $reasonLabels[$event['type']] ?? htmlspecialchars($event['type']) ?></span>
                        <strong><?= htmlspecialchars($event['motif']) ?></strong>
                        <span class="muted-text">
                            · <?= htmlspecialchars($event['titre_cours']) ?>, le <?= $formatDate($event['date_cours'])->format('d/m/Y') ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php else: ?>
        <p class="muted-text member-date">Aucun refus ni désinscription.</p>
    <?php endif; ?>

    <p class="muted-text member-date"><?= htmlspecialchars($memberDateLabel) ?> <?= $formatDate($member['date_reservation'])->format('d/m/Y à H:i') ?></p>
</div>
