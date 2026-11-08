# Notes pour Claude

- Langue du projet : français (interface, messages, documentation, commits).
- Application à compte unique : ne jamais ajouter d'inscription, de réinitialisation de mot de passe par e-mail
  ni de route publique. Toute page passe par le panneau Filament (`app/Providers/Filament/AdminPanelProvider.php`).
- Ne charger aucune ressource depuis un domaine tiers (polices, images, scripts) : la CSP de
  `app/Http/Middleware/SecurityHeaders.php` les bloque.
- Aucune candidature ne doit pouvoir partir sans une action explicite de validation du propriétaire.
- Avant de pousser : `php artisan test` et `composer audit`.
