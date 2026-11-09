<?php
require_once __DIR__ . '/history.php';

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

// Choix d'un motif : liste prédéfinie + « Autre » qui affiche un champ libre (voir main.js).
// Champs envoyés : {$prefix}_choix et {$prefix}_autre. $selected : motif à présélectionner (ex. « Séance complète »)
function reasonFields(string $prefix, string $label, array $reasons, ?string $error = null, ?string $selected = null, string $idSuffix = ''): string
{
    $id      = htmlspecialchars($prefix . $idSuffix);
    $options = '<option value="">Choisir un motif</option>';
    foreach ($reasons as $reason) {
        $options .= '<option value="' . htmlspecialchars($reason) . '"' . ($reason === $selected ? ' selected' : '') . '>'
            . htmlspecialchars($reason) . '</option>';
    }
    $options .= '<option value="autre">Autre…</option>';

    return '<div class="field reason-field" data-reason>'
        . '<label for="' . $id . '-choix">' . htmlspecialchars($label) . '</label>'
        . '<select id="' . $id . '-choix" name="' . htmlspecialchars($prefix) . '_choix" required>' . $options . '</select>'
        . '<textarea name="' . htmlspecialchars($prefix) . '_autre" rows="2" maxlength="1000" aria-label="Préciser le motif"'
        . ' placeholder="Précise le motif" hidden></textarea>'
        . ($error ? '<p class="field-error">' . htmlspecialchars($error) . '</p>' : '')
        . '</div>';
}

// Motif envoyé par reasonFields() : ['motif' => ..., 'error' => null|string]
function reasonFromPost(array $post, string $prefix, array $reasons): array
{
    return validateReason((string) ($post[$prefix . '_choix'] ?? ''), (string) ($post[$prefix . '_autre'] ?? ''), $reasons);
}
