<?php

namespace App\Filament\Pages;

use App\Models\GoogleConnection;
use App\Services\Google\GoogleClient;
use App\Services\RegistrySync;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Connexion du tableau de bord au compte Google (Gmail et Drive).
 */
class GoogleAccount extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Compte Google';

    protected static ?string $title = 'Compte Google';

    protected static ?string $slug = 'compte-google';

    protected static ?int $navigationSort = 10;

    public function content(Schema $schema): Schema
    {
        $connection = GoogleConnection::current();

        if (! GoogleClient::isConfigured()) {
            return $schema->components([
                Section::make('Configuration requise')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Text::make('Les identifiants Google ne sont pas encore renseignés. Crée-les dans Google Cloud (guide docs/google-cloud.md), puis colle l\'ID client et le code secret dans Réglages, onglet Google.'),
                    ])
                    ->footerActions([
                        Action::make('open_settings')->label('Ouvrir les réglages Google')->url(Settings::getUrl().'?tab=google::data::tab'),
                    ]),
            ]);
        }

        if (! $connection) {
            return $schema->components([
                Section::make('Non connecté')
                    ->icon('heroicon-o-x-circle')
                    ->schema([
                        Text::make('Connecte ton compte Google pour importer les brouillons Gmail, envoyer les candidatures validées depuis ton adresse, suivre les réponses et archiver les documents dans Drive.'),
                        Text::make('Le tableau de bord demande trois autorisations : Gmail (lire et envoyer), Drive (uniquement les fichiers qu\'il crée) et Sheets en lecture seule (pour synchroniser le registre). Rien n\'est jamais envoyé sans ta validation.'),
                    ]),
            ]);
        }

        return $schema->components([
            Section::make('Connecté')
                ->icon('heroicon-o-check-circle')
                ->iconColor('success')
                ->schema([
                    Text::make('Compte : '.$connection->email),
                    Text::make('Connecté depuis le '.$connection->created_at->format('d.m.Y à H:i').'.'),
                    Text::make('Les documents envoyés sont archivés dans le dossier Drive « '.config('services.google.drive_root_folder').' ».'),
                ]),
            Section::make('Registre Google Sheets')
                ->icon('heroicon-o-arrow-path')
                ->schema(array_values(array_filter([
                    ($reason = RegistrySync::unavailableReason())
                        ? Text::make($reason)->color('danger')
                        : Text::make('Synchronisé automatiquement toutes les 15 minutes et à l\'ouverture du tableau de bord. Les changements faits dans le registre sont reportés ici sans écraser ton suivi.'),
                    $connection->registry_synced_at
                        ? Text::make('Dernière synchronisation : '.$connection->registry_synced_at->format('d.m.Y à H:i').'. '.($connection->registry_sync_error ? 'Erreur : '.$connection->registry_sync_error : $connection->registry_sync_summary))
                            ->color($connection->registry_sync_error ? 'danger' : null)
                        : null,
                ]))),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(GoogleClient::isConnected() ? 'Reconnecter' : 'Connecter mon compte Google')
                ->icon('heroicon-o-link')
                ->color(GoogleClient::isConnected() ? 'gray' : 'primary')
                ->visible(GoogleClient::isConfigured())
                ->action(fn (GoogleClient $google) => $this->redirect($google->authorizationUrl())),
            Action::make('sync_registry')
                ->label('Synchroniser maintenant')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(GoogleClient::isConnected() && ! RegistrySync::unavailableReason())
                ->action(function (RegistrySync $sync) {
                    self::notifySync($sync);
                    $this->redirect(static::getUrl());
                }),
            Action::make('disconnect')
                ->label('Déconnecter')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(GoogleClient::isConnected())
                ->requiresConfirmation()
                ->modalDescription('L\'accès du tableau de bord à ton Gmail et ton Drive sera retiré. Tes candidatures restent enregistrées.')
                ->action(function (GoogleClient $google) {
                    $google->disconnect();
                    Notification::make()->title('Compte Google déconnecté')->success()->send();
                    $this->redirect(static::getUrl());
                }),
        ];
    }

    public static function notifySync(RegistrySync $sync): void
    {
        try {
            $result = $sync->sync();
        } catch (\Throwable $e) {
            Notification::make()->title('Synchronisation impossible')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()
            ->title('Registre synchronisé')
            ->body(RegistrySync::summary($result))
            ->{$result['errors'] ? 'warning' : 'success'}()
            ->send();
    }
}
