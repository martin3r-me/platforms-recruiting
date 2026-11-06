# Jobs anbieten: Schichtende im Schlüssel, Zusage verschieben, Abgleich nach Schicht

Stand 06.11.2026. Anlass: drei Mails Markus 06.11. zu Anfrage 16 (Mc Kinsey 13.11., Schichten 16–01, 17–03, 17–23) und Anfrage 13.

## 0. Befund (Prod 06.11.)

- Anfrage 16: drei Schichten um 13:25:51 zusammen angelegt, Welle 1 an 292 Personen. Schicht 17–03 (Posten 125140) und 17–23 (ohne Posten, „andere Schicht“) hatten identisch 12 Zusagen derselben Personen zur selben Sekunde. Ursache: `DispoJobRecipientPlanner::schichtSchluessel` = VA | Tag | Beginn | Tätigkeit, **ohne Ende**. Damit galten beide als eine Schicht: die Mitarbeiterseite bündelte sie zu einer Zeile (Markus 3c: „im Link nur 16–01 und 17–23“), und `JobOffersZusage` sagte die „Schwester“ mit zu (Markus Mail 3: „komisch erfasst“).
- Anfrage 13: vier Zusagen „an ZAS übergeben, noch nicht angekommen“ (Alothman, Abesolom, Souiri, Boukeng Tiofack) wurden korrekt exportiert (05.11. 15:17 bzw. 06.11. 11:13), ZAS hat sie nicht gebucht; alle ohne laufenden Vertrag. Nicht unser Fehler, Frage an Olaf. Access-Fehler 3704 kam aus seinem Programm, keine Zeile verloren (06.11. 11:13: 11 von 12 angekommen).
- Nebenbefund: `DispoJobZasAbgleich` verknüpfte nach Person + VA + Tag, nicht nach Schicht. Alsaghir stand grün auf 17:30–04:00, in ZAS aber auf 17–03; Dinzala/Saju grün auf 17–03, in ZAS inzwischen auf 17–23 (Sheran bucht in ZAS um).

## 1. Schichtende im Schlüssel

`schichtSchluessel($event, $datum, $von, $bis, $taetigkeit)` — Ende ist Pflichtparameter, damit keine Stelle ihn vergisst. Alle Nutzer nachgezogen: Planer (`$s['bis'] ?? ''`), `DispoJobSchonZugesagt` (lädt `bis`), `DispoJobGedeckt::offeneSchichten` (liefert `bis`), Portal `JobOffers` (Zeilen tragen `bis`), `JobOffersBuendelung`, `JobOffersSchwestern`, `JobOffersZusage`. Wirkung: 17–23 und 17–03 erscheinen beide auf der Seite; Zusage auf eine, die andere meldet „für diesen Tag schon zugesagt“ (§6 einmal pro Tag). Dieselbe Schicht inklusive Ende aus zwei Anfragen wird weiterhin mitzugesagt (Spec Runde 1 §9).

## 2. Zusage verschieben (Mail 2)

`DispoJobZusageVerschieben::ausfuehren($request, $offerShiftId, $zielShiftId)` → `ok | weg | in_zas | ziel | schon | voll | abgesagt | schwester`. Erlaubt nur, wenn die Zusage noch nicht in ZAS ist: nicht übergeben (`zas_exported_at`), kein Hand-Haken (`zas_entered_at`), nicht angekommen (`zas_assignment_id`) — Review Fund 6: der Haken bedeutet „steht in ZAS“, dann dort umbuchen (oder erst den Haken entfernen). Ziel = andere Schicht **derselben Anfrage, derselben VA, desselben Tags**, nicht voll/gedeckt (`closed_at`/`gedeckt_manuell_at`, Fund 2), von der Person nicht abgesagt (Fund 7), noch nicht zugesagt. Verweigert, wenn dieselbe Schicht (Schlüssel inkl. Ende) in einer anderen laufenden Anfrage mitzugesagt ist (§9-Schwester, Fund 1) — sonst hätte die Person zwei Zusagen an einem Tag. Die Zusage wandert mit ihrem Zeitpunkt. Fehlt der Person die Angebots-Zeile der Zielschicht, wird sie angelegt. Danach `DispoJobGedeckt::nachzaehlen` an Quelle und Ziel: im Modus „zuerst“ schließt das Ziel bei erreichtem Soll, die Quelle öffnet wieder (außer von Hand gedeckt). Oberfläche: Auswahl „Verschieben nach …“ in der Zusage-Zeile (`Show::verschieben`, Computed `verschiebeZiele`), nur sichtbar wenn erlaubt und Ziele existieren; das Select springt nach der Antwort auf den Platzhalter zurück (Fund 10).

## 3. Abgleich und Anzeige nach Schicht

- `DispoJobZasAbgleich`: Verknüpfung verlangt zusätzlich passende Zeit (`zeitPasst`, Uhrzeit normalisiert `17:00:00`/`7:00` → `17:00`/`07:00`), sofern beide Seiten Werte haben. Passend ist die eigene Zeit der Schicht **oder jede Zeitvariante des ZAS-Postens** (`zeitvarianten()` aus `rec_dispo_bedarfe.zeiten`, Fund 3: ZAS bucht die Person in irgendeiner Variante, die Schicht trägt nur die erste). Alte Fixtures ohne Zeiten matchen wie bisher.
- `DispoJobZasStand::andereSchicht($zeilen, $schichten)`: je Zusage-Zeile die Schicht, in der ZAS die Person stattdessen führt („17:00–03:00“), auch für alt verknüpfte Zeilen. `DispoJobZasUebergabe::hinweis(..., $andereZeit)`: „✓ in ZAS, dort 17:00–03:00“ (verknüpft, aber Zeit anders) bzw. „in ZAS auf 17:00–03:00 · hier nicht“ (nicht verknüpft), Art `anders`, gelb.
- Bestehende falsche Verknüpfungen werden nicht umgehängt, nur sichtbar gemacht.

## 4. Einmalige Bereinigung Anfrage 16

`recruiting:dispo-zusagen-entdoppeln {requestId} --behalten={shiftId} [--ausfuehren]`. Gruppe = Person + VA + Tag + Beginn + Tätigkeit (alter Schlüssel). Es bleibt: die Zeile, die schon in ZAS ist (übergeben, Haken oder angekommen); sonst die auf `--behalten`; sonst nichts (Warnung, unverändert). Entfernte Zeilen verlieren `accepted_at`, bleiben als Angebot; das Update ist bedingt (nur ohne ZAS-Marker, Transaktion — Fund 9) und zieht `DispoJobGedeckt::nachzaehlen` nach (Fund 2). Ohne `--ausfuehren` Vorschau. Für Anfrage 16 (Modus „sammeln“, kein `closed_at`-Thema): `--behalten` = Schicht 17–23 (#87, stand im Link).

Lauf am 06.11. (Prod): der erste Lauf entfernte nur 3 von 13, weil bei 10 Personen BEIDE Zeilen dieselbe `zas_assignment_id` trugen (der alte Abgleich nach Tag hatte beide verknüpft) und damit beide als „in ZAS“ galten; ZAS führte alle zehn auf 17–23. Die zehn wurden per Tinker bereinigt (Zusage auf #86 weg, Verknüpfung gelöst). Danach als Regel im Befehl: zeigen mehrere Zeilen einer Gruppe auf dieselbe ZAS-Buchung, bleibt nur die Zeile, deren Schicht (inkl. Posten-Zeitvarianten) zur Buchung passt; die anderen verlieren Zusage und Verknüpfung (bedingtes Update auf dieselbe `zas_assignment_id`). Passt keine oder passen alle, bleibt alles stehen.

Ebenfalls nachgezogen (Fund 4/5): Ende im Dedupe-Schlüssel von `DispoJobAnfrageAnlegen` (zwei „andere“ Schichten 17–23/17–03 überschrieben sich sonst still), im Options-Schlüssel von `DispoJobSchichten` (Personenzeilen ohne Posten: je Ende eine Option statt „häufigstes Ende“) und in `DispoJobSchichten::schluessel` (Eingabefelder im Wizard); `Create::zahlenRechnen` gibt `bis` mit, damit die Vorschau „schon zugesagt“ wie der Versand filtert. Nicht geändert: der DB-Unique-Index ohne `bis` (bei NULL-Posten erlaubt MySQL Duplikate; mit Posten sind zwei Schichten mit gleichem Beginn und gleichem Posten kein realer Fall). Altbestand mit leerem `bis` (vor 14.10.) liegt in der Vergangenheit.

## 5. Deploy

Keine Migration, `view:clear`. Danach: Vorschau `recruiting:dispo-zusagen-entdoppeln 16 --behalten=87`, mit Sheran abstimmen, dann `--ausfuehren`.

## 6. Tests

`DispoJobSchichtendeTest` (Zusage ohne Mit-Zusage + einmal pro Tag, §9 bleibt, Abgleich nach Zeit und Altfall, ZAS-Stand inkl. alt verknüpft, Verschieben: Haken, Zeile anlegen, Verweigerungen, Ziele; Bereinigung mit allen drei Regeln, Vorschau, fremde Schicht), `DispoJobRecipientPlannerTest`/`JobOffersBuendelungTest` (Schlüssel mit Ende).
