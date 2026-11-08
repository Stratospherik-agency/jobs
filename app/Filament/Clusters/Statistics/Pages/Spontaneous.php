<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\ProspectChart;
use App\Filament\Widgets\Statistics\ProspectKeyFigures;

class Spontaneous extends StatisticsPage
{
    protected static ?string $title = 'Candidatures spontanées';

    protected static ?string $navigationLabel = 'Spontanées';

    protected static ?string $slug = 'spontanees';

    protected static ?int $navigationSort = 6;

    protected function widgets(): array
    {
        return [
            ProspectKeyFigures::class,
            ProspectChart::make(['kind' => 'weekly', 'span' => 'full']),
            ProspectChart::make(['kind' => 'funnel', 'span' => 'full']),
            ProspectChart::make(['kind' => 'family']),
            ProspectChart::make(['kind' => 'channel']),
            ProspectChart::make(['kind' => 'ats', 'span' => 'full']),
        ];
    }
}
