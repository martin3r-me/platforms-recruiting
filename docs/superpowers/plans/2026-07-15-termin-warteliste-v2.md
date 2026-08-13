# Termin-Warteliste V2 (Dauerabo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das Termin-Abo wird von Variante A (einmalig benachrichtigen, manuelles Re-Arm) auf Variante B (Dauerabo) umgestellt: Es feuert bei JEDEM echten Voll→Frei-Übergang des Termins erneut, ohne Nachklicken. Dazu: Abmelden-Action an der Termin-Karte, Skip-Logik (Termin-Abo gewinnt über Ort-Abo), persistenter Ort-Button unter der Terminliste, termin-spezifisches WhatsApp-Template mit `{{termin}}`-Variable.

**Architecture:** Der Voll→Frei-Übergang wird NICHT im Notify-Job rekonstruiert, sondern auf der Arm-Seite erkannt: Ein neues `armed`-Flag am Wartelisten-Eintrag wird gesetzt, wenn der Termin VOLL wird (neuer Re-Arm-Hook auf Buchungs-Aktivierung + Kapazitäts-Senkung), und atomar verbraucht, wenn der Job bei freiem Platz zustellt. Damit ist Produktentscheidung 2 ("ein Ereignis pro Frei-Fenster") strukturell garantiert: Storno-Welle im selben Fenster findet `armed=0` vor; "frei→noch freier" armiert nie. `notified_at` wird zum reinen Zeitstempel "zuletzt benachrichtigt" (1h-Notbremse). Der Termin-Zweig bekommt einen EIGENEN Claim-Loop (`notifyTerminEntries`); der Ort-Zweig samt V1-Claim-Loop (`notifyEntries`) bleibt byte-unverändert — nur seine Query bekommt den Skip-Filter.

**Begründung der Trigger-Frage (gegen Code auf main geklärt, Stand 6c8e864):** Der V1-Storno-Observer (`RecInterviewWaitlistObserver.php:50-63`) feuert bei JEDEM Status→cancelled ohne Voll-Check; der Job (`NotifyWaitlistForInterview.php:41-61`) prüft nur den IST-Zustand "ist jetzt Platz frei". Den ÜBERGANG "war voll → ist frei" kennt heute niemand. V2 braucht ihn aber als Ereignis-Grenze — und die zuverlässigste, race-arme Stelle dafür ist die Gegenrichtung: "wurde voll" ist ein diskretes, beobachtbares Ereignis (Buchung wird aktiv / Kapazität sinkt) und armiert die Einträge. Die Frei-Seite bleibt bei den bestehenden Dispatches (Storno-Observer, Interview-Observer) — Über-Dispatch ist weiterhin harmlos, weil der atomare Claim jetzt auf `armed` läuft. Der Job braucht so keine Historie und keine neue Zustandstabelle.

**Tech Stack:** PHP 8 / Laravel-Modul `platforms-recruiting`, Livewire 3, reines PHPUnit (Runner aus meingedeck-vendor), MySQL-Migration, Carbon 3 (`->locale('de')` für die `{{termin}}`-Variable — NICHT `APP_LOCALE`-abhängig).

## Global Constraints

- Tests laufen OHNE Laravel/DB: nur `PHPUnit\Framework\TestCase` (Carbon aus dem meingedeck-vendor ist in Tests verfügbar und standalone nutzbar). Job/Observer/Migration → `php -l` + Suite + Harness (Task 8).
- Test-Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` im Modul-Root. Suite-Baseline vor V2: 165 Tests.
- **HEIKELSTE ÄNDERUNG DES PLANS (Task 4):** V2 hebt die V1-Invariante "max. 1 Benachrichtigung pro Scharfschaltung" für den TERMIN-Zweig bewusst auf. Der Ort-Zweig behält sie: `notifyEntries()` bleibt byte-unverändert gegenüber V1 (Reviewer-Gate: Verbatim-Vergleich, wie in V1 hunk-für-hunk). Neue Termin-Invariante: **max. 1 Benachrichtigung pro Voll→Frei-Fenster UND mindestens 60 Minuten Abstand pro Person+Termin.**
- Deploy-Sicherheit wie V1: Die Migration setzt NUR die neue Spalte (DDL + ein Query-Builder-Backfill auf der neuen Spalte — keine Model-Events, kein Dispatch). Bestands-Ort-Einträge werden weder umklassifiziert noch getriggert. Trigger-Sicherheits-Check vor Push (Task 8), bei Nicht-Bestätigung: STOPP.
- Blade: `@php ... @endphp` Block-Form, Direktiven balanciert, Werte vorberechnet.
- Kein Edit außerhalb von platforms-recruiting. Commits deutsch, conventional mit Scope.
- Nach Push: STOPP (Merge/Bump separat nach Freigabe). Nach Deploy: **`queue:restart` PFLICHT** (Notify-Job wird geändert).
- Produktentscheidungen 1-9 aus dem User-Briefing sind FIX — nicht neu herleiten, nicht aufweichen.

## Vorab-Audit (User-Fragen P0/P1, gegen Code auf main 6c8e864 bestätigt)

1. **Fill-Pfade vollständig gehookt:** 4 Erzeuger (Public updateOrCreate :273, HR-Index updateOrCreate :240, MCP-Tool updateOrCreate :106, Applicant/Index ::create :577) + alle cancelled-weg-Reaktivierer (3× updateOrCreate, updateStatus $booking->update, Tool $booking->save) laufen über Model-Events. Einziger Query-Builder-Statuswechsel: RecApplicant.php:584-586 booked→registered — kein Fill-Ereignis (Belegung unverändert, where('status','booked') schließt Reaktivierung aus). KEIN ungehookter Pfad, kein Umbau nötig.
2. **Interview-Guard** betritt den Body bei max_participants-only-Änderung (wasChanged-Liste enthält max_participants; Observer:40-41) — Kapazitäts-Rearm ist kein toter Code.
3. **sendBookingLinkWhatsApp** liefert bei nicht aufgelöstem Key/Template/Account `return false` (kein Throw) — Fallback + Claim-Rollback greifen.
4. **Drei Bestandsaufrufer** (:689/:704/:898), keiner mit 5. Argument → $bodyValues-Default = Verhalten identisch.
5. **waitlistEntry()** trägt den ortBased()-Filter aus V1 unverändert.

Whole-Branch-Review-Mandat (User): T4+T5 als gekoppeltes Paar GEMEINSAM gegen die drei Pflicht-Harness-Fälle prüfen (feuert wieder / nicht doppelt im Fenster / Bremse greift ohne zu verbrennen) — ein Fehler in T5 macht T4s korrekten Claim wirkungslos.

## Ausführungsempfehlung

**Subagent-Driven (wie V1):** frischer Implementer pro Task + unabhängiger Task-Reviewer + finales Whole-Branch-Review auf dem stärksten Modell. Task 4 (Claim-Loop-Umbau) bekommt im Reviewer-Prompt explizit den Verbatim-Vergleich des Ort-Zweigs als benannten Prüfauftrag. Inline-Ausführung ist möglich, aber beim Job-Umbau ist das unabhängige Review-Gate der eigentliche Sicherheitsmechanismus — nicht darauf verzichten.

---

### Task 0: Branch anlegen

**Files:** keine (nur git)

- [ ] **Step 1: Fetch + Basis prüfen**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git log --oneline -1 origin/main
```

- [ ] **Step 2: Branch von origin/main**

```bash
git checkout -b feature/termin-warteliste-v2 origin/main
```

---

### Task 1: Migration `armed` + Backfill + Model

**Files:**
- Create: `database/migrations/2026_07_16_000001_add_armed_to_rec_interview_waitlist.php`
- Modify: `src/Models/RecInterviewWaitlist.php`

**Interfaces:**
- Consumes: bestehende Tabelle/Scopes aus V1.
- Produces: Spalte `armed` (boolean, default true), Model-Cast `'armed' => 'boolean'` + fillable. Semantik: `armed=1` = "beim nächsten freien Platz benachrichtigen" (nur für Termin-Einträge relevant; an Ort-Einträgen wird das Flag nie gelesen). `notified_at` bleibt bestehen, bedeutet ab jetzt nur noch "zuletzt benachrichtigt am".

- [ ] **Step 1: Migration schreiben**

Datei `database/migrations/2026_07_16_000001_add_armed_to_rec_interview_waitlist.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            // Dauerabo-Zustand (nur Termin-Einträge): 1 = beim nächsten
            // freien Platz benachrichtigen. Wird beim Voll-Werden des
            // Termins gesetzt (Re-Arm-Hook) und beim Zustellen atomar
            // verbraucht. An Ort-Einträgen ungenutzt (Ort-Zweig läuft
            // unverändert über notified_at).
            $table->boolean('armed')->default(true)->after('notified_at');
        });

        // Backfill NUR auf der neuen Spalte (Query-Builder: keine Model-
        // Events, kein Observer, kein Dispatch — deploy-sicher):
        // V1-Termin-Einträge, die bereits benachrichtigt wurden, starten
        // entwaffnet — sie werden automatisch wieder scharf, sobald ihr
        // Termin das nächste Mal voll wird.
        DB::table('rec_interview_waitlist')
            ->whereNotNull('rec_interview_id')
            ->whereNotNull('notified_at')
            ->update(['armed' => false]);
    }

    public function down(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            $table->dropColumn('armed');
        });
    }
};
```

- [ ] **Step 2: Model erweitern**

In `src/Models/RecInterviewWaitlist.php`: im `$fillable`-Array nach `'notified_at',` einfügen:

```php
        'armed',
```

und im `$casts`-Array nach `'notified_at'  => 'datetime',` einfügen:

```php
        'armed'        => 'boolean',
```

- [ ] **Step 3: Gate**

```bash
php -l database/migrations/2026_07_16_000001_add_armed_to_rec_interview_waitlist.php
php -l src/Models/RecInterviewWaitlist.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: Syntax clean, Suite 165 grün.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_16_000001_add_armed_to_rec_interview_waitlist.php src/Models/RecInterviewWaitlist.php
git commit -m "feat(recruiting): armed-Flag an Warteliste — Fundament Termin-Dauerabo"
```

---

### Task 2: Pure Logik — `TerminLabel` + Planner-Vereinfachung (TDD)

**Files:**
- Create: `src/Services/TerminLabel.php`
- Create: `tests/Unit/TerminLabelTest.php`
- Modify: `src/Services/WaitlistEnrollmentPlanner.php` (nur `planForInterview()`)
- Modify: `tests/Unit/WaitlistEnrollmentPlannerTest.php` (nur die 3 planForInterview-Tests)

**Interfaces:**
- Consumes: Carbon (aus meingedeck-vendor, standalone).
- Produces:
  - `TerminLabel::format(\Carbon\CarbonInterface $startsAt): string` → `"Samstag, 25. Juli 2026 um 15:00 Uhr"` — explizit `->locale('de')`, nur `starts_at` (keine End-Zeit, `ends_at` ist nicht garantiert gefüllt). Task 3 konsumiert das.
  - `planForInterview(?array $openEntry): array` → NEU nur noch `['action' => 'create'|'noop']`: offener Eintrag (egal ob benachrichtigt) = aktives Dauerabo = noop. Der `rearm`-Action-Wert entfällt ersatzlos — manuelles Re-Arm existiert im Dauerabo-Modell nicht mehr. Task 5 (Komponente) verlässt sich exakt darauf.

- [ ] **Step 1: Failing Tests schreiben**

Datei `tests/Unit/TerminLabelTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\TerminLabel;

class TerminLabelTest extends TestCase
{
    public function test_format_deutsch_mit_wochentag_und_uhrzeit(): void
    {
        $this->assertSame(
            'Samstag, 25. Juli 2026 um 15:00 Uhr',
            TerminLabel::format(Carbon::create(2026, 7, 25, 15, 0))
        );
    }

    public function test_format_ignoriert_ambiente_locale(): void
    {
        // Explizites ->locale('de') im Format — auch wenn die globale
        // Carbon-Locale (APP_LOCALE-Sync) auf en steht, kommt Deutsch raus.
        Carbon::setLocale('en');
        try {
            $this->assertSame(
                'Mittwoch, 24. Dezember 2025 um 08:05 Uhr',
                TerminLabel::format(Carbon::create(2025, 12, 24, 8, 5))
            );
        } finally {
            Carbon::setLocale('en');
        }
    }

    public function test_format_fuehrende_null_bei_minuten(): void
    {
        $this->assertSame(
            'Montag, 3. August 2026 um 09:07 Uhr',
            TerminLabel::format(Carbon::create(2026, 8, 3, 9, 7))
        );
    }
}
```

In `tests/Unit/WaitlistEnrollmentPlannerTest.php` die drei bestehenden planForInterview-Tests ERSETZEN durch:

```php
    // --- planForInterview: Termin-Dauerabo (V2) ---
    // Offener Eintrag = aktives Abo = noop, egal ob schon benachrichtigt.
    // Manuelles Re-Arm existiert nicht mehr (automatisches Re-Arm beim
    // Voll-Werden des Termins, siehe WaitlistRearmService).

    public function test_termin_kein_eintrag_ergibt_create(): void
    {
        $this->assertSame(
            ['action' => 'create'],
            WaitlistEnrollmentPlanner::planForInterview(null)
        );
    }

    public function test_termin_offener_eintrag_ergibt_noop(): void
    {
        $this->assertSame(
            ['action' => 'noop'],
            WaitlistEnrollmentPlanner::planForInterview(['notified' => false])
        );
    }

    public function test_termin_benachrichtigter_offener_eintrag_ergibt_ebenfalls_noop(): void
    {
        // V1 hätte hier 'rearm' geliefert — im Dauerabo-Modell ist der
        // Eintrag weiterhin aktiv, ein Klick darf notified_at (Basis der
        // 1h-Notbremse) NICHT nullen.
        $this->assertSame(
            ['action' => 'noop'],
            WaitlistEnrollmentPlanner::planForInterview(['notified' => true])
        );
    }
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/TerminLabelTest.php tests/Unit/WaitlistEnrollmentPlannerTest.php
```

Erwartung: TerminLabel-Tests FAIL (Class not found), der geänderte rearm→noop-Test FAIL (bekommt noch `['action' => 'rearm']`).

- [ ] **Step 3: Implementierung**

Datei `src/Services/TerminLabel.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Carbon\CarbonInterface;

/**
 * Formatiert einen Schulungstermin für Bewerber-Nachrichten
 * ({{termin}}-Variable im WhatsApp-Template).
 *
 * Bewusst explizites ->locale('de') statt Verlass auf die globale
 * Carbon-Locale: die kommt aus APP_LOCALE (Default 'en') und wäre im
 * Queue-Worker eine stille Env-Abhängigkeit. Nur starts_at — ends_at
 * ist nullable und nicht garantiert gefüllt.
 */
class TerminLabel
{
    public static function format(CarbonInterface $startsAt): string
    {
        return $startsAt->locale('de')->translatedFormat('l, j. F Y')
            . ' um ' . $startsAt->format('H:i') . ' Uhr';
    }
}
```

(Format-Detail: `j` statt `d` für den Tag — "3. August", nicht "03. August"; deckt sich mit dem Zielformat "25. Juli". Der Test mit dem 3. August sichert das ab.)

In `src/Services/WaitlistEnrollmentPlanner.php` die Methode `planForInterview()` ERSETZEN durch:

```php
    /**
     * Entscheidung für den Termin-Warteliste-Klick (V2, Dauerabo):
     * offener Eintrag = aktives Abo = noop — egal ob schon benachrichtigt.
     * Re-Arm passiert automatisch beim Voll-Werden des Termins
     * (WaitlistRearmService), nie durch Klick. Ein Klick-Re-Arm würde
     * notified_at nullen und damit die 1h-Notbremse aushebeln.
     *
     * @param array{notified: bool}|null $openEntry
     * @return array{action: 'noop'|'create'}
     */
    public static function planForInterview(?array $openEntry): array
    {
        return $openEntry === null
            ? ['action' => 'create']
            : ['action' => 'noop'];
    }
```

- [ ] **Step 4: Tests grün + Gate**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/TerminLabelTest.php tests/Unit/WaitlistEnrollmentPlannerTest.php
php -l src/Services/TerminLabel.php
php -l src/Services/WaitlistEnrollmentPlanner.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: 17 Tests in den zwei Dateien grün, Gesamt-Suite 168 grün (165 + 3 TerminLabel).

- [ ] **Step 5: Commit**

```bash
git add src/Services/TerminLabel.php tests/Unit/TerminLabelTest.php src/Services/WaitlistEnrollmentPlanner.php tests/Unit/WaitlistEnrollmentPlannerTest.php
git commit -m "feat(recruiting): TerminLabel (de) + planForInterview auf Dauerabo-Semantik"
```

---

### Task 3: Versand — `$bodyValues`-Parameter + Termin-Template-Methode

**Files:**
- Modify: `src/Models/RecApplicant.php` (nur `sendBookingLinkWhatsApp()`-Signatur/Match + neue Methode `sendTerminWaitlistNotification()`)

**Interfaces:**
- Consumes: `TerminLabel::format()` (Task 2).
- Produces: `RecApplicant::sendTerminWaitlistNotification(RecInterview $interview): bool` — Task 4 (Job) ruft genau das. Settings-Key `interview_waitlist_termin_wa_template_id` in derselben Position→Team-Kaskade wie das bestehende `interview_waitlist_wa_template_id`. Fallback: Ist das Termin-Template (noch) nicht konfiguriert oder schlägt der Versand fehl, wird das bestehende generische Ort-Template versucht — das Feature funktioniert damit auch VOR dem Meta-Approval des neuen Templates.

- [ ] **Step 1: `sendBookingLinkWhatsApp()` um `$bodyValues` erweitern**

Signatur ändern von:

```php
    private function sendBookingLinkWhatsApp(string $templateSettingKey, string $logType, string $logSummary, string $contextPurpose = 'interview_booking'): bool
```

zu:

```php
    private function sendBookingLinkWhatsApp(string $templateSettingKey, string $logType, string $logSummary, string $contextPurpose = 'interview_booking', array $bodyValues = []): bool
```

Und im Body-Parameter-Resolver den `match`-Block ersetzen. Bisher:

```php
                foreach ($bodyParams as $param) {
                    $value = match (strtolower($param['name'])) {
                        '1', 'name', 'vorname' => $contactName,
                        default => $param['example'] ?: $contactName,
                    };
```

Neu:

```php
                foreach ($bodyParams as $param) {
                    // Explizit übergebene Werte (z.B. {{termin}}) gewinnen
                    // über die Default-Auflösung (Name/Beispielwert).
                    $paramKey = strtolower($param['name']);
                    $value = $bodyValues[$paramKey] ?? match ($paramKey) {
                        '1', 'name', 'vorname' => $contactName,
                        default => $param['example'] ?: $contactName,
                    };
```

(Rest der Schleife unverändert — `$paramEntry`-Aufbau bleibt wie er ist.)

- [ ] **Step 2: Neue Methode einfügen** (direkt nach `sendWaitlistAvailableNotification()`):

```php
    /**
     * Termin-Warteliste (Dauerabo): "In deinem Termin ist ein Platz frei
     * geworden" — mit {{termin}}-Variable ("Samstag, 25. Juli 2026 um
     * 15:00 Uhr"). Fallback aufs generische Ort-Template, solange das
     * Termin-Template nicht konfiguriert/approved ist oder der Versand
     * damit fehlschlägt — so bleibt das Feature vor dem Meta-Approval
     * funktionsfähig.
     */
    public function sendTerminWaitlistNotification(\Platform\Recruiting\Models\RecInterview $interview): bool
    {
        $terminLabel = \Platform\Recruiting\Services\TerminLabel::format($interview->starts_at);

        $sent = $this->sendBookingLinkWhatsApp(
            'interview_waitlist_termin_wa_template_id',
            'waitlist_termin_slot_available_sent',
            'Termin-Warteliste: Benachrichtigung "Platz im Termin frei" per WhatsApp gesendet.',
            'interview_booking',
            [
                'termin' => $terminLabel,
                // Positional-Fallback, falls das Meta-Template {{2}} statt
                // {{termin}} nutzt ({{1}} ist konventionell der Name).
                '2'      => $terminLabel,
            ]
        );

        return $sent ?: $this->sendWaitlistAvailableNotification();
    }
```

(Import-Stil an die Datei anpassen: nutzt die Datei oben `use`-Statements für Recruiting-Klassen, dann `use Platform\Recruiting\Services\TerminLabel;` ergänzen und im Code kurz referenzieren — der Implementer folgt dem vorhandenen Stil. `RecInterview` ist im selben Namespace, kein FQCN nötig, falls die Datei ihn schon kurz referenziert.)

- [ ] **Step 3: Gate**

```bash
php -l src/Models/RecApplicant.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Erwartung: Suite 168 grün. (Kein Unit-Test möglich — Versand-Kern hängt an Models/Settings; Harness Task 8 prüft die $bodyValues-Auflösung logisch, das Template-Rendern selbst ist NICHT lokal verifizierbar.)

- [ ] **Step 4: Commit**

```bash
git add src/Models/RecApplicant.php
git commit -m "feat(recruiting): Termin-Template-Versand mit {{termin}}-Variable + bodyValues-Override"
```

---

### Task 4: ⚠️ Notify-Job — Termin-Zweig auf Dauerabo (DIE heikle Änderung)

**Files:**
- Modify: `src/Jobs/NotifyWaitlistForInterview.php`

> **EXPLIZITE KENNZEICHNUNG:** Dieser Task hebt die in V1 verbatim-verifizierte Invariante "max. 1 Benachrichtigung pro Scharfschaltung" für den TERMIN-Zweig bewusst auf (Produktentscheidung 1). Der Ort-Zweig behält sie vollständig. Der Reviewer dieses Tasks MUSS bestätigen: (a) `notifyEntries()` ist byte-identisch zu V1 (Verbatim-Vergleich Hunk für Hunk), (b) der Ort-Zweig-Aufruf unterscheidet sich vom V1-Stand AUSSCHLIESSLICH durch den Skip-Filter in der Query, (c) der neue Termin-Claim ist atomar (Einzel-UPDATE mit Bedingungen, Claim = 1 affected row).

**Interfaces:**
- Consumes: Spalte/Cast `armed` (Task 1), `sendTerminWaitlistNotification()` (Task 3), Scopes aus V1.
- Produces: Neue Termin-Invariante: max. 1 Nachricht pro Voll→Frei-Fenster (über `armed`-Claim) UND `RENOTIFY_COOLDOWN_MINUTES = 60` Mindestabstand pro Person+Termin (Notbremse, Produktentscheidung 3). Skip-Logik: Ort-Zweig überspringt Personen mit OFFENEM Termin-Abo für genau diesen Termin (Produktentscheidung 7).

- [ ] **Step 1: Konstante ergänzen** (unter `MIN_LEAD_HOURS`):

```php
    /**
     * Notbremse gegen Sekunden-Flattern (voll↔frei im Minutentakt):
     * Mindestabstand zwischen zwei Nachrichten an dieselbe Person für
     * denselben Termin. NICHT der Haupt-Mechanismus (das ist der
     * armed-Claim = ein Ereignis pro Voll→Frei-Fenster) — nur ein Deckel
     * für den pathologischen Fall. Greift die Bremse, bleibt der Eintrag
     * scharf; zugestellt wird beim nächsten Trigger nach Ablauf.
     */
    public const RENOTIFY_COOLDOWN_MINUTES = 60;
```

- [ ] **Step 2: Termin-Zweig in `handle()` umstellen**

Den V1-Block:

```php
        // 1) Termin-Wartende: warten auf genau diesen Termin.
        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->forInterview($interview->id)
                ->with('applicant')
                ->get()
        );
```

ersetzen durch:

```php
        // 1) Termin-Abos (Dauerabo): scharfe Einträge dieses Termins.
        //    armed wird beim Voll-Werden gesetzt (WaitlistRearmService)
        //    und hier atomar verbraucht — ein Ereignis pro
        //    Voll→Frei-Fenster, Storno-Wellen im selben Fenster finden
        //    armed=0 vor.
        $this->notifyTerminEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->where('armed', true)
                ->forInterview($interview->id)
                ->with('applicant')
                ->get(),
            $interview
        );
```

- [ ] **Step 3: Skip-Filter am Ort-Zweig**

Zwischen Termin-Zweig und Ort-Zweig einfügen:

```php
        // Skip-Logik: Wer ein OFFENES Termin-Abo für genau diesen Termin
        // hat, wird vom Ort-Zweig für diesen Termin übersprungen — das
        // speziellere Abo gewinnt, keine Doppel-WhatsApp.
        $terminAboApplicantIds = RecInterviewWaitlist::query()
            ->forInterview($interview->id)
            ->open()
            ->pluck('rec_applicant_id');
```

und in der Ort-Query (NUR diese eine Zeile ergänzen, alles andere byte-identisch zu V1):

```php
        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->ortBased()
                ->whereJsonContains('wunschorte', $ort)
                ->when($terminAboApplicantIds->isNotEmpty(), fn ($query) => $query->whereNotIn('rec_applicant_id', $terminAboApplicantIds))
                ->with('applicant')
                ->get()
        );
```

- [ ] **Step 4: Neuen Termin-Claim-Loop einfügen** (NACH `notifyEntries()`, das UNANGETASTET bleibt):

```php
    /**
     * Dauerabo-Zustellung (nur Termin-Einträge). Atomarer Claim auf
     * armed=1: nur wer das Flag umlegt (1 affected row), verschickt —
     * parallel laufende Jobs desselben Frei-Fensters gehen leer aus.
     * Die Cooldown-Bedingung steckt IM Claim-UPDATE: greift die
     * Notbremse, bleibt armed=1 stehen (Zustellung beim nächsten
     * Trigger nach Ablauf), nur notified_at/armed werden NICHT angefasst.
     */
    private function notifyTerminEntries(Collection $entries, RecInterview $interview): void
    {
        $entries->each(function (RecInterviewWaitlist $entry) use ($interview) {
            $previousNotifiedAt = $entry->notified_at;

            $claimed = RecInterviewWaitlist::where('id', $entry->id)
                ->where('armed', true)
                ->where(function ($query) {
                    $query->whereNull('notified_at')
                        ->orWhere('notified_at', '<=', now()->subMinutes(self::RENOTIFY_COOLDOWN_MINUTES));
                })
                ->update(['armed' => false, 'notified_at' => now()]);

            if ($claimed !== 1) {
                return; // anderer Job war schneller ODER Notbremse aktiv
            }

            // Versand: termin-spezifisches Template ({{termin}}), mit
            // Fallback aufs generische Template (siehe RecApplicant).
            $applicant = $entry->applicant;
            $sent = $applicant && $applicant->is_active
                && $applicant->sendTerminWaitlistNotification($interview);

            if (!$sent) {
                // Claim zurückgeben: wieder scharf UND den alten
                // notified_at-Stand wiederherstellen — sonst würde der
                // fehlgeschlagene Versand die Notbremse für eine Stunde
                // scharf schalten, obwohl nichts ankam. fulfilled_at-Guard
                // wie im Ort-Loop: zwischenzeitliche Buchung nicht anfassen.
                RecInterviewWaitlist::where('id', $entry->id)
                    ->whereNull('fulfilled_at')
                    ->update(['armed' => true, 'notified_at' => $previousNotifiedAt]);
            }
        });
    }
```

- [ ] **Step 5: Gate + Selbst-Verbatim-Check**

```bash
php -l src/Jobs/NotifyWaitlistForInterview.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git diff -U0 origin/main -- src/Jobs/NotifyWaitlistForInterview.php | grep "^[+-]" | grep -v "^[+-][+-]"
```

Erwartung: Suite 168 grün. Der Diff darf innerhalb der `notifyEntries()`-Methode NULL Zeilen zeigen (nur Konstante, Termin-Block, Skip-Block, eine `->when(...)`-Zeile in der Ort-Query, neue Methode).

- [ ] **Step 6: Commit**

```bash
git add src/Jobs/NotifyWaitlistForInterview.php
git commit -m "feat(recruiting): Termin-Dauerabo — armed-Claim + 1h-Notbremse + Ort-Skip-Logik"
```

---

### Task 5: Re-Arm-Trigger — `WaitlistRearmService` + Observer-Hooks

**Files:**
- Create: `src/Services/WaitlistRearmService.php`
- Modify: `src/Observers/RecInterviewWaitlistObserver.php`

**Interfaces:**
- Consumes: Spalte `armed` (Task 1).
- Produces: `WaitlistRearmService::rearmIfNowFull(int $interviewId): void` — armiert alle offenen Termin-Einträge eines Termins, WENN er (wieder) voll ist. Aufgerufen aus: (a) Booking-Hook bei aktivierenden Änderungen (Neuanlage aktiv / Status-Wechsel weg von cancelled), (b) Interview-Hook bei `max_participants`-Änderung (Kapazitäts-SENKUNG kann voll machen; bei Erhöhung no-op't der Voll-Check). Query-Builder-Update → keine Events, keine Kaskade.

- [ ] **Step 1: Service schreiben**

Datei `src/Services/WaitlistRearmService.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Automatisches Re-Arm der Termin-Dauerabos: Wird ein Termin (wieder)
 * VOLL, werden alle offenen Termin-Einträge scharf gestellt (armed=1) —
 * der nächste Voll→Frei-Übergang ist damit ein neues Ereignis und
 * benachrichtigt erneut (Produktentscheidung: Dauerabo, Variante B).
 *
 * Bewusst NICHT im Notify-Job: der kennt nur den Ist-Zustand "frei".
 * Das Voll-Werden ist die diskrete Ereignis-Grenze und wird an der
 * Quelle beobachtet (Buchungs-Aktivierung, Kapazitäts-Senkung).
 *
 * Über-Aufruf ist harmlos: der Voll-Check no-op't, und armed=1 auf
 * bereits scharfen Einträgen ändert nichts (idempotent).
 */
class WaitlistRearmService
{
    public static function rearmIfNowFull(int $interviewId): void
    {
        $interview = RecInterview::find($interviewId);
        if (!$interview || !$interview->max_participants) {
            return;
        }

        $booked = RecInterviewBooking::where('rec_interview_id', $interviewId)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        if ($booked < $interview->max_participants) {
            return;
        }

        // Query-Builder: keine Model-Events, kein Observer-Ping-Pong.
        RecInterviewWaitlist::query()
            ->forInterview($interviewId)
            ->open()
            ->where('armed', false)
            ->update(['armed' => true]);
    }
}
```

- [ ] **Step 2: Booking-Hook erweitern**

In `src/Observers/RecInterviewWaitlistObserver.php`, Import ergänzen:

```php
use Platform\Recruiting\Services\WaitlistRearmService;
```

Den bestehenden `RecInterviewBooking::saved`-Hook ersetzen durch:

```php
        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                if (!$booking->rec_interview_id) {
                    return;
                }

                // Storno gibt ggf. einen Platz frei → Warteliste anstoßen.
                // Der Job re-validiert Kapazität/Status/Cutoff selbst;
                // Über-Dispatch ist dank armed-/notified_at-Claim safe.
                if ($booking->wasChanged('status') && $booking->status === 'cancelled') {
                    NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
                    return;
                }

                // Aktivierende Änderung (Neuanlage aktiv oder Status weg
                // von cancelled) kann den Termin VOLL machen → Dauerabos
                // wieder scharf stellen. rearmIfNowFull no-op't, wenn
                // noch Platz ist.
                $activated = $booking->status !== 'cancelled'
                    && ($booking->wasRecentlyCreated || $booking->wasChanged('status'));

                if ($activated) {
                    WaitlistRearmService::rearmIfNowFull($booking->rec_interview_id);
                }
            }, 'rec_interview_booking.saved.waitlist', $booking->id);
        });
```

(Semantik-Erhalt: Der Storno-Dispatch-Pfad ist inhaltlich identisch zu V1 — gleiche Guards, nur Reihenfolge der Null-Prüfung vorgezogen und `return` nach Dispatch.)

- [ ] **Step 3: Interview-Hook ergänzen**

Im bestehenden `RecInterview::saved`-Hook, NACH dem `NotifyWaitlistForInterview::dispatch($interview->id);` (der Rest des Hooks bleibt unverändert), die Kapazitäts-Senkung abdecken — dazu den Hook-Body am Ende erweitern:

```php
                NotifyWaitlistForInterview::dispatch($interview->id);

                // Kapazitäts-SENKUNG kann den Termin voll machen →
                // Dauerabos scharf stellen (Erhöhung: Voll-Check no-op't).
                if ($interview->wasChanged('max_participants')) {
                    WaitlistRearmService::rearmIfNowFull($interview->id);
                }
```

Achtung Einfüge-Logik: Der Dispatch steht im Guard-Pfad "verfügbar + relevante Änderung". Eine Kapazitäts-Senkung auf VOLL erfüllt "verfügbar" ggf. trotzdem (aktiv/geplant/Zukunft) — der Voll-Check im Service entscheidet. Fall "Senkung macht voll, aber Termin z.B. inaktiv": dann läuft der Hook gar nicht bis hierher — akzeptiert, der Cleanup schließt Einträge toter Termine ohnehin (Produktentscheidung 4a, V1-Cleanup bleibt unverändert passend: er schließt bei inaktiv/abgesagt/vergangen — Dauerabo-Enden (a) ist damit abgedeckt, KEINE Änderung nötig).

- [ ] **Step 4: Gate**

```bash
php -l src/Services/WaitlistRearmService.php
php -l src/Observers/RecInterviewWaitlistObserver.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 5: Commit**

```bash
git add src/Services/WaitlistRearmService.php src/Observers/RecInterviewWaitlistObserver.php
git commit -m "feat(recruiting): Auto-Re-Arm beim Voll-Werden — WaitlistRearmService + Observer-Hooks"
```

---

### Task 6: Komponente — Abmelden-Action + Create mit armed

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php`

**Interfaces:**
- Consumes: `planForInterview()` V2-Semantik (Task 2), Spalte `armed` (Task 1).
- Produces: `leaveInterviewWaitlist(int $interviewId): void` (Task 7/Blade konsumiert); `joinInterviewWaitlist()` ohne rearm-Zweig, Create-Payload explizit mit `'armed' => true`.

- [ ] **Step 1: `joinInterviewWaitlist()` — rearm-Zweig entfernen, armed setzen**

Den Action-Ausführungsblock am Ende der Methode ersetzen. Bisher (V1):

```php
        if ($plan['action'] === 'create') {
            // Wunschorte-Snapshot nur als HR-Info — das Matching läuft
            // über rec_interview_id, deshalb ist auch [] okay.
            RecInterviewWaitlist::create([
                'rec_applicant_id' => $applicant->id,
                'rec_interview_id' => $interviewId,
                'team_id'          => $applicant->team_id,
                'wunschorte'       => WaitlistEnrollmentPlanner::resolveWunschorte(
                    $applicant->getExtraField('beschaftigungsort'),
                    $applicant->postings->first()?->position?->beschaftigungsort_lookup_value,
                ),
                'enrolled_at'      => now(),
            ]);
        } elseif ($plan['action'] === 'rearm') {
            $entry->update(['notified_at' => null]);
        }
```

Neu:

```php
        if ($plan['action'] === 'create') {
            // Wunschorte-Snapshot nur als HR-Info — das Matching läuft
            // über rec_interview_id, deshalb ist auch [] okay.
            // armed=true: Abo entsteht nur an vollen Terminen (Guard oben),
            // der nächste freie Platz soll benachrichtigen.
            RecInterviewWaitlist::create([
                'rec_applicant_id' => $applicant->id,
                'rec_interview_id' => $interviewId,
                'team_id'          => $applicant->team_id,
                'armed'            => true,
                'wunschorte'       => WaitlistEnrollmentPlanner::resolveWunschorte(
                    $applicant->getExtraField('beschaftigungsort'),
                    $applicant->postings->first()?->position?->beschaftigungsort_lookup_value,
                ),
                'enrolled_at'      => now(),
            ]);
        }
```

(Der `$entry`-Lookup und `planForInterview`-Aufruf davor bleiben unverändert — `noop` fällt durch.)

- [ ] **Step 2: `leaveInterviewWaitlist()` einfügen** (direkt nach `joinInterviewWaitlist()`):

```php
    /**
     * Bewerber meldet sich aktiv von der Termin-Warteliste ab
     * (Dauerabo-Ende (c)). Schließt NUR den eigenen offenen Eintrag
     * für genau diesen Termin — Ort-Abo und andere Termin-Abos bleiben.
     */
    public function leaveInterviewWaitlist(int $interviewId): void
    {
        if (!$this->applicantId) {
            return;
        }

        RecInterviewWaitlist::where('rec_applicant_id', $this->applicantId)
            ->forInterview($interviewId)
            ->open()
            ->update(['cancelled_at' => now()]);

        unset($this->interviewWaitlistEntries);
    }
```

- [ ] **Step 3: Gate**

```bash
php -l src/Livewire/Public/InterviewBooking.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Public/InterviewBooking.php
git commit -m "feat(recruiting): Termin-Abo — Abmelden-Action, Create mit armed, kein Klick-Rearm mehr"
```

---

### Task 7: Blade — Dauerabo-Zustände + Abmelden + persistenter Ort-Button

**Files:**
- Modify: `resources/views/livewire/public/interview-booking.blade.php`

**Interfaces:**
- Consumes: `leaveInterviewWaitlist` (Task 6), bestehende `joinWaitlist`/`joinInterviewWaitlist`/`waitlistEntry`/`interviewWaitlistEntries`.
- Produces: reine View-Änderung. Termin-Karte: ZWEI Warteliste-Zustände statt drei (offenes Abo = "Wir melden uns" + Abmelden-Link; kein Abo = Glocken-Button — der V1-"benachrichtigt→Button wieder zeigen"-Zustand entfällt, Dauerabo braucht kein Nachklicken). Unter der Terminliste: persistenter Ort-Button (Produktentscheidung 8), immer sichtbar bei `waitlist_enabled`. Die Empty-Box (kein Termin existiert) bleibt UNVERÄNDERT.

- [ ] **Step 1: Button-Bereich der Termin-Karte umstellen**

Im `@php`-Block des Button-Containers die Zustandsberechnung ersetzen. Bisher (V1):

```blade
                                    @php
                                        $isFull = $interview->max_participants
                                            && $interview->bookings_count >= $interview->max_participants;
                                        $terminEntry = $isFull ? ($this->interviewWaitlistEntries[$interview->id] ?? null) : null;
                                        $terminWaiting = $terminEntry && !$terminEntry->notified_at;
                                    @endphp
```

Neu (offenes Abo zählt unabhängig von notified_at — Dauerabo):

```blade
                                    @php
                                        $isFull = $interview->max_participants
                                            && $interview->bookings_count >= $interview->max_participants;
                                        $terminEntry = $isFull ? ($this->interviewWaitlistEntries[$interview->id] ?? null) : null;
                                        $terminSubscribed = (bool) $terminEntry;
                                    @endphp
```

Und die Zweig-Kette: `@elseif($this->waitlistEnabled && $terminWaiting)` wird zu `@elseif($this->waitlistEnabled && $terminSubscribed)`, und der Badge-Zweig bekommt den Abmelden-Link. Kompletter neuer Badge-Zweig:

```blade
                                    @elseif($this->waitlistEnabled && $terminSubscribed)
                                        <div class="flex flex-col items-end gap-1.5">
                                            <span class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full bg-blue-50 text-blue-700 text-xs font-semibold whitespace-nowrap">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                Wir melden uns bei einem freien Platz
                                            </span>
                                            <button
                                                type="button"
                                                x-data
                                                @click="if (confirm('Möchtest du für diesen Termin wirklich keine Benachrichtigungen mehr bekommen?')) $wire.leaveInterviewWaitlist({{ $interview->id }})"
                                                wire:loading.attr="disabled"
                                                class="text-xs text-gray-400 hover:text-gray-600 underline underline-offset-2 transition-colors"
                                            >
                                                Nicht mehr benachrichtigen
                                            </button>
                                        </div>
```

Der nachfolgende `@elseif($this->waitlistEnabled)`-Zweig (Glocken-Button "Platz frei? Benachrichtige mich") und der `@else`-Zweig ("Ausgebucht"-Badge) bleiben UNVERÄNDERT — durch die neue `$terminSubscribed`-Definition ist der Button jetzt schlicht nie mehr für Abonnenten sichtbar.

- [ ] **Step 2: Persistenter Ort-Button unter der Terminliste**

Im `@if(count($this->visibleInterviews) > 0)`-Zweig, direkt nach dem schließenden `</div>` der Terminliste (`<div class="space-y-8">…</div>`) und VOR dem `@else` der Empty-Box, einfügen:

```blade
                @if($this->waitlistEnabled)
                    @php
                        $ortEntry = $this->waitlistEntry;
                        $ortWaiting = $ortEntry && !$ortEntry->notified_at;
                    @endphp
                    <div class="mt-6 text-center">
                        @if($ortWaiting)
                            <p class="text-sm text-white/70">
                                Du stehst auf der Warteliste — wir melden uns, sobald es neue Termine für deinen Standort gibt.
                            </p>
                        @else
                            <button
                                type="button"
                                wire:click="joinWaitlist"
                                wire:loading.attr="disabled"
                                class="text-sm font-medium text-white/60 hover:text-white/80 underline underline-offset-2 transition-colors"
                            >
                                <span wire:loading.remove wire:target="joinWaitlist">Keiner der Termine passt? Benachrichtige mich, sobald es neue Termine gibt.</span>
                                <span wire:loading wire:target="joinWaitlist">Wird eingetragen…</span>
                            </button>
                        @endif
                    </div>
                @endif
```

(Styling analog zum bestehenden "Schulung absagen"-Link auf dunklem Grund. Ort-Semantik bleibt V1: einmal feuern, danach zeigt `$ortWaiting=false` den Button wieder → manuelles Re-Arm über die bestehende `joinWaitlist()`-Logik. Die Empty-Box weiter unten bleibt unangetastet für den Fall "gar keine Termine".)

- [ ] **Step 3: Gate**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Plus Sichtprüfung des Diffs: `@php` Block-Form, `@if`-Ketten balanciert, Empty-Box-Block ohne Hunks.

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/public/interview-booking.blade.php
git commit -m "feat(recruiting): Termin-Karte Dauerabo-Zustände + Abmelden + persistenter Ort-Button"
```

---

### Task 8: HR-Sichtbarkeit — Dauerabo-Status im Tool

**Files:**
- Modify: `src/Tools/ListWaitlistTool.php`

**Interfaces:**
- Consumes: `armed`-Cast (Task 1).
- Produces: additives Feld `armed` + korrigierte Status-Ableitung für Termin-Einträge (ein offenes Termin-Abo ist nie "final benachrichtigt", sondern "abonniert"). Ort-Einträge behalten die V1-Ableitung.

- [ ] **Step 1: Status-Ableitung erweitern**

Im `map()`-Array das `'status'`-Feld ersetzen. Bisher:

```php
                    'status' => $entry->fulfilled_at ? 'gebucht'
                        : ($entry->cancelled_at ? 'storniert'
                        : ($entry->notified_at ? 'benachrichtigt' : 'wartet')),
```

Neu:

```php
                    'armed' => (bool) $entry->armed,
                    'status' => $entry->fulfilled_at ? 'gebucht'
                        : ($entry->cancelled_at ? 'storniert'
                        : ($entry->rec_interview_id
                            ? 'abonniert' . ($entry->notified_at ? ' (zuletzt benachrichtigt ' . $entry->notified_at->format('d.m.Y H:i') . ')' : '')
                            : ($entry->notified_at ? 'benachrichtigt' : 'wartet'))),
```

- [ ] **Step 2: Beschreibung ergänzen** — an den `getDescription()`-String anhängen: ` Termin-Einträge sind Dauerabos (Status "abonniert", armed=true heißt: feuert beim nächsten freien Platz); Ort-Einträge feuern einmalig (Status wartet/benachrichtigt).`

- [ ] **Step 3: Gate + Commit**

```bash
php -l src/Tools/ListWaitlistTool.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
git add src/Tools/ListWaitlistTool.php
git commit -m "feat(recruiting): HR-Tool zeigt Dauerabo-Status + armed-Flag"
```

---

### Task 9: Harness + Trigger-Sicherheit + Push (dann STOPP)

**Files:** keine neuen im Repo (Harness im Scratchpad)

- [ ] **Step 1: Verifikations-Harness** (sqlite-:memory:-Smoke mit echten Klassen, wie V1; Blade-Compile beider Views; volle Suite; git sanity). PFLICHT-Fälle für die neue Kern-Mechanik:

1. **Feuert bei neuem Platz wieder:** Abo armed=1 → Frei-Ereignis (Claim-Replika) → Zustellung, armed=0, notified_at=now → Voll-Ereignis (rearmIfNowFull-Replika mit voller Buchungslage) → armed=1 → nächstes Frei-Ereignis (notified_at > 60min alt simulieren) → ZWEITE Zustellung. (Dauerabo-Kernfall)
2. **Feuert nicht doppelt im selben Frei-Fenster:** nach Zustellung (armed=0) zweites Frei-Ereignis OHNE zwischenzeitliches Voll-Werden → Claim schlägt fehl (0 rows) → keine zweite Zustellung. Auch: Storno-Welle = 3 Claims direkt nacheinander → genau 1 gewinnt.
3. **"Frei→noch freier" armiert nie:** rearmIfNowFull mit Buchungslage unter max_participants → armed bleibt 0.
4. **1h-Notbremse:** armed=1, notified_at = vor 20 Minuten → Claim schlägt fehl UND armed bleibt 1 (Eintrag nicht verbrannt); notified_at = vor 61 Minuten → Claim gewinnt.
5. **Rollback bei Versand-Fehler:** Claim gewinnt (armed 1→0, notified_at überschrieben) → Fehler-Pfad-Replika → armed wieder 1 UND notified_at auf den ALTEN Wert zurückgesetzt (nicht now — sonst blockiert die Bremse eine Stunde ohne zugestellte Nachricht); fulfilled_at-Guard: zwischenzeitlich gebuchter Eintrag bleibt unangetastet.
6. **Skip-Logik:** Person mit offenem Termin-Abo für Termin X wird vom Ort-Zweig für X übersprungen, für Termin Y (gleicher Ort, kein Abo) aber weiterhin benachrichtigt; Person ohne Termin-Abo unverändert.
7. **Ort-Zweig-Verhalten unverändert:** Ort-Eintrag mit notified_at gesetzt wird NICHT erneut benachrichtigt (V1-Semantik trotz armed=true-Default auf der neuen Spalte — belegt, dass armed am Ort-Zweig wirkungslos ist).
8. **Backfill-Replika:** V1-Bestandslage nachstellen → nur Termin-Einträge mit notified_at bekommen armed=0; Ort-Einträge und wartende Termin-Einträge behalten armed=1; keine sonstige Spalte ändert sich.
9. **Abmelden:** leaveInterviewWaitlist-Replika schließt NUR den eigenen offenen Eintrag des einen Termins (cancelled_at), Ort-Abo + fremde Einträge unberührt.
10. **planForInterview/TerminLabel:** bereits unit-getestet (Task 2) — im Harness nur der Format-String einmal end-to-end gegen ein echtes RecInterview-Model-Feld.
11. **Cleanup unverändert passend (Produktentscheidung 4a):** Cleanup-Query-Replika schließt Dauerabo-Einträge toter Termine genau wie V1 (armed spielt keine Rolle) — bestätigt, dass KEINE Cleanup-Änderung nötig ist.

- [ ] **Step 2: Trigger-Sicherheits-Verifikation VOR dem Push** (STOPP bei Nicht-Bestätigung):

1. Migration = DDL + Backfill NUR auf der neuen Spalte via Query-Builder (keine Events, kein Dispatch, kein Touch an Ort-Einträgen außer dem bedeutungslosen Spalten-Default). Deploy triggert NIEMANDEN.
2. `grep -rn "NotifyWaitlistForInterview::dispatch" src/` → weiterhin AUSSCHLIESSLICH die zwei reaktiven Observer-Hooks. `rearmIfNowFull` dispatcht nicht (nur armed-Update).
3. Verhaltensänderung an Bestandswartenden: Ort-Einträge exakt NULL Änderung; V1-Termin-Einträge werden zu Dauerabos (gewollt, Produktentscheidung 1) — bereits benachrichtigte starten entwaffnet und feuern erst nach dem nächsten Voll-Werden ihres Termins.

- [ ] **Step 3: Push**

```bash
git push -u origin feature/termin-warteliste-v2
```

DANACH STOPP — finales Whole-Branch-Review (stärkstes Modell, mit explizitem Verbatim-Mandat für `notifyEntries()` und den Ort-Zweig) gehört noch VOR den Push-Report an den User; Merge/Bump/Deploy sind ein separater Durchlauf nach Freigabe.

- [ ] **Step 4: Nach User-Freigabe (separater Durchlauf):** ff-Merge auf main → meingedeck-Bump (`chore(deps): bump platform-recruiting → Termin-Warteliste V2`) → Deploy mit Migration → **`queue:restart` PFLICHT** (Job geändert).

- [ ] **Step 5 (nach Deploy, in diesem Durchlauf NICHT abhakbar): Live-Smoke Dauerabo** — voller Termin mit Abonnent: Buchung stornieren → WhatsApp 1 kommt; wieder einbuchen (voll) → stornieren nach >1h → WhatsApp 2 kommt OHNE Nachklicken; zweiter Storno im selben Frei-Fenster → KEINE dritte Nachricht. Plus: Template-Render von `{{termin}}` auf dem echten Meta-Template prüfen (Approval läuft separat; bis dahin greift der dokumentierte Fallback aufs generische Template).

---

## Nicht lokal / im Harness verifizierbar (ehrliche Grenzen)

- **Re-Arm-Verhalten live** über echte Buchungs-Events (Observer feuern im Harness nicht — nur die Guard-/Service-Logik ist repliziert).
- **WhatsApp-Versand** inkl. Fallback-Kette Termin-Template → generisches Template.
- **Template-Render** von `{{name}}`/`{{termin}}` im echten Meta-Template (Approval läuft separat beim User).
- Livewire-Hydration der neuen Abmelden-Action, visueller Render, Scheduler-Läufe.

## Bewusste Entscheidungen (Review-relevant)

- **Invarianten-Wechsel nur im Termin-Zweig (Task 4):** V1-"1x pro Scharfschaltung" → V2-"1x pro Voll→Frei-Fenster + 60min-Cooldown". Ort-Zweig-Loop byte-identisch; Reviewer-Gate wie V1.
- **Übergangs-Erkennung auf der Arm-Seite** ("wurde voll" armiert) statt Historien-Rekonstruktion im Job — begründet im Architecture-Block gegen den Ist-Code.
- **Notbremse konsumiert den Arm NICHT:** blockt sie, bleibt der Eintrag scharf; Zustellung beim nächsten Trigger nach Ablauf. Kein eigener Nachhol-Mechanismus (kein delayed Re-Dispatch) — bewusster Verzicht, dokumentierter Rest-Fall: bleibt der Termin nach dem Bremsen-Block dauerhaft frei UND kommt kein weiterer Trigger, verpasst die Person dieses Fenster.
- **Rollback stellt notified_at wieder her** (nicht now) — sonst schaltet ein fehlgeschlagener Versand die Bremse scharf, ohne dass etwas ankam.
- **`planForInterview` verliert die rearm-Action** — Klick-Re-Arm würde notified_at nullen und die Bremse aushebeln; Server-seitig noop, UI zeigt Abonnenten den Button gar nicht mehr.
- **Skip-Logik über OFFENES Abo** (nicht armed): auch ein gerade entwaffnetes Termin-Abo unterdrückt die Ort-Nachricht für diesen Termin — die Person IST für diesen Termin versorgt (Produktentscheidung 7, "keine Zustandsunterscheidung").
- **Fallback aufs generische Template** bei fehlendem/fehlschlagendem Termin-Template — Feature funktioniert vor dem Meta-Approval; schlägt BEIDES fehl, greift der Claim-Rollback.
- **Cleanup bleibt unverändert** (Harness-Fall 11 belegt Passung) — Dauerabo-Ende (a) war schon V1-Verhalten.
- **`UpdateWaitlistTool` bleibt unverändert:** `reset_notification` nullt weiterhin notified_at (hebt für Termin-Abos einmalig die Bremse auf — akzeptabler HR-Notbehelf, kein neues Verhalten nötig).
- **Ort-Einträge tragen ungenutztes armed=true** (Spalten-Default) — bewusst nicht auf Typen verzweigt; der Ort-Zweig liest das Flag nie (Harness-Fall 7 belegt das).
