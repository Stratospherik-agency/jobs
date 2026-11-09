{{-- Barre d'onglets en bas de l'écran sur téléphone (masquée sur grand écran). --}}
@php
    use App\Filament\Clusters\Statistics\StatisticsCluster;
    use App\Filament\Pages\Dashboard;
    use App\Filament\Pages\Settings;
    use App\Filament\Resources\Opportunities\OpportunityResource;
    use App\Filament\Resources\Prospects\ProspectResource;
    use Filament\Support\Icons\Heroicon;

    $current = url()->current();
    $tabs = [
        ['label' => 'Accueil', 'url' => Dashboard::getUrl(), 'icon' => Heroicon::OutlinedHome, 'exact' => true],
        ['label' => 'Offres', 'url' => OpportunityResource::getUrl(), 'icon' => Heroicon::OutlinedBriefcase],
        ['label' => 'Spontanées', 'url' => ProspectResource::getUrl(), 'icon' => Heroicon::OutlinedBuildingOffice2],
        ['label' => 'Stats', 'url' => StatisticsCluster::getUrl(), 'icon' => Heroicon::OutlinedChartBar, 'prefix' => url('statistiques')],
        ['label' => 'Réglages', 'url' => Settings::getUrl(), 'icon' => Heroicon::OutlinedCog6Tooth],
    ];
@endphp
@if (filament()->auth()->check())
    <nav class="jobs-tabbar" aria-label="Navigation principale">
        @foreach ($tabs as $tab)
            @php
                $prefix = rtrim($tab['prefix'] ?? $tab['url'], '/');
                $active = ($tab['exact'] ?? false)
                    ? rtrim($current, '/') === rtrim($tab['url'], '/')
                    : ($current === $prefix || str_starts_with($current, $prefix.'/'));
            @endphp
            <a href="{{ $tab['url'] }}" @class(['jobs-tab', 'jobs-on' => $active]) @if ($active) aria-current="page" @endif>
                <x-filament::icon :icon="$tab['icon']" class="jobs-tab-icon" />
                <span>{{ $tab['label'] }}</span>
            </a>
        @endforeach
    </nav>
@endif
