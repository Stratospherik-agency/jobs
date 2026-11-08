<?php

namespace App\Filament\Widgets\Statistics;

use App\Models\Opportunity;
use App\Services\Statistics\Statistics;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Graphique statistique générique : une mesure ventilée selon une ou deux variables,
 * avec une infobulle qui détaille la valeur et le nombre de cas.
 */
class StatChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    /** Palette lisible en clair comme en sombre. */
    public const PALETTE = ['#3b82f6', '#f59e0b', '#10b981', '#f43f5e', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'];

    public string $title = '';

    public ?string $subtitle = null;

    public string $measure = 'applications';

    public string $dimension = 'month';

    public ?string $series = null;

    /** Seconde mesure affichée sur le même graphique (sans variable de couleur). */
    public ?string $secondMeasure = null;

    public string $chart = 'bar';

    public bool $horizontal = false;

    public bool $stacked = false;

    public ?string $facetDimension = null;

    public ?string $facetValue = null;

    public ?string $filterDimension = null;

    public ?string $filterValue = null;

    public int $limit = 12;

    public int|string|array $span = 1;

    protected ?string $maxHeight = '320px';

    /**
     * Point d'extension : l'explorateur relit ici ses réglages depuis les filtres de la page.
     */
    protected function resolve(): void {}

    public function getHeading(): string|Htmlable|null
    {
        $this->resolve();

        return $this->title ?: Statistics::measures()[$this->measure]['label'].' par '.mb_strtolower(Statistics::dimensions()[$this->dimension]['label']);
    }

    public function getDescription(): string|Htmlable|null
    {
        $this->resolve();
        $rate = Statistics::measures()[$this->measure]['format'] !== 'count';
        $n = Statistics::measure($this->measure, $this->data())['n'];

        $notes = array_filter([
            $this->subtitle,
            $rate && $n < Statistics::SMALL_SAMPLE ? "Échantillon faible ({$n}) : à interpréter avec prudence." : null,
        ]);

        return $notes ? implode(' ', $notes) : null;
    }

    public function getColumnSpan(): int|string|array
    {
        return $this->span;
    }

    protected function getType(): string
    {
        return $this->chart;
    }

    /**
     * @return Collection<int, Opportunity>
     */
    protected function data(): Collection
    {
        $data = Statistics::dataset($this->pageFilters ?? []);

        foreach ([[$this->facetDimension, $this->facetValue], [$this->filterDimension, $this->filterValue]] as [$dimension, $value]) {
            if (filled($dimension) && $value !== null && $value !== '') {
                $data = $data->filter(fn ($o) => Statistics::value($o, $dimension) === $value)->values();
            }
        }

        return $data;
    }

    protected function getData(): array
    {
        $this->resolve();
        $table = Statistics::crosstab($this->data(), $this->measure, $this->dimension, $this->series, $this->limit);

        if ($this->secondMeasure && $this->series === null) {
            $table['series'] += Statistics::crosstab($this->data(), $this->secondMeasure, $this->dimension, null, $this->limit)['series'];
        }

        $hasValue = collect($table['series'])->flatten(1)->contains(fn ($cell) => filled($cell['value']) && $cell['value'] != 0);
        if (! $hasValue) {
            return [];
        }

        $datasets = [];
        $index = 0;
        $pie = in_array($this->chart, ['doughnut', 'pie'], true);

        foreach ($table['series'] as $name => $cells) {
            $color = self::PALETTE[$index++ % count(self::PALETTE)];
            $datasets[] = [
                'label' => $name,
                'data' => array_map(fn ($c) => $c['value'], $cells),
                'meta' => array_map(fn ($c) => Statistics::format($index === 1 ? $this->measure : ($this->secondMeasure ?? $this->measure), $c['value']).' — '.$c['detail'], $cells),
                'backgroundColor' => $pie ? array_slice(array_merge(self::PALETTE, self::PALETTE), 0, count($cells)) : ($this->chart === 'line' ? $color.'33' : $color),
                'borderColor' => $pie ? 'transparent' : $color,
                'fill' => $this->chart === 'line',
                'tension' => 0.3,
                'borderRadius' => $this->chart === 'bar' ? 4 : 0,
                'spanGaps' => true,
            ];
        }

        return ['labels' => $table['labels'], 'datasets' => $datasets];
    }

    protected function getOptions(): RawJs
    {
        $this->resolve();
        $format = Statistics::measures()[$this->measure]['format'];
        $suffix = match ($format) {
            'percent' => ' %',
            'days' => ' j',
            default => '',
        };
        $multi = $this->series !== null || $this->secondMeasure !== null || in_array($this->chart, ['doughnut', 'pie'], true);
        $axis = $this->horizontal ? 'y' : 'x';
        $valueAxis = $this->horizontal ? 'x' : 'y';
        $stacked = $this->stacked ? 'true' : 'false';
        $max = $format === 'percent' ? 'max: 100,' : ($format === 'score' ? 'max: 3,' : '');
        $scales = in_array($this->chart, ['doughnut', 'pie'], true) ? '{ x: { display: false }, y: { display: false } }' : <<<JS
            {
                {$axis}: { stacked: {$stacked}, grid: { display: false } },
                {$valueAxis}: { stacked: {$stacked}, beginAtZero: true, {$max} ticks: { precision: 0, callback: (v) => v + '{$suffix}' } }
            }
            JS;

        return RawJs::make(<<<JS
            {
                indexAxis: '{$axis}',
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: {$this->bool($multi)}, position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const meta = ctx.dataset.meta ? ctx.dataset.meta[ctx.dataIndex] : null;
                                const name = ctx.chart.data.datasets.length > 1 ? ctx.dataset.label + ' : ' : '';
                                return ' ' + name + (meta ?? ctx.formattedValue);
                            },
                        },
                    },
                },
                scales: {$scales},
            }
            JS);
    }

    private function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return 'Pas encore de données';
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return 'Ce graphique se remplira avec tes prochaines candidatures, ou en élargissant la période.';
    }
}
