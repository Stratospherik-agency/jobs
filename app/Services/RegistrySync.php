<?php

namespace App\Services;

use App\Models\GoogleConnection;
use App\Services\Google\GoogleException;
use App\Services\Google\Sheets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Synchronisation automatique du registre Google Sheets vers le tableau de bord.
 * Lancée par la tâche planifiée, à l'ouverture des pages principales et par le bouton
 * « Synchroniser maintenant ».
 */
class RegistrySync
{
    /** Délai minimal entre deux synchronisations automatiques (minutes). */
    public const STALE_AFTER = 5;

    public function __construct(private RegistryImporter $importer, private Sheets $sheets, private ProspectImporter $prospects) {}

    /**
     * Raison pour laquelle la synchronisation n'est pas possible, ou null si elle l'est.
     */
    public static function unavailableReason(): ?string
    {
        $connection = GoogleConnection::current();

        if (! $connection) {
            return 'Connecte ton compte Google pour activer la synchronisation du registre.';
        }

        if (! $connection->hasScope(Sheets::SCOPE)) {
            return 'Reconnecte ton compte Google (page Compte Google) pour autoriser la lecture du registre.';
        }

        if (blank(config('services.google.registry_spreadsheet_id'))) {
            return 'Aucun registre Google Sheets configuré.';
        }

        return null;
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>, prospects?: array|null}
     *
     * @throws GoogleException|RuntimeException
     */
    public function sync(): array
    {
        if ($reason = self::unavailableReason()) {
            throw new GoogleException($reason);
        }

        $lock = Cache::lock('registry-sync', 120);

        if (! $lock->get()) {
            throw new GoogleException('Une synchronisation est déjà en cours.');
        }

        $connection = GoogleConnection::current();

        try {
            $values = $this->sheets->values(
                config('services.google.registry_spreadsheet_id'),
                config('services.google.registry_range'),
            );
            $result = $this->importer->sync($values);
            $result['prospects'] = $this->syncProspects();

            $connection->forceFill([
                'registry_synced_at' => now(),
                'registry_sync_summary' => self::summary($result),
                'registry_sync_error' => null,
            ])->save();

            return $result;
        } catch (Throwable $e) {
            $connection?->exists && $connection->forceFill([
                'registry_synced_at' => now(),
                'registry_sync_error' => $e->getMessage(),
            ])->save();
            Log::warning('Synchronisation du registre en échec', ['error' => $e->getMessage()]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Onglet « Spontanées » : entreprises cibles des candidatures spontanées.
     * Un onglet absent n'est pas une erreur.
     *
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}|null
     */
    private function syncProspects(): ?array
    {
        try {
            $values = $this->sheets->values(
                config('services.google.registry_spreadsheet_id'),
                config('services.google.prospects_range'),
            );
        } catch (GoogleException $e) {
            if (str_contains($e->getMessage(), 'Unable to parse range')) {
                return null;
            }

            throw $e;
        }

        return $this->prospects->sync($values);
    }

    /**
     * Synchronise si la dernière synchronisation date de plus de quelques minutes.
     * Ne lève jamais d'exception : une erreur est seulement mémorisée.
     */
    public function syncIfStale(): void
    {
        $connection = GoogleConnection::current();

        if (self::unavailableReason() || $connection->registry_synced_at?->gt(now()->subMinutes(self::STALE_AFTER))) {
            return;
        }

        try {
            $this->sync();
        } catch (Throwable) {
            // Déjà mémorisé et journalisé.
        }
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $result
     */
    public static function summary(array $result): string
    {
        $summary = "{$result['created']} ajoutée(s), {$result['updated']} mise(s) à jour, {$result['skipped']} sans changement.";

        $errors = $result['errors'];

        if ($prospects = $result['prospects'] ?? null) {
            $summary .= " Candidatures spontanées : {$prospects['created']} ajoutée(s), {$prospects['updated']} mise(s) à jour.";
            $errors = [...$errors, ...$prospects['errors']];
        }

        if ($errors) {
            $summary .= ' Lignes ignorées : '.implode(' ', array_slice($errors, 0, 5));
        }

        return $summary;
    }
}
