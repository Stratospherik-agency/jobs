# Tableau de bord des candidatures

Application privée de Pierre-Ollivier Tissier, servie sur `jobs.pierreolliviertissier.ch`.
Elle centralise les opportunités d'emploi, la correspondance, les candidatures et les documents adaptés
à chaque poste. Aucune candidature n'est envoyée sans validation explicite dans l'interface.

Pile : Laravel 13, Filament 5, PHP 8.3, MySQL/MariaDB, hébergement Infomaniak.

## Sécurité en place

| Mesure | Détail |
| --- | --- |
| Compte unique | Seule l'adresse `DASHBOARD_OWNER_EMAIL` peut entrer ; pas d'inscription ni de « mot de passe oublié » en ligne |
| Double authentification obligatoire | Application TOTP (Google Authenticator, 1Password…) + 8 codes de secours ; secret chiffré en base |
| Mot de passe | 14 caractères minimum, majuscules, chiffres, symboles ; refus des mots de passe présents dans des fuites connues |
| Tentatives limitées | 5 essais par minute pour le mot de passe et pour le code |
| Alerte | E-mail à chaque connexion réussie ; journal `storage/logs/security-*.log` conservé 90 jours |
| Non indexé | `robots.txt` (Disallow), en-tête `X-Robots-Tag`, balise meta `robots` |
| En-têtes | CSP, HSTS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store` |
| Sessions | Chiffrées, cookie `Secure` + `HttpOnly`, expiration après 60 min et à la fermeture du navigateur |
| Aucun service tiers | Polices système et avatars générés localement : aucune requête vers un autre domaine |
| Dépendances | `composer audit` à chaque pull request, Dependabot chaque semaine |

Limite connue : Filament (Alpine.js, Livewire) exige `'unsafe-inline'` et `'unsafe-eval'` dans la CSP
pour les scripts. La CSP bloque en revanche tout script ou ressource venant d'un autre domaine.

## Développement local

```bash
composer install
cp .env.example .env   # puis APP_ENV=local, APP_DEBUG=true, DB_CONNECTION=sqlite, SESSION_SECURE_COOKIE=false, MAIL_MAILER=log
php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan filament:assets
php artisan dashboard:owner      # crée le compte propriétaire
php artisan serve
```

Tests : `php artisan test`

## Déploiement

Voir [docs/deploiement-infomaniak.md](docs/deploiement-infomaniak.md).

## Commandes utiles

| Commande | Usage |
| --- | --- |
| `php artisan dashboard:owner` | Créer le compte propriétaire, ou changer son mot de passe (ferme toutes les sessions) |
| `php artisan dashboard:owner --reset-2fa` | Supprimer la double authentification (téléphone perdu et codes de secours épuisés) |
