<?php

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;

/**
 * Le registre gagne des colonnes (secteur, niveau, contrat…) : l'ancienne plage par défaut
 * A1:N enregistrée depuis la page Réglages est retirée pour revenir à la nouvelle (A1:Z).
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::query()->where('key', 'google_registry_range')->first();

        try {
            if ($setting && $setting->value === 'Offres!A1:N1000') {
                $setting->delete();
            }
        } catch (DecryptException) {
            // valeur illisible : laissée telle quelle
        }
    }

    public function down(): void {}
};
