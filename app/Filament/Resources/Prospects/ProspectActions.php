<?php

namespace App\Filament\Resources\Prospects;

use App\Enums\OpportunityStatus;
use App\Enums\ProspectStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use App\Models\Prospect;
use App\Support\Catalog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Actions sur une entreprise cible : retenir, écarter, préparer la candidature.
 * L'envoi passe ensuite par la fiche de l'opportunité, avec validation explicite.
 */
class ProspectActions
{
    public static function select(): Action
    {
        return Action::make('select')
            ->label('Retenir')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Prospect $record) => in_array($record->status, [ProspectStatus::ToQualify, ProspectStatus::Discarded, ProspectStatus::Recontact], true))
            ->action(function (Prospect $record) {
                $record->update(['status' => ProspectStatus::Selected]);
                Notification::make()->title($record->company.' retenue')->success()->send();
            });
    }

    public static function discard(): Action
    {
        return Action::make('discard')
            ->label('Écarter')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (Prospect $record) => in_array($record->status, [ProspectStatus::ToQualify, ProspectStatus::Selected, ProspectStatus::Recontact], true))
            ->action(function (Prospect $record) {
                $record->update(['status' => ProspectStatus::Discarded]);
                Notification::make()->title($record->company.' écartée')->send();
            });
    }

    /**
     * Crée l'opportunité « Candidature spontanée » liée à la cible et ouvre sa fiche,
     * où la candidature est préparée puis validée avant tout envoi.
     */
    public static function prepare(): Action
    {
        return Action::make('prepare')
            ->label(fn (Prospect $record) => $record->opportunity ? 'Ouvrir la candidature' : 'Préparer la candidature')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->visible(fn (Prospect $record) => $record->opportunity !== null || in_array($record->status, [ProspectStatus::Selected, ProspectStatus::Recontact], true))
            ->action(function (Prospect $record, $livewire) {
                $opportunity = $record->opportunity ?? DB::transaction(fn () => self::createOpportunity($record));

                $livewire->redirect(OpportunityResource::getUrl('view', ['record' => $opportunity]), navigate: true);
            });
    }

    public static function createOpportunity(Prospect $prospect): Opportunity
    {
        $opportunity = Opportunity::query()->create([
            'company' => $prospect->company,
            'title' => 'Candidature spontanée',
            'location' => $prospect->locality,
            'source' => Catalog::SOURCES['spontanee'],
            'url' => $prospect->website,
            'relevance' => $prospect->relevance,
            'status' => OpportunityStatus::InPreparation,
            'sector' => $prospect->sector,
            'company_size' => match ($prospect->family) {
                'institution' => 'institution',
                'grande' => 'grande',
                'moyenne', 'petite' => 'pme',
                default => null,
            },
            'language' => $prospect->language,
            'notes' => collect([
                $prospect->reasons ? "Pourquoi cette entreprise :\n".$prospect->reasons : null,
                $prospect->contributions ? "Pistes d'apport :\n".$prospect->contributions : null,
                $prospect->signal ? 'Signal : '.$prospect->signal : null,
            ])->filter()->implode("\n\n") ?: null,
        ]);

        $prospect->opportunity()->associate($opportunity);
        $prospect->status = ProspectStatus::InPreparation;
        $prospect->save();

        return $opportunity;
    }
}
