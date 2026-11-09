<?php

use App\Models\Prospect;
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

// Candidatures spontanées : sans réponse 3 semaines après la relance, refus à recontacter après 6 mois.
Schedule::call(fn () => Prospect::housekeeping())->dailyAt('06:00')->name('candidatures-spontanees');

// Automatisations : préparation d'office, relances (brouillons), réponses, veille, résumé du matin, bilan.
// Chaque tâche vérifie elle-même son heure et son intervalle (Réglages > Notifications).
Schedule::command('automatisations:executer')->everyFiveMinutes()->withoutOverlapping(30);
