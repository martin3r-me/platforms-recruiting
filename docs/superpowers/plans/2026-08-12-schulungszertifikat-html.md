# Schulungszertifikat als HTML-Vorlage — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> # ZUSCHNITT v3, 12.08.2026 — vor Task 7 entschieden, nach Task 6a
>
> **Das Zertifikat ist keine Vorlage.** Der Inhalt steht als festes HTML im Code, nicht als Zeile in `rec_contract_templates`. Grund: das Dokument hat drei variable Werte, eine Schulungsart und einen Text, der sich praktisch nie ändert — die Vorlagen-Infrastruktur trug die Kosten einer Flexibilität, die niemand braucht.
>
> **Betroffene Tasks tragen einen Banner direkt unter ihrer Überschrift.** Der alte Text bleibt darunter als Protokoll stehen; maßgeblich ist der Banner. Kurzfassung:
>
> | | Tasks |
> | --- | --- |
> | **unverändert fertig** | 0, 1, 2a, 3, 4, 4a, 5, 5a, 6, 6a |
> | **fertig, geändert** | 2 (Migration editiert: `kind` statt Vorlagen-FK) |
> | **bleibt, halbiert** | 7 (`TrainingLeaderResolver` bleibt, `resolveSource()`-Zweig entfällt) |
> | **bleibt, geändert** | 8, 11, 13, 14 |
> | **bleibt unverändert** | 9, 10, 12 |
> | **entfällt** | 15, 16, 17 |
>
> Vollständige Begründung und der Preis: Spec, „Revision v3" und „Aufgegeben mit dem Zuschnitt v3".

**Goal:** Ein ausstellbares Schulungszertifikat mit **festem HTML-Inhalt im Code** — gerendert als einseitiges A4-PDF, ausgestellt bei HR-Ablehnung und automatisch bei der Mitarbeiter-Anlage, abrufbar in beiden Portalen. ~~als gewöhnliche Vorlage in `rec_contract_templates` — HTML mit Platzhaltern~~

**Architecture:** ~~Zertifikat-Vorlagen sind `rec_contract_templates` mit `type='certificate'` und erzwungenem `code`-Präfix `ZERT-`.~~ **[v3]** Es gibt keine Zertifikat-Zeile in `rec_contract_templates`; `type` und der `ZERT-`-Präfixzwang bleiben als leerlaufende Tür für den Rückweg im Code und sind dort als tote Schalter kommentiert. Das Aussehen liegt nicht in einer Blade, sondern in zwei laravel-freien Support-Klassen (HTML-Hülle und DomPDF-Optionen), die Controller *und* Test konsumieren — sonst testet der Render-Test eine anders konfigurierte Engine als die ausgelieferte. Ausgestellte Zertifikate liegen in einer eigenen Tabelle mit Inhalts-Snapshot, dedupliziert über `(rec_applicant_id, kind)`, nicht als `RecContract`.

**Tech Stack:** PHP 8.4, Laravel (Host-App `meingedeck`), Livewire 3, DomPDF 3.1.5 via `barryvdh/laravel-dompdf` 3.1.2, `dompdf/php-font-lib` (transitiv), PHPUnit 11.5, SQLite in-memory via `Illuminate\Database\Capsule\Manager`.

## Global Constraints

- **Test-Runner:** `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`, ausgeführt im Modul-Root `modules/platforms-recruiting`. Das Modul hat **kein eigenes `vendor/`**.
- **Kein Laravel-Bootstrap in Tests.** `tests/bootstrap.php` ist ein reiner Autoloader mit dem Kommentar „kein Laravel-Bootstrap"; `orchestra/testbench` ist nicht installiert. Unit-Tests dürfen nur laravel-freie Klassen laden. Integrationstests bauen Container + Capsule von Hand (Muster: `tests/Integration/DuplicateMatchQueryTest.php:28-45`).
- **Der Runner lädt Modulklassen aus der Arbeitskopie**, nicht aus `meingedeck/vendor` (per Reflection gemessen). Kein composer-Bump für Tests nötig.
- **`Pdf::loadHTML` ist im Test nicht aufrufbar** (Facade braucht App-Container). `Dompdf\Dompdf` direkt schon.
- **Keine `grep`- oder Literal-String-Assertions auf PDF-Bytes.** Nur `preg_match_all` mit `\s*`. **Die Begründung dieser Regel war zwei Commits lang falsch** („je 0 Treffer, Marker über Zeilenumbruch verteilt") und ist am gerenderten Zertifikat neu gemessen (Fix-Runde Task 9/10, Details im Docblock von `TrainingCertificateRenderTest`): die Marker stehen **nicht** über Zeilen verteilt, `/usr/bin/grep -c "/Type /Page"` liefert **2** und `-c "/BaseFont"` **4** (je rc=0). Die echten Gründe sind (1) `/Type /Page` ist **Präfix von** `/Type /Pages`, dem Seitenbaum — ein Literalzähler liefert auf dem einseitigen Dokument 2 statt 1; (2) `grep -c` zählt **Zeilen, nicht Treffer** (auf dieser Datei stimmen beide Zahlen zufällig überein, die Zahl sieht also richtig aus, ohne die gefragte Größe zu messen); (3) die Binärbehandlung ist **werkzeugabhängig** — BSD grep 2.6.0 zählt, das `grep` im Shell-Wrapper der Werkzeugkette ist ugrep 7.5.0 mit `-I` und überspringt die Datei ganz (keine Ausgabe, rc=1).
- **Zertifikat-`code` beginnt immer mit `ZERT-`.** Erzwungen im Model, siehe Task 3.
- **Nicht anfassen:** `resources/views/pdf/contract.blade.php`, `src/Http/Controllers/Concerns/RendersContractPdf.php`, `src/Http/Controllers/ContractPdfController.php`. Der Zertifikat-Weg bekommt eigenen Controller und eigene Hülle. Keine „Gemeinsamkeiten" in den Trait ziehen.
- **Farben verbatim:** Papier `#FDF3E0`, Tinte `#3C4A63`.
- **Commit-Präfixe** wie im Repo üblich: `feat(recruiting):`, `fix(recruiting):`, `refactor(recruiting):`.

### Zwei Regeln, die aus Fehlern dieses Durchlaufs stammen — für alle restlichen Tasks verbindlich

**1) Falsifizierbarkeitsfrage pro Test: welche plausible Änderung macht ihn rot?** Findet sich keine, ist der Test falsch formuliert oder überflüssig — er belegt dann Unverletzbares und täuscht Schutz vor. Gefunden an Fall 7 des Pin-Tests (Task 6a): er sollte belegen, dass der Lookup-Zweig nicht global übersetzt, aber diese Eigenschaft ist über den Codepfad prinzipiell nicht verletzbar, weil `ZasLookupResolver::loadLabelMap()` selbst guardet. Ein Test, dessen Aussage nicht kaputtzumachen ist, ist kein Test.

**7) Bei jedem Suite-Vergleich BEIDE Zahlen nennen, plus die Fehlerzahl — nie nur „X tests".** Ein `Error` zählt den **Test**, aber nicht seine **Assertions**. Eine kaputte Baseline sieht auf der Testzahl deshalb unauffällig aus.

Gemessen an genau diesem Fall: mit leerem `vendor/dompdf/dompdf/lib/fonts` lief die Suite als `689 tests / 1921 assertions / 9 Errors`, geheilt als `689 tests / 1939 assertions / 0`. **Gleiche Testzahl, +18 Assertions.** Neun Tests brachen ab, bevor sie assertieren konnten — darunter die sieben des Zertifikat-Render-Tests, also die Absicherung, die den 500er-Fund überhaupt erst möglich gemacht hat. Task 11 wurde gegen diese Baseline gemessen; die Differenz war korrekt, die Grundlage stumm kaputt.

Also: `OK (689 tests, 1939 assertions)` als Ganzes zitieren, und bei `ERRORS!`/`FAILURES!` die Fehlerzahl mit. Wer „689 Tests wie vorher" schreibt, hat nichts belegt.

**6) `try { … fail(); } catch (\RuntimeException …)` schluckt das eigene `fail()`.** `PHPUnit\Framework\AssertionFailedError` **ist** eine `\RuntimeException` (gemessen). Ein Test, der einen erwarteten Wurf so prüft, wird **grün**, wenn der Wurf ausbleibt — der `fail()`-Aufruf landet im eigenen `catch`.

Zweimal in diesem Durchlauf aufgetreten (Task 8 und die Task-9/10-Fix-Runde), beide Male vom Implementierer selbst gefunden. Der dritte Auftritt wäre der teure: in Tasks 11–14 sind die Tests dann grün statt rot, und **niemand merkt es**, weil ein grüner Test keine Aufmerksamkeit erzeugt.

Sicherer Weg — einer von beiden, nicht der `catch`-Block mit `fail()`:
```php
$this->expectException(\InvalidArgumentException::class);   // wenn nur der Typ zählt
// oder: Exception in eine Variable fangen und danach assertieren
$e = null;
try { $fn(); } catch (\Throwable $caught) { $e = $caught; }
$this->assertInstanceOf(\InvalidArgumentException::class, $e);
```
Fängt man selbst, dann `\Throwable` **außerhalb** des Assertion-Pfads, und die Prüfung passiert danach.

**5) Eine Regel aus einer Messung braucht die ISOLIERTE Gegenprobe — sonst schreibt man die Ursache fest, die man vermutet hat, statt der gemessenen.**

Dreimal in diesem Durchlauf passiert, jedes Mal mit demselben Ablauf: eine Messung schlägt fehl, die naheliegende Ursache wird festgeschrieben, und die Regel gilt danach als gemessen.

- **Times-Bold (Task 0):** aus einer Fontliste geschlossen, die Ursache lag im Metrik-Cache. Nach 11 Konfigurationen nicht reproduzierbar, Spec-Fakt G24 als Messfehler zurückgenommen.
- **§E5 (Task 6-Review):** „Einseitigkeit ist strukturell erzwungen" — aus dem Prototyp mit sechs Listenzeilen verallgemeinert. Bei 20 Zeilen zwei Seiten. Die Spec enthielt bei G13.5 längst das Gegenteil als gemessenen Fakt.
- **`fontDir`/`chroot` (Task 9/10-Review):** „`fontDir` darf nie spät kommen" — der Ausfall kam vom `chroot`, nicht von `fontDir`. Die falsche Regel stand zwei Commits in der Spec und hätte den einzigen sauberen Fix für das `storage/fonts`-Problem **verboten**.

Das Verfahren dagegen ist billig: **eine Variable pro Lauf ändern, und den Gutfall mitmessen.** Beim `fontDir`-Irrtum hätte ein einziger Lauf mit korrektem `chroot` und spätem `fontDir` die Regel widerlegt. Wer eine Regel formuliert, muss sagen können, welche Messung sie widerlegen würde — und diese Messung gefahren haben.

**4) Vor jedem Dispatch: die im Brief genannten Methoden, Beziehungen und Klassennamen einmal gegen den AKTUELLEN CODE abgleichen — nicht gegen die Spec.**

Anlass, und es ist der teuerste Fund des Tages: der Task-10-Brief enthielt `->with('contractTemplate')`. Die Beziehung existiert seit dem Zuschnitt v3 nicht mehr. Das wäre ein **500 auf jedem WhatsApp-Link** gewesen, und der erste Klick eines abgelehnten Bewerbers wäre der Fehlerfall gewesen. Ohne HTTP-Tests im Modul hätte es nichts gefangen — der Implementierer hat es per `method_exists` gefunden.

**Die Herkunft ist der Punkt, nicht der Fund:** Spec und Plan wurden beim Zuschnitt gründlich nachgezogen, aber die **Briefe** werden aus dem Plan generiert und dann einzeln angereichert. Sie sind damit der letzte Ort, an dem v2-Stand überleben kann — und der einzige, der nie gegen den Code gelesen wird.

Konkret vor dem Dispatch: jeden im Brief genannten Methodennamen, Relationsnamen und Klassennamen per `grep`/`method_exists` gegen `src/` prüfen. Das kostet eine Minute und fängt genau die Klasse von Fehlern, die erst im Betrieb auffällt.

**ERWEITERT nach Task 11, weil die Namensprüfung allein nicht reichte:** bei jeder **Bestandsmethode, deren Rückgabewert weiterverarbeitet wird, den tatsächlichen Rückgabetyp am Code prüfen — nicht am Namen.**

Anlass: der Task-11-Brief enthielt `in_array($applicant->id, $this->attendedApplicantIds(), true)`. Der Name sagt „Liste von IDs", der Code liefert `pluck(...)->flip()`, also eine **Map `applicantId => Position`**. Die Werte sind `0, 1, 2 …`. Die Prüfung hätte gegen Positionen verglichen und die Checkbox **beim falschen Bewerber** eingeblendet — kein Fehler, keine Exception, nur die falsche Zeile. Der Bestand macht es in der Blade schon richtig, mit `isset()` auf dem Schlüssel.

Regel 4 in ihrer ersten Fassung hätte das **nicht** gefangen: `attendedApplicantIds()` existiert, der Name stimmt, `method_exists` sagt ja. Was fehlte, war ein Blick in den Rumpf. `pluck->flip` sieht von außen aus wie eine Liste, und dieselbe Falle steckt in jedem `keyBy`, `mapWithKeys`, `->flip()` und jeder Methode, die `array_column` mit Indexspalte benutzt.

**3) Ein Kommentar, der eine Falle benennt, muss den sicheren Weg VORGEBEN — nicht den unsicheren beschreiben. Wo so ein Hinweis nötig ist, gehört stattdessen eine Assertion hin.**

Zwei Vertreter in diesem Durchlauf, beide Male war die **Prosa** das Problem, nicht der Code:

- `TrainingLeaderResolver` (Task 7): der Docblock schrieb „ohne `->all()` ist das ein Vertragsbruch". Das nennt die Falle und lässt offen, was richtig ist. Die naheliegende Halb-Befolgung — `->interviewers->all()`, das `->all()` befolgt, das `pluck('name')` vergessen — landet im **stillen** Fall: `Model::__toString()` liefert `toJson()`, also stand JSON auf dem Zertifikat, ohne Warnung, ohne roten Test. Der Hinweis führte in das Loch, das er schließen sollte. Ersetzt durch eine Typ-Prüfung, die wirft.
- `DuplicateMatchQueryTest` (Task 6a): der Kommentar „PFLICHT, nicht Deko" an einem `Facade::clearResolvedInstances()`-Aufruf las sich als Notwendigkeit und hat die Suche nach dem tatsächlichen Verursacher verhindert — der Aufruf war Vorsorge, die Verschmutzung kam von woanders.

Prüffrage vor jedem warnenden Kommentar: **kann ein Leser den Hinweis halb befolgen und dabei schlechter dastehen als ohne ihn?** Wenn ja, ist der Kommentar der falsche Mechanismus.

**2) Die eine Fehlerklasse, die in diesem Paket siebenmal zugeschlagen hat: eine Bedingung, ein Guard oder eine Assertion, die plausibel aussieht und den Fehlerfall durchlässt, ohne dass etwas rot wird.** Vertreter bisher: Idempotenz-Guard für nur eine von zwei DDL-Operationen (Task 1); `try/catch` in einer Blueprint-Closure, das nichts fing, weil das SQL danach läuft (Task 1); `str_starts_with` ohne Verzeichnisgrenze (Task 5); `file_get_contents() !== false` bei einer lesbaren 0-Byte-Datei (Task 5a); `filesize() === 0` gegen `false` (Task 5a); vier Assertionen, die eine kaputte HTML-Hülle grün ließen (Task 6); `[]` als Rückgabe für „nichts fehlt" **und** „nicht prüfbar" (Task 4a). **Alle sieben standen in einem plausibel klingenden Auftragstext.** Wer einen Guard schreibt, muss die Mutation fahren, die ihn aushebelt — erschließen genügt nicht.

---

## File Structure

**Neu — laravel-freie Support-Klassen (alle unit-testbar):**
- `src/Support/FontGlyphCoverage.php` — welche Zeichen eines Textes fehlen in einer TTF-Datei
- `src/Support/TrainingCertificatePdfOptions.php` — einzige Quelle der DomPDF-Optionen
- `src/Support/TrainingCertificateAssets.php` — einzige Quelle der Asset-Auflösung (Schrift + drei Bilder + Liste der fehlenden)
- `src/Support/TrainingCertificateHtml.php` — HTML-Hülle: Seitensetup, Styles, die drei Bilder
- `src/Support/TrainingLeaderResolver.php` — Schulungsleiter-Namen aus einer Buchungsmenge
- `tests/Support/TestSchema.php` — die EINZIGE Quelle des handgebauten Testschemas (siehe Task 2a)
- `tests/Integration/PlaceholderResolutionPinTest.php` — nagelt die Platzhalter-Auflösung der Bestandsvorlagen fest (Task 6a, muss vor Task 7 grün sein)

**Neu — Persistenz und Ausstellung:**
- `database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php`
- `database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php`
- `src/Models/RecTrainingCertificate.php`
- `src/Services/IssueTrainingCertificateService.php`
- `src/Http/Controllers/TrainingCertificatePdfController.php`
- `src/Console/Commands/SeedTrainingCertificateTemplate.php`

**Neu — Assets (kommen mit dem Deploy, kein Upload):**
- `resources/fonts/Oswald-SemiBold.ttf`, `resources/fonts/OFL.txt`
- `resources/images/certificates/logo.png`
- `resources/images/certificates/headline-zertifikat.png`
- `resources/images/certificates/signature-block.png`

**Ändern:**
- `src/Models/RecContractTemplate.php` — `type`, Konstanten, saving-Hook, `schulung.`-Zweig
- `src/Services/HrDeskRoutingService.php` — Ausstellung in `applyRejection`
- `src/Livewire/HrDesk/Index.php` + `resources/views/livewire/hr-desk/index.blade.php`
- `src/Services/CreateEmployeeFromApplicantService.php`
- `src/Livewire/Public/EmployeePortal.php` + `resources/views/livewire/public/employee-portal.blade.php`
- `src/Livewire/Public/ApplicantPortal.php` + `resources/views/livewire/public/applicant-portal.blade.php`
- `src/Livewire/ContractTemplates/Index.php` + `resources/views/livewire/contract-templates/index.blade.php`
- `src/Models/RecApplicantSettings.php`
- `routes/public.php`
- Die 22 Handlungszeilen aus `docs/zertifikat/guard-landkarte-511451c.md`

---

### Task 0: Regressionsschutz Bestandsverträge — SOLL einfrieren, BEVOR gebaut wird

**Files:**
- Test: `tests/Integration/ContractPdfRegressionTest.php`

**Interfaces:**
- Consumes: `resources/views/pdf/contract.blade.php` (nur lesend, **nicht ändern**)
- Produces: nichts

**Warum dieser Task an Position 1 steht:** Er friert SOLL-Werte ein. Als letzter Task würde er den Zustand *nach* allen anderen Tasks einfrieren — hätte unterwegs jemand `contract.blade.php` angefasst, also genau das, was er verhindern soll, schriebe er den Schaden als SOLL fest und wäre grün. Er konsumiert nur lesend und produziert nichts, läuft also ohne jede Abhängigkeit vorneweg. Kosten: null.

**Spec-Ausschnitt (wörtlich):**

> **Regressionstest Bestandsverträge:** Vor der ersten Änderung das PDF eines **bereits signierten** Arbeitsvertrags und eines IFSG rendern und ablegen. Nach dem Bau erneut rendern und vergleichen — nicht byteweise (PDFs enthalten Erzeugungszeit und Datei-ID), sondern: extrahierter Text identisch, Liste der eingebetteten Fonts identisch, Seitenzahl identisch, Firmenstempel weiterhin vorhanden.

> **Die Font-Liste wird als SOLL eingefroren.** Wer die Font-Situation später bewusst ändert, aktualisiert den SOLL-Wert und begründet es im Commit — der Test soll nicht fehlschlagen, weil jemand etwas verbessert hat, und er soll nicht schweigen, wenn sich etwas ungeplant verschiebt.

> **SOLL-Wert (in Task 0 gemessen und korrigiert): `['DejaVuSans', 'DejaVuSans-Bold']`.** Eine frühere Messung mit einem Wegwerf-Skript hatte `Times-Bold` ergeben; das war ein Messfehler und ist in G24 der Spec richtiggestellt. Über die Produktion (dort `font_dir = storage_path('fonts')`) sagt keine der Messungen etwas.

**Bewusste Abweichung von der Spec — hier benannt, nicht stillschweigend:** Die Spec verlangt unter „Regressionstest Bestandsverträge" als erstes Kriterium „**extrahierter Text identisch**". Das ist in diesem Test nicht umsetzbar: `personalizeContent()` braucht Models und DB, und der Runner bootet kein Laravel. Der Test ersetzt das Kriterium durch **Seitenzahl + Fontliste + md5 des Stylesheets** und rendert dazu einen festen Beispielinhalt durch das **echte** Stylesheet der Vertrags-Blade.

Was dadurch nicht abgedeckt ist: eine Änderung an `personalizeContent()` selbst, die den Text verändert. Das fängt der `schulung.`-Zweig-Test aus Task 7 nur für den neuen Zweig ab, nicht für die Bestandszweige. Wer das schließen will, braucht einen Test mit echten Models auf Capsule — **nicht Teil dieses Plans**.

Was der Test dafür genau prüft: was G17 behauptet — dass die Zertifikat-Änderungen die Vertrags-Darstellung nicht anfassen.

- [ ] **Step 1: Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

/**
 * Belegt, dass die Zertifikat-Arbeit den Vertragsweg nicht beruehrt.
 *
 * Rendert einen festen Beispielinhalt durch das ECHTE Stylesheet aus
 * resources/views/pdf/contract.blade.php und friert Seitenzahl, Fontliste
 * und Textinhalt als SOLL ein.
 *
 * Times-Bold gehoert bewusst zum SOLL: fette Zellen fallen auf den Core-Font
 * zurueck, weil unter dem Namen "DejaVu Sans" keine Bold-Variante registriert
 * ist. Das ist ein Bestandsmakel, keine Regression dieses Pakets. Wer ihn
 * behebt, aendert diesen SOLL-Wert bewusst.
 */
class ContractPdfRegressionTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    private function contractStylesheet(): string
    {
        $blade = file_get_contents(self::MODULE_ROOT . '/resources/views/pdf/contract.blade.php');
        $css = preg_replace('/^.*?<style>/s', '<style>', $blade);

        return preg_replace('/<\/style>.*$/s', '</style>', $css);
    }

    private function render(): string
    {
        $body = '<div class="contract-content">'
            . '<p>§1 Vertragsgegenstand</p>'
            . '<p>Zwischen der RheinGedeck GmbH und Erika Mustermann, geb. 01.01.2000, '
            . 'wird folgender Arbeitsvertrag geschlossen. Stundenlohn 13,50 €.</p>'
            . '<table><tr><th>Feld</th><th>Wert</th></tr>'
            . '<tr><td>Beginn</td><td>01.09.2026</td></tr></table>'
            . '</div>';

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . $this->contractStylesheet() . '</head><body>' . $body . '</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('dpi', 96);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function testSeitenzahlBleibtEins(): void
    {
        preg_match_all('/\/Type\s*\/Page[^s]/', $this->render(), $m);

        $this->assertCount(1, $m[0]);
    }

    public function testFontlisteIstEingefroren(): void
    {
        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/', $this->render(), $m);

        $normalized = array_values(array_unique(array_map(
            fn (string $f) => preg_replace('/^[A-Z]+\+/', '', $f),
            $m[1]
        )));
        sort($normalized);

        $this->assertSame(['DejaVuSans', 'DejaVuSans-Bold'], $normalized);
    }

    /**
     * md5 ueber den gesamten <style>-Block, nicht zwei Stichproben.
     *
     * Dass ein legitimer Edit an contract.blade.php diesen Test rot macht,
     * ist der ZWECK: die Zertifikat-Arbeit darf das Vertrags-Stylesheet nicht
     * anfassen. Wer es aus einem anderen Grund aendert, aktualisiert den
     * Hash bewusst und begruendet es im Commit.
     */
    public function testVertragsstylesheetIstUnveraendert(): void
    {
        $css = $this->contractStylesheet();

        $this->assertSame(1347, strlen($css), 'Laenge des <style>-Blocks abgewichen.');
        $this->assertSame(
            '9e0d883726cd80892ad640c236103a67',
            md5($css),
            'contract.blade.php wurde geaendert. Zertifikat-Arbeit darf das nicht — '
            . 'war die Aenderung beabsichtigt, Hash bewusst aktualisieren.'
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ContractPdfRegressionTest`
Expected: PASS (3 tests). Schlägt `testFontlisteIstEingefroren` oder `testVertragsstylesheetIstUnveraendert` fehl, hat jemand die Vertrags-Blade angefasst — das ist der Zweck des Tests, kein Anlass, den SOLL-Wert nachzuziehen.

- [ ] **Step 3: Gesamtsuite**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/ContractPdfRegressionTest.php
git commit -m "test(recruiting): Regressionsschutz fuer Bestandsvertraege (Seite, Fonts, Stylesheet)"
```

---

---

### Task 1: Migration `type` auf `rec_contract_templates`

**Files:**
- Create: `database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php`

**Interfaces:**
- Consumes: nichts
- Produces: Spalte `rec_contract_templates.type` `string(20) NOT NULL DEFAULT 'contract'`

**Spec-Ausschnitt (wörtlich):**

> `type`-Spalte auf `rec_contract_templates` (`string(20) NOT NULL DEFAULT 'contract'`, Werte `contract`/`certificate`). **Nicht nullable**, damit es keinen dritten Zustand „unbekannt" gibt, den jede Query mitdenken müsste. Bestand wird durch den Default korrekt zu `contract`.

> **Der `type`-Default trifft den Bestand richtig.** Die 10 live vorhandenen Vorlagen (7 aktive: `AV-default`, `AV-010`, `AV-060`, `AV-110`, `AV-160`, `AV-210`, `AV-260`, plus `IFSG`; inaktiv: `AV`, `AV-TEST`) werden dadurch `contract`. Jeder neue `type`-Filter (`where('type','contract')`) lässt sie also unverändert durch.

**Hinweis zur Verifikation:** Migrationen sind lokal **nicht** ausführbar (`meingedeck` hat kein `.env`, das Modul keine DB). Die Spalte wird in den Integrationstests von Hand ins Capsule-Schema geschrieben. Die Migration selbst wird per Review und beim Deploy verifiziert.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unterscheidet Vertragsvorlagen von Zertifikat-Vorlagen. Beide leben in
 * derselben Tabelle, damit Editor, Platzhalter-Engine und Verwaltungsseite
 * mitbenutzt werden koennen.
 *
 * Bewusst NOT NULL mit Default: ein dritter Zustand "unbekannt" muesste in
 * jeder Query mitgedacht werden. Der Bestand wird durch den Default korrekt
 * zu 'contract'.
 *
 * Idempotent per hasColumn — Muster aus
 * 2026_05_19_000002_add_check_flag_and_additional_contract_...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_contract_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_contract_templates', 'type')) {
                $table->string('type', 20)->default('contract')->after('code');
                $table->index(['team_id', 'type']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_contract_templates', function (Blueprint $table) {
            if (Schema::hasColumn('rec_contract_templates', 'type')) {
                $table->dropIndex(['team_id', 'type']);
                $table->dropColumn('type');
            }
        });
    }
};
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php
git commit -m "feat(recruiting): type-Spalte auf rec_contract_templates"
```

---

### Task 2: Migration `rec_training_certificates`

> **GEÄNDERT durch den Zuschnitt v3.** Die Migration ist bereits editiert (nicht nachmigriert — sie war nicht deployed): `rec_contract_template_id` ist raus, `kind` (string 40, NOT NULL, ohne Default) ist rein, der Unique ist `(rec_applicant_id, kind)`. Der Codeblock unten zeigt den **alten** Stand; maßgeblich ist die Datei `database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php` und ihr Docblock, der die Constraint-Namen durchrechnet und begründet, warum der Snapshot bleibt. `tests/Support/TestSchema::trainingCertificates()` ist mitgezogen.


**Files:**
- Create: `database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php`

**Interfaces:**
- Consumes: Spalte `type` aus Task 1 (nur logisch, keine FK darauf)
- Produces: Tabelle `rec_training_certificates` mit Unique `(rec_applicant_id, rec_contract_template_id)`

**Spec-Ausschnitt (wörtlich):**

> **Tabelle `rec_training_certificates`.** Spalten: `id`, `uuid` (unique, UuidV7 via `booted()` wie überall im Modul), `team_id`, `rec_applicant_id`, `rec_contract_template_id`, `personalized_content` (longText, Snapshot), `issued_at`, `issued_by_user_id` nullable, `wa_sent_at` nullable, Timestamps.

> **Nicht** als `RecContract` ablegen: eine Contract-Zeile würde `hasAnyContractSent()` wahr machen, worauf die Versand-Guards des Nicht-EU-Umbaus aufsetzen (`ContractDispatchService`, `InterviewBookings/Index:522`), und in Portal-, Employees-Show- und ZAS-Vertragslisten auftauchen.

> **Unique-Constraint auf `(rec_applicant_id, rec_contract_template_id)`** statt allein auf `rec_applicant_id`. Sonst blockiert die erste Schulungsart jede zweite für denselben Menschen. Weiterhin auf DB-Ebene: ein Doppelklick am Desk ist kein Sonderfall.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausgestellte Schulungszertifikate. Bewusst KEINE rec_contracts-Zeile:
 * die wuerde hasAnyContractSent() wahr machen (Versand-Guards des
 * Nicht-EU-Umbaus) und in Portal-, Employees-Show- und ZAS-Vertragslisten
 * auftauchen.
 *
 * personalized_content ist ein Snapshot — die Huelle (Layout, Assets) steckt
 * NICHT darin, sondern wird beim Rendern aufgeloest. Muster wie beim
 * Firmenstempel in Vertraegen.
 *
 * Unique auf (rec_applicant_id, rec_contract_template_id): ein Zertifikat pro
 * Person pro Vorlage. Eine zweite Schulungsart bleibt damit moeglich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_training_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            $table->foreignId('rec_contract_template_id')->constrained('rec_contract_templates')->cascadeOnDelete();
            $table->longText('personalized_content')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('wa_sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['rec_applicant_id', 'rec_contract_template_id'],
                'rec_training_cert_applicant_tpl_unique'
            );
            $table->index(['team_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_training_certificates');
    }
};
```

**Hinweis:** Der Unique-Index bekommt einen expliziten kurzen Namen. Der auto-generierte wäre `rec_training_certificates_rec_applicant_id_rec_contract_template_id_unique` = 74 Zeichen und überschreitet das MySQL-Limit von 64 — genau der Fehler 1059, an dem die Migration `2026_05_19_000002` schon einmal halb durchgelaufen ist.

- [ ] **Step 2: Syntax prüfen**

Run: `php -l database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Namenslänge verifizieren**

Run: `php -r 'echo strlen("rec_training_cert_applicant_tpl_unique"), PHP_EOL;'`
Expected: `38` (< 64)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php
git commit -m "feat(recruiting): Tabelle rec_training_certificates"
```

---

### Task 2a: `TestSchema` — eine Quelle für das handgebaute Testschema

**Files:**
- Create: `tests/Support/TestSchema.php`

**Interfaces:**
- Consumes: nichts
- Produces:
  - `TestSchema::contractTemplates(\Illuminate\Database\Schema\Builder $schema): void`
  - `TestSchema::trainingCertificates(\Illuminate\Database\Schema\Builder $schema): void`

  Konsumiert von Task 3, Task 8 und Task 17 — allen Integrationstests, die `rec_contract_templates` bzw. `rec_training_certificates` auf SQLite in-memory brauchen.

**Warum dieser Task existiert — belegter Anlass, nicht Vorsorge:** Die erste Fassung dieses Plans baute `rec_contract_templates` an **drei** Stellen von Hand auf (Tasks 3, 8, 17). Beim Audit vor Task 2 stellte sich heraus, dass die drei Kopien **schon auseinandergelaufen waren**, bevor eine Zeile Code existierte: die Fassung in Task 3 enthielt die Spalte `description`, die beiden anderen nicht. Ein handgebautes Testschema, das von der Migration oder von den Geschwistertests abweicht, lässt Tests grün werden und bestätigt dabei einen Zustand, den die Produktion nicht hat. Genau das ist die gefährlichste Sorte Test: einer, der lügt.

Die Konvention des Moduls verlangt reines PHPUnit ohne Laravel-Bootstrap; Integrationstests bauen Container und `Capsule` von Hand (Muster: `tests/Integration/DuplicateMatchQueryTest.php:28-45`). Das Schema von Hand zu bauen ist dort unvermeidlich — es dreimal zu tun nicht.

**Wichtig:** Diese Klasse ist die Testabbildung der Migrationen aus Task 1 und Task 2. Ändert sich eine Migration, muss sie mitgezogen werden. Der Docblock sagt das ausdrücklich, damit die nächste Änderung nicht wieder Drift erzeugt.

- [ ] **Step 1: Helper schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Support;

use Illuminate\Database\Schema\Builder;

/**
 * Die EINZIGE Quelle des handgebauten Testschemas fuer Zertifikat-Tests.
 *
 * Warum es das gibt: die Modul-Konvention ist reines PHPUnit ohne
 * Laravel-Bootstrap (tests/bootstrap.php ist ein reiner Autoloader,
 * orchestra/testbench ist nicht installiert). Integrationstests bauen
 * Container und Capsule von Hand und muessen das Schema selbst anlegen.
 *
 * Das dreimal zu tun hat schon einmal Drift erzeugt: in der ersten Fassung
 * des Umsetzungsplans hatte eine der drei Kopien die Spalte 'description',
 * die anderen zwei nicht. Ein Testschema, das von der Migration abweicht,
 * laesst Tests gruen werden und bestaetigt einen Zustand, den die Produktion
 * nicht hat.
 *
 * ACHTUNG: Diese Klasse ist die Testabbildung der Migrationen
 *   2026_08_12_000001_add_type_to_rec_contract_templates.php
 *   2026_08_12_000002_create_rec_training_certificates_table.php
 * und der Basis-Migration 2026_04_15_100000_create_rec_contract_tables.php.
 * Aendert sich dort etwas, gehoert es hier mit hinein. Sonst faellt es
 * niemandem auf.
 */
final class TestSchema
{
    /** Vollstaendig wie die Basis-Migration plus die type-Spalte aus Task 1. */
    public static function contractTemplates(Builder $schema): void
    {
        if ($schema->hasTable('rec_contract_templates')) {
            return;
        }

        $schema->create('rec_contract_templates', function ($t) {
            $t->id();
            $t->string('uuid', 36)->unique();
            $t->string('name');
            $t->string('code', 20)->nullable();
            // NOT NULL mit Default — wie die Migration. Nicht nullable machen:
            // ein dritter Zustand "unbekannt" wuerde die type-Filter aushebeln.
            $t->string('type', 20)->default('contract');
            $t->text('description')->nullable();
            $t->longText('content')->nullable();
            $t->text('field_mappings')->nullable();
            $t->boolean('requires_signature')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    /** Wie die Migration aus Task 2, inklusive Unique-Constraint. */
    public static function trainingCertificates(Builder $schema): void
    {
        if ($schema->hasTable('rec_training_certificates')) {
            return;
        }

        $schema->create('rec_training_certificates', function ($t) {
            $t->id();
            $t->string('uuid', 36)->unique();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('rec_applicant_id');
            $t->unsignedBigInteger('rec_contract_template_id');
            $t->longText('personalized_content')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->unsignedBigInteger('issued_by_user_id')->nullable();
            $t->timestamp('wa_sent_at')->nullable();
            $t->timestamps();
            // Der Constraint ist Teil der Invariante "ein Zertifikat pro
            // Bewerber pro Vorlage" und muss im Test genauso greifen.
            $t->unique(
                ['rec_applicant_id', 'rec_contract_template_id'],
                'rec_training_cert_applicant_tpl_unique'
            );
        });
    }
}
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l tests/Support/TestSchema.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Autoloader-Auflösung prüfen**

Der Autoloader in `tests/bootstrap.php` mappt `Platform\Recruiting\Tests\` auf `tests/`. Prüf, dass die Klasse damit gefunden wird:

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml 2>&1 | tail -3`
Expected: `OK (518 tests, 1493 assertions)` — unverändert. Die Klasse wird noch von keinem Test benutzt; dieser Schritt belegt nur, dass sie die Suite nicht bricht.

- [ ] **Step 4: Commit**

```bash
git add tests/Support/TestSchema.php
git commit -m "test(recruiting): eine Quelle fuer das handgebaute Testschema

Drei Integrationstests brauchen rec_contract_templates auf SQLite in-memory.
Die drei handgebauten Kopien im Umsetzungsplan waren schon auseinander-
gelaufen (eine hatte 'description', zwei nicht), bevor Code existierte. Ein
Testschema, das von der Migration abweicht, laesst Tests gruen werden und
bestaetigt einen Zustand, den die Produktion nicht hat.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `type` am Model + saving-Hook mit zwei Invarianten

**Files:**
- Modify: `src/Models/RecContractTemplate.php`
- Test: `tests/Integration/ContractTemplateTypeInvariantsTest.php`

**Interfaces:**
- Consumes: Spalte `type` (Task 1)
- Produces:
  - `RecContractTemplate::TYPE_CONTRACT = 'contract'`
  - `RecContractTemplate::TYPE_CERTIFICATE = 'certificate'`
  - `RecContractTemplate::CERTIFICATE_CODE_PREFIX = 'ZERT-'`
  - `scopeContracts($query)` und `scopeCertificates($query)`
  - saving-Hook, der bei `type === 'certificate'` `requires_signature = false` setzt und einen `code` ohne Präfix mit `\InvalidArgumentException` abweist

**Spec-Ausschnitt (wörtlich):**

> Im Model (`booted()`/`saving`-Hook) werden bei `type === 'certificate'` **zwei** Dinge erzwungen:
> 1. **`requires_signature = false`.** Ein Zertifikat wird von niemandem unterschrieben: die Unterschrift der RheinGedeck GmbH ist Teil des Dokuments, und der Empfänger bestätigt nichts. Ein `true` würde einen Signaturweg suggerieren, den es nicht gibt.
> 2. **`code` beginnt mit `ZERT-`.** Verstoß → Exception, nicht stille Korrektur.

> **Warum der Präfix-Zwang und nicht die Konvention aus v1.** v1 sagte „Zertifikat-`code` darf nie `AV-*` oder `AT-140` sein". Das ist eine Konvention ohne Guard: `ContractPreSigningType::forCode()` entscheidet allein am `code`, ob ein Vorschalt-Schritt läuft — ein Zertifikat mit `code = 'AV-ZERT'` bekäme die §15/§16-Abfrage. Und der nächste Seed-Command, der einen `code` frei setzt, kennt die Konvention nicht. Ein erzwungener Präfix macht die Kollision **unmöglich statt unwahrscheinlich**.

> Der Hook deckt die Wege ab, die das Modal umgehen: MCP (`CreateContractTemplateTool:87`, `UpdateContractTemplateTool:86`), einen nachträglichen Typwechsel an einer Bestandsvorlage, und Seeder/Commands.

> **§B8 ist einzelne Ausfallstelle für 12 Einträge der Guard-Landkarte; der Test dazu ist Pflicht.**

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Tests\Support\TestSchema;

/**
 * §B8: ein saving-Hook, zwei Invarianten. Zwoelf "keiner"-Zeilen der
 * Guard-Landkarte haengen an der Praefix-Zusicherung — deshalb Pflichttest.
 */
class ContractTemplateTypeInvariantsTest extends TestCase
{
    private const TEAM = 3;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        TestSchema::contractTemplates($capsule->schema());
    }

    private function make(array $attrs): RecContractTemplate
    {
        return RecContractTemplate::create(array_merge([
            'name' => 'Test',
            'team_id' => self::TEAM,
        ], $attrs));
    }

    public function testBestandsvorlageBleibtVertragMitSignatur(): void
    {
        $t = $this->make(['code' => 'AV-010', 'requires_signature' => true]);

        $this->assertSame('contract', $t->type);
        $this->assertTrue($t->requires_signature);
    }

    public function testZertifikatErzwingtSignaturFalse(): void
    {
        $t = $this->make([
            'code' => 'ZERT-BASIS',
            'type' => 'certificate',
            'requires_signature' => true,
        ]);

        $this->assertFalse($t->requires_signature);
    }

    public function testZertifikatOhnePraefixWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(['code' => 'AV-ZERT', 'type' => 'certificate']);
    }

    public function testZertifikatOhneCodeWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(['code' => null, 'type' => 'certificate']);
    }

    public function testNachtraeglicherTypwechselGreiftEbenfalls(): void
    {
        $t = $this->make(['code' => 'ZERT-UMBAU', 'requires_signature' => true]);
        $this->assertTrue($t->requires_signature);

        $t->type = 'certificate';
        $t->save();

        $this->assertFalse($t->fresh()->requires_signature);
    }

    public function testScopesTrennenDieTypen(): void
    {
        $this->make(['code' => 'AV-060']);
        $this->make(['code' => 'ZERT-SERVICE', 'type' => 'certificate']);

        $this->assertSame(1, RecContractTemplate::query()->contracts()->count());
        $this->assertSame(1, RecContractTemplate::query()->certificates()->count());
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ContractTemplateTypeInvariantsTest`
Expected: FAIL — `Call to undefined method …::contracts()` bzw. `requires_signature` bleibt `true`

- [ ] **Step 3: Model erweitern**

In `src/Models/RecContractTemplate.php`:

`'type'` in `$fillable` direkt nach `'code'` einfügen. In `booted()` nach dem bestehenden `creating`-Hook ergänzen:

```php
    public const TYPE_CONTRACT = 'contract';
    public const TYPE_CERTIFICATE = 'certificate';

    /**
     * Zertifikat-Codes muessen mit diesem Praefix beginnen. Grund:
     * ContractPreSigningType::forCode() entscheidet ALLEIN am code, ob ein
     * Vorschalt-Schritt vor der Unterschrift laeuft (AT-140 → Resttage,
     * Praefix AV- → §15/§16). Ein Zertifikat mit code 'AV-ZERT' bekaeme die
     * §15/§16-Abfrage. Der Praefix macht die Kollision unmoeglich statt
     * unwahrscheinlich — und er schuetzt zwoelf code-Muster-Filter in der
     * Guard-Landkarte, die sonst nur auf eine Konvention vertrauen.
     */
    public const CERTIFICATE_CODE_PREFIX = 'ZERT-';
```

```php
        static::saving(function (self $model) {
            if ($model->type !== self::TYPE_CERTIFICATE) {
                return;
            }

            // Invariante 1: ein Zertifikat unterschreibt niemand.
            $model->requires_signature = false;

            // Invariante 2: Praefix-Zwang. Exception statt stiller Korrektur —
            // ein automatisch umgeschriebener code wuerde Verweise brechen,
            // die der Aufrufer schon gesetzt hat.
            $code = (string) $model->code;
            if (!str_starts_with($code, self::CERTIFICATE_CODE_PREFIX)) {
                throw new \InvalidArgumentException(
                    'Zertifikat-Vorlagen brauchen einen code mit Praefix "'
                    . self::CERTIFICATE_CODE_PREFIX . '" (bekommen: "' . $code . '").'
                );
            }
        });
```

Und die zwei Scopes neben `scopeActive`:

```php
    public function scopeContracts($query)
    {
        return $query->where('type', self::TYPE_CONTRACT);
    }

    public function scopeCertificates($query)
    {
        return $query->where('type', self::TYPE_CERTIFICATE);
    }
```

- [ ] **Step 4: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ContractTemplateTypeInvariantsTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecContractTemplate.php tests/Integration/ContractTemplateTypeInvariantsTest.php
git commit -m "feat(recruiting): type-Invarianten fuer Zertifikat-Vorlagen (Signatur + ZERT-Praefix)"
```

---

### Task 4: `FontGlyphCoverage` — fehlende Zeichen finden

**Files:**
- Create: `src/Support/FontGlyphCoverage.php`
- Test: `tests/Unit/FontGlyphCoverageTest.php`

**Interfaces:**
- Consumes: `FontLib\Font` aus `dompdf/php-font-lib` (transitive Abhängigkeit von DomPDF, liegt unter `meingedeck/vendor/dompdf/php-font-lib/src/FontLib/Font.php`)
- Produces: `FontGlyphCoverage::missing(string $content, string $fontPath): array` — Liste der Zeichen (als UTF-8-Strings, dedupliziert, in Reihenfolge des ersten Auftretens), die die Schrift nicht hat. Leerer Array = alles abgedeckt. Nicht lesbare Fontdatei → leerer Array (Prüfung soll nichts blockieren).

> ⚠️ **ÜBERHOLT DURCH TASK 4a.** Genau der letzte Satz — „nicht lesbare Fontdatei → leerer Array" — war der Fehler: damit bedeutete `[]` sowohl „nichts fehlt" als auch „nicht prüfbar", und eine kaputte Schrift bekam ein besseres Zeugnis als eine intakte. `missing()` existiert nicht mehr; die API ist `inspect(): FontGlyphReport` mit drei getrennten Zuständen. Dieser Abschnitt bleibt als Protokoll dessen stehen, was gebaut wurde; **maßgeblich ist Task 4a.**

**Spec-Ausschnitt (wörtlich):**

> **Custom Font = kein Glyph-Fallback.** Jedes Zeichen, das die eingebundene Schrift nicht hat, wird `?`. Konkret gemessen: ★ (U+2605) in Oswald ergab `STEHEMPFANG ? FLYING BUFFET`.

> **„Zeichen prüfen"** ruft eine pure Funktion auf: `Support/FontGlyphCoverage::missing(string $content, string $fontPath): array`. Sie liest die `cmap` der Schriftdatei und gibt die Zeichen des Inhalts zurück, die darin fehlen — also genau die, die im PDF zu `?` würden. **Am Eingang, nicht am PDF**, weil der PDF-Text komprimiert und UTF-16BE-kodiert ist und eine Prüfung dort teuer und indirekt wäre.

**Gemessene Ausgangslage für die Testfälle** (`FontLib\Font::load()` + `getUnicodeCharMap()` gegen `Oswald-SemiBold.ttf`): `A` U+0041 vorhanden, `Ü` U+00DC vorhanden, `ä` U+00E4 vorhanden, `–` U+2013 vorhanden, `€` U+20AC vorhanden, **`★` U+2605 FEHLT**.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FontGlyphCoverage;

class FontGlyphCoverageTest extends TestCase
{
    private function font(): string
    {
        return __DIR__ . '/../../resources/fonts/Oswald-SemiBold.ttf';
    }

    public function testLatinUndUmlauteSindAbgedeckt(): void
    {
        $this->assertSame(
            [],
            FontGlyphCoverage::missing('GÄSTEBETREUUNG UND KOMMUNIKATION – 3-Gang-Menü, 12 €', $this->font())
        );
    }

    public function testSternFehlt(): void
    {
        $this->assertSame(
            ['★'],
            FontGlyphCoverage::missing('STEHEMPFANG ★ FLYING BUFFET', $this->font())
        );
    }

    public function testJedesFehlendeZeichenNurEinmalUndInReihenfolge(): void
    {
        $this->assertSame(
            ['★', '☂'],
            FontGlyphCoverage::missing('★ A ☂ B ★', $this->font())
        );
    }

    public function testHtmlTagsWerdenNichtGepruefft(): void
    {
        // Der Vorlageninhalt ist HTML. Spitze Klammern und Attributnamen
        // stehen nie im gerenderten Text und duerfen nicht gemeldet werden.
        $this->assertSame(
            ['★'],
            FontGlyphCoverage::missing('<div class="skill">A ★ B</div>', $this->font())
        );
    }

    public function testFehlendeFontdateiBlockiertNicht(): void
    {
        $this->assertSame([], FontGlyphCoverage::missing('★', '/gibt/es/nicht.ttf'));
    }

    public function testLeererInhalt(): void
    {
        $this->assertSame([], FontGlyphCoverage::missing('', $this->font()));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FontGlyphCoverageTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\FontGlyphCoverage" not found`

- [ ] **Step 3: Font-Assets ablegen**

```bash
mkdir -p resources/fonts resources/images/certificates
curl -sS -o resources/fonts/Oswald-SemiBold.ttf \
  "https://raw.githubusercontent.com/googlefonts/OswaldFont/main/fonts/ttf/Oswald-SemiBold.ttf"
curl -sS -o resources/fonts/OFL.txt \
  "https://raw.githubusercontent.com/google/fonts/main/ofl/oswald/OFL.txt"
cp /Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/logo.png \
   resources/images/certificates/logo.png
cp /Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/headline.png \
   resources/images/certificates/headline-zertifikat.png
cp /Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/signature-block.png \
   resources/images/certificates/signature-block.png
ls -la resources/fonts resources/images/certificates
```

Expected: `Oswald-SemiBold.ttf` 109120 Bytes, `OFL.txt` vorhanden, drei PNGs vorhanden.

- [ ] **Step 4: Implementierung schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

use FontLib\Font;

/**
 * Welche Zeichen eines Textes fehlen in einer TTF-Datei?
 *
 * Hintergrund: DomPDF macht bei einer per @font-face eingebundenen Schrift
 * KEINEN Glyph-Fallback. Jedes Zeichen, das die Schrift nicht kennt, landet
 * als "?" im PDF — ohne Warnung. Gemessen an Oswald-SemiBold: ★ (U+2605)
 * fehlt, waehrend –, €, Ü, ä vorhanden sind.
 *
 * Geprueft wird am EINGANG, nicht am fertigen PDF: dort ist der Text
 * FlateDecode-komprimiert und UTF-16BE-kodiert (CID-Font, Identity-H), eine
 * Pruefung waere teuer und indirekt.
 *
 * Dependency: FontLib liegt als dompdf/php-font-lib immer dort, wo DomPDF
 * liegt — keine neue Abhaengigkeit.
 */
final class FontGlyphCoverage
{
    /**
     * @return list<string> fehlende Zeichen als UTF-8-Strings, dedupliziert,
     *                      in Reihenfolge des ersten Auftretens
     */
    public static function missing(string $content, string $fontPath): array
    {
        $text = self::plainText($content);
        if ($text === '') {
            return [];
        }

        $map = self::charMap($fontPath);
        if ($map === null) {
            // Nicht lesbare Fontdatei blockiert die Pruefung nicht — sie ist
            // eine Hilfe, kein Gate. Das fehlende Asset faellt beim Rendern auf.
            return [];
        }

        $missing = [];
        foreach (self::codepoints($text) as $codepoint => $char) {
            if (!isset($map[$codepoint]) && !isset($missing[$codepoint])) {
                $missing[$codepoint] = $char;
            }
        }

        return array_values($missing);
    }

    /** HTML-Markup entfernen — im PDF steht nur der Textinhalt. */
    private static function plainText(string $content): string
    {
        $withoutTags = strip_tags($content);
        $decoded = html_entity_decode($withoutTags, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($decoded);
    }

    /** @return array<int,int>|null Unicode-Codepoint => Glyph-Index */
    private static function charMap(string $fontPath): ?array
    {
        if (!is_file($fontPath) || !is_readable($fontPath)) {
            return null;
        }

        try {
            $font = Font::load($fontPath);
            if ($font === null) {
                return null;
            }
            $font->parse();
            $map = $font->getUnicodeCharMap();
            $font->close();
        } catch (\Throwable) {
            return null;
        }

        return is_array($map) ? $map : null;
    }

    /**
     * @return array<int,string> Codepoint => Zeichen, in Textreihenfolge.
     *         Whitespace wird uebersprungen (steht in jeder Schrift und
     *         wuerde nur Rauschen erzeugen).
     */
    private static function codepoints(string $text): array
    {
        $out = [];
        $length = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            if (trim($char) === '') {
                continue;
            }
            $codepoint = mb_ord($char, 'UTF-8');
            if ($codepoint === false) {
                continue;
            }
            $out[] = [$codepoint, $char];
        }

        // Reihenfolge des ersten Auftretens erhalten, Duplikate spaeter
        // in missing() gefiltert.
        $ordered = [];
        foreach ($out as [$codepoint, $char]) {
            $ordered[$codepoint] ??= $char;
        }

        return $ordered;
    }
}
```

- [ ] **Step 5: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FontGlyphCoverageTest`
Expected: PASS (6 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Support/FontGlyphCoverage.php tests/Unit/FontGlyphCoverageTest.php \
        resources/fonts resources/images/certificates
git commit -m "feat(recruiting): FontGlyphCoverage + Zertifikat-Assets"
```

---

### Task 4a: Dritter Zustand für die Glyph-Prüfung — „nicht prüfbar" ist nicht „nichts fehlt"

**Nachträglich eingefügt, nach Task 6, auf Grund einer Messung.** Task 4 ist bereits umgesetzt; dieser Task ändert seine Klasse. Grund: `missing()` gibt `[]` zurück für „nichts fehlt" **und** für „Font nicht parsbar". Damit bekommt eine kaputte Schrift ein *besseres* Zeugnis als eine intakte.

**Gemessen** gegen die echte `Oswald-SemiBold.ttf` (109 120 Byte), Prüftext `STEHEMPFANG ★` (Oswald hat U+2605 nicht):

| Zustand der Datei | `TrainingCertificateAssets::resolve()` | `FontGlyphCoverage::missing()` | `/BaseFont` im PDF |
|---|---|---|---|
| intakt (109 120 B) | schweigt | meldet `★` | `Oswald-SemiBold` |
| abgeschnitten 40 % (43 648 B) | schweigt | meldet `★` | **`Helvetica`** |
| abgeschnitten 5 % (5 456 B) | schweigt | meldet `★` | **`Helvetica`** |
| 3 Byte | schweigt | **schweigt (= „nichts fehlt")** | **`Helvetica`** |
| 0 Byte | meldet | **schweigt (= „nichts fehlt")** | **`Helvetica`** |

**Warum das jetzt behoben wird und nicht als Folgeticket:** Der Editor-Knopf „Zeichen prüfen" (§E8) ist die **einzige Stelle, an der ein Mensch den stillen Helvetica-Fallback je bemerken würde** — die Spec führt ihn als benanntes Betriebsrisiko ohne Fehlerpfad (G13.1). In der jetzigen Form bestätigt der Knopf bei kaputtem Font das Gegenteil: grüner Haken. Solange die zwei Konsumenten (Task 9, Task 13) noch nicht existieren, ist die Änderung folgenlos; nach Task 13 wäre sie eine Änderung an ausgeliefertem UI-Verhalten.

**Files:**
- Create: `src/Support/FontGlyphReport.php`
- Edit: `src/Support/FontGlyphCoverage.php`
- Edit: `tests/Unit/FontGlyphCoverageTest.php`

**Interfaces:**
- Produces: `FontGlyphCoverage::inspect(string $content, string $fontPath): FontGlyphReport`
- Entfällt: `FontGlyphCoverage::missing()` — siehe Auflage 2 unten.

**Drei verbindliche Auflagen (Vorgaben des Auftraggebers, wörtlich):**

> 1) **KEIN Gate.** Die Spec sagt „Hilfe, kein Gate", das bleibt. Der dritte Zustand ist eine Warnung („Schrift nicht prüfbar"), nicht ein Blocker — weder im Editor noch im Test-PDF-Weg.
>
> 2) **Drei Zustände klar getrennt, nicht ein Sonderwert in der Liste:** nichts fehlt / diese Zeichen fehlen / nicht prüfbar. Ein leeres Array darf nach dem Fix nur noch „nichts fehlt" bedeuten.
>
> 3) **Die fünf Beschädigungsstufen der Messtabelle als Testfälle mit**, nicht nur intakt und 0 Byte. Die 3-Byte-Stufe ist die interessante: dort schweigen beide Wege heute.

**Auflage 2 erzwingt, dass `missing()` verschwindet, nicht dass eine Methode dazukommt.** Bliebe `missing()` bestehen, bedeutete sein leeres Array weiterhin beides — genau das, was Auflage 2 verbietet. Zwei Wege nebeneinander hätten außerdem den bekannten Effekt, dass der schwächere benutzt wird. Es gibt noch keine Konsumenten außer dem eigenen Test, der Bruch ist also kostenlos. *(Der Auftraggeber sagte „additiv"; gemeint war nachweislich „ohne ausgeliefertes Verhalten zu ändern" — das ist erfüllt. Diese Lesart ist hier ausdrücklich festgehalten, weil sie eine Auslegung ist.)*

**Form des Rückgabewerts — und warum nicht `?array`:**

```php
final class FontGlyphReport
{
    /** @param list<string> $missing */
    private function __construct(
        public readonly bool $checkable,
        public readonly array $missing,
    ) {}

    public static function notCheckable(): self;

    /** @param list<string> $missing */
    public static function checked(array $missing): self;

    /** true, wenn es etwas zu melden gibt: fehlende Zeichen ODER nicht prüfbar. */
    public function hasWarning(): bool;
}
```

Ein nullbares Array (`?array`, `null` = nicht prüfbar) wäre kürzer und wäre **falsch**: `if (empty($result))` und `if (!$result)` behandeln `null` und `[]` gleich, und ein Aufrufer, der `if ($missing) { warnen }` schreibt, führt „nicht prüfbar" still als „alles in Ordnung". Das ist wörtlich die Fehlerklasse, die dieses Paket fünfmal getroffen hat. `hasWarning()` ist der Mechanismus dagegen: **ein** Aufruf, der in **beiden** Problemzuständen `true` ist. Wer die Unterscheidung für den Meldungstext braucht, liest `checkable` und `missing`.

**Was „nicht prüfbar" auslöst.** Die bestehende private `charMap()` gibt schon heute `null` in genau diesen Fällen zurück — Datei fehlt oder unlesbar, `Font::load()` liefert `null`, eine Exception, oder `getUnicodeCharMap()` liefert kein Array. Die Änderung besteht **nicht** darin, neue Fälle zu erkennen, sondern darin, dieses `null` nicht mehr zu `[]` einzuschmelzen.

**Eine offene Frage, die durch Messung zu entscheiden ist, nicht durch Meinung:** Was tut `getUnicodeCharMap()` bei einer beschädigten Datei, die noch parst — kommt je ein **leeres, aber gültiges Array** heraus? Falls ja, gilt es als **nicht prüfbar**, nicht als „alle Zeichen fehlen": eine Schrift mit null Glyphen ist keine Schrift, und eine Liste mit 40 gemeldeten Zeichen sähe wie ein Inhaltsproblem aus statt wie ein Schriftproblem. Miss das an allen fünf Stufen und halte das Ergebnis fest; tritt der Fall nicht auf, sag das ausdrücklich statt Code für einen unbelegten Fall zu schreiben.

- [ ] **Step 1: Failing test schreiben**

Die fünf Stufen brauchen beschädigte Kopien der echten Schrift. Sie werden in ein **temporäres Verzeichnis** geschrieben — `resources/fonts` bleibt unangetastet. Achtung: `sys_get_temp_dir()` ist auf diesem Rechner `/var/folders/6r/h4ndlx0s6ns5gj49vckp25w0v20knp/T`, nicht `/tmp`.

Erwartete Zustände pro Stufe (aus der Messtabelle, `checkable` / `missing`):

| Stufe | `checkable` | `missing` | `hasWarning()` |
|---|---|---|---|
| intakt | `true` | `['★']` | `true` |
| 40 % | `true` | `['★']` | `true` |
| 5 % | `true` | `['★']` | `true` |
| 3 Byte | `false` | `[]` | `true` |
| 0 Byte | `false` | `[]` | `true` |
| Pfad existiert nicht | `false` | `[]` | `true` |
| intakt, Text ohne `★` | `true` | `[]` | **`false`** |

Die letzte Zeile ist die wichtigste: sie ist der **einzige** Fall, in dem `hasWarning()` falsch ist. Ohne sie wäre ein `hasWarning()`, das immer `true` liefert, grün.

Die bestehenden Testfälle aus Task 4 (Zeichenabdeckung, Entity-Dekodierung, Markup-Entfernung, leerer Inhalt) bleiben inhaltlich erhalten und werden auf `inspect()` umgestellt. **Die Entity-Dekodierung nicht wegoptimieren** — sie ist in Task 16 als Absicht festgehalten, weil die einzige ausgelieferte Vorlage `&#9733;` benutzt.

- [ ] **Step 2: Test laufen lassen, FAIL sehen, Ausgabe festhalten**

- [ ] **Step 3: `FontGlyphReport` anlegen**

- [ ] **Step 4: `FontGlyphCoverage::inspect()` implementieren, `missing()` entfernen**

- [ ] **Step 5: Test laufen lassen, grün bestätigen, Gesamtsuite prüfen**

- [ ] **Step 6: Mutationstest pro Zustand** — mindestens: `notCheckable()` durch `checked([])` ersetzen (muss die 3-Byte- und 0-Byte-Stufe rot machen), und `hasWarning()` auf `return $this->missing !== []` verkürzen (muss dieselben zwei Stufen rot machen). Die zweite Mutation ist die eigentliche: sie ist genau der Fehler, den dieser Task behebt. Rohe Ausgabe festhalten, mit `git checkout --` zurücksetzen, Sauberkeit nachweisen. Mutationen **nach** dem Commit fahren.

- [ ] **Step 7: Commit**

**Was dieser Task NICHT tut:** Er ändert nichts an Task 9, Assertion 2. Die Folgerung dort bleibt wörtlich stehen — `/BaseFont` ist der einzige Wächter, der jede Beschädigungsstufe rot macht, und wird **auch nach diesem Fix nicht** mit Verweis auf die Glyph-Prüfung aufgeweicht. Dieser Task macht die Glyph-Prüfung ehrlich, nicht zu einem Ersatz für sie.

---

### Task 5: `TrainingCertificatePdfOptions` — geteilte Options-Quelle

**Files:**
- Create: `src/Support/TrainingCertificatePdfOptions.php`
- Test: `tests/Unit/TrainingCertificatePdfOptionsTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: `TrainingCertificatePdfOptions::for(string $fontPath, string $chroot): array` — Options-Array mit Keys `chroot`, `isRemoteEnabled`, `dpi`, `defaultFont`, `isHtml5ParserEnabled`. Wird von Task 9 (Render-Test) **und** Task 10 (Controller) konsumiert.

**Spec-Ausschnitt (wörtlich):**

> **`TrainingCertificatePdfOptions` ist die einzige Stelle, an der die Engine-Optionen stehen** — `chroot`, `isRemoteEnabled`, `dpi`, `defaultFont`, `isHtml5ParserEnabled`. **Controller und Render-Test konsumieren dieselbe Quelle.** Begründung: der Vertrags-Controller setzt seine Optionen selbst; im Prototyp fiel ein `isRemoteEnabled`-Unterschied nur auf, weil ich ihn von Hand gesucht habe. Setzen Controller und Test ihre Optionen je selbst, testet der Render-Test eine andere Engine als die ausgelieferte und ist grün ohne Aussage. Das ist die eigentliche Absicherung, nicht der Test selbst.

> **`@font-face` braucht `chroot`.** Ein absoluter Pfad in `src: url(...)` allein genügt nicht — DomPDF fällt dann **stumm auf Helvetica** zurück: keine Exception, kein Log.

> `enable_remote => false` ist der Live-Wert. Gegengeprüft: mit `enable_remote = false` **und** gesetztem `chroot` bettet die Schrift korrekt ein *und* das Data-URI-Bild wird gerendert.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificatePdfOptions;

class TrainingCertificatePdfOptionsTest extends TestCase
{
    public function testEnthaeltAlleFuenfSchluessel(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertSame(
            ['chroot', 'defaultFont', 'dpi', 'isHtml5ParserEnabled', 'isRemoteEnabled'],
            self::sortedKeys($opts)
        );
    }

    public function testRemoteBleibtAusWieLive(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertFalse($opts['isRemoteEnabled']);
    }

    public function testFontPfadLiegtUnterChroot(): void
    {
        // Genau diese Bedingung entscheidet, ob DomPDF die Schrift einbettet
        // oder stumm auf Helvetica zurueckfaellt.
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertStringStartsWith($opts['chroot'], '/app/resources/fonts/X.ttf');
    }

    public function testFontPfadAusserhalbChrootWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrainingCertificatePdfOptions::for('/anderswo/X.ttf', '/app');
    }

    public function testDpiUndParserWieImVertragsweg(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertSame(96, $opts['dpi']);
        $this->assertTrue($opts['isHtml5ParserEnabled']);
        $this->assertSame('DejaVu Sans', $opts['defaultFont']);
    }

    private static function sortedKeys(array $a): array
    {
        $k = array_keys($a);
        sort($k);

        return $k;
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificatePdfOptionsTest`
Expected: FAIL — `Class "…TrainingCertificatePdfOptions" not found`

- [ ] **Step 3: Implementierung schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Die EINZIGE Quelle der DomPDF-Optionen fuer das Schulungszertifikat.
 *
 * Warum eine eigene Klasse: der Vertrags-Controller setzt seine Optionen
 * selbst (defaultFont, isHtml5ParserEnabled). Wuerden Zertifikat-Controller
 * und Render-Test das ebenfalls je selbst tun, pruefte der Test eine anders
 * konfigurierte Engine als die ausgelieferte — und waere gruen ohne Aussage.
 * Genau so ist im Prototyp ein isRemoteEnabled-Unterschied entstanden, der
 * nur durch manuelles Nachsehen auffiel.
 *
 * chroot ist nicht Kosmetik: ohne passenden chroot ignoriert DomPDF das
 * @font-face STUMM und rendert in Helvetica — keine Exception, kein Log.
 */
final class TrainingCertificatePdfOptions
{
    /**
     * @param string $fontPath absoluter Pfad zur TTF-Datei
     * @param string $chroot   Wurzel, unterhalb der DomPDF lesen darf
     *                         (in der Host-App: realpath(base_path()))
     * @return array<string,mixed>
     */
    public static function for(string $fontPath, string $chroot): array
    {
        if (!str_starts_with($fontPath, rtrim($chroot, '/'))) {
            throw new \InvalidArgumentException(
                'Der Font-Pfad liegt ausserhalb des chroot — DomPDF wuerde die '
                . 'Schrift stumm ignorieren und in Helvetica rendern. '
                . "font={$fontPath} chroot={$chroot}"
            );
        }

        return [
            'chroot' => rtrim($chroot, '/'),
            'isRemoteEnabled' => false,
            'dpi' => 96,
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
        ];
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificatePdfOptionsTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/TrainingCertificatePdfOptions.php tests/Unit/TrainingCertificatePdfOptionsTest.php
git commit -m "feat(recruiting): geteilte DomPDF-Options-Quelle fuer Zertifikate"
```

---

### Task 5a: `TrainingCertificateAssets` — ein Resolver, drei Konsumenten

**Files:**
- Create: `src/Support/TrainingCertificateAssets.php`
- Test: `tests/Unit/TrainingCertificateAssetsTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: `TrainingCertificateAssets::resolve(string $resourcesDir): array` mit den Keys
  - `font` (string, absoluter Pfad — wird **immer** zurückgegeben, auch wenn die Datei fehlt, weil das `@font-face` den Pfad braucht)
  - `logo`, `headline`, `signature` (je `?string` Data-URI, `null` wenn Datei fehlt oder unlesbar)
  - `missing` (`list<string>`, relative Namen der fehlenden Assets, in fester Reihenfolge)

  Konsumiert von Task 6 (Hülle, nimmt die vier ersten Keys), Task 9 (Render-Test, assertiert `missing === []`), Task 10 (Controller, **loggt** `missing`), Task 15 (Editor, **zeigt** `missing` an).

**Warum diese Klasse existiert:** Ohne sie würden Controller (Task 10) und Editor-Vorschau (Task 15) die vier Assets **je selbst** auflösen. Das ist exakt die Konstellation, die bei den DomPDF-Optionen zum Auseinanderlaufen geführt hätte: weicht ein Pfad ab, zeigt die Vorschau etwas anderes als die Auslieferung — und damit wäre der Riss wieder offen, den der Test-PDF-Knopf schließen sollte. Ein Resolver, drei Konsumenten.

**Spec-Ausschnitt (wörtlich):**

> **Vier Assets, geteilt über alle Zertifikat-Vorlagen.** Nicht pro Design, nicht pro Schulungsart: `resources/fonts/Oswald-SemiBold.ttf` (Grundschrift, Pfad + `chroot`), `resources/images/certificates/logo.png`, `resources/images/certificates/headline-zertifikat.png`, `resources/images/certificates/signature-block.png` (je Data-URI).

> Fehlt ein Bild, rendert das PDF ohne dieses Element (`null` statt Fehler); fehlt die Schrift, läuft alles in Helvetica. Beides ist kein Absturz und beides ist falsch — deshalb loggt der aufrufende Controller jedes fehlende Asset als `warning`; die Hülle selbst bleibt laravel-frei und lässt fehlende Bilder still weg.

> **„Test-PDF"** rendert mit Beispielwerten über dieselbe Hülle und dieselben Optionen und liefert das Ergebnis aus.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificateAssets;

class TrainingCertificateAssetsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/zert-assets-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp . '/fonts', 0777, true);
        mkdir($this->tmp . '/images/certificates', 0777, true);
    }

    protected function tearDown(): void
    {
        // Aufraeumen, damit Testlaeufe sich nicht gegenseitig sehen.
        foreach (['fonts/Oswald-SemiBold.ttf', 'images/certificates/logo.png',
                  'images/certificates/headline-zertifikat.png',
                  'images/certificates/signature-block.png'] as $f) {
            @unlink($this->tmp . '/' . $f);
        }
        @rmdir($this->tmp . '/images/certificates');
        @rmdir($this->tmp . '/images');
        @rmdir($this->tmp . '/fonts');
        @rmdir($this->tmp);
    }

    private function write(string $relative, string $bytes = 'PNGDATA'): void
    {
        file_put_contents($this->tmp . '/' . $relative, $bytes);
    }

    private function writeAll(): void
    {
        $this->write('fonts/Oswald-SemiBold.ttf', 'TTF');
        $this->write('images/certificates/logo.png');
        $this->write('images/certificates/headline-zertifikat.png');
        $this->write('images/certificates/signature-block.png');
    }

    public function testAlleAssetsVorhanden(): void
    {
        $this->writeAll();

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertSame([], $a['missing']);
        $this->assertSame($this->tmp . '/fonts/Oswald-SemiBold.ttf', $a['font']);
        $this->assertSame('data:image/png;base64,' . base64_encode('PNGDATA'), $a['logo']);
        $this->assertNotNull($a['headline']);
        $this->assertNotNull($a['signature']);
    }

    public function testFehlendesBildWirdNullUndGemeldet(): void
    {
        $this->writeAll();
        unlink($this->tmp . '/images/certificates/headline-zertifikat.png');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertNull($a['headline']);
        $this->assertSame(['images/certificates/headline-zertifikat.png'], $a['missing']);
        // Die uebrigen bleiben unberuehrt — ein fehlendes Bild ist kein Absturz.
        $this->assertNotNull($a['logo']);
    }

    public function testFehlendeSchriftWirdGemeldetAberDerPfadBleibt(): void
    {
        $this->writeAll();
        unlink($this->tmp . '/fonts/Oswald-SemiBold.ttf');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        // Der Pfad muss trotzdem kommen: das @font-face braucht ihn, und der
        // chroot-Check in TrainingCertificatePdfOptions prueft ihn.
        $this->assertSame($this->tmp . '/fonts/Oswald-SemiBold.ttf', $a['font']);
        $this->assertSame(['fonts/Oswald-SemiBold.ttf'], $a['missing']);
    }

    public function testAllesFehltErgibtVierMeldungenInFesterReihenfolge(): void
    {
        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertSame([
            'fonts/Oswald-SemiBold.ttf',
            'images/certificates/logo.png',
            'images/certificates/headline-zertifikat.png',
            'images/certificates/signature-block.png',
        ], $a['missing']);
    }

    public function testKeysSindImmerVollstaendig(): void
    {
        $a = TrainingCertificateAssets::resolve($this->tmp);
        $keys = array_keys($a);
        sort($keys);

        $this->assertSame(['font', 'headline', 'logo', 'missing', 'signature'], $keys);
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificateAssetsTest`
Expected: FAIL — `Class "…TrainingCertificateAssets" not found`

- [ ] **Step 3: Implementierung schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Loest die vier Zertifikat-Assets auf: Schrift-Pfad und drei Bilder als
 * Data-URIs, plus die Liste der fehlenden.
 *
 * Warum eine eigene Klasse und nicht je im Controller und im Editor:
 * genau diese Doppelung ist bei den DomPDF-Optionen fast zum Problem
 * geworden. Weicht ein Pfad zwischen Vorschau und Auslieferung ab, zeigt der
 * Test-PDF-Knopf etwas anderes als das ausgestellte Dokument — und der
 * Knopf existiert genau dazu, das zu verhindern. Ein Resolver, drei
 * Konsumenten: Controller loggt `missing`, Editor zeigt es an, Render-Test
 * assertiert `missing === []`.
 *
 * Laravel-frei, damit der Render-Test ohne Bootstrap laeuft. Das Loggen
 * fehlender Assets ist deshalb Sache des Aufrufers, nicht dieser Klasse.
 */
final class TrainingCertificateAssets
{
    private const FONT = 'fonts/Oswald-SemiBold.ttf';

    /** Reihenfolge ist Teil des Vertrags — die Tests assertieren sie. */
    private const IMAGES = [
        'logo' => 'images/certificates/logo.png',
        'headline' => 'images/certificates/headline-zertifikat.png',
        'signature' => 'images/certificates/signature-block.png',
    ];

    /**
     * @param string $resourcesDir absoluter Pfad auf das resources/-Verzeichnis des Moduls
     * @return array{font: string, logo: ?string, headline: ?string, signature: ?string, missing: list<string>}
     */
    public static function resolve(string $resourcesDir): array
    {
        $base = rtrim($resourcesDir, '/');
        $missing = [];

        $fontPath = $base . '/' . self::FONT;
        if (!is_file($fontPath) || !is_readable($fontPath)) {
            // Pfad trotzdem zurueckgeben: das @font-face braucht ihn, und
            // TrainingCertificatePdfOptions prueft ihn gegen den chroot.
            $missing[] = self::FONT;
        }

        $out = ['font' => $fontPath];

        foreach (self::IMAGES as $key => $relative) {
            $path = $base . '/' . $relative;

            if (!is_file($path) || !is_readable($path)) {
                $out[$key] = null;
                $missing[] = $relative;
                continue;
            }

            $binary = @file_get_contents($path);
            if ($binary === false) {
                $out[$key] = null;
                $missing[] = $relative;
                continue;
            }

            $out[$key] = 'data:image/png;base64,' . base64_encode($binary);
        }

        $out['missing'] = $missing;

        return $out;
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificateAssetsTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/TrainingCertificateAssets.php tests/Unit/TrainingCertificateAssetsTest.php
git commit -m "feat(recruiting): Asset-Resolver fuer Zertifikate — ein Resolver, drei Konsumenten"
```

---

### Task 6: `TrainingCertificateHtml` — die Hülle

**Files:**
- Create: `src/Support/TrainingCertificateHtml.php`
- Test: `tests/Unit/TrainingCertificateHtmlTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: `TrainingCertificateHtml::build(string $personalizedContent, array $assets): string`. `$assets` erwartet die Keys `font` (absoluter Pfad), `logo`, `headline`, `signature` (je Data-URI-String oder `null`). Fehlt ein Bild (`null`), wird es weggelassen — kein Fehler.

**Spec-Ausschnitt (wörtlich):**

> `TrainingCertificateHtml` liefert Seiten-Setup, Papierfarbe, Schrift-Einbindung, Basis-Styles und die Fuß-Verankerung:
>
> ```
> @font-face { font-family: "Zert"; font-weight: normal; font-style: normal;
>              src: url("<fontPath>") format("truetype"); }
> @page { margin: 0; size: A4; }
> body  { margin: 0; padding: 15mm 18mm 11mm; background: #FDF3E0;
>         font-family: "Zert", sans-serif; color: #3C4A63; text-align: center; }
> .zert-fuss-links  { position: absolute; left:  24mm; width: 54mm; bottom: 12mm; }
> .zert-fuss-rechts { position: absolute; left: 116mm; width: 66mm; bottom: 10mm; }
> ```

> **Datum und Unterschriftszeile sind am Seitenfuß verankert**, als Divs (nicht als Tabelle) mit `position: absolute; bottom: …`. Damit ist der Fuß aus dem Fluss genommen und kann nicht mehr durch Abstände nach unten geschoben werden.

**KORRIGIERT nach dem Review zu Task 6:** Der Spec-Ausschnitt sagte hier ursprünglich, damit könne der Mittelteil „keinen Seitenumbruch mehr erzeugen — die Einzelseiten-Eigenschaft wird strukturell erzwungen". Gemessen mit echten Assets: 4 Zeilen → 1 Seite, 10 → 1 Seite, **20 → 2 Seiten**, 40 → 2 Seiten. Die Verankerung erzwingt, dass **der Fuß** nicht umbricht, nicht dass das Dokument einseitig bleibt. Die Einzelseitigkeit braucht weiterhin einen Längen-Guard (Task 7/8, advisory) und einen Render-Test über die **Listenlänge** (Task 9), nicht nur über lange Namen. Die Spec ist an §E5 entsprechend korrigiert.

> **`position:absolute` + `bottom` funktioniert bei Block-Divs, NICHT zuverlässig bei `<table>`.** Eine bottom-verankerte Tabelle lief unten aus der Seite; als zwei Divs korrekt.

> **Die Hülle stylt auch `p`, `h2`, `strong` und `li`** so, dass gewöhnliches HTML brauchbar aussieht (zentriert, Grundschrift, sinnvolle Abstände). Die Klassen sind dann Feinsteuerung, keine Voraussetzung. Wer nur einen Satz ergänzen will, tippt einen `<p>` und es passt.

> **Sonderzeichen laufen in DejaVu.** Die ★-Trenner der Kenntnisliste stehen in `<span class="zeichen">`, das per CSS auf `"DejaVu Sans"` schaltet.

> Papierfarbe `#FDF3E0`, nicht der Scan-Ton `#FBDAA3`.

**Layout-Entscheidung, die dieser Task festlegt:** Die Hülle emittiert die drei Bilder an fixen Positionen — Logo und Headline oben im Fluss, der Unterschriften-Block absolut in `.zert-fuss-links`. Der Vorlageninhalt liefert **nur Text**: Labels, Name, Kursname, Datum, Kenntnisliste, die Ausstellungszeile und den rechten Fußblock mit dem Schulungsleiter. Grund: die Bilder sind über alle Zertifikat-Vorlagen identisch und dürfen von HR nicht verschoben werden.

Geometrie aus dem verifizierten Prototyp (`/Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/render_live.php` — **absoluter Pfad, außerhalb des Repos**; das Repo hat ein eigenes `docs/zertifikat/`, das nur die Guard-Landkarte enthält): Logo 40 mm, Headline 116 mm, Unterschriftsbild 54 mm, Ausstellungszeile `bottom: 46mm`.

> **`font-weight` im `@font-face` ist `normal`, und das ist Absicht — nicht `600`.** Die ausgelieferte Datei heißt `Oswald-SemiBold.ttf`, also ist `font-weight: 600` die intuitive Deklaration. Sie fällt **stumm auf Helvetica** zurück, solange der `body` nicht ebenfalls `font-weight: 600` fordert. Gemessen mit intaktem Font, sonst identischen Optionen, `/BaseFont` im erzeugten PDF:
>
> | `@font-face` | `body` | `/BaseFont` |
> |---|---|---|
> | `normal` | (keine Angabe) | `Oswald-SemiBold` |
> | `600` | (keine Angabe) | **`Helvetica`** |
> | `600` | `font-weight:600` | `Oswald-SemiBold` |
> | `bold` | `font-weight:bold` | `Oswald-SemiBold` |
>
> Dieselbe Fehlerklasse wie der fehlende `chroot`: keine Exception, kein Log, das PDF sieht auf den ersten Blick brauchbar aus. Wer die Deklaration an den Dateinamen „angleicht", muss den `body` mitziehen — oder er bricht die Schrift still. Gefangen wird das von Task 9, Assertion 2 (`/BaseFont` enthält `Oswald-SemiBold`); deshalb darf die Assertion nicht aufgeweicht werden.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificateHtml;

class TrainingCertificateHtmlTest extends TestCase
{
    private function assets(): array
    {
        return [
            'font' => '/app/resources/fonts/Oswald-SemiBold.ttf',
            'logo' => 'data:image/png;base64,AAAA',
            'headline' => 'data:image/png;base64,BBBB',
            'signature' => 'data:image/png;base64,CCCC',
        ];
    }

    public function testSeitenSetupUndPapierfarbe(): void
    {
        $html = TrainingCertificateHtml::build('<p>X</p>', $this->assets());

        $this->assertStringContainsString('@page { margin: 0; size: A4; }', $html);
        $this->assertStringContainsString('background: #FDF3E0', $html);
        $this->assertStringContainsString('color: #3C4A63', $html);
    }

    public function testFontWirdMitAbsolutemPfadEingebunden(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertStringContainsString(
            'src: url("/app/resources/fonts/Oswald-SemiBold.ttf") format("truetype")',
            $html
        );
    }

    public function testFussKlassenSindBottomVerankertUndKeineTabelle(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertStringContainsString('.zert-fuss-links', $html);
        $this->assertStringContainsString('bottom: 12mm', $html);
        $this->assertStringContainsString('.zert-fuss-rechts', $html);
        $this->assertStringContainsString('bottom: 10mm', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testNackteElementeWerdenGestylt(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        foreach (['p {', 'h2 {', 'strong {', 'li {'] as $selector) {
            $this->assertStringContainsString($selector, $html);
        }
    }

    public function testSonderzeichenKlasseSchaltetAufDejaVu(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertMatchesRegularExpression(
            '/\.zeichen\s*\{[^}]*DejaVu Sans/',
            $html
        );
    }

    public function testDreiBilderWerdenEmittiert(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertStringContainsString('data:image/png;base64,AAAA', $html);
        $this->assertStringContainsString('data:image/png;base64,BBBB', $html);
        $this->assertStringContainsString('data:image/png;base64,CCCC', $html);
    }

    public function testFehlendesBildWirdWeggelassenOhneFehler(): void
    {
        $assets = $this->assets();
        $assets['headline'] = null;

        $html = TrainingCertificateHtml::build('', $assets);

        $this->assertStringNotContainsString('zert-headline', $html);
        $this->assertStringContainsString('zert-logo', $html);
    }

    public function testInhaltWirdUnveraendertEingesetzt(): void
    {
        $content = '<div class="val">ERIKA MUSTERMANN</div>';

        $this->assertStringContainsString(
            $content,
            TrainingCertificateHtml::build($content, $this->assets())
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificateHtmlTest`
Expected: FAIL — `Class "…TrainingCertificateHtml" not found`

- [ ] **Step 3: Implementierung schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Die HTML-Huelle des Schulungszertifikats.
 *
 * Bewusst KEINE Blade: der Render-Test laeuft ohne Laravel-Bootstrap
 * (tests/bootstrap.php ist ein reiner Autoloader), und eine Blade waere dort
 * nur mit handverdrahtetem BladeCompiler + FileViewFinder + Engine-Resolver
 * zu rendern. Als Klasse ist die Huelle direkt unit-testbar — und es gibt
 * keine zweite Blade, die jemand versehentlich in ein gemeinsames Layout
 * mit dem Vertragsweg ziehen koennte.
 *
 * Aufteilung: die drei Bilder emittiert die Huelle an fixen Positionen, weil
 * sie ueber alle Zertifikat-Vorlagen identisch sind und HR sie nicht
 * verschieben soll. Der Vorlageninhalt liefert nur Text.
 *
 * Datum und Unterschriftszeile sind absolut am Seitenfuss verankert. Damit ist
 * der FUSS aus dem Fluss genommen und kann nicht mehr durch Abstaende nach
 * unten geschoben werden. Als <table> funktioniert das in DomPDF 3.1.5 nicht:
 * eine bottom-verankerte Tabelle laeuft unten aus der Seite.
 *
 * KORREKTUR: hier stand "damit kann der fliessende Mittelteil keinen
 * Seitenumbruch erzeugen — die Einzelseiten-Eigenschaft ist strukturell
 * erzwungen". FALSCH, gemessen: 4 Zeilen Kenntnisliste 1 Seite, 10 Zeilen
 * 1 Seite, 20 Zeilen 2 SEITEN, 40 Zeilen 2 Seiten. Die Einseitigkeit ist
 * KEINE Eigenschaft dieser Klasse, sondern eine des Vorlageninhalts, und der
 * liegt in einem Textarea, in das HR schreiben darf. Waechter sind die
 * Seitenzahl-Anzeige am Test-PDF-Knopf und die Assertion im Render-Test.
 */
final class TrainingCertificateHtml
{
    /**
     * @param array{font: string, logo: ?string, headline: ?string, signature: ?string} $assets
     */
    public static function build(string $personalizedContent, array $assets): string
    {
        $font = $assets['font'];
        $logo = $assets['logo'] ?? null;
        $headline = $assets['headline'] ?? null;
        $signature = $assets['signature'] ?? null;

        $logoHtml = $logo === null
            ? ''
            : '<div><img class="zert-logo" src="' . $logo . '" alt="RheinGedeck"></div>';

        $headlineHtml = $headline === null
            ? ''
            : '<div><img class="zert-headline" src="' . $headline . '" alt="Zertifikat"></div>';

        $signatureHtml = $signature === null
            ? ''
            : '<div class="zert-fuss-links"><img class="zert-signatur" src="' . $signature
              . '" alt="RheinGedeck GmbH"></div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="de"><head><meta charset="UTF-8"><style>
  @font-face { font-family: "Zert"; font-weight: normal; font-style: normal;
               src: url("{$font}") format("truetype"); }
  @page { margin: 0; size: A4; }
  body  { margin: 0; padding: 15mm 18mm 11mm; background: #FDF3E0;
          font-family: "Zert", sans-serif; color: #3C4A63; text-align: center; }

  /* Bilder — Positionen aus dem verifizierten Prototyp */
  .zert-logo     { width:  40mm; }
  .zert-headline { width: 116mm; margin: 4mm 0 6mm; }
  .zert-signatur { width:  54mm; }

  /* Fuss-Verankerung: Divs, nicht Tabelle (DomPDF-Einschraenkung) */
  .zert-datum       { position: absolute; left:  18mm; width: 174mm; bottom: 46mm;
                      font-size: 11.5pt; letter-spacing: 2px; text-transform: uppercase; }
  .zert-fuss-links  { position: absolute; left:  24mm; width:  54mm; bottom: 12mm; text-align: center; }
  .zert-fuss-rechts { position: absolute; left: 116mm; width:  66mm; bottom: 10mm; text-align: center; }

  /* Vokabular fuer den Vorlageninhalt */
  .lab    { font-size: 11pt;   letter-spacing: 2.5px; text-transform: uppercase; }
  .val    { font-size: 15pt;   letter-spacing: 2px;   text-transform: uppercase; margin: 2mm 0 6mm; }
  .kurs   { font-size: 24pt;   letter-spacing: 3px;   text-transform: uppercase; margin: 2mm 0 6mm; }
  .intro  { font-size: 11.5pt; letter-spacing: 2px;   text-transform: uppercase; margin: 8mm 0 4mm; }
  .skill  { font-size: 12pt;   letter-spacing: 1.6px; text-transform: uppercase; margin: 1.1mm 0; }
  .leiter { font-size: 9.5pt;  letter-spacing: 1.5px; text-transform: uppercase; }
  .cap    { font-size: 10pt;   letter-spacing: 2px;   text-transform: uppercase; }
  .linie  { border-top: 1px solid #3C4A63; margin: 1.5mm 0; }

  /* Sonderzeichen: Oswald hat kein ★. Ohne diesen Umweg steht "?" im PDF. */
  .zeichen { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; padding: 0 3mm; }

  /* Basis-Styles fuer nackte Elemente: wer nur einen Satz ergaenzt, tippt
     einen <p> und es passt. Die Klassen oben sind dann Feinsteuerung. */
  p      { font-size: 11.5pt; letter-spacing: 1.5px; margin: 3mm 0; }
  h2     { font-size: 16pt;   letter-spacing: 2px; text-transform: uppercase; margin: 4mm 0; font-weight: normal; }
  strong { font-weight: normal; letter-spacing: 2.5px; }
  li     { font-size: 12pt; letter-spacing: 1.6px; list-style: none; margin: 1.1mm 0; }
</style></head><body>
{$logoHtml}
{$headlineHtml}
{$personalizedContent}
{$signatureHtml}
</body></html>
HTML;
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificateHtmlTest`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/TrainingCertificateHtml.php tests/Unit/TrainingCertificateHtmlTest.php
git commit -m "feat(recruiting): HTML-Huelle des Schulungszertifikats als Support-Klasse"
```

---

### Task 6a: Platzhalter-Auflösung der Bestandsvorlagen festnageln — VOR Task 7

**Files:**
- Test: `tests/Integration/PlaceholderResolutionPinTest.php`

**Interfaces:**
- Consumes: `RecContractTemplate::personalizeContent()` (Bestand, **unverändert**), `TestSchema` (Task 2a)
- Produces: nichts — reine Absicherung

**Warum dieser Task existiert, und warum als eigener Task VOR Task 7:**

Task 0 friert das **Aussehen** des Vertrags-PDFs ein (Seitenzahl, Fontliste, Stylesheet-Hash, Stempel). Für die **Werte** — was `personalizeContent()` aus den `field_mappings` macht — gibt es bis hierher keinen Test. Task 7 hängt einen neuen `schulung.`-Zweig in `resolveSource()`.

Der bisherige Schutz war ein Argument statt eines Tests: „keine Bestandsvorlage benutzt `schulung.*`, also ist der neue Zweig für sie unerreichbar". Das ist heute wahr — und bleibt wahr, bis jemand im Vorlagen-Editor ein Mapping tippt. Der Editor ist genau die Fläche, die dieses Paket erweitert (Task 15). **Ein Argument über Daten, die HR selbst ändern kann, ist kein Schutz.**

Dazu ist `resolveSource()` eine Methode in Bewegung: in den neun Commits vor `511451c` bekam sie eine neue Signatur (`?ZasLookupResolver $lookups = null`) und einen neuen Lookup-Label-Zweig. Task 7 wäre die dritte Änderung in kurzer Folge.

**Eigener Task, nicht Steps in Task 7** — sonst wäre der Schutz Teil derselben Änderung, gegen die er schützen soll. Er muss grün sein, *bevor* Task 7 anfängt.

**Die festzunagelnden Mappings — mechanisch aus den 11 Live-Vorlagen abgeleitet (Stand 2026-08-12), nicht aus dem Gedächtnis.** Diese Aufstellung gehört als Kommentar in den Test:

```
Vorlagen: 11 (AV-default, AT-140, AV, IFSG, AV-010, AV-060, AV-110, AV-160,
               AV-210, AV-260, AV-TEST)

Alle verwendeten Quellen, sortiert, mit Anzahl der Vorlagen:
   1x  applicant.extra_field.ausweisnummer
   9x  applicant.extra_field.geburtsort
   1x  applicant.extra_field.nationalitaet
   1x  applicant.zuschlag
  11x  contact.address.city
  10x  contact.address.house_number
  11x  contact.address.postal_code
  11x  contact.address.street
  11x  contact.birth_date
  11x  contact.first_name
  11x  contact.last_name
   1x  contract.extra_field.stundenlohn
   9x  contract.extra_field.vertragsbeginn
   9x  contract.extra_field.vertragsende
   1x  contract.extra_field.zuschlag
  11x  meta.datum_heute
   8x  settings.minimum_wage_hourly

Distinkte Praefixe: applicant. contact. contract. meta. settings.
schulung.* in Benutzung: NEIN
```

**Zweig-Reihenfolge in `resolveSource()` gegen `511451c`** — die Reihenfolge ist Teil des Verhaltens, der erste passende Zweig gewinnt: `contact.` (`:110`, darin `address.` `:126`), `applicant.` (`:139`, darin `extra_field.` `:142` mit Lookup-Label-Zweig `:145-160`), `contract.extra_field.` (`:184`, **nur wenn ein `$contract` übergeben ist**), `settings.` (`:189`), `text:` (`:205`), `meta.` (`:209`), danach `return ''`.

**Die 13 Fälle, jeder als eigene Testmethode. Fall 1 steht bewusst vorn:**

1. **Ein nicht gemapptes `{{resttage}}` bleibt unverändert stehen.** Der wertvollste Fall des ganzen Tasks, deshalb an erster Stelle. Begründung gehört als Kommentar an die Methode: Die AT-140-Zusatzvertrag-Logik (`Support/ResttagePlaceholder`) baut darauf, dass `personalizeContent()` nur die **gemappten** Platzhalter ersetzt und alles andere durchlässt — `{{resttage}}` wird erst beim Unterschreiben durch die Eingabe des Bewerbers gefüllt. Wer `personalizeContent()` später „aufräumt" und unbekannte Platzhalter leert, **bricht den Zusatzvertrag still**: ein Vertrag, dem die Zahl fehlt, ohne Fehlermeldung, ohne Log. Genau die Sorte Annahme, die nirgends steht, weil sie immer galt. Erst dieser Test schreibt sie fest.
2. **`contact.first_name` / `contact.last_name`** → der Wert des verknüpften CRM-Kontakts.
3. **`contact.birth_date`** → Format `d.m.Y`. Ein `Carbon` muss formatiert herauskommen, nicht als ISO-String.
4. **`contact.address.city` / `.postal_code` / `.street` / `.house_number`** → aus der primären Postadresse; ohne Adresse leerer String.
5. **`applicant.extra_field.geburtsort`** → Extra-Field-Wert als Text.
6. **`applicant.extra_field.nationalitaet` MIT Lookup-Definition → das LABEL.** Nicht „nichtleerer String" assertieren, sondern den konkreten Labeltext. Der Zweig (`:145-160`) prüft `options['lookup_id']` an der Definition und löst über `ZasLookupResolver::resolveLabel()` auf. Fixture: Definition mit `lookup_id`, gespeicherter Maschinenwert (z.B. `tr`), Erwartung der Labeltext (z.B. `Türkei`). **Der Labeltext IST der Punkt dieses Falls** — ein Test, der nur Nichtleere prüft, schützt genau das Spezifische nicht: er bliebe grün, wenn der Maschinenwert `tr` im Dokument landet.
7. **`applicant.extra_field.*` OHNE Lookup-Definition** → unveränderter Wert. Belegt, dass der Lookup-Zweig nur bei echten Lookup-Feldern greift.
8. **`applicant.zuschlag` → deutsches Dezimalformat, zwei Stellen.** Nicht die Auflösung, sondern **die Formatierung** ist der Punkt (`:178-180`, `number_format($v, 2, ',', '.')`): aus `0.6` muss **`0,60`** werden. Assertiere den exakten String. Ein Test auf „enthält 0" oder „nichtleer" bliebe grün, wenn daraus `0.6` würde — und `0.6` in einem Arbeitsvertrag ist ein Zahlendreher mit Rechtsfolge.
9. **`contract.extra_field.vertragsbeginn`** → Wert aus dem übergebenen Contract. **Und der Fall ohne Contract:** ohne `$contract` greift der Zweig nicht (`:184` verlangt `&& $contract`), Ergebnis leerer String. Beide Fälle.
10. **`settings.minimum_wage_hourly`** → Float-Formatierung nach `:196-197`, deutsches Format, exakter String. Zusätzlich der Bool-Fall (`:200`: `true` → `'ja'`), falls mit vertretbarem Fixture-Aufwand erreichbar; sonst als nicht festgenagelt dokumentieren.
11. **`meta.datum_heute`** → heutiges Datum in `d.m.Y`.
12. **`meta.ort` → leerer String.** Der dokumentierte Dead End; wird er je versehentlich verdrahtet, muss dieser Test rot werden.
13. **NEGATIVFALL: unbekanntes Präfix** → z.B. `voelligUnbekannt.feld` liefert **leeren String, wirft nicht**. Ohne diesen Fall fällt später nicht auf, wenn ein neuer Zweig die Fallback-Semantik ändert — etwa auf Exception oder auf den rohen Quellstring.

**Durchgehende Auflage zur Assertion-Schärfe:** Wo eine Formatierung Teil des Verhaltens ist (Fälle 3, 6, 8, 10, 11), wird der **exakte erwartete String** assertiert, nicht Nichtleere und nicht „enthält". Genau dort steckt das Spezifische, das sonst unbemerkt wegbricht.

**Fixture-Aufbau:** Muster `tests/Integration/DuplicateMatchQueryTest.php:25-70` (Container + Capsule + Facade-Verdrahtung) und `:86-129` — jener Test lädt die **echten Migrationsdateien** per `require` und ruft `up()` auf, statt Schemata von Hand zu bauen, und löst dabei zwei Wurzeln getrennt auf (eigenes Modul über `dirname(__DIR__, 2)`, Nachbarmodule über eine inhaltsbasierte Aufwärtssuche). Übernimm dieses Muster. Gebraucht werden: `rec_contract_templates` (über `TestSchema`, Task 2a), `rec_applicants`, `crm_contacts` samt Verknüpfungstabelle und Postadressen, `rec_contracts`, `rec_applicant_settings`, und die `core_extra_field_*`-Tabellen für Definitionen und Werte.

**Zwei ehrliche Auflagen:**

- **Der Fixture-Aufwand ist hier höher als in jedem anderen Task dieses Plans.** Ist eine der 13 Positionen ohne Änderung an Produktionscode nicht fixturebar, dann **dokumentiere sie im Docblock als nicht festgenagelt** und baue sie NICHT nach, indem du Produktionscode umbaust. Zweck dieses Tasks ist, Bestandsverhalten zu konservieren; Produktionscode anzufassen wäre das Gegenteil. Melde solche Fälle als Bedenken zurück.
- **Wird der Test rot, hat der Bestand recht, nicht die Erwartung.** Korrigiere die Erwartung, melde die Abweichung als Bedenken, und passe unter keinen Umständen Produktionscode an, damit der Test grün wird.

**Nicht anfassen:** `src/Models/RecContractTemplate.php` und alles andere unter `src/`. Dieser Task legt ausschließlich eine Testdatei an.

- [ ] **Step 1: Test schreiben**

Die 13 Fälle als je eigene Testmethode mit sprechendem Namen und sprechender Assertion-Meldung. Fall 1 als erste Methode in der Datei. Die Mapping-Aufstellung, die Zweig-Reihenfolge und der Datumsstand `2026-08-12` als Klassen-Docblock, mit dem Hinweis, wogegen festgenagelt wurde.

Fixture-Skelett:

```php
public static function setUpBeforeClass(): void
{
    $container = Container::getInstance();
    $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Model::unguard();

    // Schema-/DB-Facades auf Capsule verdrahten, damit die ECHTEN
    // Migrationsdateien unveraendert laufen — Muster
    // DuplicateMatchQueryTest:63-67
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
    Facade::setFacadeApplication($container);

    TestSchema::contractTemplates($capsule->schema());
    // weitere Tabellen: echte Migrationsdateien laden wie in
    // DuplicateMatchQueryTest:86-129
}
```

- [ ] **Step 2: Test laufen lassen — er MUSS von Anfang an grün sein**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PlaceholderResolutionPinTest`
Expected: PASS.

Anders als bei TDD-Tasks ist ein roter Lauf hier ein **Befund**, nicht der erwartete Ausgangspunkt: der Test beschreibt Verhalten, das es schon gibt.

- [ ] **Step 3: Gesamtsuite**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS, mit den neuen Tests dazugezählt.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/PlaceholderResolutionPinTest.php
git commit -m "test(recruiting): Platzhalter-Aufloesung der Bestandsvorlagen festnagelt

Task 0 friert das Aussehen des Vertrags-PDFs ein, dieser Test die Werte.
Task 7 haengt einen neuen schulung.-Zweig in resolveSource(); der bisherige
Schutz fuer die 17 in Benutzung befindlichen Mapping-Quellen war ein Argument
ueber Live-Daten, die HR im Editor selbst aendern kann — kein Test.

Erster Fall ist der wichtigste: ein nicht gemapptes {{resttage}} muss stehen
bleiben. Die AT-140-Logik baut darauf; wer personalizeContent() spaeter
aufraeumt und unbekannte Platzhalter leert, bricht den Zusatzvertrag still.

Wo Formatierung Teil des Verhaltens ist (Dezimalkomma beim Zuschlag,
Lookup-Label statt Maschinenwert, d.m.Y bei Datumsfeldern), wird der exakte
String assertiert, nicht Nichtleere.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: `TrainingLeaderResolver` + `schulung.`-Zweig in `resolveSource()`

> **HALBIERT durch den Zuschnitt v3 — und die verbleibende Hälfte ist die harmlose.**
>
> **Es bleibt:** `src/Support/TrainingLeaderResolver.php` mit `pickBooking()`, `leaderNames()`, `trainingDate()` und `tests/Unit/TrainingLeaderResolverTest.php`. Vollständig, unverändert. Die drei variablen Werte müssen auch in festem HTML von irgendwo kommen, und Schulungsdatum wie Schulungsleiter stehen in der maßgeblichen Buchung (G10). Der Task behält damit seinen Kern und seinen kompletten Testumfang, inklusive des dreizehnten Falls, der als wertvollster benannt war.
>
> **Es entfällt:** der `schulung.`-Zweig in `RecContractTemplate::resolveSource()` und jede Änderung an `src/Models/RecContractTemplate.php`. Damit entfallen auch die drei Auflagen aus dem Review zu Task 6a, die genau diesen Eingriff betrafen (Breite der Bedingung, Fallback-Semantik, der Reflection-Konsument `DebugContractFieldResolution.php:102-109`) — es wird keine Bestandsmethode angefasst, also gibt es dort nichts zu brechen.
>
> **Task 6a bleibt trotzdem wertvoll.** Er war als Netz für genau diesen Eingriff gedacht und wird dafür nicht mehr gebraucht. Was er geleistet hat, bleibt: 17 festgenagelte Bestandsfälle für den Vertragsweg, der ISO-Datums-Befund an `contract.extra_field.*` und der Facade-Defekt, der sonst Task 9 und 15 getroffen hätte. Nicht umsonst gebaut.
>
> **Neu in diesem Task, weil es keinen anderen Ort mehr hat:** die Platzhalter-Ersetzung. Vier `{{…}}`-Marken (`kontakt_vorname`, `kontakt_nachname`, `schulung_datum`, `schulung_leiter`, plus `datum_heute`) werden bei der Ausstellung per `str_replace` gesetzt — Schreibweise und Namen bleiben identisch zur Vorlagen-Fassung, weil `ResttagePlaceholder::hasUnresolvedPlaceholder()` in Task 9 auf genau dieses Muster prüft und der Rückweg dieselben Namen braucht.


**Files:**
- Create: `src/Support/TrainingLeaderResolver.php`
- Modify: `src/Models/RecContractTemplate.php` (`resolveSource`, ab `:108`)
- Test: `tests/Unit/TrainingLeaderResolverTest.php`

**Interfaces:**
- Consumes: **Task 6a muss grün sein, bevor dieser Task startet** — er nagelt die Auflösung der 17 in Benutzung befindlichen Mapping-Quellen fest, gegen die dieser Task einen neuen Zweig hängt.

**Drei Auflagen aus dem Review zu Task 6a — gemessen, nicht vermutet:**

**1) Die Einfügeposition des `schulung.`-Zweigs ist verhaltensneutral. Ein „an Stelle X einsortieren" ist die falsche Vorsicht.** Gemessen gegen die 17 Pin-Fälle: der Zweig ganz oben (vor `contact.`) → grün, unten (vor `return ''`) → grün. Die Präfixe sind disjunkt. Was der Pin-Test wirklich bewacht und woran du dich halten musst: **die Bedingung darf nicht breiter sein als genau `schulung.`** (eine Bedingung `contract.` statt `contract.extra_field.` machte Fall 9 rot) und **die Fallback-Semantik bleibt `return ''`** (ein `return $source` machte Fall 13 und 9 rot). Die echte Gefahr ist ein **Umbau des if-Chains**, nicht die Position.

**2) Es gibt einen zweiten Konsumenten von `resolveSource()`, und er ruft per Reflection auf.** `src/Console/Commands/DebugContractFieldResolution.php:102-109` ruft die private Methode mit **fünf positionalen Argumenten**. Der modell-interne Aufrufer ist durch Pin-Fall 6 gedeckt, dieser Command **nicht**: ein neuer Parameter vor `$lookups` lässt das Diagnosewerkzeug still Maschinenwerte statt Labels anzeigen — es lügt also genau dann, wenn man ihm glaubt. Ziehst du die Signatur an, zieh den Command mit und prüf ihn.

**3) Wenn du eine neue Integrations-Testklasse anlegst:** `Facade::clearResolvedInstances()` in Setup **und** Teardown, plus `Model::clearBootedModels()`. Beides ist prozessweiter statischer Zustand, dessen Symptome **nur im Gesamtlauf** auftreten, nie im gefilterten. Muster: `tests/Integration/PlaceholderResolutionPinTest.php`. Und beachte: Klassennamen, die alphabetisch **nach** `PlaceholderResolutionPinTest` sortieren (`T…`, `Z…`), erben dessen globale Capsule (`setAsGlobal()`) und den Eloquent-Connection-Resolver auf eine nicht mehr gebrauchte In-Memory-DB — der Pin-Test räumt nur die Facade-Instanzen auf, nicht das.
- Produces:
  - `TrainingLeaderResolver::pickBooking(array $bookings): ?array` — wählt aus einer Liste von Buchungen `['id' => int, 'status' => string, 'starts_at' => string|null, 'interviewers' => list<string>]` die maßgebliche aus
  - `TrainingLeaderResolver::leaderNames(array $bookings): string`
  - `TrainingLeaderResolver::trainingDate(array $bookings): string` (Format `d.m.Y`)
  - Neue Mapping-Quellen `schulung.datum` und `schulung.leiter`

**Spec-Ausschnitt (wörtlich):**

> Platzhalter-Zweig `schulung.` mit der Selektionsregel: `attended`, sortiert **`interview.starts_at DESC`, Tie-Break `bookings.id DESC`** — **bewusst nicht `latest('id')`**: Bei einer Umbuchung kann die zuletzt *erfasste* Buchung ein *früheres* Termindatum haben. Auf dem Dokument steht das Datum, das der Bewerber liest — es muss das späteste tatsächliche Teilnahmedatum sein, nicht das jüngste Insert.

> `schulung.datum` → `starts_at` im Format `d.m.Y`. Keine Buchung gefunden oder Feld leer → leerer String (Semantik wie alle anderen Zweige).

> Aufgelöst als `interview->interviewers->pluck('name')->join(', ')`. Keine Buchung, kein Interviewer oder leerer Name → leerer String, wie alle anderen Zweige von `resolveSource()`.

> **Die Auflösung liegt in einer Support-Klasse, nicht inline in `resolveSource()`.** `resolveSource()` hat seit `511451c` fünf Parameter und drei Verzweigungsebenen; der `schulung.`-Zweig delegiert deshalb an eine eigene Klasse mit eigenem Unit-Test, so wie `Support/ResttagePlaceholder` und `Support/LookupLabelFormatter` es vormachen.

> **Kein Typ-Filter auf die Terminart.** Kriterium ist `attended`. Ein Filter auf eine bestimmte `interview_type_id` wäre eine zweite, stillschweigende Definition von „Schulung" neben der, die das Modul benutzt.

> `schulung.ort` wird für dieses Dokument **nicht** gemappt: `rec_interviews.location` enthält die volle Adresse (live geprüft: `"RheinGedeck GmbH, Hansaallee 321 / Halle 33a, 40549 Düsseldorf"`), in „DÜSSELDORF, DEN …" gehört ein Stadtname. Der Ort bleibt Literaltext in der Vorlage.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingLeaderResolver;

class TrainingLeaderResolverTest extends TestCase
{
    private function booking(int $id, string $status, ?string $startsAt, array $interviewers): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'starts_at' => $startsAt,
            'interviewers' => $interviewers,
        ];
    }

    public function testNurAttendedZaehlt(): void
    {
        $bookings = [
            $this->booking(1, 'no_show', '2026-07-01 14:00:00', ['Falsch']),
            $this->booking(2, 'attended', '2026-06-01 14:00:00', ['Richtig']),
        ];

        $this->assertSame('Richtig', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testSpaetesterTerminGewinntNichtDasJuengsteInsert(): void
    {
        // Umbuchungsfall: Buchung 9 wurde SPAETER erfasst, hat aber ein
        // FRUEHERES Termindatum. Auf dem Dokument muss das spaeteste
        // tatsaechliche Teilnahmedatum stehen.
        $bookings = [
            $this->booking(3, 'attended', '2026-07-24 14:00:00', ['Spaeter Termin']),
            $this->booking(9, 'attended', '2026-06-02 14:00:00', ['Juengeres Insert']),
        ];

        $this->assertSame('Spaeter Termin', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('24.07.2026', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testTieBreakUeberIdAbsteigend(): void
    {
        $bookings = [
            $this->booking(4, 'attended', '2026-07-24 14:00:00', ['Alt']),
            $this->booking(7, 'attended', '2026-07-24 14:00:00', ['Neu']),
        ];

        $this->assertSame('Neu', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testZweiInterviewerWerdenVerbunden(): void
    {
        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', ['Michel Zimmer', 'Anna Bergmann'])];

        $this->assertSame('Michel Zimmer, Anna Bergmann', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testKeinInterviewerErgibtLeerenString(): void
    {
        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', [])];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testLeereNamenWerdenAussortiert(): void
    {
        $bookings = [$this->booking(1, 'attended', '2026-07-24 14:00:00', ['', '  ', 'Echt'])];

        $this->assertSame('Echt', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testKeineAttendedBuchungErgibtLeereStrings(): void
    {
        $bookings = [$this->booking(1, 'registered', '2026-07-24 14:00:00', ['X'])];

        $this->assertSame('', TrainingLeaderResolver::leaderNames($bookings));
        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
    }

    public function testBuchungOhneTerminErgibtLeeresDatum(): void
    {
        $bookings = [$this->booking(1, 'attended', null, ['X'])];

        $this->assertSame('', TrainingLeaderResolver::trainingDate($bookings));
        // Der Leiter ist trotzdem bekannt — die Buchung bleibt waehlbar.
        $this->assertSame('X', TrainingLeaderResolver::leaderNames($bookings));
    }

    public function testLeereListe(): void
    {
        $this->assertSame('', TrainingLeaderResolver::leaderNames([]));
        $this->assertSame('', TrainingLeaderResolver::trainingDate([]));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingLeaderResolverTest`
Expected: FAIL — `Class "…TrainingLeaderResolver" not found`

- [ ] **Step 3: Support-Klasse schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Waehlt die massgebliche Schulungsbuchung und liefert Datum und
 * Schulungsleiter fuer die schulung.*-Platzhalter.
 *
 * Selektionsregel: status='attended', sortiert nach Termindatum absteigend,
 * Tie-Break Buchungs-ID absteigend.
 *
 * Bewusst NICHT "juengste Buchung": bei einer Umbuchung kann die zuletzt
 * erfasste Buchung ein frueheres Termindatum haben. Auf dem Dokument steht
 * das Datum, das der Bewerber liest — es muss das spaeteste tatsaechliche
 * Teilnahmedatum sein.
 *
 * Kein Filter auf die Terminart: Kriterium ist 'attended'. Ein Filter auf
 * eine interview_type_id waere eine zweite, stillschweigende Definition von
 * "Schulung" neben der, die das Modul benutzt.
 *
 * Reine Datenstrukturen als Eingabe (keine Models) — damit unit-testbar
 * ohne Laravel, Muster wie Support/ResttagePlaceholder.
 */
final class TrainingLeaderResolver
{
    /**
     * @param list<array{id: int, status: string, starts_at: ?string, interviewers: list<string>}> $bookings
     * @return array{id: int, status: string, starts_at: ?string, interviewers: list<string>}|null
     */
    public static function pickBooking(array $bookings): ?array
    {
        $attended = array_values(array_filter(
            $bookings,
            fn (array $b) => ($b['status'] ?? null) === 'attended'
        ));

        if ($attended === []) {
            return null;
        }

        usort($attended, function (array $a, array $b) {
            // Buchungen ohne Termin sortieren nach hinten: ein bekanntes
            // Datum schlaegt ein unbekanntes.
            $aTime = $a['starts_at'] === null ? '' : (string) $a['starts_at'];
            $bTime = $b['starts_at'] === null ? '' : (string) $b['starts_at'];

            $byDate = strcmp($bTime, $aTime);
            if ($byDate !== 0) {
                return $byDate;
            }

            return ((int) $b['id']) <=> ((int) $a['id']);
        });

        return $attended[0];
    }

    /** @param list<array<string,mixed>> $bookings */
    public static function leaderNames(array $bookings): string
    {
        $booking = self::pickBooking($bookings);
        if ($booking === null) {
            return '';
        }

        $names = array_values(array_filter(
            array_map(
                fn ($n) => trim((string) $n),
                $booking['interviewers'] ?? []
            ),
            fn (string $n) => $n !== ''
        ));

        return implode(', ', $names);
    }

    /** @param list<array<string,mixed>> $bookings */
    public static function trainingDate(array $bookings): string
    {
        $booking = self::pickBooking($bookings);
        $raw = $booking['starts_at'] ?? null;

        if ($booking === null || $raw === null || trim((string) $raw) === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable((string) $raw))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingLeaderResolverTest`
Expected: PASS (9 tests)

- [ ] **Step 5: `schulung.`-Zweig in `resolveSource()` einhängen**

In `src/Models/RecContractTemplate.php`, in `resolveSource()` **vor** dem `meta.`-Zweig einfügen:

```php
        if (str_starts_with($source, 'schulung.')) {
            $field = substr($source, strlen('schulung.'));

            // Buchungen einmal in eine reine Datenstruktur uebersetzen — die
            // Auswahl- und Formatlogik liegt in TrainingLeaderResolver und ist
            // dort ohne Laravel testbar.
            $bookings = $applicant->interviewBookings()
                ->with('interview.interviewers:id,name')
                ->get()
                ->map(fn ($b) => [
                    'id' => (int) $b->id,
                    'status' => (string) $b->status,
                    'starts_at' => $b->interview?->starts_at?->format('Y-m-d H:i:s'),
                    'interviewers' => $b->interview
                        ? $b->interview->interviewers->pluck('name')->all()
                        : [],
                ])
                ->all();

            return match ($field) {
                'datum' => TrainingLeaderResolver::trainingDate($bookings),
                'leiter' => TrainingLeaderResolver::leaderNames($bookings),
                // schulung.ort bewusst NICHT gemappt: rec_interviews.location
                // enthaelt die volle Adresse, in "DUESSELDORF, DEN ..." gehoert
                // ein Stadtname. Der Ort bleibt Literaltext in der Vorlage.
                default => '',
            };
        }
```

Import ergänzen: `use Platform\Recruiting\Support\TrainingLeaderResolver;`

- [ ] **Step 6: Beziehungsname gegenprüfen**

Run: `grep -n "function interviewBookings" src/Models/RecApplicant.php`
Expected: Treffer auf `:325`. Gegen `511451c` verifiziert — dieser Schritt fängt nur ab, dass sich der Stand inzwischen bewegt hat.

- [ ] **Step 7: Syntax + Gesamtsuite**

Run: `php -l src/Models/RecContractTemplate.php && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: `No syntax errors detected`, gesamte Suite PASS

- [ ] **Step 8: Commit**

```bash
git add src/Support/TrainingLeaderResolver.php tests/Unit/TrainingLeaderResolverTest.php \
        src/Models/RecContractTemplate.php
git commit -m "feat(recruiting): schulung.datum und schulung.leiter als Platzhalter"
```

---

### Task 8: `RecTrainingCertificate` + `IssueTrainingCertificateService`

> **GEÄNDERT durch den Zuschnitt v3.** Vier Punkte, der letzte ist neu:
>
> 1. **Kein `templateId`-Parameter.** Signatur wird `issue(RecApplicant $applicant, ?int $issuedByUserId): RecTrainingCertificate`. Die Schulungsart kommt aus einer Konstante am Model (`RecTrainingCertificate::KIND_SERVICE_BASIS = 'service-basis'`), nicht von außen.
> 2. **Kein `resolveTemplate()`-Guard.** Der Spec-Ausschnitt unten fordert einen Filter `type='certificate'` gegen die Gegenrichtung „darf hier ein *Vertrag* als Zertifikat ausgestellt werden?". Diese Gegenrichtung existiert nicht mehr — es gibt keine Vorlagen-ID, die man verwechseln könnte. Der Guard entfällt, das Risiko mit ihm.
> 3. **`firstOrCreate` gegen `(rec_applicant_id, kind)`** statt gegen die Vorlagen-ID.
> 4. **Das neue Team-Setting `issue_training_certificates` (Default `false`) gated die Ausstellung.** Es wird an derselben Stelle geprüft wie `$canIssueCertificate`, also **vor** der Sichtbarkeit der Checkbox — nicht erst hier. Der Service prüft es zusätzlich, weil Weg (b) ihn ohne UI aufruft. Grund für das Setting: mit festem HTML gibt es kein `default_certificate_template_id` mehr, und ohne Ersatz wäre der einzige Weg, das Feature stillzulegen, ein Deploy.


**Files:**
- Create: `src/Models/RecTrainingCertificate.php`
- Create: `src/Services/IssueTrainingCertificateService.php`
- Test: `tests/Integration/IssueTrainingCertificateServiceTest.php`

**Interfaces:**
- Consumes: `RecContractTemplate::TYPE_CERTIFICATE`, `scopeCertificates()` (Task 3); Tabelle aus Task 2
- Produces: `IssueTrainingCertificateService::issue(RecApplicant $applicant, int $templateId, ?int $issuedByUserId): RecTrainingCertificate` — wirft `\InvalidArgumentException`, wenn die Vorlage nicht `type='certificate'` ist oder inaktiv; `firstOrCreate`-Semantik gegen das Unique-Constraint

**Spec-Ausschnitt (wörtlich):**

> `IssueTrainingCertificateService`, Vorlagen-Auflösung — **Gegenrichtung**: darf hier ein *Vertrag* als Zertifikat ausgestellt werden? Soll-Filter: **`type='certificate'`**.

> Nebeneffekt: Wird ein abgelehnter Bewerber später doch eingestellt, greift das Constraint und Weg (b) legt kein zweites Zertifikat derselben Vorlage an. Die Ausstellung behandelt diesen Fall als **Normalfall** (`firstOrCreate`-Semantik), nicht als Fehler.

> `personalized_content` (longText, Snapshot). **Der Hintergrund gehört NICHT in den Snapshot.** Er wird erst beim Rendern aufgelöst, exakt wie der Stempel. Andernfalls lägen ~550 KB Base64 **pro ausgestelltem Zertifikat** in `personalized_content`.

> `uuid` (unique, UuidV7 via `booted()` wie überall im Modul)

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Tests\Support\TestSchema;

class IssueTrainingCertificateServiceTest extends TestCase
{
    private const TEAM = 3;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        TestSchema::contractTemplates($capsule->schema());

        TestSchema::trainingCertificates($capsule->schema());
    }

    protected function setUp(): void
    {
        RecTrainingCertificate::query()->delete();
        RecContractTemplate::query()->forceDelete();
    }

    private function template(array $attrs): RecContractTemplate
    {
        return RecContractTemplate::create(array_merge([
            'name' => 'Zertifikat Service',
            'team_id' => self::TEAM,
            'code' => 'ZERT-SERVICE',
            'type' => 'certificate',
            'content' => '<div class="val">Snapshot</div>',
        ], $attrs));
    }

    public function testVertragsvorlageWirdAbgewiesen(): void
    {
        $tpl = $this->template(['code' => 'AV-010', 'type' => 'contract']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type=certificate/');

        (new \Platform\Recruiting\Services\IssueTrainingCertificateService())
            ->issueFromSnapshot(self::TEAM, 42, $tpl->id, '<p>x</p>', null);
    }

    public function testInaktiveVorlageWirdAbgewiesen(): void
    {
        $tpl = $this->template(['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);

        (new \Platform\Recruiting\Services\IssueTrainingCertificateService())
            ->issueFromSnapshot(self::TEAM, 42, $tpl->id, '<p>x</p>', null);
    }

    public function testAusstellungLegtZeileMitSnapshotUndUuidAn(): void
    {
        $tpl = $this->template([]);

        $cert = (new \Platform\Recruiting\Services\IssueTrainingCertificateService())
            ->issueFromSnapshot(self::TEAM, 42, $tpl->id, '<div class="val">ERIKA</div>', 7);

        $this->assertNotEmpty($cert->uuid);
        $this->assertSame('<div class="val">ERIKA</div>', $cert->personalized_content);
        $this->assertSame(7, $cert->issued_by_user_id);
        $this->assertNotNull($cert->issued_at);
        $this->assertNull($cert->wa_sent_at);
    }

    public function testZweiteAusstellungDerselbenVorlageIstNormalfall(): void
    {
        $tpl = $this->template([]);
        $svc = new \Platform\Recruiting\Services\IssueTrainingCertificateService();

        $first = $svc->issueFromSnapshot(self::TEAM, 42, $tpl->id, '<p>eins</p>', null);
        $second = $svc->issueFromSnapshot(self::TEAM, 42, $tpl->id, '<p>zwei</p>', null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RecTrainingCertificate::query()->count());
        // Snapshot der ersten Ausstellung bleibt stehen — ein Dokument, das
        // schon zugestellt wurde, darf sich nicht nachtraeglich aendern.
        $this->assertSame('<p>eins</p>', $second->personalized_content);
    }

    public function testAndereVorlageFuerDenselbenBewerberGehtDurch(): void
    {
        $a = $this->template(['code' => 'ZERT-SERVICE']);
        $b = $this->template(['code' => 'ZERT-KUECHE', 'name' => 'Zertifikat Kueche']);
        $svc = new \Platform\Recruiting\Services\IssueTrainingCertificateService();

        $svc->issueFromSnapshot(self::TEAM, 42, $a->id, '<p>a</p>', null);
        $svc->issueFromSnapshot(self::TEAM, 42, $b->id, '<p>b</p>', null);

        $this->assertSame(2, RecTrainingCertificate::query()->count());
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter IssueTrainingCertificateServiceTest`
Expected: FAIL — `Class "…RecTrainingCertificate" not found`

- [ ] **Step 3: Model schreiben**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein ausgestelltes Schulungszertifikat.
 *
 * Bewusst KEIN RecContract: eine Contract-Zeile wuerde hasAnyContractSent()
 * wahr machen (worauf die Versand-Guards des Nicht-EU-Umbaus aufsetzen) und
 * in Portal-, Employees-Show- und ZAS-Vertragslisten auftauchen.
 *
 * personalized_content ist der Snapshot des Vorlageninhalts nach der
 * Platzhalter-Ersetzung. Die Huelle (Layout, Schrift, Bilder) steckt NICHT
 * darin — sie wird beim Rendern aufgeloest, wie der Firmenstempel bei
 * Vertraegen. Sonst lagen ~550 KB Base64 pro Zertifikat in der DB.
 */
class RecTrainingCertificate extends Model
{
    protected $table = 'rec_training_certificates';

    protected $fillable = [
        'uuid',
        'team_id',
        'rec_applicant_id',
        'rec_contract_template_id',
        'personalized_content',
        'issued_at',
        'issued_by_user_id',
        'wa_sent_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'wa_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(RecContractTemplate::class, 'rec_contract_template_id');
    }
}
```

- [ ] **Step 4: Service schreiben**

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Tests\Support\TestSchema;

/**
 * Stellt ein Schulungszertifikat aus.
 *
 * Gegenrichtungs-Guard: hier wird geprueft, dass keine VERTRAGSvorlage als
 * Zertifikat ausgestellt wird. Die uebrigen type-Filter im Modul schuetzen
 * die andere Richtung (kein Zertifikat als Vertrag) — diese Stelle ist die
 * einzige, die den umgekehrten Fehlgriff abfangen kann.
 *
 * firstOrCreate-Semantik: das Unique-Constraint (rec_applicant_id,
 * rec_contract_template_id) ist eine Invariante, kein Fehlerfall. Wird ein
 * abgelehnter Bewerber spaeter doch eingestellt, laeuft die automatische
 * Ausstellung erneut an — und soll das bestehende Zertifikat zurueckgeben,
 * nicht abbrechen. Der Snapshot der ersten Ausstellung bleibt stehen: ein
 * bereits zugestelltes Dokument darf sich nicht nachtraeglich aendern.
 */
class IssueTrainingCertificateService
{
    /** Personalisiert und stellt aus. */
    public function issue(
        RecApplicant $applicant,
        int $templateId,
        ?int $issuedByUserId
    ): RecTrainingCertificate {
        $template = $this->resolveTemplate((int) $applicant->team_id, $templateId);

        return $this->issueFromSnapshot(
            (int) $applicant->team_id,
            (int) $applicant->id,
            $template->id,
            $template->personalizeContent($applicant),
            $issuedByUserId
        );
    }

    /**
     * Ausstellung mit fertigem Snapshot — getrennter Einstieg, damit die
     * Persistenz ohne Platzhalter-Engine testbar ist.
     */
    public function issueFromSnapshot(
        int $teamId,
        int $applicantId,
        int $templateId,
        string $personalizedContent,
        ?int $issuedByUserId
    ): RecTrainingCertificate {
        $this->resolveTemplate($teamId, $templateId);

        $existing = RecTrainingCertificate::where('rec_applicant_id', $applicantId)
            ->where('rec_contract_template_id', $templateId)
            ->first();

        if ($existing) {
            return $existing;
        }

        return RecTrainingCertificate::create([
            'team_id' => $teamId,
            'rec_applicant_id' => $applicantId,
            'rec_contract_template_id' => $templateId,
            'personalized_content' => $personalizedContent,
            'issued_at' => now(),
            'issued_by_user_id' => $issuedByUserId,
        ]);
    }

    private function resolveTemplate(int $teamId, int $templateId): RecContractTemplate
    {
        $template = RecContractTemplate::where('team_id', $teamId)
            ->where('id', $templateId)
            ->certificates()
            ->where('is_active', true)
            ->first();

        if (!$template) {
            throw new \InvalidArgumentException(
                "Vorlage #{$templateId} ist keine aktive Zertifikat-Vorlage "
                . "(type=certificate) im Team #{$teamId}."
            );
        }

        return $template;
    }
}
```

- [ ] **Step 5: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter IssueTrainingCertificateServiceTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecTrainingCertificate.php src/Services/IssueTrainingCertificateService.php \
        tests/Integration/IssueTrainingCertificateServiceTest.php
git commit -m "feat(recruiting): Zertifikat-Ablage und Ausstellungs-Service"
```

---

### Task 9: Render-Test — Erstnachweis, dass das PDF stimmt

**Files:**
- Test: `tests/Integration/TrainingCertificateRenderTest.php`

**Interfaces:**
- Consumes: `TrainingCertificateHtml::build()` (Task 6), `TrainingCertificatePdfOptions::for()` (Task 5), `TrainingCertificateAssets::resolve()` (Task 5a), **`FontGlyphCoverage::inspect()` (Task 4a — `missing()` existiert nicht mehr)**, `ResttagePlaceholder::hasUnresolvedPlaceholder()` (Bestand, `src/Support/ResttagePlaceholder.php:88-91`), Assets-Dateien aus Task 4
- Produces: nichts — reiner Nachweis

**Spec-Ausschnitt (wörtlich):**

> **Render-Test (Erstnachweis, nicht Absicherung — der Prototyp ist kein Code).** Läuft als Integrationstest mit `Dompdf\Dompdf` direkt und **den Optionen aus `TrainingCertificatePdfOptions`**, nicht mit selbst gesetzten:
> 1. **Genau eine Seite** — `preg_match_all('/\/Type\s*\/Page[^s]/')` auf `$dompdf->output()`, auch mit Worst-Case-Inhalt: langer Doppelname, zwei Interviewer, längste Kursbezeichnung.
> 2. **Die Schrift ist eingebettet** — `preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/')` enthält `Oswald-SemiBold`.
> 3. **Keine fehlenden Glyphen** — `FontGlyphCoverage::missing()` auf dem personalisierten Inhalt ist leer.
> 4. **Keine unaufgelösten Platzhalter** — `ResttagePlaceholder::hasUnresolvedPlaceholder()` auf dem personalisierten Inhalt ist `false`.

> **Mechanik-Auflage: keine `grep`- und keine Literal-String-Assertions.** `grep -c "/Type /Page"` und `grep -c "/BaseFont"` liefern auf einem DomPDF-PDF je 0 Treffer. Wer so assertiert, baut einen Test, der immer grün ist.

**Drei Nachträge zu Kriterium 1 und 3:**

**Zu 3 — die API heißt `inspect()`, nicht `missing()`.** Der Spec-Ausschnitt oben ist der Stand vor Task 4a. `FontGlyphCoverage::missing()` existiert nicht mehr; `inspect()` liefert ein `FontGlyphReport` mit drei getrennten Zuständen. Kriterium 3 lautet damit: `checkable === true` **und** `missing === []`. Nur `missing === []` zu prüfen wäre der alte Fehler in neuer Form — es wäre auch bei einer unlesbaren Schrift erfüllt, und der Test hätte dann nur belegt, dass die Schrift kaputt ist.

**Zu 1 — der Worst Case ist die Listenlänge, nicht die Namenslänge.** Der Spec-Ausschnitt nennt „langer Doppelname, zwei Interviewer, längste Kursbezeichnung". Gemessen bricht keine dieser Dimensionen um; die Kenntnisliste tut es: 4 → 1 Seite, 10 → 1 Seite, **20 → 2 Seiten**. Der Prototyp hatte genau die sechs Zeilen der Originalvorlage, deshalb fiel das dort nicht auf. Beide Dimensionen testen, und die Listenlänge **mit Negativkontrolle** — eine Seitenzahl-Assertion ohne einen Fall, der wirklich zwei Seiten erzeugt, belegt nicht, dass sie auslösen kann.

**Zu 3 — die Glyph-Prüfung läuft auf dem rohen Vorlageninhalt, NIE auf der Ausgabe von `TrainingCertificateHtml::build()`.** Grund, gemessen: `FontGlyphCoverage` benutzt `strip_tags()`, und `strip_tags()` entfernt den `<style>`-Tag, **nicht dessen Inhalt**. Die Hülle hat einen CSS-Kommentar mit einem `★` darin. Ergebnis bei einem Inhalt ohne jeden Stern:

```
Inhalt allein:  array ()
Huelle+Inhalt:  array ( 0 => '★' )
```

Wer die Prüfung auf die zusammengebaute Hülle richtet, bekommt also eine **Phantom-Meldung** „★ fehlt in Oswald" für ein Zertifikat, in dem kein Stern vorkommt — und wird sie plausibel finden, weil die Aussage über Oswald ja stimmt. Für DomPDF ist der Kommentar harmlos (bewiesen: die entpackten Content-Streams sind bitgleich, ob der Kommentar da ist, fehlt, oder das `★` durch ASCII ersetzt wird), für den eigenen Glyph-Wächter ist er es nicht. Gilt genauso für den Editor-Knopf in Task 13.

**Gemessene Referenz aus dem Prototyp** (`/Users/shaustein/Documents/dev/docs/zertifikat/mockups/prototyp/render_live.php` — **absoluter Pfad, außerhalb des Repos**, siehe Hinweis in Task 6; mit `isRemoteEnabled=false`): 315 802 Bytes, 1 Seite, `SUBAAB+Oswald-SemiBold` + `SUBAAC+DejaVuSans`, 6 Bildobjekte.

**Was Assertion 2 tatsächlich abdeckt — gemessen, nicht erschlossen.** Ein beschädigter Font kommt in mehreren Abstufungen vor, und die drei Wächter des Pakets reagieren unterschiedlich. Gemessen gegen die echte `Oswald-SemiBold.ttf` (109 120 Byte), Prüftext `STEHEMPFANG ★`:

| Zustand der Datei | `TrainingCertificateAssets::resolve()` | `FontGlyphCoverage::missing()` | `/BaseFont` im PDF |
|---|---|---|---|
| intakt (109 120 B) | schweigt | meldet `★` | `Oswald-SemiBold` |
| abgeschnitten 40 % (43 648 B) | schweigt | meldet `★` | **`Helvetica`** |
| abgeschnitten 5 % (5 456 B) | schweigt | meldet `★` | **`Helvetica`** |
| 3 Byte | schweigt | **schweigt (= „nichts fehlt")** | **`Helvetica`** |
| 0 Byte | meldet | **schweigt (= „nichts fehlt")** | **`Helvetica`** |

Daraus zwei verbindliche Folgerungen: **(a)** Assertion 2 ist der einzige Wächter, der jede Beschädigungsstufe rot macht — sie aufzuweichen nimmt dem Paket den einzigen wirksamen Schutz gegen stilles Helvetica. **(b)** Assertion 3 (`FontGlyphCoverage`) ist **kein** Schutz gegen einen kaputten Font: bei unparsbarer Datei liefert sie `[]`, was „nichts fehlt" bedeutet — eine kaputte Schrift bekommt dort ein besseres Zeugnis als eine intakte. Sie prüft Zeichenabdeckung, nichts sonst. Wer Assertion 2 mit Verweis auf Assertion 3 für redundant hält, irrt.

**Haltung bei Fehlschlag — verbindlich:** Dieser Task ist ein **Erstnachweis**, keine Absicherung. Der Prototyp ist kein Code; bis hier grün ist, ist nicht belegt, dass die eine Seite und die eingebettete Schrift auch aus dem gebauten Pfad herauskommen. Schlägt eine Assertion fehl, ist das ein **Befund über den gebauten Pfad** — kein Anlass, die Erwartung anzupassen. Also: nicht die Seitenzahl-Erwartung auf 2 setzen, nicht die Font-Assertion aufweichen, nicht den Glyph-Test überspringen. Ursache im Pfad suchen (meist `chroot`, Asset-Pfad oder ein Abstand im Fließteil) und dort beheben.

- [ ] **Step 1: Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FontGlyphCoverage;
use Platform\Recruiting\Support\ResttagePlaceholder;
use Platform\Recruiting\Support\TrainingCertificateAssets;
use Platform\Recruiting\Support\TrainingCertificateHtml;
use Platform\Recruiting\Support\TrainingCertificatePdfOptions;

/**
 * Erstnachweis, dass das Zertifikat-PDF stimmt.
 *
 * Nutzt bewusst DIESELBEN Optionen wie der Controller
 * (TrainingCertificatePdfOptions) — mit selbst gesetzten Optionen wuerde der
 * Test eine andere Engine pruefen als die ausgelieferte und waere gruen ohne
 * Aussage.
 *
 * Assertions per PCRE mit \s*: grep und Literalvergleiche finden die
 * PDF-Marker NICHT (Marker ueber Zeilenumbruch verteilt).
 */
class TrainingCertificateRenderTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    /** Derselbe Resolver, den Controller und Editor-Vorschau benutzen. */
    private function assets(): array
    {
        return TrainingCertificateAssets::resolve(
            (string) realpath(self::MODULE_ROOT . '/resources')
        );
    }

    private function fontPath(): string
    {
        return $this->assets()['font'];
    }

    /** Inhalt wie ihn der Seed-Command anlegt, nach Platzhalter-Ersetzung. */
    private function content(string $name, string $leiter, string $kurs): string
    {
        $stern = '<span class="zeichen">&#9733;</span>';
        $skills = '';
        foreach ([
            'Fachgerechte Tellerschulung 2-er Obergriff',
            'Stehempfang' . $stern . 'Flying Buffet',
            'Buffetservice',
            '3-Gang-Menü fachgerecht eindecken',
            'Weinservice',
            'Gästebetreuung und Kommunikation',
        ] as $skill) {
            $skills .= '<div class="skill">' . $stern . '<span>' . $skill . '</span>' . $stern . '</div>';
        }

        return '<div class="lab">Herr / Frau</div>'
            . '<div class="val">' . $name . '</div>'
            . '<div class="lab">hat am Kurs</div>'
            . '<div class="kurs">' . $kurs . '</div>'
            . '<div class="lab">am</div>'
            . '<div class="val">24.07.2026</div>'
            . '<div class="lab">mit Erfolg teilgenommen.</div>'
            . '<div class="intro">Bei der Schulung wurden folgende Grundkenntnisse vermittelt:</div>'
            . $skills
            . '<div class="zert-datum">Düsseldorf, den 05.08.2026</div>'
            . '<div class="zert-fuss-rechts"><div class="leiter">' . $leiter . '</div>'
            . '<div class="linie"></div><div class="cap">Schulungsleiter</div></div>';
    }

    private function render(string $content): string
    {
        $assets = $this->assets();
        $html = TrainingCertificateHtml::build($content, $assets);

        $options = new Options();
        foreach (TrainingCertificatePdfOptions::for($assets['font'], (string) realpath(self::MODULE_ROOT)) as $k => $v) {
            $options->set($k, $v);
        }
        $fontCache = sys_get_temp_dir() . '/zert-fontcache-' . getmypid();
        @mkdir($fontCache, 0777, true);
        $options->set('fontDir', $fontCache);
        $options->set('fontCache', $fontCache);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function pageCount(string $pdf): int
    {
        preg_match_all('/\/Type\s*\/Page[^s]/', $pdf, $m);

        return count($m[0]);
    }

    /** @return list<string> */
    private function baseFonts(string $pdf): array
    {
        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/', $pdf, $m);

        return array_values(array_unique($m[1]));
    }

    public function testAlleAssetsSindImRepoVorhanden(): void
    {
        // Faellt hier etwas auf, fehlt es auch bei der Ausstellung — derselbe
        // Resolver.
        $this->assertSame([], $this->assets()['missing']);
    }

    public function testNormalfallIstEineSeiteMitEingebetteterSchrift(): void
    {
        $pdf = $this->render($this->content('Erika Mustermann', 'Michel Zimmer', 'Service-Basisschulung'));

        $this->assertSame(1, $this->pageCount($pdf));
        $this->assertContains(
            'Oswald-SemiBold',
            array_map(fn (string $f) => preg_replace('/^[A-Z]+\+/', '', $f), $this->baseFonts($pdf))
        );
    }

    public function testWorstCaseBleibtEineSeite(): void
    {
        $pdf = $this->render($this->content(
            'Maximiliane Charlotte von Hohenberg-Lichtenstein',
            'Michel Zimmer, Anna Bergmann',
            'Service-Basisschulung für Großveranstaltungen'
        ));

        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * Die Listenlaenge ist die Dimension, die tatsaechlich umbricht — nicht die
     * Namenslaenge. Gemessen: 4 Zeilen 1 Seite, 10 Zeilen 1 Seite, 20 Zeilen
     * 2 Seiten. Dieser Test nagelt die obere Grenze fest, die noch traegt.
     * Er belegt zugleich, dass die Fuss-Verankerung die Einzelseitigkeit NICHT
     * strukturell erzwingt — die urspruengliche Spec-Behauptung war falsch.
     */
    public function testZwoelfKenntnisZeilenBleibenEineSeite(): void
    {
        $pdf = $this->render($this->contentMitKenntnisZeilen(12));

        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * Negativkontrolle, ohne die der Test darueber wertlos ist: die
     * Seitenzahl-Assertion muss ueberhaupt ausloesen koennen. Wuerde
     * pageCount() immer 1 liefern (falsches Muster, siehe Mechanik-Auflage),
     * bliebe dieser Test gruen und der darueber ebenfalls.
     */
    public function testZuVieleKenntnisZeilenErzeugenEineZweiteSeite(): void
    {
        $pdf = $this->render($this->contentMitKenntnisZeilen(24));

        $this->assertGreaterThan(1, $this->pageCount($pdf));
    }

    public function testKeineFehlendenGlyphenImInhalt(): void
    {
        // Die ★ stehen in <span class="zeichen"> und laufen damit in DejaVu.
        // Geprueft wird der Rest gegen Oswald.
        $content = $this->content('Erika Mustermann', 'Michel Zimmer', 'Service-Basisschulung');
        $ohneZeichenSpans = preg_replace('#<span class="zeichen">.*?</span>#', '', $content);

        // Task 4a: inspect() statt missing(). Beide Hälften prüfen — checkable
        // muss true sein (sonst hat der Test nur belegt, dass die Schrift
        // unlesbar ist) und missing leer.
        $report = FontGlyphCoverage::inspect($ohneZeichenSpans, $this->fontPath());

        $this->assertTrue($report->checkable, 'Die echte Schrift muss prüfbar sein.');
        $this->assertSame([], $report->missing);
        $this->assertFalse($report->hasWarning());
    }

    public function testKeineUnaufgeloestenPlatzhalter(): void
    {
        $content = $this->content('Erika Mustermann', 'Michel Zimmer', 'Service-Basisschulung');

        $this->assertFalse(ResttagePlaceholder::hasUnresolvedPlaceholder($content));
    }

    public function testEinUebriggebliebenerPlatzhalterWirdErkannt(): void
    {
        // Negativkontrolle: der Guard muss ausloesen, sonst ist Assertion 4
        // wertlos.
        $this->assertTrue(
            ResttagePlaceholder::hasUnresolvedPlaceholder('<div class="val">{{kontakt_vorname}}</div>')
        );
    }

    // ---------------------------------------------------------------------
    // Drei Eigenschaften, die aus dem Task-6-Review hierher verschoben wurden.
    // In Task 6 waeren sie Assertions auf einen String gewesen und dort als
    // "unassertierte Geometrie" geparkt; hier sind sie Assertions auf ein
    // wirklich gerendertes PDF. Gemessen war jeweils, dass eine kaputte
    // Ausgabe die Suite gruen liess: .zert-logo von 40mm auf 400mm, eine leere
    // p-Regel, und Inhalt vor Logo/Headline emittiert.
    // ---------------------------------------------------------------------

    public function testGeometrieDerBilderStimmt(): void
    {
        // Logo 40mm, Headline 116mm, Signatur 54mm bei 96 dpi. Geprueft wird
        // die BREITE der platzierten Bildobjekte im PDF, nicht der CSS-String —
        // eine Assertion auf "width: 40mm" im HTML haette 400mm nicht gefangen,
        // weil DomPDF den Wert erst beim Rendern anwendet.
        // Die Toleranz ist bewusst grob (DomPDF rundet auf Punkte); sie muss
        // eng genug bleiben, dass ein Faktor 10 auffaellt.
        $pdf = $this->render($this->content('Erika Mustermann', 'Michel Zimmer', 'Service-Basisschulung'));

        $breiten = $this->bildBreitenInMm($pdf);   // aus dem /Width je XObject + der cm-Matrix
        sort($breiten);

        $this->assertCount(3, $breiten, 'Es muessen genau drei Bilder platziert sein.');
        $this->assertEqualsWithDelta(40.0, $breiten[0], 1.5, 'Logo');
        $this->assertEqualsWithDelta(54.0, $breiten[1], 1.5, 'Unterschriftsblock');
        $this->assertEqualsWithDelta(116.0, $breiten[2], 1.5, 'Headline');
    }

    public function testBasisStylesWirkenUndSindNichtNurDeklariert(): void
    {
        // Ein nackter <p> muss anders aussehen als der Standard: zentriert,
        // Grundschrift, eigener Abstand. Geprueft ueber die Y-Position der
        // Textzeilen — eine leere Regel `p { }` laesst die Suite sonst gruen
        // (in Task 6 gemessen), weil der Test nur die EXISTENZ des Selektors
        // pruefte.
        $mitAbstand = $this->render($this->content('A', 'B', 'C') . '<p>Erste Zeile</p><p>Zweite Zeile</p>');

        $abstaende = $this->zeilenAbstaendeInPunkten($mitAbstand);

        $this->assertNotEmpty($abstaende);
        $this->assertGreaterThan(
            12.0,
            max($abstaende),
            'Die p-Regel setzt margin 3mm; ohne wirksame Regel liegen die Zeilen dichter.'
        );
    }

    public function testLogoUndHeadlineStehenVorDemInhalt(): void
    {
        // Reihenfolge im Fluss, nicht im Quelltext: Logo und Headline gehoeren
        // nach OBEN. Ein Tausch von Inhalt und Bildern liess die Suite in
        // Task 6 gruen, weil dort nur geprueft wurde, DASS beide vorkommen.
        // Geprueft wird die Y-Position: im PDF-Koordinatensystem hat oben den
        // GROESSEREN Wert.
        $pdf = $this->render($this->content('Erika Mustermann', 'Michel Zimmer', 'Service-Basisschulung'));

        $this->assertGreaterThan(
            $this->obersteTextZeileY($pdf),
            $this->obersteBildY($pdf),
            'Logo/Headline muessen ueber der ersten Textzeile liegen.'
        );
    }
}
```

**Zu den drei verschobenen Eigenschaften — Auflage an den Implementierer:** die drei Helfer (`bildBreitenInMm()`, `zeilenAbstaendeInPunkten()`, `obersteBildY()`/`obersteTextZeileY()`) sind hier **nicht** ausgeschrieben, weil ihre Form von der tatsächlichen DomPDF-Ausgabe abhängt und ich sie nicht geraten haben will. Bau sie gegen ein echtes PDF und **halte die Rohausgabe im Report fest** (die relevanten Content-Stream-Zeilen), damit nachvollziehbar ist, woraus die Zahlen kommen. Es gilt die Mechanik-Auflage von oben: kein `grep`, kein Literal-Match, nur whitespace-toleranter PCRE — und **jede** der drei Assertions braucht die Mutation, die sie aushebelt (400mm statt 40mm, leere `p`-Regel, getauschte Reihenfolge). Bringt eine Mutation kein Rot, ist die Assertion falsch gebaut und **nicht** der Befund erledigt.

- [ ] **Step 2: Test laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TrainingCertificateRenderTest`
Expected: PASS (11 tests) — 6 aus der Spec-Fassung, zwei zur Listenlaenge (mit Negativkontrolle) und drei aus dem Task-6-Review verschobene. Bei `testNormalfall…` FAIL mit fehlendem `Oswald-SemiBold`: der `chroot` greift nicht — `TrainingCertificatePdfOptions::for()` prüfen, der Font-Pfad muss unterhalb des übergebenen chroot liegen. Bei `testAlleAssetsSindImRepoVorhanden` FAIL: Task 4 Step 3 wurde nicht ausgeführt oder eine Datei fehlt im Repo.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/TrainingCertificateRenderTest.php
git commit -m "test(recruiting): Render-Nachweis Zertifikat-PDF (Seite, Schrift, Glyphen, Platzhalter)"
```

---

### Task 10: PDF-Controller + Public-Route

**Files:**
- Create: `src/Http/Controllers/TrainingCertificatePdfController.php`
- Modify: `routes/public.php`

**Interfaces:**
- Consumes: `RecTrainingCertificate` (Task 8), `TrainingCertificateHtml` (Task 6), `TrainingCertificatePdfOptions` (Task 5), `TrainingCertificateAssets::resolve()` (Task 5a)
- Produces: benannte Route `recruiting.public.training-certificate` mit Pfad `/zertifikat/{uuid}`

**Spec-Ausschnitt (wörtlich):**

> **Route `/recruiting/zertifikat/{uuid}`**, aufgelöst über `rec_training_certificates.uuid`. Neu in `routes/public.php`, damit unter `web` + `NoCacheHeaders`.

> **Nicht über den Applicant-Token.** Der Applicant-Token öffnet auch Bewerbungsformular und `contract-pdf`, unbegrenzt und ohne Rotation. Ihn per WhatsApp **aktiv erneut** zu versenden ist eine neu geöffnete Tür in eine bestehende Lücke — der Trostpreis soll das Zertifikat zustellen, nicht den Generalschlüssel.

> **Kein Status-Guard im Controller** — bewusst: der abgelehnte, inaktive Bewerber ist der Normalfall dieses Dokuments. Geprüft wird nur die Existenz der Zertifikat-Zeile.

> Der Controller ruft danach `Pdf::loadHTML(...)`, überträgt die Optionen aus der Klasse und liefert per **`->stream()`** aus, nicht `->download()`: der WhatsApp-Link soll das PDF anzeigen, nicht einen Download erzwingen.

> Fehlt ein Bild, rendert das PDF ohne dieses Element (`null` statt Fehler); fehlt die Schrift, läuft alles in Helvetica. Beides ist kein Absturz und beides ist falsch — deshalb loggt **der aufrufende Controller** jedes fehlende Asset als `warning`; die Hülle selbst bleibt laravel-frei und lässt fehlende Bilder still weg.

- [ ] **Step 1: Controller schreiben**

```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Support\TrainingCertificateAssets;
use Platform\Recruiting\Support\TrainingCertificateHtml;
use Platform\Recruiting\Support\TrainingCertificatePdfOptions;

/**
 * Liefert ein ausgestelltes Schulungszertifikat als PDF.
 *
 * Kein Status-Guard: der abgelehnte, inaktive Bewerber ist der Normalfall
 * dieses Dokuments. Geprueft wird nur, dass die Zertifikat-Zeile existiert.
 *
 * Adressiert wird ueber die Zertifikat-uuid, NICHT ueber den Applicant-Token:
 * der oeffnet auch Bewerbungsformular und Vertrags-PDFs, unbegrenzt und ohne
 * Rotation. Die uuid oeffnet genau ein Dokument.
 *
 * stream() statt download(): der Link kommt per WhatsApp, das PDF soll
 * angezeigt werden und nicht als Datei im Downloadordner landen.
 */
class TrainingCertificatePdfController extends Controller
{
    public function __invoke(string $uuid)
    {
        $certificate = RecTrainingCertificate::where('uuid', $uuid)
            ->with('contractTemplate')
            ->firstOrFail();

        $assets = TrainingCertificateAssets::resolve(
            (string) realpath(__DIR__ . '/../../../resources')
        );

        // Fehlende Assets sind kein Absturz, aber auch nicht harmlos: das PDF
        // kaeme ohne Logo/Headline/Unterschrift raus, oder — bei fehlender
        // Schrift — komplett in Helvetica. Der Resolver bleibt laravel-frei,
        // das Loggen ist deshalb Sache dieses Controllers.
        if ($assets['missing'] !== []) {
            Log::warning('[TrainingCertificatePdfController] Assets fehlen', [
                'certificate_uuid' => $uuid,
                'missing' => $assets['missing'],
            ]);
        }

        $html = TrainingCertificateHtml::build($certificate->personalized_content ?? '', $assets);

        $pdf = Pdf::loadHTML($html);
        foreach (TrainingCertificatePdfOptions::for($assets['font'], (string) realpath(base_path())) as $key => $value) {
            $pdf->setOption($key, $value);
        }

        return $pdf->setPaper('a4')->stream('zertifikat.pdf');
    }
}
```

- [ ] **Step 2: Route ergänzen**

Am Ende von `routes/public.php`:

```php
// Schulungszertifikat-PDF (public, ueber die Zertifikat-uuid).
// Bewusst nicht ueber den Applicant-Token: der oeffnet auch
// Bewerbungsformular und Vertrags-PDFs.
Route::get('/zertifikat/{uuid}', [\Platform\Recruiting\Http\Controllers\TrainingCertificatePdfController::class, '__invoke'])
    ->name('recruiting.public.training-certificate');
```

- [ ] **Step 3: Syntax prüfen**

Run: `php -l src/Http/Controllers/TrainingCertificatePdfController.php && php -l routes/public.php`
Expected: beide `No syntax errors detected`

- [ ] **Step 4: Route-Registrierung prüfen**

Run: `grep -n "training-certificate" routes/public.php`
Expected: eine Zeile mit dem Routennamen

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/TrainingCertificatePdfController.php routes/public.php
git commit -m "feat(recruiting): Public-Route und Controller fuer Zertifikat-PDF"
```

---

### Task 11: Ausstellung im Ablehnen-Zweig des HR-Schreibtischs

> **GEÄNDERT durch den Zuschnitt v3.** Die Checkbox bleibt, das **Vorlagen-Dropdown entfällt** — samt der Logik „bei genau einer Vorlage vorausgewählt", die diesen Normalfall kaschieren sollte. `$certificateTemplateId` verschwindet aus der Komponente, `rejectCase()` bekommt einen `bool` statt einer `?int`. Neu: `$canIssueCertificate` prüft zusätzlich das Team-Setting `issue_training_certificates` (Default `false`) — ist es aus, erscheint die Checkbox nicht.
>
> **VERHALTEN, vom Auftraggeber ausdrücklich bestätigt statt nur aus der Spec übernommen — bindend:**
>
> - Die Zertifikat-Checkbox ist **optional und NICHT vorausgewählt.** HR setzt sie bewusst.
> - Sichtbar **nur bei vorhandener `attended`-Buchung.**
> - Gilt für **jeden Ablehnungsgrund**, keine Einschränkung auf bestimmte Gründe. Der konkrete Anlass ist heute die Nicht-EU-Ablehnung, **aber wir legen uns nicht auf eine Grundliste fest** — also keine `in_array($reason, [...])`-Bedingung, auch nicht „vorerst".
> - **Ohne Haken läuft die Ablehnung exakt wie heute:** kein Zertifikat, kein Versand, kein zusätzlicher Query, kein veränderter Ablauf.
>
> **KOPPLUNG AN TASK 12 — Bedingung, nicht Kommentar.** Der Modal-Hinweis nennt den „Zertifikat-Link", und der wird erst mit **Task 12** (WhatsApp-Zustellung) real. Das ist bewusst so, **weil 11 und 12 zusammen ausgeliefert werden.** Wird Task 11 je **allein** deployed — oder Task 12 aus dem Paket herausgeschnitten —, ist der Halbsatz zu streichen, sonst verspricht die UI HR eine Nachricht, die nicht rausgeht. Wer den Zuschnitt hier ändert, prüft `resources/views/livewire/hr-desk/index.blade.php` auf „Zertifikat-Link".
>
> Der letzte Punkt ist der, der einen Test braucht und nicht nur eine Zusicherung: **die Ablehnung ohne Haken muss nachweisbar unverändert sein.** Das ist die Fehlerklasse dieses Pakets in ihrer teuersten Form — ein Eingriff in `rejectCase()`, der im Normalfall etwas verschiebt, fällt niemandem auf, weil die Ablehnung ja funktioniert.


**Files:**
- Modify: `src/Services/HrDeskRoutingService.php` (`rejectCase` `:276-283`, `applyRejection` `:285`)
- Modify: `src/Livewire/HrDesk/Index.php`
- Modify: `resources/views/livewire/hr-desk/index.blade.php` (Resolve-Modal `:333-378`)
- Test: `tests/Unit/CertificateIssuanceEligibilityTest.php`

**Interfaces:**
- Consumes: `IssueTrainingCertificateService::issue()` (Task 8), `RecContractTemplate::scopeCertificates()` (Task 3)
- Produces: `CertificateIssuanceEligibility::isAvailable(bool $hasAttendedBooking, bool $templateExists, bool $alreadyIssued): bool`

**Spec-Ausschnitt (wörtlich):**

> **Weg (a): Ablehnung am HR-Schreibtisch.** Checkbox „☑ Teilnahme-Zertifikat ausstellen" im Ablehnen-Zweig des Resolve-Modals, sichtbar nur bei vorhandener `attended`-Buchung (bestehendes Batch-Muster `attendedApplicantIds()`), Vorlagen-Dropdown über die aktiven `certificate`-Vorlagen, bei genau einer vorausgewählt. Gilt für jeden Fall-Grund, nicht nur `insufficient_documents`.

> **Platzierung und Kollisionsfreiheit sind geprüft:** die Checkbox gehört direkt neben `sendRejectionMessage` (`hr-desk/index.blade.php:349-360`), im gleichen `$resolvingAction === 'reject'`-Gate, mit einem bei Modal-Öffnung berechneten `$canIssueCertificate` analog zu `$canSendRejectionMessage`. Der `AT-*`-Select liegt auf der Karte (`:171-186`), nicht im Modal, und hat eigenen State — keine UI- und keine Validierungskollision.

> **Zwei Sends bei einer Ablehnung sind möglich.** Sind beide Checkboxen gesetzt, gehen zwei separate Template-Sends raus (Jugendschutz-Absage und Zertifikat-Link) — verschiedene Settings-Keys, verschiedene Meta-Templates. Sie werden **nicht** zusammengelegt. Die UI weist darauf hin, wenn beide angehakt sind.

> Eingehängt **innerhalb** der bestehenden Transaktion von `rejectCase()` — als vierter Schritt in `applyRejection()` oder als Parameter an `rejectCase()`. Alles oder nichts: keine Ablehnung ohne Zertifikat, kein Zertifikat ohne Ablehnung. Der WhatsApp-Versand läuft **nach dem Commit**.

- [ ] **Step 1: Failing test für die Freigabe-Regel schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CertificateIssuanceEligibility;

class CertificateIssuanceEligibilityTest extends TestCase
{
    public function testAlleBedingungenErfuellt(): void
    {
        $this->assertTrue(CertificateIssuanceEligibility::isAvailable(true, true, false));
    }

    public function testOhneAttendedBuchungNicht(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(false, true, false));
    }

    public function testOhneVorlageNicht(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(true, false, false));
    }

    public function testBereitsAusgestelltNicht(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(true, true, true));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CertificateIssuanceEligibilityTest`
Expected: FAIL — `Class "…CertificateIssuanceEligibility" not found`

- [ ] **Step 3: Freigabe-Regel schreiben**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Darf am HR-Schreibtisch ein Zertifikat ausgestellt werden?
 *
 * Kriterium ist die attended-Buchung, NICHT der Fall-Grund: auch ein
 * no_german_knowledge-Fall hat an der Schulung teilgenommen.
 */
final class CertificateIssuanceEligibility
{
    public static function isAvailable(
        bool $hasAttendedBooking,
        bool $templateExists,
        bool $alreadyIssued
    ): bool {
        return $hasAttendedBooking && $templateExists && !$alreadyIssued;
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün bestätigen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CertificateIssuanceEligibilityTest`
Expected: PASS (4 tests)

- [ ] **Step 5: `applyRejection` um die Ausstellung erweitern**

In `src/Services/HrDeskRoutingService.php`: `rejectCase()` bekommt zwei optionale Parameter, `applyRejection()` ebenfalls, und stellt am Ende aus:

```php
    public function rejectCase(
        RecHrDeskCase $case,
        int $userId,
        ?string $notes = null,
        ?int $certificateTemplateId = null
    ): void {
        // Fall-Abschluss, Bewerber-Update und Zertifikat gehoeren zusammen:
        // scheitert die Ausstellung, darf der Fall nicht geschlossen
        // zurueckbleiben — und umgekehrt.
        DB::transaction(function () use ($case, $userId, $notes, $certificateTemplateId) {
            $this->applyRejection($case, $userId, $notes, $certificateTemplateId);
        });
    }
```

Am Ende von `applyRejection()`, nach dem bestehenden Bewerber-Update:

```php
        if ($certificateTemplateId !== null && $applicant) {
            app(IssueTrainingCertificateService::class)
                ->issue($applicant, $certificateTemplateId, $userId);
        }
```

Import ergänzen: `use Platform\Recruiting\Services\IssueTrainingCertificateService;`

- [ ] **Step 6: Livewire-Komponente erweitern**

In `src/Livewire/HrDesk/Index.php`:

```php
    /** Zertifikat-Ausstellung im Ablehnen-Zweig */
    public bool $issueCertificate = false;
    public bool $canIssueCertificate = false;
    public ?int $certificateTemplateId = null;
```

Beim Öffnen des Resolve-Modals, direkt neben der Berechnung von `$canSendRejectionMessage`:

```php
            $certificateTemplates = $this->availableCertificateTemplates;
            $this->canIssueCertificate = CertificateIssuanceEligibility::isAvailable(
                in_array($applicant->id, $this->attendedApplicantIds(), true),
                $certificateTemplates->isNotEmpty(),
                RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->exists()
            );
            $this->certificateTemplateId = $certificateTemplates->count() === 1
                ? (int) $certificateTemplates->first()->id
                : null;
            $this->issueCertificate = false;
```

Neue Computed-Property:

```php
    /** Aktive Zertifikat-Vorlagen des Teams (Gegenrichtungs-Filter: type=certificate). */
    #[Computed]
    public function availableCertificateTemplates()
    {
        return RecContractTemplate::where('team_id', (int) Auth::user()->currentTeam->id)
            ->certificates()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
```

In `confirmResolve()` im `reject`-Zweig den Aufruf erweitern:

```php
                $templateId = ($this->issueCertificate && $this->canIssueCertificate)
                    ? $this->certificateTemplateId
                    : null;

                app(HrDeskRoutingService::class)
                    ->rejectCase($case, $userId, $this->resolveNotes, $templateId);
```

- [ ] **Step 7: Blade erweitern**

In `resources/views/livewire/hr-desk/index.blade.php`, direkt **nach** dem `sendRejectionMessage`-Block (endet auf `:360`):

```blade
                @if($resolvingAction === 'reject' && $canIssueCertificate)
                    <div class="p-3 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox"
                                   wire:model.live="issueCertificate"
                                   class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                            <div>
                                <span class="text-sm font-medium text-[var(--ui-secondary)]">Teilnahme-Zertifikat ausstellen</span>
                                <p class="text-xs text-[var(--ui-muted)] mt-0.5">Der Bewerber hat an der Schulung teilgenommen — das Zertifikat bleibt ihm als Nachweis.</p>
                            </div>
                        </label>
                        @if($issueCertificate && $this->availableCertificateTemplates->count() > 1)
                            <select wire:model="certificateTemplateId"
                                    class="mt-3 w-full text-sm border border-[var(--ui-border)] rounded-md px-2 py-1.5 bg-[var(--ui-surface)]">
                                @foreach($this->availableCertificateTemplates as $tpl)
                                    <option value="{{ $tpl->id }}">{{ $tpl->code }} — {{ $tpl->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if($issueCertificate && $sendRejectionMessage)
                            <p class="mt-3 text-xs text-amber-800">
                                Es gehen zwei WhatsApp-Nachrichten raus: die Absage und der Zertifikat-Link.
                            </p>
                        @endif
                    </div>
                @endif
```

- [ ] **Step 8: Blade und Syntax prüfen**

Run: `php tools/blade-check.php resources/views/livewire/hr-desk/index.blade.php && php -l src/Livewire/HrDesk/Index.php && php -l src/Services/HrDeskRoutingService.php`
Expected: alle drei ohne Fehler. (`php -l` auf `.blade.php` prüft nichts — dafür ist `tools/blade-check.php` da.)

- [ ] **Step 9: Gesamtsuite**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add src/Support/CertificateIssuanceEligibility.php tests/Unit/CertificateIssuanceEligibilityTest.php \
        src/Services/HrDeskRoutingService.php src/Livewire/HrDesk/Index.php \
        resources/views/livewire/hr-desk/index.blade.php
git commit -m "feat(recruiting): Zertifikat-Ausstellung im Ablehnen-Zweig des HR-Schreibtischs"
```

---

### Task 12: WhatsApp-Zustellung für Weg (a)

> **NACHTRAG nach der Umsetzung: warum der Template-Guard da ist. Er darf nicht als „das macht der Sender doch schon" wegfallen.**
>
> Der Guard prüft, dass das konfigurierte Meta-Template die Body-Variable `{{zertifikat_link}}` **wirklich enthält**, bevor gesendet wird. Er war nicht beauftragt und ist trotzdem richtig, und der Grund ist gemessen:
>
> **Ohne ihn füllt der Builder ein Template ohne die Variable mit dem BEISPIELTEXT.** Der Send **gelingt**, `wa_sent_at` wird gestempelt, und der abgelehnte Bewerber bekommt eine Nachricht **ohne Link**. Kein Fehler, kein rotes Signal, keine Log-Zeile — und das Dropdown in den Einstellungen listet **jedes genehmigte Template**, die Fehlkonfiguration ist also einen Klick entfernt.
>
> Das ist die Fehlerklasse dieses Pakets mit einem Menschen am anderen Ende: der Bewerber sieht eine sinnlose Nachricht, HR sieht einen erfolgreichen Versand, und niemand erfährt, dass das Dokument nie zugestellt wurde. Wer den Guard entfernt, weil `sendToMany` ja Fehler fängt, verwechselt „der Sender bricht nicht ab" mit „die Nachricht ist brauchbar".
>
> **Der bittere Zwilling, ebenfalls in dieser Umsetzung gefunden:** mein Brieftext sah `flash('error')` für den Fehlerfall vor. Die HR-Seite rendert nur `session('message')`, das Core-Layout nichts — **die Meldung, die HR sagt „lade das PDF herunter und verschick es von Hand", wäre unsichtbar geblieben.** Der Fallback für den Fehlerfall war selbst stumm. Daraus ist die Meldekanal-Auflage in Task 13, 14 und 18 geworden.
>
> **Zweiter Zusatz derselben Runde:** ein Doppelversand-Guard an `wa_sent_at` — zwei offene HR-Fälle zum selben Bewerber sind ein belegter Weg. Ein *fehlgeschlagener* Versand bleibt ausdrücklich wiederholbar.

**Files:**
- Modify: `src/Models/RecApplicantSettings.php`
- Modify: `src/Livewire/HrDesk/Index.php` (`confirmResolve`, nach dem Commit)
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php`

**Interfaces:**
- Consumes: `HoldingTemplateSender::sendOne()` (Bestand, `src/Services/Comms/HoldingTemplateSender.php:81-84`), Route aus Task 10
- Produces: Settings-Keys `training_certificate_wa_template_id` und `default_certificate_template_id`

**Spec-Ausschnitt (wörtlich):**

> **Weg (a) nutzt `HoldingTemplateSender`** mit neuem Settings-Key `training_certificate_wa_template_id`, Aufruf-Muster wie die Jugendschutz-Absage. Der PDF-Link geht als **Body-Variable** über `$namedValues` (z.B. `{{zertifikat_link}}`), nicht als URL-Button: der Sender kann Buttons nicht füllen, und ein Umbau von `HoldingTemplateComponents` würde einen Pfad anfassen, der auch Holding, Auto-Reply und Voice-Note-Antworten bedient. WhatsApp verlinkt URLs im Text automatisch klickbar.

> **Fehler kippen die Ablehnung nicht.** Versand nach dem Commit; `sendToMany` fängt jeden `Throwable` pro Empfänger — und `sendOne` erbt das, weil es vollständig delegiert; `resolveConfig` gibt Konfigurationsfehler als `error`-String zurück statt zu werfen. Erfolg → `wa_sent_at`; Fehler → Feld bleibt leer, Flash-Meldung, HR lädt das PDF herunter und verschickt es von Hand. Ohne konfiguriertes Template wird trotzdem ausgestellt.

> Die zwei neuen Settings kopieren das bestehende Muster: `wire:model.live` **plus explizites `:value`**, **kein `@entangle`**. Der `save()` schreibt das ganze `settings`-Array (`$this->settingsModel->settings = $this->settings; ->save();`), es gibt keinen JSON_SET-Workaround im Code.

- [ ] **Step 1: Settings-Defaults ergänzen**

In `src/Models/RecApplicantSettings.php`, in `DEFAULT_SETTINGS` neben `minor_rejection_template_id`:

```php
        'training_certificate_wa_template_id' => null,
        'default_certificate_template_id' => null,
```

- [ ] **Step 2: Versand nach dem Commit einbauen**

In `src/Livewire/HrDesk/Index.php`, in `confirmResolve()` **nach** dem `rejectCase()`-Aufruf:

```php
                // Nach dem Commit. Ein WA-Fehler darf die Ablehnung nicht
                // kippen: sendOne delegiert an sendToMany, das jeden Throwable
                // pro Empfaenger faengt, und resolveConfig gibt
                // Konfigurationsfehler als error-String zurueck.
                if ($templateId !== null) {
                    // $applicant wird in confirmResolve() heute NUR im
                    // Jugendschutz-Zweig gesetzt (HrDesk/Index.php:171) —
                    // hier deshalb selbst holen. $teamId (:145) und $case
                    // (:148) sind im Scope.
                    $applicant = $case->applicant;
                    if (!$applicant) {
                        return;
                    }

                    $certificate = RecTrainingCertificate::where('rec_applicant_id', $applicant->id)
                        ->where('rec_contract_template_id', $templateId)
                        ->first();

                    if ($certificate) {
                        $link = route('recruiting.public.training-certificate', ['uuid' => $certificate->uuid]);

                        // Verifizierte Namen: primaryContactPhone() (HrDesk/Index.php:173),
                        // Vorname inline wie dort :175-176 — es gibt kein firstName().
                        $phone = $applicant->primaryContactPhone();
                        $firstName = trim((string) ($applicant->getExtraField('vorname')
                            ?? $applicant->crmContactLinks->first()?->contact?->first_name ?? ''));

                        if ($phone !== null) {
                            $result = app(HoldingTemplateSender::class)->sendOne(
                                $teamId,
                                $phone,
                                $firstName,
                                'training_certificate_wa_template_id',
                                ['zertifikat_link' => $link],
                            );

                            if (($result['sent'] ?? 0) > 0) {
                                $certificate->update(['wa_sent_at' => now()]);
                            } else {
                                session()->flash('error',
                                    'Zertifikat ausgestellt, aber der WhatsApp-Versand ist fehlgeschlagen: '
                                    . ($result['error'] ?? 'unbekannter Fehler')
                                    . ' — bitte das PDF herunterladen und manuell senden.');
                            }
                        }
                    }
                }
```

- [ ] **Step 3: Verifizierte Namen gegenprüfen**

Run: `grep -n "function primaryContactPhone" src/Models/RecApplicant.php && grep -n "primaryContactPhone\|getExtraField('vorname')" src/Livewire/HrDesk/Index.php`
Expected: `primaryContactPhone()` existiert am Model, und `HrDesk/Index.php:173-176` zeigt dasselbe Vorname-Muster. Beides ist gegen `511451c` bereits verifiziert — dieser Schritt fängt nur ab, dass sich der Stand inzwischen bewegt hat.

- [ ] **Step 4: Settings-Selects ergänzen**

In `resources/views/livewire/applicant/applicant-settings-modal.blade.php`, direkt nach dem `minor_rejection_template_id`-Block (endet auf `:126`):

```blade
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            :value="$settings['training_certificate_wa_template_id'] ?? null"
                            name="settings.training_certificate_wa_template_id"
                            label="Schulungszertifikat — WhatsApp-Template mit Link"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model.live="settings.training_certificate_wa_template_id"
                        />
                    @endif
                    <p class="text-xs text-[var(--ui-muted)] -mt-2">
                        Das Template braucht eine Body-Variable <code>zertifikat_link</code> — keinen URL-Button.
                        Fehlt das Template, wird das Zertifikat trotzdem ausgestellt und muss von Hand verschickt werden.
                    </p>
```

- [ ] **Step 5: Blade und Syntax prüfen**

Run: `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php && php -l src/Livewire/HrDesk/Index.php && php -l src/Models/RecApplicantSettings.php`
Expected: ohne Fehler

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecApplicantSettings.php src/Livewire/HrDesk/Index.php \
        resources/views/livewire/applicant/applicant-settings-modal.blade.php
git commit -m "feat(recruiting): WhatsApp-Zustellung des Zertifikats mit Link als Body-Variable"
```

---

### Task 13: Weg (b) — automatisch bei der Mitarbeiter-Anlage

> **GEÄNDERT durch den Zuschnitt v3.** Keine Vorlagen-Auflösung mehr, kein `default_certificate_template_id`. Der Hook ruft `issue($applicant, null)` und prüft dasselbe Team-Setting `issue_training_certificates`. **Wichtig:** dieser Weg hat keine UI, das Setting ist hier also die einzige Bremse.
>
> **ZWEI AUFLAGEN, die in Task 8 gemessen wurden und hier fällig werden:**
>
> **1) `issue()` WIRFT bei ausgeschaltetem Schalter** (der Rückgabetyp ist nicht nullable). Dieser Weg hängt an der **Mitarbeiter-Anlage** — also muss er **vorher** `IssueTrainingCertificateService::isEnabledForTeam()` fragen und darf den Aufruf **nicht** in ein `try/catch` legen. Ein `try/catch` sähe defensiv aus und wäre das Gegenteil: es würde jede andere Ursache mitschlucken, und ein ausgeschaltetes Feature dürfte niemals die Anlage eines Mitarbeiters mitreißen — genauso wenig wie ein Fehler im Zertifikat.
>
> **6) ENTSCHEIDUNG: die Ausstellung läuft HINTER dem Commit, nicht in der Transaktion. Der Codeblock weiter unten zeigt es falsch.**
>
> `createOrUpdate()` umschließt alles mit `DB::transaction()` (`src/Services/CreateEmployeeFromApplicantService.php:43`). Der Plantext hängte die Ausstellung dort hinein und fing sie mit `try/catch (\Throwable)` — das ist **beides falsch**, und zwar aus einem Grund:
>
> **Innerhalb der Transaktion ist „alles oder nichts" die Zusage. Genau die wollen wir bei Weg (b) nicht.** Ein Mitarbeiter **ohne** Zertifikat ist ein legitimer Zustand — das Zertifikat ist der Trostpreis, nicht Teil der Anlage-Invariante. Der umgekehrte Fall ist keiner: keine Mitarbeiter-Anlage, weil der Zertifikat-Pfad einen Defekt hat. Der `catch` wäre dort also eine **Ausnahme von einer Zusage, die man von vornherein nicht will**. Hinter dem Commit ist die richtige Zusage die Standardzusage.
>
> **Der Nebeneffekt ist der eigentliche Gewinn:** in der Transaktion hätte der Code eine ungemessene Voraussetzung getragen. Gemessen wurde: SQLite verträgt einen gefangenen Statement-Fehler (Transaktion bleibt benutzbar, `transactionLevel` unverändert, Commit greift), MySQL nach dokumentiertem Verhalten ebenfalls — **nicht gemessen** —, **Postgres nicht**: dort vergiftet jeder Fehler die Transaktion, und der Folgefehler wäre irreführend. Hinter dem Commit stellt sich die Frage nicht mehr. In einem Durchlauf mit vier gekippten Messungen ist „die Frage stellt sich nicht" mehr wert als „die Antwort ist vermutlich ok".
>
> **Der Unterschied zu Weg (a) ist echt und kein Widerspruch.** Bei (a) hat HR die Checkbox **bewusst gesetzt** und würde sonst glauben, beides sei passiert — dort soll ein gescheitertes Zertifikat die Ablehnung mitnehmen. Bei (b) hakt **niemand etwas an**, die Ausstellung ist ein Automatismus. Zwei verschiedene Zusagen mit zwei verschiedenen Gründen.
>
> **Drei Auflagen des Auftraggebers:**
>
> 1. Der Aufruf muss **zuverlässig nach dem Commit** laufen, nicht bloß nach der letzten Zeile im Transaktionsblock. **Zeig im Diff, wo genau er sitzt.**
> 2. **Falsifikator:** Ausstellung wirft → Mitarbeiter ist trotzdem angelegt und committet, Log-Zeile da. Plus die Gegenprobe, dass ein erfolgreicher Lauf **beides** hat.
> 3. `catch` als **Ausnahmeliste** statt `\Throwable`. **Umfasst die Liste faktisch alles, sag es** — dann nehmen wir `\Throwable` mit einem Kommentar, der das benennt. Lieber ehrlich breit als kosmetisch verengt.
>
> **Gestrichen, nicht umformuliert:** die Begründung im Codeblock „die Vorlage kann inzwischen gelöscht oder auf `type=contract` umgestellt worden sein" ist ein v2-Rest. Es gibt keine Vorlage. Eine Begründung, die auf nichts zeigt, ist schlimmer als keine.
>
> **Zwei weitere v2-Reste im Codeblock unten**, von Regel 4 vor dem Dispatch gefangen: `->issue($applicant, (int) $templateId, null)` — `$templateId` existiert nicht mehr, die Signatur ist `issue(RecApplicant, ?int $issuedByUserId)`. Und die Zeilenangabe `:106` ist um eins verschoben, `transferEvaluationToHrData()` steht auf `:105`.
>
> **Log:** `Log::error` (nicht `warning`), **eigener Marker** — ein Fehler bei der Ausstellung darf nicht wie einer beim Bewertungs-Transfer aussehen — und die **Applicant-ID** rein, sonst ist die Zeile nicht nachverfolgbar.
>
> **4) Meldekanal prüfen, nicht annehmen.** Geht eine Meldung dieses Tasks über einen Kanal, den die betroffene Seite **tatsächlich rendert**? Bei Task 12 wäre `flash('error')` unsichtbar geblieben: die HR-Seite rendert nur `session('message')`, das Core-Layout nichts. Das war der bittere Zwilling des Template-Guards — **der Fallback für den Fehlerfall wäre selbst stumm gewesen.** Also: den Kanal am Blade nachsehen, nicht am Framework-Wissen.
>
> **5) Der Template-Guard aus Task 12 gilt hier NICHT, und das ist der Grund, ihn zu erwähnen:** Weg (b) verschickt nichts (§D3). Wenn du also über einen WhatsApp-Versand nachdenkst, ist der Task falsch verstanden.
>
> **3) Das Query-Protokoll aus Task 11 mitnehmen — dieselbe Konstellation, derselbe blinde Fleck.** Dieser Task hängt einen Hook in einen **bestehenden** Ablauf (Mitarbeiter-Anlage), genau wie Task 11 in `rejectCase()`. Dort hat ein Zustands-Test **nicht** genügt: die Mutation „Settings-Lookup vor den Guard ziehen, nichts schreiben" ließ Zustand und Ablauf **völlig korrekt** und wäre grün durchgelaufen. Rot wurde sie erst durch eine Assertion auf das **Query-Protokoll**:
>
> ```
> -Array &0 []
> +Array &0 [ 0 => 'select * from "rec_applicant_settings" where ("team_id" = ?) limit 1' ]
> ```
>
> Also: die Query-Zahl des unveränderten Pfades **messen, nicht schätzen** (in Task 11 waren es 4, meine Schätzung war 5) und als Konstante festnageln. Der Nachweis „die Mitarbeiter-Anlage läuft mit ausgeschaltetem Feature exakt wie heute" braucht diese Assertion, sonst ist er eine Annahme.
>
> **2) Prüf, ob der Schalter beim Einschalten tatsächlich in der Settings-Zeile landet — und berichte das Ergebnis.** Gemessener Hintergrund: `RecApplicantSettings::getSetting()` liest
>
> ```php
> $settings[$key] ?? $default ?? (self::DEFAULT_SETTINGS[$key] ?? null)
> ```
>
> Bei einer **bestehenden** Settings-Zeile ohne den neuen Schlüssel — dem Zustand jedes heute existierenden Teams — trägt also allein der `DEFAULT_SETTINGS`-Eintrag. Solange der `false` ist, ist die Richtung sicher; der Mechanismus ist aber fragil, weil der Default die Arbeit tut statt eines gespeicherten Werts.
>
> Die Frage, die hier zu beantworten ist: **schreibt das Einschalten im Formular den Schlüssel wirklich in die `settings`-Spalte, oder zeigt die UI nur `true` an, während die Zeile den Schlüssel weiterhin nicht enthält?** Im zweiten Fall hängt der Live-Zustand weiter am Default, und ein späterer Default-Wechsel würde ihn still umdrehen. Miss es (Settings-Zeile vor und nach dem Speichern, roh), statt es zu erschließen — und wenn der Schlüssel nicht landet, ist das ein Befund und kein Detail.


**Files:**
- Modify: `src/Services/CreateEmployeeFromApplicantService.php` (neben `:106`)

**Interfaces:**
- Consumes: `IssueTrainingCertificateService::issue()` (Task 8), Setting `default_certificate_template_id` (Task 12)
- Produces: nichts

**Spec-Ausschnitt (wörtlich):**

> **Weg (b): automatisch bei der Mitarbeiter-Anlage.** Vierter Nachbereitungs-Schritt neben `transferEvaluationToHrData()`, im dortigen try/catch-Muster, aber mit **eigenem Log-Marker**, damit ein Fehler bei der Ausstellung nicht wie ein Fehler beim Bewertungs-Transfer gelesen wird.

> `createOrUpdate()` steigt bei existierendem Employee vor allem anderen aus (`:38-41`), danach läuft alles in `DB::transaction` (`:43`). Darin bereits drei Nachbereitungs-Schritte in Folge: `ensureHrData()` (`:104`), `snapshotContractDatesToHrData()` (`:105`), `transferEvaluationToHrData()` (`:106`). **Jeder Hook dort feuert genau einmal pro Mitarbeiter.**

> Vorlagenwahl über ein neues Team-Setting `default_certificate_template_id`. Ist keines gesetzt oder hat der Bewerber keine `attended`-Buchung, passiert nichts (kein Fehler) — Direkteinstellungen und ZAS-Importe haben keine Schulung.

> Setting `default_certificate_template_id`: **Wert muss zur Ausstellungszeit noch `type='certificate'` sein** — Prüfung bei der Ausstellung, nicht beim Speichern: der Typ einer Vorlage kann sich nach dem Setzen des Settings ändern.

> **Weg (b) verschickt nichts.** Der neue Mitarbeiter bekommt seine Portal-Einladung ohnehin, und dort steht das Zertifikat.

- [ ] **Step 1: Aufruf einhängen**

In `src/Services/CreateEmployeeFromApplicantService.php`, direkt nach Zeile `:106` (`$this->transferEvaluationToHrData($applicant, $hrData);`):

```php
            $this->issueTrainingCertificate($applicant);
```

Und die Methode ergänzen:

```php
    /**
     * Weg (b) der Zertifikat-Ausstellung: jeder Teilnehmer, der Mitarbeiter
     * wird, bekommt sein Zertifikat — ohne Zutun.
     *
     * Eigener Log-Marker, damit ein Fehler hier nicht wie ein Fehler beim
     * Bewertungs-Transfer gelesen wird.
     *
     * Kein Versand: der neue Mitarbeiter bekommt seine Portal-Einladung
     * ohnehin, und dort steht das Zertifikat.
     */
    private function issueTrainingCertificate(RecApplicant $applicant): void
    {
        try {
            $templateId = RecApplicantSettings::getOrCreateForTeam((int) $applicant->team_id)
                ->getSetting('default_certificate_template_id');

            if (!$templateId) {
                return;
            }

            $hasAttended = $applicant->interviewBookings()
                ->where('status', 'attended')
                ->exists();

            if (!$hasAttended) {
                // Direkteinstellungen und ZAS-Importe haben keine Schulung.
                return;
            }

            app(IssueTrainingCertificateService::class)
                ->issue($applicant, (int) $templateId, null);
        } catch (\Throwable $e) {
            // Die MA-Anlage schlaegt schwerer als das Zertifikat — sie darf
            // hier nicht kippen. Die Vorlage kann inzwischen geloescht oder
            // auf type=contract umgestellt worden sein; der Service wirft
            // dann InvalidArgumentException.
            Log::warning('[CreateEmployeeFromApplicantService] issueTrainingCertificate failed', [
                'applicant_id' => $applicant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

Imports ergänzen: `use Platform\Recruiting\Models\RecApplicantSettings;`, `use Platform\Recruiting\Services\IssueTrainingCertificateService;` (falls nicht vorhanden), `use Illuminate\Support\Facades\Log;` (prüfen, ob schon importiert).

- [ ] **Step 2: Relations- und Methodennamen verifizieren**

Run: `grep -n "function interviewBookings\|function getSetting" src/Models/RecApplicant.php src/Models/RecApplicantSettings.php`
Expected: beide existieren. Falls die Bookings-Relation anders heißt, an den in Task 7 Step 6 festgestellten Namen anpassen.

- [ ] **Step 3: Syntax + Gesamtsuite**

Run: `php -l src/Services/CreateEmployeeFromApplicantService.php && /Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: ohne Fehler, Suite PASS

- [ ] **Step 4: Commit**

```bash
git add src/Services/CreateEmployeeFromApplicantService.php
git commit -m "feat(recruiting): Zertifikat automatisch bei der Mitarbeiter-Anlage"
```

---

### Task 14: Beide Portale zeigen das Zertifikat

> **GEÄNDERT durch den Zuschnitt v3, an einer Stelle die man leicht übersieht.** Der Spec-Ausschnitt unten setzt `display_name` = **Vorlagenname**. Es gibt keine Vorlage; `display_name` wird ein konstanter String („Teilnahme-Zertifikat" o. ä.), passend zur `kind`-Konstante. Alles andere an diesem Task bleibt unverändert — insbesondere die Pflicht-Reihenfolge des `issued`-Zweigs vor der `signed_at`-Bedingung und die Mitzählung in `ApplicantPortal:78`.
>
> **MELDEKANAL PRÜFEN, nicht annehmen** (aus Task 12, wo es teuer war): geht eine Meldung dieses Tasks über einen Kanal, den die jeweilige Portal-Seite **tatsächlich rendert**? Bei Task 12 wäre `flash('error')` unsichtbar geblieben — die HR-Seite rendert nur `session('message')`, das Core-Layout nichts. Hier betrifft es zwei verschiedene Blades in zwei verschiedenen Portalen; **prüf beide getrennt**, sie müssen nicht denselben Kanal haben.
>
> **Und das ist der zweite und letzte Blade-Task des Pakets.** `php tools/blade-check.php` war bis Task 11 im Worktree **tot** (Exit 2, Autoloader-Pfad fest vier Ebenen aufwärts) und ist dort repariert worden. Es ist hier das einzige Werkzeug, das überhaupt etwas prüft — Livewire-Komponenten sind im Modul nicht instanziierbar. Lauf es und nenn die Ausgabe.


**Files:**
- Modify: `src/Livewire/Public/EmployeePortal.php` (`contracts()` `:464-501`)
- Modify: `resources/views/livewire/public/employee-portal.blade.php` (`:112`)
- Modify: `src/Livewire/Public/ApplicantPortal.php` (`:53-78`)
- Modify: `resources/views/livewire/public/applicant-portal.blade.php` (`:42`)

**Interfaces:**
- Consumes: `RecTrainingCertificate` (Task 8), Route aus Task 10
- Produces: nichts

**Spec-Ausschnitt (wörtlich):**

> **Zertifikat-Zeilen an `contracts()` anhängen, in beiden Portalen.** Nach den Vertragszeilen: `display_name` = Vorlagenname, `signed_at` = `issued_at`, `sign_url` = `null`, `pdf_url` = die Route, `status` = `'issued'`. Die Array-Form ist in `EmployeePortal::contracts()` (`:464-501`) und `ApplicantPortal::contracts()` (`:53-77`) identisch — dieselbe Ergänzung, zweimal.

> **Genau eine Blade-Anpassung pro Portal, und die Reihenfolge ist Pflicht.** Der `issued`-Zweig muss **vor** die Bedingung auf `employee-portal.blade.php:112` bzw. `applicant-portal.blade.php:42` (`status === 'completed' || signed_at`) — sonst gewinnt sie, weil `signed_at` gesetzt ist, und die Zeile behauptet „Unterschrieben am …" über ein Dokument, das niemand unterschrieben hat. Richtig ist „Ausgestellt am …". Ohne den Zweig gäbe der Rohwert-Fallback das Wort `issued` aus.

> Zwei Dinge sind **ohne Änderung** korrekt und nur deshalb festgehalten, weil sie die Wahl von `status = 'issued'` und `sign_url = null` begründen: der Unterschreiben-Button verlangt `sent`/`in_progress` → bleibt weg; der PDF-Button hängt allein an `pdf_url` → erscheint von allein.

> **`ApplicantPortal:78` muss Zertifikate mitzählen.** Die Zeile setzt `state = count($contracts) === 0 ? 'empty' : 'ready'`. Ein abgelehnter Nicht-EU-Bewerber hat typischerweise **keine** Verträge — bleibt die Zählung wie sie ist, liegt sein Zertifikat in einem Portal, das sich für leer erklärt.

- [ ] **Step 1: `EmployeePortal::contracts()` erweitern**

Vor `->values()->toArray()` das Ergebnis in eine Variable ziehen und die Zertifikate anhängen:

```php
        $rows = $employee->applicant->contracts
            ->filter(fn ($c) => $c->status !== 'cancelled')
            ->map(function ($c) use ($applicantToken) {
                // ... unveraendert ...
            })
            ->values()
            ->toArray();

        return array_merge($rows, $this->certificateRows($employee->applicant));
```

Und die geteilte Methode ergänzen (identisch in beiden Portalen):

```php
    /**
     * Zertifikat-Zeilen in der Form der Vertragszeilen.
     *
     * status='issued' und sign_url=null sind bewusst gewaehlt: der
     * Unterschreiben-Button der Blade verlangt 'sent'/'in_progress' und
     * bleibt damit von allein weg; der PDF-Button haengt allein an pdf_url
     * und erscheint von allein.
     *
     * signed_at wird mit issued_at befuellt, statt einen zweiten Datums-Key
     * einzufuehren — die Zeile soll denselben gruenen Erledigt-Zustand
     * tragen wie ein fertiger Vertrag, nur mit anderem Wort.
     */
    private function certificateRows(RecApplicant $applicant): array
    {
        return RecTrainingCertificate::where('rec_applicant_id', $applicant->id)
            ->with('contractTemplate:id,name')
            ->orderBy('issued_at')
            ->get()
            ->map(fn (RecTrainingCertificate $cert) => [
                'id' => 'cert-' . $cert->id,
                'display_name' => $cert->contractTemplate?->name ?? 'Schulungszertifikat',
                'status' => 'issued',
                'signed_at' => $cert->issued_at,
                'completed_at' => $cert->issued_at,
                'sign_url' => null,
                'pdf_url' => route('recruiting.public.training-certificate', ['uuid' => $cert->uuid]),
            ])
            ->all();
    }
```

- [ ] **Step 2: `employee-portal.blade.php` — `issued`-Zweig VOR `:112`**

Die Badge-Kette beginnt heute mit:

```blade
                                            @if($c['status'] === 'completed' || $c['signed_at'])
```

Davor einfügen:

```blade
                                            @if($c['status'] === 'issued')
                                                <span class="inline-flex items-center gap-1 text-green-700">
                                                    @svg('heroicon-o-academic-cap', 'w-4 h-4')
                                                    Ausgestellt
                                                    @if($c['signed_at'])
                                                        am {{ \Carbon\Carbon::parse($c['signed_at'])->format('d.m.Y') }}
                                                    @endif
                                                </span>
                                            @elseif($c['status'] === 'completed' || $c['signed_at'])
```

(Das bestehende `@if` wird zum `@elseif` — die Reihenfolge ist der Kern dieser Änderung.)

- [ ] **Step 3: `ApplicantPortal` erweitern**

Analog zu Step 1, und **die `state`-Zählung anpassen**:

```php
        $this->contracts = array_merge($contracts, $this->certificateRows($applicant));
        // Zaehlt Zertifikate mit: ein abgelehnter Bewerber hat typischerweise
        // keine Vertraege. Ohne das laege sein Zertifikat in einem Portal,
        // das sich fuer leer erklaert.
        $this->state = count($this->contracts) === 0 ? 'empty' : 'ready';
```

- [ ] **Step 4: `applicant-portal.blade.php` — `issued`-Zweig VOR `:42`**

Identisch zu Step 2, an der Bedingung auf `:42`.

- [ ] **Step 5: Blades und Syntax prüfen**

Run:
```bash
php tools/blade-check.php resources/views/livewire/public/employee-portal.blade.php
php tools/blade-check.php resources/views/livewire/public/applicant-portal.blade.php
php -l src/Livewire/Public/EmployeePortal.php
php -l src/Livewire/Public/ApplicantPortal.php
```
Expected: alle ohne Fehler

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Public/EmployeePortal.php src/Livewire/Public/ApplicantPortal.php \
        resources/views/livewire/public/employee-portal.blade.php \
        resources/views/livewire/public/applicant-portal.blade.php
git commit -m "feat(recruiting): Zertifikat in MA- und Bewerber-Portal bei den Vertraegen"
```

---

### Task 15: Vorlagen-Editor — Typ, Test-PDF, Zeichen prüfen

> **ENTFÄLLT — Zuschnitt v3, entschieden am 12.08.2026, vollständig. Der Task wird NICHT aus dem Plan gelöscht: eine Lücke in der Nummerierung liesse offen, ob hier etwas vergessen wurde oder bewusst wegfiel.**
>
> Es gibt keine Zertifikat-Vorlage, also nichts zu editieren: kein Typ-Dropdown, kein Badge, keine Vokabular-Hilfe, und **keine der beiden Knöpfe** (Test-PDF, Zeichen prüfen). Das ist mehr als das Zurücknehmen der „load-bearing"-Markierung an §E8 — die Knöpfe hatten ihren Platz im Editor, und der existiert nicht.
>
> **Warum das vertretbar ist, und das ist die Begründung, die nicht verloren gehen darf:** die Markierung war richtig, solange der Inhalt in einem Textarea lag. Die Kette war „Einseitigkeit hängt am Inhalt → Inhalt ist HR-editierbar → also braucht es eine Sichtprüfung nach jeder Bearbeitung". Mit v3 bricht das mittlere Glied: der Inhalt ist **deploy-gebunden** und ändert sich nur, wenn jemand `TrainingCertificateHtml` anfasst — und genau dann läuft **Task 9**. Der übernimmt beide Aufgaben automatisch statt manuell: Seitenzahl inklusive Negativkontrolle (Kriterium 1), Zeichenabdeckung über `FontGlyphCoverage::inspect()` mit `checkable === true` (Kriterium 3), plus `/BaseFont` (Kriterium 2), das §E8 ohnehin nie konnte.
>
> **Wer diesen Task reaktiviert, muss zuerst das Textarea zurückbringen.** Ein Test-PDF-Knopf über deploy-gebundenem Inhalt wäre ein Bequemlichkeitswerkzeug, kein Wächter, und darf nicht als solcher geführt werden.
>
> Was aus diesem Task **schon gebaut ist und bleibt:** `FontGlyphCoverage` samt drittem Zustand (Task 4/4a). Es beschreibt jetzt eine Klasse, die der Render-Test benutzt, keinen Knopf.
>
> **Reaktivierung** nur zusammen mit dem Rückweg, also wenn der Inhalt wieder eine Vorlage wird. Siehe Spec, Abschnitt „Aufgegeben mit dem Zuschnitt v3“, und die nicht ausgeführte Guard-Analyse in `docs/zertifikat/guard-landkarte-511451c.md`.


**Files:**
- Modify: `src/Livewire/ContractTemplates/Index.php`
- Modify: `resources/views/livewire/contract-templates/index.blade.php`

**Interfaces:**
- Consumes: **`FontGlyphCoverage::inspect()` (Task 4a — `missing()` existiert nicht mehr)**, `TrainingCertificateAssets::resolve()` (Task 5a), `TrainingCertificateHtml` (Task 6), `TrainingCertificatePdfOptions` (Task 5), `RecContractTemplate::TYPE_*` (Task 3)
- Produces: nichts

**Spec-Ausschnitt (wörtlich):**

> `ContractTemplates/Index` bekommt `type` in `$rules`, im Create/Edit-Modal ein Dropdown („Vertrag" / „Schulungszertifikat"), in der Liste einen Typ-Badge. Der Signatur-Toggle wird bei `certificate` ausgeblendet. Die Admin-Liste bleibt **ungefiltert** — hier sollen beide Typen sichtbar sein.

> **„Test-PDF"** rendert mit Beispielwerten über dieselbe Hülle und dieselben Optionen und liefert das Ergebnis aus. Begründung: das Fließlayout ist inhaltsabhängig — ein langer Name oder eine längere Kenntnisliste kann das Layout sprengen, und ohne Vorschau merkt das erst der Bewerber.

> **„Zeichen prüfen"** ruft eine pure Funktion auf. Sie liest die `cmap` der Schriftdatei und gibt die Zeichen des Inhalts zurück, die darin fehlen — also genau die, die im PDF zu `?` würden. **Damit ist der Tradeoff „prüft nur die ausgelieferte Vorlage, nicht spätere Bearbeitungen" geschlossen** — HR kann nach jeder Bearbeitung selbst prüfen.

> **Das Vokabular steht in der Platzhalter-Hilfe** neben dem Editor, dort wo schon die verfügbaren Platzhalter erklärt werden.

- [ ] **Step 1: `type` in Rules und Formular-State**

In `src/Livewire/ContractTemplates/Index.php`: `public string $type = 'contract';`, in `$rules` `'type' => 'required|in:contract,certificate'`, in `openEditModal()` laden und in `save()` in `$data` schreiben.

- [ ] **Step 2: Zeichen-Prüfung als Action**

```php
    public ?string $glyphCheckResult = null;
    public ?string $testPdfHinweis = null;

    /**
     * Meldet Zeichen, die die Zertifikat-Schrift nicht kennt. DomPDF macht
     * bei einer eingebundenen Schrift keinen Glyph-Fallback — jedes
     * unbekannte Zeichen wird im PDF zu "?", ohne Warnung.
     */
    public function checkGlyphs(): void
    {
        $assets = TrainingCertificateAssets::resolve($this->resourcesDir());

        // Task 4a: inspect() auf dem ROHEN Vorlageninhalt, nie auf der Ausgabe
        // von TrainingCertificateHtml::build(). strip_tags() entfernt den
        // <style>-Tag, nicht dessen Inhalt, und die Huelle hat einen
        // CSS-Kommentar mit einem ★ darin — die Pruefung wuerde sonst ein
        // Phantom-★ melden fuer eine Vorlage, in der kein Stern vorkommt.
        $report = FontGlyphCoverage::inspect((string) $this->content, $assets['font']);

        $hinweis = '';
        if ($assets['missing'] !== []) {
            // Derselbe Resolver wie im Controller — fehlt hier etwas, fehlt
            // es auch bei der Ausstellung.
            $hinweis = ' Achtung: diese Assets fehlen auf dem Server: '
                . implode(', ', $assets['missing']) . '.';
        }

        // Drei Zustaende, drei Meldungen. Der dritte ist der Grund fuer Task 4a:
        // vorher lieferte eine unparsbare Schrift denselben gruenen Text wie
        // eine intakte, und dieser Knopf ist die einzige Stelle, an der ein
        // Mensch den stillen Helvetica-Fallback je bemerken wuerde. Es bleibt
        // eine WARNUNG, kein Gate — gespeichert wird trotzdem.
        if (!$report->checkable) {
            $text = 'Die Schrift konnte nicht gelesen werden — die Zeichen sind '
                . 'damit UNGEPRUEFT. Das Zertifikat wuerde vermutlich in einer '
                . 'Ersatzschrift gerendert. Bitte an die IT melden.';
        } elseif ($report->missing === []) {
            $text = 'Alle Zeichen sind in der Schrift vorhanden.';
        } else {
            $text = 'Diese Zeichen fehlen in der Schrift und würden im PDF zu „?“: '
                . implode(' ', $report->missing)
                . ' — in <span class="zeichen">…</span> setzen.';
        }

        $this->glyphCheckResult = $text . $hinweis;
    }
```

- [ ] **Step 3: Test-PDF als Action**

```php
    /**
     * Rendert die Vorlage mit Beispielwerten — gleiche Huelle, gleiche
     * Optionen UND derselbe Asset-Resolver wie die Ausstellung. Wuerde die
     * Vorschau ihre Assets selbst aufloesen, koennte sie etwas anderes zeigen
     * als das ausgestellte Dokument — und genau dagegen existiert der Knopf.
     *
     * Dieser Knopf ist load-bearing, nicht Komfort: seit die Einseitigkeit
     * nachweislich KEINE strukturelle Garantie ist (§E5, 20 Listenzeilen
     * ergeben 2 Seiten), ist er die einzige Stelle, an der ein Mensch die
     * Seitenzahl einer von HR bearbeiteten Vorlage ueberhaupt sieht. Der
     * Render-Test prueft nur die ausgelieferte Vorlage.
     */
    public function testPdf()
    {
        $assets = TrainingCertificateAssets::resolve($this->resourcesDir());

        $beispiel = str_replace(
            ['{{kontakt_vorname}}', '{{kontakt_nachname}}', '{{schulung_datum}}', '{{datum_heute}}', '{{schulung_leiter}}'],
            ['Erika', 'Mustermann', '24.07.2026', now()->format('d.m.Y'), 'Michel Zimmer, Anna Bergmann'],
            (string) $this->content
        );

        $html = TrainingCertificateHtml::build($beispiel, $assets);

        $pdf = Pdf::loadHTML($html);
        foreach (TrainingCertificatePdfOptions::for($assets['font'], (string) realpath(base_path())) as $key => $value) {
            $pdf->setOption($key, $value);
        }

        $bytes = $pdf->setPaper('a4')->output();

        // Seitenzahl ANZEIGEN, nicht nur ausliefern: ein zweiseitiges
        // Zertifikat sieht auf Seite 1 voellig normal aus, wer nicht scrollt
        // merkt nichts. Kein Gate — das PDF geht trotzdem raus.
        // Dasselbe Muster wie im Render-Test; grep -c "/Type /Page" findet auf
        // einem DomPDF-PDF NULL Treffer (G13.6), ein Literal-Match waere hier
        // also immer "1 Seite".
        $seiten = preg_match_all('/\/Type\s*\/Page[^s]/', $bytes);
        $this->testPdfHinweis = $seiten === 1
            ? '1 Seite'
            : $seiten . ' Seiten — Zertifikate sollen einseitig sein';

        return response()->streamDownload(
            fn () => print($bytes),
            'zertifikat-test.pdf'
        );
    }

    private function resourcesDir(): string
    {
        return (string) realpath(__DIR__ . '/../../../resources');
    }
```

- [ ] **Step 4: Blade — Typ-Dropdown, Badge, zwei Knöpfe, Vokabular-Hilfe**

Im Create/Edit-Modal vor dem Vertragstext-Textarea (`:166`):

```blade
                <x-ui-input-select
                    :value="$type"
                    name="type"
                    label="Typ"
                    :options="[['id' => 'contract', 'label' => 'Vertrag'], ['id' => 'certificate', 'label' => 'Schulungszertifikat']]"
                    optionValue="id"
                    optionLabel="label"
                    wire:model.live="type"
                />
                @if($type === 'certificate')
                    <div class="text-xs text-[var(--ui-muted)] space-y-1">
                        <p><strong>Code muss mit <code>ZERT-</code> beginnen.</strong> Unterschrift wird automatisch abgeschaltet.</p>
                        <p>Platzhalter: <code>{{ '{{kontakt_vorname}}' }}</code>, <code>{{ '{{kontakt_nachname}}' }}</code>,
                           <code>{{ '{{schulung_datum}}' }}</code>, <code>{{ '{{datum_heute}}' }}</code>,
                           <code>{{ '{{schulung_leiter}}' }}</code></p>
                        <p>Klassen: <code>lab</code>, <code>val</code>, <code>kurs</code>, <code>intro</code>,
                           <code>skill</code>, <code>zeichen</code>, <code>zert-datum</code>, <code>zert-fuss-rechts</code>.
                           Ein nackter <code>&lt;p&gt;</code> funktioniert auch.</p>
                        <p><code>zeichen</code> ist für Sonderzeichen wie ★ — ohne sie stehen sie als „?" im PDF.</p>
                    </div>
                @endif
```

Nach dem Textarea:

```blade
                @if($type === 'certificate')
                    <div class="flex items-center gap-2">
                        <x-ui-button variant="secondary-outline" size="sm" wire:click="checkGlyphs">Zeichen prüfen</x-ui-button>
                        <x-ui-button variant="secondary-outline" size="sm" wire:click="testPdf">Test-PDF</x-ui-button>
                    </div>
                    @if($glyphCheckResult)
                        <p class="text-xs text-[var(--ui-secondary)]">{!! $glyphCheckResult !!}</p>
                    @endif
                    @if($testPdfHinweis)
                        <p class="text-xs text-[var(--ui-secondary)]">{{ $testPdfHinweis }}</p>
                    @endif
                @endif
```

**Zu verifizieren, nicht zu unterstellen — Reihenfolge von Download und Re-Render.** `testPdf()` setzt `$testPdfHinweis` **und** gibt eine `streamDownload`-Response zurück. Livewire v3 behandelt Datei-Downloads aus Actions gesondert (Datei als Payload neben dem normalen Component-Update), der Hinweis sollte also im selben Klick erscheinen. **Das ist erschlossen, nicht gemessen** — ohne gebootete App nicht prüfbar. Beim Bauen also ausdrücklich nachsehen: erscheint die Zeile nach *einem* Klick? Wenn nicht, ist der Hinweis nach einem Download wertlos, und die Seitenzahl muss anders sichtbar werden (z. B. `checkGlyphs()` zählt sie mit, oder ein eigener Knopf „Seiten zählen" ohne Download). **Nicht** stillschweigend so lassen, dass der Hinweis erst beim nächsten Speichern auftaucht — dann zeigt er die Seitenzahl der *vorherigen* Fassung, und das ist schlimmer als keine Anzeige.

In der Liste, neben dem `is_active`-Badge (`:57`):

```blade
                                        <x-ui-badge variant="{{ $item->type === 'certificate' ? 'info' : 'secondary' }}" size="xs">
                                            {{ $item->type === 'certificate' ? 'Zertifikat' : 'Vertrag' }}
                                        </x-ui-badge>
```

Und den Signatur-Toggle in `@if($type !== 'certificate')` einwickeln.

- [ ] **Step 5: Blade und Syntax prüfen**

Run: `php tools/blade-check.php resources/views/livewire/contract-templates/index.blade.php && php -l src/Livewire/ContractTemplates/Index.php`
Expected: ohne Fehler

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/ContractTemplates/Index.php resources/views/livewire/contract-templates/index.blade.php
git commit -m "feat(recruiting): Vorlagen-Editor mit Typ, Test-PDF und Zeichen-Pruefung"
```

---

### Task 16: Seed-Command für die erste Zertifikat-Vorlage

> **ENTFÄLLT — Zuschnitt v3, entschieden am 12.08.2026. Nicht gelöscht, aus demselben Grund wie Task 15 und 17: die Nummerierung soll zeigen, dass hier bewusst nichts steht.** Es gibt keine Vorlage zu seeden; der Inhalt ist Teil des Deploys.
>
> **Zwei Dinge aus diesem Task ziehen um, statt zu verschwinden:**
>
> 1. **Der Vorlageninhalt selbst** — das HTML mit den vier Platzhaltern und der Kenntnisliste — wird der feste Block in `TrainingCertificateHtml` bzw. in der Ausstellung. Er ist nicht verloren, er wechselt nur den Ort.
> 2. **Die HTML-Entity-Schreibweise `&#9733;` für die ★ bleibt Absicht** und der Grund bleibt derselbe: `FontGlyphCoverage` dekodiert Entities (in Task 4 direkt verprobt), die Zeichenprüfung greift also auch auf diese Schreibweise. Wer die Dekodierung für unnötig hält und entfernt, macht die Prüfung am **einzigen ausgelieferten Inhalt** still blind. Das galt für die geseedete Vorlage und gilt unverändert für den festen Block — der Hinweis muss mit ihm umziehen.
>
> **Reaktivierung** nur zusammen mit dem Rückweg, also wenn der Inhalt wieder eine Vorlage wird. Siehe Spec, Abschnitt „Aufgegeben mit dem Zuschnitt v3“, und die nicht ausgeführte Guard-Analyse in `docs/zertifikat/guard-landkarte-511451c.md`.


**Files:**
- Create: `src/Console/Commands/SeedTrainingCertificateTemplate.php`
- Modify: `src/RecruitingServiceProvider.php` (Command registrieren)

**Interfaces:**
- Consumes: `RecContractTemplate` (Task 3)
- Produces: Command `recruiting:seed-training-certificate-template`

**Spec-Ausschnitt (wörtlich):**

> **Die erste Zertifikat-Vorlage kommt aus einem Command**, nicht aus Handarbeit im Textarea — Muster vorhanden: `Console/Commands/CreateArbeitsvertragVariants.php`, `CopyHcmContractTemplates.php`, `SeedRecContractExtraFields.php`. HR bearbeitet danach Wörter in einer fertigen Vorlage, statt Struktur zu schreiben. Für eine neue Schulungsart wird kopiert und der Text getauscht.

> Vorlageninhalt mit den Platzhaltern `{{kontakt_vorname}} {{kontakt_nachname}}`, `{{schulung_datum}}`, `{{datum_heute}}`, `{{schulung_leiter}}`. Kursname, Kenntnisliste und Ort sind Literaltext, weil eine Vorlage pro Schulungsart existiert.

**Die Sterne stehen als HTML-Entity `&#9733;`, und das ist Absicht — nicht aufräumen.** Zwei Gründe, beide belegt:

1. Oswald hat kein ★ (U+2605). Ohne den `<span class="zeichen">`-Umweg, der auf DejaVu schaltet, stünde `?` im PDF (G13.3). Die Entity ändert daran nichts — sie ist nur die Schreibweise —, aber sie macht sichtbar, dass hier ein Sonderzeichen steht, das eine eigene Behandlung braucht.
2. **`FontGlyphCoverage::inspect()` dekodiert HTML-Entities** (in Task 4 direkt verprobt: `'A &#9733; B'` → `["★"]`). Die Zeichenprüfung im Editor greift also auch auf die Entity-Schreibweise. Würde jemand die Entity durch ein wörtliches ★ ersetzen, bliebe die Prüfung weiterhin korrekt — würde jemand aber umgekehrt annehmen, Entities seien „nur Text" und die Dekodierung aus `FontGlyphCoverage` entfernen, wäre die Prüfung an der **einzigen ausgelieferten Vorlage** still blind.

Wer den Seed-Inhalt anfasst, muss deshalb beides zusammen denken: die Entity und die Dekodierung. Ein Kommentar im Command hält das fest.

- [ ] **Step 1: Command schreiben**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Tests\Support\TestSchema;

/**
 * Legt die Zertifikat-Vorlage "Service-Basisschulung" an.
 *
 * Warum ein Command und nicht Handarbeit im Editor: der Vorlageninhalt hat
 * eine Struktur (Klassen, Reihenfolge, Sonderzeichen-Spans), die niemand in
 * einem sechszeiligen Textarea fehlerfrei tippt. HR bearbeitet danach Woerter
 * in einer fertigen Vorlage. Fuer eine weitere Schulungsart wird kopiert und
 * der Text getauscht.
 *
 * Idempotent ueber (team_id, code).
 */
class SeedTrainingCertificateTemplate extends Command
{
    protected $signature = 'recruiting:seed-training-certificate-template
        {--team= : Team-ID (Pflicht)}
        {--dry-run : Nur anzeigen was passieren wuerde}';

    protected $description = 'Legt die Zertifikat-Vorlage ZERT-SERVICE (Service-Basisschulung) an. Idempotent via (team_id, code).';

    private const CODE = 'ZERT-SERVICE';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if ($teamId <= 0) {
            $this->error('--team ist erforderlich.');

            return self::FAILURE;
        }

        $existing = RecContractTemplate::where('team_id', $teamId)
            ->where('code', self::CODE)
            ->first();

        if ($existing) {
            $this->line("⏭ Vorlage {$existing->code} existiert bereits (#{$existing->id}) — nichts zu tun.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('[DRY-RUN] Wuerde Vorlage ' . self::CODE . " in Team {$teamId} anlegen.");

            return self::SUCCESS;
        }

        $template = DB::transaction(fn () => RecContractTemplate::create([
            'name' => 'Zertifikat Service-Basisschulung',
            'code' => self::CODE,
            'type' => RecContractTemplate::TYPE_CERTIFICATE,
            'description' => 'Teilnahme-Zertifikat der Service-Basisschulung. Ort und Kenntnisliste sind Literaltext — fuer eine andere Schulungsart kopieren und Text tauschen.',
            'content' => $this->content(),
            'field_mappings' => [
                'kontakt_vorname' => 'contact.first_name',
                'kontakt_nachname' => 'contact.last_name',
                'schulung_datum' => 'schulung.datum',
                'datum_heute' => 'meta.datum_heute',
                'schulung_leiter' => 'schulung.leiter',
            ],
            'is_active' => true,
            'sort_order' => 500,
            'team_id' => $teamId,
        ]));

        $this->info("✚ Vorlage {$template->code} angelegt (#{$template->id}).");
        $this->line('Naechster Schritt: im Vorlagen-Editor "Test-PDF" pruefen.');

        return self::SUCCESS;
    }

    private function content(): string
    {
        // Der Stern steht als HTML-Entity &#9733; und in einem span, das per
        // CSS auf DejaVu schaltet. BEIDES ist Absicht, bitte nicht aufraeumen:
        //   - Oswald hat kein U+2605. Ohne den span-Umweg steht "?" im PDF,
        //     ohne Warnung (DomPDF macht bei Custom-Fonts keinen Fallback).
        //   - FontGlyphCoverage::inspect() dekodiert Entities, die
        //     Zeichenpruefung im Vorlagen-Editor greift also auch hier.
        // Wer die Entity ersetzt, muss die Dekodierung in FontGlyphCoverage
        // mitdenken — sonst wird die Pruefung an der einzigen ausgelieferten
        // Vorlage still blind.
        $stern = '<span class="zeichen">&#9733;</span>';

        $kenntnisse = [
            'Fachgerechte Tellerschulung 2-er Obergriff',
            'Stehempfang' . $stern . 'Flying Buffet',
            'Buffetservice',
            '3-Gang-Menü fachgerecht eindecken',
            'Weinservice',
            'Gästebetreuung und Kommunikation',
        ];

        $liste = '';
        foreach ($kenntnisse as $k) {
            $liste .= '<div class="skill">' . $stern . '<span>' . $k . '</span>' . $stern . '</div>' . "\n";
        }

        return <<<HTML
<div class="lab">Herr / Frau</div>
<div class="val">{{kontakt_vorname}} {{kontakt_nachname}}</div>

<div class="lab">hat am Kurs</div>
<div class="kurs">Service-Basisschulung</div>

<div class="lab">am</div>
<div class="val">{{schulung_datum}}</div>

<div class="lab">mit Erfolg teilgenommen.</div>

<div class="intro">Bei der Schulung wurden folgende Grundkenntnisse vermittelt:</div>
{$liste}
<div class="zert-datum">Düsseldorf, den {{datum_heute}}</div>

<div class="zert-fuss-rechts">
  <div class="leiter">{{schulung_leiter}}</div>
  <div class="linie"></div>
  <div class="cap">Schulungsleiter</div>
</div>
HTML;
    }
}
```

- [ ] **Step 2: Command registrieren**

In `src/RecruitingServiceProvider.php` bei den übrigen `commands([...])`-Einträgen `SeedTrainingCertificateTemplate::class` ergänzen.

- [ ] **Step 3: Syntax + Registrierung prüfen**

Run: `php -l src/Console/Commands/SeedTrainingCertificateTemplate.php && grep -n "SeedTrainingCertificateTemplate" src/RecruitingServiceProvider.php`
Expected: ohne Fehler, ein Treffer im Provider

- [ ] **Step 4: Commit**

```bash
git add src/Console/Commands/SeedTrainingCertificateTemplate.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): Seed-Command fuer die Zertifikat-Vorlage ZERT-SERVICE"
```

---

### Task 17: Guard-Landkarte abarbeiten — die 22 Handlungszeilen

> **ENTFÄLLT — Zuschnitt v3, entschieden am 12.08.2026, vollständig, und das ist der Hauptgewinn der Entscheidung. Nicht gelöscht: die Analyse dahinter lebt weiter, siehe unten.**
>
> Die 22 Handlungszeilen existieren ausschließlich, weil eine Zertifikat-Zeile in `rec_contract_templates` für jede Query sichtbar wäre, die Vorlagen liest. Keine Zeile, nichts zu filtern.
>
> **Mitentfallen ist die Kopplung, die die Landkarte selbst als größtes Risiko benennt:** §B8 als **einzelne Ausfallstelle für 12 dieser Einträge**. Der erzwungene `code`-Präfix `ZERT-` war die einzige Garantie, dass die bestehenden Filter `AV%`, `AT-%`, `AV-default` und `IFSG` eine Zertifikat-Zeile nicht erwischen — ein Zertifikat mit `code = 'AV-ZERT'` hätte die §15/§16-Abfrage vor der Unterschrift bekommen. Dieses Risiko wird nicht bewacht und nicht verlagert, es existiert nicht mehr.
>
> **Das Merge-Gate dieses Pakets entfällt damit ebenfalls.** Es lautete „die 22 Zeilen müssen abgehakt sein"; abzuhaken ist nichts.
>
> **`docs/zertifikat/guard-landkarte-511451c.md` wird NICHT gelöscht.** Sie ist die versionierte Analyse für den Rückweg und trägt oben einen Vermerk: nicht ausgeführt, Grund, Datum, und dass der Grep in Zeile 1 gegen einen neuen Stand nachfahrbar ist. Der teure Teil war die Untersuchung, nicht die Ausführung.


**Files:**
- Consumes-Artefakt: `docs/zertifikat/guard-landkarte-511451c.md` (44 + 17 Zeilen, davon 22 mit Handlungsbedarf)
- Modify: die in der Landkarte genannten Dateien
- Test: `tests/Integration/CertificateTypeGuardsTest.php`

**Interfaces:**
- Consumes: `RecContractTemplate::scopeContracts()`, `scopeCertificates()` (Task 3)
- Produces: nichts

**Spec-Ausschnitt (wörtlich):**

> Maßgeblich ist **`docs/zertifikat/guard-landkarte-511451c.md`** — mit Spalte `erledigt` als Merge-Gate. **Merge-Gate: die 22 Zeilen mit Handlungsbedarf müssen in Spalte `erledigt` abgehakt sein.** Handlungsbedarf hat eine Zeile genau dann, wenn ihr Soll-Filter einen Filter *hinzufügt*. Nicht dazu gehören: Zeilen mit Soll-Filter „keiner" (inklusive der geerbten), sowie die zwei ausdrücklich mit **n/a** markierten Zeilen.

> Die gefährlichste ist `Applicant/Show.php:750` — `findOrFail($templateId)` ohne jeden Filter, davor nur eine `exists:`-Regel, die den Typ nicht kennt.

> `SendContractsService.php:144-145`: Zusatzvertrag-Auflösung über `legalStatus?->additionalContractTemplate`, danach Contract-Anlage auf `:154-160` mit `personalizeContent()` — **dritte Vertragsanlage in diesem Service**. Filtert heute nur `is_active`. Soll: **type='contract'** — heute nur transitiv geschützt über HrDesk:262 plus §B8.

> **Alle „keiner"-Zeilen mit code-Muster-Filter hängen an §B8 (`ZERT-`-Präfixzwang). §B8 ist einzelne Ausfallstelle für 12 Einträge; der Test dazu ist Pflicht.**

- [ ] **Step 1: Failing test für die drei kritischsten Stellen schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Tests\Support\TestSchema;

/**
 * Die Guard-Landkarte hat 22 Handlungszeilen. Drei davon koennen einen
 * echten Vertrag aus einer Zertifikat-Vorlage erzeugen und bekommen deshalb
 * einen Test:
 *  - SendContractsService:62  (AV-Trichter)
 *  - SendContractsService:144 (Zusatzvertrag, dritte Anlage)
 *  - Applicant/Show:750       (findOrFail ohne Filter)
 */
class CertificateTypeGuardsTest extends TestCase
{
    private const TEAM = 3;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        TestSchema::contractTemplates($capsule->schema());
    }

    public function testContractsScopeSchliesstZertifikateAus(): void
    {
        RecContractTemplate::create([
            'name' => 'Zert', 'code' => 'ZERT-A', 'type' => 'certificate', 'team_id' => self::TEAM,
        ]);
        RecContractTemplate::create([
            'name' => 'AV', 'code' => 'AV-010', 'team_id' => self::TEAM,
        ]);

        $ids = RecContractTemplate::query()->contracts()->pluck('code')->all();

        $this->assertSame(['AV-010'], $ids);
    }

    public function testEinZertifikatCodeKannNieMitAvOderAtBeginnen(): void
    {
        // Das ist die Zusicherung, an der 12 code-Muster-Filter der
        // Guard-Landkarte haengen.
        foreach (['AV-ZERT', 'AT-140', 'IFSG', 'AV-default'] as $code) {
            try {
                RecContractTemplate::create([
                    'name' => 'X', 'code' => $code, 'type' => 'certificate', 'team_id' => self::TEAM,
                ]);
                $this->fail("code '{$code}' haette abgewiesen werden muessen.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CertificateTypeGuardsTest`
Expected: PASS (2 tests) — beide Zusicherungen kommen aus Task 3

**Warum dieser Task nicht gesplittet wird:** Die 22 Zeilen sind mechanisch gleichförmig — dieselbe Frage, dieselbe Antwortform, oft dieselbe Datei. Ein Split über drei Tasks würde denselben Kontext dreimal aufbauen, ohne dass ein Reviewer eine der drei Gruppen sinnvoll ablehnen könnte, während er die anderen annimmt. (Das Gate wäre **kein** Argument: es ist ein Grep auf eine Datei und läuft, wo man ihn hinlegt.)

- [ ] **Step 3: Die 22 Handlungszeilen abarbeiten — Häkchen pro Zeile, sofort**

Für jede Zeile mit Handlungsbedarf in `docs/zertifikat/guard-landkarte-511451c.md`, **eine nach der anderen**:

1. Den Soll-Filter aus der Spalte anwenden. Für Eloquent-Abfragen ist das `->contracts()` bzw. `->certificates()`; für `Applicant/Show.php:696` ein Rule-Objekt statt `exists:`; für `Tools/CreateContractTool.php:87` zusätzlich `ToolResult::error('VALIDATION_ERROR', …)`; für `Tools/ListContractTemplatesTool.php:56` ein optionaler `type`-Parameter mit Default `contract`.
2. **Sofort danach** die Spalte `erledigt` in genau dieser Zeile auf `x` setzen — nicht am Ende alle in einem Rutsch. Sonst zählt der Gate-Grep in Step 4 zweiundzwanzig Häkchen aus der Erinnerung statt aus der Arbeit.
3. Nach jeweils drei bis fünf Zeilen die Gesamtsuite laufen lassen.

**Reihenfolge:** zuerst die drei Stellen mit Vertragsanlage (`SendContractsService:62`, `SendContractsService:144-145`, `Applicant/Show.php:750`), dann die Dropdowns und Validierungen, dann die MCP-Tools, zuletzt die Commands.

- [ ] **Step 4: Gate-Grep — eigener Schritt, Ausgabe zurückmelden**

Run:
```bash
grep '^| ' docs/zertifikat/guard-landkarte-511451c.md | tail -n +3 \
  | grep -v '| keiner' | grep -v '\*\*n/a\*\*' | grep -c '| x |'
```
Expected: `22`

Kommt eine kleinere Zahl, fehlt ein Häkchen **oder** eine Änderung. Beides ist ein Gate-Verstoß — nicht weitermachen, nicht die Zahl im Kopf korrigieren. Die Rohausgabe dieses Greps gehört in die Rückmeldung des Tasks.

- [ ] **Step 5: Gesamtsuite**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(recruiting): type-Guards an allen 22 Stellen der Guard-Landkarte"
```

---

## Nach dem Plan — nicht Teil der Tasks

**Vor dem Deploy zu klären (aus der Spec):**

- **V2-Abfrage auf dem Server** (Interviewer-Befüllung bei Alt-Terminen). Auslegung vorab festgelegt: `termine_ohne_interviewer` ≤ 2 → leerer String bleibt; größer → Team-Setting `training_certificate_leader_fallback` als Rückfall; regelmäßig ≥3 Interviewer → `TrainingLeaderResolver::leaderNames()` auf „erster Interviewer" umstellen und den Render-Test um diesen Fall erweitern.
- **Logo und Unterschrift im Original** von RheinGedeck besorgen — die abgelegten PNGs sind aus dem 300-dpi-Scan per Schwellenwert freigestellt.
- **WhatsApp-Template bei Meta einreichen**: Body-Variable `zertifikat_link`, **kein** URL-Button.
- **Settings setzen:** `training_certificate_wa_template_id`, `default_certificate_template_id`.
- **`storage/fonts` muss existieren und schreibbar sein** — dort legt DomPDF den Font-Metrik-Cache an. Erster Render nach Deploy prüfen.

**Deploy-Reihenfolge:** Migrationen (Task 1+2) zuerst pushen, Feature danach — Task 10 bringt eine öffentlich erreichbare Route, die ohne Tabelle 500er wirft. Danach `composer.lock`-Bump in `meingedeck`. Kein `queue:restart` (kein Worker-Code in diesem Paket).

**Live-Sichttest** nach dem Deploy: die fünf Schritte aus der Spec, inklusive „Zeichen prüfen" mit einem eingefügten ★ und der Prüfung, dass das Bewerber-Portal nach der Ausstellung nicht mehr „leer" meldet.

---

### Task 18: Bedienelement für den Team-Schalter `issue_training_certificates`

**Nachträglich eingefügt am 13.08.2026, nach Task 12.** Die Nummer 18 ist frei, weil die ursprüngliche Task 18 zu Task 0 wurde; 15, 16 und 17 bleiben als „entfällt" stehen und werden nicht neu belegt.

**Anlass, gemessen:**

```
$ grep -rln "issue_training_certificates" resources/views/ src/Livewire/ | wc -l
       0
```

Der Schalter existiert in `RecApplicantSettings::DEFAULT_SETTINGS` (Default `false`) und wird von Task 8, 11 und 13 geprüft — aber **kein Task des Plans legt ein Bedienelement dafür an.** Live wäre er damit nur per SQL einschaltbar, und weil der Default `false` ist, heißt das: **nach dem Deploy ist das Feature aus und niemand kann es über die Oberfläche einschalten.**

Der Abschaltweg war eine ausdrückliche Vorgabe (§C3), damit man nicht deployen muss, um das Feature stillzulegen. Der Einschaltweg fehlt aus demselben Grund. Und ein Feature-Flag, das nur die Datenbank kennt, ist in einem halben Jahr ein toter Schalter — von denen hat dieses Paket schon zwei gefunden.

**WARUM EIGENER TASK, NICHT IN TASK 14 EINGEBAUT** (Entscheidung des Auftraggebers): Task 14 ist der zweite Blade-Task und fällt in Dateien, die niemand testen kann (Livewire ist im Modul nicht instanziierbar). **Zwei Blade-Änderungen in einem Task heißt, dass beim ersten Klick nach dem Deploy zwei Dinge gleichzeitig falsch sein können.**

**Files:**
- Modify: das Einstellungs-Modal der Bewerber-Einstellungen (dieselbe Fläche, in der `minor_rejection_template_id` und `training_certificate_wa_template_id` sitzen)

**AUFLAGE ZUM EINFÜGEPUNKT — der Grund ist gemessen, nicht vermutet.** Bei Task 12 hätte mein erster Vorschlag für den Einfügepunkt **den Jugendschutz-Hinweis von seinem Select getrennt.** Genau deshalb wird das nicht nebenbei gemacht. Der Task liefert im Report eine **Vorher-Nachher-Ansicht der betroffenen Modal-Sektion**, nicht nur den Zeilen-Diff — man muss sehen, was neben was steht.

**AUFLAGE ZUM MELDEKANAL** (aus Task 12, wo es teuer war): prüf, ob Meldungen dieser Fläche über einen Kanal gehen, den die Seite **tatsächlich rendert**. Bei Task 12 wäre `flash('error')` unsichtbar geblieben — die HR-Seite rendert nur `session('message')`, das Core-Layout nichts. Der Fallback für den Fehlerfall wäre selbst stumm gewesen.

**Erinnerung an ein Bestandsverhalten, das hier zählt:** `RecApplicantSettings::getSetting()` liest `$settings[$key] ?? $default ?? (DEFAULT_SETTINGS[$key] ?? null)`. Bei einer **bestehenden** Zeile ohne den Schlüssel trägt allein der Default. Das Bedienelement muss den Schlüssel beim Speichern also **wirklich in die `settings`-Spalte schreiben** — zeigt es nur `true` an, hängt der Live-Zustand weiter am Default. Task 13 hat die Auflage, das zu messen; nimm sein Ergebnis auf.

- [ ] **Step 1: Einfügepunkt bestimmen und die Sektion vorher festhalten**
- [ ] **Step 2: Schalter einbauen**
- [ ] **Step 3: Blade-Check (`php tools/blade-check.php`) und Gesamtsuite**
- [ ] **Step 4: Vorher-Nachher-Ansicht der Sektion in den Report**
- [ ] **Step 5: Commit**
