<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" heading="Correspondance Gmail">
        <x-slot name="afterHeader">
            @if ($state === 'ok')
                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="reload">Actualiser</x-filament::button>
            @endif
        </x-slot>

        @if ($state === 'disconnected')
            <p class="text-sm text-gray-500">Connecte ton compte Google (menu « Compte Google ») pour voir ici les échanges avec cette entreprise.</p>
        @elseif ($state === 'empty')
            <p class="text-sm text-gray-500">Renseigne l'adresse du destinataire dans la candidature pour retrouver les échanges avec cette entreprise.</p>
        @elseif ($state === 'error')
            <p class="text-sm text-danger-600">{{ $error }}</p>
        @elseif (empty($messages))
            <p class="text-sm text-gray-500">Aucun échange trouvé pour l'instant.</p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($messages as $message)
                    <li class="py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <a href="{{ $message['url'] }}" target="_blank" rel="noopener" class="font-medium hover:underline">{{ $message['subject'] }}</a>
                            <span class="text-xs text-gray-500">{{ $message['date']?->format('d.m.Y H:i') }}</span>
                        </div>
                        <div class="text-xs text-gray-500">De : {{ $message['from'] }} · À : {{ $message['to'] }}</div>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $message['snippet'] }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
