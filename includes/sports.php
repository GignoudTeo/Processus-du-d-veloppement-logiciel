<?php
require_once __DIR__ . '/config.php';

function getCategories(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT id_categorie, nom_categorie FROM categories ORDER BY nom_categorie'
    );

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function getSports(PDO $connection, ?int $categoryId = null): array
{
    $sql = 'SELECT sports.id_sport, sports.nom_sport, sports.description,
                   categories.id_categorie, categories.nom_categorie
            FROM sports
            INNER JOIN categories ON categories.id_categorie = sports.id_categorie';

    if ($categoryId !== null) {
        $sql .= ' WHERE categories.id_categorie = :category_id';
    }

    $sql .= ' ORDER BY categories.nom_categorie, sports.nom_sport';
    $statement = $connection->prepare($sql);

    if ($categoryId !== null) {
        $statement->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
    }

    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}