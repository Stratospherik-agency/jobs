<?php

namespace App\Services\Automation;

use App\Enums\ProspectStatus;
use App\Filament\Resources\Prospects\ProspectResource;
use App\Models\AutomationRun;
use App\Models\Opportunity;
use App\Models\Prospect;
use App\Models\TrackedMessage;
use App\Services\Google\Gmail;
use App\Services\Google\GoogleClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Veille des entreprises cibles (onglet « Spontanées ») : repère le bon moment pour écrire.
 *
 * Deux sources, sans robot ni site tiers :
 *  - les nouvelles offres du tableau de bord publiées par une entreprise cible ;
 *  - les e-mails du libellé Gmail « Jobs » qui la citent (alertes Google, newsletters, presse locale),
 *    classés en recrutement, nomination, levée de fonds ou développement.
 */
class TargetWatch
{
    public const SIGNALS = [
        'recrutement' => ['recrute', 'recrutement', 'engage', 'offre d\'emploi', 'poste à pourvoir', 'hiring', 'job opening', 'we are looking for'],
        'nomination' => ['nommé', 'nommée', 'nomination', 'nouveau directeur', 'nouvelle directrice', 'prend la tête', 'rejoint la direction', 'appointed', 'new ceo', 'new head'],
        'levee' => ['levée de fonds', 'lève', 'financement', 'millions de francs', 'mio', 'série a', 'series a', 'série b', 'series b', 'raised', 'funding', 'investissement'],
        'developpement' => ['ouvre', 'ouverture', 'inaugure', 'nouveaux locaux', 's\'installe', 'déménage', 'rachète', 'acquisition', 'fusion', 'lance', 'lancement', 'expansion'],
    ];

    public const LABELS = [
        'recrutement' => 'Recrute',
        'nomination' => 'Nomination',
        'levee' => 'Levée de fonds',
        'developpement' => 'Développement',
        'actualite' => 'Actualité',
        'offre' => 'Nouvelle offre publiée',
    ];

    public function __construct(private Gmail $gmail) {}

    public static function classify(string $text): string
    {
        $text = Str::lower($text);
        foreach (self::SIGNALS as $kind => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $kind;
                }
            }
        }

        return 'actualite';
    }

    /**
     * @return array{signals: int}
     */
    public function run(int $days = 1): array
    {
        $targets = Prospect::query()->whereNotIn('status', [ProspectStatus::Discarded->value])->get();
        $found = [];

        if ($targets->isEmpty()) {
            return ['signals' => 0];
        }

        // 1. Nouvelles offres d'une entreprise cible.
        $since = AutomationRun::lastRun('veille') ?? now()->subDays($days);
        Opportunity::query()->where('created_at', '>=', $since)->each(function (Opportunity $o) use ($targets, &$found) {
            if ($p = $this->find($targets, $o->company)) {
                $found[] = $this->record($p, 'offre', $o->title.($o->source ? ' ('.$o->source.')' : ''), 'Tableau de bord');
            }
        });

        // 2. E-mails du libellé « Jobs » qui citent une entreprise cible.
        if (GoogleClient::isConnected()) {
            $label = trim((string) config('dashboard.gmail_label', 'Jobs')) ?: 'Jobs';
            $query = 'label:'.(str_contains($label, ' ') ? '"'.$label.'"' : $label).' newer_than:'.max(1, $days + 1).'d';

            foreach ($this->gmail->search($query, 100) as $id) {
                if (TrackedMessage::seen($id, 'veille') || ! ($m = $this->gmail->message($id))) {
                    continue;
                }

                $text = $m['subject'].' '.$m['snippet'].' '.Str::limit($m['text'], 3000, '');
                $matched = null;
                foreach ($targets as $p) {
                    if ($this->mentions($text, $p->company)) {
                        $matched = $p;
                        break;
                    }
                }

                TrackedMessage::query()->create([
                    'gmail_message_id' => $id, 'purpose' => 'veille', 'kind' => $matched ? self::classify($text) : null,
                    'prospect_id' => $matched?->id, 'subject' => Str::limit($m['subject'], 250), 'received_at' => $m['date'],
                ]);

                if ($matched) {
                    $found[] = $this->record($matched, self::classify($text), Str::limit($m['subject'], 160), 'Gmail (libellé '.$label.')');
                }
            }
        }

        if ($found) {
            $lines = collect($found)->map(fn ($f) => "• {$f['company']} – {$f['label']} : {$f['detail']}")->implode("\n");
            $first = $found[0];
            NotificationCenter::notify(
                'veille',
                count($found) === 1 ? "Veille : {$first['company']} ({$first['label']})" : 'Veille : '.count($found).' signaux chez tes entreprises cibles',
                "C'est peut-être le bon moment pour une candidature spontanée.\n\n".$lines,
                ['Ouvrir la fiche' => $first['url'], 'Toutes les cibles' => ProspectResource::getUrl('index')],
            );
        }

        return ['signals' => count($found)];
    }

    /**
     * @return array{company: string, label: string, detail: string, url: string}
     */
    private function record(Prospect $p, string $kind, string $detail, string $source): array
    {
        $label = self::LABELS[$kind] ?? 'Actualité';
        $p->forceFill([
            'signal' => Str::limit($label.' : '.$detail, 250),
            'signal_date' => today(),
            'signal_source' => $source,
        ])->saveQuietly();

        return ['company' => $p->company, 'label' => $label, 'detail' => $detail, 'url' => ProspectResource::getUrl('view', ['record' => $p])];
    }

    /**
     * @param  Collection<int, Prospect>  $targets
     */
    private function find(Collection $targets, ?string $company): ?Prospect
    {
        $name = self::normalize($company);

        return $name === '' ? null : $targets->first(fn (Prospect $p) => self::normalize($p->company) === $name);
    }

    private function mentions(string $text, ?string $company): bool
    {
        $name = self::normalize($company);

        return mb_strlen($name) >= 4 && (bool) preg_match('/(?<![a-z0-9])'.preg_quote($name, '/').'(?![a-z0-9])/', self::normalize($text, false));
    }

    public static function normalize(?string $text, bool $stripLegal = true): string
    {
        $text = Str::lower(Str::ascii((string) $text));
        if ($stripLegal) {
            $text = preg_replace('/\b(sa|sarl|ag|gmbh|ltd|inc|holding|group|groupe)\b\.?/', '', $text);
        }

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }
}
