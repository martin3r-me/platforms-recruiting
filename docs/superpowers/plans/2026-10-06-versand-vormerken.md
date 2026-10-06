# Vertragsversand vormerken — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wer beim Vertragsversand noch im Onboarding steckt, wird vorgemerkt statt ohne Portal versendet; nach Vervollständigung gehen Verträge und Portal-Link automatisch raus, jeder Schritt ist in Nachbereitung, HR-Schreibtisch und Verlauf sichtbar.

**Architecture:** Eine Regel am Modell (`RecApplicant::versandBereitschaft()`) entscheidet bereit / unvollständig / gesperrt; der Versanddienst sperrt hart. Eine neue Tabelle hält Vormerkungen mit den beim Klick eingetragenen Vertragsdaten. Ein Beobachter stößt bei Phasenwechsel in die Anlage-Phase (und bei HR-Freigabe) einen Job an, der alle Prüfungen frisch durchläuft und über den bestehenden `ContractDispatchService` versendet. Erinnerung = Onboarding-Vorlage der Phase über einen aus `sendBookingLinkWhatsApp` herausgelösten Template-Versand.

**Tech Stack:** Laravel 11 / Livewire 3 / Eloquent, PHPUnit 11 (Integration-Suite mit handgebautem Container + Capsule/SQLite, echte Migrationen per glob), Queue-Job (`ShouldQueue`), WhatsApp über `WhatsAppMetaService`.

**Spec:** `docs/superpowers/specs/2026-10-06-versand-vormerken-design.md`

## Global Constraints

- Tests laufen NUR so: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (kein eigenes vendor/). Gesamtsuite in Default-Reihenfolge, nie `--order-by=random`.
- Blade-Prüfung: `php tools/blade-check.php <datei>` (kein `php -l` auf .blade.php). Keine inline `@if`/`??` in `x-ui-*`-Attributen, keine an Wortzeichen geklebten Direktiven, `@php` nur als Block.
- `rec_auto_pilot_logs.type` ist `varchar(30)`: alle neuen Typen ≤ 30 Zeichen.
- Verlaufseinträge immer per `RecAutoPilotLog::create([...])` in try/catch — ein Log-Fehler darf nie die Aktion kippen.
- Höchstens EINE offene Vormerkung je Bewerber (offen = `completed_at` und `cancelled_at` leer).
- Erinnerung höchstens einmal pro 24 h (`last_reminder_at`).
- Vergangener Vertragsbeginn stoppt den automatischen Versand (Vormerkung bleibt offen, Grund sichtbar).
- Keine neue Meta-Vorlage: Erinnerung = `auto_pilot_wa_initial_template_id` der aktuellen Phase (Kaskade Phase → Stelle → Team).
- Commit-Nachrichten auf Deutsch, Imperativ wie im Repo, Abschluss: `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Deploy braucht: Migration, `queue:restart`, `view:clear`.

## Review Focus

1. **Bewerber ohne Telefonnummer / ohne genehmigte Vorlage:** Vormerken muss trotzdem gelingen, nur die Erinnerung scheitert (Log `contract_send_reminder` mit Fehlertext, `last_reminder_at` bleibt leer). → Test in Task 4.
2. **Zweimal „Versenden" innerhalb einer Stunde:** keine zweite Erinnerung, Vormerkung wird aktualisiert, kein Duplikat. → Test in Task 4.
3. **Job läuft zweimal gleichzeitig (Retry + Beobachter):** nur ein Versand; Zeilensperre + `hasAnyContractSent`. → Test in Task 6 (zweiter Lauf → `bereits_versendet`).
4. **Bewerber wird nach Vormerkung von Hand auf andere Stelle umgesetzt:** Job prüft `versandBereitschaft()` frisch; Phase fremder Stelle → `gesperrt`, Vormerkung bleibt offen mit Grund. → Test in Task 6.
5. **Vormerkung ohne Vertragsbeginn (HR-Schreibtisch ohne Datum):** darf nicht entstehen — Klick-Ablauf verlangt Beginn VOR dem Vormerken (bestehende Prüfreihenfolge). → Test in Task 5.

---

## Dateiübersicht

**Neu**
- `src/Support/VersandBereitschaft.php` — Wertobjekt (status, grund, fehlendeFelder).
- `database/migrations/2026_10_07_000001_create_rec_contract_send_reservations_table.php`
- `src/Models/RecContractSendReservation.php`
- `src/Services/ContractSendReservationService.php` — vormerken / erinnern / zurücknehmen / abschließen.
- `src/Services/ReservedContractSender.php` — der Prüf- und Sendelauf (ohne Queue).
- `src/Jobs/SendReservedContractsJob.php` — dünner Queue-Wrapper.
- `src/Services/ReservedSendTrigger.php` (Interface) + `src/Services/QueueReservedSendTrigger.php` (dispatcht den Job nach Commit).
- `src/Observers/RecContractSendReservationObserver.php` — Auslöser (Phasenwechsel, Rechtsstatus) und Aufräumen (Buchung, Bewerber).
- `tests/Integration/VersandVormerkenTest.php` — eine Testklasse für alles mit DB.
- `tests/Unit/VersandBereitschaftTest.php`

**Geändert**
- `src/Models/RecApplicant.php` — `fehlendePflichtfelder()`, `versandBereitschaft()`, `sendPhaseOnboardingReminder()`, Relation `contractSendReservations()`, `offeneVersandVormerkung()`; `sendBookingLinkWhatsApp` delegiert an neues `sendWhatsAppTemplateById`.
- `src/Services/SendContractsService.php` — Riegel auf `versandBereitschaft()`.
- `src/Services/HrDeskRoutingService.php` — `approveCase` stößt den Trigger an.
- `src/Livewire/InterviewBookings/Index.php` + `resources/views/livewire/interview-bookings/index.blade.php` — Vormerken im Sammelversand, Spalte „Versand", Aktionen.
- `src/Livewire/HrDesk/Index.php` + `resources/views/livewire/hr-desk/index.blade.php` — Vormerken vom Schreibtisch, Anzeige.
- `resources/views/livewire/applicant/show.blade.php` — Vormerkung im Kasten „Stelle & Phase".
- `resources/views/livewire/shared/activity-log.blade.php` — Icons für die neuen Log-Typen.
- `src/RecruitingServiceProvider.php` — Observer registrieren, Trigger binden.

---

### Task 1: Versandbereitschaft (Regel am Modell + Riegel im Dienst)

**Files:**
- Create: `src/Support/VersandBereitschaft.php`
- Create: `tests/Unit/VersandBereitschaftTest.php`
- Modify: `src/Models/RecApplicant.php` (neben `mitarbeiterAnlageSperrgrund()`, ca. Zeile 460–520, und neben `calculateProgress()`, ca. Zeile 2140)
- Modify: `src/Services/SendContractsService.php:55-66`
- Test: `tests/Integration/AnzeigeVerknuepfenTest.php` (bestehende Klasse, hat Seed mit Gladbach-Phasen 1/2, Phase 2 legt MA an)

**Interfaces:**
- Produces: `final class VersandBereitschaft { public readonly string $status; /* 'bereit'|'unvollstaendig'|'gesperrt' */ public readonly ?string $grund; /** @var list<string> */ public readonly array $fehlendeFelder; static bereit(); static unvollstaendig(array $felder); static gesperrt(string $grund); istBereit(): bool; kurztext(): string }`
- Produces: `RecApplicant::fehlendePflichtfelder(): array` — Labels der sichtbaren, leeren Pflichtfelder der aktuellen Phase.
- Produces: `RecApplicant::versandBereitschaft($phasenDerStelle = null): VersandBereitschaft`
- Consumes: `RecApplicant::mitarbeiterAnlageSperrgrund($phasenDerStelle = null): ?string` (vorhanden).

- [ ] **Step 1: Unit-Test für das Wertobjekt**

```php
<?php
// tests/Unit/VersandBereitschaftTest.php
namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VersandBereitschaft;

final class VersandBereitschaftTest extends TestCase
{
    public function test_bereit(): void
    {
        $b = VersandBereitschaft::bereit();
        $this->assertTrue($b->istBereit());
        $this->assertSame('bereit', $b->status);
        $this->assertNull($b->grund);
        $this->assertSame('Bereit', $b->kurztext());
    }

    public function test_unvollstaendig_nennt_die_felder(): void
    {
        $b = VersandBereitschaft::unvollstaendig(['Straße', 'Ausweis-Foto Vorderseite']);
        $this->assertFalse($b->istBereit());
        $this->assertSame('unvollstaendig', $b->status);
        $this->assertSame(['Straße', 'Ausweis-Foto Vorderseite'], $b->fehlendeFelder);
        $this->assertSame('Onboarding unvollständig: Straße, Ausweis-Foto Vorderseite', $b->grund);
        $this->assertSame('Daten fehlen: Straße, Ausweis-Foto Vorderseite', $b->kurztext());
    }

    public function test_gesperrt(): void
    {
        $b = VersandBereitschaft::gesperrt('Die Phase gehört zur Stelle „Sonstiges“ …');
        $this->assertFalse($b->istBereit());
        $this->assertSame('gesperrt', $b->status);
        $this->assertStringStartsWith('Gesperrt: Die Phase', $b->kurztext());
    }
}
```

- [ ] **Step 2: Test ausführen, erwartet rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandBereitschaftTest`
Expected: Error „Class … VersandBereitschaft not found".

- [ ] **Step 3: Wertobjekt anlegen**

```php
<?php
// src/Support/VersandBereitschaft.php
namespace Platform\Recruiting\Support;

/**
 * Darf dieser Bewerber JETZT Verträge + Portal-Link bekommen?
 *
 *  - bereit:         Mitarbeiter existiert oder Bewerber steht in der Phase, die
 *                    beim Vertragsversand den Mitarbeiter anlegt.
 *  - unvollstaendig: Weg ist gesichert, aber die aktuelle Phase ist noch nicht die
 *                    Anlage-Phase (typisch: Onboarding offen). → vormerken.
 *  - gesperrt:       kein gesicherter Weg (Phase fremder Stelle, Stelle ohne
 *                    Anlage-Phase, Direkteinstellung, keine Phase). → Riegel.
 *
 * Spec: docs/superpowers/specs/2026-10-06-versand-vormerken-design.md §1
 */
final class VersandBereitschaft
{
    /** @param list<string> $fehlendeFelder */
    private function __construct(
        public readonly string $status,
        public readonly ?string $grund,
        public readonly array $fehlendeFelder,
    ) {}

    public static function bereit(): self
    {
        return new self('bereit', null, []);
    }

    /** @param list<string> $felder */
    public static function unvollstaendig(array $felder): self
    {
        $liste = $felder === [] ? 'Pflichtfelder der aktuellen Phase' : implode(', ', $felder);

        return new self('unvollstaendig', 'Onboarding unvollständig: ' . $liste, array_values($felder));
    }

    public static function gesperrt(string $grund): self
    {
        return new self('gesperrt', $grund, []);
    }

    public function istBereit(): bool
    {
        return $this->status === 'bereit';
    }

    /** Für Spalten und Chips: kurz, ohne Satzpunkt. */
    public function kurztext(): string
    {
        return match ($this->status) {
            'bereit' => 'Bereit',
            'unvollstaendig' => 'Daten fehlen: ' . ($this->fehlendeFelder === [] ? 'Pflichtfelder' : implode(', ', $this->fehlendeFelder)),
            default => 'Gesperrt: ' . $this->grund,
        };
    }
}
```

- [ ] **Step 4: Unit-Test grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandBereitschaftTest`
Expected: OK (3 tests).

- [ ] **Step 5: Integrationstests für `fehlendePflichtfelder()` und `versandBereitschaft()` in `AnzeigeVerknuepfenTest` ergänzen**

Die Klasse hat bereits den Helfer `bewerberIn(int $positionId, int $phaseId): RecApplicant` (setzt Bewerber 4013 auf Stelle+Phase) und den Seed: Gladbach-Phase 1 (`PHASE_GLADBACH_1`, Felder `vorname` + `geburtsdatum`, beide required) und Phase 2 (`PHASE_GLADBACH_2`, `creates_employee_on_completion`). Direkt vor `private static function definitionId(` einfügen:

```php
    // -----------------------------------------------------------------
    // Versandbereitschaft: bereit / unvollstaendig / gesperrt
    // -----------------------------------------------------------------

    public function test_fehlende_pflichtfelder_nennt_leere_sichtbare_pflichtfelder_der_phase(): void
    {
        $applicant = $this->bewerberIn(self::POSITION_GLADBACH, self::PHASE_GLADBACH_1);
        Capsule::table('core_extra_field_values')->where('fieldable_id', $applicant->id)->delete();
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => self::definitionId(self::PHASE_GLADBACH_1, 'vorname'),
            'fieldable_type' => (new RecApplicant())->getMorphClass(), 'fieldable_id' => $applicant->id,
            'value' => 'Soufiane', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        $this->assertSame(['Geburtsdatum'], RecApplicant::find($applicant->id)->fehlendePflichtfelder());
    }

    public function test_versandbereitschaft_unvollstaendig_in_phase_vor_der_anlage_phase(): void
    {
        $applicant = $this->bewerberIn(self::POSITION_GLADBACH, self::PHASE_GLADBACH_1);
        Capsule::table('core_extra_field_values')->where('fieldable_id', $applicant->id)->delete();

        $b = RecApplicant::find($applicant->id)->versandBereitschaft();

        $this->assertSame('unvollstaendig', $b->status);
        $this->assertSame(['Vorname', 'Geburtsdatum'], $b->fehlendeFelder);
    }

    public function test_versandbereitschaft_bereit_in_der_anlage_phase(): void
    {
        $this->assertTrue($this->bewerberIn(self::POSITION_GLADBACH, self::PHASE_GLADBACH_2)->versandBereitschaft()->istBereit());
    }

    public function test_versandbereitschaft_bereit_wenn_mitarbeiter_existiert_egal_in_welcher_phase(): void
    {
        $applicant = $this->bewerberIn(self::POSITION_KOELN, self::PHASE_KOELN_1);
        Capsule::table('rec_employees')->insert([
            'uuid' => 'avk-emp-2', 'team_id' => self::TEAM, 'rec_applicant_id' => $applicant->id,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);
        try {
            $this->assertTrue($applicant->versandBereitschaft()->istBereit());
        } finally {
            Capsule::table('rec_employees')->where('uuid', 'avk-emp-2')->delete();
        }
    }

    public function test_versandbereitschaft_gesperrt_bei_phase_fremder_stelle(): void
    {
        $b = $this->bewerberIn(self::POSITION_GLADBACH, self::PHASE_KOELN_1)->versandBereitschaft();

        $this->assertSame('gesperrt', $b->status);
        $this->assertStringContainsString('gehört zur Stelle', $b->grund);
    }

    public function test_versanddienst_sperrt_bei_unvollstaendig(): void
    {
        $applicant = $this->bewerberIn(self::POSITION_GLADBACH, self::PHASE_GLADBACH_1);
        Capsule::table('core_extra_field_values')->where('fieldable_id', $applicant->id)->delete();
        $applicant = RecApplicant::find($applicant->id);
        $applicant->contract_template_id = 999;
        $applicant->zuschlag = 1.1;

        try {
            (new \Platform\Recruiting\Services\SendContractsService())->send($applicant);
            $this->fail('Versand haette gesperrt sein muessen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Onboarding unvollständig', $e->getMessage());
        }
        $this->assertSame(0, Capsule::table('rec_contracts')->where('rec_applicant_id', $applicant->id)->count());
    }

```

Außerdem im Seed (Methode `seed()`, Block `CoreExtraFieldDefinition::query()->insert([...])`) sicherstellen, dass die beiden Gladbach-1-Definitionen `vorname` und `geburtsdatum` `'is_required' => 1` haben (sind sie bereits).

- [ ] **Step 6: Tests ausführen, erwartet rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter AnzeigeVerknuepfenTest`
Expected: Errors „Call to undefined method … fehlendePflichtfelder()" / `versandBereitschaft()`.

- [ ] **Step 7: Methoden am Modell**

In `src/Models/RecApplicant.php` direkt NACH `mitarbeiterAnlageSperrgrund()` einfügen:

```php
    /**
     * Darf JETZT versendet werden? Dreistufig (Spec Versand vormerken §1):
     * gesperrt (kein Mitarbeiter-Weg) → unvollstaendig (Weg da, aber noch nicht
     * in der Anlage-Phase, typisch Onboarding offen) → bereit.
     *
     * @param \Illuminate\Support\Collection<int, RecPhase>|null $phasenDerStelle siehe mitarbeiterAnlageSperrgrund()
     */
    public function versandBereitschaft($phasenDerStelle = null): \Platform\Recruiting\Support\VersandBereitschaft
    {
        $grund = $this->mitarbeiterAnlageSperrgrund($phasenDerStelle);
        if ($grund !== null) {
            return \Platform\Recruiting\Support\VersandBereitschaft::gesperrt($grund);
        }

        $mitarbeiterDa = $this->relationLoaded('employee')
            ? $this->employee !== null
            : $this->employee()->exists();
        if ($mitarbeiterDa) {
            return \Platform\Recruiting\Support\VersandBereitschaft::bereit();
        }

        $phase = $this->phase;
        $legtAn = (($phase?->completion_config ?? [])['creates_employee_on_completion'] ?? false) === true;
        if ($legtAn) {
            return \Platform\Recruiting\Support\VersandBereitschaft::bereit();
        }

        return \Platform\Recruiting\Support\VersandBereitschaft::unvollstaendig($this->fehlendePflichtfelder());
    }

    /**
     * Labels der sichtbaren Pflichtfelder der aktuellen Phase, die noch leer
     * sind — dieselbe Regel wie calculateProgress(), nur mit Namen statt Prozent.
     *
     * @return list<string>
     */
    public function fehlendePflichtfelder(): array
    {
        $definitions = $this->getExtraFieldDefinitions();
        $currentPhase = $this->phase;
        $required = $definitions->filter(fn ($def) => $this->isFieldRequiredInCurrentPhase($def, $currentPhase));
        if ($required->isEmpty()) {
            return [];
        }

        $values = $this->extraFieldValues()->get()->keyBy('definition_id');
        $valuesByName = [];
        foreach ($definitions as $def) {
            $valuesByName[$def->name] = $values->get($def->id)?->value;
        }

        $evaluator = new \Platform\Core\Services\ExtraFieldConditionEvaluator();
        $fehlend = [];
        foreach ($required as $def) {
            $visibility = $def->visibility_config;
            $sichtbar = !$visibility || !($visibility['enabled'] ?? false) || $evaluator->evaluate($visibility, $valuesByName);
            if (!$sichtbar) {
                continue;
            }
            $val = $values->get($def->id);
            $leer = $val === null || $val->value === null || $val->value === '' || $val->value === '[]';
            if ($leer) {
                $fehlend[] = (string) ($def->label ?: $def->name);
            }
        }

        return $fehlend;
    }
```

In `src/Services/SendContractsService.php` den bestehenden Riegel-Block (beginnt mit dem Kommentar `// Versand-Riegel: ohne gesicherte Mitarbeiter-Anlage …`, endet mit `throw new \RuntimeException("Bewerber #{$applicant->id}: {$grund}"); }`) ersetzen durch:

```php
        // Versand-Riegel (Spec Versand vormerken §1): nur wer bereit ist, bekommt
        // Vertraege. gesperrt = kein Mitarbeiter-Weg, unvollstaendig = Onboarding
        // offen (sonst gingen Vertraege raus, aber kein Mitarbeiter und kein
        // Portal — und ein zweiter Versand wird uebersprungen). Hier und nicht in
        // den Aufrufern: Nachbereitung, HR-Schreibtisch, Job und MCP-Werkzeug
        // laufen alle durch diesen Dienst. Schon Versendete bleiben unberuehrt.
        if (!$applicant->hasAnyContractSent()) {
            $bereitschaft = $applicant->versandBereitschaft();
            if (!$bereitschaft->istBereit()) {
                throw new \RuntimeException("Bewerber #{$applicant->id}: {$bereitschaft->grund}");
            }
        }
```

- [ ] **Step 8: Tests grün, dann Gesamtsuite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'AnzeigeVerknuepfenTest|VersandBereitschaftTest'`
Expected: OK.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: OK (vorhandene Tests `test_versanddienst_laesst_bewerber_mit_mitarbeiter_weg_durch` nutzt `PHASE_GLADBACH_1` → jetzt `unvollstaendig`; diesen Test auf `PHASE_GLADBACH_2` umstellen, Erwartung „keinen Zuschlag" bleibt).

- [ ] **Step 9: Commit**

```bash
git add src/Support/VersandBereitschaft.php tests/Unit/VersandBereitschaftTest.php src/Models/RecApplicant.php src/Services/SendContractsService.php tests/Integration/AnzeigeVerknuepfenTest.php
git commit -m "feat(recruiting): Versandbereitschaft — bereit/unvollstaendig/gesperrt, Riegel sperrt auch offenes Onboarding

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Vormerkung (Migration + Modell)

**Files:**
- Create: `database/migrations/2026_10_07_000001_create_rec_contract_send_reservations_table.php`
- Create: `src/Models/RecContractSendReservation.php`
- Modify: `src/Models/RecApplicant.php` (Relationen, neben `contracts()` ca. Zeile 455)
- Create: `tests/Integration/VersandVormerkenTest.php` (Grundgerüst + erste Tests)

**Interfaces:**
- Produces: Model `RecContractSendReservation` (Tabelle `rec_contract_send_reservations`), Scope `offen()`, Relationen `applicant()`, `booking()`; Konstanten `SOURCE_NACHBEREITUNG = 'nachbereitung'`, `SOURCE_HR_DESK = 'hr_desk'`; Methode `istOffen(): bool`.
- Produces: `RecApplicant::contractSendReservations(): HasMany`, `RecApplicant::offeneVersandVormerkung(): ?RecContractSendReservation` (liest die geladene Relation, wenn vorhanden).

- [ ] **Step 1: Migration**

```php
<?php
// database/migrations/2026_10_07_000001_create_rec_contract_send_reservations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsversand vormerken (Spec 06.10.2026, §2).
 *
 * Wer beim Klick „Versenden" noch im Onboarding steckt, wird hier mit den
 * eingetragenen Vertragsdaten vorgemerkt; nach Vervollstaendigung versendet
 * der Job automatisch. Hoechstens EINE offene Zeile je Bewerber
 * (completed_at + cancelled_at leer) — erzwungen im Service, nicht per Index,
 * weil abgeschlossene Zeilen Historie sind.
 *
 * dateTime statt timestamp: unter explicit_defaults_for_timestamp=OFF
 * bekaeme die erste NOT-NULL-timestamp-Spalte ON UPDATE CURRENT_TIMESTAMP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_contract_send_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('rec_applicant_id')->index();
            $table->unsignedBigInteger('rec_interview_booking_id')->nullable();
            $table->date('vertragsbeginn')->nullable();
            $table->date('vertragsende')->nullable();
            $table->string('source', 32)->default('nachbereitung');
            $table->unsignedBigInteger('reserved_by_user_id')->nullable();
            // Name beim Vormerken festhalten: Verlauf und Spalte sagen „vorgemerkt von Clara",
            // auch wenn der Benutzer spaeter umbenannt oder geloescht wird.
            $table->string('reserved_by_name', 190)->nullable();
            $table->dateTime('reserved_at');
            $table->dateTime('last_reminder_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('last_attempt_result', 500)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_contract_send_reservations');
    }
};
```

- [ ] **Step 2: Modell**

```php
<?php
// src/Models/RecContractSendReservation.php
namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vormerkung fuer den automatischen Vertragsversand (Spec Versand vormerken §2).
 * Offen = completed_at UND cancelled_at leer. Historie bleibt stehen.
 */
class RecContractSendReservation extends Model
{
    public const SOURCE_NACHBEREITUNG = 'nachbereitung';
    public const SOURCE_HR_DESK = 'hr_desk';

    protected $table = 'rec_contract_send_reservations';
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'team_id', 'rec_applicant_id', 'rec_interview_booking_id',
        'vertragsbeginn', 'vertragsende', 'source',
        'reserved_by_user_id', 'reserved_by_name', 'reserved_at',
        'last_reminder_at', 'last_attempt_at', 'last_attempt_result',
        'completed_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'vertragsbeginn' => 'date',
        'vertragsende' => 'date',
        'reserved_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function scopeOffen($query)
    {
        return $query->whereNull('completed_at')->whereNull('cancelled_at');
    }

    public function istOffen(): bool
    {
        return $this->completed_at === null && $this->cancelled_at === null;
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RecInterviewBooking::class, 'rec_interview_booking_id');
    }

    /** Vertragsdaten in der Form, die ContractDispatchService erwartet. */
    public function contractFields(): ?array
    {
        if ($this->vertragsbeginn === null && $this->vertragsende === null) {
            return null;
        }

        return [
            'vertragsbeginn' => $this->vertragsbeginn?->format('Y-m-d'),
            'vertragsende' => $this->vertragsende?->format('Y-m-d'),
        ];
    }
}
```

In `src/Models/RecApplicant.php` direkt nach `public function contracts()`-Methode einfügen:

```php
    public function contractSendReservations()
    {
        return $this->hasMany(RecContractSendReservation::class, 'rec_applicant_id');
    }

    /** Die eine offene Vormerkung — aus der geladenen Relation, sonst per Abfrage. */
    public function offeneVersandVormerkung(): ?RecContractSendReservation
    {
        if ($this->relationLoaded('contractSendReservations')) {
            return $this->contractSendReservations->first(fn ($r) => $r->istOffen());
        }

        return $this->contractSendReservations()->offen()->first();
    }
```

- [ ] **Step 3: Testklasse anlegen (Grundgerüst, Seed, erster Test)**

`tests/Integration/VersandVormerkenTest.php` — Bootstrap wörtlich wie `AnzeigeVerknuepfenTest` (Container + Capsule, Fremdtabellen, Auth-Attrappe, ECHTE Migrationen per glob über `runRealMigrations()` und `packageRootOf()` — diese beiden Methoden 1:1 aus `AnzeigeVerknuepfenTest` übernehmen), aber MIT Event-Dispatcher (die Beobachter in Task 6/7 brauchen ihn):

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Models\RecPhase;

/**
 * Vertragsversand vormerken (Spec 06.10.2026). Eine Klasse fuer alles mit DB:
 * Vormerkung, Erinnerung (Drossel), Klick-Ablauf, Ausloeser, Job-Pruefstufen,
 * Aufraeumen. Aufbau wie AnzeigeVerknuepfenTest, aber MIT Dispatcher.
 */
final class VersandVormerkenTest extends TestCase
{
    private const TEAM = 11;
    private const POSITION = 111;        // „Gladbach"
    private const POSITION_FREMD = 112;  // „Koeln"
    private const PHASE_1 = 211;         // Bewerbung (fields: vorname)
    private const PHASE_3 = 213;         // Onboarding (fields: strasse)
    private const PHASE_4 = 214;         // Vertraege (contract_sent, creates_employee)
    private const PHASE_FREMD = 215;
    private const APPLICANT = 5001;
    private const BOOKING = 7001;
    private const HEUTE = '2026-10-07 10:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::setEventDispatcher($capsule->getEventDispatcher());
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        foreach (['teams', 'users', 'hcm_job_titles', 'comms_channels'] as $fremd) {
            if (!$schema->hasTable($fremd)) {
                $schema->create($fremd, fn ($table) => $table->id());
            }
        }
        if (!$schema->hasTable('crm_contact_links')) {
            $schema->create('crm_contact_links', function ($table) {
                $table->id();
                $table->string('linkable_type');
                $table->unsignedBigInteger('linkable_id');
                $table->timestamps();
            });
        }

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory {
            public function __construct(private int $teamId) {}
            public function user(): object
            {
                return new class($this->teamId) {
                    public object $currentTeam;
                    public int $id = 7;
                    public function __construct(int $teamId) { $this->currentTeam = (object) ['id' => $teamId]; }
                };
            }
            public function guard($name = null) { return $this; }
            public function shouldUse($name) {}
        });

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        RecApplicant::flushEventListeners();
        \Platform\Recruiting\Models\RecInterviewBooking::flushEventListeners();
        \Platform\Recruiting\Models\RecApplicantLegalStatus::flushEventListeners();
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::HEUTE);
        self::ausgangszustand();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Bewerber 5001 zurueck auf Phase 3, keine Vormerkungen, keine Vertraege, keine Logs. */
    private static function ausgangszustand(): void
    {
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update([
            'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_3,
            'is_active' => 1, 'is_parked' => 0, 'rejected_at' => null,
            'contract_template_id' => 900, 'zuschlag' => 1.1,
        ]);
        Capsule::table('rec_contract_send_reservations')->delete();
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_hr_desk_cases')->delete();
        Capsule::table('rec_applicant_legal_statuses')->delete();
        Capsule::table('rec_auto_pilot_logs')->delete();
        Capsule::table('core_extra_field_values')->where('fieldable_id', self::APPLICANT)->delete();
        Capsule::table('rec_interview_bookings')->where('id', self::BOOKING)->update(['status' => 'attended', 'deleted_at' => null]);
    }

    private function bewerber(): RecApplicant
    {
        return RecApplicant::find(self::APPLICANT);
    }

    private function strasseAusfuellen(): void
    {
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => self::definitionId(self::PHASE_3, 'strasse'),
            'fieldable_type' => (new RecApplicant())->getMorphClass(), 'fieldable_id' => self::APPLICANT,
            'value' => 'Bootstraße 8', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);
    }

    private static function definitionId(int $phaseId, string $name): int
    {
        return (int) CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)->where('context_id', $phaseId)->where('name', $name)->value('id');
    }

    private function logs(string $type): \Illuminate\Support\Collection
    {
        return Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', self::APPLICANT)->where('type', $type)->get();
    }

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;
        Capsule::table('rec_positions')->insert([
            ['id' => self::POSITION, 'uuid' => 'vv-pos-111', 'team_id' => self::TEAM, 'title' => 'Moenchengladbach allgemein', 'location' => 'MG', 'is_active' => 1, 'is_sammelstelle' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_FREMD, 'uuid' => 'vv-pos-112', 'team_id' => self::TEAM, 'title' => 'Koeln allgemein', 'location' => 'K', 'is_active' => 1, 'is_sammelstelle' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_1, 'uuid' => 'vv-ph-211', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'name' => 'Bewerbung', 'order' => 1, 'completion_type' => 'fields', 'is_active' => 1, 'auto_pilot_settings' => json_encode(['auto_pilot_wa_initial_template_id' => 25]), 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_3, 'uuid' => 'vv-ph-213', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'name' => 'Onboarding (Bestätigung)', 'order' => 3, 'completion_type' => 'fields', 'is_active' => 1, 'auto_pilot_settings' => json_encode(['auto_pilot_wa_initial_template_id' => 28]), 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_4, 'uuid' => 'vv-ph-214', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'name' => 'Schulung & Verträge versenden', 'order' => 4, 'completion_type' => 'contract_sent', 'completion_config' => json_encode(['creates_employee_on_completion' => true]), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_FREMD, 'uuid' => 'vv-ph-215', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION_FREMD, 'name' => 'Bewerbung', 'order' => 1, 'completion_type' => 'fields', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        CoreExtraFieldDefinition::query()->insert([
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_1, 'name' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'is_required' => 1, 'order' => 1, 'options' => null, 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_3, 'name' => 'strasse', 'label' => 'Straße', 'type' => 'text', 'is_required' => 1, 'order' => 1, 'options' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Capsule::table('rec_contract_templates')->insert([
            'id' => 900, 'uuid' => 'vv-tpl-900', 'team_id' => self::TEAM, 'code' => 'AV-default', 'name' => 'AV', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_interviews')->insert([
            'id' => 8001, 'uuid' => 'vv-int-8001', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'starts_at' => '2026-10-07 18:00:00', 'status' => 'planned', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_applicants')->insert([
            'id' => self::APPLICANT, 'uuid' => 'vv-app-5001', 'team_id' => self::TEAM, 'applied_at' => '2026-10-05',
            'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_3, 'is_test' => 0, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_interview_bookings')->insert([
            'id' => self::BOOKING, 'uuid' => 'vv-bk-7001', 'team_id' => self::TEAM, 'rec_interview_id' => 8001,
            'rec_applicant_id' => self::APPLICANT, 'status' => 'attended', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_offene_vormerkung_wird_gefunden_und_abgeschlossene_nicht(): void
    {
        Capsule::table('rec_contract_send_reservations')->insert([
            ['team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT, 'reserved_at' => self::HEUTE, 'completed_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT, 'reserved_at' => self::HEUTE, 'vertragsbeginn' => '2026-11-01', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);

        $offen = $this->bewerber()->offeneVersandVormerkung();

        $this->assertNotNull($offen);
        $this->assertSame('2026-11-01', $offen->vertragsbeginn->format('Y-m-d'));
        $this->assertSame(['vertragsbeginn' => '2026-11-01', 'vertragsende' => null], $offen->contractFields());
    }
}
```

Hinweis: Die Spaltenlisten der Seeds (z. B. `rec_contract_templates`, `rec_interviews`) müssen zu den echten Migrationen passen. Fehlt eine NOT-NULL-Spalte, meldet SQLite sie beim ersten Lauf — dann ergänzen, nicht raten.

- [ ] **Step 4: Test ausführen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest`
Expected: OK (1 Test). Bei Seed-Fehlern Spalten nachziehen.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_07_000001_create_rec_contract_send_reservations_table.php src/Models/RecContractSendReservation.php src/Models/RecApplicant.php tests/Integration/VersandVormerkenTest.php
git commit -m "feat(recruiting): Vormerkung fuer den Vertragsversand — Tabelle, Modell, Relation

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Erinnerung — Phasen-Vorlage per WhatsApp (Versand herauslösen)

**Files:**
- Modify: `src/Models/RecApplicant.php` — `sendBookingLinkWhatsApp()` (ca. Zeile 1605) aufteilen; neue `sendPhaseOnboardingReminder()`.

**Interfaces:**
- Produces: `RecApplicant::sendPhaseOnboardingReminder(): array{ok: bool, error: ?string}` — Vorlage aus `phase.auto_pilot_settings['auto_pilot_wa_initial_template_id']`, Rückfall Stelle → Team (`RecApplicantSettings`), Log-Typ `contract_send_reminder`.
- Produces (privat): `sendWhatsAppTemplateById(int $templateId, string $logType, string $logSummary, string $contextPurpose, array $bodyValues = []): array{ok: bool, error: ?string}` — der Rumpf des bisherigen Versands ab „class_exists(IntegrationsWhatsAppTemplate)".
- Konsumenten von `sendBookingLinkWhatsApp` (`sendInterviewBookingNotification`, `sendWaitlistAvailableNotification`, `sendTerminWaitlistNotification`) bleiben unverändert (bool-Rückgabe).

- [ ] **Step 1: Herauslösen (reiner Umbau, Verhalten gleich)**

In `sendBookingLinkWhatsApp(...)`: den Teil ab `if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class))` bis zum Ende des äußeren `try` in eine neue private Methode verschieben:

```php
    /**
     * Versendet EINE genehmigte WhatsApp-Vorlage an die primaere Nummer des
     * Bewerbers — Kanal aus Stelle→Team-Kaskade, Body-Platzhalter aus dem
     * Bewerberkontext, URL-Knopf mit dem oeffentlichen Formular-Token.
     * Herausgeloest aus sendBookingLinkWhatsApp (Spec Versand vormerken §3),
     * damit die Onboarding-Erinnerung denselben Weg nimmt.
     *
     * @return array{ok: bool, error: ?string}
     */
    private function sendWhatsAppTemplateById(int $templateId, string $logType, string $logSummary, string $contextPurpose = 'interview_booking', array $bodyValues = []): array
    {
        try {
            $this->loadMissing(['postings.position', 'crmContactLinks.contact.phoneNumbers']);
            $position = $this->postings->sortBy('pivot.applied_at')->first()?->position;
            $positionSettings = $position?->auto_pilot_settings ?? [];
            $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

            if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
                return ['ok' => false, 'error' => 'WhatsApp-Integration nicht verfügbar.'];
            }
            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
            if (!$template || $template->status !== 'APPROVED') {
                return ['ok' => false, 'error' => 'Vorlage nicht gefunden oder nicht genehmigt.'];
            }
            // … ab hier WÖRTLICH der bisherige Rumpf (WA-Konto, Kanal, Telefonnummer,
            // Body-Parameter, URL-Knopf, sendTemplate, Buchhaltung) — mit diesen
            // Ersetzungen: jedes `return false;` → `return ['ok' => false, 'error' => '<Grund>'];`
            // (Gruende: 'Kein WhatsApp-Konto konfiguriert.', 'WhatsApp-Konto inaktiv.',
            // 'Kein aktiver WhatsApp-Kanal.', 'Keine Telefonnummer.'),
            // das abschliessende `return true;` → `return ['ok' => true, 'error' => null];`
        } catch (\Throwable $e) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'error',
                    'summary' => 'WA-Vorlagenversand fehlgeschlagen: ' . $e->getMessage(),
                ]);
            } catch (\Throwable) {}

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
```

`sendBookingLinkWhatsApp` wird zu:

```php
    private function sendBookingLinkWhatsApp(string $templateSettingKey, string $logType, string $logSummary, string $contextPurpose = 'interview_booking', array $bodyValues = []): bool
    {
        $this->loadMissing(['postings.position']);
        $position = $this->postings->sortBy('pivot.applied_at')->first()?->position;
        $positionSettings = $position?->auto_pilot_settings ?? [];
        $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

        $templateId = $positionSettings[$templateSettingKey] ?? $teamSettings->getSetting($templateSettingKey);
        if (!$templateId) {
            return false;
        }

        return $this->sendWhatsAppTemplateById((int) $templateId, $logType, $logSummary, $contextPurpose, $bodyValues)['ok'];
    }
```

Dann die neue öffentliche Methode direkt darunter:

```php
    /**
     * Onboarding-Erinnerung mit Formular-Link: die Erstkontakt-Vorlage der
     * AKTUELLEN Phase (Kaskade Phase → Stelle → Team), Log-Typ
     * contract_send_reminder. Fuer vorgemerkte Versaende (Spec §3).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendPhaseOnboardingReminder(): array
    {
        $this->loadMissing(['phase', 'postings.position']);
        $phaseSettings = $this->phase?->auto_pilot_settings ?? [];
        $position = $this->postings->sortBy('pivot.applied_at')->first()?->position;
        $positionSettings = $position?->auto_pilot_settings ?? [];
        $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

        $templateId = $phaseSettings['auto_pilot_wa_initial_template_id']
            ?? $positionSettings['auto_pilot_wa_initial_template_id']
            ?? $teamSettings->getSetting('auto_pilot_wa_initial_template_id');
        if (!$templateId) {
            return ['ok' => false, 'error' => 'Keine Onboarding-Vorlage für diese Phase konfiguriert.'];
        }

        $phasenName = $this->phase?->name ?? 'aktuelle Phase';

        return $this->sendWhatsAppTemplateById(
            (int) $templateId,
            'contract_send_reminder',
            "Erinnerung zur Vervollständigung („{$phasenName}“) per WhatsApp gesendet — Verträge sind vorgemerkt.",
            'contract_send_reminder',
        );
    }
```

- [ ] **Step 2: Gesamtsuite (Umbau ohne neue Tests — die Suite ist das Netz)**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: OK, gleiche Testzahl wie vor Task 3.

- [ ] **Step 3: Commit**

```bash
git add src/Models/RecApplicant.php
git commit -m "refactor(recruiting): WA-Vorlagenversand herausgeloest, Onboarding-Erinnerung der Phase als eigene Methode

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Vormerkungs-Dienst (anlegen/aktualisieren, Erinnerung mit Drossel, zurücknehmen, abschließen)

**Files:**
- Create: `src/Services/ContractSendReservationService.php`
- Modify: `resources/views/livewire/shared/activity-log.blade.php:26-45` (Icons)
- Test: `tests/Integration/VersandVormerkenTest.php`

**Interfaces:**
- Produces:
  - `vormerken(RecApplicant $a, ?int $bookingId, ?string $beginn, ?string $ende, string $source, ?int $userId, string $userName, array $fehlendeFelder): RecContractSendReservation` — legt an oder aktualisiert die offene; Log `contract_send_reserved`; ruft `erinnern()`.
  - `erinnern(RecContractSendReservation $r, bool $force = false): bool` — Drossel 24 h über `last_reminder_at`; Log `contract_send_reminder` nur bei Fehler (Erfolg loggt das Modell selbst).
  - `zuruecknehmen(RecContractSendReservation $r, string $grund, ?int $userId = null): void` — `cancelled_at`, Log `contract_send_cancelled`.
  - `abschliessen(RecContractSendReservation $r, string $ergebnis): void` — `completed_at`.
  - `vermerkeVersuch(RecContractSendReservation $r, string $ergebnis): void` — `last_attempt_*`, Log `contract_send_waiting`.
  - Überschreibbar für Tests: `protected function erinnerungSenden(RecApplicant $a): array` → delegiert an `$a->sendPhaseOnboardingReminder()`.

- [ ] **Step 1: Tests**

In `VersandVormerkenTest` ergänzen (Helfer + Tests):

```php
    /** Dienst mit Erinnerungs-Attrappe: zaehlt Aufrufe, Ergebnis einstellbar. */
    private array $erinnerungen = [];
    private array $erinnerungsErgebnis = ['ok' => true, 'error' => null];

    private function dienst(): \Platform\Recruiting\Services\ContractSendReservationService
    {
        $calls = &$this->erinnerungen;
        $ergebnis = &$this->erinnerungsErgebnis;

        return new class($calls, $ergebnis) extends \Platform\Recruiting\Services\ContractSendReservationService {
            public function __construct(private array &$calls, private array &$ergebnis) {}
            protected function erinnerungSenden(RecApplicant $a): array
            {
                $this->calls[] = $a->id;
                return $this->ergebnis;
            }
        };
    }

    public function test_vormerken_legt_an_loggt_und_erinnert(): void
    {
        $r = $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', ['Straße']);

        $this->assertTrue($r->istOffen());
        $this->assertSame('2026-11-01', $r->vertragsbeginn->format('Y-m-d'));
        $this->assertSame(7, (int) $r->reserved_by_user_id);
        $this->assertSame([self::APPLICANT], $this->erinnerungen);
        $this->assertSame(self::HEUTE, $r->fresh()->last_reminder_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $this->logs('contract_send_reserved'));
        $this->assertStringContainsString('Clara', $this->logs('contract_send_reserved')->first()->summary);
        $this->assertStringContainsString('Straße', $this->logs('contract_send_reserved')->first()->summary);
    }

    public function test_zweites_vormerken_aktualisiert_die_offene_und_erinnert_nicht_erneut(): void
    {
        $dienst = $this->dienst();
        $erste = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', ['Straße']);
        Carbon::setTestNow('2026-10-07 11:00:00');

        $zweite = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-15', '2027-11-14', 'hr_desk', 8, 'HR', ['Straße']);

        $this->assertSame($erste->id, $zweite->id, 'hoechstens eine offene Vormerkung');
        $this->assertSame('2026-11-15', $zweite->vertragsbeginn->format('Y-m-d'));
        $this->assertSame('hr_desk', $zweite->source);
        $this->assertSame(1, Capsule::table('rec_contract_send_reservations')->count());
        $this->assertCount(1, $this->erinnerungen, 'Drossel: keine zweite Erinnerung binnen 24 h');
        $this->assertCount(2, $this->logs('contract_send_reserved'));
    }

    public function test_erinnerung_nach_24h_wieder_und_mit_force_sofort(): void
    {
        $dienst = $this->dienst();
        $r = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);

        Carbon::setTestNow('2026-10-08 09:59:00');
        $this->assertFalse($dienst->erinnern($r->fresh()));
        Carbon::setTestNow('2026-10-08 10:01:00');
        $this->assertTrue($dienst->erinnern($r->fresh()));
        $this->assertTrue($dienst->erinnern($r->fresh(), force: true));
        $this->assertCount(3, $this->erinnerungen);
    }

    public function test_vormerken_gelingt_auch_wenn_die_erinnerung_scheitert(): void
    {
        $this->erinnerungsErgebnis = ['ok' => false, 'error' => 'Keine Telefonnummer.'];

        $r = $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);

        $this->assertTrue($r->istOffen());
        $this->assertNull($r->fresh()->last_reminder_at);
        $log = $this->logs('contract_send_reminder')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Keine Telefonnummer', $log->summary);
    }

    public function test_zuruecknehmen_und_abschliessen(): void
    {
        $dienst = $this->dienst();
        $r = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);

        $dienst->zuruecknehmen($r, 'Buchung abgesagt', null);
        $this->assertFalse($r->fresh()->istOffen());
        $this->assertSame('Buchung abgesagt', $r->fresh()->cancel_reason);
        $this->assertCount(1, $this->logs('contract_send_cancelled'));
        $this->assertNull($this->bewerber()->offeneVersandVormerkung());

        $r2 = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);
        $dienst->abschliessen($r2, 'automatisch versendet');
        $this->assertNotNull($r2->fresh()->completed_at);
        $this->assertSame(2, Capsule::table('rec_contract_send_reservations')->count(), 'Historie bleibt');
    }
```

- [ ] **Step 2: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest`
Expected: Error „Class … ContractSendReservationService not found".

- [ ] **Step 3: Dienst**

```php
<?php
// src/Services/ContractSendReservationService.php
namespace Platform\Recruiting\Services;

use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecContractSendReservation;

/**
 * Vormerkungen fuer den automatischen Vertragsversand (Spec Versand vormerken
 * §2/§3/§6). Hoechstens EINE offene je Bewerber; erneutes Vormerken
 * aktualisiert sie. Erinnerung = Onboarding-Vorlage der Phase, gedrosselt auf
 * eine pro 24 h. Jeder Schritt steht im Verlauf (rec_auto_pilot_logs).
 */
class ContractSendReservationService
{
    public const REMINDER_THROTTLE_HOURS = 24;

    /** @param list<string> $fehlendeFelder */
    public function vormerken(
        RecApplicant $applicant,
        ?int $bookingId,
        ?string $vertragsbeginn,
        ?string $vertragsende,
        string $source,
        ?int $userId,
        string $userName,
        array $fehlendeFelder,
    ): RecContractSendReservation {
        $offen = $applicant->contractSendReservations()->offen()->first();
        $neu = $offen === null;

        $daten = [
            'rec_interview_booking_id' => $bookingId,
            'vertragsbeginn' => $vertragsbeginn ?: null,
            'vertragsende' => $vertragsende ?: null,
            'source' => $source,
            'reserved_by_user_id' => $userId,
            'reserved_by_name' => mb_substr($userName, 0, 190),
            'reserved_at' => Carbon::now(),
        ];

        if ($neu) {
            $offen = $applicant->contractSendReservations()->create($daten + ['team_id' => $applicant->team_id]);
        } else {
            $offen->fill($daten)->save();
        }

        $felder = $fehlendeFelder === [] ? 'Pflichtfelder der aktuellen Phase' : implode(', ', $fehlendeFelder);
        $this->log($applicant, 'contract_send_reserved', sprintf(
            'Versand %s von %s — Onboarding unvollständig: %s. Vertragsbeginn %s. Geht automatisch raus, sobald die Daten vollständig sind.',
            $neu ? 'vorgemerkt' : 'erneut vorgemerkt (Daten aktualisiert)',
            $userName,
            $felder,
            $vertragsbeginn ?: '—',
        ), ['reservation_id' => $offen->id, 'user_id' => $userId, 'fehlende_felder' => $fehlendeFelder, 'source' => $source]);

        $this->erinnern($offen);

        return $offen;
    }

    /** true = Erinnerung ging raus. Drossel: eine pro 24 h, ausser force. */
    public function erinnern(RecContractSendReservation $reservation, bool $force = false): bool
    {
        if (!$force && $reservation->last_reminder_at !== null
            && $reservation->last_reminder_at->gt(Carbon::now()->subHours(self::REMINDER_THROTTLE_HOURS))) {
            return false;
        }

        $applicant = $reservation->applicant;
        if (!$applicant) {
            return false;
        }

        $ergebnis = $this->erinnerungSenden($applicant);
        if ($ergebnis['ok']) {
            $reservation->forceFill(['last_reminder_at' => Carbon::now()])->save();

            return true;
        }

        // Erfolg loggt das Modell selbst (contract_send_reminder); hier nur der Fehler.
        $this->log($applicant, 'contract_send_reminder', 'Erinnerung zur Vervollständigung NICHT gesendet: ' . ($ergebnis['error'] ?? 'unbekannt'), ['reservation_id' => $reservation->id]);

        return false;
    }

    public function zuruecknehmen(RecContractSendReservation $reservation, string $grund, ?int $userId = null): void
    {
        if (!$reservation->istOffen()) {
            return;
        }
        $reservation->forceFill(['cancelled_at' => Carbon::now(), 'cancel_reason' => mb_substr($grund, 0, 255)])->save();
        if ($reservation->applicant) {
            $this->log($reservation->applicant, 'contract_send_cancelled', 'Versand-Vormerkung zurückgenommen: ' . $grund, ['reservation_id' => $reservation->id, 'user_id' => $userId]);
        }
    }

    public function abschliessen(RecContractSendReservation $reservation, string $ergebnis): void
    {
        $reservation->forceFill([
            'completed_at' => Carbon::now(),
            'last_attempt_at' => Carbon::now(),
            'last_attempt_result' => mb_substr($ergebnis, 0, 500),
        ])->save();
    }

    public function vermerkeVersuch(RecContractSendReservation $reservation, string $ergebnis): void
    {
        $reservation->forceFill(['last_attempt_at' => Carbon::now(), 'last_attempt_result' => mb_substr($ergebnis, 0, 500)])->save();
        if ($reservation->applicant) {
            $this->log($reservation->applicant, 'contract_send_waiting', 'Automatischer Versand wartet: ' . $ergebnis, ['reservation_id' => $reservation->id]);
        }
    }

    /** @return array{ok: bool, error: ?string} */
    protected function erinnerungSenden(RecApplicant $applicant): array
    {
        return $applicant->sendPhaseOnboardingReminder();
    }

    private function log(RecApplicant $applicant, string $type, string $summary, array $details = []): void
    {
        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type' => $type,
                'summary' => $summary,
                'details' => $details,
            ]);
        } catch (\Throwable) {
            // Verlauf darf die Aktion nie kippen.
        }
    }
}
```

In `resources/views/livewire/shared/activity-log.blade.php` in beiden `match($log->type)`-Blöcken ergänzen (vor `default`):

```php
                            'contract_send_reserved' => 'heroicon-o-clock',
                            'contract_send_reminder' => 'heroicon-o-chat-bubble-left',
                            'contract_send_waiting' => 'heroicon-o-pause-circle',
                            'contract_send_auto_sent' => 'heroicon-o-paper-airplane',
                            'contract_send_cancelled' => 'heroicon-o-x-circle',
```

und bei den Farben: `reserved` → `text-amber-500`, `reminder` → `text-blue-500`, `waiting` → `text-amber-600`, `auto_sent` → `text-emerald-600`, `cancelled` → `text-gray-500`.

- [ ] **Step 4: Tests grün + Blade-Check**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → OK.
Run: `php tools/blade-check.php resources/views/livewire/shared/activity-log.blade.php` → 0 Funde.

- [ ] **Step 5: Commit**

```bash
git add src/Services/ContractSendReservationService.php resources/views/livewire/shared/activity-log.blade.php tests/Integration/VersandVormerkenTest.php
git commit -m "feat(recruiting): Vormerkungs-Dienst — anlegen/aktualisieren, Erinnerung mit 24h-Drossel, zuruecknehmen, Verlauf

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Klick-Ablauf — Nachbereitung und HR-Schreibtisch merken vor statt zu überspringen

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` — `sendContractsBulk()`, `sendPortalLinkBulk()`, `mitarbeiterAnlageSperrgruende()` → wird `versandZustaende()`, `bulkSendState()`, Flash-Texte.
- Modify: `src/Livewire/HrDesk/Index.php` — `sendContractsFromDesk()`.
- Modify: `resources/views/livewire/hr-desk/index.blade.php:233-262` und `:383-392`.
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Zeilen mit `$versandGesperrt`, Button-Zustände ab `@if($bulkState === 'no_attended')`).
- Test: `tests/Integration/VersandVormerkenTest.php` (der Klick-Kern wird in eine testbare Hilfsklasse gezogen, siehe Interfaces).

**Interfaces:**
- Produces: `src/Services/ContractSendRun.php` — `final class ContractSendRun { public function __construct(ContractDispatchService $dispatch, ContractSendReservationService $reservations) {} /** @param iterable<RecInterviewBooking> $bookings @param array<int, array{vertragsbeginn?:?string, vertragsende?:?string}> $datenJeBewerber */ public function ausfuehren(iterable $bookings, array $datenJeBewerber, ?RecContractTemplate $defaultTemplate, int $userId, string $userName, string $source): ContractSendRunResult }` mit `ContractSendRunResult { public array $versendet = []; public array $vorgemerkt = []; /** [applicantId => grund] */ public array $gesperrt = []; public array $fehler = []; public function meldung(): string }`.
- Konsumiert: `RecApplicant::versandBereitschaft()`, `ContractSendReservationService::vormerken(...)`, `ContractDispatchService::sendForApplicant(...)`.
- Die beiden Sammelmethoden in `InterviewBookings\Index` filtern weiterhin selbst (teilgenommen, Vorlage, nicht versendet, Rechtsstatus, Vertragsbeginn, Zuschlag) und reichen die Übrigen an `ContractSendRun` — damit Review-Focus 5 (kein Vormerken ohne Vertragsbeginn) durch die bestehende Reihenfolge gesichert bleibt.

- [ ] **Step 1: Test für `ContractSendRun`**

```php
    private array $gesendet = [];

    private function run(): \Platform\Recruiting\Services\ContractSendRun
    {
        $gesendet = &$this->gesendet;
        $dispatch = new class($gesendet) extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct(private array &$gesendet) {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $this->gesendet[] = [$applicant->id, $contractFields];
                Capsule::table('rec_contracts')->insert(['uuid' => 'c-' . $applicant->id . '-' . count($this->gesendet), 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };

        return new \Platform\Recruiting\Services\ContractSendRun($dispatch, $this->dienst());
    }

    public function test_klick_sendet_bereite_merkt_unvollstaendige_vor_und_sperrt_fremde(): void
    {
        // 5001: Phase 3, Strasse leer → unvollstaendig
        // 5002: Phase 4 → bereit
        // 5003: Phase fremder Stelle → gesperrt
        Capsule::table('rec_applicants')->insert([
            ['id' => 5002, 'uuid' => 'vv-app-5002', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_4, 'contract_template_id' => 900, 'zuschlag' => 1.1, 'is_test' => 0, 'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['id' => 5003, 'uuid' => 'vv-app-5003', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_FREMD, 'contract_template_id' => 900, 'zuschlag' => 1.1, 'is_test' => 0, 'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        Capsule::table('rec_interview_bookings')->insert([
            ['id' => 7002, 'uuid' => 'vv-bk-7002', 'team_id' => self::TEAM, 'rec_interview_id' => 8001, 'rec_applicant_id' => 5002, 'status' => 'attended', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['id' => 7003, 'uuid' => 'vv-bk-7003', 'team_id' => self::TEAM, 'rec_interview_id' => 8001, 'rec_applicant_id' => 5003, 'status' => 'attended', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        try {
            $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereIn('id', [self::BOOKING, 7002, 7003])->get();
            $daten = [self::APPLICANT => ['vertragsbeginn' => '2026-11-01'], 5002 => ['vertragsbeginn' => '2026-11-01'], 5003 => ['vertragsbeginn' => '2026-11-01']];

            $ergebnis = $this->run()->ausfuehren($bookings, $daten, null, 7, 'Clara', 'nachbereitung');

            $this->assertSame([5002], $ergebnis->versendet);
            $this->assertSame([self::APPLICANT], $ergebnis->vorgemerkt);
            $this->assertArrayHasKey(5003, $ergebnis->gesperrt);
            $this->assertSame([], $ergebnis->fehler);
            $this->assertNotNull($this->bewerber()->offeneVersandVormerkung());
            $this->assertSame('2026-11-01', $this->bewerber()->offeneVersandVormerkung()->vertragsbeginn->format('Y-m-d'));
            $this->assertStringContainsString('1 versendet', $ergebnis->meldung());
            $this->assertStringContainsString('1 vorgemerkt', $ergebnis->meldung());
            $this->assertStringContainsString('1 gesperrt', $ergebnis->meldung());
        } finally {
            Capsule::table('rec_interview_bookings')->whereIn('id', [7002, 7003])->delete();
            Capsule::table('rec_applicants')->whereIn('id', [5002, 5003])->delete();
        }
    }

    public function test_klick_ohne_vertragsbeginn_merkt_nicht_vor(): void
    {
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $ergebnis = $this->run()->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => null]], null, 7, 'Clara', 'nachbereitung');

        $this->assertSame([], $ergebnis->vorgemerkt);
        $this->assertArrayHasKey(self::APPLICANT, $ergebnis->fehler);
        $this->assertStringContainsString('Vertragsbeginn', $ergebnis->fehler[self::APPLICANT]);
        $this->assertNull($this->bewerber()->offeneVersandVormerkung());
    }
```

- [ ] **Step 2: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → Error „ContractSendRun not found".

- [ ] **Step 3: `ContractSendRun` + Ergebnis**

```php
<?php
// src/Services/ContractSendRun.php
namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Der Klick „Verträge versenden" fuer eine Gruppe (Nachbereitung) oder eine
 * Person (HR-Schreibtisch) — Spec Versand vormerken §3:
 *   bereit → sofort senden · unvollstaendig → vormerken + Erinnerung ·
 *   gesperrt → nur melden. Vorfilter (Teilgenommen, Vorlage, Rechtsstatus,
 *   Zuschlag) macht der Aufrufer; Vertragsbeginn wird HIER verlangt, damit
 *   nie ohne Datum vorgemerkt wird.
 */
final class ContractSendRun
{
    public function __construct(
        private ContractDispatchService $dispatch,
        private ContractSendReservationService $reservations,
    ) {}

    /**
     * @param iterable<RecInterviewBooking> $bookings
     * @param array<int, array{vertragsbeginn?: ?string, vertragsende?: ?string}> $datenJeBewerber
     */
    public function ausfuehren(iterable $bookings, array $datenJeBewerber, ?RecContractTemplate $defaultTemplate, int $userId, string $userName, string $source): ContractSendRunResult
    {
        $ergebnis = new ContractSendRunResult();

        foreach ($bookings as $booking) {
            $applicant = $booking->applicant;
            if (!$applicant) {
                continue;
            }
            $daten = $datenJeBewerber[$applicant->id] ?? [];
            $beginn = $daten['vertragsbeginn'] ?? null;
            $ende = $daten['vertragsende'] ?? null;

            if (empty($beginn)) {
                $ergebnis->fehler[$applicant->id] = 'Vertragsbeginn fehlt.';
                continue;
            }

            $bereitschaft = $applicant->versandBereitschaft();

            if ($bereitschaft->status === 'gesperrt') {
                $ergebnis->gesperrt[$applicant->id] = $bereitschaft->grund;
                continue;
            }

            if ($bereitschaft->status === 'unvollstaendig') {
                $this->reservations->vormerken($applicant, $booking->id, $beginn, $ende, $source, $userId, $userName, $bereitschaft->fehlendeFelder);
                $ergebnis->vorgemerkt[] = $applicant->id;
                continue;
            }

            $result = $this->dispatch->sendForApplicant($applicant, $userId, ['vertragsbeginn' => $beginn, 'vertragsende' => $ende], $defaultTemplate);
            if ($result['status'] === 'sent') {
                $ergebnis->versendet[] = $applicant->id;
                if (ContractDispatchService::isPortalFailure($result)) {
                    $ergebnis->fehler[$applicant->id] = $result['message'] ?? 'Portal-WA fehlgeschlagen.';
                }
            } elseif ($result['status'] === 'error') {
                $ergebnis->fehler[$applicant->id] = $result['message'] ?? 'Versand fehlgeschlagen.';
            }
        }

        return $ergebnis;
    }
}
```

```php
<?php
// src/Services/ContractSendRunResult.php
namespace Platform\Recruiting\Services;

final class ContractSendRunResult
{
    /** @var list<int> */ public array $versendet = [];
    /** @var list<int> */ public array $vorgemerkt = [];
    /** @var array<int, string> */ public array $gesperrt = [];
    /** @var array<int, string> */ public array $fehler = [];

    public function meldung(): string
    {
        $teile = [count($this->versendet) . ' versendet'];
        if ($this->vorgemerkt !== []) {
            $teile[] = count($this->vorgemerkt) . ' vorgemerkt (Daten fehlen — Erinnerung geschickt, Versand folgt automatisch)';
        }
        if ($this->gesperrt !== []) {
            $teile[] = count($this->gesperrt) . ' gesperrt (siehe Teilnehmerliste)';
        }
        if ($this->fehler !== []) {
            $teile[] = count($this->fehler) . ' mit Fehler: ' . implode(' · ', array_slice(array_values($this->fehler), 0, 3));
        }

        return implode(', ', $teile) . '.';
    }

    public function hatFehler(): bool
    {
        return $this->fehler !== [];
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → OK.

- [ ] **Step 5: Nachbereitung umstellen (`src/Livewire/InterviewBookings/Index.php`)**

(a) Computed `mitarbeiterAnlageSperrgruende()` ERSETZEN durch `versandZustaende()`; alle Vorkommen von `mitarbeiterAnlageSperrgruende` in Index.php und der View auf `versandZustaende` umbenennen (grep!). Rückgabe je Bewerber ein Array:

```php
    /**
     * Versand-Zustand je Bewerber dieses Termins (Spec Versand vormerken §7):
     * [applicantId => ['code' => 'bereit'|'unvollstaendig'|'gesperrt'|'vorgemerkt'|'wartet'|'versendet',
     *                  'text' => Kurztext, 'detail' => ?string, 'reservation_id' => ?int]].
     * Nur Buchungen, bei denen ein Versand ueberhaupt ansteht (nicht abgesagt/
     * nicht erschienen/aussortiert). Phasen je Stelle einmal geladen.
     *
     * @return array<int, array{code:string,text:string,detail:?string,reservation_id:?int}>
     */
    #[Computed]
    public function versandZustaende(): array
    {
        $relevant = $this->bookings->filter(fn ($b) => $b->applicant && !in_array($b->status, ['cancelled', 'no_show', 'rejected_on_site'], true));

        $stellenIds = $relevant->map(fn ($b) => $b->applicant->phase?->rec_position_id)->filter()->unique()->values();
        $phasenJeStelle = $stellenIds->isEmpty() ? collect()
            : \Platform\Recruiting\Models\RecPhase::whereIn('rec_position_id', $stellenIds)
                ->get(['id', 'rec_position_id', 'order', 'is_active', 'completion_config'])->groupBy('rec_position_id');

        $zustaende = [];
        foreach ($relevant as $b) {
            $a = $b->applicant;
            $versendet = $a->contracts->contains(fn ($c) => $c->status !== 'cancelled' && $c->sent_at !== null);
            $vormerkung = $a->offeneVersandVormerkung();

            if ($versendet) {
                $gesendetAm = $a->contracts->filter(fn ($c) => $c->sent_at !== null)->min('sent_at');
                $abgeschlossen = $a->contractSendReservations->first(fn ($r) => $r->completed_at !== null);
                $zustaende[$a->id] = [
                    'code' => 'versendet',
                    'text' => 'Versendet ' . ($gesendetAm ? \Illuminate\Support\Carbon::parse($gesendetAm)->format('d.m. H:i') : ''),
                    'detail' => $abgeschlossen ? 'automatisch (vorgemerkt am ' . $abgeschlossen->reserved_at?->format('d.m.') . ')' : null,
                    'reservation_id' => null,
                ];
                continue;
            }

            $phasen = $a->phase ? ($phasenJeStelle->get($a->phase->rec_position_id) ?? collect()) : null;
            $bereitschaft = $a->versandBereitschaft($phasen);

            if ($vormerkung) {
                $wartet = $vormerkung->last_attempt_result;
                $zustaende[$a->id] = [
                    'code' => $wartet ? 'wartet' : 'vorgemerkt',
                    'text' => $wartet ? 'Vorgemerkt · wartet' : 'Vorgemerkt · ' . $bereitschaft->kurztext(),
                    'detail' => $wartet ?: ($vormerkung->last_reminder_at ? 'erinnert ' . $vormerkung->last_reminder_at->format('d.m. H:i') : 'noch nicht erinnert'),
                    'reservation_id' => $vormerkung->id,
                ];
                continue;
            }

            $zustaende[$a->id] = ['code' => $bereitschaft->status, 'text' => $bereitschaft->kurztext(), 'detail' => null, 'reservation_id' => null];
        }

        return $zustaende;
    }
```

Eager-Load in `bookings()` ergänzen: `'applicant.contractSendReservations'` (neben `'applicant.contracts:…'`).

(b) `bulkSendState()`: den Block `$pendingVersandbar = …; if ($pendingVersandbar->isEmpty()) { return 'no_employee_path'; }` ersetzen durch:

```php
        $zustaende = $this->versandZustaende;
        $pendingVersandbar = $pending->filter(fn ($b) => in_array($zustaende[$b->applicant?->id]['code'] ?? 'gesperrt', ['bereit', 'unvollstaendig', 'vorgemerkt', 'wartet'], true));
        if ($pendingVersandbar->isEmpty()) {
            return 'no_employee_path';
        }
        $pending = $pendingVersandbar;
```

(c) `sendContractsBulk()` und `sendPortalLinkBulk()`: der Filter bleibt bis einschließlich Rechtsstatus; die Zeile `if (isset($this->mitarbeiterAnlageSperrgruende[...]))`-Prüfung entfällt (das macht der Run). Die Schleife mit `$service->send(...)` / `$dispatch->sendForApplicant(...)` samt Zählern ersetzen durch:

```php
        $daten = [];
        foreach ($eligible as $b) {
            $daten[$b->applicant->id] = $this->contractDates[$b->applicant->id] ?? [];
        }
        $run = new \Platform\Recruiting\Services\ContractSendRun(
            app(\Platform\Recruiting\Services\ContractDispatchService::class),
            app(\Platform\Recruiting\Services\ContractSendReservationService::class),
        );
        $ergebnis = $run->ausfuehren($eligible, $daten, $this->defaultContractTemplate, (int) auth()->id(), (string) (auth()->user()->name ?? 'HR'), \Platform\Recruiting\Models\RecContractSendReservation::SOURCE_NACHBEREITUNG);

        unset($this->bookings, $this->openNonEuCaseApplicantIds, $this->versandZustaende, $this->bulkSendState);
        $this->hydrateContractDatesFromExistingContracts();

        $msg = $ergebnis->meldung();
        if ($blockedByLegalStatus->isNotEmpty()) {
            $msg .= sprintf(' %d wegen offener Rechtsstatus-Prüfung übersprungen.', $blockedByLegalStatus->count());
        }
        session()->flash($ergebnis->hatFehler() ? 'error' : 'success', $msg);
```

Die Prüfungen „Vertragsbeginn fehlt" / „Zuschlag fehlt" VOR dem Run bleiben (sie brechen wie bisher den ganzen Lauf ab). `sendContractsBulk` (ohne Portal) nutzt bisher `SendContractsService` direkt — auf denselben `ContractSendRun` umstellen (der Run versendet über `ContractDispatchService`, der Vertrag + Portal in einem Zug macht; die UI markiert den reinen Vertrags-Button ohnehin als „NICHT NUTZEN").
Die private Methode `versandRiegelHinweis()` entfällt.

(d) View `resources/views/livewire/interview-bookings/index.blade.php`: `$versandGesperrt = $this->mitarbeiterAnlageSperrgruende;` → `$versandZustaende = $this->versandZustaende;`. Der obere Hinweis zählt jetzt zwei Gruppen:

```blade
                    @php
                        $versandZustaende = $this->versandZustaende;
                        $anzahlGesperrt = count(array_filter($versandZustaende, fn ($z) => $z['code'] === 'gesperrt'));
                        $anzahlVorgemerkt = count(array_filter($versandZustaende, fn ($z) => in_array($z['code'], ['vorgemerkt', 'wartet'], true)));
                    @endphp
                    @if($anzahlGesperrt > 0)
                        <div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                            <span class="font-semibold">{{ $anzahlGesperrt }} {{ $anzahlGesperrt === 1 ? 'Teilnehmer ist' : 'Teilnehmer sind' }} für den Vertragsversand gesperrt</span>
                            — danach würde kein Mitarbeiter angelegt. Den Grund zeigt der rote Hinweis an der Zeile.
                        </div>
                    @endif
                    @if($anzahlVorgemerkt > 0)
                        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <span class="font-semibold">{{ $anzahlVorgemerkt }} Versand{{ $anzahlVorgemerkt === 1 ? '' : 'e' }} vorgemerkt</span>
                            — gehen automatisch raus, sobald die Daten vollständig sind (Spalte „Versand").
                        </div>
                    @endif
```

Zeilenhinweise in Übersicht und Nachbereitung: `@if(isset($versandGesperrt[$id]))` → `@if(($versandZustaende[$id]['code'] ?? null) === 'gesperrt')` mit `title="{{ $versandZustaende[$id]['text'] }}"`. Button-Zustand `no_employee_path` bleibt (Text: „— alle offenen Teilnehmer gesperrt").

- [ ] **Step 6: HR-Schreibtisch umstellen (`src/Livewire/HrDesk/Index.php::sendContractsFromDesk`)**

Nach dem Block `if ($state === 'missing_zuschlag') {…}` und vor `$portalWarning = null;` einfügen:

```php
        // Spec Versand vormerken §3: Onboarding offen → vormerken statt senden.
        $bereitschaft = $applicant->versandBereitschaft();
        if ($state === 'ready' && $bereitschaft->status === 'unvollstaendig') {
            app(\Platform\Recruiting\Services\ContractSendReservationService::class)->vormerken(
                $applicant,
                $this->attendedBookingIdFor($applicant),
                $fields['vertragsbeginn'] ?? null,
                $fields['vertragsende'] ?? null,
                \Platform\Recruiting\Models\RecContractSendReservation::SOURCE_HR_DESK,
                $userId,
                (string) (Auth::user()->name ?? 'HR'),
                $bereitschaft->fehlendeFelder,
            );
            session()->flash('message', 'Versand vorgemerkt — ' . $bereitschaft->kurztext() . '. Erinnerung geschickt; nach Vervollständigung gehen Verträge + Portallink automatisch raus. Fall bleibt offen.');
            unset($this->cases);

            return;
        }
```

Helfer in derselben Klasse:

```php
    private function attendedBookingIdFor(RecApplicant $applicant): ?int
    {
        return $applicant->interviewBookings()->where('status', 'attended')->orderByDesc('id')->value('id');
    }
```

In der View (`hr-desk/index.blade.php`, `@php`-Block mit `$deskSperrgrund`): zusätzlich `$deskVormerkung = $applicant?->offeneVersandVormerkung();` (Eager-Load `applicant.contractSendReservations` in `cases()` ergänzen). Nach dem `@elseif($sendState === 'no_employee_path')`-Absatz:

```blade
                                        @elseif($deskVormerkung)
                                            <p class="text-[11px] text-amber-800 mt-2">Versand vorgemerkt am {{ $deskVormerkung->reserved_at->format('d.m. H:i') }} — {{ $deskVormerkung->last_attempt_result ? 'wartet: ' . $deskVormerkung->last_attempt_result : 'geht automatisch raus, sobald die Daten vollständig sind' }}.</p>
```

- [ ] **Step 7: Blade-Check + Gesamtsuite**

Run: `php tools/blade-check.php resources/views/livewire/interview-bookings/index.blade.php resources/views/livewire/hr-desk/index.blade.php` → 0 Funde.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` → OK.

- [ ] **Step 8: Commit**

```bash
git add src/Services/ContractSendRun.php src/Services/ContractSendRunResult.php src/Livewire/InterviewBookings/Index.php src/Livewire/HrDesk/Index.php resources/views/livewire/interview-bookings/index.blade.php resources/views/livewire/hr-desk/index.blade.php tests/Integration/VersandVormerkenTest.php
git commit -m "feat(recruiting): Klick 'Versenden' merkt Unvollstaendige vor statt sie zu ueberspringen — Nachbereitung + HR-Schreibtisch

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Automatischer Versand — Prüflauf, Job, Trigger

**Files:**
- Create: `src/Services/ReservedContractSender.php`
- Create: `src/Jobs/SendReservedContractsJob.php`
- Create: `src/Services/ReservedSendTrigger.php` (Interface), `src/Services/QueueReservedSendTrigger.php`
- Modify: `src/RecruitingServiceProvider.php` (Binding im `register()`)
- Test: `tests/Integration/VersandVormerkenTest.php`

**Interfaces:**
- Produces: `ReservedContractSender::versuchen(int $applicantId): string` — Ergebnis-Codes: `keine_vormerkung`, `abgebrochen`, `bereits_versendet`, `wartet_hr`, `wartet_unvollstaendig`, `wartet_gesperrt`, `wartet_vertragsbeginn`, `wartet_zuschlag`, `versendet`, `fehler`. Konstruktor: `(ContractDispatchService $dispatch, ContractSendReservationService $reservations, HrDeskRoutingService $hrDesk)`.
- Produces: `interface ReservedSendTrigger { public function anstossen(int $applicantId, string $anlass): void; }`; `QueueReservedSendTrigger` dispatcht `SendReservedContractsJob` mit `->afterCommit()`.
- Produces: `SendReservedContractsJob implements ShouldQueue`, `__construct(public int $applicantId)`, `$tries = 3`, `handle(ReservedContractSender $sender): void` → `$sender->versuchen($this->applicantId)`.

- [ ] **Step 1: Tests für alle Prüfstufen**

```php
    private function sender(): \Platform\Recruiting\Services\ReservedContractSender
    {
        $gesendet = &$this->gesendet;
        $dispatch = new class($gesendet) extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct(private array &$gesendet) {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $this->gesendet[] = [$applicant->id, $userId, $contractFields];
                Capsule::table('rec_contracts')->insert(['uuid' => 'c-' . $applicant->id . '-' . count($this->gesendet), 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => Carbon::now()->format('Y-m-d H:i:s'), 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };

        return new \Platform\Recruiting\Services\ReservedContractSender($dispatch, $this->dienst(), new \Platform\Recruiting\Services\HrDeskRoutingService());
    }

    private function vormerkung(string $beginn = '2026-11-01'): RecContractSendReservation
    {
        return $this->dienst()->vormerken($this->bewerber(), self::BOOKING, $beginn, null, 'nachbereitung', 7, 'Clara', ['Straße']);
    }

    private function inVertragsphase(): void
    {
        $this->strasseAusfuellen();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_4]);
    }

    public function test_job_ohne_vormerkung_tut_nichts(): void
    {
        $this->assertSame('keine_vormerkung', $this->sender()->versuchen(self::APPLICANT));
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_versendet_mit_den_vorgemerkten_daten_und_schliesst_ab(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();

        $this->assertSame('versendet', $this->sender()->versuchen(self::APPLICANT));

        $this->assertSame([[self::APPLICANT, 7, ['vertragsbeginn' => '2026-11-01', 'vertragsende' => null]]], $this->gesendet);
        $this->assertNotNull($r->fresh()->completed_at);
        $this->assertCount(1, $this->logs('contract_send_auto_sent'));
        $this->assertStringContainsString('Clara', $this->logs('contract_send_auto_sent')->first()->summary);
    }

    public function test_job_zweiter_lauf_versendet_nicht_noch_einmal(): void
    {
        $this->vormerkung();
        $this->inVertragsphase();
        $sender = $this->sender();
        $sender->versuchen(self::APPLICANT);

        $this->assertSame('keine_vormerkung', $sender->versuchen(self::APPLICANT));
        $this->assertCount(1, $this->gesendet);
    }

    public function test_job_schliesst_ab_wenn_vertrag_auf_anderem_weg_raus_ist(): void
    {
        $r = $this->vormerkung();
        Capsule::table('rec_contracts')->insert(['uuid' => 'c-fremd', 'team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('bereits_versendet', $this->sender()->versuchen(self::APPLICANT));
        $this->assertNotNull($r->fresh()->completed_at);
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_wartet_bei_unvollstaendig(): void
    {
        $r = $this->vormerkung();

        $this->assertSame('wartet_unvollstaendig', $this->sender()->versuchen(self::APPLICANT));
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertStringContainsString('Straße', $r->fresh()->last_attempt_result);
        $this->assertCount(1, $this->logs('contract_send_waiting'));
    }

    public function test_job_wartet_bei_gesperrt_nach_umsetzen_auf_fremde_phase(): void
    {
        $r = $this->vormerkung();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_FREMD]);

        $this->assertSame('wartet_gesperrt', $this->sender()->versuchen(self::APPLICANT));
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_wartet_bei_vergangenem_vertragsbeginn(): void
    {
        $r = $this->vormerkung('2026-10-01');
        $this->inVertragsphase();

        $this->assertSame('wartet_vertragsbeginn', $this->sender()->versuchen(self::APPLICANT));
        $this->assertStringContainsString('Vergangenheit', $r->fresh()->last_attempt_result);
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_wartet_bei_fehlendem_zuschlag(): void
    {
        $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['zuschlag' => null]);

        $this->assertSame('wartet_zuschlag', $this->sender()->versuchen(self::APPLICANT));
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_nicht_eu_ungeprueft_routet_auf_hr_schreibtisch_und_wartet(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_applicant_legal_statuses')->insert(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => 0, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('wartet_hr', $this->sender()->versuchen(self::APPLICANT));

        $fall = Capsule::table('rec_hr_desk_cases')->where('rec_applicant_id', self::APPLICANT)->where('reason', 'non_eu_citizen')->first();
        $this->assertNotNull($fall, 'HR-Fall angelegt');
        $this->assertStringContainsString('vorgemerkt', (string) $fall->notes);
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_bricht_ab_wenn_bewerber_geparkt(): void
    {
        $r = $this->vormerkung();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['is_parked' => 1]);

        $this->assertSame('abgebrochen', $this->sender()->versuchen(self::APPLICANT));
        $this->assertNotNull($r->fresh()->cancelled_at);
    }
```

Hinweis zum HR-Fall-Test: `HrDeskRoutingService::routeToHrDesk` schreibt vermutlich `is_on_hr_desk`/`auto_pilot` am Bewerber und einen Log — prüfen, ob es im Capsule-Setup ohne Laravel-App läuft (es nutzt Eloquent, keine Facades außer ggf. `now()`); falls es `auth()` o. Ä. braucht, den Sender-Konstruktor mit einer Attrappe (anonyme Unterklasse, `routeIfNotAlreadyOpen` überschrieben, legt die Zeile direkt an) versorgen und das im Test dokumentieren.

- [ ] **Step 2: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → Error „ReservedContractSender not found".

- [ ] **Step 3: Prüflauf**

```php
<?php
// src/Services/ReservedContractSender.php
namespace Platform\Recruiting\Services;

use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * Der automatische Versand einer Vormerkung (Spec Versand vormerken §4).
 * Prueft ALLES frisch, in fester Reihenfolge, und schreibt jedes Ergebnis an
 * die Vormerkung + in den Verlauf. Idempotent: Zeilensperre auf der
 * Vormerkung, „schon versendet" schliesst ab statt zu senden.
 */
class ReservedContractSender
{
    public function __construct(
        private ContractDispatchService $dispatch,
        private ContractSendReservationService $reservations,
        private HrDeskRoutingService $hrDesk,
    ) {}

    public function versuchen(int $applicantId): string
    {
        $connection = (new RecContractSendReservation())->getConnection();

        return $connection->transaction(function () use ($applicantId) {
            $reservation = RecContractSendReservation::query()
                ->where('rec_applicant_id', $applicantId)->offen()
                ->lockForUpdate()->first();
            if (!$reservation) {
                return 'keine_vormerkung';
            }

            $applicant = RecApplicant::with(['phase.position', 'position', 'legalStatus', 'employee'])->find($applicantId);

            // 1) Bewerber noch im Rennen?
            if (!$applicant || !$applicant->is_active || $applicant->is_parked || $applicant->rejected_at !== null) {
                $this->reservations->zuruecknehmen($reservation, 'Bewerber nicht mehr aktiv (geparkt/abgelehnt/deaktiviert).');
                return 'abgebrochen';
            }

            // 2) Schon ein Vertrag raus (anderer Weg)?
            if ($applicant->hasAnyContractSent()) {
                $this->reservations->abschliessen($reservation, 'Verträge waren bereits auf anderem Weg versendet.');
                return 'bereits_versendet';
            }

            // 3) Rechtsstatus / offener HR-Fall
            $offenerFall = $applicant->hrDeskCases()->open()->whereIn('reason', RecHrDeskCase::CONTRACT_BLOCKING_REASONS)->exists();
            if ($applicant->isLegalStatusUnchecked() || $offenerFall) {
                if (!$offenerFall) {
                    $this->hrDesk->routeIfNotAlreadyOpen($applicant, RecHrDeskCase::REASON_NON_EU_CITIZEN, null, sprintf(
                        'Versand vorgemerkt am %s (Vertragsbeginn %s) — nach Rechtsstatus-Prüfung und Freigabe geht er automatisch raus.',
                        $reservation->reserved_at?->format('d.m.Y H:i'),
                        $reservation->vertragsbeginn?->format('d.m.Y') ?? '—',
                    ));
                }
                $this->reservations->vermerkeVersuch($reservation, 'Rechtsstatus prüfen (HR-Schreibtisch).');
                return 'wartet_hr';
            }

            // 4) Bereit?
            $bereitschaft = $applicant->versandBereitschaft();
            if ($bereitschaft->status === 'gesperrt') {
                $this->reservations->vermerkeVersuch($reservation, $bereitschaft->grund);
                return 'wartet_gesperrt';
            }
            if ($bereitschaft->status === 'unvollstaendig') {
                $this->reservations->vermerkeVersuch($reservation, $bereitschaft->grund);
                return 'wartet_unvollstaendig';
            }

            // 5) Vertragsbeginn nicht in der Vergangenheit (User-Entscheidung: stoppen)
            if ($reservation->vertragsbeginn !== null && $reservation->vertragsbeginn->lt(Carbon::today())) {
                $this->reservations->vermerkeVersuch($reservation, 'Vertragsbeginn ' . $reservation->vertragsbeginn->format('d.m.Y') . ' liegt in der Vergangenheit — bitte in der Nachbereitung anpassen.');
                return 'wartet_vertragsbeginn';
            }

            // 6) Zuschlag
            if ($applicant->zuschlag === null) {
                $this->reservations->vermerkeVersuch($reservation, 'Zuschlag fehlt.');
                return 'wartet_zuschlag';
            }

            // 7) Senden — gleiche Sequenz wie der Klick (Vertraege → Mitarbeiter → Portal).
            $defaultTemplate = RecContractTemplate::where('team_id', $applicant->team_id)->where('code', 'AV-default')->where('is_active', true)->first();
            $result = $this->dispatch->sendForApplicant($applicant, $reservation->reserved_by_user_id ? (int) $reservation->reserved_by_user_id : null, $reservation->contractFields(), $defaultTemplate);

            if ($result['status'] === 'sent') {
                $portal = ContractDispatchService::isPortalFailure($result) ? ' Portal-WA fehlgeschlagen: ' . ($result['message'] ?? '') : '';
                $this->reservations->abschliessen($reservation, 'Automatisch versendet.' . $portal);
                $this->log($applicant, 'contract_send_auto_sent', sprintf(
                    'Verträge + Portal-Link automatisch versendet (vorgemerkt am %s von %s, Vertragsbeginn %s).%s',
                    $reservation->reserved_at?->format('d.m.Y H:i'),
                    $reservation->reserved_by_name ?? ('Benutzer #' . ($reservation->reserved_by_user_id ?? '—')),
                    $reservation->vertragsbeginn?->format('d.m.Y') ?? '—',
                    $portal,
                ), ['reservation_id' => $reservation->id]);
                return 'versendet';
            }

            if ($result['status'] === 'skipped_already_sent') {
                $this->reservations->abschliessen($reservation, 'Verträge waren bereits versendet.');
                return 'bereits_versendet';
            }

            $this->reservations->vermerkeVersuch($reservation, 'Versand fehlgeschlagen: ' . ($result['message'] ?? 'unbekannt'));
            return 'fehler';
        });
    }

    private function log(RecApplicant $applicant, string $type, string $summary, array $details = []): void
    {
        try {
            RecAutoPilotLog::create(['rec_applicant_id' => $applicant->id, 'type' => $type, 'summary' => $summary, 'details' => $details]);
        } catch (\Throwable) {}
    }
}
```

- [ ] **Step 4: Job, Trigger, Binding**

```php
<?php
// src/Services/ReservedSendTrigger.php
namespace Platform\Recruiting\Services;

/** Stoesst den automatischen Versand einer Vormerkung an (Queue im Betrieb, Attrappe im Test). */
interface ReservedSendTrigger
{
    public function anstossen(int $applicantId, string $anlass): void;
}
```

```php
<?php
// src/Services/QueueReservedSendTrigger.php
namespace Platform\Recruiting\Services;

use Platform\Recruiting\Jobs\SendReservedContractsJob;

final class QueueReservedSendTrigger implements ReservedSendTrigger
{
    public function anstossen(int $applicantId, string $anlass): void
    {
        // afterCommit: der Beobachter feuert in der Transaktion des Phasenwechsels;
        // der Job soll den fertigen Stand lesen.
        SendReservedContractsJob::dispatch($applicantId, $anlass)->afterCommit();
    }
}
```

```php
<?php
// src/Jobs/SendReservedContractsJob.php
namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Services\ReservedContractSender;

/** Duenner Queue-Wrapper um ReservedContractSender (Spec Versand vormerken §4). */
class SendReservedContractsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(public int $applicantId, public string $anlass = '') {}

    public function handle(ReservedContractSender $sender): void
    {
        $ergebnis = $sender->versuchen($this->applicantId);
        Log::info('[SendReservedContractsJob] ' . $ergebnis, ['applicant_id' => $this->applicantId, 'anlass' => $this->anlass]);
    }
}
```

In `src/RecruitingServiceProvider.php` in `register()` (Methode suchen; falls es nur `boot()` gibt, `register()` anlegen):

```php
        $this->app->bind(
            \Platform\Recruiting\Services\ReservedSendTrigger::class,
            \Platform\Recruiting\Services\QueueReservedSendTrigger::class,
        );
```

- [ ] **Step 5: Tests grün + Gesamtsuite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → OK.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` → OK.

- [ ] **Step 6: Commit**

```bash
git add src/Services/ReservedContractSender.php src/Services/ReservedSendTrigger.php src/Services/QueueReservedSendTrigger.php src/Jobs/SendReservedContractsJob.php src/RecruitingServiceProvider.php tests/Integration/VersandVormerkenTest.php
git commit -m "feat(recruiting): automatischer Versand vorgemerkter Vertraege — Prueflauf mit sieben Stufen, Job, Trigger

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Auslöser und Aufräumen (Beobachter) + HR-Freigabe

**Files:**
- Create: `src/Observers/RecContractSendReservationObserver.php`
- Modify: `src/Services/HrDeskRoutingService.php::approveCase` (nach `$case->update([...])`)
- Modify: `src/Livewire/HrDesk/Index.php::toggleLegalStatusChecked` (nach `$legalStatus->save();`)
- Modify: `src/RecruitingServiceProvider.php` (Registrierung neben den anderen `::register()`-Aufrufen, ca. Zeile 182–195)
- Test: `tests/Integration/VersandVormerkenTest.php`

**Interfaces:**
- Produces: `RecContractSendReservationObserver::register(): void` (statisches Muster wie `RecInterviewBookingWaitlistObserver`), registriert:
  - `RecApplicant::saved`: (a) `wasChanged('rec_phase_id')` und neue Phase hat `creates_employee_on_completion` und offene Vormerkung → `app(ReservedSendTrigger::class)->anstossen($id, 'phase')`; (b) Aussteiger (`rejected_at`/`is_parked`/`is_active=false` geändert) → `zuruecknehmen`.
  - `RecInterviewBooking::saved`: `wasChanged('status')` und Status in `cancelled|no_show|rejected_on_site` und offene Vormerkung mit dieser `rec_interview_booking_id` (oder ohne Buchungsbezug) → `zuruecknehmen`.
  - `RecApplicantLegalStatus::saved`: `is_eu_citizen` wurde `false` und offene Vormerkung → `HrDeskRoutingService::routeIfNotAlreadyOpen(non_eu_citizen, Notiz mit Vormerkung)`; `legal_status_checked_at` wurde gesetzt und offene Vormerkung → Trigger `anstossen($id, 'rechtsstatus')`.
- Consumes: `ReservedSendTrigger` aus dem Container (`app()`), `ContractSendReservationService`.

- [ ] **Step 1: Tests**

```php
    /** @var list<array{0:int,1:string}> */
    private array $angestossen = [];

    private function beobachterAktiv(): void
    {
        $calls = &$this->angestossen;
        Container::getInstance()->instance(\Platform\Recruiting\Services\ReservedSendTrigger::class, new class($calls) implements \Platform\Recruiting\Services\ReservedSendTrigger {
            public function __construct(private array &$calls) {}
            public function anstossen(int $applicantId, string $anlass): void { $this->calls[] = [$applicantId, $anlass]; }
        });
        \Platform\Recruiting\Observers\RecContractSendReservationObserver::register();
    }

    private function beobachterAus(): void
    {
        RecApplicant::flushEventListeners();
        \Platform\Recruiting\Models\RecInterviewBooking::flushEventListeners();
        \Platform\Recruiting\Models\RecApplicantLegalStatus::flushEventListeners();
        Container::getInstance()->forgetInstance(\Platform\Recruiting\Services\ReservedSendTrigger::class);
        Model::clearBootedModels(); // creating-Hooks (uuid) der Models wieder registrieren
    }

    public function test_phasenwechsel_in_die_anlage_phase_stoesst_den_versand_an(): void
    {
        $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->rec_phase_id = self::PHASE_4;
            $a->save();
            $this->assertSame([[self::APPLICANT, 'phase']], $this->angestossen);

            // Wechsel in eine Phase OHNE Anlage: kein Anstoss
            $a->rec_phase_id = self::PHASE_3;
            $a->save();
            $this->assertCount(1, $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_ohne_vormerkung_kein_anstoss(): void
    {
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->rec_phase_id = self::PHASE_4;
            $a->save();
            $this->assertSame([], $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_absage_der_buchung_nimmt_die_vormerkung_zurueck(): void
    {
        $r = $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $b = \Platform\Recruiting\Models\RecInterviewBooking::find(self::BOOKING);
            $b->status = 'cancelled';
            $b->save();
            $this->assertNotNull($r->fresh()->cancelled_at);
            $this->assertStringContainsString('Buchung', $r->fresh()->cancel_reason);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_parken_nimmt_die_vormerkung_zurueck(): void
    {
        $r = $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->is_parked = true;
            $a->save();
            $this->assertNotNull($r->fresh()->cancelled_at);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_nicht_eu_nach_vormerkung_landet_auf_dem_hr_schreibtisch_und_freigabe_stoesst_an(): void
    {
        $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $legal = \Platform\Recruiting\Models\RecApplicantLegalStatus::create(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => null]);
            $legal->setEuCitizen(false, null);
            $legal->save();
            $fall = Capsule::table('rec_hr_desk_cases')->where('rec_applicant_id', self::APPLICANT)->where('reason', 'non_eu_citizen')->first();
            $this->assertNotNull($fall);
            $this->assertStringContainsString('vorgemerkt', (string) $fall->notes);

            $legal->legal_status_checked_at = Carbon::now();
            $legal->save();
            $this->assertSame([[self::APPLICANT, 'rechtsstatus']], $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_hr_freigabe_stoesst_den_versand_an(): void
    {
        $this->vormerkung();
        Capsule::table('rec_applicant_legal_statuses')->insert(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => 0, 'legal_status_checked_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);
        $fall = \Platform\Recruiting\Models\RecHrDeskCase::create(['uuid' => 'vv-case-1', 'rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'reason' => 'non_eu_citizen', 'status' => 'open', 'opened_at' => self::HEUTE]);
        $this->beobachterAktiv();
        try {
            (new \Platform\Recruiting\Services\HrDeskRoutingService())->approveCase($fall, 7, 'geprüft');
            $this->assertContains([self::APPLICANT, 'hr_freigabe'], $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }
```

Hinweis: `approveCase` ruft `$applicant->checkAutoPilotCompletion()`; im Capsule-Setup kann das weitere Hooks ziehen. Wirft es, den Fehler lesen und ggf. den Bewerber vorher auf `auto_pilot = false` setzen — der Trigger-Aufruf liegt VOR diesem Block.

- [ ] **Step 2: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → Errors „Observer … not found".

- [ ] **Step 3: Beobachter**

```php
<?php
// src/Observers/RecContractSendReservationObserver.php
namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantLegalStatus;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Services\ContractSendReservationService;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\ReservedSendTrigger;

/**
 * Ausloeser und Aufraeumen fuer vorgemerkte Versaende (Spec Versand vormerken
 * §4–§6). Haengt am Modell, damit JEDER Weg zaehlt: Autopilot, oeffentliches
 * Formular, Dashboard-Button, Werkzeuge. Jeder Body in safelyRun(): ein Fehler
 * hier darf keinen regulaeren Save kippen.
 */
class RecContractSendReservationObserver
{
    public static function register(): void
    {
        RecApplicant::saved(static function (RecApplicant $applicant): void {
            self::safelyRun(function () use ($applicant): void {
                // Erst die billige Frage (geaenderte Spalten), dann die Abfrage —
                // sonst laeuft bei JEDEM Bewerber-Save eine Query mit.
                $ausgestiegen = ($applicant->wasChanged('rejected_at') && $applicant->rejected_at)
                    || ($applicant->wasChanged('is_parked') && $applicant->is_parked)
                    || ($applicant->wasChanged('is_active') && !$applicant->is_active);
                $phaseGewechselt = $applicant->wasChanged('rec_phase_id') && $applicant->rec_phase_id;
                if (!$ausgestiegen && !$phaseGewechselt) {
                    return;
                }

                $vormerkung = $applicant->contractSendReservations()->offen()->first();
                if (!$vormerkung) {
                    return;
                }

                if ($ausgestiegen) {
                    app(ContractSendReservationService::class)->zuruecknehmen($vormerkung, 'Bewerber geparkt, abgelehnt oder deaktiviert.');
                    return;
                }

                if ($phaseGewechselt) {
                    $legtAn = ((RecPhase::find($applicant->rec_phase_id)?->completion_config ?? [])['creates_employee_on_completion'] ?? false) === true;
                    if ($legtAn) {
                        app(ReservedSendTrigger::class)->anstossen($applicant->id, 'phase');
                    }
                }
            }, 'rec_applicant.saved.reservation', $applicant->id);
        });

        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                if (!$booking->wasChanged('status') || !in_array($booking->status, ['cancelled', 'no_show', 'rejected_on_site'], true)) {
                    return;
                }
                $vormerkung = \Platform\Recruiting\Models\RecContractSendReservation::query()
                    ->where('rec_applicant_id', $booking->rec_applicant_id)->offen()
                    ->where(fn ($q) => $q->whereNull('rec_interview_booking_id')->orWhere('rec_interview_booking_id', $booking->id))
                    ->first();
                if ($vormerkung) {
                    app(ContractSendReservationService::class)->zuruecknehmen($vormerkung, 'Buchung auf „' . $booking->status_label . '“ gesetzt.');
                }
            }, 'rec_interview_booking.saved.reservation', $booking->id);
        });

        RecApplicantLegalStatus::saved(static function (RecApplicantLegalStatus $legal): void {
            self::safelyRun(function () use ($legal): void {
                $euNein = $legal->wasChanged('is_eu_citizen') && $legal->is_eu_citizen === false;
                $geprueft = $legal->wasChanged('legal_status_checked_at') && $legal->legal_status_checked_at !== null;
                if (!$euNein && !$geprueft) {
                    return;
                }
                $applicant = RecApplicant::find($legal->rec_applicant_id);
                $vormerkung = $applicant?->contractSendReservations()->offen()->first();
                if (!$applicant || !$vormerkung) {
                    return;
                }

                if ($euNein) {
                    app(HrDeskRoutingService::class)->routeIfNotAlreadyOpen($applicant, RecHrDeskCase::REASON_NON_EU_CITIZEN, null, sprintf(
                        'Versand vorgemerkt am %s (Vertragsbeginn %s) — nach Rechtsstatus-Prüfung und Freigabe geht er automatisch raus.',
                        $vormerkung->reserved_at?->format('d.m.Y H:i'),
                        $vormerkung->vertragsbeginn?->format('d.m.Y') ?? '—',
                    ));
                }

                if ($geprueft) {
                    app(ReservedSendTrigger::class)->anstossen($applicant->id, 'rechtsstatus');
                }
            }, 'rec_applicant_legal_status.saved.reservation', $legal->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Vormerkungs-Observer Fehler [{$context}#{$id}]: " . $e->getMessage());
        }
    }
}
```

Hinweis zu `setEuCitizen(false)`: die Methode speichert und ruft danach `HrDeskRoutingService::evaluateAndRoute`, das Nicht-EU seit der Nach-Schulung-Umstellung NICHT mehr routet (nur Auto-Close bei EU=ja). Der Beobachter oben feuert IM Save davor und legt den Fall mit der Vormerkungs-Notiz an; `evaluateAndRoute` lässt ihn stehen.

In `HrDeskRoutingService::approveCase` direkt NACH `$case->update([...]);`:

```php
        // Spec Versand vormerken §4 (b): Freigabe loest den vorgemerkten Versand aus.
        if ($applicant && $applicant->contractSendReservations()->offen()->exists()) {
            app(\Platform\Recruiting\Services\ReservedSendTrigger::class)->anstossen($applicant->id, 'hr_freigabe');
        }
```

`toggleLegalStatusChecked` braucht keinen eigenen Aufruf — der Beobachter auf `RecApplicantLegalStatus::saved` deckt ihn ab.

In `RecruitingServiceProvider.php` neben `RecInterviewBookingWaitlistObserver::register();`:

```php
        \Platform\Recruiting\Observers\RecContractSendReservationObserver::register();
```

- [ ] **Step 4: Tests grün + Gesamtsuite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VersandVormerkenTest` → OK.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` → OK (achte auf die Reihenfolge-Falle aus phpunit.xml: die Klasse setzt in `tearDownAfterClass` den Dispatcher zurück und leert den Boot-Cache).

- [ ] **Step 5: Commit**

```bash
git add src/Observers/RecContractSendReservationObserver.php src/Services/HrDeskRoutingService.php src/RecruitingServiceProvider.php tests/Integration/VersandVormerkenTest.php
git commit -m "feat(recruiting): Vormerkung — Ausloeser bei Phasenwechsel/Rechtsstatus/HR-Freigabe, Aufraeumen bei Absage/Parken/Ablehnung

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Anzeige — Spalte „Versand", Aktionen, Bewerberseite

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (zwei neue Aktionen)
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Nachbereitungstabelle: neue Spalte nach „Vertragsstatus")
- Modify: `resources/views/livewire/applicant/show.blade.php` (Kasten „Stelle & Phase")
- Modify: `src/Livewire/Applicant/Show.php` (Eager-Load `contractSendReservations` in `mount()`)

**Interfaces:**
- Produces: `InterviewBookings\Index::erinnernZurVervollstaendigung(int $applicantId): void` (force=true, Flash), `InterviewBookings\Index::vormerkungZuruecknehmen(int $applicantId): void`.
- Consumes: `versandZustaende` (Task 5), `ContractSendReservationService::erinnern/zuruecknehmen` (Task 4).

- [ ] **Step 1: Aktionen in `InterviewBookings\Index`**

Nach `moveBookings()` einfügen:

```php
    /** Erinnerung zur Vervollständigung von Hand — ohne Drossel (bewusster Klick). */
    public function erinnernZurVervollstaendigung(int $applicantId): void
    {
        $booking = $this->bookings->first(fn ($b) => (int) $b->applicant?->id === $applicantId);
        if (!$booking) {
            return;
        }
        $vormerkung = $booking->applicant->offeneVersandVormerkung();
        $dienst = app(\Platform\Recruiting\Services\ContractSendReservationService::class);

        $ok = $vormerkung
            ? $dienst->erinnern($vormerkung, force: true)
            : ($booking->applicant->sendPhaseOnboardingReminder()['ok'] ?? false);

        session()->flash($ok ? 'success' : 'error', $ok ? 'Erinnerung gesendet.' : 'Erinnerung konnte nicht gesendet werden — Details im Verlauf des Bewerbers.');
        unset($this->bookings, $this->versandZustaende);
    }

    public function vormerkungZuruecknehmen(int $applicantId): void
    {
        $booking = $this->bookings->first(fn ($b) => (int) $b->applicant?->id === $applicantId);
        $vormerkung = $booking?->applicant->offeneVersandVormerkung();
        if (!$vormerkung) {
            return;
        }
        app(\Platform\Recruiting\Services\ContractSendReservationService::class)->zuruecknehmen($vormerkung, 'Von Hand zurückgenommen.', (int) auth()->id());
        session()->flash('success', 'Vormerkung zurückgenommen.');
        unset($this->bookings, $this->versandZustaende, $this->bulkSendState);
    }
```

- [ ] **Step 2: Spalte in der Nachbereitungstabelle**

Kopfzeile: nach `<th class="px-4 py-3">Vertragsstatus</th>` → `<th class="px-4 py-3">Versand</th>`. In der Zeile direkt nach der Vertragsstatus-Zelle (die mit dem „Klärung an HR"-Button endet, `</td>`) einfügen:

```blade
                                        <td class="px-4 py-3">
                                            @php $vz = $applicant ? ($versandZustaende[$applicant->id] ?? null) : null; @endphp
                                            @if(!$vz)
                                                <span class="text-xs text-[var(--ui-muted)]">—</span>
                                            @elseif($vz['code'] === 'versendet')
                                                <div class="text-xs text-emerald-700">{{ $vz['text'] }}</div>
                                                @if($vz['detail'])<div class="text-[10px] text-[var(--ui-muted)]">{{ $vz['detail'] }}</div>@endif
                                            @elseif($vz['code'] === 'gesperrt')
                                                <div class="text-xs text-red-700" title="{{ $vz['text'] }}">Gesperrt</div>
                                                <div class="text-[10px] text-red-700 max-w-[220px] leading-snug">{{ \Illuminate\Support\Str::limit(substr($vz['text'], 10), 120) }}</div>
                                            @elseif($vz['code'] === 'vorgemerkt' || $vz['code'] === 'wartet')
                                                <div class="text-xs text-amber-800 font-medium">{{ $vz['text'] }}</div>
                                                <div class="text-[10px] text-amber-800 max-w-[220px] leading-snug">{{ $vz['detail'] }}</div>
                                                <div class="mt-1.5 flex gap-1">
                                                    <x-ui-button variant="secondary-outline" size="xs" wire:click="erinnernZurVervollstaendigung({{ $applicant->id }})" wire:confirm="Erinnerung jetzt senden?">Erinnern</x-ui-button>
                                                    <x-ui-button variant="danger-outline" size="xs" wire:click="vormerkungZuruecknehmen({{ $applicant->id }})" wire:confirm="Vormerkung zurücknehmen?">Zurücknehmen</x-ui-button>
                                                </div>
                                            @elseif($vz['code'] === 'unvollstaendig')
                                                <div class="text-xs text-amber-800">{{ $vz['text'] }}</div>
                                                <div class="text-[10px] text-[var(--ui-muted)] max-w-[220px] leading-snug">Beim Versand wird vorgemerkt und erinnert.</div>
                                                <div class="mt-1.5">
                                                    <x-ui-button variant="secondary-outline" size="xs" wire:click="erinnernZurVervollstaendigung({{ $applicant->id }})" wire:confirm="Erinnerung jetzt senden?">Erinnern</x-ui-button>
                                                </div>
                                            @else
                                                <span class="text-xs text-emerald-700">Bereit</span>
                                            @endif
                                        </td>
```

Die Leerzeile `<td colspan="…">` der Nachbereitungstabelle um 1 erhöhen.

- [ ] **Step 3: Bewerberseite**

`src/Livewire/Applicant/Show.php::mount()` Eager-Load ergänzen: `'contractSendReservations',`. In `show.blade.php`, im Kasten „Stelle & Phase" nach dem `@if($versandSperrgrund && !$phaseFremd) … @endif`-Block:

```blade
            @php $vormerkung = $applicant->offeneVersandVormerkung(); @endphp
            @if($vormerkung)
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Vertragsversand vorgemerkt am {{ $vormerkung->reserved_at->format('d.m.Y H:i') }}
                    (Vertragsbeginn {{ $vormerkung->vertragsbeginn?->format('d.m.Y') ?? '—' }}).
                    @if($vormerkung->last_attempt_result)
                        Wartet: {{ $vormerkung->last_attempt_result }}
                    @else
                        Geht automatisch raus, sobald die Daten vollständig sind.
                    @endif
                </div>
            @endif
```

- [ ] **Step 4: Blade-Checks + Gesamtsuite**

Run: `php tools/blade-check.php resources/views/livewire/interview-bookings/index.blade.php resources/views/livewire/applicant/show.blade.php` → 0 Funde.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` → OK.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php src/Livewire/Applicant/Show.php resources/views/livewire/interview-bookings/index.blade.php resources/views/livewire/applicant/show.blade.php
git commit -m "feat(recruiting): Spalte 'Versand' in der Nachbereitung, Erinnern/Zuruecknehmen, Vormerkung auf der Bewerberseite

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Spec-Nachtrag, Deploy-Notiz, Review vor dem Merge

**Files:**
- Modify: `docs/superpowers/specs/2026-10-06-versand-vormerken-design.md` (Abschnitt „Umsetzung" anhängen)

- [ ] **Step 1: Nachtrag an die Spec**

```markdown
## Umsetzung (Nachtrag)

- Regel: `RecApplicant::versandBereitschaft()` / `fehlendePflichtfelder()`; Riegel in `SendContractsService::send`.
- Vormerkung: `rec_contract_send_reservations`, `RecContractSendReservation`, `ContractSendReservationService`.
- Klick: `ContractSendRun` (Nachbereitung beide Sammelversände, HR-Schreibtisch).
- Automatik: `ReservedContractSender` (7 Stufen) ← `SendReservedContractsJob` ← `QueueReservedSendTrigger`.
- Auslöser/Aufräumen: `RecContractSendReservationObserver`, `HrDeskRoutingService::approveCase`.
- Deploy: `php artisan migrate`, `php artisan queue:restart`, `php artisan view:clear`.
- Nach Deploy prüfen: Gladbach 07.10. — Teilnehmer in Phase 3 erscheinen in der Spalte „Versand" als „Daten fehlen: …", nicht als „Bereit".
```

- [ ] **Step 2: Review-Agent (Pflicht laut Projektregel: alles Außensichtbare geht vor dem Merge durch ein Review)**

Auftrag an den Prüfer: `git diff main..HEAD`; Schwerpunkte: (1) kann der Job doppelt senden (Retry + zwei Trigger gleichzeitig)? (2) kann eine Vormerkung ohne Vertragsbeginn entstehen? (3) Beobachter-Rekursion: `zuruecknehmen` → Log → kein weiterer Save am Bewerber? (4) `approveCase` ruft `checkAutoPilotCompletion`, das die Phase wechselt → Beobachter stößt ZUSÄTZLICH an (zweiter Job) — unschädlich durch Idempotenz, aber bestätigen. (5) Blade: Spaltenzahl/colspan, `x-ui-*`-Attribute. Funde selbst nachprüfen, nicht blind übernehmen.

- [ ] **Step 3: Commit + Handoff**

```bash
git add docs/superpowers/specs/2026-10-06-versand-vormerken-design.md
git commit -m "docs(recruiting): Spec-Nachtrag Umsetzung Versand vormerken

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

Danach: ff-Merge auf main nur nach Freigabe durch den User, dann meingedeck-Bump (`composer update martin3r/platform-recruiting -d …/meingedeck --no-interaction --no-scripts`), Push; Deploy-Hinweis an den User: migrate + queue:restart + view:clear.
