<?php

namespace App\Services\Automation;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Support\Catalog;
use Illuminate\Support\Str;

/**
 * Score de pertinence d'une offre sur 100, avec la raison de chaque point.
 *
 * Critères : métier (35), zone (20), taux (15), secteur (10), mots-clés ATS (20),
 * moins une pénalité apprise des annonces ignorées avec le bouton « Ignorer ».
 */
class RelevanceScorer
{
    /** Domaines visés (poste marketing, communication, digital, web). */
    private const DOMAIN = [
        'marketing', 'communication', 'digital', 'digitale', 'numérique', 'numerique', 'web', 'internet', 'site',
        'brand', 'marque', 'contenu', 'content', 'e-commerce', 'ecommerce', 'crm', 'growth', 'seo', 'social media',
        'réseaux sociaux', 'media', 'médias', 'éditorial', 'editorial', 'ux', 'online', 'webmaster',
    ];

    /** Niveau ou nature du poste visé. */
    private const ROLE = [
        'responsable', 'directeur', 'directrice', 'head', 'chef de projet', 'cheffe de projet', 'chef·fe de projet',
        'project manager', 'manager', 'lead', 'spécialiste', 'specialiste', 'specialist', 'stratège', 'strategist',
        'stratégie', 'strategy', 'coordinateur', 'coordinatrice', 'chargé', 'chargée',
    ];

    /** Région lausannoise (zone idéale). */
    private const LAUSANNE = [
        'lausanne', 'renens', 'prilly', 'pully', 'epalinges', 'crissier', 'ecublens', 'chavannes', 'bussigny',
        'le mont-sur-lausanne', 'mont-sur-lausanne', 'lutry', 'belmont', 'paudex', 'jouxtens', 'saint-sulpice',
        'st-sulpice', 'romanel', 'cheseaux', 'villars-sainte-croix', 'morges', 'savigny', 'épalinges',
    ];

    /** Reste du canton de Vaud. */
    private const VAUD = [
        'vaud', '(vd)', ' vd', 'nyon', 'vevey', 'montreux', 'yverdon', 'gland', 'rolle', 'aigle', 'cossonay',
        'payerne', 'echallens', 'échallens', 'la tour-de-peilz', 'aubonne', 'moudon', 'orbe', 'oron', 'bex',
        'coppet', 'founex', 'saint-prex', 'st-prex', 'etoy', 'allaman', 'tolochenaz', 'préverenges', 'preverenges',
        'denges', 'echandens', 'mex', 'penthalaz', 'le sentier', 'sainte-croix', 'grandson', 'avenches', 'lucens',
        'villeneuve', 'blonay', 'saint-légier', 'chexbres', 'cully', 'puidoux', 'mézières', 'crans-près-céligny',
    ];

    /** Hors zone (au-delà du canton de Vaud). */
    private const OUTSIDE = [
        'genève', 'geneve', 'geneva', 'fribourg', 'freiburg', 'berne', 'bern', 'zurich', 'zürich', 'bâle', 'basel',
        'neuchâtel', 'neuchatel', 'sion', 'valais', 'wallis', 'lucerne', 'luzern', 'bienne', 'biel', 'jura',
        'delémont', 'la chaux-de-fonds', 'martigny', 'monthey', 'zug', 'zoug', 'lugano', 'ticino', 'france', 'paris',
    ];

    /** Mots trop courants pour servir à l'apprentissage. */
    private const STOPWORDS = [
        'pour', 'avec', 'dans', 'des', 'les', 'une', 'and', 'the', 'with', 'h/f', 'f/h', 'm/f', 'w/m', 'f/m/d',
        'm/w/d', 'responsable', 'manager', 'chef', 'cheffe', 'projet', 'project', 'marketing', 'communication',
        'digital', 'digitale', 'senior', 'junior', 'spécialiste', 'specialist', '100%', '80%', '80-100%',
    ];

    /** @var array{words: array<string, int>, companies: array<string, int>}|null */
    private static ?array $learned = null;

    /**
     * @return array{score: int, reasons: list<string>}
     */
    public static function score(Opportunity $o): array
    {
        $reasons = [];
        $title = self::lower($o->title.' '.$o->job_family);

        // Exclusions de Pierre : les HEP (clientes ou concurrentes de son employeur actuel).
        if (self::isExcluded($o->company)) {
            return ['score' => 0, 'reasons' => ['Entreprise exclue (HEP) : 0 / 100']];
        }

        // Métier (35)
        $domain = self::matches($title, self::DOMAIN);
        $role = self::matches($title, self::ROLE);
        $job = match (true) {
            $domain && $role => 35,
            (bool) $domain => 25,
            (bool) $role => 12,
            default => 0,
        };
        if (in_array($o->seniority, ['junior'], true) || $o->contract_type === 'stage' || str_contains($title, 'stagiaire') || str_contains($title, 'apprenti')) {
            $job = max(0, $job - 15);
            $reasons[] = "Métier {$job}/35 : poste junior, stage ou apprentissage";
        } else {
            $reasons[] = "Métier {$job}/35 : ".match (true) {
                $domain && $role => 'domaine ('.$domain.') et niveau ('.$role.') visés',
                (bool) $domain => 'domaine visé ('.$domain.')',
                (bool) $role => 'niveau visé ('.$role.') hors marketing/digital',
                default => 'intitulé éloigné des postes visés',
            };
        }

        // Zone (20)
        $place = ' '.self::lower($o->location.' '.$o->company_address).' ';
        [$zone, $why] = match (true) {
            (bool) self::matches($place, self::LAUSANNE) => [20, 'région lausannoise'],
            (bool) self::matches($place, self::VAUD) => [15, 'canton de Vaud'],
            $o->remote === 'distance' => [12, 'à distance'],
            (bool) self::matches($place, self::OUTSIDE) => [0, 'hors du canton de Vaud'],
            trim($place) === '' => [8, 'lieu inconnu'],
            default => [8, 'lieu à vérifier'],
        };
        $reasons[] = "Zone {$zone}/20 : {$why}";

        // Taux (15)
        [$rate, $why] = match (true) {
            $o->rate_max === null => [8, 'taux non précisé'],
            $o->rate_max >= 100 => [15, 'jusqu\'à 100 %'],
            $o->rate_max >= 80 => [12, 'au moins 80 %'],
            default => [0, 'moins de 80 %'],
        };
        $reasons[] = "Taux {$rate}/15 : {$why}";

        // Secteur (10) : agences web seulement pour un poste de gestion de projets.
        $isAgency = Catalog::isWebAgency($o->company, $o->sector);
        $projectRole = (bool) self::matches($title, ['chef de projet', 'cheffe de projet', 'project manager', 'gestion de projet']);
        [$sector, $why] = match (true) {
            $isAgency && ! $projectRole => [0, 'agence web (seulement pour un poste de gestion de projets)'],
            $isAgency => [7, 'agence web, poste de gestion de projets'],
            filled($o->sector) => [10, 'secteur '.$o->sector],
            default => [8, 'secteur non renseigné'],
        };
        $reasons[] = "Secteur {$sector}/10 : {$why}";

        // Mots-clés ATS (20)
        if ($o->ats_score !== null) {
            $ats = (int) round(max(0, min(100, $o->ats_score)) / 5);
            $reasons[] = "Mots-clés ATS {$ats}/20 : couverture {$o->ats_score} %";
        } else {
            $ats = 10;
            $reasons[] = 'Mots-clés ATS 10/20 : pas encore analysés';
        }

        $score = $job + $zone + $rate + $sector + $ats;

        // Apprentissage : annonces proches de celles ignorées.
        [$penalty, $why] = self::learnedPenalty($o);
        if ($penalty > 0) {
            $score -= $penalty;
            $reasons[] = "Ajustement -{$penalty} : {$why}";
        }

        return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
    }

    /** Met à jour le score d'une offre sans déclencher les autres automatismes. */
    public static function refresh(Opportunity $o): void
    {
        $result = self::score($o);
        $o->relevance_score = $result['score'];
        $o->relevance_reasons = $result['reasons'];
    }

    /** Recalcule toutes les offres encore ouvertes (après de nouveaux clics sur « Ignorer »). */
    public static function refreshAll(): int
    {
        self::$learned = null;
        $count = 0;

        Opportunity::query()->each(function (Opportunity $o) use (&$count) {
            $result = self::score($o);
            if ($result['score'] !== $o->relevance_score || $result['reasons'] !== $o->relevance_reasons) {
                $o->forceFill(['relevance_score' => $result['score'], 'relevance_reasons' => $result['reasons']])->saveQuietly();
                $count++;
            }
        });

        return $count;
    }

    public static function forget(): void
    {
        self::$learned = null;
    }

    public static function isExcluded(?string $company): bool
    {
        $c = self::lower($company);

        return (bool) preg_match('/\bhep\b|haute école pédagogique|haute ecole pedagogique|pädagogische hochschule/u', $c);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function learnedPenalty(Opportunity $o): array
    {
        $learned = self::learned();
        $penalty = 0;
        $why = [];

        $company = self::lower($o->company);
        if ($company !== '' && ($learned['companies'][$company] ?? 0) > ($o->exists && $o->status === OpportunityStatus::Discarded ? 1 : 0)) {
            $penalty += 5;
            $why[] = 'entreprise déjà ignorée';
        }

        $words = [];
        foreach (self::words($o->title) as $word) {
            if (($learned['words'][$word] ?? 0) >= 2) {
                $words[] = $word;
            }
        }
        if ($words) {
            $penalty += min(12, 4 * count($words));
            $why[] = 'intitulé proche d\'annonces ignorées ('.implode(', ', array_slice($words, 0, 3)).')';
        }

        return [min(15, $penalty), implode(' ; ', $why)];
    }

    /**
     * Mots fréquents dans les annonces ignorées et jamais dans celles où Pierre a postulé.
     *
     * @return array{words: array<string, int>, companies: array<string, int>}
     */
    private static function learned(): array
    {
        if (self::$learned !== null) {
            return self::$learned;
        }

        $ignored = Opportunity::query()->where('status', OpportunityStatus::Discarded->value)->whereNull('applied_at')->get(['company', 'title']);
        $kept = Opportunity::query()->whereNotNull('applied_at')->orWhereIn('status', [
            OpportunityStatus::InPreparation->value, OpportunityStatus::Applied->value, OpportunityStatus::FollowedUp->value,
            OpportunityStatus::Interview->value, OpportunityStatus::OfferReceived->value,
        ])->pluck('title');

        $keptWords = [];
        foreach ($kept as $title) {
            foreach (self::words($title) as $w) {
                $keptWords[$w] = true;
            }
        }

        $words = [];
        $companies = [];
        foreach ($ignored as $o) {
            foreach (array_unique(self::words($o->title)) as $w) {
                if (! isset($keptWords[$w])) {
                    $words[$w] = ($words[$w] ?? 0) + 1;
                }
            }
            $c = self::lower($o->company);
            if ($c !== '') {
                $companies[$c] = ($companies[$c] ?? 0) + 1;
            }
        }

        return self::$learned = ['words' => $words, 'companies' => $companies];
    }

    /**
     * @return list<string>
     */
    private static function words(?string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}%\/-]+/u', self::lower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true)));
    }

    private static function matches(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($needle, '/').'(?![\p{L}])/u', $haystack)) {
                return trim($needle, ' ()');
            }
        }

        return null;
    }

    private static function lower(?string $text): string
    {
        return trim(Str::lower((string) $text));
    }
}
