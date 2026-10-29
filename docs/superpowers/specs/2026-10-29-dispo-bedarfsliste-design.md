# Jobs anbieten — Schichten aus der ZAS-Bedarfsliste

Stand 29.10.2026. Anlass: Markus/Sheran („da fehlen Schichten“, VA RG20407 Broich Connected 02.–04.11.). Belegt per Prod-Abfrage auf Lieferung #378: 13 Personenzeilen geliefert = 13 bei uns gespeichert (Import vollständig), aber 18 Bedarfsposten in derselben Lieferung, davon 8 ohne eine einzige Person. Genau diese 8 fehlten in Schritt 2.

## 1. Ursache
Schritt 2 („Schichten“) baut die Auswahl ausschließlich aus Personenzeilen (`rec_dispo_assignments`, Block `{Dispo}`): je Tag jede Kombination Beginn × Tätigkeit. Offene Plätze waren bisher nur sichtbar, wenn ZAS sie als Platzhalterzeilen RG0/RG14 mitlieferte. Bei dieser VA gibt es keine Platzhalter, die offenen Plätze stehen nur in der Bedarfsliste (Block `{Dispo2}`), die der Import zwar liest, aber nur für die Kopfdaten der VA nutzt und sonst verwirft.

## 2. Begriffe aus den Daten
- **Dispoposten** = Bedarf für eine Tätigkeit an einem Tag einer VA, mit `anzahl` Plätzen und einer `dispoposten_id` (ZAS-Nummer, z. B. 121000). Dieselbe Nummer ist die Spalte „Dispo“ in Olaf Michels Importformat für Buchungen (Mail 29.10.).
- Hat ein Posten mehrere Plätze mit unterschiedlichen Zeiten, liefert ZAS je **Zeitvariante** eine Zeile mit derselben Nummer und derselben `anzahl` (14.09.: zweimal 120988, 08:30 und 08:45, Anzahl 2). `anzahl` ist die Gesamtzahl des Postens, nie je Zeile.
- Personenzeilen tragen keine Posten-Nummer. Zuordnung Person → Posten nur über Tag, Tätigkeit und Beginn.
- Die Bedarfsliste enthält auch vergangene Tage.

## 3. Datenmodell
Neue Tabelle `rec_dispo_bedarfe`:
`id`, `rec_dispo_event_id` (FK, cascade), `dispoposten_id` (string 32, unique), `datum` (date), `von`, `bis` (string 8, `''` statt null), `zeiten` (json, Liste `[{von,bis}]` aller Zeitvarianten, sortiert nach von), `taetigkeit` (string 190), `taetigkeit_id` (string 32, nullable, z. B. RG27), `anzahl` (unsigned int), `status_id` (tinyint), `last_seen_at`, `missing_since`, timestamps. Index `datum`, Index `(rec_dispo_event_id, datum)`.
`von`/`bis` = früheste Zeitvariante. Modell `RecDispoBedarf`, Relation `RecDispoEvent::bedarfe()`.

`rec_dispo_job_shifts.dispoposten_id` (string 32, nullable, index): wird beim Anlegen einer Anfrage aus der gewählten Schicht übernommen. Grundlage für den späteren Buchungs-Export an ZAS.

## 4. Import (`ZasDispoImportPlanner` + `ZasDispoWebexportImporter`)
- Planner liefert zusätzlich `bedarfe`: `dispoposten_id => {einsatz_ref, datum, von, bis, zeiten, taetigkeit, taetigkeit_id, anzahl, status_id}`. Zeilen ohne `dispoposten_id`, ohne `einsatz_id` oder mit unparsebarem Datum werden gezählt und übersprungen (`stats.skipped_bedarf_rows`). Mehrere Zeilen derselben Nummer werden zu einem Eintrag mit mehreren `zeiten` zusammengefasst; `anzahl` kommt aus der ersten Zeile.
- Importer: `updateOrCreate` je `dispoposten_id` mit `rec_dispo_event_id`, `last_seen_at = now`, `missing_since = null`. Zähler `bedarfe_created/updated`.
- Verschwunden: nur wenn die Lieferung mindestens einen brauchbaren Posten hat (Review M-1: ein `{Dispo2}`-Block, dessen Zeilen alle übersprungen werden, leert den Bestand nicht). Bestehende Bedarfe mit `datum >= heute`, `missing_since` leer, deren Nummer nicht in der Lieferung ist → `missing_since = now` (`bedarfe_missing_marked`). Wiederauftauchen setzt `missing_since` zurück (über das `updateOrCreate`). Trockenlauf zählt, schreibt nicht.
- Alles im bestehenden Transaktionsblock; die Personenzeilen-Logik bleibt unverändert.

## 5. Schritt 2 (`DispoJobSchichten::optionen`, `Create`)
`optionen(iterable $assignments, array $tage, iterable $bedarfe = [])`. Ergebnis je Tag wie heute, Zeilen zusätzlich mit `offen` (?int) und `dispoposten` (?string):

1. Bedarfe (nicht verschwunden, Status ≠ 3) ergeben je Posten **eine** Zeile: `von`/`bis` der frühesten Variante, `taetigkeit`, `dispoposten`.
2. Personenzeilen (nicht verschwunden, nicht zur Löschung gemeldet) werden einem Posten zugeordnet, wenn Tag und Tätigkeit (trim, case-insensitiv) passen und ihr Beginn einer Zeitvariante des Postens entspricht; passen mehrere Posten, gewinnt der mit den meisten noch offenen Plätzen (Gleichstand: der erste). Passt der Beginn zu keinem, aber es gibt an dem Tag genau einen Posten dieser Tätigkeit, gehört sie dorthin. Zugeordnete Zeilen erhöhen `zeilen` (und `platzhalter`, wenn RG0/RG14) der Postenzeile. Bewusst nicht abgezogen: eine Person mit abweichendem Beginn bei mehreren Posten derselben Tätigkeit (eigene Zeile, `offen` der Posten bleibt; Review M-3).
3. `offen = max(anzahl − echte Personen des Postens, 0)`; Platzhalter zählen nicht als echte Personen.
4. Personen ohne Posten bilden wie heute eigene Zeilen (Beginn × Tätigkeit) mit `offen = null`.
5. Sortierung je Tag wie heute (Beginn, Tätigkeit).

Anzeige: Spalte „laut ZAS unbesetzt“ zeigt `laut ZAS N offen` (auch bei 0, dann grau), sonst wie bisher die Platzhalterzahl. Schritt 1 zeigt je VA zusätzlich „N Posten laut ZAS“. Keine Vorbelegung von „gesucht“ (Entscheidung aus Runde 1 bleibt). Schlüssel der Schicht: `schluessel(event, datum, von, taetigkeit, posten)` — Postenzeilen tragen die Posten-Nummer im Schlüssel, Personenzeilen nicht (Review I-1: zwei Posten mit gleichem Beginn und gleicher Tätigkeit, etwa 14:00–23:30 und 14:00–18:00, sind zwei Schichten mit eigenem Eingabefeld). Entsprechend nimmt der Unique-Index von `rec_dispo_job_shifts` die `dispoposten_id` mit auf, und `DispoJobAnfrageAnlegen` fasst nur Zeilen mit gleichem Posten zusammen.

`Create::events()` listet eine VA auch dann, wenn sie im Zeitraum nur Bedarfe und keine Personenzeilen hat. `Create::schichtOptionen()` lädt Bedarfe der gewählten VAs im Zeitraum. `gewaehlteSchichten` reicht `dispoposten` durch, `DispoJobAnfrageAnlegen` speichert es an der Job-Schicht.

## 6. Nicht Teil dieses Pakets
- Buchungs-Export an ZAS (wartet auf Olafs Antworten; braucht nur noch die Posten-Nummer an der Job-Schicht, die hier mitkommt).
- Vorbelegung „gesucht = offen“.
- Mitarbeiterseite, Zusagen, Wellen: unverändert.

## 7. Tests
- Unit Planner: Bedarfe mit Zeitvarianten zusammengefasst, Anzahl aus erster Zeile, Zeilen ohne Nummer/Datum übersprungen.
- Unit Schichten: Posten ohne Person erscheint mit `offen`; Person mit passendem Beginn zählt; Person mit abweichendem Beginn zählt bei eindeutigem Posten; Platzhalter nicht als echt; zwei Zeitvarianten = eine Zeile; Person ohne Posten bleibt eigene Zeile; verschwundene/stornierte Bedarfe fallen raus.
- Integration Importer (Capsule, Migrationen): Bedarfe werden angelegt und aktualisiert, verschwinden und tauchen wieder auf, Trockenlauf schreibt nicht.
- Integration Create-Flow: `dispoposten_id` landet an der Job-Schicht; zwei Posten mit gleichem Beginn werden zwei Schichten.
- Integration Komponente (`DispoJobCreateKomponenteTest`): `Create::events()` listet eine VA nur mit Bedarf (andere Filiale, Zeitraum, Storno, verschwunden bleiben draußen) und `Create::schichtOptionen()` liefert die Posten samt Personen-Zuordnung.
- Deploy: `migrate` (zwei Migrationen), `view:clear`. Kein queue:restart nötig (Importer läuft im Request des Inbound-Endpunkts; falls als Job: restart).
