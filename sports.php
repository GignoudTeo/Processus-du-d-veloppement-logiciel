<?php
require_once __DIR__ . '/includes/sports.php';

$pageTitle = 'Les sports';
$activePage = 'sports';
$categoryId = filter_input(INPUT_GET, 'categorie', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$categoryId = is_int($categoryId) ? $categoryId : null;
$categories = [];
$sports = [];
$databaseError = false;

try {
    $connection = getDatabaseConnection();
    $categories = getCategories($connection);
    $sports = getSports($connection, $categoryId);
} catch (PDOException $exception) {
    $databaseError = true;
}

require __DIR__ . '/includes/header.php';
?>

<section class="sports-section">
    <div class="container">
        <div class="sports-heading">
            <div>
                <p class="tag">Bouge à ta façon</p>
                <h1>Nos <span class="highlight">sports</span></h1>
                <p class="lead">Choisis une discipline et trouve l'entraînement qui te correspond.</p>
            </div>

            <form class="sports-filter" method="get" action="sports.php">
                <label for="category-filter">Catégorie</label>
                <select id="category-filter" name="categorie" onchange="this.form.submit()">
                    <option value="">Toutes les catégories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id_categorie'] ?>"
                            <?= $categoryId === (int) $category['id_categorie'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['nom_categorie'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline" type="submit">Filtrer</button>
            </form>
        </div>

        <?php if ($databaseError): ?>
            <p class="sports-message" role="status">
                Les sports ne sont pas disponibles pour le moment. Vérifie la configuration de la base de données.
            </p>
        <?php elseif ($sports === []): ?>
            <p class="sports-message" role="status">Aucun sport ne correspond à cette catégorie.</p>
        <?php else: ?>
            <div class="sports-grid">
                <?php foreach ($sports as $sport): ?>
                    <article class="sport-item">
                        <p class="sport-category">
                            <?= htmlspecialchars($sport['nom_categorie'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <h2><?= htmlspecialchars($sport['nom_sport'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="sport-description">
                            <?= htmlspecialchars($sport['description'] ?? 'Description à venir.', ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>