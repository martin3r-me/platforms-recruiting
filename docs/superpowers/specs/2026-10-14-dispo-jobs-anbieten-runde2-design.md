# Jobs anbieten — Runde 2 (Bedienung und Rückmeldungen)

Stand 14.10.2026. Baut auf `2026-10-12-dispo-jobs-anbieten-design.md` (Runde 1, live seit 13.10., main `b12641a9`) auf. Runde 1 bleibt in Datenmodell und Ablauf bestehen; diese Runde macht die Seiten schneller bedienbar und gibt dem Disponenten die Informationen, die er zum Aussuchen braucht.

## 1. Ziel

Acht Verbesserungen aus dem Gespräch vom 13.10. (S. Haustein), alle ohne Rückfrage an Markus baubar:

| Nr | Was | Wo |
|---|---|---|
| A | Drei Schritte statt fünf | Neue Anfrage |
| B | Entscheidungshilfe je Zusage | Anfrageseite |
| C | Fortschritt oben, offene Schichten zuerst | Anfrageseite |
| D | Weniger Klicks beim Eintragen in ZAS | Anfrageseite |
| E | Zähler am Menüpunkt | Sidebar, Übersicht |
| F | Endzeit der Schicht | überall, wo eine Schicht steht |
| G | „Doch nicht“ für den Mitarbeiter | Mitarbeiter-Seite |
| H | Quote und Filter in der Übersicht | Übersicht |

**Nicht in dieser Runde** (warten auf Markus, siehe §13 der Runde-1-Spec): Vorbelegung „gesucht“ aus RG0/RG14; Wellenplan mit automatischer Stoppregel.

## 2. Entscheidungen (13.10., S. Haustein)

- Alle acht Punkte kommen. Reihenfolge der Umsetzung: B, C, D, A, dann E, F, G, H.
- G ist die einzige Abweichung von der PDF („Kein ‚Kann nicht‘-Knopf“ aus §12 Runde 1 bleibt; „Doch nicht“ ist kein Absagen, sondern das Zurücknehmen der eigenen Zusage).
- Keine Vorbelegung von Zahlen, keine Farblogik, die etwas „empfiehlt“. Die Entscheidungshilfe zeigt Fakten, der Disponent entscheidet.
- Bewertungen erscheinen als fünf Einzelwerte, kein Gesamtschnitt (Vorgabe Kunde 02.09. für das Crew-Kärtchen gilt weiter).

## 3. A — Drei Schritte statt fünf

### 3.1 Ablauf

1. **Was suchst du?** — Filiale (Select), Zeitraum (von/bis), darunter die Liste der Veranstaltungen der Filiale im Zeitraum mit Haken. Filiale und Zeitraum sind `wire:model.live`, die Liste filtert sich beim Ändern. Die Haken bleiben erhalten, solange die Veranstaltung noch in der Liste ist; fällt sie heraus, fällt auch der Haken.
2. **Schichten** — unverändert (Tage, Schichten, „gesucht“, freie Schichten).
3. **Senden** — unverändert (Empfänger, Anlass, Vorschau, Zahlen, Startzeit).

Schritt-Leiste zeigt drei Pillen: „1 Was suchst du?“, „2 Schichten“, „3 Senden“. Zurückspringen wie bisher, vorwärts nur über „Weiter“.

### 3.2 Regeln

`DispoJobCreateState::schritte()` liefert `[1 => 'Was suchst du?', 2 => 'Schichten', 3 => 'Senden']`. `kannWeiter(1, …)` prüft nacheinander Filiale, Zeitraum, Veranstaltungen; die erste Meldung gewinnt (Wortlaut der bisherigen Schritte 1–3 bleibt). `kannWeiter(2)` = bisheriger Schritt 4, `kannWeiter(3)` = bisheriger Schritt 5. `alles()` unverändert in der Wirkung.

Von der VA-Seite (`?event=`) landet man wie bisher direkt bei den Schichten, jetzt Schritt 2. Die Filiale aus der Session wird weiter vorbelegt. `updatedFilialNr()` leert wie bisher Veranstaltungen, Tage, Zahlen.

Die Veranstaltungsliste ohne Treffer zeigt: „In diesem Zeitraum hat die Filiale keine Veranstaltung mit Einsatztagen.“ (ohne „geh einen Schritt zurück“).

### 3.3 Anleitung

`docs/anleitungen/dispo-jobs-anbieten.md`: Folien zu Filiale/Zeitraum/Veranstaltungen werden zu einer Folie, Nummerierung angepasst.

## 4. B — Entscheidungshilfe je Zusage

Im Modus „Alle Zusagen sammeln“ sucht der Disponent aus. Dafür bekommt jede Zusage-Zeile drei zusätzliche Angaben:

| Spalte | Inhalt | Quelle |
|---|---|---|
| Einsätze | Zahl der Auftrags-Einsatztage im laufenden Monat, über alle Datensätze der Person | `DispoEinsatzZaehler::proMonat` mit Identitätsgruppe (wie in Schritt 3) |
| Tätigkeit | „passt“ (grün) wenn die Tätigkeit der Schicht in den ZAS-Tätigkeiten der Person steht, sonst „andere“ (grau) mit Tooltip der eigenen Tätigkeiten; „keine aus ZAS“ wenn die Liste leer ist | `hrData.dispo_taetigkeiten`, Vergleich wie `DispoJobRecipientPlanner` (kleingeschrieben, getrimmt) |
| Bewertung | die fünf Termin-Bewertungen als Ziffern „4 · 5 · 3 · 4 · 4“ in fester Reihenfolge, Tooltip mit den fünf Labels; Altbestand ohne Termin-Bewertung: „★ 4“ aus `star_rating`; nichts vorhanden: „–“ | `DispoEmployeeGateway::profileCards` (Lücken aus der Gruppe füllen wie `crewCard` auf der VA-Seite) |

Neuer Baustein `DispoJobZusagenInfo::laden(array $employeeIds, string $monat): array` (DB-Rand, ein Durchlauf pro Seitenaufruf): liefert je Mitarbeiter-ID `['name', 'personnel_number', 'phone', 'einsaetze', 'taetigkeiten', 'ratings']`. Er ersetzt in `Show::rueckmeldungen()` den bisherigen `contacts()`-Aufruf. Zwei bis vier Queries für alle Zeilen zusammen, nie pro Zeile.

Reihenfolge der Zeilen bleibt nach Zusage-Zeitpunkt. Keine Sortierung nach Bewertung (keine Empfehlung).

## 5. C — Fortschritt oben, offene Schichten zuerst

Über der Schichtenliste eine Zeile: **„12 von 20 Plätzen zugesagt · 5 in ZAS eingetragen · 3 angekommen“**. Zusätzlich ein schmaler Balken (zugesagt/gesucht, bei ≥ 100 % grün). „gesucht“ ist die Summe über alle Schichten der Anfrage.

Schichten werden sortiert: zuerst Schichten mit `zugesagt < gesucht` nach Datum, Beginn, Tätigkeit; dahinter volle Schichten in derselben Ordnung, Kopf grün hinterlegt. Eine Schicht mit `gesucht = 0` (kommt nicht vor, Schritt 2 verlangt > 0 nur in Summe) gilt als voll.

Neuer reiner Baustein `DispoJobFortschritt::berechnen(iterable $schichten): array{gesucht:int, zugesagt:int, eingetragen:int, angekommen:int, prozent:int, offen:list, voll:list}`.

## 6. D — Weniger Klicks beim Eintragen

Je Schicht-Kachel zwei Knöpfe rechts im Kopf, nur sichtbar, wenn die Anfrage offen ist und Zusagen vorliegen:

- **„Alle als in ZAS eingetragen markieren“** (nur wenn mindestens eine Zeile ohne Haken): `wire:confirm` „N Zusagen als in ZAS eingetragen markieren?“; `Show::alleEintragen(int $shiftId)` setzt `zas_entered_at`/`zas_entered_by_user_id` auf allen angenommenen Zeilen der Schicht ohne Haken (Abfrage mit `request_id`-Bindung wie `zasHaken`). Bereits gesetzte Haken bleiben.
- **„Liste kopieren“**: kopiert eine Textliste in die Zwischenablage, eine Zeile je Zusage: `Nachname, Vorname · PNr · Handynummer`, sortiert wie die Tabelle. Der Text wird serverseitig gebaut (`DispoJobZasListe::text(array $zeilen): string`, rein) und über ein `x-data`/`navigator.clipboard.writeText` am Knopf kopiert; danach steht zwei Sekunden „kopiert“ am Knopf. Fehlt `navigator.clipboard` (http, alter Browser), öffnet der Knopf ein kleines `<textarea readonly>` mit dem Text zum Markieren.

## 7. E — Zähler am Menüpunkt

Sidebar „Jobs anbieten“ bekommt ein Badge wie „Kommunikation“: Zahl der **Zusagen ohne ZAS-Haken in offenen Anfragen** (= das, was der Disponent noch abarbeiten muss). Kein „neu seit letztem Besuch“ — dafür bräuchte es einen Gesehen-Stempel, der hier nichts verbessert.

Neuer Baustein `DispoJobOffeneZusagen::count(): int`: eine Query (`rec_dispo_job_offer_shifts` join shifts join requests, `accepted_at` gesetzt, `zas_entered_at` null, `zas_assignment_id` null, Anfrage offen, `shift.datum >= heute`), `try/catch` → 0 wie `DispoUnreadCounter`. Kein Cache (die Query ist indexiert und klein).

Die Übersicht zeigt dieselbe Zahl in der Kopfzeile: „N Zusagen warten auf ZAS“.

## 8. F — Endzeit der Schicht

### 8.1 Daten

Migration `2026_10_14_000001_add_bis_and_withdrawn_at_to_rec_dispo_job_tables.php`:
- `rec_dispo_job_shifts.bis` string(8) NOT NULL default '' nach `von`. **Nicht** Teil des Unique-Index — die Schicht bleibt über (request, event, datum, von, taetigkeit) eindeutig.
- `rec_dispo_job_offer_shifts.withdrawn_at` timestamp nullable (für G).

### 8.2 Herkunft

`DispoJobSchichten::optionen()` liefert je Gruppe zusätzlich `bis`: die häufigste nicht-leere Endzeit der Einbuchungen der Gruppe, bei Gleichstand die früheste. Fehlt sie überall: ''. `Create::gewaehlteSchichten()` reicht `bis` durch, `DispoJobAnfrageAnlegen` speichert es.

Freie Schichten („andere“) bekommen neben „Beginn“ ein optionales Feld „Ende“ (`type="time"`, `neuBis`). Validierung: Ein Ende vor dem Beginn ist erlaubt (Nachtschicht über Mitternacht, z. B. 18:00–02:00); nur Beginn gleich Ende wird abgelehnt (Meldung „Beginn und Ende dürfen nicht gleich sein.“); leer ist erlaubt.

### 8.3 Anzeige

Überall, wo heute „16:00 Uhr“ steht: „16:00–23:00 Uhr“ wenn `bis` gefüllt, sonst wie bisher. Betroffen: Schritt 2 (Schichtzeile), Anfrageseite (Schichttitel), Mitarbeiter-Seite (`l1`: „16:00–23:00 · Servicekraft“), Übersicht unverändert. Die WhatsApp-Vorlagen bleiben unverändert.

Bestand: vorhandene Schichten haben `bis = ''` und zeigen weiter nur den Beginn. Kein Backfill.

## 9. G — „Doch nicht“

### 9.1 Mitarbeiter-Seite

Unter „Du bist dabei — wir melden uns.“ steht ein Textlink **„Doch nicht?“**, solange die Zusage zurücknehmbar ist. Klick → `wire:confirm` „Deine Zusage für diese Schicht zurücknehmen?“ → `JobOffers::zuruecknehmen(int $offerShiftId)`.

Zurücknehmbar = Anfrage läuft (Status läuft, nicht abgeschlossen), Datum ≥ heute, `zas_entered_at` null, `zas_assignment_id` null.

Ist die Zeile schon in ZAS eingetragen oder angekommen, steht statt des Links: „Du bist eingetragen — wenn etwas dazwischenkommt, melde dich bei deiner Dispo.“

### 9.2 Baustein

`JobOffersRuecknahme::ausfuehren(int $offerShiftId, array $employeeIds): 'ok'|'eingetragen'|'weg'`:
- `weg`: Zeile nicht vorhanden, gehört nicht zu `$employeeIds`, Anfrage nicht offen oder Datum vergangen. Eine geschlossene Schicht sperrt die Rücknahme nicht.
- `eingetragen`: `zas_entered_at` oder `zas_assignment_id` gesetzt.
- `ok`: bedingtes Update in einer Query (`whereNotNull('accepted_at')->whereNull('zas_entered_at')->whereNull('zas_assignment_id')`) setzt `accepted_at = null`, `withdrawn_at = now()`. Trifft das Update keine Zeile (Disponent hat zwischenzeitlich den Haken gesetzt), Ergebnis `eingetragen`.

Hinweise auf der Seite: ok → „Alles klar, deine Zusage ist zurückgenommen.“ (grün); eingetragen → „Du bist schon eingetragen — bitte melde dich bei deiner Dispo.“; weg → „Dieses Angebot gibt es nicht mehr.“

Nach der Rücknahme zeigt die Schicht wieder „Ich bin dabei“ (sofern Tag frei und Schicht nicht voll). Erneute Zusage über `JobOffersZusage` setzt `accepted_at` neu und `withdrawn_at = null`. Bei „Wer zuerst kommt“ wird der Platz durch die Rücknahme wieder frei (Zählung über `accepted_at`, unverändert). Eine volle Schicht im Modus ‚Wer zuerst kommt‘ wird nach der Rücknahme wieder geöffnet, wenn Zusagen < gesucht.

Schwestern-Zeilen (dieselbe Schicht aus mehreren Anfragen, Runde 1 §9): die Rücknahme gilt für alle Schwestern mit derselben Bedingung, analog zur Zusage. Ist eine Schwester schon in ZAS eingetragen, bleibt alles stehen und das Ergebnis ist `eingetragen`.

### 9.3 Disponent

Zurückgenommene Zusagen verschwinden aus der Tabelle. Die Schicht-Kachel zeigt zusätzlich den grauen Chip „N zurückgezogen“, wenn N > 0 (Zählung `withdrawn_at` gesetzt und `accepted_at` null). Kein Name, kein Zeitpunkt — die Person ist einfach nicht mehr dabei.

`DispoJobZasAbgleich`, `DispoJobSchonZugesagt`, Planner-Topf `schon_zugesagt`, Zähler auf Übersicht und Badge arbeiten alle über `accepted_at` und bleiben unverändert korrekt. Das wird mit je einem Test festgenagelt.

## 10. H — Übersicht

- Spalte „Zusagen“ zeigt „12 / 20“ (zugesagt / gesucht), bei Erfüllung grün.
- Neue Spalte „Noch in ZAS“: Zusagen ohne Haken (0 ausgeblendet als „–“).
- Filter-Pillen über der Tabelle: **Offen** (Standard) · Abgeschlossen · Alle. `#[Url]`-Property `filter`, Werte `offen|zu|alle`, alles andere → `offen`.
- Cap bleibt 200 Zeilen (Nachtrag Runde 1).

## 11. Bausteine (Code)

Neu, alle unter `src/Services/Zas/Dispo/Jobs/`:
- `DispoJobFortschritt` (rein)
- `DispoJobZusagenInfo` (DB-Rand)
- `DispoJobZasListe` (rein)
- `DispoJobOffeneZusagen` (DB-Rand, wirft nie)
- `JobOffersRuecknahme` (DB-Rand, Transaktion wie `JobOffersZusage`)

Geändert: `DispoJobCreateState` (3 Schritte), `DispoJobSchichten` (`bis`), `DispoJobAnfrageAnlegen` (`bis`), `JobOffersZusage` (`withdrawn_at = null`), `RecDispoJobShift` + `RecDispoJobOfferShift` (fillable/casts), `Livewire/Dispo/Jobs/{Create,Show,Index}`, `Livewire/Public/JobOffers`, die vier Views, `sidebar.blade.php`, Anleitung.

Keine Änderung an Versand, Wellen-Job, Planner, ZAS-Abgleich.

## 12. Tests

Integration (Capsule, echte Migrationen):
- `DispoJobCreateStateTest`: drei Schritte, Meldungen von Schritt 1 in Reihenfolge, `alles()` unverändert.
- `DispoJobSchichtenTest`: `bis` häufigste/früheste, leer wenn keine.
- `DispoJobFortschrittTest`: Summen, Prozent, Sortierung offen vor voll, `gesucht = 0` zählt als voll.
- `DispoJobZusagenInfoTest`: Einsätze über Identitätsgruppe, „passt“ kleingeschrieben, fünf Bewertungen einzeln, Altbestand `star_rating`, Lücken aus der Gruppe.
- `DispoJobZasListeTest`: Zeilenformat, Sortierung, fehlende Nummer als „–“.
- `DispoJobOffeneZusagenTest`: zählt nur offen + unverhakt + nicht angekommen + zukünftig; wirft nie (Tabelle fehlt → 0).
- `JobOffersRuecknahmeTest`: ok/eingetragen/weg, Race gegen ZAS-Haken (bedingtes Update), Schwestern, erneute Zusage leert `withdrawn_at`; Abgleich/Topf/Badge ignorieren zurückgezogene.
- `Show::alleEintragen`: nur Zeilen der eigenen Anfrage und Schicht, bestehende Haken bleiben.

Unit (Platzierungsverträge wie bisher): Sidebar-Badge, Filter-Pillen, „Doch nicht“ nur bei rücknehmbaren Zeilen, Endzeit-Format.

`php tools/blade-check.php` über alle fünf Views. Mutationsproben auf Sortierung, bedingtes Update, Badge-Filter.

## 13. Auslieferung

Migration (eine Datei, zwei Tabellen) → `migrate`, `view:clear`. Kein `queue:restart` (der Wellen-Job ist unverändert). Bestand läuft ohne Backfill weiter.

## 14. Bewusst nicht

- Keine Vorbelegung „gesucht“, kein Wellenplan (Markus).
- Keine Sortierung nach Bewertung, kein Gesamtschnitt.
- Kein „Kann nicht“ (Absage) — nur das Zurücknehmen der eigenen Zusage.
- Keine Nachricht an den Mitarbeiter bei Rücknahme oder ZAS-Haken; die Bestätigung kommt wie bisher über den normalen Dispo-Weg nach dem ZAS-Import.
- Kein Gesehen-Stempel fürs Badge.
