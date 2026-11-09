</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <a href="index.php" class="logo logo-small">FIT<span>PASS</span></a>
        <p>&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
    </div>
</footer>
<script src="assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
<?php if (!empty($extraScripts)): ?>
    <?php foreach ($extraScripts as $script): ?>
        <script src="<?= $script ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
<?php
// Rappel 2 jours avant la séance aux coachs (US6), sans tâche planifiée : vérifiés au premier chargement de page de chaque heure.
// La page est d'abord envoyée au navigateur, la vérification (et l'envoi des mails) se fait ensuite
require_once __DIR__ . '/reminders.php';
ignore_user_abort(true);  // la vérification continue même si la personne change de page entre-temps
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
runRemindersIfDue(new DateTimeImmutable('now', new DateTimeZone(PLANNING_TIMEZONE)));
?>
