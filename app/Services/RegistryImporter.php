<?php

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Support\Catalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importe le registre des candidatures exporté de Google Sheets en CSV
 * (Fichier > Télécharger > Valeurs séparées par des virgules).
 *
 * Les offres déjà présentes (même lien, ou même entreprise et même poste) sont ignorées,
 * pour qu'un nouvel import n'écrase jamais le suivi fait dans le tableau de bord.
 */
class RegistryImporter
{
    /** En-têtes du registre → attributs du modèle. */
    public const COLUMNS = [
        "date d'ajout" => 'added_at',
        'entreprise' => 'company',
        'poste' => 'title',
        'lieu' => 'location',
        'taux' => 'rate',
        'source' => 'source',
        'lien' => 'url',
        'publiée le' => 'published_at',
        'pertinence (1-3)' => 'relevance',
        'statut' => 'status',
        'date de candidature' => 'applied_at',
        'relance prévue' => 'follow_up_at',
        'retour' => 'feedback',
        'notes' => 'notes',
        'secteur' => 'sector',
        "taille d'entreprise" => 'company_size',
        'niveau' => 'seniority',
        'métier' => 'job_family',
        'contrat' => 'contract_type',
        'télétravail' => 'remote',
        'langue' => 'language',
        'salaire min' => 'salary_min',
        'salaire max' => 'salary_max',
        'adresse' => 'company_address',
        'ats' => 'ats',
        'système ats' => 'ats_system',
        'outil ats' => 'ats_system',
        'score ats (%)' => 'ats_score',
        'score ats' => 'ats_score',
        'mots-clés manquants' => 'ats_missing_keywords',
    ];

    /** Colonnes à liste fermée : libellé du registre → clé. */
    private const CHOICES = [
        'company_size' => Catalog::COMPANY_SIZES,
        'seniority' => Catalog::SENIORITY,
        'contract_type' => Catalog::CONTRACTS,
        'remote' => Catalog::REMOTE,
        'language' => Catalog::LANGUAGES,
        'ats' => Catalog::ATS,
    ];

    private const DATES = ['added_at', 'published_at', 'applied_at', 'follow_up_at'];

    /**
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function importFile(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Impossible de lire le fichier {$path}.");
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if (! $header) {
                throw new RuntimeException('Le fichier est vide.');
            }

            $map = $this->mapHeader($header);
            $rows = [];
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $this->importRows($map, $rows);
    }

    /**
     * Synchronise le tableau de bord avec les valeurs lues dans le registre Google Sheets.
     *
     * Les nouvelles offres sont créées. Pour une offre déjà présente, seuls les champs modifiés
     * dans le registre depuis la synchronisation précédente sont reportés : ce qui a été changé
     * dans le tableau de bord n'est donc pas écrasé tant que le registre ne change pas ce champ.
     *
     * @param  list<list<string|null>>  $values  première ligne = en-têtes
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function sync(array $values): array
    {
        $header = array_shift($values);

        if (! $header) {
            throw new RuntimeException('Le registre est vide.');
        }

        return $this->importRows($this->mapHeader($header), $values, sync: true);
    }

    /**
     * @param  array<int, string>  $header
     * @return array<int, string> index de colonne → attribut
     */
    private function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $index => $label) {
            $key = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $label)));
            if (isset(self::COLUMNS[$key])) {
                $map[$index] = self::COLUMNS[$key];
            }
        }

        if (! in_array('company', $map, true) || ! in_array('title', $map, true)) {
            throw new RuntimeException('Colonnes « Entreprise » et « Poste » introuvables : est-ce bien un export du registre ?');
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $map
     * @param  list<array<int, string|null>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    private function importRows(array $map, array $rows, bool $sync = false): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        DB::transaction(function () use ($map, $rows, $sync, &$result) {
            foreach ($rows as $number => $row) {
                $line = $number + 2; // ligne 1 = en-têtes
                $data = [];

                foreach ($map as $index => $attribute) {
                    $value = trim((string) ($row[$index] ?? ''));
                    $data[$attribute] = $value === '' ? null : $value;
                }

                if (blank($data['company'] ?? null) && blank($data['title'] ?? null)) {
                    continue; // ligne vide
                }

                if (blank($data['company'] ?? null) || blank($data['title'] ?? null)) {
                    $result['errors'][] = "Ligne {$line} : entreprise ou poste manquant.";

                    continue;
                }

                try {
                    $data = $this->normalize($data);
                } catch (RuntimeException $e) {
                    $result['errors'][] = "Ligne {$line} : ".$e->getMessage();

                    continue;
                }

                if ($existing = $this->find($data)) {
                    if ($sync && $this->applyChanges($existing, $data)) {
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                    }

                    continue;
                }

                $opportunity = new Opportunity($data);
                $opportunity->registry_snapshot = $this->snapshot($data);
                $opportunity->save();
                $opportunity->events()->create([
                    'type' => 'import',
                    'body' => $sync ? 'Ajoutée par synchronisation du registre Google Sheets' : 'Importée depuis le registre Google Sheets',
                    'happened_at' => now(),
                ]);
                $result['created']++;
            }
        });

        return $result;
    }

    /**
     * @param  array<string, string|null>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (self::DATES as $attribute) {
            if (filled($data[$attribute] ?? null)) {
                $data[$attribute] = $this->parseDate($data[$attribute]);
            }
        }

        foreach (self::CHOICES as $attribute => $options) {
            if (filled($data[$attribute] ?? null)) {
                $data[$attribute] = Catalog::keyFor($options, $data[$attribute]); // valeur inconnue ignorée
            }
        }

        foreach (['salary_min', 'salary_max'] as $attribute) {
            if (filled($data[$attribute] ?? null)) {
                $digits = preg_replace('/\D/', '', $data[$attribute]);
                $data[$attribute] = $digits === '' ? null : (int) $digits;
            }
        }

        if (filled($data['ats_score'] ?? null)) {
            $digits = preg_replace('/[^\d]/', '', explode(',', str_replace('.', ',', $data['ats_score']))[0]);
            $data['ats_score'] = $digits === '' ? null : min(100, (int) $digits);
        }

        if (filled($data['relevance'] ?? null)) {
            $relevance = (int) $data['relevance'];
            $data['relevance'] = $relevance >= 1 && $relevance <= 3 ? $relevance : null;
        }

        if (array_key_exists('status', $data)) {
            $status = $data['status'] === null ? OpportunityStatus::ToEvaluate : OpportunityStatus::fromLabel($data['status']);

            if (! $status) {
                throw new RuntimeException("statut « {$data['status']} » inconnu.");
            }

            $data['status'] = $status;
        }

        if (filled($data['url'] ?? null) && ! preg_match('#^https?://#i', $data['url'])) {
            $data['url'] = null; // n'accepter que des liens web
        }

        return array_filter($data, fn ($value) => $value !== null);
    }

    private function parseDate(string $value): string
    {
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd.m.y'] as $format) {
            $date = Carbon::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        throw new RuntimeException("date « {$value} » illisible.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function find(array $data): ?Opportunity
    {
        if (filled($data['url'] ?? null) && $found = Opportunity::query()->where('url', $data['url'])->first()) {
            return $found;
        }

        return Opportunity::query()
            ->whereRaw('lower(company) = ?', [mb_strtolower($data['company'])])
            ->whereRaw('lower(title) = ?', [mb_strtolower($data['title'])])
            ->first();
    }

    /**
     * Reporte sur l'offre les champs modifiés dans le registre.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyChanges(Opportunity $opportunity, array $data): bool
    {
        $snapshot = $this->snapshot($data);
        $previous = $opportunity->registry_snapshot;

        foreach ($snapshot as $attribute => $value) {
            if ($previous !== null) {
                // Seulement ce qui a changé dans le registre depuis la dernière synchronisation.
                $changed = ($previous[$attribute] ?? null) !== $value;
            } elseif ($attribute === 'status') {
                // Première synchronisation d'une offre importée à la main : on ne revient jamais
                // sur un suivi déjà avancé dans le tableau de bord.
                $changed = in_array($opportunity->status, [OpportunityStatus::ToEvaluate, OpportunityStatus::ToApply], true);
            } else {
                $changed = blank($opportunity->getAttribute($attribute));
            }

            if ($changed) {
                $opportunity->setAttribute($attribute, $data[$attribute]);
            }
        }

        $opportunity->registry_snapshot = $snapshot;
        $updated = collect($opportunity->getDirty())->except('registry_snapshot')->isNotEmpty();
        $opportunity->save();

        return $updated;
    }

    /**
     * Valeurs du registre sous forme comparable (chaînes), mémorisées sur l'offre.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function snapshot(array $data): array
    {
        return array_map(fn ($value) => $value instanceof OpportunityStatus ? $value->value : (string) $value, $data);
    }
}
