<?php

namespace App\Filament\Clusters\Statistics\Pages;

use App\Filament\Widgets\Statistics\StatChart;

class Ats extends StatisticsPage
{
    protected static ?string $title = 'ATS';

    protected static ?string $slug = 'ats';

    protected static ?int $navigationSort = 5;

    protected function widgets(): array
    {
        return [
            StatChart::make([
                'title' => 'Taux de réponse selon le passage par un ATS',
                'subtitle' => 'Compare les candidatures triées par un logiciel à celles lues directement.',
                'measure' => 'response_rate', 'dimension' => 'ats',
            ]),
            StatChart::make([
                'title' => 'Taux d\'entretien selon le passage par un ATS',
                'measure' => 'interview_rate', 'dimension' => 'ats',
            ]),
            StatChart::make([
                'title' => 'Taux de réponse selon le score ATS',
                'subtitle' => 'Score : part des mots-clés de l\'annonce présents dans ton CV.',
                'measure' => 'response_rate', 'dimension' => 'ats_score',
            ]),
            StatChart::make([
                'title' => 'Taux d\'entretien selon le score ATS',
                'measure' => 'interview_rate', 'dimension' => 'ats_score',
            ]),
            StatChart::make([
                'title' => 'Taux de réponse par système ATS',
                'measure' => 'response_rate', 'dimension' => 'ats_system', 'horizontal' => true,
            ]),
            StatChart::make([
                'title' => 'Candidatures envoyées par système ATS',
                'measure' => 'applications', 'dimension' => 'ats_system', 'horizontal' => true,
            ]),
            StatChart::make([
                'title' => 'Évolution du score ATS moyen',
                'subtitle' => 'Montre si l\'adaptation de tes CV progresse au fil des mois.',
                'measure' => 'ats_score', 'dimension' => 'month', 'chart' => 'line', 'span' => 'full',
            ]),
        ];
    }
}
