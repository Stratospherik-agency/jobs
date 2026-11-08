<?php

namespace App\Console\Commands;

use App\Services\RegistryImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportRegistry extends Command
{
    protected $signature = 'registre:importer {fichier : Export CSV du registre Google Sheets}';

    protected $description = 'Importe les offres du registre Google Sheets (export CSV)';

    public function handle(RegistryImporter $importer): int
    {
        try {
            $result = $importer->importFile($this->argument('fichier'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['created']} offre(s) importée(s), {$result['skipped']} déjà présente(s).");

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
