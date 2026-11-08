<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Recherches d'emploi – {{ $month }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 10.5pt; color: #111; margin: 24px; }
        h1 { font-size: 15pt; margin: 0 0 4px; }
        p.meta { margin: 0 0 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        .actions { margin-bottom: 16px; }
        .actions button { font: inherit; padding: 6px 14px; cursor: pointer; }
        .empty { padding: 24px; text-align: center; color: #555; }
        footer { margin-top: 18px; display: flex; justify-content: space-between; color: #444; }
        @media print { .actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Imprimer ou enregistrer en PDF</button></div>
    <h1>Preuves des recherches personnelles en vue de trouver un emploi</h1>
    <p class="meta">Période : {{ $month }} · {{ $name }} · {{ count($rows) }} démarche(s)</p>

    <table>
        <thead>
            <tr>
                <th>N°</th>
                @foreach ($columns as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    @foreach (array_keys($columns) as $key)
                        <td>{{ $row[$key] }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($columns) + 1 }}">Aucune candidature envoyée ce mois-ci.</td></tr>
            @endforelse
        </tbody>
    </table>

    <footer>
        <span>Établi le {{ now()->format('d.m.Y') }}</span>
        <span>Signature : ______________________</span>
    </footer>
</body>
</html>
