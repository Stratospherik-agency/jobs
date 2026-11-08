<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Clusters\Statistics\StatisticsCluster;
use App\Services\Statistics\Statistics;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Widgets\WidgetConfiguration;

/**
 * Onglet de la section Statistiques : un filtre de période commun, puis des graphiques.
 */
abstract class StatisticsPage extends Page
{
    use HasFiltersForm;

    protected static ?string $cluster = StatisticsCluster::class;

    /**
     * @return array<WidgetConfiguration|class-string>
     */
    abstract protected function widgets(): array;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 3, 'xl' => 4])
                ->schema([
                    Select::make('period')
                        ->label('Période')
                        ->options(Statistics::PERIODS)
                        ->default('90')
                        ->selectablePlaceholder(false)
                        ->native(false),
                    DatePicker::make('from')->label('Du')->native(false)->displayFormat('d.m.Y')
                        ->visible(fn (Get $get) => $get('period') === 'custom'),
                    DatePicker::make('to')->label('Au')->native(false)->displayFormat('d.m.Y')
                        ->visible(fn (Get $get) => $get('period') === 'custom'),
                    ...$this->extraFilters(),
                ]),
        ]);
    }

    /**
     * Filtres propres à un onglet (Explorateur).
     *
     * @return array<Component|Field>
     */
    protected function extraFilters(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->filters ??= [];
        $this->filters['period'] ??= '90';
    }

    public function getFiltersSessionKey(): string
    {
        return 'statistiques.filtres'; // période partagée entre les onglets
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->widgets())),
        ]);
    }
}
