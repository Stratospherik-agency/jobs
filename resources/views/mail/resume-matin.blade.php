<x-mail::message>
# Bonjour,

Voici ton point du jour : {{ $summary }}.

@php
    $sections = [
        'validate' => 'À valider',
        'followups' => 'Relances du jour',
        'interviews' => 'Entretiens',
        'offers' => 'Nouvelles offres',
        'signals' => 'Veille des entreprises cibles',
    ];
@endphp
@foreach ($sections as $key => $heading)
@if (count($data[$key]))
## {{ $heading }}

@foreach ($data[$key] as $item)
- **[{{ $item['title'] }}]({{ $item['url'] }})**@if (filled($item['detail'])) — {{ $item['detail'] }}@endif

@endforeach

@endif
@endforeach
<x-mail::button :url="$dashboard">
Ouvrir le tableau de bord
</x-mail::button>

Rien ne part sans ta validation : les candidatures et les relances attendent ton feu vert.

Ton tableau de bord jobs
</x-mail::message>
