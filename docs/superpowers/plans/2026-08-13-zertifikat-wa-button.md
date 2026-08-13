# Zertifikat-Link als dynamischer URL-Button — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Zertifikat-Link geht per WhatsApp als dynamischer URL-Button raus statt als Body-Variable im Fließtext, über einen eigenen Sendepfad mit direktem `WhatsAppMetaService::sendTemplate()`.

**Architecture:** Der Umbau bleibt in `TrainingCertificateWhatsAppDelivery`. Eine neue Laravel-freie Support-Klasse liest die Button-Struktur eines Meta-Templates (Guard), `HoldingTemplateSender` bekommt **eine** lesende öffentliche Methode für Template+Kanal, und `HoldingTemplateComponents::build()` wird für den Body weiter **aufgerufen** (nicht erweitert). Der Body-Variablen-Weg wird ersetzt, nicht als Fallback behalten.

**Tech Stack:** PHP 8.2+, Laravel (Host-App `meingedeck`), Livewire 3, PHPUnit 10 ohne Laravel-Bootstrap, WhatsApp Cloud API (Meta) über `Platform\Crm\Services\Comms\WhatsAppMetaService`.

**Spec:** `docs/superpowers/specs/2026-08-13-zertifikat-wa-button-design.md` — Gate 1 bestanden am 2026-08-13. Der Plan argumentiert aus der Spec; wer eine Task ausführt, liest beide. Verweise der Form `W4`, `H2`, `T-3`, `B1` zeigen in die Spec.

## Global Constraints

- **Nur `platforms-recruiting` wird geändert.** Kein Edit in `platforms-core`, `platform-crm`, `platform-hcm`, `platform-integrations` — auch nicht „nur ein Kommentar". `WhatsAppMetaService` und `CommsChannel` werden **benutzt**, nicht angefasst.
- **Testrunner:** `cd /Users/shaustein/Documents/dev/platforms/meingedeck && vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml`. Das Modul hat kein eigenes `vendor/`.
- **Voller Lauf muss grün bleiben.** Ausgangsstand main `9382981`: **`OK (771 tests, 2325 assertions)`** — in Task 1 nachgemessen. (Die `746 tests, 2239 assertions` aus `docs/zertifikat/folgeliste.md` waren der Stand beim Schreiben jener Datei, nicht der von `9382981`.) Kein `--order-by=random` (Folgeliste F3, vorbestehend).
- **Erwartete Testzahlen nach jeder Task**, auf der korrigierten Basis: Task 1 → **779** (gemessen, `OK (779 tests, 2342 assertions)`), Task 2 → **783**, Task 3 und 4 → keine feste Zahl, dort verschieben sich Methoden; Kriterium ist „keine Fehler".
- **Kein Laravel-Bootstrap in Tests.** `tests/bootstrap.php` ist ein reiner Autoloader. Unit-Tests bleiben dependency-frei; Integrationstests bauen Container und `Capsule` von Hand.
- **PHP-Kommentare und Docblocks ohne Umlaute** (`faellt`, `gepruefte`, `Loesung`) — Modulstil, in jeder berührten Datei sichtbar. Markdown-Dokumente dagegen mit echten Umlauten.
- **Blade-Änderungen prüfen mit `php tools/blade-check.php`**, nicht mit `php -l` (das prüft an `.blade.php` nichts). Bekannte Lücke: unbalancierte `{{--`-Kommentare erkennt es nicht (Folgeliste F6) — beim Bearbeiten selbst hinsehen.
- **Blade-Fallstricke:** `x-ui-*`-Komponenten brechen still bei inline-`@if` in Attributen und bei `:required`-`??`-Fallbacks; Werte vorher berechnen, Block-Form benutzen.
- **Commit-Stil:** deutsch, `feat(recruiting):` / `test(recruiting):` / `docs(recruiting):`, und jeder Commit endet mit
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`
- **Branch:** `feat/zertifikat-wa-button`, Basis main `9382981`. Kein Push, kein Merge, kein `meingedeck`-Bump in diesem Plan.
- **Bindende Fakten aus der Spec, wörtlich:** Button-URL bei Meta ist `https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/` + variabler Rest (H2, im Meta-Manager geprüft am 2026-08-13). Der Button-Parameter trägt **nur die `uuid`**, nie die vollständige URL. Button-Position ist **0**.

## File Structure

| Datei | Verantwortung | Task |
| --- | --- | --- |
| `src/Support/WhatsAppTemplateUrlButtons.php` | **neu.** Liest die Buttons eines Meta-Templates: welche URL-Buttons tragen eine Variable, an welcher Position, und eine Klartext-Aufzählung für die Fehlermeldung. Laravel-frei. | 1 |
| `tests/Unit/WhatsAppTemplateUrlButtonsTest.php` | **neu.** T-1. | 1 |
| `src/Services/Comms/HoldingTemplateSender.php` | **+1 Methode.** `resolveTarget()` reicht das private `resolveConfig()` lesend nach außen. `sendToMany`/`sendOne`/`configuredTemplateName`/`resolveConfig` bleiben Zeile für Zeile unverändert. | 2 |
| `tests/Integration/HoldingTemplateSenderResolveTargetTest.php` | **neu.** Auflösung gegen echte Migrationen: Erfolg und „kein aktiver Kanal". | 2 |
| `src/Services/TrainingCertificateWhatsAppDelivery.php` | **Umbau.** Direkter `sendTemplate()`, Button-Component, Guard-Wechsel, Log-Marker. Signatur und Rückgabeform bleiben. | 3 |
| `tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php` | **Umbau.** Zweiter Stub (Meta-Service), Button-Fixtures, T-2/T-3/T-5/T-6/T-9. | 3 |
| `src/Support/TrainingCertificateWaTemplate.php` | **Konstanten-Tausch.** `BODY_VARIABLE` raus, `UUID_SENTINEL` + reiner Sentinel-Tausch rein. Bleibt Laravel-frei. | 4 — **aber `URL_BUTTON_INDEX` legt schon Task 3 an** (Step 5 dort, mit Grund); Task 4 liest sie nur noch |
| `src/Livewire/Applicant/ApplicantSettingsModal.php` | **+1 Computed.** Leitet die erwartete Meta-Button-URL aus der Route ab. | 4 |
| `resources/views/livewire/applicant/applicant-settings-modal.blade.php` | **Hinweistext.** Kein URL-Literal, abgeleiteter Wert + Preis aus T1. | 4 |
| `src/Models/RecApplicantSettings.php` | **Kommentar** am Default `training_certificate_wa_template_id` (`:60-69`). | 4 |
| `tests/Unit/WhatsAppTemplateBodyVariablesTest.php` | **Umbau** von `:228-249` (Pin-Test) + Docblock von `:200`. | 4 |
| `tests/Integration/TrainingCertificatePublicRouteTest.php` | **+1 Test.** Abgeleitete Form gegen die Testerwartung `/recruiting/zertifikat/{{1}}`. | 4 |

**Nicht angefasst, bewusst:** `HoldingTemplateComponents.php` (nur neuer Leser), `Applicant/Show.php`, `RecInterview.php`, `RecApplicant.php`, `RecEmployee.php`, `ProcessAutoPilotApplicants.php`, `InterviewSchedule/Index.php`, `HrDesk/Index.php`, `routes/public.php`, `WhatsAppTemplateBodyVariables.php` (außer Docblock-Verweis in Task 4).

---

### Task 1: Guard-Klasse `WhatsAppTemplateUrlButtons`

**Files:**
- Create: `src/Support/WhatsAppTemplateUrlButtons.php`
- Test: `tests/Unit/WhatsAppTemplateUrlButtonsTest.php`

**Interfaces:**
- Consumes: nichts. Erste Task, keine Abhängigkeit.
- Produces:
  - `WhatsAppTemplateUrlButtons::dynamicIndexes(?array $templateComponents): list<int>` — Positionen der URL-Buttons **mit** `{{` in der URL, in Vorkommens-Reihenfolge.
  - `WhatsAppTemplateUrlButtons::hasDynamicAt(?array $templateComponents, int $index): bool`
  - `WhatsAppTemplateUrlButtons::describe(?array $templateComponents): list<string>` — je Button eine Zeile `"Position 0: URL mit Variable"` für die Fehlermeldung.

**Kontext für den Ausführenden:** Meta-Templates tragen ihre Buttons in einer Komponente `['type' => 'BUTTONS', 'buttons' => [...]]`. Jeder Button hat `type` (`URL`, `QUICK_REPLY`, `PHONE_NUMBER`) und bei `URL` ein `url`. Der Button ist **dynamisch**, wenn die URL eine Variable enthält (`{{1}}`) — nur dann darf ein Parameter mitgeschickt werden. Die Position zählt über alle Buttons in Vorkommens-Reihenfolge, weil Meta sie so indiziert. Das Vorbild im Modul ist `src/Support/WhatsAppTemplateBodyVariables.php` — gleiche Bauart, gleicher Docblock-Ton, dependency-frei.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/WhatsAppTemplateUrlButtonsTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Was ein Meta-Template ueber seine Buttons hergibt — die Frage „darf ich hier
 * einen URL-Parameter mitschicken, und an welche Position?".
 *
 * WOZU: der Sender setzt den Button-Parameter auf index 0 (an allen sechs
 * Sendestellen des Moduls hartkodiert, Spec H3). Ein Template mit Quick-Reply
 * an 0 und URL-Button an 1 bekaeme den Parameter also an die falsche
 * Komponente. Und ein STATISCHER URL-Button darf ueberhaupt keinen Parameter
 * bekommen — fuenf der sieben Erkennungsstellen im Modul pruefen das nicht
 * (Spec H1), diese Klasse prueft es.
 */
class WhatsAppTemplateUrlButtonsTest extends TestCase
{
    /** Ein dynamischer URL-Button an Position 0 — der Normalfall. */
    public function testDynamischerButtonAnPositionNull(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Zertifikat', 'url' => 'https://example.org/recruiting/zertifikat/{{1}}'],
            ]],
        ];

        $this->assertSame([0], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
        $this->assertTrue(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 0));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 1));
    }

    /**
     * Quick-Reply an 0, dynamischer URL-Button an 1.
     *
     * DER FALL, DER EINE FALSCHE MELDUNG ERZEUGT, wenn man nur „gibt es einen
     * Button" fragt: es gibt einen, er steht nur woanders. Deshalb Positionen
     * statt bool.
     */
    public function testDynamischerButtonAnPositionEins(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'text' => 'Zertifikat', 'url' => 'https://example.org/zertifikat/{{1}}'],
            ]],
        ];

        $this->assertSame([1], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 0));
        $this->assertTrue(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 1));
    }

    /**
     * Statischer URL-Button: type stimmt, Variable fehlt.
     *
     * Der wichtigste Negativfall des Tasks. Ein Parameter fuer einen statischen
     * Button ist ein Parameter zu viel; RecInterview.php:162 und
     * InterviewSchedule/Index.php:145 pruefen deshalb auf '{{', die anderen
     * fuenf Stellen nicht.
     */
    public function testStatischerUrlButtonZaehltNicht(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Website', 'url' => 'https://example.org/karriere'],
            ]],
        ];

        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 0));
    }

    public function testQuickReplyAlleinZaehltNicht(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Ja'],
                ['type' => 'QUICK_REPLY', 'text' => 'Nein'],
            ]],
        ];

        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
    }

    public function testOhneButtonsKomponenteUndOhneKomponentenUeberhaupt(): void
    {
        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes([
            ['type' => 'BODY', 'text' => 'Hallo'],
        ]));
        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes([]));
        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes(null));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt(null, 0));
    }

    /** Kaputte Struktur darf nicht werfen — Meta-JSON ist nicht unser Schema. */
    public function testUnerwarteteStrukturWirftNicht(): void
    {
        $components = [
            'kein-array',
            ['type' => 'BUTTONS'],
            ['type' => 'BUTTONS', 'buttons' => 'auch-kein-array'],
            ['type' => 'BUTTONS', 'buttons' => ['kein-array', ['type' => 'URL']]],
        ];

        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
    }

    /**
     * describe() liefert Typ UND Position im Klartext — das Material fuer die
     * Fehlermeldung an HR. Ohne die Position sagt die Meldung nicht, was zu tun
     * ist (Spec W5).
     */
    public function testDescribeNenntTypUndPosition(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'text' => 'Zertifikat', 'url' => 'https://example.org/z/{{1}}'],
                ['type' => 'URL', 'text' => 'Website', 'url' => 'https://example.org/karriere'],
            ]],
        ];

        $this->assertSame([
            'Position 0: QUICK_REPLY',
            'Position 1: URL mit Variable',
            'Position 2: URL ohne Variable',
        ], WhatsAppTemplateUrlButtons::describe($components));
    }

    public function testDescribeOhneButtonsIstLeer(): void
    {
        $this->assertSame([], WhatsAppTemplateUrlButtons::describe(null));
        $this->assertSame([], WhatsAppTemplateUrlButtons::describe([['type' => 'BODY', 'text' => 'x']]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter WhatsAppTemplateUrlButtonsTest
```
Expected: FAIL — `Error: Class "Platform\Recruiting\Support\WhatsAppTemplateUrlButtons" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Support/WhatsAppTemplateUrlButtons.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Liest die Buttons eines Meta-Templates — also die Frage „darf ich hier einen
 * URL-Parameter mitschicken, und an welcher Position sitzt der Button?".
 *
 * WOZU: der Sendepfad setzt den Button-Parameter auf index 0. Das ist an allen
 * sechs Sendestellen des Moduls hartkodiert (Spec H3), nicht ermittelt. Zwei
 * Faelle gehen davon still schief, und beide fuehren zu einer Nachricht ohne
 * brauchbaren Link an einen abgelehnten Bewerber:
 *
 *  1. Der dynamische URL-Button sitzt NICHT an Position 0 (z.B. Quick-Reply an
 *     0). Der Parameter landet dann an der falschen Komponente.
 *  2. Der URL-Button ist STATISCH — seine URL traegt keine Variable. Dann ist
 *     jeder Parameter einer zu viel.
 *
 * DAS KRITERIUM IST ABSICHTLICH DAS STRENGERE der beiden im Modul vorhandenen:
 * `type === 'URL'` UND `{{` in der URL, wie RecInterview.php:162 und
 * InterviewSchedule/Index.php:145. Die anderen fuenf Erkennungsstellen (Spec
 * H1) pruefen nur den Typ; ihre laschere Fassung wird hier NICHT kopiert, sie
 * ist der Defekt 2 aus Spec H4.
 *
 * POSITIONEN STATT bool, und das ist der Punkt: mit einem bool lautete die
 * Fehlermeldung „kein URL-Button gefunden" auch dann, wenn es einen gibt und er
 * nur an der falschen Stelle sitzt. Die richtige Anweisung ist dann „Button an
 * die erste Position verschieben" — die kann nur eine Positionsliste hergeben.
 *
 * Schwesterklasse fuer den Body: WhatsAppTemplateBodyVariables. Beide sind
 * dependency-frei -> unit-testbar (Modul-Test-Konvention).
 */
final class WhatsAppTemplateUrlButtons
{
    /**
     * Positionen der URL-Buttons MIT Variable, in Vorkommens-Reihenfolge.
     *
     * Gezaehlt wird ueber alle Buttons aller BUTTONS-Komponenten hinweg, weil
     * Meta sie so indiziert. Meta erlaubt heute nur eine BUTTONS-Komponente;
     * die Schleife ueber mehrere kostet nichts und macht die Zaehlung
     * unabhaengig von dieser Zusage.
     *
     * @param  array<int, mixed>|null  $templateComponents  Meta-Komponenten (JSON-decodiert)
     * @return list<int>
     */
    public static function dynamicIndexes(?array $templateComponents): array
    {
        $indexes = [];
        $position = 0;

        foreach ($templateComponents ?? [] as $component) {
            if (!is_array($component) || ($component['type'] ?? '') !== 'BUTTONS') {
                continue;
            }

            $buttons = $component['buttons'] ?? [];
            if (!is_array($buttons)) {
                continue;
            }

            foreach ($buttons as $button) {
                if (is_array($button) && self::istDynamisch($button)) {
                    $indexes[] = $position;
                }
                $position++;
            }
        }

        return $indexes;
    }

    /** Sitzt an genau dieser Position ein dynamischer URL-Button? */
    public static function hasDynamicAt(?array $templateComponents, int $index): bool
    {
        return in_array($index, self::dynamicIndexes($templateComponents), true);
    }

    /**
     * Je Button eine Klartextzeile mit Typ und Position — das Material fuer die
     * Fehlermeldung an HR.
     *
     * Der Text steht hier und nicht in der Meldung selbst, damit die Meldung
     * ohne Container testbar bleibt und beide Zweige (kein Button / falsche
     * Position) dieselbe Aufzaehlung benutzen.
     *
     * @param  array<int, mixed>|null  $templateComponents
     * @return list<string>
     */
    public static function describe(?array $templateComponents): array
    {
        $zeilen = [];
        $position = 0;

        foreach ($templateComponents ?? [] as $component) {
            if (!is_array($component) || ($component['type'] ?? '') !== 'BUTTONS') {
                continue;
            }

            $buttons = $component['buttons'] ?? [];
            if (!is_array($buttons)) {
                continue;
            }

            foreach ($buttons as $button) {
                $typ = is_array($button) ? (string) ($button['type'] ?? '?') : '?';

                if ($typ === 'URL') {
                    $typ .= self::istDynamisch(is_array($button) ? $button : [])
                        ? ' mit Variable'
                        : ' ohne Variable';
                }

                $zeilen[] = sprintf('Position %d: %s', $position, $typ);
                $position++;
            }
        }

        return $zeilen;
    }

    /**
     * URL-Button mit Variable in der URL.
     *
     * `str_contains(…, '{{')` und nicht eine Regex auf {{1}}: Meta erlaubt in
     * der Button-URL genau einen Parameter und schreibt ihn als {{1}}, aber die
     * Schreibweise ist nicht unsere und ein toleranter Test ist hier der
     * sicherere — wir wollen wissen, OB die URL variabel ist, nicht wie sie
     * heisst.
     *
     * @param  array<string, mixed>  $button
     */
    private static function istDynamisch(array $button): bool
    {
        return ($button['type'] ?? '') === 'URL'
            && str_contains((string) ($button['url'] ?? ''), '{{');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter WhatsAppTemplateUrlButtonsTest
```
Expected: PASS, 8 Tests.

- [ ] **Step 5: Run the full suite**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml
```
Expected: `OK` — 771 + 8 = **779 Tests**, keine Fehler. **Erledigt und gemessen:** `OK (779 tests, 2342 assertions)`, Commit `6602e03`. Eine neue Datei ohne Aufrufer kann nichts anderes bewegen.

- [ ] **Step 6: Commit**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git add src/Support/WhatsAppTemplateUrlButtons.php tests/Unit/WhatsAppTemplateUrlButtonsTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Button-Struktur eines Meta-Templates lesbar machen

Positionen statt bool, und das strengere der beiden im Modul vorhandenen
Kriterien: URL-Button zaehlt nur mit Variable in der URL. Damit ist der Fall
"Button vorhanden, aber an der falschen Position" von "kein Button vorhanden"
unterscheidbar — die beiden brauchen verschiedene Anweisungen an HR.

Noch ohne Aufrufer; der Guard-Wechsel kommt mit dem Umbau des Sendepfads.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `resolveTarget()` am `HoldingTemplateSender`

**Files:**
- Modify: `src/Services/Comms/HoldingTemplateSender.php` (neue Methode nach `configuredTemplateName()`, `:87-91`)
- Test: `tests/Integration/HoldingTemplateSenderResolveTargetTest.php` (neu)

**Interfaces:**
- Consumes: nichts aus Task 1.
- Produces:
  - `HoldingTemplateSender::resolveTarget(int $teamId, string $settingsKey = 'comms_holding_template_id'): array` mit der Form
    `array{error: ?string, template: ?IntegrationsWhatsAppTemplate, channel: ?CommsChannel}` — identisch zu `resolveConfig()`.

**Kontext für den Ausführenden:** Der direkte Sendepfad in Task 3 braucht ein Template-Objekt und einen `CommsChannel`. Heute liefert das `resolveConfig()` — `private`, vier Queries: Settings → Template (`status === 'APPROVED'`) → WhatsApp-Account (`auto_pilot_wa_account_id` gewinnt über `$template->whatsapp_account_id`) → aktiver `CommsChannel` mit passendem `sender_identifier`. Diese Kette wird **nicht** nachgebaut (das wäre die achte Kopie im Modul) und **nicht** extrahiert (der Sender trägt drei fremde Sendewege: Holding-Bestätigung, OOO-Auto-Reply, Voice-Note-Antwort). Sie wird lesend geöffnet. Spec W2, Q2, T4.

`HoldingTemplateSender` ist `final` und hat einen Konstruktor-Parameter `WhatsAppMetaService` — im Test wird dafür eine Attrappe übergeben, die nie aufgerufen wird.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/HoldingTemplateSenderResolveTargetTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * resolveTarget() — die Aufloesung Settings -> Template -> Account -> Kanal,
 * lesend und ohne zu senden.
 *
 * WARUM DIESE METHODE UEBERHAUPT EXISTIERT: der Zertifikat-Versand ruft
 * WhatsAppMetaService::sendTemplate() direkt (Spec W1) und braucht dafuer
 * Template und Kanal. Die Kette nachzubauen waere die achte Kopie im Modul; sie
 * aus dem Sender herauszuziehen hiesse, einen Pfad anzufassen, der auch
 * Holding-Bestaetigung, OOO-Auto-Reply und Voice-Note-Antwort traegt (Spec Q2).
 * Also: lesender Zugang, kein Umbau.
 *
 * GEPRUEFT WIRD GEGEN DIE ECHTEN MIGRATIONEN, nicht gegen ein handgebautes
 * Schema: die Methode lebt von Spaltennamen (`sender_identifier`, `active`,
 * `is_active`), und genau die soll ein Rename in einem Nachbarmodul hier rot
 * machen statt still gruen laufen zu lassen.
 *
 * PROZESSWEITER ZUSTAND: diese Klasse setzt Container-Instanzen und eine
 * Facade-Wurzel und raeumt beide selbst wieder weg. Der Schaden traefe sonst
 * SPAETERE Testklassen und faellt nur im Gesamtlauf auf (Modulmuster, siehe
 * TrainingCertificateWhatsAppDeliveryTest).
 */
class HoldingTemplateSenderResolveTargetTest extends TestCase
{
    private const TEAM = 71;

    private const SETTINGS_KEY = 'training_certificate_wa_template_id';

    /** Nummer des WhatsApp-Accounts — der Kanal wird darueber gefunden. */
    private const ACCOUNT_NUMMER = '+49 100 5550000';

    private static int $templateId = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        // Jeder Test darf den Kanal umschalten; der naechste faengt aktiv an.
        Capsule::table('comms_channels')->update(['is_active' => true]);
        parent::tearDown();
    }

    private function sender(): HoldingTemplateSender
    {
        // Die Attrappe wird nie aufgerufen — resolveTarget sendet nicht. Sie
        // steht nur da, weil der Konstruktor sie verlangt.
        $whatsApp = new class extends \Platform\Crm\Services\Comms\WhatsAppMetaService {};

        return new HoldingTemplateSender($whatsApp);
    }

    public function testAufloesungLiefertTemplateUndKanal(): void
    {
        $target = $this->sender()->resolveTarget(self::TEAM, self::SETTINGS_KEY);

        $this->assertNull($target['error'], 'Vollstaendig konfiguriert -> kein Fehler.');
        $this->assertInstanceOf(IntegrationsWhatsAppTemplate::class, $target['template']);
        $this->assertSame(self::$templateId, (int) $target['template']->id);
        $this->assertInstanceOf(CommsChannel::class, $target['channel']);
        $this->assertSame(self::ACCOUNT_NUMMER, $target['channel']->sender_identifier);
    }

    /**
     * Der Kanal ist die letzte Stufe der Kette und die einzige, die im Betrieb
     * unabhaengig vom Template umgelegt wird (Kanal deaktiviert, Nummer
     * gewechselt). Deshalb dieser Negativfall und nicht ein anderer.
     */
    public function testOhneAktivenKanalKommtDerFehlerStringZurueck(): void
    {
        Capsule::table('comms_channels')->update(['is_active' => false]);

        $target = $this->sender()->resolveTarget(self::TEAM, self::SETTINGS_KEY);

        $this->assertSame('Kein aktiver WhatsApp-Kanal fuer den Account.', $target['error']);
        $this->assertNull($target['template']);
        $this->assertNull($target['channel']);
    }

    /**
     * Ein leerer Settings-Key faellt in denselben Fehler wie im Sendeweg — und
     * die Meldung nennt das Eingangsbestaetigungs-Template, egal welcher
     * Schluessel gefragt wurde. Genau deshalb behaelt der Zertifikat-Versand
     * seinen eigenen not_configured-Zweig VOR dieser Aufloesung (Spec W2).
     */
    public function testUnkonfiguriertesTeamMeldetDenGenerischenText(): void
    {
        $target = $this->sender()->resolveTarget(9999, self::SETTINGS_KEY);

        $this->assertNotNull($target['error']);
        $this->assertStringContainsString('Eingangsbestaetigungs-Template', $target['error']);
    }

    /**
     * resolveConfig() bleibt private und in seiner Signatur unveraendert.
     *
     * Der Zugang ist ausdruecklich EIN Durchreicher, kein Umbau: sobald jemand
     * resolveConfig oeffnet oder seine Parameter aendert, ist das eine Aenderung
     * an einem Pfad mit drei fremden Aufrufern und soll auffallen.
     */
    public function testResolveConfigBleibtPrivat(): void
    {
        $methode = new \ReflectionMethod(HoldingTemplateSender::class, 'resolveConfig');

        $this->assertTrue($methode->isPrivate(), 'resolveConfig darf nicht oeffentlich werden.');
        $this->assertSame(2, $methode->getNumberOfParameters());

        $zugang = new \ReflectionMethod(HoldingTemplateSender::class, 'resolveTarget');
        $this->assertTrue($zugang->isPublic());
        $this->assertSame(
            ['teamId', 'settingsKey'],
            array_map(fn ($p) => $p->getName(), $zugang->getParameters()),
            'Gleiche Parameter wie resolveConfig, gleiche Reihenfolge.'
        );
    }

    /**
     * Schema aus den ECHTEN Migrationen (Modulmuster). comms_channels traegt
     * Fremdschluessel auf `teams` und `comms_provider_connections`; die Tabellen
     * fehlen hier und muessen es auch nicht geben — sqlite erzwingt die
     * Referenz nicht. Nachgemessen im Bestand: SettingsModalToggleWriteTest:308
     * laedt dieselbe Migration ohne diese Tabellen.
     */
    private static function runRealMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-resolve-target',
            'phone_number' => self::ACCOUNT_NUMMER,
            'title' => 'Test-Account',
            'active' => true,
        ]);

        self::$templateId = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-resolve-target',
            'name' => 'zert_button',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hallo {{name}}']],
            'whatsapp_account_id' => $accountId,
        ])->id;

        Capsule::table('comms_channels')->insert([
            'team_id' => self::TEAM,
            'type' => 'whatsapp',
            'provider' => 'whatsapp_meta',
            'sender_identifier' => self::ACCOUNT_NUMMER,
            'is_active' => true,
        ]);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => [self::SETTINGS_KEY => self::$templateId],
        ]);
    }

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);

        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
```

**Zwei Dinge, die beim ersten Lauf abweichen können und dann im Test korrigiert werden, nicht im Produktivcode:**
1. **Der Fehlertext** in `testOhneAktivenKanalKommtDerFehlerStringZurueck` und `testUnkonfiguriertesTeamMeldetDenGenerischenText` steht in `HoldingTemplateSender.php:103` und `:131` **mit echten Umlauten** („Eingangsbestätigungs-Template", „Kanal für den Account"). Die Assertion muss den Text tragen, der dort wirklich steht — im Zweifel `:96-135` lesen und wörtlich übernehmen. Den Produktivtext **nicht** anpassen; er erscheint in der Host-App.
2. **Pflichtspalten** von `integrations_whatsapp_accounts` / `_templates` / `comms_channels`: falls eine `NOT NULL`-Spalte ohne Default fehlt (z.B. `user_id`), meldet sqlite das beim Insert. Dann die Spalte im Fixture ergänzen — Vorbild `TrainingCertificateWhatsAppDeliveryTest:825-860`, das dieselben Tabellen füllt und dort `user_id` mitgibt.

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter HoldingTemplateSenderResolveTargetTest
```
Expected: FAIL — `Error: Call to undefined method …HoldingTemplateSender::resolveTarget()` (bzw. `ReflectionException` in `testResolveConfigBleibtPrivat`).

- [ ] **Step 3: Write minimal implementation**

In `src/Services/Comms/HoldingTemplateSender.php`, **direkt nach** `configuredTemplateName()` (endet `:91`) und **vor** `private function resolveConfig` (`:96`) einfügen:

```php
    /**
     * Template + Kanal fuer einen Settings-Key — LESEND, ohne zu senden.
     *
     * WOZU: der Zertifikat-Versand ruft WhatsAppMetaService::sendTemplate()
     * direkt, weil er einen dynamischen URL-Button fuellen muss, den
     * HoldingTemplateComponents::build() strukturell nicht kann. Er braucht
     * dafuer genau das, was resolveConfig() ohnehin ermittelt.
     *
     * WARUM DURCHREICHEN UND NICHT EXTRAHIEREN: die Kette in resolveConfig
     * (Settings -> Template -> Account -> Kanal, vier Queries) sie in eine
     * eigene Klasse zu ziehen, waere die sauberere Form und ein Refactoring an
     * einem Pfad, der auch die Holding-Bestaetigung, den OOO-Auto-Reply und die
     * Voice-Note-Antwort traegt. Bewusst als Folgepunkt notiert
     * (docs/zertifikat/folgeliste.md F11), nicht in dem Paket, das den Versand
     * umbaut.
     *
     * WARUM NICHT NACHBAUEN: das waere die zweite Kopie derselben Kette,
     * inklusive der Regel „auto_pilot_wa_account_id gewinnt ueber
     * $template->whatsapp_account_id" (`:115`). Solche Kopien hat das Modul
     * genug.
     *
     * DIESE METHODE AENDERT NICHTS AM SENDEWEG. Sie ist ein Durchreicher; wer
     * hier Logik ergaenzt, ergaenzt sie fuer sendToMany() mit.
     *
     * @return array{error: ?string, template: ?IntegrationsWhatsAppTemplate, channel: ?CommsChannel}
     */
    public function resolveTarget(int $teamId, string $settingsKey = 'comms_holding_template_id'): array
    {
        return $this->resolveConfig($teamId, $settingsKey);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter HoldingTemplateSenderResolveTargetTest
```
Expected: PASS, 4 Tests. Bei rot: erst die zwei benannten Abweichungen oben prüfen (Fehlertext wörtlich, Pflichtspalten), **nicht** den Produktivcode ändern.

- [ ] **Step 5: Run the full suite**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml
```
Expected: `OK`, **783 Tests** (779 nach Task 1 + 4 neue). Diese Task fügt eine neue Testklasse mit prozessweitem Zustand hinzu (Container, Capsule, Facade) — genau deshalb steht der volle Lauf hier und nicht nur der gefilterte. Wird eine **andere** Klasse rot, ist der Teardown dieser Klasse die erste Verdächtige (Folgeliste F3: vier geteilte Statics, vorbestehend).

- [ ] **Step 6: Commit**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git add src/Services/Comms/HoldingTemplateSender.php tests/Integration/HoldingTemplateSenderResolveTargetTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Template- und Kanal-Aufloesung des Holding-Senders lesbar machen

Ein Durchreicher auf resolveConfig(), damit der Zertifikat-Versand direkt
sendTemplate() aufrufen kann, ohne die vierstufige Kette ein zweites Mal zu
bauen. sendToMany, sendOne, configuredTemplateName und resolveConfig bleiben
unveraendert — der Pfad traegt auch Holding, Auto-Reply und Voice-Note.

Der Test nagelt zusaetzlich fest, dass resolveConfig privat bleibt.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Umbau des Sendepfads in `TrainingCertificateWhatsAppDelivery`

**Files:**
- Modify: `src/Services/TrainingCertificateWhatsAppDelivery.php` (Klassen-Docblock `:14-47`, Status-Konstanten `:65-66`, `deliver()` `:78-197`)
- Test: `tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php` (Docblock, Fixtures, zweiter Stub, mehrere Testmethoden)

**Interfaces:**
- Consumes: `WhatsAppTemplateUrlButtons::hasDynamicAt()`, `::dynamicIndexes()`, `::describe()` (Task 1); `HoldingTemplateSender::resolveTarget()` (Task 2).
- Produces:
  - `TrainingCertificateWhatsAppDelivery::STATUS_TEMPLATE_WITHOUT_URL_BUTTON = 'template_without_url_button'` (ersetzt `STATUS_TEMPLATE_WITHOUT_VARIABLE`).
  - `deliver(RecApplicant $applicant): array{status: string, error: ?string, link: ?string}` — **unverändert in Signatur und Rückgabeform.** `HrDesk/Index::confirmResolve()` wird nicht angefasst.

**Kontext für den Ausführenden:** Bisher ging der Link als Body-Variable `{{zertifikat_link}}` über `HoldingTemplateSender::sendOne()`. Jetzt geht er als Button-Parameter über `WhatsAppMetaService::sendTemplate()`. Vorbild für den Button-Component ist `src/Models/RecInterview.php:204-216` (Leerwert-Riegel inklusive), **nicht** `src/Livewire/Applicant/Show.php:543-552` — dort steckt der Bewerber-Formular-Token in jedem URL-Button, ungeprüft (Spec H4).

**Die Signatur, die den ganzen Umbau trägt — roh gezogen am 2026-08-13, nicht aus dem Gedächtnis.** Quelle: `platform-crm/src/Services/Comms/WhatsAppMetaService.php:56-64` (die Klasse liegt in **platform-crm**, nicht in platform-integrations). Sie wird **benutzt und nicht angefasst**:

```php
// platform-crm/src/Services/Comms/WhatsAppMetaService.php:53-64 — woertlich
    /**
     * Send a template message via WhatsApp.
     */
    public function sendTemplate(
        CommsChannel $channel,
        string $to,
        string $templateName,
        array $components = [],
        string $languageCode = 'de',
        ?User $sender = null,
        bool $isAutoReply = false,
    ): CommsWhatsAppMessage {
```

Dazu die Typen, gemessen an den Imports `:9-15`: `$channel` ist `Platform\Crm\Models\CommsChannel`, `$sender` ist `?Platform\Core\Models\User`, Rückgabe ist `Platform\Crm\Models\CommsWhatsAppMessage`. Die Klasse ist **nicht `final`** (`:20`) — sie kann im Test also abgeleitet *oder* duck-typed gebunden werden.

**Drei Folgen daraus, die beim Bauen zählen:**

1. **Die sechs benannten Argumente im Aufruf sind genau diese Namen.** `channel`, `to`, `templateName`, `components`, `languageCode`, `sender` — `isAutoReply` bleibt weg (Default `false`).
2. **`$channel` ist typisiert.** Der Test bindet einen duck-typed Stub mit **untypisiertem** `$channel` und kann dem Sender-Stub deshalb ein `\stdClass()` als Kanal geben. **Damit beweist der Test nicht, dass der Kanaltyp im Betrieb passt** — er kann es in dieser Suite nicht (kein `comms_channels`-Schema in der Klasse). Was ihn trägt: `resolveTarget()` gibt genau den `CommsChannel` zurück, den `resolveConfig():125-128` aus der Datenbank holt, und **Task 2 prüft das mit `assertInstanceOf(CommsChannel::class, …)` gegen die echte Migration.** Diese Arbeitsteilung gehört so und ist der Grund, warum Task 2 vor Task 3 steht.
3. **`sender: auth()->user()` bindet die Klasse an den Request-Kontext.** Heute richtig — der einzige Aufrufer ist `HrDesk/Index::confirmResolve()`, also ein Livewire-Request mit angemeldetem Benutzer. In einem Job oder Command wäre es `null` und die Nachricht hätte keinen Absender. Das gehört als Satz in den Klassen-Docblock (Step 3).

**Der Button-Parameter ist die `uuid`, nicht die URL.** Die Basis-URL steht bei Meta (H2, gemessen). `$link` wird weiter gebaut, aber nur noch für den Rückgabewert und die Fehlermeldungen — HR braucht ihn für den Versand von Hand. Wer die `route()`-Zeile als „nicht mehr gebraucht" entfernt, nimmt HR den Notweg.

**Reihenfolge in `deliver()` bleibt:** Zertifikat → `wa_sent_at` → Telefonnummer → `not_configured` → **neu:** `resolveTarget()` → Guard → `uuid` → Body → Button → Send → `wa_sent_at` stempeln.

- [ ] **Step 1: Fixtures und zweiten Stub in den Test einziehen**

In `tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php`:

**1a — Import ergänzen:**

```php
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;
```

**1b — Team-Konstanten ergänzen** (nach `TEAM_TEMPLATE_FEHLT`, `:97`):

```php
    /** Team, dessen Template den URL-Button an Position 1 statt 0 hat. */
    private const TEAM_BUTTON_FALSCHE_POSITION = 7;

    private static int $templateButtonPositionEins = 0;
```

**1c — Fixtures in `runRealMigrations()` umstellen.** `self::$templateMitVariable` bekommt zusätzlich eine `BUTTONS`-Komponente, `self::$templateOhneVariable` wird zum Template **ohne** dynamischen Button, und ein drittes Template kommt dazu. Die drei `components`-Blöcke lauten:

```php
        // Das richtig gebaute Template: Anrede im Body, dynamischer URL-Button
        // an Position 0. Die Basis-URL steht bei Meta (Spec H2) — hier steht
        // sie nur, damit die Struktur echt aussieht; geprueft wird der
        // Parameter, nicht die URL.
        self::$templateMitVariable = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-mit-button',
            'name' => 'zert_link_mit_button',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => 'Hallo {{name}}, dein Zertifikat liegt bereit.',
                    'example' => ['body_text_named_params' => [
                        ['param_name' => 'name', 'example' => 'Max'],
                    ]],
                ],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Zertifikat oeffnen',
                     'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}'],
                ]],
            ],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;

        // Der Guard-Fall: gar kein dynamischer Button. Der statische URL-Button
        // ist absichtlich drin — er ist genau der, den fuenf der sieben
        // Erkennungsstellen im Modul fuer fuellbar halten (Spec H1).
        self::$templateOhneVariable = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-ohne-button',
            'name' => 'zert_ohne_button',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hallo {{name}}, wir kuemmern uns.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Website', 'url' => 'https://mitarbeiter.rheingedeck.de/karriere'],
                ]],
            ],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;

        // Der zweite Guard-Fall, und der mit der anderen Anweisung: Button
        // vorhanden, nur an Position 1.
        self::$templateButtonPositionEins = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-button-pos-1',
            'name' => 'zert_button_pos_1',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hallo {{name}}.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                    ['type' => 'URL', 'text' => 'Zertifikat',
                     'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}'],
                ]],
            ],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;
```

Und eine Settings-Zeile für das neue Team, im Block bei `:863-883`:

```php
        RecApplicantSettings::create([
            'team_id' => self::TEAM_BUTTON_FALSCHE_POSITION,
            'settings' => [
                'issue_training_certificates' => true,
                TrainingCertificateWaTemplate::SETTINGS_KEY => self::$templateButtonPositionEins,
            ],
        ]);
```

> Der `issue_training_certificates`-Schlüssel und die genaue Form der bestehenden Settings-Zeilen sind bei `:855-885` abzulesen und **wörtlich** zu übernehmen — der Ausstellungsschalter gehört dazu, sonst existiert kein Zertifikat zum Verschicken.

**1d — Zweiten Stub anlegen**, direkt nach `senderStub()` (`:729-759`):

```php
    /**
     * Attrappe fuer WhatsAppMetaService::sendTemplate.
     *
     * DUCK-TYPED wie der Sender-Stub: der Container prueft bei ->instance()
     * keinen Typ, und die Delivery loest den Service ueber app() auf. Ein
     * echter Service braeuchte Meta-Zugang.
     *
     * KEIN Rueckgabetyp deklariert, und das ist Absicht: die echte Methode
     * liefert ein CommsWhatsAppMessage-Model, das hier ohne comms-Tabellen
     * nicht entstehen kann. Der Rueckgabewert wird von der Delivery nicht
     * benutzt (kein addContext, Spec W6/Q3) — sie stempelt nur wa_sent_at.
     * Genau das haelt testStubSignaturPasstZuSendTemplate fest.
     */
    private function metaStub(?\Throwable $wirft = null): object
    {
        $stub = new class($wirft) {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private ?\Throwable $wirft) {}

            public function sendTemplate(
                $channel,
                string $to,
                string $templateName,
                array $components = [],
                string $languageCode = 'de',
                $sender = null,
                bool $isAutoReply = false,
            ) {
                $this->calls[] = [
                    'channel' => $channel,
                    'to' => $to,
                    'templateName' => $templateName,
                    'components' => $components,
                    'languageCode' => $languageCode,
                    'sender' => $sender,
                    'isAutoReply' => $isAutoReply,
                ];

                if ($this->wirft !== null) {
                    throw $this->wirft;
                }

                return new \stdClass();
            }
        };

        Container::getInstance()->instance(WhatsAppMetaService::class, $stub);

        return $stub;
    }
```

**1e — `senderStub()` bekommt `resolveTarget()`.** Der Stub muss ab jetzt auflösen statt senden. `sendOne()` bleibt drin und wird zur Falle: ruft die Delivery sie noch, ist der Umbau unvollständig. In der anonymen Klasse (`:731-754`) ergänzen:

```php
            /**
             * Was die Delivery ab jetzt vom Sender will: Template und Kanal.
             * Das Template kommt aus der ECHTEN Zeile, damit der Guard eine
             * echte components-Struktur zu lesen bekommt.
             */
            public function resolveTarget(int $teamId, string $settingsKey = 'comms_holding_template_id'): array
            {
                $this->resolveCalls[] = ['teamId' => $teamId, 'settingsKey' => $settingsKey];

                if ($this->fehler !== null) {
                    return ['error' => $this->fehler, 'template' => null, 'channel' => null];
                }

                return [
                    'error' => null,
                    'template' => $this->template,
                    // Der Kanal wird nur durchgereicht — die Attrappe des
                    // Meta-Service hat keinen Typ darauf (Spec W1).
                    'channel' => new \stdClass(),
                ];
            }

            /**
             * FALLE, absichtlich: nach dem Umbau darf die Delivery nicht mehr
             * ueber den Sender verschicken. Ein Aufruf hier ist ein
             * unvollstaendiger Umbau, kein Testfehler.
             */
            public function sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array
            {
                throw new \LogicException(
                    'Der Zertifikat-Versand darf nicht mehr ueber HoldingTemplateSender::sendOne laufen '
                    . '(Spec W1: eigener Sendepfad mit WhatsAppMetaService::sendTemplate).'
                );
            }
```

Konstruktor und Felder des Stubs entsprechend auf `(?IntegrationsWhatsAppTemplate $template, ?string $fehler)` umstellen, `public array $resolveCalls = [];` ergänzen und `$calls` entfernen (der Sender zeichnet keine Sends mehr auf — das tut `metaStub`).

**1f — `tearDown()` und `tearDownAfterClass()` müssen den neuen Stub mit wegräumen** (`:154-167`):

```php
        Container::getInstance()->forgetInstance(HoldingTemplateSender::class);
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
```

**1g — Die neuen und geänderten Testmethoden.** `testVersandTraegtDieZertifikatUuidUndNichtDenApplicantToken` (`:217`) wird zu T-3:

```php
    /**
     * T-3 — DER BELASTBARE TEIL DER TOKEN-ABSICHERUNG.
     *
     * Geprueft wird der INHALT des Button-Parameters: die Zertifikat-uuid, und
     * NICHT die vollstaendige URL und NICHT der Applicant-Token. Die Basis-URL
     * steht bei Meta (Spec H2, im Meta-Manager gemessen) — steht hier eine URL,
     * verschickt Meta einen doppelten Pfad.
     */
    public function testButtonParameterTraegtDieZertifikatUuid(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $sender = $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_SENT, $result['status']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $meta->calls, 'Genau ein Send.');

        $call = $meta->calls[0];
        $this->assertSame('+49 151 1234567', $call['to']);
        $this->assertSame('zert_link_mit_button', $call['templateName']);
        $this->assertSame('de', $call['languageCode']);
        $this->assertFalse($call['isAutoReply'], 'Kein Auto-Reply: HR loest den Versand aus.');

        $buttons = array_values(array_filter(
            $call['components'],
            fn (array $c) => ($c['type'] ?? '') === 'button'
        ));

        $this->assertCount(1, $buttons, 'Genau ein Button-Component.');
        $this->assertSame('url', $buttons[0]['sub_type']);
        $this->assertSame(0, $buttons[0]['index']);
        $this->assertSame(
            [['type' => 'text', 'text' => $zertifikat->uuid]],
            $buttons[0]['parameters'],
            'Nur die uuid — die Basis-URL steht bei Meta.'
        );

        // Und das Gegenteil, ausdruecklich: im gesendeten components-Array steht
        // weder der Applicant-Token noch eine vollstaendige URL.
        //
        // ASSERTIONSZIEL IST GENAU $call['components'] — NICHT der ganze
        // mitgeschnittene Aufruf. Der Stub zeichnet auch 'channel' und
        // 'templateName' auf, und das Fixture-Template traegt in seiner
        // Button-URL ein https://…/recruiting/zertifikat/{{1}}: eine
        // Serialisierung des ganzen Calls wuerde an dieser URL scheitern und
        // damit eine Zusage pruefen, die niemand gemacht hat. Was hier zaehlt,
        // ist der Payload an Meta.
        $payload = (string) json_encode($call['components']);
        $this->assertStringNotContainsString($applicant->public_token, $payload);
        $this->assertStringNotContainsString('https://', $payload);

        // Der Link bleibt in der Rueckgabe — HR braucht ihn fuer den Versand
        // von Hand, und die Fehlerzweige geben ihn mit.
        $this->assertSame(self::BASIS . '/recruiting/zertifikat/' . $zertifikat->uuid, $result['link']);

        $this->assertSame(
            [['teamId' => self::TEAM, 'settingsKey' => TrainingCertificateWaTemplate::SETTINGS_KEY]],
            $sender->resolveCalls,
            'Aufgeloest wird genau einmal, mit dem Zertifikat-Settings-Key.'
        );
    }
```

> **Zum Feldnamen `public_token`:** in `testVersandTraegtDieZertifikatUuidUndNichtDenApplicantToken` steht bei `:251-260` schon eine Gegenprobe gegen den Applicant-Token. Der dort verwendete Ausdruck ist **wörtlich zu übernehmen**, statt ihn zu erraten.

T-5 (Body bleibt) als eigene Methode:

```php
    /**
     * T-5 — die Anrede geht nicht verloren.
     *
     * Der Body wird weiter von HoldingTemplateComponents::build() gebaut (Spec
     * W3: aufrufen, nicht erweitern). Der naheliegende Umbaufehler ist, beim
     * Wechsel auf den direkten Send nur noch den Button zu schicken.
     */
    public function testBodyMitVornameUndButtonGehenZusammenRaus(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $components = $meta->calls[0]['components'];
        $this->assertSame('body', $components[0]['type'], 'Body zuerst, dann der Button.');
        $this->assertSame(
            [['type' => 'text', 'text' => 'Erika', 'parameter_name' => 'name']],
            $components[0]['parameters']
        );
        $this->assertSame('button', $components[1]['type']);
        $this->assertCount(2, $components);
    }
```

T-2, beide Zweige:

```php
    /**
     * T-2a — kein dynamischer URL-Button: es geht NICHTS raus.
     *
     * Der statische URL-Button im Fixture ist der Punkt: fuenf der sieben
     * Erkennungsstellen im Modul halten ihn fuer fuellbar (Spec H1). Dieser
     * Guard nicht.
     */
    public function testTemplateOhneDynamischenButtonWirdNichtVersendet(): void
    {
        $applicant = $this->bewerber(self::TEAM_TEMPLATE_OHNE_VARIABLE);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            TrainingCertificateWhatsAppDelivery::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
            $result['status']
        );
        $this->assertSame([], $meta->calls, 'Es darf NICHTS rausgehen.');
        $this->assertNull($zertifikat->fresh()->wa_sent_at);

        $meldung = (string) $result['error'];
        $this->assertStringContainsString('zert_ohne_button', $meldung, 'Der Vorlagenname gehoert in die Meldung.');
        $this->assertStringContainsString('URL ohne Variable', $meldung, 'Was gefunden wurde, mit Position.');
        $this->assertStringContainsString('herunterladen und manuell senden', $meldung);
    }

    /**
     * T-2b — Button vorhanden, aber an Position 1.
     *
     * DIE MELDUNG MUSS DIE RICHTIGE ANWEISUNG SAGEN. „Kein URL-Button
     * gefunden" waere hier schlicht falsch und schickt HR in die Suche nach
     * einem Button, den es gibt (Spec W5).
     */
    public function testDynamischerButtonAnFalscherPositionSagtWasZuTunIst(): void
    {
        $applicant = $this->bewerber(self::TEAM_BUTTON_FALSCHE_POSITION);
        $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            TrainingCertificateWhatsAppDelivery::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
            $result['status']
        );
        $this->assertSame([], $meta->calls);

        $meldung = (string) $result['error'];
        $this->assertStringContainsString('erste Position', $meldung);
        $this->assertStringNotContainsString(
            'keinen URL-Button',
            $meldung,
            'Es gibt einen — er steht nur an der falschen Stelle.'
        );
    }
```

T-6 (leerer Pflicht-Parameter): der Bewerber ohne auflösbaren Vornamen. Die Hilfsmethode `bewerber()` (`:640ff.`) ist zu lesen; wenn sie den Vornamen fest setzt, braucht dieser Test eine Variante ohne Extra-Field `vorname` und ohne Kontakt-Vornamen — Vorbild sind `testVornameKommtVomKontaktMitDerKleinstenIdWieAufDemZertifikat` (`:269`) und `testExtraFieldVornameGewinntUeberDenKontakt` (`:323`), die beide am Vornamen drehen.

```php
    /**
     * T-6 — leerer Pflicht-Parameter geht nicht an Meta.
     *
     * Im Sender war das ein stiller `skipped` (HoldingTemplateSender:56-59).
     * Der direkte Pfad hat diese Bremse nicht geerbt und braucht sie
     * ausdruecklich: Meta lehnt leere Pflicht-Parameter ab (131008), und ein
     * garantiert scheiternder Send ist kein Send, den man absetzt.
     */
    public function testLeererVornameFuehrtZuFailedOhneSend(): void
    {
        $applicant = $this->bewerberOhneVornamen(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertSame([], $meta->calls);
        $this->assertNull($zertifikat->fresh()->wa_sent_at);
        $this->assertStringContainsString('herunterladen und manuell senden', (string) $result['error']);
    }
```

Bestehende Methoden, die mitwandern:
- `testSenderWirftAblehnungBleibtCommittetUndWaSentAtLeer` (`:357`) — der Wurf kommt ab jetzt aus `metaStub($wirft)`, nicht aus `senderStub`. Die Zusage (§D5) bleibt wörtlich.
- `testSendefehlerLaesstWaSentAtLeerUndNenntDenGrund` (`:409`) — bisher `sent < 1`; den Fall gibt es nicht mehr. Umbauen auf „`sendTemplate()` wirft" (`metaStub(new \RuntimeException('Meta 131026'))`) und prüfen, dass der Meta-Text in der Meldung steht.
- `testKonfigurationsfehlerDesSendersStehtInDerMeldung` (`:424`) — bisher `$result['error']` von `sendOne`; jetzt der `error`-String aus `resolveTarget()`, Status `failed`.
- `testTemplateOhneBodyVariableWirdNichtVersendet` (`:459`) — **ersetzt** durch T-2a/T-2b oben; Methode entfernen.
- `testStubEntsprichtDerEchtenSenderSignatur` (`:606`) — behalten und um `resolveTarget` erweitern; zusätzlich eine Schwester für den Meta-Stub:

```php
    /**
     * Die Attrappe darf nicht von der echten Signatur wegdriften — sonst
     * behauptet der Test einen Aufruf, den es so nicht gibt.
     *
     * VERGLICHEN WERDEN NUR PARAMETER, nicht der Rueckgabetyp: die echte
     * Methode liefert ein CommsWhatsAppMessage-Model, das hier ohne
     * comms-Tabellen nicht entstehen kann. Dass der Rueckgabewert nicht benutzt
     * wird, ist eine Zusage der Delivery (kein addContext, Spec W6) und steht
     * in testDeliveryBenutztDenRueckgabewertDesSendsNicht.
     */
    public function testStubSignaturPasstZuSendTemplate(): void
    {
        $echt = new \ReflectionMethod(WhatsAppMetaService::class, 'sendTemplate');
        $stub = new \ReflectionMethod($this->metaStub(), 'sendTemplate');

        $this->assertSame(
            array_map(fn ($p) => $p->getName(), $echt->getParameters()),
            array_map(fn ($p) => $p->getName(), $stub->getParameters()),
            'Gleiche Parameternamen in gleicher Reihenfolge — die Delivery ruft benannt auf.'
        );
    }
```

T-9 (Log-Marker): Laravels `Log`-Facade braucht im Test eine Wurzel. Der Container dieser Klasse hat keinen `log`-Binding — er ist zu ergänzen, mit einer Attrappe, die Aufrufe sammelt:

```php
    /**
     * Log-Attrappe: sammelt Kanal, Stufe und Nachricht.
     *
     * WOZU: vier verschiedene Ursachen fallen auf denselben Statuswert `failed`
     * (Spec W8/B2). Der Statuswert unterscheidet sie nicht, die HR-Meldung
     * schon, und das Log muss es auch — sonst steht beim Nachsehen eine Zeile
     * ohne Unterscheidung.
     */
    private function logStub(): object
    {
        $stub = new class {
            /** @var list<array{level: string, message: string, context: array}> */
            public array $lines = [];

            public function error($message, array $context = []): void
            {
                $this->lines[] = ['level' => 'error', 'message' => (string) $message, 'context' => $context];
            }

            public function warning($message, array $context = []): void
            {
                $this->lines[] = ['level' => 'warning', 'message' => (string) $message, 'context' => $context];
            }

            public function __call($method, $args) {}
        };

        Container::getInstance()->instance('log', $stub);

        return $stub;
    }

    /**
     * T-9 — die Ursachen sind im Log unterscheidbar.
     *
     * Geprueft an zwei der vier: Aufloesungsfehler und Wurf beim Send. Wenn
     * diese beiden verschiedene Marker tragen, tragen die anderen zwei es auch
     * — sie stehen im selben Muster, und der Test soll die Bauart festnageln,
     * nicht vier Zeilen abschreiben.
     */
    public function testDieUrsachenStehenUnterscheidbarImLog(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $this->zertifikat($applicant);
        $log = $this->logStub();

        $this->senderStub(null, 'WhatsApp-Account nicht aktiv.');
        $this->metaStub();
        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $applicantZwei = $this->bewerber(self::TEAM);
        $this->zertifikat($applicantZwei);
        $this->senderStub();
        $this->metaStub(new \RuntimeException('Meta 131026'));
        (new TrainingCertificateWhatsAppDelivery())->deliver($applicantZwei);

        $this->assertCount(2, $log->lines);
        $this->assertNotSame(
            $log->lines[0]['message'],
            $log->lines[1]['message'],
            'Zwei Ursachen, zwei Meldungen — sonst ist das Log beim Nachsehen wertlos.'
        );
        foreach ($log->lines as $zeile) {
            $this->assertStringContainsString('[TrainingCertificateWhatsAppDelivery]', $zeile['message']);
        }
    }
```

> `logStub()` muss in `tearDown()` mit `forgetInstance('log')` weggeräumt werden — ein leckender Log-Stub sieht in der nächsten Klasse wie ein kaputtes Logging aus. Die Signatur von `senderStub()` ändert sich dabei auf `(?IntegrationsWhatsAppTemplate $template = null, ?string $fehler = null)`; alle bestehenden Aufrufer im Test mitziehen.

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter TrainingCertificateWhatsAppDeliveryTest
```
Expected: FAIL. Die aussagekräftigste Fehlermeldung ist die `LogicException` aus dem `sendOne`-Fallenzweig („darf nicht mehr über HoldingTemplateSender::sendOne laufen") — die Delivery sendet noch über den alten Weg. Dazu `STATUS_TEMPLATE_WITHOUT_URL_BUTTON` undefined.

- [ ] **Step 3: Status-Konstanten und Docblock umstellen**

In `src/Services/TrainingCertificateWhatsAppDelivery.php`:

`:65-66` ersetzen:

```php
    /**
     * Template konfiguriert, aber ohne dynamischen URL-Button an Position 0.
     *
     * ZWEI FAELLE, EIN STATUS: gar kein dynamischer Button, oder einer an der
     * falschen Position. Fuer den Aufrufer ist beides dasselbe Ereignis (es
     * ging nichts raus); unterschiedlich ist nur die Anweisung an HR, und die
     * steht in der Meldung.
     */
    public const STATUS_TEMPLATE_WITHOUT_URL_BUTTON = 'template_without_url_button';
```

Im Klassen-Docblock (`:14-47`) die drei Stellen nachziehen, die den alten Weg beschreiben — und die neue Begründung ausschreiben:

```php
 * WARUM DIREKT WhatsAppMetaService::sendTemplate() UND NICHT HoldingTemplateSender:
 * der Link steckt ab jetzt in einem dynamischen URL-Button, und
 * HoldingTemplateComponents::build() kann strukturell keine Buttons — es
 * iteriert nur ueber type === 'BODY'. Den Builder zu erweitern faellt in den
 * Pfad, der auch Holding-Bestaetigung, OOO-Auto-Reply und Voice-Note-Antworten
 * bedient; deshalb ein eigener Sendepfad nach dem Muster der sechs bestehenden
 * Button-Stellen im Modul (naechste Vorlage: RecInterview.php:204-216).
 *
 * NICHT das Muster von Applicant/Show.php:543-552: der Block dort setzt den
 * Bewerber-FORMULAR-Token in jeden URL-Button, sobald das Template irgendeinen
 * hat — ohne zu pruefen, wohin er zeigt und ob seine URL ueberhaupt eine
 * Variable traegt. Hier kommt der Wert aus dem Zertifikat, das ohnehin in der
 * Hand ist, und der Guard ist die SENDEBEDINGUNG statt eines Ausloesers. In
 * dieser Klasse steht deshalb kein getPublicUrl(), kein
 * getOrCreatePublicFormLink() und kein portal_token — festgenagelt in
 * WhatsAppTemplateBodyVariablesTest.
 *
 * DIESE KLASSE GEHOERT IN EINEN REQUEST, NICHT IN EINEN JOB. `sender:
 * auth()->user()` traegt den ausloesenden Benutzer in die Nachricht; einziger
 * Aufrufer ist HrDesk/Index::confirmResolve(), also ein Livewire-Request mit
 * angemeldetem Benutzer. Aus einem Command oder Queue-Job aufgerufen ist der
 * Absender still `null` — kein Fehler, nur eine Nachricht ohne Urheber. Wer sie
 * dort braucht, uebergibt den Benutzer, statt sich auf auth() zu verlassen.
 *
 * ES GIBT KEINEN WIEDERVERSAND-WEG. Gemessen: deliver() hat genau einen
 * Aufrufer (HrDesk/Index.php:270), wa_sent_at wird nirgends geleert, und es gibt
 * keinen „erneut senden"-Knopf. Der einzige zweite Eintritt ist eine zweite
 * Ablehnung mit Haken, und die faellt in STATUS_ALREADY_SENT. Wer einen
 * Wiederversand baut, muss ihn durch DIESE Methode fuehren — sonst hat der
 * Guard eine zweite Tuer, an der er nicht steht.
 *
 * DER BUTTON-PARAMETER IST DIE uuid, NICHT DIE URL. Die Basis-URL
 * (https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/) steht im bei Meta
 * genehmigten Template; das Modul liefert nur das letzte Pfadsegment. Preis,
 * benannt: aendert sich die Domain, muss sie bei Meta nachgezogen werden, und
 * kein Guard hier kann das sehen. Die vollstaendige URL wird trotzdem weiter
 * gebaut — fuer die Rueckgabe und die Fehlermeldungen, damit HR von Hand
 * senden kann.
```

- [ ] **Step 4: `deliver()` umbauen**

`src/Services/TrainingCertificateWhatsAppDelivery.php`, ab `:119` (nach dem `not_configured`-Zweig) bis `:196`. Der Teil davor — Zertifikat, `wa_sent_at`, Telefonnummer, `not_configured` — bleibt **unverändert**.

```php
        // Template UND Kanal aus einer Aufloesung (Spec W2). Vorher holte diese
        // Klasse das Template selbst nur fuer den Guard, waehrend der Sender es
        // unabhaengig ein zweites Mal aufloeste — zwei Lookups derselben ID.
        // Jetzt prueft der Guard genau das Template, das gleich gesendet wird.
        $target = app(HoldingTemplateSender::class)
            ->resolveTarget($teamId, TrainingCertificateWaTemplate::SETTINGS_KEY);

        $link = route(TrainingCertificateWaTemplate::ROUTE_NAME, ['uuid' => $certificate->uuid]);

        if ($target['error'] !== null) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber der WhatsApp-Versand ist nicht moeglich: '
                . $target['error'] . $this->vonHand(),
                $link,
                'Aufloesung von Template oder Kanal fehlgeschlagen',
                ['grund' => $target['error']]
            );
        }

        $template = $target['template'];
        $components = $template->components ?? [];

        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($components, TrainingCertificateWaTemplate::URL_BUTTON_INDEX)) {
            return $this->fehler(
                self::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
                $this->buttonMeldung($template, $components),
                $link,
                'Template ohne dynamischen URL-Button an Position '
                . TrainingCertificateWaTemplate::URL_BUTTON_INDEX,
                ['template' => (string) $template->name]
            );
        }

        // Leerwert-Riegel wie RecInterview.php:208. Praktisch unerreichbar (die
        // uuid entsteht bei der Ausstellung), aber ein Button-Parameter mit
        // leerem Text ist Meta 131008 — dieselbe Klasse Fehler, die
        // hasEmptyRequiredParam fuer den Body abfaengt.
        $uuid = trim((string) $certificate->uuid);
        if ($uuid === '') {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber es hat keine Kennung fuer den Link'
                . $this->vonHand(),
                null,
                'Zertifikat ohne uuid',
                ['zertifikat' => (int) $certificate->id]
            );
        }

        $firstName = $this->firstName($applicant);

        // Der Body kommt weiter von HoldingTemplateComponents::build() — das ist
        // ein AUFRUF, keine Erweiterung (Spec W3). Ein eigener Body-Parser waere
        // die achte Kopie derselben {{…}}-Schleife im Modul. $namedValues bleibt
        // leer: der Link steckt jetzt im Button, und damit ist auch der
        // Beispieltext-Mechanismus aus build():45 fuer ihn kein Risiko mehr.
        $sendComponents = HoldingTemplateComponents::build($components, $firstName);

        // Im Sender fuehrte ein leerer Pflicht-Parameter zu einem stillen
        // `skipped` (`:56-59`). Der direkte Pfad hat die Bremse nicht geerbt und
        // braucht sie ausdruecklich: erreichbar bei einem Bewerber ohne
        // aufloesbaren Vornamen und einem Template mit Anrede-Variable.
        if (HoldingTemplateComponents::hasEmptyRequiredParam($sendComponents)) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber eine Pflichtangabe der Nachricht ist leer '
                . '(meist der Vorname) — Meta lehnt solche Sends ab' . $this->vonHand(),
                $link,
                'Leerer Pflicht-Parameter im Body',
                ['template' => (string) $template->name]
            );
        }

        $sendComponents[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => TrainingCertificateWaTemplate::URL_BUTTON_INDEX,
            'parameters' => [['type' => 'text', 'text' => $uuid]],
        ];

        try {
            app(WhatsAppMetaService::class)->sendTemplate(
                channel: $target['channel'],
                to: $phone,
                templateName: (string) $template->name,
                components: $sendComponents,
                languageCode: (string) ($template->language ?? 'de'),
                sender: auth()->user(),
            );
        } catch (\Throwable $e) {
            return $this->fehler(
                self::STATUS_FAILED,
                'Zertifikat ausgestellt, aber der WhatsApp-Versand ist fehlgeschlagen: '
                . $e->getMessage() . $this->vonHand(),
                $link,
                'sendTemplate hat geworfen',
                ['fehler' => $e->getMessage()]
            );
        }

        // Bewusst NICHT in einem try: siehe Klassen-Docblock. Und es ist der
        // einzige Schritt nach dem Send — ein addContext() auf den Thread waere
        // eine Verhaltensaenderung ueber den Auftrag hinaus und steht als
        // eigener Punkt in docs/zertifikat/folgeliste.md (F10).
        $certificate->update(['wa_sent_at' => Carbon::now()]);

        return ['status' => self::STATUS_SENT, 'error' => null, 'link' => $link];
    }

    /**
     * Die Meldung fuer den Guard-Zweig — und sie muss die ANWEISUNG sagen,
     * nicht nur den Befund.
     *
     * Zwei Faelle, zwei Anweisungen: sitzt der dynamische Button an einer
     * anderen Position, ist „kein URL-Button gefunden" schlicht falsch und
     * schickt HR in die Suche nach einem Button, den es gibt. Deshalb liefert
     * WhatsAppTemplateUrlButtons Positionen und nicht bool.
     *
     * @param  array<int, mixed>  $components
     */
    private function buttonMeldung($template, array $components): string
    {
        $gefunden = WhatsAppTemplateUrlButtons::describe($components);
        $liste = $gefunden === [] ? 'keine Buttons' : implode(', ', $gefunden);
        $dynamisch = WhatsAppTemplateUrlButtons::dynamicIndexes($components);

        if ($dynamisch !== []) {
            return sprintf(
                'Zertifikat ausgestellt, aber im WhatsApp-Template „%s" sitzt der URL-Button mit '
                . 'Variable an Position %d statt an Position %d. Bitte ihn im Meta-Template an die '
                . 'erste Position verschieben (gefunden: %s). Es wurde NICHTS versendet%s',
                (string) $template->name,
                $dynamisch[0],
                TrainingCertificateWaTemplate::URL_BUTTON_INDEX,
                $liste,
                $this->vonHand()
            );
        }

        return sprintf(
            'Zertifikat ausgestellt, aber das WhatsApp-Template „%s" hat keinen URL-Button mit '
            . 'Variable (gefunden: %s). Die Button-URL muss auf %s enden. Es wurde NICHTS '
            . 'versendet — sonst waere eine Nachricht ohne Link rausgegangen%s',
            (string) $template->name,
            $liste,
            'die Zertifikat-Route mit {{1}} am Ende',
            $this->vonHand()
        );
    }
```

Und `fehler()` (`:236-240`) bekommt den Log-Marker:

```php
    /**
     * @param  array<string, mixed>  $kontext
     * @return array{status: string, error: ?string, link: ?string}
     *
     * VIER URSACHEN FALLEN AUF `failed`, und der Statuswert unterscheidet sie
     * nicht (Spec W8). Eigene Statuswerte waeren vier Zweige in
     * HrDesk/Index::confirmResolve(), die alle dasselbe taeten — fuer die
     * Diagnose reicht ein unterscheidbarer Log-Marker. Der Guard-Zweig wird
     * mitgeloggt, obwohl er kein Fehler ist: er wird nach dem Deploy der
     * haeufigste sein, und ohne Logzeile ist er nur so lange sichtbar, wie der
     * Flash am Bildschirm steht.
     */
    private function fehler(
        string $status,
        string $meldung,
        ?string $link = null,
        ?string $logMarker = null,
        array $kontext = []
    ): array {
        if ($logMarker !== null) {
            Log::error('[TrainingCertificateWhatsAppDelivery] ' . $logMarker, $kontext + ['status' => $status]);
        }

        return ['status' => $status, 'error' => $meldung, 'link' => $link];
    }
```

Imports am Kopf der Datei: `Illuminate\Support\Facades\Log`, `Platform\Crm\Services\Comms\WhatsAppMetaService`, `Platform\Recruiting\Services\Comms\HoldingTemplateComponents`, `Platform\Recruiting\Support\WhatsAppTemplateUrlButtons`. **Raus:** `Platform\Recruiting\Support\WhatsAppTemplateBodyVariables` und `Platform\Integrations\Models\IntegrationsWhatsAppTemplate` (das Template kommt jetzt aus `resolveTarget()`) — `class_exists`-Schutz entfällt hier, er steckt in `resolveConfig():106-108`.

**Die vier bestehenden Zweige oberhalb (`no_certificate`, `already_sent`, `no_phone`, `not_configured`) rufen `fehler()` ohne Log-Marker** — bewusst: Zustände des Bewerbers oder der Konfiguration, keine Störungen des Versands.

- [ ] **Step 5: `URL_BUTTON_INDEX` provisorisch bereitstellen**

Task 4 stellt die Konstantenklasse um; Task 3 braucht `URL_BUTTON_INDEX` aber schon. Deshalb hier **nur** diese eine Konstante ergänzen, in `src/Support/TrainingCertificateWaTemplate.php`, und `BODY_VARIABLE` **stehen lassen** (sie fällt in Task 4, zusammen mit ihren Lesern in Blade und Pin-Test):

```php
    /**
     * Die Position des dynamischen URL-Buttons im Meta-Template.
     *
     * Eine echte geteilte Zahl: der Sendepfad setzt den Parameter auf diesen
     * index, und der Guard prueft genau diese Position. Alle sechs
     * Sendestellen im Modul hardcodieren 0 (Spec H3) — die Zahl ist damit
     * geteilte Annahme, nicht Wahrheit; der Fall „Button an Position 1" wird
     * hier sichtbar gemacht statt falsch gesendet.
     */
    public const URL_BUTTON_INDEX = 0;
```

- [ ] **Step 6: Run tests to verify they pass**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter TrainingCertificateWhatsAppDeliveryTest
```
Expected: PASS.

> **Achtung, aus Task 2 gelernt: die Fehlertexte des Senders tragen echte Umlaute.** `testKonfigurationsfehlerDesSendersStehtInDerMeldung` prüft den `error`-String, der aus `resolveTarget()` kommt. Die sechs möglichen Texte stehen nach Task 2 in `src/Services/Comms/HoldingTemplateSender.php:134-162` (vor Task 2 waren es `:103-131` — die Methode `resolveTarget()` hat alles um 31 Zeilen nach unten geschoben). Wörtlich übernehmen, zum Beispiel `'Kein aktiver WhatsApp-Kanal für den Account.'` — nicht transliterieren, und den Produktivtext nicht anpassen. In Task 2 war genau das die einzige Abweichung vom Plan-Code, und sie war richtig.

**Welche anderen Testklassen mitziehen müssen: keine. Gemessen am 2026-08-13, nicht vermutet.**

```
$ grep -rln "HoldingTemplateSender\|function sendOne" tests/
tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php

$ grep -rn "TrainingCertificateWhatsAppDelivery" src/ | grep -v "^src/Services/TrainingCertificateWhatsAppDelivery.php"
src/Livewire/HrDesk/Index.php:21    (use)
src/Livewire/HrDesk/Index.php:270   (der einzige deliver()-Aufruf)
```

- **Genau eine Testklasse** fasst den Sender an — die, die in dieser Task umgebaut wird. `HrDeskRejectionCertificateTest` stubbt ihn **nicht** und ruft `deliver()` **nicht**; `EmployeeCreationCertificateTest` nennt die Klasse nur in einem Kommentar (`:211`). Die frühere Plan-Fassung sagte „möglicherweise mit" — das war in beide Richtungen falsch und ist damit erledigt.
- **Genau ein Produktiv-Aufrufer:** `HrDesk/Index.php:267-283`. Er wird nicht angefasst: er liest `$delivery['status'] === STATUS_SENT` und sonst `$delivery['error']`, und beide bleiben (Statuswert und Rückgabeform unverändert). **Der Statuswert `template_without_url_button` erreicht ihn über den `error`-Zweig — dort wird auf `error !== null` geprüft, nicht auf einzelne Statuswerte** (`:275`). Genau deshalb braucht es keine vier Statuswerte für die vier `failed`-Ursachen (Spec W8).

Trotzdem einmal gegenprüfen, weil beide Klassen Zertifikate ausstellen und prozessweiten Zustand teilen (Folgeliste F3):

```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter 'HrDeskRejectionCertificateTest|EmployeeCreationCertificateTest'
```
Expected: PASS, unverändert. Werden sie rot, liegt es **nicht** an einer fehlenden Stub-Umstellung — dann zuerst den Teardown der umgebauten Klasse prüfen (zwei Stubs statt einem, Step 1f).

- [ ] **Step 7: Run the full suite**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml
```
Expected: `OK`, keine Fehler. Die Testzahl verschiebt sich (eine Methode entfällt, vier kommen dazu) — die genaue Zahl ist hier kein Kriterium, „keine Fehler" schon.

- [ ] **Step 8: Commit**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git add src/Services/TrainingCertificateWhatsAppDelivery.php \
        src/Support/TrainingCertificateWaTemplate.php \
        tests/Integration/TrainingCertificateWhatsAppDeliveryTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Zertifikat-Link als dynamischer URL-Button verschicken

Eigener Sendepfad mit WhatsAppMetaService::sendTemplate() nach dem Muster der
sechs bestehenden Button-Stellen; Vorlage war RecInterview, nicht
Applicant/Show. Der Button traegt die Zertifikat-uuid, nicht die vollstaendige
URL — die Basis-URL steht bei Meta.

Der Guard aus Task 12 wandert mit: er prueft jetzt den dynamischen URL-Button
an Position 0 statt der Body-Variable, und seine Meldung unterscheidet "kein
Button" von "Button an der falschen Position" — das sind zwei verschiedene
Anweisungen an HR.

Vier Ursachen fallen auf failed; jede bekommt einen eigenen Log-Marker, weil
der Statuswert sie nicht unterscheidet.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Konstanten, abgeleitete Button-URL, Hinweistext, Pin-Test

**Files:**
- Modify: `src/Support/TrainingCertificateWaTemplate.php` (`BODY_VARIABLE` `:31-47` raus, Sentinel + reine Funktion rein, Klassen-Docblock `:5-18`)
- Modify: `src/Livewire/Applicant/ApplicantSettingsModal.php` (neue `#[Computed]`-Methode neben `availableWhatsAppTemplates()`, `:336-360`)
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (`:181-186`)
- Modify: `src/Models/RecApplicantSettings.php` (`:60-69`)
- Modify: `tests/Unit/WhatsAppTemplateBodyVariablesTest.php` (`:200` Docblock, `:228-249` Pin-Test)
- Modify: `tests/Integration/TrainingCertificatePublicRouteTest.php` (+1 Test)
- Modify: `tests/Integration/SettingsModalCertificateToggleTest.php` (Anker in `testDieReihenfolgeDerSektionStehtFest`) — **in Task 3 gefunden, siehe Step 5b**

**Interfaces:**
- Consumes: `TrainingCertificateWaTemplate::URL_BUTTON_INDEX` (Task 3), `TrainingCertificateWaTemplate::ROUTE_NAME` (Bestand).
- Produces:
  - `TrainingCertificateWaTemplate::UUID_SENTINEL` (string) — urlsicherer Platzhalter für die Ableitung.
  - `TrainingCertificateWaTemplate::metaButtonUrlFrom(string $routeUrlWithSentinel): string` — pure Funktion, ersetzt den Sentinel durch `{{1}}`.
  - `ApplicantSettingsModal::metaButtonUrl()` (`#[Computed]`) — die abgeleitete Form für den Hinweistext.

**Kontext für den Ausführenden:** Der Blocker B1 aus dem Review: ein handgeschriebener String `'/recruiting/zertifikat/{{1}}'` wäre eine dritte Stelle mit derselben Annahme. Die Route registriert nur `/zertifikat/{uuid}` (`routes/public.php:54-55`); das Präfix `recruiting` kommt aus `RecruitingServiceProvider.php:128`. Deshalb wird die Form **aus `route()` erzeugt**. `{{1}}` direkt durch `route()` zu schicken funktioniert nicht — die Klammern werden zu `%7B%7B1%7D%7D` encodet; also ein Wort als Sentinel und danach ersetzen.

`TrainingCertificateWaTemplate` bleibt **Laravel-frei** (ihr Unit-Test baut keinen Container): sie ruft `route()` nicht selbst, sondern nimmt die fertige URL als Argument.

**Die Route trägt keine Constraint auf `{uuid}` — gemessen am 2026-08-13.** `routes/public.php:54-55` ist ein nacktes `Route::get('/zertifikat/{uuid}', …)->name(…)`, kein `->where('uuid', …)`, keine Pattern-Registrierung. Das Sentinel-Verfahren hängt damit **nicht** an der Annahme, dass Laravels Generator `where`-Regeln beim Bauen ignoriert: es gibt keine Regel, die er ignorieren müsste. Wer später eine Constraint ergänzt (z.B. auf UUID-Format), macht `UUID_SENTINEL` damit ungültig — dann schlägt der abgeleitete Aufruf fehl, und T-7 wird rot. Das ist der gewünschte Weg herum; ein Sentinel, der zufällig zur Constraint passt, wäre der stille.

- [ ] **Step 1: Write the failing tests**

**1a — Pin-Test in `tests/Unit/WhatsAppTemplateBodyVariablesTest.php`.** `testDerVariablennameStehtAnEinerStelle` (`:228-249`) ersetzen durch:

```php
    /**
     * Die Namen und die FORM stehen an einer Stelle.
     *
     * Vorher hing hier der Body-Variablenname; mit dem Button gibt es keinen
     * mehr, dafuer eine Form, die zusammenpassen muss: die bei Meta hinterlegte
     * Button-URL endet auf das Pfadsegment der Zertifikat-Route mit {{1}}.
     *
     * DIE FORM WIRD ABGELEITET, NICHT GEPFLEGT (Spec W7/B1): ein
     * handgeschriebener String waere eine dritte Stelle mit derselben Annahme —
     * die Route registriert nur /zertifikat/{uuid}, das Praefix kommt aus dem
     * ServiceProvider. Was die Ableitung im Betrieb ergibt, prueft
     * TrainingCertificatePublicRouteTest gegen den echten Router; hier steht
     * nur der Sentinel-Tausch, und der ist eine pure Funktion.
     */
    public function testFormDerButtonUrlEntstehtAusDerRoute(): void
    {
        $this->assertSame(0, TrainingCertificateWaTemplate::URL_BUTTON_INDEX);

        $this->assertSame(
            'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}',
            TrainingCertificateWaTemplate::metaButtonUrlFrom(
                'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/'
                . TrainingCertificateWaTemplate::UUID_SENTINEL
            )
        );
    }

    /**
     * Der Sentinel muss die URL-Kodierung ueberleben.
     *
     * GEMESSEN, nicht vermutet: {{1}} direkt durch route() zu schicken ergibt
     * %7B%7B1%7D%7D — deshalb ein Wort als Platzhalter. Ein Sentinel mit
     * Sonderzeichen haette denselben Fehler an anderer Stelle.
     */
    public function testSentinelIstUrlsicher(): void
    {
        $sentinel = TrainingCertificateWaTemplate::UUID_SENTINEL;

        $this->assertSame($sentinel, rawurlencode($sentinel), 'Der Sentinel darf nicht kodiert werden.');
        $this->assertNotSame('', $sentinel);
    }

    /**
     * Das Einstellungs-Modal nennt keine URL als Literal.
     *
     * Ohne diese Assertion waere die Ableitung gebaut und danach umgangen: ein
     * hartkodierter Hinweistext ueberlebt jeden Praefix- und Domainwechsel
     * still falsch.
     */
    public function testDasModalNenntKeineHartkodierteZertifikatUrl(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php'
        );

        $this->assertStringContainsString(
            'settings.' . TrainingCertificateWaTemplate::SETTINGS_KEY,
            $blade,
            'Ohne das Select im Modal ist der Schluessel nur per SQL setzbar.'
        );
        $this->assertStringContainsString(
            'metaButtonUrl',
            $blade,
            'Der Hinweistext muss die abgeleitete Form zeigen, keine getippte URL.'
        );
        $this->assertStringNotContainsString(
            '/recruiting/zertifikat/',
            $blade,
            'Kein URL-Literal in der Blade — die Form kommt aus der Route.'
        );
        $this->assertStringNotContainsString(
            'zertifikat_link',
            $blade,
            'Der Body-Weg ist ersetzt, nicht als Fallback behalten.'
        );
    }
```

**1b — Integrationsteil in `tests/Integration/TrainingCertificatePublicRouteTest.php`.** Neuer Test; der `router()`-Helfer dieser Klasse lädt `routes/public.php` **ohne** Präfix, das Präfix ist dort als bewusste Lücke benannt. Für die abgeleitete Form wird es gebraucht, also wird es hier — und nur hier — wie in der Host-App gesetzt:

```php
    /**
     * Die Form, die bei Meta in der Button-URL stehen muss — abgeleitet, nicht
     * getippt.
     *
     * DIE ERWARTUNG STEHT IM TEST UND NICHT IM PRODUKTIVCODE (Spec W7/B1): eine
     * Konstante mit dieser Form waere bei einem Praefix- oder Pfadwechsel still
     * falsch geblieben. So wird dieser Test rot — und das ist die gewuenschte
     * Wirkung, denn dann muss die Button-URL im Meta-Manager nachgezogen werden
     * (Spec T1). Was dort wirklich hinterlegt ist, sieht kein Test.
     *
     * Das Praefix wird hier gesetzt wie in RecruitingServiceProvider.php:128 —
     * der router()-Helfer dieser Klasse laedt bewusst ohne, weil er die
     * Registrierung IN routes/public.php prueft.
     */
    public function testFormDerMetaButtonUrlKommtAusDerRoute(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);

        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();

        $url = new \Illuminate\Routing\UrlGenerator(
            $router->getRoutes(),
            \Illuminate\Http\Request::create('https://mitarbeiter.rheingedeck.de')
        );

        $mitSentinel = $url->route(
            TrainingCertificateWaTemplate::ROUTE_NAME,
            ['uuid' => TrainingCertificateWaTemplate::UUID_SENTINEL]
        );

        $this->assertSame(
            'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}',
            TrainingCertificateWaTemplate::metaButtonUrlFrom($mitSentinel),
            'Aendert sich Praefix oder Pfad, muss die Button-URL bei Meta nachgezogen werden.'
        );

        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }
```

Import `use Platform\Recruiting\Support\TrainingCertificateWaTemplate;` am Kopf ergänzen. **Aufräumen ist Pflicht** — die Klasse setzt bereits Facade-Wurzeln und begründet im Docblock (`:30-35`), warum: der Schaden trifft spätere Testklassen und fällt nur im Gesamtlauf auf.

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter 'WhatsAppTemplateBodyVariablesTest|TrainingCertificatePublicRouteTest'
```
Expected: FAIL — `UUID_SENTINEL` / `metaButtonUrlFrom()` undefined, und der Blade-Test findet `zertifikat_link` sowie kein `metaButtonUrl`.

- [ ] **Step 3: Konstantenklasse umstellen**

In `src/Support/TrainingCertificateWaTemplate.php`: den Block `BODY_VARIABLE` (`:31-47`) **ersatzlos entfernen** und stattdessen einsetzen:

```php
    /**
     * Urlsicherer Platzhalter fuer die Ableitung der Meta-Button-URL.
     *
     * GEMESSEN, nicht vermutet: {{1}} direkt durch route() zu schicken ergibt
     * %7B%7B1%7D%7D — die Klammern werden kodiert. Deshalb ein Wort durch
     * route() schicken und danach tauschen.
     */
    public const UUID_SENTINEL = 'ZERTUUIDPLATZHALTER';

    /**
     * Aus einer mit UUID_SENTINEL gebauten Route-URL die Form machen, die im
     * Meta-Template hinter dem Button stehen muss.
     *
     * WARUM ABGELEITET UND NICHT ALS KONSTANTE: die Route registriert nur
     * /zertifikat/{uuid} (routes/public.php), das Praefix „recruiting" kommt aus
     * RecruitingServiceProvider.php:128, und der Host kommt aus dem Request. Ein
     * getippter String waere eine dritte Stelle mit derselben Annahme — und ein
     * Praefixwechsel haette ihn still falsch gemacht, statt einen Test rot zu
     * machen. Die Erwartung an das Ergebnis steht deshalb im Test
     * (TrainingCertificatePublicRouteTest), nicht hier.
     *
     * DIESE KLASSE BLEIBT LARAVEL-FREI: sie ruft route() nicht selbst, sondern
     * nimmt die fertige URL. Den Aufruf macht die Livewire-Komponente, die den
     * Hinweistext rendert.
     */
    public static function metaButtonUrlFrom(string $routeUrlWithSentinel): string
    {
        return str_replace(self::UUID_SENTINEL, '{{1}}', $routeUrlWithSentinel);
    }
```

Den Klassen-Docblock (`:5-18`) nachziehen: aus „drei Strings, vier Orte" wird „zwei Namen und eine Form"; der Satz zur Body-Variable fällt, der Verweis auf die abgeleitete Form kommt dazu.

- [ ] **Step 4: Computed-Property in der Livewire-Komponente**

In `src/Livewire/Applicant/ApplicantSettingsModal.php`, direkt **nach** `availableWhatsAppTemplates()` (endet `:360`):

```php
    /**
     * Die URL, die im Meta-Template hinter dem Zertifikat-Button stehen muss.
     *
     * Abgeleitet aus der Route (Spec W7): Host, Praefix und Pfad kommen alle aus
     * der laufenden App, damit der Hinweistext nicht neben der Wirklichkeit
     * steht. Was bei Meta WIRKLICH hinterlegt ist, sieht das Modul nie — deshalb
     * steht der Preis im Hinweistext daneben.
     */
    #[Computed]
    public function metaButtonUrl(): string
    {
        return \Platform\Recruiting\Support\TrainingCertificateWaTemplate::metaButtonUrlFrom(
            route(
                \Platform\Recruiting\Support\TrainingCertificateWaTemplate::ROUTE_NAME,
                ['uuid' => \Platform\Recruiting\Support\TrainingCertificateWaTemplate::UUID_SENTINEL]
            )
        );
    }
```

- [ ] **Step 5: Hinweistext in der Blade**

`resources/views/livewire/applicant/applicant-settings-modal.blade.php:181-186` ersetzen:

```blade
                    @php($zertButtonUrl = $this->metaButtonUrl)
                    <p class="text-xs text-[var(--ui-muted)] -mt-2">
                        Das Template braucht einen <strong>URL-Button mit Variable an erster Position</strong>;
                        seine URL muss lauten: <code>{{ $zertButtonUrl }}</code>.
                        Fehlt der Button oder steht er an einer anderen Position, wird nichts versendet
                        (sonst ginge eine Nachricht ohne Link raus) und die Ablehnung meldet es.
                        Ohne Template wird das Zertifikat trotzdem ausgestellt und muss von Hand verschickt werden.
                        <br>
                        <strong>Wichtig bei einem Domainwechsel:</strong> die Basis-URL steht bei Meta und muss
                        dort nachgezogen werden — das Modul kann nicht prüfen, ob sie stimmt.
                    </p>
```

**Warum `@php(...)` in Block-freier Kurzform hier zulässig ist und trotzdem geprüft werden muss:** die Kurzform bricht, wenn später `@php`-Blöcke in derselben Datei folgen (500-ParseError). Nach dem Einfügen prüfen:

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && \
php tools/blade-check.php
```
Expected: keine Funde. Findet das Werkzeug etwas, den Wert stattdessen direkt als `{{ $this->metaButtonUrl }}` in den `<code>`-Ausdruck setzen und die `@php`-Zeile weglassen.

- [ ] **Step 5b: Den zweiten Blade-Leser mitziehen — sonst wird er rot**

**In Task 3 gefunden, nicht vorher bekannt.** `tests/Integration/SettingsModalCertificateToggleTest.php` nagelt die Reihenfolge der Zertifikat-Sektion im Einstellungs-Modal fest und benutzt in `testDieReihenfolgeDerSektionStehtFest` (`:161-180`) den String `'zertifikat_link'` als Anker für den Eintrag `'Zertifikat-Hinweis'` — **plus** eine Assertion `substr_count($source, $needle) === 1`. Nachgemessen:

```php
// tests/Integration/SettingsModalCertificateToggleTest.php:172 — heute
            'Zertifikat-Hinweis'           => 'zertifikat_link',
```

Sobald Step 5 den Hinweistext ersetzt, findet dieser Anker nichts mehr und der Test wird rot. **Er darf nicht gestrichen werden** — er hält die Reihenfolge der Sektion, und das ist eine andere Zusage als die des Pin-Tests.

Ersetze den Anker durch einen, der zum neuen Text gehört und **genau einmal** in der Blade vorkommt. Der Kandidat aus Step 5 ist `metaButtonUrl` (der Ausdruck, mit dem die Blade die abgeleitete URL zieht):

```php
            'Zertifikat-Hinweis'           => 'metaButtonUrl',
```

**Prüf die Einmaligkeit, statt sie anzunehmen:** hast du in Step 5 sowohl `@php($zertButtonUrl = $this->metaButtonUrl)` als auch `{{ $zertButtonUrl }}` geschrieben, kommt `metaButtonUrl` **einmal** vor — schreibst du stattdessen zweimal `{{ $this->metaButtonUrl }}`, zweimal. Dann ist der Anker ein anderer oder die Blade wird auf eine Verwendung reduziert. Kontrolle:

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && \
grep -c "metaButtonUrl" resources/views/livewire/applicant/applicant-settings-modal.blade.php
```
Expected: `1`.

Danach:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter SettingsModalCertificateToggleTest
```
Expected: PASS.

- [ ] **Step 6: Kommentar am Settings-Default**

`src/Models/RecApplicantSettings.php:60-69`, der Kommentarblock über `'training_certificate_wa_template_id' => null,`:

```php
        // Schulungszertifikat: genehmigtes WhatsApp-Template, mit dem Weg (a)
        // den PDF-Link nach der Ablehnung zustellt. Das Template MUSS einen
        // dynamischen URL-Button an erster Position haben — der Link steckt im
        // Button, nicht im Fliesstext. Fehlt er, verweigert der Guard in
        // TrainingCertificateWhatsAppDelivery den Versand, statt eine Nachricht
        // ohne Link rauszuschicken. Leer = es wird trotzdem ausgestellt, nur
        // nicht zugestellt: der Versand ist die Zugabe, nicht die Bedingung.
        // Schluesselname steht in
        // Support/TrainingCertificateWaTemplate::SETTINGS_KEY.
```

- [ ] **Step 7: Docblock des Builder-Pin-Tests nachziehen**

`tests/Unit/WhatsAppTemplateBodyVariablesTest.php:200` (`testUrlButtonBekommtKeinenParameter`): Die Assertion bleibt **unverändert richtig** — `HoldingTemplateComponents::build()` gibt nie einen Button-Component zurück. Nur die Begründung im Docblock dreht sich: das war „deshalb Body-Variable", jetzt ist es „deshalb hängt die Delivery den Button selbst an" (Spec W3/W4). Den Docblock entsprechend umschreiben, keine Assertion anfassen.

- [ ] **Step 8: Run tests to verify they pass**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml \
  --filter 'WhatsAppTemplateBodyVariablesTest|TrainingCertificatePublicRouteTest'
```
Expected: PASS.

- [ ] **Step 9: `BODY_VARIABLE` ist restlos weg**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting && \
grep -rn "BODY_VARIABLE\|zertifikat_link" src/ resources/ tests/ docs/superpowers/specs/2026-08-13-zertifikat-wa-button-design.md
```
Expected: **nur** Treffer in der Spec und in `docs/zertifikat/` (dort beschreiben sie die Herkunft und sollen stehen bleiben). Kein Treffer in `src/`, `resources/`, `tests/`. Findet sich einer, ist der Body-Weg noch halb da — genau das, was die Spec als „zwei Pfade zum Pflegen" ausschließt.

**Die Liste der Leser, gemessen am Ende von Task 3** — mehr als diese darf der Grep nicht finden:
- `src/Support/TrainingCertificateWaTemplate.php:32,42,47` (Docblock + Konstante) → Step 3
- `src/Models/RecApplicantSettings.php:62` (Kommentar) → Step 6
- `resources/views/livewire/applicant/applicant-settings-modal.blade.php:182` (Hinweistext) → Step 5
- `tests/Unit/WhatsAppTemplateBodyVariablesTest.php` (Zeilen 89, 119, 123, 156, 160, 180, 184, 210, 230, 240) → Step 1a und Step 7. **Achtung:** die Vorkommen bei `:89-210` sind **Testdaten** in den Builder-Tests (ein Template mit einer Body-Variable dieses Namens) — die dürfen bleiben, wenn sie nach dem Umbau noch eine Aussage tragen, müssen dann aber auf einen neutralen Variablennamen umgeschrieben werden, damit der Grep aus diesem Step sauber ist. Entscheide beim Lesen: trägt der Test eine Aussage über `HoldingTemplateComponents`, bleibt er mit umbenannter Variable; trägt er eine über den Zertifikat-Weg, entfällt er.
- `tests/Integration/SettingsModalCertificateToggleTest.php:172` (Anker) → Step 5b
- **Im Produktivcode wird `BODY_VARIABLE` nach Task 3 nirgends mehr gelesen** — nur noch definiert. Die Konstante fällt also ohne Ersatz.

- [ ] **Step 10: Run the full suite**

Run:
```bash
cd /Users/shaustein/Documents/dev/platforms/meingedeck && \
vendor/bin/phpunit -c /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/phpunit.xml
```
Expected: `OK`, keine Fehler.

- [ ] **Step 11: Commit**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git add src/Support/TrainingCertificateWaTemplate.php \
        src/Livewire/Applicant/ApplicantSettingsModal.php \
        resources/views/livewire/applicant/applicant-settings-modal.blade.php \
        src/Models/RecApplicantSettings.php \
        tests/Unit/WhatsAppTemplateBodyVariablesTest.php \
        tests/Integration/TrainingCertificatePublicRouteTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): erwartete Meta-Button-URL aus der Route ableiten

BODY_VARIABLE faellt, und ihr Ersatz ist kein getippter String: die Form
'.../recruiting/zertifikat/{{1}}' entsteht aus route() mit einem Sentinel. Ein
handgeschriebener Wert waere eine dritte Stelle mit derselben Annahme — die
Route registriert nur /zertifikat/{uuid}, das Praefix kommt aus dem
ServiceProvider. Die Erwartung steht jetzt im Test und wird bei einem
Praefixwechsel rot, statt still falsch zu sein.

Hinweistext im Einstellungs-Modal zeigt die abgeleitete URL und benennt den
Preis: die Basis-URL liegt bei Meta und ist von hier nicht pruefbar.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Nach dem letzten Task — was NICHT zu diesem Plan gehört

Keine dieser Schritte wird hier ausgeführt; sie stehen, damit niemand sie als vergessen ansieht:

- **Push, Merge, `meingedeck`-Bump.** Erst nach der Freigabe; Merge als ff auf main, kein PR per CLI.
- **Meta-Template anlegen und genehmigen lassen** — muss **vor** dem Deploy fertig sein (Spec Rollout 1). Button-URL `https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}`, URL-Button an erster Position.
- **HR stellt den Settings-Key um** (Spec Rollout 2). Bis dahin: ausgestellt, nichts versandt, Meldung an HR — der Guard trägt das Fenster.
- **Live-Sichtprüfung** in `docs/zertifikat/live-checkliste.md` nachtragen: eine Ablehnung mit Haken, am Gerät prüfen, dass die Nachricht einen **Button** trägt, dass er das PDF öffnet, und dass `wa_sent_at` gesetzt ist.
- **`queue:restart` ist nicht nötig** — der Versand läuft synchron im Livewire-Request.
- **Folgeliste F10–F12** (Thread-Kontext, Extraktion der Auflösung, `index`-Ermittlung + `Show.php`-Fix) sind eigene Vorhaben.

## Self-Review

**Spec-Deckung** — jede Zusage der Spec hat eine Task:

| Spec | Task |
| --- | --- |
| W1 eigener Sendepfad, `sender:`/`isAutoReply` explizit | 3, Step 4 |
| W2 `resolveTarget()`, `not_configured` bleibt vorgeschaltet | 2; 3 Step 4 |
| W3 `build()` aufrufen, `hasEmptyRequiredParam` als eigener Zweig | 3, Step 4 + T-5/T-6 |
| W4 Button-Component, `uuid`, `index 0`, Leerwert-Riegel, `$link` bleibt | 3, Step 4 + T-3 |
| W5 Guard-Klasse, strenges Kriterium, Position, zwei Meldungen | 1; 3 Step 4 (`buttonMeldung`) + T-2a/T-2b |
| W6 kein Token im Pfad, kein Thread-Kontext | 3 Docblock + T-3-Gegenprobe; F10 |
| W7 Body-Weg ersatzlos, Ableitung statt Konstante | 4, Steps 3–9 |
| W8 Log-Marker pro Ursache | 3, Step 4 (`fehler()`) + T-9 |
| H2 Button trägt nur die `uuid` | 3, T-3 |
| H3 `index 0` geteilt zwischen Sender und Guard | `URL_BUTTON_INDEX`, Task 3 Step 5 |
| T-1 … T-9 | 1 (T-1), 3 (T-2/3/5/6/9), 4 (T-4-Ersatz/T-7/T-8) |
| Statusliste, vier unveränderte Zweige | 3, Step 4 (oberer Teil unangetastet) |

**Eine bewusste Abweichung, benannt:** T-4 der Spec („Quelltext-Assertion: die Delivery ruft nirgends `getPublicUrl()`") ist im Plan **nicht** als eigene Quelltext-Assertion umgesetzt, sondern als Payload-Gegenprobe in T-3 (`assertStringNotContainsString($applicant->public_token, …)` plus `assertStringNotContainsString('https://', …)`). Grund: die Spec selbst nennt die Quelltext-Assertion die schwächere — sie wird grün, sobald jemand den Aufruf in einen Helfer verlegt. Die Payload-Prüfung greift unabhängig davon, wie der Wert beschafft würde. Wer die Quelltext-Assertion zusätzlich will, hängt sie an Task 4; sie ist Leitplanke, nicht Nachweis.

**Platzhalter-Scan:** keine „TBD", kein „ähnlich wie Task N", kein „Fehlerbehandlung ergänzen". Drei Stellen verweisen bewusst auf Bestandscode zum wörtlichen Abschreiben statt zum Erraten — Fehlertexte in `HoldingTemplateSender:96-135` (Task 2), Settings-Fixtures `:855-885` und die Token-Gegenprobe `:251-260` (Task 3). Das ist Absicht: geratene Literale wären hier der wahrscheinlichere Fehler als fehlender Code.

**Typ-Konsistenz geprüft:** `dynamicIndexes`/`hasDynamicAt`/`describe` heißen in Task 1 (Definition), Task 3 (`buttonMeldung`) und im Spec-Text gleich. `resolveTarget(int, string): array{error, template, channel}` ist in Task 2 definiert, in Task 3 konsumiert und im Sender-Stub gleich geformt. `URL_BUTTON_INDEX` entsteht in Task 3 Step 5 und wird in Task 4 nur noch gelesen. `metaButtonUrlFrom(string): string` und `UUID_SENTINEL` entstehen in Task 4 Step 3 und werden in Steps 1a/1b/4 verwendet. `STATUS_TEMPLATE_WITHOUT_URL_BUTTON` ersetzt `STATUS_TEMPLATE_WITHOUT_VARIABLE` in Task 3 — der alte Name existiert danach nirgends mehr (Step 9 grept ihn mit).
