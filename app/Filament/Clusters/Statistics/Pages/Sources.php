<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\StatChart;

class Sources extends StatisticsPage
{
    protected static ?string $title = 'Sources';

    protected static ?string $slug = 'sources';

    protected static ?int $navigationSort = 3;

    protected function widgets(): array
    {
        return [
            StatChart::make([
                'title' => 'Offres repérées par source',
                'measure' => 'offers', 'dimension' => 'source', 'horizontal' => true,
            ]),
            StatChart::make([
                'title' => 'Candidatures envoyées par source',
                'measure' => 'applications', 'dimension' => 'source', 'horizontal' => true,
            ]),
            StatChart::make([
                'title' => 'Taux de réponse par source',
                'measure' => 'response_rate', 'dimension' => 'source', 'horizontal' => true,
            ]),
            StatChart::make([
                'title' => 'Pertinence moyenne des offres par source',
                'subtitle' => 'Quelle plateforme propose les offres qui te correspondent le mieux.',
                'measure' => 'relevance', 'dimension' => 'source', 'horizontal' => true,
            ]),
        ];
    }
}
