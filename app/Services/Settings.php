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
    ];

    /** Réglages jamais renvoyés vers le navigateur. */
    public const SECRETS = ['google_client_secret'];

    private const CASTS = [
        'follow_up_days' => 'int',
        'login_alerts' => 'bool',
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
            default => $value,
        };
    }
}
