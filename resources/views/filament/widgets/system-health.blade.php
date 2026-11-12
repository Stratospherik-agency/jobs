<x-filament-widgets::widget>
    @if (empty($failing))
        <x-filament::section compact>
            <div class="flex items-center gap-2 text-sm">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600" />
                <span>Tout fonctionne : tâche planifiée, e-mails, compte Google, registre, alertes Gmail et recherche quotidienne.</span>
            </div>
            <details class="mt-2 text-xs text-gray-500">
                <summary class="cursor-pointer">Détail</summary>
                <ul class="mt-1 space-y-1">
                    @foreach ($checks as $check)
                        <li><strong>{{ $check['label'] }}</strong> : {{ $check['detail'] }}</li>
                    @endforeach
                </ul>
            </details>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" heading="État du système : {{ count($failing) }} point(s) à vérifier">
            <ul class="space-y-3 text-sm">
                @foreach ($checks as $check)
                    <li class="flex gap-2">
                        <x-filament::icon :icon="$check['ok'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'" :class="\Illuminate\Support\Arr::toCssClasses(['h-5 w-5 shrink-0', 'text-success-600' => $check['ok'], 'text-danger-600' => ! $check['ok']])" />
                        <div>
                            <strong>{{ $check['label'] }}</strong> : {{ $check['detail'] }}
                            @unless ($check['ok'])
                                <div class="text-gray-600 dark:text-gray-400">{{ $check['fix'] }}</div>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
