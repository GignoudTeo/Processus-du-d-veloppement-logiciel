<?php
// Valeur déjà saisie, pour pré-remplir un champ après une erreur
function old(array $data, string $field): string
{
    return htmlspecialchars($data[$field] ?? '');
}

function fieldError(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<p class="field-error">' . htmlspecialchars($errors[$field]) . '</p>'
        : '';
}
