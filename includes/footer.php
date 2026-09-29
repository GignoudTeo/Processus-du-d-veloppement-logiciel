</main>
<footer class="site-footer">
    <div class="container footer-inner">
        <a href="index.php" class="logo logo-small">FIT<span>PASS</span></a>
        <p>&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
    </div>
</footer>
<script src="assets/js/main.js"></script>
<?php if (!empty($extraScripts)): ?>
    <?php foreach ($extraScripts as $script): ?>
        <script src="<?= $script ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
