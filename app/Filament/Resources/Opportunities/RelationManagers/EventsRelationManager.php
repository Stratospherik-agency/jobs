<?php

namespace App\Filament\Resources\Opportunities\RelationManagers;

use App\Models\OpportunityEvent;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historique de l'opportunité : changements de statut automatiques et notes datées
 * (appel, entretien, échange…).
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Historique';

    protected static ?string $modelLabel = 'note';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('happened_at')->label('Date')->default(now())->required()->native(false)->displayFormat('d.m.Y H:i'),
            Textarea::make('body')->label('Note')->required()->rows(4)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('happened_at', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('happened_at')->label('Date')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) { 'statut' => 'Statut', 'import' => 'Import', default => 'Note' })
                    ->color(fn (string $state) => match ($state) { 'statut' => 'info', 'import' => 'gray', default => 'success' }),
                TextColumn::make('summary')
                    ->label('Détail')
                    ->state(fn (OpportunityEvent $record) => $record->summary())
                    ->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Ajouter une note')
                    ->mutateDataUsing(fn (array $data) => [...$data, 'type' => 'note']),
            ])
            ->recordActions([
                DeleteAction::make()->visible(fn (OpportunityEvent $record) => $record->type === 'note'),
            ]);
    }
}
