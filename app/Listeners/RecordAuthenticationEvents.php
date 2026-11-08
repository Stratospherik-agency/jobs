<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\NewLoginAlert;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Journalise les connexions et les échecs, et prévient le propriétaire
 * par e-mail à chaque connexion réussie.
 *
 * Les méthodes handle* sont découvertes automatiquement par Laravel.
 */
class RecordAuthenticationEvents
{
    public function handleLogin(Login $event): void
    {
        $request = request();

        Log::channel('security')->info('Connexion réussie', [
            'user_id' => $event->user->getAuthIdentifier(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (config('dashboard.login_alerts') && $event->user instanceof User) {
            // Un problème d'envoi (SMTP mal configuré, serveur indisponible) ne doit pas bloquer la connexion.
            try {
                $event->user->notify(new NewLoginAlert(
                    ip: (string) $request->ip(),
                    userAgent: (string) $request->userAgent(),
                    at: now(),
                ));
            } catch (\Throwable $e) {
                Log::channel('security')->error('Alerte de connexion non envoyée', ['erreur' => $e->getMessage()]);
            }
        }
    }

    public function handleFailed(Failed $event): void
    {
        Log::channel('security')->warning('Échec de connexion', [
            'email' => $event->credentials['email'] ?? null,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        Log::channel('security')->warning('Trop de tentatives de connexion', [
            'ip' => $event->request->ip(),
        ]);
    }
}
