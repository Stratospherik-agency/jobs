<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Accès OAuth au compte Google du propriétaire (Gmail et Drive).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/google/callback'),
        'scopes' => [
            'openid',
            'email',
            // Lire les échanges, importer les brouillons et envoyer les candidatures validées.
            'https://www.googleapis.com/auth/gmail.modify',
            // Uniquement les fichiers et dossiers créés par le tableau de bord.
            'https://www.googleapis.com/auth/drive.file',
            // Lecture seule du registre des candidatures (synchronisation automatique).
            'https://www.googleapis.com/auth/spreadsheets.readonly',
        ],
        // Registre Google Sheets synchronisé avec le tableau de bord.
        'registry_spreadsheet_id' => env('GOOGLE_REGISTRY_SHEET_ID', '1hJl9PTZBWSL_U-Oo1oTvv9NdSqzbTNYMGmwcI5dW_Ac'),
        'registry_range' => env('GOOGLE_REGISTRY_RANGE', 'Offres!A1:Z1000'),
        'drive_root_folder' => env('GOOGLE_DRIVE_FOLDER', 'Candidatures'),
    ],

];
