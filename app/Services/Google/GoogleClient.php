<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Client minimal pour les API Google (OAuth 2.0 avec PKCE, Gmail, Drive),
 * basé sur le client HTTP de Laravel.
 */
class GoogleClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public const GMAIL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public const DRIVE = 'https://www.googleapis.com/drive/v3';

    public const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public static function isConnected(): bool
    {
        return GoogleConnection::current() !== null;
    }

    /**
     * URL d'autorisation Google. Le « state » et le vérificateur PKCE sont gardés en session.
     */
    public function authorizationUrl(): string
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        session()->put('google_oauth', ['state' => $state, 'verifier' => $verifier]);

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'login_hint' => config('dashboard.owner_email'),
        ]);
    }

    /**
     * Échange le code reçu contre des jetons, après vérification du « state ».
     */
    public function handleCallback(?string $code, ?string $state): GoogleConnection
    {
        $pending = session()->pull('google_oauth');

        if (! $pending || ! $state || ! hash_equals($pending['state'], $state)) {
            throw new GoogleException('La réponse de Google ne correspond pas à la demande (state invalide). Recommence la connexion.');
        }

        if (blank($code)) {
            throw new GoogleException('Google n\'a pas renvoyé d\'autorisation.');
        }

        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.google.redirect'),
            'code_verifier' => $pending['verifier'],
        ]);

        if (blank($tokens['refresh_token'] ?? null)) {
            throw new GoogleException('Google n\'a pas fourni de jeton de renouvellement. Retire l\'accès de « Candidatures » dans ton compte Google puis recommence.');
        }

        $granted = explode(' ', (string) ($tokens['scope'] ?? ''));
        foreach (['https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/drive.file'] as $scope) {
            if (! in_array($scope, $granted, true)) {
                throw new GoogleException('Toutes les autorisations n\'ont pas été accordées (Gmail et Drive). Recommence en cochant toutes les cases.');
            }
        }

        $email = Http::withToken($tokens['access_token'])->get(self::USERINFO_URL)->throw()->json('email');
        $owner = (string) config('dashboard.owner_email');

        if (filled($owner) && mb_strtolower($email) !== mb_strtolower($owner)) {
            $this->revoke($tokens['refresh_token']);

            throw new GoogleException("Le compte {$email} n'est pas celui du propriétaire ({$owner}). Connecte-toi avec le bon compte Google.");
        }

        GoogleConnection::query()->delete();

        return GoogleConnection::query()->create([
            'email' => $email,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600) - 60),
            'scopes' => $tokens['scope'] ?? null,
        ]);
    }

    public function disconnect(): void
    {
        $connection = GoogleConnection::current();

        if ($connection) {
            $this->revoke($connection->refresh_token);
        }

        GoogleConnection::query()->delete();
    }

    /**
     * Requête authentifiée vers une API Google, avec renouvellement automatique du jeton.
     */
    public function api(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false);
    }

    public function accessToken(): string
    {
        $connection = GoogleConnection::current();

        if (! $connection) {
            throw new GoogleException('Le compte Google n\'est pas connecté.');
        }

        if ($connection->expires_at->isFuture()) {
            return $connection->access_token;
        }

        $tokens = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        $connection->update([
            'access_token' => $tokens['access_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600) - 60),
        ]);

        return $tokens['access_token'];
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            ...$params,
        ]);

        if ($response->failed()) {
            $error = $response->json('error');
            if ($error === 'invalid_grant') {
                GoogleConnection::query()->delete();

                throw new GoogleException('L\'accès Google a expiré ou a été retiré. Reconnecte le compte Google.');
            }

            throw new GoogleException('Google a refusé la demande : '.($response->json('error_description') ?? $error ?? $response->status()));
        }

        return $response->json();
    }

    private function revoke(string $token): void
    {
        rescue(fn () => Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]), report: false);
    }

    public static function check(Response $response, string $what): Response
    {
        if ($response->failed()) {
            throw new GoogleException($what.' : '.($response->json('error.message') ?? 'erreur '.$response->status()));
        }

        return $response;
    }
}
