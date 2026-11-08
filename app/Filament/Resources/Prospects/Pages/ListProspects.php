<?php

namespace App\Filament\Resources\Prospects\Pages;

use App\Filament\Pages\GoogleAccount;
use App\Filament\Resources\Prospects\ProspectResource;
use App\Services\Google\GoogleClient;
use App\Services\RegistrySync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProspects extends ListRecords
{
    protected static string $resource = ProspectResource::class;

    public function getTitle(): string
    {
        return 'Candidatures spontanées';
    }

    public function getSubheading(): ?string
    {
        return 'Entreprises à démarcher, avec les raisons de les viser. Retiens ou écarte chaque piste, puis prépare la candidature : rien ne part sans ta validation.';
    }

    public function mount(): void
    {
        app(RegistrySync::class)->syncIfStale();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_registry')
                ->label('Synchroniser le registre')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => GoogleClient::isConnected() && ! RegistrySync::unavailableReason())
                ->action(fn (RegistrySync $sync) => GoogleAccount::notifySync($sync)),
            CreateAction::make()->label('Nouvelle entreprise'),
        ];
    }
}
