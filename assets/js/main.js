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

// Bouton "Retour" : le navigateur ressort la page de sa mémoire (bfcache),
// on la recharge pour afficher l'état réel de la session (ex. après déconnexion)
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
