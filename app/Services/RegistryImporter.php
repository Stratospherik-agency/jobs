<?php

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
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
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    private function importRows(array $map, array $rows): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];

        DB::transaction(function () use ($map, $rows, &$result) {
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

                if ($this->exists($data)) {
                    $result['skipped']++;

                    continue;
                }

                $opportunity = Opportunity::query()->create($data);
                $opportunity->events()->create([
                    'type' => 'import',
                    'body' => 'Importée depuis le registre Google Sheets',
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
    private function exists(array $data): bool
    {
        if (filled($data['url'] ?? null) && Opportunity::query()->where('url', $data['url'])->exists()) {
            return true;
        }

        return Opportunity::query()
            ->whereRaw('lower(company) = ?', [mb_strtolower($data['company'])])
            ->whereRaw('lower(title) = ?', [mb_strtolower($data['title'])])
            ->exists();
    }
}
