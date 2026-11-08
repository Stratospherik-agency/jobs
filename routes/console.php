<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

// Fichiers téléversés mais jamais rattachés à une candidature (formulaire abandonné).
Schedule::call(function () {
    $disk = Storage::disk('local');
    foreach (['candidatures/tmp', 'livewire-tmp'] as $directory) {
        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
})->daily()->name('nettoyage-televersements');

// Registre Google Sheets → tableau de bord.
Schedule::command('registre:synchroniser')->everyFifteenMinutes()->withoutOverlapping();
