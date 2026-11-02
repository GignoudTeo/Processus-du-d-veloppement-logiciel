// Planning sur téléphone : un seul jour affiché, choisi avec les onglets ou en glissant le doigt
(function () {
    const tabs = Array.from(document.querySelectorAll('.tt-tab'));
    if (tabs.length === 0) return;

    function showDay(tab) {
        const day = tab.dataset.day;

        document.querySelectorAll('.tt-tab, .tt-day-head, .tt-day').forEach(function (element) {
            element.classList.toggle('is-active', element.dataset.day === day);
        });
        tabs.forEach(function (other) {
            other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            showDay(tab);
        });
    });

    // Glisser vers la gauche : jour suivant, vers la droite : jour précédent.
    // Au bout de la semaine, on passe à la semaine suivante / précédente.
    const wrapper = document.querySelector('.tt-wrapper');
    const isPhone = window.matchMedia('(max-width: 700px)');
    let startX = null;
    let startY = null;

    wrapper.addEventListener('touchstart', function (event) {
        startX = event.touches[0].clientX;
        startY = event.touches[0].clientY;
    }, { passive: true });

    wrapper.addEventListener('touchend', function (event) {
        if (!isPhone.matches || startX === null) return;

        const deltaX = event.changedTouches[0].clientX - startX;
        const deltaY = event.changedTouches[0].clientY - startY;
        startX = null;

        // Geste trop court ou plutôt vertical (défilement de la page) : on ignore
        if (Math.abs(deltaX) < 60 || Math.abs(deltaX) < Math.abs(deltaY) * 1.5) return;

        const current = tabs.findIndex(function (tab) {
            return tab.classList.contains('is-active');
        });
        const next = current + (deltaX < 0 ? 1 : -1);

        if (next >= 0 && next < tabs.length) {
            showDay(tabs[next]);
        } else {
            const link = document.querySelector(deltaX < 0 ? '[data-week="next"]' : '[data-week="prev"]');
            if (link) window.location.href = link.href;
        }
    }, { passive: true });
})();

// Ligne "maintenant" : mise à jour chaque minute pendant que la page reste ouverte
(function () {
    const line = document.querySelector('.tt-now');
    const grid = document.querySelector('.timetable');
    if (!line || !grid) return;

    const firstHour = parseInt(grid.dataset.firstHour, 10);
    const lastHour = parseInt(grid.dataset.lastHour, 10);

    function update() {
        const now = new Date();
        const minutes = (now.getHours() - firstHour) * 60 + now.getMinutes();
        const total = (lastHour - firstHour) * 60;

        line.hidden = minutes < 0 || minutes >= total;
        line.style.top = (minutes / total * 100) + '%';
    }

    update();
    setInterval(update, 60 * 1000);
})();

// Fenêtre de détail : s'ouvre au clic (ou Entrée / Espace) sur un cours
(function () {
    const dialog = document.getElementById('course-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    function field(name) {
        return dialog.querySelector('[data-field="' + name + '"]');
    }

    function open(courseElement) {
        const course = JSON.parse(courseElement.dataset.course);

        // textContent : le texte est affiché tel quel, jamais interprété comme du HTML.
        // Un champ vide (null) est masqué.
        dialog.querySelectorAll('[data-field]').forEach(function (element) {
            const value = course[element.dataset.field];
            element.textContent = value || '';
            element.hidden = !value;
        });

        // Sections entières (salle, bio du coach, sport) masquées si l'info manque
        dialog.querySelectorAll('[data-section]').forEach(function (element) {
            element.hidden = !course[element.dataset.section];
        });

        field('statut').classList.toggle('is-full', course.statut === 'Complet');
        field('places').className = 'course-dialog-places is-' + course.niveau;

        dialog.style.setProperty('--sport-color', course.couleur);
        dialog.querySelector('.course-dialog-bar div').style.width = course.remplissage + '%';

        // Lien présent seulement pour un coach (sur ses séances) ou un administrateur (sur toutes)
        const editLink = dialog.querySelector('.course-dialog-edit');
        if (editLink) {
            editLink.hidden = !course.lien_modifier;
            editLink.href = course.lien_modifier || '#';
        }

        showParticipants(course);
        showBooking(course);

        dialog.showModal();
    }

    // Bouton d'inscription : présent seulement pour un compte avec un profil adhérent, masqué si le cours a commencé
    function showBooking(course) {
        const form = dialog.querySelector('.course-dialog-booking');
        if (!form) return;

        form.hidden = !course.inscription;
        if (!course.inscription) return;

        const registered = course.inscription === 'inscrit';
        const button = form.querySelector('button');
        const labels = { inscrit: 'Se désinscrire', complet: 'Complet', disponible: "S'inscrire" };

        form.elements.id_cours.value = course.id_cours;
        form.elements.action.value = registered ? 'desinscrire' : 'inscrire';
        form.querySelector('.course-dialog-booked').hidden = !registered;
        button.textContent = labels[course.inscription];
        button.disabled = course.inscription === 'complet';
        button.classList.toggle('btn-danger', registered);
    }

    // Liste des inscrits : reçue seulement pour une séance que la personne connectée gère
    function showParticipants(course) {
        const list = dialog.querySelector('.course-dialog-participants');
        const template = document.getElementById('participant-row');
        if (!list || !template || !course.inscrits) return;

        list.textContent = '';
        dialog.querySelector('.course-dialog-no-participant').hidden = course.inscrits.length > 0;

        course.inscrits.forEach(function (participant) {
            const name = participant.prenom + ' ' + participant.nom;
            const row = template.content.cloneNode(true);
            const form = row.querySelector('form');

            row.querySelector('.course-dialog-participant-name').textContent = name;

            // Pas de croix sur une séance déjà commencée
            if (course.retrait_possible) {
                form.elements.id_reservation.value = participant.id_reservation;
                form.querySelector('button').setAttribute('aria-label', 'Retirer ' + name);
                form.addEventListener('submit', function (event) {
                    if (!window.confirm('Retirer ' + name + ' de cette séance ? Cette personne sera prévenue par mail.')) {
                        event.preventDefault();
                    }
                });
            } else {
                form.remove();
            }

            list.appendChild(row);
        });
    }

    document.querySelectorAll('.tt-course[data-course]').forEach(function (courseElement) {
        courseElement.addEventListener('click', function () {
            open(courseElement);
        });
        courseElement.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open(courseElement);
            }
        });
    });

    dialog.querySelector('.course-dialog-close').addEventListener('click', function () {
        dialog.close();
    });

    // Clic sur le fond assombri (en dehors du contenu) : on ferme
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) dialog.close();
    });
})();
