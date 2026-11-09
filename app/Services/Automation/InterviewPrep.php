<?php

namespace App\Services\Automation;

use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Models\Opportunity;
use App\Support\Catalog;
use Illuminate\Support\Str;

/**
 * Fiche de préparation d'entretien, créée dès qu'une candidature passe au statut « Entretien » :
 * l'entreprise, le poste, les questions probables et les preuves chiffrées de la banque de preuves
 * (Réglages > Automatisations) les plus proches de l'annonce. Aucun chiffre n'est inventé :
 * seules les preuves de la banque sont citées.
 */
class InterviewPrep
{
    private const GENERAL_QUESTIONS = [
        'Présentez-vous en deux minutes (parcours, ce qui vous amène à ce poste).',
        'Pourquoi souhaitez-vous rejoindre {entreprise} ?',
        'Pourquoi quitter votre poste actuel ?',
        'Quelle réalisation dont vous êtes le plus fier illustre le mieux ce poste ?',
        'Comment travaillez-vous avec des équipes ou des services qui ne dépendent pas de vous ?',
        'Racontez une situation difficile (délais, budget, désaccord) et ce que vous avez fait.',
        'Quels sont vos points d\'amélioration ?',
        'Quelles sont vos prétentions salariales et votre disponibilité ?',
    ];

    /** Questions selon le métier (mots repérés dans l'intitulé). */
    private const FAMILY_QUESTIONS = [
        'projet' => [
            'Comment pilotez-vous un projet web de bout en bout (cadrage, appel d\'offres, recette, mise en ligne) ?',
            'Comment tenez-vous un budget et un planning quand le périmètre change ?',
            'Comment embarquez-vous des contributeurs ou des métiers peu disponibles ?',
        ],
        'marketing' => [
            'Comment construisez-vous un plan marketing annuel et comment l\'évaluez-vous ?',
            'Quels indicateurs suivez-vous pour mesurer l\'efficacité d\'une campagne ?',
            'Comment arbitrez-vous un budget entre canaux (SEA, réseaux sociaux, contenu, événements) ?',
        ],
        'communication' => [
            'Comment adaptez-vous un message à des publics internes et externes différents ?',
            'Comment gérez-vous une communication de crise ou un sujet sensible ?',
            'Comment faites-vous respecter une charte éditoriale ou graphique à grande échelle ?',
        ],
        'digital' => [
            'Quelle serait votre feuille de route digitale pour les 6 premiers mois ?',
            'Comment améliorez-vous le référencement naturel et l\'expérience utilisateur d\'un site ?',
            'Quels outils (CMS, GA4, CRM, automatisation) maîtrisez-vous et comment les choisissez-vous ?',
        ],
        'responsable' => [
            'Comment managez-vous une équipe et donnez-vous du feedback ?',
            'Comment fixez-vous les priorités quand tout est urgent ?',
        ],
    ];

    private const QUESTIONS_TO_ASK = [
        'Quels sont les objectifs prioritaires du poste pour les 6 à 12 premiers mois ?',
        'Comment l\'équipe est-elle organisée et avec qui travaillerais-je au quotidien ?',
        'Comment la réussite dans ce poste sera-t-elle mesurée ?',
        'Quels sont les grands projets digitaux ou de communication à venir ?',
        'Quelles sont les prochaines étapes du processus de recrutement ?',
    ];

    /**
     * Crée (ou recrée) la fiche et prévient le propriétaire.
     */
    public static function prepare(Opportunity $o, bool $notify = true): string
    {
        $markdown = self::build($o);
        $o->forceFill(['interview_prep' => $markdown, 'interview_prep_at' => now()])->saveQuietly();
        $o->events()->create(['type' => 'note', 'body' => 'Fiche de préparation d\'entretien créée.', 'happened_at' => now()]);

        if ($notify) {
            NotificationCenter::notify(
                'entretien',
                "Entretien chez {$o->company} : ta fiche est prête",
                "{$o->title}\n\nLa fiche réunit l'entreprise, les questions probables, tes preuves chiffrées adaptées au poste et des questions à poser.",
                ['Ouvrir la fiche' => OpportunityResource::getUrl('view', ['record' => $o])],
            );
        }

        return $markdown;
    }

    public static function build(Opportunity $o): string
    {
        $english = $o->language === 'en';
        $lines = ["## {$o->company} – {$o->title}", ''];

        $lines[] = '### L\'entreprise';
        $facts = array_filter([
            'Secteur' => $o->sector,
            'Taille' => Catalog::COMPANY_SIZES[$o->company_size] ?? null,
            'Adresse' => $o->company_address,
            'Lieu du poste' => $o->location,
        ]);
        foreach ($facts as $label => $value) {
            $lines[] = "- **{$label}** : {$value}";
        }
        $lines[] = '- **À vérifier avant l\'entretien** : site, actualités récentes, profil LinkedIn des personnes qui te reçoivent.';
        $lines[] = '';

        $lines[] = '### Le poste';
        foreach (array_filter([
            'Taux / contrat' => $o->rate,
            'Source' => $o->source,
            'Candidature envoyée le' => $o->applied_at?->format('d.m.Y'),
            'Annonce' => $o->url ? "[ouvrir l'annonce]({$o->url})" : null,
        ]) as $label => $value) {
            $lines[] = "- **{$label}** : {$value}";
        }
        if (filled($o->ats_missing_keywords)) {
            $lines[] = '- **Mots-clés de l\'annonce à placer à l\'oral** : '.$o->ats_missing_keywords;
        }
        $lines[] = '';

        $proofs = self::proofs($o);
        $lines[] = '### Tes preuves chiffrées à citer';
        if ($proofs) {
            foreach ($proofs as $p) {
                $lines[] = "- **{$p['id']}** – ".($english && $p['en'] ? $p['en'] : $p['fr']);
            }
            $lines[] = '';
            $lines[] = '_Méthode STAR : situation, tâche, action, résultat. Les montants sont des ordres de grandeur : dis « environ »._';
        } else {
            $lines[] = '- Ajoute ta banque de preuves dans Réglages > Automatisations pour qu\'elles apparaissent ici.';
        }
        $lines[] = '';

        $lines[] = '### Questions probables';
        foreach (self::questions($o) as $q) {
            $lines[] = '- '.$q;
        }
        if ($english) {
            $lines[] = '- L\'annonce est en anglais : prépare ta présentation et deux preuves en anglais.';
        }
        $lines[] = '';

        $lines[] = '### Questions à poser';
        foreach (self::QUESTIONS_TO_ASK as $q) {
            $lines[] = '- '.$q;
        }
        $lines[] = '';
        $lines[] = '### À emporter';
        $lines[] = '- CV, lettre et dossier envoyés (les relire la veille), quelques exemples de réalisations, la liste de tes questions.';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public static function questions(Opportunity $o): array
    {
        $title = Str::lower($o->title.' '.$o->job_family);
        $questions = array_map(fn ($q) => str_replace('{entreprise}', (string) $o->company, $q), self::GENERAL_QUESTIONS);

        foreach (self::FAMILY_QUESTIONS as $word => $list) {
            if (str_contains($title, $word) || ($word === 'digital' && Str::contains($title, ['web', 'numérique', 'digitale']))
                || ($word === 'responsable' && Str::contains($title, ['directeur', 'directrice', 'head', 'manager']))) {
                array_push($questions, ...$list);
            }
        }

        return array_values(array_unique($questions));
    }

    /**
     * Preuves de la banque les plus proches de l'annonce (4 au plus).
     *
     * @return list<array{id: string, fr: string, en: ?string, keywords: string}>
     */
    public static function proofs(Opportunity $o, int $max = 4): array
    {
        $bank = self::parseBank((string) config('dashboard.proof_bank'));
        if (! $bank) {
            return [];
        }

        $haystack = Str::lower(Str::ascii(implode(' ', [
            $o->title, $o->job_family, $o->sector, $o->ats_missing_keywords, $o->notes, $o->cover_letter_text,
        ])));

        $scored = [];
        foreach ($bank as $i => $proof) {
            $score = 0;
            foreach (preg_split('/\s*[,;]\s*/', $proof['keywords']) as $keyword) {
                $keyword = Str::lower(Str::ascii(trim($keyword)));
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    $score += 3;
                }
            }
            foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($proof['keywords']))) as $word) {
                if (strlen($word) >= 4 && str_contains($haystack, $word)) {
                    $score++;
                }
            }
            $score += substr_count($proof['strength'], '★'); // à égalité, la preuve la plus solide
            $scored[] = ['score' => $score, 'index' => $i];
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);

        return array_map(fn ($s) => $bank[$s['index']], array_slice($scored, 0, $max));
    }

    /**
     * Lit la banque de preuves : tableau Markdown « | # | Preuve FR | Preuve EN | Mots-clés | Force | »,
     * ou simple liste « - preuve (mots-clés : …) ».
     *
     * @return list<array{id: string, fr: string, en: ?string, keywords: string, strength: string}>
     */
    public static function parseBank(string $text): array
    {
        $proofs = [];
        $bullets = [];

        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '|')) {
                $cells = array_map('trim', explode('|', trim($line, '|')));
                if (count($cells) < 2 || preg_match('/^[-:\s]*$/', $cells[0]) || in_array(Str::lower($cells[0]), ['#', 'n°', 'id'], true)) {
                    continue;
                }
                $proofs[] = [
                    'id' => $cells[0],
                    'fr' => $cells[1],
                    'en' => count($cells) >= 4 ? ($cells[2] ?: null) : null,
                    'keywords' => count($cells) >= 4 ? $cells[3] : ($cells[2] ?? ''),
                    'strength' => $cells[4] ?? '',
                ];

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $line, $m)) {
                $keywords = preg_match('/\((?:mots-clés|mots clés|keywords)\s*:\s*([^)]+)\)/iu', $m[1], $k) ? $k[1] : $m[1];
                $bullets[] = [
                    'id' => 'P'.(count($bullets) + 1),
                    'fr' => trim(preg_replace('/\((?:mots-clés|mots clés|keywords)\s*:[^)]+\)/iu', '', $m[1])),
                    'en' => null,
                    'keywords' => $keywords,
                    'strength' => '',
                ];
            }
        }

        // Un tableau prime : les listes à puces autour sont alors des consignes, pas des preuves.
        return $proofs ?: $bullets;
    }
}
