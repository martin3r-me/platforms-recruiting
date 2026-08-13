# Live-Checkliste Schulungszertifikat — am Stück abzuarbeiten

**Stand:** 2026-08-13, Branch `feat/schulungszertifikat`, Suite `OK (746 tests, 2239 assertions)` / 0 Errors.
**Zweck:** alles, was nach dem Deploy zu klicken oder zu prüfen ist, an einer Stelle. Gesammelt über den ganzen Durchlauf; die Einzelbegründungen stehen in Spec und Plan, hier steht nur, was zu tun ist und woran man es sieht.

**Warum es diese Liste gibt:** das Modul hat kein Laravel im Testlauf. Livewire-Komponenten sind nicht instanziierbar, Blades werden nur an einer Stelle gerendert, HTTP-Pfade gar nicht. Die Punkte hier sind deshalb nicht Nachlässigkeit, sondern strukturell nicht testbar — jeder einzelne ist im Report des jeweiligen Tasks als solcher benannt.

---

## A — Vor dem ersten Klick (Deploy-Reihenfolge)

- [ ] **A1 · Migrationen zuerst pushen**, Feature danach. Task 10 bringt eine öffentlich erreichbare Route, die ohne `rec_training_certificates` 500er wirft. Zwei Pushes, nicht einer.
- [ ] **A2 · `composer.lock`-Bump in `meingedeck`** — ohne ihn ist nichts live.
- [ ] **Kein `queue:restart`** nötig: kein Worker-Code im Paket. (Nichts zu tun, nur damit die Frage nicht offen bleibt.)
- [ ] **A3 · Assets im installierten Paket prüfen:**
      `ls -la meingedeck/vendor/martin3r/platform-recruiting/resources/fonts meingedeck/vendor/martin3r/platform-recruiting/resources/images/certificates`
      Erwartet: `Oswald-SemiBold.ttf` + `OFL.txt`, und drei PNGs.
      **Fehlen sie, gibt es ein PDF ohne Bilder in Helvetica — keinen Fehler, nur ein falsches Dokument.**
- [ ] **A4 · WhatsApp-Template bei Meta einreichen und genehmigen lassen** — mit **Body-Variable** `{{zertifikat_link}}`, **kein URL-Button**. Ein Template ohne diese Variable wird vom Guard abgelehnt (siehe D3).
- [ ] **A5 · Original-Assets von RheinGedeck** besorgen und die aus dem Scan freigestellten ersetzen (Logo, Unterschrift). Kosmetisch, aber vor dem ersten echten Bewerber besser.

## B — Der Schalter (ohne ihn passiert nichts)

- [ ] **B1 · `issue_training_certificates` einschalten.** Bewerber-Einstellungen → Modal → erste Zeile der Zertifikat-Gruppe. **Default ist `false`**, das Feature ist nach dem Deploy also aus.
- [ ] **B2 · Nachsehen, dass der Wert wirklich in der Spalte landet — der einzige Punkt, an dem das ganze Feature hängt.**
      `SELECT settings FROM rec_applicant_settings WHERE team_id = <TEAM>;` **vor und nach** dem Speichern.
      Erwartet: `"issue_training_certificates":true` steht danach roh im JSON.
      **Grund:** `getSetting()` liest `$settings[$key] ?? $default ?? DEFAULT_SETTINGS[$key]`. Bei einer bestehenden Zeile ohne den Schlüssel trägt allein der Default — schreibt das Formular nicht, bleibt das Feature für immer aus **und der Default verdeckt das.** Gemessen ist der Weg bis `save()`; **nicht** messbar war allein Livewires Hydration `wire:model` → Property, und genau die prüft dieser Punkt.
- [ ] **B3 · `training_certificate_wa_template_id` setzen** (dasselbe Modal, direkt darunter).

## C — Das PDF (sieben strukturell untestbare Punkte des Controllers)

Route: `/recruiting/zertifikat/{uuid}`, `uuid` aus `rec_training_certificates`.

- [ ] **C1 · Die Route antwortet überhaupt** → PDF, kein 500.
      Schlägt es fehl: `storage/fonts` nicht anlegbar. Die Fehlermeldung nennt Pfad und Grund. **Das Verzeichnis liegt nicht im Git und fehlt auf jedem neu aufgesetzten Server erneut** — der Code legt es an, aber wenn die Rechte fehlen, sagt er es laut.
- [ ] **C2 · Das PDF wird im Browser ANGEZEIGT**, kein Download-Dialog (`->stream()`, nicht `->download()`). Sonst zwingt der WhatsApp-Link zum Download, und auf Mobilgeräten sehen Bewerber nichts.
- [ ] **C3 · Die Schrift ist wirklich Oswald** — Überschriften schmal und hoch, nicht Helvetica-breit. Im Zweifel PDF-Eigenschaften → eingebettete Fonts. Ein stiller Helvetica-Fallback ist kein Fehler, nur ein anderes Dokument.
- [ ] **C4 · Die drei Bilder sind da:** Logo oben, „ZERTIFIKAT"-Schriftzug, Unterschriftsblock unten links.
- [ ] **C5 · Ins Log sehen, nicht nur aufs PDF.** Nach dem ersten Aufruf: keine `warning` mit `missing`. Ein fehlendes Bild rendert das PDF **ohne Fehler** — das Log ist der einzige Kanal.
- [ ] **C6 · Unbekannte uuid → 404**, keine Fehlerseite.
- [ ] **C7 · Genau eine Seite.** Bei der ausgelieferten Kenntnisliste (sechs Zeilen) sicher; die gemessene Obergrenze ist **elf** Zeilen, ab zwölf sind es zwei Seiten.

## D — Ausstellung und Zustellung, Weg (a): Ablehnung am HR-Schreibtisch

- [ ] **D1 · Checkbox erscheint nur bei vorhandener `attended`-Buchung**, ist **nicht vorausgewählt**, und gilt für **jeden** Ablehnungsgrund.
- [ ] **D2 · Ohne Haken läuft die Ablehnung wie immer** — kein Zertifikat, kein Versand. (Im Test über das Query-Protokoll abgesichert, hier nur der Sichtcheck.)
- [ ] **D3 · Mit Haken:** Zertifikat entsteht, WhatsApp geht raus, **und der Link in der Nachricht ist die Zertifikat-URL** — nicht der Bewerber-Token.
      **Prüf den Link in der echten Nachricht.** Er muss auf `/recruiting/zertifikat/<uuid>` zeigen.
- [ ] **D4 · Ein Template ohne `{{zertifikat_link}}` wird abgelehnt.** Testweise ein falsches Template einstellen: es darf **kein** Versand stattfinden und `wa_sent_at` leer bleiben. Ohne diesen Guard füllt der Builder den **Beispieltext** ein — der Send gelingt, und der Bewerber bekommt eine Nachricht ohne Link.
- [ ] **D5 · Sind Jugendschutz-Absage und Zertifikat beide angehakt, gehen ZWEI Nachrichten raus.** Bewusst nicht zusammengelegt; die UI weist darauf hin.
- [ ] **D6 · Scheitert der Versand, bleibt die Ablehnung bestehen** und `wa_sent_at` leer. Die Meldung an HR („PDF herunterladen und von Hand verschicken") muss **sichtbar** sein — sie läuft über `session('message')`, nicht über `flash('error')`.

## E — Ausstellung, Weg (b): Mitarbeiter-Anlage

- [ ] **E1 · Mitarbeiter mit `attended`-Buchung anlegen → Zertifikat entsteht automatisch**, ohne WhatsApp-Versand (Weg (b) verschickt nichts, das Portal zeigt es).
- [ ] **E2 · Direkteinstellung ohne Schulung → kein Zertifikat**, und die Anlage läuft normal.
- [ ] **E3 · Bei ausgeschaltetem Schalter läuft die Anlage unverändert** — genau ein Query mehr als vorher, sonst nichts.
- [ ] **E4 · Scheitert die Ausstellung, ist der Mitarbeiter trotzdem angelegt**, und im Log steht eine `error`-Zeile mit eigenem Marker und der Applicant-ID.

## F — Beide Portale

- [ ] **F1 · MA-Portal:** die Zertifikat-Zeile steht bei den Verträgen, mit **„Ausgestellt am …"** — **nicht** „Unterschrieben am …". Kein Unterschreiben-Button, PDF-Button vorhanden.
- [ ] **F2 · Bewerber-Portal, der eigentliche Fall:** ein **abgelehnter Bewerber ohne Vertrag, mit Zertifikat** sieht die Liste — **nicht** „Keine Verträge". (Die `state`-Zeile zählt Zertifikate mit.)
- [ ] **F3 · Bewerber ohne alles** sieht weiterhin den leeren Zustand.
- [ ] **F4 · Bestandsfall:** ein Mitarbeiter mit Verträgen und ohne Zertifikat sieht seine Seite unverändert.
- [ ] **F5 · Das Icon rendert** (`heroicon-o-academic-cap`) — kein Test rendert die Direktive.

---

## Bekannt, benannt, nicht in diesem Paket behoben

Kein Handlungsbedarf beim Deploy, aber es soll nicht als Überraschung kommen:

- **`contract.extra_field.vertragsbeginn` / `vertragsende` rendern als ISO-Datum** (`2026-08-01` statt `01.08.2026`) in **9 von 11 Vertragsvorlagen**, auf denen zusammen 205 Verträge hängen. Bestandsdefekt, nicht von diesem Paket verursacht, per Pin-Test festgenagelt wie er ist. **Ein Blick in ein bestehendes Vertrags-PDF klärt in 30 Sekunden, ob es wirklich so aussieht** — der letzte Schritt war über keinen Lesepfad messbar.
- **Die Suite ist nur unter der Default-Reihenfolge verlässlich grün.** Vier verschiedene geteilte Statics, Tabelle in der Spec. Eigenes Ticket; `phpunit.xml` setzt bewusst kein `executionOrder`.
- **`tools/blade-check.php` erkennt einen nicht geschlossenen `{{--` nicht.** Real verschluckt er alles bis zum nächsten `--}}` — in Task 18 wären das 4084 Zeichen und drei Formularelemente gewesen, still. Der neue Test fängt es für diese Datei, für andere ist es ungedeckt.
- **`save():258` im Einstellungs-Modal setzt `addError()` auf eine rohe Checkbox ohne `@error`-Stelle** — die Meldung ist unsichtbar, das Modal bleibt stumm offen. Vorbestehend.
- **`storage/fonts` liegt nicht im Git.** Auf einem neu aufgesetzten Server legt der Code es an; scheitert das, gibt es einen sprechenden Fehler statt eines Fatals.

## Rückweg, falls HR den Text doch selbst ändern soll

Der Zertifikat-Inhalt ist festes HTML im Code (Zuschnitt v3). Was der Rückweg kostet und was dafür schon vorbereitet ist, steht in der Spec unter „Aufgegeben mit dem Zuschnitt v3". Die nicht ausgeführte Guard-Analyse liegt in `docs/zertifikat/guard-landkarte-511451c.md` — 61 Zeilen, drei Greps, mit dem Kommando in Zeile 1 nachfahrbar.
