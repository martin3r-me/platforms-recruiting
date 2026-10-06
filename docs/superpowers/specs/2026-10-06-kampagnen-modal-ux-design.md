# Kampagnen-Modal der Statistik — UX-Paket (Design)

Stand: 06.10.2026 · Status: umgesetzt auf feat/kampagne-modal-ux · Modul: platforms-recruiting
Betrifft: das Senden-Modal der Kampagnen „Neue Termine“ (Kachel „Ohne Termin“) und „Schulung voll“ (Pille an
ausgebuchten Terminen). Beide teilen Rumpf und Fuß des Drill-Modals in `statistics/index.blade.php`.

## 1. Problem (Kunde, 05.10.)

HR sah im Modal „Bewerbung · A“, „Schulung buchen · B“ und Dropdowns mit `statistik_p1 (de)`. Niemand wusste,
was die Kampagne tut, wer welche Nachricht bekommt und was in der Nachricht steht. Die Kopfzeile hieß
„Ohne Termin — Ohne Termin (43)“.

## 2. Entscheidungen

| Thema | Entscheidung |
|---|---|
| Zwei Gruppen, zwei Worte | „Angaben ergänzen“ (Formular-Link) und „Termine ansehen“ (Terminauswahl-Link). Eine Quelle: `CampaignSegment::empfaengtLabel()`. Keine Buchstaben A/B mehr in der Oberfläche. |
| Vorlagen-Text statt Name | Vorschau mit Beispielname „Anna“, Button-Text darunter, Vorlagen-Name nur als Tooltip. `CampaignTemplatePreview::render()` baut die Parameter wie der Sender (`HoldingTemplateComponents::build`) und rendert mit `WhatsAppTemplateRenderer` — derselbe Text, der später im Chat steht. |
| Dropdown | bleibt, aber zugeklappt hinter „Vorlage ändern“; offen nur, wenn keine Vorlage gewählt ist (rote Karte). |
| Bestätigung | `wire:confirm` mit `Index::campaignConfirmText()`: Anzahl, je Gruppe Anzahl und Link, „keine automatischen Erinnerungen“. |
| Erklärblock | oben im Modal, je Modus ein Absatz; Hinweis auf den fehlenden Re-Arm. |
| Zeilen-Chip | „bekommt: Angaben ergänzen“ (blau) / „bekommt: Termine ansehen“ (grün); gesperrte Zeilen „keine Nachricht“ durchgestrichen, Grund als Badge daneben. |
| Kopfzeile | Spaltenname nur anhängen, wenn er vom Präfix abweicht (`drill()`). |
| Fehlertexte | „Für N Personen fehlt die Nachricht „…“ — Vorlage wählen.“ |

Nicht angefasst: das Einstellungs-Modal (Vorbelegung der Vorlagen), der Sammelversand „ohne Einsatz“ im
Schulungs-Detail (eigene Karte, bei Bedarf gleiches Muster), der Speicher-Bug der Select-Felder (Core).

## 3. Bausteine

- `Support/CampaignTemplatePreview` (rein) + `tests/Unit/CampaignTemplatePreviewTest`.
- `CampaignSegment::empfaengtLabel()` + Test.
- `Statistics\Index`: `campaignTemplatePreviews()` (Computed, gleiche Query-Basis wie `campaignTemplates()`),
  `campaignConfirmText()` (rein, Test), Kopfzeile ohne Doppelung, Fehlertexte.
- `statistics/index.blade.php`: Erklärblock, Zähler in Worten, Zeilen-Chips, Nachricht-Karten, Button mit
  Bestätigung. `tools/blade-check.php` grün, Anführungszeichen-Hygiene (StatisticsPageStructureTest) grün.

## 4. Auslieferung

ff auf main, meingedeck-Bump, `view:clear`. Keine Migration, kein `queue:restart` (nur View + Komponente).
Sichttest: Kachel „Ohne Termin“ → Modal; Pille an einem vollen Termin → Modal; Vorlage ändern; Bestätigung lesen;
Testversand an eine HR-Nummer.
