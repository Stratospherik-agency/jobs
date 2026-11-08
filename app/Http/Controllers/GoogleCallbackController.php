<?php

namespace App\Http\Controllers;

use App\Filament\Pages\GoogleAccount;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Retour de Google après autorisation. Route réservée au propriétaire connecté (panneau Filament).
 */
class GoogleCallbackController
{
    public function __invoke(Request $request, GoogleClient $google): RedirectResponse
    {
        if ($request->filled('error')) {
            session()->forget('google_oauth');
            Notification::make()->title('Connexion Google annulée')->warning()->send();

            return redirect(GoogleAccount::getUrl());
        }

        try {
            $connection = $google->handleCallback($request->query('code'), $request->query('state'));
            Notification::make()->title('Compte Google connecté')->body($connection->email)->success()->send();
        } catch (GoogleException $e) {
            Notification::make()->title('Connexion Google impossible')->body($e->getMessage())->danger()->persistent()->send();
        }

        return redirect(GoogleAccount::getUrl());
    }
}
