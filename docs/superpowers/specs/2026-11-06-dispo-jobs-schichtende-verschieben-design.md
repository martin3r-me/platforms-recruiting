# Jobs anbieten: Schichtende im Schlüssel, Zusage verschieben, Abgleich nach Schicht

Stand 06.11.2026. Anlass: drei Mails Markus 06.11. zu Anfrage 16 (Mc Kinsey 13.11., Schichten 16–01, 17–03, 17–23) und Anfrage 13.

## 0. Befund (Prod 06.11.)

- Anfrage 16: drei Schichten um 13:25:51 zusammen angelegt, Welle 1 an 292 Personen. Schicht 17–03 (Posten 125140) und 17–23 (ohne Posten, „andere Schicht“) hatten identisch 12 Zusagen derselben Personen zur selben Sekunde. Ursache: `DispoJobRecipientPlanner::schichtSchluessel` = VA | Tag | Beginn | Tätigkeit, **ohne Ende**. Damit galten beide als eine Schicht: die Mitarbeiterseite bündelte sie zu einer Zeile (Markus 3c: „im Link nur 16–01 und 17–23“), und `JobOffersZusage` sagte die „Schwester“ mit zu (Markus Mail 3: „komisch erfasst“).
- Anfrage 13: vier Zusagen „an ZAS übergeben, noch nicht angekommen“ (Alothman, Abesolom, Souiri, Boukeng Tiofack) wurden korrekt exportiert (05.11. 15:17 bzw. 06.11. 11:13), ZAS hat sie nicht gebucht; alle ohne laufenden Vertrag. Nicht unser Fehler, Frage an Olaf. Access-Fehler 3704 kam aus seinem Programm, keine Zeile verloren (06.11. 11:13: 11 von 12 angekommen).
- Nebenbefund: `DispoJobZasAbgleich` verknüpfte nach Person + VA + Tag, nicht nach Schicht. Alsaghir stand grün auf 17:30–04:00, in ZAS aber auf 17–03; Dinzala/Saju grün auf 17–03, in ZAS inzwischen auf 17–23 (Sheran bucht in ZAS um).

## 1. Schichtende im Schlüssel

`schichtSchluessel($event, $datum, $von, $bis, $taetigkeit)` — Ende ist Pflichtparameter, damit keine Stelle ihn vergisst. Alle Nutzer nachgezogen: Planer (`$s['bis'] ?? ''`), `DispoJobSchonZugesagt` (lädt `bis`), `DispoJobGedeckt::offeneSchichten` (liefert `bis`), Portal `JobOffers` (Zeilen tragen `bis`), `JobOffersBuendelung`, `JobOffersSchwestern`, `JobOffersZusage`. Wirkung: 17–23 und 17–03 erscheinen beide auf der Seite; Zusage auf eine, die andere meldet „für diesen Tag schon zugesagt“ (§6 einmal pro Tag). Dieselbe Schicht inklusive Ende aus zwei Anfragen wird weiterhin mitzugesagt (Spec Runde 1 §9).

## 2. Zusage verschieben (Mail 2)

`DispoJobZusageVerschieben::ausfuehren($request, $offerShiftId, $zielShiftId)` → `ok | weg | in_zas | ziel | schon`. Erlaubt nur, wenn die Zusage weder an ZAS übergeben (`zas_exported_at`) noch angekommen (`zas_assignment_id`) ist; Ziel = andere Schicht **derselben Anfrage, derselben VA, desselben Tags**. Die Zusage wandert mit ihrem Zeitpunkt; der Haken „in ZAS eingetragen“ bleibt nicht (neue Schicht ist in ZAS noch nicht eingetragen). Fehlt der Person die Angebots-Zeile der Zielschicht, wird sie angelegt. Oberfläche: Auswahl „Verschieben nach …“ in der Zusage-Zeile (`Show::verschieben`, Computed `verschiebeZiele`), nur sichtbar wenn erlaubt und Ziele existieren. Nach Übergabe an ZAS: in ZAS umbuchen, die nächste Lieferung zieht nach (§3).

## 3. Abgleich und Anzeige nach Schicht

- `DispoJobZasAbgleich`: Verknüpfung verlangt zusätzlich gleichen Beginn und gleiches Ende, sofern beide Seiten Werte haben (`zeitPasst`, Uhrzeit normalisiert `17:00:00`/`7:00` → `17:00`/`07:00`). Alte Fixtures ohne Zeiten matchen wie bisher.
- `DispoJobZasStand::andereSchicht($zeilen, $schichten)`: je Zusage-Zeile die Schicht, in der ZAS die Person stattdessen führt („17:00–03:00“), auch für alt verknüpfte Zeilen. `DispoJobZasUebergabe::hinweis(..., $andereZeit)`: „✓ in ZAS, dort 17:00–03:00“ (verknüpft, aber Zeit anders) bzw. „in ZAS auf 17:00–03:00 · hier nicht“ (nicht verknüpft), Art `anders`, gelb.
- Bestehende falsche Verknüpfungen werden nicht umgehängt, nur sichtbar gemacht.

## 4. Einmalige Bereinigung Anfrage 16

`recruiting:dispo-zusagen-entdoppeln {requestId} --behalten={shiftId} [--ausfuehren]`. Gruppe = Person + VA + Tag + Beginn + Tätigkeit (alter Schlüssel). Es bleibt: die Zeile, die schon in ZAS ist (übergeben, Haken oder angekommen); sonst die auf `--behalten`; sonst nichts (Warnung, unverändert). Entfernte Zeilen verlieren `accepted_at` und den Haken, bleiben als Angebot. Ohne `--ausfuehren` Vorschau. Für Anfrage 16: `--behalten` = Schicht 17–23 (#87, stand im Link), Vorschau zuerst an Sheran.

## 5. Deploy

Keine Migration, `view:clear`. Danach: Vorschau `recruiting:dispo-zusagen-entdoppeln 16 --behalten=87`, mit Sheran abstimmen, dann `--ausfuehren`.

## 6. Tests

`DispoJobSchichtendeTest` (Zusage ohne Mit-Zusage + einmal pro Tag, §9 bleibt, Abgleich nach Zeit und Altfall, ZAS-Stand inkl. alt verknüpft, Verschieben: Haken, Zeile anlegen, Verweigerungen, Ziele; Bereinigung mit allen drei Regeln, Vorschau, fremde Schicht), `DispoJobRecipientPlannerTest`/`JobOffersBuendelungTest` (Schlüssel mit Ende).
