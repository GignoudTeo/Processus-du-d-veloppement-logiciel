(function () {
    const card = document.getElementById('qr-card');
    if (!card || typeof QRCode === 'undefined') return;

    const countdownEl = document.getElementById('qr-countdown');
    const progressBar = document.getElementById('qr-progress-bar');
    const refreshBtn = document.getElementById('qr-refresh');
    const validity = parseInt(card.dataset.validity, 10);

    const qr = new QRCode(document.getElementById('qr-code'), {
        text: card.dataset.token,
        width: 220,
        height: 220,
        colorDark: '#0a0a0a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M,
    });

    let remaining = validity;

    function updateCountdown() {
        countdownEl.textContent = remaining;
        progressBar.style.width = (remaining / validity * 100) + '%';
    }

    async function refreshToken() {
        refreshBtn.disabled = true;
        try {
            const response = await fetch('api/qr-token.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();

            qr.makeCode(data.token);
            remaining = validity;
            updateCountdown();
        } catch (error) {
            console.error('Impossible de régénérer le QR code :', error);
            remaining = 5; // nouvel essai dans 5 s
        } finally {
            refreshBtn.disabled = false;
        }
    }

    setInterval(function () {
        remaining--;
        if (remaining <= 0) {
            refreshToken();
            return;
        }
        updateCountdown();
    }, 1000);

    refreshBtn.addEventListener('click', refreshToken);
})();
