<?php

namespace App\Filament\Widgets\Statistics;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Candidatures sans nouvelles depuis plus de 30 jours.
 */
class SilentApplications extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sans nouvelles depuis plus de 30 jours')
            ->description('Candidatures envoyées sans réponse : à relancer une dernière fois ou à classer.')
            ->query(Opportunity::query()
                ->whereIn('status', [OpportunityStatus::Applied->value, OpportunityStatus::FollowedUp->value])
                ->whereNull('first_response_at')
                ->whereDate('applied_at', '<=', today()->subDays(30)))
            ->defaultSort('applied_at')
            ->columns([
                TextColumn::make('company')->label('Entreprise'),
                TextColumn::make('title')->label('Poste'),
                TextColumn::make('applied_at')->label('Envoyée le')->date('d.m.Y'),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->recordUrl(fn (Opportunity $record) => OpportunityResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Aucune')
            ->emptyStateDescription('Toutes tes candidatures récentes ont eu une réponse ou sont encore jeunes.')
            ->paginated([5, 10]);
    }
}
