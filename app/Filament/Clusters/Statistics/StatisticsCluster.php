<?php

namespace App\Filament\Clusters\Statistics;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class StatisticsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Statistiques';

    protected static string|UnitEnum|null $navigationGroup = 'Analyse';

    protected static ?string $clusterBreadcrumb = 'Statistiques';

    protected static ?string $slug = 'statistiques';

    protected static ?int $navigationSort = 5;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
