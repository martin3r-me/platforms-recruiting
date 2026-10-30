# Jobs anbieten: Zeitfenster je Anlass + Reihenfolge der ersten Welle (Markus 29./30.10.2026)

Grundlage: unsere Mail vom 29.10. (Punkte 1 und 4) und Markus' Antwort vom 30.10. („genau, eine Anpassung von Hand soll möglich sein, wenn sie dieses möchten“). Die Monatsende-Frage hat er nicht beantwortet; die Regel in §1 ist unsere Entscheidung und wird ihm mitgeteilt.

## 1. Zeitfenster je Anlass

Der Zeitraum in Schritt 1 wird aus dem Anlass vorbelegt. `DispoJobZeitfenster::fuer(string $anlass, DateTimeInterface $heute): ?array{von: string, bis: string}` (rein, Y-m-d):

| Anlass | von | bis |
|---|---|---|
| `dringend` | morgen | morgen |
| `naechste_woche` | nächster Montag (streng nach heute) | dessen Sonntag |
| `vier_wochen` | nächster Montag | Ende des Monats, in den Montag + 27 Tage fällt |
| unbekannt / leer | `null` → Assistent behält morgen bis +28 Tage |

Beispiele (heute Fr 30.10.2026): Nächste Woche = Mo 02.11. bis So 08.11.; Vier Wochen = Mo 02.11. bis Mo 30.11. Heute Mo 23.11.: Vier Wochen = Mo 30.11. bis Do 31.12. (nie kürzer als vier Wochen).

Assistent (`Create`):
- Die Anlass-Pillen stehen zusätzlich in Schritt 1 **über** dem Zeitraum (dieselbe Liste wie in Schritt 3, derselbe `anlassWaehlen`). Darunter der Hinweis „Vorgeschlagen für „Nächste Woche“: Mo 02.11. bis So 08.11. Du kannst den Zeitraum ändern.“ (`DispoJobZeitfenster::hinweis`).
- `mount()`: ohne Veranstaltung → von/bis aus dem vorbelegten Anlass. Mit Veranstaltung (`?event=`) → wie bisher die VA-Tage, der Anlass belegt nichts vor.
- `anlassWaehlen()`: belegt von/bis nur neu, wenn Schritt 1 aktiv ist, der Einstieg nicht über eine VA kam und der Nutzer von/bis noch nicht angefasst hat (`zeitraumAngefasst`, gesetzt in `updated()` für `von`/`bis`). Danach `hakenAngleichen()` wie bei einer Handänderung.
- Von/bis bleiben freie Felder (Markus: Anpassung von Hand). Prüfung unverändert (`DispoJobCreateState::zeitraumProblem`).

## 2. Reihenfolge der ersten Welle

Bisher (Spec 2026-10-27 §2.3): wenigste Einsätze im laufenden Kalendermonat zuerst, dann Anker-ID. Neu:

- **Zählfenster** `DispoEinsatzZaehler::zaehlfenster(string $heute, list<string> $tage): array{von, bis}` = heute − 30 Tage bis zum letzten angebotenen Tag (mindestens heute). `DispoEinsatzZaehler::imZeitraum($assignments, $anchorByEmployee, $von, $bis)` zählt Einsätze (gleiche Zeilenregel wie `proMonat`) in diesem Fenster je Person.
- **Gleichstand**: wer am längsten keine Anfrage bekommen hat, zuerst. Maß: `max(rec_dispo_job_offers.created_at)` je `anchor_employee_id` über alle Anfragen; nie angeschrieben steht ganz vorn. Danach Anker-ID.
- `DispoJobKandidaten::laden()` liefert zusätzlich `reihenfolge: array{einsaetze: array<int,int>, letzte_anfrage: array<int,string>}` (nur Anker mit Werten) und lädt die Einbuchungen für Monat ∪ Zählfenster ∪ Tage.
- `DispoJobRecipientPlanner::plan(..., ?array $reihenfolge = null)`: `null` = altes Verhalten (Monat, Anker). Sonst Sortierschlüssel `[einsaetze im Fenster (fehlend = 0), letzte_anfrage (fehlend = ''), anker]`. Aufrufer: `SendDispoJobWave`, `DispoJobZahlen::ausDaten` (Vorschau = Versand).
- **Gruppen-Bänder** (0 / weniger als 5 / … laut Kunden-PDF) bleiben auf dem Kalendermonat. Nur die Reihenfolge wechselt.

## 3. Nicht in Scope
Zeitfenster an bestehenden Anfragen, Anzeige der Zählwerte in der Oberfläche, Änderung der Bänder.

## 4. Tests
Unit: `DispoJobZeitfensterTest` (vier Anlässe, Sonntag, Monatswechsel 30.11./31.12., Hinweistext), `DispoEinsatzZaehlerTest::imZeitraum` + `zaehlfenster`, `DispoJobRecipientPlannerTest` (Fenster schlägt Monat, Gleichstand nach letzter Anfrage, nie angeschrieben zuerst, `null` = alt). Integration: `DispoJobKandidatenTest` (Zählfenster, letzte Anfrage), `DispoJobCreateKomponenteTest` (Anlass belegt den Zeitraum in Schritt 1, nicht nach Handänderung, nicht in Schritt 3).

## 5. Deploy
Keine Migration. `view:clear`. Kein queue:restart nötig (Reihenfolge greift in neuen Wellen; laufende Worker nehmen den alten Sortierschlüssel bis zum nächsten Neustart — unkritisch).
