<?php

namespace App\Support;

/**
 * Listes de valeurs des caractéristiques d'une offre (formulaire, registre, statistiques).
 */
final class Catalog
{
    public const COMPANY_SIZES = [
        'startup' => 'Start-up',
        'pme' => 'PME (moins de 250 personnes)',
        'grande' => 'Grande entreprise',
        'institution' => 'Institution publique ou parapublique',
        'association' => 'Association ou fondation',
    ];

    public const SENIORITY = [
        'junior' => 'Junior',
        'confirme' => 'Confirmé',
        'senior' => 'Senior',
        'cadre' => 'Cadre',
        'direction' => 'Direction',
    ];

    public const CONTRACTS = [
        'cdi' => 'CDI',
        'cdd' => 'CDD',
        'mandat' => 'Mandat / freelance',
        'temporaire' => 'Temporaire',
        'stage' => 'Stage',
    ];

    public const REMOTE = [
        'site' => 'Sur site',
        'hybride' => 'Hybride',
        'distance' => 'À distance',
    ];

    public const LANGUAGES = [
        'fr' => 'Français',
        'en' => 'Anglais',
        'de' => 'Allemand',
        'it' => 'Italien',
    ];

    public const RESPONSE_TYPES = [
        'positive' => 'Positive (entretien, contact)',
        'refus' => 'Refus',
        'information' => 'Demande d\'informations',
    ];

    public const REFUSAL_REASONS = [
        'profil' => 'Profil ne correspond pas',
        'experience' => 'Expérience insuffisante ou trop élevée',
        'langue' => 'Langue',
        'pourvu' => 'Poste déjà pourvu',
        'interne' => 'Candidat interne',
        'sans_motif' => 'Sans motif',
        'autre' => 'Autre',
    ];

    /** Candidatures spontanées : familles d'entreprises cibles. */
    public const PROSPECT_FAMILIES = [
        'institution' => 'Institution',
        'grande' => 'Grande',
        'moyenne' => 'Moyenne',
        'petite' => 'Petite',
    ];

    /** Candidatures spontanées : canal d'envoi. */
    public const PROSPECT_CHANNELS = [
        'portail' => 'Portail carrière',
        'email' => 'E-mail direct',
        'reseau' => 'Réseau',
    ];

    /** Candidatures spontanées : type de réponse. */
    public const PROSPECT_RESPONSES = [
        'entretien' => 'Entretien',
        'refus' => 'Refus',
        'vivier' => 'Vivier',
        'sans_reponse' => 'Sans réponse',
    ];

    /** Agences web : Pierre ne les vise que pour un poste de gestion de projets (signalées, pas bloquées). */
    public static function isWebAgency(?string ...$texts): bool
    {
        $text = mb_strtolower(implode(' ', array_filter($texts)));

        return (bool) preg_match('/agence\s+(web|digitale|de\s+communication\s+digitale|de\s+marketing\s+digital|internet)|web\s*agency|digital\s+agency|agence\s+de\s+cr[ée]ation\s+de\s+sites/u', $text);
    }

    public const ATS = [
        'oui' => 'Oui',
        'non' => 'Non',
        'inconnu' => 'Inconnu',
    ];

    /** Systèmes de recrutement (ATS) courants en Suisse romande. */
    public const ATS_SYSTEMS = [
        'Workday', 'SuccessFactors', 'Abacus Umantis', 'Refline', 'Greenhouse', 'Lever', 'SmartRecruiters',
        'Personio', 'Teamtailor', 'Recruitee', 'Softgarden', 'iCIMS', 'Taleo', 'Prospective', 'Indeed (Indeed Apply)',
        'LinkedIn (Easy Apply)', 'E-mail direct',
    ];

    /** Domaine du lien de l'annonce → système ATS. */
    private const ATS_DOMAINS = [
        'myworkdayjobs.com' => 'Workday', 'workday.com' => 'Workday',
        'successfactors' => 'SuccessFactors', 'sapsf.' => 'SuccessFactors',
        'umantis.com' => 'Abacus Umantis', 'refline.ch' => 'Refline',
        'greenhouse.io' => 'Greenhouse', 'lever.co' => 'Lever', 'smartrecruiters.com' => 'SmartRecruiters',
        'personio.' => 'Personio', 'teamtailor.com' => 'Teamtailor', 'recruitee.com' => 'Recruitee',
        'softgarden.io' => 'Softgarden', 'softgarden.de' => 'Softgarden', 'icims.com' => 'iCIMS',
        'taleo.net' => 'Taleo', 'prospective.ch' => 'Prospective',
    ];

    public static function atsFromUrl(?string $url): ?string
    {
        $host = mb_strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        foreach (self::ATS_DOMAINS as $needle => $system) {
            if (str_contains($host, $needle)) {
                return $system;
            }
        }

        return null;
    }

    /** Tranches du score ATS retenues pour l'analyse. */
    public static function atsScoreBand(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score < 60 => 'Moins de 60 %',
            $score < 80 => '60 à 79 %',
            default => '80 % et plus',
        };
    }

    /** Zones du canton de Vaud : mots repérés dans le champ « Lieu ». */
    public const ZONES = [
        'Lausanne et Ouest lausannois' => ['lausanne', 'renens', 'prilly', 'ecublens', 'écublens', 'crissier', 'bussigny', 'chavannes', 'saint-sulpice', 'st-sulpice', 'epalinges', 'épalinges', 'pully', 'le mont', 'romanel', 'cheseaux', 'biopôle', 'biopole', 'epfl', 'unil'],
        'Lavaux et Riviera' => ['lutry', 'cully', 'lavaux', 'vevey', 'montreux', 'la tour-de-peilz', 'clarens', 'chexbres', 'blonay', 'corsier', 'caux'],
        'La Côte' => ['morges', 'nyon', 'rolle', 'gland', 'aubonne', 'saint-prex', 'st-prex', 'etoy', 'allaman', 'coppet', 'préverenges', 'preverenges', 'tolochenaz', 'apples'],
        'Nord vaudois et Broye' => ['yverdon', 'orbe', 'grandson', 'sainte-croix', 'vallorbe', 'payerne', 'moudon', 'avenches', 'lucens', 'chavornay'],
        'Chablais vaudois' => ['aigle', 'bex', 'villeneuve', 'leysin', 'villars', 'ollon'],
        'Gros-de-Vaud' => ['echallens', 'échallens', 'oron', 'mézières', 'mezieres'],
        'Tout le canton (Vaud)' => ['vaud', 'vd'],
    ];

    /** Mots recherchés dans « Source » pour chaque plateforme. */
    public const SOURCE_NEEDLES = [
        'indeed' => ['indeed'], 'jobup' => ['jobup'], 'linkedin' => ['linkedin'], 'google' => ['google'],
        'jobs.ch' => ['jobs.ch'], 'site' => ['site', 'carrière', 'careers'], 'spontanee' => ['spontan'],
        'reseau' => ['réseau', 'reseau'],
    ];

    public const SECTORS = [
        'Tech et logiciels', 'Industrie', 'Horlogerie et luxe', 'Banque et assurance', 'Santé et pharma',
        'Biotech et medtech', 'Commerce et distribution', 'Télécoms', 'Médias et communication',
        'Tourisme et hôtellerie', 'Sport', 'Éducation et recherche', 'Administration publique',
        'Organisations internationales', 'Associations et fondations', 'Construction et immobilier',
        'Énergie et environnement', 'Transport et logistique', 'Alimentation',
    ];

    public const JOB_FAMILIES = [
        'Marketing digital', 'Marketing produit', 'Communication', 'Contenu et réseaux sociaux',
        'Marque et création', 'Événementiel', 'Relations publiques', 'Direction marketing', 'Data et CRM',
    ];

    /** Sources de référence : toute autre valeur est rangée dans « Autre ». */
    public const SOURCES = [
        'indeed' => 'Indeed',
        'jobup' => 'jobup',
        'linkedin' => 'LinkedIn',
        'google' => 'Google Careers',
        'jobs.ch' => 'jobs.ch',
        'site' => 'Site de l\'entreprise',
        'spontanee' => 'Candidature spontanée',
        'reseau' => 'Réseau',
    ];

    /**
     * Ramène une source saisie librement (« Alerte Indeed (Gmail) ») à une source de référence.
     */
    public static function normalizeSource(?string $source): string
    {
        $value = mb_strtolower((string) $source);

        foreach ([
            'spontan' => 'spontanee', 'indeed' => 'indeed', 'jobup' => 'jobup', 'linkedin' => 'linkedin',
            'google' => 'google', 'jobs.ch' => 'jobs.ch', 'réseau' => 'reseau', 'reseau' => 'reseau',
            'site' => 'site', 'carrière' => 'site', 'careers' => 'site',
        ] as $needle => $key) {
            if (str_contains($value, $needle)) {
                return self::SOURCES[$key];
            }
        }

        return $value === '' ? 'Non renseignée' : 'Autre';
    }

    /**
     * Retrouve la clé d'une liste depuis son libellé ou sa clé (import du registre).
     *
     * @param  array<string, string>  $options
     */
    public static function keyFor(array $options, ?string $value): ?string
    {
        $needle = mb_strtolower(trim((string) $value));

        if ($needle === '') {
            return null;
        }

        foreach ($options as $key => $label) {
            if ($needle === mb_strtolower($key) || $needle === mb_strtolower($label) || str_starts_with(mb_strtolower($label), $needle)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Taux d'activité minimal et maximal lus dans un texte libre (« 80-100 % », « 100% », « Temps partiel »).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function parseRate(?string $rate): array
    {
        $text = mb_strtolower((string) $rate);

        if (preg_match_all('/(\d{2,3})\s*%?/', $text, $m) && $m[1]) {
            $values = array_values(array_filter(array_map('intval', $m[1]), fn ($v) => $v >= 10 && $v <= 100));
            if ($values) {
                return [min($values), max($values)];
            }
        }

        if (str_contains($text, 'partiel')) {
            return [null, 70];
        }

        if (str_contains($text, 'plein')) {
            return [100, 100];
        }

        return [null, null];
    }

    /**
     * Type de contrat mentionné dans le champ « Taux / contrat ».
     */
    public static function contractFromText(?string $text): ?string
    {
        $text = mb_strtolower((string) $text);

        foreach (['cdi' => 'cdi', 'cdd' => 'cdd', 'durée indéterminée' => 'cdi', 'durée déterminée' => 'cdd', 'stage' => 'stage', 'freelance' => 'mandat', 'mandat' => 'mandat', 'temporaire' => 'temporaire'] as $needle => $key) {
            if (str_contains($text, $needle)) {
                return $key;
            }
        }

        return null;
    }
}
