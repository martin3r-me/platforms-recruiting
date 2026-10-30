# Jobs anbieten — Zusagen als Buchungen an ZAS übergeben

Stand 30.10.2026. Anlass: Olaf Michel (ZAS, Mail 29.10.) importiert Buchungen aus dem alten Webportal im Format `PNr;Projekt;Dispo;VDatum` und bietet an, dieselbe Datei von uns zu nehmen. Markus' Frage 2 („muss Sheran die Zusagen weiter von Hand in ZAS eintragen?“) wird damit beantwortet: nein, sobald dieser Abruf läuft.

Olafs Antworten auf unsere drei Fragen (30.10.):
1. Die Zeilen gehen direkt in die Dispo-Liste. Passt die Zeile zu den Zeiteinträgen, ist sie sofort bestätigt (Status Auftrag), sonst wird sie „zum Bearbeiten markiert“.
2. Eine Rückmeldung an uns (angekommen / abgelehnt) gibt es nicht.
3. Der Webexport an uns wird von Hand angestoßen, kein fester Takt.

## 1. Format (verbindlich nach Olafs Beispiel)
```
PNr;Projekt;Dispo;VDatum;|;
RG17502;RG19801;122910;21.09.2026;;|;
```
- UTF-8 mit BOM, Trenner `;`, Zeilenende `;|;` wie alle unsere ZAS-Dateien (`ZasCsvBuilder`).
- Kopfzeile mit vier Spalten. **Datenzeilen mit einer fünften, leeren Zelle** (`...;21.09.2026;;|;`), exakt wie in Olafs Beispiel aus dem alten Portal. Sein Importer liest positional; wir weichen nicht ab.
- `PNr` = Personalnummer des Mitarbeiters mit Präfix (`RG17502`), `Projekt` = `einsatz_ref` der VA (`RG19801`), `Dispo` = `dispoposten_id` der Job-Schicht, `VDatum` = Tag der Schicht `d.m.Y`.
- Sortierung: Projekt, Datum, PNr.

## 2. Welche Zusagen rausgehen
Eine Zeile je `rec_dispo_job_offer_shifts`-Eintrag, wenn **alles** gilt:
- `accepted_at` gesetzt, `declined_at` leer.
- `zas_exported_at` leer (noch nie übergeben).
- `zas_entered_at` leer (nicht von Hand in ZAS eingetragen, sonst Dublette in ZAS).
- `zas_assignment_id` leer (nicht schon aus ZAS angekommen).
- Schicht: `dispoposten_id` nicht leer, `datum >= heute`.
- VA: `einsatz_ref` nicht leer.
- Mitarbeiter (`offer.rec_employee_id`, die für diese VA gewählte Anstellung): `personnel_number` nicht leer **und mit Firmenkennung** (`RG…`/`MA…`, `ZasPersonnelNumber::hasPrefix`; reine Ziffern sind zwischen RG und MA nicht eindeutig), `is_test` false.
- Alle drei Werte nach `trim` nicht leer (Review F7).
- Team: ist `recruiting.zas.inbound_team_id` gesetzt, nur Anfragen dieses Teams (Review F9).

Fehlt Posten-Nummer, Personalnummer oder Firmenkennung, bleibt die Zusage Handarbeit (Haken wie bisher); die Seite sagt warum (§5). Anfrage-Status ist egal: eine Zusage ist eine Zusage, auch wenn die Anfrage abgeschlossen ist.

**Eine Buchung = (Person, Posten, Tag).** Schwester-Anfragen führen dieselbe Schicht doppelt, und `JobOffersZusage` sagt beiden Zeilen zu. Deshalb: Zusage-Zeilen mit gleichem Schlüssel werden zu **einer** Datei-Zeile zusammengefasst (führend die kleinste ID, alle IDs werden markiert). Ist der Schlüssel schon über eine andere Zeile übergeben, von Hand eingetragen oder angekommen, wird die Zeile **nicht** geliefert, sondern nur als eingetragen markiert („abgedeckt“, Review F6).

## 3. Marker und Abruf
Neue Spalte `rec_dispo_job_offer_shifts.zas_exported_at` (timestamp, nullable).

Abruf `GET /recruiting/zas/dispo/bookings.csv` (Bearer wie die anderen ZAS-Abrufe), Controller `ZasDispoBookingExportController`, Service `ZasDispoBookingExport`:
- `?einsatz=RG19801` begrenzt auf eine VA (für Olafs Tests).
- `?dry_run` (auch ohne Wert, `=1`, `=true`, jeder andere Wert) liefert die Datei, setzt keinen Marker. Nur ein ausdrückliches Nein (`0`, `false`, `no`, `off`, `nein`) ist scharf. Ein Test-Abruf darf nie aus Versehen Zeilen für immer verbrauchen (Review F2).
- Scharf: **erst beanspruchen, dann liefern** (Review F1). Je Datei-Zeile ein bedingter `UPDATE … SET zas_exported_at = now WHERE id = ? AND zas_exported_at IS NULL`; nur Zeilen mit Treffer kommen in die Datei. Zwei gleichzeitige Abrufe teilen sich die Zeilen, keiner liefert doppelt. Danach für alle IDs der Buchung `zas_exported_at` (falls leer) und `zas_entered_at` (falls leer, `zas_entered_by_user_id` bleibt leer). Abgedeckte Zeilen (§2) bekommen nur `zas_entered_at`.
- Logzeile je Übergabe mit den IDs (Review F3).
- Antwort-Header `X-Records-Count`, `X-Dry-Run`, Dateiname `rheingedeck-dispo-bookings.csv`, `Cache-Control: no-store`.

**Erneut senden** (Review F3): Der Marker steht vor dem Senden. Reißt die Verbindung oder scheitert Olafs Import, gibt `recruiting:dispo-buchungen-erneut --id=… | --seit="Y-m-d H:i" [--dry-run]` die Zeilen wieder frei: `zas_exported_at` leer, `zas_entered_at` leer nur wenn kein Haken von Hand (`zas_entered_by_user_id` leer). Schon angekommene Zusagen werden nie freigegeben.

**Warum auch `zas_entered_at`:** „in ZAS eingetragen“ heißt fachlich: die Zusage steht in der ZAS-Dispo-Liste. Nach Olafs Antwort 1 ist das nach dem Import so. Alle bestehenden Zähler (Schicht-Chips, Fortschritt, Sidebar-Badge „noch in ZAS“, Übersicht) behandeln übergebene Zusagen damit automatisch richtig, ohne dass eine Abfrage angefasst wird. Der Unterschied Hand/Automatik steht in `zas_exported_at` und `zas_entered_by_user_id`.

Der Marker wird nie automatisch zurückgesetzt. Nimmt Sheran den Haken von Hand weg, bleibt `zas_exported_at` stehen, die Zeile geht nicht noch einmal raus. Das Ankommen in ZAS erkennt wie bisher `DispoJobZasAbgleich` (nächste Lieferung mit Status Auftrag → `zas_assignment_id`).

## 4. Keine Rückmeldung von ZAS → wir zeigen das Ausbleiben
Olaf meldet nicht zurück. Ersatz: Ist eine Zusage übergeben, ohne `zas_assignment_id`, und ist seitdem eine echte Dispo-Lieferung **eingegangen**, die mindestens 60 Minuten nach der Übergabe kam (`rec_zas_dispo_inbound_files.created_at`, `parse_status = processed`, `is_test = false`, Notizen mit `blocks_found = true`, also keine reine Qualifikationsliste; Review F5), dann steht sie in ZAS nicht als Auftrag: entweder noch nicht importiert oder „zum Bearbeiten markiert“. Die 60 Minuten fangen den Fall ab, dass Olaf den Webexport anstößt, bevor er unsere Datei importiert hat. Eingangszeit statt Verarbeitungszeit, damit ein späteres Neu-Verarbeiten alter Dateien keine falsche „Lieferung nach Übergabe“ erzeugt.

## 5. Anzeige auf der Anfrage-Seite (`Show`, je Zusage-Zeile)
Reihenfolge der Prüfung, genau ein Hinweis:
1. `zas_assignment_id` gesetzt → wie heute „✓ in ZAS angekommen“ (grün).
2. `zas_exported_at` gesetzt und Lieferung nach Übergabe (§4) → „an ZAS übergeben 30.10. 14:05 · noch nicht angekommen“ (amber).
3. `zas_exported_at` gesetzt → „an ZAS übergeben 30.10. 14:05“ (grau).
4. nicht übergeben, kein Haken, Schicht ohne Posten-Nummer → „von Hand eintragen: kein ZAS-Posten“ (grau, klein).
5. nicht übergeben, kein Haken, Person ohne Personalnummer → „von Hand eintragen: keine Personalnummer“.
6. nicht übergeben, kein Haken, Personalnummer ohne Firmenkennung → „von Hand eintragen: Personalnummer ohne Firmenkennung“.
7. sonst nichts (Haken wie heute).

Der Haken bleibt in allen Fällen bedienbar. Die Chips am Schichtkopf („in ZAS eingetragen n“, „angekommen n“) bleiben unverändert. Die Regel liegt rein in `DispoJobZasUebergabe::hinweis(...)` (unit-testbar), die Seite rendert nur.

## 6. Nicht im Umfang
- Posten-Nummer für Zusagen von vor dem 30.10. nachtragen (Bedarfsliste-Abgleich über Tag/Beginn/Tätigkeit): eigenes Kommando, nur wenn der Bestand das braucht.
- Fester Takt des Webexports: Frage an Olaf, liegt bei ZAS.
- Storno nach Übergabe: Zusagen sind bei uns nicht zurücknehmbar (nur über den Chat an Sheran); ZAS-seitige Stornos kommen wie bisher über die Lieferung (Status 3) und ändern an dieser Übergabe nichts.
- Automatischer Abruf-Trigger auf unserer Seite: ZAS holt ab, wie bei den Bestätigungen.

## 7. Tests
- `ZasDispoBookingExportTest` (Integration, Capsule): Auswahlregeln aus §2 je Bedingung (eine Zeile je Ausschluss, inkl. Leerzeichen, fehlender MA, ohne Firmenkennung), Zeilenformat/Sortierung, Team-Grenze, Beanspruchen setzt beide Spalten und lässt `zas_entered_by_user_id` leer, zwei parallele Abrufe liefern jede Zeile nur einmal, Schwester-Zusagen = eine Zeile, abgedeckte Zeilen nicht geliefert, `dry_run`-Erkennung, `einsatz`-Filter, CSV-Bytes exakt wie Olafs Beispiel (fünfte leere Zelle), Logzeile, Freigabe-Kommando.

## 8. Vor dem Scharfschalten (Review F4)
Alle Zusagen mit Posten-Nummer seit dem 30.10., Datum ≥ heute und ohne Haken gehen beim ersten Abruf raus. Hat Sheran davon schon etwas von Hand in ZAS eingetragen, ohne den Haken zu setzen, entsteht eine Dublette. Deshalb vor der URL an Olaf: `?dry_run=1` mit dem Prod-Token ziehen, Liste mit Sheran abgleichen, Eingetragenes abhaken lassen. Erst dann bekommt Olaf die URL.
- `DispoJobZasUebergabeTest` (Unit, rein): die sechs Fälle aus §5 inkl. 60-Minuten-Grenze.
- Bestehende Tests: `DispoJobOffeneZusagenTest` / `DispoJobUebersichtZahlenTest` bekommen je einen Fall „übergeben zählt nicht mehr als offen“.
- Migration in die Migrationslisten der betroffenen Integrationstests.
