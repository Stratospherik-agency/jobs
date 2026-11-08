<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\StatChart;

class Timing extends StatisticsPage
{
    protected static ?string $title = 'Temps et saisonnalité';

    protected static ?string $navigationLabel = 'Temps';

    protected static ?string $slug = 'temps';

    protected static ?int $navigationSort = 2;

    protected function widgets(): array
    {
        return [
            StatChart::make([
                'title' => 'Offres repérées et candidatures par mois',
                'subtitle' => 'Saisonnalité de ta recherche : elle devient lisible après quelques mois.',
                'measure' => 'offers', 'secondMeasure' => 'applications', 'dimension' => 'month', 'chart' => 'line', 'span' => 'full',
            ]),
            StatChart::make([
                'title' => 'Taux de réponse selon le jour d\'envoi',
                'measure' => 'response_rate', 'dimension' => 'weekday',
            ]),
            StatChart::make([
                'title' => 'Taux de réponse selon l\'âge de l\'offre',
                'subtitle' => 'Délai entre la publication et ta candidature.',
                'measure' => 'response_rate', 'dimension' => 'offer_age',
            ]),
            StatChart::make([
                'title' => 'Candidatures selon l\'âge de l\'offre',
                'measure' => 'applications', 'dimension' => 'offer_age',
            ]),
            StatChart::make([
                'title' => 'Délai moyen de réponse par mois',
                'measure' => 'response_delay', 'dimension' => 'month', 'chart' => 'line',
            ]),
        ];
    }
}
