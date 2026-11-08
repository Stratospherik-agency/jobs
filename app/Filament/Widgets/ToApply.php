<?php

namespace App\Filament\Widgets;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ToApply extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Meilleures pistes à traiter';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Opportunity::query()
                ->whereIn('status', [OpportunityStatus::ToEvaluate->value, OpportunityStatus::ToApply->value])
                ->orderByDesc('relevance')
                ->orderByDesc('added_at'))
            ->emptyStateHeading('Aucune offre en attente')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn (Opportunity $record) => OpportunityResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('company')->label('Entreprise')->weight('bold')->description(fn (Opportunity $record) => $record->title),
                TextColumn::make('location')->label('Lieu'),
                TextColumn::make('relevance')
                    ->label('Pertinence')
                    ->badge()
                    ->color(fn (?int $state) => match ($state) { 3 => 'success', 2 => 'info', default => 'gray' }),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('published_at')->label('Publiée le')->date('d.m.Y'),
            ]);
    }
}
