# Schulungszertifikat als festes HTML — Design

**Datum:** 2026-08-11 (Variante C, 2026-08-12) — **Revision v3, 2026-08-12: Zuschnitt geändert**
**Modul:** platforms-recruiting
**Status:** Entwurf, Gate 1 (Spec-Review) offen
Code-Referenzen gegen main **`511451c`** (v1 stand gegen `8718f10`)

> # Revision v3 — der Zuschnitt, und er ist maßgeblich
>
> **Das Zertifikat ist keine Vorlage mehr.** Der Inhalt steht als festes HTML in `src/Support/TrainingCertificateHtml.php`; es landet **keine Zeile** in `rec_contract_templates`. Alle Aussagen unten, die vom Gegenteil ausgehen, sind mit **[entfällt v3]** markiert oder tragen einen Verweis hierher. Wo v2 und v3 sich widersprechen, gilt v3.
>
> **Anlass:** Der Zuschnitt wurde in Frage gestellt, nachdem Tasks 0–6a gebaut waren. Das Dokument ist stumpf — festes Layout, **drei** variable Werte (Name, Schulungsdatum, Schulungsleiter), **eine** Schulungsart, ein Text, der sich praktisch nie ändert. Die Vorlagen-Infrastruktur trug also die Kosten einer Flexibilität, die niemand braucht.
>
> **Was der Zuschnitt beseitigt, nicht bewacht:**
>
> - **22 Guards in fremden Queries** (`docs/zertifikat/guard-landkarte-511451c.md`) — es gibt nichts zu filtern, wenn keine Zeile existiert. Die Datei bleibt als versionierte Analyse für den Rückweg, mit einem Vermerk oben.
> - **§B8 als einzelne Ausfallstelle für 12 dieser Guards.** Der erzwungene `code`-Präfix `ZERT-` war die einzige Garantie, dass `AV%`, `AT-%`, `AV-default` und `IFSG` eine Zertifikat-Zeile nicht erwischen. Kein Präfix nötig, weil keine Zeile.
> - **Ein Eingriff in `RecContractTemplate::resolveSource()`** — die Methode, deren Nachbarschaft in Task 6a gerade den ISO-Datums-Befund hergegeben hat. Der `schulung.`-Zweig entfällt.
>
> **Was bleibt und weiter gebraucht wird:** `TrainingLeaderResolver` (die drei variablen Werte müssen auch in festem HTML von irgendwo kommen — Schulungsdatum und Schulungsleiter aus der maßgeblichen Buchung, G10), die Ausstellung, die PDF-Route, beide Portale, der WhatsApp-Versand. Und alles aus Tasks 0–6a: nichts davon ist verloren.
>
> **Was aufgegeben wird**, ausdrücklich und mit Preis: siehe den Abschnitt **„Aufgegeben mit dem Zuschnitt v3"** in §Benannte Tradeoffs.

> **Löst den Zertifikat-Teil von `2026-08-05-schulungszertifikat-bewertbarkeit-design.md` ab.**
> Was von dort übernommen wird, steht namentlich in §Übernahme.
> §A jener Spec war schon überholt und ist inzwischen umgesetzt
> (`CreateEmployeeFromApplicantService.php:106`, `transferEvaluationToHrData`).
>
> **Eigene Vorfassung ebenfalls ersetzt.** Die erste Fassung dieses Dokuments
> (Scan als Vollflächen-Hintergrund, vier absolut positionierte Felder) wurde
> gebaut, gemessen und **verworfen** — Begründung und Messwerte in §E7. Das
> Zertifikat wird stattdessen wie jede andere Vorlage behandelt: HTML mit
> Feldern.

## Revision v2 — was sich geändert hat

Anlass: Verifikationsrunde vor der Planerstellung. Alle Änderungen sind im Text
mit **[v2]** markiert.

**Drei Architekturänderungen** (nicht Details):

1. **§E1** — Hülle wird eine Support-Klasse statt einer Blade, **und die
   DomPDF-Optionen kommen aus derselben Quelle**, die Controller und Test
   konsumieren. Grund: ohne geteilte Options-Quelle testet der Render-Test eine
   anders konfigurierte Engine als die ausgelieferte und ist grün ohne Aussage —
   der `isRemoteEnabled`-Unterschied im Prototyp wurde manuell gefunden, nicht
   strukturell verhindert. `training-certificate.blade.php` fällt damit aus der
   Dateiliste.
2. **§B8** — der `type`-Hook erzwingt zusätzlich den **code-Präfix `ZERT-`** für
   `type='certificate'`. Ein Hook, zwei Invarianten. Ersetzt die Konvention
   „Zertifikat-code darf nie `AV-*` oder `AT-140` sein", die keinen Guard hatte
   und den nächsten Seed-Command nicht überlebt hätte.
3. **§E8** — die Glyph-Prüfung wird eine **pure Funktion, im Editor aufrufbar**,
   nicht nur eine Test-Assertion. Schließt den benannten Tradeoff „prüft nur die
   ausgelieferte Vorlage, nicht spätere Bearbeitungen".

**Weitere Änderungen:** Zeilennummern auf `511451c`; §B7 als Support-Klasse
(neues Muster im Modul); Guard-Landkarte als eigenes Artefakt; §D-Begründung
korrigiert (§D1 bleibt); §F deckt beide Portale ab; Render-Test-Assertions
mechanisch neu; Settings-Select vom Risiko zum Kopiermuster herabgestuft;
gemessene Fontliste als SOLL eingefroren; neue Fakten G18–G24. (G24 in Task 0 korrigiert — die Times-Bold-Behauptung war ein Messfehler.)

**Was ausdrücklich NICHT geändert wurde:** §D1 (Zertifikat-UUID statt
Applicant-Token) — der Portal-Fund stützt sie, siehe §D6.

## Problem

Das Zertifikat existiert als Word-Datei (`Zertifikat.docx`) und ist Handarbeit:
Word öffnen, vier Felder tippen, PDF exportieren, verschicken. Im Modul gibt es
kein ausstellbares Dokument — alles, was „Bescheinigung" oder „Certificate"
heißt, sind Upload-Felder für Dokumente, die der Bewerber *liefert*
(`schulbescheinigung_file_id`, `erstbescheinigung_file_id`).

Zwei Personengruppen sollen es bekommen (Kundenentscheidung): Teilnehmer, die
am HR-Schreibtisch abgelehnt werden, weil ihre Dokumente nicht reichen — und
jeder Teilnehmer, der Mitarbeiter wird.

## Ziel (Produktentscheidungen — fix)

1. **Eine Zertifikat-Vorlage ist eine normale Vorlage**: HTML-Editor, Felder,
   Signatur-Schalter. Keine Koordinaten, keine Vorlagen-eigenen Assets (§E).
2. **Zwei Ausstellungswege:** Ablehnung am HR-Schreibtisch *und* automatisch bei
   der Mitarbeiter-Anlage (§C).
3. **In beiden Portalen steht es bei den Verträgen** — als eigene Zeile, nicht
   als `RecContract` (§F). **[v2]** (v1: „im MA-Portal")
4. **Zustellung per WhatsApp nur für Abgelehnte**, weil sie ihren Portallink
   nicht wiederfinden (§D6). Link als Body-Variable, nicht als URL-Button (§D).
   **[v2]** (v1-Begründung „weil nur die kein Portal haben" war falsch, G20)
5. Zertifikat-Vorlagen leben in `rec_contract_templates` mit `type`-
   Unterscheidung; Editor, Platzhalter-Engine und Verwaltungsseite werden
   mitbenutzt (§B, übernommen).
6. Ablage als eigene Tabelle mit Inhalts-Snapshot (§C, übernommen).

## Übernahme aus der Spec vom 2026-08-05

Unverändert gültig und hier nicht neu begründet:

- **§B1, §B3** `type`-Spalte auf `rec_contract_templates`
  (`string(20) NOT NULL DEFAULT 'contract'`, Werte `contract`/`certificate`),
  Typ-Dropdown und Badge im Editor.
- **§B4 nur im Prinzip, nicht in seiner Liste. [v2]** Die Guard-Landkarte jener
  Spec (dort F9 + Tabelle B4) stammt aus main `45f97d3` und ist an mehreren
  Stellen verschoben, unvollständig und in einem Fall irreführend. Maßgeblich ist
  ab jetzt **`docs/zertifikat/guard-landkarte-511451c.md`** — 44 Stellen, gegen
  `511451c` gezogen, mit Spalte `erledigt` als Merge-Gate.
- **§B5** Platzhalter-Zweig `schulung.` mit der Selektionsregel („`attended`,
  sortiert `interview.starts_at DESC`, Tie-Break `bookings.id DESC`" — bewusst
  nicht `latest('id')`, weil eine Umbuchung ein früheres Termindatum haben
  kann). Erweitert um `schulung.leiter` (§B7).
- **§B6** `meta.ort` bleibt Dead End.
- **§C1** Eigene Tabelle statt `RecContract`, weil eine Contract-Zeile
  `hasAnyContractSent()` wahr machen und die Versand-Guards des Nicht-EU-Umbaus
  kippen würde. Spaltenliste übernommen, **Unique-Constraint geändert (§C5)**.
- **§D1** Eigene Public-Route über die Zertifikat-`uuid`, kein Applicant-Token,
  kein Status-Guard im Controller (der abgelehnte, inaktive Bewerber ist der
  Normalfall dieses Dokuments). **Bleibt, und ist durch G20 stärker begründet
  als in v1 — siehe §D6. [v2]**

**Nicht übernommen:**

- **§B2 in seiner dortigen Formulierung.** „Signatur-Zwang" ist zweideutig
  formuliert und wäre falsch, wenn „Signatur erforderlich" gemeint wäre:
  **ein Zertifikat wird von niemandem unterschrieben.** Präzisiert in §B8:
  bei `type === 'certificate'` wird `requires_signature` im Model auf **`false`**
  erzwungen.
- **§C2 Schritt 1** („`rejectCase()` reparieren") — **erledigt.** `rejectCase()`
  läuft heute in `DB::transaction` und delegiert an `applyRejection()`
  (G12). Der Befund F3 jener Spec ist überholt.
- **§D2 URL-Button** — ersetzt durch die Body-Variable (§D4).
- Der Tradeoff „kein nachträgliches Ausstellen" — mit Weg (b) ist das
  automatische Ausstellen der Regelfall.

## Ausführungs-Schnitt

**Ein Paket.** §B (Typ + Guards), §B7 (Platzhalter), §C (zwei Wege), §D
(Zustellung), §E (Hülle + Assets), §F (Portale). Ohne `type` gibt es keine
Zertifikat-Vorlage, ohne Hülle kein Dokument, ohne Ausstellung nichts zu
rendern.

Aufwandsschwerpunkt liegt **nicht** beim Rendering (G13/G14: an der
Live-Version gemessen, funktioniert mit der bestehenden Konfiguration ohne
Änderung), sondern bei der Guard-Landkarte: **20 der 44 Stellen in
`docs/zertifikat/guard-landkarte-511451c.md` brauchen einen Filter.** **[v2]**
Die gefährlichste ist `Applicant/Show.php:750` — `findOrFail($templateId)` ohne
jeden Filter, davor nur eine `exists:`-Regel, die den Typ nicht kennt.

## Geteilte Fakten (gegen Code und Vorlage verifiziert — bindend)

**G1 — Die Word-Vorlage ist ein Scan mit vier Textfeldern.**
`Zertifikat.docx`: `sectPr` mit `pgSz w=11906 h=16838` (A4), **alle `pgMar` auf
0**. Ein Bild `word/media/image1.jpg`, 2480×3508 px bei 300 dpi, 2 743 585
Bytes, platziert über die ganze Seite (`extent cx=7560000 cy=10692000` EMU =
210×297 mm). Darüber vier `wp:anchor`-Textfelder (Arial, zentriert,
Platzhalterfarbe `#6E7781`): Teilnehmer 13 pt, Datum der Schulung, Datum der
Ausstellung 10 pt, Name des Schulungsleiters 10 pt.

Im Scan eingebrannt: Logo, „ZERTIFIKAT", der Kursname **SERVICE-BASISSCHULUNG**,
die Kenntnisliste, die Unterschrift der RheinGedeck GmbH und die leere Linie mit
der Bildunterschrift „SCHULUNGSLEITER". Papierton des Scans ≈ `#FBDAA3` (warm,
digital leicht vergilbt wirkend).

**Das Dokument enthält also genau vier variable Werte** — alles andere ist Text,
der pro Schulungsart feststeht.

**G2 — PDF-Engine ist DomPDF 3.1.5.** `ContractPdfController.php:40` rendert per
`Pdf::loadHTML($html)`. `meingedeck/composer.lock`: `dompdf/dompdf v3.1.5`
(`:956-957`), `barryvdh/laravel-dompdf v3.1.2` (`:161-162`).

**Der Controller setzt zwei Optionen explizit:** `defaultFont => 'DejaVu Sans'`
und `isHtml5ParserEnabled => true` (`:41-42`), und liefert per `->download()`
aus. **[v2]**

**G3 — Für Raster-Assets existiert ein Muster.**
`RendersContractPdf::loadCompanyStampDataUrl()` (`:68-79`) liest
`resources/images/company-stamp.png` und gibt eine `data:image/png;base64,…`-URL
zurück; fehlt oder bricht die Datei, liefert die Methode `null` und das PDF wird
ohne Stempel gerendert. Injiziert wird erst beim Rendern (`:44-56`), **nicht**
in den gespeicherten Vertragstext.

**G4 — Die bestehende PDF-Hülle passt nicht.**
`resources/views/pdf/contract.blade.php` setzt `body { margin: 2cm 2.5cm }` und
`.contract-content { white-space: pre-line }`. Für den Vertragsfluss richtig,
für ein gestaltetes Dokument mit eigener Papierfarbe und Fußverankerung falsch
→ eigene Hülle. **Alles Optische steckt bei Verträgen ebenfalls in der Hülle,
nicht in der Vorlage** — das ist die Form, der das Zertifikat folgt.

**G5 — Der Vorlagen-Editor ist ein Textarea, kein Rich-Text-Editor.**
`resources/views/livewire/contract-templates/index.blade.php:166`:
`<x-ui-input-textarea name="content" … rows="6" />`, Validierung
`'content' => 'nullable|string'` (`ContractTemplates/Index.php:28`). HTML
überlebt Speichern und Laden unverändert. (Der Weiß-Farb-Stripper in
`RecContractTemplate::personalizeContent()` **`:103`** stammt aus der HCM-Zeit
mit TinyMCE und trifft nur `color: white|#fff|rgb(255,255,255)`.) **[v2]**

**G6 — Es gibt einen subjekt-unabhängigen WhatsApp-Sendeweg, und er läuft bei
der Ablehnung schon live.** `HoldingTemplateSender` braucht nur Nummer +
Vorname (`sendOne` **`:81-84`**, Körper eine Zeile auf `:83`; `sendToMany`
`:28-78`), wählt Template und Kanal über einen frei übergebenen Settings-Key
(`resolveConfig` `:96-135`) und akzeptiert zusätzliche Body-Variablen als
`$namedValues`. Genutzt im Ablehnen-Zweig des HR-Schreibtischs für die
Jugendschutz-Absage (`HrDesk/Index.php:117-118` Guard, **`:177`** Versand).
**Ein abgelehnter Bewerber ohne Mitarbeiterdatensatz ist damit erreichbar** —
der Blocker „Verifikation V1" der Vorgänger-Spec ist in diesem Punkt aufgelöst.

**`sendOne` delegiert vollständig an `sendToMany` und erbt damit dessen
`try/catch (\Throwable)` aus `:72-74`** — die Fehlertoleranz aus §D5 gilt für
beide Wege. **[v2]**

**G7 — Derselbe Sender kann keinen dynamischen URL-Button füllen.**
`HoldingTemplateComponents::build()` iteriert ausschließlich über Komponenten mit
`type === 'BODY'` (`:23-26`) und gibt nur `[['type' => 'body', …]]` zurück
(`:60`); Buttons werden laut Klassenkommentar (`:8-9`) bewusst ignoriert. Ein
URL-Button mit Variable bekäme keinen Parameter, Meta weist den Send ab.

**G8 — Der einzige Pfad, der URL-Buttons füllt, füllt sie falsch.**
`Applicant/Show.php` **`:531-549`** (`sendManualTemplate`): sobald das Template
*irgendeinen* URL-Button hat, wird `index: 0` mit dem **Bewerber-Formular-Token**
belegt (`:543-549`). Für einen Zertifikat-Link der falsche Wert. Pfad
**nicht verwendbar**. **[v2]** (v1: `:530-553` / `:546-553`)

**G9 — Die Portal-Vertragsliste ist ein reiner Array-Mapper.**
`EmployeePortal::contracts()` (**`:464-501`**) lädt `applicant->contracts`,
filtert `status !== 'cancelled'` und mappt auf Arrays mit `id`, `display_name`,
`status`, `signed_at`, `completed_at`, `sign_url`, `pdf_url`; `display_name`
entsteht aus dem Template-`code` per `match` (`:481-486`). Die Blade rendert in
einer Schleife (`employee-portal.blade.php:104`); Status-Badge `:112-135` mit
**Rohwert-Fallback** `:133`, Unterschreiben-Button nur bei `sent`/`in_progress`
und leerem `signed_at` (`:139`), PDF-Button allein an `!empty($c['pdf_url'])`
(`:146`).

**`signed_at` wird an drei Stellen gelesen, nicht zwei: `:112`, `:116-117`
(Datumsausgabe innerhalb des `:112`-Zweigs) und `:139`. Keine Sortierung, keine
Zählung, kein weiteres Partial, keine weitere Livewire-Methode.** **[v2]**

**G10 — Der Schulungsleiter steckt in einer gepflegten n:m-Beziehung.**
`RecInterview::interviewers()` ist `belongsToMany` über `rec_interview_user`
(`RecInterview.php:72-81`), Tabelle aus
`2026_04_14_000001_create_rec_interview_tables.php:59`. Gesetzt per Multi-Select
in der Termin-Bearbeitung (`InterviewSchedule/Index.php:279`, `:284`,
`->sync(...)`), angezeigt in der Nachbereitung als
`interviewers->pluck('name')->join(', ')`
(`interview-bookings/index.blade.php:44-45`). **Mehrere Interviewer pro Termin
sind möglich; die Befüllung bei Alt-Terminen ist offen (V2).**

**G11 — Die Mitarbeiter-Anlage hat genau einen Erst-Anlage-Punkt mit
Übernahme-Hooks.** `CreateEmployeeFromApplicantService::createOrUpdate()` steigt
bei existierendem Employee vor allem anderen aus (`:38-41`), danach läuft alles
in `DB::transaction` (`:43`). Darin bereits drei Nachbereitungs-Schritte in
Folge: `ensureHrData()` (`:104`), `snapshotContractDatesToHrData()` (`:105`),
`transferEvaluationToHrData()` (`:106`). **Jeder Hook dort feuert genau einmal
pro Mitarbeiter.** (Gegen `511451c` unverändert gültig.)

**G12 — `rejectCase()` ist transaktional und hat einen privaten Kern.**
`HrDeskRoutingService::rejectCase()` (`:276-283`) umschließt `applyRejection()`
(`:285`) mit `DB::transaction`. `applyRejection` schließt den Fall (`:287-292`)
und setzt am Bewerber `rejected_at`, `is_on_hr_desk=false`, `auto_pilot=false`,
`is_active=false` (`:294-300`), mit Sonderzweig für Jugendschutz (`:307+`).
(Gegen `511451c` unverändert gültig.)

**G13 — DomPDF-Eigenheiten, alle an 3.1.5 gemessen (nicht vermutet).**

1. **`@font-face` braucht `chroot`.** Ein absoluter Pfad in `src: url(...)`
   allein genügt nicht — DomPDF fällt dann **stumm auf Helvetica** zurück:
   keine Exception, kein Log. Mit gesetztem `chroot` wird die Schrift
   eingebettet.
2. **Data-URIs funktionieren bei Fonts nicht** (nur bei Bildern) — mit
   `data:font/truetype;base64,…` wieder Helvetica.
3. **Custom Font = kein Glyph-Fallback.** Jedes Zeichen, das die eingebundene
   Schrift nicht hat, wird `?`. Konkret gemessen: ★ (U+2605) in Oswald ergab
   `STEHEMPFANG ? FLYING BUFFET`.
4. **`position:absolute` + `bottom` funktioniert bei Block-Divs, NICHT
   zuverlässig bei `<table>`.** Eine bottom-verankerte Tabelle lief unten aus
   der Seite; als zwei Divs korrekt.
5. **Fließlayout garantiert keine Einzelseite.** Ein randvolles A4 kippte durch
   0,2 mm Mehrhöhe auf zwei Seiten — dreimal reproduziert.
6. **Die PDF-Marker sind nur mit whitespace-toleranter Regex zu finden. [v2]**
   `grep -c "/Type /Page"`, `"/Type/Page"`, `"/BaseFont"` und `"FlateDecode"`
   liefern auf einem DomPDF-PDF **je 0 Treffer** (Marker über Zeilenumbruch
   verteilt, plus grep-Binary-Modus). `preg_match_all('/\/Type\s*\/Page[^s]/')`
   und `preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/')` finden sie. Der
   v1-Nachweisvorschlag `strings … | grep -oE` ist damit gestrichen.
7. **Text im PDF ist FlateDecode-komprimiert und UTF-16BE-kodiert. [v2]**
   Content-Streams müssen erst inflatiert werden, danach liegt der Text als
   UTF-16BE mit Nullbytes vor (CID-Font, Identity-H). Eine naive Suche nach `?`
   im Byte-Stream findet nichts. Konsequenz für §E8 und §Tests: die
   Glyph-Prüfung geschieht **am Eingang**, nicht am PDF.

**G14 — Die Live-Konfiguration trägt das ohne Änderung.**
`meingedeck` hat **keine** `config/dompdf.php`, es gelten die Paket-Defaults
(`vendor/barryvdh/laravel-dompdf/config/dompdf.php`):
`chroot => realpath(base_path())` (`:81`),
`font_dir`/`font_cache` => `storage_path('fonts')` (`:48`, `:58`),
`enable_remote => false` (`:270`).

Das Modul ist als `martin3r/platform-recruiting` installiert und liegt real
unter `vendor/martin3r/platform-recruiting` — **innerhalb** von
`realpath(base_path())`, also innerhalb des `chroot`. Gegengeprüft: mit
`enable_remote = false` **und** gesetztem `chroot` bettet die Schrift korrekt
ein *und* das Data-URI-Bild wird gerendert. **Keine neue Config-Datei, kein
`chroot`-Override nötig.** Voraussetzung bleibt, dass `storage/fonts` existiert
und schreibbar ist (dort legt DomPDF den Font-Metrik-Cache an).

**Die Installation ist ein dist-Install (zip), kein path-Repository und kein
Symlink** — `installed.json` pinnt `dist.type = zip` auf `511451c`;
`meingedeck/composer.json` führt unter `repositories` nur azure-sso und
Socialite/Microsoft. **`realpath()` bleibt damit lokal genauso innerhalb des
`chroot` wie live** — G14 gilt in beiden Umgebungen. **[v2]**

**G15 — Oswald ist frei und in statischen Gewichten verfügbar.**
`ofl/oswald/METADATA.pb` führt `license: "OFL"`, die `OFL.txt` nennt die
**SIL Open Font License 1.1** (Copyright 2016 The Oswald Project Authors). OFL
erlaubt kommerzielle Nutzung und ausdrücklich das Einbetten in Dokumente.

`google/fonts` liefert nur noch den Variable Font `Oswald[wght].ttf`; DomPDF
nimmt daraus die **Regular-Instanz** (im PDF: `Oswald-Regular`) — zu dünn.
`Oswald-SemiBold.ttf` liegt im Upstream-Repo `googlefonts/OswaldFont`
(`fonts/ttf/`, HTTP 200 geprüft, 109 120 Bytes) und bettet als
`Oswald-SemiBold` ein.

**G16 — `schulung.ort` ist für die Ausstellungszeile unbrauchbar.**
`rec_interviews.location` enthält die volle Adresse, live geprüft:
`"RheinGedeck GmbH, Hansaallee 321 / Halle 33a, 40549 Düsseldorf"`,
`"Hennes Weisweiler Allee 1, 41179 Mönchengladbach"`. In „DÜSSELDORF, DEN …"
gehört ein Stadtname. Der Ort bleibt daher **Literaltext in der Vorlage**;
`schulung.ort` wird für dieses Dokument nicht gemappt.

**G17 — Bestandsverträge sind von allem hier unberührt. Fünf getrennte
Gründe, alle geprüft:**

1. **Eigene Hülle, kein gemeinsames Layout.** `pdf/contract.blade.php` ist ein
   vollständiges HTML-Dokument mit eigenem `<style>`-Block; es gibt kein
   geteiltes Layout, kein `@include`, kein Parent-Template. Die Basis-Styles für
   nackte Elemente aus E10.1 stehen **ausschließlich in der
   Zertifikat-Hülle-Klasse** (§E1) und können Verträge nicht erreichen.
   **[v2] Durch den Wegfall der Blade wird dieser Punkt stärker:** die
   Zertifikat-Styles liegen jetzt in einer PHP-Klasse, die der Vertragsweg
   nirgends anfasst — es gibt nicht einmal mehr eine zweite Blade, die jemand
   versehentlich in ein gemeinsames Layout ziehen könnte.
2. **Der `type`-Default trifft den Bestand richtig.** Die Spalte kommt als
   `NOT NULL DEFAULT 'contract'` (§B1) — die **11** live vorhandenen Vorlagen
   (9 aktive: `AV-default`, `AV-010`, `AV-060`, `AV-110`, `AV-160`, `AV-210`,
   `AV-260`, `IFSG`, **`AT-140`**; inaktiv: `AV`, `AV-TEST`) werden dadurch
   `contract`. **[korrigiert 2026-08-12]** v2 sprach von 10 Vorlagen; `AT-140`
   („Erklärung 140-Tage Tätigkeit") ist seither dazugekommen und hat bereits
   einen echten Vertrag. Ihr `code` beginnt weder mit `ZERT-` noch kollidiert
   sie mit dem Präfixzwang aus §B8 — sie wird schlicht `contract` wie alle
   anderen.
   Jeder neue `type`-Filter (`where('type','contract')`) lässt sie also
   unverändert durch.
3. **Der neue `schulung.`-Zweig kann bei Verträgen nicht feuern.**
   `resolveSource()` verzweigt über das Präfix der gemappten Quelle. Live
   geprüft über alle **11** Vorlagen und mechanisch aggregiert (2026-08-12):
   **17 distinkte Quellen** über die fünf Präfixe `contact.`, `applicant.`,
   `contract.`, `settings.`, `meta.` — **kein einziges `schulung.*`**. Ein
   neuer Zweig am Ende der Kette ändert für diese Mappings nichts.

   **Dieses Argument ist ab Task 6a nicht mehr die Absicherung, sondern nur
   noch ihre Begründung. [v2-Nachtrag]** Es beschreibt einen Live-Datenstand,
   den HR im Vorlagen-Editor jederzeit ändern kann — und der Editor ist genau
   die Fläche, die dieses Paket erweitert. Festgenagelt wird das Verhalten
   deshalb durch `tests/Integration/PlaceholderResolutionPinTest.php`
   (Task 6a), der vor Task 7 grün sein muss.
4. **Der `requires_signature`-Zwang ist an `type === 'certificate'` gebunden**
   (§B8). Alle **11** Bestandsvorlagen haben live `requires_signature: true`
   und `type` wird `contract` — der Hook greift bei keiner von ihnen. **Gleiches
   gilt für den neuen `ZERT-`-Präfix-Zwang. [v2]**
5. **Die Schrift wird nur im Zertifikat-Weg eingebunden.** Verträge rendern
   weiter in DejaVu Sans; auch ein fehlendes oder unschreibbares
   `storage/fonts` (G14) kann Verträge nicht betreffen.

**Nicht anfassen, bewusst:** `resources/views/pdf/contract.blade.php`,
`Http/Controllers/Concerns/RendersContractPdf.php`,
`ContractPdfController.php`. Der Zertifikat-Weg bekommt eigenen Controller und
eigene Hülle. Wer beim Bauen anfängt, Gemeinsamkeiten in den Trait zu ziehen,
öffnet genau das Risiko, das dieser Punkt ausschließt — die Kopplung ist hier
nicht erwünscht.

**G18 — Der Code hat sich seit v1 bewegt, und zwar in den relevanten Dateien.
[v2]** Zwischen `8718f10` und `511451c` liegen neun Commits (969 Insertions),
darunter:

- **`AT-140` existiert und ist gemerged** — es gibt einen produktiven
  `AT-*`-Zusatzvertrag. Die v1-Aussage „es gibt kein einziges `AT-*`" ist
  überholt.
- **`resolveSource()` hat eine neue Signatur** (`:108`):
  `(string $source, RecApplicant $applicant, $contact, ?RecContract $contract,
  ?ZasLookupResolver $lookups = null)`, plus einen neuen Lookup-Label-Zweig
  (`:145-160`). §B7 hängt daran.
- **`personalizeContent()` instanziiert einen Resolver pro Dokument** (`:91`),
  ausdrücklich kein Singleton wegen Label-Cache in langlebigen Workern. Braucht
  `schulung.*` eine Abfrage, gilt dasselbe Muster.
- **Neues Modul-Muster: Platzhalter- und Weichenlogik liegt in testbaren
  Support-Klassen** — `Support/ResttagePlaceholder.php`,
  `Support/ContractPreSigningType.php`, `Support/LookupLabelFormatter.php`, je
  mit eigenem Unit-Test. §B7 und §E1 folgen dem, statt in `resolveSource` bzw.
  eine Blade zu wachsen.
- **`ResttagePlaceholder::hasUnresolvedPlaceholder()`** (`:88-91`) prüft
  generisch auf übrig gebliebene `{{…}}` mit der Zeichenklasse `[^{}]+`.
  **Wiederverwenden statt neu bauen** (§Tests).
- **`ContractPreSigningType::forCode()`** (`:23-38`) entscheidet den
  Vorschalt-Schritt: `AT-140` → Resttage, Präfix `AV-` → §15/§16, sonst
  `null`. Eine neue Auswahl-Stelle, die in der v1-Guard-Liste fehlte.

**G19 — Der Testrunner bootet kein Laravel, und das entscheidet die
Test-Architektur. [v2]** `tests/bootstrap.php` ist ein 20-zeiliger
`spl_autoload_register` mit dem ausdrücklichen Kommentar „kein
Laravel-Bootstrap"; `orchestra/testbench` ist in `meingedeck/vendor` nicht
vorhanden. Integrationstests bauen Container und `Capsule` von Hand
(`tests/Integration/DuplicateMatchQueryTest.php:28-45`) und laufen ohne `.env`.

Folgen:
- **`Pdf::loadHTML` ist im Test nicht aufrufbar** (Facade braucht
  App-Container + Config). **`Dompdf\Dompdf` direkt schon.**
- **Blade rendern erfordert Handverdrahtung** von BladeCompiler,
  FileViewFinder und Engine-Resolver. Deshalb §E1: keine Blade.
- **Der Runner lädt Modulklassen aus der Arbeitskopie, nicht aus `vendor`** —
  gemessen per Reflection im laufenden Test:
  `…/modules/platforms-recruiting/src/Support/ContractPreSigningType.php`,
  während Illuminate aus `meingedeck/vendor/laravel/framework` kommt. Der
  Render-Test prüft also den aktuellen Stand, **nicht** den gebumpten. Ein
  composer-Bump ist für den Test nicht nötig.

**G20 — Es gibt ein zweites Portal, und ein abgelehnter Bewerber behält
unbegrenzten Zugriff darauf. [v2]** Route `/recruiting/portal/{token}`
(`routes/public.php:36-37`) → `ApplicantPortal`. `mount()` prüft
ausschließlich Token-Existenz und -Gültigkeit sowie den `linkable`-Typ
(`:23-38`) — **kein `rejected_at`, kein `is_active`**. Der Token ist 128 Bit,
verfällt nie und wird nie rotiert (F1 der Vorgänger-Spec).

`ApplicantPortal::contracts()` (`:53-77`) hat **dieselbe Array-Form** wie
`EmployeePortal` (`display_name`-`match` ohne `AT-*`-Zweig), gerendert von
`applicant-portal.blade.php` mit derselben Badge-Logik (`:42`, `:46-47`, `:69`).

**Und `:78` setzt `state = count($contracts) === 0 ? 'empty' : 'ready'`** — ein
abgelehnter Nicht-EU-Bewerber ohne Verträge sieht heute ein Portal, das sich für
leer erklärt. Zählt die Zeile Zertifikate nicht mit, liegt das Dokument in einem
Portal, das behauptet, es gäbe nichts.

**G21 — Das Speichern von WhatsApp-Template-Settings funktioniert; der
JSON_SET-Workaround steckt nicht im Code. [v2]**
`ApplicantSettingsModal::save()` (`:252-266`) macht
`$this->settingsModel->settings = $this->settings; $this->settingsModel->save();`
— eine schlichte Array-Zuweisung. `grep -rn "JSON_SET" src/` liefert **null
Treffer** (die JSON_SET-Reparatur war eine einmalige SQL-Aktion, kein
Code-Pfad). Das bestehende WA-Template-Select nutzt
`wire:model.live="settings.minor_rejection_template_id"` **plus explizites
`:value`** (`applicant-settings-modal.blade.php:115-126`), **kein `@entangle`**.

→ Die zwei neuen Settings kopieren dieses Muster. **Kein neues Risiko**, und der
v1-Risikoeintrag entfällt.

**G22 — Die AT-140-Weiche und die Zertifikat-Checkbox kollidieren nicht. [v2]**
Der `AT-*`-Zusatzvertrag-Select sitzt **auf der Karte**, im
Rechtsstatus-Block neben „Als geprüft markieren"
(`hr-desk/index.blade.php:171-186`), und schreibt per eigenem `wire:change` auf
den LegalStatus. Das Resolve-Modal (`:333-378`) enthält nur: Header,
Erklärtext, die reject-exklusive WhatsApp-Checkbox
(`:349-360`, gated auf `$resolvingAction === 'reject' && $canSendRejectionMessage`),
das Notiz-Textarea und den Footer. **Getrennte States** (`resolveModalShow`,
`resolvingAction`, `resolvingCaseId`, `resolveNotes`, `sendRejectionMessage`
gegen `additional_contract_template_id`), keine gemeinsame Validierung.

Die Zertifikat-Checkbox aus §C3 gehört damit direkt neben
`sendRejectionMessage`, im gleichen `reject`-Gate und mit einem analog bei
Modal-Öffnung berechneten `$canIssueCertificate`.

**G23 — Der Vertrags-PDF-Weg rendert aus dem Snapshot. [v2]**
`RendersContractPdf::prepareContractContentForPdf()` liest
`$contract->personalized_content` (`:36`); `personalizeContent()` wird beim
Download **nicht** erneut aufgerufen. Snapshot-Semantik bestätigt — die
Zertifikat-Ablage (§C1) folgt derselben Logik.

**G24 — KORRIGIERT (2026-08-12, Task 0): die Times-Bold-Behauptung war ein
Messfehler.** Die v2-Fassung dieses Fakts behauptete, der Vertrags-PDF mische
`SUBAAB+DejaVuSans` mit dem Core-Font `Times-Bold`, fette Zellen fielen also auf
Times zurück. Gemessen war das mit einem Wegwerf-Skript. Der Regressionstest aus
Task 0 liefert in der kanonischen Testumgebung
(`meingedeck/vendor/bin/phpunit`, dompdf 3.1.5) reproduzierbar
**`['DejaVuSans', 'DejaVuSans-Bold']`** — kein Core-Font-Fallback. Dreimal
wiederholt, plus Gegenproben gegen `installed-fonts.dist.json` und das
UA-Stylesheet.

Beide Messungen liefen mit identischem Stylesheet (md5 geprüft) und identischen
Optionen; die Abweichung ließ sich nicht auf `defaultFont` zurückführen. Sie ist
nicht aufgeklärt.

**Was daraus folgt:**

- Es gibt **keinen** belegten „Times-Bold-Bestandsmakel". Der Satz ist gestrichen.
- Der SOLL-Wert des Regressionstests ist `['DejaVuSans', 'DejaVuSans-Bold']`,
  gemessen in der Umgebung, in der der Test läuft. Für seinen Zweck —
  Änderungserkennung — genügt das: er muss stabil sein, nicht
  produktionsidentisch.
- **Über die Produktion sagt keine der beiden Messungen etwas.** Dort ist
  `font_dir = storage_path('fonts')` (G14), nicht das Font-Verzeichnis unter
  `vendor/`. Wer wissen will, welche Fonts in einem live erzeugten Vertrags-PDF
  stecken, muss ein echtes PDF von dort prüfen — **offener Punkt, nicht in
  diesem Scope**.

## Architektur

### §E Rendering — HTML-Vorlage, geteilte Assets

**E1 — Hülle und DomPDF-Optionen liegen in einer Support-Klasse, nicht in einer
Blade. [v2]** (v1: `resources/views/pdf/training-certificate.blade.php`)

> **Reihenfolge beim Setzen der Optionen — gemessen in Task 10, gilt für JEDEN
> Renderpfad. [v3]** Der Controller setzt die fünf Optionen aus
> `TrainingCertificatePdfOptions` **nach** `Pdf::loadHTML()`. Das sieht falsch aus
> und ist es nicht: `@font-face` wird erst beim **Rendern** aufgelöst, nicht beim
> Laden des HTML. Nachgemessen mit absichtlich falschem Start-`chroot` und
> korrektem `chroot` erst nach `loadHtml()` — Oswald wird trotzdem eingebettet.
>
> **KORRIGIERT, wenige Stunden nach dem ersten Schreiben.** Hier stand: „Die
> Ausnahme, und sie ist die eigentliche Regel: `fontDir` und `fontCache` dürfen
> NIE spät kommen." **Das ist falsch, und zwar gemessen.** Konstruktion mit einem
> **nicht existierenden** `fontDir`, Umstellung auf ein existierendes **nach**
> `loadHtml()`:
>
> ```
> fontDir SPAET auf existierendes Verzeichnis: 315693 Byte, Fonts: Oswald-SemiBold, DejaVuSans
> Dateien im spaet gesetzten Ordner: 5
> ```
>
> Es zählt also der Wert **zum Zeitpunkt von `render()`** — für **alle** diese
> Optionen, `fontDir` und `fontCache` eingeschlossen. Die Gegenprobe stützt das:
> ein spät gesetzter *falscher* `chroot` bricht die Schrift sehr wohl.
>
> Woher der Irrtum kam: der ursprüngliche Gegenbefund war durch den **`chroot`**
> verursacht, nicht durch `fontDir`. Ich habe die Regel aus einer Messung
> abgeleitet, deren Ursache falsch zugeordnet war — dieselbe Bauart wie die
> Times-Bold-Behauptung und die §E5-Zusicherung, und diesmal mit Folgen: die
> falsche Regel hätte die einzige saubere Lösung für das
> `storage/fonts`-Problem (siehe §Deploy) **verboten**.
>
> Was bleibt: `fontDir`/`fontCache` früh zu setzen ist **Cache-Hygiene**, keine
> Korrektheitsbedingung. Wird `fontDir` spät umgestellt, verteilt sich der
> Metrik-Cache auf zwei Verzeichnisse — unschön, aber das PDF ist korrekt.
>
> Diese Zeile steht hier und nicht nur im Controller-Docblock, weil sie für jeden
> **zweiten** Renderpfad gilt, den später jemand baut — Vorschau, Neuausstellung,
> ein Batch-Export. Wer dort die Reihenfolge „aufräumt", weil sie unlogisch
> aussieht, bekommt keinen Fehler, sondern ein anderes PDF.

Zwei Klassen, beide ohne Laravel-Abhängigkeit und damit direkt unit-testbar
(G19), nach dem Muster von `Support/ResttagePlaceholder` (G18):

```
Support/TrainingCertificateHtml::build(string $personalizedContent, array $assets): string
Support/TrainingCertificatePdfOptions::for(string $fontPath): array
```

`TrainingCertificateHtml` liefert Seiten-Setup, Papierfarbe,
Schrift-Einbindung, Basis-Styles (E10.1) und die Fuß-Verankerung:

```html
<style>
  @font-face { font-family: "Zert"; font-weight: normal; font-style: normal;
               src: url("<fontPath>") format("truetype"); }
  @page { margin: 0; size: A4; }
  body  { margin: 0; padding: 15mm 18mm 11mm; background: #FDF3E0;
          font-family: "Zert", sans-serif; color: #3C4A63; text-align: center; }
  .zert-fuss-links  { position: absolute; left:  24mm; width: 54mm; bottom: 12mm; }
  .zert-fuss-rechts { position: absolute; left: 116mm; width: 66mm; bottom: 10mm; }
</style>
```

**`TrainingCertificatePdfOptions` ist die einzige Stelle, an der die
Engine-Optionen stehen** — `chroot`, `isRemoteEnabled`, `dpi`, `defaultFont`,
`isHtml5ParserEnabled`. **Controller und Render-Test konsumieren dieselbe
Quelle.** Begründung: G2 zeigt, dass der Vertrags-Controller seine Optionen
selbst setzt; im Prototyp fiel ein `isRemoteEnabled`-Unterschied nur auf, weil
ich ihn von Hand gesucht habe. Setzen Controller und Test ihre Optionen je
selbst, testet der Render-Test eine andere Engine als die ausgelieferte und ist
grün ohne Aussage. Das ist die eigentliche Absicherung, nicht der Test selbst.

Der Controller ruft danach `Pdf::loadHTML(...)`, überträgt die Optionen aus der
Klasse und liefert per **`->stream()`** aus, nicht `->download()` (G2): der
WhatsApp-Link soll das PDF anzeigen, nicht einen Download erzwingen. **[v2]**

`$fontPath` ist der absolute Pfad auf die Schriftdatei im Modul — funktioniert
dank G14 ohne Config-Änderung, lokal wie live.

**E2 — Vier Assets, geteilt über alle Zertifikat-Vorlagen.** Nicht pro Design,
nicht pro Schulungsart:

> **[geändert 17.08.2026] Es sind FÜNF.** Dazugekommen ist
> `resources/images/certificates/signature-schulungsleiter.png` — Unterschrift
> des Schulungsleiters mit Firmenstempel, Data-URI wie die anderen, unten rechts
> mit 48 mm Breite und −12 mm Versatz (Zahlen und Begründung stehen in
> `TrainingCertificateHtml`). **Ein festes Bild für alle Zertifikate, nicht pro
> Person:** ein Termin kann mehrere Interviewer haben (`belongsToMany`), „welche
> Unterschrift" hätte bei zweien keine Antwort — und pro Person bräuchte es eine
> Ablage für Unterschriftsbilder, eine Upload-Oberfläche und eine Regel, wer
> wessen hochladen darf. Bewusst nicht gebaut.
>
> **Damit entfällt `{{schulung_leiter}}`** aus §E3 und aus `PLACEHOLDERS`: das
> Dokument zeigt an dieser Stelle eine Unterschrift, keinen Namen.
> `TrainingLeaderResolver` bleibt vollständig nötig — `trainingDate()` liefert
> weiter `{{schulung_datum}}`; nur `leaderNames()` hat keinen Aufrufer mehr und
> steht bewusst weiter da, weil seine Tests die geteilte Buchungsauswahl
> abdecken, an der `trainingDate()` hängt.
>
> **Der rechte Fußblock wandert in die Hülle.** Er stand im Vorlageninhalt,
> solange dort ein Name eingesetzt wurde; ein Bild braucht das Asset, und Assets
> kommen nur in `TrainingCertificateHtml::build()`. Beide Fußblöcke baut jetzt
> die Hülle — links wie rechts. Linie und Bildunterschrift stehen auch ohne
> Asset.

| Datei | Zweck | Einbindung |
| --- | --- | --- |
| `resources/fonts/Oswald-SemiBold.ttf` | Grundschrift | Pfad + `chroot` (G13.1, G14) |
| `resources/images/certificates/logo.png` | RheinGedeck-Logo | Data-URI (G3) |
| `resources/images/certificates/headline-zertifikat.png` | Wort „ZERTIFIKAT" mit Inline-Kontur | Data-URI |
| `resources/images/certificates/signature-block.png` | Unterschrift + Linie + „RHEINGEDECK GMBH" | Data-URI |

**Warum die Headline ein Bild ist:** die Inline-Kontur des Originals hat keine
freie Schrift, und das Wort „ZERTIFIKAT" ist konstant — als Bild kostet es
keine Flexibilität und rettet das einzige wirklich unverwechselbare Element.

**Warum der Unterschriften-Block Linie und Bildunterschrift mit einschließt:**
die Unterschrift läuft im Original **unter die Linie durch** und ihr Abstrich
kreuzt die Bildunterschrift. Jeder Schnitt oberhalb der Linie schneidet sie ab.
Grenzen wurden über die Tintenausdehnung bestimmt, nicht geschätzt.

**E3 — Der Vorlageninhalt ist gewöhnliches HTML mit Platzhaltern.** Nur diese
vier Werte sind variabel (G1) — der Name über zwei Platzhalter, weil
`contact.first_name` und `contact.last_name` getrennt gemappt sind;
Kursname, Kenntnisliste und Ort sind
Literaltext, weil eine Vorlage pro Schulungsart existiert (G16 für den Ort):

| Platzhalter | Mapping |
| --- | --- |
| `{{kontakt_vorname}} {{kontakt_nachname}}` | `contact.first_name` / `contact.last_name` |
| `{{schulung_datum}}` | `schulung.datum` (§B5) |
| `{{datum_heute}}` | `meta.datum_heute` |
| `{{schulung_leiter}}` | `schulung.leiter` (§B7) |

~~Damit ist die Vorlage formgleich mit einer Vertragsvorlage: HTML, Platzhalter,
`field_mappings` — und im Textarea (G5) lesbar und editierbar.~~

> **[geändert v3]** Es gibt keine Vorlage und keine `field_mappings`. Der Inhalt steht als Konstante in `TrainingCertificateHtml`, die Platzhalter werden bei der Ausstellung durch `str_replace` ersetzt. **Die vier Platzhalter der Tabelle oben bleiben als Form erhalten** — gleiche Schreibweise `{{…}}`, gleiche Namen. Nicht aus Nostalgie: `ResttagePlaceholder::hasUnresolvedPlaceholder()` prüft in Task 9 auf genau dieses Muster, und der Rückweg braucht dieselben Namen, wenn der Inhalt wieder in eine Vorlage wandert.
>
> Die Quellen ändern sich dabei nicht, nur ihr Aufrufer:
>
> | Platzhalter | v2: `field_mappings` → `resolveSource()` | v3: Ausstellungs-Service |
> | --- | --- | --- |
> | `{{kontakt_vorname}}` / `{{kontakt_nachname}}` | `contact.first_name` / `contact.last_name` | direkt am `CrmContact` |
> | `{{schulung_datum}}` | `schulung.datum` (§B5) | `TrainingLeaderResolver::trainingDate()` |
> | `{{schulung_leiter}}` | `schulung.leiter` (§B7) | `TrainingLeaderResolver::leaderNames()` |
> | `{{datum_heute}}` | `meta.datum_heute` | `now()->format('d.m.Y')` |
>
> `TrainingLeaderResolver` bleibt also **vollständig** nötig; was entfällt, ist der `schulung.`-Zweig in `resolveSource()` — genau der Teil, der eine Bestandsmethode angefasst hätte.

**E4 — Sonderzeichen laufen in DejaVu.** Die ★-Trenner der Kenntnisliste stehen
in `<span class="zeichen">`, das per CSS auf `"DejaVu Sans"` schaltet. Grund:
G13.3 — Oswald hat kein ★, und ohne diesen Umweg steht `?` auf dem Zertifikat.
Gilt für jedes Zeichen außerhalb des Latin-Grundbestands.

**E5 — Datum und Unterschriftszeile sind am Seitenfuß verankert**, als Divs
(nicht als Tabelle) mit `position: absolute; bottom: …` (E1). Damit ist der Fuß
aus dem Fluss genommen und kann nicht mehr durch Abstände nach unten geschoben
werden (G13.4, G13.5).

**KORRIGIERT [v3]:** v1 und v2 behaupteten hier, damit könne „der fließende
Mittelteil **keinen Seitenumbruch mehr erzeugen** — die Einzelseiten-Eigenschaft
wird strukturell erzwungen". **Das ist falsch, und zwar gemessen**, mit den
echten Assets und wachsender Kenntnisliste:

| Zeilen in der Kenntnisliste | Seiten im PDF |
|---|---|
| 4 (ausgelieferte Vorlage) | 1 |
| 10 | 1 |
| 20 | **2** |
| 40 | **2** |

Die Verankerung erzwingt, dass **der Fuß** nicht umbricht — nicht, dass das
Dokument einseitig bleibt. Der Mittelteil kann weiterhin überlaufen, und dann
liegt der absolut positionierte Fuß auf Seite 1, während der Inhalt auf Seite 2
weiterläuft. 20 Zeilen à 12 pt sind für eine von HR gepflegte Vorlage nicht
exotisch.

Folge für die Umsetzung: die Einzelseitigkeit braucht **weiterhin einen
Längen-Guard** über die Kenntnisliste (advisory, kein Gate — im Stil der
`missing`-Liste aus §E1), und der Render-Test (§Tests) muss den Worst Case
**über die Listenlänge** fahren, nicht nur über lange Namen und Kursbezeichnungen.
Gefunden im Review zu Task 6, nicht bei der Prototyp-Messung: der Prototyp hatte
genau die sechs Zeilen der Originalvorlage.

**E6 — Papierfarbe `#FDF3E0`**, nicht der Scan-Ton `#FBDAA3` (G1). Der Scan hat
einen warmen Farbstich, der digital vergilbt wirkt; die CSS-Farbe ist neutraler.

**E7 — Verworfen: Scan als Vollflächen-Hintergrund.** Gebaut und gemessen:
funktioniert, 150 dpi → 415 KB PDF / 26 MB Peak-RAM, 300 dpi → 2 681 KB /
44 MB, Positionen aus den Word-Ankern +1,3 mm treffen exakt. **Trotzdem
verworfen**, weil ein Scan zwangsläufig Koordinaten erfordert (die gedruckten
Zeilen liegen im Bild) und daraus folgt:

- zwei deploy-gebundene Assets **pro Schulungsart** (Bild + Koordinaten-CSS),
- kein Editor-Charakter — Geometrie statt Text,
- keine Vorschau möglich,
- eine neue Schulungsart erfordert einen neuen Scan, nicht nur neuen Text.

Die Vorfassung dieses Dokuments hatte die Koordinaten in `content` gelegt und
das mit „sonst braucht jede neue Schulungsart ein Deploy" begründet — **das war
sachlich falsch**: der Scan lässt sich nicht über die UI hochladen, ein Deploy
fällt ohnehin an.

Gemessenes Ergebnis der gewählten Variante: **308 KB (315 802 Bytes), eine
Seite**, eingebettet `Oswald-SemiBold` + `DejaVuSans`, 6 Bildobjekte.
**Nachgemessen mit `isRemoteEnabled = false`, also dem Live-Wert aus G14:
byteidentisch. [v2]** (v1 hatte mit `true` gemessen — der Unterschied ist damit
ausgeschlossen, nicht bloß unwahrscheinlich.) Prototyp und Messumgebung liegen
unter `/Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/` —
**absoluter Pfad, außerhalb des Repos.** Die Schreibweise ohne Präfix war
irreführend: das Repo hat ein eigenes `docs/zertifikat/`, das nur die
Guard-Landkarte enthält, man landet dort also in einem existierenden
Verzeichnis ohne die Datei.

**E8 — Test-PDF und Glyph-Prüfung im Vorlagen-Editor. [v2]**

Zwei Knöpfe neben der Vorlage:

> **[entfällt v3] — und die Begründung ist wichtiger als die Streichung.**
>
> §E8 beschreibt zwei Knöpfe **im Vorlagen-Editor**. Es gibt keine Zertifikat-Vorlage mehr, also auch keinen Editor dafür — beide Knöpfe entfallen, nicht nur ihre Priorität.
>
> **Die „load-bearing"-Markierung unten wird ausdrücklich zurückgenommen.** Sie war richtig, solange sie galt, und sie fällt nicht weil wir sie uns nicht leisten wollen, sondern weil ihre Voraussetzung weg ist. Die Kette war: die Einseitigkeit ist keine strukturelle Garantie (§E5, gemessen: 20 Listenzeilen → 2 Seiten) → also hängt sie am Inhalt → der Inhalt liegt in einem Textarea, in das HR schreiben darf → also braucht es eine Stelle, an der ein Mensch die Seitenzahl **nach jeder HR-Bearbeitung** sieht.
>
> **Mit dem Zuschnitt v3 bricht das dritte Glied:** der Inhalt ist deploy-gebunden. Er ändert sich nur, wenn jemand `TrainingCertificateHtml` anfasst und deployt — und **genau dann** läuft der Render-Test. Damit übernimmt **Task 9** beide Aufgaben von §E8 vollständig und automatisch statt manuell und optional:
>
> - Seitenzahl: `testZwoelfKenntnisZeilenBleibenEineSeite` plus die Negativkontrolle `testZuVieleKenntnisZeilenErzeugenEineZweiteSeite` (Kriterium 1).
> - Zeichenabdeckung: `FontGlyphCoverage::inspect()` mit `checkable === true` und leerem `missing` (Kriterium 3).
> - Und weiterhin das, was §E8 nie konnte: `/BaseFont` (Kriterium 2), der einzige Wächter, der eine beschädigte Schriftdatei in jeder Stufe rot macht.
>
> **Wer §E8 reaktiviert, muss das mittlere Glied wiederherstellen** — also erst das Textarea zurückbringen (Rückweg, siehe Tradeoffs). Solange der Text deploy-gebunden ist, wäre ein Test-PDF-Knopf ein Bequemlichkeitswerkzeug, kein Wächter, und dürfte nicht als solcher geführt werden.
>
> Was von §E8 **inhaltlich** überlebt und in Task 4a schon gebaut ist: die drei Zustände der Glyph-Prüfung (`checkable` / `missing` / `hasWarning()`) und die Messung, dass eine *abgeschnittene* Schriftdatei damit prinzipiell nicht erkennbar ist. Beides steht weiter unten in diesem Abschnitt und bleibt gültig — es beschreibt jetzt nur eine Klasse, die der Render-Test benutzt, keinen Knopf.

**§E8 war load-bearing. [v3 — zurückgenommen, siehe Kasten oben]** Nicht mehr „nice to have neben der Test-Assertion":
seit die Einseitigkeit als strukturelle Garantie gekippt ist (§E5), ist das
Test-PDF **die einzige Stelle, an der ein Mensch die Seitenzahl einer von HR
bearbeiteten Vorlage überhaupt sieht.** Der Render-Test prüft nur die
ausgelieferte Vorlage; jede spätere Bearbeitung im Textarea läuft ohne
Netz. Fällt §E8 aus dem Scope, gibt es für den Fall „HR erweitert die
Kenntnisliste auf 25 Zeilen" keinen Wächter mehr — auch keinen nachgelagerten.

1. **„Test-PDF"** rendert mit Beispielwerten über dieselbe Hülle und dieselben
   Optionen (E1) und liefert das Ergebnis aus. Begründung: G13.5 ist
   inhaltsabhängig — ein langer Name oder eine längere Kenntnisliste kann das
   Layout sprengen, und ohne Vorschau merkt das erst der Bewerber.

   **Die Seitenzahl wird ANGEZEIGT, nicht nur mitgeliefert. [v3]** Ein
   zweiseitiges Zertifikat sieht auf Seite 1 völlig normal aus; wer nicht
   scrollt, merkt nichts. Neben dem Knopf steht deshalb das Ergebnis im
   Klartext, gezählt am erzeugten PDF mit demselben Muster wie im Render-Test
   (`/\/Type\s*\/Page[^s]/`, **nicht** `grep` — G13.6):

   - eine Seite → `1 Seite`
   - mehr → `2 Seiten — Zertifikate sollen einseitig sein`

   **Kein Gate**, aber sichtbar: gespeichert wird trotzdem, das PDF wird
   trotzdem ausgeliefert. Der Grund gegen ein Gate ist derselbe wie bei der
   Glyph-Prüfung — HR muss eine Vorlage auch in einem Zwischenstand speichern
   können. Der Grund für die Anzeige ist, dass ein Hinweis, den man wegklicken
   kann, hier nichts nützt: der Fehler ist nicht sichtbar, solange man ihn
   nicht ausdrücklich benennt.

2. **„Zeichen prüfen"** ruft eine pure Funktion auf:

```
Support/FontGlyphCoverage::inspect(string $content, string $fontPath): FontGlyphReport
```

**Drei Zustände, nicht zwei. [v3]** `missing()` gab `[]` zurück für „nichts
fehlt" **und** für „Font nicht parsbar" — eine kaputte Schrift bekam damit ein
besseres Zeugnis als eine intakte, und dieser Knopf, die einzige Stelle, an der
ein Mensch den stillen Helvetica-Fallback (G13.1) je bemerkt hätte, bestätigte
das Gegenteil. `inspect()` trennt: `checkable` false = nicht prüfbar,
`checkable` true mit leerem `missing` = nichts fehlt, sonst die Liste.
`hasWarning()` ist in beiden Problemzuständen true. Bleibt eine **Warnung, kein
Gate.**

**Was der Knopf NICHT prüft, und woran man sich nicht gewöhnen darf. [v3]** Eine
*abgeschnittene* Schriftdatei kann er prinzipiell nicht erkennen: gemessen liegt
die `cmap` vollständig im erhaltenen Dateikopf, 40 % und 5 % der echten Datei
liefern **identisch 737 Einträge** wie das Original. Erst wenn `FontLib` die
Datei gar nicht mehr lädt (gemessen ab der 3-Byte-Stufe), wird `checkable` false.
Für „ist die Schrift heil?" ist und bleibt `/BaseFont` im erzeugten PDF der
einzige Wächter — also das Test-PDF und die Assertion im Render-Test.

**Nebeneffekt, KEINE Absicherung. [v3]** In der Host-App wandelt Laravels
`HandleExceptions::handleError()` jede PHP-Warning in eine `ErrorException` um;
`FontGlyphCoverage::charMap()` fängt die mit `catch (\Throwable)` und liefert
dadurch auch bei den Stufen 40 % und 5 % „nicht prüfbar". Der Knopf meldet in
der App also real vier von fünf Beschädigungsstufen. **Darauf darf sich nichts
verlassen:** das hängt an Laravels Error-Handler, nicht am Modul, gilt im
CLI-Testlauf nicht, und ein geänderter Handler oder ein anderes
`error_reporting` nimmt es wieder weg. Wer daraus ableitet, die Glyph-Prüfung
decke kaputte Schriften ab, baut auf Zufall.

Sie liest die `cmap` der Schriftdatei und gibt die Zeichen des Inhalts zurück,
die darin fehlen — also genau die, die im PDF zu `?` würden (G13.3). **Am
Eingang, nicht am PDF**, weil der PDF-Text komprimiert und UTF-16BE-kodiert ist
(G13.7) und eine Prüfung dort teuer und indirekt wäre.

**Damit ist der v1-Tradeoff „prüft nur die ausgelieferte Vorlage, nicht spätere
Bearbeitungen" geschlossen** — HR kann nach jeder Bearbeitung selbst prüfen, und
derselbe Aufruf läuft als Assertion im Render-Test.

~~Beides ist verzichtbar, wenn der Schnitt zu groß wird — dann bleibt nur die
Assertion aus §Tests, und der Tradeoff kommt zurück.~~ **GESTRICHEN [v3].** Das
galt, solange die Einseitigkeit als strukturell erzwungen geführt wurde (§E5).
Sie ist es nicht, und damit ist §E8 nicht die Bequemlichkeit, sondern der
Wächter. Der Satz bleibt durchgestrichen stehen, weil er zweimal zitiert wurde
und niemand ihn aus dem Gedächtnis wiederbeleben soll.

> **[teilweise entfällt v3]** Der **Seed-Command entfällt** — es gibt keine Vorlage zu seeden, der Inhalt ist Teil des Deploys. Das **Mitstylen nackter Elemente bleibt** und wird sogar wichtiger: es ist jetzt der Autor des festen HTML, der davon profitiert, und es hält die Tür für den Rückweg offen, weil ein späterer Vorlageninhalt dasselbe Vokabular vorfindet.
>
> Was mit dem Seed-Command mitentfällt: das Vorlagen-Vokabular muss nicht mehr in einer Editor-Hilfe erklärt werden (kein Editor), und die HTML-Entity-Schreibweise `&#9733;` für die ★ ist keine Absprache mehr, sondern eine Zeile im Klassencode. **Die Entity-Dekodierung in `FontGlyphCoverage` bleibt trotzdem nötig und getestet** — der feste HTML-Block benutzt sie weiter, und Task 9 prüft gegen genau diesen Inhalt.

**E10 — Nackte Elemente werden mitgestylt, ~~und die erste Vorlage wird
geseedet~~.** Der Vorlageninhalt (E3) benutzt ein kleines Klassen-Vokabular
(`.lab`, `.val`, `.kurs`, `.intro`, `.skill`, `.zeichen`, die zwei
Fuß-Klassen), das die Hülle definiert. Damit das kein Fallstrick wird, drei
Maßnahmen — keine davon ein neues Konzept:

1. **Die Hülle stylt auch `p`, `h2`, `strong` und `li`** so, dass gewöhnliches
   HTML brauchbar aussieht (zentriert, Grundschrift, sinnvolle Abstände). Die
   Klassen sind dann Feinsteuerung, keine Voraussetzung. Wer nur einen Satz
   ergänzen will, tippt einen `<p>` und es passt. **Diese Styles wandern mit
   der Hülle in `TrainingCertificateHtml` (E1) — an ihrer Wirkung ändert das
   nichts, und sie sind dort erstmals unit-testbar. [v2]**
2. **Das Vokabular steht in der Platzhalter-Hilfe** neben dem Editor, dort wo
   schon die verfügbaren Platzhalter erklärt werden.
3. **Die erste Zertifikat-Vorlage kommt aus einem Command**, nicht aus
   Handarbeit im Textarea — Muster vorhanden:
   `Console/Commands/CreateArbeitsvertragVariants.php`,
   `CopyHcmContractTemplates.php`, `SeedRecContractExtraFields.php`. HR
   bearbeitet danach Wörter in einer fertigen Vorlage, statt Struktur zu
   schreiben. Für eine neue Schulungsart wird kopiert und der Text getauscht.

Ein `<style>`-Block im `content` würde übrigens funktionieren (G5 speichert
verlustfrei, DomPDF wertet ihn aus) — als Notausgang für einen Sonderfall,
nicht als vorgesehener Weg.

**E9 — Zertifikat-Inhalt gehört nie in die Bildschirm-Ansicht.** Der
`personalized_content` eines Zertifikats ergibt nur mit Hülle und Assets ein
Dokument; in der `.prose`-Ansicht der Signing-Seite
(**`contract-signing.blade.php:284`**) fehlten Schrift, Papier und Bilder.
**[v2]** (v1: `:234`) Heute unerreichbar — Zertifikate erzeugen keinen
`RecContract` (§C1) und damit keinen Signing-Link, und die Portale verlinken das
PDF (§F1). Es ist aber der Grund, warum §C1 keine Contract-Zeile anlegen darf,
und gehört als Kommentar an die Hülle.

Zusätzlich abgesichert durch §B8: mit `code`-Präfix `ZERT-` liefert
`ContractPreSigningType::forCode()` (G18) für jedes Zertifikat `null`, kann also
selbst dann keinen Vorschalt-Schritt auslösen, wenn doch einmal ein
Signing-Pfad erreicht würde. **[v2]**

### §B7 Platzhalter `schulung.leiter`

Dritter Wert im `schulung.`-Zweig aus §B5, **mit derselben Buchungs-Selektion**
(`attended`, `interview.starts_at DESC`, Tie-Break `bookings.id DESC`) — kein
zweiter Abfrageweg, keine zweite Sortierregel.

Aufgelöst als `interview->interviewers->pluck('name')->join(', ')` (Muster
G10). Keine Buchung, kein Interviewer oder leerer Name → leerer String, wie
alle anderen Zweige von `resolveSource()`.

**Die Auflösung liegt in einer Support-Klasse, nicht inline in
`resolveSource()`. [v2]** `resolveSource()` hat seit `511451c` fünf Parameter
und drei Verzweigungsebenen (G18); der `schulung.`-Zweig delegiert deshalb an
eine eigene Klasse mit eigenem Unit-Test, so wie
`Support/ResttagePlaceholder` und `Support/LookupLabelFormatter` es vormachen.
Braucht die Auflösung eine Abfrage pro Platzhalter, wird die Instanz — wie
`ZasLookupResolver` in `personalizeContent():91` — **einmal pro Dokument**
erzeugt und durchgereicht, nicht pro Platzhalter.

Das Feld ist 66 mm breit (E1) und darf umbrechen; es wächst nach oben, weil es
am Fuß verankert ist (E5). **Kein Clipping** — G13 hat gezeigt, dass
`overflow: hidden` bei zentriertem Text beidseitig abschneidet und dann wie ein
Datenfehler aussieht (`CHEL ZIMMER, ANNA BERGMAN`).

**Kein Typ-Filter auf die Terminart.** Kriterium ist `attended`, genau wie in
§B5. Ein Filter auf eine bestimmte `interview_type_id` wäre eine zweite,
stillschweigende Definition von „Schulung" neben der, die das Modul benutzt.

### §B8 Ein saving-Hook, zwei Invarianten **[v2]**

Im Model (`booted()`/`saving`-Hook) werden bei `type === 'certificate'` **zwei**
Dinge erzwungen:

1. **`requires_signature = false`.** Ein Zertifikat wird von niemandem
   unterschrieben: die Unterschrift der RheinGedeck GmbH ist Teil des Dokuments
   (E2), und der Empfänger bestätigt nichts. Ein `true` würde einen Signaturweg
   suggerieren, den es nicht gibt — und §E9 verbietet ihn ausdrücklich.
2. **`code` beginnt mit `ZERT-`.** Verstoß → Exception, nicht stille Korrektur.

**Warum der Präfix-Zwang und nicht die Konvention aus v1. [v2]** v1 sagte
„Zertifikat-`code` darf nie `AV-*` oder `AT-140` sein". Das ist eine Konvention
ohne Guard: `ContractPreSigningType::forCode()` (G18) entscheidet allein am
`code`, ob ein Vorschalt-Schritt läuft — ein Zertifikat mit `code = 'AV-ZERT'`
bekäme die §15/§16-Abfrage. Und der nächste Seed-Command, der einen `code`
frei setzt, kennt die Konvention nicht. Ein erzwungener Präfix macht die
Kollision **unmöglich statt unwahrscheinlich**, und er tut es an derselben
Stelle wie die Signatur-Invariante: ein Hook, zwei Zusicherungen, ein Test.

Der Hook deckt die Wege ab, die das Modal umgehen: MCP
(`CreateContractTemplateTool:87`, `UpdateContractTemplateTool:86`), einen
nachträglichen Typwechsel an einer Bestandsvorlage, und Seeder/Commands. Der
Signatur-Schalter wird im Editor bei `certificate` ausgeblendet (Anzeige folgt
dem Model, erzwingt nichts selbst).

### §C Ausstellung — zwei Wege

**C3 — Weg (a): Ablehnung am HR-Schreibtisch.** Checkbox „☑ Teilnahme-Zertifikat
ausstellen" im Ablehnen-Zweig des Resolve-Modals, sichtbar nur bei vorhandener
`attended`-Buchung (bestehendes Batch-Muster `attendedApplicantIds()`),
~~Vorlagen-Dropdown über die aktiven `certificate`-Vorlagen, bei genau einer
vorausgewählt.~~ Gilt für jeden Fall-Grund, nicht nur `insufficient_documents`.

> **[geändert v3] Kein Vorlagen-Dropdown** — es gibt nichts zu wählen. Die Checkbox allein, ohne zweites Feld. Damit fällt auch die Auto-Auswahl-Logik „bei genau einer Vorlage vorausgewählt" weg, die den Normalfall kaschieren sollte.
>
> **Dafür kommt ein Abschaltweg dazu, und der ist neu — nicht bloß umbenannt. [v3]** In v2 war `default_certificate_template_id` faktisch der Schalter: kein Wert gesetzt → nichts auszustellen. Mit festem HTML gibt es keinen solchen Wert mehr, und damit wäre der einzige Weg zurück ein Deploy. Deshalb:
>
> ```
> RecApplicantSettings::DEFAULT_SETTINGS['issue_training_certificates'] => false
> ```
>
> Ein Team-Setting „Zertifikate ausstellen: ja/nein", **Default aus**. Es gated beide Wege (C3 und C4) und wird an derselben Stelle geprüft wie `$canIssueCertificate` — also **vor** der Sichtbarkeit der Checkbox, nicht erst beim Ausstellen. Ist es aus, existiert das Feature für HR nicht, und kein Deploy ist nötig, um es stillzulegen.
>
> Default `false` heißt: nach dem Deploy passiert erst einmal nichts, bis jemand es bewusst einschaltet. Das ist die richtige Richtung für ein Feature, das personenbezogene Dokumente an abgelehnte Bewerber verschickt.

**Platzierung und Kollisionsfreiheit sind geprüft (G22): [v2]** die Checkbox
gehört direkt neben `sendRejectionMessage` (`hr-desk/index.blade.php:349-360`),
im gleichen `$resolvingAction === 'reject'`-Gate, mit einem bei Modal-Öffnung
berechneten `$canIssueCertificate` analog zu `$canSendRejectionMessage`. Der
`AT-*`-Select liegt auf der Karte (`:171-186`), nicht im Modal, und hat eigenen
State — keine UI- und keine Validierungskollision.

**Zwei Sends bei einer Ablehnung sind möglich. [v2]** Sind beide Checkboxen
gesetzt, gehen zwei separate Template-Sends raus (Jugendschutz-Absage und
Zertifikat-Link) — verschiedene Settings-Keys, verschiedene Meta-Templates. Sie
werden **nicht** zusammengelegt: ein gemeinsames Template hieße, den Absagetext
mit einem Zertifikat-Link zu vermischen, und beide Wege haben unterschiedliche
Zielgruppen (`minor` vs. `attended`). Die UI weist darauf hin, wenn beide
angehakt sind.

Eingehängt **innerhalb** der bestehenden Transaktion von `rejectCase()` (G12) —
als vierter Schritt in `applyRejection()` oder als Parameter an `rejectCase()`.
Alles oder nichts: keine Ablehnung ohne Zertifikat, kein Zertifikat ohne
Ablehnung. Der WhatsApp-Versand läuft **nach dem Commit** (§D).

**C4 — Weg (b): automatisch bei der Mitarbeiter-Anlage.** Vierter
Nachbereitungs-Schritt neben `transferEvaluationToHrData()` (G11, `:106`), im
dortigen try/catch-Muster, aber mit **eigenem Log-Marker**, damit ein Fehler
bei der Ausstellung nicht wie ein Fehler beim Bewertungs-Transfer gelesen wird.

Vorlagenwahl über ein neues Team-Setting `default_certificate_template_id`. Ist
keines gesetzt oder hat der Bewerber keine `attended`-Buchung, passiert nichts
(kein Fehler) — Direkteinstellungen und ZAS-Importe haben keine Schulung.

**Bewusst nicht pro Terminart konfigurierbar.** Eine FK von
`rec_interview_types` auf die Vorlage wäre die saubere Form, aber es existiert
genau eine Schulungsart — YAGNI. Upgrade-Pfad: das Team-Setting wird dann der
Fallback.

**Bewusst automatisch statt per Knopf.** Weg (b) betrifft jeden neuen
Mitarbeiter; ein Knopf wäre eine Aufgabe, die jemand vergisst.

**C5 — Unique-Constraint auf `(rec_applicant_id, kind)` [geändert v3]**
statt allein auf `rec_applicant_id` (Abweichung von §C1). Sonst blockiert die
erste Schulungsart jede zweite für denselben Menschen. Weiterhin auf DB-Ebene:
ein Doppelklick am Desk ist kein Sonderfall.

> **Die zweite Spalte hieß in v2 `rec_contract_template_id`.** Sie war die Dedup-Dimension, weil die Vorlage die Schulungsart *war*. Ohne Vorlage trägt eine eigene Spalte `kind` (string, NOT NULL, **ohne** Default) diese Rolle; der Wert kommt aus einer Konstante am Model, nicht aus einem Formular.
>
> **Warum nicht einfach `unique(rec_applicant_id)`:** das ist der naheliegende Reflex und er verbaut die zweite Schulungsart. Mit `kind` braucht sie nur ein Deploy mit einem zweiten HTML-Block — kein Schemawechsel auf einer dann gewachsenen Tabelle. Der Fall „zweite Schulungsart" ist deutlich wahrscheinlicher als „HR will den Text editieren", also ist das die Tür, die offen bleiben muss.
>
> Nebenwirkung, die dabei herausfällt: der Constraint-Name muss nicht mehr handgepflegt werden. `rec_training_certificates_rec_applicant_id_kind_unique` ist 54 Zeichen und passt unter die MySQL-Grenze von 64 — die v2-Variante kam mit `…rec_applicant_id_rec_contract_template_id_unique` auf **74** und brauchte deshalb einen expliziten Kurznamen.

Nebeneffekt: Wird ein abgelehnter Bewerber später doch eingestellt, greift das
Constraint und Weg (b) legt kein zweites Zertifikat derselben Schulungsart an. Die
Ausstellung behandelt diesen Fall als **Normalfall** (`firstOrCreate`-Semantik),
nicht als Fehler.

### §D Zustellung — nur für Weg (a), Link als Body-Variable

**D3 — Weg (b) verschickt nichts.** Der neue Mitarbeiter bekommt seine
Portal-Einladung ohnehin, und dort steht das Zertifikat (§F).

**D4 — Weg (a) nutzt `HoldingTemplateSender`** mit neuem Settings-Key
`training_certificate_wa_template_id`, Aufruf-Muster wie die
Jugendschutz-Absage (G6). Der PDF-Link geht als **Body-Variable** über
`$namedValues` (z.B. `{{zertifikat_link}}`), nicht als URL-Button: G7 — der
Sender kann Buttons nicht füllen, und ein Umbau von
`HoldingTemplateComponents` würde einen Pfad anfassen, der auch Holding,
Auto-Reply und Voice-Note-Antworten bedient. G8 schließt den einzigen
button-fähigen Pfad ohnehin aus. WhatsApp verlinkt URLs im Text automatisch
klickbar.

**Preis, benannt:** die vollständige URL steht im Nachrichtentext statt hinter
einem Button — bei einer UUID eine lange Zeile.

**D5 — Fehler kippen die Ablehnung nicht.** Versand nach dem Commit;
`sendToMany` fängt jeden `Throwable` pro Empfänger (`:72-74`) — **und `sendOne`
erbt das, weil es vollständig delegiert (G6)**; `resolveConfig` gibt
Konfigurationsfehler als `error`-String zurück statt zu werfen. Erfolg →
`wa_sent_at`; Fehler → Feld bleibt leer, Flash-Meldung, HR lädt das PDF herunter
und verschickt es von Hand. Ohne konfiguriertes Template wird trotzdem
ausgestellt. **[v2]** (Bestätigung, keine Änderung.)

**D6 — Warum der WhatsApp-Versand trotz vorhandenem Bewerber-Portal bleibt.
[v2]** G20 hat die v1-Begründung „nur die Abgelehnten haben kein Portal"
widerlegt: sie haben eines, unbegrenzt. Der Versand bleibt trotzdem, mit
korrigierter Begründung: **der Bewerber findet sein Portal nicht wieder.** Der
Portallink steckt in einer möglicherweise Monate alten Nachricht; nach einer
Ablehnung gezielt danach zu suchen, ist keine realistische Erwartung.

**§D1 bleibt und wird durch G20 gestützt, nicht geschwächt.** Der
Portal-Token öffnet Bewerbungsformular, Vertrags-PDFs und die gesamte
Vertragsliste; die Zertifikat-`uuid` öffnet **genau ein Dokument**. Für eine
Nachricht an einen abgelehnten Bewerber ist die engere Fläche die richtige. Wer
den Portallink schickt, verschickt einen Generalschlüssel, um ein Blatt Papier
zuzustellen.

### §F Portale **[v2]** (v1: „MA-Portal")

**F1 — Zertifikat-Zeilen an `contracts()` anhängen, in beiden Portalen.**
Nach den Vertragszeilen (G9/G20): `display_name` = Vorlagenname,
`signed_at` = `issued_at`, `sign_url` = `null`, `pdf_url` = die Route aus §D1,
`status` = `'issued'`. Die Array-Form ist in `EmployeePortal::contracts()`
(`:464-501`) und `ApplicantPortal::contracts()` (`:53-77`) identisch — dieselbe
Ergänzung, zweimal.

**F2 — Genau eine Blade-Anpassung pro Portal, und die Reihenfolge ist Pflicht.**
Der `issued`-Zweig muss **vor** die Bedingung auf
`employee-portal.blade.php:112` bzw. `applicant-portal.blade.php:42`
(`status === 'completed' || signed_at`) — sonst gewinnt sie, weil `signed_at`
gesetzt ist, und die Zeile behauptet „Unterschrieben am …" über ein Dokument,
das niemand unterschrieben hat (§B8). Richtig ist „Ausgestellt am …". Ohne den
Zweig gäbe der Rohwert-Fallback (`:133` bzw. analog) das Wort `issued` aus.

Zwei Dinge sind **ohne Änderung** korrekt und nur deshalb festgehalten, weil sie
die Wahl von `status = 'issued'` und `sign_url = null` begründen: ~~der
Unterschreiben-Button verlangt `sent`/`in_progress` (`:139` bzw. `:69`) →
bleibt weg~~; der PDF-Button hängt allein an `pdf_url` (`:146`) → erscheint von
allein.

> **KORRIGIERT [v3] — der Unterschreiben-Button ist DOPPELT verriegelt, nicht einfach.**
>
> Die einfache Fassung („verlangt `sent`/`in_progress`") war **falsch**, und die Art des Findens ist der Punkt: **die Mutation blieb grün, niemand hat die Prosa gelesen.** In Task 14 wurde `'issued'` probeweise in die Statusliste aufgenommen — der Button erschien **trotzdem nicht.**
>
> Tatsächlich sind es **zwei unabhängige Riegel**: `!signed_at && in_array($status, [...])`. Erst als der zweite Test die `signed_at`-Klausel entfernte, wurde die Mutation rot. Da Zertifikate `signed_at = issued_at` tragen, hält bei ihnen **schon der erste Riegel** — der Statuswert ist für den Button gar nicht ausschlaggebend.
>
> **Was `status = 'issued'` wirklich trägt: den Badge**, nicht die Button-Freiheit. Die Wahl bleibt richtig, die Begründung war es nicht. Wer den Statuswert später ändert, darf sich nicht auf diesen Absatz verlassen, sondern muss beide Riegel nachsehen.

**F3 — `ApplicantPortal:78` muss Zertifikate mitzählen. [v2]** Die Zeile setzt
`state = count($contracts) === 0 ? 'empty' : 'ready'` (G20). Ein abgelehnter
Nicht-EU-Bewerber hat typischerweise **keine** Verträge — bleibt die Zählung wie
sie ist, liegt sein Zertifikat in einem Portal, das sich für leer erklärt. Der
Zähler läuft nach der Ergänzung aus F1 über die vereinigte Liste.

Überschrift bleibt „Deine Verträge" (Kundenwunsch: „unter den Verträgen
aufrufbar"). `signed_at` wird mit `issued_at` befüllt, statt einen zweiten
Datums-Key einzuführen — die Zeile soll denselben grünen Erledigt-Zustand
tragen wie ein fertiger Vertrag, nur mit anderem Wort.

## Tests & Verifikation

**Pure-Unit (PHPUnit, Modul-Konvention — kein Laravel, keine DB; G19):**

- `schulung.leiter`-Auflösung: ein Interviewer, zwei (`join(', ')`), keiner
  (leerer String), Buchung ohne Termin.
- Buchungs-Selektion aus §B5/§B7 als pure Funktion: `starts_at DESC` mit
  Tie-Break `id DESC`, explizit der Umbuchungsfall (jüngstes Insert ≠ spätester
  Termin).
- Ausstell-Freigabe als pure Funktion: `attended`? Vorlage vorhanden? bereits
  ausgestellt?
- Asset-Pfad-Auflösung: alle vier Dateien vorhanden / je eine fehlt → `null`
  plus Log-Marker statt Exception (G3-Semantik).
- **`TrainingCertificateHtml::build()`: Basis-Styles vorhanden, Fuß-Klassen
  vorhanden, Content unverändert eingesetzt. [v2]**
- **`TrainingCertificatePdfOptions::for()`: enthält `chroot`, und der Font-Pfad
  liegt darunter. [v2]**
- **`FontGlyphCoverage::missing()`: ★ gegen Oswald → gemeldet; derselbe Text
  gegen DejaVu → leer; Umlaute gegen Oswald → leer. [v2]**

**Harness (sqlite, Muster Warteliste/Dedup-Guard):**

- Unique-Constraint `(rec_applicant_id, rec_contract_template_id)`: zweites
  Zertifikat derselben Vorlage abgewiesen, andere Vorlage geht durch.
- Weg (a): Fehler bei der Ausstellung → Ablehnung **nicht** committed.
- Weg (b): zweiter `createOrUpdate()`-Lauf legt kein zweites Zertifikat an.
- **§B8-Hook, beide Invarianten: `requires_signature` wird bei Typwechsel auf
  `false` gezogen; ein `code` ohne `ZERT-`-Präfix wirft. [v2]**
- `type`-Guard in `SendContractsService`: Zertifikat-ID als
  `contract_template_id` → Exception statt Vertrag.
- **`Applicant/Show.php:750`: Zertifikat-ID durch die `exists:`-Regel und dann
  in `findOrFail` → muss abgewiesen werden. [v2]** Die gefährlichste Stelle der
  Guard-Landkarte bekommt ihren eigenen Test.

**Render-Test (Erstnachweis, nicht Absicherung — der Prototyp ist kein Code).
[v2]** Läuft als Integrationstest mit `Dompdf\Dompdf` direkt und **den Optionen
aus `TrainingCertificatePdfOptions`** (E1), nicht mit selbst gesetzten:

1. **Genau eine Seite** — `preg_match_all('/\/Type\s*\/Page[^s]/')` auf
   `$dompdf->output()`, auch mit Worst-Case-Inhalt: langer Doppelname, zwei
   Interviewer, längste Kursbezeichnung. Deckt G13.5 ab.
2. **Die Schrift ist eingebettet** — `preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/')`
   enthält `Oswald-SemiBold`. Deckt G13.1 ab, den stillen Helvetica-Fallback.
3. **Keine fehlenden Glyphen** — `FontGlyphCoverage::missing()` auf dem
   personalisierten Inhalt ist leer. Deckt G13.3 ab, **am Eingang** statt im
   komprimierten UTF-16BE-Stream (G13.7).
4. **Keine unaufgelösten Platzhalter** —
   `ResttagePlaceholder::hasUnresolvedPlaceholder()` auf dem personalisierten
   Inhalt ist `false`. Wiederverwendung statt Neubau (G18).

**Mechanik-Auflage: keine `grep`- und keine Literal-String-Assertions.** G13.6
hat gezeigt, dass `grep -c "/Type /Page"` und `grep -c "/BaseFont"` auf einem
DomPDF-PDF je 0 Treffer liefern. Wer so assertiert, baut einen Test, der immer
grün ist.

**Regressionstest Bestandsverträge (belegt G17):** Vor der ersten Änderung das
PDF eines **bereits signierten** Arbeitsvertrags und eines IFSG rendern und
ablegen. Nach dem Bau erneut rendern und vergleichen — nicht byteweise (PDFs
enthalten Erzeugungszeit und Datei-ID), sondern:

- extrahierter Text identisch,
- Liste der eingebetteten Fonts identisch (`/BaseFont`-Vorkommen),
- Seitenzahl identisch,
- Firmenstempel weiterhin vorhanden (`/Subtype /Image`-Zähler).

**Die Font-Liste wird als SOLL eingefroren** — gemessener Wert
`['DejaVuSans', 'DejaVuSans-Bold']` (G24, in Task 0 korrigiert). Wer die
Font-Situation später bewusst ändert, aktualisiert den SOLL-Wert und begründet
es im Commit — der Test soll nicht fehlschlagen, weil jemand etwas verbessert
hat, und er soll nicht schweigen, wenn sich etwas ungeplant verschiebt.

Weicht etwas ab, ist die Trennung aus G17 verletzt. Der Test läuft gegen einen
echten Bestandsvertrag, nicht gegen eine Fixture — es geht um die Vorlagen, die
live in Benutzung sind.

**V2 — Live-Abfrage vor dem Bau (Pivot-Befüllung, G10).** Über MCP nicht
erreichbar (kein Tool auf `rec_interview_user`), daher auf dem Server:

```sql
-- Vorflug (Nenner): Termine mit mindestens einer attended-Buchung
SELECT COUNT(DISTINCT i.id) AS termine_mit_attended
FROM rec_interviews i
JOIN rec_interview_bookings b ON b.rec_interview_id = i.id
WHERE b.status = 'attended' AND b.deleted_at IS NULL;

-- Davon ohne eingetragenen Interviewer → Zertifikat-Zeile bliebe leer
SELECT COUNT(DISTINCT i.id) AS termine_ohne_interviewer
FROM rec_interviews i
JOIN rec_interview_bookings b ON b.rec_interview_id = i.id
WHERE b.status = 'attended' AND b.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM rec_interview_user iu WHERE iu.rec_interview_id = i.id
  );

-- Verteilung: wie viele Namen muss die Zeile tragen?
SELECT anzahl_interviewer, COUNT(*) AS termine FROM (
  SELECT i.id,
         (SELECT COUNT(*) FROM rec_interview_user iu
          WHERE iu.rec_interview_id = i.id) AS anzahl_interviewer
  FROM rec_interviews i
  JOIN rec_interview_bookings b ON b.rec_interview_id = i.id
  WHERE b.status = 'attended' AND b.deleted_at IS NULL
  GROUP BY i.id
) x GROUP BY anzahl_interviewer ORDER BY anzahl_interviewer;
```

**Auslegung, vorab festgelegt:** Ist `termine_ohne_interviewer` klein (≤2),
bleibt es beim leeren String. Ist es groß, kommt ein Team-Setting
`training_certificate_leader_fallback` als Rückfall dazu — eine Erweiterung von
§B7 um einen `?:`-Ausdruck, kein Umbau. Zeigt die dritte Abfrage regelmäßig ≥3
Interviewer, wird §B7 auf „erster Interviewer" umgestellt und der Render-Test um
diesen Fall erweitert.

**Live-Sichttest nach Deploy:**

1. Zertifikat-Vorlage per Command anlegen (E10.3), im Editor öffnen, Test-PDF
   prüfen (E8) — und einmal einen nackten `<p>` ergänzen, um E10.1 zu belegen.
   **Zusätzlich „Zeichen prüfen" mit einem eingefügten ★ auslösen und die
   Meldung sehen. [v2]**
2. Testbewerber mit `attended` am HR-Schreibtisch ablehnen, Zertifikat anhaken →
   WhatsApp kommt an, Link **zeigt** das PDF (nicht Download, E1), vier Werte
   sitzen. **[v2]**
3. Fall zu, Bewerber inaktiv, **kein** Vertrag und **kein** Portallink erzeugt.
   **Bewerber-Portal öffnen: Zertifikat-Zeile sichtbar, Portal nicht mehr
   „leer" (F3). [v2]**
4. Testbewerber mit `attended` einstellen → Zertifikat entsteht ohne Zutun und
   steht im MA-Portal unter „Deine Verträge" mit „Ausgestellt am …".
5. Direkteinstellung ohne Schulung → **kein** Zertifikat.

## Testreihenfolge — bekannter Bestandsdefekt, bewusst nicht gelöst

**Die Suite ist nur unter der Default-Reihenfolge verlässlich grün.** `phpunit.xml`
setzt deshalb bewusst kein `executionOrder`; ein Kommentar an genau dieser
Stelle in der Datei sagt das und verweist hierher.

**Ursache.** Die Integrationstests des Moduls bauen Container und Capsule von
Hand auf (kein Laravel-Bootstrap, kein testbench). Mehrere Testklassen booten
dabei Eloquent-Modelle im **geteilten** PHPUnit-Prozess, teils mit, teils ohne
Event-Dispatcher. Eloquents `$booted`-Cache ist statisch: wer eine Modellklasse
zuerst ohne Dispatcher instanziiert, lässt deren `creating`-Hooks für alle
späteren Testklassen still ausfallen. Symptom ist dann etwa
`NOT NULL constraint failed: rec_contract_templates.uuid` — **nur im
Gesamtlauf, nie im gefilterten.**

**Messung (2026-08-12, 12 Seeds, zufällige Reihenfolge):**

| Seed | main `511451c` | feat/schulungszertifikat |
| --- | --- | --- |
| 3, 99 | ROT | ROT |
| 2, 42, 1234 | grün | **ROT** |
| 1, 4, 5, 7, 11, 23, 31337 | grün | grün |

**Auslegung, präzise:** Der Defekt ist **vorbestehend** — zwei Seeds brechen
auch ohne die Commits dieses Branches. Die Arbeit an diesem Paket hat die
**Angriffsfläche verbreitert**, weil weitere Testklassen mit Eloquent-Hooks
hinzugekommen sind: drei zusätzliche Reihenfolgen brechen nur hier. Unter der
tatsächlich konfigurierten Reihenfolge sind beide Stände grün.

### Vier Vertreter desselben Defekts, gesammelt — das Material für die Basisklasse [v3]

Der Defekt hat **nicht eine** Ursache, sondern vier verschiedene geteilte Statics. Das ist der Grund, warum punktuelle Fixes ihn nicht erledigen, und das Material, mit dem eine gemeinsame Integrations-Basisklasse später begründet wird:

| # | Geteilter Zustand | Symptom | Gefunden in | Punktueller Fix |
| --- | --- | --- | --- | --- |
| 1 | `Model::$booted` (Eloquent) | `NOT NULL constraint failed: …uuid` — `creating`-Hooks fallen still aus | Task 0 / Task 3 | `Model::clearBootedModels()` beim Verursacher |
| 2 | `Facade::$resolvedInstance` | `no such table: core_extra_field_definitions` — `Schema::` und `DB::` landen auf **verschiedenen** In-Memory-DBs, weil `setFacadeApplication()` den Instanz-Cache nicht leert | Task 6a | `Facade::clearResolvedInstances()` beim Verursacher (`DuplicateMatchQueryTest`) |
| 3 | Globale Capsule / `Model::$resolver` | Klassen, die alphabetisch **nach** `PlaceholderResolutionPinTest` sortieren, erben dessen `setAsGlobal()`-Verbindung auf eine nicht mehr gebrauchte DB | Task 6a-Review | keiner — nur benannt |
| 4 | Statischer Definitions-Cache in `HasExtraFields` (`"Klasse:id"`) | **leere** Cache-Einträge lecken in die nächste Testklasse und kippen zwei fremde Tests; gefiltert grün, im Gesamtlauf rot | Task 13 | Reset im `tearDown()` der neuen Klasse |

**Das Muster über alle vier:** prozessweiter statischer Zustand, Symptom **nur im Gesamtlauf**, und der punktuelle Fix sitzt jeweils beim *Verursacher* oder beim *Opfer* — nie an einer Stelle, die alle vier abdeckt. Jede neue Integrationstestklasse muss heute vier Aufräumzeilen kennen, deren Notwendigkeit sie einzeln nicht beurteilen kann. In Task 12 trug **keine** der drei damals bekannten Zeilen, Grund war allein die alphabetische Reihenfolge — die Zeilen werden also auch dort geschrieben, wo sie nichts tun, weil niemand es messen kann.

**Was die Basisklasse leisten müsste:** Container, Capsule, Facade-Instanzen, Eloquent-Boot-Cache und den `HasExtraFields`-Cache einheitlich in `setUpBeforeClass()`/`tearDownAfterClass()`. Erst dann wird `--order-by=random` überhaupt angreifbar, und erst dann verschwinden die drei Kopien der Auth-Guard-Stub-Klasse. **Nicht Teil dieses Pakets** — eigenes Ticket, und dieser Abschnitt ist seine Begründung.

**Was in diesem Paket dagegen getan wurde** — punktuell, nicht strukturell:
`ContractPdfRegressionTest` räumt in `tearDownAfterClass()` mit
`Model::clearBootedModels()` hinter sich auf (es ist der Verursacher, weil es
Modelle per `new` ohne Dispatcher instanziiert), und
`ContractTemplateTypeInvariantsTest` ruft dasselbe defensiv in
`setUpBeforeClass()`. Zwei Stellen, weil keine allein reicht.

**Nachhaltige Lösung, ausdrücklich NICHT Teil dieses Pakets:** eine gemeinsame
Basisklasse für Integrationstests, die Boot-Cache und Dispatcher einheitlich
aufsetzt. Sie berührt Bestandstests, die mit dem Zertifikat nichts zu tun
haben — eigenes Ticket.

## Benannte Tradeoffs

### Aufgegeben mit dem Zuschnitt v3

Drei Dinge, bewusst und mit Preisschild. Sie stehen zuerst, weil sie die
teuersten Zusagen dieser Spec sind.

- **HR kann den Text nicht selbst ändern.** Jede Formulierung, jeder
  Kenntnispunkt, jeder Tippfehler braucht einen Entwickler und ein Deploy. Bei
  einem Text, der sich seit der Word-Vorlage nicht geändert hat, ist das
  tragbar — bei einem, den jemand „nur schnell" anpassen will, ist es lästig.
  **Was es dafür ausschließt:** dass HR das Layout zerlegt, die Fuß-Klassen
  vergisst (dann fehlt der Schulungsleiter lautlos), die ★ ohne
  `<span class="zeichen">` schreibt (dann steht `?` im PDF) oder die
  Kenntnisliste auf 20 Zeilen erweitert (dann sind es zwei Seiten, §E5).
- **Eine zweite Schulungsart braucht ein Deploy.** Sie braucht dank der
  `kind`-Spalte **keinen** Schemawechsel und keine Migration — nur einen zweiten
  HTML-Block und einen zweiten Konstantenwert. Das ist der wahrscheinlichere der
  beiden Erweiterungsfälle, deshalb ist er der billigere.
- **Der Rückweg kostet ungefähr das, was wir jetzt einsparen.** Wird der Inhalt
  wieder eine Vorlage, kommen zurück: der `schulung.`-Zweig in
  `resolveSource()`, der Vorlagen-Editor mit Typ-Dropdown, der Seed-Command und
  **die 22 Guards aus der Landkarte** — die sind der Brocken. Dazu eine
  Migration, die `kind` durch eine Vorlagen-ID ersetzt oder ergänzt.

  **Zwei Dinge machen ihn billiger, als er klingt.** Die *Analyse* ist erledigt
  und versioniert: `docs/zertifikat/guard-landkarte-511451c.md` bleibt liegen,
  61 Zeilen, drei Greps, Gegenrichtung, mit dem Grep-Kommando in Zeile 1 und
  einem Vermerk oben, dass sie nicht ausgeführt wurde. Nur die Ausführung fehlt,
  nicht die Untersuchung. Und die *Tür* ist offen gelassen: die Spalte `type`
  (Migration `2026_08_12_000001`) und die Invarianten in `RecContractTemplate`
  existieren, laufen leer und sind an beiden Stellen als tote Schalter
  kommentiert — der `ZERT-`-Präfixzwang greift sofort, wenn doch jemand eine
  Zertifikat-Vorlage anlegt.

  **Was der Rückweg NICHT kostet:** Datenmigration. Der Snapshot in
  `personalized_content` ist vollständig; bereits ausgestellte Zertifikate
  rendern unabhängig weiter.

- **Ohne Snapshot wäre das Dokument nicht stabil — deshalb bleibt er, obwohl der
  Text jetzt fest ist.** Er hält die drei variablen Werte zum
  Ausstellungszeitpunkt fest. Ohne ihn würde bei jedem Download neu aufgelöst,
  und ein im August ausgestelltes Zertifikat zeigte im Dezember ein anderes
  Ausstellungsdatum — und womöglich einen anderen Schulungsleiter, weil
  Interviewer an einer Buchung nachgetragen werden können (G10). Er sieht beim
  nächsten Aufräumen redundant aus, jetzt wo der Text konstant ist. **Ist er
  nicht.** Steht auch als Kommentar an der Migration.

### Weiterhin gültig

- **Der Letterpress-Charakter ist weg.** Oswald ist nah, aber nicht die
  Originalschrift; die Papierstruktur des Scans fehlt. Die Inline-Kontur ist
  nur dort erhalten, wo sie als Bild kommt („ZERTIFIKAT"), nicht beim Kursnamen.
  Bewusst akzeptiert für den Editor-Charakter (§E7).
- **Zwei Bildunterschriften in zwei Schriften.** Links kommt
  „RHEINGEDECK GMBH" aus dem Scan-Bild, rechts steht „SCHULUNGSLEITER" in
  Oswald. Wegzukriegen wäre das nur durch Retusche der Bildunterschrift aus dem
  Unterschriften-Block, was am durchlaufenden Abstrich scheitert (E2).
- **Die Einseitigkeit ist inhaltsabhängig und wird NICHT strukturell erzwungen.
  [v3]** Der wichtigste Tradeoff des Pakets, und v1/v2 haben ihn als gelöst
  geführt. Gemessen: 4 Zeilen Kenntnisliste → 1 Seite, 10 → 1 Seite, **20 → 2
  Seiten**. Die Fuß-Verankerung (§E5) nimmt den Fuß aus dem Fluss, sie hindert
  den Mittelteil nicht am Überlaufen. Die Einseitigkeit ist damit eine
  Eigenschaft des ausgelieferten Vorlageninhalts — und der liegt in einem
  Textarea, in das HR schreiben darf. **Wächter dagegen:** die
  Seitenzahl-Anzeige am Test-PDF-Knopf (§E8, load-bearing) und die
  Seitenzahl-Assertion im Render-Test (§Tests) für die ausgelieferte Vorlage.
  Kein Gate, weil HR Zwischenstände speichern können muss. Widerspruch, den man
  hätte sehen können: G13.5 stand als gemessener Fakt schon in v1
  („Fließlayout garantiert keine Einzelseite") — §E5 hat ihm widersprochen und
  niemand hat die zwei Stellen gegeneinander gelesen.
- **Der stille Helvetica-Fallback bleibt ein Risiko im Betrieb.** G13.1 hat
  keinen Fehlerpfad; nur der Render-Test fängt ihn. Bricht die Schriftdatei
  oder wird `storage/fonts` unschreibbar, sieht das Zertifikat plötzlich anders
  aus, ohne dass etwas protokolliert wird. **[v3]** Präzisierung: eine
  *abgeschnittene* Datei fängt auch die Glyph-Prüfung nicht — die `cmap` liegt
  im erhaltenen Kopf, 40 % und 5 % der Datei liefern identisch 737 Einträge wie
  das Original. Nur `/BaseFont` im PDF unterscheidet die Stufen, also Test-PDF
  und Render-Test. Dass der Editor-Knopf in der App trotzdem meist meldet, ist
  ein Nebeneffekt von Laravels Error-Handler (§E8) und keine Absicherung.
- **Abgelehnte, inaktive Bewerber behalten unbegrenzten Portalzugriff mit einem
  nie verfallenden Token (G20) — und wir hängen ein weiteres personenbezogenes
  Dokument dort hinein. [v2]** Bestandsbefund, nicht von diesem Paket
  verursacht: die Route prüft weder `rejected_at` noch `is_active`, und der
  Token wird nirgends rotiert oder invalidiert. **Keine Reparatur in diesem
  Scope** — sie berührte Token-Lebenszyklus, Bewerbungsformular und
  Vertrags-PDF-Zugriff und wäre ein eigenes Vorhaben. Hier nur benannt, damit
  die Entscheidung bewusst ist.
- **Weg (b) trägt Altbestand nicht nach.** Der Hook sitzt an der Erst-Anlage
  (G11); bestehende Mitarbeiter bekommen rückwirkend keins. Wer das will,
  braucht ein eigenes Command — nicht Teil dieses Scopes.
- **Nur als PDF sinnvoll** (§E9) — anders als der Vertrag, den es auch als
  Bildschirmansicht gibt.
- **Falsche Vorlage nur per DB korrigierbar** (C5).
- **Zwei WhatsApp-Nachrichten bei einer Ablehnung**, wenn beide Checkboxen
  gesetzt sind (§C3). Bewusst nicht zusammengelegt. **[v2]**

**Entfallen gegenüber v1:**

- ~~„Sonderzeichen sind eine Falle für Vorlagen-Autoren … der Render-Test prüft
  das nur für die ausgelieferte Vorlage"~~ — geschlossen durch die
  Editor-Prüfung in §E8. **[v2]**
- ~~„Settings-Select speichert nicht (bekannter Bug)"~~ — durch G21 widerlegt:
  das bestehende WA-Template-Select funktioniert mit
  `wire:model.live` + explizitem `:value`, es gibt kein `@entangle` und keinen
  JSON_SET-Workaround im Code. **Kopiermuster, kein Risiko.** **[v2]**

## Live-Sichtprüfung nach dem Deploy — die Liste, die kein Test abdecken kann [v3]

**Warum es diese Liste gibt:** das Modul hat **kein Laravel im Testlauf** (G19). Sieben Eigenschaften des PDF-Controllers sind deshalb von keinem Test gedeckt — nicht aus Nachlässigkeit, sondern strukturell. Ein einziger Feature-Test **im Host-Projekt** würde sechs davon abdecken; im Modul geht das nicht. Die Liste ist beim **ersten Klick nach dem Deploy** abzugehen, in dieser Reihenfolge:

| # | Zu prüfen | Woran man es sieht | Wenn falsch |
|---|---|---|---|
| 1 | Die Route antwortet überhaupt | `/recruiting/zertifikat/{uuid}` mit echter uuid → PDF, kein 500 | `storage/fonts` nicht anlegbar (§Deploy) — die Fehlermeldung nennt Pfad und Grund |
| 2 | **`->stream()`, nicht `->download()`** | PDF wird **im Browser angezeigt**, kein Download-Dialog | Der WhatsApp-Link zwingt zum Download; Bewerber auf Mobilgeräten sehen nichts |
| 3 | **Die Schrift ist wirklich Oswald** | Überschriften schmal und hoch, nicht Helvetica-breit. Im Zweifel: PDF-Eigenschaften → eingebettete Fonts | Stiller Helvetica-Fallback (G13.1) — kein Fehler, nur ein anderes Dokument |
| 4 | Unbekannte uuid | erfundene uuid → **404**, keine Fehlerseite | `firstOrFail()` greift nicht |
| 5 | Die drei Bilder sind da | Logo oben, „ZERTIFIKAT"-Schriftzug, Unterschriftsblock unten links | Assets fehlen im dist-Zip (§Deploy) — dann steht eine `warning`-Zeile im Log |
| 6 | **Ins Log sehen, nicht nur aufs PDF** | nach dem ersten Aufruf: keine `warning` mit `missing` | Ein fehlendes Bild rendert das PDF **ohne Fehler** — das Log ist der einzige Kanal |
| 7 | Die Reihenfolge der Render-Schritte | (nur bei Auffälligkeiten) — Optionen werden **nach** `loadHTML()` gesetzt, das ist Absicht (§E1) | Wer sie „aufräumt", bekommt kein Fehler, sondern ein anderes PDF |

**Punkte 2 und 3 sind die wichtigsten**, weil sie beide zur Fehlerklasse dieses Pakets gehören: kein Absturz, kein rotes Signal, nur ein falsches Dokument. Ein Download statt einer Anzeige fällt erst auf, wenn ein Bewerber es meldet — und der meldet nichts, er klickt nur nicht weiter.

## Deploy

- **Zwei-Push-Struktur:** Migrationen zuerst (`type`-Spalte,
  `rec_training_certificates`), Feature danach. Das Feature bringt eine neue
  öffentlich erreichbare Route; ein Feature-Deploy vor der Migration erzeugt
  dort 500er. **Bleibt gültig — Begründung weiter unten ausdrücklich
  nachgeprüft. [v3]**
- **Fünf Assets müssen im Push sein** (E2, seit 17.08.2026). Fehlt ein Bild, rendert das PDF
  ohne dieses Element (G3-Semantik: `null` statt Fehler); fehlt die Schrift,
  läuft alles in Helvetica (G13.1). Beides ist kein Absturz und beides ist
  falsch — deshalb loggt **der aufrufende Controller** jedes fehlende Asset als
  `warning`; die Hülle selbst bleibt laravel-frei und lässt fehlende Bilder
  still weg. Aufgelöst werden die Assets von einer geteilten Klasse
  `Support/TrainingCertificateAssets`, die Controller, Editor-Vorschau und
  Render-Test gemeinsam benutzen — sonst liefe die Vorschau gegen andere
  Pfade als die Ausstellung. **[v2]**
- **`storage/fonts` wird beim ersten Render automatisch angelegt; schlägt das
  fehl, gibt es einen sprechenden Fehler statt eines Fatals. [v3, hochgezogen]**
  Vorher stand hier „muss existieren und schreibbar sein (G14) — erster Render
  nach Deploy prüfen". Das war zu schwach, und der Review hat gezeigt, warum:

  Gemessen im Host — `meingedeck/storage/` enthält nur `app`, `framework`,
  `logs`, es gibt **kein** `config/dompdf.php`, und der Paket-Default zeigt auf
  `storage_path('fonts')`. Mit fehlendem Verzeichnis:

  ```
  PHP Warning: fopen(.../zert_normal_….ufm): Failed to open stream: No such file or directory
               in php-font-lib/src/FontLib/AdobeFontMetrics.php on line 44
  -> TypeError: fwrite(): Argument #1 ($stream) must be of type resource, false given
     in AdobeFontMetrics.php:226
  ```

  Der Fatal passiert in `render()`, also **vor jeder Ausgabe** → 500 auf **100 %**
  der Aufrufe, auf genau dem Link, der per WhatsApp an abgelehnte Bewerber geht.
  **Der erste echte Bewerber wäre der Fehlerfall gewesen.**

  Warum es niemand bemerkt hätte: das Zertifikat ist das **erste
  `@font-face`-PDF der ganzen Host-App**. Verträge rendern mit gebündeltem
  DejaVu Sans und brauchen nie einen schreibbaren Fontordner.

  **`storage/fonts` liegt nicht im Git** (`storage/` ist bis auf `.gitignore`-Reste
  leer im Repo) — es fehlt also auf jedem neu aufgesetzten Server erneut. Genau
  deshalb liegt die Absicherung im **Code** und nicht in einer Ops-Zeile: Ops-Wissen
  verfällt beim nächsten Server, Code nicht. Die Klasse legt das Verzeichnis an,
  prüft **Schreibbarkeit** (nicht nur Existenz — ein existierendes, nicht
  beschreibbares Verzeichnis erzeugt denselben Fatal) und **wirft** mit Pfad und
  Grund, wenn das nicht geht. **Kein Fallback auf ein anderes Verzeichnis** — das
  ist genau, was DomPDF tut (stiller Rückfall auf
  `vendor/dompdf/dompdf/lib/fonts`) und was die Isolation aufhebt.
- **Nach Merge und Bump: die Route einmal live aufrufen. [v3]**
  `/recruiting/zertifikat/{uuid}` mit einem echten Datensatz, und **hinsehen**:
  wird das PDF **inline** angezeigt (nicht heruntergeladen), und steht der Text in
  **Oswald** (nicht Helvetica)? Beides ist im Test abgesichert, aber der Test
  rendert gegen die Arbeitskopie, nicht gegen das installierte Paket.
- **Prüfen, dass `resources/fonts` und `resources/images/certificates` im
  installierten Paket ankommen. [v3]** Das Modul wird als dist-Zip nach
  `meingedeck/vendor/` installiert; fehlen die Verzeichnisse dort, gibt es
  **ein PDF ohne Bilder in Helvetica — keinen Fehler, nur ein falsches
  Dokument.** Genau die Fehlerklasse, die dieses Paket zehnmal getroffen hat: es
  sieht plausibel aus, nichts wird rot, niemand erfährt davon.

  Der Controller loggt jedes fehlende Asset als `warning` (§E1,
  `TrainingCertificateAssets::resolve()`), das ist der einzige Kanal. Also
  **nach dem ersten Live-Aufruf ins Log sehen**, nicht nur aufs PDF. Prüfbefehl
  auf dem Server:
  `ls -la meingedeck/vendor/martin3r/platform-recruiting/resources/fonts meingedeck/vendor/martin3r/platform-recruiting/resources/images/certificates`
- **Keine `config/dompdf.php` nötig** (G14). Falls später eine angelegt wird,
  muss `chroot` weiterhin `base_path()` umfassen, sonst fällt die Schrift
  stumm weg.
- **`composer.lock`-Bump in `meingedeck` nach jedem Push** — sonst nicht live.
  **Für die Modul-Tests nicht erforderlich: der Runner lädt aus der
  Arbeitskopie (G19). [v2]**
- **Kein `queue:restart`:** WA-Versand ist synchron, Ausstellung und Rendering
  laufen im Request. Kein Worker-Code in diesem Paket.
- **Vor Live nötig, außerhalb Code [nachgezogen v3]:** `Oswald-SemiBold.ttf` aus
  `googlefonts/OswaldFont` samt `OFL.txt` ins Modul legen (G15), die drei
  freigestellten PNGs ablegen (besser: Logo und Unterschrift im Original von
  RheinGedeck statt aus dem Scan), WhatsApp-Template bei Meta einreichen und
  genehmigen lassen (**Body-Variable für den Link**, kein URL-Button), Setting
  `training_certificate_wa_template_id` setzen (Muster G21).

  **Entfallen:** ~~Zertifikat-Vorlage per Command anlegen (E10.3)~~ — der Inhalt
  ist Teil des Deploys, es gibt keinen Seed-Command.
  ~~Setting `default_certificate_template_id` setzen~~ — es gibt keine Vorlagen-ID.

  **Neu, und es ist ein eigener Schritt, keine Fußnote:** Setting
  `issue_training_certificates` auf `true` setzen. **Default ist `false`**, das
  Feature ist nach dem Deploy also aus, bis jemand es bewusst einschaltet — und
  das ist die gewollte Richtung für ein Feature, das personenbezogene Dokumente
  an abgelehnte Bewerber verschickt. Wer nach dem Deploy testet und „es passiert
  nichts" beobachtet, prüft zuerst dieses Setting. Es ist zugleich der
  **Abschaltweg ohne Deploy**: in v2 war `default_certificate_template_id`
  faktisch dieser Schalter (kein Wert → nichts auszustellen), und ohne Ersatz
  wäre der einzige Weg zurück ein Deploy gewesen.

- **Zwei-Push-Struktur bleibt nötig [v3, ausdrücklich geprüft].** Der Grund ist
  unverändert vorhanden: das Feature bringt weiterhin eine neue öffentlich
  erreichbare Route (`/recruiting/zertifikat/{uuid}`, §E9/Task 10), die auf
  `rec_training_certificates` auflöst. Ein Feature-Deploy vor der Migration
  erzeugt dort 500er. Der Zuschnitt v3 ändert daran nichts — er nimmt nur die
  `type`-Spalte aus der *Notwendigkeit* heraus (sie hat keinen Konsumenten mehr),
  nicht die Tabelle. Bliebe nur die `type`-Spalte, könnte man zusammenlegen; mit
  `rec_training_certificates` nicht.
- ~~**Merge-Gate: `docs/zertifikat/guard-landkarte-511451c.md` — alle 20
  Handlungszeilen abgehakt. [v2]**~~ **ENTFÄLLT [v3].** Abzuhaken ist nichts: es
  landet keine Zeile in `rec_contract_templates`, also greift keine der 22
  Handlungszeilen. Die Datei bleibt als versionierte Analyse für den Rückweg
  liegen und trägt oben einen Vermerk. **Damit hat dieses Paket kein
  Merge-Gate-Artefakt mehr** — das ist eine Folge der Zuschnitt-Entscheidung und
  soll nicht als Versehen gelesen werden.

## Betroffene Dateien

**Neu**
- `database/migrations/*_add_type_to_rec_contract_templates.php`
- `database/migrations/*_create_rec_training_certificates_table.php`
- `src/Models/RecTrainingCertificate.php`
- `src/Services/IssueTrainingCertificateService.php`
- `src/Http/Controllers/TrainingCertificatePdfController.php`
- `src/Console/Commands/SeedTrainingCertificateTemplate.php` (E10.3)
- **`src/Support/TrainingCertificateHtml.php` (E1) [v2]**
- **`src/Support/TrainingCertificatePdfOptions.php` (E1) [v2]**
- **`src/Support/FontGlyphCoverage.php` (E8) [v2]**
- **`src/Support/TrainingLeaderResolver.php` (§B7) [v2]**
- `resources/fonts/Oswald-SemiBold.ttf` + `resources/fonts/OFL.txt`
- `resources/images/certificates/{logo,headline-zertifikat,signature-block}.png`
- `tests/Unit/*`, `tests/Integration/*` (s. §Tests)

**Entfällt gegenüber v1: [v2]**
- ~~`resources/views/pdf/training-certificate.blade.php`~~ — ersetzt durch
  `TrainingCertificateHtml` (E1). Keine Blade im Zertifikat-Weg.

**Ändern**
- `src/Models/RecContractTemplate.php` — `type` + Konstanten, saving-Hook mit
  beiden Invarianten (§B8), `schulung.`-Zweig delegiert an
  `TrainingLeaderResolver`
- `src/Services/HrDeskRoutingService.php` — Ausstellung in `applyRejection`
- `src/Livewire/HrDesk/Index.php` + Blade — Checkbox neben
  `sendRejectionMessage` (G22), Vorlagen-Dropdown, Download, Erneut senden
- `src/Services/CreateEmployeeFromApplicantService.php` — Weg (b) neben `:106`
- `src/Livewire/Public/EmployeePortal.php` + `employee-portal.blade.php` —
  Zertifikat-Zeilen, `issued`-Badge
- **`src/Livewire/Public/ApplicantPortal.php` + `applicant-portal.blade.php` —
  Zertifikat-Zeilen, `issued`-Badge, `state`-Zählung (§F3) [v2]**
- `src/Livewire/ContractTemplates/Index.php` + Blade — Typ-Feld, Badge,
  Platzhalter-Hilfe, Test-PDF- und Zeichen-prüfen-Knopf (E8)
- `src/Models/RecApplicantSettings.php` — zwei neue Settings
- `routes/public.php` — Zertifikat-Route
- **Die 20 Handlungszeilen aus `docs/zertifikat/guard-landkarte-511451c.md`
  [v2]** (ersetzt die veraltete Aufzählung aus §B4 der Vorgänger-Spec)
