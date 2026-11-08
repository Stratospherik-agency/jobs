<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\Funnel;
use App\Filament\Widgets\Statistics\KeyFigures;
use App\Filament\Widgets\Statistics\StatChart;

class Overview extends StatisticsPage
{
    protected static ?string $title = 'Vue d\'ensemble';

    protected static ?string $slug = 'vue-d-ensemble';

    protected static ?int $navigationSort = 1;

    protected function widgets(): array
    {
        return [
            KeyFigures::class,
            Funnel::class,
            StatChart::make([
                'title' => 'Candidatures envoyées par semaine',
                'measure' => 'applications', 'dimension' => 'week', 'chart' => 'bar', 'span' => 1,
            ]),
            StatChart::make([
                'title' => 'Où en sont mes offres',
                'measure' => 'offers', 'dimension' => 'status', 'chart' => 'doughnut', 'span' => 1,
            ]),
        ];
    }
}
