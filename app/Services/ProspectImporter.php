<?php

namespace App\Services;

use App\Enums\ProspectStatus;
use App\Models\Prospect;
use App\Support\Catalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synchronise l'onglet « Spontanées » du registre Google Sheets avec les entreprises cibles.
 * Même règle que pour les offres : seuls les champs modifiés dans le registre depuis la
 * synchronisation précédente sont reportés, le suivi fait dans le tableau de bord est conservé.
 */
class ProspectImporter
{
    public const COLUMNS = [
        "date d'ajout" => 'added_at',
        'entreprise' => 'company',
        'famille' => 'family',
        'secteur' => 'sector',
        'localité' => 'locality',
        'site web' => 'website',
        'employés' => 'employees',
        'signal' => 'signal',
        'date du signal' => 'signal_date',
        'source du signal' => 'signal_source',
        'raisons' => 'reasons',
        "pistes d'apport" => 'contributions',
        'contact' => 'contact_name',
        'fonction' => 'contact_role',
        'e-mail' => 'contact_email',
        'linkedin' => 'contact_linkedin',
        'canal' => 'channel',
        'note (1-3)' => 'relevance',
        'langue' => 'language',
        'statut' => 'status',
        "date d'envoi" => 'sent_at',
        'relance prévue' => 'follow_up_at',
        'date de réponse' => 'response_at',
        'type de réponse' => 'response_type',
        'recontact' => 'recontact_at',
        'documents' => 'documents',
        'notes' => 'notes',
    ];

    private const CHOICES = [
        'family' => Catalog::PROSPECT_FAMILIES,
        'channel' => Catalog::PROSPECT_CHANNELS,
        'response_type' => Catalog::PROSPECT_RESPONSES,
        'language' => Catalog::LANGUAGES,
    ];

    private const DATES = ['added_at', 'signal_date', 'sent_at', 'follow_up_at', 'response_at', 'recontact_at'];

    /**
     * @param  list<list<string|null>>  $values  première ligne = en-têtes
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function sync(array $values): array
    {
        $header = array_shift($values);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        if (! $header) {
            return $result; // onglet vide
        }

        $map = [];
        foreach ($header as $index => $label) {
            $key = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $label)));
            if (isset(self::COLUMNS[$key])) {
                $map[$index] = self::COLUMNS[$key];
            }
        }

        if (! in_array('company', $map, true)) {
            throw new RuntimeException('Onglet « Spontanées » : colonne « Entreprise » introuvable.');
        }

        DB::transaction(function () use ($map, $values, &$result) {
            foreach ($values as $number => $row) {
                $line = $number + 2;
                $data = [];
                foreach ($map as $index => $attribute) {
                    $value = trim((string) ($row[$index] ?? ''));
                    $data[$attribute] = $value === '' ? null : $value;
                }

                if (blank($data['company'] ?? null)) {
                    continue;
                }

                try {
                    $data = $this->normalize($data);
                } catch (RuntimeException $e) {
                    $result['errors'][] = "Spontanées, ligne {$line} : ".$e->getMessage();

                    continue;
                }

                $existing = Prospect::query()->whereRaw('lower(company) = ?', [mb_strtolower($data['company'])])->first();

                if ($existing) {
                    $this->applyChanges($existing, $data) ? $result['updated']++ : $result['skipped']++;

                    continue;
                }

                $prospect = new Prospect($data);
                $prospect->registry_snapshot = $this->snapshot($data);
                $prospect->save();
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
                $data[$attribute] = Catalog::keyFor($options, $data[$attribute]);
            }
        }

        if (filled($data['relevance'] ?? null)) {
            $relevance = (int) $data['relevance'];
            $data['relevance'] = $relevance >= 1 && $relevance <= 3 ? $relevance : null;
        }

        if (array_key_exists('status', $data)) {
            $status = $data['status'] === null ? ProspectStatus::ToQualify : ProspectStatus::fromLabel($data['status']);
            if (! $status) {
                throw new RuntimeException("statut « {$data['status']} » inconnu.");
            }
            $data['status'] = $status;
        }

        foreach (['website', 'contact_linkedin', 'signal_source'] as $attribute) {
            if (filled($data[$attribute] ?? null) && str_contains($data[$attribute], '.') && ! preg_match('#^https?://#i', $data[$attribute]) && ! str_contains($data[$attribute], ' ')) {
                $data[$attribute] = 'https://'.$data[$attribute];
            }
        }

        if (filled($data['contact_email'] ?? null) && ! filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $data['contact_email'] = null;
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

        throw new RuntimeException("date « {$value} » illisible (format attendu AAAA-MM-JJ).");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyChanges(Prospect $prospect, array $data): bool
    {
        $snapshot = $this->snapshot($data);
        $previous = $prospect->registry_snapshot;

        foreach ($snapshot as $attribute => $value) {
            if ($previous !== null) {
                $changed = ($previous[$attribute] ?? null) !== $value;
            } elseif ($attribute === 'status') {
                $changed = $prospect->status === ProspectStatus::ToQualify;
            } else {
                $changed = blank($prospect->getAttribute($attribute));
            }

            if ($changed) {
                $prospect->setAttribute($attribute, $data[$attribute]);
            }
        }

        $prospect->registry_snapshot = $snapshot;
        $updated = collect($prospect->getDirty())->except('registry_snapshot')->isNotEmpty();
        $prospect->save();

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function snapshot(array $data): array
    {
        return array_map(fn ($value) => $value instanceof ProspectStatus ? $value->value : (string) $value, $data);
    }
}
