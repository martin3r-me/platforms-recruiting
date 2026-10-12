{{-- Folien-Look der Kundenanleitungen (RheinGedeck-HCM-Folien). Eigenes CSS statt
     Tailwind-Arbitrary-Klassen: der Tailwind-Build scannt diese Views nicht sicher. --}}
<style>
    .rg-folie { position: relative; overflow: hidden; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
        padding: 22px 26px 44px 38px; box-shadow: 0 1px 2px rgba(0,0,0,.05); color: #111111;
        font-family: Calibri, "Segoe UI", Arial, sans-serif; scroll-margin-top: 16px; }
    .rg-folie__balken { position: absolute; inset: 0 auto 0 0; width: 8px; background: #1273DE; }
    .rg-folie__kicker { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #1273DE; }
    .rg-folie__titel { margin-top: 4px; font-size: 26px; line-height: 1.2; font-weight: 700; text-wrap: balance; }
    .rg-folie__linie { height: 1px; background: #B8BCC4; margin: 12px 0 18px; }
    .rg-folie__einleitung { color: #5F6368; font-size: 15px; margin-bottom: 14px; }
    .rg-folie__zweispaltig { display: grid; gap: 24px; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); align-items: start; }
    .rg-folie__schritte { font-size: 16px; line-height: 1.5; }
    .rg-folie__schritte ol { list-style: decimal; padding-left: 1.4em; }
    .rg-folie__schritte ul { list-style: disc; padding-left: 1.4em; }
    .rg-folie__schritte li { margin-bottom: 8px; }
    .rg-folie__schritte--breit ol, .rg-folie__schritte--breit ul { columns: 2; column-gap: 32px; }
    .rg-folie__schritte--breit li { break-inside: avoid; }
    .rg-folie__merksatz { margin-top: 14px; font-size: 15px; font-weight: 700; color: #1273DE; }
    .rg-folie__bild img { width: 100%; height: auto; border: 1px solid #e5e7eb; border-radius: 4px; }
    .rg-folie__platzhalter { aspect-ratio: 16 / 10; display: flex; align-items: center; justify-content: center; text-align: center;
        background: #F1F3F5; border: 1px dashed #B8BCC4; border-radius: 4px; color: #5F6368; font-size: 14px; padding: 16px; }
    .rg-folie__tabelle { overflow-x: auto; margin-top: 4px; }
    .rg-folie__tabelle table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .rg-folie__tabelle th { text-align: left; color: #5F6368; font-weight: 600; padding: 6px 10px; border-bottom: 1px solid #B8BCC4; }
    .rg-folie__tabelle td { padding: 8px 10px; vertical-align: top; }
    .rg-folie__tabelle tr:nth-child(even) td { background: #F1F3F5; }
    .rg-folie__tabelle td:first-child { font-weight: 700; color: #1273DE; width: 28%; }
    .rg-folie__fuss { position: absolute; left: 38px; right: 26px; bottom: 14px; display: flex; justify-content: space-between;
        font-size: 12px; color: #5F6368; font-variant-numeric: tabular-nums; }
    .rg-folie strong { font-weight: 700; }
    @media (max-width: 1023px) {
        .rg-folie__zweispaltig { grid-template-columns: minmax(0, 1fr); }
        .rg-folie__schritte--breit ol, .rg-folie__schritte--breit ul { columns: 1; }
        .rg-folie { padding: 18px 16px 44px 26px; }
        .rg-folie__fuss { left: 26px; right: 16px; }
    }
</style>
