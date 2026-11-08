<?php

namespace App\Filament\Pages;

use App\Services\RegistrySync;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Tableau de bord';

    public function mount(): void
    {
        // Le registre Google Sheets est relu si la dernière synchronisation date de plus de 5 minutes.
        app(RegistrySync::class)->syncIfStale();
    }
}
