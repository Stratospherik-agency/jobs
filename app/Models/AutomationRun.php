<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Dernière exécution d'une automatisation (résumé du matin, relances, veille…).
 */
class AutomationRun extends Model
{
    protected $fillable = ['task', 'ran_at', 'summary'];

    protected function casts(): array
    {
        return ['ran_at' => 'datetime'];
    }

    public static function lastRun(string $task): ?Carbon
    {
        return static::query()->where('task', $task)->first()?->ran_at;
    }

    /**
     * Tâche planifiée à heure fixe : due si l'heure est passée et si le dernier passage
     * date d'au moins « tous les N jours » (et, au besoin, le bon jour de la semaine).
     */
    public static function dueAt(string $task, string $time, int $everyDays = 1, ?int $weekday = null): bool
    {
        $now = now();
        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => 0]);

        if ($now->lt($now->copy()->setTime($hour, $minute))) {
            return false;
        }

        if ($weekday !== null && $now->dayOfWeekIso !== $weekday) {
            return false;
        }

        $last = self::lastRun($task);

        return ! $last || $last->copy()->startOfDay()->addDays(max(1, $everyDays))->lte($now->copy()->startOfDay());
    }

    /** Tâche répétée : due si le dernier passage date d'au moins N minutes. */
    public static function dueEvery(string $task, int $minutes): bool
    {
        $last = self::lastRun($task);

        return ! $last || $last->lte(now()->subMinutes($minutes));
    }

    public static function mark(string $task, ?string $summary = null): void
    {
        static::query()->updateOrCreate(['task' => $task], ['ran_at' => now(), 'summary' => $summary]);
    }
}
