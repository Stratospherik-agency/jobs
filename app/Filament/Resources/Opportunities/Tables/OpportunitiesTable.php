<?php

namespace App\Filament\Resources\Opportunities\Tables;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\Actions\OpportunityActions;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use App\Support\Catalog;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class OpportunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Les offres les plus récentes d'abord (puis les plus pertinentes) : une nouvelle offre peu notée
            // reste visible en haut de la liste le jour de son arrivée.
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('added_at')->orderByDesc('relevance_score')->orderByDesc('relevance'))
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
                    ->color(fn (?int $state) => match ($state) {
                        3 => 'success', 2 => 'info', default => 'gray'
                    })
                    ->sortable(),
                TextColumn::make('relevance_score')
                    ->label('Score')
                    ->suffix(' / 100')
                    ->badge()
                    ->color(fn (?int $state) => match (true) {
                        $state === null => 'gray', $state >= 75 => 'success', $state >= 50 => 'info', default => 'gray',
                    })
                    ->tooltip(fn (Opportunity $record) => implode("\n", $record->relevance_reasons ?? []))
                    ->placeholder('—')
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
                TextColumn::make('added_at')
                    ->label('Ajoutée le')
                    ->date('d.m.Y')
                    ->badge()
                    ->color(fn (Opportunity $record) => $record->added_at?->gte(today()->subDay()) ? 'success' : 'gray')
                    ->tooltip(fn (Opportunity $record) => $record->added_at?->gte(today()->subDay()) ? 'Nouvelle offre (moins de 48 h)' : null)
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters(self::filters())
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(['sm' => 2, 'lg' => 4])
            ->filtersTriggerAction(fn (Action $action) => $action->button()->label('Filtres'))
            ->deferFilters(false)
            ->recordUrl(fn (Opportunity $record) => OpportunityResource::getUrl('view', ['record' => $record]))
            ->recordActionsPosition(RecordActionsPosition::BeforeColumns)
            ->recordActions([
                OpportunityActions::ignore()->iconButton()->tooltip('Ignorer cette annonce'),
                OpportunityActions::restore()->iconButton()->tooltip('Rétablir cette annonce'),
                Action::make('open_offer')
                    ->iconButton()
                    ->tooltip('Voir l\'annonce')
                    ->label('Offre')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Opportunity $record) => $record->url, shouldOpenInNewTab: true)
                    ->visible(fn (Opportunity $record) => filled($record->url)),
                EditAction::make()->iconButton()->tooltip('Modifier'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('ignore_many')
                        ->label('Ignorer')
                        ->icon('heroicon-o-eye-slash')
                        ->action(fn (Collection $records) => $records->filter(fn (Opportunity $o) => OpportunityActions::canIgnore($o))
                            ->each->update(['status' => OpportunityStatus::Discarded]))
                        ->deselectRecordsAfterCompletion(),
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

    /**
     * Filtres de la liste, construits à partir des données disponibles sur les offres.
     */
    private static function filters(): array
    {
        $options = fn (string $column) => fn () => Opportunity::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column, $column)->all();

        $dateRange = fn (string $column, string $label) => Filter::make($column)
            ->schema([
                DatePicker::make('from')->label($label.' depuis le'),
                DatePicker::make('until')->label($label.' jusqu\'au'),
            ])
            ->columns(2)
            ->columnSpan(2)
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate($column, '>=', $d))
                ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate($column, '<=', $d)))
            ->indicateUsing(fn (array $data) => array_values(array_filter([
                ($data['from'] ?? null) ? $label.' depuis le '.Carbon::parse($data['from'])->format('d.m.Y') : null,
                ($data['until'] ?? null) ? $label.' jusqu\'au '.Carbon::parse($data['until'])->format('d.m.Y') : null,
            ])));

        return [
            SelectFilter::make('status')->label('Statut')->options(OpportunityStatus::class)->multiple(),
            SelectFilter::make('relevance')->label('Pertinence')->multiple()
                ->options([3 => '3 – très pertinente', 2 => '2 – pertinente', 1 => '1 – peu pertinente']),
            SelectFilter::make('zone')
                ->label('Zone géographique')
                ->multiple()
                ->options(array_combine(array_keys(Catalog::ZONES), array_keys(Catalog::ZONES)))
                ->query(function (Builder $query, array $data) {
                    $zones = array_intersect_key(Catalog::ZONES, array_flip($data['values'] ?? []));
                    if (! $zones) {
                        return;
                    }
                    $query->where(function (Builder $q) use ($zones) {
                        foreach (array_merge(...array_values($zones)) as $word) {
                            $q->orWhereRaw('lower(location) like ?', ['%'.mb_strtolower($word).'%']);
                        }
                    });
                }),
            SelectFilter::make('location')->label('Lieu')->multiple()->searchable()->options($options('location')),
            Filter::make('rate')
                ->label('Taux d\'activité')
                ->schema([
                    Select::make('rate')
                        ->label('Taux d\'activité')
                        ->placeholder('Tout')
                        ->options([
                            '100' => '100 % possible',
                            '80' => '80 % ou plus possible',
                            'partial' => 'Moins de 80 % seulement',
                            'unknown' => 'Non indiqué',
                        ]),
                ])
                ->query(fn (Builder $query, array $data) => match ($data['rate'] ?? null) {
                    '100' => $query->where('rate_max', '>=', 100),
                    '80' => $query->where('rate_max', '>=', 80),
                    'partial' => $query->where('rate_max', '<', 80),
                    'unknown' => $query->whereNull('rate_max'),
                    default => $query,
                })
                ->indicateUsing(fn (array $data) => filled($data['rate'] ?? null) ? 'Taux : '.[
                    '100' => '100 %', '80' => '80 % ou plus', 'partial' => 'moins de 80 %', 'unknown' => 'non indiqué',
                ][$data['rate']] : null),
            SelectFilter::make('source')
                ->label('Plateforme')
                ->multiple()
                ->options(Catalog::SOURCES)
                ->query(function (Builder $query, array $data) {
                    $needles = array_merge(...array_values(array_intersect_key(Catalog::SOURCE_NEEDLES, array_flip($data['values'] ?? [])) ?: [[]]));
                    if (! $needles) {
                        return;
                    }
                    $query->where(function (Builder $q) use ($needles) {
                        foreach ($needles as $word) {
                            $q->orWhereRaw('lower(source) like ?', ['%'.$word.'%']);
                        }
                    });
                }),
            SelectFilter::make('sector')->label('Secteur')->multiple()->searchable()->options($options('sector')),
            SelectFilter::make('company_size')->label('Taille d\'entreprise')->multiple()->options(Catalog::COMPANY_SIZES),
            SelectFilter::make('seniority')->label('Niveau de poste')->multiple()->options(Catalog::SENIORITY),
            SelectFilter::make('job_family')->label('Métier')->multiple()->searchable()->options($options('job_family')),
            SelectFilter::make('contract_type')->label('Contrat')->multiple()->options(Catalog::CONTRACTS),
            SelectFilter::make('remote')->label('Télétravail')->multiple()->options(Catalog::REMOTE),
            SelectFilter::make('language')->label('Langue de l\'annonce')->options(Catalog::LANGUAGES),
            SelectFilter::make('ats')->label('Passe par un ATS')->options(Catalog::ATS),
            TernaryFilter::make('applied')
                ->label('Candidature envoyée')
                ->placeholder('Tout')
                ->trueLabel('Oui')
                ->falseLabel('Pas encore')
                ->queries(
                    true: fn (Builder $q) => $q->whereNotNull('applied_at'),
                    false: fn (Builder $q) => $q->whereNull('applied_at'),
                ),
            $dateRange('added_at', 'Ajoutée'),
            $dateRange('published_at', 'Publiée'),
            Filter::make('follow_up_due')
                ->label('Relances à faire')
                ->toggle()
                ->query(fn (Builder $query) => $query->followUpDue()),
            Filter::make('open')
                ->label('Masquer les annonces ignorées et les refus')
                ->toggle()
                ->default()
                ->query(fn (Builder $query) => $query->whereNotIn('status', [
                    OpportunityStatus::Rejected->value,
                    OpportunityStatus::Discarded->value,
                ])),
        ];
    }
}
