<?php

namespace App\Filament\Widgets\Statistics;

use App\Enums\OpportunityStatus;
use App\Services\Statistics\Statistics;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Entonnoir : offres repérées → candidatures → réponses → entretiens → offres reçues.
 */
class Funnel extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): string|Htmlable|null
    {
        return 'Entonnoir des candidatures';
    }

    public function getDescription(): string|Htmlable|null
    {
        return 'Au survol : part de l\'étape précédente qui atteint cette étape.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = Statistics::dataset($this->pageFilters ?? []);

        $stages = [
            'Offres repérées' => $data->count(),
            'Candidatures envoyées' => $data->filter(fn ($o) => Statistics::applied($o))->count(),
            'Réponses reçues' => $data->filter(fn ($o) => Statistics::responded($o))->count(),
            'Entretiens' => $data->filter(fn ($o) => Statistics::interviewed($o))->count(),
            'Offres reçues' => $data->filter(fn ($o) => $o->status === OpportunityStatus::OfferReceived)->count(),
        ];

        if ($stages['Offres repérées'] === 0) {
            return [];
        }

        $values = array_values($stages);
        $meta = [];
        foreach ($values as $i => $value) {
            $meta[] = $i === 0
                ? "{$value} offre(s)"
                : $value.' — '.($values[$i - 1] ? round(100 * $value / $values[$i - 1]).' % de l\'étape précédente' : '—');
        }

        return [
            'labels' => array_keys($stages),
            'datasets' => [[
                'label' => 'Entonnoir',
                'data' => $values,
                'meta' => $meta,
                'backgroundColor' => ['#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#10b981'],
                'borderRadius' => 4,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => ' ' + ctx.dataset.meta[ctx.dataIndex] } },
                },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } },
            }
            JS);
    }
}
