<?php

namespace App\Filament\Widgets\Statistics;

use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Services\Statistics\ProspectStatistics as PS;
use App\Services\Statistics\Statistics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Chiffres clés des candidatures spontanées, comparés aux objectifs.
 */
class ProspectKeyFigures extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return 3;
    }

    protected function getStats(): array
    {
        $filters = $this->pageFilters ?? [];
        $data = PS::dataset($filters);
        $sent = PS::sent($data);
        [$from, $to] = Statistics::range($filters);

        $thisWeek = Prospect::query()->whereDate('sent_at', '>=', today()->startOfWeek())->count();
        $weeks = $sent->isEmpty() ? 0 : max(1, (int) ceil((($from ?? $sent->min('sent_at'))->diffInDays($to ?? today()) + 1) / 7));
        $perWeek = $weeks ? round($sent->count() / $weeks, 1) : null;
        $response = PS::rate($data, 'response');
        $interview = PS::rate($data, 'interview');
        $delay = PS::averageDelay($data);
        $due = Prospect::query()->followUpDue()->count();
        $toQualify = Prospect::query()->where('status', ProspectStatus::ToQualify->value)->count();

        $pct = fn (?float $v) => $v === null ? '—' : str_replace('.', ',', (string) round($v)).' %';

        return [
            Stat::make('Envois cette semaine', (string) $thisWeek)
                ->description('Objectif : '.PS::WEEKLY_TARGET_MIN.' à '.PS::WEEKLY_TARGET_MAX.' par semaine')
                ->color($thisWeek >= PS::WEEKLY_TARGET_MIN ? 'success' : 'warning')
                ->icon('heroicon-o-paper-airplane'),
            Stat::make('Moyenne par semaine', $perWeek === null ? '—' : str_replace('.', ',', (string) $perWeek))
                ->description($sent->count().' envoi(s) sur la période')
                ->color($perWeek !== null && $perWeek >= PS::WEEKLY_TARGET_MIN ? 'success' : 'gray')
                ->icon('heroicon-o-calendar-days'),
            Stat::make('Taux de réponse', $pct($response['value']))
                ->description('Objectif : '.PS::RESPONSE_TARGET.' % · '.$response['hits'].' sur '.$response['n'])
                ->color($response['value'] === null ? 'gray' : ($response['value'] >= PS::RESPONSE_TARGET ? 'success' : 'danger'))
                ->icon('heroicon-o-chat-bubble-left-right'),
            Stat::make('Taux d\'entretien', $pct($interview['value']))
                ->description($interview['hits'].' entretien(s) sur '.$interview['n'].' envoi(s)')
                ->icon('heroicon-o-user-group'),
            Stat::make('Délai moyen de réponse', $delay === null ? '—' : str_replace('.', ',', (string) $delay).' j')
                ->description('Entre l\'envoi et la réponse')
                ->icon('heroicon-o-clock'),
            Stat::make('Relances à faire', (string) $due)
                ->description($toQualify.' entreprise(s) à qualifier')
                ->color($due > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-bell-alert'),
        ];
    }
}
