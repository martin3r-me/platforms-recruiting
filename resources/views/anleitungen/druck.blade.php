<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $anleitung->titel }} — Anleitung</title>
    @include('recruiting::anleitungen._stil')
    <style>
        @page { size: A4 landscape; margin: 0; }
        html, body { margin: 0; background: #e5e7eb; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .rg-druck { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 16px; }
        .rg-druck .rg-folie { width: 297mm; height: 210mm; box-sizing: border-box; padding: 14mm 14mm 18mm 20mm; border-radius: 0; }
        .rg-druck .rg-folie__balken { width: 4.3mm; }
        .rg-druck .rg-folie__titel { font-size: 28pt; }
        .rg-druck .rg-folie__schritte { font-size: 15pt; }
        .rg-druck .rg-folie__fuss { left: 20mm; right: 14mm; bottom: 8mm; font-size: 10.5pt; }
        .rg-druck .rg-folie__zweispaltig { grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); }
        .rg-druck .rg-folie__bild img { max-height: 140mm; width: auto; max-width: 100%; }
        @media print {
            html, body { background: #fff; }
            .rg-druck { display: block; padding: 0; }
            .rg-druck .rg-folie { border: none; box-shadow: none; break-after: page; page-break-after: always; }
            .rg-druck .rg-folie:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="rg-druck">
        @foreach ($anleitung->folien as $folie)
            @include('recruiting::anleitungen._folie', ['anleitung' => $anleitung, 'folie' => $folie])
        @endforeach
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
