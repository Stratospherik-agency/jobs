<?php

return [
    /*
     * Adresse e-mail du seul compte autorisé à accéder au tableau de bord.
     * Tout autre compte présent en base est refusé.
     */
    'owner_email' => env('DASHBOARD_OWNER_EMAIL'),

    /*
     * Envoyer un e-mail d'alerte à chaque connexion réussie.
     */
    'login_alerts' => (bool) env('DASHBOARD_LOGIN_ALERTS', true),

    /*
     * Nombre de jours entre l'envoi d'une candidature et la relance prévue.
     */
    'follow_up_days' => (int) env('DASHBOARD_FOLLOW_UP_DAYS', 10),

    /*
     * Signature ajoutée au message d'une nouvelle candidature (modifiable dans Réglages).
     */
    'signature' => env('DASHBOARD_SIGNATURE'),

    /*
     * Proxys de confiance (adresses IP séparées par des virgules, ou *).
     * Laisser vide si le site n'est pas derrière un proxy.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES'),
];
