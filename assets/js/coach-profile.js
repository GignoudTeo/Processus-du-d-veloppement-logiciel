// Profil coach : courbe du nombre de présents par semaine ou par mois (US12)
(function () {
    const canvas = document.getElementById('presence-chart');
    if (!canvas || typeof Chart === 'undefined') return;

    const timeline = JSON.parse(canvas.dataset.timeline);

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: timeline.map(function (point) { return point.label; }),
            datasets: [{
                label: 'Présents',
                data: timeline.map(function (point) { return point.presents; }),
                borderColor: '#ffd100',
                backgroundColor: '#ffd10033',
                pointBackgroundColor: '#ffd100',
                fill: true,
                tension: 0.3,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#ffffff' } } },
            scales: {
                x: { ticks: { color: '#a3a3a3' }, grid: { color: '#2a2a2a' } },
                y: { beginAtZero: true, ticks: { color: '#a3a3a3', precision: 0 }, grid: { color: '#2a2a2a' } },
            },
        },
    });
})();
