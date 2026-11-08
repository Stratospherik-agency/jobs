<?php

namespace App\Console\Commands;

use App\Services\RegistrySync;
use Illuminate\Console\Command;
use Throwable;

class SyncRegistry extends Command
{
    protected $signature = 'registre:synchroniser';

    protected $description = 'Synchronise le registre Google Sheets avec le tableau de bord';

    public function handle(RegistrySync $sync): int
    {
        if ($reason = RegistrySync::unavailableReason()) {
            $this->warn($reason);

            return self::SUCCESS;
        }

        try {
            $this->info(RegistrySync::summary($sync->sync()));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
