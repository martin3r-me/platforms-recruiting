# Dokumente bereitstellen, zur Kenntnis nehmen, gegenzeichnen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR legt eine PDF ab (einzeln aus der Akte, an alle Eingebuchten einer Veranstaltung oder an eine gefilterte Gruppe), der Mitarbeiter bekommt eine WhatsApp, sieht das Dokument im neuen Portal als offenen Punkt, liest es und bestätigt oder unterschreibt; Zeitpunkte, Unterschrift und Nachweisblatt liegen in der Akte, HR sieht je Dokument „12 von 14".

**Architecture:** Ein Dokument (`rec_documents`, Datei auf dem Dispo-Anhang-Disk, Prüfsumme eingefroren) mit vielen Zustellungen (`rec_document_recipients`, je Anstellung, Zeitstempel gesehen/bestätigt/unterschrieben/zurückgezogen). Alle Regeln liegen in reinen Klassen unter `src/Support` (Kategorie, Status, Entdoppler, Zugriff, Upload-Regeln, Fortschritt) und dünnen Services unter `src/Services` (Speicher, Bereitstellen, Lesen, Unterschrift, WhatsApp-Hinweis). Offene Dokumente werden Punkte in `OffenePunkte`, damit Startbildschirm und Einsatz-Prüfung sie ohne neuen Code mitnehmen. Kein Schreiben auf `rec_employees`.

**Tech Stack:** Laravel 12 / Livewire 3 (Modul `platforms-recruiting`, Branch `feat/ma-konto`), Eloquent + Query Builder (observer-frei für Zeitstempel), Flysystem über `Storage::disk(config('recruiting.zas.inbound_disk'))`, DomPDF über `Barryvdh\DomPDF\Facade\Pdf`, Meta WhatsApp Cloud API über `WhatsAppMetaService::sendTemplate`, PHPUnit 11 (`../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`), Integrationstests mit handgebautem Container + Capsule/SQLite.

**Spec:** `docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md`

## Global Constraints

- Arbeitsverzeichnis ist der Worktree `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting-portal` (Branch `feat/ma-konto`). Kein Edit außerhalb dieses Moduls (kein Core, kein CRM, kein HCM, kein meingedeck).
- Testlauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (vom Worktree aus). Es gibt kein eigenes `vendor/`, kein testbench, kein `Livewire::test`. Blade-Dateien prüft `php tools/blade-check.php <datei>`, nicht `php -l`.
- Nie auf `rec_employees` schreiben (kein `$employee->update()`, kein `save()`): jede Eloquent-Schreibung setzt den ZAS-Export-Marker (Vorfall 02.09.2026). Zeitstempel der neuen Tabellen über `DB::table(...)->update(...)`.
- Kein neues Feld im ZAS-Export, keine Änderung an `rec_contracts`, `ContractSigning`, Zertifikaten, Nachweisen.
- Dateien: nur PDF, höchstens 20 MB (`20 * 1024 * 1024` Bytes), Magic-Bytes `%PDF-` am Anfang, Endung `.pdf`.
- Pfad der Datei: `recruiting/dokumente/{team_id}/{uuid}.pdf` auf dem Disk `config('recruiting.zas.inbound_disk', 'local')`.
- Die Datei ist nach dem Bereitstellen unveränderlich. Es gibt keine Methode „Datei ersetzen".
- Erster Zeitpunkt gewinnt: `first_viewed_at`, `acknowledged_at`, `signed_at` werden nie überschrieben.
- Zurückziehen nur solange `signed_at IS NULL`.
- Kodes der Kategorien (englisch, wie Spec §2.1): `contract`, `instruction`, `form`, `payslip`, `certificate`, `other`. Aktionen: `none`, `acknowledge`, `sign`.
- Code eines offenen Dokuments in `OffenePunkte`: `dokument:<recipient_id>` mit `ko = false`. `ProofTypes::istKo()`/`label()` werden dafür nie gefragt.
- WhatsApp-Vorlage über die Team-Einstellung `document_wa_template_id` (Einstellungs-Fenster → Kommunikation), aufgelöst über `HoldingTemplateSender::resolveTarget()` wie Zertifikat und Fristenlauf. Keine .env, keine Konfigurationsdatei (Spec §4 nennt den Schlüssel bereits; §12 wird in Task 16 nachgezogen).
- Wer `portal_v2_since IS NULL` hat (altes Portal), bekommt keine Nachricht: Status `altes_portal`.
- Die WhatsApps gehen NIE im Klick raus, sondern über den Job `DokumentHinweiseVersenden` (Queue); Bereitstellen legt nur Zeilen an. Deploy braucht deshalb `queue:restart`.
- Die Einsatz-Prüfung lässt Dokumente aus, die in den letzten `EinsatzBezug::PAUSE_TAGE` (7) Tagen ihre eigene WhatsApp bekommen haben (`OffenePunkte::fuerTrigger()`); das Portal (`fuer()`) zeigt sie trotzdem.
- Blade-Hausregeln: kein Inline-`@if` in `x-ui-*`-Attributen, Zweige vorher in `@php` berechnen, Direktiven nie an Wortzeichen kleben, `@php(...)` ohne `@endphp` vermeiden.
- Deutsche Bezeichner in neuem Code (Klassen, Methoden, Tests) wie im Branch üblich; Spaltennamen englisch wie die Spec.
- Commits: `git add <dateien>` + `git commit -m "<typ>(recruiting): <Zusammenfassung>" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"`. Kein Push, kein Merge, kein Bump durch die Tasks.

## Review Focus

1. **Datei heißt `.pdf`, ist aber keine PDF** (umbenanntes JPG, leere Datei): muss serverseitig abgelehnt werden, nichts gespeichert. Test in Task 3 (`DokumentUploadRegelnTest::test_umbenanntes_jpg_wird_abgelehnt`).
2. **Gruppenauswahl enthält eine Anstellung eines anderen Teams** (manipulierte ID im Request): darf keine Zustellung bekommen. Test in Task 6 (`test_fremdes_team_wird_stumm_uebersprungen`).
3. **Empfänger ohne neues Portal** (`portal_v2_since` leer): Zeile entsteht, keine Nachricht, Status `altes_portal`, Erneut-senden nach Umstellung funktioniert. Test in Task 5 (`test_altes_portal_bekommt_keine_nachricht`) und Task 7 (`test_erneut_senden_nach_umstellung`).
4. **Unterschrift ohne Bild** (leerer String, kein `data:image/png;base64,`-Anfang): muss abgelehnt werden, `signed_at` bleibt leer. Test in Task 9 (`test_leere_unterschrift_wird_abgelehnt`).
5. **Zurückgezogene Zustellung und „Erneut senden"**: darf nicht senden. Test in Task 7 (`test_erneut_senden_auf_zurueckgezogen_bricht_ab`).

---

## Dateistruktur

**Neu (Datenbank/Modelle)**
- `database/migrations/2026_10_09_000001_create_rec_documents_table.php` — Dokument.
- `database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php` — Zustellung je Anstellung.
- `src/Models/RecDocument.php`, `src/Models/RecDocumentRecipient.php` — Eloquent, nur Lesen + `create()`; Zeitstempel schreibt der Query Builder.

**Neu (reine Regeln, `src/Support`)**
- `DokumentKategorie.php` — Kodes, Labels, Default-Aktion.
- `DokumentStatus.php` — Statusableitung aus Zeitstempeln.
- `DokumentUploadRegeln.php` — PDF-Prüfung (Magic Bytes, Größe, Endung).
- `EmpfaengerEntdoppler.php` — Anstellungen → Personen.
- `DokumentZugriff.php` — 200/403/404 für den Download.
- `DokumentFortschritt.php` — „12 von 14".
- `DokumentNachweisDaten.php` — Daten fürs Nachweisblatt.

**Neu (Services, `src/Services`)**
- `DokumentSpeicher.php` — Datei ablegen, lesen, Prüfsumme.
- `DokumentService.php` — bereitstellen, Hinweise versenden, erneut senden, zurückziehen.
- `../Jobs/DokumentHinweiseVersenden.php` — Hintergrundversand je Dokument (Queue).
- `DokumentLeser.php` — Portal-Sicht über den Personen-Scope, offene Punkte.
- `DokumentUnterschrift.php` — öffnen, bestätigen, unterschreiben (Hash-Prüfung).
- `DokumentEmpfaengerSuche.php` — Kandidaten für die Seite Dokumente.
- `EingebuchteFuerDokument.php` — Eingebuchte einer Veranstaltung.
- `Comms/DokumentHinweisSender.php` — die WhatsApp.

**Neu (HTTP/Views)**
- `src/Http/Controllers/DokumentDownloadController.php` — Portal-Download (Sitzung + Scope).
- `src/Http/Controllers/DokumentHrController.php` — HR: Datei + Nachweisblatt.
- `resources/views/pdf/dokument-nachweis.blade.php`.
- `src/Livewire/Employees/Documents.php` + `resources/views/livewire/employees/documents.blade.php` — Seite Dokumente.

**Geändert**
- `src/Models/RecApplicantSettings.php` — Default `document_wa_template_id`.
- `resources/views/livewire/applicant/applicant-settings-modal.blade.php` — Auswahlfeld der Vorlage.
- `routes/public.php`, `routes/web.php` — neue Routen.
- `src/Services/OffenePunkte.php` — zweite Quelle, `fuerTrigger()` ohne frisch gemeldete Dokumente.
- `src/Console/Commands/EinsatzPruefung.php` — eine Zeile: `fuer()` → `fuerTrigger()`.
- `src/Livewire/Public/PortalShell.php` + `resources/views/livewire/public/portal-shell.blade.php` — Dokumente-Block, Blatt, Start-Klick.
- `src/Livewire/Employees/Show.php` + `resources/views/livewire/employees/show.blade.php` — Kurzform + Liste.
- `src/Livewire/Dispo/Events/Show.php` + `resources/views/livewire/dispo/events/show.blade.php` — Knopf + Modal + Zeile.
- `resources/views/livewire/sidebar.blade.php` — Eintrag „Dokumente".
- `docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` — §4 Vorlagenquelle.

**Tests**
- `tests/Unit/Dokumente/DokumentKategorieTest.php`, `DokumentStatusTest.php`, `DokumentUploadRegelnTest.php`, `EmpfaengerEntdopplerTest.php`, `DokumentZugriffTest.php`, `DokumentFortschrittTest.php`, `DokumentNachweisDatenTest.php`.
- `tests/Integration/DokumentSchemaTest.php`, `DokumentSpeicherTest.php`, `DokumentServiceTest.php`, `DokumentHinweisSenderTest.php`, `DokumentLeserTest.php`, `DokumentUnterschriftTest.php`, `OffenePunkteDokumenteTest.php`, `DokumentEmpfaengerSucheTest.php`, `EingebuchteFuerDokumentTest.php`, `PortalShellMitarbeiterDokumenteTest.php`.

---

### Task 1: Migrationen, Modelle, Schema-Test

**Files:**
- Create: `database/migrations/2026_10_09_000001_create_rec_documents_table.php`
- Create: `database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php`
- Create: `src/Models/RecDocument.php`
- Create: `src/Models/RecDocumentRecipient.php`
- Test: `tests/Integration/DokumentSchemaTest.php`

**Interfaces:**
- Produces: Tabellen `rec_documents`, `rec_document_recipients` (Spalten unten); Modelle `RecDocument` (Relationen `recipients()`, `event()`), `RecDocumentRecipient` (Relationen `document()`, `employee()`), beide mit `uuid` (UuidV7 beim Anlegen), `RecDocument` mit SoftDeletes.

- [ ] **Step 1: Schema-Test schreiben**

`tests/Integration/DokumentSchemaTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Die Welt kommt aus den Migrationen (Muster TriggerStateSchemaTest): ein
 * handgebautes Schema koennte eine Spalte vergessen, und SQLite macht aus einem
 * fehlenden Spaltennamen kein Fehler, sondern ein String-Literal.
 */
final class DokumentSchemaTest extends TestCase
{
    private const DOKUMENTE  = 'database/migrations/2026_10_09_000001_create_rec_documents_table.php';
    private const EMPFAENGER = 'database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php';

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        (require dirname(__DIR__, 2) . '/' . self::DOKUMENTE)->up();
        (require dirname(__DIR__, 2) . '/' . self::EMPFAENGER)->up();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_dokumente_tabelle_hat_alle_spalten(): void
    {
        foreach (['id', 'team_id', 'uuid', 'title', 'category', 'action', 'disk', 'stored_path',
                  'original_filename', 'file_sha256', 'file_size', 'rec_dispo_event_id',
                  'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'] as $spalte) {
            $this->assertTrue(Schema::hasColumn('rec_documents', $spalte), "Spalte fehlt: {$spalte}");
        }
    }

    public function test_empfaenger_tabelle_hat_alle_spalten(): void
    {
        foreach (['id', 'team_id', 'uuid', 'rec_document_id', 'rec_employee_id', 'person_key',
                  'notified_at', 'notify_error', 'first_viewed_at', 'acknowledged_at', 'signed_at',
                  'signature_data', 'withdrawn_at', 'created_at', 'updated_at'] as $spalte) {
            $this->assertTrue(Schema::hasColumn('rec_document_recipients', $spalte), "Spalte fehlt: {$spalte}");
        }
    }

    public function test_eine_anstellung_bekommt_ein_dokument_nur_einmal(): void
    {
        $docId = Capsule::table('rec_documents')->insertGetId([
            'team_id' => 1, 'uuid' => 'd-1', 'title' => 'T', 'category' => 'other', 'action' => 'none',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
            'created_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00',
        ]);
        $zeile = ['team_id' => 1, 'uuid' => 'r-1', 'rec_document_id' => $docId, 'rec_employee_id' => 7,
                  'created_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00'];
        Capsule::table('rec_document_recipients')->insert($zeile);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Capsule::table('rec_document_recipients')->insert(array_merge($zeile, ['uuid' => 'r-2']));
    }

    public function test_down_raeumt_beide_tabellen(): void
    {
        (require dirname(__DIR__, 2) . '/' . self::EMPFAENGER)->down();
        (require dirname(__DIR__, 2) . '/' . self::DOKUMENTE)->down();
        $this->assertFalse(Schema::hasTable('rec_documents'));
        $this->assertFalse(Schema::hasTable('rec_document_recipients'));
    }
}
```

- [ ] **Step 2: Test laufen lassen, er muss rot sein**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentSchemaTest`
Expected: FAIL, `Failed opening required ... 2026_10_09_000001_create_rec_documents_table.php`.

- [ ] **Step 3: Migrationen schreiben**

`database/migrations/2026_10_09_000001_create_rec_documents_table.php`:

```php
<?php
// database/migrations/2026_10_09_000001_create_rec_documents_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumente, die HR Mitarbeitern bereitstellt (Spec 2026-10-08, §2.1): die
 * PDF liegt auf dem Dispo-Anhang-Disk, hier stehen Titel, Kategorie, die
 * verlangte Aktion und die eingefrorene Pruefsumme. Ein Dokument, viele
 * Zustellungen (rec_document_recipients). Die Datei ist nach dem Anlegen
 * unveraenderlich — es gibt keine "Datei ersetzen"-Aktion.
 *
 * dateTime statt timestamp: unter explicit_defaults_for_timestamp=OFF bekaeme
 * die erste NOT-NULL-timestamp-Spalte ON UPDATE CURRENT_TIMESTAMP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->uuid('uuid')->unique();
            $table->string('title', 255);
            $table->string('category', 30);
            $table->string('action', 20);
            $table->string('disk', 50);
            $table->string('stored_path', 500);
            $table->string('original_filename', 255);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('rec_dispo_event_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_documents');
    }
};
```

`database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php`:

```php
<?php
// database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Zustellung je Anstellung (Spec 2026-10-08, §2.2). rec_employee_id ohne
 * DB-Fremdschluessel wie rec_contracts.rec_employee_id. person_key ist eine
 * Kopie zum Entdoppeln und fuer die Portal-Sicht ueber beide Anstellungen.
 *
 * Status wird aus den Zeitstempeln abgeleitet (DokumentStatus), es gibt keine
 * Status-Spalte. Zurueckziehen ist ein Zeitstempel, kein Loeschen: die Zeile
 * bleibt im Ueberblick als "zurueckgezogen" sichtbar. Die uuid ist die
 * oeffentliche Kennung des Portal-Downloads (eine Zustellung, ein Mensch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_document_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('rec_document_id')->index();
            $table->unsignedBigInteger('rec_employee_id')->index();
            $table->string('person_key', 64)->nullable()->index();
            $table->dateTime('notified_at')->nullable();
            $table->string('notify_error', 120)->nullable();
            $table->dateTime('first_viewed_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->text('signature_data')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['rec_document_id', 'rec_employee_id'], 'rec_doc_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_document_recipients');
    }
};
```

- [ ] **Step 4: Modelle schreiben**

`src/Models/RecDocument.php`:

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein bereitgestelltes Dokument (Spec 2026-10-08, §2.1). Datei-Lifecycle
 * laeuft ausschliesslich ueber DokumentSpeicher/DokumentService — das Model
 * hat bewusst keinen Storage-Hook (Integrationstests ohne Filesystem).
 * Zeitstempel der Zustellungen schreibt der Query Builder, nie dieses Model.
 */
class RecDocument extends Model
{
    use SoftDeletes;

    protected $table = 'rec_documents';

    protected $fillable = [
        'team_id', 'uuid', 'title', 'category', 'action',
        'disk', 'stored_path', 'original_filename', 'file_sha256', 'file_size',
        'rec_dispo_event_id', 'created_by_user_id',
    ];

    protected $casts = [
        'team_id'            => 'integer',
        'file_size'          => 'integer',
        'rec_dispo_event_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(RecDocumentRecipient::class, 'rec_document_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id');
    }
}
```

`src/Models/RecDocumentRecipient.php`:

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine Zustellung je Anstellung (Spec 2026-10-08, §2.2). Alle Zeitstempel
 * (notified_at, first_viewed_at, acknowledged_at, signed_at, withdrawn_at)
 * werden ueber DB::table geschrieben — erster Zeitpunkt gewinnt, und kein
 * Beobachter darf daran haengen.
 */
class RecDocumentRecipient extends Model
{
    protected $table = 'rec_document_recipients';

    protected $fillable = [
        'team_id', 'uuid', 'rec_document_id', 'rec_employee_id', 'person_key',
    ];

    protected $casts = [
        'team_id'         => 'integer',
        'rec_document_id' => 'integer',
        'rec_employee_id' => 'integer',
        'notified_at'     => 'datetime',
        'first_viewed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'signed_at'       => 'datetime',
        'withdrawn_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RecDocument::class, 'rec_document_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(RecEmployee::class, 'rec_employee_id');
    }

    /** Die Zeitstempel als Y-m-d H:i:s-Strings oder null — die Form, die DokumentStatus::fuer() liest. */
    public function zeitstempel(): array
    {
        return [
            'withdrawn_at'    => $this->withdrawn_at?->format('Y-m-d H:i:s'),
            'signed_at'       => $this->signed_at?->format('Y-m-d H:i:s'),
            'acknowledged_at' => $this->acknowledged_at?->format('Y-m-d H:i:s'),
            'first_viewed_at' => $this->first_viewed_at?->format('Y-m-d H:i:s'),
        ];
    }
}
```

- [ ] **Step 5: Test laufen lassen, grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentSchemaTest`
Expected: `OK (4 tests)`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_09_000001_create_rec_documents_table.php database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php src/Models/RecDocument.php src/Models/RecDocumentRecipient.php tests/Integration/DokumentSchemaTest.php
git commit -m "feat(recruiting): Tabellen und Modelle fuer Dokumente und Zustellungen" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Kategorie und Status (reine Regeln)

**Files:**
- Create: `src/Support/DokumentKategorie.php`
- Create: `src/Support/DokumentStatus.php`
- Test: `tests/Unit/Dokumente/DokumentKategorieTest.php`, `tests/Unit/Dokumente/DokumentStatusTest.php`

**Interfaces:**
- Produces:
  - `DokumentKategorie::codes(): list<string>`, `::labels(): array<string,string>`, `::label(string $code): string`, `::exists(string $code): bool`, `::defaultAktion(string $code): string` (wirft `InvalidArgumentException` bei unbekanntem Code), `::aktionen(): array<string,string>` (Code → Label), `::aktionExists(string): bool`, `::brauchtHandlung(string $aktion): bool`. Konstanten `CONTRACT`, `INSTRUCTION`, `FORM`, `PAYSLIP`, `CERTIFICATE`, `OTHER`, `AKTION_NONE = 'none'`, `AKTION_ACKNOWLEDGE = 'acknowledge'`, `AKTION_SIGN = 'sign'`.
  - `DokumentStatus::fuer(array $zeitstempel, string $aktion): string` mit Konstanten `ZURUECKGEZOGEN`, `UNTERSCHRIEBEN`, `BESTAETIGT`, `GESEHEN`, `OFFEN`, `ABGELEGT`; `::istErledigt(array, string): bool`; `::label(string): string`. `$zeitstempel` ist `array{withdrawn_at:?string, signed_at:?string, acknowledged_at:?string, first_viewed_at:?string}` (die Form von `RecDocumentRecipient::zeitstempel()`).

- [ ] **Step 1: Tests schreiben**

`tests/Unit/Dokumente/DokumentKategorieTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentKategorie;

final class DokumentKategorieTest extends TestCase
{
    public function test_sechs_kategorien_mit_label(): void
    {
        $this->assertSame(['contract', 'instruction', 'form', 'payslip', 'certificate', 'other'], DokumentKategorie::codes());
        $this->assertSame('Vertrag / Zusatzvereinbarung', DokumentKategorie::label('contract'));
        $this->assertSame('Belehrung / Unterweisung', DokumentKategorie::label('instruction'));
        $this->assertCount(6, DokumentKategorie::labels());
    }

    public function test_default_aktion_je_kategorie(): void
    {
        $this->assertSame('sign', DokumentKategorie::defaultAktion('contract'));
        $this->assertSame('acknowledge', DokumentKategorie::defaultAktion('instruction'));
        $this->assertSame('acknowledge', DokumentKategorie::defaultAktion('form'));
        $this->assertSame('none', DokumentKategorie::defaultAktion('payslip'));
        $this->assertSame('none', DokumentKategorie::defaultAktion('certificate'));
        $this->assertSame('none', DokumentKategorie::defaultAktion('other'));
    }

    public function test_unbekannte_kategorie_wirft(): void
    {
        $this->assertFalse(DokumentKategorie::exists('lohn'));
        $this->expectException(\InvalidArgumentException::class);
        DokumentKategorie::defaultAktion('lohn');
    }

    public function test_aktionen_und_handlungsbedarf(): void
    {
        $this->assertSame(['none', 'acknowledge', 'sign'], array_keys(DokumentKategorie::aktionen()));
        $this->assertTrue(DokumentKategorie::aktionExists('sign'));
        $this->assertFalse(DokumentKategorie::aktionExists('read'));
        $this->assertFalse(DokumentKategorie::brauchtHandlung('none'));
        $this->assertTrue(DokumentKategorie::brauchtHandlung('acknowledge'));
        $this->assertTrue(DokumentKategorie::brauchtHandlung('sign'));
    }
}
```

`tests/Unit/Dokumente/DokumentStatusTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentStatus;

final class DokumentStatusTest extends TestCase
{
    private function z(array $set = []): array
    {
        return array_merge(['withdrawn_at' => null, 'signed_at' => null, 'acknowledged_at' => null, 'first_viewed_at' => null], $set);
    }

    public function test_reihenfolge_zurueckgezogen_schlaegt_alles(): void
    {
        $this->assertSame('zurueckgezogen', DokumentStatus::fuer($this->z(['withdrawn_at' => '2026-10-09 10:00:00', 'signed_at' => '2026-10-08 10:00:00']), 'sign'));
    }

    public function test_sign_stufen(): void
    {
        $this->assertSame('offen', DokumentStatus::fuer($this->z(), 'sign'));
        $this->assertSame('gesehen', DokumentStatus::fuer($this->z(['first_viewed_at' => '2026-10-09 10:00:00']), 'sign'));
        $this->assertSame('bestaetigt', DokumentStatus::fuer($this->z(['first_viewed_at' => '2026-10-09 10:00:00', 'acknowledged_at' => '2026-10-09 10:01:00']), 'sign'));
        $this->assertSame('unterschrieben', DokumentStatus::fuer($this->z(['signed_at' => '2026-10-09 10:02:00']), 'sign'));
    }

    public function test_acknowledge_kennt_keine_unterschrift(): void
    {
        $this->assertSame('bestaetigt', DokumentStatus::fuer($this->z(['acknowledged_at' => '2026-10-09 10:01:00']), 'acknowledge'));
        $this->assertTrue(DokumentStatus::istErledigt($this->z(['acknowledged_at' => '2026-10-09 10:01:00']), 'acknowledge'));
        $this->assertFalse(DokumentStatus::istErledigt($this->z(['acknowledged_at' => '2026-10-09 10:01:00']), 'sign'));
    }

    public function test_none_ist_abgelegt_oder_gesehen(): void
    {
        $this->assertSame('abgelegt', DokumentStatus::fuer($this->z(), 'none'));
        $this->assertSame('gesehen', DokumentStatus::fuer($this->z(['first_viewed_at' => '2026-10-09 10:00:00']), 'none'));
        $this->assertTrue(DokumentStatus::istErledigt($this->z(), 'none'), 'nur ablegen verlangt nichts');
    }

    public function test_labels(): void
    {
        $this->assertSame('Unterschrieben', DokumentStatus::label('unterschrieben'));
        $this->assertSame('Zurückgezogen', DokumentStatus::label('zurueckgezogen'));
        $this->assertSame('unbekannt', DokumentStatus::label('unbekannt'));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentKategorieTest|DokumentStatusTest"`
Expected: FAIL, `Class "Platform\Recruiting\Support\DokumentKategorie" not found`.

- [ ] **Step 3: Klassen schreiben**

`src/Support/DokumentKategorie.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Kategorien und Aktionen eines bereitgestellten Dokuments (Spec 2026-10-08,
 * §2.1). Die Kategorie belegt die Aktion nur VOR, HR kann sie je Dokument
 * uebersteuern. Eine Stelle fuer Kodes und Labels — Blade und Services fragen
 * hier, nie eigene Listen.
 */
final class DokumentKategorie
{
    public const CONTRACT    = 'contract';
    public const INSTRUCTION = 'instruction';
    public const FORM        = 'form';
    public const PAYSLIP     = 'payslip';
    public const CERTIFICATE = 'certificate';
    public const OTHER       = 'other';

    public const AKTION_NONE        = 'none';
    public const AKTION_ACKNOWLEDGE = 'acknowledge';
    public const AKTION_SIGN        = 'sign';

    private const KATALOG = [
        self::CONTRACT    => ['label' => 'Vertrag / Zusatzvereinbarung', 'aktion' => self::AKTION_SIGN],
        self::INSTRUCTION => ['label' => 'Belehrung / Unterweisung',     'aktion' => self::AKTION_ACKNOWLEDGE],
        self::FORM        => ['label' => 'Formular',                     'aktion' => self::AKTION_ACKNOWLEDGE],
        self::PAYSLIP     => ['label' => 'Lohnabrechnung',               'aktion' => self::AKTION_NONE],
        self::CERTIFICATE => ['label' => 'Bescheinigung',                'aktion' => self::AKTION_NONE],
        self::OTHER       => ['label' => 'Sonstiges',                    'aktion' => self::AKTION_NONE],
    ];

    private const AKTIONEN = [
        self::AKTION_NONE        => 'Nur ablegen',
        self::AKTION_ACKNOWLEDGE => 'Zur Kenntnis bestätigen',
        self::AKTION_SIGN        => 'Unterschreiben',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::KATALOG);
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return array_map(static fn (array $k) => $k['label'], self::KATALOG);
    }

    public static function label(string $code): string
    {
        return self::KATALOG[$code]['label'] ?? $code;
    }

    public static function exists(string $code): bool
    {
        return isset(self::KATALOG[$code]);
    }

    public static function defaultAktion(string $code): string
    {
        if (!self::exists($code)) {
            throw new \InvalidArgumentException("Unbekannte Dokumentkategorie '{$code}'.");
        }

        return self::KATALOG[$code]['aktion'];
    }

    /** @return array<string,string> Code → Label */
    public static function aktionen(): array
    {
        return self::AKTIONEN;
    }

    public static function aktionExists(string $aktion): bool
    {
        return isset(self::AKTIONEN[$aktion]);
    }

    public static function aktionLabel(string $aktion): string
    {
        return self::AKTIONEN[$aktion] ?? $aktion;
    }

    /** Verlangt diese Aktion etwas vom Menschen? Nur dann gibt es Nachricht und offenen Punkt. */
    public static function brauchtHandlung(string $aktion): bool
    {
        return $aktion === self::AKTION_ACKNOWLEDGE || $aktion === self::AKTION_SIGN;
    }
}
```

`src/Support/DokumentStatus.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Status einer Zustellung, ABGELEITET aus Zeitstempeln (Spec 2026-10-08, §2.2).
 * Reihenfolge ist Absicht: zurueckgezogen > unterschrieben > bestaetigt >
 * gesehen > offen. Bei Aktion `none` gibt es nur abgelegt/gesehen. Der
 * Benachrichtigungsstand ist eine zweite Achse und kommt hier nicht vor.
 */
final class DokumentStatus
{
    public const ZURUECKGEZOGEN = 'zurueckgezogen';
    public const UNTERSCHRIEBEN = 'unterschrieben';
    public const BESTAETIGT     = 'bestaetigt';
    public const GESEHEN        = 'gesehen';
    public const OFFEN          = 'offen';
    public const ABGELEGT       = 'abgelegt';

    private const LABELS = [
        self::ZURUECKGEZOGEN => 'Zurückgezogen',
        self::UNTERSCHRIEBEN => 'Unterschrieben',
        self::BESTAETIGT     => 'Bestätigt',
        self::GESEHEN        => 'Gesehen',
        self::OFFEN          => 'Offen',
        self::ABGELEGT       => 'Abgelegt',
    ];

    /**
     * @param array{withdrawn_at:?string, signed_at:?string, acknowledged_at:?string, first_viewed_at:?string} $z
     */
    public static function fuer(array $z, string $aktion): string
    {
        if (($z['withdrawn_at'] ?? null) !== null) {
            return self::ZURUECKGEZOGEN;
        }
        if ($aktion === DokumentKategorie::AKTION_SIGN && ($z['signed_at'] ?? null) !== null) {
            return self::UNTERSCHRIEBEN;
        }
        if ($aktion !== DokumentKategorie::AKTION_NONE && ($z['acknowledged_at'] ?? null) !== null) {
            return self::BESTAETIGT;
        }
        if (($z['first_viewed_at'] ?? null) !== null) {
            return self::GESEHEN;
        }

        return $aktion === DokumentKategorie::AKTION_NONE ? self::ABGELEGT : self::OFFEN;
    }

    /** Erledigt heisst: das Verlangte ist passiert. Nur ablegen verlangt nichts. */
    public static function istErledigt(array $z, string $aktion): bool
    {
        if (($z['withdrawn_at'] ?? null) !== null) {
            return false;
        }

        return match ($aktion) {
            DokumentKategorie::AKTION_SIGN        => ($z['signed_at'] ?? null) !== null,
            DokumentKategorie::AKTION_ACKNOWLEDGE => ($z['acknowledged_at'] ?? null) !== null,
            default                               => true,
        };
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentKategorieTest|DokumentStatusTest"`
Expected: `OK (9 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Support/DokumentKategorie.php src/Support/DokumentStatus.php tests/Unit/Dokumente/DokumentKategorieTest.php tests/Unit/Dokumente/DokumentStatusTest.php
git commit -m "feat(recruiting): Dokumentkategorie und Statusableitung als reine Regeln" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 3: Upload-Regeln, Entdoppler, Zugriff, Fortschritt (reine Regeln)

**Files:**
- Create: `src/Support/DokumentUploadRegeln.php`
- Create: `src/Support/EmpfaengerEntdoppler.php`
- Create: `src/Support/DokumentZugriff.php`
- Create: `src/Support/DokumentFortschritt.php`
- Test: `tests/Unit/Dokumente/DokumentUploadRegelnTest.php`, `EmpfaengerEntdopplerTest.php`, `DokumentZugriffTest.php`, `DokumentFortschrittTest.php`

**Interfaces:**
- Produces:
  - `DokumentUploadRegeln::MAX_BYTES = 20971520`; `::pruefe(string $inhalt, string $originalName): ?string` (null = ok, sonst Fehlertext).
  - `EmpfaengerEntdoppler::aufPersonen(array $anstellungen): list<int>` — Eingabe `list<array{id:int, person_key:?string}>`, Ausgabe die behaltenen ids in Eingabereihenfolge der ersten Nennung, je `person_key` die kleinste id.
  - `DokumentZugriff::entscheide(bool $empfaengerGefunden, bool $sitzungGueltig, bool $portalGesperrt, bool $zurueckgezogen): int` → 200/403/404.
  - `DokumentFortschritt::fuer(array $zeitstempelListe, string $aktion): array{erledigt:int, gesamt:int, zurueckgezogen:int, text:string}` — `gesamt` zählt nur nicht zurückgezogene.

- [ ] **Step 1: Tests schreiben**

`tests/Unit/Dokumente/DokumentUploadRegelnTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentUploadRegeln;

final class DokumentUploadRegelnTest extends TestCase
{
    public function test_echte_pdf_geht_durch(): void
    {
        $this->assertNull(DokumentUploadRegeln::pruefe("%PDF-1.7\n%âãÏÓ\n1 0 obj", 'Verschwiegenheit.pdf'));
    }

    public function test_umbenanntes_jpg_wird_abgelehnt(): void
    {
        $jpg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 100);
        $this->assertSame('Die Datei ist keine PDF.', DokumentUploadRegeln::pruefe($jpg, 'foto.pdf'));
    }

    public function test_falsche_endung_wird_abgelehnt(): void
    {
        $this->assertSame('Nur PDF-Dateien sind erlaubt.', DokumentUploadRegeln::pruefe("%PDF-1.4", 'vertrag.docx'));
    }

    public function test_leere_datei_wird_abgelehnt(): void
    {
        $this->assertSame('Die Datei ist leer.', DokumentUploadRegeln::pruefe('', 'leer.pdf'));
    }

    public function test_zu_gross_wird_abgelehnt(): void
    {
        $inhalt = '%PDF-' . str_repeat('x', DokumentUploadRegeln::MAX_BYTES);
        $this->assertSame('Die Datei ist größer als 20 MB.', DokumentUploadRegeln::pruefe($inhalt, 'gross.pdf'));
    }

    public function test_endung_ist_nicht_case_sensitiv(): void
    {
        $this->assertNull(DokumentUploadRegeln::pruefe("%PDF-1.4", 'SCAN.PDF'));
    }
}
```

`tests/Unit/Dokumente/EmpfaengerEntdopplerTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmpfaengerEntdoppler;

final class EmpfaengerEntdopplerTest extends TestCase
{
    public function test_zwei_anstellungen_einer_person_werden_eine(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 223, 'person_key' => 'p-7'],
            ['id' => 160, 'person_key' => 'p-7'],
        ]);
        $this->assertSame([160], $ids, 'die kleinere id gewinnt, unabhaengig von der Reihenfolge');
    }

    public function test_ohne_person_key_bleibt_jede_anstellung(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 5, 'person_key' => null],
            ['id' => 6, 'person_key' => ''],
            ['id' => 7, 'person_key' => '   '],
        ]);
        $this->assertSame([5, 6, 7], $ids);
    }

    public function test_doppelte_ids_in_der_eingabe_zaehlen_einmal(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 9, 'person_key' => null],
            ['id' => 9, 'person_key' => null],
        ]);
        $this->assertSame([9], $ids);
    }

    public function test_reihenfolge_folgt_der_ersten_nennung(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 30, 'person_key' => 'b'],
            ['id' => 10, 'person_key' => 'a'],
            ['id' => 20, 'person_key' => 'b'],
        ]);
        $this->assertSame([20, 10], $ids);
    }
}
```

`tests/Unit/Dokumente/DokumentZugriffTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentZugriff;

/** Reihenfolge wie DispoAttachmentAccess: nie ein Existenz-Orakel. */
final class DokumentZugriffTest extends TestCase
{
    public function test_matrix(): void
    {
        // empfaengerGefunden, sitzungGueltig, portalGesperrt, zurueckgezogen → Code
        $faelle = [
            [false, true,  false, false, 404],
            [true,  false, false, false, 403],
            [true,  true,  true,  false, 403],
            [true,  true,  false, true,  404],
            [true,  true,  false, false, 200],
            [false, false, true,  true,  404],
        ];
        foreach ($faelle as [$e, $s, $g, $z, $erwartet]) {
            $this->assertSame($erwartet, DokumentZugriff::entscheide($e, $s, $g, $z), "Fall ($e,$s,$g,$z)");
        }
    }
}
```

`tests/Unit/Dokumente/DokumentFortschrittTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentFortschritt;

final class DokumentFortschrittTest extends TestCase
{
    private function z(array $set = []): array
    {
        return array_merge(['withdrawn_at' => null, 'signed_at' => null, 'acknowledged_at' => null, 'first_viewed_at' => null], $set);
    }

    public function test_zwoelf_von_vierzehn(): void
    {
        $liste = array_merge(
            array_fill(0, 12, $this->z(['signed_at' => '2026-10-09 10:00:00'])),
            array_fill(0, 2, $this->z()),
        );
        $f = DokumentFortschritt::fuer($liste, 'sign');
        $this->assertSame(12, $f['erledigt']);
        $this->assertSame(14, $f['gesamt']);
        $this->assertSame('12 von 14 unterschrieben', $f['text']);
    }

    public function test_zurueckgezogene_zaehlen_nicht_im_nenner(): void
    {
        $f = DokumentFortschritt::fuer([
            $this->z(['acknowledged_at' => '2026-10-09 10:00:00']),
            $this->z(['withdrawn_at' => '2026-10-09 11:00:00']),
        ], 'acknowledge');
        $this->assertSame(['erledigt' => 1, 'gesamt' => 1, 'zurueckgezogen' => 1, 'text' => '1 von 1 bestätigt'], $f);
    }

    public function test_none_zaehlt_gesehen(): void
    {
        $f = DokumentFortschritt::fuer([$this->z(['first_viewed_at' => '2026-10-09 10:00:00']), $this->z()], 'none');
        $this->assertSame('1 von 2 gesehen', $f['text']);
        $this->assertSame(1, $f['erledigt']);
    }
}
```

- [ ] **Step 2: Tests laufen lassen, rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentUploadRegelnTest|EmpfaengerEntdopplerTest|DokumentZugriffTest|DokumentFortschrittTest"`
Expected: FAIL, Klassen nicht gefunden.

- [ ] **Step 3: Klassen schreiben**

`src/Support/DokumentUploadRegeln.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Serverseitige Pruefung einer bereitzustellenden Datei (Spec §3.1, Schritt 1).
 * Prueft den INHALT, nicht nur den Namen: ein umbenanntes JPG heisst .pdf und
 * ist trotzdem keins. Reine Funktion, keine Abhaengigkeiten.
 */
final class DokumentUploadRegeln
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /** @return ?string null = in Ordnung, sonst Fehlertext fuer HR */
    public static function pruefe(string $inhalt, string $originalName): ?string
    {
        $endung = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($endung !== 'pdf') {
            return 'Nur PDF-Dateien sind erlaubt.';
        }
        if ($inhalt === '') {
            return 'Die Datei ist leer.';
        }
        if (strlen($inhalt) > self::MAX_BYTES) {
            return 'Die Datei ist größer als 20 MB.';
        }
        if (!str_starts_with($inhalt, '%PDF-')) {
            return 'Die Datei ist keine PDF.';
        }

        return null;
    }
}
```

`src/Support/EmpfaengerEntdoppler.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Anstellungen → Personen (Spec §3.1, Schritt 3). Wer bei RHEINGEDECK und MA
 * arbeitet, hat zwei Anstellungen mit gleichem person_key und bekommt ein
 * Dokument EINMAL: die Anstellung mit der kleineren id traegt die Zustellung.
 * Ohne person_key bleibt jede Anstellung ein Empfaenger — zwei Menschen ohne
 * Marker duerfen nicht verschmelzen (Muster SendProofReminders).
 */
final class EmpfaengerEntdoppler
{
    /**
     * @param  list<array{id:int, person_key:?string}> $anstellungen
     * @return list<int> behaltene ids, Reihenfolge der ersten Nennung
     */
    public static function aufPersonen(array $anstellungen): array
    {
        $ohneKey   = [];   // id => true
        $proPerson = [];   // key => kleinste id
        $reihe     = [];   // Schluessel in Reihenfolge der ersten Nennung

        foreach ($anstellungen as $a) {
            $id  = (int) $a['id'];
            $key = trim((string) ($a['person_key'] ?? ''));

            if ($key === '') {
                if (!isset($ohneKey[$id])) {
                    $ohneKey[$id] = true;
                    $reihe[] = ['art' => 'id', 'wert' => $id];
                }
                continue;
            }

            if (!isset($proPerson[$key])) {
                $proPerson[$key] = $id;
                $reihe[] = ['art' => 'key', 'wert' => $key];
            } elseif ($id < $proPerson[$key]) {
                $proPerson[$key] = $id;
            }
        }

        $out = [];
        foreach ($reihe as $r) {
            $out[] = $r['art'] === 'id' ? $r['wert'] : $proPerson[$r['wert']];
        }

        return $out;
    }
}
```

`src/Support/DokumentZugriff.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Zugriffsentscheidung fuer den Portal-Download eines Dokuments (Spec §5.3).
 * Reihenfolge wie DispoAttachmentAccess: unbekannte Zustellung → 404 (kein
 * Hinweis, ob es sie gibt); keine Sitzung → 403; Portalsperre → 403;
 * zurueckgezogen → 404; sonst 200.
 */
final class DokumentZugriff
{
    public static function entscheide(bool $empfaengerGefunden, bool $sitzungGueltig, bool $portalGesperrt, bool $zurueckgezogen): int
    {
        if (!$empfaengerGefunden) {
            return 404;
        }
        if (!$sitzungGueltig || $portalGesperrt) {
            return 403;
        }

        return $zurueckgezogen ? 404 : 200;
    }
}
```

`src/Support/DokumentFortschritt.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * "12 von 14" fuer den HR-Ueberblick (Spec §8). Zurueckgezogene Zustellungen
 * stehen nicht im Nenner, werden aber gezaehlt.
 */
final class DokumentFortschritt
{
    /**
     * @param  list<array{withdrawn_at:?string, signed_at:?string, acknowledged_at:?string, first_viewed_at:?string}> $zeitstempelListe
     * @return array{erledigt:int, gesamt:int, zurueckgezogen:int, text:string}
     */
    public static function fuer(array $zeitstempelListe, string $aktion): array
    {
        $erledigt = 0;
        $gesamt = 0;
        $zurueck = 0;

        foreach ($zeitstempelListe as $z) {
            if (($z['withdrawn_at'] ?? null) !== null) {
                $zurueck++;
                continue;
            }
            $gesamt++;
            $fertig = $aktion === DokumentKategorie::AKTION_NONE
                ? ($z['first_viewed_at'] ?? null) !== null
                : DokumentStatus::istErledigt($z, $aktion);
            if ($fertig) {
                $erledigt++;
            }
        }

        $wort = match ($aktion) {
            DokumentKategorie::AKTION_SIGN        => 'unterschrieben',
            DokumentKategorie::AKTION_ACKNOWLEDGE => 'bestätigt',
            default                               => 'gesehen',
        };

        return [
            'erledigt'       => $erledigt,
            'gesamt'         => $gesamt,
            'zurueckgezogen' => $zurueck,
            'text'           => "{$erledigt} von {$gesamt} {$wort}",
        ];
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentUploadRegelnTest|EmpfaengerEntdopplerTest|DokumentZugriffTest|DokumentFortschrittTest"`
Expected: `OK (14 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Support/DokumentUploadRegeln.php src/Support/EmpfaengerEntdoppler.php src/Support/DokumentZugriff.php src/Support/DokumentFortschritt.php tests/Unit/Dokumente/DokumentUploadRegelnTest.php tests/Unit/Dokumente/EmpfaengerEntdopplerTest.php tests/Unit/Dokumente/DokumentZugriffTest.php tests/Unit/Dokumente/DokumentFortschrittTest.php
git commit -m "feat(recruiting): Upload-Regeln, Entdoppler, Zugriff und Fortschritt fuer Dokumente" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: DokumentSpeicher (Datei ablegen, lesen, Prüfsumme)

**Files:**
- Create: `src/Services/DokumentSpeicher.php`
- Test: `tests/Integration/DokumentSpeicherTest.php`

**Interfaces:**
- Produces: `DokumentSpeicher::__construct(Filesystem $files, string $diskName)`, `::default(): self` (Disk aus `config('recruiting.zas.inbound_disk', 'local')`), `::ablegen(int $teamId, string $uuid, string $inhalt): array{disk:string, stored_path:string, sha256:string, size:int}`, `::inhalt(string $storedPath): ?string` (null wenn Datei fehlt), `::pruefsumme(string $storedPath): ?string`, `::diskName(): string`.

- [ ] **Step 1: Test schreiben**

`tests/Integration/DokumentSpeicherTest.php` (Muster `DispoAttachmentStoreTest`: echter lokaler Flysystem-Adapter auf einem Temp-Ordner):

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\DokumentSpeicher;

final class DokumentSpeicherTest extends TestCase
{
    private string $root;
    private DokumentSpeicher $speicher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/rec-dokumente-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $files = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]);
        $this->speicher = new DokumentSpeicher($files, 'test-local');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/recruiting/dokumente/*/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->root . '/recruiting/dokumente/3');
        @rmdir($this->root . '/recruiting/dokumente');
        @rmdir($this->root . '/recruiting');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_ablegen_schreibt_datei_und_pruefsumme(): void
    {
        $inhalt = "%PDF-1.4\nHallo";
        $e = $this->speicher->ablegen(3, 'abc-123', $inhalt);

        $this->assertSame('test-local', $e['disk']);
        $this->assertSame('recruiting/dokumente/3/abc-123.pdf', $e['stored_path']);
        $this->assertSame(hash('sha256', $inhalt), $e['sha256']);
        $this->assertSame(strlen($inhalt), $e['size']);
        $this->assertFileExists($this->root . '/' . $e['stored_path']);
        $this->assertSame($inhalt, $this->speicher->inhalt($e['stored_path']));
    }

    public function test_pruefsumme_erkennt_veraenderte_bytes(): void
    {
        $e = $this->speicher->ablegen(3, 'abc-124', "%PDF-1.4\nOriginal");
        file_put_contents($this->root . '/' . $e['stored_path'], "%PDF-1.4\nManipuliert");

        $this->assertNotSame($e['sha256'], $this->speicher->pruefsumme($e['stored_path']));
    }

    public function test_fehlende_datei_liefert_null(): void
    {
        $this->assertNull($this->speicher->inhalt('recruiting/dokumente/3/gibt-es-nicht.pdf'));
        $this->assertNull($this->speicher->pruefsumme('recruiting/dokumente/3/gibt-es-nicht.pdf'));
    }
}
```

- [ ] **Step 2: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentSpeicherTest`
Expected: FAIL, `Class ... DokumentSpeicher not found`.

- [ ] **Step 3: Klasse schreiben**

`src/Services/DokumentSpeicher.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Ablage der Dokument-PDFs (Spec §2.1, §3.1 Schritt 2). Eigener Speicher wie
 * die Dispo-Anhaenge (DispoAttachmentStore), KEIN ContextFile: die Auslieferung
 * an den Mitarbeiter braucht eine eigene Zugriffspruefung (DokumentZugriff),
 * die ContextFile nicht kennt. Die Pruefsumme entsteht aus den GESPEICHERTEN
 * Bytes, nicht aus dem Upload — so friert sie genau das ein, was der Mensch
 * spaeter vorgesetzt bekommt.
 */
class DokumentSpeicher
{
    public function __construct(private Filesystem $files, private string $diskName)
    {
    }

    public static function default(): self
    {
        $disk = (string) config('recruiting.zas.inbound_disk', 'local');

        return new self(Storage::disk($disk), $disk);
    }

    /** @return array{disk:string, stored_path:string, sha256:string, size:int} */
    public function ablegen(int $teamId, string $uuid, string $inhalt): array
    {
        $pfad = "recruiting/dokumente/{$teamId}/{$uuid}.pdf";
        $this->files->put($pfad, $inhalt);
        $gespeichert = (string) $this->files->get($pfad);

        return [
            'disk'        => $this->diskName,
            'stored_path' => $pfad,
            'sha256'      => hash('sha256', $gespeichert),
            'size'        => strlen($gespeichert),
        ];
    }

    public function inhalt(string $storedPath): ?string
    {
        if (!$this->files->exists($storedPath)) {
            return null;
        }

        return (string) $this->files->get($storedPath);
    }

    public function pruefsumme(string $storedPath): ?string
    {
        $inhalt = $this->inhalt($storedPath);

        return $inhalt === null ? null : hash('sha256', $inhalt);
    }

    public function diskName(): string
    {
        return $this->diskName;
    }
}
```

- [ ] **Step 4: Test grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentSpeicherTest`
Expected: `OK (3 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/DokumentSpeicher.php tests/Integration/DokumentSpeicherTest.php
git commit -m "feat(recruiting): DokumentSpeicher legt PDFs ab und friert die Pruefsumme ein" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 5: DokumentHinweisSender (die WhatsApp) + Team-Einstellung

**Files:**
- Create: `src/Services/Comms/DokumentHinweisSender.php`
- Modify: `src/Models/RecApplicantSettings.php` (Default-Liste, nach `'no_assignment_campaign_wa_template_id' => null,`)
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (nach dem `x-ui-input-select` für `training_certificate_wa_template_id`, Zeile ~181)
- Test: `tests/Integration/DokumentHinweisSenderTest.php`

**Interfaces:**
- Produces: `DokumentHinweisSender::sende(RecEmployee $employee): string` (nicht `final`, damit Tests ihn ersetzen können), Konstanten `SETTINGS_KEY = 'document_wa_template_id'`, `STATUS_SENT = 'sent'`, `STATUS_FAILED = 'failed'`, `STATUS_NO_PHONE = 'no_phone'`, `STATUS_NICHT_KONFIGURIERT = 'nicht_konfiguriert'`, `STATUS_VORLAGE_UNTAUGLICH = 'vorlage_untauglich'`, `STATUS_ALTES_PORTAL = 'altes_portal'`; `::istErfolg(string $status): bool`. Team-Einstellung `document_wa_template_id` (ID der genehmigten Meta-Vorlage), gepflegt im Einstellungs-Fenster unter Kommunikation.
- Consumes: `HoldingTemplateSender::resolveTarget($teamId, SETTINGS_KEY)` (Settings → Template → Account → Kanal, dieselbe Kette wie Zertifikat und Fristenlauf), `WhatsAppMetaService::sendTemplate(channel:, to:, templateName:, components:, languageCode:)`, `ApplicantTemplateSender::buildTokenComponents()`, `WhatsAppTemplateUrlButtons::dynamicIndexes()`, `PhoneE164::normalize()`.

- [ ] **Step 1: Einstellung registrieren und im Fenster anbieten**

In `src/Models/RecApplicantSettings.php` nach `'no_assignment_campaign_wa_template_id' => null,` einfügen:

```php
        // Dokumente (Spec 2026-10-08, §4): "im Portal liegt etwas fuer dich".
        // Genehmigte Vorlage mit Platzhalter {{vorname}} und einem URL-Knopf,
        // der das Portal-Token als Suffix traegt. Ohne Wert liegt jedes
        // Dokument bereit, aber niemand bekommt eine WhatsApp.
        'document_wa_template_id' => null,
```

In `resources/views/livewire/applicant/applicant-settings-modal.blade.php` direkt nach dem `@endif`, das den `x-ui-input-select` für `training_certificate_wa_template_id` schließt (Zeile ~181), einfügen:

```blade
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            :value="$settings['document_wa_template_id'] ?? null"
                            name="settings.document_wa_template_id"
                            label="Dokumente — WhatsApp-Template mit Portal-Link"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– Template wählen –"
                            wire:model.live="settings.document_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] mt-0.5">
                            Geht raus, wenn HR ein Dokument zur Kenntnisnahme oder Unterschrift bereitstellt.
                            Vorlage mit {{ '{{vorname}}' }} und einem URL-Knopf, dessen Variable am Ende steht.
                        </p>
                    @endif
```

- [ ] **Step 2: Test schreiben**

`tests/Integration/DokumentHinweisSenderTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;

/**
 * Der Hinweis "im Portal liegt ein Dokument" — Muster AufgabenSenderTest und
 * HoldingTemplateSenderResolveTargetTest: echte Tabellen fuer Einstellungen,
 * Konto, Kanal und Vorlage; Meta als Attrappe; Log als Attrappe.
 */
final class DokumentHinweisSenderTest extends TestCase
{
    private const TEAM = 3;
    private const NUMMER = '+4915111111111';
    private const TOKEN = 'portal-token-dok';
    private const VORLAGE = 'dokument_hinweis';
    private const ANGEFASST = '2026-10-09 09:00:00';

    private Capsule $capsule;
    private object $meta;
    private int $kontoId;
    private int $vorlageId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        if (!class_exists('Log')) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $container->instance('log', new class { public function __call($m, $a) {} });
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => 'RG']]]));
        $this->meta = $this->metaAttrappe();
        $container->instance(WhatsAppMetaService::class, $this->meta);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('phone')->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamps();
        });
        $this->echteMigrationen();
        $this->kontoUndKanal();
        $this->vorlageId = $this->vorlageAnlegen();
        $this->einstellungen([DokumentHinweisSender::SETTINGS_KEY => $this->vorlageId]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['log', 'config', 'db', 'db.schema', WhatsAppMetaService::class] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_erfolg_schickt_vorname_und_token_knopf(): void
    {
        $ma = $this->mitarbeiter();

        $status = (new DokumentHinweisSender())->sende($ma);

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, $status);
        $this->assertCount(1, $this->meta->calls);
        $call = $this->meta->calls[0];
        $this->assertSame(self::NUMMER, $call['to']);
        $this->assertSame(self::VORLAGE, $call['templateName']);
        $this->assertSame('de', $call['languageCode']);
        $this->assertSame([['type' => 'text', 'parameter_name' => 'vorname', 'text' => 'Gregor']], $call['components'][0]['parameters']);
        $this->assertSame('button', $call['components'][1]['type']);
        $this->assertSame(self::TOKEN, $call['components'][1]['parameters'][0]['text']);
    }

    public function test_altes_portal_bekommt_keine_nachricht(): void
    {
        $ma = $this->mitarbeiter(['portal_v2_since' => null]);

        $this->assertSame(DokumentHinweisSender::STATUS_ALTES_PORTAL, (new DokumentHinweisSender())->sende($ma));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_ohne_nummer_kein_versand(): void
    {
        $ma = $this->mitarbeiter(['phone' => 'keine']);

        $this->assertSame(DokumentHinweisSender::STATUS_NO_PHONE, (new DokumentHinweisSender())->sende($ma));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_ohne_einstellung_nicht_konfiguriert(): void
    {
        $this->einstellungen([]);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_nicht_genehmigte_vorlage_ist_nicht_konfiguriert(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update(['status' => 'PENDING']);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
    }

    public function test_vorlage_ohne_url_knopf_ist_untauglich(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update([
            'components' => json_encode([['type' => 'BODY', 'text' => 'Hallo {{vorname}}']]),
        ]);

        $this->assertSame(DokumentHinweisSender::STATUS_VORLAGE_UNTAUGLICH, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_unbekannter_platzhalter_wird_nicht_mit_vorname_befuellt(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update([
            'components' => json_encode([
                ['type' => 'BODY', 'text' => 'Hallo {{vorname}}, {{titel}} wartet.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Zum Portal', 'url' => 'https://meingedeck.de/recruiting/mitarbeiter/neu/{{1}}']]],
            ]),
        ]);

        $this->assertSame(DokumentHinweisSender::STATUS_VORLAGE_UNTAUGLICH, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_meta_ablehnung_ist_failed_nicht_sent(): void
    {
        $this->meta->lehntAb();

        $this->assertSame(DokumentHinweisSender::STATUS_FAILED, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
    }

    public function test_meta_ausnahme_wird_gefangen(): void
    {
        $this->meta->wirft(new \RuntimeException('Meta down'));

        $this->assertSame(DokumentHinweisSender::STATUS_FAILED, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
    }

    private function mitarbeiter(array $set = []): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId(array_merge([
            'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
            'phone' => self::NUMMER, 'portal_token' => self::TOKEN, 'is_active' => 1,
            'portal_v2_since' => self::ANGEFASST,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ], $set));

        return RecEmployee::find($id);
    }

    private function metaAttrappe(): object
    {
        return new class(self::VORLAGE) {
            public array $calls = [];
            private bool $lehntAb = false;
            private ?\Throwable $wirft = null;
            public function __construct(private string $vorlage) {}
            public function lehntAb(): void { $this->lehntAb = true; }
            public function wirft(\Throwable $e): void { $this->wirft = $e; }
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false)
            {
                $this->calls[] = compact('channel', 'to', 'templateName', 'components', 'languageCode');
                if ($this->wirft !== null) {
                    throw $this->wirft;
                }
                if ($this->lehntAb || $templateName !== $this->vorlage) {
                    return new class { public string $status = 'failed'; public array $meta_payload = ['error' => ['message' => 'abgelehnt']]; };
                }
                return new class { public int $id = 4712; public string $status = 'sent'; public array $meta_payload = []; };
            }
        };
    }

    private function echteMigrationen(): void
    {
        $eigen = dirname(__DIR__, 2);
        $crm = $this->paketWurzel(CommsChannel::class);
        $integrations = $this->paketWurzel(IntegrationsWhatsAppTemplate::class);
        foreach ([
            [$eigen, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$wurzel, $relativ]) {
            (require $wurzel . '/' . $relativ)->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        return dirname((new \ReflectionClass($klasse))->getFileName(), 3);
    }

    private function kontoUndKanal(): void
    {
        $this->kontoId = (int) DB::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-dok', 'phone_number' => '+49 160 5552002', 'title' => 'Recruiting-WABA', 'active' => true, 'user_id' => 1,
        ]);
        DB::table('comms_channels')->insert([
            'team_id' => self::TEAM, 'name' => 'Recruiting WhatsApp', 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552002', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $this->kontoId]),
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }

    /** Team-Einstellungen wie das Einstellungs-Fenster sie schreibt; auto_pilot_wa_account_id ist der Kanal-Anker. */
    private function einstellungen(array $weitere): void
    {
        DB::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(array_merge(['auto_pilot_wa_account_id' => $this->kontoId], $weitere)),
        ]);
    }

    private function vorlageAnlegen(): int
    {
        return (int) DB::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-dok', 'external_id' => 'ext-dok', 'name' => self::VORLAGE, 'language' => 'de',
            'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([
                ['type' => 'BODY', 'text' => 'Hallo {{vorname}}, im Portal liegt etwas für dich.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Zum Portal', 'url' => 'https://meingedeck.de/recruiting/mitarbeiter/neu/{{1}}']]],
            ]),
            'whatsapp_account_id' => $this->kontoId, 'user_id' => 1,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }
}
```

- [ ] **Step 3: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentHinweisSenderTest`
Expected: FAIL, Klasse nicht gefunden.

- [ ] **Step 4: Sender schreiben**

`src/Services/Comms/DokumentHinweisSender.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * "Im Portal liegt ein Dokument fuer dich" (Spec 2026-10-08, §4). Universeller
 * Text: Vorname plus Knopf ins Portal, kein Dokumenttitel — welches Dokument,
 * sagt das Portal.
 *
 * Vorlage aus der TEAM-EINSTELLUNG `document_wa_template_id` ueber dieselbe
 * Kette wie Zertifikat und Fristenlauf (HoldingTemplateSender::resolveTarget:
 * Settings → Template → Account → Kanal). Die Komponenten baut dieser Sender
 * SELBST, nicht ueber HoldingTemplateComponents::build(): die setzt bei einem
 * unbekannten Platzhalter still den Vornamen ein (Muster ProofReminderSender).
 *
 * Drei Abwehren wie AufgabenSender:
 *  1. `$message->status === 'failed'` ist ein Fehlschlag, kein Erfolg.
 *  2. Ein Platzhalter, den wir nicht befuellen koennen, verhindert den Versand.
 *  3. sendTemplate() im try/catch — ein Meta-Ausfall kostet einen Empfaenger.
 *
 * Wer `portal_v2_since` nicht hat, bekommt NICHTS: der Knopf fuehrte ins alte
 * Portal, das keine Dokumente kennt (Ruling C2 des Fristenlaufs).
 *
 * Nicht final: DokumentService nimmt ihn als Abhaengigkeit, Tests ersetzen ihn
 * durch eine Unterklasse ohne Meta.
 */
class DokumentHinweisSender
{
    public const SETTINGS_KEY = 'document_wa_template_id';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NO_PHONE = 'no_phone';
    public const STATUS_NICHT_KONFIGURIERT = 'nicht_konfiguriert';
    public const STATUS_VORLAGE_UNTAUGLICH = 'vorlage_untauglich';
    public const STATUS_ALTES_PORTAL = 'altes_portal';

    /** Was HoldingTemplateComponents als Vorname erkennt — dieselbe Liste, damit beide Wege dasselbe meinen. */
    private const NAME_PLATZHALTER = ['name', 'vorname', '1'];

    public static function istErfolg(string $status): bool
    {
        return $status === self::STATUS_SENT;
    }

    public function sende(RecEmployee $employee): string
    {
        if ($employee->portal_v2_since === null) {
            return self::STATUS_ALTES_PORTAL;
        }

        $nummer = PhoneE164::normalize($employee->phone);
        if ($nummer === null || $nummer === '') {
            Log::error('recruiting.dokumente.keine_nummer', ['employee_id' => $employee->id]);

            return self::STATUS_NO_PHONE;
        }

        $ziel = app(HoldingTemplateSender::class)->resolveTarget((int) $employee->team_id, self::SETTINGS_KEY);
        if ($ziel['error'] !== null) {
            Log::error('recruiting.dokumente.nicht_konfiguriert', ['team_id' => $employee->team_id, 'fehler' => $ziel['error']]);

            return self::STATUS_NICHT_KONFIGURIERT;
        }
        $vorlage = $ziel['template'];
        $teile = (array) ($vorlage->components ?? []);

        if (WhatsAppTemplateUrlButtons::dynamicIndexes($teile) === []) {
            Log::error('recruiting.dokumente.vorlage_ohne_link', ['vorlage' => $vorlage->name]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $unbekannt = $this->unbefuellbarePlatzhalter($teile);
        if ($unbekannt !== []) {
            Log::error('recruiting.dokumente.platzhalter_unbekannt', ['vorlage' => $vorlage->name, 'platzhalter' => $unbekannt]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $vorname = trim((string) $employee->first_name);
        if ($vorname === '') {
            Log::warning('recruiting.dokumente.kein_vorname', ['employee_id' => $employee->id]);

            return self::STATUS_FAILED;
        }

        $token = trim((string) $employee->portal_token);
        if ($token === '') {
            Log::error('recruiting.dokumente.kein_portal_token', ['employee_id' => $employee->id]);

            return self::STATUS_FAILED;
        }
        $knopf = ApplicantTemplateSender::buildTokenComponents($teile, $token);
        if (!$knopf['ok']) {
            Log::error('recruiting.dokumente.knopf_nicht_eindeutig', ['vorlage' => $vorlage->name, 'fehler' => $knopf['error']]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $parameter = [];
        foreach ($this->rumpfPlatzhalter($teile) as $name) {
            $parameter[] = ['type' => 'text', 'parameter_name' => strtolower($name), 'text' => $vorname];
        }
        $components = array_merge([['type' => 'body', 'parameters' => $parameter]], $knopf['components']);

        try {
            $nachricht = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $ziel['channel'],
                to:           $nummer,
                templateName: (string) $vorlage->name,
                components:   $components,
                languageCode: (string) ($vorlage->language ?? 'de'),
            );
        } catch (\Throwable $e) {
            Log::warning('recruiting.dokumente.ausnahme', ['employee_id' => $employee->id, 'fehler' => $e->getMessage()]);

            return self::STATUS_FAILED;
        }

        if (($nachricht->status ?? null) === 'failed') {
            Log::warning('recruiting.dokumente.abgelehnt', [
                'employee_id' => $employee->id,
                'meta'        => $nachricht->meta_payload ?? null,
            ]);

            return self::STATUS_FAILED;
        }

        return self::STATUS_SENT;
    }

    /** @return list<string> alle {{...}} im BODY, in Fundreihenfolge, ohne Doppelte */
    private function rumpfPlatzhalter(array $teile): array
    {
        $namen = [];
        foreach ($teile as $teil) {
            if (($teil['type'] ?? '') !== 'BODY') {
                continue;
            }
            preg_match_all('/\{\{(\w+)\}\}/', (string) ($teil['text'] ?? ''), $treffer);
            foreach ($treffer[1] as $name) {
                if (!in_array($name, $namen, true)) {
                    $namen[] = $name;
                }
            }
        }

        return $namen;
    }

    /** @return list<string> Platzhalter, die kein Vorname sind — mehr kennt dieser Sender nicht */
    private function unbefuellbarePlatzhalter(array $teile): array
    {
        return array_values(array_filter(
            $this->rumpfPlatzhalter($teile),
            static fn (string $n) => !in_array(strtolower($n), self::NAME_PLATZHALTER, true)
        ));
    }
}
```

- [ ] **Step 5: Test grün, Blade prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentHinweisSenderTest|SettingsModal|BladeCompileIntegrity"`
Expected: `OK` (9 Tests neu; die Settings-Modal-Tests müssen die zusätzliche Auswahl vertragen).

Run: `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php`
Expected: keine Fehler.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Comms/DokumentHinweisSender.php src/Models/RecApplicantSettings.php resources/views/livewire/applicant/applicant-settings-modal.blade.php tests/Integration/DokumentHinweisSenderTest.php
git commit -m "feat(recruiting): DokumentHinweisSender — WhatsApp aus der Team-Einstellung, Vorname plus Portal-Knopf" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: DokumentService::bereitstellen

**Files:**
- Create: `src/Services/DokumentService.php`
- Create: `src/Jobs/DokumentHinweiseVersenden.php`
- Test: `tests/Integration/DokumentServiceTest.php`

**Interfaces:**
- Produces:
  - `DokumentService::__construct(?DokumentSpeicher $speicher = null, ?DokumentHinweisSender $sender = null)` (faule Defaults: `DokumentSpeicher::default()`, `app(DokumentHinweisSender::class)`).
  - `::bereitstellen(int $teamId, array $daten, string $pdfInhalt, string $originalName, array $employeeIds, ?int $eventId, ?int $userId): array{dokument: RecDocument, empfaenger: int, versand_noetig: bool}` — `$daten` ist `array{title:string, category:string, action:string}`; wirft `InvalidArgumentException` mit dem Fehlertext bei ungültiger Datei/Daten/leerer Empfängerliste. **Schickt nichts.**
  - `::hinweiseVersenden(RecDocument $d): array{versucht:int, benachrichtigt:int, fehler:array<string,int>}` — schickt an alle Zustellungen, die noch nie versucht wurden (`notified_at IS NULL AND notify_error IS NULL AND withdrawn_at IS NULL`); idempotent, ein zweiter Lauf versucht nichts erneut.
  - `DokumentHinweiseVersenden::starten(RecDocument $d): void` — stellt den Job in die Queue, nur wenn `DokumentKategorie::brauchtHandlung($d->action)`; `handle()` ruft `hinweiseVersenden()`.
- Consumes: Task 2–5.

- [ ] **Step 1: Test schreiben**

`tests/Integration/DokumentServiceTest.php`. Harness: Capsule/SQLite, echte Migrationen für `rec_employees` (plus `person_key`/`portal_v2_since`-Migrationen), Dokument-Tabellen, Temp-Disk wie Task 4, Sender als Unterklasse ohne Meta.

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\DokumentSpeicher;

/** Sender ohne Meta: merkt sich, wen er anschreiben sollte, und antwortet nach Plan. */
final class DokumentSenderAttrappe extends DokumentHinweisSender
{
    /** @var list<int> */
    public array $angeschrieben = [];
    /** @var array<int,string> employee_id => Status */
    public array $antworten = [];

    public function sende(RecEmployee $employee): string
    {
        $this->angeschrieben[] = (int) $employee->id;

        return $this->antworten[(int) $employee->id] ?? self::STATUS_SENT;
    }
}

final class DokumentServiceTest extends TestCase
{
    private const TEAM = 3;
    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private string $root;
    private DokumentSpeicher $speicher;
    private DokumentSenderAttrappe $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => 'RG']]]));
        $container->instance('log', new class { public function __call($m, $a) {} });

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $eigen = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
            'database/migrations/2026_10_09_000001_create_rec_documents_table.php',
            'database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php',
        ] as $relativ) {
            $pfad = $eigen . '/' . $relativ;
            if (!file_exists($pfad)) {
                throw new \RuntimeException("Migration fehlt: {$pfad}");
            }
            (require $pfad)->up();
        }

        $this->root = sys_get_temp_dir() . '/rec-dokservice-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $this->speicher = new DokumentSpeicher(new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]), 'test-local');
        $this->sender = new DokumentSenderAttrappe();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['config', 'log', 'db', 'db.schema'] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function service(): DokumentService
    {
        return new DokumentService($this->speicher, $this->sender);
    }

    private function ma(array $set = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => self::TEAM, 'first_name' => 'Anna', 'last_name' => 'Test', 'is_active' => true,
            'portal_v2_since' => '2026-10-01 00:00:00',
        ], $set));
    }

    private function daten(array $set = []): array
    {
        return array_merge(['title' => 'Verschwiegenheit', 'category' => 'contract', 'action' => 'sign'], $set);
    }

    public function test_bereitstellen_legt_dokument_und_empfaenger_an_und_benachrichtigt(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben']);

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'Verschwiegenheit.pdf', [$a->id, $b->id], null, 9);

        $dok = $r['dokument'];
        $this->assertInstanceOf(RecDocument::class, $dok);
        $this->assertSame(hash('sha256', self::PDF), $dok->file_sha256);
        $this->assertSame(strlen(self::PDF), $dok->file_size);
        $this->assertSame('test-local', $dok->disk);
        $this->assertSame(9, $dok->created_by_user_id);
        $this->assertSame(self::PDF, $this->speicher->inhalt($dok->stored_path));

        $this->assertSame(2, $r['empfaenger']);
        $this->assertTrue($r['versand_noetig']);
        $this->assertSame([], $this->sender->angeschrieben, 'Bereitstellen schickt nichts — das tut der Job');

        $zeilen = RecDocumentRecipient::where('rec_document_id', $dok->id)->orderBy('rec_employee_id')->get();
        $this->assertCount(2, $zeilen);
        $this->assertNull($zeilen[0]->notified_at);
        $this->assertNull($zeilen[0]->notify_error);
        $this->assertNotEmpty($zeilen[0]->uuid);
    }

    public function test_hinweise_versenden_schreibt_erfolg_und_fehler_je_person(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben', 'phone' => null]);
        $this->sender->antworten[$b->id] = DokumentHinweisSender::STATUS_NO_PHONE;
        $dok = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id, $b->id], null, null)['dokument'];

        $e = $this->service()->hinweiseVersenden($dok);

        $this->assertSame(['versucht' => 2, 'benachrichtigt' => 1, 'fehler' => ['no_phone' => 1]], $e);
        $this->assertSame([$a->id, $b->id], $this->sender->angeschrieben);
        $za = RecDocumentRecipient::where('rec_employee_id', $a->id)->first();
        $zb = RecDocumentRecipient::where('rec_employee_id', $b->id)->first();
        $this->assertNotNull($za->notified_at);
        $this->assertNull($za->notify_error);
        $this->assertNull($zb->notified_at);
        $this->assertSame('no_phone', $zb->notify_error);
    }

    public function test_hinweise_versenden_ist_idempotent_und_laesst_zurueckgezogene_aus(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben']);
        $c = $this->ma(['first_name' => 'Cem']);
        $this->sender->antworten[$b->id] = DokumentHinweisSender::STATUS_FAILED;
        $dok = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id, $b->id, $c->id], null, null)['dokument'];
        DB::table('rec_document_recipients')->where('rec_employee_id', $c->id)->update(['withdrawn_at' => '2026-10-09 10:00:00']);

        $erster = $this->service()->hinweiseVersenden($dok);
        $this->sender->angeschrieben = [];
        $zweiter = $this->service()->hinweiseVersenden($dok);

        $this->assertSame(['versucht' => 2, 'benachrichtigt' => 1, 'fehler' => ['failed' => 1]], $erster, 'Cem ist zurueckgezogen');
        $this->assertSame(['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => []], $zweiter, 'auch der Fehlschlag wird nicht von selbst wiederholt — das ist Erneut senden');
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_nur_ablegen_braucht_keinen_versand(): void
    {
        $a = $this->ma();

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(['category' => 'payslip', 'action' => 'none']), self::PDF, 'x.pdf', [$a->id], null, null);

        $this->assertFalse($r['versand_noetig']);
        $this->assertSame(['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => []], $this->service()->hinweiseVersenden($r['dokument']));
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_zwei_anstellungen_einer_person_sind_ein_empfaenger(): void
    {
        $rg = $this->ma(['person_key' => 'p-1', 'company' => 'RG']);
        $maUg = $this->ma(['person_key' => 'p-1', 'company' => 'MA']);

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$maUg->id, $rg->id], null, null);

        $this->assertSame(1, $r['empfaenger']);
        $this->assertSame([$rg->id], $this->sender->angeschrieben, 'kleinere id traegt die Zustellung');
        $this->assertSame('p-1', RecDocumentRecipient::first()->person_key);
    }

    public function test_fremdes_team_wird_stumm_uebersprungen(): void
    {
        $eigen = $this->ma();
        $fremd = $this->ma(['team_id' => 99]);

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$eigen->id, $fremd->id, 123456], null, null);

        $this->assertSame(1, $r['empfaenger']);
        $this->assertSame([$eigen->id], RecDocumentRecipient::pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_keine_pdf_wird_abgelehnt_und_nichts_gespeichert(): void
    {
        $a = $this->ma();

        try {
            $this->service()->bereitstellen(self::TEAM, $this->daten(), "\xFF\xD8\xFFjpg", 'foto.pdf', [$a->id], null, null);
            $this->fail('Ausnahme erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Die Datei ist keine PDF.', $e->getMessage());
        }
        $this->assertSame(0, RecDocument::count());
        $this->assertSame([], glob($this->root . '/recruiting/dokumente/*/*') ?: []);
    }

    public function test_leere_empfaengerliste_und_leerer_titel_werden_abgelehnt(): void
    {
        $a = $this->ma();

        try {
            $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [], null, null);
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Bitte mindestens einen Empfänger wählen.', $e->getMessage());
        }
        try {
            $this->service()->bereitstellen(self::TEAM, $this->daten(['title' => '  ']), self::PDF, 'x.pdf', [$a->id], null, null);
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Bitte einen Titel angeben.', $e->getMessage());
        }
        $this->assertSame(0, RecDocument::count());
    }

    public function test_unbekannte_aktion_wird_abgelehnt(): void
    {
        $a = $this->ma();
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->bereitstellen(self::TEAM, $this->daten(['action' => 'read']), self::PDF, 'x.pdf', [$a->id], null, null);
    }

    public function test_veranstaltung_wird_am_dokument_vermerkt(): void
    {
        $a = $this->ma();
        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id], 77, null);
        $this->assertSame(77, $r['dokument']->rec_dispo_event_id);
    }

    public function test_bereitstellen_schreibt_nie_auf_rec_employees(): void
    {
        $a = $this->ma();
        $vorher = DB::table('rec_employees')->where('id', $a->id)->value('updated_at');
        $marker = DB::table('rec_employees')->where('id', $a->id)->value('zas_changed_at');

        $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id], null, null);

        $this->assertSame($vorher, DB::table('rec_employees')->where('id', $a->id)->value('updated_at'));
        $this->assertSame($marker, DB::table('rec_employees')->where('id', $a->id)->value('zas_changed_at'));
    }
}
```

- [ ] **Step 2: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentServiceTest`
Expected: FAIL, `DokumentService` nicht gefunden.

- [ ] **Step 3: Service schreiben**

`src/Services/DokumentService.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentUploadRegeln;
use Platform\Recruiting\Support\EmpfaengerEntdoppler;
use Symfony\Component\Uid\UuidV7;

/**
 * Bereitstellen, erneut senden, zurueckziehen (Spec 2026-10-08, §3.1, §4, §7).
 * EIN Service fuer alle drei Einstiege (Akte, Veranstaltung, Seite Dokumente).
 *
 * Reihenfolge beim Bereitstellen: pruefen → Datei ablegen → Empfaenger
 * entdoppeln → Transaktion (Dokument + Zustellungen) → AUSSERHALB der
 * Transaktion je Empfaenger die WhatsApp. Ein Meta-Fehler kostet einen
 * Empfaenger, nie das Dokument. Nie eine Schreibung auf rec_employees.
 */
class DokumentService
{
    /**
     * Nullable mit faulen Defaults: app(DokumentService::class) baut ihn ohne
     * Argumente, Tests uebergeben Speicher und Sender ausdruecklich.
     */
    public function __construct(
        private ?DokumentSpeicher $speicher = null,
        private ?DokumentHinweisSender $sender = null,
    ) {
    }

    private function speicher(): DokumentSpeicher
    {
        return $this->speicher ??= DokumentSpeicher::default();
    }

    private function sender(): DokumentHinweisSender
    {
        return $this->sender ??= app(DokumentHinweisSender::class);
    }

    /**
     * @param  array{title:string, category:string, action:string} $daten
     * @param  list<int> $employeeIds
     * @return array{dokument: RecDocument, empfaenger: int, benachrichtigt: int, fehler: array<string,int>}
     * @throws \InvalidArgumentException mit dem Fehlertext fuer HR
     */
    public function bereitstellen(int $teamId, array $daten, string $pdfInhalt, string $originalName, array $employeeIds, ?int $eventId, ?int $userId): array
    {
        $titel = trim((string) ($daten['title'] ?? ''));
        $kategorie = (string) ($daten['category'] ?? '');
        $aktion = (string) ($daten['action'] ?? '');

        if ($titel === '') {
            throw new \InvalidArgumentException('Bitte einen Titel angeben.');
        }
        if (!DokumentKategorie::exists($kategorie)) {
            throw new \InvalidArgumentException('Unbekannte Kategorie.');
        }
        if (!DokumentKategorie::aktionExists($aktion)) {
            throw new \InvalidArgumentException('Unbekannte Aktion.');
        }
        $dateiFehler = DokumentUploadRegeln::pruefe($pdfInhalt, $originalName);
        if ($dateiFehler !== null) {
            throw new \InvalidArgumentException($dateiFehler);
        }

        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $anstellungen = $ids === [] ? [] : DB::table('rec_employees')
            ->where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->get(['id', 'person_key'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'person_key' => $r->person_key])
            ->keyBy('id')
            ->all();
        // Reihenfolge der Eingabe behalten, fremde/unbekannte ids stumm uebergehen.
        $geordnet = [];
        foreach ($ids as $id) {
            if (isset($anstellungen[$id])) {
                $geordnet[] = $anstellungen[$id];
            }
        }
        $empfaengerIds = EmpfaengerEntdoppler::aufPersonen($geordnet);
        if ($empfaengerIds === []) {
            throw new \InvalidArgumentException('Bitte mindestens einen Empfänger wählen.');
        }

        $uuid = (string) UuidV7::generate();
        $ablage = $this->speicher()->ablegen($teamId, $uuid, $pdfInhalt);

        $dokument = DB::transaction(function () use ($teamId, $uuid, $titel, $kategorie, $aktion, $ablage, $originalName, $eventId, $userId, $empfaengerIds, $anstellungen) {
            $dokument = RecDocument::create([
                'team_id'            => $teamId,
                'uuid'               => $uuid,
                'title'              => mb_substr($titel, 0, 255),
                'category'           => $kategorie,
                'action'             => $aktion,
                'disk'               => $ablage['disk'],
                'stored_path'        => $ablage['stored_path'],
                'original_filename'  => mb_substr($originalName, 0, 255),
                'file_sha256'        => $ablage['sha256'],
                'file_size'          => $ablage['size'],
                'rec_dispo_event_id' => $eventId,
                'created_by_user_id' => $userId,
            ]);
            foreach ($empfaengerIds as $employeeId) {
                RecDocumentRecipient::create([
                    'team_id'         => $teamId,
                    'rec_document_id' => $dokument->id,
                    'rec_employee_id' => $employeeId,
                    'person_key'      => $anstellungen[$employeeId]['person_key'] ?? null,
                ]);
            }

            return $dokument;
        });

        return [
            'dokument'      => $dokument->fresh(),
            'empfaenger'    => count($empfaengerIds),
            'versand_noetig' => DokumentKategorie::brauchtHandlung($aktion),
        ];
    }

    /**
     * Die WhatsApps zu einem Dokument — aus dem Job, nie aus dem Klick: bei
     * 200 Eingebuchten sind das Minuten, und ein Timeout mittendrin liesse
     * die Haelfte stumm. Versucht wird NUR, was noch nie versucht wurde
     * (notified_at UND notify_error leer); ein Fehlschlag wird nicht von
     * selbst wiederholt, dafuer gibt es "Erneut senden". Zurueckgezogene und
     * "nur ablegen" bekommen nichts.
     *
     * @return array{versucht:int, benachrichtigt:int, fehler:array<string,int>}
     */
    public function hinweiseVersenden(RecDocument $dokument): array
    {
        $e = ['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => []];
        if (!DokumentKategorie::brauchtHandlung((string) $dokument->action)) {
            return $e;
        }
        $offene = RecDocumentRecipient::query()
            ->where('rec_document_id', $dokument->id)
            ->whereNull('notified_at')
            ->whereNull('notify_error')
            ->whereNull('withdrawn_at')
            ->orderBy('id')
            ->get();
        foreach ($offene as $empfaenger) {
            $status = $this->hinweisSenden($empfaenger);
            $e['versucht']++;
            if (DokumentHinweisSender::istErfolg($status)) {
                $e['benachrichtigt']++;
            } else {
                $e['fehler'][$status] = ($e['fehler'][$status] ?? 0) + 1;
            }
        }

        return $e;
    }

    /** Schickt den Hinweis und schreibt das Ergebnis an die Zustellung (Query Builder). */
    private function hinweisSenden(RecDocumentRecipient $empfaenger): string
    {
        $employee = RecEmployee::find($empfaenger->rec_employee_id);
        $status = $employee === null ? DokumentHinweisSender::STATUS_FAILED : $this->sender()->sende($employee);

        DB::table('rec_document_recipients')->where('id', $empfaenger->id)->update(
            DokumentHinweisSender::istErfolg($status)
                ? ['notified_at' => now(), 'notify_error' => null, 'updated_at' => now()]
                : ['notify_error' => mb_substr($status, 0, 120), 'updated_at' => now()]
        );

        return $status;
    }
}
```

`src/Jobs/DokumentHinweiseVersenden.php`:

```php
<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Hintergrundversand der Dokument-Hinweise (Spec §3.1 Schritt 5, Nachtrag
 * 09.10.2026): der Klick legt nur Zeilen an, dieser Job schickt. Idempotent
 * ueber DokumentService::hinweiseVersenden() — ein zweiter Lauf versucht
 * nichts erneut, deshalb ist $tries = 1 ungefaehrlich und $tries > 1 unnoetig.
 * Kein SerializesModels: nur die ID, das Dokument wird frisch geladen.
 */
class DokumentHinweiseVersenden implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;
    public $timeout = 900;

    public function __construct(private int $documentId)
    {
    }

    /** Einziger Einstieg fuer die drei Oberflaechen — stellt nur ein, wenn es etwas zu schicken gibt. */
    public static function starten(RecDocument $dokument): void
    {
        if (DokumentKategorie::brauchtHandlung((string) $dokument->action)) {
            self::dispatch((int) $dokument->id);
        }
    }

    public function handle(): void
    {
        $dokument = RecDocument::find($this->documentId);
        if ($dokument === null) {
            return;
        }
        $e = app(DokumentService::class)->hinweiseVersenden($dokument);
        Log::info('recruiting.dokumente.hinweise_versendet', ['document_id' => $dokument->id] + $e);
    }
}
```

- [ ] **Step 4: Test grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentServiceTest`
Expected: `OK (12 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/DokumentService.php src/Jobs/DokumentHinweiseVersenden.php tests/Integration/DokumentServiceTest.php
git commit -m "feat(recruiting): DokumentService stellt bereit, Job verschickt die Hinweise im Hintergrund" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Erneut senden und Zurückziehen

**Files:**
- Modify: `src/Services/DokumentService.php`
- Test: `tests/Integration/DokumentServiceTest.php` (ergänzen)

**Interfaces:**
- Produces: `DokumentService::erneutSenden(RecDocumentRecipient $r): string` (Status des Senders; `'zurueckgezogen'`/`'nur_ablegen'` ohne Versand), `::zurueckziehen(RecDocumentRecipient $r): ?string` (null = ok, sonst Fehlertext), `::dokumentZurueckziehen(RecDocument $d): array{zurueckgezogen:int, geloescht:bool}`.

- [ ] **Step 1: Tests ergänzen** (in `DokumentServiceTest`, vor der schließenden Klammer der Klasse)

```php
    private function bereit(array $datenSet = [], ?RecEmployee $ma = null): array
    {
        $ma ??= $this->ma();
        $r = $this->service()->bereitstellen(self::TEAM, $this->daten($datenSet), self::PDF, 'x.pdf', [$ma->id], null, null);
        $this->service()->hinweiseVersenden($r['dokument']);
        $this->sender->angeschrieben = [];

        return [$r['dokument'], RecDocumentRecipient::where('rec_document_id', $r['dokument']->id)->first(), $ma];
    }

    public function test_erneut_senden_schreibt_erfolg_und_loescht_fehler(): void
    {
        [$dok, $zeile, $ma] = $this->bereit();
        DB::table('rec_document_recipients')->where('id', $zeile->id)->update(['notified_at' => null, 'notify_error' => 'no_phone']);

        $status = $this->service()->erneutSenden($zeile->fresh());

        $this->assertSame('sent', $status);
        $this->assertSame([$ma->id], $this->sender->angeschrieben);
        $zeile = $zeile->fresh();
        $this->assertNotNull($zeile->notified_at);
        $this->assertNull($zeile->notify_error);
    }

    public function test_erneut_senden_nach_umstellung(): void
    {
        $ma = $this->ma(['portal_v2_since' => null]);
        $this->sender->antworten[$ma->id] = DokumentHinweisSender::STATUS_ALTES_PORTAL;
        [$dok, $zeile] = $this->bereit([], $ma);
        $this->assertSame('altes_portal', $zeile->notify_error, 'Testannahme');

        unset($this->sender->antworten[$ma->id]);   // umgestellt: der Sender wuerde jetzt senden
        $this->assertSame('sent', $this->service()->erneutSenden($zeile->fresh()));
        $this->assertNull($zeile->fresh()->notify_error);
    }

    public function test_erneut_senden_auf_zurueckgezogen_bricht_ab(): void
    {
        [$dok, $zeile] = $this->bereit();
        $this->assertNull($this->service()->zurueckziehen($zeile->fresh()));

        $this->assertSame('zurueckgezogen', $this->service()->erneutSenden($zeile->fresh()));
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_erneut_senden_bei_nur_ablegen_sendet_nicht(): void
    {
        [$dok, $zeile] = $this->bereit(['category' => 'payslip', 'action' => 'none']);
        $this->assertSame('nur_ablegen', $this->service()->erneutSenden($zeile->fresh()));
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_zurueckziehen_nach_unterschrift_verweigert(): void
    {
        [$dok, $zeile] = $this->bereit();
        DB::table('rec_document_recipients')->where('id', $zeile->id)->update(['signed_at' => '2026-10-09 12:00:00']);

        $fehler = $this->service()->zurueckziehen($zeile->fresh());

        $this->assertSame('Unterschrieben, kann nicht zurückgezogen werden.', $fehler);
        $this->assertNull($zeile->fresh()->withdrawn_at);
    }

    public function test_zurueckziehen_ist_idempotent(): void
    {
        [$dok, $zeile] = $this->bereit();
        $this->service()->zurueckziehen($zeile->fresh());
        $erstes = $zeile->fresh()->withdrawn_at;
        $this->assertNotNull($erstes);

        $this->assertNull($this->service()->zurueckziehen($zeile->fresh()));
        $this->assertEquals($erstes, $zeile->fresh()->withdrawn_at);
    }

    public function test_dokument_zurueckziehen_loescht_nur_ohne_unterschriften(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben']);
        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id, $b->id], null, null);
        $dok = $r['dokument'];

        $e = $this->service()->dokumentZurueckziehen($dok);
        $this->assertSame(['zurueckgezogen' => 2, 'geloescht' => true], $e);
        $this->assertNotNull(RecDocument::withTrashed()->find($dok->id)->deleted_at);
        $this->assertSame(2, RecDocumentRecipient::where('rec_document_id', $dok->id)->whereNotNull('withdrawn_at')->count());
    }

    public function test_dokument_zurueckziehen_mit_unterschrift_bleibt_bestehen(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben']);
        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id, $b->id], null, null);
        $dok = $r['dokument'];
        DB::table('rec_document_recipients')->where('rec_document_id', $dok->id)->where('rec_employee_id', $a->id)
            ->update(['signed_at' => '2026-10-09 12:00:00']);

        $e = $this->service()->dokumentZurueckziehen($dok);

        $this->assertSame(['zurueckgezogen' => 1, 'geloescht' => false], $e);
        $this->assertNull(RecDocument::find($dok->id)->deleted_at);
        $this->assertNull(RecDocumentRecipient::where('rec_employee_id', $a->id)->first()->withdrawn_at);
        $this->assertNotNull(RecDocumentRecipient::where('rec_employee_id', $b->id)->first()->withdrawn_at);
    }
```

- [ ] **Step 2: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentServiceTest`
Expected: FAIL, `Call to undefined method ... erneutSenden()`.

- [ ] **Step 3: Methoden ergänzen** (in `DokumentService`, vor `hinweisSenden()`)

```php
    /**
     * HR-Knopf "Erneut senden" (Spec §4): derselbe Sender, dasselbe Ergebnis
     * an der Zustellung. Kein Versand an Zurueckgezogene, keiner bei "nur
     * ablegen" — beides Zustaende, nicht Fehler.
     */
    public function erneutSenden(RecDocumentRecipient $empfaenger): string
    {
        if ($empfaenger->withdrawn_at !== null) {
            return 'zurueckgezogen';
        }
        $aktion = (string) DB::table('rec_documents')->where('id', $empfaenger->rec_document_id)->value('action');
        if (!DokumentKategorie::brauchtHandlung($aktion)) {
            return 'nur_ablegen';
        }

        return $this->hinweisSenden($empfaenger);
    }

    /**
     * Zurueckziehen je Empfaenger (Spec §7): Zeitstempel, kein Loeschen.
     * Gesperrt sobald unterschrieben — eine Unterschrift ist ein Nachweis.
     *
     * @return ?string null = zurueckgezogen (oder schon gewesen), sonst Fehlertext
     */
    public function zurueckziehen(RecDocumentRecipient $empfaenger): ?string
    {
        if ($empfaenger->signed_at !== null) {
            return 'Unterschrieben, kann nicht zurückgezogen werden.';
        }
        DB::table('rec_document_recipients')
            ->where('id', $empfaenger->id)
            ->whereNull('signed_at')
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

        return null;
    }

    /**
     * Ganzes Dokument zurueckziehen (Spec §7): alle offenen Zustellungen, dann
     * SoftDelete nur, wenn NIEMAND unterschrieben hat. Mit Unterschriften bleibt
     * das Dokument als Nachweis stehen ("teilweise zurueckgezogen").
     *
     * @return array{zurueckgezogen:int, geloescht:bool}
     */
    public function dokumentZurueckziehen(RecDocument $dokument): array
    {
        $zurueck = DB::table('rec_document_recipients')
            ->where('rec_document_id', $dokument->id)
            ->whereNull('signed_at')
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

        $unterschrieben = DB::table('rec_document_recipients')
            ->where('rec_document_id', $dokument->id)
            ->whereNotNull('signed_at')
            ->exists();

        if (!$unterschrieben) {
            $dokument->delete();
        }

        return ['zurueckgezogen' => (int) $zurueck, 'geloescht' => !$unterschrieben];
    }
```

- [ ] **Step 4: Tests grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentServiceTest`
Expected: `OK (20 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/DokumentService.php tests/Integration/DokumentServiceTest.php
git commit -m "feat(recruiting): Dokumente erneut senden und zurueckziehen (je Empfaenger und ganz)" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 8: DokumentLeser (Portal-Sicht) und offene Punkte

**Files:**
- Create: `tests/Integration/DokumenteHarness.php` (Trait, von Task 8 an für alle Dokument-Integrationstests)
- Create: `src/Services/DokumentLeser.php`
- Modify: `src/Services/OffenePunkte.php` (Konstruktor, `fuer()`, neu `fuerTrigger()`)
- Modify: `src/Console/Commands/EinsatzPruefung.php` (Zeile 355: `fuer` → `fuerTrigger`)
- Test: `tests/Integration/DokumentLeserTest.php`, `tests/Integration/OffenePunkteDokumenteTest.php`

**Interfaces:**
- Produces:
  - `DokumentLeser::__construct(PersonScopeResolver $scope = new PersonScopeResolver())`
  - `::fuerMitarbeiter(RecEmployee $e): list<array{recipient_id:int, uuid:string, title:string, category:string, category_label:string, action:string, status:string, status_label:string, offen:bool, first_viewed_at:?string, acknowledged_at:?string, signed_at:?string, provided_at:string}>` — ohne `withdrawn_at`, ohne gelöschte Dokumente, über alle Anstellungen der Person, neueste zuerst.
  - `::empfaenger(RecEmployee $e, int $recipientId): ?RecDocumentRecipient` — nur im Scope, nicht zurückgezogen, Dokument nicht gelöscht; sonst null.
  - `::offenePunkte(RecEmployee $e, ?int $ohneFrischGemeldeteTage = null, ?string $heute = null): list<array{code:string, label:string, status:string, ko:bool, punkt:string, text:string}>` mit `code = 'dokument:<recipient_id>'`; mit gesetztem `$ohneFrischGemeldeteTage` fallen Zustellungen weg, deren `notified_at` jünger als so viele Tage ist (Fehlversuche ohne `notified_at` bleiben drin).
  - `OffenePunkte::fuer()` liefert alle Dokument-Punkte (Portal); `OffenePunkte::fuerTrigger(RecEmployee $e, ?string $heute = null)` dieselbe Form, aber Dokumente ohne die frisch gemeldeten (`EinsatzBezug::PAUSE_TAGE`). `EinsatzPruefung` ruft `fuerTrigger()`.
- Consumes: `PersonScopeResolver::forEmployee()['ids']`, Modelle aus Task 1, `DokumentKategorie`, `DokumentStatus`.

- [ ] **Step 1: Harness-Trait schreiben**

`tests/Integration/DokumenteHarness.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Facade;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\DokumentSpeicher;

/**
 * Gemeinsamer Unterbau der Dokument-Integrationstests: Container, Capsule,
 * ALLE Modul-Migrationen per glob (Muster DispoImportErinnerungsStempelTest —
 * die Welt kommt aus den Migrationen, nicht aus einem handgebauten Schema),
 * ein echter lokaler Speicher im Temp-Ordner.
 */
trait DokumenteHarness
{
    protected string $root;
    protected DokumentSpeicher $speicher;

    protected function harnessAufsetzen(): void
    {
        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => 'RG']]]));
        $container->instance('log', new class { public function __call($m, $a) {} });

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $dateien = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // Migrationen auf fremde Tabellen koennen hier nicht laufen; welche, haelt MassenzuweisungGeschlosseneWeltTest fest.
            }
        }

        $this->root = sys_get_temp_dir() . '/rec-dok-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $this->speicher = new DokumentSpeicher(new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]), 'test-local');
    }

    protected function harnessAbbauen(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['config', 'log', 'db', 'db.schema'] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
    }

    protected function anstellung(array $set = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => 3, 'first_name' => 'Anna', 'last_name' => 'Test', 'is_active' => true,
            'portal_v2_since' => '2026-10-01 00:00:00',
        ], $set));
    }
}
```

- [ ] **Step 2: Tests schreiben**

`tests/Integration/DokumentLeserTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentLeser;

final class DokumentLeserTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function dokument(array $set = []): RecDocument
    {
        return RecDocument::create(array_merge([
            'team_id' => 3, 'title' => 'Verschwiegenheit', 'category' => 'contract', 'action' => 'sign',
            'disk' => 'test-local', 'stored_path' => 'recruiting/dokumente/3/x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 10,
        ], $set));
    }

    private function zustellung(RecDocument $d, int $employeeId, array $set = []): RecDocumentRecipient
    {
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $employeeId]);
        if ($set !== []) {
            DB::table('rec_document_recipients')->where('id', $z->id)->update($set);
        }

        return $z->fresh();
    }

    public function test_sieht_dokumente_beider_anstellungen_einmal_und_fremde_nie(): void
    {
        $rg = $this->anstellung(['person_key' => 'p-1', 'company' => 'RG']);
        $ma = $this->anstellung(['person_key' => 'p-1', 'company' => 'MA']);
        $fremd = $this->anstellung(['person_key' => 'p-2']);
        $d1 = $this->dokument(['title' => 'An RG']);
        $d2 = $this->dokument(['title' => 'An MA']);
        $d3 = $this->dokument(['title' => 'Fremd']);
        $this->zustellung($d1, $rg->id);
        $this->zustellung($d2, $ma->id);
        $this->zustellung($d3, $fremd->id);

        $liste = (new DokumentLeser())->fuerMitarbeiter($ma);

        $this->assertSame(['An MA', 'An RG'], array_column($liste, 'title'), 'neueste zuerst, beide Anstellungen');
        $this->assertSame('Vertrag / Zusatzvereinbarung', $liste[0]['category_label']);
        $this->assertSame('offen', $liste[0]['status']);
        $this->assertTrue($liste[0]['offen']);
    }

    public function test_zurueckgezogene_und_geloeschte_fehlen(): void
    {
        $e = $this->anstellung();
        $d1 = $this->dokument();
        $this->zustellung($d1, $e->id, ['withdrawn_at' => '2026-10-09 10:00:00']);
        $d2 = $this->dokument(['title' => 'Geloescht']);
        $this->zustellung($d2, $e->id);
        $d2->delete();
        $d3 = $this->dokument(['title' => 'Bleibt']);
        $this->zustellung($d3, $e->id);

        $liste = (new DokumentLeser())->fuerMitarbeiter($e);

        $this->assertSame(['Bleibt'], array_column($liste, 'title'));
    }

    public function test_empfaenger_liefert_nur_eigene_offene(): void
    {
        $e = $this->anstellung();
        $fremd = $this->anstellung();
        $d = $this->dokument();
        $eigen = $this->zustellung($d, $e->id);
        $fremde = $this->zustellung($this->dokument(), $fremd->id);
        $zurueck = $this->zustellung($this->dokument(), $e->id, ['withdrawn_at' => '2026-10-09 10:00:00']);

        $leser = new DokumentLeser();
        $this->assertSame($eigen->id, $leser->empfaenger($e, $eigen->id)?->id);
        $this->assertNull($leser->empfaenger($e, $fremde->id));
        $this->assertNull($leser->empfaenger($e, $zurueck->id));
        $this->assertNull($leser->empfaenger($e, 999999));
    }

    public function test_offene_punkte_nur_fuer_handlungsbedarf(): void
    {
        $e = $this->anstellung();
        $sign = $this->zustellung($this->dokument(['title' => 'Unterschreiben']), $e->id);
        $ack = $this->zustellung($this->dokument(['title' => 'Lesen', 'action' => 'acknowledge', 'category' => 'instruction']), $e->id);
        $this->zustellung($this->dokument(['title' => 'Lohn', 'action' => 'none', 'category' => 'payslip']), $e->id);
        $this->zustellung($this->dokument(['title' => 'Fertig']), $e->id, ['signed_at' => '2026-10-09 10:00:00']);
        $this->zustellung($this->dokument(['title' => 'Gelesen', 'action' => 'acknowledge']), $e->id, ['acknowledged_at' => '2026-10-09 10:00:00']);

        $punkte = (new DokumentLeser())->offenePunkte($e);

        $this->assertCount(2, $punkte);
        $codes = array_column($punkte, 'code');
        $this->assertContains('dokument:' . $sign->id, $codes);
        $this->assertContains('dokument:' . $ack->id, $codes);
        $this->assertSame('Unterschreiben', $punkte[0]['label']);
        $this->assertFalse($punkte[0]['ko']);
        $this->assertSame('crit', $punkte[0]['punkt']);
        $this->assertSame('Lesen und unterschreiben', $punkte[0]['text']);
        $this->assertSame('Lesen und bestätigen', $punkte[1]['text']);
    }

    public function test_frisch_gemeldete_dokumente_fallen_fuer_den_trigger_weg(): void
    {
        $e = $this->anstellung();
        $frisch = $this->zustellung($this->dokument(['title' => 'Frisch']), $e->id, ['notified_at' => '2026-10-08 10:00:00']);
        $alt    = $this->zustellung($this->dokument(['title' => 'Alt']), $e->id, ['notified_at' => '2026-09-30 10:00:00']);
        $fehl   = $this->zustellung($this->dokument(['title' => 'Fehlversuch']), $e->id, ['notify_error' => 'no_phone']);

        $alle = (new DokumentLeser())->offenePunkte($e);
        $trigger = (new DokumentLeser())->offenePunkte($e, 7, '2026-10-09');

        $this->assertCount(3, $alle, 'das Portal sieht alles');
        $codes = array_column($trigger, 'code');
        $this->assertNotContains('dokument:' . $frisch->id, $codes, 'vor einem Tag gemeldet — die Einsatz-Pruefung schweigt noch');
        $this->assertContains('dokument:' . $alt->id, $codes, 'vor neun Tagen gemeldet — jetzt wieder dran');
        $this->assertContains('dokument:' . $fehl->id, $codes, 'nie angekommen — die Einsatz-Pruefung ist der zweite Fang');
    }
}
```

`tests/Integration/OffenePunkteDokumenteTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Support\TriggerRegeln;

/**
 * Ein offenes Dokument ist ein offener Punkt (Spec §5.2, §6) — und alle
 * Verbraucher der Punkteliste kommen mit einem Code ohne Katalogeintrag aus.
 */
final class OffenePunkteDokumenteTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_offenes_dokument_ist_genau_ein_punkt_ohne_sperre(): void
    {
        $e = $this->anstellung(['is_eu_citizen' => true]);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);

        $stand = (new OffenePunkte())->fuer($e, '2026-10-09');

        $dokPunkte = array_values(array_filter($stand['punkte'], fn ($p) => str_starts_with($p['code'], 'dokument:')));
        $this->assertCount(1, $dokPunkte);
        $this->assertSame('dokument:' . $z->id, $dokPunkte[0]['code']);
        $this->assertFalse($dokPunkte[0]['ko']);
        $this->assertFalse($stand['gesperrt']);

        // Verbraucher: Signatur (Einsatz-Pruefung) und Dekoration (Portal) tragen den Code.
        $this->assertSame(64, strlen(TriggerRegeln::signatur($stand['punkte'])));
        $mitSaetzen = PortalShell::punkteMitSaetzen($dokPunkte, []);
        $this->assertSame('Lesen und bestätigen', $mitSaetzen[0]['text']);
        $this->assertSame('crit', $mitSaetzen[0]['punkt']);
    }

    public function test_fuer_trigger_laesst_frisch_gemeldete_aus_und_ist_sonst_gleich(): void
    {
        $e = $this->anstellung(['is_eu_citizen' => true]);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        \Illuminate\Support\Facades\DB::table('rec_document_recipients')->where('id', $z->id)->update(['notified_at' => '2026-10-09 08:00:00']);

        $portal = (new OffenePunkte())->fuer($e, '2026-10-09');
        $trigger = (new OffenePunkte())->fuerTrigger($e, '2026-10-09');

        $this->assertContains('dokument:' . $z->id, array_column($portal['punkte'], 'code'));
        $this->assertNotContains('dokument:' . $z->id, array_column($trigger['punkte'], 'code'));
        $this->assertSame($portal['einsatz'], $trigger['einsatz']);
        $this->assertSame($portal['gesperrt'], $trigger['gesperrt']);

        \Illuminate\Support\Facades\DB::table('rec_document_recipients')->where('id', $z->id)->update(['notified_at' => '2026-10-01 08:00:00']);
        $this->assertContains('dokument:' . $z->id, array_column((new OffenePunkte())->fuerTrigger($e, '2026-10-09')['punkte'], 'code'));
    }

    public function test_nur_ablegen_erzeugt_keinen_punkt(): void
    {
        $e = $this->anstellung(['is_eu_citizen' => true]);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Lohn', 'category' => 'payslip', 'action' => 'none',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);

        $stand = (new OffenePunkte())->fuer($e, '2026-10-09');

        $this->assertSame([], array_filter($stand['punkte'], fn ($p) => str_starts_with($p['code'], 'dokument:')));
    }
}
```

- [ ] **Step 3: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentLeserTest|OffenePunkteDokumenteTest"`
Expected: FAIL, `DokumentLeser` nicht gefunden.

- [ ] **Step 4: DokumentLeser schreiben**

`src/Services/DokumentLeser.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;

/**
 * Die Dokumente EINES Menschen (Spec §5.1): alle Zustellungen, deren
 * Anstellung im Personen-Scope liegt, ohne zurueckgezogene, ohne geloeschte
 * Dokumente. Fremde IDs aus der Anfrage laufen immer ueber empfaenger() und
 * damit ueber dieselbe Menge — es gibt keinen zweiten Weg zu einer Zeile.
 */
class DokumentLeser
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver())
    {
    }

    /**
     * @return list<array{recipient_id:int, uuid:string, title:string, category:string, category_label:string, action:string, status:string, status_label:string, offen:bool, first_viewed_at:?string, acknowledged_at:?string, signed_at:?string, provided_at:string}>
     */
    public function fuerMitarbeiter(RecEmployee $employee): array
    {
        return $this->sichtbare($employee)
            ->map(fn (RecDocumentRecipient $z) => $this->zeile($z))
            ->all();
    }

    public function empfaenger(RecEmployee $employee, int $recipientId): ?RecDocumentRecipient
    {
        return $this->sichtbare($employee)->firstWhere('id', $recipientId);
    }

    /**
     * Offene Dokumente als Punkte. Mit $ohneFrischGemeldeteTage (die
     * Einsatz-Pruefung) fallen Zustellungen weg, die in den letzten N Tagen
     * ihre eigene WhatsApp bekommen haben — sonst kaeme eine Stunde nach
     * "im Portal liegt etwas" noch "fuer deinen Einsatz ist ein Punkt offen"
     * zum selben Dokument. Fehlversuche (notify_error, kein notified_at)
     * bleiben drin: dort ist die Einsatz-Pruefung der zweite Fang.
     *
     * @return list<array{code:string, label:string, status:string, ko:bool, punkt:string, text:string}>
     */
    public function offenePunkte(RecEmployee $employee, ?int $ohneFrischGemeldeteTage = null, ?string $heute = null): array
    {
        $grenze = $ohneFrischGemeldeteTage === null
            ? null
            : \Carbon\Carbon::parse($heute ?? now()->toDateString())->subDays($ohneFrischGemeldeteTage);
        $punkte = [];
        foreach ($this->sichtbare($employee) as $z) {
            $aktion = (string) $z->document->action;
            if (!DokumentKategorie::brauchtHandlung($aktion) || DokumentStatus::istErledigt($z->zeitstempel(), $aktion)) {
                continue;
            }
            if ($grenze !== null && $z->notified_at !== null && $z->notified_at->greaterThan($grenze)) {
                continue;
            }
            $punkte[] = [
                'code'   => 'dokument:' . $z->id,
                'label'  => (string) $z->document->title,
                'status' => DokumentStatus::fuer($z->zeitstempel(), $aktion),
                'ko'     => false,
                'punkt'  => 'crit',
                'text'   => $aktion === DokumentKategorie::AKTION_SIGN ? 'Lesen und unterschreiben' : 'Lesen und bestätigen',
            ];
        }

        return $punkte;
    }

    /** @return \Illuminate\Support\Collection<int, RecDocumentRecipient> neueste zuerst, Dokument geladen */
    private function sichtbare(RecEmployee $employee)
    {
        $ids = $this->scope->forEmployee($employee)['ids'];

        return RecDocumentRecipient::query()
            ->whereIn('rec_employee_id', $ids)
            ->whereNull('withdrawn_at')
            ->whereHas('document')            // SoftDeletes: geloeschte Dokumente fallen hier raus
            ->with('document')
            ->orderByDesc('id')
            ->get()
            ->values();
    }

    private function zeile(RecDocumentRecipient $z): array
    {
        $aktion = (string) $z->document->action;
        $status = DokumentStatus::fuer($z->zeitstempel(), $aktion);

        return [
            'recipient_id'    => (int) $z->id,
            'uuid'            => (string) $z->uuid,
            'title'           => (string) $z->document->title,
            'category'        => (string) $z->document->category,
            'category_label'  => DokumentKategorie::label((string) $z->document->category),
            'action'          => $aktion,
            'status'          => $status,
            'status_label'    => DokumentStatus::label($status),
            'offen'           => DokumentKategorie::brauchtHandlung($aktion) && !DokumentStatus::istErledigt($z->zeitstempel(), $aktion),
            'first_viewed_at' => $z->first_viewed_at?->format('Y-m-d H:i:s'),
            'acknowledged_at' => $z->acknowledged_at?->format('Y-m-d H:i:s'),
            'signed_at'       => $z->signed_at?->format('Y-m-d H:i:s'),
            'provided_at'     => $z->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }
}
```

- [ ] **Step 5: OffenePunkte erweitern**

In `src/Services/OffenePunkte.php` den Konstruktor und `fuer()` ändern:

```php
    public function __construct(
        private readonly ProofReader $nachweise = new ProofReader(),
        private readonly PersonScopeResolver $scope = new PersonScopeResolver(),
        private readonly DokumentLeser $dokumente = new DokumentLeser(),
    ) {
    }
```

`fuer()` wird zur dünnen Hülle über einer privaten Methode, und `fuerTrigger()` kommt dazu:

```php
    /**
     * @return array{punkte: list<array{code:string, label:string, status:string, ko:bool}>, einsatz: ?array{datum:string, taetigkeit:?string, event:?string}, gesperrt: bool}
     */
    public function fuer(RecEmployee $employee, ?string $heute = null): array
    {
        return $this->stand($employee, $heute, null);
    }

    /**
     * Dieselbe Form fuer die Einsatz-Pruefung — ohne Dokumente, die in den
     * letzten PAUSE_TAGE ihre eigene WhatsApp bekommen haben (Spec Dokumente,
     * Nachtrag 09.10.2026). Nachweise und Pflichtangaben unveraendert.
     */
    public function fuerTrigger(RecEmployee $employee, ?string $heute = null): array
    {
        return $this->stand($employee, $heute, EinsatzBezug::PAUSE_TAGE);
    }

    private function stand(RecEmployee $employee, ?string $heute, ?int $ohneFrischGemeldeteTage): array
    {
        $heute = $heute ?? now()->toDateString();
        $checkliste = $this->nachweise->checklist($employee, $heute);

        $punkte = [];
        foreach ($checkliste as $zeile) {
            if (($zeile['offen'] ?? false) !== true) {
                continue;
            }
            $punkte[] = [
                'code'   => $zeile['code'],
                'label'  => $zeile['label'],
                'status' => $zeile['status'],
                'ko'     => ProofTypes::istKo($zeile['code']),
            ];
        }

        // Zweite Quelle (Spec Dokumente 2026-10-08, §5.2): offene Dokumente
        // sind Punkte mit Code 'dokument:<id>', ko=false, Label und Satz
        // bringen sie selbst mit — ProofTypes kennt sie nicht und wird fuer
        // sie nicht gefragt. gesperrt bleibt allein Sache der Arbeitserlaubnis.
        $punkte = array_merge($punkte, $this->dokumente->offenePunkte($employee, $ohneFrischGemeldeteTage, $heute));

        return [
            'punkte'   => $punkte,
            'einsatz'  => $this->naechsterEinsatz($employee, $heute),
            'gesperrt' => Arbeitserlaubnis::istGesperrt($checkliste),
        ];
    }
```

Der bisherige Rumpf von `fuer()` (Zeilen 77–97) geht vollständig in `stand()` auf; `naechsterEinsatz()` bleibt. In `src/Console/Commands/EinsatzPruefung.php` Zeile 355 `$stand   = $punkteLeser->fuer($rep, $heute);` durch `$stand   = $punkteLeser->fuerTrigger($rep, $heute);` ersetzen, mit dem Kommentar `// fuerTrigger: frisch per WhatsApp gemeldete Dokumente bleiben PAUSE_TAGE still (Spec Dokumente §6).`

- [ ] **Step 6: Tests grün, plus die Nachbarn**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentLeserTest|OffenePunkteDokumenteTest|OffenePunkte|EinsatzPruefung|PortalAufgaben"`
Expected: alles `OK`. Die bestehenden OffenePunkte-/EinsatzPruefung-Tests müssen unverändert grün bleiben (ihre Tabellen kennen keine Dokumente, der Leser liefert dort eine leere Liste; falls ein Test ohne `rec_document_recipients`-Tabelle läuft, dessen Schema um die beiden Migrationen aus Task 1 ergänzen).

- [ ] **Step 7: Commit**

```bash
git add tests/Integration/DokumenteHarness.php src/Services/DokumentLeser.php src/Services/OffenePunkte.php src/Console/Commands/EinsatzPruefung.php tests/Integration/DokumentLeserTest.php tests/Integration/OffenePunkteDokumenteTest.php
git commit -m "feat(recruiting): DokumentLeser — Portal-Sicht ueber den Personen-Scope, offene Dokumente als Punkte, Trigger schweigt 7 Tage nach eigener WhatsApp" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: DokumentUnterschrift (öffnen, bestätigen, unterschreiben)

**Files:**
- Create: `src/Services/DokumentUnterschrift.php`
- Test: `tests/Integration/DokumentUnterschriftTest.php`

**Interfaces:**
- Produces: `DokumentUnterschrift::__construct(?DokumentSpeicher $speicher = null)` (fauler Default `DokumentSpeicher::default()`), `::oeffnen(RecDocumentRecipient $z): void` (setzt `first_viewed_at` einmalig), `::bestaetigen(RecDocumentRecipient $z, bool $gelesen, bool $duzen): ?string`, `::unterschreiben(RecDocumentRecipient $z, bool $gelesen, string $signatureData, bool $duzen): ?string` (null = ok, sonst Text für den Menschen). Konstante `SIGNATUR_PRAEFIX = 'data:image/png;base64,'`.

- [ ] **Step 1: Test schreiben**

`tests/Integration/DokumentUnterschriftTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentUnterschrift;

final class DokumentUnterschriftTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\nHallo";
    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function zustellung(string $aktion = 'sign'): RecDocumentRecipient
    {
        $e = $this->anstellung();
        $ablage = $this->speicher->ablegen(3, 'u-1', self::PDF);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'T', 'category' => 'contract', 'action' => $aktion,
            'disk' => $ablage['disk'], 'stored_path' => $ablage['stored_path'], 'original_filename' => 'x.pdf',
            'file_sha256' => $ablage['sha256'], 'file_size' => $ablage['size'],
        ]);

        return RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
    }

    private function dienst(): DokumentUnterschrift
    {
        return new DokumentUnterschrift($this->speicher);
    }

    public function test_oeffnen_setzt_gesehen_genau_einmal(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        $erstes = $z->fresh()->first_viewed_at;
        $this->assertNotNull($erstes);

        DB::table('rec_document_recipients')->where('id', $z->id)->update(['first_viewed_at' => '2026-01-01 00:00:00']);
        $this->dienst()->oeffnen($z->fresh());

        $this->assertSame('2026-01-01 00:00:00', $z->fresh()->first_viewed_at->format('Y-m-d H:i:s'));
    }

    public function test_bestaetigen_braucht_oeffnen_und_haken(): void
    {
        $z = $this->zustellung('acknowledge');

        $this->assertSame('Bitte zuerst das Dokument öffnen.', $this->dienst()->bestaetigen($z, true, true));
        $this->dienst()->oeffnen($z);
        $this->assertSame('Bitte bestätige, dass du das Dokument gelesen hast.', $this->dienst()->bestaetigen($z->fresh(), false, true));
        $this->assertSame('Bitte bestätigen Sie, dass Sie das Dokument gelesen haben.', $this->dienst()->bestaetigen($z->fresh(), false, false));
        $this->assertNull($z->fresh()->acknowledged_at);

        $this->assertNull($this->dienst()->bestaetigen($z->fresh(), true, true));
        $this->assertNotNull($z->fresh()->acknowledged_at);
    }

    public function test_bestaetigen_ist_idempotent(): void
    {
        $z = $this->zustellung('acknowledge');
        $this->dienst()->oeffnen($z);
        $this->dienst()->bestaetigen($z->fresh(), true, true);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['acknowledged_at' => '2026-01-01 00:00:00']);

        $this->assertNull($this->dienst()->bestaetigen($z->fresh(), true, true));
        $this->assertSame('2026-01-01 00:00:00', $z->fresh()->acknowledged_at->format('Y-m-d H:i:s'));
    }

    public function test_unterschreiben_speichert_bild_und_zeitpunkte(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);

        $this->assertNull($this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));

        $z = $z->fresh();
        $this->assertNotNull($z->signed_at);
        $this->assertNotNull($z->acknowledged_at, 'Unterschrift schliesst Kenntnisnahme ein');
        $this->assertSame(self::SIG, $z->signature_data);
    }

    public function test_leere_unterschrift_wird_abgelehnt(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);

        $this->assertSame('Bitte unterschreibe im Feld.', $this->dienst()->unterschreiben($z->fresh(), true, '', true));
        $this->assertSame('Bitte unterschreibe im Feld.', $this->dienst()->unterschreiben($z->fresh(), true, 'data:text/plain;base64,QQ==', true));
        $this->assertSame('Bitte unterschreibe im Feld.', $this->dienst()->unterschreiben($z->fresh(), true, 'data:image/png;base64,', true));
        $this->assertNull($z->fresh()->signed_at);
    }

    public function test_manipulierte_datei_verhindert_unterschrift(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        file_put_contents($this->root . '/' . $z->document->stored_path, "%PDF-1.4\nManipuliert");

        $fehler = $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true);

        $this->assertSame('Das Dokument kann gerade nicht unterschrieben werden. Bitte melde dich bei uns.', $fehler);
        $this->assertNull($z->fresh()->signed_at);
        $this->assertNull($z->fresh()->signature_data);
    }

    public function test_fehlende_datei_verhindert_unterschrift(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        unlink($this->root . '/' . $z->document->stored_path);

        $this->assertNotNull($this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));
        $this->assertNull($z->fresh()->signed_at);
    }

    public function test_zweite_unterschrift_ueberschreibt_nicht(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['signed_at' => '2026-01-01 00:00:00', 'signature_data' => 'ERSTE']);

        $this->assertNull($this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));
        $z = $z->fresh();
        $this->assertSame('2026-01-01 00:00:00', $z->signed_at->format('Y-m-d H:i:s'));
        $this->assertSame('ERSTE', $z->signature_data);
    }

    public function test_zurueckgezogen_wird_freundlich_abgewiesen(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['withdrawn_at' => '2026-10-09 10:00:00']);

        $this->assertSame('Dieses Dokument wurde zurückgezogen.', $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));
        $this->assertSame('Dieses Dokument wurde zurückgezogen.', $this->dienst()->bestaetigen($z->fresh(), true, true));
        $this->assertNull($z->fresh()->signed_at);
    }

    public function test_unterschrift_schreibt_nie_auf_rec_employees(): void
    {
        $z = $this->zustellung();
        $vorher = DB::table('rec_employees')->where('id', $z->rec_employee_id)->value('zas_changed_at');
        $this->dienst()->oeffnen($z);
        $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true);
        $this->assertSame($vorher, DB::table('rec_employees')->where('id', $z->rec_employee_id)->value('zas_changed_at'));
    }
}
```

- [ ] **Step 2: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentUnterschriftTest`
Expected: FAIL, Klasse nicht gefunden.

- [ ] **Step 3: Service schreiben**

`src/Services/DokumentUnterschrift.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocumentRecipient;

/**
 * Die drei Handlungen des Mitarbeiters (Spec §5.3): oeffnen, bestaetigen,
 * unterschreiben. Alle Zeitstempel ueber den Query Builder, erster Zeitpunkt
 * gewinnt (die WHERE-Bedingung "IS NULL" ist die Idempotenz, nicht ein
 * vorheriges Lesen). Die Pruefsumme der gespeicherten Datei wird VOR der
 * Unterschrift gegen file_sha256 gehalten — der Wachhund ueber der
 * Unveraenderlichkeit.
 */
class DokumentUnterschrift
{
    public const SIGNATUR_PRAEFIX = 'data:image/png;base64,';

    /** Nullable mit faulem Default: app(DokumentUnterschrift::class) baut ihn ohne Argument, Tests geben den Speicher mit. */
    public function __construct(private ?DokumentSpeicher $speicher = null)
    {
    }

    private function speicher(): DokumentSpeicher
    {
        return $this->speicher ??= DokumentSpeicher::default();
    }

    public function oeffnen(RecDocumentRecipient $z): void
    {
        DB::table('rec_document_recipients')
            ->where('id', $z->id)
            ->whereNull('first_viewed_at')
            ->update(['first_viewed_at' => now(), 'updated_at' => now()]);
    }

    public function bestaetigen(RecDocumentRecipient $z, bool $gelesen, bool $duzen): ?string
    {
        $z = $z->fresh();
        if ($fehler = $this->vorbedingungen($z, $gelesen, $duzen)) {
            return $fehler;
        }
        DB::table('rec_document_recipients')
            ->where('id', $z->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'updated_at' => now()]);

        return null;
    }

    public function unterschreiben(RecDocumentRecipient $z, bool $gelesen, string $signatureData, bool $duzen): ?string
    {
        $z = $z->fresh();
        if ($fehler = $this->vorbedingungen($z, $gelesen, $duzen)) {
            return $fehler;
        }
        if (!self::istUnterschriftsbild($signatureData)) {
            return $duzen ? 'Bitte unterschreibe im Feld.' : 'Bitte unterschreiben Sie im Feld.';
        }

        $dokument = $z->document;
        $aktuell = $this->speicher()->pruefsumme((string) $dokument->stored_path);
        if ($aktuell === null || !hash_equals((string) $dokument->file_sha256, $aktuell)) {
            Log::error('recruiting.dokumente.pruefsumme_abweichung', [
                'document_id' => $dokument->id, 'recipient_id' => $z->id,
                'erwartet' => $dokument->file_sha256, 'ist' => $aktuell,
            ]);

            return $duzen
                ? 'Das Dokument kann gerade nicht unterschrieben werden. Bitte melde dich bei uns.'
                : 'Das Dokument kann gerade nicht unterschrieben werden. Bitte melden Sie sich bei uns.';
        }

        DB::transaction(function () use ($z, $signatureData) {
            DB::table('rec_document_recipients')
                ->where('id', $z->id)
                ->whereNull('signed_at')
                ->update(['signed_at' => now(), 'signature_data' => $signatureData, 'updated_at' => now()]);
            DB::table('rec_document_recipients')
                ->where('id', $z->id)
                ->whereNull('acknowledged_at')
                ->update(['acknowledged_at' => now()]);
        });

        return null;
    }

    public static function istUnterschriftsbild(string $signatureData): bool
    {
        if (!str_starts_with($signatureData, self::SIGNATUR_PRAEFIX)) {
            return false;
        }
        $rumpf = substr($signatureData, strlen(self::SIGNATUR_PRAEFIX));
        $bytes = base64_decode($rumpf, true);

        return $bytes !== false && str_starts_with($bytes, "\x89PNG");
    }

    private function vorbedingungen(?RecDocumentRecipient $z, bool $gelesen, bool $duzen): ?string
    {
        if ($z === null || $z->withdrawn_at !== null || $z->document === null) {
            return 'Dieses Dokument wurde zurückgezogen.';
        }
        if ($z->first_viewed_at === null) {
            return 'Bitte zuerst das Dokument öffnen.';
        }
        if (!$gelesen) {
            return $duzen
                ? 'Bitte bestätige, dass du das Dokument gelesen hast.'
                : 'Bitte bestätigen Sie, dass Sie das Dokument gelesen haben.';
        }

        return null;
    }
}
```

- [ ] **Step 4: Test grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentUnterschriftTest`
Expected: `OK (10 tests)`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/DokumentUnterschrift.php tests/Integration/DokumentUnterschriftTest.php
git commit -m "feat(recruiting): DokumentUnterschrift — oeffnen, bestaetigen, unterschreiben mit Pruefsummen-Wachhund" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Portal-Download (Route + Controller)

**Files:**
- Create: `src/Http/Controllers/DokumentDownloadController.php`
- Modify: `routes/public.php` (nach der Route `recruiting.public.portal-shell`, Zeile 59–60)
- Test: `tests/Integration/DokumentDownloadControllerTest.php`

**Interfaces:**
- Produces: Route `GET /recruiting/mitarbeiter/dokument/{uuid}` mit Namen `recruiting.public.dokument` (`uuid` = `rec_document_recipients.uuid`); Controller `__invoke(Request $request, string $uuid)`; Hilfsmethode `DokumentDownloadController::sitzungDeckt(array $scopeIds, callable $hatSitzung): bool` (statisch, rein, testbar).
- Consumes: `DokumentZugriff::entscheide()`, `PortalAuth::sessionKey()`, `PersonScopeResolver`, `DokumentSpeicher::default()`.

- [ ] **Step 1: Route eintragen**

In `routes/public.php` nach den Zeilen

```php
Route::get('/mitarbeiter/neu/{token}', \Platform\Recruiting\Livewire\Public\PortalShell::class)
    ->name('recruiting.public.portal-shell');
```

einfügen:

```php
// Dokument-Download aus dem neuen Portal (Spec Dokumente 2026-10-08, §5.3):
// keine Token-URL, sondern die verifizierte Portal-Sitzung — fuer die
// Anstellung der Zustellung oder eine ihrer Schwester-Anstellungen.
Route::get('/mitarbeiter/dokument/{uuid}', \Platform\Recruiting\Http\Controllers\DokumentDownloadController::class)
    ->name('recruiting.public.dokument')
    ->where('uuid', '[0-9a-fA-F-]{36}');
```

- [ ] **Step 2: Test schreiben**

`tests/Integration/DokumentDownloadControllerTest.php` — testet den reinen Sitzungs-Abgleich und die Route; die HTTP-Antwort selbst ist Laravel (Storage::response) und wird nicht nachgebaut.

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\DokumentDownloadController;
use Platform\Recruiting\Services\PortalAuth;

final class DokumentDownloadControllerTest extends TestCase
{
    public function test_sitzung_deckt_eigene_oder_schwester_anstellung(): void
    {
        $sitzung = [PortalAuth::sessionKey(160) => true];
        $hat = fn (string $key) => isset($sitzung[$key]);

        $this->assertTrue(DokumentDownloadController::sitzungDeckt([160, 223], $hat));
        $this->assertTrue(DokumentDownloadController::sitzungDeckt([223, 160], $hat));
        $this->assertFalse(DokumentDownloadController::sitzungDeckt([223], $hat));
        $this->assertFalse(DokumentDownloadController::sitzungDeckt([], $hat));
    }

    public function test_route_ist_registriert_und_traegt_die_uuid_am_ende(): void
    {
        $container = new Container();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $url = new UrlGenerator($router->getRoutes(), Request::create('https://mitarbeiter.rheingedeck.de'));

        $this->assertSame(
            'https://mitarbeiter.rheingedeck.de/recruiting/mitarbeiter/dokument/0192a3b4-0000-7000-8000-000000000001',
            $url->route('recruiting.public.dokument', ['uuid' => '0192a3b4-0000-7000-8000-000000000001'])
        );
        Facade::clearResolvedInstances();
    }
}
```

- [ ] **Step 3: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentDownloadControllerTest`
Expected: FAIL, Klasse nicht gefunden.

- [ ] **Step 4: Controller schreiben**

`src/Http/Controllers/DokumentDownloadController.php`:

```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Support\DokumentZugriff;

/**
 * PDF-Auslieferung an den Mitarbeiter (Spec §5.3). Keine Token-URL: die
 * verifizierte Portal-Sitzung muss fuer die Anstellung der Zustellung oder
 * eine Schwester-Anstellung derselben Person stehen (PersonScopeResolver).
 * Entscheidung in DokumentZugriff (rein, getestet), Reihenfolge wie
 * DispoAttachmentController: nie ein Existenz-Orakel.
 *
 * "Gesehen am" setzt NICHT dieser Controller, sondern PortalShell::oeffneDokument()
 * — der Download ist das Ergebnis des Oeffnens, nicht das Oeffnen selbst.
 */
class DokumentDownloadController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        $zustellung = RecDocumentRecipient::query()->where('uuid', $uuid)->with('document')->first();
        $dokument = $zustellung?->document;                    // null bei SoftDelete
        $employee = $zustellung ? RecEmployee::find($zustellung->rec_employee_id) : null;

        $scopeIds = $employee ? app(PersonScopeResolver::class)->forEmployee($employee)['ids'] : [];
        $sitzungGueltig = self::sitzungDeckt($scopeIds, fn (string $key) => $request->session()->has($key));
        $gesperrt = $scopeIds !== []
            && RecEmployee::query()->whereIn('id', $scopeIds)->whereNotNull('portal_locked_at')->exists();

        $code = DokumentZugriff::entscheide(
            $zustellung !== null && $dokument !== null && $employee !== null,
            $sitzungGueltig,
            $gesperrt,
            $zustellung?->withdrawn_at !== null,
        );
        abort_if($code !== 200, $code);

        return Storage::disk($dokument->disk)->response(
            $dokument->stored_path,
            $dokument->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }

    /**
     * @param  list<int> $scopeIds
     * @param  callable(string):bool $hatSitzung
     */
    public static function sitzungDeckt(array $scopeIds, callable $hatSitzung): bool
    {
        foreach ($scopeIds as $id) {
            if ($hatSitzung(PortalAuth::sessionKey((int) $id))) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Test grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentDownloadControllerTest|PortalShellDokumenteTest"`
Expected: `OK`. (`PortalShellDokumenteTest` lädt `routes/public.php` ebenfalls und muss die neue Route vertragen.)

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/DokumentDownloadController.php routes/public.php tests/Integration/DokumentDownloadControllerTest.php
git commit -m "feat(recruiting): Portal-Download fuer Dokumente ueber die verifizierte Sitzung" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 11: Portal — Dokumente-Block, Blatt zum Lesen/Bestätigen/Unterschreiben, Start-Klick

**Files:**
- Modify: `src/Livewire/Public/PortalShell.php` (Imports, Eigenschaften nach Zeile 110, neue Methoden, `ansichtsDaten()`)
- Modify: `resources/views/livewire/public/portal-shell.blade.php` (Start-Kasten Zeilen 247–275, Dokumente-Pane vor Zeile 368, neues Blatt nach dem Gruppen-Blatt)
- Test: `tests/Integration/PortalShellMitarbeiterDokumenteTest.php`

**Interfaces:**
- Produces (PortalShell): Eigenschaften `public ?int $dokumentId = null; public bool $dokumentGelesen = false; public string $dokumentUnterschrift = ''; public string $dokumentFehler = ''; public string $dokumentMeldung = '';`; Methoden `oeffneDokument(int $recipientId): void`, `schliesseDokument(): void`, `bestaetigeDokument(): void`, `unterschreibeDokument(): void`; `ansichtsDaten()` liefert zusätzlich `'mitarbeiterDokumente' => list<...>` (Form von `DokumentLeser::fuerMitarbeiter`) und `'dokumentBlatt' => ?array{recipient_id:int, title:string, action:string, status:string, download_url:string, gesehen:bool, erledigt:bool}`; `'offen'` zählt offene Dokumente mit.
- Consumes: `DokumentLeser`, `DokumentUnterschrift` (über `app(DokumentUnterschrift::class)`, siehe Hinweis unten), Route `recruiting.public.dokument`.

- [ ] **Step 1: Test schreiben**

`tests/Integration/PortalShellMitarbeiterDokumenteTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\DokumentUnterschrift;

/**
 * Das Portal als Huelle um DokumentLeser/DokumentUnterschrift (Spec §5.3):
 * jede Aktion prueft die Empfaenger-ID gegen den Scope des angemeldeten
 * Menschen, nie gegen eine ID aus dem Zustand. Muster PortalShellDokumenteTest:
 * `new PortalShell()` + ReflectionMethod, Router fuer route().
 */
final class PortalShellMitarbeiterDokumenteTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\nHallo";
    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $container = Container::getInstance();
        $container->instance(DokumentUnterschrift::class, new DokumentUnterschrift($this->speicher));
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $container->instance('url', new UrlGenerator($router->getRoutes(), Request::create('https://mitarbeiter.rheingedeck.de')));
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([DokumentUnterschrift::class, 'router', 'url'] as $n) {
            $c->forgetInstance($n);
        }
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';

        return $shell;
    }

    private function zustellung(RecEmployee $ma, string $aktion = 'sign'): RecDocumentRecipient
    {
        $ablage = $this->speicher->ablegen(3, 'p-' . uniqid(), self::PDF);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => $aktion,
            'disk' => $ablage['disk'], 'stored_path' => $ablage['stored_path'], 'original_filename' => 'h.pdf',
            'file_sha256' => $ablage['sha256'], 'file_size' => $ablage['size'],
        ]);

        return RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $ma->id]);
    }

    private function privat(object $o, string $m, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($o, $m);
        $ref->setAccessible(true);

        return $ref->invoke($o, ...$args);
    }

    public function test_oeffnen_setzt_gesehen_und_zeigt_das_blatt_mit_download_url(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma);
        $shell = $this->shell($ma);

        $shell->oeffneDokument($z->id);

        $this->assertSame($z->id, $shell->dokumentId);
        $this->assertNotNull($z->fresh()->first_viewed_at);
        $blatt = $this->privat($shell, 'dokumentBlatt', [$ma]);
        $this->assertSame('Hausordnung', $blatt['title']);
        $this->assertSame('sign', $blatt['action']);
        $this->assertTrue($blatt['gesehen']);
        $this->assertFalse($blatt['erledigt']);
        $this->assertSame('https://mitarbeiter.rheingedeck.de/recruiting/mitarbeiter/dokument/' . $z->uuid, $blatt['download_url']);
    }

    public function test_fremde_zustellung_oeffnet_nichts(): void
    {
        $ma = $this->anstellung();
        $fremd = $this->anstellung();
        $z = $this->zustellung($fremd);
        $shell = $this->shell($ma);

        $shell->oeffneDokument($z->id);

        $this->assertNull($shell->dokumentId);
        $this->assertNull($z->fresh()->first_viewed_at);
    }

    public function test_unterschreiben_ueber_die_huelle(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma);
        $shell = $this->shell($ma);
        $shell->oeffneDokument($z->id);

        $shell->dokumentGelesen = false;
        $shell->dokumentUnterschrift = self::SIG;
        $shell->unterschreibeDokument();
        $this->assertSame('Bitte bestätige, dass du das Dokument gelesen hast.', $shell->dokumentFehler);
        $this->assertNull($z->fresh()->signed_at);

        $shell->dokumentGelesen = true;
        $shell->unterschreibeDokument();
        $this->assertSame('', $shell->dokumentFehler);
        $this->assertNotNull($z->fresh()->signed_at);
        $this->assertNull($shell->dokumentId, 'Blatt schliesst sich nach Erfolg');
        $this->assertNotSame('', $shell->dokumentMeldung);
    }

    public function test_bestaetigen_ueber_die_huelle(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma, 'acknowledge');
        $shell = $this->shell($ma);
        $shell->oeffneDokument($z->id);
        $shell->dokumentGelesen = true;

        $shell->bestaetigeDokument();

        $this->assertNotNull($z->fresh()->acknowledged_at);
        $this->assertNull($shell->dokumentId);
    }

    public function test_aktion_mit_manipulierter_id_schreibt_nichts(): void
    {
        $ma = $this->anstellung();
        $fremd = $this->anstellung();
        $eigene = $this->zustellung($ma);
        $fremde = $this->zustellung($fremd);
        $shell = $this->shell($ma);
        $shell->oeffneDokument($eigene->id);
        $shell->dokumentId = $fremde->id;          // $wire.set von aussen
        $shell->dokumentGelesen = true;
        $shell->dokumentUnterschrift = self::SIG;

        $shell->unterschreibeDokument();

        $this->assertNull($fremde->fresh()->signed_at);
        $this->assertNull($eigene->fresh()->signed_at);
        $this->assertNull($shell->dokumentId);
    }

    public function test_mitarbeiter_dokumente_liste_und_offen_zaehler(): void
    {
        $ma = $this->anstellung();
        $this->zustellung($ma, 'sign');
        $this->zustellung($ma, 'none');
        $shell = $this->shell($ma);

        $liste = $this->privat($shell, 'mitarbeiterDokumente', [$ma]);

        $this->assertCount(2, $liste);
        $this->assertSame(1, count(array_filter($liste, fn ($d) => $d['offen'])));
    }
}
```

- [ ] **Step 2: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellMitarbeiterDokumenteTest`
Expected: FAIL, `Call to undefined method ... oeffneDokument()`.

- [ ] **Step 3: PortalShell erweitern**

Imports ergänzen (nach `use Platform\Recruiting\Services\OffenePunkte;`):

```php
use Platform\Recruiting\Services\DokumentLeser;
use Platform\Recruiting\Services\DokumentUnterschrift;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;
```

Eigenschaften nach `public string $profilMeldung = '';` (Zeile 110):

```php
    /**
     * Das offene Dokument-Blatt (Spec Dokumente §5.3). dokumentId ist die
     * Empfaenger-ID und bewusst NICHT #[Locked]: jede Aktion prueft sie bei
     * JEDEM Aufruf ueber DokumentLeser::empfaenger() gegen den Scope des
     * angemeldeten Menschen. Ein fremder Wert fuehrt zu nichts -- nicht zu
     * einem Fehler, sondern zu keiner Schreibung (Lehre aus dem Auth-Bypass).
     */
    public ?int $dokumentId = null;
    public bool $dokumentGelesen = false;
    public string $dokumentUnterschrift = '';
    public string $dokumentFehler = '';
    public string $dokumentMeldung = '';
```

Methoden (nach `schliesseGruppe()`):

```php
    public function oeffneDokument(int $recipientId): void
    {
        $this->dokumentFehler = '';
        $this->dokumentMeldung = '';
        $this->dokumentGelesen = false;
        $this->dokumentUnterschrift = '';
        $this->dokumentId = null;

        $employee = $this->berechtigterMitarbeiter();
        $z = $employee ? app(DokumentLeser::class)->empfaenger($employee, $recipientId) : null;
        if ($z === null) {
            return;
        }

        app(DokumentUnterschrift::class)->oeffnen($z);
        $this->dokumentId = (int) $z->id;
    }

    public function schliesseDokument(): void
    {
        $this->dokumentId = null;
        $this->dokumentGelesen = false;
        $this->dokumentUnterschrift = '';
        $this->dokumentFehler = '';
    }

    public function bestaetigeDokument(): void
    {
        $z = $this->eigeneZustellung();
        if ($z === null) {
            return;
        }
        $fehler = app(DokumentUnterschrift::class)->bestaetigen($z, $this->dokumentGelesen, $this->duzen);
        $this->dokumentAbschliessen($fehler, $this->duzen ? 'Danke, wir haben deine Bestätigung.' : 'Danke, wir haben Ihre Bestätigung.');
    }

    public function unterschreibeDokument(): void
    {
        $z = $this->eigeneZustellung();
        if ($z === null) {
            return;
        }
        $fehler = app(DokumentUnterschrift::class)->unterschreiben($z, $this->dokumentGelesen, (string) $this->dokumentUnterschrift, $this->duzen);
        $this->dokumentAbschliessen($fehler, $this->duzen ? 'Danke, deine Unterschrift ist gespeichert.' : 'Danke, Ihre Unterschrift ist gespeichert.');
    }

    /** Die Zustellung zur dokumentId -- nur wenn sie dem angemeldeten Menschen gehoert; sonst schliesst sich das Blatt stumm. */
    private function eigeneZustellung(): ?\Platform\Recruiting\Models\RecDocumentRecipient
    {
        $employee = $this->berechtigterMitarbeiter();
        $z = ($employee !== null && $this->dokumentId !== null)
            ? app(DokumentLeser::class)->empfaenger($employee, (int) $this->dokumentId)
            : null;
        if ($z === null) {
            $this->schliesseDokument();
        }

        return $z;
    }

    private function dokumentAbschliessen(?string $fehler, string $meldung): void
    {
        if ($fehler !== null) {
            $this->dokumentFehler = $fehler;

            return;
        }
        $this->schliesseDokument();
        $this->dokumentMeldung = $meldung;
    }

    /** @return list<array> Form von DokumentLeser::fuerMitarbeiter() */
    private function mitarbeiterDokumente(RecEmployee $employee): array
    {
        return app(DokumentLeser::class)->fuerMitarbeiter($employee);
    }

    /** @return ?array{recipient_id:int, title:string, action:string, status:string, download_url:string, gesehen:bool, erledigt:bool} */
    private function dokumentBlatt(RecEmployee $employee): ?array
    {
        if ($this->dokumentId === null) {
            return null;
        }
        $z = app(DokumentLeser::class)->empfaenger($employee, (int) $this->dokumentId);
        if ($z === null) {
            return null;
        }
        $aktion = (string) $z->document->action;

        return [
            'recipient_id' => (int) $z->id,
            'title'        => (string) $z->document->title,
            'action'       => $aktion,
            'status'       => DokumentStatus::fuer($z->zeitstempel(), $aktion),
            'download_url' => route('recruiting.public.dokument', ['uuid' => $z->uuid]),
            'gesehen'      => $z->first_viewed_at !== null,
            'erledigt'     => DokumentStatus::istErledigt($z->zeitstempel(), $aktion),
        ];
    }
```

In `ansichtsDaten()` vor dem `return [` ergänzen:

```php
        $mitarbeiterDokumente = $employee ? $this->mitarbeiterDokumente($employee) : [];
        $offeneDokumente = count(array_filter($mitarbeiterDokumente, static fn (array $d) => $d['offen']));
```

und im zurückgegebenen Array `'offen' => $offenAusNachweisen + count($pflichtAufgaben),` ersetzen durch `'offen' => $offenAusNachweisen + count($pflichtAufgaben) + $offeneDokumente,` sowie zwei Schlüssel anhängen:

```php
            'mitarbeiterDokumente' => $mitarbeiterDokumente,
            'dokumentBlatt'        => $employee ? $this->dokumentBlatt($employee) : null,
```

- [ ] **Step 4: Blade — Start-Kasten**

In `resources/views/livewire/public/portal-shell.blade.php`, Zeilen 247–275 (die Schleife über `$offenePunkte['punkte']`): den `@php`-Block um den Klick ergänzen und das `wire:click` austauschen.

```blade
                        @foreach ($offenePunkte['punkte'] as $offenerPunkt)
                            @php
                                $offenerPunktKlasse = $offenerPunkt['ko'] ? 'aufgabe aufgabe-ko' : 'aufgabe';
                                // Dokument-Punkte oeffnen das Dokument-Blatt, Nachweise das Upload-Blatt.
                                // Vorberechnet, kein @if im Attribut (Hausregel).
                                $offenerPunktKlick = str_starts_with($offenerPunkt['code'], 'dokument:')
                                    ? 'oeffneDokument(' . (int) substr($offenerPunkt['code'], 9) . ')'
                                    : "oeffneUpload('" . $offenerPunkt['code'] . "')";
                            @endphp
                            <div class="{{ $offenerPunktKlasse }}" wire:click="{{ $offenerPunktKlick }}">
```

Der Rest der Zeile (dot, t, s, chev) bleibt wie er ist. Bestehende Kommentare im `@php`-Block (Zeilen 249–251) behalten.

- [ ] **Step 5: Blade — Dokumente-Pane**

Vor `<div>` + `<div class="sec-label">{{ $duzen ? 'Deine Verträge' : 'Ihre Verträge' }} ...` (Zeile 368) einfügen:

```blade
                @if ($dokumentMeldung !== '')
                    <div class="alert ok">
                        <span class="dot ok" style="margin-top:6px"></span>
                        <div class="txt">{{ $dokumentMeldung }}</div>
                    </div>
                @endif

                <div>
                    <div class="sec-label">{{ $duzen ? 'Deine Dokumente' : 'Ihre Dokumente' }} <span class="count">{{ count($mitarbeiterDokumente) }}</span></div>
                    <div class="card" style="margin-top:11px">
                        @forelse ($mitarbeiterDokumente as $mdok)
                            @php
                                // Vorberechnet statt @if im Attribut -- Hausregel.
                                if ($mdok['status'] === 'unterschrieben') {
                                    $mdokChip = 'chip ok';
                                } elseif ($mdok['status'] === 'bestaetigt' || $mdok['status'] === 'abgelegt') {
                                    $mdokChip = 'chip info';
                                } elseif ($mdok['offen']) {
                                    $mdokChip = 'chip crit';
                                } else {
                                    $mdokChip = 'chip info';
                                }
                                $mdokDatum = $mdok['signed_at'] ?? $mdok['acknowledged_at'] ?? null;
                                $mdokSub = $mdokDatum
                                    ? $mdok['status_label'] . ' am ' . \Carbon\Carbon::parse($mdokDatum)->format('d.m.Y')
                                    : $mdok['category_label'];
                                $mdokKnopf = $mdok['offen']
                                    ? ($mdok['action'] === 'sign' ? 'Lesen und unterschreiben' : 'Lesen und bestätigen')
                                    : 'Öffnen';
                            @endphp
                            <div class="doc">
                                <div class="docicon"><span>PDF</span></div>
                                <div class="body">
                                    <div class="title">{{ $mdok['title'] }}</div>
                                    <div class="sub">{{ $mdokSub }}</div>
                                    <div class="row">
                                        <span class="{{ $mdokChip }}">{{ $mdok['status_label'] }}</span>
                                        <button type="button" class="mini {{ $mdok['offen'] ? 'primary' : '' }}" wire:click="oeffneDokument({{ $mdok['recipient_id'] }})">{{ $mdokKnopf }}</button>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="leer">Hier liegen noch keine Dokumente.</div>
                        @endforelse
                    </div>
                </div>

```

- [ ] **Step 6: Blade — das Dokument-Blatt**

Nach dem Gruppen-Blatt (`@if ($profilGruppe !== null) ... @endif`, das bei Zeile 746 beginnt; direkt nach dessen `@endif`) einfügen:

```blade
            {{--
                Dokument-Blatt (Spec Dokumente §5.3): oeffnen setzt "gesehen",
                der PDF-Knopf ist die Download-Route, Bestaetigen/Unterschreiben
                laufen ueber DokumentUnterschrift. Zweige vorberechnet.
            --}}
            @if ($dokumentBlatt !== null)
                @php
                    $blattIstUnterschrift = $dokumentBlatt['action'] === 'sign';
                    $blattEinleitung = $dokumentBlatt['erledigt']
                        ? 'Dieses Dokument ist erledigt. Du kannst es jederzeit erneut öffnen.'
                        : ($blattIstUnterschrift
                            ? ($duzen ? 'Bitte lies das Dokument und unterschreibe unten.' : 'Bitte lesen Sie das Dokument und unterschreiben Sie unten.')
                            : ($duzen ? 'Bitte lies das Dokument und bestätige unten.' : 'Bitte lesen Sie das Dokument und bestätigen Sie unten.'));
                    $blattHaken = $duzen ? 'Ich habe das Dokument gelesen und verstanden.' : 'Ich habe das Dokument gelesen und verstanden.';
                    $blattKnopf = $blattIstUnterschrift ? 'Unterschreiben' : 'Bestätigen';
                    $blattAktion = $blattIstUnterschrift ? 'unterschreibeDokument' : 'bestaetigeDokument';
                    $blattSignaturLabel = $duzen ? 'Deine Unterschrift' : 'Ihre Unterschrift';
                @endphp
                <div class="upload-overlay" wire:click.self="schliesseDokument">
                    <form class="upload-sheet" wire:submit="{{ $blattAktion }}">
                        <div class="upload-head">
                            <h3>{{ $dokumentBlatt['title'] }}</h3>
                            <button type="button" class="upload-close" wire:click="schliesseDokument" aria-label="Schließen">&times;</button>
                        </div>
                        <p class="upload-sub">{{ $blattEinleitung }}</p>

                        <a href="{{ $dokumentBlatt['download_url'] }}" target="_blank" rel="noopener" class="btn">PDF öffnen</a>

                        @if (!$dokumentBlatt['erledigt'])
                            <label class="feld">
                                <input type="checkbox" wire:model="dokumentGelesen"> <span class="n">{{ $blattHaken }}</span>
                            </label>

                            @if ($blattIstUnterschrift)
                                <x-ui-input-signature
                                    name="dokumentUnterschrift"
                                    :label="$blattSignaturLabel"
                                    wire:model="dokumentUnterschrift"
                                    :required="true"
                                    :height="200"
                                />
                            @endif

                            @if ($dokumentFehler !== '')
                                <div class="alert crit">
                                    <span class="dot crit" style="margin-top:6px"></span>
                                    <div class="txt">{{ $dokumentFehler }}</div>
                                </div>
                            @endif

                            <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="{{ $blattAktion }}">
                                {{ $blattKnopf }}
                            </button>
                        @endif
                        <button type="button" class="btn" wire:click="schliesseDokument">Schließen</button>
                    </form>
                </div>
            @endif
```

- [ ] **Step 7: Blade prüfen, Tests grün**

Run: `php tools/blade-check.php resources/views/livewire/public/portal-shell.blade.php`
Expected: keine Fehler.

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "PortalShellMitarbeiterDokumenteTest|PortalShellDokumenteTest|PortalAufgabenBladeTest|PortalShell"`
Expected: `OK`. Wenn `PortalAufgabenBladeTest` wegen fehlender Tabelle `rec_document_recipients` rot wird: in dessen `schemaBauen()` die beiden Migrationen aus Task 1 per `(require ...)->up()` laden (die Welt kommt aus den Migrationen) und in der Variablenliste `mitarbeiterDokumente => []`, `dokumentBlatt => null` sind durch `ansichtsDaten()` bereits gesetzt.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Public/PortalShell.php resources/views/livewire/public/portal-shell.blade.php tests/Integration/PortalShellMitarbeiterDokumenteTest.php
git commit -m "feat(recruiting): Portal zeigt Dokumente, oeffnet das Blatt zum Bestaetigen und Unterschreiben" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 12: Akte — Kurzform zum Bereitstellen, Liste, HR-Download

**Files:**
- Create: `src/Http/Controllers/DokumentHrController.php` (Methode `datei`; `nachweis` kommt in Task 13)
- Modify: `routes/web.php` (vor `Route::get('/employees/{employee}', ...)`, Zeile 70)
- Modify: `src/Livewire/Employees/Show.php` (Imports, Eigenschaften nach Zeile 38, neue Methoden)
- Modify: `resources/views/livewire/employees/show.blade.php` (nach dem `@endif` der Karte „Offene Vertraege", Zeile 595, vor `{{-- Vertrag neu ausstellen --}}`)
- Test: `tests/Integration/DokumentAkteTest.php`

**Interfaces:**
- Produces:
  - Routen `GET /recruiting/employees/dokumente/{uuid}/datei` (Name `recruiting.employees.dokument.datei`, `uuid` = Dokument-uuid) und `GET /recruiting/employees/dokumente/{uuid}/nachweis` (Name `recruiting.employees.dokument.nachweis`, `uuid` = Empfänger-uuid; Controller-Methode in Task 13, Routen beide hier, weil ein `[Klasse, 'methode']`-Paar erst beim Aufruf aufgelöst wird).
  - `Employees\Show`: Eigenschaften `public $dokumentDatei = null; public string $dokumentTitel = ''; public string $dokumentKategorie = 'other'; public string $dokumentAktion = 'none';`; Methoden `updatedDokumentKategorie()`, `updatedDokumentDatei()`, `dokumentBereitstellen()`, `dokumentErneutSenden(int $recipientId)`, `dokumentZurueckziehen(int $recipientId)`; Computed `personDokumente(): list<array>` (Form von `DokumentAkteZeilen::fuer()`).
  - `src/Support/DokumentAkteZeilen.php`: `::fuer(list<RecDocumentRecipient> $zustellungen): list<array{recipient_id:int, document_uuid:string, recipient_uuid:string, title:string, category_label:string, action:string, action_label:string, status:string, status_label:string, benachrichtigt:?string, fehler:?string, signed_at:?string, kann_zurueckziehen:bool, hat_nachweis:bool, provided_at:string, employee_id:int}>` — rein, Eingabe mit geladenem `document`.
- Consumes: `DokumentService`, `DokumentLeser` (Scope-Ids über `PersonScopeResolver`), `DokumentKategorie`, `DokumentStatus`.

- [ ] **Step 1: Test für die Zeilen und die Routen schreiben**

`tests/Integration/DokumentAkteTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Support\DokumentAkteZeilen;

final class DokumentAkteTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_zeilen_tragen_status_benachrichtigung_und_rechte(): void
    {
        $e = $this->anstellung();
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'sign',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        $offen = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        DB::table('rec_document_recipients')->where('id', $offen->id)->update(['notify_error' => 'no_phone']);
        $d2 = RecDocument::create([
            'team_id' => 3, 'title' => 'Vertrag', 'category' => 'contract', 'action' => 'sign',
            'disk' => 'test-local', 'stored_path' => 'y.pdf', 'original_filename' => 'y.pdf',
            'file_sha256' => str_repeat('b', 64), 'file_size' => 1,
        ]);
        $fertig = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d2->id, 'rec_employee_id' => $e->id]);
        DB::table('rec_document_recipients')->where('id', $fertig->id)->update([
            'notified_at' => '2026-10-09 09:00:00', 'first_viewed_at' => '2026-10-09 09:05:00',
            'acknowledged_at' => '2026-10-09 09:06:00', 'signed_at' => '2026-10-09 09:07:00',
        ]);

        $zeilen = DokumentAkteZeilen::fuer(RecDocumentRecipient::with('document')->orderBy('id')->get()->all());

        $this->assertSame('offen', $zeilen[0]['status']);
        $this->assertSame('no_phone', $zeilen[0]['fehler']);
        $this->assertNull($zeilen[0]['benachrichtigt']);
        $this->assertFalse($zeilen[0]['versand_laeuft']);
        $this->assertSame('nicht erreicht (no_phone)', $zeilen[0]['versand_text']);
        $this->assertTrue($zeilen[0]['kann_zurueckziehen']);
        $this->assertFalse($zeilen[0]['hat_nachweis']);
        $this->assertSame('Belehrung / Unterweisung', $zeilen[0]['category_label']);
        $this->assertSame('Unterschreiben', $zeilen[0]['action_label']);

        $this->assertSame('unterschrieben', $zeilen[1]['status']);
        $this->assertSame('2026-10-09 09:00:00', $zeilen[1]['benachrichtigt']);
        $this->assertSame('WhatsApp 09.10. 09:00', $zeilen[1]['versand_text']);
        $this->assertFalse($zeilen[1]['kann_zurueckziehen']);
        $this->assertTrue($zeilen[1]['hat_nachweis']);
        $this->assertSame($fertig->uuid, $zeilen[1]['recipient_uuid']);
        $this->assertSame($d2->uuid, $zeilen[1]['document_uuid']);
    }

    public function test_noch_nicht_versuchte_zustellung_heisst_wird_verschickt(): void
    {
        $e = $this->anstellung();
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'T', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        $dNone = RecDocument::create([
            'team_id' => 3, 'title' => 'Lohn', 'category' => 'payslip', 'action' => 'none',
            'disk' => 'test-local', 'stored_path' => 'y.pdf', 'original_filename' => 'y.pdf',
            'file_sha256' => str_repeat('b', 64), 'file_size' => 1,
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $dNone->id, 'rec_employee_id' => $e->id]);

        $zeilen = DokumentAkteZeilen::fuer(RecDocumentRecipient::with('document')->orderBy('id')->get()->all());

        $this->assertTrue($zeilen[0]['versand_laeuft']);
        $this->assertSame('wird verschickt …', $zeilen[0]['versand_text']);
        $this->assertFalse($zeilen[1]['versand_laeuft']);
        $this->assertSame('ohne Nachricht', $zeilen[1]['versand_text']);
    }

    public function test_hr_routen_sind_registriert(): void
    {
        $container = Container::getInstance();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/web.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $url = new UrlGenerator($router->getRoutes(), Request::create('https://meingedeck.de'));

        $this->assertSame('https://meingedeck.de/recruiting/employees/dokumente/abc/datei', $url->route('recruiting.employees.dokument.datei', ['uuid' => 'abc']));
        $this->assertSame('https://meingedeck.de/recruiting/employees/dokumente/abc/nachweis', $url->route('recruiting.employees.dokument.nachweis', ['uuid' => 'abc']));
        $container->forgetInstance('router');
        Facade::clearResolvedInstances();
    }
}
```

- [ ] **Step 2: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentAkteTest`
Expected: FAIL (`DokumentAkteZeilen` fehlt; Routen fehlen).

- [ ] **Step 3: Zeilen-Klasse schreiben**

`src/Support/DokumentAkteZeilen.php`:

```php
<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecDocumentRecipient;

/**
 * Die Zeilen der HR-Sicht (Akte und Seite Dokumente, Spec §3.2, §8): Status,
 * Benachrichtigungsstand, was HR damit darf. Rein — die Abfrage liegt beim
 * Aufrufer, hier nur die Form.
 */
final class DokumentAkteZeilen
{
    /**
     * @param  list<RecDocumentRecipient> $zustellungen mit geladener Relation document
     * @return list<array{recipient_id:int, document_id:int, document_uuid:string, recipient_uuid:string, employee_id:int, title:string, category_label:string, action:string, action_label:string, status:string, status_label:string, benachrichtigt:?string, fehler:?string, versand_laeuft:bool, versand_text:string, signed_at:?string, kann_zurueckziehen:bool, hat_nachweis:bool, provided_at:string}>
     */
    public static function fuer(array $zustellungen): array
    {
        $out = [];
        foreach ($zustellungen as $z) {
            $d = $z->document;
            if ($d === null) {
                continue;
            }
            $aktion = (string) $d->action;
            $status = DokumentStatus::fuer($z->zeitstempel(), $aktion);
            // Versandstand in Worten — eine Stelle fuer Akte und Seite Dokumente.
            $versandLaeuft = DokumentKategorie::brauchtHandlung($aktion)
                && $z->withdrawn_at === null && $z->notified_at === null && $z->notify_error === null;
            $versandText = match (true) {
                !DokumentKategorie::brauchtHandlung($aktion) => 'ohne Nachricht',
                $z->notified_at !== null                     => 'WhatsApp ' . $z->notified_at->format('d.m. H:i'),
                $z->notify_error !== null                    => 'nicht erreicht (' . $z->notify_error . ')',
                $z->withdrawn_at !== null                    => 'nicht gesendet',
                default                                      => 'wird verschickt …',
            };
            $out[] = [
                'recipient_id'       => (int) $z->id,
                'document_id'        => (int) $d->id,
                'document_uuid'      => (string) $d->uuid,
                'recipient_uuid'     => (string) $z->uuid,
                'employee_id'        => (int) $z->rec_employee_id,
                'title'              => (string) $d->title,
                'category_label'     => DokumentKategorie::label((string) $d->category),
                'action'             => $aktion,
                'action_label'       => DokumentKategorie::aktionLabel($aktion),
                'status'             => $status,
                'status_label'       => DokumentStatus::label($status),
                'benachrichtigt'     => $z->notified_at?->format('Y-m-d H:i:s'),
                'fehler'             => $z->notify_error,
                'versand_laeuft'     => $versandLaeuft,
                'versand_text'       => $versandText,
                'signed_at'          => $z->signed_at?->format('Y-m-d H:i:s'),
                'kann_zurueckziehen' => $z->signed_at === null && $z->withdrawn_at === null,
                'hat_nachweis'       => $z->signed_at !== null,
                'provided_at'        => $z->created_at?->format('Y-m-d H:i:s') ?? '',
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Routen und HR-Controller**

In `routes/web.php` VOR `Route::get('/employees/{employee}', ...)` (Zeile 70) einfügen:

```php
// Dokumente (Spec 2026-10-08): Datei-Download und Nachweisblatt fuer HR.
// VOR der {employee}-Wildcard. (Die Seite /employees/documents kommt in Task 14.)
Route::get('/employees/dokumente/{uuid}/datei', [\Platform\Recruiting\Http\Controllers\DokumentHrController::class, 'datei'])
    ->name('recruiting.employees.dokument.datei');
Route::get('/employees/dokumente/{uuid}/nachweis', [\Platform\Recruiting\Http\Controllers\DokumentHrController::class, 'nachweis'])
    ->name('recruiting.employees.dokument.nachweis');
```

`src/Http/Controllers/DokumentHrController.php` (die Methode `nachweis` folgt in Task 13):

```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDocument;

/**
 * HR-Seite der Dokumente (Spec §3.2, §5.4): Datei und Nachweisblatt. Laeuft
 * im Modul-Guard (ModuleRouter::group), Mandant = aktives Team; fremde
 * Mandanten sehen 404, nie 403 (kein Existenz-Orakel).
 */
class DokumentHrController extends Controller
{
    public function datei(string $uuid)
    {
        $dokument = RecDocument::withTrashed()
            ->where('uuid', $uuid)
            ->where('team_id', auth()->user()->currentTeam->id)
            ->firstOrFail();

        return Storage::disk($dokument->disk)->response(
            $dokument->stored_path,
            $dokument->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
```

- [ ] **Step 5: Employees\Show erweitern**

Imports ergänzen:

```php
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Support\DokumentAkteZeilen;
use Platform\Recruiting\Support\DokumentKategorie;
```

Eigenschaften nach `public ?string $flashError = null;` (Zeile 38):

```php
    /** Kurzform "Dokument bereitstellen" in der Akte (Spec Dokumente §3.2). */
    public $dokumentDatei = null;
    public string $dokumentTitel = '';
    public string $dokumentKategorie = 'other';
    public string $dokumentAktion = 'none';
```

Methoden (z. B. nach `reissueContract()`):

```php
    public function updatedDokumentKategorie(): void
    {
        $this->dokumentAktion = DokumentKategorie::exists($this->dokumentKategorie)
            ? DokumentKategorie::defaultAktion($this->dokumentKategorie)
            : DokumentKategorie::AKTION_NONE;
    }

    /** Titel aus dem Dateinamen vorbelegen, solange HR nichts getippt hat. */
    public function updatedDokumentDatei(): void
    {
        if ($this->dokumentTitel === '' && $this->dokumentDatei) {
            $this->dokumentTitel = (string) pathinfo($this->dokumentDatei->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    public function dokumentBereitstellen(): void
    {
        $this->flash = null;
        $this->flashError = null;
        $emp = $this->employee();
        if (!$emp) {
            return;
        }
        if (!$this->dokumentDatei) {
            $this->flashError = 'Bitte eine PDF auswählen.';

            return;
        }

        try {
            $ergebnis = app(DokumentService::class)->bereitstellen(
                (int) $emp->team_id,
                ['title' => $this->dokumentTitel, 'category' => $this->dokumentKategorie, 'action' => $this->dokumentAktion],
                (string) file_get_contents($this->dokumentDatei->getRealPath()),
                (string) $this->dokumentDatei->getClientOriginalName(),
                [(int) $emp->id],
                null,
                auth()->id(),
            );
        } catch (\InvalidArgumentException $e) {
            $this->flashError = $e->getMessage();

            return;
        }

        DokumentHinweiseVersenden::starten($ergebnis['dokument']);

        $this->dokumentDatei = null;
        $this->dokumentTitel = '';
        $this->dokumentKategorie = 'other';
        $this->dokumentAktion = 'none';
        unset($this->personDokumente);
        $this->flash = $ergebnis['versand_noetig']
            ? 'Dokument bereitgestellt, WhatsApp wird verschickt.'
            : 'Dokument bereitgestellt.';
    }

    public function dokumentErneutSenden(int $recipientId): void
    {
        $z = $this->zustellungDieserPerson($recipientId);
        if ($z === null) {
            return;
        }
        $status = app(DokumentService::class)->erneutSenden($z);
        unset($this->personDokumente);
        $this->flash = $status === 'sent' ? 'WhatsApp erneut gesendet.' : null;
        $this->flashError = $status === 'sent' ? null : 'Nicht gesendet: ' . $status;
    }

    public function dokumentZurueckziehen(int $recipientId): void
    {
        $z = $this->zustellungDieserPerson($recipientId);
        if ($z === null) {
            return;
        }
        $fehler = app(DokumentService::class)->zurueckziehen($z);
        unset($this->personDokumente);
        $this->flash = $fehler === null ? 'Dokument zurückgezogen.' : null;
        $this->flashError = $fehler;
    }

    /** Alle Zustellungen der Person (beide Anstellungen), neueste zuerst — auch zurueckgezogene, HR sieht Historie. */
    #[Computed]
    public function personDokumente(): array
    {
        $emp = $this->employee();
        if (!$emp) {
            return [];
        }
        $ids = app(PersonScopeResolver::class)->forEmployee($emp)['ids'];

        return DokumentAkteZeilen::fuer(
            RecDocumentRecipient::query()
                ->whereIn('rec_employee_id', $ids)
                ->with(['document' => fn ($q) => $q->withTrashed()])
                ->orderByDesc('id')
                ->get()
                ->all()
        );
    }

    private function zustellungDieserPerson(int $recipientId): ?RecDocumentRecipient
    {
        $emp = $this->employee();
        if (!$emp) {
            return null;
        }
        $ids = app(PersonScopeResolver::class)->forEmployee($emp)['ids'];

        return RecDocumentRecipient::query()->whereIn('rec_employee_id', $ids)->find($recipientId);
    }
```

- [ ] **Step 6: Blade — Abschnitt „Dokumente" in der Akte**

In `resources/views/livewire/employees/show.blade.php` nach dem `@endif` der Karte „Offene Vertraege" (Zeile 595) und vor `{{-- Vertrag neu ausstellen --}}` einfügen:

```blade
            {{-- Dokumente bereitstellen und Stand (Spec Dokumente 2026-10-08, §3.2) --}}
            @php
                $dokZeilen = $this->personDokumente;
                $dokKategorien = \Platform\Recruiting\Support\DokumentKategorie::labels();
                $dokAktionen = \Platform\Recruiting\Support\DokumentKategorie::aktionen();
            @endphp
            <div class="mt-6 p-4 bg-[var(--ui-muted-5)] border border-[var(--ui-border)] rounded-lg">
                <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">Dokumente</h3>

                <form wire:submit="dokumentBereitstellen" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end p-3 bg-white border border-[var(--ui-border)]/60 rounded-md">
                    <div class="md:col-span-4">
                        <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">PDF</label>
                        <input type="file" accept=".pdf" wire:model="dokumentDatei" class="block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                        <div wire:loading wire:target="dokumentDatei" class="text-xs text-[var(--ui-muted)] mt-1">Wird hochgeladen …</div>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Titel</label>
                        <input type="text" wire:model="dokumentTitel" placeholder="z. B. Verschwiegenheitserklärung" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Kategorie</label>
                        <select wire:model.live="dokumentKategorie" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                            @foreach ($dokKategorien as $dokCode => $dokLabel)
                                <option value="{{ $dokCode }}">{{ $dokLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Der Mitarbeiter soll</label>
                        <select wire:model="dokumentAktion" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                            @foreach ($dokAktionen as $dokCode => $dokLabel)
                                <option value="{{ $dokCode }}">{{ $dokLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-1">
                        <button type="submit" wire:loading.attr="disabled" wire:target="dokumentBereitstellen,dokumentDatei"
                                class="w-full inline-flex items-center justify-center px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-md hover:bg-blue-700 disabled:opacity-60">
                            Bereitstellen
                        </button>
                    </div>
                </form>

                @php $dokVersandLaeuft = count(array_filter($dokZeilen, fn ($z) => $z['versand_laeuft'])) > 0; @endphp
                <div class="mt-3 space-y-2" @if ($dokVersandLaeuft) wire:poll.5s @endif>
                    @forelse ($dokZeilen as $dz)
                        @php
                            if ($dz['status'] === 'unterschrieben' || $dz['status'] === 'bestaetigt') {
                                $dzBadge = 'border-emerald-200 bg-emerald-50 text-emerald-800';
                            } elseif ($dz['status'] === 'zurueckgezogen') {
                                $dzBadge = 'border-gray-200 bg-gray-100 text-gray-600';
                            } elseif ($dz['status'] === 'offen') {
                                $dzBadge = 'border-red-200 bg-red-50 text-red-800';
                            } else {
                                $dzBadge = 'border-amber-200 bg-amber-50 text-amber-800';
                            }
                            $dzVersand = $dz['versand_text'];
                            $dzZeigtErneut = $dz['action'] !== 'none' && $dz['status'] !== 'zurueckgezogen' && !$dz['benachrichtigt'] && !$dz['versand_laeuft'];
                        @endphp
                        <div class="flex items-center justify-between gap-3 p-2 bg-white border border-[var(--ui-border)]/60 rounded-md">
                            <div class="flex items-center gap-2 text-sm flex-wrap">
                                @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--ui-secondary)]')
                                <span class="font-medium">{{ $dz['title'] }}</span>
                                <span class="text-xs text-[var(--ui-muted)]">{{ $dz['category_label'] }} · {{ $dz['action_label'] }}</span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium border {{ $dzBadge }}">{{ $dz['status_label'] }}</span>
                                <span class="text-xs text-[var(--ui-muted)]">{{ $dzVersand }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('recruiting.employees.dokument.datei', ['uuid' => $dz['document_uuid']]) }}" target="_blank"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-[var(--ui-border)] text-[var(--ui-secondary)] bg-white text-xs font-medium rounded-md hover:bg-[var(--ui-muted-5)]">PDF</a>
                                @if ($dz['hat_nachweis'])
                                    <a href="{{ route('recruiting.employees.dokument.nachweis', ['uuid' => $dz['recipient_uuid']]) }}" target="_blank"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-emerald-300 text-emerald-800 bg-emerald-50 text-xs font-medium rounded-md hover:bg-emerald-100">Nachweis</a>
                                @endif
                                @if ($dzZeigtErneut)
                                    <button type="button" wire:click="dokumentErneutSenden({{ $dz['recipient_id'] }})"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-blue-300 text-blue-800 bg-blue-50 text-xs font-medium rounded-md hover:bg-blue-100">Erneut senden</button>
                                @endif
                                @if ($dz['kann_zurueckziehen'])
                                    <button type="button" wire:click="dokumentZurueckziehen({{ $dz['recipient_id'] }})" wire:confirm="Dokument „{{ $dz['title'] }}" für diese Person zurückziehen?"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-[var(--ui-border)] text-[var(--ui-muted)] bg-white text-xs font-medium rounded-md hover:bg-red-50 hover:text-red-700">Zurückziehen</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-[var(--ui-muted)]">Noch kein Dokument bereitgestellt.</p>
                    @endforelse
                </div>
            </div>
```

- [ ] **Step 7: Blade prüfen, Tests grün**

Run: `php tools/blade-check.php resources/views/livewire/employees/show.blade.php`
Expected: keine Fehler.

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentAkteTest|Employee"`
Expected: `OK`.

- [ ] **Step 8: Commit**

```bash
git add src/Support/DokumentAkteZeilen.php src/Http/Controllers/DokumentHrController.php routes/web.php src/Livewire/Employees/Show.php resources/views/livewire/employees/show.blade.php tests/Integration/DokumentAkteTest.php
git commit -m "feat(recruiting): Akte — Dokument bereitstellen, Stand je Person, HR-Download" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 13: Nachweisblatt (PDF)

**Files:**
- Create: `src/Support/DokumentNachweisDaten.php`
- Create: `resources/views/pdf/dokument-nachweis.blade.php`
- Modify: `src/Http/Controllers/DokumentHrController.php` (Methode `nachweis`)
- Test: `tests/Unit/Dokumente/DokumentNachweisDatenTest.php`

**Interfaces:**
- Produces: `DokumentNachweisDaten::fuer(array $dokument, array $zustellung, array $mitarbeiter): array{name:string, personalnummer:string, firma:string, titel:string, dateiname:string, pruefsumme:string, bereitgestellt:string, geoeffnet:string, bestaetigt:string, unterschrieben:string, abstand_sekunden:?int, unterschrift:?string}` — alle Eingaben einfache Arrays (`$dokument`: title, original_filename, file_sha256, created_at; `$zustellung`: created_at, first_viewed_at, acknowledged_at, signed_at, signature_data; `$mitarbeiter`: first_name, last_name, personnel_number, company). Datumsformat `d.m.Y H:i:s`, leer = `'—'`.
- Consumes: `config('recruiting.zas.company_labels')` (RG/MA → Firmenname) über den Controller, nicht in der reinen Klasse.

- [ ] **Step 1: Test schreiben**

`tests/Unit/Dokumente/DokumentNachweisDatenTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentNachweisDaten;

final class DokumentNachweisDatenTest extends TestCase
{
    public function test_alle_felder_und_der_abstand(): void
    {
        $d = DokumentNachweisDaten::fuer(
            ['title' => 'Verschwiegenheit', 'original_filename' => 'v.pdf', 'file_sha256' => str_repeat('a', 64), 'created_at' => '2026-10-09 09:00:00'],
            ['created_at' => '2026-10-09 09:00:00', 'first_viewed_at' => '2026-10-09 09:05:00', 'acknowledged_at' => '2026-10-09 09:06:30', 'signed_at' => '2026-10-09 09:06:30', 'signature_data' => 'data:image/png;base64,AAA='],
            ['first_name' => 'Anna', 'last_name' => 'Test', 'personnel_number' => 'RG1464', 'company' => 'RheinGedeck GmbH'],
        );

        $this->assertSame('Anna Test', $d['name']);
        $this->assertSame('RG1464', $d['personalnummer']);
        $this->assertSame('RheinGedeck GmbH', $d['firma']);
        $this->assertSame('09.10.2026 09:05:00', $d['geoeffnet']);
        $this->assertSame('09.10.2026 09:06:30', $d['unterschrieben']);
        $this->assertSame(90, $d['abstand_sekunden']);
        $this->assertSame('data:image/png;base64,AAA=', $d['unterschrift']);
    }

    public function test_leere_werte_werden_strich(): void
    {
        $d = DokumentNachweisDaten::fuer(
            ['title' => 'T', 'original_filename' => 't.pdf', 'file_sha256' => 'x', 'created_at' => null],
            ['created_at' => null, 'first_viewed_at' => null, 'acknowledged_at' => null, 'signed_at' => null, 'signature_data' => null],
            ['first_name' => '', 'last_name' => '', 'personnel_number' => null, 'company' => null],
        );

        $this->assertSame('—', $d['name']);
        $this->assertSame('—', $d['personalnummer']);
        $this->assertSame('—', $d['geoeffnet']);
        $this->assertNull($d['abstand_sekunden']);
        $this->assertNull($d['unterschrift']);
    }
}
```

- [ ] **Step 2: Test rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DokumentNachweisDatenTest`
Expected: FAIL, Klasse nicht gefunden.

- [ ] **Step 3: Klasse, Vorlage, Controller-Methode schreiben**

`src/Support/DokumentNachweisDaten.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Die Werte des Nachweisblatts (Spec §5.4). Rein: Eingaben sind Arrays, die
 * Datumsangaben werden hier formatiert, der Abstand zwischen Oeffnen und
 * Unterschrift ist das Indiz gegen "nach null Sekunden bestaetigt".
 */
final class DokumentNachweisDaten
{
    public static function fuer(array $dokument, array $zustellung, array $mitarbeiter): array
    {
        $name = trim((string) ($mitarbeiter['first_name'] ?? '') . ' ' . (string) ($mitarbeiter['last_name'] ?? ''));
        $geoeffnet = $zustellung['first_viewed_at'] ?? null;
        $unterschrieben = $zustellung['signed_at'] ?? null;

        return [
            'name'             => $name !== '' ? $name : '—',
            'personalnummer'   => self::text($mitarbeiter['personnel_number'] ?? null),
            'firma'            => self::text($mitarbeiter['company'] ?? null),
            'titel'            => (string) ($dokument['title'] ?? ''),
            'dateiname'        => (string) ($dokument['original_filename'] ?? ''),
            'pruefsumme'       => (string) ($dokument['file_sha256'] ?? ''),
            'bereitgestellt'   => self::datum($zustellung['created_at'] ?? ($dokument['created_at'] ?? null)),
            'geoeffnet'        => self::datum($geoeffnet),
            'bestaetigt'       => self::datum($zustellung['acknowledged_at'] ?? null),
            'unterschrieben'   => self::datum($unterschrieben),
            'abstand_sekunden' => ($geoeffnet && $unterschrieben) ? max(0, strtotime((string) $unterschrieben) - strtotime((string) $geoeffnet)) : null,
            'unterschrift'     => ($zustellung['signature_data'] ?? null) ?: null,
        ];
    }

    private static function datum(?string $wert): string
    {
        $wert = trim((string) $wert);
        if ($wert === '') {
            return '—';
        }
        $ts = strtotime($wert);

        return $ts === false ? $wert : date('d.m.Y H:i:s', $ts);
    }

    private static function text(?string $wert): string
    {
        $wert = trim((string) $wert);

        return $wert === '' ? '—' : $wert;
    }
}
```

`resources/views/pdf/dokument-nachweis.blade.php`:

```blade
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 1.5; color: #111827; margin: 2cm 2.5cm; }
        h1 { font-size: 16pt; margin-bottom: 4px; }
        .sub { color: #6b7280; font-size: 10pt; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; font-size: 10pt; vertical-align: top; }
        th { width: 34%; background: #f3f4f6; font-weight: 600; }
        .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; word-break: break-all; }
        .signature { margin-top: 10px; page-break-inside: avoid; }
        .signature img { max-height: 110px; display: block; margin: 8px 0; border-bottom: 1px solid #111827; }
        .stamp img { max-width: 160px; display: block; margin-top: 14px; }
        .hint { color: #6b7280; font-size: 9pt; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Nachweis der Unterschrift</h1>
    <div class="sub">{{ $daten['titel'] }} · erstellt {{ $erstellt }}</div>

    <table>
        <tr><th>Mitarbeiter</th><td>{{ $daten['name'] }}</td></tr>
        <tr><th>Personalnummer</th><td>{{ $daten['personalnummer'] }}</td></tr>
        <tr><th>Firma</th><td>{{ $daten['firma'] }}</td></tr>
        <tr><th>Dokument</th><td>{{ $daten['titel'] }}<br><span class="mono">{{ $daten['dateiname'] }}</span></td></tr>
        <tr><th>Prüfsumme (SHA-256)</th><td class="mono">{{ $daten['pruefsumme'] }}</td></tr>
        <tr><th>Bereitgestellt am</th><td>{{ $daten['bereitgestellt'] }}</td></tr>
        <tr><th>Erstes Öffnen</th><td>{{ $daten['geoeffnet'] }}</td></tr>
        <tr><th>Gelesen bestätigt am</th><td>{{ $daten['bestaetigt'] }}</td></tr>
        <tr><th>Unterschrieben am</th><td>{{ $daten['unterschrieben'] }}@if ($daten['abstand_sekunden'] !== null) <span class="sub">({{ $daten['abstand_sekunden'] }} Sekunden nach dem Öffnen)</span>@endif</td></tr>
    </table>

    @if ($daten['unterschrift'])
        <div class="signature">
            <strong>Unterschrift</strong>
            <img src="{{ $daten['unterschrift'] }}" alt="Unterschrift">
            <div>{{ $daten['name'] }}</div>
        </div>
    @endif

    @if ($stempel)
        <div class="stamp"><img src="{{ $stempel }}" alt="Firmenstempel"></div>
    @endif

    <p class="hint">Die Prüfsumme bezieht sich auf die Datei, die dem Mitarbeiter im Portal vorlag. Sie wurde beim Bereitstellen eingefroren und bei der Unterschrift erneut geprüft.</p>
</body>
</html>
```

In `DokumentHrController` ergänzen (Imports: `use Barryvdh\DomPDF\Facade\Pdf; use Illuminate\Support\Str; use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf; use Platform\Recruiting\Models\RecDocumentRecipient; use Platform\Recruiting\Models\RecEmployee; use Platform\Recruiting\Support\DokumentNachweisDaten;` und `use RendersContractPdf;` in der Klasse):

```php
    public function nachweis(string $uuid)
    {
        $teamId = auth()->user()->currentTeam->id;
        $z = RecDocumentRecipient::query()
            ->where('uuid', $uuid)
            ->where('team_id', $teamId)
            ->whereNotNull('signed_at')
            ->with(['document' => fn ($q) => $q->withTrashed()])
            ->firstOrFail();
        $d = $z->document;
        abort_if($d === null || (int) $d->team_id !== (int) $teamId, 404);
        $ma = RecEmployee::find($z->rec_employee_id);
        $labels = (array) config('recruiting.zas.company_labels', []);
        $firma = $ma ? ($labels[(string) $ma->company] ?? (string) $ma->company) : null;

        $daten = DokumentNachweisDaten::fuer(
            ['title' => $d->title, 'original_filename' => $d->original_filename, 'file_sha256' => $d->file_sha256, 'created_at' => $d->created_at?->format('Y-m-d H:i:s')],
            [
                'created_at'      => $z->created_at?->format('Y-m-d H:i:s'),
                'first_viewed_at' => $z->first_viewed_at?->format('Y-m-d H:i:s'),
                'acknowledged_at' => $z->acknowledged_at?->format('Y-m-d H:i:s'),
                'signed_at'       => $z->signed_at?->format('Y-m-d H:i:s'),
                'signature_data'  => $z->signature_data,
            ],
            ['first_name' => $ma?->first_name, 'last_name' => $ma?->last_name, 'personnel_number' => $ma?->personnel_number, 'company' => $firma],
        );

        $html = view('recruiting::pdf.dokument-nachweis', [
            'daten'   => $daten,
            'erstellt' => now()->format('d.m.Y H:i'),
            'stempel' => $this->loadCompanyStampDataUrl(),
        ])->render();

        return Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->stream(Str::slug('Nachweis ' . $d->title) . '.pdf');
    }
```

- [ ] **Step 4: Test grün, Blade prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentNachweisDatenTest|DokumentAkteTest"`
Expected: `OK`.

Run: `php tools/blade-check.php resources/views/pdf/dokument-nachweis.blade.php`
Expected: keine Fehler.

- [ ] **Step 5: Commit**

```bash
git add src/Support/DokumentNachweisDaten.php resources/views/pdf/dokument-nachweis.blade.php src/Http/Controllers/DokumentHrController.php tests/Unit/Dokumente/DokumentNachweisDatenTest.php
git commit -m "feat(recruiting): Nachweisblatt zur Unterschrift eines Dokuments (DomPDF)" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 14: Seite „Dokumente" — Empfängerwähler und Überblick

**Files:**
- Create: `src/Services/DokumentEmpfaengerSuche.php`
- Create: `src/Services/EingebuchteFuerDokument.php`
- Create: `src/Livewire/Employees/Documents.php`
- Create: `resources/views/livewire/employees/documents.blade.php`
- Modify: `routes/web.php` (vor den Dokument-Routen aus Task 12)
- Modify: `resources/views/livewire/employees/show.blade.php` (Link „Alle Dokumente" in der Kopfzeile des Abschnitts)
- Modify: `resources/views/livewire/sidebar.blade.php` (nach dem Eintrag „Lohnrelevante Änderungen", Zeile ~127)
- Test: `tests/Integration/DokumentEmpfaengerSucheTest.php`, `tests/Integration/EingebuchteFuerDokumentTest.php`

**Interfaces:**
- Produces:
  - `DokumentEmpfaengerSuche::finde(int $teamId, array $filter, int $limit = 500): list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}>` — `$filter` = `array{suche:string, firma:string, aktiv:string, taetigkeit:string, event_id:?int}`; `aktiv` ∈ `active|inactive|all`; sortiert nach Nachname, Vorname.
  - `DokumentEmpfaengerSuche::taetigkeiten(int $teamId): list<string>` — Katalog aus Lookup `ZasDispoTaetigkeitSync::LOOKUP`, natürlich sortiert.
  - `EingebuchteFuerDokument::ids(int $eventId): list<int>` — `rec_employee_id` der Assignments mit `status_id = RecDispoAssignment::STATUS_AUFTRAG`, `missing_since IS NULL`, `rec_employee_id` gesetzt, entdoppelt, aufsteigend.
  - Route `GET /recruiting/employees/documents` → `Employees\Documents`, Name `recruiting.employees.documents`.
- Consumes: `DokumentService`, `DokumentAkteZeilen`, `DokumentFortschritt`, `DokumentKategorie`, `RecDispoEvent`, `RecDispoAssignment`.

- [ ] **Step 1: Tests schreiben**

`tests/Integration/DokumentEmpfaengerSucheTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\DokumentEmpfaengerSuche;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

final class DokumentEmpfaengerSucheTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function filter(array $set = []): array
    {
        return array_merge(['suche' => '', 'firma' => '', 'aktiv' => 'active', 'taetigkeit' => '', 'event_id' => null], $set);
    }

    public function test_suche_firma_und_aktiv(): void
    {
        $this->anstellung(['first_name' => 'Anna', 'last_name' => 'Zwei', 'company' => 'RG', 'personnel_number' => 'RG1']);
        $this->anstellung(['first_name' => 'Ben', 'last_name' => 'Eins', 'company' => 'MA', 'personnel_number' => 'MA2']);
        $this->anstellung(['first_name' => 'Cem', 'last_name' => 'Drei', 'company' => 'RG', 'is_active' => false]);
        $this->anstellung(['first_name' => 'Fremd', 'last_name' => 'Team', 'team_id' => 99]);

        $alle = DokumentEmpfaengerSuche::finde(3, $this->filter());
        $this->assertSame(['Eins', 'Zwei'], array_map(fn ($r) => explode(' ', $r['name'])[1], $alle), 'aktiv, nach Nachname');

        $this->assertSame(['Anna Zwei'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['firma' => 'RG'])), 'name'));
        $this->assertSame(['Cem Drei'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['aktiv' => 'inactive'])), 'name'));
        $this->assertCount(3, DokumentEmpfaengerSuche::finde(3, $this->filter(['aktiv' => 'all'])));
        $this->assertSame(['Ben Eins'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'MA2'])), 'name'));
        $this->assertSame(['Anna Zwei'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['suche' => 'zwei'])), 'name'));
    }

    public function test_taetigkeit_filtert_ueber_den_zas_katalog(): void
    {
        $a = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'A']);
        $b = $this->anstellung(['first_name' => 'Ben', 'last_name' => 'B']);
        DB::table('rec_employee_hr_data')->insert([
            ['team_id' => 3, 'rec_employee_id' => $a->id, 'dispo_taetigkeiten' => json_encode(['Küchenchef', 'Logistiker']), 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => 3, 'rec_employee_id' => $b->id, 'dispo_taetigkeiten' => json_encode(['Servicekräfte']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $lookupId = DB::table('core_lookups')->insertGetId(['team_id' => 3, 'name' => ZasDispoTaetigkeitSync::LOOKUP, 'label' => 'x', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Servicekräfte', 'Küchenchef', 'Logistiker'] as $v) {
            DB::table('core_lookup_values')->insert(['lookup_id' => $lookupId, 'value' => $v, 'label' => $v, 'order' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(['Anna A'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['taetigkeit' => 'küchenchef'])), 'name'), 'Vergleich in Kleinschreibung, Umlaut im JSON');
        $this->assertSame(['Ben B'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['taetigkeit' => 'Servicekräfte'])), 'name'));
        $this->assertSame(['Küchenchef', 'Logistiker', 'Servicekräfte'], DokumentEmpfaengerSuche::taetigkeiten(3));
    }

    public function test_veranstaltung_filtert_auf_eingebuchte(): void
    {
        $a = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'A']);
        $b = $this->anstellung(['first_name' => 'Ben', 'last_name' => 'B']);
        $eventId = DB::table('rec_dispo_events')->insertGetId(['uuid' => 'ev-1', 'einsatz_ref' => 'E-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rec_dispo_assignments')->insert([
            ['uuid' => 'as-1', 'ds_ref' => 'DS-1', 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $a->id, 'pnr_raw' => 'RG1', 'datum' => '2026-12-31', 'status_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => 'as-2', 'ds_ref' => 'DS-2', 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $b->id, 'pnr_raw' => 'RG2', 'datum' => '2026-12-31', 'status_id' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(['Anna A'], array_column(DokumentEmpfaengerSuche::finde(3, $this->filter(['event_id' => $eventId])), 'name'));
    }
}
```

`tests/Integration/EingebuchteFuerDokumentTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\EingebuchteFuerDokument;

final class EingebuchteFuerDokumentTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_nur_auftrag_ohne_missing_mit_mitarbeiter_entdoppelt(): void
    {
        $eventId = DB::table('rec_dispo_events')->insertGetId(['uuid' => 'ev-1', 'einsatz_ref' => 'E-1', 'created_at' => now(), 'updated_at' => now()]);
        $zeile = fn (string $ds, ?int $emp, int $status, ?string $missing) => [
            'uuid' => 'as-' . $ds, 'ds_ref' => $ds, 'rec_dispo_event_id' => $eventId, 'rec_employee_id' => $emp,
            'pnr_raw' => 'RG1', 'datum' => '2026-12-31', 'status_id' => $status, 'missing_since' => $missing,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('rec_dispo_assignments')->insert([
            $zeile('1', 10, 1, null),
            $zeile('2', 10, 1, null),            // derselbe Mensch, zweiter Tag
            $zeile('3', 11, 0, null),            // Angebot
            $zeile('4', 12, 1, '2026-10-01'),    // verschwunden
            $zeile('5', null, 1, null),          // unbesetzter Platz
            $zeile('6', 9, 1, null),
        ]);

        $this->assertSame([9, 10], EingebuchteFuerDokument::ids($eventId));
        $this->assertSame([], EingebuchteFuerDokument::ids(999));
    }
}
```

- [ ] **Step 2: Tests rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentEmpfaengerSucheTest|EingebuchteFuerDokumentTest"`
Expected: FAIL, Klassen nicht gefunden.

- [ ] **Step 3: Services schreiben**

`src/Services/EingebuchteFuerDokument.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Wer bekommt ein Dokument "an alle Eingebuchten" (Spec §3.3): Einbuchungen
 * mit Status Auftrag, nicht verschwunden, mit zugeordnetem Mitarbeiter.
 * Einmaliger Stand zum Zeitpunkt des Bereitstellens — Nachruecker legt HR nach.
 */
final class EingebuchteFuerDokument
{
    /** @return list<int> */
    public static function ids(int $eventId): array
    {
        return DB::table('rec_dispo_assignments')
            ->where('rec_dispo_event_id', $eventId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNotNull('rec_employee_id')
            ->distinct()
            ->orderBy('rec_employee_id')
            ->pluck('rec_employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
```

`src/Services/DokumentEmpfaengerSuche.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

/**
 * Kandidaten fuer den Empfaengerwaehler der Seite Dokumente (Spec §3.4).
 * Dieselben Filter wie die Mitarbeiterliste plus Taetigkeit (ZAS-Katalog,
 * Vergleich in Kleinschreibung) und Veranstaltung (EingebuchteFuerDokument).
 *
 * Taetigkeit: rec_employee_hr_data.dispo_taetigkeiten ist eine JSON-Liste von
 * Katalognamen; json_encode schreibt Umlaute als ü, deshalb KEIN LIKE auf
 * den Rohtext, sondern whereJsonContains mit dem Katalog-Schreibweise. Der
 * Filterwert kommt in beliebiger Schreibung an und wird ueber den Katalog
 * auf die kanonische Form gebracht.
 */
final class DokumentEmpfaengerSuche
{
    /**
     * @param  array{suche:string, firma:string, aktiv:string, taetigkeit:string, event_id:?int} $filter
     * @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}>
     */
    public static function finde(int $teamId, array $filter, int $limit = 500): array
    {
        $q = DB::table('rec_employees')->where('team_id', $teamId);

        $aktiv = (string) ($filter['aktiv'] ?? 'active');
        if ($aktiv === 'active') {
            $q->where('is_active', true);
        } elseif ($aktiv === 'inactive') {
            $q->where('is_active', false);
        }

        $firma = trim((string) ($filter['firma'] ?? ''));
        if ($firma !== '') {
            $q->where('company', $firma);
        }

        $suche = trim((string) ($filter['suche'] ?? ''));
        if ($suche !== '') {
            $needle = '%' . $suche . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('first_name', 'like', $needle)
                  ->orWhere('last_name', 'like', $needle)
                  ->orWhere('personnel_number', 'like', $needle);
            });
        }

        $taetigkeit = trim((string) ($filter['taetigkeit'] ?? ''));
        if ($taetigkeit !== '') {
            $kanonisch = self::kanonisch($teamId, $taetigkeit);
            $ids = DB::table('rec_employee_hr_data')
                ->whereJsonContains('dispo_taetigkeiten', $kanonisch)
                ->pluck('rec_employee_id');
            $q->whereIn('id', $ids);
        }

        $eventId = $filter['event_id'] ?? null;
        if ($eventId !== null && (int) $eventId > 0) {
            $q->whereIn('id', EingebuchteFuerDokument::ids((int) $eventId));
        }

        return $q->orderBy('last_name')->orderBy('first_name')->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'personnel_number', 'company', 'person_key', 'portal_v2_since'])
            ->map(fn ($r) => [
                'id'               => (int) $r->id,
                'name'             => trim((string) $r->first_name . ' ' . (string) $r->last_name) ?: ('Mitarbeiter #' . $r->id),
                'personnel_number' => $r->personnel_number,
                'company'          => $r->company,
                'person_key'       => $r->person_key,
                'hat_portal'       => $r->portal_v2_since !== null,
            ])
            ->all();
    }

    /** @return list<string> der ganze ZAS-Katalog, natuerlich sortiert */
    public static function taetigkeiten(int $teamId): array
    {
        $lookupId = DB::table('core_lookups')->where('team_id', $teamId)->where('name', ZasDispoTaetigkeitSync::LOOKUP)->value('id');
        if ($lookupId === null) {
            return [];
        }
        $werte = DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('value')->map(fn ($v) => (string) $v)->all();
        usort($werte, 'strnatcasecmp');

        return array_values($werte);
    }

    private static function kanonisch(int $teamId, string $eingabe): string
    {
        foreach (self::taetigkeiten($teamId) as $wert) {
            if (mb_strtolower($wert) === mb_strtolower($eingabe)) {
                return $wert;
            }
        }

        return $eingabe;
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "DokumentEmpfaengerSucheTest|EingebuchteFuerDokumentTest"`
Expected: `OK (4 tests)`. Falls `whereJsonContains` unter SQLite in dieser Laravel-Version nicht greift (`SQLSTATE ... no such function: json_each`), die SQLite-Version prüfen (`php -r 'echo SQLite3::version()["versionString"];'`, braucht ≥ 3.38) — nicht auf LIKE ausweichen.

- [ ] **Step 5: Livewire-Seite**

`src/Livewire/Employees/Documents.php`:

```php
<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentEmpfaengerSuche;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\DokumentAkteZeilen;
use Platform\Recruiting\Support\DokumentFortschritt;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Seite Dokumente (Spec §3.4, §8): die lange Form zum Bereitstellen an eine
 * Gruppe (Suche, Firma, aktiv, Taetigkeit, Veranstaltung, Haken je Person)
 * und der Ueberblick ueber alles, was unterwegs ist — egal, von wo es
 * gestartet wurde. Der Empfaengerwaehler existiert NUR hier.
 */
class Documents extends Component
{
    use WithFileUploads;

    public $datei = null;
    public string $titel = '';
    public string $kategorie = 'other';
    public string $aktion = 'none';

    public string $suche = '';
    public string $firma = '';
    public string $aktiv = 'active';
    public string $taetigkeit = '';
    public ?int $eventId = null;
    /** @var list<int> ids, die HR abgewaehlt hat */
    public array $abgewaehlt = [];
    public bool $waehlerOffen = false;

    public string $zeige = 'offen';
    public ?int $aufgeklappt = null;
    public ?string $flash = null;
    public ?string $flashError = null;

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->id;
    }

    public function updatedKategorie(): void
    {
        $this->aktion = DokumentKategorie::exists($this->kategorie) ? DokumentKategorie::defaultAktion($this->kategorie) : 'none';
    }

    public function updatedDatei(): void
    {
        if ($this->titel === '' && $this->datei) {
            $this->titel = (string) pathinfo($this->datei->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    /** Jeder Filterwechsel setzt die Abwahl zurueck — sonst bleiben Haken an Personen haengen, die nicht mehr in der Liste sind. */
    public function updated(string $name): void
    {
        if (in_array($name, ['suche', 'firma', 'aktiv', 'taetigkeit', 'eventId'], true)) {
            $this->abgewaehlt = [];
            unset($this->kandidaten);
        }
    }

    public function toggle(int $id): void
    {
        $this->abgewaehlt = in_array($id, $this->abgewaehlt, true)
            ? array_values(array_diff($this->abgewaehlt, [$id]))
            : [...$this->abgewaehlt, $id];
    }

    /** @return list<array{id:int, name:string, personnel_number:?string, company:?string, person_key:?string, hat_portal:bool}> */
    #[Computed]
    public function kandidaten(): array
    {
        return DokumentEmpfaengerSuche::finde($this->teamId(), [
            'suche' => $this->suche, 'firma' => $this->firma, 'aktiv' => $this->aktiv,
            'taetigkeit' => $this->taetigkeit, 'event_id' => $this->eventId,
        ]);
    }

    /** @return list<int> */
    public function gewaehlteIds(): array
    {
        return array_values(array_filter(
            array_column($this->kandidaten, 'id'),
            fn (int $id) => !in_array($id, $this->abgewaehlt, true)
        ));
    }

    /** @return list<string> */
    #[Computed]
    public function taetigkeitOptionen(): array
    {
        return DokumentEmpfaengerSuche::taetigkeiten($this->teamId());
    }

    /** @return list<array{id:int, label:string}> kommende Veranstaltungen, naechste zuerst */
    #[Computed]
    public function eventOptionen(): array
    {
        return RecDispoEvent::query()
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()))
            ->orderBy('starts_on')
            ->limit(100)
            ->get(['id', 'name', 'einsatz_ref', 'starts_on'])
            ->map(fn (RecDispoEvent $e) => [
                'id'    => (int) $e->id,
                'label' => trim(($e->starts_on?->format('d.m.Y') ?? '') . ' ' . ($e->name ?: $e->einsatz_ref)),
            ])
            ->all();
    }

    public function bereitstellen(): void
    {
        $this->flash = null;
        $this->flashError = null;
        if (!$this->datei) {
            $this->flashError = 'Bitte eine PDF auswählen.';

            return;
        }
        $ids = $this->gewaehlteIds();

        try {
            $e = app(DokumentService::class)->bereitstellen(
                $this->teamId(),
                ['title' => $this->titel, 'category' => $this->kategorie, 'action' => $this->aktion],
                (string) file_get_contents($this->datei->getRealPath()),
                (string) $this->datei->getClientOriginalName(),
                $ids,
                $this->eventId,
                auth()->id(),
            );
        } catch (\InvalidArgumentException $ex) {
            $this->flashError = $ex->getMessage();

            return;
        }

        DokumentHinweiseVersenden::starten($e['dokument']);

        $this->datei = null;
        $this->titel = '';
        $this->kategorie = 'other';
        $this->aktion = 'none';
        $this->abgewaehlt = [];
        $this->waehlerOffen = false;
        $this->aufgeklappt = (int) $e['dokument']->id;
        unset($this->dokumente, $this->kandidaten);
        $this->flash = $e['versand_noetig']
            ? "An {$e['empfaenger']} Personen bereitgestellt, Versand läuft im Hintergrund."
            : "An {$e['empfaenger']} Personen abgelegt.";
    }

    public function aufklappen(int $documentId): void
    {
        $this->aufgeklappt = $this->aufgeklappt === $documentId ? null : $documentId;
    }

    public function erneutSenden(int $recipientId): void
    {
        $z = RecDocumentRecipient::query()->where('team_id', $this->teamId())->find($recipientId);
        if ($z === null) {
            return;
        }
        $status = app(DokumentService::class)->erneutSenden($z);
        $this->flash = $status === 'sent' ? 'WhatsApp erneut gesendet.' : null;
        $this->flashError = $status === 'sent' ? null : 'Nicht gesendet: ' . $status;
        unset($this->dokumente);
    }

    public function zurueckziehen(int $recipientId): void
    {
        $z = RecDocumentRecipient::query()->where('team_id', $this->teamId())->find($recipientId);
        if ($z === null) {
            return;
        }
        $this->flashError = app(DokumentService::class)->zurueckziehen($z);
        $this->flash = $this->flashError === null ? 'Zurückgezogen.' : null;
        unset($this->dokumente);
    }

    public function dokumentZurueckziehen(int $documentId): void
    {
        $d = RecDocument::query()->where('team_id', $this->teamId())->find($documentId);
        if ($d === null) {
            return;
        }
        $e = app(DokumentService::class)->dokumentZurueckziehen($d);
        $this->flash = "{$e['zurueckgezogen']} Zustellungen zurückgezogen" . ($e['geloescht'] ? ', Dokument entfernt.' : ', Dokument bleibt als Nachweis.');
        unset($this->dokumente);
    }

    /**
     * @return list<array{id:int, uuid:string, title:string, category_label:string, action:string, action_label:string, event:?string, erstellt:string, geloescht:bool, fortschritt:array, erledigt:bool, benachrichtigt:int, ausstehend:int, empfaenger:list<array>}>
     */
    #[Computed]
    public function dokumente(): array
    {
        $teamId = $this->teamId();
        $docs = RecDocument::query()->withTrashed()
            ->where('team_id', $teamId)
            ->with(['recipients', 'event'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $out = [];
        foreach ($docs as $d) {
            $zeiten = $d->recipients->map(fn (RecDocumentRecipient $z) => $z->zeitstempel())->all();
            $f = DokumentFortschritt::fuer($zeiten, (string) $d->action);
            $brauchtVersand = DokumentKategorie::brauchtHandlung((string) $d->action);
            $benachrichtigt = $d->recipients->whereNotNull('notified_at')->count();
            $ausstehend = $brauchtVersand
                ? $d->recipients->filter(fn ($z) => $z->withdrawn_at === null && $z->notified_at === null && $z->notify_error === null)->count()
                : 0;
            $erledigt = $f['gesamt'] === 0 || $f['erledigt'] === $f['gesamt'];
            if ($this->zeige === 'offen' && $erledigt) {
                continue;
            }
            // $ausstehend wird oben berechnet und unten mitgegeben; die Zeile bleibt
            // auch bei "nur offene" sichtbar, solange der Versand laeuft.
            $empfaenger = [];
            if ($this->aufgeklappt === (int) $d->id) {
                $zeilen = $d->recipients->each(fn ($z) => $z->setRelation('document', $d))->sortBy('id')->values()->all();
                $namen = DB::table('rec_employees')->whereIn('id', array_map(fn ($z) => $z->rec_employee_id, $zeilen))
                    ->get(['id', 'first_name', 'last_name'])->keyBy('id');
                foreach (DokumentAkteZeilen::fuer($zeilen) as $zeile) {
                    $n = $namen->get($zeile['employee_id']);
                    $zeile['name'] = $n ? trim($n->first_name . ' ' . $n->last_name) : ('Mitarbeiter #' . $zeile['employee_id']);
                    $empfaenger[] = $zeile;
                }
            }
            $out[] = [
                'id'             => (int) $d->id,
                'uuid'           => (string) $d->uuid,
                'title'          => (string) $d->title,
                'category_label' => DokumentKategorie::label((string) $d->category),
                'action'         => (string) $d->action,
                'action_label'   => DokumentKategorie::aktionLabel((string) $d->action),
                'event'          => $d->event?->name ?: $d->event?->einsatz_ref,
                'erstellt'       => $d->created_at?->format('d.m.Y H:i') ?? '',
                'geloescht'      => $d->deleted_at !== null,
                'fortschritt'    => $f,
                'erledigt'       => $erledigt,
                'benachrichtigt' => $benachrichtigt,
                'ausstehend'     => $ausstehend,
                'empfaenger'     => $empfaenger,
            ];
        }

        return $out;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.documents')
            ->layout('platform::layouts.app');
    }
}
```

- [ ] **Step 6: Blade der Seite**

`resources/views/livewire/employees/documents.blade.php`:

```blade
@php
    $kategorien = \Platform\Recruiting\Support\DokumentKategorie::labels();
    $aktionen = \Platform\Recruiting\Support\DokumentKategorie::aktionen();
    $kandidaten = $this->kandidaten;
    $gewaehlt = count($kandidaten) - count(array_intersect($abgewaehlt, array_column($kandidaten, 'id')));
    $ohnePortal = count(array_filter($kandidaten, fn ($k) => !$k['hat_portal'] && !in_array($k['id'], $abgewaehlt, true)));
    $dokumente = $this->dokumente;
    $versandLaeuft = count(array_filter($dokumente, fn ($d) => $d['ausstehend'] > 0)) > 0;
@endphp
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Dokumente" icon="heroicon-o-document-text" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Recruiting', 'href' => route('recruiting.dashboard'), 'icon' => 'briefcase'],
            ['label' => 'Mitarbeiter', 'href' => route('recruiting.employees.index')],
            ['label' => 'Dokumente'],
        ]">
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="full">
        @if ($flash)
            <div class="mb-4 p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-md">{{ $flash }}</div>
        @endif
        @if ($flashError)
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 text-sm rounded-md">{{ $flashError }}</div>
        @endif

        {{-- 1. Dokument --}}
        <div class="bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">1 · Dokument</h3>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">PDF</label>
                    <input type="file" accept=".pdf" wire:model="datei" class="block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                    <div wire:loading wire:target="datei" class="text-xs text-[var(--ui-muted)] mt-1">Wird hochgeladen …</div>
                </div>
                <div class="md:col-span-4">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Titel</label>
                    <input type="text" wire:model="titel" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Kategorie</label>
                    <select wire:model.live="kategorie" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        @foreach ($kategorien as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Der Mitarbeiter soll</label>
                    <select wire:model="aktion" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        @foreach ($aktionen as $code => $label)
                            <option value="{{ $code }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- 2. Empfaenger --}}
        <div class="mt-4 bg-white border border-[var(--ui-border)] rounded-lg p-4">
            <h3 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">2 · Empfänger</h3>
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Suche</label>
                    <input type="text" wire:model.live.debounce.300ms="suche" placeholder="Name oder Personalnummer" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Firma</label>
                    <select wire:model.live="firma" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="">Alle</option>
                        <option value="RG">RG</option>
                        <option value="MA">MA</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Status</label>
                    <select wire:model.live="aktiv" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="active">Aktiv</option>
                        <option value="inactive">Inaktiv</option>
                        <option value="all">Alle</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Tätigkeit (ZAS, nur RG)</label>
                    <select wire:model.live="taetigkeit" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="">Alle</option>
                        @foreach ($this->taetigkeitOptionen as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] mb-1">Veranstaltung</label>
                    <select wire:model.live="eventId" class="border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                        <option value="">Keine</option>
                        @foreach ($this->eventOptionen as $ev)
                            <option value="{{ $ev['id'] }}">{{ $ev['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between">
                <div class="text-sm">
                    <span class="font-medium">{{ $gewaehlt }}</span> von {{ count($kandidaten) }} ausgewählt
                    @if ($ohnePortal > 0)
                        <span class="text-xs text-amber-700">· {{ $ohnePortal }} noch im alten Portal, bekommen keine WhatsApp</span>
                    @endif
                </div>
                <button type="button" wire:click="$toggle('waehlerOffen')" class="text-xs text-blue-600 hover:underline">{{ $waehlerOffen ? 'Liste einklappen' : 'Liste anzeigen und einzelne abwählen' }}</button>
            </div>

            @if ($waehlerOffen)
                <div class="mt-2 max-h-80 overflow-y-auto border border-[var(--ui-border)] rounded-md divide-y divide-[var(--ui-border)]/60">
                    @forelse ($kandidaten as $k)
                        @php $kAn = !in_array($k['id'], $abgewaehlt, true); @endphp
                        <label class="flex items-center gap-3 px-3 py-1.5 text-sm cursor-pointer hover:bg-[var(--ui-muted-5)]">
                            <input type="checkbox" wire:click="toggle({{ $k['id'] }})" @checked($kAn)>
                            <span class="flex-1">{{ $k['name'] }}</span>
                            <span class="text-xs text-[var(--ui-muted)]">{{ $k['personnel_number'] ?? '—' }} · {{ $k['company'] ?? '—' }}</span>
                            @if (!$k['hat_portal'])
                                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">altes Portal</span>
                            @endif
                        </label>
                    @empty
                        <div class="px-3 py-4 text-sm text-[var(--ui-muted)]">Keine Treffer.</div>
                    @endforelse
                </div>
            @endif

            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="bereitstellen" wire:loading.attr="disabled" wire:target="bereitstellen,datei"
                        class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:opacity-60">
                    An {{ $gewaehlt }} Personen bereitstellen
                </button>
            </div>
        </div>

        {{-- 3. Ueberblick --}}
        <div class="mt-6">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">Unterwegs und erledigt</h3>
                <select wire:model.live="zeige" class="border border-[var(--ui-border)] rounded-md px-2 py-1 text-xs bg-white">
                    <option value="offen">Nur offene</option>
                    <option value="alle">Alle</option>
                </select>
            </div>
            <div class="bg-white border border-[var(--ui-border)] rounded-lg divide-y divide-[var(--ui-border)]/60" @if ($versandLaeuft) wire:poll.5s @endif>
                @forelse ($dokumente as $dok)
                    @php
                        $dokOffen = $aufgeklappt === $dok['id'];
                        $dokBalken = $dok['fortschritt']['gesamt'] > 0 ? (int) round(100 * $dok['fortschritt']['erledigt'] / $dok['fortschritt']['gesamt']) : 0;
                    @endphp
                    <div class="p-3">
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="aufklappen({{ $dok['id'] }})" class="flex-1 text-left">
                                <div class="text-sm font-medium">{{ $dok['title'] }} @if ($dok['geloescht'])<span class="text-xs text-[var(--ui-muted)]">(zurückgezogen)</span>@endif</div>
                                <div class="text-xs text-[var(--ui-muted)]">{{ $dok['category_label'] }} · {{ $dok['action_label'] }} · {{ $dok['erstellt'] }}@if ($dok['event']) · {{ $dok['event'] }}@endif</div>
                            </button>
                            <div class="w-44">
                                <div class="text-xs text-right mb-1">{{ $dok['fortschritt']['text'] }}</div>
                                @if ($dok['ausstehend'] > 0)
                                    <div class="text-[10px] text-right text-amber-700">{{ $dok['benachrichtigt'] }} von {{ $dok['benachrichtigt'] + $dok['ausstehend'] }} benachrichtigt, läuft …</div>
                                @elseif ($dok['action'] !== 'none')
                                    <div class="text-[10px] text-right text-[var(--ui-muted)]">{{ $dok['benachrichtigt'] }} benachrichtigt</div>
                                @endif
                                <div class="h-1.5 bg-[var(--ui-muted-5)] rounded-full overflow-hidden"><div class="h-full bg-emerald-500" style="width: {{ $dokBalken }}%"></div></div>
                            </div>
                            <a href="{{ route('recruiting.employees.dokument.datei', ['uuid' => $dok['uuid']]) }}" target="_blank" class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md bg-white hover:bg-[var(--ui-muted-5)]">PDF</a>
                            @if (!$dok['geloescht'] && !$dok['erledigt'])
                                <button type="button" wire:click="dokumentZurueckziehen({{ $dok['id'] }})" wire:confirm="„{{ $dok['title'] }}" für alle Offenen zurückziehen?" class="px-3 py-1.5 border border-[var(--ui-border)] text-xs rounded-md text-[var(--ui-muted)] hover:bg-red-50 hover:text-red-700">Zurückziehen</button>
                            @endif
                        </div>
                        @if ($dokOffen)
                            <div class="mt-3 border-t border-[var(--ui-border)]/60 pt-2 space-y-1">
                                @foreach ($dok['empfaenger'] as $em)
                                    @php
                                        $emVersand = $em['versand_text'];
                                        $emZeigtErneut = $em['action'] !== 'none' && $em['status'] !== 'zurueckgezogen' && !$em['benachrichtigt'] && !$em['versand_laeuft'];
                                    @endphp
                                    <div class="flex items-center gap-3 text-sm">
                                        <a href="{{ route('recruiting.employees.show', $em['employee_id']) }}" wire:navigate class="flex-1 hover:underline">{{ $em['name'] }}</a>
                                        <span class="text-xs">{{ $em['status_label'] }}</span>
                                        <span class="text-xs text-[var(--ui-muted)] w-44">{{ $emVersand }}</span>
                                        @if ($em['hat_nachweis'])
                                            <a href="{{ route('recruiting.employees.dokument.nachweis', ['uuid' => $em['recipient_uuid']]) }}" target="_blank" class="text-xs text-emerald-700 hover:underline">Nachweis</a>
                                        @endif
                                        @if ($emZeigtErneut)
                                            <button type="button" wire:click="erneutSenden({{ $em['recipient_id'] }})" class="text-xs text-blue-700 hover:underline">Erneut senden</button>
                                        @endif
                                        @if ($em['kann_zurueckziehen'])
                                            <button type="button" wire:click="zurueckziehen({{ $em['recipient_id'] }})" class="text-xs text-[var(--ui-muted)] hover:text-red-700">Zurückziehen</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-6 text-center text-sm text-[var(--ui-muted)]">Nichts offen.</div>
                @endforelse
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
```

- [ ] **Step 7: Route, Sidebar, Link in der Akte**

In `routes/web.php` direkt vor der Zeile `Route::get('/employees/dokumente/{uuid}/datei', ...)` aus Task 12:

```php
Route::get('/employees/documents', \Platform\Recruiting\Livewire\Employees\Documents::class)
    ->name('recruiting.employees.documents');
```

In `resources/views/livewire/sidebar.blade.php` nach dem `</x-ui-sidebar-item>` des Eintrags „Lohnrelevante Änderungen" (Zeile ~127):

```blade
        <x-ui-sidebar-item :href="route('recruiting.employees.documents')">
            @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dokumente</span>
        </x-ui-sidebar-item>
```

In `resources/views/livewire/employees/show.blade.php` die Überschrift des Abschnitts aus Task 12 ersetzen:

```blade
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">Dokumente</h3>
                    <a href="{{ route('recruiting.employees.documents') }}" wire:navigate class="text-xs text-[var(--ui-muted)] hover:underline">Alle Dokumente</a>
                </div>
```

In `tests/Integration/DokumentAkteTest::test_hr_routen_sind_registriert` ergänzen:

```php
        $this->assertSame('https://meingedeck.de/recruiting/employees/documents', $url->route('recruiting.employees.documents'));
```

- [ ] **Step 8: Blade prüfen, Tests grün**

Run: `php tools/blade-check.php resources/views/livewire/employees/documents.blade.php && php tools/blade-check.php resources/views/livewire/sidebar.blade.php && php tools/blade-check.php resources/views/livewire/employees/show.blade.php`
Expected: keine Fehler.

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "Dokument|Eingebuchte"`
Expected: `OK`.

- [ ] **Step 9: Commit**

```bash
git add src/Services/DokumentEmpfaengerSuche.php src/Services/EingebuchteFuerDokument.php src/Livewire/Employees/Documents.php resources/views/livewire/employees/documents.blade.php routes/web.php resources/views/livewire/sidebar.blade.php resources/views/livewire/employees/show.blade.php tests/Integration/DokumentEmpfaengerSucheTest.php tests/Integration/EingebuchteFuerDokumentTest.php tests/Integration/DokumentAkteTest.php
git commit -m "feat(recruiting): Seite Dokumente — Empfaengerwaehler mit Taetigkeit und Veranstaltung, Ueberblick 12 von 14" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 15: Veranstaltungsseite — „Dokument an alle Eingebuchten"

**Files:**
- Modify: `src/Livewire/Dispo/Events/Show.php` (Imports, Eigenschaften, Methoden)
- Modify: `resources/views/livewire/dispo/events/show.blade.php` (Kopfknöpfe bei Zeile 211; Modal nach dem Anhang-Modal; Zeile „Dokumente dieser Veranstaltung")
- Test: keine neue Testklasse — `EingebuchteFuerDokumentTest` (Task 14) deckt die Empfängermenge, `DokumentServiceTest::test_veranstaltung_wird_am_dokument_vermerkt` (Task 6) den Vermerk. Die Livewire-Hülle wird per `blade-check` und Sichttest geprüft.

**Interfaces:**
- Produces (Events\Show): `public bool $showDokumentModal = false; public $dokDatei = null; public string $dokTitel = ''; public string $dokKategorie = 'instruction'; public string $dokAktion = 'acknowledge';`; Methoden `openDokumentModal()`, `closeDokumentModal()`, `updatedDokKategorie()`, `updatedDokDatei()`, `dokumentBereitstellen()`; Computed `eingebuchteIds(): list<int>`, `eventDokumente(): list<array{id:int, title:string, action_label:string, fortschritt:array}>`.

- [ ] **Step 1: Komponente erweitern**

Imports:

```php
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\EingebuchteFuerDokument;
use Platform\Recruiting\Support\DokumentFortschritt;
use Platform\Recruiting\Support\DokumentKategorie;
```

Eigenschaften (neben den anderen Modal-Flags, z. B. nach `public bool $showNotesModal = false;`):

```php
    /** Dokument an alle Eingebuchten (Spec Dokumente §3.3). */
    public bool $showDokumentModal = false;
    public $dokDatei = null;
    public string $dokTitel = '';
    public string $dokKategorie = 'instruction';
    public string $dokAktion = 'acknowledge';
```

Im `$pollBlocked`-Ausdruck am Blade-Anfang (Zeile 5) `|| $showDokumentModal` ergänzen.

Methoden (nach `removeAttachment()`):

```php
    public function openDokumentModal(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->dokDatei = null;
        $this->dokTitel = '';
        $this->dokKategorie = 'instruction';
        $this->dokAktion = 'acknowledge';
        $this->resetErrorBag('dokDatei');
        $this->showDokumentModal = true;
    }

    public function closeDokumentModal(): void
    {
        $this->showDokumentModal = false;
    }

    public function updatedDokKategorie(): void
    {
        $this->dokAktion = DokumentKategorie::exists($this->dokKategorie) ? DokumentKategorie::defaultAktion($this->dokKategorie) : 'none';
    }

    public function updatedDokDatei(): void
    {
        if ($this->dokTitel === '' && $this->dokDatei) {
            $this->dokTitel = (string) pathinfo($this->dokDatei->getClientOriginalName(), PATHINFO_FILENAME);
        }
    }

    /** @return list<int> */
    #[Computed]
    public function eingebuchteIds(): array
    {
        return EingebuchteFuerDokument::ids($this->eventId);
    }

    public function dokumentBereitstellen(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        if (!$this->dokDatei) {
            $this->addError('dokDatei', 'Bitte eine PDF auswählen.');

            return;
        }
        try {
            $e = app(DokumentService::class)->bereitstellen(
                (int) auth()->user()->currentTeam->id,
                ['title' => $this->dokTitel, 'category' => $this->dokKategorie, 'action' => $this->dokAktion],
                (string) file_get_contents($this->dokDatei->getRealPath()),
                (string) $this->dokDatei->getClientOriginalName(),
                $this->eingebuchteIds,
                $this->eventId,
                auth()->id(),
            );
        } catch (\InvalidArgumentException $ex) {
            $this->addError('dokDatei', $ex->getMessage());

            return;
        }
        DokumentHinweiseVersenden::starten($e['dokument']);
        $this->showDokumentModal = false;
        $this->dokDatei = null;
        unset($this->eventDokumente);
        session()->flash('dokument_flash', $e['versand_noetig']
            ? "An {$e['empfaenger']} Eingebuchte bereitgestellt, WhatsApp läuft im Hintergrund — Stand auf der Dokumente-Seite."
            : "An {$e['empfaenger']} Eingebuchte abgelegt.");
    }

    /** @return list<array{id:int, title:string, action_label:string, fortschritt:array}> */
    #[Computed]
    public function eventDokumente(): array
    {
        return RecDocument::query()
            ->where('rec_dispo_event_id', $this->eventId)
            ->with('recipients')
            ->orderByDesc('id')
            ->get()
            ->map(fn (RecDocument $d) => [
                'id'           => (int) $d->id,
                'title'        => (string) $d->title,
                'action_label' => DokumentKategorie::aktionLabel((string) $d->action),
                'fortschritt'  => DokumentFortschritt::fuer($d->recipients->map(fn (RecDocumentRecipient $z) => $z->zeitstempel())->all(), (string) $d->action),
            ])
            ->all();
    }
```

- [ ] **Step 2: Blade — Knopf, Zeile, Modal**

Kopfknöpfe: vor `<button wire:click="openInfoModal"` (Zeile 211) einfügen:

```blade
            @php $dokEingebuchte = count($this->eingebuchteIds); @endphp
            <button wire:click="openDokumentModal"
                    @if ($dokEingebuchte === 0) disabled title="Niemand eingebucht (Status Auftrag)" @endif
                    class="rounded bg-white px-3 py-1.5 text-sm font-medium ring-1 {{ $dokEingebuchte > 0 ? 'text-gray-700 ring-gray-200 hover:bg-gray-50' : 'text-gray-400 ring-gray-100 cursor-not-allowed' }}"
                    title="PDF an alle Eingebuchten dieser Veranstaltung">
                Dokument an Eingebuchte <span class="tabular-nums opacity-60">{{ $dokEingebuchte }}</span>
            </button>
```

Zeile „Dokumente dieser Veranstaltung": nach dem `<div class="grid grid-cols-1 gap-4 md:grid-cols-2">`-Block mit Adresse/Ort (also nach dessen schließendem `</div>`) einfügen:

```blade
    @if (session('dokument_flash'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('dokument_flash') }}</div>
    @endif
    @php $evDoks = $this->eventDokumente; @endphp
    @if ($evDoks !== [])
        <div class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between">
                <div class="text-sm font-medium text-gray-500">Dokumente dieser Veranstaltung</div>
                <a href="{{ route('recruiting.employees.documents') }}" wire:navigate class="text-xs text-blue-600 hover:underline">Zur Dokumente-Seite</a>
            </div>
            <div class="mt-2 space-y-1">
                @foreach ($evDoks as $evd)
                    <div class="flex items-center justify-between text-sm">
                        <span>{{ $evd['title'] }} <span class="text-xs text-gray-400">· {{ $evd['action_label'] }}</span></span>
                        <span class="text-xs tabular-nums text-gray-600">{{ $evd['fortschritt']['text'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
```

Modal: nach dem `@endif` des Anhang-Modals (`@if ($showAttachmentModal) ... @endif`) einfügen:

```blade
    @if ($showDokumentModal)
        @php
            $dokKategorien = \Platform\Recruiting\Support\DokumentKategorie::labels();
            $dokAktionen = \Platform\Recruiting\Support\DokumentKategorie::aktionen();
            $dokAnzahl = count($this->eingebuchteIds);
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center" wire:click.self="closeDokumentModal">
            <div class="w-full max-w-lg my-auto flex max-h-[calc(100dvh-2rem)] flex-col rounded-lg bg-white">
                <div class="shrink-0 px-6 pt-6 pb-3">
                    <h2 class="text-lg font-semibold">Dokument an {{ $dokAnzahl }} Eingebuchte</h2>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 pb-2 space-y-4">
                    <p class="text-sm text-gray-500">Geht an alle, die laut Dispo im Status Auftrag sind — einmalig jetzt. Nachrücker legst du über die Akte oder die Dokumente-Seite nach. Wer noch im alten Portal ist, bekommt keine WhatsApp, sieht das Dokument aber nach der Umstellung.</p>
                    <label class="block text-sm">
                        <span class="text-xs font-medium text-gray-500">PDF</span>
                        <input type="file" accept=".pdf" wire:model="dokDatei" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100">
                    </label>
                    <div wire:loading wire:target="dokDatei" class="text-xs text-gray-500">Wird hochgeladen …</div>
                    @error('dokDatei') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <label class="block text-sm">
                        <span class="text-xs font-medium text-gray-500">Titel</span>
                        <input type="text" wire:model="dokTitel" class="mt-1 w-full rounded border border-gray-300 px-3 py-1.5 text-sm">
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm">
                            <span class="text-xs font-medium text-gray-500">Kategorie</span>
                            <select wire:model.live="dokKategorie" class="mt-1 w-full rounded border border-gray-300 px-3 py-1.5 text-sm bg-white">
                                @foreach ($dokKategorien as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="text-xs font-medium text-gray-500">Der Mitarbeiter soll</span>
                            <select wire:model="dokAktion" class="mt-1 w-full rounded border border-gray-300 px-3 py-1.5 text-sm bg-white">
                                @foreach ($dokAktionen as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </div>
                <div class="shrink-0 border-t border-gray-100 px-6 py-4">
                    <div class="flex justify-end gap-3">
                        <button wire:click="closeDokumentModal" class="rounded px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Abbrechen</button>
                        <button wire:click="dokumentBereitstellen" wire:loading.attr="disabled" wire:target="dokumentBereitstellen,dokDatei" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60">An {{ $dokAnzahl }} Personen bereitstellen</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
```

- [ ] **Step 3: Blade prüfen, Suite grün**

Run: `php tools/blade-check.php resources/views/livewire/dispo/events/show.blade.php`
Expected: keine Fehler.

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "Dispo|Event"`
Expected: `OK` (die bestehenden Dispo-Tests dürfen sich nicht ändern; `eingebuchteIds` ist nur Lesen).

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Dispo/Events/Show.php resources/views/livewire/dispo/events/show.blade.php
git commit -m "feat(recruiting): Veranstaltungsseite — Dokument an alle Eingebuchten, Stand je Dokument" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---
### Task 16: Spec nachziehen, Gesamtlauf, Mutationsproben

**Files:**
- Modify: `docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` (§4, §12)
- Test: gesamte Suite

**Interfaces:** keine neuen.

- [ ] **Step 1: Spec §4 und §12 an die Umsetzung angleichen**

In §4 den ersten Spiegelstrich ersetzen:

```markdown
- Meta-Vorlage aus der Team-Einstellung `document_wa_template_id` (Einstellungs-Fenster →
  Kommunikation, Liste der genehmigten Vorlagen), aufgelöst über dieselbe Kette wie
  Zertifikat und Fristenlauf (`HoldingTemplateSender::resolveTarget`). Platzhalter nur
  `vorname` plus URL-Knopf mit dem Portal-Token. Kein Dokumenttitel in der Nachricht:
  universeller Text „im Portal liegt etwas für dich“.
```

In §12 den zweiten Spiegelstrich ersetzen:

```markdown
- Einstellungs-Fenster → Kommunikation: „Dokumente — WhatsApp-Template mit Portal-Link“
  auf die genehmigte Meta-Vorlage setzen. Ohne Wert liegt jedes Dokument bereit, aber
  niemand bekommt eine WhatsApp (Status `nicht_konfiguriert`, Erneut-senden holt es nach).
- `queue:restart` nach dem Deploy: die Hinweise verschickt der Job `DokumentHinweiseVersenden`
  auf dem Worker, nie der Klick.
```

Dazu in §3.1 Schritt 5 ersetzen durch:

```markdown
5. NACH der Transaktion stellt die Oberfläche den Job `DokumentHinweiseVersenden` ein
   (nur bei `action ≠ none`). Der Job ruft `DokumentService::hinweiseVersenden()`: je
   Zustellung ohne Versuch die WhatsApp, Ergebnis in `notified_at`/`notify_error`.
   Idempotent, ein Fehlschlag wird nicht von selbst wiederholt. HR sieht den Stand
   („37 von 200 benachrichtigt, läuft …") auf der Seite Dokumente und in der Akte.
```

und in §6 einen Absatz anfügen:

```markdown
Damit niemand zweimal zum selben Dokument angeschrieben wird, lässt die Einsatz-Prüfung
Dokumente aus, die in den letzten `EinsatzBezug::PAUSE_TAGE` (7) Tagen ihre eigene WhatsApp
bekommen haben (`OffenePunkte::fuerTrigger()`); Fehlversuche ohne `notified_at` bleiben drin,
dort ist die Einsatz-Prüfung der zweite Fang. Das Portal zeigt alle offenen Dokumente.
```

Dazu in §2.2 bei `rec_document_recipients` eine Zeile ergänzen:

```markdown
| `uuid` | uuid unique | öffentliche Kennung des Portal-Downloads (eine Zustellung, ein Mensch) |
```

- [ ] **Step 2: Gesamte Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml 2>&1 | tail -4`
Expected: `OK` mit mindestens 3667 + ~75 neuen Tests, keine Failures, keine Errors. Warnungen/Deprecations wie vor dem Paket (3 PHPUnit Deprecations).

- [ ] **Step 3: Blade-Prüfung aller angefassten Vorlagen**

Run:
```bash
for f in resources/views/livewire/public/portal-shell.blade.php resources/views/livewire/employees/show.blade.php resources/views/livewire/employees/documents.blade.php resources/views/livewire/dispo/events/show.blade.php resources/views/livewire/sidebar.blade.php resources/views/pdf/dokument-nachweis.blade.php; do php tools/blade-check.php "$f" || echo "FEHLER in $f"; done
```
Expected: keine Zeile `FEHLER in`.

- [ ] **Step 4: Mutationsproben (Checkliste `reference_pruefmuster_gruenes_nichts`)**

Jede Mutation einzeln einbauen, den genannten Test laufen lassen, ROT sehen, zurücknehmen. Nichts committen, solange eine Mutation grün bleibt.

| Mutation | muss rot werden |
|---|---|
| `DokumentUploadRegeln::pruefe`: Magic-Byte-Prüfung entfernen | `DokumentUploadRegelnTest::test_umbenanntes_jpg_wird_abgelehnt` |
| `EmpfaengerEntdoppler`: `$id < $proPerson[$key]` zu `>` | `EmpfaengerEntdopplerTest::test_zwei_anstellungen_einer_person_werden_eine` |
| `DokumentService::bereitstellen`: `->where('team_id', $teamId)` entfernen | `DokumentServiceTest::test_fremdes_team_wird_stumm_uebersprungen` |
| `DokumentService::hinweisSenden`: `'notify_error' => null` im Erfolgszweig weglassen | `DokumentServiceTest::test_erneut_senden_schreibt_erfolg_und_loescht_fehler` |
| `DokumentService::zurueckziehen`: `->whereNull('signed_at')` entfernen und Vorab-Prüfung weglassen | `DokumentServiceTest::test_zurueckziehen_nach_unterschrift_verweigert` |
| `DokumentHinweisSender::sende`: Rückgabe `STATUS_SENT` auch bei `status === 'failed'` | `DokumentHinweisSenderTest::test_meta_ablehnung_ist_failed_nicht_sent` |
| `DokumentHinweisSender::sende`: `portal_v2_since`-Wächter entfernen | `DokumentHinweisSenderTest::test_altes_portal_bekommt_keine_nachricht` |
| `DokumentUnterschrift::unterschreiben`: `hash_equals`-Vergleich entfernen | `DokumentUnterschriftTest::test_manipulierte_datei_verhindert_unterschrift` |
| `DokumentUnterschrift::oeffnen`: `->whereNull('first_viewed_at')` entfernen | `DokumentUnterschriftTest::test_oeffnen_setzt_gesehen_genau_einmal` |
| `DokumentLeser::sichtbare`: `->whereNull('withdrawn_at')` entfernen | `DokumentLeserTest::test_zurueckgezogene_und_geloeschte_fehlen` |
| `DokumentLeser::sichtbare`: `whereIn('rec_employee_id', $ids)` durch `where('rec_employee_id', $employee->id)` ersetzen | `DokumentLeserTest::test_sieht_dokumente_beider_anstellungen_einmal_und_fremde_nie` |
| `OffenePunkte::fuer`: `array_merge` mit Dokument-Punkten entfernen | `OffenePunkteDokumenteTest::test_offenes_dokument_ist_genau_ein_punkt_ohne_sperre` |
| `PortalShell::eigeneZustellung`: `empfaenger()`-Prüfung durch `RecDocumentRecipient::find($this->dokumentId)` ersetzen | `PortalShellMitarbeiterDokumenteTest::test_aktion_mit_manipulierter_id_schreibt_nichts` |
| `DokumentZugriff::entscheide`: `!$sitzungGueltig` ignorieren | `DokumentZugriffTest::test_matrix` |
| `EingebuchteFuerDokument::ids`: `->whereNull('missing_since')` entfernen | `EingebuchteFuerDokumentTest::test_nur_auftrag_ohne_missing_mit_mitarbeiter_entdoppelt` |
| `DokumentEmpfaengerSuche::finde`: Tätigkeit-Zweig auf `LIKE '%' . $taetigkeit . '%'` gegen den Rohtext umstellen | `DokumentEmpfaengerSucheTest::test_taetigkeit_filtert_ueber_den_zas_katalog` (Umlaut) |
| `DokumentService::hinweiseVersenden`: `->whereNull('notify_error')` entfernen | `DokumentServiceTest::test_hinweise_versenden_ist_idempotent_und_laesst_zurueckgezogene_aus` |
| `DokumentLeser::offenePunkte`: die `$grenze`-Prüfung entfernen | `DokumentLeserTest::test_frisch_gemeldete_dokumente_fallen_fuer_den_trigger_weg` |
| `OffenePunkte::fuerTrigger`: `EinsatzBezug::PAUSE_TAGE` durch `null` ersetzen | `OffenePunkteDokumenteTest::test_fuer_trigger_laesst_frisch_gemeldete_aus_und_ist_sonst_gleich` |

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md
git commit -m "docs(recruiting): Spec Dokumente — Vorlagenquelle Konfiguration, Empfaenger-uuid, Deploy-Hinweis" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Nach dem letzten Task (für den Orchestrator, nicht für die Implementierer)

- Prüf-Agent vor dem Merge (Memory-Regel `feedback_review_agent_vor_merge`): außensichtbar und identitätsberührend. Auftrag konkret: (1) kann eine fremde Empfänger-ID über `$wire.set('dokumentId', …)` zu einer Schreibung führen; (2) liefert der Download-Controller je eine Datei ohne Sitzung, mit Sitzung einer fremden Person, nach Zurückziehen; (3) schreibt irgendein Pfad auf `rec_employees`; (4) Blade-Hausregeln in allen sechs Vorlagen; (5) `whereJsonContains` auf MySQL 8 gegen das echte JSON-Format von `dispo_taetigkeiten`.
- Vor dem Push `git fetch origin && git merge origin/main`, Suite erneut.
- Deploy: zwei Migrationen, `view:clear`, und `queue:restart` — der Job `DokumentHinweiseVersenden` läuft auf dem Forge-Worker (Memory `feedback_forge_queue_restart_after_deploy`).
- Demo-Pin erst nach dem Review; Demo braucht `migrate` (zwei neue Migrationen) und im Einstellungs-Fenster die Vorlage unter „Dokumente — WhatsApp-Template mit Portal-Link“ (erst, wenn die Meta-Vorlage genehmigt ist; vorher Status `nicht_konfiguriert`, Dokument liegt trotzdem bereit).
- Sichttest auf der Demo (Spec §12): Akte → PDF ablegen → WhatsApp kommt (sobald Vorlage da) → Portal zeigt Punkt → unterschreiben → Nachweisblatt öffnet → Seite Dokumente zeigt „1 von 1 unterschrieben" → Veranstaltung: Knopf zeigt Anzahl Eingebuchter.
