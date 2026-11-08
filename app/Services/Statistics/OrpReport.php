<?php

namespace App\Services\Statistics;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Support\Catalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Justificatif mensuel des recherches d'emploi, au format des « Preuves des recherches
 * personnelles en vue de trouver un emploi » demandées par l'ORP.
 */
class OrpReport
{
    public const COLUMNS = [
        'date' => 'Date',
        'company' => 'Entreprise',
        'address' => 'Adresse',
        'contact' => 'Personne / adresse de contact',
        'title' => 'Poste',
        'rate' => 'Taux',
        'method' => 'Démarche',
        'kind' => 'Offre ou spontanée',
        'result' => 'Résultat',
    ];

    public static function month(?string $month): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m', (string) $month);
        } catch (\Throwable) {
            return today()->startOfMonth();
        }
    }

    /**
     * @return Collection<int, array<string, string>>
     */
    public static function rows(Carbon $month): Collection
    {
        return Opportunity::query()
            ->with('application')
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->orderBy('applied_at')
            ->get()
            ->map(fn (Opportunity $o) => self::row($o));
    }

    /**
     * @return array<string, string>
     */
    public static function row(Opportunity $o): array
    {
        $spontaneous = Catalog::normalizeSource($o->source) === Catalog::SOURCES['spontanee'];

        return [
            'date' => $o->applied_at->format('d.m.Y'),
            'company' => (string) $o->company,
            'address' => (string) ($o->company_address ?: $o->location),
            'contact' => (string) ($o->application?->to_email ?? ''),
            'title' => (string) $o->title,
            'rate' => match (true) {
                $o->rate_max === null => (string) $o->rate,
                $o->rate_min !== null && $o->rate_min !== $o->rate_max => $o->rate_min.'–'.$o->rate_max.' %',
                default => $o->rate_max.' %',
            },
            'method' => $o->application?->sent_at ? 'Écrite (e-mail)' : 'Écrite (électronique)',
            'kind' => $spontaneous ? 'Candidature spontanée' : 'Offre publiée ('.Catalog::normalizeSource($o->source).')',
            'result' => match ($o->status) {
                OpportunityStatus::Rejected => 'Refus'.($o->refusal_reason ? ' ('.mb_strtolower(Catalog::REFUSAL_REASONS[$o->refusal_reason] ?? '').')' : ''),
                OpportunityStatus::Interview => 'Entretien',
                OpportunityStatus::OfferReceived => 'Offre reçue',
                default => 'En attente',
            },
        ];
    }

    /**
     * CSV lisible par Excel (UTF-8 avec BOM, point-virgule).
     */
    public static function csv(Carbon $month): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values(self::COLUMNS), ';', escape: '');

        foreach (self::rows($month) as $row) {
            // Neutralise les formules (=, +, -, @) pour un tableur.
            fputcsv($handle, array_map(fn ($v) => preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, array_values($row)), ';', escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Mois proposés : ceux qui ont au moins une candidature, plus le mois en cours.
     *
     * @return array<string, string>
     */
    public static function months(): array
    {
        $months = Opportunity::query()->whereNotNull('applied_at')->pluck('applied_at')
            ->map(fn ($d) => $d->format('Y-m'))
            ->push(today()->format('Y-m'))
            ->unique()->sortDesc();

        return $months->mapWithKeys(fn ($m) => [$m => ucfirst(self::month($m)->locale('fr')->translatedFormat('F Y'))])->all();
    }
}
