<?php

namespace App\Filament\Widgets\Statistics;

use App\Services\Statistics\ProspectStatistics as PS;
use App\Services\Statistics\Statistics;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Graphiques des candidatures spontanées : envois par semaine, entonnoir, résultats par famille ou canal.
 */
class ProspectChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    /** weekly | funnel | family | channel */
    public string $kind = 'weekly';

    public int|string|array $span = 1;

    protected ?string $maxHeight = '300px';

    public function getColumnSpan(): int|string|array
    {
        return $this->span;
    }

    public function getHeading(): string|Htmlable|null
    {
        return match ($this->kind) {
            'weekly' => 'Envois par semaine',
            'funnel' => 'Entonnoir des candidatures spontanées',
            'family' => 'Résultats par famille d\'entreprise',
            'channel' => 'Résultats par canal d\'envoi',
        };
    }

    public function getDescription(): string|Htmlable|null
    {
        return match ($this->kind) {
            'weekly' => 'La ligne pointillée marque l\'objectif de '.PS::WEEKLY_TARGET_MIN.' envois.',
            'funnel' => 'Au survol : part de l\'étape précédente.',
            default => 'Taux de réponse et d\'entretien. Au survol : nombre d\'envois.',
        };
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $filters = $this->pageFilters ?? [];
        $data = PS::dataset($filters);

        if ($this->kind === 'weekly') {
            $weeks = PS::sentPerWeek($data, Statistics::range($filters)[0]);
            if (! $weeks) {
                return [];
            }

            return [
                'labels' => array_keys($weeks),
                'datasets' => [
                    ['type' => 'bar', 'label' => 'Envois', 'data' => array_values($weeks), 'meta' => array_map(fn ($n) => "{$n} envoi(s)", array_values($weeks)), 'backgroundColor' => '#3b82f6', 'borderRadius' => 4],
                    ['type' => 'line', 'label' => 'Objectif', 'data' => array_fill(0, count($weeks), PS::WEEKLY_TARGET_MIN), 'meta' => array_fill(0, count($weeks), 'Objectif : '.PS::WEEKLY_TARGET_MIN.' à '.PS::WEEKLY_TARGET_MAX), 'borderColor' => '#10b981', 'borderDash' => [6, 4], 'pointRadius' => 0, 'fill' => false],
                ],
            ];
        }

        if ($this->kind === 'funnel') {
            $stages = PS::funnel($data);
            if (! $stages['Cibles repérées']) {
                return [];
            }
            $values = array_values($stages);
            $meta = [];
            foreach ($values as $i => $v) {
                $meta[] = $i === 0 ? "{$v} entreprise(s)" : $v.' — '.($values[$i - 1] ? round(100 * $v / $values[$i - 1]).' % de l\'étape précédente' : '—');
            }

            return [
                'labels' => array_keys($stages),
                'datasets' => [['label' => 'Entonnoir', 'data' => $values, 'meta' => $meta, 'backgroundColor' => ['#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#10b981'], 'borderRadius' => 4]],
            ];
        }

        $table = PS::byAttribute($data, $this->kind);
        if (! $table['labels']) {
            return [];
        }

        return [
            'labels' => $table['labels'],
            'datasets' => [
                ['label' => 'Taux de réponse', 'data' => $table['response'], 'meta' => $table['meta'], 'backgroundColor' => '#3b82f6', 'borderRadius' => 4],
                ['label' => 'Taux d\'entretien', 'data' => $table['interview'], 'meta' => $table['meta'], 'backgroundColor' => '#10b981', 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        $percent = in_array($this->kind, ['family', 'channel'], true) ? 'true' : 'false';
        $horizontal = $this->kind === 'funnel' ? "'y'" : "'x'";
        $legend = $this->kind === 'funnel' ? 'false' : 'true';

        return RawJs::make(<<<JS
            {
                indexAxis: {$horizontal},
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: {$legend}, position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => {
                        const v = ctx.parsed[{$horizontal}] ?? ctx.raw;
                        const shown = {$percent} ? Math.round(v) + ' %' : v;
                        return ' ' + ctx.dataset.label + ' : ' + shown + ' — ' + (ctx.dataset.meta?.[ctx.dataIndex] ?? '');
                    } } },
                },
                scales: {$this->scales($percent)},
            }
            JS);
    }

    private function scales(string $percent): string
    {
        if ($this->kind === 'funnel') {
            return '{ x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } }';
        }

        return $percent === 'true'
            ? "{ y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + ' %' } }, x: { grid: { display: false } } }"
            : '{ y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }';
    }
}
