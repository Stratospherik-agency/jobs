<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\SilentApplications;
use App\Filament\Widgets\Statistics\StatChart;

class Responses extends StatisticsPage
{
    protected static ?string $title = 'Retours et relances';

    protected static ?string $slug = 'retours';

    protected static ?int $navigationSort = 4;

    protected function widgets(): array
    {
        return [
            StatChart::make([
                'title' => 'Taux de réponse avec et sans relance',
                'subtitle' => 'Mesure l\'effet de tes relances.',
                'measure' => 'response_rate', 'dimension' => 'followed_up',
            ]),
            StatChart::make([
                'title' => 'Types de réponse',
                'measure' => 'offers', 'dimension' => 'response_type', 'chart' => 'doughnut',
                'filterDimension' => 'responded', 'filterValue' => 'Réponse reçue',
            ]),
            StatChart::make([
                'title' => 'Motifs de refus',
                'measure' => 'offers', 'dimension' => 'refusal_reason', 'chart' => 'doughnut',
                'filterDimension' => 'response_type', 'filterValue' => 'Refus',
                'subtitle' => 'À renseigner sur chaque refus (fiche de l\'offre, section Suivi).',
            ]),
            StatChart::make([
                'title' => 'Taux d\'entretien par pertinence',
                'subtitle' => 'Vérifie que la note 1 à 3 donnée aux offres prédit bien les entretiens.',
                'measure' => 'interview_rate', 'dimension' => 'relevance',
            ]),
            SilentApplications::class,
        ];
    }
}
