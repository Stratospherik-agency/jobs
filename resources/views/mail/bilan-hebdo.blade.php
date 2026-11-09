<x-mail::message>
# Bilan de la semaine

Du {{ $report['from'] }} au {{ $report['to'] }} : **{{ $report['week']['sent'] }}** candidature(s) envoyée(s), **{{ $report['week']['responses'] }}** réponse(s), **{{ $report['week']['interviews'] }}** entretien(s).
Depuis le début : {{ $report['total']['sent'] }} envoi(s), taux de réponse {{ $report['total']['rate'] }} %.

@foreach ($report['tables'] as $table)
## {{ $table['title'] }}

<x-mail::table>
| {{ $table['label'] }} | Envois | Réponses | Taux | Entretiens |
|:--|--:|--:|--:|--:|
@foreach ($table['rows'] as $row)
| {{ $row['value'] }} | {{ $row['sent'] }} | {{ $row['responses'] }} | {{ $row['rate'] }} % | {{ $row['interviews'] }} |
@endforeach
</x-mail::table>

@endforeach
@if (count($report['suggestions']))
## Ajustements proposés

@foreach ($report['suggestions'] as $suggestion)
- {{ $suggestion }}
@endforeach
@endif

<x-mail::button :url="$url">
Voir les statistiques
</x-mail::button>

Ton tableau de bord jobs
</x-mail::message>
