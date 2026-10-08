<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.5; color: #111827; margin: 2cm 2.5cm; }
        h1 { font-size: 16pt; margin-bottom: 4px; }
        .sub { color: #6b7280; font-size: 10pt; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; font-size: 10pt; vertical-align: top; }
        th { width: 34%; background: #f3f4f6; font-weight: 600; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; word-break: break-all; }
        .signature { margin-top: 10px; page-break-inside: avoid; }
        .signature img { max-height: 110px; display: block; margin: 8px 0; border-bottom: 1px solid #111827; }
        .stamp img { max-width: 160px; display: block; margin-top: 14px; }
        .hint { color: #6b7280; font-size: 9pt; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Nachweis der Unterschrift</h1>
    <div class="sub">{{ $daten['titel'] }} · erstellt {{ $erstellt }}</div>

    <table>
        <tr><th>Mitarbeiter</th><td>{{ $daten['name'] }}</td></tr>
        <tr><th>Personalnummer</th><td>{{ $daten['personalnummer'] }}</td></tr>
        <tr><th>Firma</th><td>{{ $daten['firma'] }}</td></tr>
        <tr><th>Dokument</th><td>{{ $daten['titel'] }}<br><span class="mono">{{ $daten['dateiname'] }}</span></td></tr>
        <tr><th>Prüfsumme (SHA-256)</th><td class="mono">{{ $daten['pruefsumme'] }}</td></tr>
        <tr><th>Bereitgestellt am</th><td>{{ $daten['bereitgestellt'] }}</td></tr>
        <tr><th>Erstes Öffnen</th><td>{{ $daten['geoeffnet'] }}</td></tr>
        <tr><th>Gelesen bestätigt am</th><td>{{ $daten['bestaetigt'] }}</td></tr>
        <tr><th>Unterschrieben am</th><td>{{ $daten['unterschrieben'] }}@if ($daten['abstand_sekunden'] !== null) <span class="sub">({{ $daten['abstand_sekunden'] }} Sekunden nach dem Öffnen)</span>@endif</td></tr>
    </table>

    @if ($daten['unterschrift'])
        <div class="signature">
            <strong>Unterschrift</strong>
            <img src="{{ $daten['unterschrift'] }}" alt="Unterschrift">
            <div>{{ $daten['name'] }}</div>
        </div>
    @endif

    @if ($stempel)
        <div class="stamp"><img src="{{ $stempel }}" alt="Firmenstempel"></div>
    @endif

    <p class="hint">Die Prüfsumme bezieht sich auf die Datei, die dem Mitarbeiter im Portal vorlag. Sie wurde beim Bereitstellen eingefroren und bei der Unterschrift erneut geprüft.</p>
</body>
</html>
