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
     * Rappel « toujours sans réponse », en jours après la relance.
     */
    'reminder_days' => (int) env('DASHBOARD_REMINDER_DAYS', 21),

    /*
     * Automatisations (toutes modifiables dans Réglages). Aucune ne peut envoyer de candidature
     * ni d'e-mail à un recruteur : elles préparent, rangent et préviennent le propriétaire.
     */
    'auto_prepare' => true,              // préparer d'office la candidature des offres très pertinentes
    'auto_prepare_min_score' => 75,      // score minimal (sur 100), en plus des offres notées 3/3
    'auto_prepare_top_rated' => true,    // les offres notées 3/3 dans le registre sont aussi préparées
    'follow_up_drafts' => true,          // créer le brouillon de relance dans Gmail
    'follow_up_template' => null,        // modèle de relance (sinon celui par défaut)
    'response_auto_status' => true,      // mettre à jour le statut quand un refus ou un entretien est détecté
    'gmail_label' => 'Jobs',             // seul libellé Gmail lu (alertes, réponses, veille)
    'proof_bank' => null,                // banque de preuves chiffrées (fiches d'entretien)

    /*
     * Préférences de notification, par type (voir App\Services\Automation\NotificationCenter).
     */
    'notifications' => [],

    /*
     * Proxys de confiance (adresses IP séparées par des virgules, ou *).
     * Laisser vide si le site n'est pas derrière un proxy.
     */
    'trusted_proxies' => env('TRUSTED_PROXIES'),
];
