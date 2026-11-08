<?php

namespace App\Filament\Resources\Prospects\Tables;

use App\Enums\ProspectStatus;
use App\Filament\Resources\Prospects\ProspectActions;
use App\Filament\Resources\Prospects\ProspectResource;
use App\Models\Prospect;
use App\Support\Catalog;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProspectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('relevance')->orderByDesc('added_at'))
            ->columns([
                TextColumn::make('company')
                    ->label('Entreprise')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Prospect $record) => collect([$record->sector, $record->locality])->filter()->implode(' · ')),
                TextColumn::make('reasons')
                    ->label('Pourquoi la viser')
                    ->limit(110)
                    ->tooltip(fn (Prospect $record) => $record->reasons)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('family')->label('Famille')->formatStateUsing(fn ($state) => Catalog::PROSPECT_FAMILIES[$state] ?? $state)->toggleable(),
                TextColumn::make('relevance')
                    ->label('Note')
                    ->badge()
                    ->color(fn (?int $state) => match ($state) {
                        3 => 'success', 2 => 'info', default => 'gray'
                    })
                    ->sortable(),
                TextColumn::make('status')->label('Statut')->badge()->sortable(),
                TextColumn::make('channel')->label('Canal')->formatStateUsing(fn ($state) => Catalog::PROSPECT_CHANNELS[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sent_at')->label('Envoyée le')->date('d.m.Y')->sortable()->placeholder('—'),
                TextColumn::make('follow_up_at')
                    ->label('Relance')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (Prospect $record) => $record->follow_up_at && ! $record->follow_up_at->isFuture() ? 'danger' : null),
                TextColumn::make('added_at')->label('Ajoutée le')->date('d.m.Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(ProspectStatus::class)->multiple(),
                SelectFilter::make('family')->label('Famille')->options(Catalog::PROSPECT_FAMILIES),
                SelectFilter::make('channel')->label('Canal')->options(Catalog::PROSPECT_CHANNELS),
                Filter::make('follow_up_due')->label('Relances à faire')->toggle()->query(fn (Builder $query) => $query->followUpDue()),
                Filter::make('open')
                    ->label('Masquer les pistes écartées')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->where('status', '!=', ProspectStatus::Discarded->value)),
            ])
            ->recordUrl(fn (Prospect $record) => ProspectResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ProspectActions::select()->iconButton()->tooltip('Retenir'),
                ProspectActions::discard()->iconButton()->tooltip('Écarter'),
                ActionGroup::make([
                    ProspectActions::prepare(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('select_many')
                        ->label('Retenir')
                        ->icon('heroicon-o-check')
                        ->action(fn (Collection $records) => $records->reject->isWebAgency()->each->update(['status' => ProspectStatus::Selected]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('discard_many')
                        ->label('Écarter')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn (Collection $records) => $records->each->update(['status' => ProspectStatus::Discarded]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
