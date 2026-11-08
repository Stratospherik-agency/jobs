<?php

namespace App\Services\Statistics;

use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Support\Catalog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Statistiques des candidatures spontanées. Objectifs fixés dans le plan : 3 à 5 envois par
 * semaine et 15 % de réponses.
 */
class ProspectStatistics
{
    public const WEEKLY_TARGET_MIN = 3;

    public const WEEKLY_TARGET_MAX = 5;

    public const RESPONSE_TARGET = 15;

    /**
     * Cibles de la période : date d'envoi, sinon date d'ajout.
     *
     * @return Collection<int, Prospect>
     */
    public static function dataset(?array $filters = []): Collection
    {
        [$from, $to] = Statistics::range($filters ?? []);

        return Prospect::query()->get()->filter(function (Prospect $p) use ($from, $to) {
            $date = $p->sent_at ?? $p->added_at ?? $p->created_at;

            return (! $from || ($date && $date->gte($from))) && (! $to || ($date && $date->lte($to)));
        })->values();
    }

    /** @return Collection<int, Prospect> */
    public static function sent(Collection $data): Collection
    {
        return $data->filter(fn (Prospect $p) => $p->status->isSent() || $p->sent_at !== null)->values();
    }

    /**
     * @return array{value: ?float, hits: int, n: int}
     */
    public static function rate(Collection $data, string $kind): array
    {
        $sent = self::sent($data);
        $hits = $sent->filter(fn (Prospect $p) => $kind === 'response' ? $p->responded() : $p->interviewed())->count();

        return ['value' => $sent->count() ? round(100 * $hits / $sent->count(), 1) : null, 'hits' => $hits, 'n' => $sent->count()];
    }

    public static function averageDelay(Collection $data): ?float
    {
        $days = $data->map(fn (Prospect $p) => $p->responseDays())->filter(fn ($d) => $d !== null);

        return $days->isEmpty() ? null : round($days->avg(), 1);
    }

    /**
     * Envois par semaine (lundi), semaines vides comprises.
     *
     * @return array<string, int> libellé → nombre
     */
    public static function sentPerWeek(Collection $data, ?CarbonInterface $from = null): array
    {
        $sent = self::sent($data)->filter(fn (Prospect $p) => $p->sent_at);

        if ($sent->isEmpty()) {
            return [];
        }

        $start = ($from ?? $sent->min('sent_at'))->copy()->startOfWeek();
        $end = today()->startOfWeek();
        $weeks = [];

        for ($week = $start->copy(); $week->lte($end); $week->addWeek()) {
            $weeks[$week->format('Y-m-d')] = 0;
        }

        foreach ($sent as $p) {
            $key = $p->sent_at->copy()->startOfWeek()->format('Y-m-d');
            if (isset($weeks[$key])) {
                $weeks[$key]++;
            }
        }

        $labels = [];
        foreach ($weeks as $date => $count) {
            $labels['Sem. du '.Carbon::parse($date)->format('d.m')] = $count;
        }

        return $labels;
    }

    /**
     * Taux de réponse et d'entretien selon la famille ou le canal.
     *
     * @return array{labels: list<string>, response: list<?float>, interview: list<?float>, meta: list<string>}
     */
    public static function byAttribute(Collection $data, string $attribute): array
    {
        $options = match ($attribute) {
            'family' => Catalog::PROSPECT_FAMILIES,
            'ats' => Catalog::ATS,
            default => Catalog::PROSPECT_CHANNELS,
        };
        $sent = self::sent($data);
        $result = ['labels' => [], 'response' => [], 'interview' => [], 'meta' => []];

        foreach ([...$options, '' => 'Non renseigné'] as $key => $label) {
            $group = $sent->filter(fn (Prospect $p) => (string) $p->{$attribute} === (string) $key);
            if ($group->isEmpty()) {
                continue;
            }
            $response = self::rate($group, 'response');
            $interview = self::rate($group, 'interview');
            $result['labels'][] = $label;
            $result['response'][] = $response['value'];
            $result['interview'][] = $interview['value'];
            $result['meta'][] = "{$response['hits']} réponse(s) et {$interview['hits']} entretien(s) sur {$response['n']} envoi(s)";
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public static function funnel(Collection $data): array
    {
        $selected = $data->filter(fn (Prospect $p) => ! in_array($p->status, [ProspectStatus::ToQualify, ProspectStatus::Discarded], true));

        return [
            'Cibles repérées' => $data->count(),
            'Retenues' => $selected->count(),
            'Envoyées' => self::sent($data)->count(),
            'Réponses' => self::sent($data)->filter->responded()->count(),
            'Entretiens' => self::sent($data)->filter->interviewed()->count(),
        ];
    }
}
