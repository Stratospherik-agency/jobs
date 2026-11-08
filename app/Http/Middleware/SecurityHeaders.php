<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajoute les en-têtes de sécurité et d'exclusion des moteurs de recherche
 * à toutes les réponses de l'application.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        // Ne jamais indexer, suivre, archiver ni afficher d'extrait.
        $headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');

        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Pages authentifiées : aucune mise en cache par le navigateur ou un proxy.
        if (! $headers->has('Cache-Control') || ! str_contains((string) $headers->get('Cache-Control'), 'immutable')) {
            if (! $this->isStaticAsset($request)) {
                $headers->set('Cache-Control', 'no-store, private');
            }
        }

        return $response;
    }

    /**
     * Filament (Alpine.js et Livewire) exige 'unsafe-inline' et 'unsafe-eval' pour les scripts.
     * La politique interdit en revanche tout script, style, police ou cadre venant d'un autre domaine,
     * l'envoi de formulaires ailleurs et l'intégration du site dans une autre page.
     */
    protected function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            'upgrade-insecure-requests',
        ]);
    }

    protected function isStaticAsset(Request $request): bool
    {
        return (bool) preg_match('#\.(css|js|woff2?|ttf|svg|png|jpe?g|webp|ico)$#i', $request->path());
    }
}
