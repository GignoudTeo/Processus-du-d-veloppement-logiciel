<?php
// Onglets de l'espace adhérent. À inclure dans la page, avec $accountTab = 'compte', 'seances', 'venues' ou 'progression'
$accountTabs = [
    'compte'      => ['label' => 'Mon profil', 'href' => 'compte.php'],
    'seances'     => ['label' => 'Mes séances', 'href' => 'historique.php'],
    'venues'      => ['label' => 'Mes venues', 'href' => 'historique.php?onglet=venues'],
    'progression' => ['label' => 'Ma progression', 'href' => 'progression.php'],
];
?>
<nav class="account-tabs" aria-label="Mon espace">
    <?php /* Noms de variables propres à ce fichier : il est inclus dans des pages qui ont leurs propres $tab, $key... */ ?>
    <?php foreach ($accountTabs as $accountTabKey => $accountTabLink): ?>
        <a href="<?= $accountTabLink['href'] ?>" class="<?= $accountTabKey === ($accountTab ?? '') ? 'is-active' : '' ?>"
           <?= $accountTabKey === ($accountTab ?? '') ? 'aria-current="page"' : '' ?>><?= $accountTabLink['label'] ?></a>
    <?php endforeach; ?>
</nav>
