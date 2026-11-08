<?php

namespace App\Filament\Resources\Opportunities\Tables;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OpportunitiesTable
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
                    ->description(fn (Opportunity $record) => $record->title),
                TextColumn::make('location')->label('Lieu')->searchable()->sortable()->toggleable(),
                TextColumn::make('rate')->label('Taux')->toggleable(),
                TextColumn::make('relevance')
                    ->label('Pertinence')
                    ->badge()
                    ->color(fn (?int $state) => match ($state) { 3 => 'success', 2 => 'info', default => 'gray' })
                    ->sortable(),
                TextColumn::make('status')->label('Statut')->badge()->sortable(),
                TextColumn::make('application.status')->label('Dossier')->badge()->placeholder('—')->toggleable(),
                TextColumn::make('source')->label('Source')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')->label('Publiée le')->date('d.m.Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applied_at')->label('Envoyée le')->date('d.m.Y')->sortable()->placeholder('—'),
                TextColumn::make('follow_up_at')
                    ->label('Relance')
                    ->date('d.m.Y')
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (Opportunity $record) => $record->follow_up_at && ! $record->follow_up_at->isFuture() ? 'danger' : null),
                TextColumn::make('added_at')->label('Ajoutée le')->date('d.m.Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(OpportunityStatus::class)->multiple(),
                SelectFilter::make('relevance')->label('Pertinence')->options([3 => '3', 2 => '2', 1 => '1']),
                Filter::make('follow_up_due')
                    ->label('Relances à faire')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->followUpDue()),
                Filter::make('open')
                    ->label('Masquer les dossiers clos')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNotIn('status', [
                        OpportunityStatus::Rejected->value,
                        OpportunityStatus::Discarded->value,
                    ])),
            ])
            ->recordUrl(fn (Opportunity $record) => \App\Filament\Resources\Opportunities\OpportunityResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('open_offer')
                    ->label('Offre')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Opportunity $record) => $record->url, shouldOpenInNewTab: true)
                    ->visible(fn (Opportunity $record) => filled($record->url)),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('change_status')
                        ->label('Changer le statut')
                        ->icon('heroicon-o-arrow-path')
                        ->schema([
                            Select::make('status')->label('Nouveau statut')->options(OpportunityStatus::class)->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $records->each(fn (Opportunity $record) => $record->update(['status' => $data['status']]));
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
