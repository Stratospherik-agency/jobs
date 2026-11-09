# Tableau de bord des candidatures

Application privée de Pierre-Ollivier Tissier, servie sur `jobs.pierreolliviertissier.ch`.
Elle centralise les opportunités d'emploi, la correspondance, les candidatures et les documents adaptés
à chaque poste. Aucune candidature n'est envoyée sans validation explicite dans l'interface.

Pile : Laravel 13, Filament 5, PHP 8.3, MySQL/MariaDB, hébergement Infomaniak.

## Fonctionnalités

- **Opportunités** : offres avec entreprise, poste, lieu, taux, source, lien, pertinence (1 à 3) et statut
  (mêmes statuts que le registre Google Sheets). Filtres, recherche, changement de statut en lot.
- **Suivi de candidature** : au passage à « Candidature envoyée », la date d'envoi et une relance à 10 jours
  sont remplies automatiquement ; un dossier clos (refus, écartée, offre reçue) n'a plus de relance.
- **Historique** : chaque changement de statut est journalisé, et des notes datées peuvent être ajoutées.
- **Tableau de bord** : chiffres clés, relances à faire, meilleures pistes à traiter.
- **Import du registre** : bouton « Importer le registre » (export CSV de Google Sheets), ou
  `php artisan registre:importer fichier.csv`. Les offres déjà présentes sont ignorées.
- **Candidatures** : sur chaque offre, préparer le dossier (destinataire, objet, lettre, pièces jointes)
  ou importer un brouillon Gmail. L'envoi part de Gmail uniquement après le bouton « Valider et envoyer »
  et la case de relecture cochée ; un dossier ne peut jamais partir deux fois. L'offre passe alors en
  « Candidature envoyée » et les documents sont archivés dans Drive (dossier « Candidatures »).
- **Synchronisation du registre** : le registre Google Sheets est relu automatiquement (à l'ouverture et
  toutes les 15 minutes). Les nouvelles offres sont ajoutées ; pour une offre existante, seuls les champs
  modifiés dans le registre depuis la synchronisation précédente sont reportés, sans écraser le suivi fait ici.
- **Réglages** : identifiants Google (code secret chiffré, jamais réaffiché), registre et dossier Drive,
  délai de relance, signature des candidatures, alertes de connexion. Les réglages priment sur le `.env`.
- **Automatisations et notifications** : score de pertinence sur 100, candidature préparée d'office,
  relances en brouillon Gmail, suivi des réponses (libellé « Jobs »), fiche de préparation d'entretien,
  veille des entreprises cibles, résumé du matin et bilan hebdomadaire. Notifications par e-mail et/ou dans
  la web app, réglables par type (heure, intervalle en jours). Rien ne part sans validation.
  Voir [docs/automatisations.md](docs/automatisations.md).
- **Correspondance** : les échanges Gmail avec le domaine de l'entreprise s'affichent sur la fiche.
- **Compte Google** : connexion OAuth limitée à Gmail et aux fichiers Drive créés par l'application,
  réservée au compte du propriétaire, avec lecture seule de Google Sheets ; jetons chiffrés en base. Mise en place : [docs/google-cloud.md](docs/google-cloud.md).

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
| Google | OAuth avec PKCE et contrôle `state`, seul le compte du propriétaire est accepté, jetons chiffrés, pièces jointes stockées hors du dossier public et servies seulement après connexion |
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
