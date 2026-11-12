<?php

namespace App\Filament\Widgets;

use App\Services\Automation\HealthCheck;
use Filament\Widgets\Widget;

/**
 * État du système en haut du tableau de bord : une ligne verte si tout fonctionne,
 * sinon le détail de chaque maillon en panne et la marche à suivre.
 */
class SystemHealth extends Widget
{
    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.system-health';

    protected function getViewData(): array
    {
        $checks = HealthCheck::checks();

        return [
            'checks' => $checks,
            'failing' => array_filter($checks, fn ($c) => ! $c['ok']),
        ];
    }
}
