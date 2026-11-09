// Suivi de progression : formulaire, tableau et graphique mis à jour sans recharger la page (US11)
(function () {
    const root = document.getElementById('progression');
    if (!root) return;

    const form = root.querySelector('.progression-form');
    const alertBox = form.querySelector('.form-alert');
    const cancelButton = form.querySelector('.progression-cancel');
    const tableBody = root.querySelector('.progression-history tbody');
    const emptyMessage = root.querySelector('.progression-empty');
    const chartBox = root.querySelector('.progression-chart');
    const indicators = {
        poids: { label: 'Poids (kg)', color: '#ffd100' },
        imc: { label: 'IMC', color: '#38bdf8' },
        duree_seance: { label: 'Durée de séance (min)', color: '#4ade80' },
    };

    let measures = JSON.parse(root.dataset.mesures);
    let indicator = 'poids';
    let chart = null;

    function formatNumber(value) {
        return Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 1 });
    }

    function formatDate(isoDate) {
        const parts = isoDate.split('-');
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function renderChart() {
        const empty = measures.length === 0;
        emptyMessage.hidden = !empty;
        chartBox.hidden = empty;
        if (empty || typeof Chart === 'undefined') return;

        const settings = indicators[indicator];
        const data = {
            labels: measures.map(function (m) { return formatDate(m.date_mesure); }),
            datasets: [{
                label: settings.label,
                data: measures.map(function (m) { return m[indicator]; }),
                borderColor: settings.color,
                backgroundColor: settings.color + '33',
                pointBackgroundColor: settings.color,
                fill: true,
                tension: 0.3,
            }],
        };

        if (chart) {
            chart.data = data;
            chart.update();
            return;
        }

        chart = new Chart(document.getElementById('progression-chart'), {
            type: 'line',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#ffffff' } } },
                scales: {
                    x: { ticks: { color: '#a3a3a3' }, grid: { color: '#2a2a2a' } },
                    y: { ticks: { color: '#a3a3a3' }, grid: { color: '#2a2a2a' } },
                },
            },
        });
    }

    function renderTable() {
        tableBody.textContent = '';
        // Les plus récentes en haut du tableau
        measures.slice().reverse().forEach(function (measure) {
            const row = document.createElement('tr');
            [formatDate(measure.date_mesure), formatNumber(measure.poids) + ' kg', formatNumber(measure.taille) + ' cm',
             formatNumber(measure.imc), measure.duree_seance + ' min'].forEach(function (text) {
                const cell = document.createElement('td');
                cell.textContent = text;
                row.appendChild(cell);
            });

            const actions = document.createElement('td');
            actions.className = 'progression-actions';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn btn-outline btn-small';
            edit.textContent = 'Modifier';
            edit.addEventListener('click', function () { startEdit(measure); });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-danger btn-small';
            remove.textContent = 'Supprimer';
            remove.addEventListener('click', function () { removeMeasure(measure); });
            actions.append(edit, remove);
            row.appendChild(actions);

            tableBody.appendChild(row);
        });
    }

    function render() {
        renderChart();
        renderTable();
    }

    function showErrors(errors, message) {
        form.querySelectorAll('[data-error]').forEach(function (element) {
            const text = errors[element.dataset.error];
            element.textContent = text || '';
            element.hidden = !text;
        });
        alertBox.textContent = message || '';
        alertBox.hidden = !message;
    }

    function resetForm() {
        form.reset();
        form.elements.id_mesure.value = '';
        form.elements.date_mesure.value = form.elements.date_mesure.max;
        form.querySelector('.progression-form-title').textContent = 'Nouvelle mesure';
        cancelButton.hidden = true;
        showErrors({});
    }

    function startEdit(measure) {
        form.elements.id_mesure.value = measure.id_mesure;
        ['date_mesure', 'poids', 'taille', 'duree_seance'].forEach(function (name) {
            form.elements[name].value = measure[name];
        });
        form.querySelector('.progression-form-title').textContent = 'Modifier la mesure du ' + formatDate(measure.date_mesure);
        cancelButton.hidden = false;
        showErrors({});
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Envoie une action à l'API et met tout à jour avec la liste renvoyée
    async function send(payload) {
        const response = await fetch('api/progression.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
            body: JSON.stringify(payload),
        });
        const body = await response.json().catch(function () { return {}; });

        if (!response.ok) {
            showErrors(body.errors || {}, body.error || (body.errors ? '' : 'Une erreur est survenue, réessaie.'));
            return false;
        }

        measures = body.mesures;
        render();
        return true;
    }

    async function removeMeasure(measure) {
        if (!window.confirm('Supprimer la mesure du ' + formatDate(measure.date_mesure) + ' ?')) return;
        if (await send({ action: 'delete', id_mesure: measure.id_mesure }) && String(form.elements.id_mesure.value) === String(measure.id_mesure)) {
            resetForm();
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const id = form.elements.id_mesure.value;
        const payload = {
            action: id ? 'update' : 'create',
            id_mesure: id || null,
            date_mesure: form.elements.date_mesure.value,
            poids: form.elements.poids.value,
            taille: form.elements.taille.value,
            duree_seance: form.elements.duree_seance.value,
        };

        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            if (await send(payload)) resetForm();
        } catch (error) {
            showErrors({}, 'Connexion impossible, réessaie.');
        } finally {
            button.disabled = false;
        }
    });

    cancelButton.addEventListener('click', resetForm);

    root.querySelectorAll('[data-indicator]').forEach(function (button) {
        button.addEventListener('click', function () {
            indicator = button.dataset.indicator;
            root.querySelectorAll('[data-indicator]').forEach(function (other) {
                other.classList.toggle('is-active', other === button);
            });
            renderChart();
        });
    });

    render();
})();
