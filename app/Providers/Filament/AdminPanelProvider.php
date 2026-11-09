<?php

namespace App\Providers\Filament;

use App\Filament\InitialsAvatarProvider;
use App\Filament\Pages\Dashboard;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\GoogleCallbackController;
use App\Http\Controllers\OrpReportController;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Navigation\NavigationGroup;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Le tableau de bord occupe la racine du sous-domaine.
            ->path('')
            ->brandName('jobs · pierre-ollivier.tissier')
            // Logo « jobs » dessiné en SVG (s'adapte seul au mode sombre).
            ->brandLogo(fn () => new HtmlString(view('filament.theme.brand')->render()))
            ->brandLogoHeight('3rem')
            ->favicon(asset('theme/favicon.svg'))
            // Connexion seule : pas d'inscription ni de réinitialisation par e-mail.
            // En cas d'oubli, le mot de passe se réinitialise en SSH (php artisan dashboard:owner).
            ->login()
            // Page de profil : mot de passe et double authentification.
            ->profile(isSimple: false)
            // Double authentification obligatoire (application TOTP + codes de secours).
            ->multiFactorAuthentication(
                AppAuthentication::make()
                    ->brandName('Candidatures')
                    ->recoverable(),
                isRequired: true,
            )
            // Aucune police chargée depuis un service tiers.
            // Police système d'Apple, puis Inter hébergée sur le site (public/theme/fonts).
            ->font('Inter', provider: LocalFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                // Bleu « jobs » : la nuance 600 est exactement #0A5BD8 (texte blanc lisible dessus).
                'primary' => [
                    50 => 'oklch(0.97 0.014 260.4)',
                    100 => 'oklch(0.935 0.033 260.4)',
                    200 => 'oklch(0.885 0.062 260.4)',
                    300 => 'oklch(0.81 0.105 260.4)',
                    400 => 'oklch(0.71 0.16 260.4)',
                    500 => 'oklch(0.615 0.195 260.4)',
                    600 => 'oklch(0.5096 0.2046 260.4)',
                    700 => 'oklch(0.455 0.185 260.4)',
                    800 => 'oklch(0.4 0.155 260.4)',
                    900 => 'oklch(0.355 0.125 260.4)',
                    950 => 'oklch(0.265 0.09 260.4)',
                ],
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Teal,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->sidebarWidth('17rem')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Candidatures'),
                NavigationGroup::make('Analyse'),
                NavigationGroup::make('Compte'),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn () => new HtmlString('<meta name="robots" content="noindex, nofollow, noarchive">'),
            )
            // Thème « jobs » : style en verre, réglages de lisibilité appliqués avant l'affichage.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.theme.head'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.theme.lisibilite'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.theme.tabbar'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Routes réservées au propriétaire connecté (même protection que le panneau).
            ->authenticatedRoutes(function () {
                Route::get('google/callback', GoogleCallbackController::class)->name('google.callback');
                Route::get('pieces-jointes/{attachment}', AttachmentController::class)->name('attachments.download');
                Route::get('statistiques/orp/{month}/csv', [OrpReportController::class, 'csv'])->where('month', '\d{4}-\d{2}')->name('orp.csv');
                Route::get('statistiques/orp/{month}/imprimer', [OrpReportController::class, 'print'])->where('month', '\d{4}-\d{2}')->name('orp.print');
            })
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
