<?php

namespace App\Filament\Resources\Opportunities\Pages;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\Actions\ApplicationActions;
use App\Filament\Resources\Opportunities\Widgets\Correspondence;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOpportunity extends ViewRecord
{
    protected static string $resource = OpportunityResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->company;
    }

    protected function getFooterWidgets(): array
    {
        return [Correspondence::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            ApplicationActions::validateAndSend(),
            ActionGroup::make([
                ApplicationActions::prepare(),
                ApplicationActions::importDraft(),
                ApplicationActions::discard(),
            ])
                ->label('Candidature')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->button(),
            Action::make('open_offer')
                ->label('Voir l\'offre')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (Opportunity $record) => $record->url, shouldOpenInNewTab: true)
                ->visible(fn (Opportunity $record) => filled($record->url)),
            ActionGroup::make(
                collect(OpportunityStatus::cases())->map(
                    fn (OpportunityStatus $status) => Action::make('status_'.$status->value)
                        ->label($status->getLabel())
                        ->color($status->getColor())
                        ->hidden(fn (Opportunity $record) => $record->status === $status)
                        ->action(function (Opportunity $record) use ($status) {
                            $record->update(['status' => $status]);
                            // Recharge la page pour mettre à jour le suivi et l'historique.
                            $this->redirect(OpportunityResource::getUrl('view', ['record' => $record]), navigate: true);
                        }),
                )->all()
            )
                ->label('Changer le statut')
                ->icon('heroicon-o-arrow-path')
                ->button(),
            EditAction::make(),
        ];
    }
}
