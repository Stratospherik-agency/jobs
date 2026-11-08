<?php

namespace App\Filament\Widgets;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class FollowUpsDue extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Relances à faire';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Opportunity::query()->followUpDue()->orderBy('follow_up_at'))
            ->emptyStateHeading('Aucune relance à faire')
            ->emptyStateDescription('Les candidatures sans réponse apparaîtront ici '.\App\Models\Opportunity::followUpDays().' jours après l\'envoi.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10])
            ->recordUrl(fn (Opportunity $record) => OpportunityResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('company')->label('Entreprise')->weight('bold')->description(fn (Opportunity $record) => $record->title),
                TextColumn::make('applied_at')->label('Envoyée le')->date('d.m.Y'),
                TextColumn::make('follow_up_at')->label('Relance prévue')->date('d.m.Y')->color('danger'),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->recordActions([
                Action::make('followed_up')
                    ->label('Marquer relancé')
                    ->icon('heroicon-o-check')
                    ->action(fn (Opportunity $record) => $record->update(['status' => OpportunityStatus::FollowedUp])),
            ]);
    }
}
