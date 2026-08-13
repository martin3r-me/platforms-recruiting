# Folgeliste Schulungszertifikat — was offen bleibt

**Stand:** 2026-08-13, nach Abschluss aller Tasks (`OK (746 tests, 2239 assertions)`).

Jeder Punkt mit einem Satz **Herkunft**: woher er kommt und was gemessen wurde. Nichts hier ist ein Blocker für den Deploy des Zertifikat-Pakets — das ist der Grund, warum es eine Liste und keine Aufgabe ist. Die Belegkette steht in `ledger-schulungszertifikat.md`.

---

## In Arbeit — als nächstes

### F1 · Zertifikat-Link als dynamischer URL-Button statt als Body-Variable

**Spec liegt vor und hat Gate 1 bestanden (13.08.2026):**
`docs/superpowers/specs/2026-08-13-zertifikat-wa-button-design.md`. Branch
`feat/zertifikat-wa-button`. Die Spec ist ab jetzt maßgeblich; was unten steht,
ist ihre Herkunft.

**Entschieden am 13.08.2026, Spec folgt.** Der Versand folgt dem Muster der sechs bestehenden Stellen und ruft **direkt `WhatsAppMetaService::sendTemplate()`** auf, nicht `HoldingTemplateSender`.

**Herkunft:** Bei der Umsetzung von Task 12 hatte ich nur `Applicant/Show.php:548` gefunden und daraus geschlossen, der Button-Weg sei neuer Code — mit dieser Begründung wurde „Body-Variable jetzt, Button später" abgesegnet. Ein breiterer Grep zeigt **sechs** Stellen, die Button-Komponenten bauen (`RecInterview.php:210`, `RecApplicant.php:1412` und `:1651`, `RecEmployee.php:554`, `Applicant/Show.php:548`, `ProcessAutoPilotApplicants.php:474`), plus die `BUTTONS`-Erkennung an sieben. **Die Empfehlung stand auf einer zu engen Suche; der Body-Variablen-Weg ist die Ausnahme im Modul, nicht die Regel.**

Was den Aufwand klein macht: `RecInterview.php:205-215` löst den Button-Wert über `resolveVariableValue($mapping['url_button'], $booking)` aus einem **konfigurierbaren Mapping** auf — also aus einem beliebigen Wert und nicht aus einem festverdrahteten Token. Das ist strukturell schon der Zertifikat-Fall (die `uuid`). Der Task heißt „wiederverwenden", nicht „neu bauen".

**Drei Vorgaben, die dabei gelten:**
- **`HoldingTemplateSender` bleibt außen vor.** Er kann strukturell keine Buttons (`HoldingTemplateComponents::build()` iteriert nur über `type === 'BODY'` und gibt nur `[['type' => 'body', …]]` zurück), und ihn zu erweitern fällt in den Pfad, der auch Holding, Auto-Reply und Voice-Note-Antworten bedient.
- **Der Guard aus Task 12 wandert mit, er fällt nicht weg.** Er prüft dann, dass das Template einen **dynamischen URL-Button** hat, statt der Body-Variable. Ohne ihn geht eine Nachricht ohne Link an einen abgelehnten Bewerber — der Grund ist derselbe wie vorher: `build()` füllt eine fehlende Variable mit dem **Beispieltext**, und Meta weist einen Send ohne Button-Parameter ab.
- **Der Body-Weg wird ERSETZT, nicht als Fallback behalten.** Zwei Zustellwege für dasselbe Dokument sind zwei Pfade zum Pflegen, und der zweite wäre der ungetestete.

### F2 · `Applicant/Show.php:543-552` ist die Negativvorlage — und G8 der Spec ist zu breit formuliert

**Herkunft:** Ich hatte den Block als „setzt den Bewerber-Formular-Token in jeden URL-Button ein" beschrieben und ihn damit pauschal als Defekt geführt. Gemessen ist präziser: `RecApplicant.php:1400` nennt den Token ausdrücklich den **kanonischen** Bewerber-Public-Token, „gleiche Quelle wie `/form/`, `/portal/`, `/contract/` und `/recruiting/interviews/`". Für Templates, deren Button-URL auf eine dieser Routen zeigt, ist er **genau richtig**.

**Der eigentliche Defekt:** der Block läuft **unbedingt**, sobald das Template *irgendeinen* URL-Button hat (`:531-541` setzt `$hasUrlButton` ohne jede Prüfung, welche URL der Button trägt). Bei einem Template, dessen Button auf etwas anderes zeigt, landet trotzdem der Formular-Token darin. Nicht „der Token ist falsch", sondern „der Token wird ungeprüft in jeden Button gesetzt". **G8 ist entsprechend zu korrigieren** — das passiert mit der F1-Spec.

**Erledigt am 13.08.2026, soweit dieser Punkt reicht:** die Ersatzfassung von G8
steht in §Änderungen an der alten Spec der F1-Spec, und der Defekt ist dort als
H4 mit **drei** Teilen gemessen (unbedingt / kein `{{`-Kriterium / kein Guard).
**Der Fix an `Applicant/Show.php` ist nicht Teil davon** — er betrifft jedes
manuell gesendete Template für jeden Bewerber und berührt alle sechs Stellen;
siehe F12.

---

## Eigene Tickets, benannt und nicht in diesem Paket behoben

### F3 · Testreihenfolge — vier geteilte Statics, gemeinsame Integrations-Basisklasse

**Herkunft:** Die Suite ist nur unter der Default-Reihenfolge verlässlich grün; `phpunit.xml` setzt deshalb bewusst kein `executionOrder`, mit Kommentar an genau der Stelle. Gemessen an 12 Seeds: auf `main` brechen 2, auf dem Branch 5 — der Defekt ist **vorbestehend**, dieses Paket hat die Angriffsfläche verbreitert.

Vier verschiedene Ursachen, Tabelle in der Spec (§Testreihenfolge): `Model::$booted`, `Facade::$resolvedInstance`, globale Capsule/`Model::$resolver`, und der statische Definitions-Cache in `HasExtraFields`, der **leere** Einträge in die nächste Testklasse leckt. Jeder punktuelle Fix sitzt beim Verursacher oder beim Opfer, keiner deckt alle vier ab; in Task 12 trug **keine** der drei damals bekannten Aufräumzeilen, Grund war allein die alphabetische Reihenfolge.

**Was die Basisklasse leisten müsste:** Container, Capsule, Facade-Instanzen, Eloquent-Boot-Cache und den `HasExtraFields`-Cache einheitlich in `setUpBeforeClass()`/`tearDownAfterClass()`. Erst dann wird `--order-by=random` angreifbar, und erst dann verschwinden die drei Kopien der Auth-Guard-Stub-Klasse.

### F4 · ISO-Datum in Vertragsvorlagen — 9 Vorlagen, 205 Verträge

**Herkunft:** Task 6a hat die Platzhalter-Auflösung der Bestandsvorlagen festgenagelt und dabei gefunden: `contract.extra_field.*` (`RecContractTemplate.php:243-246`) formatiert Datumswerte **nicht** um, `applicant.extra_field.*` (`:222-231`) schon. Kette nachgeprüft: `RecContract::resolveContractDates()` liefert `Y-m-d`; `platforms-core` castet `date` ausdrücklich zu `(string)`, nicht zu Carbon; im PDF-/Signier-Pfad gibt es keine nachgelagerte Formatierung. Betroffen sind `vertragsbeginn` und `vertragsende`, je in **9 von 11** Live-Vorlagen, auf denen zusammen **205 Verträge** hängen.

**Ein Schritt ist nicht gemessen:** ob `{{vertragsbeginn}}` im `content` der Vorlagen sichtbar steht — `content` und `personalized_content` geben die MCP-Tools nicht aus, im Repo liegt kein Vorlagen-HTML. **Ein Blick in ein bestehendes Vertrags-PDF klärt es in 30 Sekunden.**

Bestandsdefekt, nicht von diesem Paket verursacht. Der Pin-Test nagelt das Verhalten fest **wie es ist** — wird es korrigiert, wird der Test rot und zeigt die Stelle.

### F5 · Portal-Zugriff abgelehnter Bewerber mit nie verfallendem Token

**Herkunft:** Spec-Fakt G20, benannt beim Verifikationslauf: die Bewerber-Portal-Route prüft weder `rejected_at` noch `is_active`, und der Token wird nirgends rotiert oder invalidiert. Dieses Paket hängt ein weiteres personenbezogenes Dokument dort hinein — deshalb ausdrücklich in den Tradeoffs benannt. **Keine Reparatur im Zertifikat-Scope**: sie berührte Token-Lebenszyklus, Bewerbungsformular und Vertrags-PDF-Zugriff und wäre ein eigenes Vorhaben.

---

## Kleine Werkzeug- und Hygienepunkte

### F6 · `tools/blade-check.php` erkennt einen nicht geschlossenen `{{--` nicht

**Herkunft:** In Task 18 gemessen: `0 mit Funden` bei einem unbalancierten Blade-Kommentar. Real hätte er **4084 Zeichen** bis zum nächsten `--}}` verschluckt — Schalter, Template-Select **und** Hinweis wären still von der Seite verschwunden. Der neue Test fängt es für die eine Datei; für die anderen 39 ist es ungedeckt.

Nebenbefund derselben Runde: das Werkzeug war bis Task 11 im Worktree **tot** (Exit 2, Autoloader-Pfad fest vier Ebenen aufwärts) und ist dort repariert worden. Es hätte also seit Beginn nichts geprüft, wenn es gebraucht worden wäre.

### F7 · Zwei Migrations-Lader ohne Drift-Detektor

**Herkunft:** Task-6a-Review. `DuplicateMatchQueryTest::runRealMigrations()` löst die Modulwurzel per Aufwärtssuche auf, `PlaceholderResolutionPinTest::runRealMigrations()` per Reflection — zwei Dateilisten, zwei Wurzel-Strategien, dieselbe Aufgabe. **Heute kein Drift** (16 von 16 Spalten identisch, nachgemessen), aber nichts erkennt ihn.

Das ist genau die Fläche, die Task 2a (`TestSchema` als einzige Quelle) beseitigen sollte — nur eine Ebene höher gewandert, beim Lader statt beim DDL. Vorschlag von damals: den Lader nach `TestSchema` ziehen, damit es wieder **eine** Tür zum Testschema gibt.

### F8 · `addError()` auf eine rohe Checkbox ohne `@error`-Stelle

**Herkunft:** Task 18, am Blade geprüft. `ApplicantSettingsModal::save():258` setzt `addError('settings.auto_start_auto_pilot', …)` — die Checkbox dort ist rohes HTML ohne `@error`-Ausgabe. Die Meldung ist **unsichtbar**, das Modal bleibt stumm offen. Vorbestehend, nicht angefasst.

### F9 · `FontGlyphCoverageTest::tearDown()` benutzt `glob()`

**Herkunft:** Beim Beheben der `glob()`-Schwäche im fontCache-Aufräumer (Minors-Triage B2) aufgefallen: derselbe Mechanismus steht dort noch. Praktisch harmlos — es räumt nur selbst geschriebene `*.ttf` in einem selbst gewürfelten Pfad ohne Metazeichen auf. Notiert, damit das Muster nicht weiterkopiert wird.

---

### F10 · Zertifikat-Versand hängt den WhatsApp-Thread nicht an den Bewerber

**Herkunft:** Beim Spec-Review zu F1 (13.08.2026) benannt und dort **ausdrücklich
aus dem Schnitt genommen** (Q3), nicht übersehen. Gemessen: die sechs
Button-Sendestellen rufen alle `$message->thread->addContext(...)` mit eigenem
Purpose-String (`RecApplicant.php`, `RecEmployee.php:570`,
`ProcessAutoPilotApplicants.php:494`, `Applicant/Show.php:568-570`,
`InterviewBookings/Index.php:1127-1133`); `HoldingTemplateSender::sendToMany()`
tut es nicht. Der Zertifikat-Thread hängt also an keinem Bewerber — heute schon,
und nach dem F1-Umbau unverändert.

**Warum es trotzdem richtig wäre:** die Antwort des Bewerbers auf einen
Zertifikat-Link kommt in einem Thread ohne Bewerber-Kontext an und läuft damit in
dieselbe Lücke, die als Kontext-Gate-Fall bekannt ist — sie ist in
`/conversations` nicht am Bewerber zu finden.

**Warum nicht in F1:** Verhaltensänderung über den Auftrag hinaus, und sie fällt
ohne Rest weg — der Versand funktioniert vollständig ohne. Wer es baut: eigener
Purpose-String, in einem eigenen `try` (Muster `RecApplicant.php:1673-1680`), und
`wa_sent_at` bleibt außerhalb jedes `try`.

### F11 · Template-/Account-/Kanal-Auflösung steckt privat im Holding-Sender

**Herkunft:** F1-Spec §W2 und Tradeoff T4. `HoldingTemplateSender::resolveConfig()`
(`:96-135`) löst in vier Queries Settings → Template (`APPROVED`) → Account →
`CommsChannel` auf und ist `private`. Der F1-Umbau bekommt eine lesende
öffentliche Methode dazu (`resolveTarget()`), **weil die Extraktion einen Pfad
mit drei fremden Aufrufern anfasst** (Holding-Bestätigung, OOO-Auto-Reply,
Voice-Note-Antwort).

**Der Folgepunkt:** die Auflösung in eine eigene Support-Klasse ziehen, die
Sender und Zertifikat-Versand gleichberechtigt benutzen. Dann ist die Kette
einmal vorhanden statt einmal vorhanden und einmal durchgereicht. Reine
Umstrukturierung, kein Verhaltenswechsel — und genau deshalb nicht im Paket, in
dem der Versand umgebaut wird.

### F12 · `index: 0` ist an sechs Stellen gesetzt, nicht ermittelt — plus der Show.php-Fix

**Herkunft:** F1-Spec H3 und H4, gegen main `9382981` gemessen. Alle sechs
Sendestellen hardcodieren `index: 0` für den URL-Button
(`RecInterview.php:212`, `RecApplicant.php:1412` und `:1653`,
`RecEmployee.php:555`, `Applicant/Show.php:547`,
`ProcessAutoPilotApplicants.php:475`). Ein Template mit Quick-Reply an Position 0
und URL-Button an Position 1 wird von **keiner** Stelle korrekt befüllt.

Dazu kommt die zweite Hälfte: fünf der sieben Erkennungsstellen prüfen **nicht**,
ob die Button-URL überhaupt eine Variable trägt (`{{`) — nur
`RecInterview.php:162` und `InterviewSchedule/Index.php:145` tun es. Ein
statischer URL-Button bekommt dort einen Parameter.

**Was F1 davon abdeckt:** nichts an den sechs Stellen. Der Zertifikat-Weg
bekommt die strenge Erkennung und einen Guard, der den Positionsfall **sichtbar**
macht (Meldung „an die erste Position verschieben", kein Send) statt ihn falsch zu
senden. Der Fix am Bestand — Index ermitteln statt setzen, `{{`-Kriterium
überall — ist dieser Punkt, und `Applicant/Show.php:529-552` ist die Stelle, an
der er am meisten wehtut, weil dort der Bediener das Template frei wählt.

---

## Bekannt, bewusst so, kein Handlungsbedarf

Diese vier stehen hier, damit sie nicht als Fund neu entdeckt werden:

- **`getSetting()` ist fragil, aber die Richtung ist sicher.** `$settings[$key] ?? $default ?? DEFAULT_SETTINGS[$key]` — bei einer bestehenden Zeile ohne den Schlüssel trägt allein der Default. Mit `false` als Default ist das Feature nach dem Deploy aus, was gewollt ist. **Wer den Default je auf `true` dreht, schaltet alle Bestandsteams still ein.**
- **Der Race auf dem Unique-Constraint bei der Ausstellung ist nicht abgefangen** — nicht rot zu machen, deshalb eine benannte Lücke statt eines ungetesteten Guards.
- **Das Template-Vokabular kann die Hülle nicht erzwingen.** `.zert-fuss-rechts`, `.zert-datum` und `.zeichen` liefert der Inhalt, nicht `TrainingCertificateHtml::build()`. Mit festem HTML (Zuschnitt v3) ist das entschärft; beim Rückweg kommt es zurück.
- **`type`-Spalte und die Invarianten in `RecContractTemplate` laufen leer.** Bewusst stehengelassen als billiger Teil des Rückwegs, an beiden Stellen als toter Schalter kommentiert. Nicht „aufräumen", ohne `guard-landkarte-511451c.md` zu lesen.
