# Ledger Schulungszertifikat — die Belegkette für die Zahlen in Spec und Plan

**Versioniert am 2026-08-13**, weil das Original unter `.superpowers/sdd/` liegt und dort per `.gitignore` mit `*` ausgeschlossen ist — es wäre beim Entfernen des Worktrees ersatzlos verschwunden, zusammen mit 20 Briefen und 20 Reports.

**Was das hier ist:** das laufende Protokoll der Umsetzung, Task für Task. Es enthält die **rohen Messungen**, auf die sich Spec und Plan berufen — Fontlisten, Seitenzahlen, Query-Zahlen, Mutationsergebnisse, Transaktionszustände. Ohne es sind die Zahlen in den beiden anderen Dokumenten nicht mehr nachvollziehbar, nur noch behauptet.

**Was es NICHT ist:** eine Spezifikation. Es steht chronologisch, enthält Zwischenstände, zurückgenommene Annahmen und vier Messungen, deren Ursache zunächst falsch zugeordnet war (Times-Bold, §E5, `fontDir`/`chroot`, und die zu kurz greifende Korrektur davon). Maßgeblich sind Spec und Plan; hier steht, **wie** es dort hingekommen ist.

**Nicht mitversioniert** sind die 20 Task-Briefe und 20 Reports aus demselben Verzeichnis. Wo ein Report eine Aussage trägt, die sonst nirgends steht, ist sie in diesem Ledger, in der Spec, im Plan oder in `folgeliste.md` festgehalten — die Reports selbst sind Zwischenmaterial.

---

# SDD ledger — plan: docs/superpowers/plans/2026-08-12-schulungszertifikat-html.md

Branch: `feat/schulungszertifikat`, Basis `511451c` (== origin/main)
Baseline vor Task 0: 512 Tests, 1485 Assertions, OK
Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`

## Setup-Entscheidungen

- **Kein Worktree, stattdessen Branch im Hauptcheckout.** `tests/Integration/DuplicateMatchQueryTest.php:88` löst das Modul-Verzeichnis als `dirname(__DIR__, 3)` auf. Im Hauptcheckout ergibt das `platform/modules/`, in jedem Worktree dagegen `.claude/worktrees/` — dort liegt kein `platform-crm`, der Test bricht mit „Migration fehlt". Betrifft alle vier Worktrees des Repos, nicht nur einen neu erstellten. Fremdtest wird nicht im Setup gefixt; Isolation läuft über den Branch. **Offener Befund für den User.**
- Spec, Plan und Guard-Landkarte sind auf dem Branch committet (`5908aad`) — die Briefs werden aus dem Plan extrahiert, und die Landkarte ist das Merge-Gate, dessen Häkchen im Diff prüfbar sein müssen.
- **Die vier Assets sind vom Controller vorab abgelegt** (`resources/fonts/Oswald-SemiBold.ttf`, `resources/fonts/OFL.txt`, `resources/images/certificates/{logo,headline-zertifikat,signature-block}.png`), noch nicht committet. Grund: Task 4 Step 3 hätte Netzzugriff und einen absoluten Pfad außerhalb des Repos gebraucht. Task 4 verifiziert und committet sie.
- **Plan-Korrektur vor Dispatch:** Task 12 benutzte `$applicant`, das in `confirmResolve()` nur im Jugendschutz-Zweig gesetzt ist (`HrDesk/Index.php:171`). Der Codeblock holt es jetzt selbst aus `$case->applicant` mit Null-Guard.

## Fortschritt

Task 0: implementer DONE_WITH_CONCERNS (commit ac64a64) — SOLL-Fontliste vom Brief abweichend gemessen.
Task 0: adjudiziert — Implementierer hatte recht. Eigene Gegenprobe reproduzierte Times-Bold NICHT in der Testumgebung; Abweichung nicht auf defaultFont zurueckfuehrbar und unaufgeklaert. G24 der Spec war ein Messfehler, korrigiert. SOLL bleibt ['DejaVuSans','DejaVuSans-Bold'].
Task 0: offener Punkt (nicht blockierend) — welche Fonts ein LIVE erzeugtes Vertrags-PDF enthaelt, ist unbekannt (Produktion nutzt storage_path('fonts')). Ausserhalb Scope.
Task 0: Fontlisten-Divergenz untersucht (11 Laeufe). Cache-Hypothese WIDERLEGT: kalt/kalt/warm/warm auf einer Kopie des Fontordners je ['DejaVuSans','DejaVuSans-Bold']; geteilt-vs-Kopie x mit/ohne defaultFont ebenfalls 4x derselbe Wert; frischer fontCache 3x derselbe Wert. Times-Bold unter KEINER Konfiguration reproduzierbar — Einzelereignis, unaufgeklaert. SOLL-Wert des Implementierers bestaetigt.
Task 0: effektive Pfade gemessen — Default fontDir == fontCache == meingedeck/vendor/dompdf/dompdf/lib/fonts (geteilt, beschreibbar). Enthaelt Times-Bold.afm.json UND DejaVuSans-Bold.ufm.json, also Spuren beider Bold-Auflösungen.
Task 0: md5-Punkt geprueft — in ac64a64 umgesetzt (md5 ueber den ganzen <style>-Block + Laengenpruefung, sprechende Fehlermeldungen), nicht die zwei Literale der ersten Planfassung.
Task 0: fix round 1/5 dispatched — Finding (Important): Test nutzt den geteilten vendor-fontCache, schreibt dorthin und haengt an fremdem Zustand. Fix: eigener fontCache pro Lauf, fontDir unveraendert.
Task 0: fix round 1/5 (1 addressed, 0 open — eigener fontCache pro Lauf, vendor-Fontordner wird nicht mehr beschrieben; commit e72646a, nach Historie-Aufraeumung fbcdd81). Suite 515/1489 PASS.
Task 0: minor (deferred) — der pro-PID angelegte Temp-fontCache wird nicht aufgeraeumt. Kein Korrektheits-/Isolationsproblem (eigene PID, keine Kollision), reine Hygiene. Fuer die Triage im Final Review.
Task 0: CONTROLLER-FEHLER 1 — mein "git add docs/" in cae48b6 hat 38 statt 2 Dateien committet (zwei .DS_Store plus 36 fremde, alte Plaene/Specs). Historie neu aufgebaut: reset auf ac64a64, Docs-Korrektur als 3b67a4d (2 Dateien), fontCache-Fix als fbcdd81 (1 Datei). Sicherung: branch backup/vor-docs-aufraeumen.
Task 0: CONTROLLER-FEHLER 2 — der reset --hard hat die 36 fremden Dateien von der Platte geloescht (sie waren untracked). Aus der Sicherung zurueckgeholt, danach entstaged: 29 plans + 29 specs wieder da, 36x untracked wie vorher, meine zwei Dateien identisch mit HEAD.
Task 0: Branch aendert gegenueber origin/main jetzt genau 4 Dateien (plan, spec, guard-landkarte, ContractPdfRegressionTest). Suite 515/1489 PASS.
Task 0: task review 1 (nach Sandbox-Fehlschlag des ersten Versuchs) — Spec DECKUNG NICHT ERFUELLT. Befund: viertes Regressionskriterium der Spec ("Firmenstempel weiterhin vorhanden") im Task-0-Code des PLANS nie umgesetzt und nicht als Abweichung benannt. Planfehler des Controllers, nicht des Implementierers.
Task 0: Testbarkeit vorab bewiesen — RendersContractPdf laesst sich ohne Laravel per anonymer Klasse + Trait-Aliasing nutzen; RecContract/RecContractTemplate ohne DB via setRelation. Gemessen: loadCompanyStampDataUrl -> data-URI 27742 Zeichen; AV-010 -> Stempel JA; IFSG -> Stempel NEIN.
Task 0: minor (deferred) — fehlende Assertion-Meldungen in testSeitenzahlBleibtEins und testFontlisteIstEingefroren.
Task 0: minor (deferred) — @mkdir unterdrueckt auch echte Fehler (Berechtigungen), Ursache waere dann unklar.
Task 0: minor (deferred) — Testname weicht vom Brief ab (testFontlisteIstEingefroren statt ...InklusiveTimesBold), begruendet und per Grep als folgenlos verifiziert.
Task 0: fix round 2/5 dispatched — Findings: (1) Spec, Stempel-Kriterium fehlt, Fix ueber die echte RendersContractPdf-Logik mit Positiv- UND Negativfall; (2) Important, Klassen-Docblock behauptet Textinhalt-Absicherung, die es nicht gibt.
Task 0: fix round 2/5 (2 addressed, 0 open — Stempel-Kriterium mit Positiv- und Negativfall gegen die echte Trait-Logik; Docblock korrigiert und Textlücke benannt; commit a258894). Re-Review: alle Findings addressed, keine neuen Schaeden. Suite 518/1493 PASS.
Task 0: complete (commits 5908aad..a258894, review clean, 5 parked minors)

Task 1: implementer DONE (commit 6860008) — Brief-Code woertlich, 1 Datei, 40 Zeilen, php -l gruen, Suite 518/1493.
Task 1: task review — Spec OK, aber zwei Important-Findings.
Task 1: Finding "Co-Authored-By fehlt" = FALSE POSITIVE, mit Beleg verworfen. Voller Commit-Body enthaelt den Trailer (grep-Treffer 1). URSACHE IST MEIN REVIEW-PAKET: es liefert "git log --oneline", das keine Trailer zeigt. AB SOFORT: Commit-Bodies ins Paket aufnehmen (git log --format='%h %s%n%b').
Task 1: Finding "Idempotenz-Guard deckt zwei DDL-Operationen ab" = ECHT, Ursache mein Brief-Code. up(): ADD COLUMN ok + ADD INDEX scheitert -> Retry ueberspringt alles, Index fehlt still. down(): dropIndex ok + dropColumn scheitert -> Retry bricht hart auf dem entfernten Index. Praezedenz-Migration 2026_05_19_000002 macht es richtig (Guard pro Spalte :45/:50, try/catch um dropForeign :65-67) — mein Code zitiert sie und reproduziert den Fehler, gegen den sie geschrieben wurde.
Task 1: Schema::hasIndex verifiziert verfuegbar (laravel/framework v12.62.0).
Task 1: fix round 1/5 dispatched — Guard pro DDL-Operation, hasIndex im up(), try/catch um dropIndex im down(), Docblock nachziehen.
Task 1: fix round 1/5 (1 addressed, 1 neu — commit 5dadf1e). up() ADDRESSED: getrennte Guards, hasIndex verhindert stillen Indexverlust. down() faktisch geheilt, aber durch den vom Implementierer SELBST ergaenzten hasIndex-Guard, nicht durch das von mir verlangte try/catch.
Task 1: NEUES Finding (Important) — das try/catch um dropIndex ist toter Code. An der Framework-Quelle geprueft: Blueprint::dropIndex (Blueprint.php:468 -> dropIndexCommand :1755 -> indexCommand) queued nur, fuehrt kein SQL aus; Schema\Builder::table (Builder.php:492-495) fuehrt das SQL in build() NACH der Closure aus. Das try/catch kann nichts fangen. MEINE Fix-Anweisung war Cargo-Cult aus dem Bestand.
Task 1: Bestandsbefund (ausserhalb Scope) — 2026_05_19_000002 hat um dropForeign dasselbe funktionslose try/catch. Nicht angefasst.
Task 1: Berichtsfehler des Implementierers — behauptete, die Suite sei lokal nicht ausfuehrbar. Falsch, nur Migrationen sind es nicht. Suite laeuft, 518/1493. Korrigiert mitgegeben.
Task 1: fix round 2/5 dispatched — totes try/catch entfernen, Kommentar streichen, Docblock auf Live-Guards statt Exception-Behandlung umstellen.
Task 1: fix round 2/5 (1 addressed, 0 open — totes try/catch entfernt, Kommentar weg, Docblock auf Live-Guards umgestellt; commit b62c10f). Re-Review: ADDRESSED, Docblock ohne Ueberclaims, keine neuen Schaeden. php -l gruen, Suite 518/1493.
Task 1: complete (commits a258894..b62c10f, review clean)

## MERGE-VORMERKUNG (User-Auftrag, 2026-08-12)

**fbd72db `test(recruiting): DuplicateMatchQueryTest worktree-fest machen` gehoert
als ERSTES auf main — NICHT mit dem Zertifikat-Paket warten.**
Grund: der Fix macht die Suite in JEDEM Worktree des Repos lauffaehig, nicht nur
in diesem. Der parallel laufende ZAS-Worktree (feat/zas-dispo-verarbeitung)
braucht ihn genauso. Solange er nur an feat/schulungszertifikat haengt, ist er
dort nicht verfuegbar.
Vorgehen beim Merge: fbd72db zuerst cherry-picken/ff auf main, dann der Rest.

## Umzug in eigenen Worktree (2026-08-12)

- Arbeitsverzeichnis ab jetzt: .claude/worktrees/zertifikat (branch feat/schulungszertifikat)
- Haupt-Checkout steht auf main und ist fuer parallele Arbeit des Users frei.
- Anlass: der User arbeitet parallel im Worktree zas-dispo-inbound; vorher teilten wir uns den Haupt-Checkout, ein Branchwechsel von aussen unterbrach den Task-Loop.
- Suite im Haupt-Checkout nach dem Fix: OK (518 tests, 1493 assertions). Suite im Worktree: OK (518 tests, 1493 assertions). DuplicateMatchQueryTest isoliert: 9/16 OK in beiden.
- fbd72db trennt zwei Wurzeln: eigenes Modul ueber dirname(__DIR__, 2) (Worktree testet EIGENE Migrationen), platform-crm ueber inhaltsbasierte Aufwaertssuche mit Obergrenze 10.
- 785cb90 committet die vier Assets + OFL.txt. Reihenfolge bewusst getauscht (vor dem Umzug statt danach), damit die handfreigestellten PNGs keinen Kopierschritt brauchen. Pruefsummen im Commit-Text.

Plan-Aenderungen vor Task 2: Task 6a eingefuegt (Platzhalter-Aufloesung festnageln, 13 Faelle, Fall 1 = nicht gemapptes {{resttage}} bleibt stehen), Spec 10 -> 11 Vorlagen korrigiert (AT-140 dazugekommen). Commit 3d07431.
Brief-Extraktion: DEFEKT im task-brief-Skript entdeckt — es matcht per Praefix, also zieht "2" auch "2a" mit. task-2-brief.md enthielt beide Tasks. Von Hand getrennt. AB JETZT: jeden Brief auf genau eine "### Task "-Ueberschrift pruefen. Betrifft auch 5/5a und 6/6a.
Task 2: implementer DONE (commit 25de922) — Brief-Code woertlich, 1 Datei, 48 Zeilen.
Task 2: eigene Nachpruefung — alle sieben Constraint-Namen unter dem MySQL-Limit (laengster 58, rec_training_certificates_rec_contract_template_id_foreign); der auto-generierte Composite-Unique waere 74 gewesen, der explizite kurze Name (38) verhindert Fehler 1059. php -l gruen, keine Zeitstempel-Kollision, Suite 518/1493 selbst nachgefahren.
Task 2: task review — Spec OK, keine Critical/Important.
Task 2: minor (deferred) — Rechenfehler im Report (task-2-report.md:24 schreibt "36 Zeichen unter Limit", richtig ist 26). Kein Code-Defekt, nur falsche Zahl in der Verifikationsdoku.
Task 2: complete (commits 3d07431..25de922, review clean, 1 parked minor)

## Werkzeug-Fix: task-brief-Extraktor (2026-08-12)

Gepatchte Datei (ausserhalb des Repos, im Plugin-Cache):
  ~/.claude/plugins/cache/claude-plugins-official/superpowers/6.2.0/skills/
  subagent-driven-development/scripts/task-brief
Original gesichert daneben als task-brief.orig-vor-patch.

**ACHTUNG: Der Patch liegt im Plugin-Cache und kann bei einem Plugin-Update
verlorengehen.** Wenn Briefs wieder zwei Tasks enthalten, ist das die Ursache.

Zwei Defekte behoben:
 1. Praefix- statt exaktem Match. Alter Guard: "Task[ \t]+" n "([^0-9]|$)" —
    schliesst nachfolgende ZIFFERN aus, aber keine BUCHSTABEN. n=2 matchte
    daher auch "### Task 2a". Neu: ([^0-9A-Za-z]|$), und der Ueberschriften-
    Detektor erkennt Buchstaben-Suffixe ([0-9]+[A-Za-z]*).
 2. Keine Selbstpruefung. Neu: das Skript zaehlt die Task-Ueberschriften in
    seiner EIGENEN Ausgabe und BRICHT AB (exit 4), wenn es nicht genau eine
    ist — bewusst Abbruch statt Warnung, weil eine Warnung dieselbe
    Fehlerbauart hat wie der Bug (haengt daran, dass jemand sie liest).

A/B belegt: gepatcht n=2 -> 91 Zeilen / 1 Ueberschrift; Original n=2 -> 228
Zeilen / 2 Ueberschriften. Alle Nummern 2, 2a, 5, 5a, 6, 6a, 17 liefern je
genau eine Ueberschrift, n=99 bricht sauber ab.

## Offene Vereinbarung

Die geparkten Minors werden NACH TASK 9 am Stueck vorgelegt, dann wird
entschieden, was davon noch drankommt. Stand jetzt: 7 geparkt.

Task 2a: implementer DONE_WITH_CONCERNS (commit 504c276) — drei Abweichungen zwischen Brief-Code und echten Migrationen SELBST gemeldet statt geglaettet. Genau das Verhalten, gegen dessen Fehlen der Task gebaut wurde.
Task 2a: adjudiziert — (1) field_mappings text() statt json() = MEIN Brief-Fehler, echte Korrektur; (2) unsignedBigInteger statt foreignId()->constrained() = richtig, nur unbegruendet; (3) fehlende Indizes = richtig, nur unbegruendet.
Task 2a: fix round 1/5 (3 addressed — json()-Fix plus Docblock-Abschnitt "BEWUSSTE ABWEICHUNGEN VON DEN MIGRATIONEN"; commit 0fe2685).
Task 2a: mechanischer Abgleich gegen die echten Migrationen (Controller): rec_contract_templates 12/12 Spalten, rec_training_certificates 8/8, keine fehlend, keine zusaetzlich. Einzige Typabweichungen = die sechs dokumentierten foreignId->unsignedBigInteger. field_mappings nicht mehr abweichend.
Task 2a: task review — Spec OK. Important: Commit-Zahlen im Report falsch (107/32 statt 84/0 und 16/1) — Report als Evidenzquelle beschaedigt, verstoesst gegen die Projektkonvention "Zahlen woertlich aus der Terminal-Ausgabe". Minor: Docblock behauptete konfigurationsabhaengige Aussage absolut.
Task 2a: PRAGMA foreign_keys gegen die echte Capsule-Konfiguration gemessen = 0. Aussage also heute wahr, aber konfigurationsabhaengig.
Task 2a: fix round 2/5 (2 addressed — Report-Zahlen mit roher numstat-Ausgabe, Docblock als gemessene Ist-Aussage mit Datum und Neubewertungs-Hinweis; commit 24398a9). Re-Review: beide addressed, keine neuen Schaeden.
Task 2a: complete (commits 25de922..24398a9, review clean)

AUFLAGE FUER TASKS 3, 8, 17 (aus dem Task-2a-Review, nicht vergessen):
TestSchema::contractTemplates/trainingCertificates steigen per hasTable-Guard aus,
wenn die Tabelle schon existiert. Teilen mehrere Testklassen im selben
PHPUnit-Prozess eine Capsule-Verbindung, legt nur die ERSTE die Tabelle an — die
folgenden erben sie samt Daten der Vorgaengerklasse. Jede konsumierende
Testklasse muss deshalb selbst fuer Isolation sorgen: eigene Verbindung pro
Klasse ODER Truncate in setUp(). In die Dispatches von 3, 8, 17 aufnehmen.

Task 3: implementer DONE (commit 830bd1e) — beide Invarianten, Testcode byte-identisch zum Brief, Suite 524/1493->1502.
Task 3: Implementierer fand durch den GESAMTSUITE-Lauf einen Fehler, den ich in Task 0 eingebaut hatte: ContractPdfRegressionTest:161-185 instanziiert Models per new OHNE Dispatcher und bootet sie prozessweit mit toten Hooks (Model::$booted ist statisch). Symptom: NOT NULL constraint failed auf uuid, NUR im Gesamtlauf. Mit --filter unsichtbar.
Task 3: task review — Spec OK, zwei Important zur PLATZIERUNG: (1) type-Default gehoerte in $attributes statt in den creating-Hook (new ohne save() lieferte null; dieser Pfad existiert real in ContractPdfRegressionTest:161/182); (2) clearBootedModels() sass bei der Konsumentin statt beim Verursacher und war damit selbst reihenfolgeabhaengig.
Task 3: fix round 1/5 (2 addressed — $attributes-Default plus siebter Test fuer die ungespeicherte Instanz; tearDownAfterClass beim Verursacher, Aufruf bei der Konsumentin als defensiv umkommentiert; commit 2326aa9). Re-Review: beide addressed, keine neuen Schaeden, Drei-Datei-Scope gehalten.
Task 3: complete (commits 24398a9..2326aa9, review clean)
Task 3: minor (deferred) — kein Enum-Schutz auf type: ein anderer String als contract/certificate greift durch beide Invarianten und faellt aus beiden Scopes.
Task 3: minor (deferred) — where(...)->update() umgeht die Model-Events und damit den saving-Hook.

## BEFUND: Suite bricht unter zufaelliger Testreihenfolge (deferred, eigenes Ticket)

Selbst gemessen, 12 Seeds:
  unser Branch: gruen 1 4 5 7 11 23 31337 | ROT (je 8 Errors) 2 3 42 99 1234
  main 511451c: seed 3 ROT, seed 99 ROT   | seed 2, 42, 1234 GRUEN

Auslegung, praezise: der Defekt ist VORBESTEHEND (2 Seeds brechen auch ohne
unsere Commits), aber unsere Arbeit hat die ANGRIFFSFLAECHE VERBREITERT (3
weitere Seeds brechen nur bei uns). Ursache ist ein modulweites Muster:
Integrationstest-Klassen booten Eloquent-Models im geteilten PHPUnit-Prozess
mit und ohne Dispatcher; Model::$booted ist statisch.

Unter der tatsaechlich konfigurierten Reihenfolge (kein executionOrder in
phpunit.xml, kein --order-by-Flag) ist beides gruen — main wie Branch.

Nachhaltige Loesung waere eine gemeinsame Basisklasse fuer Integrationstests,
die Boot-Cache und Dispatcher einheitlich aufsetzt. Ausserhalb dieses Pakets.
Reviewer-Einschaetzung: in dieser Runde nicht loesen, als eigenes Ticket
nachziehen.

Task 4: implementer DONE (commit 036278c) — Brief-Code woertlich, 2 Dateien (114+58 Zeilen), Step 3 korrekt NICHT ausgefuehrt (Assets lagen aus 785cb90), gefiltert 6/6, Gesamtsuite 531/1509 (+6/+6).
Task 4: eigene Direktverprobung gegen die echte Schriftdatei — Kenntnisliste ohne Sterne [], mit Stern ["★"], HTML-Markup ["★"], zwei fehlende ["★","☂"], HTML-Entity &#9733; ["★"], leerer Inhalt [], fehlende Fontdatei []. Der Entity-Fall ist relevant: der Seed-Command aus Task 16 schreibt die Sterne genau so.
Task 4: laravel-frei bestaetigt (grep auf Illuminate/Facade/app(/config( -> leer).
Task 4: task review — Spec OK, keine Critical/Important.
Task 4: minor (deferred) — $font->close() nur im Erfolgspfad; bei Exception in parse()/getUnicodeCharMap() keine Freigabe. finally waere robuster. Aus MEINEM Brief-Code.
Task 4: minor (deferred) — doppelte Dedup-Logik (codepoints() dedupliziert, missing() prueft nochmal). Funktional korrekt, redundant. Aus MEINEM Brief-Code.
Task 4: complete (commits 2326aa9..036278c, review clean, 2 parked minors)

## Stand nach Task 4

Fertig: Task 0, 1, 2, 2a, 3, 4.
Naechster: Task 5 (TrainingCertificatePdfOptions).
Branch feat/schulungszertifikat @ 036278c im Worktree .claude/worktrees/zertifikat.
Suite Default-Reihenfolge: OK (531 tests, 1509 assertions).
Geparkte Minors: 10. Vorlage am Stueck nach Task 9 (Vereinbarung mit dem User).
Merge-Vormerkung unveraendert: fbd72db zuerst auf main.

Task 5: implementer brach an einem API-Fehler ab, direkt vor dem Report. Arbeit war vollstaendig und committet (16d383d); nur der Report fehlte. Agent gezielt nur fuer den Report fortgesetzt, mit dem von mir gemessenen Ist-Zustand, damit er nichts neu implementiert.
Task 5: Mutationstest vom Controller nachgeholt, weil der Implementierer die Assertion-Schaerfe offen als nur ERSCHLOSSEN benannt hatte. Schluessel 'dpi' entfernt -> Failures: 2 plus PHP-Warning. Danach git checkout, Arbeitsbaum sauber, Suite wieder 536/1516. Assertion nachweislich scharf.
Task 5: task review — Spec OK, aber ein echter Korrektheitsfehler in MEINEM Brief-Code: die chroot-Pruefung war reines Zeichen-Praefix ohne Verzeichnisgrenze. chroot=/app liess fontPath=/apply/x.ttf durch. Der Guard existiert genau dafuer, diesen Fall zu fangen — er liess ihn durch und erzeugte ein falsches Sicherheitsgefuehl. Die Tests, die es gefangen haetten, fehlten im Brief ebenfalls.
Task 5: fix round 1/5 (3 addressed — Verzeichnisgrenze statt Zeichen-Praefix, drei Grenzfall-Tests, irrefuehrenden Testnamen gesplittet; commit 0e68e34). Der Implementierer hat den Mutationstest diesmal SELBST gefahren (alte Pruefung temporaer eingesetzt, exakt 2 Failures, zurueckgesetzt).
Task 5: Re-Review-Praezisierung, wert festzuhalten: von den drei neuen Tests waeren nur ZWEI unter der alten Pruefung rot geworden. Der Trailing-Slash-Test ist ein Non-Regression-Test, kein Bug-Faenger — die alte Pruefung hatte rtrim schon.
Task 5: complete (commits 1452f93..0e68e34, review clean)

## Stand nach Task 5

Fertig: Task 0, 1, 2, 2a, 3, 4, 5.
Naechster: Task 5a (TrainingCertificateAssets).
Branch feat/schulungszertifikat @ 0e68e34, Worktree .claude/worktrees/zertifikat.
Suite Default-Reihenfolge: OK (540 tests, 1519 assertions).
Geparkte Minors: 10 (unveraendert — Task 5 brachte keine neuen).
Merge-Vormerkung: fbd72db zuerst auf main.

## Offene Zusage an den User

Nach Task 9: (a) die geparkten Minors am Stueck vorlegen, (b) Vorschlag, die
Tasks 6-17 gezielt gegen EINE Fehlerklasse durchzusehen — Guards und
Bedingungen, die plausibel aussehen und den Fehlerfall durchlassen. Bisherige
Vertreter dieser Klasse: Idempotenz-Guard (Task 1), totes try/catch (Task 1),
chroot-Praefix ohne Verzeichnisgrenze (Task 5).

Task 5a: implementer meldete einen 0-Byte-Randfall als "Vollstaendigkeitsnotiz, kein Fund". Eigene Sonde belegte: lesbares 0-Byte-Bild besteht is_file/is_readable, file_get_contents liefert '' (nicht false) -> leerer Data-URI, der im PDF nichts rendert, UND kein Eintrag in missing -> Controller loggt nichts, Editor zeigt nichts, Render-Test bleibt gruen. Echter Fund, vierter Vertreter der Fehlerklasse.
Task 5a: fix round 1/5 (0-Byte bei Bild und Schrift als fehlend, zwei Tests; commit fd1f7a5). Selbst nachgemessen: Sonde liefert jetzt null + missing-Eintrag.
Task 5a: re-review scoped -> FINDINGS (3), keiner blockierend. Der Re-Reviewer hat meinen Mutationstest zurecht VERSCHAERFT: ich hatte beide Guards gemeinsam entfernt, was nur zeigt, dass die zwei Tests zusammen beide Guards abdecken. Einzeln mutiert haengt jeder Guard an genau einem Test.
Task 5a: fix round 2/5 (3 addressed; commit b0d0fda):
  FIX 1 filesize() kann false liefern, und false === 0 ist false -> fehlgeschlagenes filesize() haette den Guard passiert und die Schrift als heil gemeldet. Zusaetzlich war die Warning nicht unterdrueckt, waehrend der Bildzweig @file_get_contents nutzt -> unter failOnWarning/Laravel Abbruch statt missing-Eintrag. Die zwei Zweige irrten in ENTGEGENGESETZTE Richtungen.
  FIX 2 Testnamen "NullByte" waren zweideutig (0 Byte Laenge vs. NUL-Byte im Inhalt) -> umbenannt.
  FIX 3 Reihenfolge-Test deckte nur den Absent-Fall ab; zweiter Test mit vier vorhandenen 0-Byte-Dateien ergaenzt.
Task 5a: BEWUSST UNGETESTETE STELLE, nicht verschweigen: der false-Fang aus FIX 1 haengt an keinem Test. Mutation C (isoliert nur den false-Fang entfernt) laeuft GRUEN — vom Implementierer offen gemeldet, vom Controller selbst nachgefahren und bestaetigt. Ein Test ist nicht sinnvoll konstruierbar: der Zustand entsteht nur ueber einen nicht-deterministischen Race (Fremdprozess entfernt die Datei zwischen is_file und filesize), und filesize ist bei einer statischen Methode ohne Filesystem-Seam nicht mockbar. Kein Placebo-Test geschrieben. Die Begruendung steht als Kommentar im Code.
Task 5a: zwei Meldungen des Implementierers, die MEINE Vorgaben korrigiert haben:
  (a) Der Temp-Nachweis der Runde 1 hat nichts belegt: sys_get_temp_dir() ist hier /var/folders/6r/.../T, nicht /tmp — das "ls /tmp | grep zert" konnte per Konstruktion nichts finden. Ich hatte das akzeptiert. Jetzt am echten Ort geprueft, dort ist nichts.
  (b) Meine Auflage "Mutationstest VOR dem Commit, Restore per git checkout --" ist in sich widerspruechlich: ohne Commit loescht das Restore den Fix mit. Reihenfolge korrigiert, keine Pruefung entfallen.
Task 5a: complete (commits 82f17ea..b0d0fda, re-review clean nach Runde 2)

## Plan- und Spec-Nachtrag aus dieser Runde (commit f9f2b1b)

Drei Befunde, die nicht in Task 5a gehoerten, aber aus seinem Review fielen:
1) Prototyp-Pfad stand an drei Stellen ohne Praefix als docs/zertifikat/mockups/prototyp/ — liest sich repo-relativ, und das Repo HAT ein docs/zertifikat/ (mit der Guard-Landkarte, ohne den Prototyp). Man landet in einem existierenden Verzeichnis ohne die Datei. Jetzt absolut. Korrektur meiner eigenen Zwischenaussage: der Prototyp EXISTIERT, unter /Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/.
2) font-weight-Falle gemessen und in Task 6 dokumentiert: @font-face mit font-weight:600 faellt STUMM auf Helvetica zurueck, solange der body keine 600 fordert. Die Datei heisst Oswald-SemiBold.ttf, also ist 600 die intuitive Deklaration. Vier Kombinationen gemessen. Der Plan hatte den richtigen Wert (normal) schon, aber ohne Begruendung. Gefunden, weil mein EIGENES Probe-Skript darauf hereingefallen ist — die Referenzzeile war rot, was die erste Messtabelle wertlos machte.
3) Task 9 haelt jetzt gemessen fest, was Assertion 2 abdeckt: fuenf Beschaedigungsstufen gegen drei Waechter. /BaseFont ist der EINZIGE Waechter, der jede Stufe rot macht. FontGlyphCoverage ist KEIN Schutz gegen kaputte Fonts — bei unparsbarer Datei liefert es [], also "nichts fehlt".

## OFFENE ENTSCHEIDUNG des Users, gebraucht VOR Task 9

FontGlyphCoverage::missing() gibt [] zurueck fuer "nichts fehlt" UND fuer "Font
nicht parsbar". Gemessen: 3-Byte-Font -> [], intakter Font -> ['★']. Folge: der
Vorlagen-Editor (Plan Task 13, Zeile ~3309) zeigt auf einem kaputten Font einen
gruenen Haken. Additiv behebbar (zweite Methode, die "nicht pruefbar" von
"nichts fehlt" trennt), solange die zwei Konsumenten noch nicht existieren —
danach ist es eine Aenderung an ausgeliefertem UI-Verhalten. Reoeffnet Task 4
und beruehrt die Spec-Formulierung "Hilfe, kein Gate". Tasks 6, 6a, 7, 8 haengen
nicht daran, laufen also weiter.

## Stand nach Task 5a

Fertig: Task 0, 1, 2, 2a, 3, 4, 5, 5a.
Naechster: Task 6 (TrainingCertificateHtml).
Branch feat/schulungszertifikat @ b0d0fda, Worktree .claude/worktrees/zertifikat.
Suite Default-Reihenfolge: OK (548 tests, 1536 assertions).
Geparkte Minors: 10 (unveraendert — die drei Funde dieser Runde sind sofort
erledigt oder als Entscheidung eskaliert, nicht geparkt).
Merge-Vormerkung: fbd72db zuerst auf main.

## Task 6 (TrainingCertificateHtml)

Task 6: implementer meldete, dass MEIN Brief-Test gegen MEINE Brief-Implementierung rot war. Zwei Planfehler von mir: (a) 'p {', 'h2 {', 'li {' konnten nie matchen, weil die Selektoren im Stylesheet in Spalten stehen (p<6 Leerzeichen>{); nur 'strong {' hatte ein einzelnes Leerzeichen. (b) Der Test auf ein fehlendes Headline-Bild pruefte den nackten String 'zert-headline', der als CSS-Regel IMMER im HTML steht. Beides selbst nachgemessen. Die Ersetzungen des Implementierers sind schaerfer als meine Vorgabe, nicht passend gebogen.
Task 6: der Implementierer hat einen Test ergaenzt, den mein Brief NICHT hatte — die font-weight-Invariante. Ich hatte die Falle im Brief DREIMAL in Prosa hervorgehoben und keinen Mechanismus dafuer gebaut. Genau der Unterschied, um den es beim task-brief-Skript ging.
Task 6: review -> FINDINGS (4 x "sollte", 3 Minors), keiner blockierend. Vier Mutationen, die die Ausgabe kaputtmachen und die Suite gruen lassen: Signaturbild verliert den Fuss-Anker; body-font-family von der @font-face-Familie abgekoppelt (WEITER als die font-weight-Tuer); @font-face heisst anders als angefordert; meta charset entfaellt (Mojibake in jedem Umlaut). Plus: leerer oder null Font-Pfad wird wortlos zu url(""), das DomPDF stumm ignoriert.
Task 6: der Reviewer hat MICH korrigiert, und er hat recht. Ich hatte dem User berichtet, meine Headline-Assertion waere "ohne Bild gruen geblieben, also wertlos". Sie war NIE gruen — sie war immer rot, weil die CSS-Regel den String immer liefert. Wertlos war die LOGO-Zeile derselben Methode: assertStringContainsString('zert-logo', $html) ist gruen, auch wenn das Logo-Bild komplett fehlt. Gleiches Ergebnis, andere Ursache; mein Probe-Skript hat eine negative mit einer positiven Assertion verwechselt. Im Chat richtiggestellt.
Task 6: fix round 1/5 (4 addressed + 2 Zugaben; commit 121cb44). Alle vier Brief-Mutationen wurden rot, keine Abweichung noetig. Der Implementierer hat einen dritten FIX-4-Test ergaenzt, weil das gebilligte '?? ""' das Verhalten des fehlenden Keys AENDERT (vorher Warning + fertiges HTML, jetzt Exception) — eine Verhaltensaenderung ohne Test waere die naechste stille Tuer. Und er hat eine verstuemmelte perl-Mutation (@font@font-face) erkannt, die zwar rot lief, aber nicht die beauftragte Mutation war, und sie wiederholt. Nur der saubere Lauf ist protokolliert.
Task 6: complete (commits b0d0fda..121cb44). Suite OK (563 tests, 1572 assertions).

## Spec-Korrektur E5 aus dem Task-6-Review (commit e20756e) — der schwerste Fund der Runde

Spec v1 und v2 behaupteten, die Fuss-Verankerung nehme dem Mittelteil die
Faehigkeit, einen Seitenumbruch zu erzeugen ("strukturell erzwungen"). GEMESSEN
FALSCH: 4 Zeilen 1 Seite, 10 Zeilen 1 Seite, 20 Zeilen 2 SEITEN, 40 Zeilen
2 Seiten. Die Verankerung erzwingt, dass der FUSS nicht umbricht, nicht dass das
Dokument einseitig bleibt. Nicht aufgefallen war es, weil der Prototyp genau die
sechs Zeilen der Originalvorlage hatte — eine Aussage aus einem Einzelfall
verallgemeinert, dieselbe Bauart wie die zwei gekippten Font-Behauptungen.
Folgen im Plan: Task 9 bekommt zwei Tests ueber die LISTENLAENGE mit
Negativkontrolle; Task 7/8 braucht einen advisory Laengen-Guard.
Zweiter Nachtrag: die Glyph-Pruefung darf NIE auf build()-Ausgabe laufen —
strip_tags entfernt den style-Tag, nicht dessen Inhalt, und die Huelle hat einen
CSS-Kommentar mit einem Stern. Gemessen: sternfreier Inhalt durch die Huelle
gefiltert ergibt die Phantom-Meldung "★ fehlt in Oswald".

## Geparkte Minors: jetzt 14 (waren 10)

11) Geometrie der Huelle ist unassertiert (drei Bildbreiten, body-padding,
    left-Werte der Fussbloecke). .zert-logo width 40mm -> 400mm laeuft gruen.
    Sichtpruefungs-Eigenschaft; gehoert eher in Task 9 als in Task 6.
12) 'p { }' als leere Regel laeuft gruen — der Test prueft, dass ein Selektor
    existiert, nicht dass er etwas tut. Die Spec verlangt "sinnvolle Abstaende".
13) Reihenfolge Logo/Headline vs. Inhalt getauscht laeuft gruen, obwohl der
    Brief "oben im Fluss" verlangt.
14) Der fontCache-Aufraeumer (704847c) degradiert still: glob() ueberspringt
    Dotfiles und scheitert an Glob-Metazeichen, is_file() ueberspringt
    Unterverzeichnisse, @rmdir() schluckt das Ergebnis. In drei von vier
    konstruierten Faellen kommt das Leck lautlos zurueck. Praktische Exposition
    gering (DomPDF-fontCache ist flach, macOS-Temp ohne Metazeichen).
    Billiger fester: scandir() statt glob(), rmdir()-Rueckgabe nicht wegwerfen.
    Ausserdem strukturell nicht abgedeckt: tearDownAfterClass laeuft nicht bei
    Fatal Error oder kill — dem Reviewer selbst passiert. Task 9 muss das wissen.

## Stand nach Task 6

Fertig: Task 0, 1, 2, 2a, 3, 4, 5, 5a, 6.
Naechster: Task 4a (nachtraeglich eingefuegt, vom User freigegeben), dann 6a, dann 7.
Branch feat/schulungszertifikat @ 121cb44, Worktree .claude/worktrees/zertifikat.
Suite Default-Reihenfolge: OK (563 tests, 1572 assertions).
Merge-Vormerkung: fbd72db zuerst auf main.

## ZUSCHNITT v3 — 12.08.2026, nach Task 6a, vor Task 7 (commits c70ffa1, 8d04e0d)

Der User hat den Zuschnitt in Frage gestellt: das Dokument ist stumpf (festes
Layout, drei variable Werte, eine Schulungsart, Text aendert sich praktisch nie).
Ergebnis: der Zertifikat-Inhalt steht als festes HTML im Code, KEINE Zeile in
rec_contract_templates.

Beseitigt statt bewacht: 22 Guards (nichts zu filtern), §B8 als einzelne
Ausfallstelle fuer 12 davon, und der Eingriff in resolveSource().

Meine drei Korrekturen an der User-Annahme, alle angenommen:
1) TrainingLeaderResolver bleibt VOLLSTAENDIG noetig — die drei variablen Werte
   muessen auch in festem HTML von irgendwo kommen. Nur der resolveSource()-Zweig
   entfaellt. "Task 7 entfaellt" war falsch.
2) display_name in den Portalen ist der Vorlagenname -> wird Konstante (Task 14).
3) kind-Spalte statt unique(rec_applicant_id) allein — letzteres haette die
   zweite Schulungsart verbaut. User: "besser als mein Reflex".

Und ein Punkt, den der User nicht gelistet hatte: §E8 wird wieder verzichtbar.
Die load-bearing-Markierung von vor drei Commits hing daran, dass HR editieren
kann. Faellt das Textarea, faellt die Begruendung — Task 9 uebernimmt beide
Aufgaben automatisch. Markierung zurueckgenommen MIT Begruendung, nicht nur mit
Ergebnis (User-Auflage: "sonst liest der naechste nur verzichtbar").

Tasks: 0,1,2a,3,4,4a,5,5a,6,6a unveraendert fertig | 2 fertig+geaendert |
7 halbiert | 8,11,13,14 geaendert | 9,10,12 unveraendert | 15,16,17 entfallen.

Drei Mitnahmen, die sonst still verloren gegangen waeren:
- Der &#9733;-Hinweis zieht mit dem Inhalt um. Waere er mit Task 16 verschwunden,
  haette jemand die Entity-Dekodierung in FontGlyphCoverage fuer Altlast
  gehalten und rausgeraeumt. User: "genau die Sorte Kopplung, die man nur beim
  Umzug sieht."
- Die vier Platzhalter behalten {{...}}-Schreibweise und Namen, weil
  ResttagePlaceholder::hasUnresolvedPlaceholder() in Task 9 darauf prueft.
- Die drei 6a-Review-Auflagen an Task 7 entfallen mit dem resolveSource()-Zweig.

## OFFENER PUNKT bis Task 13 — nicht abhaken

issue_training_certificates (Team-Setting, Default false) ist SPEZIFIZIERT, aber
NICHT IMPLEMENTIERT: der Schluessel fehlt in RecApplicantSettings::DEFAULT_SETTINGS,
weil seine Konsumenten (Tasks 8, 11, 13) nicht gebaut sind. Selbst per grep
geprueft und dem User so gemeldet statt "ist drin" zu schreiben. Erst mit dem
letzten Konsumenten (Task 13) ist der Schluessel wirklich drin. Bis dahin gibt es
KEINEN Abschaltweg — wer vorher deployt, hat kein Feature-Flag.


## Task 7 (TrainingLeaderResolver)

Task 7: Brief HALBIERT durch Zuschnitt v3 — nur die Support-Klasse, kein resolveSource()-Zweig. Der Brief war in sich widerspruechlich (Banner sagt halbiert, Files-Zeile darunter sagt noch "Modify RecContractTemplate.php"); im Dispatch ausdruecklich benannt statt zu hoffen, dass der Implementierer den Banner gewinnen laesst.
Task 7: MEIN Dispatch-Fehler — "der dreizehnte Fall ist der wertvollste" in den Task-7-Auftrag geschrieben. Die Aussage war des Users, aber ueber Task 6a; Task 7 hat nur neun Faelle. Der Implementierer hat die Fehlzuordnung gemeldet statt einen dreizehnten Fall zu erfinden. User: "Der Dispatch-Fehler war meiner, nicht deiner — beim Weiterreichen ist der Bezug verlorengegangen."
Task 7: ACHTER Vertreter der Fehlerklasse, wieder in MEINEM Brief-Code: Sortierung per strcmp() ueber Roh-Datumsstrings. 'kaputt' sortiert vor jedes echte Datum ('k' > '2'), '2026-7-05' vor '2026-10-01'. Folge waere leeres Schulungsdatum UND falscher Schulungsleiter, bei gruenen Tests. Selbst nachgemessen.
Task 7: PHP-8.4-Randfaelle, selbst nachgemessen: new DateTimeImmutable("") liefert HEUTE statt zu werfen; "0000-00-00 00:00:00" parst zu 30.11.-0001. Der erste ist der boesere — das Zertifikat haette sein eigenes Ausstellungsdatum als Schulungsdatum getragen, plausibel und konsistent falsch.
Task 7: review -> FINDINGS (4x sollte, 4x Minor), 14 von 14 Mutationen vom Reviewer selbst nachgefahren und bestaetigt.
Task 7: fix round 1/5 (8 addressed; commit c154000). Kern: date_parse-Guard auf die EINGABE statt aufs Ergebnis. Damit gehen drei Einzel-Guards in EINER Verhaltensgrenze auf statt sich zu stapeln — date_parse('') liefert errors=["Empty string"], der Leerstring-Guard waere sonst verhaltenstot geworden. User: "die richtige Verallgemeinerung".
Task 7: Implementierer hat STRENGER umgesetzt als meine Vorgabe (is_string statt is_string || is_int) mit der Begruendung, meine Fassung haette ['Anna', 42] -> 'Anna, 42' durchgelassen — einen Fall, den die Review-Messtabelle selbst als stillen Fehler auffuehrt. Uebernommen.
Task 7: ungefragt gebaut und richtig — ein Gegengewicht-Test mit allen sieben Gutfaellen (Carbon-Mikrosekunden, ISO-Offset). Begruendung: beauftragt waren nur Tests fuer schlechte Eingaben, und ein ZU SCHARFER Guard macht das Zertifikatsfeld leer, derselbe Schaden aus der anderen Richtung.
Task 7: die zwei Mutationen ohne rote Assertion sind aufgeloest. Der User hat es als Testluecken-Befund eingeordnet, nicht als Werkzeugbefund: "laeuft die Suite mal ohne das Flag, sind die zwei Guards ungeschuetzt, und 'OK, but there were issues!' liest jeder als gruen." Loesung: set_error_handler-Helfer, assertSame([], warnungen). Nachweis jeweils MIT --do-not-fail-on-warning, Exitcode 1.

## REGEL 3 — vom User aus Task 7 gezogen, gilt fuer alles Weitere

Ein Kommentar, der eine Falle benennt, muss den sicheren Weg VORGEBEN, nicht den
unsicheren beschreiben. Wo so ein Hinweis noetig ist, gehoert stattdessen eine
Assertion hin.

Zwei Vertreter in diesem Durchlauf, BEIDE MALE war die Prosa das Problem, nicht
der Code:
- "ohne ->all() ist das ein Vertragsbruch" (TrainingLeaderResolver, Task 7)
  nannte die Falle und liess offen, was richtig ist. Die naheliegende
  Halb-Befolgung ->interviewers->all() landete im STILLEN Fall: Model::__toString()
  liefert toJson(), also JSON auf dem Zertifikat, ohne Warnung, ohne roten Test.
  Der Hinweis fuehrte in das Loch, das er schliessen sollte.
- "PFLICHT, nicht Deko" (DuplicateMatchQueryTest, Task 6a) las sich als
  Notwendigkeit und hat die Suche nach dem tatsaechlichen Verursacher verhindert.

Prueffrage vor jedem warnenden Kommentar: kann ein Leser den Hinweis HALB
befolgen und dabei schlechter dastehen als ohne ihn? Wenn ja, ist der Kommentar
der falsche Mechanismus.

Die Regel steht zusaetzlich in den Global Constraints des Plans als Regel 3 —
das Ledger lesen die Subagenten nicht, der Plan schon.

## Geparkte Minors: 17

Neu in dieser Runde (aus dem Task-7-Review):
16) testKeinInterviewerErgibtLeerenString ist strikt von
    testLeiterUndDatumStammenAusDerselbenBuchung subsumiert.
17) testLeereListe ist von testKeineAttendedBuchungErgibtLeereStrings subsumiert.
Beide behalten oder streichen — Entscheidung mit der Sammelvorlage.

ZUSAGE AN DEN USER, angepasst: Vorlage am Stueck nach Task 9 bleibt. ABER wenn
die Zahl vorher 20 reisst, VORHER melden und frueher aufraeumen. Nicht
stillschweigend weiterparken.

## MINORS-TRIAGE, vorgezogen vor Task 8 (commits 6a57539, a640d75, 1d39938)

Der User hat zugestimmt, frueher aufzuraeumen. Sein Grund war der bessere von
meinen zwei: "Minors, die an Entscheidungen haengen, die Task 8 beruehrt, sind
keine Minors mehr."

MEINE ZAEHLUNG WAR FALSCH, und ich habe damit eine Grenze gemeldet. Gegen den
Code geprueft: drei Posten waren laengst erledigt (fontCache-Aufraeumen in
704847c, doppelte Dedup-Logik in Task 4a, konfigurationsabhaengige Aussage in
24398a9), und beim Zaehlen nach Task 7 bin ich von 14 auf 17 gesprungen, obwohl
nur zwei dazukamen. Es waren 17 echte, nicht 21.

Ergebnis der Triage: 17 -> 6 zu tun, 11 gestrichen mit Begruendung. Der User:
"17 auf 6 mit Begruendung ist genau richtig", und zu C8: "eine Zahl in einem
ueberholten Dokument zu korrigieren erzeugt nur den Eindruck, das Dokument sei
aktuell."

Sieben der elf Streichungen hingen an zwei Aenderungen seit dem Parken:
Zuschnitt v3 hat type entwertet (Enum-Schutz, where()->update(), Report-Zahl),
und Task 9 ist der richtige Ort fuer Rendering-Eigenschaften (Geometrie, leere
p-Regel, Reihenfolge) — dort als ausgeschriebene Assertions eingetragen, nicht
als "pruefen ob", mit der Auflage, jede gegen ihre Mutation zu belegen.

A1 (a640d75): der Typ-Vertrag hatte eine Ausnahme zu viel. Der User hat die
Regel bis zum Ende gezogen: '42' muss genauso laut sein wie null, nicht bloss
herausgefiltert — "ein Schulungsleiter, der 42 heisst, ist kein fehlender Wert,
sondern ein kaputter Produzent". Vier Faelle, keine Ausnahme.
BEFUND DES IMPLEMENTIERERS, schaerfer als der Anlass: der ''-Fall war der
teurere, nicht '42'. ['Anna',''] -> 'Anna' machte aus zwei Leitern still einen —
ein Feld, das plausibel UND vollstaendig aussieht.
Und trim() waere bei diesem Umbau wieder still verhaltenstot geworden (sein
einziger Falsifikator war der !== ''-Filter). Dritte Positionsaenderung von
trim() in diesem Task, jedes Mal gemessen.

B3 (1d39938): SCHAERFER ALS MEINE TRIAGE ANNAHM. Ich hatte geschrieben, der Test
scheitere spaeter am fehlenden Verzeichnis. Gemessen mit nicht beschreibbarem
Elternverzeichnis: der alte Code scheitert GAR NICHT — DomPDF faellt still auf
seinen Standard-fontCache zurueck, und der liegt in
meingedeck/vendor/dompdf/dompdf/lib/fonts. Damit war genau die Isolation
verschwunden, die Task 0 Fix-Runde 1 hergestellt hatte, und zwar lautlos.
Fehlerklasse, nicht Meldungsqualitaet.

B1 (1d39938): der Umbau eroeffnet selbst einen Fehlerweg. close() ins finally zu
heben macht fclose(false) erstmals sichtbar (FontLib liefert das Font-Objekt auch
bei fehlgeschlagenem fopen()); vorher fing das aeussere catch den TypeError
versehentlich mit ab. Ohne inneren try/catch tauscht man ein Handle-Leck gegen
einen Bruch der Zusage "wirft nie". Als eigener Test festgenagelt.
Der Test assertiert ueber einen zaehlenden Stream-Wrapper, nicht ueber /dev/fd —
der Deskriptor wird wegen FontLibs Objektgraph nicht sofort eingesammelt.

Offen notiert, nicht angefasst: FontGlyphCoverageTest::tearDown() benutzt selbst
glob() und hat im Prinzip dieselbe Schwaeche wie B2. Fuer Task 9 vermerkt, damit
auch dieses Muster nicht mitkopiert wird.

Geparkte Minors nach der Triage: 0 offen aus der alten Liste.

## Stand vor Task 8

Fertig: 0, 1, 2, 2a, 3, 4, 4a, 5, 5a, 6, 6a, 7 (+ Minors-Triage).
Branch feat/schulungszertifikat @ 1d39938.
Suite: OK (625 tests, 1742 assertions).
Merke: fbdd81/fbd72db zuerst auf main (Worktree-Fix), siehe oben.
OFFEN bis Task 13: issue_training_certificates fehlt noch in DEFAULT_SETTINGS.

## Task 8 (RecTrainingCertificate + IssueTrainingCertificateService), commit e4f2768

Suite 625 -> 647 (+22), 1742 -> 1813 Assertions. Dritte Datei nicht im Brief
gelistet und gemeldet: src/Support/TrainingCertificateContent.php — der feste
HTML-Block als eine Quelle fuer Ausstellung, Vorschau und Render-Test.

ZEHNTER VERTRETER DER FEHLERKLASSE, und diesmal im Bestandscode statt in meinem
Brieftext: RecApplicantSettings::getSetting($key, $default) liest

    $settings[$key] ?? $default ?? (self::DEFAULT_SETTINGS[$key] ?? null)

Bei einer BESTEHENDEN Settings-Zeile ohne den neuen Schluessel — dem Zustand
JEDES heute existierenden Teams — traegt also allein der Default. Mit `true`
statt `false` waere das Feature fuer alle Bestandsteams still AN gewesen, ohne
dass jemand es einschaltet. Die erste Testrunde des Implementierers blieb bei
genau dieser Mutation gruen. Geschlossen mit einem eigenen Test (Team mit alter
Zeile ohne Schluessel).
Wir haben die sichere Richtung gewaehlt (Default false), aber der Mechanismus
bleibt fragil: es ist der Default, der die Arbeit tut, nicht ein gespeicherter
Wert. Wer den Default je auf true dreht, schaltet Bestandsteams still ein.

AUFLAGE AN TASK 11/12/13, vom Implementierer: issue() WIRFT bei ausgeschaltetem
Schalter (nicht-nullable Rueckgabetyp). Weg (b) muss vorher isEnabledForTeam()
fragen und darf den Aufruf NICHT in try/catch legen — sonst reisst ein
ausgeschaltetes Feature die Mitarbeiter-Anlage mit.

Empfehlung fuer Task 9, uebernommen: den Inhalt aus TrainingCertificateContent
konsumieren statt den Planentwurf-String zu kopieren, sonst driften Zertifikat
und Render-Test.

Weitere Meldungen: TestSchema::trainingCertificates() hat jetzt keinen
Konsumenten mehr (der Task laedt die echten Migrationen, damit 2026_08_12_000002
erstmals ausgefuehrt wird). try/catch (\RuntimeException) schluckt PHPUnits
fail(), weil AssertionFailedError eine RuntimeException ist — gemessen, im Modul
leicht wieder einzubauen. Der Race auf dem Unique ist absichtlich nicht
abgefangen: nicht rot zu machen, deshalb benannte Luecke statt ungetesteter
Guard.

## REGEL 5 — Muster hinter drei Fehlmessungen (jetzt auch in den Global Constraints)

Eine Regel aus einer Messung braucht die ISOLIERTE Gegenprobe, sonst schreibt man
die Ursache fest, die man VERMUTET hat, statt der gemessenen. Dreimal passiert,
immer derselbe Ablauf: Messung schlaegt fehl -> naheliegende Ursache
festgeschrieben -> Regel gilt danach als gemessen.

1) Times-Bold (Task 0): aus einer Fontliste geschlossen, Ursache lag im
   Metrik-Cache. Nach 11 Konfigurationen nicht reproduzierbar, G24 als Messfehler
   zurueckgenommen.
2) §E5 (Task-6-Review): "Einseitigkeit strukturell erzwungen", aus dem Prototyp
   mit sechs Listenzeilen verallgemeinert. Bei 20 Zeilen zwei Seiten. Die Spec
   hatte bei G13.5 laengst das Gegenteil als gemessenen Fakt.
3) fontDir/chroot (Task-9/10-Review): "fontDir darf nie spaet kommen" — der
   Ausfall kam vom chroot. Die falsche Regel stand zwei Commits in der Spec und
   haette den einzigen sauberen Fix fuer storage/fonts VERBOTEN.

Verfahren: eine Variable pro Lauf, Gutfall mitmessen. Beim fontDir-Irrtum haette
EIN Lauf mit korrektem chroot und spaetem fontDir die Regel widerlegt. Wer eine
Regel formuliert, muss sagen koennen, welche Messung sie widerlegen wuerde — und
diese Messung gefahren haben.

## BLOCKIERENDER FUND F1 (Task-9/10-Review) — selbst nachgestellt

meingedeck/storage/fonts existiert NICHT, es gibt keine config/dompdf.php, der
Paket-Default zeigt auf storage_path('fonts'). Mit fehlendem Verzeichnis:
TypeError in AdobeFontMetrics.php:226, in render(), also vor jeder Ausgabe -> 500
auf 100 % der Aufrufe, auf dem WhatsApp-Link an abgelehnte Bewerber. Mit
existierendem Verzeichnis: OK, 315693 Byte, Oswald-SemiBold eingebettet.

Warum es niemand bemerkt haette: das Zertifikat ist das ERSTE @font-face-PDF der
ganzen Host-App. Vertraege rendern mit gebuendeltem DejaVu Sans.

UND DER GRUND IST WICHTIGER ALS DER FUND (User): der Render-Test ist dagegen
KONSTRUKTIV BLIND. Er biegt fontDir auf Temp um — richtig fuer die Isolation, und
genau deshalb kann die beste Absicherung des Pakets diesen Fehler prinzipiell
nicht finden. Das gehoert als Kommentar an den Test, sonst haelt ihn beim
naechsten Mal jemand fuer vollstaendig.

Entscheidung des Users: Code, nicht Ops. Begruendung: das Verzeichnis liegt nicht
im Git, der Fehlerfall waere der erste echte Bewerber, und Ops-Wissen verfaellt
beim naechsten Server.

## REGEL 6 — try/fail/catch schluckt das eigene fail() (jetzt auch im Plan)

PHPUnit\Framework\AssertionFailedError IST eine \RuntimeException (gemessen). Ein
Test, der einen erwarteten Wurf per try { ...; $this->fail(); } catch
(\RuntimeException) prueft, wird GRUEN, wenn der Wurf ausbleibt — das fail()
landet im eigenen catch.

Zweimal aufgetreten: Task 8 und die Task-9/10-Fix-Runde, beide Male vom
Implementierer selbst gefunden. Der dritte Auftritt waere der teure: in Tasks
11-14 sind die Tests dann gruen statt rot, und niemand merkt es, weil ein gruener
Test keine Aufmerksamkeit erzeugt (User).

Sicherer Weg: expectException(), oder die Exception in eine Variable fangen
(catch \Throwable AUSSERHALB des Assertion-Pfads) und danach assertieren.

## Task-9/10-Fix-Runde: die VIERTE Messkorrektur, und diesmal griff die Korrektur zu kurz

Meine korrigierte Options-Regel war immer noch unvollstaendig. fontDir/fontCache
werden an ZWEI Zeitpunkten gelesen:
 - beim Konstruieren: welche Fonts gelten als REGISTRIERT
   (FontMetrics::__construct liest fontDir/installed-fonts.json)
 - zur render()-Zeit: wohin wird GESCHRIEBEN
Gemessen: eine fremde Registrierung im Konstruktions-Ordner gewinnt komplett —
/BaseFont /zert_normal_<md5> statt Oswald, 310589 statt 315786 Byte.

Die vier Dateien in meingedeck/vendor/dompdf/dompdf/lib/fonts (aus MEINEN
Mess-Skripten, die ohne eigenen fontDir gerendert haben) haben genau dadurch die
erste Messung des Implementierers verfaelscht — die Fehlerklasse aus F1 belegt
sich damit selbst. Nicht angefasst, weil ausserhalb des Moduls; der User raeumt.

## Sieben untestbare Controller-Punkte -> Live-Sichtpruefung der Spec

Als eigener Abschnitt "Live-Sichtpruefung nach dem Deploy" in die Spec, nicht nur
ins Ledger — es ist die Liste, die der User beim ersten Klick abgeht. Punkte 2
(stream statt download) und 3 (Font wirklich Oswald) sind die wichtigsten, weil
beide zur Fehlerklasse gehoeren: kein Absturz, kein rotes Signal, nur ein
falsches Dokument. Ein Download statt einer Anzeige faellt erst auf, wenn ein
Bewerber es meldet — und der meldet nichts, er klickt nur nicht weiter.

## Task 11 (Ablehnen-Zweig), commit 10864eb

BASELINE-WARNUNG, aufgeloest: Task 11 wurde gegen eine KAPUTTE Baseline gemessen.
Beim Aufraeumen meiner vier zert_normal_*-Messdateien in
meingedeck/vendor/dompdf/dompdf/lib/fonts sind DomPDFs mitgelieferte DejaVu-Fonts
und installed-fonts.dist.json mitgegangen (rm statt composer install). Ordner war
danach leer, 9 Tests mit Error: ContractPdfRegressionTest (2) und
TrainingCertificateRenderTest (7) — also genau die PDF-Absicherung, die den
500er-Fund erst moeglich gemacht hat.
Nach composer reinstall dompdf/dompdf (User): 40 Dateien, Suite
OK (689 tests, 1939 assertions), die 17 betroffenen gruen.

EIGENER BEFUND aus dem Vergleich: kaputt war es 689 / 1921 / 9 Errors, heil
689 / 1939 / 0. GLEICHE Testzahl, +18 Assertions. Ein Error zaehlt den Test, aber
nicht seine Assertions — wer nur auf die Testzahl schaut, sieht den Schaden
nicht. Derselbe Mechanismus wie die Fehlerklasse des Pakets, diesmal in der
MESSUNG selbst.

ZWOELFTER VERTRETER DER FEHLERKLASSE, aus meinem Brieftext:
in_array($applicant->id, $this->attendedApplicantIds(), true) — die Methode
liefert pluck->flip, also eine Map applicantId => POSITION. Werte sind 0,1,2...
Die Checkbox waere beim FALSCHEN Bewerber erschienen. Kein Fehler, keine
Exception. Der Bestand macht es in der Blade richtig, mit isset() auf dem
Schluessel.
Zweiter Fund: alreadyIssued ohne kind-Filter — ein Kuechen-Zertifikat haette die
Checkbox fuer die Service-Basisschulung verdeckt.

REGEL 4 ERWEITERT (User): Namenspruefung allein reicht nicht. Bei jeder
Bestandsmethode, deren Rueckgabewert weiterverarbeitet wird, den tatsaechlichen
Rueckgabetyp AM CODE pruefen, nicht am Namen. method_exists sagte ja, der Name
stimmte, was fehlte war ein Blick in den Rumpf. Dieselbe Falle in jedem keyBy,
mapWithKeys, ->flip() und array_column mit Indexspalte.

QUERY-PROTOKOLL als Muster fuer Task 13 festgehalten: der Zustands-Test allein
genuegte nicht. Mutation "Settings-Lookup vor den Guard, nichts schreiben" liess
Zustand und Ablehnung korrekt und waere gruen durchgelaufen. Rot erst durch die
Assertion auf das Query-Protokoll. Query-Zahl MESSEN statt schaetzen: es waren 4,
meine Schaetzung war 5.

tools/blade-check.php war im Worktree TOT (Exit 2, Autoloader-Pfad fest vier
Ebenen aufwaerts). Task 11 ist der erste Task des Pakets mit Blade-Aenderung, das
Werkzeug haette also von Anfang an nichts geprueft. Repariert mit
Negativkontrolle. Task 14 ist der zweite und letzte Blade-Task — dort waere es
das einzige pruefende Werkzeug gewesen.

Kopplung 11<->12 als BEDINGUNG im Task, nicht als Kommentar: der Modal-Hinweis
nennt den Zertifikat-Link, der erst mit Task 12 real wird. Bei Alleindeploy von
11 ist der Halbsatz zu streichen, sonst verspricht die UI eine Nachricht, die
nicht rausgeht.

## REGEL 7 — bei jedem Suite-Vergleich beide Zahlen plus die Fehlerzahl

Ein Error zaehlt den TEST, aber nicht seine ASSERTIONS. Eine kaputte Baseline
sieht auf der Testzahl deshalb unauffaellig aus.

Gemessen: leerer vendor-Fontordner -> 689 tests / 1921 assertions / 9 Errors.
Geheilt -> 689 tests / 1939 assertions / 0. GLEICHE Testzahl, +18 Assertions.
Neun Tests brachen ab, bevor sie assertieren konnten, darunter die sieben des
Zertifikat-Render-Tests — also die Absicherung, die den 500er-Fund erst moeglich
gemacht hat. Task 11 wurde gegen diese Baseline gemessen; die Differenz war
korrekt, die Grundlage stumm kaputt.

Ab jetzt: "OK (689 tests, 1939 assertions)" als Ganzes zitieren, bei
ERRORS!/FAILURES! die Fehlerzahl mit. "689 Tests wie vorher" belegt nichts.
Steht auch in den Global Constraints des Plans, damit es die Subagenten erreicht.

## Zum rm-Vorfall, fuer die Akten

Der User hat die Empfehlung gegeben und sie selbst als falsch benannt. Mein
Anteil: ich habe eine Dateiliste gemeldet, aber nicht, was SONST in dem
Verzeichnis liegt. Bei einer Loeschempfehlung an einen Ort, den man selbst
verschmutzt hat, gehoert das dazu — die vier Dateien waren meine, die 40 anderen
nicht. Ursache der Verschmutzung: eigene Mess-Skripte, die ohne eigenen fontDir
gerendert haben und damit in DomPDFs Default-Verzeichnis schrieben.

## Regel-4-Abgleich vor Task 12 (durchgefuehrt, sauber)

HoldingTemplateSender::sendOne() liegt bei src/Services/Comms/...:81-84, Signatur
(int $teamId, string $phone, string $firstName, string $settingsKey,
array $namedValues, bool $isAutoReply): array — wie im Plan behauptet, inklusive
Zeilennummern. sendOne delegiert vollstaendig an sendToMany (Zeile 83), und
sendToMany faengt \Throwable INNERHALB der Empfaenger-Schleife. Auflage 1 des
Users (Sender wirft -> Ablehnung bleibt committet) hat damit eine echte
Grundlage, keine erschlossene.

## Gemessen: Transaktionszustand nach einem GEFANGENEN Statement-Fehler

Die Antwort, bevor sie beim naechsten Mal neu gesucht wird:

  sqlite    Transaktion bleibt voll benutzbar. Unique-Verletzung gefangen,
            danach weiterer Insert gelungen, Commit greift, transactionLevel
            unveraendert 1. GEMESSEN.
  MySQL     dasselbe nach dokumentiertem Verhalten (Statement-Fehler bricht die
            Transaktion nicht ab). NICHT GEMESSEN, erschlossen.
  Postgres  NEIN. Dort vergiftet JEDER Fehler die Transaktion, jedes
            Folgestatement scheitert mit "current transaction is aborted" — und
            der Folgefehler ist irrefuehrend.

Produktionstreiber liess sich nicht feststellen: config/database.php hat
env('DB_CONNECTION', 'sqlite'), die .env ist nicht lesbar. Nach den MySQL-Bezuegen
im Modul (Fehler 1059, 64-Zeichen-Grenze) ist MySQL anzunehmen — erschlossen.

Fuer Task 13 entscheidet die Messung NICHT mehr. Der User hat seine eigene Regel
("wenn es zutrifft, hinter den Commit") zurueckgenommen, weil das unabhaengige
Argument staerker ist: innerhalb der Transaktion ist "alles oder nichts" die
Zusage, und die will man bei Weg (b) nicht — ein Mitarbeiter ohne Zertifikat ist
ein legitimer Zustand, der umgekehrte Fall keiner. Der catch waere eine Ausnahme
von einer Zusage, die man von vornherein nicht will.
Nebeneffekt und eigentlicher Gewinn: die erschlossene MySQL-Annahme verschwindet
aus dem Code, statt als ungemessene Voraussetzung drinzustehen. User: "In einem
Durchlauf, in dem vier Messungen mit falsch zugeordneter Ursache gekippt sind,
ist 'die Frage stellt sich nicht mehr' mehr wert als 'die Antwort ist vermutlich
ok'."

## Regel 4 laeuft vor JEDEM Dispatch, nicht nach Gefuehl

Dreimal in Folge hat sie v2-Reste in meinen Brieftexten gefangen:
 - Task 10: ->with('contractTemplate') -> waere ein 500 auf jedem WhatsApp-Link
 - Task 12: $templateId in confirmResolve() und rec_contract_template_id als
   Spalte -> waere ein SQL-Fehler NACH dem Commit
 - Task 13: dasselbe $templateId plus eine um eins verschobene Zeilenangabe
Dazu bei Task 11 die Semantik-Luecke (attendedApplicantIds ist eine Map, keine
Liste), die die erste Fassung der Regel nicht gefangen haette.

DIE BRIEFTEXTE SIND DIE LETZTE STELLE MIT v2-STAND. Spec und Plan wurden beim
Zuschnitt gruendlich nachgezogen; die Briefe werden aus dem Plan generiert und
dann einzeln angereichert, und sie werden nie gegen den Code gelesen. Regel 4 ist
deshalb keine Empfehlung, sondern ein Schritt vor dem Dispatch.
