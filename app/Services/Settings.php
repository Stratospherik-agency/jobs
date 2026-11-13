<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Réglages enregistrés depuis l'interface. Ils priment sur le fichier .env :
 * chaque réglage renseigné remplace la valeur de configuration correspondante.
 */
class Settings
{
    /** Réglage → clé de configuration. */
    public const KEYS = [
        'google_client_id' => 'services.google.client_id',
        'google_client_secret' => 'services.google.client_secret',
        'google_registry_sheet_id' => 'services.google.registry_spreadsheet_id',
        'google_registry_range' => 'services.google.registry_range',
        'google_drive_folder' => 'services.google.drive_root_folder',
        'follow_up_days' => 'dashboard.follow_up_days',
        'signature' => 'dashboard.signature',
        'login_alerts' => 'dashboard.login_alerts',
        'reminder_days' => 'dashboard.reminder_days',
        'auto_prepare' => 'dashboard.auto_prepare',
        'auto_prepare_min_score' => 'dashboard.auto_prepare_min_score',
        'auto_prepare_top_rated' => 'dashboard.auto_prepare_top_rated',
        'follow_up_drafts' => 'dashboard.follow_up_drafts',
        'follow_up_template' => 'dashboard.follow_up_template',
        'response_auto_status' => 'dashboard.response_auto_status',
        'gmail_label' => 'dashboard.gmail_label',
        'proof_bank' => 'dashboard.proof_bank',
        'notifications' => 'dashboard.notifications',
        // Envoi des e-mails du tableau de bord (serveur SMTP, par exemple la boîte Infomaniak).
        'mail_host' => 'mail.mailers.smtp.host',
        'mail_port' => 'mail.mailers.smtp.port',
        'mail_scheme' => 'mail.mailers.smtp.scheme',
        'mail_username' => 'mail.mailers.smtp.username',
        'mail_password' => 'mail.mailers.smtp.password',
        'mail_from_address' => 'mail.from.address',
        'mail_from_name' => 'mail.from.name',
        'notification_email' => 'dashboard.notification_email',
    ];

    /** Réglages jamais renvoyés vers le navigateur. */
    public const SECRETS = ['google_client_secret', 'mail_password'];

    private const CASTS = [
        'follow_up_days' => 'int',
        'login_alerts' => 'bool',
        'reminder_days' => 'int',
        'auto_prepare' => 'bool',
        'auto_prepare_min_score' => 'int',
        'auto_prepare_top_rated' => 'bool',
        'follow_up_drafts' => 'bool',
        'response_auto_status' => 'bool',
        'notifications' => 'json',
        'mail_port' => 'int',
    ];

    /**
     * Charge les réglages dans la configuration (au démarrage de chaque requête).
     */
    public static function apply(): void
    {
        try {
            $settings = Setting::query()->get();
        } catch (Throwable) {
            return; // table pas encore créée (avant la migration)
        }

        foreach ($settings as $setting) {
            if (! isset(self::KEYS[$setting->key])) {
                continue;
            }

            try {
                $value = $setting->value;
            } catch (DecryptException) {
                Log::channel('security')->warning('Réglage illisible (APP_KEY changée ?)', ['key' => $setting->key]);

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            config([self::KEYS[$setting->key] => self::cast($setting->key, $value)]);

            // Un serveur d'e-mail réglé dans l'interface remplace celui du .env.
            if ($setting->key === 'mail_host') {
                config(['mail.default' => 'smtp']);
            }
        }

        // Sans adresse d'expédition, les e-mails partent de la boîte d'envoi (sinon le serveur les refuse).
        if (blank(config('mail.from.address')) && filled(config('mail.mailers.smtp.username'))
            && filter_var(config('mail.mailers.smtp.username'), FILTER_VALIDATE_EMAIL)) {
            config(['mail.from.address' => config('mail.mailers.smtp.username')]);
        }

        // Le serveur d'e-mail a peut-être changé : le prochain envoi reprend les réglages.
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }

    /**
     * Valeur effective (réglage, sinon .env, sinon valeur par défaut).
     */
    public static function get(string $key): mixed
    {
        return config(self::KEYS[$key]);
    }

    /**
     * Enregistre des réglages. Une valeur vide efface le réglage (retour au .env).
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::KEYS[$key])) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            $value = $value === null ? '' : trim((string) $value);

            if ($value === '') {
                Setting::query()->where('key', $key)->delete();

                continue;
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        self::apply();
    }

    public static function isStored(string $key): bool
    {
        return Setting::query()->where('key', $key)->exists();
    }

    public static function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
    }

    /**
     * Accepte l'adresse complète d'une feuille Google Sheets ou son seul identifiant.
     */
    public static function spreadsheetId(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $value, $m)) {
            return $m[1];
        }

        return $value === '' ? null : $value;
    }

    private static function cast(string $key, string $value): mixed
    {
        return match (self::CASTS[$key] ?? null) {
            'int' => (int) $value,
            'bool' => $value === '1',
            'json' => is_array($decoded = json_decode($value, true)) ? $decoded : [],
            default => $value,
        };
    }
}
