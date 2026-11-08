<?php

namespace App\Filament\Widgets\Statistics;

use App\Services\Statistics\Statistics;

/**
 * Graphique de l'explorateur : ses variables suivent les filtres de la page à chaque rendu.
 */
class ExplorerChart extends StatChart
{
    protected static bool $isDiscovered = false;

    protected function resolve(): void
    {
        foreach (self::configuration($this->pageFilters ?? []) as $property => $value) {
            $this->{$property} = $value;
        }

        if ($this->facetDimension) {
            $label = Statistics::display($this->facetDimension, (string) $this->facetValue);
            $this->subtitle = $this->title;
            $this->title = Statistics::dimensions()[$this->facetDimension]['label'].' : '.$label;
        }
    }

    /**
     * Réglages du graphique déduits des choix de l'explorateur.
     *
     * @return array<string, mixed>
     */
    public static function configuration(array $f): array
    {
        $measures = Statistics::measures();
        $dimensions = Statistics::dimensions();

        $measure = array_key_exists($f['measure'] ?? '', $measures) ? $f['measure'] : 'response_rate';
        $x = array_key_exists($f['x'] ?? '', $dimensions) ? $f['x'] : 'source';
        $color = array_key_exists($f['color'] ?? '', $dimensions) && $f['color'] !== $x ? $f['color'] : null;
        $filter = array_key_exists($f['filter'] ?? '', $dimensions) ? $f['filter'] : null;
        $isTime = $dimensions[$x]['time'] ?? false;

        return [
            'measure' => $measure,
            'dimension' => $x,
            'series' => $color,
            'chart' => $isTime && ! $color ? 'line' : 'bar',
            'stacked' => $color !== null && $measures[$measure]['format'] === 'count',
            'filterDimension' => $filter,
            'filterValue' => $filter ? ($f['filter_value'] ?? null) : null,
            'limit' => 15,
            'title' => $measures[$measure]['label'].' par '.mb_strtolower($dimensions[$x]['label'])
                .($color ? ', couleur : '.mb_strtolower($dimensions[$color]['label']) : ''),
            'facetDimension' => array_key_exists($f['facet'] ?? '', $dimensions) ? $f['facet'] : null,
        ];
    }
}
