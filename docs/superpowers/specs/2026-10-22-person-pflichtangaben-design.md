# RG + MA über die Person: Pflichtangaben, Ersthelfer, Bestand — Design

Stand 22.10.2026 · Status: **Konzept abgestimmt (Sebastian 22.10.), Plan folgt** · Branch: `feat/person-pflichten` (von main 83f95016)

## 1. Ziel

Ein Mensch mit RG- und MA-Anstellung wird bei Personenangaben als **ein** Mensch behandelt. Auslöser: Fall Tanja — die Staatsangehörigkeit steht an der MA-Akte (aus ZAS), an der RG-Akte ist sie leer, das RG-Profil im neuen Portal meldet sie als fehlende Pflichtangabe.

Erfolg:
- Ist eine Personenangabe an irgendeiner **aktiven** Anstellung der Person gefüllt, meldet das Portal sie in keiner Anstellung als fehlend und zeigt den vorhandenen Wert.
- „Ersthelfer“ gilt für den Menschen: ein Ja an der RG-Akte steht auch an der MA-Akte.
- Der Bestand ist einmalig angeglichen: leere Personenfelder aktiver Akten sind aus der aktiven Schwester gefüllt, ohne ZAS-Marker.

## 2. Abgrenzung (Befund Code-Kartierung 21.10.)

Schon über die Person, **nicht** Teil des Pakets:
- **Nachweispflichten:** `ProofReader::checklist` vereinigt `ProofTypes::requiredFor` über alle aktiven Anstellungen (`PersonPflichten::vereinige`); ein Nachweis zählt für alle Akten der Person.
- **Infos/Dokumente:** `DokumentLeser::sichtbare` liest über `PersonScopeResolver` — eine Info an der RG-Akte ist auch im MA-Portal sichtbar.

Bleibt **je Anstellung** (Markus 12.10.2026 E7/E8, Sebastian 21.10.): Verträge, Grundlohn/Zuschlag, Steuerklasse, Hauptarbeitgeber (`is_main_employer`, `other_employer`), Krankenkasse, Anstellungsart (`hr_data.employment_classification`), Personalnummer, Status, Eintritt, Tagekonto, Einsätze. Das sind `PersonenFelder::JE_GESELLSCHAFT` plus die Anstellungsdaten — unverändert.

„Ich bin“ (`employment_type`: Schüler, Student, FSJ …) ist eine Eigenschaft des Menschen und bleibt in `SPIEGELN` (Klärung 21.10.: nicht die Anstellungsart).

Nicht im Paket: altes Portal (`EmployeePortal`), HR-Akte (zeigt Spaltenwerte; nach Teil 3 sind sie gefüllt), Steuerklassen-/Hauptarbeitgeber-Tausch nach Stunden (eigenes Paket, Markus Folie 19).

## 3. Teil 1 — Pflichtangaben über die Person (neues Portal)

Heute: `PortalShell::datensatzWerte($employee, …)` liest nur die geöffnete Akte; `PortalMandatory::offen` prüft die Wächter (`PortalProfileGuards::WAECHTER`: `staatsangehoerigkeit`, `ersthelfer`, `hauptarbeitgeber`) gegen diesen einen Datensatz.

Neu: ein **Personen-Datensatz** für die Personenfelder.
- Neue reine Klasse `Support/PersonDatensatz` (bzw. kleiner Service): für eine Akte und die Werte ihrer **aktiven** Schwestern (gleiche Person über `PersonScopeResolver`, ohne die eigene, nur `is_active = 1`, nie `is_test`) gilt je Feld aus `PersonenFelder::SPIEGELN` plus den Ersthelfer-Feldern (Teil 2): **eigener Wert, wenn gefüllt; sonst der Wert der zuletzt geänderten aktiven Schwester, die ihn gefüllt hat** (`updated_at` absteigend, bei Gleichstand höchste id).
- `JE_GESELLSCHAFT`-Felder werden **nie** aus der Schwester genommen — die Hauptarbeitgeber-Frage bleibt je Akte.
- Datei-Spalten (`*_file_id`) werden nicht gemischt — Nachweise laufen über `ProofReader`.
- Eingehängt an **beiden** Lesestellen des Portals: `datensatzWerte` (Pflicht, Ring, Gruppenzeilen, Sichtbarkeit) und `formularwert` (Vorbelegung des geöffneten Blatts). Folge: öffnet Tanja im RG-Portal die Gruppe, steht die Staatsangehörigkeit vorbelegt da; speichert sie, landet der Wert über `PortalProfileWriter` (Eloquent) in der RG-Akte — die Akte heilt sich beim ersten Speichern, die Wächter beim Speichern sehen einen gefüllten Wert.
- Anzeige in der Gruppenzeile (`anzeigewert`) nutzt denselben Wert.
- Widerspruch (beide Akten gefüllt, verschieden) betrifft Teil 1 nicht: der eigene Wert gewinnt.

## 4. Teil 2 — Ersthelfer am Menschen

- `is_first_aider` und `first_aider_valid_until` kommen in `PersonenFelder::SPIEGELN`. Der Spiegel (`PersonenSpiegel::spiegele`, Observer `updated` + ZAS-Eingang) schreibt sie damit in alle Akten der Person.
- Beide Felder sind ZAS-relevant (`RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS`): eine **echte** Änderung im laufenden Betrieb setzt wie bei jedem gespiegelten Feld den Marker an der Schwester — gewollt.
- Der Ersthelferschein (`first_aider_certificate_file_id`) bleibt beim Nachweis (`ProofWriter` spiegelt die Altspalte schon auf alle Akten der Person).
- `MARKER_GESCHUETZT` = `SPIEGELN + JE_GESELLSCHAFT` wächst automatisch mit; prüfen, dass der ZAS-Import die beiden Felder weiter wie bisher behandelt (Inbound-Spiegel mit `offeneMarkerAuslassen`).
- Folge für `ProofTypes::requiredFor`: bleibt Vereinigung über aktive Akten (`is_first_aider` true an einer Akte → Ersthelferschein Pflicht) — nach Teil 2 ohnehin an beiden Akten gleich.

## 5. Teil 3 — Bestand einmalig angleichen

Neue Option am bestehenden Kommando: `php artisan recruiting:personendaten-abgleich --auffuellen [--dry-run] [--team=] [--person=]`.

- Je Person mit **mindestens zwei aktiven** Anstellungen (nie `is_test`).
- Felder: `PersonenFelder::SPIEGELN` (inkl. der beiden Ersthelfer-Felder aus Teil 2). Nicht: `phone`, `JE_GESELLSCHAFT`, Datei-Spalten.
- **Quelle und Ziel nur aktive Akten** (Sebastian 22.10.: keine Werte aus ausgeschiedenen Akten — die können veraltet sein; Rückkehrer heilen über Spiegel, Portal-Pflicht und ZAS-Lieferung).
- Je Feld: Haben die gefüllten aktiven Akten **genau einen** Wert (nach derselben Normalisierung wie der Lesemodus), wird er in die **leeren** aktiven Akten geschrieben. Zwei verschiedene Werte → nichts schreiben, Feld erscheint im Bericht als Widerspruch (Lesemodus des Kommandos zeigt diese Fälle bereits).
- Schreiben per **Query Builder**: kein Observer, kein Spiegel-Lauf, **kein `zas_changed_at`** (Sebastian 22.10., Variante A — wie Nation-Backfill 23.09.). Bei ZAS kommt der Wert mit dem nächsten normalen Speichern der Akte an.
- `updated_at` wird **nicht** angefasst (sonst verfälscht die „zuletzt geändert“-Regel aus Teil 1 und die Diagnose-Abfragen nach Änderungsdatum).
- Ausgabe: Zahlen je Feld (gefüllt / Widerspruch), Zahl der Personen und Akten, Marker-Zähler vorher/nachher (muss gleich bleiben). `--dry-run` schreibt nichts. Wiederholbar: ein zweiter Lauf findet nichts mehr zu füllen.
- Reihenfolge auf Prod: nach `personen-anlegen` (erledigt 22.10.).

## 6. Fehler- und Grenzfälle

- Person ohne `rec_person_id`: `PersonScopeResolver` fällt auf `person_key` + Telefon zurück (wie überall); ohne Treffer = nur die eigene Akte, Verhalten wie heute.
- Schwester inaktiv oder `is_test`: zählt weder in Teil 1 noch in Teil 3.
- Lookup-Felder (z. B. `nationality`): Wert wird roh übernommen, Anzeige über das vorhandene Lookup — keine Übersetzung.
- `is_eu_citizen` kann `false` sein: „gefüllt“ heißt nicht-null, nicht truthy (gilt auch für `is_first_aider = false`).
- Zahl `0`/leerer String: leer ist `null` oder `''` (wie `PortalMandatory`).

## 7. Tests

- Fall Tanja: RG ohne, MA mit Staatsangehörigkeit, beide aktiv → RG-Portal ohne offene Pflicht „Staatsangehörigkeit“, Blatt vorbelegt; Speichern schreibt den Wert in die RG-Akte.
- MA-Schwester inaktiv → Pflicht bleibt offen.
- Hauptarbeitgeber an der Schwester beantwortet → an der eigenen Akte weiter offen.
- Ersthelfer: Ja an RG gespeichert → MA-Akte trägt Ja + Datum, Marker an MA gesetzt.
- Teil 3: leer + gefüllt → gefüllt, Marker-Zähler unverändert, `updated_at` unverändert; zwei verschiedene Werte → unberührt + im Bericht; inaktive Quelle → nicht übernommen; `is_test` → nicht angefasst; zweiter Lauf → 0; `--dry-run` → nichts geschrieben.
- `false`-Werte zählen als gefüllt.

## 8. Deploy

Keine Migration, kein `queue:restart` (kein Job berührt). `view:clear` wie bei jedem Deploy. Danach auf Prod: `recruiting:personendaten-abgleich --auffuellen --dry-run` → Zahlen lesen → ohne `--dry-run`; Marker-Zähler vorher/nachher. Widersprüche aus dem Bericht an HR.
