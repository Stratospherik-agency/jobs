<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\ExplorerChart;
use App\Services\Statistics\Statistics;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Croisement libre : une mesure, jusqu'à 4 variables (axe, couleur, petits graphiques, filtre).
 */
class Explorer extends StatisticsPage
{
    protected static ?string $title = 'Explorateur';

    protected static ?string $slug = 'explorateur';

    protected static ?int $navigationSort = 5;

    /** Nombre maximal de petits graphiques. */
    private const FACETS = 6;

    protected function extraFilters(): array
    {
        $dimensions = collect(Statistics::dimensions())->map(fn ($d) => $d['label'])->all();

        return [
            Select::make('measure')
                ->label('Mesure')
                ->options(collect(Statistics::measures())->map(fn ($m) => $m['label'])->all())
                ->default('response_rate')
                ->selectablePlaceholder(false)
                ->native(false),
            Select::make('x')
                ->label('Variable 1 : axe horizontal')
                ->options($dimensions)
                ->default('source')
                ->selectablePlaceholder(false)
                ->native(false),
            Select::make('color')
                ->label('Variable 2 : couleur')
                ->options($dimensions)
                ->placeholder('Aucune')
                ->native(false),
            Select::make('facet')
                ->label('Variable 3 : un graphique par valeur')
                ->options($dimensions)
                ->placeholder('Aucune')
                ->native(false),
            Select::make('filter')
                ->label('Variable 4 : filtrer sur')
                ->options($dimensions)
                ->placeholder('Aucune')
                ->afterStateUpdated(fn (Set $set) => $set('filter_value', null))
                ->native(false),
            Select::make('filter_value')
                ->label('Valeur retenue')
                ->options(fn (Get $get) => filled($get('filter')) ? Statistics::values($get('filter')) : [])
                ->visible(fn (Get $get) => filled($get('filter')))
                ->native(false),
        ];
    }

    public function mount(): void
    {
        parent::mount();
        $this->filters['measure'] ??= 'response_rate';
        $this->filters['x'] ??= 'source';
    }

    protected function widgets(): array
    {
        return []; // construits dans content() pour suivre les choix
    }

    public function content(Schema $schema): Schema
    {
        $f = $this->filters ?? [];
        $config = ExplorerChart::configuration($f);
        $facet = $config['facetDimension'];

        $charts = $facet
            ? collect(Statistics::values($facet, Statistics::dataset($f)))->keys()->take(self::FACETS)->map(fn ($value) => [(string) $value, 1])->all()
            : [[null, 'full']];

        $components = [];
        foreach ($charts as [$value, $span]) {
            $components[] = Livewire::make(ExplorerChart::class, [
                'pageFilters' => $this->filters,
                'facetValue' => $value,
                'span' => $span,
            ])->columnSpan($span === 'full' ? 'full' : 1)->key('explorateur-'.md5(json_encode($config).'|'.$value.'|'.json_encode([$f['period'] ?? null, $f['from'] ?? null, $f['to'] ?? null, $f['filter_value'] ?? null])));
        }

        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Text::make('Choisis une mesure et jusqu\'à 4 variables. Survole un graphique pour le détail de chaque valeur.')->color('gray'),
            Grid::make(['default' => 1, 'lg' => 2])->schema($components),
        ]);
    }
}
