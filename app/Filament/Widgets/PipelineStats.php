<?php

namespace App\Filament\Widgets;

use App\Enums\OpportunityStatus;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PipelineStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $count = fn (OpportunityStatus ...$statuses) => Opportunity::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            ->count();

        $link = fn (OpportunityStatus ...$statuses) => OpportunityResource::getUrl('index', [
            'filters' => ['status' => ['values' => array_map(fn ($s) => $s->value, $statuses)]],
        ]);

        $due = Opportunity::query()->followUpDue()->count();

        return [
            Stat::make('À évaluer', $count(OpportunityStatus::ToEvaluate))
                ->description('Offres repérées à trier')
                ->url($link(OpportunityStatus::ToEvaluate)),
            Stat::make('À postuler', $count(OpportunityStatus::ToApply))
                ->description('Candidatures à préparer')
                ->color('info')
                ->url($link(OpportunityStatus::ToApply)),
            Stat::make('En attente de réponse', $count(OpportunityStatus::Applied, OpportunityStatus::FollowedUp))
                ->description($due > 0 ? "{$due} relance(s) à faire" : 'Aucune relance en retard')
                ->descriptionColor($due > 0 ? 'danger' : 'gray')
                ->color('warning')
                ->url($link(OpportunityStatus::Applied, OpportunityStatus::FollowedUp)),
            Stat::make('Entretiens', $count(OpportunityStatus::Interview, OpportunityStatus::OfferReceived))
                ->description('Entretiens et offres reçues')
                ->color('success')
                ->url($link(OpportunityStatus::Interview, OpportunityStatus::OfferReceived)),
        ];
    }
}
