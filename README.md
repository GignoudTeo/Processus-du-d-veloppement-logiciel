# FitPass : application fitness

Projet M1, Processus du développement logiciel (méthode Scrum).

## Technologies

- PHP 8 (sans framework)
- HTML / CSS / JavaScript
- [qrcodejs](https://github.com/davidshimjs/qrcodejs) (via CDN) pour dessiner le QR code
- Polices Google Fonts : Anton (titres) et Inter (texte)

## Lancer le projet

### Avec MAMP

Un virtual host Apache pointe sur le dossier du projet, sur le port 8082.

1. Démarrer les serveurs dans MAMP (bouton **Start**)
2. Ouvrir http://localhost:8082/

Configuration utilisée :

- `/Applications/MAMP/conf/apache/httpd.conf` : ajout de `Listen 8082`
- `/Applications/MAMP/conf/apache/extra/httpd-vhosts.conf` :

```apache
<VirtualHost *:8082>
    DocumentRoot "/chemin/vers/Processus-du-d-veloppement-logiciel"
    ServerName localhost

    <Directory "/chemin/vers/Processus-du-d-veloppement-logiciel">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Sans MAMP

Avec le serveur intégré de PHP, depuis la racine du projet :

```bash
php -S localhost:8000
```

Puis ouvrir http://localhost:8000.

## Pages

| Page | URL (MAMP) | Description |
|---|---|---|
| Accueil | http://localhost:8082/ | Présentation et accès au QR code |
| Mon QR code | http://localhost:8082/qrcode.php | QR code d'accès à la salle, renouvelé toutes les 60 s |

## Structure

```
index.php              Page d'accueil
qrcode.php             Page "Mon QR code" (accès à la salle)
api/qr-token.php       Renvoie un nouveau jeton de QR code (JSON)
includes/config.php    Réglages (nom de l'appli, clé secrète, durée de validité, n° d'adhérent)
includes/qrcode.php    Génération et vérification des jetons
includes/header.php    En-tête commun (logo, menu)
includes/footer.php    Pied de page commun
assets/css/style.css   Styles (thème noir / jaune, responsive)
assets/js/main.js      Menu burger sur mobile
assets/js/qrcode.js    Affichage, compte à rebours et rafraîchissement du QR code
```

## Design

- Thème sombre avec un jaune vif (`#ffd100`) comme couleur d'accent
- Titres en majuscules avec la police Anton
- Accueil : grand titre, téléphone animé avec un QR code
- Page QR code : carte "pass" avec barre de progression jusqu'au prochain code
- Les couleurs sont regroupées en variables CSS en haut de `style.css`
- Les animations sont désactivées si l'utilisateur a activé "réduire les animations"

## QR code d'accès

Le QR code contient un jeton `FITPASS:<id>:<expiration>:<signature>` signé en HMAC-SHA256
avec la clé `QR_SECRET` (`includes/config.php`).

- Le jeton expire après 60 secondes (`QR_VALIDITY`)
- La page demande un nouveau jeton à `api/qr-token.php` à la fin du compte à rebours,
  une capture d'écran ne permet donc pas d'entrer
- `verifyAccessToken()` (`includes/qrcode.php`) permet de valider un code scanné
  et renvoie l'identifiant de l'adhérent, ou `null` si le code est faux ou expiré

Il n'y a pas de gestion de comptes pour l'instant : le numéro d'adhérent est fixé
par `MEMBER_ID` dans `includes/config.php`.

## Sprints

- **Sprint 1** : page d'accueil + style, page QR code d'accès
