# KPI-Dashboard V2 („Übersicht Recruiting") — Design

**Datum:** 2026-08-06
**Status:** Entwurf zur Review
**Vorgänger:** `2026-08-03-kpi-statistik-seite-design.md` (gebaut, live). Diese Spec
**ersetzt** die dort beschriebene Kohorten-Tabelle durch die vom Kunden gewünschte
Anordnung. Rechenkern (`CohortAssigner`), Anzeige-Schicht (`CohortViewModel`) und
das Phasen-Transition-Log bleiben unverändert bestehen.

## 1. Kontext & Ziel

Der Kunde hat ein Mockup geliefert („Übersicht Recruiting – Mönchengladbach (MGL)")
plus eine Ergänzung mit einem Ampelsystem. Kern der Änderung gegenüber V1:

- **Ort wird Filter statt Gruppierung.** Eine Filiale/Region wird ausgewählt, die
  Zeilen sind dann die Ausschreibungen dieses Standorts.
- **Zwei Tabellen:** oben je Ausschreibung, unten je Schulungstermin.
- **Neu: Bedarf.** Damit wird aus einer beschreibenden Auswertung („wir haben 96
  Bewerbungen") eine steuernde („wir haben 12 % des Bedarfs gedeckt").
- **Neu: zwei Ampeln** — eine auf die Pipeline (Bewerbungen), eine auf die
  Erfüllung (Unterschriften).

Nebenbei beantwortet das Mockup die alte Kundenfrage: **96 = Bewerbungseingang,
53 = Phase „Schulung buchen"**, jeweils über die aktiven MGL-Ausschreibungen.

**Was V1 beigebracht hat und hier gilt:** jede Bewerbung steckt in genau einer
Zeile, jede Summe ist die Addition der Zeilen darüber, kein Bucket verschwindet
still. Diese Eigenschaft ist der Grund, warum die Zahlen erklärbar wurden — sie
wird nicht gegen ein schöneres Layout getauscht.

## 2. Neue Datenfelder

Drei Felder an `rec_postings`, gepflegt von HR im Ausschreibungs-Formular:

| Feld | Typ | Bedeutung | leer bedeutet |
|---|---|---|---|
| `bedarf` | `unsignedInteger` nullable | Personen, die eingestellt werden sollen | keine Erfüllungs-Ampel, Spalte zeigt „–" |
| `bewerbungs_faktor` | `decimal(4,1)` nullable | Bewerbungen pro Einstellung | keine Pipeline-Ampel |
| `closes_at` | existiert | Laufzeitende | keine Hochrechnung → Ampel fällt auf absolute Lesart zurück |

**Nichts wird geraten.** Fehlt ein Wert, fehlt die Ampel — eine erfundene Ampel
ist schlimmer als keine.

**Faktor als freie Zahl, nicht als Auswahl 1–5.** Begründung: der gemessene Wert
liegt außerhalb dieses Bereichs (siehe §7). Das Feld wird mit dem gemessenen Wert
der letzten abgeschlossenen vergleichbaren Kampagne vorbelegt und ist
überschreibbar.

## 3. Zustandsmodell — zwei Marker, zwei Fragen

Das ist die Grundlage der Spalten und muss vor allem anderen klar sein:

| Marker | sagt | Lesart |
|---|---|---|
| **Phase** (`rec_phase_id`) | wo der Bewerber *steht* | jeder in genau einer → exklusiv |
| **Buchungsstatus** | wie weit er *gekommen ist* | Stufe enthält die darunter → kumulativ |

Die Buchungs-Leiter im Ist-Zustand (bestätigt durch Code-Prüfung):

| Status | ausgelöst durch |
|---|---|
| `booked` | Bewerber oder HR bucht einen Schulungstermin |
| `registered` | Phase 3 „Onboarding (Bestätigung)" abgeschlossen |
| `confirmed` | Reminder mit „Ja" beantwortet |
| `attended` / `no_show` | nach der Schulung gesetzt |

**Warum die Trichter-Spalten kumulativ gelesen werden müssen:** Der Status ist ein
einzelnes Feld und trägt nur den weitesten erreichten Punkt. Ein Mitarbeiter im
Einsatz hat trotzdem einen unterschriebenen Vertrag; wer teilgenommen hat, hat
trotzdem gebucht. Live gemessen (Stichprobe 200 Buchungen): 79× `attended`, aber
nur 2× `confirmed` — eine exklusive Zählung zeigte „Bestätigt: 2" neben
„Teilgenommen: 79" und wäre unlesbar. Zusätzlich würde die Erfüllungsquote
*sinken*, wenn Leute weiterkommen (wer im Einsatz ist, fiele aus
„unterschrieben") — eine Kennzahl, die bei Fortschritt fällt, ist kaputt.

Die exklusive Frage „wo hängen die Leute jetzt?" beantwortet die
**Phasen-Aufschlüsselung**, nicht die Trichter-Zeile. Beides steht nebeneinander,
ohne sich zu widersprechen.

**Vorbehalt (unverändert aus V1):** Der Trichter ist eine Momentaufnahme. Wer
storniert, verschwindet aus allen Spalten — Werte können zwischen zwei Aufrufen
sinken. Bleibt als Hinweis im UI.

## 4. Seitenaufbau

**Filterleiste — zwei getrennte, jeweils beschriftete Bereiche.** Der frühere
gemeinsame Zeitraum-Filter war die Ursache eines Widerspruchs (gefilterte
Bewerbungen gegen ungefilterten Bedarf ⇒ falsche Prozentzahl):

- **Ausschreibungen:** Ort (Pflichtauswahl, eine Filiale) + Status
  („aktuell online" / „alle"). **Kein Datumsfilter** — ein Ziel lässt sich nicht in
  Scheiben schneiden, und die Laufzeit steckt schon in `published_at`/`closes_at`.
- **Schulungstermine:** Zeitraum über `starts_at` (Standard: kommende + letzte
  4 Wochen). Hier ist ein Datumsfilter fachlich einwandfrei, weil ein Termin einen
  Zeitpunkt *hat*.

„Alle Orte" entfällt in V1 (Entscheidung des Kunden). Stellen ohne gepflegten
Standort sind damit nicht erreichbar — siehe §6, sie müssen trotzdem auffindbar
bleiben.

**KPI-Kacheln** darüber, unverändert aus V1: lesen ausschließlich aus dem
Kohorten-Ergebnis, keine eigenen Queries.

## 5. Tabelle 1 — je Ausschreibung

**Zeilen:** die Ausschreibungen des gewählten Standorts, gemäß Status-Filter.
**Gesamt-Zeile unten:** spaltenweise Addition, wie im Mockup.

**Spalten** (Trichter kumulativ, Namen nach dem, was sie messen — es gibt vier
Phasen, deshalb **kein** „Phase 5/6"):

| Gruppe | Spalten |
|---|---|
| Trichter | Bewerbungseingang · Phase 2 erreicht · gebucht · registriert · bestätigt · teilgenommen |
| Abzweige | Standby · nicht erschienen |
| Vertrag | Vertrag verschickt · Vertrag unterschrieben |
| Einsatz | Erster Einsatz *(leer, siehe §8)* |
| Ziel | Bedarf · Erfüllung Bedarf (%) |

„Phase N erreicht" kommt aus der Phasen-`order`. Das Transition-Log verbessert
das: wer zurückgestuft wurde (verlorener Standby-Platz), steht aktuell in einer
niedrigeren Phase, hat die höhere aber erreicht — mit dem Log wird er korrekt
gezählt, ohne es würde er unterzählt.

**Rechenregeln der Gesamt-Zeile:**
- Absolute Spalten: Summe der Zeilen.
- **Prozente werden neu gerechnet, nie gemittelt:** Erfüllung gesamt = Σ
  Unterschriften / Σ Bedarf (im Mockup 13/110 = 12 %), nicht der Durchschnitt der
  Zeilen-Prozente.
- **Der Faktor lässt sich nicht addieren.** Die Gesamt-Zeile zeigt keinen Faktor,
  sondern nur sein Ergebnis: Σ (Bedarf × jeweiliger Faktor) als
  Ziel-Bewerbungsvolumen.
- Bedarf gesamt ist die **Addition der einzelnen Bedarfe** (Entscheidung: vier
  eigenständige Ziele). Tooltip sagt das ausdrücklich, damit ein Missverständnis
  auffällt.

## 6. Tabelle 2 — je Schulungstermin

**Ein Termin ist gemischt:** Cateringhilfen, Zapfer und Logistiker sitzen im
selben Termin. Daraus folgen zwei Dinge:

- Ein Fremdschlüssel „Termin → Ausschreibung" wäre **falsch** und wird nicht
  gebaut (ein Termin gehört keiner Ausschreibung).
- **Die Kapazität gehört dem Termin, nicht der Qualifikation.** Die im Mockup
  gezeichneten eigenen IST/SOLL-Werte pro Ausschreibung am selben Abend („20/20",
  „13/15", „7/10") kann es deshalb nicht geben.

**Aufbau:** eine Zeile pro Termin mit Datum, Uhrzeit, Ort, **IST/SOLL (+Standby)**
und dem Trichter aller Teilnehmer. Darunter **aufklappbar die Aufteilung nach
Qualifikation** („davon Cateringhilfe 8, Zapfer 4, Logistiker 6") — jede
Unterzeile mit eigenem Trichter, aber **ohne** eigene Kapazität. Das ist die
Spalte „Ausschreibung" aus dem Mockup, eine Ebene tiefer, wo sie stimmt.

Die beiden Kapazitätsspalten aus V1 sind dafür bereits das richtige Modell:
„Belegt (Zeile)" = diese Qualifikation, „Belegt (Termin)" = der Abend insgesamt.
Überbuchung färbt statt zu klammern, Standby zählt in keiner der beiden.

## 7. Ampelsystem — Herleitung und Regeln

**Zweck:** Frühwarnung statt Nachbetrachtung. „Erfüllung Bedarf" allein sagt erst
am Ende, dass es nicht gereicht hat; die Pipeline-Ampel sagt es in Woche zwei.

**Was der Faktor ist:** die Umrechnung des Schwunds. Bedarf × Faktor = nötige
Bewerbungen. Faktor 3 behauptet 33 % Conversion, Faktor 7 rund 14 %.

**Gemessen (Live-Daten, 2026-08-06):**

| Kampagne | Bewerbungen | Unterschriften | Faktor | belastbar? |
|---|---|---|---|---|
| „Mönchengladbach allgemein" (abgeschlossen) | 43 | 6 | **7,2** | ✅ |
| MGL Initiativ (läuft) | 61 | 1 | — | ❌ zu jung |
| Cateringhilfe VIP (online seit 15.07.) | 33 | 1 | — | ❌ zu jung |
| Zapfer VIP | 27 | 0 | — | ❌ zu jung |
| Foodrunner | 19 | 0 | — | ❌ zu jung |
| Team gesamt, alle Zeiträume | 1542 | 151 | ~10 | Richtwert |

**Ein Faktor kann sich nur an abgeschlossenen Kampagnen messen** — eine laufende
kann sich nicht selbst bewerten (Right-Censoring). Startwert daher **7,2** aus der
abgeschlossenen Kampagne; nach den August-Schulungen neu messen. Der Kunde nannte
3 — das ist rund doppelt bis dreifach optimistischer als gemessen und würde die
Ampel grün zeigen, während die Pipeline zu klein ist. Die Entscheidung liegt beim
Kunden, die Messung steht daneben.

**Warum die Ampel nicht 60/90 % auf das Gesamtziel rechnet.** Beispiel:
Cateringhilfe, Bedarf 40, Faktor 7 → 280 Bewerbungen nötig, aktuell 33, Anzeige
läuft seit drei Wochen.

- Läuft sie noch drei Wochen → Hochrechnung ~66 von 280 → echter Alarm.
- Läuft sie noch sechs Monate → völlig im Plan.

Dieselbe Zahl, entgegengesetzte Aussage. Eine absolute Schwelle steht am
Kampagnenanfang immer auf Rot und am Ende immer auf Grün — unabhängig davon, ob
nachgesteuert werden müsste. Sie beantwortet die Frage nicht, für die sie gebaut
wird.

**Regeln:**

| Ampel | Bezug | Schwellen |
|---|---|---|
| **Pipeline** (Bewerbungen) | Hochrechnung auf das Laufzeitende: Ist / verstrichene Laufzeit × Gesamtlaufzeit, verglichen mit Bedarf × Faktor. Ohne `closes_at`: absolute Lesart mit Kennzeichnung. | < 60 % rot · 60–90 % gelb · ≥ 90 % grün |
| **Erfüllung** (Unterschriften) | **absolut** gegen den Bedarf, wie vom Kunden gewünscht. Keine Hochrechnung, weil Unterschriften in Schüben nach jeder Schulung kommen — ein linearer Verlauf wäre irreführend. Restlaufzeit und Anzahl ausstehender Schulungen im Tooltip. | dieselben |

Mathematisch ist die Hochrechnung identisch mit „Ist / Soll-Fortschritt",
**kommuniziert wird aber die Hochrechnung**: „33 Bewerbungen an Tag 22 von 48 →
Hochrechnung 72 von 280" ist verständlich, „26 % des Soll-Fortschritts" nicht.

**Schutzregeln:**
- **Keine Ampel in den ersten Tagen** einer Anzeige, sondern „zu früh für eine
  Aussage". Bei 4 Bewerbungen an Tag 2 ist jede Hochrechnung Kaffeesatz, und eine
  falsche rote Ampel verbrennt das Vertrauen schneller als gar keine.
- Kein Bedarf oder kein Faktor → keine Ampel, nie eine geratene.
- Die zwei Ampeln dürfen sich widersprechen, und das ist ihr Wert: Pipeline grün +
  Erfüllung rot heißt „genug Bewerber, aber sie konvertieren nicht" (Problem in
  Schulung/Vertrag); beide rot heißt „zu wenig Bewerber" (Problem in der Anzeige).

**Darstellung:** heller Zeilen-Tint plus farbiger Statuspunkt in der ersten
Spalte, zusätzlich ein Ampel-Chip in der Erfüllungs-Spalte. **Keine Volltönung** —
die würde die Trichter-Farben erschlagen und die Zahlen unlesbar machen.

## 8. Rekonziliation — was sichtbar bleiben muss

Das Mockup zeigt nur die aktiven Ausschreibungen. Damit die Summen weiter
aufgehen, brauchen drei Mengen einen Platz (aufklappbar, unter den Tabellen):

- **Geschlossene Ausschreibungen.** MGL hat zwei mit erheblichen Beständen (43
  und 94 Bewerbungen). Ohne eigene Zeile verschwänden sie still.
- **Ausgeschiedene:** Dubletten, Geparkte, Abgesagte, nicht Zugeordnete, ohne
  Bewerbungsdatum. Vom Kunden ausdrücklich als eigene Kategorie gewünscht.
- **Stellen ohne Standort.** Rund 929 der 1542 Bewerbungen hängen an den alten
  Stellen „… bis 22.05.26", die keinen Standort tragen. Sie fallen aus einer
  ortsgefilterten Ansicht heraus — inhaltlich in Ordnung (geschlossene Anzeigen),
  aber sie müssen im Block „geschlossene Ausschreibungen" auftauchen, sonst
  verliert die Seite still 60 % der Historie.

**Pipeline-Ampel rechnet netto:** nur Bewerbungen, die noch im Rennen sind.
Geparkte und Dubletten sind keine Pipeline.

## 9. Datenlücken — bewusst offen

- **„Erster Einsatz"** braucht die Dispo, die es noch nicht gibt. Spalte wird
  gebaut und zeigt „–" mit Tooltip „kommt mit der Dispo".
- **Buchungsstatus-Historie fehlt** (`registered_at`/`confirmed_at`/`attended_at`
  existieren nicht). Für dieses Dashboard irrelevant, weil alle Spalten den
  Ist-Zustand lesen. Fehlt erst, wenn jemand „wie schnell antworten Leute auf den
  Reminder" fragt oder „aus welcher Stufe brechen sie ab" (bei Absagen ist die
  Ausgangsstufe nicht rekonstruierbar). Nachrüstbar mit drei Zeitstempeln.
- **`closes_at` ist bei allen aktiven Anzeigen leer.** Wird von HR nachgepflegt;
  bis dahin greift die absolute Lesart der Pipeline-Ampel.
- **Standort ist Freitext.** Eine Stelle trägt „Köln, Bonn" — zwei Orte in einem
  Feld, die ein exakter Ortsfilter unter keinem von beiden findet. Aktuell
  harmlos (keine Ausschreibungen daran), langfristig braucht es eine Ortsliste.

## 10. Getrennte Aufträge (nicht Teil dieser Spec)

- **① `CreateInterviewBookingTool` (MCP):** Default ist `registered` und der
  übergebene Status wird nicht geprüft. Damit kann eine Buchung „Onboarding
  abgeschlossen" behaupten, ohne dass Phase 3 durchlaufen wurde — genau die
  Spalte „registriert", die der Kunde sehen will. Zusätzlich greift das
  Standby-Sicherheitsnetz nur auf `booked`. Fix: Default `booked`, Status gegen
  die erlaubte Liste prüfen. **Vor dem Rollout dieses Dashboards.**
- **③ ist erledigt, ohne Codeänderung:** `confirm_booking_on_completion` schreibt
  `registered` — in der Semantik des Kunden ist das korrekt (Phase-3-Abschluss
  *ist* die Registrierung, nicht die Bestätigung). Es bleibt ein irreführender
  Konfigurationsname, dessen Umbenennung optional ist.
- **Ortsliste statt Freitext** für `rec_positions.location` (siehe §9).

## 11. Tests

Modul-Konvention: reines PHPUnit ohne Laravel/DB.

- **Rekonziliation** bleibt der wichtigste Test: Σ Zeilen = Gesamtmenge, jede
  Bewerbung genau einmal — jetzt zusätzlich mit den Blöcken „geschlossene
  Ausschreibungen" und „ohne Standort".
- **Ampel-Mathematik** als pure Klasse: Hochrechnung (inkl. Division durch null
  bei Laufzeit 0), Schwellen-Grenzfälle (exakt 60 %, exakt 90 %), fehlendes
  `closes_at` → absolute Lesart, fehlender Bedarf/Faktor → keine Ampel,
  „zu früh"-Regel.
- **Gesamt-Zeilen-Arithmetik:** Prozente neu gerechnet statt gemittelt (ein Test
  mit absichtlich schiefen Zeilen-Prozenten, deren Mittelwert vom korrekten
  Ergebnis abweicht).
- **Kumulative Trichter-Spalten:** monotone Kette, `no_show` erfüllt Rang 2 aber
  nicht Rang 3, `cancelled` fällt aus allen Spalten.

## 12. Offene Annahmen

Falls der Kunde etwas anders meint, fällt es hier auf:

1. **Bedarf gesamt ist die Addition** der einzelnen Bedarfe (vier eigenständige
   Ziele, MGL = 110). Entschieden.
2. **Faktor-Startwert 7,2** aus der abgeschlossenen Kampagne statt der vom Kunden
   genannten 3. Der Kunde kann überschreiben; die Messung steht im Tooltip
   daneben.
3. **Initiativ bekommt Bedarf und Faktor wie jede andere Ausschreibung** — es ist
   strukturell eine normale Anzeige. Ob ihr Faktor deutlich schlechter ausfällt,
   zeigt die Messung nach den August-Schulungen.
