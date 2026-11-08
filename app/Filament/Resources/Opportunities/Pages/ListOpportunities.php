<?php

namespace App\Filament\Resources\Opportunities\Pages;

use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Pages\GoogleAccount;
use App\Services\Google\GoogleClient;
use App\Services\RegistryImporter;
use App\Services\RegistrySync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListOpportunities extends ListRecords
{
    protected static string $resource = OpportunityResource::class;

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
            Action::make('import_registry')
                ->label('Importer le registre')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalSubmitActionLabel('Importer')
                ->modalDescription('Dans Google Sheets : Fichier > Télécharger > Valeurs séparées par des virgules (.csv), puis dépose le fichier ici. Les offres déjà présentes sont ignorées.')
                ->schema([
                    FileUpload::make('file')
                        ->label('Fichier CSV')
                        ->disk('local')
                        ->directory('imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->maxSize(2048)
                        ->required(),
                ])
                ->action(function (array $data, RegistryImporter $importer) {
                    $path = Storage::disk('local')->path($data['file']);

                    try {
                        $result = $importer->importFile($path);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Import impossible')->body($e->getMessage())->danger()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }

                    $body = "{$result['created']} offre(s) importée(s), {$result['skipped']} déjà présente(s).";
                    if ($result['errors']) {
                        $body .= "\n".implode("\n", array_slice($result['errors'], 0, 5));
                    }

                    Notification::make()
                        ->title('Import terminé')
                        ->body($body)
                        ->{$result['errors'] ? 'warning' : 'success'}()
                        ->persistent()
                        ->send();
                }),
            CreateAction::make()->label('Nouvelle opportunité'),
        ];
    }
}
