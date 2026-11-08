<?php

namespace App\Providers;

use App\Services\Settings;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Réglages saisis dans la page Réglages (prioritaires sur le .env).
        Settings::apply();

        // En production, toutes les URL générées sont en HTTPS.
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        $proxies = config('dashboard.trusted_proxies');
        if (filled($proxies)) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Règles de mot de passe : 14 caractères minimum, et refus des mots de passe
        // présents dans des fuites connues (vérification anonyme Have I Been Pwned).
        Password::defaults(function () {
            $rule = Password::min(14)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
