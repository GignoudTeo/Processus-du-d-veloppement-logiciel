// Menu burger (mobile)
(function () {
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.querySelector('.main-nav');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', isOpen);
    });
})();

// Menus déroulants du header (Admin, compte) : un seul ouvert, fermés au clic dehors ou avec Échap
(function () {
    const dropdowns = document.querySelectorAll('.nav-dropdown');

    dropdowns.forEach(function (dropdown) {
        dropdown.addEventListener('toggle', function () {
            if (!dropdown.open) return;
            dropdowns.forEach(function (other) {
                if (other !== dropdown) other.open = false;
            });
        });
    });

    document.addEventListener('click', function (event) {
        dropdowns.forEach(function (dropdown) {
            if (!dropdown.contains(event.target)) dropdown.open = false;
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        dropdowns.forEach(function (dropdown) {
            dropdown.open = false;
        });
    });
})();

// Choix d'un motif : le champ libre n'apparaît (et n'est obligatoire) que pour « Autre »
document.querySelectorAll('[data-reason]').forEach(function (field) {
    const select = field.querySelector('select');
    const other = field.querySelector('textarea');

    function update() {
        const isOther = select.value === 'autre';
        other.hidden = !isOther;
        other.required = isOther;
        if (isOther) other.focus();
    }

    select.addEventListener('change', update);
    other.hidden = select.value !== 'autre';
});

// Bouton "Retour" : le navigateur ressort la page de sa mémoire (bfcache),
// on la recharge pour afficher l'état réel de la session (ex. après déconnexion)
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
