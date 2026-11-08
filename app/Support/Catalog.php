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
