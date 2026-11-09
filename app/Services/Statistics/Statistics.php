<?php

namespace App\Services\Statistics;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Support\Catalog;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcul des statistiques : dimensions (variables), mesures et tableaux croisés.
 */
class Statistics
{
    /** En dessous, un taux est signalé comme peu fiable. */
    public const SMALL_SAMPLE = 10;

    public const PERIODS = [
        '30' => '30 derniers jours',
        '90' => '3 derniers mois',
        '365' => '12 derniers mois',
        'all' => 'Depuis le début',
        'custom' => 'Dates choisies',
    ];

    private const NONE = 'Non renseigné';

    /**
     * Offres de la période. Date de référence : envoi de la candidature, sinon ajout de l'offre.
     *
     * @param  array<string, mixed>|null  $filters
     * @return Collection<int, Opportunity>
     */
    public static function dataset(?array $filters = []): Collection
    {
        [$from, $to] = self::range($filters ?? []);

        return Opportunity::query()->get()->filter(function (Opportunity $o) use ($from, $to) {
            $date = self::referenceDate($o);

            return (! $from || ($date && $date->gte($from))) && (! $to || ($date && $date->lte($to)));
        })->values();
    }

    /**
     * @return array{0: ?CarbonInterface, 1: ?CarbonInterface}
     */
    public static function range(array $filters): array
    {
        $period = $filters['period'] ?? '90';

        if ($period === 'custom') {
            return [
                filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->startOfDay() : null,
                filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->endOfDay() : null,
            ];
        }

        return is_numeric($period) ? [today()->subDays((int) $period - 1), now()->endOfDay()] : [null, null];
    }

    public static function referenceDate(Opportunity $o): ?CarbonInterface
    {
        return $o->applied_at ?? $o->added_at ?? $o->created_at;
    }

    // ---- Faits par offre ----------------------------------------------------------------------

    public static function applied(Opportunity $o): bool
    {
        return $o->applied_at !== null
            || in_array($o->status, [OpportunityStatus::Applied, OpportunityStatus::FollowedUp, OpportunityStatus::Interview, OpportunityStatus::OfferReceived], true);
    }

    public static function responded(Opportunity $o): bool
    {
        return self::applied($o) && (
            $o->first_response_at !== null
            || in_array($o->status, [OpportunityStatus::Interview, OpportunityStatus::OfferReceived, OpportunityStatus::Rejected], true)
        );
    }

    public static function interviewed(Opportunity $o): bool
    {
        return $o->interview_at !== null || in_array($o->status, [OpportunityStatus::Interview, OpportunityStatus::OfferReceived], true);
    }

    public static function responseDays(Opportunity $o): ?int
    {
        return $o->applied_at && $o->first_response_at ? (int) $o->applied_at->diffInDays($o->first_response_at) : null;
    }

    // ---- Dimensions ----------------------------------------------------------------------------

    /**
     * Variables disponibles : libellé, ordre des valeurs, et si elle représente le temps.
     *
     * @return array<string, array{label: string, order?: list<string>, time?: bool}>
     */
    public static function dimensions(): array
    {
        return [
            'month' => ['label' => 'Mois', 'time' => true],
            'week' => ['label' => 'Semaine', 'time' => true],
            'weekday' => ['label' => 'Jour d\'envoi', 'order' => ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']],
            'offer_age' => ['label' => 'Âge de l\'offre à l\'envoi', 'order' => ['0 à 3 jours', '4 à 7 jours', '8 à 14 jours', '15 à 30 jours', 'Plus de 30 jours']],
            'source' => ['label' => 'Source', 'order' => [...array_values(Catalog::SOURCES), 'Autre']],
            'status' => ['label' => 'Statut', 'order' => array_map(fn ($s) => $s->getLabel(), OpportunityStatus::cases())],
            'relevance' => ['label' => 'Pertinence', 'order' => ['3 / 3', '2 / 3', '1 / 3']],
            'sector' => ['label' => 'Secteur'],
            'company_size' => ['label' => 'Taille d\'entreprise', 'order' => array_values(Catalog::COMPANY_SIZES)],
            'seniority' => ['label' => 'Niveau de poste', 'order' => array_values(Catalog::SENIORITY)],
            'job_family' => ['label' => 'Métier'],
            'contract_type' => ['label' => 'Contrat', 'order' => array_values(Catalog::CONTRACTS)],
            'worktime' => ['label' => 'Temps de travail', 'order' => ['Temps plein (90 % et plus)', 'Temps partiel']],
            'remote' => ['label' => 'Télétravail', 'order' => array_values(Catalog::REMOTE)],
            'language' => ['label' => 'Langue de l\'annonce', 'order' => array_values(Catalog::LANGUAGES)],
            'location' => ['label' => 'Lieu'],
            'ats' => ['label' => 'Passage par un ATS', 'order' => array_values(Catalog::ATS)],
            'ats_system' => ['label' => 'Système ATS'],
            'ats_score' => ['label' => 'Score ATS', 'order' => ['Moins de 60 %', '60 à 79 %', '80 % et plus']],
            'followed_up' => ['label' => 'Relance', 'order' => ['Relancée', 'Non relancée']],
            'responded' => ['label' => 'Réponse', 'order' => ['Réponse reçue', 'Sans réponse', 'Pas de candidature']],
            'response_type' => ['label' => 'Type de réponse', 'order' => array_values(Catalog::RESPONSE_TYPES)],
            'refusal_reason' => ['label' => 'Motif de refus', 'order' => array_values(Catalog::REFUSAL_REASONS)],
        ];
    }

    public static function value(Opportunity $o, string $dimension): string
    {
        $applied = $o->applied_at;

        return match ($dimension) {
            'month' => ($d = self::referenceDate($o)) ? $d->format('Y-m') : self::NONE,
            'week' => ($d = self::referenceDate($o)) ? $d->format('o-\\SW') : self::NONE,
            'weekday' => $applied ? ucfirst($applied->locale('fr')->dayName) : self::NONE,
            'offer_age' => self::offerAge($o),
            'source' => Catalog::normalizeSource($o->source),
            'status' => $o->status?->getLabel() ?? self::NONE,
            'relevance' => $o->relevance ? $o->relevance.' / 3' : self::NONE,
            'sector' => filled($o->sector) ? $o->sector : self::NONE,
            'job_family' => filled($o->job_family) ? $o->job_family : self::NONE,
            'company_size' => Catalog::COMPANY_SIZES[$o->company_size] ?? self::NONE,
            'seniority' => Catalog::SENIORITY[$o->seniority] ?? self::NONE,
            'contract_type' => Catalog::CONTRACTS[$o->contract_type] ?? self::NONE,
            'remote' => Catalog::REMOTE[$o->remote] ?? self::NONE,
            'language' => Catalog::LANGUAGES[$o->language] ?? self::NONE,
            'worktime' => $o->rate_max === null ? self::NONE : ($o->rate_max >= 90 ? 'Temps plein (90 % et plus)' : 'Temps partiel'),
            'location' => filled($o->location) ? trim($o->location) : self::NONE,
            'ats' => Catalog::ATS[$o->ats] ?? self::NONE,
            'ats_system' => filled($o->ats_system) ? trim($o->ats_system) : self::NONE,
            'ats_score' => Catalog::atsScoreBand($o->ats_score) ?? self::NONE,
            'followed_up' => $o->followed_up_at || $o->status === OpportunityStatus::FollowedUp ? 'Relancée' : 'Non relancée',
            'responded' => ! self::applied($o) ? 'Pas de candidature' : (self::responded($o) ? 'Réponse reçue' : 'Sans réponse'),
            'response_type' => Catalog::RESPONSE_TYPES[$o->response_type] ?? ($o->status === OpportunityStatus::Rejected ? Catalog::RESPONSE_TYPES['refus'] : (self::responded($o) ? Catalog::RESPONSE_TYPES['positive'] : self::NONE)),
            'refusal_reason' => Catalog::REFUSAL_REASONS[$o->refusal_reason] ?? self::NONE,
            default => self::NONE,
        };
    }

    private static function offerAge(Opportunity $o): string
    {
        if (! $o->applied_at || ! $o->published_at) {
            return self::NONE;
        }

        $days = (int) $o->published_at->diffInDays($o->applied_at);

        return match (true) {
            $days <= 3 => '0 à 3 jours',
            $days <= 7 => '4 à 7 jours',
            $days <= 14 => '8 à 14 jours',
            $days <= 30 => '15 à 30 jours',
            default => 'Plus de 30 jours',
        };
    }

    /**
     * Libellé affiché pour une valeur (les mois et semaines sont stockés triables).
     */
    public static function display(string $dimension, string $value): string
    {
        if ($value === self::NONE) {
            return $value;
        }

        return match ($dimension) {
            'month' => ucfirst(Carbon::createFromFormat('!Y-m', $value)->locale('fr')->translatedFormat('M Y')),
            'week' => preg_replace('/^(\d{4})-S(\d+)$/', 'Sem. $2 ($1)', $value),
            default => $value,
        };
    }

    // ---- Mesures --------------------------------------------------------------------------------

    /**
     * @return array<string, array{label: string, format: string, base: string}>
     */
    public static function measures(): array
    {
        return [
            'offers' => ['label' => 'Nombre d\'offres', 'format' => 'count', 'base' => 'offres'],
            'applications' => ['label' => 'Candidatures envoyées', 'format' => 'count', 'base' => 'candidatures'],
            'response_rate' => ['label' => 'Taux de réponse', 'format' => 'percent', 'base' => 'candidatures'],
            'interview_rate' => ['label' => 'Taux d\'entretien', 'format' => 'percent', 'base' => 'candidatures'],
            'response_delay' => ['label' => 'Délai moyen de réponse', 'format' => 'days', 'base' => 'réponses'],
            'relevance' => ['label' => 'Pertinence moyenne', 'format' => 'score', 'base' => 'offres notées'],
            'ats_score' => ['label' => 'Score ATS moyen', 'format' => 'percent', 'base' => 'candidatures notées'],
        ];
    }

    /**
     * Valeur d'une mesure sur un groupe d'offres, avec le nombre de cas qui la fondent.
     *
     * @param  Collection<int, Opportunity>  $group
     * @return array{value: float|int|null, n: int, detail: string}
     */
    public static function measure(string $measure, Collection $group): array
    {
        $applied = $group->filter(fn ($o) => self::applied($o));

        switch ($measure) {
            case 'offers':
                return ['value' => $group->count(), 'n' => $group->count(), 'detail' => $group->count().' offre(s)'];
            case 'applications':
                return ['value' => $applied->count(), 'n' => $applied->count(), 'detail' => $applied->count().' candidature(s) sur '.$group->count().' offre(s)'];
            case 'response_rate':
            case 'interview_rate':
                $hits = $applied->filter(fn ($o) => $measure === 'response_rate' ? self::responded($o) : self::interviewed($o))->count();
                $n = $applied->count();
                $word = $measure === 'response_rate' ? 'réponse(s)' : 'entretien(s)';

                return ['value' => $n ? round(100 * $hits / $n, 1) : null, 'n' => $n, 'detail' => "{$hits} {$word} sur {$n} candidature(s)"];
            case 'response_delay':
                $days = $group->map(fn ($o) => self::responseDays($o))->filter(fn ($d) => $d !== null);

                return ['value' => $days->isEmpty() ? null : round($days->avg(), 1), 'n' => $days->count(), 'detail' => 'sur '.$days->count().' réponse(s)'];
            case 'ats_score':
                $scores = $group->filter(fn ($o) => self::applied($o))->pluck('ats_score')->filter(fn ($s) => $s !== null);

                return ['value' => $scores->isEmpty() ? null : round($scores->avg(), 1), 'n' => $scores->count(), 'detail' => 'sur '.$scores->count().' candidature(s) notée(s)'];
            case 'relevance':
                $scores = $group->pluck('relevance')->filter();

                return ['value' => $scores->isEmpty() ? null : round($scores->avg(), 2), 'n' => $scores->count(), 'detail' => 'sur '.$scores->count().' offre(s) notée(s)'];
        }

        return ['value' => null, 'n' => 0, 'detail' => ''];
    }

    public static function format(string $measure, float|int|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match (self::measures()[$measure]['format']) {
            'percent' => str_replace('.', ',', (string) round($value)).' %',
            'days' => str_replace('.', ',', (string) $value).' j',
            'score' => str_replace('.', ',', (string) $value).' / 3',
            default => (string) $value,
        };
    }

    // ---- Tableaux croisés -------------------------------------------------------------------------

    /**
     * Groupe les offres selon une dimension, dans l'ordre naturel de ses valeurs.
     *
     * @param  Collection<int, Opportunity>  $data
     * @return Collection<string, Collection<int, Opportunity>>
     */
    public static function groupBy(Collection $data, string $dimension, bool $withEmpty = false): Collection
    {
        $groups = $data->groupBy(fn ($o) => self::value($o, $dimension));

        if (! $withEmpty && $groups->keys()->count() > 1) {
            $groups->forget(self::NONE);
        }

        $definition = self::dimensions()[$dimension] ?? [];

        if ($definition['time'] ?? false) {
            return self::fillTimeGaps($groups->sortKeys(), $dimension);
        }

        $order = array_flip($definition['order'] ?? []);

        return $groups->sortBy(fn ($items, $key) => [
            $order[$key] ?? PHP_INT_MAX,
            ($order === [] ? -$items->count() : 0),
            $key,
        ]);
    }

    /**
     * Ajoute les mois ou semaines sans activité, pour une échelle de temps continue.
     *
     * @param  Collection<string, Collection<int, Opportunity>>  $groups
     * @return Collection<string, Collection<int, Opportunity>>
     */
    private static function fillTimeGaps(Collection $groups, string $dimension): Collection
    {
        $keys = $groups->keys()->reject(fn ($k) => $k === self::NONE);

        if ($keys->count() < 2) {
            return $groups;
        }

        $parse = fn (string $k) => $dimension === 'month'
            ? Carbon::createFromFormat('!Y-m', $k)
            : Carbon::now()->setISODate((int) substr($k, 0, 4), (int) substr($k, 6))->startOfWeek();
        $cursor = $parse($keys->first());
        $end = $parse($keys->last());
        $filled = collect();

        for ($i = 0; $cursor->lte($end) && $i < 400; $i++) {
            $key = $dimension === 'month' ? $cursor->format('Y-m') : $cursor->format('o-\\SW');
            $filled[$key] = $groups[$key] ?? collect();
            $dimension === 'month' ? $cursor->addMonth() : $cursor->addWeek();
        }

        return $filled;
    }

    /**
     * Mesure ventilée selon une ou deux dimensions.
     *
     * @param  Collection<int, Opportunity>  $data
     * @return array{labels: list<string>, series: array<string, list<array{value: float|int|null, n: int, detail: string}>>}
     */
    public static function crosstab(Collection $data, string $measure, string $dimension, ?string $series = null, int $limit = 12): array
    {
        $groups = self::groupBy($data, $dimension);

        if (! (self::dimensions()[$dimension]['time'] ?? false) && $groups->count() > $limit) {
            $groups = $groups->take($limit);
        }

        $labels = $groups->keys()->map(fn ($k) => self::display($dimension, (string) $k))->all();
        $result = [];

        if ($series === null) {
            $result[self::measures()[$measure]['label']] = $groups->map(fn ($g) => self::measure($measure, $g))->values()->all();
        } else {
            foreach (self::groupBy($data, $series)->keys()->take(8) as $seriesValue) {
                $result[self::display($series, (string) $seriesValue)] = $groups
                    ->map(fn ($g) => self::measure($measure, $g->filter(fn ($o) => self::value($o, $series) === $seriesValue)))
                    ->values()->all();
            }
        }

        return ['labels' => $labels, 'series' => $result];
    }

    /**
     * Valeurs possibles d'une dimension (filtres de l'explorateur).
     *
     * @return array<string, string>
     */
    public static function values(string $dimension, ?Collection $data = null): array
    {
        return self::groupBy($data ?? self::dataset(['period' => 'all']), $dimension, withEmpty: true)
            ->keys()
            ->mapWithKeys(fn ($k) => [(string) $k => self::display($dimension, (string) $k)])
            ->all();
    }
}
