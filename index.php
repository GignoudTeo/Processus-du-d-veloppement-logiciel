<?php
$pageTitle  = 'Accueil';
$activePage = 'accueil';

require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="hero-bg" aria-hidden="true">FIT</div>

    <div class="container hero-inner">
        <div class="hero-text">
            <p class="tag">Salle de sport</p>
            <h1>
                Entre<br>
                Transpire<br>
                <span class="highlight">Recommence</span>
            </h1>
            <a href="qrcode.php" class="btn btn-lg">Afficher mon QR code</a>
        </div>

        <div class="phone" aria-hidden="true">
            <div class="phone-screen">
                <p class="phone-label">Pass accès</p>
                <svg class="phone-qr" viewBox="0 0 21 21" shape-rendering="crispEdges">
                    <path fill="#0a0a0a" d="M0 0h7v7H0zM14 0h7v7h-7zM0 14h7v7H0z"/>
                    <path fill="#fff" d="M1 1h5v5H1zM15 1h5v5h-5zM1 15h5v5H1z"/>
                    <path fill="#0a0a0a" d="M2 2h3v3H2zM16 2h3v3h-3zM2 16h3v3H2z
                        M8 0h1v1H8zM10 1h2v1h-2zM8 3h2v1H8zM11 3h1v2h-1zM9 5h1v1H9zM12 6h1v1h-1z
                        M8 8h2v1H8zM11 8h3v1h-3zM0 8h2v1H0zM3 9h2v1H3zM6 8h1v2H6zM15 8h2v1h-2zM18 8h3v1h-3z
                        M1 10h1v2H1zM4 11h2v1H4zM8 10h1v2H8zM10 10h2v1h-2zM13 10h1v2h-1zM16 10h2v1h-2zM19 10h1v2h-1z
                        M0 12h1v1H0zM7 12h2v1H7zM11 12h1v1h-1zM15 12h1v2h-1zM17 12h3v1h-3z
                        M8 14h3v1H8zM12 14h2v1h-2zM17 14h1v2h-1zM19 14h2v1h-2z
                        M9 16h1v2H9zM11 16h3v1h-3zM15 16h1v1h-1zM18 17h2v1h-2z
                        M8 19h2v1H8zM12 18h1v3h-1zM14 19h3v1h-3zM19 19h2v2h-2z"/>
                </svg>
                <p class="phone-scan">Scanne à l'entrée</p>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
