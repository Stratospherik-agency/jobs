<?php

namespace App\Services\Automation;

use App\Filament\Clusters\Statistics\Pages\Overview;
use App\Models\Opportunity;
use App\Models\Prospect;
use App\Services\Statistics\Statistics;
use App\Support\Catalog;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

/**
 * Bilan hebdomadaire : taux de réponse par source, secteur, type de candidature et langue,
 * avec des ajustements proposés à partir des chiffres (jamais de chiffre inventé).
 */
class WeeklyReport
{
    /** En dessous, un groupe est trop petit pour en tirer une conclusion. */
    private const MIN_SENT = 4;

    /**
     * @return array{from: string, to: string, week: array{sent: int, responses: int, interviews: int}, total: array{sent: int, rate: int}, tables: list<array>, suggestions: list<string>}
     */
    public function build(): array
    {
        $rows = $this->rows();
        $from = today()->subDays(6);
        $week = $rows->filter(fn ($r) => $r['sent_at'] && $r['sent_at']->gte($from));
        $sent = $rows->filter(fn ($r) => $r['sent']);

        $tables = [];
        foreach (['source' => ['Par source', 'Source'], 'type' => ['Par type de candidature', 'Type'], 'sector' => ['Par secteur', 'Secteur'], 'language' => ['Par langue de candidature', 'Langue']] as $key => [$title, $label]) {
            $groups = $this->group($sent, $key);
            if ($groups) {
                $tables[] = ['title' => $title, 'label' => $label, 'rows' => $groups];
            }
        }

        $responses = $sent->where('responded', true)->count();

        return [
            'from' => $from->format('d.m.Y'),
            'to' => today()->format('d.m.Y'),
            'week' => [
                'sent' => $week->where('sent', true)->count(),
                'responses' => $rows->filter(fn ($r) => $r['response_at'] && $r['response_at']->gte($from))->count(),
                'interviews' => $rows->filter(fn ($r) => $r['interviewed'] && $r['response_at'] && $r['response_at']->gte($from))->count(),
            ],
            'total' => ['sent' => $sent->count(), 'rate' => $sent->count() ? (int) round(100 * $responses / $sent->count()) : 0],
            'tables' => $tables,
            'suggestions' => $this->suggestions($sent, $week, $tables),
        ];
    }

    public function send(): array
    {
        $report = $this->build();
        $url = Overview::getUrl();

        $mail = (new MailMessage)
            ->subject("Bilan de la semaine : {$report['week']['sent']} envoi(s), {$report['week']['responses']} réponse(s)")
            ->markdown('mail.bilan-hebdo', ['report' => $report, 'url' => $url]);

        $body = "Cette semaine : {$report['week']['sent']} envoi(s), {$report['week']['responses']} réponse(s), {$report['week']['interviews']} entretien(s). "
            ."Taux de réponse global : {$report['total']['rate']} %.";
        if ($report['suggestions']) {
            $body .= "\n\n".implode("\n", array_map(fn ($s) => '• '.$s, array_slice($report['suggestions'], 0, 3)));
        }

        NotificationCenter::notify('bilan', 'Bilan hebdomadaire', $body, ['Voir les statistiques' => $url], $mail);

        return $report;
    }

    /**
     * Une ligne par candidature : offres du tableau de bord et candidatures spontanées suivies dans l'onglet Spontanées.
     *
     * @return Collection<int, array>
     */
    private function rows(): Collection
    {
        $offers = Opportunity::query()->with('prospect')->get()->map(fn (Opportunity $o) => [
            'sent' => Statistics::applied($o),
            'sent_at' => $o->applied_at,
            'responded' => Statistics::responded($o),
            'interviewed' => Statistics::interviewed($o),
            'response_at' => $o->first_response_at,
            'source' => $o->prospect ? 'Candidature spontanée' : Catalog::normalizeSource($o->source),
            'type' => $o->prospect ? 'Spontanée' : 'Réponse à une offre',
            'sector' => filled($o->sector) ? $o->sector : 'Non renseigné',
            'language' => Catalog::LANGUAGES[$o->language] ?? 'Français',
        ]);

        $spontaneous = Prospect::query()->whereNull('opportunity_id')->get()->map(fn (Prospect $p) => [
            'sent' => $p->status->isSent(),
            'sent_at' => $p->sent_at,
            'responded' => $p->responded(),
            'interviewed' => $p->interviewed(),
            'response_at' => $p->response_at,
            'source' => 'Candidature spontanée',
            'type' => 'Spontanée',
            'sector' => filled($p->sector) ? $p->sector : 'Non renseigné',
            'language' => Catalog::LANGUAGES[$p->language] ?? 'Français',
        ]);

        return $offers->concat($spontaneous)->values();
    }

    /**
     * @return list<array{value: string, sent: int, responses: int, rate: int, interviews: int}>
     */
    private function group(Collection $sent, string $key): array
    {
        return $sent->groupBy($key)->map(fn (Collection $g, $value) => [
            'value' => (string) $value,
            'sent' => $g->count(),
            'responses' => $g->where('responded', true)->count(),
            'rate' => (int) round(100 * $g->where('responded', true)->count() / max(1, $g->count())),
            'interviews' => $g->where('interviewed', true)->count(),
        ])->sortByDesc('sent')->take(8)->values()->all();
    }

    /**
     * @return list<string>
     */
    private function suggestions(Collection $sent, Collection $week, array $tables): array
    {
        $suggestions = [];

        $spontaneous = $week->where('type', 'Spontanée')->where('sent', true)->count();
        if ($spontaneous < 3) {
            $suggestions[] = "Candidatures spontanées : {$spontaneous} cette semaine pour un objectif de 3 à 5. Choisis 2 ou 3 cibles « Retenue » à préparer lundi.";
        } elseif ($spontaneous > 5) {
            $suggestions[] = "Candidatures spontanées : {$spontaneous} cette semaine, au-delà du rythme prévu (3 à 5). Privilégie la qualité des cibles.";
        }

        foreach ($tables as $table) {
            if ($table['label'] !== 'Source' && $table['label'] !== 'Secteur') {
                continue;
            }
            $big = array_values(array_filter($table['rows'], fn ($r) => $r['sent'] >= self::MIN_SENT));
            if (count($big) < 2) {
                continue;
            }
            usort($big, fn ($a, $b) => $b['rate'] <=> $a['rate']);
            $best = $big[0];
            $worst = end($big);
            if ($best['rate'] > $worst['rate']) {
                $what = $table['label'] === 'Source' ? 'source' : 'secteur';
                $suggestions[] = ucfirst($what)." la plus efficace : {$best['value']} ({$best['rate']} % de réponses sur {$best['sent']} envois). "
                    ."La moins efficace : {$worst['value']} ({$worst['rate']} % sur {$worst['sent']}). Donne la priorité à la première.";
            }
            foreach ($big as $row) {
                if ($row['rate'] === 0 && $row['sent'] >= 6) {
                    $suggestions[] = "Aucune réponse sur {$row['sent']} envois pour « {$row['value']} » : revois le ciblage ou la lettre pour ce canal.";
                }
            }
        }

        $silent = $sent->filter(fn ($r) => ! $r['responded'] && $r['sent_at'] && $r['sent_at']->lte(today()->subDays(21)))->count();
        if ($silent >= 3) {
            $suggestions[] = "{$silent} candidatures sans réponse depuis plus de 3 semaines : classe-les ou tente un dernier contact.";
        }

        if ($sent->count() < self::MIN_SENT) {
            $suggestions[] = 'Encore peu d\'envois : les tendances deviendront fiables après une dizaine de candidatures.';
        }

        return $suggestions;
    }
}
