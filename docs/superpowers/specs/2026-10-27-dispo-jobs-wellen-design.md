# Jobs anbieten — Wellenplan, Gesellschaft, „gedeckt“, „Kann nicht“, einmal pro Tag

Stand 27.10.2026. Freigabe: Markus Ammerer, Kommentare in `RheinGedeck_Dispo_Templates_Prozess_6_Folien_kommentiert_MA_SH_MA II.pptx` („jetzt sollte es durch sein“) plus Mail 27.10. („je Filiale je Gesellschaft ausweisen. So gefiltert schreiben wir dann auch an.“). Auflage aus der Mail: **Bedienbarkeit einfach, Sheran muss es ohne Erklärung verstehen.**

Vorgänger: Spec 2026-10-14 (Runde 2), Spec 2026-10-20 (Statusfilter). Alles Bestehende bleibt, es kommt dazu.

## 0. Was NICHT gebaut wird (vom Kunden gestrichen)
- Keine Erinnerungen an Nicht-Antworter (Markus: „Erinnerung einfach weglassen“). Damit keine Erinnerungsvorlagen bei Meta.
- Keine Rückmeldefrist als eigenes Feld. „Bitte antworte bis …“ auf der Mitarbeiterseite ist der Zeitpunkt der nächsten automatischen Welle (§2).
- Kein „Kann nicht“ bei Woche und Vierwochen („volle Flexibilität“).
- Keine Zählung nach Personen hinter der Filiale (überholt durch §3).

## 1. Begriffe
Anstellung = Datensatz `rec_employees` (Firma RG oder MA). Person = Anker der Identitätsgruppe (`DispoJobKandidaten`). Angeschrieben wird je Person einmal, gezählt wird in §3 je Anstellung.

## 2. Wellenplan (Folie 4/5/6, Markus: „20er-Blöcke abarbeiten, Masse eindämmen“)

### 2.1 Zwei Zahlen je Anfrage, vorbelegt je Anlass
| Anlass | höchstens je Welle | nächste Welle automatisch nach |
|---|---|---|
| Dringend | 20 Personen | 6 Stunden |
| Nächste Woche | 50 Personen | 24 Stunden |
| Nächste vier Wochen | 50 Personen | 3 Tage |

Konstanten in `DispoJobAnlass::WELLEN` (`max`, `abstand_minuten`). Je Anfrage änderbar, beide auch abschaltbar: Obergrenze leer = alle, Abstand „keine“ = nur diese eine Welle. Keine Einstellungsseite.

### 2.2 Oberfläche, Schritt 3 „Senden“
Neue Karte **„Wellen“** unter den Gruppen, zwei Zeilen:
- „Pro Welle höchstens [ 20 ] Personen“ (Zahl, leer = alle)
- „Nächste Welle automatisch nach [ 6 Stunden ▾ ]“, Auswahl: keine · 2 Std · 6 Std · 12 Std · 24 Std · 2 Tage · 3 Tage · 5 Tage
- Grauer Satz darunter: „Weitere Wellen gehen an die nächsten Personen und stoppen von selbst, sobald alle Schichten gedeckt sind oder niemand mehr übrig ist.“

Der Zahlen-Kasten rechts zeigt „Sendet jetzt an **N** Personen · M weitere in späteren Wellen“. Die Sicherheitsabfrage nennt beide Zahlen.

### 2.3 Reihenfolge und Obergrenze
`DispoJobRecipientPlanner::plan()` sortiert die Empfänger nach Einsätzen im Monat aufsteigend (wenigste zuerst), dann Anker-ID. `$auswahl['max']` (int|null) kappt die Empfängerliste nach den Töpfen. Wer herausfällt, zählt im neuen Topf `spaeter` („kommt in einer späteren Welle dran“) — kein Ausschluss, nur Reihenfolge. Rückgabe zusätzlich `rest` = Anzahl hinter der Obergrenze.

### 2.4 Automatische Folgewellen
`rec_dispo_job_requests.welle_max` (int, null) und `welle_abstand_minuten` (int, null). `rec_dispo_job_waves.auto` (bool).

Am Ende von `SendDispoJobWave::handle()` (nach `finished_at`): Wenn `welle_abstand_minuten` gesetzt, die Anfrage offen, `rest > 0` und mindestens eine Schicht noch nicht gedeckt (§4) → nächste Welle anlegen (`nr + 1`, gleiche Filter aus dieser Welle, `auto = true`, `scheduled_for = now + Abstand`) und mit Verzögerung in die Warteschlange (`DispoJobAnfrageAnlegen::starten`). Sonst keine weitere Welle. Reines Rechnen in `DispoJobFolgewelle::entscheiden(...)` (Unit-Test), der Job ruft nur auf.

Beim Start einer automatischen Welle prüft der Job wie heute: Anfrage offen, Welle nicht abgebrochen; zusätzlich: sind alle Schichten gedeckt → Welle schließen mit Zusammenfassung `gestoppt: 'bedarf_gedeckt'`, nichts senden, keine weitere Welle. Keine Empfänger mehr → `gestoppt: 'niemand_uebrig'`.

Auf der Anfrageseite: Wellenliste zeigt bei `auto` „· automatisch“; eine wartende automatische Welle hat wie heute „Abbrechen“. Neuer Knopf oben neben „Neue Welle“: **„Automatische Wellen stoppen“** (sichtbar nur, wenn `welle_abstand_minuten` gesetzt): setzt es auf null und löscht die wartende automatische Welle. Hinweistext „Keine weiteren automatischen Wellen. Du kannst jederzeit von Hand eine neue Welle starten.“ „Abschließen“ stoppt wie bisher alles.

„Neue Welle“ von Hand nimmt dieselbe Obergrenze (vorbelegt aus der Anfrage, änderbar) und zeigt keine Abstandsauswahl; sie ändert den Automatik-Plan nicht.

### 2.5 Mitarbeiterseite
Steht an der Anfrage ein Abstand und ist die nächste Welle geplant, zeigt die Karte: „Bitte antworte bis {Zeitpunkt}“ (Format „Di 12:30 Uhr“ bzw. „Do 28.10., 12:30 Uhr“ wenn nicht heute/morgen). Zusagen gehen auch danach (Markus: „wie im Chat“).

## 3. Gesellschaft je Filiale (Mail 27.10.)
- `DispoJobKandidaten::blockOptionen()` liefert je Block zusätzlich `firmen` = `['RG' => ['aktiv' => n, 'inaktiv' => m], 'MA' => [...]]`. Anzeige in der Filialzeile: „MGL · RG 332 · MA 47“ und darunter grau „inaktiv: RG 650 · MA 55 (werden nicht angeschrieben)“. Die alte Gesamtzahl entfällt.
- Kandidat trägt `firmen` (list, z. B. `['RG','MA']`, aus `company` aller aktiven Anstellungen der Person; leer/unbekannt → `['RG']`, weil der Export-Default RG ist).
- Neuer Filter **„Gesellschaft“** in der Filial-Karte unter den Blöcken, zwei Häkchen „RG“ und „MA“, Teil der Grundmenge wie Status: `$auswahl['firmen']` (list|null, null = alte Welle = beide, [] = niemand → Prüfung „Bitte wähle mindestens eine Gesellschaft.“). Eine Person ist dabei, wenn eine ihrer Anstellungen in einer gewählten Gesellschaft ist.
- Vorbelegung: beide angehakt. Beim Einstieg über eine Veranstaltung: nur die Gesellschaft der Veranstaltung, wenn `rec_dispo_events.einsatzfirma` sie erkennen lässt (`DispoGesellschaft::ausEinsatzfirma(?string): ?string` — Text enthält „RG“/„Rhein“ → RG, „MA“ → MA, sonst null = beide). Die echten Werte der Spalte werden vor dem Merge auf Prod geprüft; die Erkennung wird dann angepasst.
- Welle speichert `firmen` (json, null = beide). Wellenliste zeigt „· RG“ / „· MA“ / nichts bei beiden.

## 4. „Personalbedarf gedeckt“ je Schicht (Folie 5)
- Gedeckt = Zusagen ≥ gesucht **oder** von Hand gesetzt. Gilt in beiden Modi.
- `rec_dispo_job_shifts.gedeckt_manuell_at` (timestamp, null). Setzen setzt auch `closed_at` (Seite zeigt „schon voll“); Wiederöffnen leert beide. Im Modus „zuerst“ bleibt der automatische `closed_at` wie heute.
- Schichtkopf auf der Anfrageseite: Chip „gedeckt“ (grün) statt der Farblogik nur über `zugesagt ≥ gesucht`; daneben ein kleiner Knopf „als gedeckt markieren“ bzw. „wieder öffnen“ (bei manuell gedeckt). Text am Chip bei manuell: „gedeckt (von Hand)“.
- Wellen (von Hand und automatisch) bieten nur Schichten an, die nicht gedeckt sind. Sind alle gedeckt, sendet eine Welle niemandem (Topf `alle_tage_gebucht` bleibt; neuer Grund in der Zusammenfassung „alle Schichten gedeckt“) — auch im Modus „sammeln“ (Markus: „eindämmen“). Die Seite nimmt im Modus „sammeln“ für schon Angeschriebene weiter Zusagen an.

## 5. „Kann nicht“ — nur bei Anlass Dringend (Folie 1, Runde 2)
- Nur Schichten einer Anfrage mit `anlass = dringend` zeigen unter „Ich bin dabei“ einen kleinen grauen Textknopf **„Kann nicht“**. Woche/Vierwochen: nichts.
- Klick öffnet darunter (ohne Seitenwechsel): „Wir zählen auf dich. Bist du sicher, dass du nicht kannst?“ Darunter, wenn es nicht die erste ist: „Das wäre deine {n}. Absage in den letzten 60 Tagen.“ Dann Gründe als Chips (Pflicht, einer): `krank` · `anderer Job` · `privat verhindert` · `Uni/Schule` · `sonstiges`; optionales Textfeld „Magst du uns kurz sagen, warum?“ (max 200 Zeichen); Knöpfe „Ja, ich kann nicht“ und „Doch, ich bin dabei“ (= Zusage wie der große Knopf).
- Speichern: `rec_dispo_job_offer_shifts.declined_at`, `declined_reason` (string 32), `declined_note` (string 200). Eine Absage gilt je Schicht. Abgesagte Schicht zeigt „Du hast abgesagt“ ohne Knöpfe; ein Umschwenken geht über den Chat (wie bisher bei Zusagen).
- Zähler: Absagen der Person (alle Anstellungen) mit `declined_at` in den letzten 60 Tagen, über alle Anfragen; `DispoJobAbsagen::anzahl(employeeIds, seit)`.
- Sichtbar für alle (Markus): (a) Anfrageseite je Schicht ein eingeklappter Block „N Absagen“ mit Name, Grund, Notiz, Zeit; (b) Entscheidungshilfe `DispoJobZusagenInfo` bekommt `absagen_60` (int), angezeigt als Spalte „Absagen (60 T.)“; (c) Mitarbeiterakte, Reiter Dispo-Einsätze: Kasten „Absagen auf Jobanfragen (60 Tage): N“ mit Liste (Datum, Veranstaltung, Grund). HR sieht die Akte ohnehin.
- Eine Absage ändert am Versand nichts (keine Erinnerungen mehr); sie nimmt die Person aber aus dem Topf „schon angeschrieben“ nicht heraus — sie wird in dieser Anfrage nicht erneut angeschrieben.

## 6. Einmal pro Tag (Folie 5, Markus: „nur 1x für einen Tag“)
- Hat die Person (alle Anstellungen) für einen Tag schon eine Zusage (`accepted_at`, beliebige Anfrage, Schicht nicht abgesagt/gelöscht), zeigt die Seite die anderen Schichten dieses Tages ohne Knopf mit „Du hast für diesen Tag schon zugesagt“. Die zugesagte bleibt mit „✓ Du bist dabei“.
- Serverseitig lehnt `JobOffersZusage::ausfuehren()` ab mit Ergebnis `tag_belegt` („Du hast für diesen Tag schon zugesagt.“) — innerhalb der bestehenden Transaktion, Zählung mit Sperre wie die Vollzählung.
- Dieselbe Schicht aus zwei Anfragen (§9 der alten Spec) bleibt eine Zusage und ist kein Verstoß.

## 7. Dringend = eine Veranstaltung, ein Tag (Folie 1, Runde 1)
- `DispoJobCreateState`: bei `anlass = dringend` genau eine Veranstaltung und genau ein Tag mit gesucht > 0, sonst „Bei „Dringend“ geht es um eine Veranstaltung an einem Tag. Für mehrere nimm „Nächste Woche“.“ Prüfung in Schritt 3 (der Anlass wird dort gewählt).
- Nachricht: `DispoJobOfferSender` füllt im Body zusätzlich die benannte Variable `{{datum}}`, wenn die Vorlage sie trägt: frühester Tag der angebotenen Schichten, Format „Sa, 31.10.“. Hinweis an den User für die Meta-Vorlage: benannte Variablen `{{name}}` und `{{datum}}` verwenden, nicht `{{1}}`/`{{2}}`.

## 8. Mitarbeiterseite — Text je Anlass (Folien 1–3)
Je Veranstaltungskarte nach Anlass der Anfrage (bei gebündelten Schichten mehrerer Anfragen gewinnt Dringend > Woche > Vierwochen):
- Dringend: Kicker „Wir zählen auf dich!“, Satz „Für diese Veranstaltung brauchen wir dringend Unterstützung.“ + ggf. „Bitte antworte bis …“ (§2.5).
- Nächste Woche: Kicker „Hier brauchen wir deine Unterstützung!“, Satz „Melde dich für alle Tage, an denen du dabei sein kannst.“ Fußnote „Eine Meldung ist noch keine bestätigte Einteilung.“
- Vier Wochen: Kicker „Sichere dir frühzeitig deine Einsätze!“, Satz „Wer zuerst reagiert, hat bessere Chancen auf passende Schichten.“ Fußnote „Keine Garantie auf eine Einteilung.“
Der Seitenkopf „Hallo {Name}“ bleibt.

## 9. Migration
`2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php`: requests `welle_max` (unsignedInteger null), `welle_abstand_minuten` (unsignedInteger null); waves `firmen` (json null), `auto` (bool default false); shifts `gedeckt_manuell_at` (timestamp null); offer_shifts `declined_at` (timestamp null), `declined_reason` (string 32 null), `declined_note` (string 200 null).

## 10. Auslieferung
Deploy mit `migrate`, `view:clear`, `queue:restart` (Job liest neue Spalten und kettet Wellen). Danach Sichttest: Anfrage über eine Veranstaltung mit Dringend (eine VA, Gesellschaft vorbelegt, 20/6 Std), Testversand an eigene Nummer, „Kann nicht“ durchklicken, Haken „gedeckt“, „Automatische Wellen stoppen“. Meta-Vorlagen mit `{{name}}`/`{{datum}}` reicht der User ein; bis dahin laufen die heutigen Texte (ohne Datum: die Variable wird nur gefüllt, wenn die Vorlage sie trägt).

## 11. Tests
Planer: Sortierung, Obergrenze + Topf `spaeter` + `rest`, Firmen-Filter als Grundmenge, null/[]-Semantik. Folgewelle: `entscheiden()` alle Fälle. Kandidaten/blockOptionen: Firmen je Block, Person mit RG+MA in beiden Zahlen. CreateState: Dringend-Regel, Gesellschaft leer. Zusage: `tag_belegt`, Absage speichert und sperrt, Absage nur bei Dringend (Komponente). SendDispoJobWave: Auto-Welle wird angelegt/gestoppt (gedeckt, niemand übrig, Abstand null, Anfrage geschlossen). Sender: `{{datum}}` nur bei Vorlage mit Variable. ZusagenInfo: `absagen_60`. Blade-Check beider Views + Jobseite.

## 12. Nachträge aus der Umsetzung (27.10.)
- Der Topf heißt `spaeter` und zählt als Topf („kommt in einer späteren Welle dran“); `rest` steht zusätzlich in der Wellen-Zusammenfassung.
- Welle speichert keine eigene Obergrenze; „Neue Welle“ von Hand schreibt eine geänderte Obergrenze an die Anfrage (`welle_max`), damit automatische Folgewellen sie übernehmen.
- Gestoppte Wellen zeigen den Grund als grünen Chip in der Wellenliste („Bedarf gedeckt — keine weiteren Wellen“ / „Niemand mehr übrig — keine weiteren Wellen“).
- `{{datum}}` ohne Tag liefert „bald“ statt leer (Meta lehnt leere Parameter ab).
- Absage: zweiter Grund bei bereits abgesagter Schicht wird ignoriert (erster bleibt). Hinweis bei Absage nach Zusage: „Du hast für diese Schicht schon zugesagt. Wenn du doch nicht kannst, melde dich bitte im Chat.“
- Vorbelegung Gesellschaft aus `einsatzfirma`: „MA“ nur als eigenes Wort (nicht „Maritim“), „RG“ als Wort oder Text mit „Rhein“; sonst beide. Echte Werte auf Prod vor dem Merge prüfen.
- Review 27.10.: Es gibt nie zwei Automatik-Ketten — eine Folgewelle entsteht nur, wenn keine Welle der Anfrage mehr wartet; nicht nach einer Welle, die nichts senden konnte (sent 0, Fehler > 0). Eine automatische Welle, deren Automatik inzwischen gestoppt wurde, sendet nicht („Automatik gestoppt — nicht gesendet“). Abgesagte Schicht kann nicht mehr zugesagt werden (zurück nur über den Chat). Absage nur bei laufender Anfrage und künftigem Tag. „Bitte antworte bis“ nur für Wellen in der Zukunft. Bewusst offen: gebündelte Schwester-Schichten (Dringend + Woche) zeigen die Vertreterin — ist sie abgesagt, fehlt der Knopf der Wochen-Zeile; „mit passender Tätigkeit“-Zahl ist mit der Obergrenze gekappt; leeres Feld „höchstens“ bei „Neue Welle“ setzt die Obergrenze der Anfrage auf „alle“.

