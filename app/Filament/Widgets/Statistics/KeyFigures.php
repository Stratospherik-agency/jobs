<?php

namespace App\Filament\Widgets\Statistics;

use App\Services\Statistics\Statistics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Chiffres clés de la période, comparés à la période précédente de même durée.
 */
class KeyFigures extends StatsOverviewWidget
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
        $data = Statistics::dataset($filters);
        $previous = $this->previous($filters);

        $stat = function (string $measure, string $label, string $help, string $icon) use ($data, $previous) {
            $now = Statistics::measure($measure, $data);
            $stat = Stat::make($label, Statistics::format($measure, $now['value']))
                ->description($help.' · '.$now['detail'])
                ->icon($icon);

            if ($previous !== null) {
                $before = Statistics::measure($measure, $previous)['value'];
                if ($now['value'] !== null && $before !== null && $now['value'] != $before) {
                    $up = $now['value'] > $before;
                    $better = $measure === 'response_delay' ? ! $up : $up;
                    $stat->descriptionIcon($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                        ->description($help.' · avant : '.Statistics::format($measure, $before))
                        ->color($better ? 'success' : 'danger');
                }
            }

            return $stat;
        };

        $waiting = $data->filter(fn ($o) => Statistics::applied($o) && ! Statistics::responded($o) && ! $o->status?->isClosed())->count();

        return [
            $stat('offers', 'Offres repérées', 'Sur la période', 'heroicon-o-magnifying-glass'),
            $stat('applications', 'Candidatures envoyées', 'Sur la période', 'heroicon-o-paper-airplane'),
            $stat('response_rate', 'Taux de réponse', 'Réponses reçues', 'heroicon-o-chat-bubble-left-right'),
            $stat('interview_rate', 'Taux d\'entretien', 'Entretiens obtenus', 'heroicon-o-user-group'),
            $stat('response_delay', 'Délai moyen de réponse', 'Entre l\'envoi et la première réponse', 'heroicon-o-clock'),
            Stat::make('En attente de réponse', (string) $waiting)
                ->description('Candidatures sans réponse à ce jour')
                ->icon('heroicon-o-inbox-stack'),
        ];
    }

    /**
     * Même durée juste avant la période choisie (rien pour « Depuis le début »).
     */
    private function previous(array $filters): ?Collection
    {
        [$from, $to] = Statistics::range($filters);

        if (! $from || ! $to) {
            return null;
        }

        $days = (int) $from->diffInDays($to) + 1;

        return Statistics::dataset([
            'period' => 'custom',
            'from' => Carbon::parse($from)->subDays($days)->toDateString(),
            'to' => Carbon::parse($from)->subDay()->toDateString(),
        ]);
    }
}
