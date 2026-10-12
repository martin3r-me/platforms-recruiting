# HR-Bereich „Mitarbeiter“ + Konto-Kasten Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR bekommt am HR-Schreibtisch einen Reiter „Mitarbeiter“ (Konto-Anträge, Gesperrt, Erinnert ohne Reaktion, Pilot-Überblick) und in der Mitarbeiterakte einen Konto-Kasten; jede HR-Handlung landet im neuen Verlauf `rec_hr_vermerke`, der Notfallweg bekommt einen Team-Schalter, und zurückgesetzte Konten beenden laufende Portal-Sitzungen.

**Architecture:** Ein Schreib-Eingang `HrKontoAktionen` (Team aus `Auth`, Scope-Prüfung, Notiz-Pflicht, Schreiber/Sender rufen, Vermerk + Log) und ein Lese-Eingang `HrMitarbeiterListen` (vier Listen, Abzeichen, Konto-Kasten-Daten) — beide ohne Livewire testbar. Zwei dünne Livewire-Kinder (`HrDesk\Mitarbeiter`, `Employees\KontoKasten`) rufen nur diese beiden. `KontoWriter` liest Nummernwechsel-Anträge künftig mit `lockForUpdate()` in einer Transaktion, damit Stopp/Freigabe/Stundenlauf sich nicht überholen; `PortalAuth::sitzungGueltig()` ist die eine Sitzungs-Prüfstelle.

**Tech Stack:** PHP 8.4, Laravel 12, Livewire 3 (Modul `platforms-recruiting`, Branch `feat/ma-konto`), PHPUnit 11 mit Capsule/SQLite (kein testbench), Blade mit `x-ui-*`-Komponenten.

**Spec:** `docs/superpowers/specs/2026-10-12-hr-bereich-mitarbeiter-design.md` (Stand 7ab6054d). Executors lesen Plan UND Spec.

## Global Constraints

- Tests laufen IMMER über `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` aus dem Modulverzeichnis `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting-portal` (kein eigenes `vendor/`). Kein `--order-by=random` (Reihenfolge-Falle, siehe `phpunit.xml`).
- Blade-Dateien werden mit `php tools/blade-check.php <datei>` geprüft, NIE mit `php -l`.
- Nur dieses Modul. Kein Edit in Core/CRM/HCM/meingedeck.
- Schreibzugriffe an `rec_employees` NUR über `DB::table(...)` (Query-Builder): kein Eloquent-`save()`/`update()`, kein `updated_at`, kein `zas_changed_at` (kein ZAS-Marker).
- Kontofelder an `rec_persons` schreibt nur `KontoWriter` bzw. (nur `sitzungen_ungueltig_ab`) `HrKontoAktionen` — beide per `DB::table`; Kontofelder bleiben außerhalb von `$fillable` (`MassenzuweisungGeschlosseneWeltTest` bleibt grün).
- Team-Scope an JEDER Liste und JEDER Aktion: Team kommt aus `Auth::user()->currentTeam` (nie aus Request/Parametern); Personen nur mit `rec_persons.team_id = Team` UND einer `rec_employees`-Zeile im Team; Nachweise/Dokumente/Verträge über `rec_employees.team_id`; `team_id = null` erscheint nirgends.
- Livewire: Modal-Kontext (`aktion`, `aktionPersonId`, `aktionEmployeeId`, `aktionBezug`, `erwartetBeantragtAt`, `einladungsLink`, `einladungsCode`) und `KontoKasten::$employeeId` sind `#[Locked]`; Methoden-Parameter gelten als Eingabe und werden serverseitig geprüft; erlaubte Aktionen als geschlossene Liste.
- Blade-Hausregeln: kein `@if` in Komponenten-Attributen, keine `:attr="… ?? …"`-Fallbacks an `x-ui-*`, Direktiven nie an Wortzeichen kleben (`da@if` kompiliert nicht), `@php … @endphp` nur in Block-Form, Zustände vorher berechnen, NIE `wire:model` an eine datetime-gecastete Property, keine Selects im Einstellungs-Modal (Select-Speicherfehler) — Schalter als rohe Checkbox, Zahlen als `x-ui-input-text type="number"`. Knöpfe mit `wire:loading.attr="disabled"` + `wire:target`.
- UI-Text auf Deutsch mit echten Umlauten und „…“-Anführungszeichen; Nummern nur als `…1234` (`NummernwechselTafel::kurz`).
- Geheimnisse: Einladungs-Klartext (Link/Code) nur im Modal (`#[Locked]`-Property, beim Schließen geleert); NIE in Log, Vermerk, Flash oder URL-Query. Notizen nie ins Log, nur escaped (`{{ }}`) ausgeben.
- Notiz-Pflicht: getrimmt 5–500 Zeichen bei allen Arten außer `erneut_erinnert` (dort optional, aber wenn angegeben 5–500).
- Einstellungs-Schlüssel exakt: `konto_notfall_weg_aktiv` (Vorgabe `false`), `hr_erinnert_tage` (Vorgabe `5`, 1–60), `hr_kontaktiert_ruhe_tage` (Vorgabe `3`, 1–30).
- Vermerk-Arten exakt: `antrag_gestoppt`, `antrag_freigegeben`, `konto_entsperrt`, `portal_entsperrt`, `bremse_geloest`, `notfallsperre_aufgehoben`, `einladung_neu`, `konto_zurueckgesetzt`, `kontaktiert`, `erneut_erinnert`.
- Jeder Commit endet mit genau diesen Trailer-Zeilen:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
  ```
- Kein Push, kein Merge (ff-Merge-Workflow nach Freigabe, nicht Teil dieses Plans).

## Review Focus

1. **Stopp/Freigabe gegen den stündlichen `--faellig`-Lauf** (Wettlauf): HR stoppt einen Antrag, den der Stundenlauf gerade anwendet — HR muss die Wahrheit lesen („war schon erledigt, Nummer steht jetzt auf …“), es darf kein Vermerk entstehen, und eine Freigabe darf nicht zu einem zweiten Wechsel/Hinweis führen. Gepinnt: Task 3 `test_alle_drei_wege_lesen_die_zeile_gesperrt_in_einer_transaktion`, Task 5 `test_stopp_nach_angewendetem_antrag_meldet_schon_erledigt_ohne_vermerk` und `test_freigabe_dann_stundenlauf_kein_zweiter_wechsel_kein_zweiter_hinweis`.
2. **HR aus Team A handelt an einer Person aus Team B** (per `$wire.set('aktionPersonId', …)` oder per Methoden-Parameter): erwartet „Nicht gefunden.“, nichts geschrieben, kein Vermerk. Gepinnt: Task 7 `test_aktionskontext_ist_gegen_wire_set_gesperrt` und `test_oeffne_mit_person_aus_fremdem_team_meldet_nicht_gefunden`, Task 5 `test_fremdes_team_wird_bei_jeder_aktion_abgewiesen`.
3. **Altwert `true` in einer Portal-Sitzung nach „Konto zurücksetzen“**: Sitzungen von vor dem Deploy tragen `true`; nach dem Zurücksetzen muss genau diese Sitzung sterben (Schlüssel verworfen), ohne Rücksetzen aber weiterleben. Gepinnt: Task 2 `test_altwert_true_stirbt_mit_dem_zuruecksetzen` und `test_altwert_true_lebt_ohne_ungueltigkeit_weiter`, Task 5 `test_zuruecksetzen_beendet_laufende_sitzungen_aller_akten`.
4. **Mensch mit RG- und MA-Akte erscheint doppelt** in einer Liste (Abzeichen zählt doppelt, HR handelt zweimal): erwartet genau eine Zeile mit Chips „RG · MA“ in allen vier Listen. Gepinnt: Task 6 `test_rg_und_ma_derselben_person_sind_eine_zeile_in_allen_listen`.
5. **„Kontaktiert“ versteckt einen Menschen, dessen nächster Einsatz morgen ist**: eine Ruhezeit, die über den nächsten Einsatz hinausreicht, würde genau den dringendsten Fall verstecken; erwartet: Zeile bleibt sichtbar (mit Hinweis „kontaktiert am …“), ruht nur, wenn der nächste Einsatz nach dem Ruhe-Ende liegt. Gepinnt: Task 6 `test_kontaktiert_ruht_nicht_wenn_der_naechste_einsatz_in_die_ruhezeit_faellt`.

---

## Dateiübersicht

| Datei | neu/geändert | Verantwortung | Task |
|---|---|---|---|
| `database/migrations/2026_10_12_000001_create_rec_hr_vermerke_table.php` | neu | Verlaufstabelle | 1 |
| `database/migrations/2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php` | neu | Sitzungs-Grenze | 1 |
| `src/Models/RecHrVermerk.php` | neu | Nur-Anfügen-Modell + `anfuegen()`/`letzte()` | 1 |
| `src/Support/HrEinstellungen.php` | neu | Lesen + Begrenzen der drei Team-Einstellungen | 1 |
| `src/Models/RecApplicantSettings.php` | geändert | drei Vorgaben | 1 |
| `src/Services/PortalAuth.php` | geändert | `anmeldeWert()`, `sitzungGueltig()` (2); `gebremsteNummern()`, `loeseNummernBremse()`, Sperr-Zeitpunkt im Cache (4) | 2, 4 |
| `src/Livewire/Public/{KontoAnmelden,PortalShell,EmployeePortal}.php` | geändert | Sitzungswert + Prüfstelle (2); Team-Schalter (9) | 2, 9 |
| `src/Http/Controllers/{DokumentDownloadController,VertragPdfController}.php` | geändert | Prüfstelle | 2 |
| `src/Services/KontoWriter.php` | geändert | Sperr-Lesen, Freigeben, HR-Zurücksetzen, Entsperren, `notfallWegAn()` | 3 |
| `src/Support/NummernwechselTafel.php` | geändert | Zustand „Notfallweg aus“ | 3 |
| `src/Services/PortalEntsperrung.php` | neu | Dispo-Entsperrung (aus `Show::unlockPortal`) | 4 |
| `src/Support/{EinladungsRiegel,Bote,KontoStatus,SperrGruende,HrAktionErgebnis}.php` | neu | reine Regeln / Werte | 4, 5 |
| `src/Console/Commands/KontoEinladen.php`, `EinsatzPruefung.php` | geändert | Auszug nach `EinladungsRiegel` / `Bote`, `LAUF_SPERRE` public | 4 |
| `src/Services/HrKontoAktionen.php` | neu | der eine Schreib-Eingang der Oberfläche | 5 |
| `src/Services/HrMitarbeiterListen.php` | neu | vier Listen + Abzeichen (6), Konto-Kasten-Daten (8) | 6, 8 |
| `src/Livewire/HrDesk/Mitarbeiter.php` + `resources/views/livewire/hr-desk/mitarbeiter.blade.php` | neu | Reiter-Inhalt | 7 |
| `src/Livewire/HrDesk/Index.php` + `index.blade.php` | geändert | Reiterleiste + Abzeichen | 7 |
| `src/Livewire/Employees/KontoKasten.php` + `resources/views/livewire/employees/konto-kasten.blade.php` | neu | Konto-Kasten | 8 |
| `src/Livewire/Employees/Show.php` + `show.blade.php` | geändert | Kasten einbetten, `unlockPortal()` raus | 8 |
| `src/Console/Commands/KontoZuruecksetzen.php` | geändert | `--faellig` mit Team-Schalter | 9 |
| `src/Livewire/Applicant/ApplicantSettingsModal.php` + `applicant-settings-modal.blade.php` | geändert | Abschnitt „Mitarbeiterkonto & Erinnerungen“, Log beim Kippen | 9 |
| `tests/Integration/HrBereichHarness.php` | neu | gemeinsame Testwelt | 1 |
| `tests/Integration/TestPortalSitzung.php` | neu | Test-Helfer „angemeldet (Altwert)“ | 2 |

---
### Task 1: Datenmodell, Einstellungen, Vermerk-Modell, Testwelt

**Files:**
- Create: `database/migrations/2026_10_12_000001_create_rec_hr_vermerke_table.php`
- Create: `database/migrations/2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php`
- Create: `src/Models/RecHrVermerk.php`
- Create: `src/Support/HrEinstellungen.php`
- Modify: `src/Models/RecApplicantSettings.php:155` (nach `'tax_class_per_company' => false,`)
- Modify: `tests/Integration/MassenzuweisungGeschlosseneWeltTest.php` (Konstante `PERSONS_GESPERRT`, nach dem Eintrag `'notfall_gesperrt_bis' => …`)
- Create: `tests/Integration/HrBereichHarness.php`
- Test: `tests/Integration/HrVermerkTest.php`, `tests/Unit/HrEinstellungenTest.php`

**Interfaces:**
- Consumes: `VertragAusAkteHarness` (Testwelt mit allen Migrationen, Core-Extrafeldern, `url`-Stub), `RecApplicantSettings::DEFAULT_SETTINGS`/`getSetting()`.
- Produces:
  - Tabelle `rec_hr_vermerke` (Spalten wie Spec §7.1), Spalte `rec_persons.sitzungen_ungueltig_ab`.
  - `RecHrVermerk::ARTEN` (list<string>), `RecHrVermerk::ART_LABELS` (array<string,string>), `RecHrVermerk::anfuegen(int $teamId, ?int $personId, ?int $employeeId, string $art, ?string $notiz = null, ?string $bezug = null, ?string $ergebnis = null, ?\DateTimeInterface $ruheBis = null, ?int $userId = null): int`, `RecHrVermerk::letzte(int $teamId, ?int $personId, array $employeeIds, int $anzahl = 10): \Illuminate\Support\Collection` (Zeilen mit `art, bezug, notiz, ergebnis, ruhe_bis, created_at, benutzer`).
  - `HrEinstellungen::NOTFALL_WEG|ERINNERT_TAGE|RUHE_TAGE` (Schlüssel), `notfallWegAktiv(?int $teamId): bool`, `erinnertTage(int $teamId): int`, `kontaktiertRuheTage(int $teamId): int`, `begrenze(mixed $wert, int $min, int $max, int $vorgabe): int`.
  - Trait `HrBereichHarness` mit `hrWeltAufbauen()`, `hrWeltAbbauen()`, `authAls(int $teamId)`, `akte(array $set = [])`, `person(array $set, RecEmployee ...$akten): int`, `personZeile(int $id): object`, `portalAuth(): PortalAuth`, `logZeilen(string $nachricht): array`, `vermerke(): Collection`, `einstellung(string $key, mixed $wert, ?int $teamId = null): void`, Properties `$sitzung` (Store), `$cache` (Repository mit zählendem ArrayStore), `$log`, `$crmSync`, `$hinweis`, `$benutzerId = 42`, `$team = 7`.

- [ ] **Step 1: Testwelt anlegen** (`tests/Integration/HrBereichHarness.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;

/**
 * Welt fuer den HR-Bereich "Mitarbeiter" (Spec 2026-10-12): alles aus
 * VertragAusAkteHarness (ALLE Modul-Migrationen inkl. der beiden neuen,
 * Core-Extrafelder, url-Stub) plus Sitzung, zaehlender Cache, Log-Rekorder,
 * Auth mit Benutzer-ID, Pfeffer, schneller Hasher, CRM- und Hinweis-Attrappe.
 *
 * Log-Attrappe nach weltAufbauen() neu binden UND Facade-Instanzen leeren
 * (reference_log_facade_test_stub: Container::instance allein reicht nicht).
 */
trait HrBereichHarness
{
    use VertragAusAkteHarness;

    protected Store $sitzung;
    protected CacheRepository $cache;
    protected object $log;
    protected object $crmSync;
    protected object $hinweis;
    protected int $benutzerId = 42;
    private int $laufendeNummer = 1000;

    protected function hrWeltAufbauen(): void
    {
        $this->weltAufbauen();
        $c = Container::getInstance();

        $c->make('config')->set('recruiting.konto.pepper', 'pfeffer-fuer-den-test');
        $c->make('config')->set('recruiting.konto.weg4_aktiv', true);

        $this->log = new class {
            /** @var list<array{stufe:string, nachricht:string, daten:array}> */
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = ['stufe' => $m, 'nachricht' => (string) ($a[0] ?? ''), 'daten' => (array) ($a[1] ?? [])];
            }
        };
        $c->instance('log', $this->log);

        $this->sitzung = new Store('test', new ArraySessionHandler(60));
        $c->instance('session', $this->sitzung);

        // Zaehlt many()/get(): Spec §3.2 verlangt EINE Cache-Abfrage fuer die Drossel.
        $this->cache = new CacheRepository(new class extends ArrayStore {
            public int $manyAufrufe = 0;
            public int $getAufrufe = 0;

            public function many(array $keys)
            {
                $this->manyAufrufe++;

                return parent::many($keys);
            }

            public function get($key)
            {
                $this->getAufrufe++;

                return parent::get($key);
            }
        });
        $c->instance('cache', $this->cache);
        $c->instance('hash', new BcryptHasher(['rounds' => 4]));

        $this->crmSync = new class extends ContactPhoneSync {
            /** @var list<int> */
            public array $nachgezogen = [];

            public function syncEmployee(RecEmployee $employee, bool $dryRun = false): array
            {
                $this->nachgezogen[] = (int) $employee->id;

                return ['status' => 'synced', 'contacts' => 1];
            }
        };
        $c->instance(ContactPhoneSync::class, $this->crmSync);

        // NummernwechselHinweisSender ist final — Attrappe ohne Vererbung,
        // aufgerufen wird er untypisiert ueber app().
        $this->hinweis = new class {
            /** @var list<array{person:int, alt:string}> */
            public array $gesendet = [];
            public string $status = NummernwechselHinweisSender::STATUS_SENT;

            public function sende(int $personId, string $alteNummer): string
            {
                $this->gesendet[] = ['person' => $personId, 'alt' => $alteNummer];

                return $this->status;
            }
        };
        $c->instance(NummernwechselHinweisSender::class, $this->hinweis);

        $this->authAls($this->team);
        Facade::clearResolvedInstances();
        Carbon::setTestNow('2026-10-12 10:00:00');
    }

    protected function hrWeltAbbauen(): void
    {
        $c = Container::getInstance();
        foreach (['session', 'cache', 'hash', ContactPhoneSync::class, NummernwechselHinweisSender::class, AuthFactory::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->weltAbbauen();
    }

    /** Handelt ab jetzt als HR-Benutzer $benutzerId im Team $teamId. */
    protected function authAls(int $teamId): void
    {
        $stub = new class($teamId, $this->benutzerId) implements AuthFactory {
            public function __construct(private int $teamId, private int $benutzerId) {}
            public function guard($name = null) { return $this; }
            public function user(): object { return (object) ['id' => $this->benutzerId, 'currentTeam' => (object) ['id' => $this->teamId]]; }
            public function check() { return true; }
            public function id() { return $this->benutzerId; }
            public function shouldUse($name) {}
        };
        Container::getInstance()->instance(AuthFactory::class, $stub);
        Facade::clearResolvedInstance('auth');
    }

    /** Eine Anstellung im Team (Vorgabe: MA, aktiv, im neuen Portal). */
    protected function akte(array $set = []): RecEmployee
    {
        $this->laufendeNummer++;

        return $this->anstellung(array_merge([
            'personnel_number' => 'MA' . $this->laufendeNummer,
            'portal_token'     => 'tok-' . $this->laufendeNummer,
            'phone'            => '+4915100' . $this->laufendeNummer,
            'portal_v2_since'  => '2026-10-01 08:00:00',
        ], $set));
    }

    /** Personen-Zeile + Verknuepfung, Query Builder wie PersonLinker. */
    protected function person(array $set, RecEmployee ...$akten): int
    {
        $id = (int) DB::table('rec_persons')->insertGetId(array_merge([
            'uuid'       => 'p-' . bin2hex(random_bytes(6)),
            'team_id'    => $this->team,
            'phone'      => $akten[0]->phone ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $set));
        if ($akten !== []) {
            DB::table('rec_employees')
                ->whereIn('id', array_map(fn (RecEmployee $a) => (int) $a->id, $akten))
                ->update(['rec_person_id' => $id]);
        }

        return $id;
    }

    protected function personZeile(int $id): object
    {
        return DB::table('rec_persons')->where('id', $id)->first();
    }

    protected function portalAuth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    /** @return list<array{stufe:string, nachricht:string, daten:array}> */
    protected function logZeilen(string $nachricht): array
    {
        return array_values(array_filter($this->log->zeilen, fn (array $z) => $z['nachricht'] === $nachricht));
    }

    protected function vermerke(): Collection
    {
        return DB::table('rec_hr_vermerke')->orderBy('id')->get();
    }

    protected function einstellung(string $key, mixed $wert, ?int $teamId = null): void
    {
        $teamId ??= $this->team;
        $zeile = DB::table('rec_applicant_settings')->where('team_id', $teamId)->first();
        $settings = $zeile ? (array) json_decode((string) $zeile->settings, true) : [];
        $settings[$key] = $wert;
        if ($zeile) {
            DB::table('rec_applicant_settings')->where('team_id', $teamId)->update(['settings' => json_encode($settings)]);

            return;
        }
        DB::table('rec_applicant_settings')->insert([
            'team_id' => $teamId, 'settings' => json_encode($settings), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Failing tests schreiben** (`tests/Integration/HrVermerkTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Support\HrEinstellungen;

final class HrVermerkTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_tabelle_und_spalte_kommen_aus_den_migrationen(): void
    {
        $spalten = Capsule::schema()->getColumnListing('rec_hr_vermerke');
        sort($spalten);
        $this->assertSame(
            ['art', 'bezug', 'created_at', 'ergebnis', 'id', 'notiz', 'rec_employee_id', 'rec_person_id', 'ruhe_bis', 'team_id', 'user_id'],
            $spalten,
            'kein updated_at: nur anfuegen (Spec §7.1)'
        );
        $this->assertTrue(Capsule::schema()->hasColumn('rec_persons', 'sitzungen_ungueltig_ab'));
    }

    public function test_down_raeumt_beides_wieder_ab(): void
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations/';
        (require $dir . '2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php')->down();
        (require $dir . '2026_10_12_000001_create_rec_hr_vermerke_table.php')->down();

        $this->assertFalse(Capsule::schema()->hasTable('rec_hr_vermerke'));
        $this->assertFalse(Capsule::schema()->hasColumn('rec_persons', 'sitzungen_ungueltig_ab'));

        // hasTable-/hasColumn-Wache: ein zweites up() nach up() wirft nicht.
        (require $dir . '2026_10_12_000001_create_rec_hr_vermerke_table.php')->up();
        (require $dir . '2026_10_12_000001_create_rec_hr_vermerke_table.php')->up();
        (require $dir . '2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php')->up();
        (require $dir . '2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php')->up();
        $this->assertTrue(Capsule::schema()->hasTable('rec_hr_vermerke'));
    }

    public function test_anfuegen_schreibt_eine_zeile_mit_allen_feldern(): void
    {
        $id = RecHrVermerk::anfuegen($this->team, 5, 9, 'kontaktiert', '  angerufen, kommt morgen  ', null, 'ok', now()->addDays(3), 42);

        $zeile = DB::table('rec_hr_vermerke')->where('id', $id)->first();
        $this->assertSame('kontaktiert', $zeile->art);
        $this->assertSame('angerufen, kommt morgen', $zeile->notiz, 'getrimmt');
        $this->assertSame(5, (int) $zeile->rec_person_id);
        $this->assertSame(9, (int) $zeile->rec_employee_id);
        $this->assertSame(42, (int) $zeile->user_id);
        $this->assertSame('2026-10-15 10:00:00', (string) $zeile->ruhe_bis);
        $this->assertSame('2026-10-12 10:00:00', (string) $zeile->created_at);
    }

    public function test_unbekannte_art_fehlende_notiz_und_fehlender_bezugspunkt_werfen(): void
    {
        foreach ([
            fn () => RecHrVermerk::anfuegen($this->team, 5, null, 'geloescht', 'eine Notiz'),
            fn () => RecHrVermerk::anfuegen($this->team, 5, null, 'antrag_gestoppt', '   '),
            fn () => RecHrVermerk::anfuegen($this->team, null, null, 'kontaktiert', 'eine Notiz'),
        ] as $fall) {
            try {
                $fall();
                $this->fail('erwartet InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('rec_hr_vermerke')->count());

        // erneut_erinnert ohne Notiz ist erlaubt (Spec §7.1).
        RecHrVermerk::anfuegen($this->team, 5, null, 'erneut_erinnert', null, 'aufgaben', 'sent');
        $this->assertSame(1, DB::table('rec_hr_vermerke')->count());
    }

    public function test_letzte_liefert_person_und_akten_neueste_zuerst_nur_im_team(): void
    {
        RecHrVermerk::anfuegen($this->team, 5, null, 'kontaktiert', 'erste Notiz', null, 'ok', null, 42);
        \Carbon\Carbon::setTestNow('2026-10-12 11:00:00');
        RecHrVermerk::anfuegen($this->team, null, 9, 'portal_entsperrt', 'zweite Notiz', null, 'ok', null, 42);
        RecHrVermerk::anfuegen(99, 5, null, 'kontaktiert', 'fremdes Team', null, 'ok', null, 42);

        $zeilen = RecHrVermerk::letzte($this->team, 5, [9], 10);

        $this->assertSame(['portal_entsperrt', 'kontaktiert'], $zeilen->pluck('art')->all());
        $this->assertNotContains('fremdes Team', $zeilen->pluck('notiz')->all());
    }

    public function test_einstellungen_haben_vorgaben_und_werden_begrenzt(): void
    {
        $this->assertFalse(HrEinstellungen::notfallWegAktiv($this->team));
        $this->assertSame(5, HrEinstellungen::erinnertTage($this->team));
        $this->assertSame(3, HrEinstellungen::kontaktiertRuheTage($this->team));

        $this->einstellung('konto_notfall_weg_aktiv', true);
        $this->einstellung('hr_erinnert_tage', '99');
        $this->einstellung('hr_kontaktiert_ruhe_tage', '0');

        $this->assertTrue(HrEinstellungen::notfallWegAktiv($this->team));
        $this->assertSame(60, HrEinstellungen::erinnertTage($this->team));
        $this->assertSame(1, HrEinstellungen::kontaktiertRuheTage($this->team));
        $this->assertFalse(HrEinstellungen::notfallWegAktiv(null), 'Person ohne Team: fail closed');
        $this->assertFalse(HrEinstellungen::notfallWegAktiv(123), 'Team ohne Einstellungszeile: Vorgabe aus');
    }
}
```

`tests/Unit/HrEinstellungenTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\HrEinstellungen;

final class HrEinstellungenTest extends TestCase
{
    public function test_begrenze_jede_verzweigung(): void
    {
        $this->assertSame(5, HrEinstellungen::begrenze(null, 1, 60, 5));
        $this->assertSame(5, HrEinstellungen::begrenze('', 1, 60, 5), 'leeres Feld: Vorgabe, nicht 1');
        $this->assertSame(5, HrEinstellungen::begrenze('abc', 1, 60, 5));
        $this->assertSame(1, HrEinstellungen::begrenze('0', 1, 60, 5));
        $this->assertSame(60, HrEinstellungen::begrenze(61, 1, 60, 5));
        $this->assertSame(7, HrEinstellungen::begrenze('7', 1, 60, 5));
    }

    public function test_vorgaben_stehen_in_default_settings(): void
    {
        $this->assertFalse(RecApplicantSettings::DEFAULT_SETTINGS[HrEinstellungen::NOTFALL_WEG]);
        $this->assertSame(5, RecApplicantSettings::DEFAULT_SETTINGS[HrEinstellungen::ERINNERT_TAGE]);
        $this->assertSame(3, RecApplicantSettings::DEFAULT_SETTINGS[HrEinstellungen::RUHE_TAGE]);
    }
}
```

- [ ] **Step 3: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'HrVermerkTest|HrEinstellungenTest'`
Expected: FAIL — `Class "Platform\Recruiting\Models\RecHrVermerk" not found` / `Class "Platform\Recruiting\Support\HrEinstellungen" not found`.

- [ ] **Step 4: Migrationen schreiben**

`database/migrations/2026_10_12_000001_create_rec_hr_vermerke_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlauf jeder HR-Handlung an Konto, Sperre oder Erinnerung (Spec HR-Bereich
 * 2026-10-12 §7.1). Haengt an Person ODER Anstellung, auch ohne Bewerbung —
 * rec_auto_pilot_logs kann das nicht (FK auf rec_applicants).
 *
 * NUR ANFUEGEN: kein updated_at, kein Bearbeiten, kein Loeschen. Kein FK
 * (Muster rec_conversation_handled) — verwaiste Zeilen sind harmlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_hr_vermerke')) {
            return;
        }

        Schema::create('rec_hr_vermerke', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('rec_person_id')->nullable()->index();
            $table->unsignedBigInteger('rec_employee_id')->nullable()->index();
            $table->string('art', 40);
            $table->string('bezug', 60)->nullable();
            $table->text('notiz')->nullable();
            $table->string('ergebnis', 40)->nullable();
            $table->timestamp('ruhe_bis')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'rec_person_id', 'created_at'], 'rec_hr_vermerke_team_person_idx');
            $table->index(['team_id', 'rec_employee_id', 'created_at'], 'rec_hr_vermerke_team_akte_idx');
            $table->index(['rec_person_id', 'art', 'ruhe_bis'], 'rec_hr_vermerke_ruhe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_hr_vermerke');
    }
};
```

`database/migrations/2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ab wann laufende Portal-Sitzungen dieser Person nicht mehr gelten (Spec
 * HR-Bereich §9.6, Entscheidung 12.10.: ein zurueckgesetztes Konto darf
 * keinen Fremden eingeloggt lassen). Gesetzt nur von HrKontoAktionen,
 * gelesen nur von PortalAuth::sitzungGueltig(). Eigene Migration, weil die
 * Konto-Migrationen auf der Demo schon gelaufen sein koennen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'sitzungen_ungueltig_ab')) {
                $table->timestamp('sitzungen_ungueltig_ab')->nullable()->after('notfall_gesperrt_bis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (Schema::hasColumn('rec_persons', 'sitzungen_ungueltig_ab')) {
                $table->dropColumn('sitzungen_ungueltig_ab');
            }
        });
    }
};
```

- [ ] **Step 5: Modell `RecHrVermerk` schreiben** (`src/Models/RecHrVermerk.php`)

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Verlauf der HR-Handlungen an Konto, Sperre und Erinnerung (Spec HR-Bereich
 * §7.1). NUR ANFUEGEN: geschrieben wird ausschliesslich ueber anfuegen()
 * (Query Builder), es gibt kein Bearbeiten und kein Loeschen. Das Modell
 * existiert zum Lesen; $guarded = ['*'] schliesst Massenzuweisung aus.
 */
class RecHrVermerk extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rec_hr_vermerke';

    protected $guarded = ['*'];

    protected $casts = [
        'ruhe_bis'   => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @var list<string> */
    public const ARTEN = [
        'antrag_gestoppt', 'antrag_freigegeben', 'konto_entsperrt', 'portal_entsperrt', 'bremse_geloest',
        'notfallsperre_aufgehoben', 'einladung_neu', 'konto_zurueckgesetzt', 'kontaktiert', 'erneut_erinnert',
    ];

    /** @var array<string,string> */
    public const ART_LABELS = [
        'antrag_gestoppt'          => 'Antrag gestoppt',
        'antrag_freigegeben'       => 'Antrag freigegeben',
        'konto_entsperrt'          => 'Konto entsperrt',
        'portal_entsperrt'         => 'Portal entsperrt',
        'bremse_geloest'           => 'Anmelde-Bremse gelöst',
        'notfallsperre_aufgehoben' => 'Notfallsperre aufgehoben',
        'einladung_neu'            => 'Einladung neu',
        'konto_zurueckgesetzt'     => 'Konto zurückgesetzt',
        'kontaktiert'              => 'Kontaktiert',
        'erneut_erinnert'          => 'Erneut erinnert',
    ];

    public static function anfuegen(
        int $teamId,
        ?int $personId,
        ?int $employeeId,
        string $art,
        ?string $notiz = null,
        ?string $bezug = null,
        ?string $ergebnis = null,
        ?\DateTimeInterface $ruheBis = null,
        ?int $userId = null,
    ): int {
        if (!in_array($art, self::ARTEN, true)) {
            throw new InvalidArgumentException("Unbekannte Vermerk-Art \"{$art}\".");
        }
        if ($personId === null && $employeeId === null) {
            throw new InvalidArgumentException('Ein Vermerk braucht eine Person oder eine Akte.');
        }
        $notiz = $notiz === null ? null : trim($notiz);
        if (($notiz === null || $notiz === '') && $art !== 'erneut_erinnert') {
            throw new InvalidArgumentException("Vermerk \"{$art}\" ohne Notiz — die Notiz ist Pflicht (Spec §7.1).");
        }

        return (int) DB::table('rec_hr_vermerke')->insertGetId([
            'team_id'         => $teamId,
            'rec_person_id'   => $personId,
            'rec_employee_id' => $employeeId,
            'art'             => $art,
            'bezug'           => $bezug,
            'notiz'           => $notiz === '' ? null : $notiz,
            'ergebnis'        => $ergebnis,
            'ruhe_bis'        => $ruheBis?->format('Y-m-d H:i:s'),
            'user_id'         => $userId,
            'created_at'      => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Die letzten Vermerke eines Menschen — an der Person ODER an einer seiner Akten.
     *
     * @param  list<int>  $employeeIds
     */
    public static function letzte(int $teamId, ?int $personId, array $employeeIds, int $anzahl = 10): Collection
    {
        if ($personId === null && $employeeIds === []) {
            return collect();
        }

        return DB::table('rec_hr_vermerke as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.team_id', $teamId)
            ->where(function ($q) use ($personId, $employeeIds) {
                if ($personId !== null) {
                    $q->orWhere('v.rec_person_id', $personId);
                }
                if ($employeeIds !== []) {
                    $q->orWhereIn('v.rec_employee_id', $employeeIds);
                }
            })
            ->orderByDesc('v.created_at')
            ->orderByDesc('v.id')
            ->limit($anzahl)
            ->get(['v.art', 'v.bezug', 'v.notiz', 'v.ergebnis', 'v.ruhe_bis', 'v.created_at', 'u.name as benutzer']);
    }
}
```

- [ ] **Step 6: `HrEinstellungen` + Vorgaben schreiben**

`src/Support/HrEinstellungen.php`:

```php
<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die drei Team-Einstellungen des HR-Bereichs (Spec §6, §7.2) — gelesen NUR
 * hier, mit Begrenzung. Liest ohne Zeile anzulegen (kein getOrCreateForTeam):
 * eine Leseabfrage darf keine Einstellungszeile erzeugen.
 */
final class HrEinstellungen
{
    public const NOTFALL_WEG   = 'konto_notfall_weg_aktiv';
    public const ERINNERT_TAGE = 'hr_erinnert_tage';
    public const RUHE_TAGE     = 'hr_kontaktiert_ruhe_tage';

    public static function notfallWegAktiv(?int $teamId): bool
    {
        return $teamId !== null && (bool) self::wert($teamId, self::NOTFALL_WEG);
    }

    public static function erinnertTage(int $teamId): int
    {
        return self::begrenze(self::wert($teamId, self::ERINNERT_TAGE), 1, 60, 5);
    }

    public static function kontaktiertRuheTage(int $teamId): int
    {
        return self::begrenze(self::wert($teamId, self::RUHE_TAGE), 1, 30, 3);
    }

    /** Leer oder unlesbar = Vorgabe (ein geleertes Feld soll nicht "1 Tag" heissen). */
    public static function begrenze(mixed $wert, int $min, int $max, int $vorgabe): int
    {
        if ($wert === null || $wert === '' || !is_numeric($wert)) {
            return $vorgabe;
        }

        return max($min, min($max, (int) $wert));
    }

    private static function wert(int $teamId, string $schluessel): mixed
    {
        $zeile = RecApplicantSettings::query()->where('team_id', $teamId)->first();

        return $zeile !== null
            ? $zeile->getSetting($schluessel)
            : (RecApplicantSettings::DEFAULT_SETTINGS[$schluessel] ?? null);
    }
}
```

In `src/Models/RecApplicantSettings.php` direkt nach Zeile 155 (`'tax_class_per_company' => false,`) einfügen:

```php
        // HR-Bereich "Mitarbeiter" (Spec 2026-10-12 §6/§7.2): Notfallweg je
        // Team (zweiter Riegel hinter recruiting.konto.weg4_aktiv), Schwelle
        // der Liste "Erinnert, aber keine Reaktion" und Ruhezeit nach
        // "Kontaktiert". Gelesen NUR ueber Support\HrEinstellungen.
        'konto_notfall_weg_aktiv' => false,
        'hr_erinnert_tage' => 5,
        'hr_kontaktiert_ruhe_tage' => 3,
```

In `tests/Integration/MassenzuweisungGeschlosseneWeltTest.php`, Konstante `PERSONS_GESPERRT`, direkt nach dem Eintrag `'notfall_gesperrt_bis' => …,` einfügen:

```php
        'sitzungen_ungueltig_ab' => 'Beendet laufende Portal-Sitzungen (Spec HR-Bereich §9.6); setzt nur '
            .'HrKontoAktionen per Query Builder. Massenzuweisbar liesse sich ein Rauswurf still zuruecknehmen.',
```

- [ ] **Step 7: Tests laufen lassen — grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'HrVermerkTest|HrEinstellungenTest|MassenzuweisungGeschlosseneWeltTest|SettingsModalEntangleKeysTest'`
Expected: PASS (alle).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_12_000001_create_rec_hr_vermerke_table.php database/migrations/2026_10_12_000002_add_sitzungen_ungueltig_ab_to_rec_persons.php src/Models/RecHrVermerk.php src/Support/HrEinstellungen.php src/Models/RecApplicantSettings.php tests/Integration/HrBereichHarness.php tests/Integration/HrVermerkTest.php tests/Unit/HrEinstellungenTest.php tests/Integration/MassenzuweisungGeschlosseneWeltTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): HR-Vermerke, Sitzungsgrenze und HR-Einstellungen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---

### Task 2: Eine Sitzungs-Prüfstelle `PortalAuth::sitzungGueltig()`

**Files:**
- Modify: `src/Services/PortalAuth.php` (neue Methoden nach `sessionKey()` :427-430; `use Illuminate\Support\Facades\DB;` ergänzen)
- Modify: `src/Livewire/Public/KontoAnmelden.php:455`
- Modify: `src/Livewire/Public/PortalShell.php:160`, `:207`, `berechtigterMitarbeiter()` (:1208-1229)
- Modify: `src/Livewire/Public/EmployeePortal.php:157`, `:210` (+ `use Platform\Recruiting\Services\PortalAuth;`)
- Modify: `src/Http/Controllers/DokumentDownloadController.php:33`, `sitzungDeckt()` :64-77
- Modify: `src/Http/Controllers/VertragPdfController.php:27`, `zugriff()` Docblock + Aufruf :90
- Create: `tests/Integration/TestPortalSitzung.php`
- Modify (Tests, nur Helfer): `tests/Integration/DokumentDownloadControllerTest.php:20-21`, `tests/Integration/VertragImPortalTest.php:114`, `:225`, sowie die PortalShell-Helfer in `PortalPflichtangabenTest.php:185`, `PortalShellUploadTest.php:194`, `PortalShellEmployerTest.php:140`, `PortalShellMitarbeiterDokumenteTest.php:62`, `PortalAufgabenBladeTest.php:313`, `PortalShellProfilDatenTest.php:119`, `VertragImPortalTest.php:256`, `PortalShellProfilTest.php:149-155`
- Test: `tests/Integration/SitzungGueltigTest.php`

**Vollständige Liste der heutigen Sitzungs-Lese-/Schreibstellen** (aus `grep -rn "sessionKey\|employee_portal_verified" src`):

| Stelle | heute | neu |
|---|---|---|
| `KontoAnmelden.php:455` | `session()->put(PortalAuth::sessionKey(…), true)` | `…, PortalAuth::anmeldeWert())` |
| `KontoAnmelden.php:522` | `session()->forget(…)` | bleibt |
| `PortalShell.php:160` | `session()->has(PortalAuth::sessionKey($employee->id))` | `PortalAuth::sitzungGueltig((int) $employee->id)` |
| `PortalShell.php:207` | `session()->put(…, true)` | `…, PortalAuth::anmeldeWert())` |
| `PortalShell.php:246` | `forget` | bleibt |
| `PortalShell::berechtigterMitarbeiter()` | prüft keine Sitzung | prüft `sitzungGueltig()` |
| `EmployeePortal.php:157` | `session()->has($this->sessionKey())` | `PortalAuth::sitzungGueltig((int) $this->employeeId)` |
| `EmployeePortal.php:210` | `session()->put($this->sessionKey(), true)` | `…, PortalAuth::anmeldeWert())` |
| `EmployeePortal.php:220` | `forget` | bleibt |
| `DokumentDownloadController.php:33/71` | `$request->session()->has($key)` über `sitzungDeckt` | `sitzungDeckt($ids, fn (int $id) => PortalAuth::sitzungGueltig($id, $request->session()))` |
| `VertragPdfController.php:27/90` | dito | dito |

Eine „KontoAnmelden-Weiche“ mit `session()->has()` gibt es im Code nicht (nur put/forget) — dort ist nichts zu ersetzen. Einen Portal-Download-Controller für Nachweise gibt es nicht (`EmployeeFileController` ist die HR-Seite hinter dem Modul-Guard).

**Interfaces:**
- Consumes: Spalte `rec_persons.sitzungen_ungueltig_ab` (Task 1), `HrBereichHarness`.
- Produces: `PortalAuth::anmeldeWert(): string` (ISO-8601), `PortalAuth::sitzungGueltig(int $employeeId, ?object $sitzung = null): bool` (verwirft ungültige Schlüssel), `PortalAuth::anmeldeZeitpunkt(mixed $wert): int`; `DokumentDownloadController::sitzungDeckt(array $scopeIds, callable $gilt)` mit `$gilt: callable(int $employeeId): bool`; Test-Helfer `TestPortalSitzung::oeffne(int $employeeId): void`.

- [ ] **Step 1: Failing tests schreiben** (`tests/Integration/SitzungGueltigTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Services\PortalAuth;

final class SitzungGueltigTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_ohne_sitzungswert_ist_nichts_gueltig(): void
    {
        $ma = $this->akte();
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
    }

    /** Review Focus 3, Hälfte 1: Altsitzungen von vor dem Deploy leben weiter. */
    public function test_altwert_true_lebt_ohne_ungueltigkeit_weiter(): void
    {
        $ma = $this->akte();
        $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id));
    }

    /** Review Focus 3, Hälfte 2. */
    public function test_altwert_true_stirbt_mit_dem_zuruecksetzen(): void
    {
        $ma = $this->akte();
        $personId = $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);

        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
        $this->assertFalse($this->sitzung->has(PortalAuth::sessionKey($ma->id)), 'der Schluessel wird verworfen');
    }

    public function test_anmeldung_vor_der_grenze_stirbt_danach_lebt(): void
    {
        $ma = $this->akte();
        $personId = $this->person([], $ma);

        $this->sitzung->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());
        Carbon::setTestNow('2026-10-12 10:05:00');
        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id), 'gleich alt oder aelter als die Grenze: weg');

        Carbon::setTestNow('2026-10-12 10:06:00');
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), PortalAuth::anmeldeWert());
        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id), 'neue Anmeldung nach der Grenze gilt');
    }

    public function test_grenze_gilt_fuer_alle_akten_der_person(): void
    {
        $ma = $this->akte();
        $rg = $this->akte(['company' => 'RG', 'personnel_number' => 'RG777']);
        $personId = $this->person([], $ma, $rg);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);
        $this->sitzung->put(PortalAuth::sessionKey($rg->id), true);

        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);

        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id));
        $this->assertFalse(PortalAuth::sitzungGueltig($rg->id));
    }

    public function test_akte_ohne_personen_zeile_gilt_wie_heute(): void
    {
        $ma = $this->akte();
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        $this->assertTrue(PortalAuth::sitzungGueltig($ma->id));
    }

    public function test_alle_schreibstellen_speichern_den_anmeldezeitpunkt(): void
    {
        $this->assertSame('2026-10-12T10:00:00+00:00', PortalAuth::anmeldeWert());
        foreach (['src/Livewire/Public/KontoAnmelden.php', 'src/Livewire/Public/PortalShell.php', 'src/Livewire/Public/EmployeePortal.php'] as $datei) {
            $code = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $datei);
            $this->assertDoesNotMatchRegularExpression('/session\(\)->put\([^;]*,\s*true\)/', $code, "{$datei} schreibt noch true");
            $this->assertDoesNotMatchRegularExpression('/session\(\)->has\(/', $code, "{$datei} prueft die Sitzung noch selbst");
        }
    }

    public function test_portal_shell_mount_und_jede_aktion_beenden_eine_ungueltige_sitzung(): void
    {
        $ma = $this->akte(['portal_token' => 'tok-shell']);
        $personId = $this->person([], $ma);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);

        $offen = new PortalShell();
        $offen->mount('tok-shell', $this->portalAuth());
        $this->assertSame('verified', $offen->state);

        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);

        // Die schon offene Seite: die naechste Aktion beendet sie (berechtigterMitarbeiter).
        $offen->oeffneDokument(999999);
        $this->assertSame('unverified', $offen->state);
        $this->assertSame('', $offen->displayName);

        // Neu laden: mount sieht keine gueltige Sitzung mehr.
        $neu = new PortalShell();
        $neu->mount('tok-shell', $this->portalAuth());
        $this->assertSame('unverified', $neu->state);
    }
}
```

Hinweis: Die Suite läuft mit der PHP-Vorgabe-Zeitzone `UTC` (geprüft: `php -r 'echo date_default_timezone_get();'` → `UTC`), daher `+00:00`.

- [ ] **Step 2: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter SitzungGueltigTest`
Expected: FAIL — `Call to undefined method Platform\Recruiting\Services\PortalAuth::sitzungGueltig()`.

- [ ] **Step 3: `PortalAuth` erweitern**

In `src/Services/PortalAuth.php` `use Illuminate\Support\Facades\DB;` ergänzen und direkt nach `sessionKey()` (Zeile 430) einfügen:

```php
    /**
     * Was eine frische Portal-Sitzung speichert: den ANMELDEZEITPUNKT statt
     * true (Spec HR-Bereich §9.6). Eine Stelle fuer alle drei Schreibstellen
     * (KontoAnmelden, PortalShell, EmployeePortal).
     */
    public static function anmeldeWert(): string
    {
        return now()->toIso8601String();
    }

    /**
     * DIE EINE Pruefstelle: gilt die Portal-Sitzung fuer diese Anstellung noch?
     *
     * Gueltig ist sie, wenn ein Sitzungswert existiert UND der
     * Anmeldezeitpunkt NACH rec_persons.sitzungen_ungueltig_ab der Person
     * liegt (gesetzt von HrKontoAktionen beim Zuruecksetzen). Ein Altwert
     * true (Sitzungen von vor §9.6) gilt als Zeitpunkt 0 — er lebt bis zum
     * ersten Zuruecksetzen und stirbt dann. Ohne Personen-Zeile: gueltig wie
     * bisher. Ungueltige Schluessel werden verworfen.
     *
     * Eine zweite Pruefung mit eigener Logik ist verboten — sonst gibt es
     * wieder eine Seite, die den Fremden weiter bedient. Die Spalte wird ueber
     * first() gelesen, damit Testschemata ohne sie wie "nie ungueltig" wirken.
     *
     * @param  object|null  $sitzung  Session-Store (Request-Session); ohne Angabe app('session').
     */
    public static function sitzungGueltig(int $employeeId, ?object $sitzung = null): bool
    {
        $sitzung ??= app('session');
        $schluessel = self::sessionKey($employeeId);
        $wert = $sitzung->get($schluessel);

        if ($wert === null || $wert === false) {
            return false;
        }

        $personId = DB::table('rec_employees')->where('id', $employeeId)->value('rec_person_id');
        if ($personId === null) {
            return true;
        }

        $person = DB::table('rec_persons')->where('id', (int) $personId)->first();
        $grenzeRoh = $person->sitzungen_ungueltig_ab ?? null;
        if ($grenzeRoh === null) {
            return true;
        }

        $grenze = strtotime((string) $grenzeRoh);
        if ($grenze !== false && self::anmeldeZeitpunkt($wert) <= $grenze) {
            $sitzung->forget($schluessel);

            return false;
        }

        return true;
    }

    /** Altwert true und Unlesbares = 0 (die strenge Richtung). */
    public static function anmeldeZeitpunkt(mixed $wert): int
    {
        if (!is_string($wert)) {
            return 0;
        }
        $zeit = strtotime($wert);

        return $zeit === false ? 0 : $zeit;
    }
```

- [ ] **Step 4: Schreibstellen und Prüfstellen umstellen**

`src/Livewire/Public/KontoAnmelden.php:455`:

```php
            session()->put(PortalAuth::sessionKey((int) $anstellung->id), PortalAuth::anmeldeWert());
```

`src/Livewire/Public/PortalShell.php:160`:

```php
        if (PortalAuth::sitzungGueltig((int) $employee->id)) {
```

`src/Livewire/Public/PortalShell.php:207`:

```php
            session()->put(PortalAuth::sessionKey($employee->id), PortalAuth::anmeldeWert());
```

`PortalShell::berechtigterMitarbeiter()` — vollständiger neuer Körper:

```php
    private function berechtigterMitarbeiter(): ?RecEmployee
    {
        if ($this->state !== 'verified' || $this->employeeId === null) {
            return null;
        }

        $employee = RecEmployee::find($this->employeeId);

        if (!$employee || !$employee->is_active || $employee->portal_v2_since === null) {
            $this->state = 'weg';

            return null;
        }

        if ($employee->portal_locked_at !== null) {
            $this->state = 'gesperrt';

            return null;
        }

        // Spec HR-Bereich §9.6: ein zurueckgesetztes Konto beendet auch die
        // schon offene Seite — nicht erst beim Neuladen.
        if (!PortalAuth::sitzungGueltig((int) $employee->id)) {
            $this->state = 'unverified';
            $this->displayName = '';
            $this->initialen = '';

            return null;
        }

        return $employee;
    }
```

`src/Livewire/Public/EmployeePortal.php` — `use Platform\Recruiting\Services\PortalAuth;` ergänzen; Zeile 157:

```php
        if (PortalAuth::sitzungGueltig((int) $this->employeeId)) {
```

Zeile 210:

```php
        session()->put($this->sessionKey(), PortalAuth::anmeldeWert());
```

`src/Http/Controllers/DokumentDownloadController.php:33`:

```php
        $sitzungGueltig = self::sitzungDeckt($scopeIds, fn (int $id) => PortalAuth::sitzungGueltig($id, $request->session()));
```

`DokumentDownloadController::sitzungDeckt()` (:64-77) ersetzen:

```php
    /**
     * Deckt eine gueltige Portal-Sitzung eine der Anstellungen? Die Antwort
     * je Anstellung kommt aus PortalAuth::sitzungGueltig() (Spec HR-Bereich
     * §9.6) — hier wird nur ueber den Umfang iteriert.
     *
     * @param  list<int> $scopeIds
     * @param  callable(int):bool $gilt
     */
    public static function sitzungDeckt(array $scopeIds, callable $gilt): bool
    {
        foreach ($scopeIds as $id) {
            if ($gilt((int) $id)) {
                return true;
            }
        }

        return false;
    }
```

`src/Http/Controllers/VertragPdfController.php` — `use Platform\Recruiting\Services\PortalAuth;` ergänzen; Zeile 27:

```php
        $code = self::zugriff($vertrag, fn (int $id) => PortalAuth::sitzungGueltig($id, $request->session()));
```

und im Docblock von `zugriff()` den Parameter dokumentieren: `@param callable(int):bool $gilt  gilt die Portal-Sitzung dieser Anstellung (PortalAuth::sitzungGueltig)`; Signatur `public static function zugriff(?RecContract $vertrag, callable $gilt): int` und Zeile 90 `DokumentDownloadController::sitzungDeckt($aktiveIds, $gilt),`.

- [ ] **Step 5: Test-Helfer und betroffene Bestandstests anpassen**

`tests/Integration/TestPortalSitzung.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Platform\Recruiting\Services\PortalAuth;

/**
 * "Angemeldet" fuer Tests, die eine PortalShell direkt auf 'verified' setzen:
 * seit Spec HR-Bereich §9.6 prueft berechtigterMitarbeiter() die Sitzung.
 * Legt den ALTWERT true ab (wie eine Sitzung von vor dem Deploy) und bindet
 * eine Array-Sitzung, falls die Testklasse keine hat.
 */
final class TestPortalSitzung
{
    public static function oeffne(int $employeeId): void
    {
        $c = Container::getInstance();
        if (!$c->bound('session')) {
            $c->instance('session', new Store('test', new ArraySessionHandler(60)));
        }
        $c->make('session')->put(PortalAuth::sessionKey($employeeId), true);
    }
}
```

In jedem dieser Helfer direkt nach der Zeile `$shell->state = 'verified';` die Zeile `TestPortalSitzung::oeffne((int) $ma->id);` einfügen (Variable je Datei: `$ma`): `PortalPflichtangabenTest.php:185`, `PortalShellUploadTest.php:194`, `PortalShellEmployerTest.php:140` (und im zweiten Helfer `shellMitOffenerArbeitgeberGruppe` ab :146, falls er `state = 'verified'` selbst setzt), `PortalShellMitarbeiterDokumenteTest.php:62`, `PortalAufgabenBladeTest.php:313`, `PortalShellProfilDatenTest.php:119`, `VertragImPortalTest.php:256`. In `PortalShellProfilTest.php` den Helfer `shell()` (:149-155) ersetzen durch:

```php
    private function shell(RecEmployee $ma, bool $angemeldet = true): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = $angemeldet ? 'verified' : 'unverified';
        if ($angemeldet) {
            TestPortalSitzung::oeffne((int) $ma->id);
        }

        return $shell;
    }
```

`tests/Integration/DokumentDownloadControllerTest.php:20-21` ersetzen:

```php
        $hat = fn (int $id) => $id === 160;
```

`tests/Integration/VertragImPortalTest.php:114` und `:225` jeweils ersetzen:

```php
        $sitzung = fn (int $id) => fn (int $gefragt) => $gefragt === $id;
```

(Zeile 290/295 bleiben: dort läuft der echte Controller mit echter Request-Session und Altwert `true` — genau der Altwert-Pfad.)

EmployeePortal-Tests (`PortalMainEmployerRequiredTest`, `PortalNationalityRequiredTest`, `PortalFirstAiderFieldsTest`, `VertragAnDerAnstellungTest`) brauchen nichts: deren Wächter in `saveAll()`/`handleFileUpload()` prüfen weiterhin nur `$this->state` (bewusst unverändert, Spec nennt nur :157).

- [ ] **Step 6: Tests laufen lassen — grün, dann ganze Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'SitzungGueltigTest|DokumentDownloadControllerTest|VertragImPortalTest|PortalShell|PortalPflichtangabenTest|PortalAufgabenBladeTest|KontoAnmeldenTest'`
Expected: PASS.
Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS. Bleibt ein Test rot, der eine `PortalShell` auf `'verified'` setzt, ohne den Helfer zu rufen: denselben Einzeiler `TestPortalSitzung::oeffne(<id>);` nach der Zuweisung einfügen (Fundstellen: `grep -rn "state = 'verified'" tests`). Keine anderen Testanpassungen.

- [ ] **Step 7: Commit**

```bash
git add src/Services/PortalAuth.php src/Livewire/Public/KontoAnmelden.php src/Livewire/Public/PortalShell.php src/Livewire/Public/EmployeePortal.php src/Http/Controllers/DokumentDownloadController.php src/Http/Controllers/VertragPdfController.php tests/Integration/TestPortalSitzung.php tests/Integration/SitzungGueltigTest.php tests/Integration
git commit -m "$(cat <<'EOF'
feat(recruiting): Portal-Sitzung speichert den Anmeldezeitpunkt, eine Pruefstelle sitzungGueltig

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 3: `KontoWriter` — gesperrtes Lesen, Freigeben, HR-Zurücksetzen, Entsperren, Notfallweg-Frage

**Files:**
- Modify: `src/Services/KontoWriter.php` — `stoppeNummerwechsel()` (:852-890), `wendeNummernwechselAn()` (:937-967), `offeneZeile()` (:1070-1092); neue Methoden nach `wendeNummernwechselAn()`; `use Platform\Recruiting\Support\HrEinstellungen;`
- Modify: `src/Support/NummernwechselTafel.php` — `zustand()` (:74-85) + zwei Konstanten
- Test: `tests/Integration/KontoWriterHrTest.php`, `tests/Unit/NummernwechselTafelZustandTest.php`

**Interfaces:**
- Consumes: `HrEinstellungen::notfallWegAktiv()` (Task 1), `PersonLinker::setzeNummer()`, `HrBereichHarness`.
- Produces:
  - `KontoWriter::sperrAbfrage(int $personId): \Illuminate\Database\Query\Builder` (mit `lockForUpdate()`)
  - `KontoWriter::gibNummernwechselFrei(int $personId, string $erwartetBeantragtAt): ?array` → `array{alt: ?string, neu: string}|null`; wirft `InvalidArgumentException`, wenn `PersonLinker::setzeNummer()` scheitert (Antrag bleibt)
  - `KontoWriter::stoppeNummerwechsel(int $personId): bool` (unverändertes Verhalten, jetzt in Transaktion mit Sperre)
  - `KontoWriter::wendeNummernwechselAn(int $personId): ?array` (unverändertes Verhalten, jetzt in Transaktion mit Sperre)
  - `KontoWriter::setzeKontoZurueckDurchHr(int $personId): string` (Klartext-Token)
  - `KontoWriter::entsperreKontoDurchHr(int $personId): bool`
  - `KontoWriter::hebeNotfallSperreAuf(int $personId): bool`
  - `KontoWriter::notfallWegAn(int $personId): bool` (Server-Riegel UND Team-Schalter)
  - `NummernwechselTafel::ZUSTAND_OFFEN = 'offen'`, `NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS = 'Notfallweg aus, wartet auf HR'`, `NummernwechselTafel::zustand(object $zeile, bool $notfallWegAn = true): string`

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/NummernwechselTafelZustandTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NummernwechselTafel;

final class NummernwechselTafelZustandTest extends TestCase
{
    public function test_jede_verzweigung(): void
    {
        $offen = (object) ['merged_into_person_id' => null, 'locked_at' => null, 'wechsel_quelle' => 'notfall'];
        $this->assertSame(NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::zustand($offen));
        $this->assertSame(NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::zustand($offen, true));
        $this->assertSame(NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS, NummernwechselTafel::zustand($offen, false));

        $andereQuelle = (object) ['merged_into_person_id' => null, 'locked_at' => null, 'wechsel_quelle' => 'kuenftig'];
        $this->assertSame(NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::zustand($andereQuelle, false), 'der Schalter betrifft nur Weg 4');

        $gesperrt = (object) ['merged_into_person_id' => null, 'locked_at' => '2026-10-01 00:00:00', 'wechsel_quelle' => 'notfall'];
        $this->assertSame('gesperrt, wird nie angewendet', NummernwechselTafel::zustand($gesperrt, false), 'gesperrt sticht den Schalter');

        $still = (object) ['merged_into_person_id' => 3, 'locked_at' => null, 'wechsel_quelle' => 'notfall'];
        $this->assertSame('stillgelegt, wird nie angewendet', NummernwechselTafel::zustand($still, false));
    }
}
```

`tests/Integration/KontoWriterHrTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\KontoWriter;

final class KontoWriterHrTest extends TestCase
{
    use HrBereichHarness;

    private const ALT = '+4915111111111';
    private const NEU = '+4917098765432';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    /** Person mit RG+MA-Akte und offenem Notfall-Antrag; wirksam = in 23 Stunden. */
    private function mitAntrag(array $person = [], string $wirksam = '2026-10-13 09:00:00'): int
    {
        $ma = $this->akte(['phone' => self::ALT]);
        $rg = $this->akte(['phone' => self::ALT, 'company' => 'RG']);

        return $this->person(array_merge([
            'phone' => self::ALT,
            'wechsel_neue_nummer' => self::NEU,
            'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => $wirksam,
            'wechsel_quelle' => 'notfall',
        ], $person), $ma, $rg);
    }

    public function test_freigeben_wechselt_sofort_an_person_und_allen_akten(): void
    {
        $id = $this->mitAntrag();
        $vorher = DB::table('rec_employees')->where('rec_person_id', $id)->pluck('updated_at', 'id')->all();

        $ergebnis = KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00');

        $this->assertSame(['alt' => self::ALT, 'neu' => self::NEU], $ergebnis, 'Faelligkeit wird bewusst nicht geprueft');
        $this->assertSame(self::NEU, $this->personZeile($id)->phone);
        $this->assertSame([self::NEU], DB::table('rec_employees')->where('rec_person_id', $id)->distinct()->pluck('phone')->all());
        $this->assertNull($this->personZeile($id)->wechsel_neue_nummer, 'Antrag weg');
        $this->assertCount(2, $this->crmSync->nachgezogen, 'CRM-Nachzug je Akte (Vorfall RG19734)');
        $protokoll = $this->logZeilen('recruiting.konto.nummer_gewechselt');
        $this->assertSame('hr_freigabe', $protokoll[0]['daten']['weg']);
        $this->assertSame($vorher, DB::table('rec_employees')->where('rec_person_id', $id)->pluck('updated_at', 'id')->all(), 'kein updated_at an rec_employees');
    }

    public function test_freigeben_nur_mit_dem_gesehenen_beantragt_at_und_nur_notfall_und_nur_offen(): void
    {
        $id = $this->mitAntrag();
        $this->assertNull(KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:01'));
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'kuenftig']);
        $this->assertNull(KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00'));

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'notfall', 'locked_at' => now()]);
        $this->assertNull(KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00'));
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);
        $this->assertNotNull($this->personZeile($id)->wechsel_neue_nummer, 'Antrag bleibt');
    }

    public function test_freigeben_mit_vergebener_zielnummer_wirft_und_der_antrag_bleibt(): void
    {
        $id = $this->mitAntrag();
        $this->person(['phone' => self::NEU], $this->akte(['phone' => self::NEU]));

        try {
            KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00');
            $this->fail('erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(self::ALT, $this->personZeile($id)->phone);
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer);
    }

    /** Spec §5.2: nach der Freigabe findet der Stundenlauf nichts mehr. */
    public function test_nach_freigabe_oder_stopp_wendet_der_stundenlauf_nichts_an(): void
    {
        $frei = $this->mitAntrag([], '2026-10-12 08:00:00');
        KontoWriter::gibNummernwechselFrei($frei, '2026-10-12 09:00:00');
        $this->assertNull(KontoWriter::wendeNummernwechselAn($frei));

        $gestoppt = $this->mitAntrag(['phone' => '+4915122222222'], '2026-10-12 08:00:00');
        DB::table('rec_employees')->where('rec_person_id', $gestoppt)->update(['phone' => '+4915122222222']);
        $this->assertTrue(KontoWriter::stoppeNummerwechsel($gestoppt));
        $this->assertNull(KontoWriter::wendeNummernwechselAn($gestoppt));
        $this->assertSame('+4915122222222', $this->personZeile($gestoppt)->phone);

        $angewendet = $this->mitAntrag(['phone' => '+4915133333333', 'wechsel_neue_nummer' => '+4917011111111'], '2026-10-12 08:00:00');
        $this->assertNotNull(KontoWriter::wendeNummernwechselAn($angewendet));
        $this->assertFalse(KontoWriter::stoppeNummerwechsel($angewendet), 'schon angewendet: false, keine Sperre');
        $this->assertNull($this->personZeile($angewendet)->notfall_gesperrt_bis);
    }

    /** Review Focus 1 (Mechanik): dieselbe Zeilensperre fuer alle drei Wege. */
    public function test_alle_drei_wege_lesen_die_zeile_gesperrt_in_einer_transaktion(): void
    {
        $this->assertTrue(KontoWriter::sperrAbfrage(1)->lock === true, 'ohne lockForUpdate ueberholt der Stundenlauf HR');

        $ebenen = [];
        DB::connection()->listen(function ($abfrage) use (&$ebenen) {
            if (str_starts_with(strtolower(ltrim($abfrage->sql)), 'select') && str_contains($abfrage->sql, 'rec_persons')) {
                $ebenen[] = $abfrage->connection->transactionLevel();
            }
        });

        foreach ([
            fn (int $id) => KontoWriter::stoppeNummerwechsel($id),
            fn (int $id) => KontoWriter::wendeNummernwechselAn($id),
            fn (int $id) => KontoWriter::gibNummernwechselFrei($id, '2026-10-12 09:00:00'),
        ] as $nr => $weg) {
            $ebenen = [];
            $id = $this->mitAntrag(['phone' => '+49151444444' . $nr, 'wechsel_neue_nummer' => '+49170555555' . $nr], '2026-10-12 08:00:00');
            $weg($id);
            $this->assertNotEmpty($ebenen);
            $this->assertGreaterThanOrEqual(1, $ebenen[0], "Weg {$nr} liest die Zeile ausserhalb einer Transaktion");
        }
    }

    public function test_hr_zuruecksetzen_nimmt_passwort_und_code_und_laedt_neu_ein(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ganz-geheim-2026'),
            'registered_at' => '2026-10-01 10:00:00',
            'code_hash' => str_repeat('a', 64), 'code_expires_at' => '2026-10-12 10:10:00',
            'code_zweck' => 'passwort', 'code_versuche' => 3,
        ], $ma);

        $token = KontoWriter::setzeKontoZurueckDurchHr($id);

        $zeile = $this->personZeile($id);
        $this->assertNull($zeile->password_hash);
        $this->assertNull($zeile->code_hash);
        $this->assertNull($zeile->code_zweck);
        $this->assertSame(0, (int) $zeile->code_versuche);
        $this->assertSame('2026-10-01 10:00:00', (string) $zeile->registered_at, 'registered_at bleibt als Beleg');
        $this->assertNull(KontoWriter::pruefeAnmeldung(null, self::ALT, 'ganz-geheim-2026'), 'altes Passwort scheitert sofort');
        $this->assertSame($id, KontoWriter::personFuerEinladung($token));

        KontoWriter::registriere($id, $token, '1990-05-17', 'ein-neues-passwort-2026');
        $this->assertSame($id, KontoWriter::pruefeAnmeldung(null, self::ALT, 'ein-neues-passwort-2026'));
    }

    public function test_entsperren_und_notfallsperre_aufheben_nur_mit_wirkung(): void
    {
        $id = $this->mitAntrag();
        $this->assertFalse(KontoWriter::entsperreKontoDurchHr($id), 'nicht gesperrt: keine Wirkung');
        DB::table('rec_persons')->where('id', $id)->update(['locked_at' => now()]);
        $this->assertTrue(KontoWriter::entsperreKontoDurchHr($id));
        $this->assertNull($this->personZeile($id)->locked_at);

        $this->assertFalse(KontoWriter::hebeNotfallSperreAuf($id));
        DB::table('rec_persons')->where('id', $id)->update(['notfall_gesperrt_bis' => '2026-10-11 00:00:00']);
        $this->assertFalse(KontoWriter::hebeNotfallSperreAuf($id), 'abgelaufene Sperre: keine Wirkung');
        DB::table('rec_persons')->where('id', $id)->update(['notfall_gesperrt_bis' => '2026-10-19 00:00:00']);
        $this->assertTrue(KontoWriter::hebeNotfallSperreAuf($id));
        $this->assertNull($this->personZeile($id)->notfall_gesperrt_bis);

        $still = $this->person(['phone' => null, 'merged_into_person_id' => $id, 'locked_at' => now()]);
        $this->assertFalse(KontoWriter::entsperreKontoDurchHr($still), 'stillgelegt: keine Wirkung');
    }

    public function test_notfallweg_an_braucht_server_riegel_und_team_schalter(): void
    {
        $id = $this->mitAntrag();
        $config = \Illuminate\Container\Container::getInstance()->make('config');

        $config->set('recruiting.konto.weg4_aktiv', true);
        $this->assertFalse(KontoWriter::notfallWegAn($id), 'Team aus (Vorgabe)');

        $this->einstellung('konto_notfall_weg_aktiv', true);
        $this->assertTrue(KontoWriter::notfallWegAn($id));

        $config->set('recruiting.konto.weg4_aktiv', false);
        $this->assertFalse(KontoWriter::notfallWegAn($id), 'Server-Riegel ist der Not-Aus');

        $config->set('recruiting.konto.weg4_aktiv', true);
        $ohneTeam = $this->person(['team_id' => null, 'phone' => '+4915166666666']);
        $this->assertFalse(KontoWriter::notfallWegAn($ohneTeam));
    }
}
```

- [ ] **Step 2: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'KontoWriterHrTest|NummernwechselTafelZustandTest'`
Expected: FAIL — `Call to undefined method …KontoWriter::gibNummernwechselFrei()` bzw. `Undefined constant …ZUSTAND_OFFEN`.

- [ ] **Step 3: `NummernwechselTafel::zustand()` erweitern** (`src/Support/NummernwechselTafel.php:74-85` ersetzen)

```php
    public const ZUSTAND_OFFEN = 'offen';

    /** Spec HR-Bereich §6: Antrag liegt, der Stundenlauf wendet ihn nicht an; HR stoppt oder gibt frei. */
    public const ZUSTAND_NOTFALLWEG_AUS = 'Notfallweg aus, wartet auf HR';

    /**
     * Warum ein Antrag nicht angewendet wird, obwohl er in der Liste steht
     * (Befund G5) — und seit dem HR-Bereich: ob der Notfallweg fuer das Team
     * der Person ueberhaupt an ist (KontoWriter::notfallWegAn()). Gesperrt
     * und stillgelegt stechen den Schalter. Der Schalter betrifft nur Weg 4
     * (wechsel_quelle = notfall).
     *
     * recruiting:konto-zuruecksetzen --faellig ueberspringt alles, was nicht
     * ZUSTAND_OFFEN ist (Befund N2).
     */
    public static function zustand(object $zeile, bool $notfallWegAn = true): string
    {
        if (($zeile->merged_into_person_id ?? null) !== null) {
            return 'stillgelegt, wird nie angewendet';
        }

        if (($zeile->locked_at ?? null) !== null) {
            return 'gesperrt, wird nie angewendet';
        }

        if (!$notfallWegAn && ($zeile->wechsel_quelle ?? null) === 'notfall') {
            return self::ZUSTAND_NOTFALLWEG_AUS;
        }

        return self::ZUSTAND_OFFEN;
    }
```

- [ ] **Step 4: `KontoWriter` umbauen**

`use Platform\Recruiting\Support\HrEinstellungen;` ergänzen.

`offeneZeile()` (:1070-1092) ersetzen durch zwei Methoden:

```php
    private static function offeneZeile(int $personId): object
    {
        return self::pruefeOffen(DB::table('rec_persons')->where('id', $personId)->first(), $personId);
    }

    /** Dieselbe Regel wie offeneZeile(), fuer eine schon (gesperrt) gelesene Zeile. */
    private static function pruefeOffen(?object $person, int $personId): object
    {
        if ($person === null) {
            throw new InvalidArgumentException("Person {$personId} existiert nicht.");
        }

        if ($person->locked_at !== null) {
            throw new InvalidArgumentException("Person {$personId} ist gesperrt — kein Konto-Vorgang moeglich.");
        }

        if ($person->merged_into_person_id !== null) {
            throw new InvalidArgumentException(sprintf(
                'Person %d ist stillgelegt (zeigt auf Person %d) — der Konto-Vorgang gehoert an die Zeile, '
                .'an der die Anstellungen jetzt haengen.',
                $personId,
                $person->merged_into_person_id,
            ));
        }

        return $person;
    }
```

`stoppeNummerwechsel()` (:852-890) — Körper ersetzen (Docblock behalten, Absatz anfügen: „Liest mit sperrAbfrage() in einer Transaktion (Spec HR-Bereich §5.2) — wer zuerst kommt, gewinnt; der zweite liest neu und findet nichts.“):

```php
    public static function stoppeNummerwechsel(int $personId): bool
    {
        $gesperrtBis = now()->addDays(self::NOTFALL_SPERRE_TAGE);

        $person = DB::transaction(function () use ($personId, $gesperrtBis) {
            $person = self::sperrAbfrage($personId)->first();

            if ($person === null || $person->wechsel_wirksam_ab === null) {
                return null;
            }

            self::loescheWechselAntrag($personId);

            // RULING GD-13: der Stopp sperrt Weg 4 fuer diese Person.
            DB::table('rec_persons')->where('id', $personId)->update([
                'notfall_gesperrt_bis' => $gesperrtBis,
                'updated_at'           => now(),
            ]);

            return $person;
        });

        if ($person === null) {
            return false;
        }

        Log::warning('recruiting.konto.nummernwechsel_gestoppt', [
            'person_id'     => $personId,
            'gesperrt_bis'  => $gesperrtBis->format('Y-m-d H:i:s'),
            'neu_endet_auf' => $person->wechsel_neue_nummer === null
                ? null
                : substr((string) $person->wechsel_neue_nummer, -4),
        ]);

        return true;
    }
```

`wendeNummernwechselAn()` (:937-967) — Körper ersetzen (Docblock behalten, Absatz anfügen: „Lesen, Wechsel und Abräumen in EINER Transaktion mit Zeilensperre (Spec §5.2); CRM-Nachzug und Protokoll nach dem Commit.“):

```php
    public static function wendeNummernwechselAn(int $personId): ?array
    {
        $ergebnis = DB::transaction(function () use ($personId) {
            $person = self::pruefeOffen(self::sperrAbfrage($personId)->first(), $personId);

            if ($person->wechsel_wirksam_ab === null || $person->wechsel_neue_nummer === null) {
                return null;
            }

            if (strtotime((string) $person->wechsel_wirksam_ab) > strtotime(self::jetzt())) {
                return null;
            }

            $alt = $person->phone === null ? null : (string) $person->phone;
            $neu = (string) $person->wechsel_neue_nummer;

            PersonLinker::setzeNummer($personId, $neu);
            self::loescheWechselAntrag($personId);

            return ['alt' => $alt, 'neu' => $neu];
        });

        if ($ergebnis === null) {
            return null;
        }

        self::zieheCrmKontakteNach($personId);
        self::protokolliereWechsel($personId, $ergebnis['alt'], $ergebnis['neu'], self::ZWECK_NOTFALL);

        return $ergebnis;
    }
```

Direkt danach neu einfügen:

```php
    /**
     * Die Personen-Zeile, gelesen MIT Zeilensperre (InnoDB). Stoppen,
     * Freigeben und der Stundenlauf nehmen dieselbe Sperre; der zweite
     * wartet, liest neu und findet den Antrag nicht mehr (Spec §5.2). Nur
     * innerhalb von DB::transaction() sinnvoll. SQLite kennt keine
     * Zeilensperre — dort pruefen die Tests die Transaktion und $lock.
     */
    public static function sperrAbfrage(int $personId): \Illuminate\Database\Query\Builder
    {
        return DB::table('rec_persons')->where('id', $personId)->lockForUpdate();
    }

    /**
     * HR gibt einen Notfall-Antrag SOFORT frei (Spec HR-Bereich §5.1).
     *
     * Nur wenn: Quelle notfall, Zeile offen (offeneZeile-Regel) und
     * wechsel_beantragt_at GLEICH dem Wert, den HR gesehen hat — sonst null
     * ("der Antrag hat sich inzwischen geaendert"). Die Faelligkeit wird
     * bewusst NICHT geprueft, das ist der Zweck. Scheitert setzeNummer()
     * (Zielnummer im Team vergeben), rollt die Transaktion zurueck, der Antrag
     * bleibt, und die Ausnahme geht an den Aufrufer.
     *
     * Der Hinweis an die alte Nummer ist Sache des Aufrufers (HrKontoAktionen),
     * wie beim Kommando.
     *
     * @return array{alt: ?string, neu: string}|null
     */
    public static function gibNummernwechselFrei(int $personId, string $erwartetBeantragtAt): ?array
    {
        $ergebnis = DB::transaction(function () use ($personId, $erwartetBeantragtAt) {
            $person = self::sperrAbfrage($personId)->first();

            if ($person === null || $person->locked_at !== null || $person->merged_into_person_id !== null) {
                return null;
            }
            if ($person->wechsel_quelle !== self::ZWECK_NOTFALL
                || $person->wechsel_neue_nummer === null
                || $person->wechsel_beantragt_at === null) {
                return null;
            }
            // Ueber Zeitstempel, nicht ueber die Zeichenkette (Treiber-Schreibweisen).
            $gesehen = strtotime($erwartetBeantragtAt);
            if ($gesehen === false || strtotime((string) $person->wechsel_beantragt_at) !== $gesehen) {
                return null;
            }

            $alt = $person->phone === null ? null : (string) $person->phone;
            $neu = (string) $person->wechsel_neue_nummer;

            PersonLinker::setzeNummer($personId, $neu);
            self::loescheWechselAntrag($personId);

            return ['alt' => $alt, 'neu' => $neu];
        });

        if ($ergebnis === null) {
            return null;
        }

        self::zieheCrmKontakteNach($personId);
        self::protokolliereWechsel($personId, $ergebnis['alt'], $ergebnis['neu'], 'hr_freigabe');

        return $ergebnis;
    }

    /**
     * HR setzt ein registriertes Konto zurueck (Spec §5.1): Passwort und
     * laufender Code weg, neue Einladung. registered_at bleibt als Beleg. KEIN
     * Passwort durch HR (Weg-5-Regel: kein Generalschluessel) — der Mensch
     * registriert sich neu, mit Token UND Geburtsdatum.
     *
     * @return string der Klartext-Token der neuen Einladung (nur fuer die Einmal-Anzeige)
     */
    public static function setzeKontoZurueckDurchHr(int $personId): string
    {
        self::offeneZeile($personId);

        DB::table('rec_persons')->where('id', $personId)->update([
            'password_hash'    => null,
            'code_hash'        => null,
            'code_expires_at'  => null,
            'code_zweck'       => null,
            'code_neue_nummer' => null,
            'code_versuche'    => 0,
            'updated_at'       => now(),
        ]);

        return self::ladeEin($personId);
    }

    /** locked_at weg — nur wenn gesetzt und nicht stillgelegt. */
    public static function entsperreKontoDurchHr(int $personId): bool
    {
        return DB::table('rec_persons')
            ->where('id', $personId)
            ->whereNotNull('locked_at')
            ->whereNull('merged_into_person_id')
            ->update(['locked_at' => null, 'updated_at' => now()]) > 0;
    }

    /** Die Sperre aus einem gestoppten Antrag (GD-13) vorzeitig aufheben — nur eine laufende. */
    public static function hebeNotfallSperreAuf(int $personId): bool
    {
        return DB::table('rec_persons')
            ->where('id', $personId)
            ->whereNotNull('notfall_gesperrt_bis')
            ->where('notfall_gesperrt_bis', '>', self::jetzt())
            ->update(['notfall_gesperrt_bis' => null, 'updated_at' => now()]) > 0;
    }

    /**
     * EINE Antwort auf "ist Weg 4 fuer Person X an?" (Spec §6): Server-Riegel
     * recruiting.konto.weg4_aktiv UND Team-Schalter konto_notfall_weg_aktiv
     * des Teams der Person. Person ohne Team: aus (fail closed).
     */
    public static function notfallWegAn(int $personId): bool
    {
        if (!(bool) config('recruiting.konto.weg4_aktiv', false)) {
            return false;
        }

        $teamId = DB::table('rec_persons')->where('id', $personId)->value('team_id');

        return $teamId !== null && HrEinstellungen::notfallWegAktiv((int) $teamId);
    }
```

- [ ] **Step 5: Tests laufen lassen — grün, dann Konto-Bestandstests**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'KontoWriterHrTest|NummernwechselTafelZustandTest|KontoWriterTest|KontoZuruecksetzenTest|KontoEinladenTest|PersonLinkerTest'`
Expected: PASS (Verhalten von Stoppen/Anwenden unverändert; `KontoZuruecksetzen::zustand()` ruft `zustand($zeile)` mit Vorgabe `true`).

- [ ] **Step 6: Commit**

```bash
git add src/Services/KontoWriter.php src/Support/NummernwechselTafel.php tests/Integration/KontoWriterHrTest.php tests/Unit/NummernwechselTafelZustandTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): KontoWriter liest Antraege gesperrt, HR-Freigabe, HR-Zuruecksetzen, Entsperren

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 4: Bausteine — Drossel lesbar, `PortalEntsperrung`, `EinladungsRiegel`, `Bote`, `KontoStatus`, `SperrGruende`

**Files:**
- Modify: `src/Services/PortalAuth.php` — `recordFailure()` (:345-355) speichert „frei ab“; neue Methoden `gebremsteNummern()`, `loeseNummernBremse()` nach `clearFailures()` (:327-331)
- Create: `src/Services/PortalEntsperrung.php`
- Create: `src/Support/EinladungsRiegel.php`; Modify: `src/Console/Commands/KontoEinladen.php` — Konstanten :87-104, `grund()` :605-623, `ohneWhatsapp()` :651-728 delegieren
- Create: `src/Support/Bote.php`; Modify: `src/Console/Commands/EinsatzPruefung.php` — `LAUF_SPERRE` :254 `public`, Aufruf :435, `bote()` :1321-1328 entfernen
- Create: `src/Support/KontoStatus.php`, `src/Support/SperrGruende.php`
- Test: `tests/Integration/PortalAuthBremseTest.php`, `tests/Integration/PortalEntsperrungTest.php`, `tests/Integration/BoteTest.php`, `tests/Unit/EinladungsRiegelTest.php`, `tests/Unit/KontoStatusTest.php`, `tests/Unit/SperrGruendeTest.php`

**Interfaces:**
- Consumes: `HrBereichHarness` (Task 1), `DispoIdentityResolver::groupFor()`.
- Produces:
  - `PortalAuth::gebremsteNummern(array $nummern): array<string, ?string>` — NUR die gebremsten Nummern als Schlüssel (Eingabe-Schreibweise), Wert = „frei ab“ ISO-8601 oder `null` (Altwert `true`); genau EIN `many()`.
  - `PortalAuth::loeseNummernBremse(string $nummer): void` — IP-Bremse bleibt.
  - `PortalEntsperrung::__construct(?DispoIdentityResolver $resolver = null)`, `fuerAnstellung(int $employeeId): int` (Anzahl entsperrter Akten).
  - `EinladungsRiegel::KEINE_NUMMER|KEIN_GEBURTSDATUM|KEIN_WHATSAPP`, `GRUENDE`, `grund(?string $nummer, bool $ohneGeburtsdatum, bool $ohneWhatsapp): ?string`, `fuerPerson(int $personId): ?string`, `ohneWhatsapp(array $personen): array<int>`.
  - `Bote::fuer(array $umfangIds): ?RecEmployee`; `EinsatzPruefung::LAUF_SPERRE` öffentlich.
  - `KontoStatus::{NICHT_EINGELADEN,EINGELADEN,ABGELAUFEN,REGISTRIERT,ZURUECKGESETZT}`, `KontoStatus::FILTER` (array<string,string>), `KontoStatus::LANGE_NICHT_TAGE = 14`, `code(object $p, string $jetzt): string`, `text(object $p, string $jetzt): string`, `filter(object $p, string $jetzt): list<string>`, `abgelaufen(object $p, string $jetzt): bool`.
  - `SperrGruende::{DISPO,KONTO,BREMSE,NOTFALL,MEHRDEUTIG}`, `SperrGruende::AKTION` (code → Aktionsname oder null), `SperrGruende::KNOPF` (code → Knopftext), `zaehltFuersAbzeichen(string $code): bool`, `fuer(array $e, string $jetzt): list<array{code:string, text:string, aktion:?string, knopf:?string, employee_id:?int}>` mit `$e = ['dispo' => ?array{seit:string, grund:?string, employee_id:int}, 'konto_gesperrt' => bool, 'bremse' => ?array{frei_ab:?string}, 'notfall_bis' => ?string, 'mehrdeutig' => bool]`.

- [ ] **Step 1: Failing tests schreiben**

`tests/Integration/PortalAuthBremseTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PortalAuthBremseTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function fuenfFehlversuche(string $nummer): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, $nummer, 'falsch-falsch-' . $i);
        }
    }

    public function test_gebremste_nummern_mit_frei_ab_in_einer_abfrage(): void
    {
        $this->fuenfFehlversuche('0151 11111111');
        $store = $this->cache->getStore();
        $store->manyAufrufe = 0;
        $store->getAufrufe = 0;

        $gebremst = $this->portalAuth()->gebremsteNummern(['+4915111111111', '+4915122222222']);

        $this->assertSame(['+4915111111111'], array_keys($gebremst), 'Normalisierung: getippt "0151…", gefragt "+49151…"');
        $this->assertSame('2026-10-12T10:15:00+00:00', $gebremst['+4915111111111']);
        $this->assertSame(1, $store->manyAufrufe, 'EIN many() fuer alle Nummern (Spec §3.2)');
        $this->assertSame(0, $store->getAufrufe, 'keine Einzelabfrage je Nummer');
        $this->assertSame([], $this->portalAuth()->gebremsteNummern([]));
    }

    public function test_bremse_loesen_laesst_die_ip_bremse_stehen(): void
    {
        $this->fuenfFehlversuche('+4915111111111');
        $this->assertNotSame([], $this->portalAuth()->gebremsteNummern(['+4915111111111']));
        $ipVorher = $this->ipSchluesselWerte();

        $this->portalAuth()->loeseNummernBremse('0151 11111111');

        $this->assertSame([], $this->portalAuth()->gebremsteNummern(['+4915111111111']));
        $this->assertSame($ipVorher, $this->ipSchluesselWerte(), 'IP-Zaehler unberuehrt');
        $this->assertNotSame([], $ipVorher, 'Anker: es gab einen IP-Zaehler');
    }

    /** @return array<string, mixed> */
    private function ipSchluesselWerte(): array
    {
        $speicher = (new \ReflectionProperty($this->cache->getStore(), 'storage'))->getValue($this->cache->getStore());

        return array_filter($speicher, fn ($k) => str_contains((string) $k, ':ip:'), ARRAY_FILTER_USE_KEY);
    }
}
```

`tests/Integration/PortalEntsperrungTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\PortalEntsperrung;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

final class PortalEntsperrungTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_entsperrt_die_ganze_dispo_gruppe_per_query_builder(): void
    {
        $a = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: nicht bestaetigt']);
        $b = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: nicht bestaetigt']);
        $fremd = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $vorher = DB::table('rec_employees')->pluck('updated_at', 'id')->all();
        $gruppe = new class([$a->id, $b->id]) extends DispoIdentityResolver {
            public function __construct(private array $ids) {}
            public function groupFor(int $employeeId): array { return $this->ids; }
        };

        $anzahl = (new PortalEntsperrung($gruppe))->fuerAnstellung($a->id);

        $this->assertSame(2, $anzahl);
        $this->assertSame(0, DB::table('rec_employees')->whereIn('id', [$a->id, $b->id])->whereNotNull('portal_locked_at')->count());
        $this->assertSame(0, DB::table('rec_employees')->whereIn('id', [$a->id, $b->id])->whereNotNull('portal_locked_reason')->count());
        $this->assertNotNull(DB::table('rec_employees')->where('id', $fremd->id)->value('portal_locked_at'), 'ausserhalb der Gruppe bleibt gesperrt');
        $this->assertSame($vorher, DB::table('rec_employees')->pluck('updated_at', 'id')->all(), 'kein updated_at, kein Eloquent');
        $this->assertSame(0, DB::table('rec_employees')->whereNotNull('zas_changed_at')->count(), 'kein ZAS-Marker');
        $this->assertSame(0, (new PortalEntsperrung($gruppe))->fuerAnstellung($a->id), 'zweiter Klick: keine Wirkung');
    }

    public function test_ohne_team_anker_entsperrt_der_echte_resolver_nur_die_eigene_akte(): void
    {
        $a = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);

        $this->assertSame(1, (new PortalEntsperrung())->fuerAnstellung($a->id));
    }
}
```

`tests/Integration/BoteTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Support\Bote;

final class BoteTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_kleinste_kennung_mit_neuem_portal(): void
    {
        $alt = $this->akte(['portal_v2_since' => null]);
        $neu1 = $this->akte();
        $neu2 = $this->akte();

        $this->assertSame($neu1->id, Bote::fuer([$neu2->id, $alt->id, $neu1->id])?->id);
        $this->assertNull(Bote::fuer([$alt->id]));
        $this->assertNull(Bote::fuer([]));
    }

    public function test_das_kommando_nutzt_den_ausgezogenen_boten_und_die_laufsperre_ist_oeffentlich(): void
    {
        $this->assertSame('recruiting:einsatz-pruefung:lauf', EinsatzPruefung::LAUF_SPERRE);
        $this->assertFalse(method_exists(EinsatzPruefung::class, 'bote'), 'eine Regel, eine Stelle: Support\\Bote');
    }
}
```

`tests/Unit/EinladungsRiegelTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinladungsRiegel;

final class EinladungsRiegelTest extends TestCase
{
    public function test_reihenfolge_der_gruende(): void
    {
        $this->assertSame('keine Nummer', EinladungsRiegel::grund(null, true, true), 'keine Nummer sticht alles');
        $this->assertSame('keine Nummer', EinladungsRiegel::grund('   ', false, false));
        $this->assertSame('kein Geburtsdatum', EinladungsRiegel::grund('+4915111111111', true, true));
        $this->assertSame('kein WhatsApp', EinladungsRiegel::grund('+4915111111111', false, true));
        $this->assertNull(EinladungsRiegel::grund('+4915111111111', false, false));
        $this->assertSame(['keine Nummer', 'kein Geburtsdatum', 'kein WhatsApp'], EinladungsRiegel::GRUENDE);
    }
}
```

`tests/Unit/KontoStatusTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\KontoStatus;

final class KontoStatusTest extends TestCase
{
    private const JETZT = '2026-10-12 10:00:00';

    private function p(array $f): object
    {
        return (object) array_merge([
            'password_hash' => null, 'registered_at' => null, 'invited_at' => null,
            'invite_expires_at' => null, 'invite_used_at' => null, 'letzte_anmeldung_at' => null,
        ], $f);
    }

    public function test_jeder_zustand_mit_text(): void
    {
        $this->assertSame(KontoStatus::NICHT_EINGELADEN, KontoStatus::code($this->p([]), self::JETZT));
        $this->assertSame('nicht eingeladen', KontoStatus::text($this->p([]), self::JETZT));

        $eingeladen = $this->p(['invited_at' => '2026-10-12 09:00:00', 'invite_expires_at' => '2026-10-19 09:00:00']);
        $this->assertSame(KontoStatus::EINGELADEN, KontoStatus::code($eingeladen, self::JETZT));
        $this->assertSame('eingeladen am 12.10.2026 (gilt bis 19.10.2026)', KontoStatus::text($eingeladen, self::JETZT));

        $genauAbgelaufen = $this->p(['invited_at' => '2026-10-05 10:00:00', 'invite_expires_at' => self::JETZT]);
        $this->assertSame(KontoStatus::ABGELAUFEN, KontoStatus::code($genauAbgelaufen, self::JETZT), 'abgelaufen GENAU an invite_expires_at');
        $eineSekundeVorher = $this->p(['invited_at' => '2026-10-05 10:00:00', 'invite_expires_at' => '2026-10-12 10:00:01']);
        $this->assertSame(KontoStatus::EINGELADEN, KontoStatus::code($eineSekundeVorher, self::JETZT));

        $registriert = $this->p(['password_hash' => 'x', 'registered_at' => '2026-10-05 10:00:00']);
        $this->assertSame(KontoStatus::REGISTRIERT, KontoStatus::code($registriert, self::JETZT));
        $this->assertSame('registriert am 05.10.2026', KontoStatus::text($registriert, self::JETZT));

        $zurueck = $this->p(['registered_at' => '2026-10-05 10:00:00', 'invited_at' => '2026-10-12 09:30:00', 'invite_expires_at' => '2026-10-19 09:30:00']);
        $this->assertSame(KontoStatus::ZURUECKGESETZT, KontoStatus::code($zurueck, self::JETZT));
        $this->assertSame('zurückgesetzt am 12.10.2026 (wartet auf neues Passwort)', KontoStatus::text($zurueck, self::JETZT));
    }

    public function test_filter(): void
    {
        $this->assertSame(['alle', 'nicht_eingeladen'], KontoStatus::filter($this->p([]), self::JETZT));
        $this->assertSame(['alle', 'eingeladen'], KontoStatus::filter($this->p(['invited_at' => '2026-10-12 09:00:00', 'invite_expires_at' => '2026-10-19 09:00:00']), self::JETZT));
        $this->assertSame(['alle', 'abgelaufen'], KontoStatus::filter($this->p(['invited_at' => '2026-10-01 09:00:00', 'invite_expires_at' => '2026-10-08 09:00:00']), self::JETZT));
        $this->assertSame(['alle', 'nie_angemeldet'], KontoStatus::filter($this->p(['password_hash' => 'x', 'registered_at' => '2026-10-05 10:00:00']), self::JETZT));
        $this->assertSame(['alle', 'lange_nicht'], KontoStatus::filter($this->p(['password_hash' => 'x', 'registered_at' => '2026-09-01 10:00:00', 'letzte_anmeldung_at' => '2026-09-28 10:00:00']), self::JETZT), 'genau 14 Tage');
        $this->assertSame(['alle'], KontoStatus::filter($this->p(['password_hash' => 'x', 'registered_at' => '2026-09-01 10:00:00', 'letzte_anmeldung_at' => '2026-10-11 10:00:00']), self::JETZT));
        $this->assertSame(['alle', 'nicht_eingeladen', 'eingeladen', 'abgelaufen', 'nie_angemeldet', 'lange_nicht'], array_keys(KontoStatus::FILTER));
    }
}
```

`tests/Unit/SperrGruendeTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SperrGruende;

final class SperrGruendeTest extends TestCase
{
    private const JETZT = '2026-10-12 10:00:00';

    public function test_jeder_grund_mit_aktion_und_text(): void
    {
        $gruende = SperrGruende::fuer([
            'dispo'          => ['seit' => '2026-10-10 16:00:00', 'grund' => 'Dispo: nicht bestätigt', 'employee_id' => 9],
            'konto_gesperrt' => true,
            'bremse'         => ['frei_ab' => '2026-10-12T10:15:00+00:00'],
            'notfall_bis'    => '2026-10-19 10:00:00',
            'mehrdeutig'     => true,
        ], self::JETZT);

        $this->assertSame(['dispo', 'konto', 'bremse', 'notfall', 'mehrdeutig'], array_column($gruende, 'code'));
        $this->assertSame('Portal gesperrt seit 10.10.2026, 16:00 — Dispo: nicht bestätigt', $gruende[0]['text']);
        $this->assertSame(9, $gruende[0]['employee_id']);
        $this->assertSame('portal_entsperren', $gruende[0]['aktion']);
        $this->assertSame('Konto gesperrt', $gruende[1]['text']);
        $this->assertSame('5 Fehlversuche, frei ab 10:15', $gruende[2]['text']);
        $this->assertSame('Notfallweg gesperrt bis 19.10.2026', $gruende[3]['text']);
        $this->assertSame('Nummer ist auch in einem anderen Team vergeben — Anmeldung nicht möglich', $gruende[4]['text']);
        $this->assertNull($gruende[4]['aktion'], 'mehrdeutig: keine Aktion (Plattform-Support)');
    }

    public function test_leere_und_abgelaufene_eingaben_ergeben_nichts(): void
    {
        $this->assertSame([], SperrGruende::fuer(['dispo' => null, 'konto_gesperrt' => false, 'bremse' => null, 'notfall_bis' => '2026-10-12 10:00:00', 'mehrdeutig' => false], self::JETZT));
        $ohneZeit = SperrGruende::fuer(['bremse' => ['frei_ab' => null]], self::JETZT);
        $this->assertSame('5 Fehlversuche, frei spätestens nach 15 Minuten', $ohneZeit[0]['text']);
        $this->assertFalse(SperrGruende::zaehltFuersAbzeichen(SperrGruende::BREMSE));
        $this->assertTrue(SperrGruende::zaehltFuersAbzeichen(SperrGruende::DISPO));
    }
}
```

- [ ] **Step 2: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'PortalAuthBremseTest|PortalEntsperrungTest|BoteTest|EinladungsRiegelTest|KontoStatusTest|SperrGruendeTest'`
Expected: FAIL — undefined methods/classes.

- [ ] **Step 3: `PortalAuth` — Drossel lesbar machen**

`recordFailure()` (:345-355) — die Sperre speichert jetzt ihr Ende statt `true` (bleibt truthy für `isRateLimited()` und das alte `EmployeePortal`):

```php
    private function recordFailure(string $token, int $grenze = self::MAX_ATTEMPTS, int $minuten = self::LOCKOUT_MINUTES): int
    {
        $versuche = ((int) $this->store()->get($this->attemptsKey($token), 0)) + 1;
        $this->store()->put($this->attemptsKey($token), $versuche, now()->addMinutes($minuten));

        if ($versuche >= $grenze) {
            // Der Wert ist das ENDE der Sperre (Spec HR-Bereich §3.2: "frei ab 14:32").
            // Wahr wie zuvor true — isRateLimited() und das alte Portal lesen (bool).
            $ende = now()->addMinutes($minuten);
            $this->store()->put($this->lockoutKey($token), $ende->toIso8601String(), $ende);
        }

        return $versuche;
    }
```

Nach `clearFailures()` (:327-331) einfügen:

```php
    /**
     * Welche dieser Nummern sind gerade gebremst (Spec HR-Bereich §5.4)?
     * Der Schluessel bleibt privat hier; EIN many() fuer alle — bei
     * CACHE_STORE=database ist das eine Abfrage (whereIn), keine je Person.
     *
     * @param  list<string>  $nummern
     * @return array<string, ?string>  nur die gebremsten: Nummer (wie uebergeben) => "frei ab" (ISO) oder null (Altwert true)
     */
    public function gebremsteNummern(array $nummern): array
    {
        if ($nummern === []) {
            return [];
        }

        $jeSchluessel = [];
        foreach ($nummern as $nummer) {
            $jeSchluessel[$this->lockoutKey($this->nummernSchluessel((string) $nummer))] = (string) $nummer;
        }

        $gebremst = [];
        foreach ($this->store()->many(array_keys($jeSchluessel)) as $schluessel => $wert) {
            if (!$wert) {
                continue;
            }
            $gebremst[$jeSchluessel[$schluessel]] = is_string($wert) ? $wert : null;
        }

        return $gebremst;
    }

    /** HR loest die Nummern-Bremse (Spec §5.4). Die IP-Bremse bleibt unberuehrt. */
    public function loeseNummernBremse(string $nummer): void
    {
        $this->clearFailures($this->nummernSchluessel($nummer));
    }
```

- [ ] **Step 4: `PortalEntsperrung` schreiben** (`src/Services/PortalEntsperrung.php`)

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Die HR-Entsperrung der Dispo-Sperre (Eskalationsstufe 3), umgezogen aus
 * Employees\Show::unlockPortal() (Spec HR-Bereich §5.3).
 *
 * GENAU DIE MENGE, DIE PortalAuth FRAGT (Ruling GD-9): die
 * Dispo-Identitaetsgruppe aus DispoIdentityResolver::groupFor(). Query
 * Builder: kein updated_at, kein Beobachter, kein ZAS-Marker — eine
 * Entsperrung ist keine fachliche Aenderung.
 */
final class PortalEntsperrung
{
    public function __construct(private readonly ?DispoIdentityResolver $resolver = null) {}

    /** @return int Anzahl entsperrter Akten (0 = war nicht gesperrt) */
    public function fuerAnstellung(int $employeeId): int
    {
        $ids = ($this->resolver ?? app(DispoIdentityResolver::class))->groupFor($employeeId);

        return DB::table('rec_employees')
            ->whereIn('id', $ids)
            ->whereNotNull('portal_locked_at')
            ->update(['portal_locked_at' => null, 'portal_locked_reason' => null]);
    }
}
```

- [ ] **Step 5: `EinladungsRiegel` schreiben und `KontoEinladen` delegieren lassen**

`src/Support/EinladungsRiegel.php` — `ohneWhatsapp()` ist WÖRTLICH der Körper von `KontoEinladen::ohneWhatsapp()` (:651-728, inkl. Docblock), nur `private function` → `public static function`:

```php
<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\KontoWriter;

/**
 * Wer bekommt KEINE Einladung — und warum (Spec HR-Bereich §4.2)? Eine Regel
 * fuer das Kommando recruiting:konto-einladen und den Konto-Kasten. grund()
 * ist rein; fuerPerson()/ohneWhatsapp() lesen die Datenbank.
 */
final class EinladungsRiegel
{
    public const KEINE_NUMMER = 'keine Nummer';
    public const KEIN_GEBURTSDATUM = 'kein Geburtsdatum';
    public const KEIN_WHATSAPP = 'kein WhatsApp';

    /**
     * Pruefreihenfolge — der erste zutreffende gewinnt; "keine Nummer" macht
     * alle anderen erst gar nicht zur Frage.
     *
     * @var list<string>
     */
    public const GRUENDE = [self::KEINE_NUMMER, self::KEIN_GEBURTSDATUM, self::KEIN_WHATSAPP];

    public static function grund(?string $nummer, bool $ohneGeburtsdatum, bool $ohneWhatsapp): ?string
    {
        if (trim((string) $nummer) === '') {
            return self::KEINE_NUMMER;
        }
        if ($ohneGeburtsdatum) {
            return self::KEIN_GEBURTSDATUM;
        }
        if ($ohneWhatsapp) {
            return self::KEIN_WHATSAPP;
        }

        return null;
    }

    /** Dieselbe Frage fuer EINE Person (Konto-Kasten). */
    public static function fuerPerson(int $personId): ?string
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first(['id', 'phone']);
        if ($person === null) {
            return self::KEINE_NUMMER;
        }

        return self::grund(
            $person->phone,
            KontoWriter::personenOhneGeburtsdatum([$personId]) !== [],
            self::ohneWhatsapp([$person]) !== [],
        );
    }

    /**
     * Wessen Nummer im CRM als 'unavailable' bekannt ist (Spec §3: "Ohne
     * WhatsApp — bekommen kein Konto").
     *
     * NUR DAS BEKANNTE NEIN SPERRT. 'unknown' ist die Vorgabe der Spalte
     * (Migration 2025_02_18_000001) und steht an fast jeder Nummer, die nie
     * angeschrieben wurde; wer daraus ein Nein machte, schloesse den Bestand
     * von der Umstellung aus. Und ein 'available'/'opted_in' an derselben
     * Nummer hebt ein 'unavailable' auf: dann ist die Nummer nachweislich
     * erreichbar, und die andere Zeile ist der veraltete Eintrag.
     *
     * DIE SCHREIBWEISEN SIND DIE DES BESTANDES, nicht die reine Lehre —
     * nachgelesen in ContactPhoneSync::syncEmployee(), das seit dem Vorfall
     * RG19734 genau so sucht: linkable_type mit LIKE '%RecEmployee' (es gibt
     * kein Morph-Kuerzel fuer rec_employee), phoneable_type entweder als
     * Kuerzel 'crm_contact' (Morph-Karte des CrmServiceProvider) ODER als
     * voller Klassenname. Wer nur eine der beiden Formen abfragt, findet je
     * nach Alter der Zeile nichts und haelt niemanden auf.
     *
     * VERGLICHEN WIRD UEBER PhoneE164::suffix() — die letzten neun Ziffern.
     * Dieselbe Kruecke wie in ContactPhoneSync, und aus demselben Grund: im
     * CRM stehen "+49 151 …", "0151 …" und E.164 nebeneinander, und
     * normalisieren scheitert an unvollstaendigen Nummern.
     *
     * @param  list<object>  $personen
     * @return list<int>
     */
    public static function ohneWhatsapp(array $personen): array
    {
        if ($personen === []) {
            return [];
        }

        $personIds = array_map(static fn (object $p): int => (int) $p->id, $personen);

        /** @var array<int, int> $anstellungZuPerson */
        $anstellungZuPerson = DB::table('rec_employees')
            ->whereIn('rec_person_id', $personIds)
            ->pluck('rec_person_id', 'id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        if ($anstellungZuPerson === []) {
            return [];
        }

        /** @var array<int, list<int>> $kontaktZuPersonen */
        $kontaktZuPersonen = [];
        foreach (DB::table('crm_contact_links')
            ->where('linkable_type', 'LIKE', '%RecEmployee')
            ->whereIn('linkable_id', array_keys($anstellungZuPerson))
            ->get(['linkable_id', 'contact_id']) as $link) {
            $personId = $anstellungZuPerson[(int) $link->linkable_id] ?? null;
            if ($personId === null) {
                continue;
            }
            $kontaktZuPersonen[(int) $link->contact_id][] = $personId;
        }

        if ($kontaktZuPersonen === []) {
            return [];
        }

        /** @var array<int, list<string>> $statusJePerson */
        $statusJePerson = [];

        $suffixe = [];
        foreach ($personen as $person) {
            $suffixe[(int) $person->id] = PhoneE164::suffix((string) $person->phone);
        }

        foreach (DB::table('crm_phone_numbers')
            ->whereIn('phoneable_id', array_keys($kontaktZuPersonen))
            ->where(fn ($q) => $q->where('phoneable_type', 'crm_contact')
                ->orWhere('phoneable_type', 'LIKE', '%CrmContact'))
            ->where('is_active', 1)
            ->get(['phoneable_id', 'international', 'raw_input', 'whatsapp_status']) as $nummer) {
            foreach ($kontaktZuPersonen[(int) $nummer->phoneable_id] ?? [] as $personId) {
                $suffix = $suffixe[$personId] ?? '';
                if ($suffix === '') {
                    continue;
                }
                if (PhoneE164::suffix((string) $nummer->international) !== $suffix
                    && PhoneE164::suffix((string) $nummer->raw_input) !== $suffix) {
                    continue;
                }
                $statusJePerson[$personId][] = (string) $nummer->whatsapp_status;
            }
        }

        $ohne = [];
        foreach ($statusJePerson as $personId => $status) {
            if (array_intersect(['available', 'opted_in'], $status) !== []) {
                continue;
            }
            if (in_array('unavailable', $status, true)) {
                $ohne[] = $personId;
            }
        }

        return $ohne;
    }
}
```

(`ohneWhatsapp()` ist zeichengleich der bisherige Körper aus `KontoEinladen.php:624-728`, nur `private function` → `public static function`.)

In `src/Console/Commands/KontoEinladen.php`: `use Platform\Recruiting\Support\EinladungsRiegel;` ergänzen; Zeilen 87-104 ersetzen durch

```php
    private const GRUND_KEINE_NUMMER = EinladungsRiegel::KEINE_NUMMER;

    private const GRUND_KEIN_GEBURTSDATUM = EinladungsRiegel::KEIN_GEBURTSDATUM;

    private const GRUND_KEIN_WHATSAPP = EinladungsRiegel::KEIN_WHATSAPP;

    /** Pruefreihenfolge: EinladungsRiegel::GRUENDE (eine Regel fuer Kommando und Konto-Kasten). */
    private const GRUENDE = EinladungsRiegel::GRUENDE;
```

`grund()` (:605-623) Körper ersetzen durch

```php
        return EinladungsRiegel::grund(
            (string) $person->phone,
            isset($ohneGeburtsdatum[(int) $person->id]),
            isset($ohneWhatsapp[(int) $person->id]),
        );
```

`ohneWhatsapp()` (:651-728) Körper ersetzen durch `return EinladungsRiegel::ohneWhatsapp($personen);` (Docblock auf einen Satz kürzen: „Ausgezogen nach EinladungsRiegel::ohneWhatsapp().“).

- [ ] **Step 6: `Bote` ausziehen** (`src/Support/Bote.php`)

```php
<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecEmployee;

/**
 * Wer traegt die Nachricht an diesen Menschen? Die kleinste Anstellung im
 * Personen-Umfang mit portal_v2_since — Rufnummer, Kanal und Portal-Token
 * muessen zum EXISTIERENDEN Portal passen. (Docblock von
 * EinsatzPruefung::bote() hierher umziehen.) Eine Regel fuer die
 * Einsatz-Pruefung und "Erneut erinnern" im HR-Bereich (Spec §3.3).
 */
final class Bote
{
    /** @param  list<int>  $umfangIds */
    public static function fuer(array $umfangIds): ?RecEmployee
    {
        if ($umfangIds === []) {
            return null;
        }

        return RecEmployee::query()
            ->whereIn('id', $umfangIds)
            ->whereNotNull('portal_v2_since')
            ->orderBy('id')
            ->first();
    }
}
```

In `src/Console/Commands/EinsatzPruefung.php`: `use Platform\Recruiting\Support\Bote;` ergänzen; Zeile 254 `private const LAUF_SPERRE` → `public const LAUF_SPERRE` (Docblock ergänzen: „Öffentlich: auch HR-„Erneut erinnern“ nimmt diese Sperre (Spec §3.3).“); Zeile 435 `$bote = $this->bote($umfang['ids']);` → `$bote = Bote::fuer($umfang['ids']);`; Methode `bote()` samt Docblock (:~1300-1328) löschen.

- [ ] **Step 7: `KontoStatus` schreiben** (`src/Support/KontoStatus.php`)

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Der Konto-Zustand eines Menschen — EINE Ableitung fuer Pilot-Ueberblick und
 * Konto-Kasten (Spec HR-Bereich §3.4/§4.1). Rein: bekommt die Personen-Felder
 * und "jetzt" ('Y-m-d H:i:s').
 */
final class KontoStatus
{
    public const NICHT_EINGELADEN = 'nicht_eingeladen';
    public const EINGELADEN       = 'eingeladen';
    public const ABGELAUFEN       = 'abgelaufen';
    public const REGISTRIERT      = 'registriert';
    public const ZURUECKGESETZT   = 'zurueckgesetzt';

    public const LANGE_NICHT_TAGE = 14;

    /** Filter-Chips des Pilot-Ueberblicks, in Anzeige-Reihenfolge. */
    public const FILTER = [
        'alle'             => 'Alle',
        'nicht_eingeladen' => 'nicht eingeladen',
        'eingeladen'       => 'eingeladen, nicht registriert',
        'abgelaufen'       => 'Einladung abgelaufen',
        'nie_angemeldet'   => 'registriert, nie angemeldet',
        'lange_nicht'      => 'seit 14 Tagen nicht angemeldet',
    ];

    public static function code(object $p, string $jetzt): string
    {
        if (!empty($p->password_hash ?? null)) {
            return self::REGISTRIERT;
        }
        if (($p->registered_at ?? null) !== null) {
            return self::ZURUECKGESETZT;
        }
        if (($p->invited_at ?? null) === null) {
            return self::NICHT_EINGELADEN;
        }

        return self::abgelaufen($p, $jetzt) ? self::ABGELAUFEN : self::EINGELADEN;
    }

    /** Abgelaufen GENAU ab invite_expires_at (wie KontoEinladen::einladungsstand). */
    public static function abgelaufen(object $p, string $jetzt): bool
    {
        $bis = $p->invite_expires_at ?? null;

        return $bis !== null
            && ($p->invite_used_at ?? null) === null
            && strtotime((string) $bis) <= strtotime($jetzt);
    }

    public static function text(object $p, string $jetzt): string
    {
        return match (self::code($p, $jetzt)) {
            self::NICHT_EINGELADEN => 'nicht eingeladen',
            self::EINGELADEN       => sprintf('eingeladen am %s (gilt bis %s)', self::tag($p->invited_at), self::tag($p->invite_expires_at)),
            self::ABGELAUFEN       => 'Einladung abgelaufen',
            self::REGISTRIERT      => 'registriert am ' . self::tag($p->registered_at),
            self::ZURUECKGESETZT   => sprintf('zurückgesetzt am %s (wartet auf neues Passwort)', self::tag($p->invited_at ?? $p->registered_at)),
        };
    }

    /** @return list<string> Schluessel aus FILTER, die auf diesen Menschen zutreffen ('alle' immer). */
    public static function filter(object $p, string $jetzt): array
    {
        $code = self::code($p, $jetzt);
        $treffer = ['alle'];

        if (in_array($code, [self::NICHT_EINGELADEN, self::EINGELADEN, self::ABGELAUFEN], true)) {
            $treffer[] = $code;
        }
        if ($code === self::REGISTRIERT) {
            $letzte = $p->letzte_anmeldung_at ?? null;
            if ($letzte === null) {
                $treffer[] = 'nie_angemeldet';
            } elseif (strtotime((string) $letzte) <= strtotime($jetzt . ' -' . self::LANGE_NICHT_TAGE . ' days')) {
                $treffer[] = 'lange_nicht';
            }
        }

        return $treffer;
    }

    private static function tag(mixed $wert): string
    {
        $zeit = $wert === null ? false : strtotime((string) $wert);

        return $zeit === false ? '—' : date('d.m.Y', $zeit);
    }
}
```

- [ ] **Step 8: `SperrGruende` schreiben** (`src/Support/SperrGruende.php`)

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Die fuenf Sperrgruende der Liste "Gesperrt" und des Konto-Kastens (Spec
 * HR-Bereich §3.2) als Chips mit Aktion. Rein: die Eingaben sammelt
 * HrMitarbeiterListen. code_versuche, IP-Bremse, Token-404 und
 * Arbeitserlaubnis sind bewusst KEINE Gruende.
 */
final class SperrGruende
{
    public const DISPO      = 'dispo';
    public const KONTO      = 'konto';
    public const BREMSE     = 'bremse';
    public const NOTFALL    = 'notfall';
    public const MEHRDEUTIG = 'mehrdeutig';

    /** Aktion in HrKontoAktionen / den Livewire-Komponenten; MEHRDEUTIG hat keine (Plattform-Support). */
    public const AKTION = [
        self::DISPO      => 'portal_entsperren',
        self::KONTO      => 'konto_entsperren',
        self::BREMSE     => 'bremse_loesen',
        self::NOTFALL    => 'notfallsperre_aufheben',
        self::MEHRDEUTIG => null,
    ];

    public const KNOPF = [
        self::DISPO      => 'Portal entsperren',
        self::KONTO      => 'Konto entsperren',
        self::BREMSE     => 'Bremse lösen',
        self::NOTFALL    => 'Notfallsperre aufheben',
        self::MEHRDEUTIG => null,
    ];

    /** Die Drossel endet nach 15 Minuten von selbst und zaehlt nicht fuers Abzeichen (Spec §3). */
    public static function zaehltFuersAbzeichen(string $code): bool
    {
        return $code !== self::BREMSE;
    }

    /**
     * @param  array{dispo?: ?array{seit:string, grund:?string, employee_id:int}, konto_gesperrt?: bool, bremse?: ?array{frei_ab:?string}, notfall_bis?: ?string, mehrdeutig?: bool}  $e
     * @return list<array{code:string, text:string, aktion:?string, knopf:?string, employee_id:?int}>
     */
    public static function fuer(array $e, string $jetzt): array
    {
        $gruende = [];

        if (($e['dispo'] ?? null) !== null) {
            $grund = trim((string) ($e['dispo']['grund'] ?? ''));
            $gruende[] = self::chip(
                self::DISPO,
                'Portal gesperrt seit ' . date('d.m.Y, H:i', (int) strtotime((string) $e['dispo']['seit'])) . ($grund !== '' ? ' — ' . $grund : ''),
                (int) $e['dispo']['employee_id'],
            );
        }
        if (($e['konto_gesperrt'] ?? false) === true) {
            $gruende[] = self::chip(self::KONTO, 'Konto gesperrt');
        }
        if (($e['bremse'] ?? null) !== null) {
            $frei = $e['bremse']['frei_ab'] ?? null;
            $gruende[] = self::chip(self::BREMSE, $frei !== null
                ? '5 Fehlversuche, frei ab ' . date('H:i', (int) strtotime((string) $frei))
                : '5 Fehlversuche, frei spätestens nach 15 Minuten');
        }
        $bis = $e['notfall_bis'] ?? null;
        if ($bis !== null && strtotime((string) $bis) > strtotime($jetzt)) {
            $gruende[] = self::chip(self::NOTFALL, 'Notfallweg gesperrt bis ' . date('d.m.Y', (int) strtotime((string) $bis)));
        }
        if (($e['mehrdeutig'] ?? false) === true) {
            $gruende[] = self::chip(self::MEHRDEUTIG, 'Nummer ist auch in einem anderen Team vergeben — Anmeldung nicht möglich');
        }

        return $gruende;
    }

    /** @return array{code:string, text:string, aktion:?string, knopf:?string, employee_id:?int} */
    private static function chip(string $code, string $text, ?int $employeeId = null): array
    {
        return ['code' => $code, 'text' => $text, 'aktion' => self::AKTION[$code], 'knopf' => self::KNOPF[$code], 'employee_id' => $employeeId];
    }
}
```

- [ ] **Step 9: Tests laufen lassen — grün, dann die betroffenen Bestandstests**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'PortalAuthBremseTest|PortalEntsperrungTest|BoteTest|EinladungsRiegelTest|KontoStatusTest|SperrGruendeTest|PortalAuthTest|PortalAuthKontoTest|KontoEinladenTest|EinsatzPruefungTest|EinsatzPruefungVertragTest|EmployeePortal'`
Expected: PASS (reine Umzüge; die Sperr-Werte bleiben truthy: `PortalAuthTest:183` und `PortalAuthKontoTest:435` prüfen `(bool)`).

- [ ] **Step 10: Commit**

```bash
git add src/Services/PortalAuth.php src/Services/PortalEntsperrung.php src/Support/EinladungsRiegel.php src/Support/Bote.php src/Support/KontoStatus.php src/Support/SperrGruende.php src/Console/Commands/KontoEinladen.php src/Console/Commands/EinsatzPruefung.php tests/Integration/PortalAuthBremseTest.php tests/Integration/PortalEntsperrungTest.php tests/Integration/BoteTest.php tests/Unit/EinladungsRiegelTest.php tests/Unit/KontoStatusTest.php tests/Unit/SperrGruendeTest.php
git commit -m "$(cat <<'EOF'
refactor(recruiting): Drossel lesbar, PortalEntsperrung, EinladungsRiegel, Bote, KontoStatus, SperrGruende

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 5: `HrKontoAktionen` — der eine Schreib-Eingang (Scope, Notiz, Schreiber/Sender, Vermerk, Log)

**Files:**
- Create: `src/Support/HrAktionErgebnis.php`
- Create: `src/Services/HrKontoAktionen.php`
- Test: `tests/Integration/HrKontoAktionenTest.php`, `tests/Integration/HrErinnernTest.php`

**Interfaces:**
- Consumes: `RecHrVermerk::anfuegen()`, `HrEinstellungen::kontaktiertRuheTage()` (Task 1); `PortalAuth::sessionKey()`, `sitzungGueltig()` (Task 2); `KontoWriter::stoppeNummerwechsel|gibNummernwechselFrei|setzeKontoZurueckDurchHr|entsperreKontoDurchHr|hebeNotfallSperreAuf|ladeEin`, `NummernwechselTafel::kurz` (Task 3); `PortalAuth::gebremsteNummern|loeseNummernBremse`, `PortalEntsperrung::fuerAnstellung`, `EinladungsRiegel::fuerPerson`, `Bote::fuer`, `EinsatzPruefung::LAUF_SPERRE` (Task 4); bestehend: `AufgabenSender::sende()/letzteNachrichtId()/STATUS_*`, `OffenePunkte::fuer()`, `TriggerRegeln::signatur()`, `ProofReminderSender::send()`, `DokumentService::erneutSenden()`, `VertragHinweisSender::sende()`, `VertragsHerkunft::ausAkte()`, `NummernwechselHinweisSender::sende()`, `NummernSchwaerzung::anwenden()`, `EinsatzBezug::PAUSE_TAGE`.
- Produces:
  - `HrAktionErgebnis` mit `public readonly string $stufe` (`OK='ok'|HINWEIS='hinweis'|FEHLER='fehler'`), `public readonly string $meldung`, `public readonly ?string $einladungsLink`, `public readonly ?string $einladungsCode`; Fabriken `ok()`, `hinweis()`, `fehler()`, `einladung(string $meldung, string $link, string $code)`; `istOk(): bool`.
  - `HrKontoAktionen::__construct(?PortalAuth $auth = null)`; Konstanten `NOTIZ_MIN=5`, `NOTIZ_MAX=500`, `NICHT_GEFUNDEN='Nicht gefunden.'`, `BEZUG_AUFGABEN='aufgaben'`.
  - statisch: `notizFehler(string $notiz, bool $pflicht = true): ?string`, `personImTeam(int $teamId, ?int $personId): ?object`, `akteImTeam(int $teamId, ?int $employeeId): ?RecEmployee`, `sichtbar(int $teamId, ?int $personId, ?int $employeeId): bool`, `pauseBis(?string $stempel): ?\Carbon\Carbon`, `statusText(string $status): string`.
  - Aktionen (alle → `HrAktionErgebnis`): `stoppeAntrag(int $personId, string $notiz)`, `gibAntragFrei(int $personId, string $erwartetBeantragtAt, string $notiz)`, `entsperreKonto(int $personId, string $notiz)`, `entsperrePortal(int $employeeId, string $notiz)`, `loeseBremse(int $personId, string $notiz)`, `hebeNotfallSperreAuf(int $personId, string $notiz)`, `ladeNeuEin(int $employeeId, string $notiz)`, `setzeKontoZurueck(int $employeeId, string $notiz)`, `kontaktiert(?int $personId, ?int $employeeId, string $notiz)`, `erinnereErneut(?int $personId, ?int $employeeId, string $bezug, string $notiz = '')`.
  - Log-Zeile `recruiting.hr.konto_aktion` mit genau `team_id, person_id, employee_id, art, user_id, ergebnis`.

- [ ] **Step 1: Failing tests schreiben — Konto- und Sperraktionen** (`tests/Integration/HrKontoAktionenTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class HrKontoAktionenTest extends TestCase
{
    use HrBereichHarness;

    private const ALT = '+4915111111111';
    private const NEU = '+4917098765432';
    private const NOTIZ = 'Mit der Person telefoniert.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(DispoIdentityResolver::class);
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function aktionen(): HrKontoAktionen
    {
        return new HrKontoAktionen($this->portalAuth());
    }

    /** @return array{0:int, 1:\Platform\Recruiting\Models\RecEmployee, 2:\Platform\Recruiting\Models\RecEmployee} */
    private function menschMitAntrag(int $team = 7, string $wirksam = '2026-10-13 09:00:00'): array
    {
        $this->team = $team;
        $ma = $this->akte(['phone' => self::ALT, 'team_id' => $team]);
        $rg = $this->akte(['phone' => self::ALT, 'team_id' => $team, 'company' => 'RG']);
        $id = $this->person([
            'team_id' => $team, 'phone' => self::ALT,
            'wechsel_neue_nummer' => self::NEU, 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => $wirksam, 'wechsel_quelle' => 'notfall',
        ], $ma, $rg);
        $this->team = 7;

        return [$id, $ma, $rg];
    }

    /** Review Focus 2 (Dienst-Ebene): jede Aktion mit Person/Akte aus Team 8. */
    public function test_fremdes_team_wird_bei_jeder_aktion_abgewiesen(): void
    {
        [$fremd, $fremdeAkte] = $this->menschMitAntrag(8);
        DB::table('rec_persons')->where('id', $fremd)->update(['locked_at' => now(), 'notfall_gesperrt_bis' => '2026-10-19 00:00:00']);
        DB::table('rec_employees')->where('id', $fremdeAkte->id)->update(['portal_locked_at' => now()]);
        $a = $this->aktionen();

        foreach ([
            $a->stoppeAntrag($fremd, self::NOTIZ),
            $a->gibAntragFrei($fremd, '2026-10-12 09:00:00', self::NOTIZ),
            $a->entsperreKonto($fremd, self::NOTIZ),
            $a->loeseBremse($fremd, self::NOTIZ),
            $a->hebeNotfallSperreAuf($fremd, self::NOTIZ),
            $a->entsperrePortal($fremdeAkte->id, self::NOTIZ),
            $a->ladeNeuEin($fremdeAkte->id, self::NOTIZ),
            $a->setzeKontoZurueck($fremdeAkte->id, self::NOTIZ),
            $a->kontaktiert($fremd, null, self::NOTIZ),
            $a->kontaktiert(null, $fremdeAkte->id, self::NOTIZ),
            $a->erinnereErneut($fremd, null, 'aufgaben'),
        ] as $nr => $ergebnis) {
            $this->assertSame(HrAktionErgebnis::FEHLER, $ergebnis->stufe, "Aktion {$nr}");
            $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $ergebnis->meldung, "Aktion {$nr}: keine Unterscheidung fremd/nicht vorhanden");
        }
        $zeile = $this->personZeile($fremd);
        $this->assertSame(self::NEU, $zeile->wechsel_neue_nummer);
        $this->assertNotNull($zeile->locked_at);
        $this->assertNotNull(DB::table('rec_employees')->where('id', $fremdeAkte->id)->value('portal_locked_at'));
        $this->assertCount(0, $this->vermerke());
    }

    public function test_person_im_eigenen_team_aber_ohne_akte_im_team_ist_nicht_gefunden(): void
    {
        $akteAnderswo = $this->akte(['team_id' => 8, 'phone' => self::ALT]);
        $id = $this->person(['team_id' => 7, 'phone' => self::ALT, 'locked_at' => now()], $akteAnderswo);

        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->entsperreKonto($id, self::NOTIZ)->meldung);
    }

    public function test_notiz_pflicht_5_bis_500_zeichen(): void
    {
        [$id] = $this->menschMitAntrag();
        foreach (['', '    ', 'kurz', str_repeat('x', 501)] as $notiz) {
            $e = $this->aktionen()->stoppeAntrag($id, $notiz);
            $this->assertSame(HrAktionErgebnis::FEHLER, $e->stufe, var_export($notiz, true));
        }
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer);
        $this->assertCount(0, $this->vermerke());
        $this->assertTrue($this->aktionen()->stoppeAntrag($id, '  12345  ')->istOk(), 'genau 5 nach trim');
    }

    public function test_stoppen_sperrt_den_notfallweg_vermerkt_und_laesst_rec_employees_in_ruhe(): void
    {
        [$id] = $this->menschMitAntrag();
        $vorher = DB::table('rec_employees')->pluck('updated_at', 'id')->all();

        $e = $this->aktionen()->stoppeAntrag($id, self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertSame('Antrag gestoppt. Die Nummer bleibt …1111. Der Notfallweg ist für diese Person bis 19.10.2026 gesperrt.', $e->meldung);
        $this->assertSame('2026-10-19 10:00:00', (string) $this->personZeile($id)->notfall_gesperrt_bis);
        $v = $this->vermerke()->sole();
        $this->assertSame(['antrag_gestoppt', 'nummernwechsel', self::NOTIZ, 42, 'ok'], [$v->art, $v->bezug, $v->notiz, (int) $v->user_id, $v->ergebnis]);
        $this->assertSame($vorher, DB::table('rec_employees')->pluck('updated_at', 'id')->all());
        $log = $this->logZeilen('recruiting.hr.konto_aktion')[0]['daten'];
        $this->assertSame(['team_id', 'person_id', 'employee_id', 'art', 'user_id', 'ergebnis'], array_keys($log));
        $this->assertStringNotContainsString(self::NOTIZ, json_encode($this->log->zeilen), 'Notiz nie ins Log');
    }

    /** Review Focus 1: der Stundenlauf war schneller. */
    public function test_stopp_nach_angewendetem_antrag_meldet_schon_erledigt_ohne_vermerk(): void
    {
        [$id] = $this->menschMitAntrag(7, '2026-10-12 08:00:00');
        KontoWriter::wendeNummernwechselAn($id);

        $e = $this->aktionen()->stoppeAntrag($id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertSame('Der Antrag war schon erledigt (angewendet, freigegeben oder gestoppt). Die Nummer steht jetzt auf …5432.', $e->meldung);
        $this->assertCount(0, $this->vermerke(), 'Vermerk nur bei Wirkung');
        $this->assertNull($this->personZeile($id)->notfall_gesperrt_bis);
    }

    public function test_doppelklick_auf_stoppen_ein_vermerk(): void
    {
        [$id] = $this->menschMitAntrag();
        $this->aktionen()->stoppeAntrag($id, self::NOTIZ);
        $zweiter = $this->aktionen()->stoppeAntrag($id, self::NOTIZ);

        $this->assertSame(HrAktionErgebnis::HINWEIS, $zweiter->stufe);
        $this->assertCount(1, $this->vermerke());
    }

    public function test_freigeben_wechselt_nachzug_hinweis_vermerk(): void
    {
        [$id, $ma, $rg] = $this->menschMitAntrag();

        $e = $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertStringContainsString('…5432', $e->meldung);
        $this->assertSame(self::NEU, $this->personZeile($id)->phone);
        $this->assertSame([self::NEU], DB::table('rec_employees')->whereIn('id', [$ma->id, $rg->id])->distinct()->pluck('phone')->all());
        $this->assertCount(2, $this->crmSync->nachgezogen);
        $this->assertSame([['person' => $id, 'alt' => self::ALT]], $this->hinweis->gesendet);
        $this->assertNull($this->personZeile($id)->wechsel_neue_nummer);
        $this->assertSame(['antrag_freigegeben', 'sent'], [$this->vermerke()->sole()->art, $this->vermerke()->sole()->ergebnis]);
    }

    /** Review Focus 1: Freigabe, dann der Stundenlauf. */
    public function test_freigabe_dann_stundenlauf_kein_zweiter_wechsel_kein_zweiter_hinweis(): void
    {
        [$id] = $this->menschMitAntrag(7, '2026-10-12 08:00:00');
        $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);

        $this->assertNull(KontoWriter::wendeNummernwechselAn($id));
        $this->assertCount(1, $this->hinweis->gesendet);
        $this->assertCount(1, $this->logZeilen('recruiting.konto.nummer_gewechselt'));
    }

    public function test_freigeben_abgewiesen_bei_anderem_beantragt_at_anderer_quelle_vergebener_nummer(): void
    {
        [$id] = $this->menschMitAntrag();
        $e = $this->aktionen()->gibAntragFrei($id, '2026-10-12 08:59:59', self::NOTIZ);
        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertStringContainsString('inzwischen geändert', $e->meldung);

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'kuenftig']);
        $this->assertSame(HrAktionErgebnis::FEHLER, $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ)->stufe);

        DB::table('rec_persons')->where('id', $id)->update(['wechsel_quelle' => 'notfall']);
        $this->person(['phone' => self::NEU], $this->akte(['phone' => self::NEU]));
        $vergeben = $this->aktionen()->gibAntragFrei($id, '2026-10-12 09:00:00', self::NOTIZ);
        $this->assertSame(HrAktionErgebnis::FEHLER, $vergeben->stufe);
        $this->assertStringNotContainsString(self::NEU, $vergeben->meldung, 'keine volle Nummer');
        $this->assertStringContainsString('…5432', $vergeben->meldung);
        $this->assertSame(self::NEU, $this->personZeile($id)->wechsel_neue_nummer, 'Antrag bleibt');
        $this->assertCount(0, $this->vermerke());
        $this->assertStringNotContainsString(self::NEU, json_encode($this->log->zeilen), 'auch im Log keine volle Nummer');
    }

    public function test_einladung_neu_zeigt_link_und_code_nur_im_ergebnis(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $id = $this->person([], $ma);

        $erste = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);
        $zweite = $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ);

        $this->assertTrue($zweite->istOk());
        $this->assertSame(8, strlen((string) $zweite->einladungsCode));
        $this->assertStringContainsString((string) $zweite->einladungsCode, (string) $zweite->einladungsLink);
        $this->assertNull(KontoWriter::personFuerEinladung((string) $erste->einladungsCode), 'die zweite entwertet die erste');
        $this->assertSame($id, KontoWriter::personFuerEinladung((string) $zweite->einladungsCode));
        $this->assertSame(['einladung_neu', 'einladung_neu'], $this->vermerke()->pluck('art')->all(), 'zwei Klicks, zwei Vermerke (gewollt)');
    }

    public function test_einladungs_klartext_steht_in_keinem_log_und_keinem_vermerk(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $this->person([], $ma);

        $code = (string) $this->aktionen()->ladeNeuEin($ma->id, self::NOTIZ)->einladungsCode;

        $this->assertNotSame('', $code);
        $this->assertStringNotContainsString($code, json_encode($this->log->zeilen));
        $this->assertStringNotContainsString($code, json_encode($this->vermerke()->all()));
    }

    public function test_einladung_und_zuruecksetzen_mit_riegel_und_passenden_zustaenden(): void
    {
        $ohneDatum = $this->akte(['phone' => self::ALT, 'birth_date' => null]);
        $this->person([], $ohneDatum);
        $e = $this->aktionen()->ladeNeuEin($ohneDatum->id, self::NOTIZ);
        $this->assertSame(HrAktionErgebnis::FEHLER, $e->stufe);
        $this->assertStringContainsString('Geburtsdatum', $e->meldung);

        $ohnePerson = $this->akte(['phone' => '+4915122222222']);
        $this->assertStringContainsString('keine Personen-Zeile', $this->aktionen()->ladeNeuEin($ohnePerson->id, self::NOTIZ)->meldung);

        $registriert = $this->akte(['phone' => '+4915133333333', 'birth_date' => '1990-05-17']);
        $this->person(['password_hash' => Hash::make('x-geheim-2026'), 'registered_at' => now()], $registriert);
        $this->assertStringContainsString('Konto zurücksetzen', $this->aktionen()->ladeNeuEin($registriert->id, self::NOTIZ)->meldung);

        $nichtRegistriert = $this->akte(['phone' => '+4915144444444', 'birth_date' => '1990-05-17']);
        $this->person([], $nichtRegistriert);
        $this->assertStringContainsString('Einladung neu', $this->aktionen()->setzeKontoZurueck($nichtRegistriert->id, self::NOTIZ)->meldung);
        $this->assertCount(0, $this->vermerke());
    }

    /** Review Focus 3 (Dienst-Ebene). */
    public function test_zuruecksetzen_beendet_laufende_sitzungen_aller_akten(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17']);
        $rg = $this->akte(['phone' => self::ALT, 'birth_date' => '1990-05-17', 'company' => 'RG']);
        $id = $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-01 10:00:00'], $ma, $rg);
        $this->sitzung->put(PortalAuth::sessionKey($ma->id), true);
        $this->sitzung->put(PortalAuth::sessionKey($rg->id), '2026-10-12T09:59:00+00:00');

        $e = $this->aktionen()->setzeKontoZurueck($ma->id, self::NOTIZ);

        $this->assertTrue($e->istOk());
        $this->assertNotNull($e->einladungsCode);
        $this->assertNull(KontoWriter::pruefeAnmeldung(null, self::ALT, 'ganz-geheim-2026'));
        $this->assertFalse(PortalAuth::sitzungGueltig($ma->id), 'Altwert true stirbt');
        $this->assertFalse(PortalAuth::sitzungGueltig($rg->id), 'Schwesterakte stirbt mit');
        $this->assertSame('konto_zurueckgesetzt', $this->vermerke()->sole()->art);
        $this->assertSame($ma->id, (int) $this->vermerke()->sole()->rec_employee_id, 'Akte, aus der gehandelt wurde');
        $this->assertSame($id, (int) $this->vermerke()->sole()->rec_person_id);
    }

    public function test_entsperren_konto_bremse_notfallsperre_portal_je_einmal_wirksam(): void
    {
        $ma = $this->akte(['phone' => self::ALT, 'portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: x']);
        $id = $this->person(['locked_at' => now(), 'notfall_gesperrt_bis' => '2026-10-19 00:00:00'], $ma);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, self::ALT, 'falsch-' . $i);
        }
        Container::getInstance()->instance(DispoIdentityResolver::class, new class extends DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
        $a = $this->aktionen();

        $this->assertTrue($a->entsperreKonto($id, self::NOTIZ)->istOk());
        $this->assertTrue($a->loeseBremse($id, self::NOTIZ)->istOk());
        $this->assertTrue($a->hebeNotfallSperreAuf($id, self::NOTIZ)->istOk());
        $this->assertTrue($a->entsperrePortal($ma->id, self::NOTIZ)->istOk());

        foreach ([$a->entsperreKonto($id, self::NOTIZ), $a->loeseBremse($id, self::NOTIZ), $a->hebeNotfallSperreAuf($id, self::NOTIZ), $a->entsperrePortal($ma->id, self::NOTIZ)] as $zweiter) {
            $this->assertSame(HrAktionErgebnis::HINWEIS, $zweiter->stufe, 'Doppelklick: keine Wirkung, kein Vermerk');
        }
        $this->assertSame(['konto_entsperrt', 'bremse_geloest', 'notfallsperre_aufgehoben', 'portal_entsperrt'], $this->vermerke()->pluck('art')->all());
        $this->assertSame([], $this->portalAuth()->gebremsteNummern([self::ALT]));
        $this->assertNotNull($this->personZeile($id)->sitzungen_ungueltig_ab, 'Notfallsperre aufheben beendet Sitzungen (Spec §9.6)');
        $this->assertNull(DB::table('rec_employees')->where('id', $ma->id)->value('zas_changed_at'));
    }

    public function test_kontaktiert_ruht_n_tage_aus_der_einstellung(): void
    {
        $ma = $this->akte();
        $id = $this->person([], $ma);
        $ohnePerson = $this->akte(['phone' => '+4915155555555']);

        $this->assertTrue($this->aktionen()->kontaktiert($id, null, self::NOTIZ)->istOk());
        $this->einstellung('hr_kontaktiert_ruhe_tage', 5);
        $this->assertTrue($this->aktionen()->kontaktiert(null, $ohnePerson->id, self::NOTIZ)->istOk());

        [$erster, $zweiter] = $this->vermerke()->all();
        $this->assertSame('2026-10-15 10:00:00', (string) $erster->ruhe_bis);
        $this->assertSame('2026-10-17 10:00:00', (string) $zweiter->ruhe_bis);
        $this->assertNull($zweiter->rec_person_id);
        $this->assertSame($ohnePerson->id, (int) $zweiter->rec_employee_id);
    }

    public function test_kontaktiert_ueber_eine_akte_mit_person_landet_an_der_person(): void
    {
        $ma = $this->akte();
        $id = $this->person([], $ma);

        $this->aktionen()->kontaktiert(null, $ma->id, self::NOTIZ);

        $this->assertSame($id, (int) $this->vermerke()->sole()->rec_person_id, 'ein Mensch = eine Zeile');
    }
}
```

- [ ] **Step 2: Failing tests schreiben — „Erneut erinnern“** (`tests/Integration/HrErinnernTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Services\ProofReminderSender;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class HrErinnernTest extends TestCase
{
    use HrBereichHarness;

    private object $aufgaben;
    private object $nachweis;
    private object $dokument;
    private object $vertrag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $c = Container::getInstance();

        $this->aufgaben = new class {
            public array $versandt = [];
            public string $status = AufgabenSender::STATUS_SENT;
            public function sende(RecEmployee $e, array $stand, string $anlass): string
            {
                $this->versandt[] = ['ma' => (int) $e->id, 'anlass' => $anlass];

                return $this->status;
            }
            public function letzteNachrichtId(): ?int { return 77; }
        };
        $c->instance(AufgabenSender::class, $this->aufgaben);

        $c->instance(OffenePunkte::class, new class extends OffenePunkte {
            public array $punkte = [['code' => 'ausweis', 'label' => 'Ausweis', 'status' => 'fehlt', 'ko' => true]];
            public function fuer(RecEmployee $employee, ?string $heute = null): array
            {
                return ['punkte' => $this->punkte, 'einsatz' => null, 'gesperrt' => false];
            }
        });

        $this->nachweis = new class {
            public array $gesendet = [];
            public string $status = ProofReminderSender::STATUS_SENT;
            public function send(RecEmployee $ma, array $eintrag): array
            {
                $this->gesendet[] = $eintrag;

                return ['status' => $this->status, 'error' => null];
            }
        };
        $c->instance(ProofReminderSender::class, $this->nachweis);

        $this->dokument = new class extends DokumentService {
            public array $gesendet = [];
            public function erneutSenden(RecDocumentRecipient $empfaenger): string
            {
                $this->gesendet[] = (int) $empfaenger->id;
                DB::table('rec_document_recipients')->where('id', $empfaenger->id)->update(['notified_at' => now()]);

                return 'sent';
            }
        };
        $c->instance(DokumentService::class, $this->dokument);

        $this->vertrag = new class extends VertragHinweisSender {
            public array $gesendet = [];
            public function sende(RecEmployee $employee): string
            {
                $this->gesendet[] = (int) $employee->id;

                return 'sent';
            }
        };
        $c->instance(VertragHinweisSender::class, $this->vertrag);
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([AufgabenSender::class, OffenePunkte::class, ProofReminderSender::class, DokumentService::class, VertragHinweisSender::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function aktionen(): HrKontoAktionen
    {
        return new HrKontoAktionen($this->portalAuth());
    }

    private function erinnertePerson(string $gemeldet = '2026-10-04 10:00:00'): int
    {
        $ma = $this->akte();

        return $this->person(['aufgaben_signatur' => 'alt', 'aufgaben_gemeldet_at' => $gemeldet], $ma);
    }

    public function test_aufgaben_ueber_den_bestehenden_sender_mit_stempel(): void
    {
        $id = $this->erinnertePerson();

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertTrue($e->istOk());
        $this->assertSame('erinnerung', $this->aufgaben->versandt[0]['anlass']);
        $zeile = $this->personZeile($id);
        $this->assertSame('2026-10-12 10:00:00', (string) $zeile->aufgaben_gemeldet_at);
        $this->assertSame(77, (int) $zeile->aufgaben_nachricht_id);
        $v = $this->vermerke()->sole();
        $this->assertSame(['erneut_erinnert', 'aufgaben', 'sent', null], [$v->art, $v->bezug, $v->ergebnis, $v->notiz], 'Notiz optional');
    }

    public function test_aufgaben_fehlschlag_stempelt_trotzdem_nicht_konfiguriert_nicht(): void
    {
        $fehl = $this->erinnertePerson();
        $this->aufgaben->status = AufgabenSender::STATUS_FAILED;
        $e = $this->aktionen()->erinnereErneut($fehl, null, 'aufgaben');
        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertSame('2026-10-12 10:00:00', (string) $this->personZeile($fehl)->aufgaben_gemeldet_at, 'ET-23: auch nach Fehlschlag');

        $ohneVorlage = $this->erinnertePerson();
        $this->aufgaben->status = AufgabenSender::STATUS_NICHT_KONFIGURIERT;
        $e = $this->aktionen()->erinnereErneut($ohneVorlage, null, 'aufgaben');
        $this->assertStringContainsString('Vorlage fehlt', $e->meldung);
        $this->assertSame('2026-10-04 10:00:00', (string) $this->personZeile($ohneVorlage)->aufgaben_gemeldet_at, 'F1: kein Stempel');
        $this->assertSame(['failed', 'nicht_konfiguriert'], $this->vermerke()->pluck('ergebnis')->all(), 'jeder Versuch im Vermerk');
    }

    public function test_doppelklick_und_pause_ein_versand(): void
    {
        $id = $this->erinnertePerson();
        $this->aktionen()->erinnereErneut($id, null, 'aufgaben');
        $zweiter = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame(HrAktionErgebnis::HINWEIS, $zweiter->stufe);
        $this->assertStringContainsString('ab 19.10.2026', $zweiter->meldung);
        $this->assertCount(1, $this->aufgaben->versandt);

        $frisch = $this->erinnertePerson('2026-10-07 10:00:00');
        $this->assertStringContainsString('ab 14.10.2026', $this->aktionen()->erinnereErneut($frisch, null, 'aufgaben')->meldung, 'Spec offene Frage 2: Pause wird respektiert');
    }

    public function test_aufgaben_respektiert_die_laufsperre_der_einsatz_pruefung(): void
    {
        $id = $this->erinnertePerson();
        $sperre = Cache::lock(EinsatzPruefung::LAUF_SPERRE, 60);
        $this->assertTrue($sperre->get());

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertSame(HrAktionErgebnis::HINWEIS, $e->stufe);
        $this->assertStringContainsString('Einsatz-Prüfung', $e->meldung);
        $this->assertCount(0, $this->aufgaben->versandt);
        $sperre->release();
    }

    public function test_ohne_neues_portal_kein_versand(): void
    {
        $ma = $this->akte(['portal_v2_since' => null]);
        $id = $this->person(['aufgaben_signatur' => 'alt', 'aufgaben_gemeldet_at' => '2026-10-04 10:00:00'], $ma);

        $e = $this->aktionen()->erinnereErneut($id, null, 'aufgaben');

        $this->assertStringContainsString('nicht im neuen Portal', $e->meldung);
        $this->assertCount(0, $this->aufgaben->versandt);
    }

    public function test_nachweis_stempelt_nur_nach_erfolg(): void
    {
        $ma = $this->akte();
        $proof = (int) DB::table('rec_employee_proofs')->insertGetId([
            'uuid' => 'pr-1', 'team_id' => $this->team, 'rec_employee_id' => $ma->id, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-20', 'version' => 1, 'reminded_at' => '2026-10-01 10:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->nachweis->status = ProofReminderSender::STATUS_NOT_CONFIGURED;
        $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof)->stufe);
        $this->assertSame('2026-10-01 10:00:00', (string) DB::table('rec_employee_proofs')->where('id', $proof)->value('reminded_at'));

        $this->nachweis->status = ProofReminderSender::STATUS_SENT;
        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'nachweis:' . $proof)->istOk());
        $this->assertSame('2026-10-12 10:00:00', (string) DB::table('rec_employee_proofs')->where('id', $proof)->value('reminded_at'));
        $this->assertSame(['proof_id' => $proof, 'rec_employee_id' => $ma->id, 'code' => 'ausweis', 'valid_until' => '2026-10-20'], $this->nachweis->gesendet[1]);
    }

    public function test_dokument_und_vertrag_ueber_ihre_sender_mit_pause(): void
    {
        $ma = $this->akte();
        $this->person([], $ma);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-1', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'sign',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        $empf = (int) DB::table('rec_document_recipients')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'r-1', 'rec_document_id' => $dok, 'rec_employee_id' => $ma->id, 'notified_at' => '2026-10-01 10:00:00',
        ]);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'dokument:' . $empf)->istOk());
        $this->assertTrue($this->aktionen()->erinnereErneut(null, $ma->id, 'vertrag:' . $v->id)->istOk());
        $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, 'dokument:' . $empf)->stufe);
        $this->assertSame(HrAktionErgebnis::HINWEIS, $this->aktionen()->erinnereErneut(null, $ma->id, 'vertrag:' . $v->id)->stufe, 'Pause ab letztem erneut_erinnert');
        $this->assertSame([$empf], $this->dokument->gesendet);
        $this->assertSame([$ma->id], $this->vertrag->gesendet);
    }

    public function test_bezug_aus_fremdem_team_und_unsinniger_bezug_sind_nicht_gefunden(): void
    {
        $fremd = $this->akte(['team_id' => 8]);
        $proof = (int) DB::table('rec_employee_proofs')->insertGetId([
            'uuid' => 'pr-f', 'team_id' => 8, 'rec_employee_id' => $fremd->id, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-20', 'version' => 1, 'reminded_at' => '2026-10-01 10:00:00',
        ]);

        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->erinnereErneut(null, $fremd->id, 'nachweis:' . $proof)->meldung);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $this->aktionen()->erinnereErneut(null, null, 'irgendwas:1')->meldung);
        $this->assertSame([], $this->nachweis->gesendet);
    }
}
```

- [ ] **Step 3: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'HrKontoAktionenTest|HrErinnernTest'`
Expected: FAIL — `Class "Platform\Recruiting\Services\HrKontoAktionen" not found`.

- [ ] **Step 4: `HrAktionErgebnis` schreiben** (`src/Support/HrAktionErgebnis.php`)

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Ergebnis einer HR-Aktion fuer die Oberflaeche: gruen (ok), gelb (hinweis:
 * nichts kaputt, aber auch keine Wirkung — schon erledigt, Pause, Vorlage
 * fehlt) oder rot (fehler: abgewiesen). Der Einladungs-Klartext reist NUR
 * hier mit und nirgends sonst hin (Spec §4.3).
 */
final class HrAktionErgebnis
{
    public const OK = 'ok';
    public const HINWEIS = 'hinweis';
    public const FEHLER = 'fehler';

    private function __construct(
        public readonly string $stufe,
        public readonly string $meldung,
        public readonly ?string $einladungsLink = null,
        public readonly ?string $einladungsCode = null,
    ) {
    }

    public static function ok(string $meldung): self
    {
        return new self(self::OK, $meldung);
    }

    public static function hinweis(string $meldung): self
    {
        return new self(self::HINWEIS, $meldung);
    }

    public static function fehler(string $meldung): self
    {
        return new self(self::FEHLER, $meldung);
    }

    public static function einladung(string $meldung, string $link, string $code): self
    {
        return new self(self::OK, $meldung, $link, $code);
    }

    public function istOk(): bool
    {
        return $this->stufe === self::OK;
    }
}
```

- [ ] **Step 5: `HrKontoAktionen` schreiben** (`src/Services/HrKontoAktionen.php`)

```php
<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Support\Bote;
use Platform\Recruiting\Support\EinladungsRiegel;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\HrEinstellungen;
use Platform\Recruiting\Support\NummernSchwaerzung;
use Platform\Recruiting\Support\NummernwechselTafel;
use Platform\Recruiting\Support\TriggerRegeln;
use Platform\Recruiting\Support\VertragsHerkunft;

/**
 * Der EINE Schreib-Eingang des HR-Bereichs (Spec 2026-10-12 §5.5) — fuer
 * Schreibtisch und Akte dieselbe Stelle. Jede Aktion:
 *  1. Team aus Auth::user()->currentTeam (nie aus dem Request),
 *  2. Person/Akte im Team laden — sonst "Nicht gefunden." (keine
 *     Unterscheidung fremd/nicht vorhanden),
 *  3. Notiz pruefen (5–500 Zeichen, getrimmt),
 *  4. zustaendigen Schreiber bzw. Sender rufen,
 *  5. NUR bei Wirkung: Vermerk + Log-Zeile recruiting.hr.konto_aktion (ohne
 *     Notiz, ohne Nummer, ohne Einladungs-Klartext).
 *
 * Sender werden untypisiert ueber app() geholt (einige sind final; Tests
 * binden Attrappen). Schreibzugriffe an rec_employees nur per Query Builder.
 */
final class HrKontoAktionen
{
    public const NOTIZ_MIN = 5;
    public const NOTIZ_MAX = 500;
    public const NICHT_GEFUNDEN = 'Nicht gefunden.';
    public const BEZUG_AUFGABEN = 'aufgaben';

    /** HR-taugliche Texte zu Sender-Status (Spec §3.3: Fehlschlag gelb mit Grund). */
    private const STATUS_TEXT = [
        'nicht_konfiguriert'           => 'Vorlage fehlt',
        'not_configured'               => 'Vorlage fehlt',
        'vorlage_untauglich'           => 'Vorlage passt nicht',
        'template_without_url_button'  => 'Vorlage passt nicht (kein Link-Knopf)',
        'template_unknown_placeholder' => 'Vorlage passt nicht (unbekannter Platzhalter)',
        'no_phone'                     => 'keine Nummer',
        'failed'                       => 'Versand abgelehnt',
        'altes_portal'                 => 'nicht im neuen Portal',
        'inaktiv'                      => 'Akte nicht aktiv',
        'erledigt'                     => 'schon erledigt',
        'zurueckgezogen'               => 'zurückgezogen',
        'nur_ablegen'                  => 'nur zur Ablage, keine Handlung nötig',
    ];

    private const RIEGEL_TEXT = [
        EinladungsRiegel::KEINE_NUMMER      => 'Keine Handynummer hinterlegt — ohne Nummer gibt es keinen Weg ins Konto.',
        EinladungsRiegel::KEIN_GEBURTSDATUM => 'Kein Geburtsdatum hinterlegt — ohne Geburtsdatum scheitert die Registrierung. Bitte erst in der Akte nachtragen.',
        EinladungsRiegel::KEIN_WHATSAPP     => 'Die Nummer ist als „kein WhatsApp“ bekannt — die Einladung käme nicht an.',
    ];

    public function __construct(private readonly ?PortalAuth $auth = null) {}

    // ------------------------------------------------------------ Scope

    public static function notizFehler(string $notiz, bool $pflicht = true): ?string
    {
        $laenge = mb_strlen(trim($notiz));
        if ($laenge === 0 && !$pflicht) {
            return null;
        }
        if ($laenge < self::NOTIZ_MIN) {
            return 'Bitte eine Notiz mit mindestens 5 Zeichen eingeben — sie steht im Verlauf.';
        }
        if ($laenge > self::NOTIZ_MAX) {
            return 'Die Notiz ist zu lang (höchstens 500 Zeichen).';
        }

        return null;
    }

    /**
     * Person im Team: rec_persons.team_id = Team UND eine Akte im Team — an
     * der Person selbst oder (stillgelegte Zeile) an der Zielzeile.
     */
    public static function personImTeam(int $teamId, ?int $personId): ?object
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }

        return DB::table('rec_persons')
            ->where('id', $personId)
            ->where('team_id', $teamId)
            ->where(function ($q) use ($teamId) {
                $q->whereExists(fn ($e) => $e->selectRaw('1')->from('rec_employees')
                    ->whereColumn('rec_employees.rec_person_id', 'rec_persons.id')
                    ->where('rec_employees.team_id', $teamId))
                  ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('rec_employees')
                    ->whereColumn('rec_employees.rec_person_id', 'rec_persons.merged_into_person_id')
                    ->where('rec_employees.team_id', $teamId));
            })
            ->first();
    }

    public static function akteImTeam(int $teamId, ?int $employeeId): ?RecEmployee
    {
        if ($employeeId === null || $employeeId <= 0) {
            return null;
        }

        return RecEmployee::query()->where('team_id', $teamId)->find($employeeId);
    }

    public static function sichtbar(int $teamId, ?int $personId, ?int $employeeId): bool
    {
        return $personId !== null
            ? self::personImTeam($teamId, $personId) !== null
            : self::akteImTeam($teamId, $employeeId) !== null;
    }

    /** Ende der 7-Tage-Pause ab einem Stempel (EinsatzBezug::PAUSE_TAGE) — oder null ohne Stempel. */
    public static function pauseBis(?string $stempel): ?Carbon
    {
        return $stempel === null || $stempel === '' ? null : Carbon::parse($stempel)->addDays(EinsatzBezug::PAUSE_TAGE);
    }

    public static function statusText(string $status): string
    {
        return self::STATUS_TEXT[$status] ?? $status;
    }

    // ------------------------------------------------------------ Konto-Antraege

    public function stoppeAntrag(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if (self::personImTeam($teamId, $personId) === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }

        if (!KontoWriter::stoppeNummerwechsel($personId)) {
            $jetzt = (string) DB::table('rec_persons')->where('id', $personId)->value('phone');

            return HrAktionErgebnis::hinweis(sprintf(
                'Der Antrag war schon erledigt (angewendet, freigegeben oder gestoppt). Die Nummer steht jetzt auf …%s.',
                NummernwechselTafel::kurz($jetzt),
            ));
        }

        $zeile = DB::table('rec_persons')->where('id', $personId)->first(['phone', 'notfall_gesperrt_bis']);
        $this->vermerk($teamId, $personId, null, 'antrag_gestoppt', $notiz, 'nummernwechsel', 'ok');

        return HrAktionErgebnis::ok(sprintf(
            'Antrag gestoppt. Die Nummer bleibt …%s. Der Notfallweg ist für diese Person bis %s gesperrt.',
            NummernwechselTafel::kurz((string) $zeile->phone),
            Carbon::parse((string) $zeile->notfall_gesperrt_bis)->format('d.m.Y'),
        ));
    }

    public function gibAntragFrei(int $personId, string $erwartetBeantragtAt, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $person = self::personImTeam($teamId, $personId);
        if ($person === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($person->wechsel_neue_nummer !== null && $person->wechsel_quelle !== KontoWriter::ZWECK_NOTFALL) {
            return HrAktionErgebnis::fehler('Freigeben gibt es nur für Notfall-Anträge („Nummer und Passwort verloren“). Diesen Antrag können Sie nur stoppen.');
        }
        $neuKurz = NummernwechselTafel::kurz((string) $person->wechsel_neue_nummer);

        try {
            $ergebnis = KontoWriter::gibNummernwechselFrei($personId, $erwartetBeantragtAt);
        } catch (\Throwable $e) {
            Log::warning('recruiting.konto.hr_nummernwechsel_fehler', [
                'person_id'        => $personId,
                'nummer_endet_auf' => $neuKurz,
                'fehler'           => NummernSchwaerzung::anwenden($e->getMessage()),
            ]);

            return HrAktionErgebnis::fehler(sprintf(
                'Die Nummer …%s ließ sich nicht setzen — sie gehört im Team vermutlich schon einer anderen Akte. Der Antrag bleibt stehen; bitte erst die andere Akte klären.',
                $neuKurz,
            ));
        }

        if ($ergebnis === null) {
            return HrAktionErgebnis::hinweis('Der Antrag hat sich inzwischen geändert (angewendet, gestoppt, gesperrt oder neu gestellt). Bitte die Liste neu ansehen.');
        }

        $hinweis = $ergebnis['alt'] !== null
            ? (string) app(NummernwechselHinweisSender::class)->sende($personId, $ergebnis['alt'])
            : 'keine_alte_nummer';
        $this->vermerk($teamId, $personId, null, 'antrag_freigegeben', $notiz, 'nummernwechsel', $hinweis);

        return HrAktionErgebnis::ok(sprintf(
            'Freigegeben. Die Nummer steht jetzt auf …%s. Der Mensch kann jetzt mit „Passwort vergessen“ ein neues Passwort setzen.%s',
            NummernwechselTafel::kurz($ergebnis['neu']),
            $hinweis === NummernwechselHinweisSender::STATUS_SENT ? '' : ' Der Hinweis an die alte Nummer ging nicht raus.',
        ));
    }

    // ------------------------------------------------------------ Sperren

    public function entsperreKonto(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if (self::personImTeam($teamId, $personId) === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if (!KontoWriter::entsperreKontoDurchHr($personId)) {
            return HrAktionErgebnis::hinweis('Das Konto war nicht (mehr) gesperrt.');
        }
        $this->vermerk($teamId, $personId, null, 'konto_entsperrt', $notiz, null, 'ok');

        return HrAktionErgebnis::ok('Konto entsperrt.');
    }

    public function loeseBremse(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $person = self::personImTeam($teamId, $personId);
        if ($person === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        $nummer = (string) $person->phone;
        if ($nummer === '' || $this->portalAuth()->gebremsteNummern([$nummer]) === []) {
            return HrAktionErgebnis::hinweis('Die Anmeldung war nicht (mehr) gebremst.');
        }
        $this->portalAuth()->loeseNummernBremse($nummer);
        $this->vermerk($teamId, $personId, null, 'bremse_geloest', $notiz, null, 'ok');

        return HrAktionErgebnis::ok(sprintf('Bremse gelöst. Die Anmeldung mit …%s geht wieder.', NummernwechselTafel::kurz($nummer)));
    }

    public function hebeNotfallSperreAuf(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if (self::personImTeam($teamId, $personId) === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if (!KontoWriter::hebeNotfallSperreAuf($personId)) {
            return HrAktionErgebnis::hinweis('Der Notfallweg war nicht (mehr) gesperrt.');
        }
        // Spec §9.6: "Entsperren nach Notfallsperre" beendet laufende Sitzungen.
        $this->sitzungenBeenden($personId);
        $this->vermerk($teamId, $personId, null, 'notfallsperre_aufgehoben', $notiz, null, 'ok');

        return HrAktionErgebnis::ok('Notfallsperre aufgehoben. Laufende Portal-Anmeldungen dieser Person sind beendet.');
    }

    public function entsperrePortal(int $employeeId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $akte = self::akteImTeam($teamId, $employeeId);
        if ($akte === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        $anzahl = app(PortalEntsperrung::class)->fuerAnstellung($employeeId);
        if ($anzahl === 0) {
            return HrAktionErgebnis::hinweis('Das Portal war nicht (mehr) gesperrt.');
        }
        $personId = $akte->rec_person_id !== null ? (int) $akte->rec_person_id : null;
        $this->vermerk($teamId, $personId, $employeeId, 'portal_entsperrt', $notiz, null, (string) $anzahl);

        return HrAktionErgebnis::ok($anzahl > 1
            ? sprintf('Portal entsperrt — auch für %d verknüpfte Akte(n) derselben Person.', $anzahl - 1)
            : 'Portal entsperrt.');
    }

    // ------------------------------------------------------------ Einladung / Zuruecksetzen (aus der Akte)

    public function ladeNeuEin(int $employeeId, string $notiz): HrAktionErgebnis
    {
        return $this->einladung($employeeId, $notiz, false);
    }

    public function setzeKontoZurueck(int $employeeId, string $notiz): HrAktionErgebnis
    {
        return $this->einladung($employeeId, $notiz, true);
    }

    private function einladung(int $employeeId, string $notiz, bool $zuruecksetzen): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $akte = self::akteImTeam($teamId, $employeeId);
        if ($akte === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($akte->rec_person_id === null) {
            return HrAktionErgebnis::fehler('Diese Akte hat noch keine Personen-Zeile — Konto-Funktionen erst nach recruiting:personen-anlegen.');
        }
        $personId = (int) $akte->rec_person_id;
        $person = self::personImTeam($teamId, $personId);
        if ($person === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($person->merged_into_person_id !== null) {
            return HrAktionErgebnis::fehler('Diese Personen-Zeile ist stillgelegt — die Akten hängen an einer anderen Zeile.');
        }
        if ($person->locked_at !== null) {
            return HrAktionErgebnis::fehler('Das Konto ist gesperrt — bitte erst entsperren.');
        }
        if ($zuruecksetzen && $person->password_hash === null) {
            return HrAktionErgebnis::fehler('Das Konto ist noch nicht registriert — dafür gibt es „Einladung neu“.');
        }
        if (!$zuruecksetzen && $person->password_hash !== null) {
            return HrAktionErgebnis::fehler('Das Konto ist schon registriert — dafür gibt es „Konto zurücksetzen“.');
        }
        $grund = EinladungsRiegel::fuerPerson($personId);
        if ($grund !== null) {
            return HrAktionErgebnis::fehler(self::RIEGEL_TEXT[$grund]);
        }

        if ($zuruecksetzen) {
            $token = KontoWriter::setzeKontoZurueckDurchHr($personId);
            $this->sitzungenBeenden($personId);
            $this->vermerk($teamId, $personId, $employeeId, 'konto_zurueckgesetzt', $notiz, null, 'ok');
            $meldung = 'Konto zurückgesetzt. Das alte Passwort gilt nicht mehr, laufende Anmeldungen sind beendet.';
        } else {
            $token = KontoWriter::ladeEin($personId);
            $this->vermerk($teamId, $personId, $employeeId, 'einladung_neu', $notiz, null, 'ok');
            $meldung = 'Neue Einladung erstellt. Frühere Einladungen gelten nicht mehr.';
        }

        return HrAktionErgebnis::einladung($meldung, route('recruiting.public.konto-anlegen', ['token' => $token]), $token);
    }

    // ------------------------------------------------------------ Erinnert, aber keine Reaktion

    public function kontaktiert(?int $personId, ?int $employeeId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if ($personId !== null) {
            if (self::personImTeam($teamId, $personId) === null) {
                return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
            }
            $employeeId = null;
        } else {
            $akte = self::akteImTeam($teamId, $employeeId);
            if ($akte === null) {
                return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
            }
            if ($akte->rec_person_id !== null) {
                // Ein Mensch = eine Zeile: die Ruhe gehoert an die Person.
                $personId = (int) $akte->rec_person_id;
                $employeeId = null;
            }
        }

        $bis = now()->addDays(HrEinstellungen::kontaktiertRuheTage($teamId));
        $this->vermerk($teamId, $personId, $employeeId, 'kontaktiert', $notiz, null, 'ok', $bis);

        return HrAktionErgebnis::ok(sprintf(
            'Als kontaktiert vermerkt. Die Zeile ruht bis %s; ist danach noch etwas offen, kommt sie von selbst zurück.',
            $bis->format('d.m.Y'),
        ));
    }

    public function erinnereErneut(?int $personId, ?int $employeeId, string $bezug, string $notiz = ''): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz, false)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        [$art, $id] = array_pad(explode(':', $bezug, 2), 2, '');

        return match ($art) {
            self::BEZUG_AUFGABEN => $this->erinnereAufgaben($teamId, $personId, $notiz),
            'nachweis'           => $this->erinnereNachweis($teamId, (int) $id, $notiz),
            'dokument'           => $this->erinnereDokument($teamId, (int) $id, $notiz),
            'vertrag'            => $this->erinnereVertrag($teamId, (int) $id, $notiz),
            default              => HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN),
        };
    }

    private function erinnereAufgaben(int $teamId, ?int $personId, string $notiz): HrAktionErgebnis
    {
        $person = self::personImTeam($teamId, $personId);
        if ($person === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if (($pause = $this->inPause($person->aufgaben_gemeldet_at)) !== null) {
            return $pause;
        }
        $umfang = DB::table('rec_employees')->where('rec_person_id', $personId)->where('team_id', $teamId)
            ->where('is_active', 1)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $bote = Bote::fuer($umfang);
        if ($bote === null) {
            return HrAktionErgebnis::hinweis('Nicht verschickt: nicht im neuen Portal — die Erinnerung führt ins neue Portal.');
        }

        // Dieselbe Laufsperre wie die Einsatz-Pruefung: nie zwei Nachrichten im selben Augenblick.
        $sperre = Cache::lock(EinsatzPruefung::LAUF_SPERRE, 120);
        if (!$sperre->get()) {
            return HrAktionErgebnis::hinweis('Gerade läuft die Einsatz-Prüfung — bitte in ein paar Minuten erneut versuchen.');
        }

        try {
            $stand = app(OffenePunkte::class)->fuer($bote);
            if ($stand['punkte'] === []) {
                return HrAktionErgebnis::hinweis('Inzwischen ist nichts mehr offen — keine Erinnerung nötig.');
            }
            $sender = app(AufgabenSender::class);
            $status = (string) $sender->sende($bote, $stand, 'erinnerung');

            // F1 der Einsatz-Pruefung: ohne brauchbare Vorlage kein Stempel.
            if (!in_array($status, [AufgabenSender::STATUS_NICHT_KONFIGURIERT, AufgabenSender::STATUS_VORLAGE_UNTAUGLICH], true)) {
                $stempel = ['aufgaben_gemeldet_at' => now(), 'aufgaben_nachricht_id' => $sender->letzteNachrichtId()];
                if ($status === AufgabenSender::STATUS_SENT) {
                    $stempel['aufgaben_signatur'] = TriggerRegeln::signatur($stand['punkte']);
                }
                DB::table('rec_persons')->where('id', $personId)->update($stempel);
            }
            $this->vermerk($teamId, $personId, null, 'erneut_erinnert', $notiz, self::BEZUG_AUFGABEN, $status);

            return $this->versandErgebnis($status);
        } finally {
            $sperre->release();
        }
    }

    private function erinnereNachweis(int $teamId, int $proofId, string $notiz): HrAktionErgebnis
    {
        $zeile = DB::table('rec_employee_proofs as p')
            ->join('rec_employees as e', 'e.id', '=', 'p.rec_employee_id')
            ->where('p.id', $proofId)
            ->where('e.team_id', $teamId)
            ->whereNull('p.superseded_at')
            ->first(['p.id', 'p.rec_employee_id', 'p.proof_type_code', 'p.valid_until', 'p.reminded_at', 'e.rec_person_id', 'e.portal_v2_since']);
        if ($zeile === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($zeile->portal_v2_since === null) {
            return HrAktionErgebnis::hinweis('Nicht verschickt: nicht im neuen Portal — Nachweis-Erinnerungen gehen nur dorthin.');
        }
        if (($pause = $this->inPause($zeile->reminded_at)) !== null) {
            return $pause;
        }

        $ma = RecEmployee::find((int) $zeile->rec_employee_id);
        $r = app(ProofReminderSender::class)->send($ma, [
            'proof_id'        => (int) $zeile->id,
            'rec_employee_id' => (int) $zeile->rec_employee_id,
            'code'            => (string) $zeile->proof_type_code,
            'valid_until'     => substr((string) $zeile->valid_until, 0, 10),
        ]);
        $status = (string) $r['status'];
        if ($status === ProofReminderSender::STATUS_SENT) {
            DB::table('rec_employee_proofs')->where('id', $proofId)->update(['reminded_at' => now()]);
        }
        $this->vermerk($teamId, $zeile->rec_person_id !== null ? (int) $zeile->rec_person_id : null, (int) $zeile->rec_employee_id, 'erneut_erinnert', $notiz, 'nachweis:' . $proofId, $status);

        return $this->versandErgebnis($status);
    }

    private function erinnereDokument(int $teamId, int $recipientId, string $notiz): HrAktionErgebnis
    {
        $z = RecDocumentRecipient::query()
            ->whereKey($recipientId)
            ->whereIn('rec_employee_id', fn ($q) => $q->select('id')->from('rec_employees')->where('team_id', $teamId))
            ->first();
        if ($z === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if (($pause = $this->inPause($z->notified_at?->format('Y-m-d H:i:s'))) !== null) {
            return $pause;
        }
        $status = (string) app(DokumentService::class)->erneutSenden($z);
        $personId = DB::table('rec_employees')->where('id', $z->rec_employee_id)->value('rec_person_id');
        $this->vermerk($teamId, $personId !== null ? (int) $personId : null, (int) $z->rec_employee_id, 'erneut_erinnert', $notiz, 'dokument:' . $recipientId, $status);

        return $this->versandErgebnis($status);
    }

    private function erinnereVertrag(int $teamId, int $contractId, string $notiz): HrAktionErgebnis
    {
        $v = RecContract::query()
            ->whereKey($contractId)
            ->whereIn('rec_employee_id', fn ($q) => $q->select('id')->from('rec_employees')->where('team_id', $teamId))
            ->first();
        if ($v === null || $v->status !== 'sent' || !VertragsHerkunft::ausAkte($v)) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        $letzter = DB::table('rec_hr_vermerke')
            ->where('team_id', $teamId)->where('art', 'erneut_erinnert')
            ->where('bezug', 'vertrag:' . $contractId)->where('ergebnis', 'sent')
            ->max('created_at');
        $stempel = max((string) $v->sent_at?->format('Y-m-d H:i:s'), (string) $letzter);
        if (($pause = $this->inPause($stempel !== '' ? $stempel : null)) !== null) {
            return $pause;
        }
        $anstellung = RecEmployee::find((int) $v->rec_employee_id);
        $status = (string) app(VertragHinweisSender::class)->sende($anstellung);
        $this->vermerk($teamId, $anstellung->rec_person_id !== null ? (int) $anstellung->rec_person_id : null, (int) $anstellung->id, 'erneut_erinnert', $notiz, 'vertrag:' . $contractId, $status);

        return $this->versandErgebnis($status);
    }

    // ------------------------------------------------------------ intern

    private function inPause(?string $stempel): ?HrAktionErgebnis
    {
        $bis = self::pauseBis($stempel);
        if ($bis !== null && now()->lt($bis)) {
            return HrAktionErgebnis::hinweis(sprintf(
                'Erneut erinnern geht erst ab %s (%d Tage nach der letzten Erinnerung).',
                $bis->format('d.m.Y'),
                EinsatzBezug::PAUSE_TAGE,
            ));
        }

        return null;
    }

    private function versandErgebnis(string $status): HrAktionErgebnis
    {
        return $status === 'sent'
            ? HrAktionErgebnis::ok('Erinnerung verschickt.')
            : HrAktionErgebnis::hinweis('Nicht verschickt: ' . self::statusText($status) . '.');
    }

    private function sitzungenBeenden(int $personId): void
    {
        DB::table('rec_persons')->where('id', $personId)->update(['sitzungen_ungueltig_ab' => now()]);
    }

    private function vermerk(int $teamId, ?int $personId, ?int $employeeId, string $art, string $notiz, ?string $bezug, ?string $ergebnis, ?\DateTimeInterface $ruheBis = null): void
    {
        $userId = Auth::id();
        RecHrVermerk::anfuegen($teamId, $personId, $employeeId, $art, trim($notiz) === '' ? null : $notiz, $bezug, $ergebnis, $ruheBis, $userId !== null ? (int) $userId : null);

        Log::notice('recruiting.hr.konto_aktion', [
            'team_id'     => $teamId,
            'person_id'   => $personId,
            'employee_id' => $employeeId,
            'art'         => $art,
            'user_id'     => $userId,
            'ergebnis'    => $ergebnis,
        ]);
    }

    private function team(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    private function portalAuth(): PortalAuth
    {
        return $this->auth ?? new PortalAuth();
    }
}
```

- [ ] **Step 6: Tests laufen lassen — grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'HrKontoAktionenTest|HrErinnernTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Support/HrAktionErgebnis.php src/Services/HrKontoAktionen.php tests/Integration/HrKontoAktionenTest.php tests/Integration/HrErinnernTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): HrKontoAktionen — ein Schreib-Eingang fuer Konto, Sperre und Erinnerung

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 6: `HrMitarbeiterListen` — die vier Listen und das Abzeichen

**Files:**
- Create: `src/Services/HrMitarbeiterListen.php`
- Test: `tests/Integration/HrMitarbeiterListenTest.php`

**Interfaces:**
- Consumes: `HrEinstellungen` (1), `KontoWriter::offeneNummernwechsel()/notfallWegAn()`, `NummernwechselTafel` (3), `PortalAuth::gebremsteNummern()`, `KontoStatus`, `SperrGruende`, `Bote` (4), `HrKontoAktionen::pauseBis()` (5); bestehend `OffenePunkte::fuer()`, `EinsatzBezug::naechster()`, `DokumentKategorie::brauchtHandlung()`, `DokumentStatus::istErledigt()`, `VertragsHerkunft::ausAkte()`, `VertragLeser::anzeigename()`, `ProofTypes`, `CommsWhatsAppMessage`.
- Produces (alle Zeilen tragen die Mensch-Felder `schluessel` ('p:<id>'|'e:<id>'), `person_id` (?int), `employee_id` (int, kleinste Akte), `name`, `akten` (list<array{id:int, firma:string, aktiv:bool}>), `employee_ids` (list<int>)):
  - `HrMitarbeiterListen::__construct(?PortalAuth $auth = null, ?OffenePunkte $offenePunkte = null, ?\Closure $nachrichtGescheitert = null)`
  - `static menschen(iterable $akten): array<string, array>` — ein Mensch = eine Zeile
  - `antraege(int $teamId, string $suche = ''): list<array>` + Felder `alt, neu, beantragt_at, beantragt, wirksam, quelle, quelle_text, zustand, kann_freigeben`
  - `gestoppte(int $teamId): array{gestoppt: list<array{name, akten, am, von, notiz}>, notfallsperren: list<array{person_id, name, akten, bis}>}`
  - `gesperrt(int $teamId, string $suche = '', bool $mitBremse = true): list<array>` + Feld `gruende` (SperrGruende-Chips)
  - `sperrGruende(int $teamId, array $menschen, iterable $akten, bool $mitBremse): array<string, list<array>>`
  - `erinnert(int $teamId, string $suche = '', bool $ruhendeZeigen = false): list<array>` + Felder `chips` (list<array{quelle, bezug, titel, text, erinnert_at, alter_tage, pause_bis, kann_erinnern, grau_text, rot, tooltip}>), `naechster_einsatz` (?Y-m-d), `naechster_text`, `aeltest`, `ruhe_bis` (?string), `ruht` (bool), `ruhe_hinweis` (?string)
  - `pilot(int $teamId, string $suche = '', string $filter = 'alle'): array{zeilen: list<array>, zahlen: array<string,int>}` + Felder `status_code, status_text, filter, pilot_seit, registriert, letzte_anmeldung, altes_portal, ohne_person, link`
  - `abzeichen(int $teamId): int` — offene Anträge + Menschen mit mindestens einem Sperrgrund außer Drossel

- [ ] **Step 1: Failing tests schreiben** (`tests/Integration/HrMitarbeiterListenTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Services\OffenePunkte;

final class HrMitarbeiterListenTest extends TestCase
{
    use HrBereichHarness;

    private object $punkte;
    private bool $gescheitert = false;
    private int $einsatzNr = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $this->punkte = new class extends OffenePunkte {
            public array $liste = [['code' => 'ausweis', 'label' => 'Personalausweis', 'status' => 'fehlt', 'ko' => true]];
            public function fuer(RecEmployee $employee, ?string $heute = null): array
            {
                return ['punkte' => $this->liste, 'einsatz' => null, 'gesperrt' => false];
            }
        };
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function listen(): HrMitarbeiterListen
    {
        return new HrMitarbeiterListen($this->portalAuth(), $this->punkte, fn (int $id) => $this->gescheitert);
    }

    private function einsatz(RecEmployee $a, string $datum, int $status = 1): void
    {
        $this->einsatzNr++;
        DB::table('rec_dispo_assignments')->insert([
            'uuid' => 'as-' . $this->einsatzNr, 'ds_ref' => 'DS' . $this->einsatzNr, 'rec_dispo_event_id' => 1,
            'pnr_raw' => 'MA' . $a->id, 'rec_employee_id' => $a->id, 'datum' => $datum, 'status_id' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function nachweis(RecEmployee $a, array $set = []): int
    {
        return (int) DB::table('rec_employee_proofs')->insertGetId(array_merge([
            'uuid' => 'pr-' . bin2hex(random_bytes(4)), 'team_id' => $a->team_id, 'rec_employee_id' => $a->id,
            'proof_type_code' => 'ausweis', 'valid_until' => '2026-10-20', 'version' => 1,
            'reminded_at' => '2026-10-01 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ], $set));
    }

    private function erinnert(int $personId, string $am = '2026-10-07 10:00:00'): void
    {
        DB::table('rec_persons')->where('id', $personId)->update(['aufgaben_signatur' => 'sig', 'aufgaben_gemeldet_at' => $am, 'aufgaben_nachricht_id' => 5]);
    }

    /** Review Focus 4. */
    public function test_rg_und_ma_derselben_person_sind_eine_zeile_in_allen_listen(): void
    {
        $ma = $this->akte(['phone' => '+4915111111111', 'first_name' => 'Rosa', 'last_name' => 'Rot']);
        $rg = $this->akte(['phone' => '+4915111111111', 'first_name' => 'Rosa', 'last_name' => 'Rot', 'company' => 'RG']);
        $id = $this->person([
            'wechsel_neue_nummer' => '+4917000000001', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall',
            'locked_at' => now(),
        ], $ma, $rg);
        DB::table('rec_employees')->whereIn('id', [$ma->id, $rg->id])->update(['portal_locked_at' => '2026-10-10 16:00:00']);
        $this->erinnert($id);
        $this->nachweis($rg);
        $l = $this->listen();

        foreach (['antraege' => $l->antraege($this->team), 'gesperrt' => $l->gesperrt($this->team), 'erinnert' => $l->erinnert($this->team), 'pilot' => $l->pilot($this->team)['zeilen']] as $liste => $zeilen) {
            $this->assertCount(1, $zeilen, $liste);
            $this->assertSame(['MA', 'RG'], array_column($zeilen[0]['akten'], 'firma'), $liste);
            $this->assertSame('Rosa Rot', $zeilen[0]['name'], $liste);
        }
        $this->assertSame(['aufgaben', 'nachweis'], array_column($l->erinnert($this->team)[0]['chips'], 'quelle'), 'zwei Chips, eine Zeile');
    }

    public function test_akte_ohne_personen_zeile_ist_eine_eigene_zeile(): void
    {
        $a = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);

        $zeilen = $this->listen()->gesperrt($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertSame('e:' . $a->id, $zeilen[0]['schluessel']);
        $this->assertNull($zeilen[0]['person_id']);
    }

    public function test_team_b_mit_derselben_nummer_erscheint_nirgends_und_wird_nicht_genannt(): void
    {
        $eigen = $this->akte(['phone' => '+4915111111111']);
        $this->person([], $eigen);
        $fremd = $this->akte(['team_id' => 8, 'phone' => '+4915111111111', 'first_name' => 'Bert', 'last_name' => 'Fremd',
            'portal_locked_at' => '2026-10-10 16:00:00']);
        $fremdId = $this->person(['team_id' => 8, 'locked_at' => now(), 'wechsel_neue_nummer' => '+4917000000002',
            'wechsel_beantragt_at' => '2026-10-12 09:00:00', 'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $fremd);
        $this->erinnert($fremdId);
        $this->nachweis($fremd);
        $l = $this->listen();

        $this->assertSame([], $l->antraege($this->team));
        $this->assertSame([], $l->erinnert($this->team));
        $gesperrt = $l->gesperrt($this->team);
        $this->assertCount(1, $gesperrt, 'nur die eigene Person, mit Chip "mehrdeutig"');
        $this->assertSame(['mehrdeutig'], array_column($gesperrt[0]['gruende'], 'code'));
        $json = json_encode($gesperrt);
        $this->assertStringNotContainsString('Bert', $json);
        $this->assertStringNotContainsString((string) $fremdId, json_encode(array_column($gesperrt[0]['gruende'], 'text')));
        $this->assertCount(1, $l->pilot($this->team)['zeilen']);
    }

    public function test_antraege_gesperrt_stillgelegt_und_notfallweg_aus(): void
    {
        $g = $this->akte();
        $gesperrt = $this->person(['locked_at' => now(), 'wechsel_neue_nummer' => '+4917000000003', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 08:00:00', 'wechsel_quelle' => 'notfall'], $g);
        $ziel = $this->akte(['first_name' => 'Zora']);
        $zielId = $this->person([], $ziel);
        $still = $this->person(['phone' => '+4915199999999', 'merged_into_person_id' => $zielId, 'wechsel_neue_nummer' => '+4917000000004',
            'wechsel_beantragt_at' => '2026-10-12 09:00:00', 'wechsel_wirksam_ab' => '2026-10-12 09:30:00', 'wechsel_quelle' => 'notfall']);
        $o = $this->akte();
        $offen = $this->person(['wechsel_neue_nummer' => '+4917000000005', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $o);

        $zeilen = collect($this->listen()->antraege($this->team))->keyBy('person_id');

        $this->assertSame('gesperrt, wird nie angewendet', $zeilen[$gesperrt]['zustand']);
        $this->assertFalse($zeilen[$gesperrt]['kann_freigeben']);
        $this->assertSame('stillgelegt, wird nie angewendet', $zeilen[$still]['zustand']);
        $this->assertSame('Zora Muster', $zeilen[$still]['name'], 'Name von der Zielzeile');
        $this->assertFalse($zeilen[$still]['kann_freigeben']);
        $this->assertSame('Notfallweg aus, wartet auf HR', $zeilen[$offen]['zustand'], 'Team-Schalter steht auf Vorgabe aus');
        $this->assertTrue($zeilen[$offen]['kann_freigeben']);
        $this->assertSame('…0005', $zeilen[$offen]['neu']);
        $this->assertSame('13.10., 09:00 (noch 23 Std.)', $zeilen[$offen]['wirksam']);
        $this->assertSame('Nummer + Passwort verloren', $zeilen[$offen]['quelle_text']);
        $this->assertSame(array_keys($zeilen->all()), [$still, $gesperrt, $offen], 'sortiert nach Faelligkeit');

        $this->einstellung('konto_notfall_weg_aktiv', true);
        $this->assertSame('offen', collect($this->listen()->antraege($this->team))->keyBy('person_id')[$offen]['zustand']);
    }

    public function test_gesperrt_je_grund_und_dispo_an_inaktiver_akte_nicht(): void
    {
        $inaktiv = $this->akte(['is_active' => false, 'portal_locked_at' => '2026-10-10 16:00:00']);
        $this->person([], $inaktiv);
        $aktiv = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: X']);
        $notfall = $this->akte();
        $notfallId = $this->person(['notfall_gesperrt_bis' => '2026-10-19 10:00:00'], $notfall);
        $abgelaufen = $this->akte();
        $this->person(['notfall_gesperrt_bis' => '2026-10-11 10:00:00'], $abgelaufen);

        $zeilen = collect($this->listen()->gesperrt($this->team))->keyBy('employee_id');

        $this->assertFalse($zeilen->has($inaktiv->id), 'GD-9: Dispo-Sperre an beendeter Anstellung nicht gelistet');
        $this->assertSame('dispo', $zeilen[$aktiv->id]['gruende'][0]['code']);
        $this->assertSame($aktiv->id, $zeilen[$aktiv->id]['gruende'][0]['employee_id']);
        $this->assertSame('notfall', $zeilen[$notfall->id]['gruende'][0]['code']);
        $this->assertSame($notfallId, $zeilen[$notfall->id]['person_id']);
        $this->assertFalse($zeilen->has($abgelaufen->id));
    }

    public function test_drossel_in_einer_cache_abfrage_und_ohne_bremse_gar_keine(): void
    {
        foreach (['+4915110000001', '+4915110000002', '+4915110000003'] as $n) {
            $this->person([], $this->akte(['phone' => $n]));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, '+4915110000002', 'falsch-' . $i);
        }
        $store = $this->cache->getStore();
        $store->manyAufrufe = 0;
        $store->getAufrufe = 0;

        $zeilen = $this->listen()->gesperrt($this->team);

        $this->assertSame(1, $store->manyAufrufe);
        $this->assertSame(0, $store->getAufrufe);
        $this->assertCount(1, $zeilen);
        $this->assertSame('bremse', $zeilen[0]['gruende'][0]['code']);

        $store->manyAufrufe = 0;
        $this->assertSame([], $this->listen()->gesperrt($this->team, '', false));
        $this->assertSame(0, $store->manyAufrufe, 'Abzeichen-Pfad fragt den Cache nicht');
    }

    public function test_erinnert_grenze_genau_x_tage(): void
    {
        $genau = $this->person([], $this->akte(['first_name' => 'Genau']));
        $this->erinnert($genau, '2026-10-07 10:00:00');
        $knapp = $this->person([], $this->akte(['first_name' => 'Knapp']));
        $this->erinnert($knapp, '2026-10-07 10:00:01');

        $namen = array_column($this->listen()->erinnert($this->team), 'name');

        $this->assertSame(['Genau Muster'], $namen, 'X = 5: genau 5 Tage ja, eine Sekunde weniger nein');

        $this->einstellung('hr_erinnert_tage', 6);
        $this->assertSame([], $this->listen()->erinnert($this->team));
    }

    public function test_erinnert_quellen_fallen_weg_wenn_erledigt(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        DB::table('rec_persons')->where('id', $id)->update(['aufgaben_signatur' => null, 'aufgaben_gemeldet_at' => '2026-10-01 10:00:00']);
        $this->nachweis($a, ['superseded_at' => now()]);
        $this->nachweis($a, ['valid_until' => '2027-06-01']);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-1', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'sign',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        DB::table('rec_document_recipients')->insert(['team_id' => $this->team, 'uuid' => 'r-1', 'rec_document_id' => $dok,
            'rec_employee_id' => $a->id, 'notified_at' => '2026-10-01 10:00:00', 'signed_at' => '2026-10-02 10:00:00']);
        $this->vertragAn($a, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00']);

        $this->assertSame([], $this->listen()->erinnert($this->team), 'Signatur geraeumt, neuere Version, noch nicht faellig, unterschrieben, Vertrag nicht aus der Akte');

        $this->erinnert($id);
        $this->punkte->liste = [];
        $this->assertSame([], $this->listen()->erinnert($this->team), 'Live-Liste leer: Zeile verschwindet');
    }

    public function test_dokument_und_akte_vertrag_sind_chips(): void
    {
        $a = $this->akte();
        $this->person([], $a);
        $dok = (int) DB::table('rec_documents')->insertGetId([
            'team_id' => $this->team, 'uuid' => 'd-2', 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'acknowledge',
            'disk' => 'local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf', 'file_sha256' => str_repeat('0', 64), 'file_size' => 1,
        ]);
        DB::table('rec_document_recipients')->insert(['team_id' => $this->team, 'uuid' => 'r-2', 'rec_document_id' => $dok,
            'rec_employee_id' => $a->id, 'notified_at' => '2026-10-01 10:00:00']);
        $v = $this->vertragAn($a, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-01 10:00:00'], ['herkunft' => 'akte']);

        $chips = $this->listen()->erinnert($this->team)[0]['chips'];

        $this->assertSame(['dokument', 'vertrag'], array_column($chips, 'quelle'));
        $this->assertSame('vertrag:' . $v->id, $chips[1]['bezug']);
        $this->assertSame('Dokument: Hausordnung · erinnert vor 11 Tagen', $chips[0]['text']);
        $this->assertTrue($chips[0]['kann_erinnern']);
    }

    public function test_sortiert_nach_naechstem_einsatz_ohne_einsatz_ans_ende(): void
    {
        foreach (['Ohne' => null, 'Spaet' => '2026-10-15', 'Frueh' => '2026-10-13'] as $name => $datum) {
            $a = $this->akte(['first_name' => $name]);
            $this->erinnert($this->person([], $a));
            if ($datum !== null) {
                $this->einsatz($a, $datum);
            }
        }
        $storno = $this->akte(['first_name' => 'Storno']);
        $this->erinnert($this->person([], $storno));
        $this->einsatz($storno, '2026-10-12', 3);

        $zeilen = $this->listen()->erinnert($this->team);

        $this->assertSame(['Frueh Muster', 'Spaet Muster', 'Ohne Muster', 'Storno Muster'], array_column($zeilen, 'name'), 'nur Auftraege zaehlen (EinsatzBezug::naechster)');
        $this->assertSame('2026-10-13', $zeilen[0]['naechster_einsatz']);
        $this->assertNull($zeilen[2]['naechster_einsatz']);
    }

    public function test_kontaktiert_ruht_n_tage_und_kommt_zurueck(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $this->erinnert($id, '2026-10-01 10:00:00');
        $this->einsatz($a, '2026-10-30');
        RecHrVermerk::anfuegen($this->team, $id, null, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);

        $this->assertSame([], $this->listen()->erinnert($this->team));
        $ruhend = $this->listen()->erinnert($this->team, '', true);
        $this->assertTrue($ruhend[0]['ruht']);
        $this->assertSame('2026-10-15 10:00:00', $ruhend[0]['ruhe_bis']);

        Carbon::setTestNow('2026-10-15 10:00:01');
        $this->assertCount(1, $this->listen()->erinnert($this->team), 'nach N Tagen wieder da');
    }

    /** Review Focus 5. */
    public function test_kontaktiert_ruht_nicht_wenn_der_naechste_einsatz_in_die_ruhezeit_faellt(): void
    {
        $a = $this->akte();
        $id = $this->person([], $a);
        $this->erinnert($id, '2026-10-01 10:00:00');
        $this->einsatz($a, '2026-10-13');
        RecHrVermerk::anfuegen($this->team, $id, null, 'kontaktiert', 'Angerufen, kommt.', null, 'ok', now()->addDays(3), 42);

        $zeilen = $this->listen()->erinnert($this->team);

        $this->assertCount(1, $zeilen, 'Einsatz morgen: die Zeile bleibt sichtbar');
        $this->assertFalse($zeilen[0]['ruht']);
        $this->assertSame('kontaktiert — ruht nicht, weil der nächste Einsatz (13.10.2026) vor dem Ruhe-Ende liegt', $zeilen[0]['ruhe_hinweis']);
    }

    public function test_nicht_zugestellte_aufgaben_nachricht_ist_rot(): void
    {
        $id = $this->person([], $this->akte());
        $this->erinnert($id);
        $this->gescheitert = true;

        $chip = $this->listen()->erinnert($this->team)[0]['chips'][0];

        $this->assertTrue($chip['rot']);
        $this->assertSame('Personalausweis', $chip['tooltip']);
    }

    public function test_pilot_filterzahlen_stimmen_mit_den_zeilen(): void
    {
        $this->person([], $this->akte(['first_name' => 'A']));
        $this->person(['invited_at' => '2026-10-11 10:00:00', 'invite_expires_at' => '2026-10-18 10:00:00'], $this->akte(['first_name' => 'B']));
        $this->person(['invited_at' => '2026-10-05 10:00:00', 'invite_expires_at' => '2026-10-12 10:00:00'], $this->akte(['first_name' => 'C']));
        $this->person(['password_hash' => 'x', 'registered_at' => '2026-10-05 10:00:00'], $this->akte(['first_name' => 'D']));
        $this->person(['password_hash' => 'x', 'registered_at' => '2026-09-05 10:00:00', 'letzte_anmeldung_at' => '2026-09-20 10:00:00'], $this->akte(['first_name' => 'E']));
        $ohne = $this->akte(['first_name' => 'F', 'portal_verified_at' => '2026-10-10 08:00:00', 'portal_last_seen_at' => '2026-10-11 08:00:00']);
        $this->akte(['first_name' => 'G', 'portal_v2_since' => null]);
        $l = $this->listen();

        $alle = $l->pilot($this->team);

        $this->assertSame(['alle' => 6, 'nicht_eingeladen' => 2, 'eingeladen' => 1, 'abgelaufen' => 1, 'nie_angemeldet' => 1, 'lange_nicht' => 1], $alle['zahlen']);
        foreach ($alle['zahlen'] as $filter => $zahl) {
            $this->assertCount($zahl, $l->pilot($this->team, '', $filter)['zeilen'], $filter);
        }
        $f = collect($alle['zeilen'])->firstWhere('employee_id', $ohne->id);
        $this->assertTrue($f['ohne_person']);
        $this->assertSame('11.10.2026, 08:00', $f['altes_portal']);
        $this->assertSame('01.10.2026', $f['pilot_seit']);
        $this->assertStringEndsWith('?tab=stammdaten#konto', $f['link']);
    }

    public function test_abzeichen_zaehlt_antraege_und_gesperrte_ohne_drossel(): void
    {
        $this->person(['wechsel_neue_nummer' => '+4917000000009', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $this->akte());
        $this->person(['locked_at' => now()], $this->akte());
        $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $gebremst = $this->akte(['phone' => '+4915120000000']);
        $this->person([], $gebremst);
        for ($i = 0; $i < 5; $i++) {
            $this->portalAuth()->anmeldenMitNummer(null, '+4915120000000', 'falsch-' . $i);
        }

        $this->assertSame(3, $this->listen()->abzeichen($this->team));
    }

    public function test_namenssuche(): void
    {
        $this->person(['locked_at' => now()], $this->akte(['first_name' => 'Jörg', 'last_name' => 'Äpfel']));
        $this->person(['locked_at' => now()], $this->akte(['first_name' => 'Ina', 'last_name' => 'Birne']));

        $this->assertSame(['Jörg Äpfel'], array_column($this->listen()->gesperrt($this->team, 'äpf'), 'name'));
    }
}
```

- [ ] **Step 2: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HrMitarbeiterListenTest`
Expected: FAIL — `Class "Platform\Recruiting\Services\HrMitarbeiterListen" not found`.

- [ ] **Step 3: `HrMitarbeiterListen` schreiben** (`src/Services/HrMitarbeiterListen.php`)

```php
<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Bote;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\HrEinstellungen;
use Platform\Recruiting\Support\KontoStatus;
use Platform\Recruiting\Support\NummernwechselTafel;
use Platform\Recruiting\Support\ProofTypes;
use Platform\Recruiting\Support\SperrGruende;
use Platform\Recruiting\Support\VertragsHerkunft;

/**
 * Die Lese-Abfragen des HR-Bereichs "Mitarbeiter" (Spec 2026-10-12 §3) —
 * reine Leser, kein Schreibzugriff. Leitsatz: EIN MENSCH = EINE ZEILE
 * (Personen-Klammer rec_persons; Akten ohne Personen-Zeile je eine Zeile).
 * Team-Scope: Personen ueber rec_persons.team_id UND Akte im Team;
 * Nachweise/Dokumente/Vertraege ueber rec_employees.team_id.
 */
final class HrMitarbeiterListen
{
    private const AKTEN_SPALTEN = [
        'id', 'rec_person_id', 'first_name', 'last_name', 'company', 'is_active', 'phone',
        'portal_v2_since', 'portal_verified_at', 'portal_last_seen_at', 'portal_locked_at', 'portal_locked_reason',
    ];

    public function __construct(
        private readonly ?PortalAuth $auth = null,
        private readonly ?OffenePunkte $offenePunkte = null,
        private readonly ?\Closure $nachrichtGescheitert = null,
    ) {
    }

    /**
     * @param  iterable<object>  $akten  nach id sortiert
     * @return array<string, array{schluessel:string, person_id:?int, employee_id:int, name:string, akten:list<array{id:int, firma:string, aktiv:bool}>, employee_ids:list<int>}>
     */
    public static function menschen(iterable $akten): array
    {
        $menschen = [];
        foreach ($akten as $a) {
            $schluessel = self::schluessel($a);
            if (!isset($menschen[$schluessel])) {
                $name = trim(((string) ($a->first_name ?? '')) . ' ' . ((string) ($a->last_name ?? '')));
                $menschen[$schluessel] = [
                    'schluessel'   => $schluessel,
                    'person_id'    => $a->rec_person_id !== null ? (int) $a->rec_person_id : null,
                    'employee_id'  => (int) $a->id,
                    'name'         => $name !== '' ? $name : 'Akte #' . (int) $a->id,
                    'akten'        => [],
                    'employee_ids' => [],
                ];
            }
            $firma = trim((string) ($a->company ?? ''));
            $menschen[$schluessel]['akten'][] = ['id' => (int) $a->id, 'firma' => $firma !== '' ? $firma : 'Akte', 'aktiv' => (bool) $a->is_active];
            $menschen[$schluessel]['employee_ids'][] = (int) $a->id;
        }

        return $menschen;
    }

    // ------------------------------------------------------------ 3.1 Konto-Antraege

    public function antraege(int $teamId, string $suche = ''): array
    {
        $offene = KontoWriter::offeneNummernwechsel($teamId);
        if ($offene === []) {
            return [];
        }

        // Stillgelegte Zeilen zeigen Name und Akten der Zielzeile (dort haengen die Akten jetzt).
        $anker = [];
        foreach ($offene as $z) {
            $anker[(int) $z->id] = $z->merged_into_person_id !== null ? (int) $z->merged_into_person_id : (int) $z->id;
        }
        $menschen = self::menschen($this->aktenFuerPersonen($teamId, array_values(array_unique($anker))));
        $jetzt = now()->getTimestamp();

        $zeilen = [];
        foreach ($offene as $z) {
            $m = $menschen['p:' . $anker[(int) $z->id]] ?? null;
            if ($m === null) {
                continue;
            }
            $zustand = NummernwechselTafel::zustand($z, KontoWriter::notfallWegAn((int) $z->id));
            $wirksam = Carbon::parse((string) $z->wechsel_wirksam_ab);
            $stunden = (int) ceil(($wirksam->getTimestamp() - $jetzt) / 3600);
            $notfall = $z->wechsel_quelle === KontoWriter::ZWECK_NOTFALL;

            $zeilen[] = array_merge($m, [
                'person_id'      => (int) $z->id,
                'alt'            => '…' . NummernwechselTafel::kurz((string) ($z->phone ?? '')),
                'neu'            => '…' . NummernwechselTafel::kurz((string) $z->wechsel_neue_nummer),
                'beantragt_at'   => (string) $z->wechsel_beantragt_at,
                'beantragt'      => $z->wechsel_beantragt_at !== null ? Carbon::parse((string) $z->wechsel_beantragt_at)->format('d.m., H:i') : '—',
                'wirksam'        => $wirksam->format('d.m., H:i') . ($stunden > 0 ? " (noch {$stunden} Std.)" : ' (fällig)'),
                'quelle'         => (string) $z->wechsel_quelle,
                'quelle_text'    => $notfall ? 'Nummer + Passwort verloren' : (string) $z->wechsel_quelle,
                'zustand'        => $zustand,
                'kann_freigeben' => $notfall && in_array($zustand, [NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS], true),
            ]);
        }

        return self::gefiltert($zeilen, $suche);
    }

    public function gestoppte(int $teamId): array
    {
        $gestoppt = DB::table('rec_hr_vermerke as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.user_id')
            ->where('v.team_id', $teamId)
            ->where('v.art', 'antrag_gestoppt')
            ->where('v.created_at', '>=', now()->subDays(30)->format('Y-m-d H:i:s'))
            ->orderByDesc('v.created_at')
            ->get(['v.rec_person_id', 'v.notiz', 'v.created_at', 'u.name as benutzer']);
        $sperren = DB::table('rec_persons')
            ->where('team_id', $teamId)
            ->where('notfall_gesperrt_bis', '>', now()->format('Y-m-d H:i:s'))
            ->orderBy('notfall_gesperrt_bis')
            ->get(['id', 'notfall_gesperrt_bis']);

        $ids = array_values(array_unique(array_merge(
            $gestoppt->pluck('rec_person_id')->filter()->map(fn ($v) => (int) $v)->all(),
            $sperren->pluck('id')->map(fn ($v) => (int) $v)->all(),
        )));
        $menschen = self::menschen($this->aktenFuerPersonen($teamId, $ids));

        $aus = ['gestoppt' => [], 'notfallsperren' => []];
        foreach ($gestoppt as $v) {
            $m = $menschen['p:' . (int) $v->rec_person_id] ?? null;
            if ($m !== null) {
                $aus['gestoppt'][] = ['name' => $m['name'], 'akten' => $m['akten'], 'am' => Carbon::parse((string) $v->created_at)->format('d.m.Y, H:i'),
                    'von' => (string) ($v->benutzer ?? '—'), 'notiz' => (string) $v->notiz];
            }
        }
        foreach ($sperren as $p) {
            $m = $menschen['p:' . (int) $p->id] ?? null;
            if ($m !== null) {
                $aus['notfallsperren'][] = ['person_id' => (int) $p->id, 'name' => $m['name'], 'akten' => $m['akten'],
                    'bis' => Carbon::parse((string) $p->notfall_gesperrt_bis)->format('d.m.Y')];
            }
        }

        return $aus;
    }

    // ------------------------------------------------------------ 3.2 Gesperrt

    public function gesperrt(int $teamId, string $suche = '', bool $mitBremse = true): array
    {
        $akten = DB::table('rec_employees')->where('team_id', $teamId)->where('is_active', 1)->orderBy('id')->get(self::AKTEN_SPALTEN);
        $menschen = self::menschen($akten);
        $gruende = $this->sperrGruende($teamId, $menschen, $akten, $mitBremse);

        $zeilen = [];
        foreach ($menschen as $schluessel => $m) {
            if (($gruende[$schluessel] ?? []) !== []) {
                $zeilen[] = $m + ['gruende' => $gruende[$schluessel]];
            }
        }

        return self::gefiltert($zeilen, $suche);
    }

    /**
     * @param  array<string, array>  $menschen
     * @param  iterable<object>  $akten  die Akten dieser Menschen (AKTEN_SPALTEN)
     * @return array<string, list<array>>
     */
    public function sperrGruende(int $teamId, array $menschen, iterable $akten, bool $mitBremse): array
    {
        $jetzt = now()->format('Y-m-d H:i:s');
        $personIds = array_values(array_filter(array_column($menschen, 'person_id')));

        $personen = $personIds === [] ? collect() : DB::table('rec_persons')
            ->whereIn('id', $personIds)->where('team_id', $teamId)
            ->get(['id', 'phone', 'locked_at', 'notfall_gesperrt_bis'])->keyBy('id');

        // GD-2: dieselbe Nummer an einer anderen lebenden Zeile (anderes Team) — ohne sie zu nennen.
        $mehrdeutig = $personIds === [] ? [] : DB::table('rec_persons as p')
            ->whereIn('p.id', $personIds)
            ->whereNotNull('p.phone')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('rec_persons as p2')
                ->whereColumn('p2.phone', 'p.phone')
                ->whereColumn('p2.id', '<>', 'p.id')
                ->whereNull('p2.merged_into_person_id'))
            ->pluck('p.id')->mapWithKeys(fn ($v) => [(int) $v => true])->all();

        $bremsen = $mitBremse
            ? $this->portalAuth()->gebremsteNummern($personen->pluck('phone')->filter()->map(fn ($n) => (string) $n)->values()->all())
            : [];

        $dispo = [];
        foreach ($akten as $a) {
            if ((bool) $a->is_active && $a->portal_locked_at !== null) {
                $dispo[self::schluessel($a)] ??= ['seit' => (string) $a->portal_locked_at, 'grund' => $a->portal_locked_reason, 'employee_id' => (int) $a->id];
            }
        }

        $aus = [];
        foreach ($menschen as $schluessel => $m) {
            $p = $m['person_id'] !== null ? $personen->get($m['person_id']) : null;
            $nummer = $p !== null && $p->phone !== null ? (string) $p->phone : null;
            $aus[$schluessel] = SperrGruende::fuer([
                'dispo'          => $dispo[$schluessel] ?? null,
                'konto_gesperrt' => $p !== null && $p->locked_at !== null,
                'bremse'         => $nummer !== null && array_key_exists($nummer, $bremsen) ? ['frei_ab' => $bremsen[$nummer]] : null,
                'notfall_bis'    => $p?->notfall_gesperrt_bis,
                'mehrdeutig'     => $p !== null && isset($mehrdeutig[(int) $p->id]),
            ], $jetzt);
        }

        return $aus;
    }

    // ------------------------------------------------------------ 3.3 Erinnert, aber keine Reaktion

    public function erinnert(int $teamId, string $suche = '', bool $ruhendeZeigen = false): array
    {
        $grenze = now()->subDays(HrEinstellungen::erinnertTage($teamId))->format('Y-m-d H:i:s');
        $heute = now()->toDateString();
        $teamAkten = fn ($q) => $q->select('id')->from('rec_employees')->where('team_id', $teamId);

        $aufgaben = DB::table('rec_persons')
            ->where('team_id', $teamId)
            ->whereNotNull('aufgaben_signatur')
            ->whereNotNull('aufgaben_gemeldet_at')
            ->where('aufgaben_gemeldet_at', '<=', $grenze)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('rec_employees')
                ->whereColumn('rec_employees.rec_person_id', 'rec_persons.id')->where('rec_employees.team_id', $teamId))
            ->orderBy('id')
            ->get(['id', 'aufgaben_gemeldet_at', 'aufgaben_nachricht_id']);

        $nachweise = DB::table('rec_employee_proofs as p')
            ->join('rec_employees as e', 'e.id', '=', 'p.rec_employee_id')
            ->where('e.team_id', $teamId)->where('e.is_active', 1)
            ->whereNull('p.superseded_at')
            ->whereNotNull('p.reminded_at')->where('p.reminded_at', '<=', $grenze)
            ->orderBy('p.id')
            ->get(['p.id', 'p.rec_employee_id', 'p.proof_type_code', 'p.valid_until', 'p.reminded_at'])
            ->filter(fn ($n) => self::nachweisNochFaellig($n, $heute))->values();

        $dokumente = RecDocumentRecipient::query()->with('document')
            ->whereNull('withdrawn_at')->whereNotNull('notified_at')->where('notified_at', '<=', $grenze)
            ->whereIn('rec_employee_id', $teamAkten)->orderBy('id')->get()
            ->filter(function (RecDocumentRecipient $z) {
                $aktion = (string) ($z->document?->action ?? '');

                return $z->document !== null && DokumentKategorie::brauchtHandlung($aktion) && !DokumentStatus::istErledigt($z->zeitstempel(), $aktion);
            })->values();

        $vertraege = RecContract::query()->with('contractTemplate')
            ->where('status', 'sent')->whereNotNull('sent_at')->where('sent_at', '<=', $grenze)
            ->whereIn('rec_employee_id', $teamAkten)->orderBy('id')->get()
            ->filter(fn (RecContract $v) => VertragsHerkunft::ausAkte($v))->values();

        $aktenIds = array_values(array_unique(array_merge(
            $nachweise->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all(),
            $dokumente->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all(),
            $vertraege->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all(),
        )));
        $personIds = $aufgaben->pluck('id')->map(fn ($v) => (int) $v)->all();
        $akten = $this->aktenMitSchwestern($teamId, $aktenIds, $personIds);
        $menschen = self::menschen($akten);
        $aktenById = $akten->keyBy('id');
        $schluesselJeAkte = $akten->mapWithKeys(fn ($a) => [(int) $a->id => self::schluessel($a)])->all();

        $chips = [];
        foreach ($aufgaben as $p) {
            $schluessel = 'p:' . (int) $p->id;
            if (isset($menschen[$schluessel]) && ($chip = $this->aufgabenChip($p, $menschen[$schluessel], $aktenById)) !== null) {
                $chips[$schluessel][] = $chip;
            }
        }
        foreach ($nachweise as $n) {
            $schluessel = $schluesselJeAkte[(int) $n->rec_employee_id] ?? null;
            if ($schluessel !== null) {
                $chips[$schluessel][] = $this->chip('nachweis', 'nachweis:' . (int) $n->id, 'Nachweis: ' . ProofTypes::label((string) $n->proof_type_code),
                    (string) $n->reminded_at, $aktenById[(int) $n->rec_employee_id]->portal_v2_since !== null, (string) $n->reminded_at);
            }
        }
        foreach ($dokumente as $z) {
            $schluessel = $schluesselJeAkte[(int) $z->rec_employee_id] ?? null;
            if ($schluessel !== null) {
                $am = $z->notified_at->format('Y-m-d H:i:s');
                $chips[$schluessel][] = $this->chip('dokument', 'dokument:' . (int) $z->id, 'Dokument: ' . (string) $z->document->title,
                    $am, $aktenById[(int) $z->rec_employee_id]->portal_v2_since !== null, $am);
            }
        }
        $letzteVertragsErinnerung = $vertraege->isEmpty() ? [] : DB::table('rec_hr_vermerke')
            ->where('team_id', $teamId)->where('art', 'erneut_erinnert')->where('ergebnis', 'sent')
            ->whereIn('bezug', $vertraege->map(fn ($v) => 'vertrag:' . $v->id)->all())
            ->groupBy('bezug')->selectRaw('bezug, max(created_at) as zuletzt')->pluck('zuletzt', 'bezug')->all();
        foreach ($vertraege as $v) {
            $schluessel = $schluesselJeAkte[(int) $v->rec_employee_id] ?? null;
            if ($schluessel !== null) {
                $am = $v->sent_at->format('Y-m-d H:i:s');
                $pause = max($am, (string) ($letzteVertragsErinnerung['vertrag:' . $v->id] ?? ''));
                $chips[$schluessel][] = $this->chip('vertrag', 'vertrag:' . (int) $v->id,
                    'Vertrag: ' . VertragLeser::anzeigename($v->contractTemplate?->code, $v->contractTemplate?->name),
                    $am, $aktenById[(int) $v->rec_employee_id]->portal_v2_since !== null, $pause);
            }
        }

        $naechster = $this->naechsteEinsaetze($menschen, $heute);
        $ruhe = $this->ruheBis($teamId);

        $zeilen = [];
        foreach ($chips as $schluessel => $liste) {
            $m = $menschen[$schluessel];
            $einsatz = $naechster[$schluessel] ?? null;
            $ruheBis = $ruhe[$schluessel] ?? null;
            // Review Focus 5: eine Ruhe, die ueber den naechsten Einsatz reicht, versteckt nichts.
            $ruht = $ruheBis !== null && ($einsatz === null || $einsatz > substr($ruheBis, 0, 10));
            if ($ruht && !$ruhendeZeigen) {
                continue;
            }
            $zeilen[] = array_merge($m, [
                'chips'             => $liste,
                'naechster_einsatz' => $einsatz,
                'naechster_text'    => $einsatz !== null ? Carbon::parse($einsatz)->format('d.m.Y') : 'kein Einsatz',
                'aeltest'           => min(array_column($liste, 'erinnert_at')),
                'ruhe_bis'          => $ruheBis,
                'ruht'              => $ruht,
                'ruhe_hinweis'      => $ruheBis !== null && !$ruht
                    ? sprintf('kontaktiert — ruht nicht, weil der nächste Einsatz (%s) vor dem Ruhe-Ende liegt', Carbon::parse((string) $einsatz)->format('d.m.Y'))
                    : null,
            ]);
        }

        usort($zeilen, fn (array $a, array $b) => [$a['naechster_einsatz'] ?? '9999-12-31', $a['aeltest']] <=> [$b['naechster_einsatz'] ?? '9999-12-31', $b['aeltest']]);

        return self::gefiltert($zeilen, $suche);
    }

    // ------------------------------------------------------------ 3.4 Pilot-Ueberblick

    public function pilot(int $teamId, string $suche = '', string $filter = 'alle'): array
    {
        $pilotAkten = DB::table('rec_employees')->where('team_id', $teamId)->whereNotNull('portal_v2_since')->orderBy('id')->get(self::AKTEN_SPALTEN);
        $pilotIds = $pilotAkten->pluck('id')->map(fn ($v) => (int) $v)->all();
        $personIds = $pilotAkten->pluck('rec_person_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $akten = $this->aktenMitSchwestern($teamId, $pilotIds, $personIds);
        $menschen = self::menschen($akten);
        $personen = $personIds === [] ? collect() : DB::table('rec_persons')->whereIn('id', $personIds)->where('team_id', $teamId)
            ->get(['id', 'password_hash', 'registered_at', 'invited_at', 'invite_expires_at', 'invite_used_at', 'letzte_anmeldung_at'])->keyBy('id');
        $jetzt = now()->format('Y-m-d H:i:s');
        $leer = (object) ['password_hash' => null, 'registered_at' => null, 'invited_at' => null, 'invite_expires_at' => null, 'invite_used_at' => null, 'letzte_anmeldung_at' => null];

        $zeilen = [];
        $zahlen = array_fill_keys(array_keys(KontoStatus::FILTER), 0);
        foreach ($menschen as $schluessel => $m) {
            if ($m['person_id'] !== null && !$personen->has($m['person_id'])) {
                continue;
            }
            $p = $m['person_id'] !== null ? $personen->get($m['person_id']) : $leer;
            $eigene = $akten->whereIn('id', $m['employee_ids']);
            $pilotSeit = $eigene->pluck('portal_v2_since')->filter()->min();
            $altesPortal = $eigene->flatMap(fn ($a) => [$a->portal_verified_at, $a->portal_last_seen_at])->filter()->max();
            $treffer = KontoStatus::filter($p, $jetzt);
            foreach ($treffer as $f) {
                $zahlen[$f]++;
            }
            $zeilen[] = array_merge($m, [
                'status_code'      => KontoStatus::code($p, $jetzt),
                'status_text'      => KontoStatus::text($p, $jetzt),
                'filter'           => $treffer,
                'pilot_seit'       => $pilotSeit !== null ? Carbon::parse((string) $pilotSeit)->format('d.m.Y') : '—',
                'registriert'      => $p->registered_at !== null ? Carbon::parse((string) $p->registered_at)->format('d.m.Y') : '—',
                'letzte_anmeldung' => $p->letzte_anmeldung_at !== null ? Carbon::parse((string) $p->letzte_anmeldung_at)->format('d.m.Y, H:i') : '—',
                'altes_portal'     => $altesPortal !== null ? Carbon::parse((string) $altesPortal)->format('d.m.Y, H:i') : '—',
                'ohne_person'      => $m['person_id'] === null,
                'link'             => route('recruiting.employees.show', ['employee' => $m['employee_id']]) . '?tab=stammdaten#konto',
            ]);
        }

        $zeilen = array_values(array_filter($zeilen, fn (array $z) => in_array($filter, $z['filter'], true)));

        return ['zeilen' => self::gefiltert($zeilen, $suche), 'zahlen' => $zahlen];
    }

    // ------------------------------------------------------------ Abzeichen

    public function abzeichen(int $teamId): int
    {
        $gesperrt = 0;
        foreach ($this->gesperrt($teamId, '', false) as $zeile) {
            foreach ($zeile['gruende'] as $grund) {
                if (SperrGruende::zaehltFuersAbzeichen($grund['code'])) {
                    $gesperrt++;
                    break;
                }
            }
        }

        return count($this->antraege($teamId)) + $gesperrt;
    }

    // ------------------------------------------------------------ intern

    private static function schluessel(object $akte): string
    {
        return $akte->rec_person_id !== null ? 'p:' . (int) $akte->rec_person_id : 'e:' . (int) $akte->id;
    }

    /** @param list<int> $personIds */
    private function aktenFuerPersonen(int $teamId, array $personIds): Collection
    {
        return $personIds === [] ? collect() : DB::table('rec_employees')
            ->where('team_id', $teamId)->whereIn('rec_person_id', $personIds)->orderBy('id')->get(self::AKTEN_SPALTEN);
    }

    /** Die genannten Akten plus alle Schwester-Akten derselben Personen im Team. */
    private function aktenMitSchwestern(int $teamId, array $aktenIds, array $personIds): Collection
    {
        if ($aktenIds !== []) {
            $personIds = array_values(array_unique(array_merge($personIds, DB::table('rec_employees')
                ->whereIn('id', $aktenIds)->whereNotNull('rec_person_id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all())));
        }
        if ($aktenIds === [] && $personIds === []) {
            return collect();
        }

        return DB::table('rec_employees')
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q->whereIn('id', $aktenIds === [] ? [0] : $aktenIds)->orWhereIn('rec_person_id', $personIds === [] ? [0] : $personIds))
            ->orderBy('id')
            ->get(self::AKTEN_SPALTEN);
    }

    private static function nachweisNochFaellig(object $n, string $heute): bool
    {
        $code = (string) $n->proof_type_code;
        $bis = substr((string) $n->valid_until, 0, 10);
        $vorlauf = ProofTypes::leadDays($code);
        if ($bis === '' || !ProofTypes::exists($code) || !ProofTypes::hasExpiry($code) || $vorlauf === null) {
            return false;
        }

        return $bis <= date('Y-m-d', (int) strtotime($heute . ' +' . $vorlauf . ' days'));
    }

    private function aufgabenChip(object $p, array $mensch, Collection $aktenById): ?array
    {
        $aktiv = array_values(array_filter($mensch['employee_ids'], fn (int $id) => (bool) $aktenById[$id]->is_active));
        $bote = Bote::fuer($aktiv);
        $leser = $bote ?? RecEmployee::find($aktiv[0] ?? $mensch['employee_id']);
        $punkte = $leser !== null ? ($this->offenePunkte ?? app(OffenePunkte::class))->fuer($leser)['punkte'] : [];
        if ($punkte === []) {
            return null;
        }
        $rot = $p->aufgaben_nachricht_id !== null && $this->gescheitert((int) $p->aufgaben_nachricht_id);

        return $this->chip('aufgaben', HrKontoAktionen::BEZUG_AUFGABEN, 'Aufgaben', (string) $p->aufgaben_gemeldet_at, $bote !== null,
            (string) $p->aufgaben_gemeldet_at, implode(', ', array_column($punkte, 'label')), $rot);
    }

    private function chip(string $quelle, string $bezug, string $titel, string $erinnertAt, bool $imNeuenPortal, string $pauseStempel, string $tooltip = '', bool $rot = false): array
    {
        $pause = HrKontoAktionen::pauseBis($pauseStempel);
        $inPause = $pause !== null && now()->lt($pause);
        $alter = (int) floor((now()->getTimestamp() - (int) strtotime($erinnertAt)) / 86400);

        return [
            'quelle'        => $quelle,
            'bezug'         => $bezug,
            'titel'         => $titel,
            'text'          => sprintf('%s · erinnert vor %d Tagen', $titel, $alter) . ($rot ? ' · nicht zugestellt' : ''),
            'erinnert_at'   => $erinnertAt,
            'alter_tage'    => $alter,
            'pause_bis'     => $pause?->format('Y-m-d'),
            'kann_erinnern' => $imNeuenPortal && !$inPause,
            'grau_text'     => !$imNeuenPortal ? 'nicht im neuen Portal' : ($inPause ? 'erneut erinnern ab ' . $pause->format('d.m.') : null),
            'rot'           => $rot,
            'tooltip'       => $tooltip,
        ];
    }

    /** @return array<string, string> Mensch-Schluessel => naechster Auftrag (Y-m-d), EINE Abfrage. */
    private function naechsteEinsaetze(array $menschen, string $heute): array
    {
        $jeAkte = [];
        foreach ($menschen as $schluessel => $m) {
            foreach ($m['employee_ids'] as $id) {
                $jeAkte[$id] = $schluessel;
            }
        }
        if ($jeAkte === []) {
            return [];
        }
        $einsaetze = [];
        foreach (DB::table('rec_dispo_assignments')->whereIn('rec_employee_id', array_keys($jeAkte))
            ->whereNull('missing_since')->where('datum', '>=', $heute)
            ->get(['rec_employee_id', 'datum', 'status_id']) as $e) {
            $einsaetze[$jeAkte[(int) $e->rec_employee_id]][] = ['datum' => substr((string) $e->datum, 0, 10), 'status_id' => (int) $e->status_id];
        }
        $aus = [];
        foreach ($einsaetze as $schluessel => $liste) {
            $n = EinsatzBezug::naechster($liste, $heute);
            if ($n !== null) {
                $aus[$schluessel] = (string) $n['datum'];
            }
        }

        return $aus;
    }

    /** @return array<string, string> Mensch-Schluessel => spaetestes laufendes ruhe_bis ('Y-m-d H:i:s'). */
    private function ruheBis(int $teamId): array
    {
        $aus = [];
        foreach (DB::table('rec_hr_vermerke')->where('team_id', $teamId)->where('art', 'kontaktiert')
            ->where('ruhe_bis', '>', now()->format('Y-m-d H:i:s'))
            ->get(['rec_person_id', 'rec_employee_id', 'ruhe_bis']) as $v) {
            $schluessel = $v->rec_person_id !== null ? 'p:' . (int) $v->rec_person_id : 'e:' . (int) $v->rec_employee_id;
            $aus[$schluessel] = max($aus[$schluessel] ?? '', (string) $v->ruhe_bis);
        }

        return $aus;
    }

    private function gescheitert(int $nachrichtId): bool
    {
        return $this->nachrichtGescheitert !== null
            ? (bool) ($this->nachrichtGescheitert)($nachrichtId)
            : CommsWhatsAppMessage::query()->whereKey($nachrichtId)->value('status') === 'failed';
    }

    private static function gefiltert(array $zeilen, string $suche): array
    {
        $suche = mb_strtolower(trim($suche));
        if ($suche === '') {
            return array_values($zeilen);
        }

        return array_values(array_filter($zeilen, fn (array $z) => str_contains(mb_strtolower($z['name']), $suche)));
    }

    private function portalAuth(): PortalAuth
    {
        return $this->auth ?? new PortalAuth();
    }
}
```

Hinweis zur Mensch-Grenze: Eine Dispo-Sperre wird für die Liste an den aktiven Akten des Menschen im Team abgelesen (Personen-Klammer). Die Entsperrung selbst nimmt die Dispo-Gruppe (`PortalEntsperrung`, GD-9) — eine Sperre an einer Gruppen-Akte außerhalb der Klammer wird dadurch mit entsperrt.

- [ ] **Step 4: Tests laufen lassen — grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HrMitarbeiterListenTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/HrMitarbeiterListen.php tests/Integration/HrMitarbeiterListenTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): HrMitarbeiterListen — Antraege, Gesperrt, Erinnert, Pilot, Abzeichen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 7: Reiter „Mitarbeiter“ am HR-Schreibtisch (Kind-Komponente, Abzeichen, Aktionen)

**Files:**
- Create: `src/Livewire/HrDesk/Mitarbeiter.php` (Alias automatisch `recruiting.hr-desk.mitarbeiter`, `RecruitingServiceProvider::registerLivewireComponents`)
- Create: `resources/views/livewire/hr-desk/mitarbeiter.blade.php`
- Create: `resources/views/livewire/hr-desk/partials/mensch.blade.php`, `resources/views/livewire/hr-desk/partials/seiten.blade.php`
- Modify: `src/Livewire/HrDesk/Index.php` — Imports (:5-23), Property nach `$reasonFilter` (:47), neue Methoden vor `render()` (:658)
- Modify: `resources/views/livewire/hr-desk/index.blade.php` — nach Zeile 14 (`<x-ui-page-container width="full">`) und vor `{{-- Resolve-Modal --}}` (~:509)
- Test: `tests/Integration/HrDeskMitarbeiterTest.php`

**Interfaces:**
- Consumes: `HrKontoAktionen` (5: alle Aktionen, `sichtbar()`, `NICHT_GEFUNDEN`), `HrAktionErgebnis` (5), `HrMitarbeiterListen` (6: `antraege|gestoppte|gesperrt|erinnert|pilot|abzeichen`), `KontoStatus::FILTER`, `NummernwechselTafel::kurz` (3/4). Beide Dienste werden über `app()` geholt (Tests binden Instanzen mit `new PortalAuth($cache)`).
- Produces: Livewire `HrDesk\Mitarbeiter` mit `LISTEN`, `AKTIONEN` (Schlüssel = geschlossene Aktionsliste), `SEITE = 50`, `oeffne(string $aktion, ?int $personId = null, ?int $employeeId = null, string $bezug = ''): void`, `bestaetige(): void`, `schliesse(): void`, `zeigeListe(string)`, `zeigePilotFilter(string)`, `blaettern(int)`, `seitenZeilen(array): array`, `seitenAnzahl(array): int`, Computeds `antraege|gestoppte|gesperrt|erinnert|pilot|zahlen`; `HrDesk\Index::$tab` (`#[Url]`, 'faelle'|'mitarbeiter'), `Index::mitarbeiterAbzeichen(): int`.

- [ ] **Step 1: Failing tests schreiben** (`tests/Integration/HrDeskMitarbeiterTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\HrDesk\Index;
use Platform\Recruiting\Livewire\HrDesk\Mitarbeiter;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class HrDeskMitarbeiterTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $c = Container::getInstance();
        $c->instance(HrKontoAktionen::class, new HrKontoAktionen($this->portalAuth()));
        $c->instance(HrMitarbeiterListen::class, new HrMitarbeiterListen($this->portalAuth()));
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance(HrKontoAktionen::class);
        $c->forgetInstance(HrMitarbeiterListen::class);
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function mitAntrag(int $team = 7): int
    {
        $akte = $this->akte(['team_id' => $team, 'phone' => '+4915111111111']);

        return $this->person(['team_id' => $team, 'wechsel_neue_nummer' => '+4917098765432', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $akte);
    }

    /** Review Focus 2: $wire.set('aktionPersonId', …) wirft — weil die Properties #[Locked] sind. */
    public function test_aktionskontext_ist_gegen_wire_set_gesperrt(): void
    {
        foreach (['aktion', 'aktionPersonId', 'aktionEmployeeId', 'aktionBezug', 'erwartetBeantragtAt', 'aktionWarnung', 'modalFehler', 'meldung', 'meldungStufe'] as $name) {
            $attribute = (new \ReflectionProperty(Mitarbeiter::class, $name))->getAttributes(Locked::class);
            $this->assertCount(1, $attribute, "{$name} ist nicht #[Locked]");
        }
        $this->assertCount(0, (new \ReflectionProperty(Mitarbeiter::class, 'notiz'))->getAttributes(Locked::class), 'die Notiz tippt HR');
    }

    /** Review Focus 2: Methoden-Parameter sind Eingabe — die Scope-Pruefung greift. */
    public function test_oeffne_mit_person_aus_fremdem_team_meldet_nicht_gefunden(): void
    {
        $fremd = $this->mitAntrag(8);
        $c = new Mitarbeiter();

        $c->oeffne('stoppen', $fremd);

        $this->assertFalse($c->modalOffen);
        $this->assertNull($c->aktionPersonId);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $c->meldung);
        $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
    }

    public function test_unbekannte_aktion_oeffnet_nichts_und_bestaetige_ohne_modal_tut_nichts(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();

        $c->oeffne('loeschen', $id);
        $this->assertFalse($c->modalOffen);

        $c->notiz = 'Eine ausreichend lange Notiz';
        $c->bestaetige();
        $this->assertCount(0, $this->vermerke());
    }

    public function test_freigeben_merkt_das_gesehene_beantragt_at_und_wirkt(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();

        $c->oeffne('freigeben', $id);

        $this->assertTrue($c->modalOffen);
        $this->assertSame('2026-10-12 09:00:00', $c->erwartetBeantragtAt);
        $this->assertStringContainsString('…5432', $c->aktionWarnung);

        $c->notiz = 'Telefonat mit Ausweis-Abgleich.';
        $c->bestaetige();

        $this->assertFalse($c->modalOffen);
        $this->assertSame(HrAktionErgebnis::OK, $c->meldungStufe);
        $this->assertSame('+4917098765432', $this->personZeile($id)->phone);
        $this->assertSame('', $c->notiz, 'Notiz nach Erfolg geleert');
    }

    public function test_zwischenzeitlich_neu_gestellter_antrag_wird_nicht_freigegeben(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();
        $c->oeffne('freigeben', $id);
        DB::table('rec_persons')->where('id', $id)->update(['wechsel_beantragt_at' => '2026-10-12 09:30:00', 'wechsel_neue_nummer' => '+4917011111111']);

        $c->notiz = 'Telefonat mit Ausweis-Abgleich.';
        $c->bestaetige();

        $this->assertSame(HrAktionErgebnis::HINWEIS, $c->meldungStufe);
        $this->assertSame('+4915111111111', $this->personZeile($id)->phone);
    }

    public function test_fehler_haelt_das_modal_offen(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();
        $c->oeffne('stoppen', $id);

        $c->notiz = 'kurz';
        $c->bestaetige();

        $this->assertTrue($c->modalOffen);
        $this->assertStringContainsString('mindestens 5 Zeichen', $c->modalFehler);
        $this->assertCount(0, $this->vermerke());
    }

    public function test_portal_entsperren_ueber_die_akte_ohne_person(): void
    {
        Container::getInstance()->instance(\Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver::class, new class extends \Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
        $akte = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $c = new Mitarbeiter();

        $c->oeffne('portal_entsperren', null, $akte->id);
        $c->notiz = 'Dispo hat Rückmeldung bekommen.';
        $c->bestaetige();

        $this->assertSame(HrAktionErgebnis::OK, $c->meldungStufe);
        $this->assertNull(DB::table('rec_employees')->where('id', $akte->id)->value('portal_locked_at'));
        Container::getInstance()->forgetInstance(\Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver::class);
    }

    public function test_seiten_zu_je_50_und_zahlen_je_unterreiter(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->person(['locked_at' => now()], $this->akte());
        }
        $c = new Mitarbeiter();
        $c->zeigeListe('gesperrt');

        $zeilen = $c->gesperrt();
        $this->assertCount(60, $zeilen);
        $this->assertCount(50, $c->seitenZeilen($zeilen));
        $this->assertSame(2, $c->seitenAnzahl($zeilen));
        $c->blaettern(2);
        $this->assertCount(10, $c->seitenZeilen($zeilen));
        $this->assertSame(60, $c->zahlen()['gesperrt']);

        $c->zeigeListe('quatsch');
        $this->assertSame('antraege', $c->liste);
        $this->assertSame(1, $c->seite);
    }

    public function test_reiter_und_abzeichen_am_schreibtisch(): void
    {
        $this->mitAntrag();
        $this->person(['locked_at' => now()], $this->akte());
        $index = new Index();

        $this->assertSame('faelle', $index->tab);
        $this->assertSame(2, $index->mitarbeiterAbzeichen());

        $index->tab = 'quatsch';
        $index->updatedTab();
        $this->assertSame('faelle', $index->tab);
    }
}
```

- [ ] **Step 2: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HrDeskMitarbeiterTest`
Expected: FAIL — `Class "Platform\Recruiting\Livewire\HrDesk\Mitarbeiter" not found`.

- [ ] **Step 3: Komponente schreiben** (`src/Livewire/HrDesk/Mitarbeiter.php`)

```php
<?php

namespace Platform\Recruiting\Livewire\HrDesk;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\KontoStatus;
use Platform\Recruiting\Support\NummernwechselTafel;

/**
 * Reiter "Mitarbeiter" am HR-Schreibtisch (Spec HR-Bereich 2026-10-12 §3).
 * Eigene Kind-Komponente, weil HrDesk\Index im Modul nicht instanziierbar
 * ist (Muster Dispo\HrDeclines). Liest nur HrMitarbeiterListen, schreibt nur
 * ueber HrKontoAktionen.
 *
 * SICHERHEIT (Spec §9.2): der Kontext eines offenen Modals ist #[Locked] und
 * wird nur in oeffne() serverseitig gesetzt; die Parameter von oeffne() sind
 * Eingabe und werden gegen das Team geprueft; bestaetige() prueft erneut
 * (HrKontoAktionen). Erlaubte Aktionen: geschlossene Liste AKTIONEN.
 */
class Mitarbeiter extends Component
{
    public const LISTEN = [
        'antraege' => 'Konto-Anträge',
        'gesperrt' => 'Gesperrt',
        'erinnert' => 'Erinnert, aber keine Reaktion',
        'pilot'    => 'Pilot-Überblick',
    ];

    /** Geschlossene Liste: Aktion => Modal-Titel. */
    public const AKTIONEN = [
        'stoppen'                => 'Antrag stoppen',
        'freigeben'              => 'Antrag freigeben',
        'konto_entsperren'       => 'Konto entsperren',
        'portal_entsperren'      => 'Portal entsperren',
        'bremse_loesen'          => 'Anmelde-Bremse lösen',
        'notfallsperre_aufheben' => 'Notfallsperre aufheben',
        'kontaktiert'            => 'Kontaktiert',
        'erneut_erinnern'        => 'Erneut erinnern',
    ];

    public const SEITE = 50;

    #[Url] public string $liste = 'antraege';
    #[Url] public string $pilotFilter = 'alle';
    public string $suche = '';
    public bool $ruhendeZeigen = false;
    public bool $gestoppteZeigen = false;
    public int $seite = 1;

    public bool $modalOffen = false;
    #[Locked] public string $aktion = '';
    #[Locked] public ?int $aktionPersonId = null;
    #[Locked] public ?int $aktionEmployeeId = null;
    #[Locked] public string $aktionBezug = '';
    #[Locked] public string $erwartetBeantragtAt = '';
    #[Locked] public string $aktionWarnung = '';
    #[Locked] public string $modalFehler = '';
    public string $notiz = '';

    #[Locked] public string $meldung = '';
    #[Locked] public string $meldungStufe = '';

    public function placeholder(): string
    {
        return '<div class="py-8 text-center text-sm text-[var(--ui-muted)]">Mitarbeiter-Listen werden geladen …</div>';
    }

    public function zeigeListe(string $liste): void
    {
        $this->liste = array_key_exists($liste, self::LISTEN) ? $liste : 'antraege';
        $this->seite = 1;
    }

    public function zeigePilotFilter(string $filter): void
    {
        $this->pilotFilter = array_key_exists($filter, KontoStatus::FILTER) ? $filter : 'alle';
        $this->seite = 1;
    }

    public function updatedSuche(): void
    {
        $this->seite = 1;
    }

    public function blaettern(int $seite): void
    {
        $this->seite = max(1, $seite);
    }

    public function seitenZeilen(array $zeilen): array
    {
        return array_slice($zeilen, ($this->seite - 1) * self::SEITE, self::SEITE);
    }

    public function seitenAnzahl(array $zeilen): int
    {
        return max(1, (int) ceil(count($zeilen) / self::SEITE));
    }

    #[Computed]
    public function antraege(): array
    {
        return $this->listen()->antraege($this->teamId(), $this->suche);
    }

    #[Computed]
    public function gestoppte(): array
    {
        return $this->listen()->gestoppte($this->teamId());
    }

    #[Computed]
    public function gesperrt(): array
    {
        // Die Drossel (Cache) erst, wenn der Unter-Reiter offen ist (Spec §3.2).
        return $this->listen()->gesperrt($this->teamId(), $this->suche, $this->liste === 'gesperrt');
    }

    #[Computed]
    public function erinnert(): array
    {
        return $this->listen()->erinnert($this->teamId(), $this->suche, $this->ruhendeZeigen);
    }

    #[Computed]
    public function pilot(): array
    {
        return $this->listen()->pilot($this->teamId(), $this->suche, $this->pilotFilter);
    }

    /** @return array<string,int> Zahl je Unter-Reiter (ohne Suche). */
    #[Computed]
    public function zahlen(): array
    {
        $teamId = $this->teamId();
        $l = $this->listen();

        return [
            'antraege' => count($l->antraege($teamId)),
            'gesperrt' => count($l->gesperrt($teamId, '', $this->liste === 'gesperrt')),
            'erinnert' => count($l->erinnert($teamId)),
            'pilot'    => $l->pilot($teamId)['zahlen']['alle'],
        ];
    }

    public function oeffne(string $aktion, ?int $personId = null, ?int $employeeId = null, string $bezug = ''): void
    {
        $this->schliesse();
        if (!array_key_exists($aktion, self::AKTIONEN)) {
            return;
        }
        $teamId = $this->teamId();
        if (!HrKontoAktionen::sichtbar($teamId, $personId, $employeeId)) {
            $this->meldung = HrKontoAktionen::NICHT_GEFUNDEN;
            $this->meldungStufe = HrAktionErgebnis::FEHLER;

            return;
        }

        if ($aktion === 'freigeben') {
            $antrag = DB::table('rec_persons')->where('id', $personId)->first(['wechsel_beantragt_at', 'wechsel_neue_nummer']);
            if ($antrag === null || $antrag->wechsel_beantragt_at === null) {
                $this->meldung = 'Der Antrag ist nicht mehr offen.';
                $this->meldungStufe = HrAktionErgebnis::HINWEIS;

                return;
            }
            $this->erwartetBeantragtAt = (string) $antrag->wechsel_beantragt_at;
            $this->aktionWarnung = sprintf(
                'Die Nummer wechselt sofort auf …%s. Wer diesen Antrag gestellt hat, kann danach mit „Passwort vergessen“ ein neues Passwort setzen. Nur freigeben, wenn Sie mit der Person gesprochen haben.',
                NummernwechselTafel::kurz((string) $antrag->wechsel_neue_nummer),
            );
        }

        $this->aktion = $aktion;
        $this->aktionPersonId = $personId;
        $this->aktionEmployeeId = $employeeId;
        $this->aktionBezug = $bezug;
        $this->modalOffen = true;
    }

    public function bestaetige(): void
    {
        if (!$this->modalOffen || !array_key_exists($this->aktion, self::AKTIONEN)) {
            return;
        }
        $a = app(HrKontoAktionen::class);
        $pid = (int) $this->aktionPersonId;
        $eid = (int) $this->aktionEmployeeId;

        $e = match ($this->aktion) {
            'stoppen'                => $a->stoppeAntrag($pid, $this->notiz),
            'freigeben'              => $a->gibAntragFrei($pid, $this->erwartetBeantragtAt, $this->notiz),
            'konto_entsperren'       => $a->entsperreKonto($pid, $this->notiz),
            'portal_entsperren'      => $a->entsperrePortal($eid, $this->notiz),
            'bremse_loesen'          => $a->loeseBremse($pid, $this->notiz),
            'notfallsperre_aufheben' => $a->hebeNotfallSperreAuf($pid, $this->notiz),
            'kontaktiert'            => $a->kontaktiert($this->aktionPersonId, $this->aktionEmployeeId, $this->notiz),
            'erneut_erinnern'        => $a->erinnereErneut($this->aktionPersonId, $this->aktionEmployeeId, $this->aktionBezug, $this->notiz),
        };

        if ($e->stufe === HrAktionErgebnis::FEHLER) {
            $this->modalFehler = $e->meldung;

            return;
        }

        $this->schliesse();
        $this->meldung = $e->meldung;
        $this->meldungStufe = $e->stufe;
        unset($this->antraege, $this->gestoppte, $this->gesperrt, $this->erinnert, $this->pilot, $this->zahlen);
    }

    public function schliesse(): void
    {
        $this->modalOffen = false;
        $this->aktion = '';
        $this->aktionPersonId = null;
        $this->aktionEmployeeId = null;
        $this->aktionBezug = '';
        $this->erwartetBeantragtAt = '';
        $this->aktionWarnung = '';
        $this->modalFehler = '';
        $this->notiz = '';
        $this->meldung = '';
        $this->meldungStufe = '';
    }

    public function render()
    {
        return view('recruiting::livewire.hr-desk.mitarbeiter');
    }

    private function listen(): HrMitarbeiterListen
    {
        return app(HrMitarbeiterListen::class);
    }

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }
}
```

- [ ] **Step 4: View schreiben** (`resources/views/livewire/hr-desk/mitarbeiter.blade.php`)

```blade
<div>
    @php
        $zahlen = $this->zahlen;
        $meldungKlasse = match ($meldungStufe) {
            'ok' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
            'hinweis' => 'bg-amber-50 border-amber-200 text-amber-900',
            'fehler' => 'bg-red-50 border-red-200 text-red-800',
            default => '',
        };
        $aktivKlasse = 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]';
        $inaktivKlasse = 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]';
        $knopf = 'rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50';
    @endphp

    @if ($meldung !== '')
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $meldungKlasse }}">{{ $meldung }}</div>
    @endif

    {{-- Unter-Reiter mit Zahl --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (\Platform\Recruiting\Livewire\HrDesk\Mitarbeiter::LISTEN as $schluessel => $titel)
            <button type="button" wire:click="zeigeListe('{{ $schluessel }}')"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors {{ $liste === $schluessel ? $aktivKlasse : $inaktivKlasse }}">
                {{ $titel }} <span class="ml-1 opacity-70">({{ $zahlen[$schluessel] ?? 0 }})</span>
            </button>
        @endforeach
        <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Name suchen …"
               class="ml-auto w-56 rounded-md border border-[var(--ui-border)] px-3 py-1.5 text-sm">
    </div>

    @if ($liste === 'antraege')
        @php
            $alle = $this->antraege;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Kein offener Konto-Antrag.</div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[var(--ui-border)] bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-[var(--ui-muted-5)] text-left text-xs uppercase text-[var(--ui-muted)]">
                        <tr><th class="px-3 py-2">Person</th><th class="px-3 py-2">alt</th><th class="px-3 py-2">neu</th><th class="px-3 py-2">beantragt</th><th class="px-3 py-2">wirksam ab</th><th class="px-3 py-2">Quelle</th><th class="px-3 py-2">Zustand</th><th class="px-3 py-2">Aktion</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($zeilen as $z)
                            <tr wire:key="antrag-{{ $z['person_id'] }}">
                                <td class="px-3 py-2">
                                    @include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])
                                </td>
                                <td class="px-3 py-2 tabular-nums">{{ $z['alt'] }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $z['neu'] }}</td>
                                <td class="px-3 py-2">{{ $z['beantragt'] }}</td>
                                <td class="px-3 py-2">{{ $z['wirksam'] }}</td>
                                <td class="px-3 py-2">{{ $z['quelle_text'] }}</td>
                                <td class="px-3 py-2">{{ $z['zustand'] }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <button type="button" class="{{ $knopf }}" wire:click="oeffne('stoppen', {{ $z['person_id'] }})" wire:loading.attr="disabled" wire:target="oeffne">Stoppen</button>
                                    @if ($z['kann_freigeben'])
                                        <button type="button" class="{{ $knopf }} ml-1 border-red-300 text-red-700" wire:click="oeffne('freigeben', {{ $z['person_id'] }})" wire:loading.attr="disabled" wire:target="oeffne">Freigeben</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif

        @php
            $gestoppte = $this->gestoppte;
        @endphp
        <div class="mt-4">
            <button type="button" wire:click="$toggle('gestoppteZeigen')" class="text-xs font-semibold text-blue-700 hover:underline">
                Gestoppte Anträge (30 Tage) und Notfallsperren ({{ count($gestoppte['gestoppt']) + count($gestoppte['notfallsperren']) }})
            </button>
            @if ($gestoppteZeigen)
                <div class="mt-2 space-y-1 rounded-lg border border-[var(--ui-border)] bg-white p-3 text-sm">
                    @foreach ($gestoppte['gestoppt'] as $g)
                        <div>{{ $g['name'] }} — gestoppt am {{ $g['am'] }} von {{ $g['von'] }}: <span class="text-[var(--ui-muted)]">{{ $g['notiz'] }}</span></div>
                    @endforeach
                    @foreach ($gestoppte['notfallsperren'] as $s)
                        <div>{{ $s['name'] }} — Notfallweg gesperrt bis {{ $s['bis'] }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($liste === 'gesperrt')
        @php
            $alle = $this->gesperrt;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Niemand ist gesperrt.</div>
        @else
            <div class="divide-y divide-gray-100 rounded-lg border border-[var(--ui-border)] bg-white">
                @foreach ($zeilen as $z)
                    @php
                        $pid = $z['person_id'] === null ? 'null' : (string) $z['person_id'];
                    @endphp
                    <div wire:key="gesperrt-{{ $z['schluessel'] }}" class="flex flex-wrap items-start gap-3 px-4 py-3">
                        <div class="min-w-[14rem]">@include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])</div>
                        <div class="flex flex-1 flex-col gap-1.5">
                            @foreach ($z['gruende'] as $g)
                                @php
                                    $eid = $g['employee_id'] === null ? 'null' : (string) $g['employee_id'];
                                    $ziel = $g['aktion'] === 'portal_entsperren' ? 'null, ' . $eid : $pid;
                                @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700">{{ $g['text'] }}</span>
                                    @if ($g['aktion'] !== null)
                                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('{{ $g['aktion'] }}', {{ $ziel }})" wire:loading.attr="disabled" wire:target="oeffne">{{ $g['knopf'] }}</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif
    @endif

    @if ($liste === 'erinnert')
        @php
            $alle = $this->erinnert;
            $zeilen = $this->seitenZeilen($alle);
        @endphp
        <label class="mb-3 inline-flex items-center gap-2 text-sm text-[var(--ui-secondary)]">
            <input type="checkbox" wire:model.live="ruhendeZeigen" class="h-4 w-4 rounded border-[var(--ui-border)]">
            Ruhende zeigen
        </label>
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Niemand wartet auf einen Anruf.</div>
        @else
            <div class="divide-y divide-gray-100 rounded-lg border border-[var(--ui-border)] bg-white">
                @foreach ($zeilen as $z)
                    @php
                        $pid = $z['person_id'] === null ? 'null' : (string) $z['person_id'];
                        $eid = $z['person_id'] === null ? (string) $z['employee_id'] : 'null';
                        $grau = $z['ruht'] ? 'opacity-50' : '';
                    @endphp
                    <div wire:key="erinnert-{{ $z['schluessel'] }}" class="flex flex-wrap items-start gap-3 px-4 py-3 {{ $grau }}">
                        <div class="min-w-[14rem]">
                            @include('recruiting::livewire.hr-desk.partials.mensch', ['z' => $z])
                            <div class="mt-0.5 text-xs text-[var(--ui-muted)]">nächster Einsatz: {{ $z['naechster_text'] }}</div>
                            @if ($z['ruhe_hinweis'] !== null)
                                <div class="mt-0.5 text-xs text-amber-800">{{ $z['ruhe_hinweis'] }}</div>
                            @endif
                        </div>
                        <div class="flex flex-1 flex-col gap-1.5">
                            @foreach ($z['chips'] as $chip)
                                @php
                                    $chipFarbe = $chip['rot'] ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700';
                                @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs font-medium {{ $chipFarbe }}" title="{{ $chip['tooltip'] }}">{{ $chip['text'] }}</span>
                                    @if ($chip['kann_erinnern'])
                                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('erneut_erinnern', {{ $pid }}, {{ $eid }}, '{{ $chip['bezug'] }}')" wire:loading.attr="disabled" wire:target="oeffne">Erneut erinnern</button>
                                    @else
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $chip['grau_text'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('kontaktiert', {{ $pid }}, {{ $eid }})" wire:loading.attr="disabled" wire:target="oeffne">Kontaktiert</button>
                    </div>
                @endforeach
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($alle)])
        @endif
    @endif

    @if ($liste === 'pilot')
        @php
            $pilot = $this->pilot;
            $zeilen = $this->seitenZeilen($pilot['zeilen']);
        @endphp
        <div class="mb-3 flex flex-wrap gap-2">
            @foreach (\Platform\Recruiting\Support\KontoStatus::FILTER as $schluessel => $titel)
                <button type="button" wire:click="zeigePilotFilter('{{ $schluessel }}')"
                        class="rounded-lg border px-2.5 py-1 text-xs font-medium {{ $pilotFilter === $schluessel ? $aktivKlasse : $inaktivKlasse }}">
                    {{ $titel }} <span class="opacity-70">({{ $pilot['zahlen'][$schluessel] ?? 0 }})</span>
                </button>
            @endforeach
        </div>
        @if ($zeilen === [])
            <div class="rounded-lg border border-dashed border-[var(--ui-border)]/40 py-12 text-center text-sm text-[var(--ui-muted)]">Keine Einträge.</div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[var(--ui-border)] bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-[var(--ui-muted-5)] text-left text-xs uppercase text-[var(--ui-muted)]">
                        <tr><th class="px-3 py-2">Person</th><th class="px-3 py-2">im Pilot seit</th><th class="px-3 py-2">Konto</th><th class="px-3 py-2">registriert</th><th class="px-3 py-2">letzte Anmeldung</th><th class="px-3 py-2">altes Portal zuletzt</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($zeilen as $z)
                            <tr wire:key="pilot-{{ $z['schluessel'] }}">
                                <td class="px-3 py-2">
                                    <a href="{{ $z['link'] }}" class="font-semibold text-blue-700 hover:underline">{{ $z['name'] }}</a>
                                    @if ($z['ohne_person'])
                                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10.5px] text-amber-800">keine Personen-Zeile — recruiting:personen-anlegen</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $z['pilot_seit'] }}</td>
                                <td class="px-3 py-2">{{ $z['status_text'] }}</td>
                                <td class="px-3 py-2">{{ $z['registriert'] }}</td>
                                <td class="px-3 py-2">{{ $z['letzte_anmeldung'] }}</td>
                                <td class="px-3 py-2">{{ $z['altes_portal'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('recruiting::livewire.hr-desk.partials.seiten', ['anzahl' => $this->seitenAnzahl($pilot['zeilen'])])
        @endif
    @endif

    {{-- Aktions-Modal: Pflichtnotiz (ausser Erneut erinnern) --}}
    @php
        $titel = \Platform\Recruiting\Livewire\HrDesk\Mitarbeiter::AKTIONEN[$aktion] ?? '';
        $notizLabel = $aktion === 'erneut_erinnern' ? 'Notiz (optional)' : 'Notiz (Pflicht, 5–500 Zeichen)';
        $gefaehrlich = $aktion === 'freigeben';
    @endphp
    <x-ui-modal size="md" model="modalOffen">
        <x-slot name="header">{{ $titel }}</x-slot>
        <div class="space-y-3 p-4">
            @if ($aktionWarnung !== '')
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">{{ $aktionWarnung }}</div>
            @endif
            @if ($modalFehler !== '')
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $modalFehler }}</div>
            @endif
            <label class="block text-sm font-medium text-[var(--ui-secondary)]">{{ $notizLabel }}</label>
            <textarea wire:model="notiz" rows="3" maxlength="500"
                      class="w-full rounded-md border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm"></textarea>
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary-outline" wire:click="schliesse">Abbrechen</x-ui-button>
            @if ($gefaehrlich)
                <x-ui-button variant="danger" wire:click="bestaetige" wire:loading.attr="disabled" wire:target="bestaetige">Freigeben</x-ui-button>
            @else
                <x-ui-button variant="primary" wire:click="bestaetige" wire:loading.attr="disabled" wire:target="bestaetige">Bestätigen</x-ui-button>
            @endif
        </x-slot>
    </x-ui-modal>
</div>
```

`resources/views/livewire/hr-desk/partials/mensch.blade.php` (Name + Akten-Chips „RG · MA“):

```blade
<div class="text-sm font-semibold text-gray-900">{{ $z['name'] }}</div>
<div class="mt-0.5 flex flex-wrap gap-1">
    @foreach ($z['akten'] as $akte)
        @php
            $aktenKlasse = $akte['aktiv'] ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500 line-through';
        @endphp
        <a href="{{ route('recruiting.employees.show', ['employee' => $akte['id']]) }}" class="rounded px-1.5 py-0.5 text-[10.5px] font-semibold {{ $aktenKlasse }}">{{ $akte['firma'] }}</a>
    @endforeach
</div>
```

`resources/views/livewire/hr-desk/partials/seiten.blade.php`:

```blade
@if ($anzahl > 1)
    <div class="mt-3 flex items-center gap-2 text-sm">
        @for ($i = 1; $i <= $anzahl; $i++)
            <button type="button" wire:click="blaettern({{ $i }})"
                    class="rounded border px-2 py-0.5 {{ $seite === $i ? 'border-[var(--ui-primary)] text-[var(--ui-primary)]' : 'border-gray-300 text-gray-600' }}">{{ $i }}</button>
        @endfor
    </div>
@endif
```

- [ ] **Step 5: `Index` erweitern**

`src/Livewire/HrDesk/Index.php` — Imports ergänzen:

```php
use Livewire\Attributes\Url;
use Platform\Recruiting\Services\HrMitarbeiterListen;
```

Nach `public string $reasonFilter = 'all';` (:47):

```php
    /** Reiter: 'faelle' (Bewerber-Faelle wie bisher) oder 'mitarbeiter' (Spec HR-Bereich §3). */
    #[Url]
    public string $tab = 'faelle';
```

Vor `render()` (:658):

```php
    public function updatedTab(): void
    {
        if (!in_array($this->tab, ['faelle', 'mitarbeiter'], true)) {
            $this->tab = 'faelle';
        }
    }

    /**
     * Zaehler am Reiter "Mitarbeiter": offene Konto-Antraege + Gesperrte
     * (ohne Drossel — die endet nach 15 Minuten von selbst). Er macht aus den
     * 24 Stunden von Weg 4 erst ein Stopp-Recht (Spec §3).
     */
    #[Computed]
    public function mitarbeiterAbzeichen(): int
    {
        return app(HrMitarbeiterListen::class)->abzeichen((int) Auth::user()->currentTeam->id);
    }
```

`resources/views/livewire/hr-desk/index.blade.php` — direkt nach Zeile 14 (`<x-ui-page-container width="full">`) einfügen:

```blade
        @php
            $mitarbeiterZahl = $this->mitarbeiterAbzeichen;
            $reiterAktiv = 'border-blue-600 text-blue-700';
            $reiterInaktiv = 'border-transparent text-gray-500 hover:text-gray-700';
        @endphp
        <div class="mb-4 flex items-center gap-1 border-b border-gray-200">
            <button type="button" wire:click="$set('tab', 'faelle')"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $tab === 'faelle' ? $reiterAktiv : $reiterInaktiv }}">Fälle</button>
            <button type="button" wire:click="$set('tab', 'mitarbeiter')"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $tab === 'mitarbeiter' ? $reiterAktiv : $reiterInaktiv }}">
                Mitarbeiter
                @if ($mitarbeiterZahl > 0)
                    <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-red-700 tabular-nums">{{ $mitarbeiterZahl }}</span>
                @endif
            </button>
        </div>

        @if ($tab === 'mitarbeiter')
            <livewire:recruiting.hr-desk.mitarbeiter lazy />
        @else
```

und unmittelbar vor der Zeile `        {{-- Resolve-Modal --}}` einfügen:

```blade
        @endif
```

- [ ] **Step 6: Tests und Blade-Prüfung — grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'HrDeskMitarbeiterTest|BladeCompileIntegrityTest|HrDesk'`
Expected: PASS.
Run: `php tools/blade-check.php resources/views/livewire/hr-desk/mitarbeiter.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/partials/mensch.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/partials/seiten.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/index.blade.php`
Expected: Exit 0, keine Funde.

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/HrDesk/Mitarbeiter.php src/Livewire/HrDesk/Index.php resources/views/livewire/hr-desk tests/Integration/HrDeskMitarbeiterTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): HR-Schreibtisch-Reiter Mitarbeiter mit vier Listen und Abzeichen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 8: Konto-Kasten in der Mitarbeiterakte (+ `Show::unlockPortal()` raus)

**Files:**
- Modify: `src/Services/HrMitarbeiterListen.php` — neue Methode `kasten()` nach `abzeichen()`; `use Platform\Recruiting\Models\RecHrVermerk;`
- Create: `src/Livewire/Employees/KontoKasten.php` (Alias `recruiting.employees.konto-kasten`)
- Create: `resources/views/livewire/employees/konto-kasten.blade.php`
- Modify: `src/Livewire/Employees/Show.php` — Methode `unlockPortal()` samt Docblock (Docblock beginnt mit „HR-Entsperrung des MA-Portal-Zugangs“, ~:596-623) löschen
- Modify: `resources/views/livewire/employees/show.blade.php` — Knopf im roten Kasten (:220-226), Einbettung vor `{{-- Felder — responsive grid` (~:229)
- Modify: `src/Services/PortalAuth.php` — Docblock von `istDispoGesperrt()` (:236): „(Show.php::unlockPortal, Zeile 354)“ → „(PortalEntsperrung::fuerAnstellung)“
- Test: `tests/Integration/KontoKastenTest.php`

**Interfaces:**
- Consumes: `HrMitarbeiterListen::sperrGruende()/menschen()` (6), `HrKontoAktionen` (5), `RecHrVermerk::letzte()/ART_LABELS` (1), `KontoStatus` (4), `SperrGruende::DISPO` (4).
- Produces:
  - `HrMitarbeiterListen::kasten(int $teamId, int $employeeId): ?array` → `null` (Akte nicht im Team) oder `array{ohne_person:bool, stillgelegt_ziel:?int, person_id:?int, status_code:?string, status_text:string, nummer:string, letzte_anmeldung:string, altes_portal:string, pilot:string, gruende:list<array>, antrag_offen:bool, verlauf:list<array{wann:string, was:string, wer:string, notiz:?string, ergebnis:?string}>, kann_einladen:bool, kann_zuruecksetzen:bool, knoepfe_aus:bool}`
  - Livewire `Employees\KontoKasten` mit `#[Locked] int $employeeId`, `AKTIONEN`, `oeffne(string $aktion)`, `oeffnePortalEntsperren()` (`#[On('konto-kasten-portal-entsperren')]`), `bestaetige()`, `schliesse()`, `schliesseEinladung()`, Computed `kasten()`; Event-Name `konto-kasten-portal-entsperren` (Show → Kasten).

- [ ] **Step 1: Failing tests schreiben** (`tests/Integration/KontoKastenTest.php`)

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\KontoKasten;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class KontoKastenTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $c = Container::getInstance();
        $c->instance(HrKontoAktionen::class, new HrKontoAktionen($this->portalAuth()));
        $c->instance(HrMitarbeiterListen::class, new HrMitarbeiterListen($this->portalAuth()));
        $c->instance(DispoIdentityResolver::class, new class extends DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([HrKontoAktionen::class, HrMitarbeiterListen::class, DispoIdentityResolver::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function kasten(int $employeeId): KontoKasten
    {
        $k = new KontoKasten();
        $k->mount($employeeId);

        return $k;
    }

    public function test_akten_kennung_und_einladung_sind_locked(): void
    {
        foreach (['employeeId', 'aktion', 'aktionEmployeeId', 'einladungsLink', 'einladungsCode', 'meldung', 'meldungStufe', 'modalFehler'] as $name) {
            $this->assertCount(1, (new \ReflectionProperty(KontoKasten::class, $name))->getAttributes(Locked::class), $name);
        }
    }

    public function test_akte_aus_fremdem_team_zeigt_nichts_und_handelt_nicht(): void
    {
        $fremd = $this->akte(['team_id' => 8, 'birth_date' => '1990-05-17']);
        $this->person(['team_id' => 8], $fremd);
        $k = $this->kasten($fremd->id);

        $this->assertNull($k->kasten());
        $k->oeffne('einladung_neu');
        $this->assertFalse($k->modalOffen);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $k->meldung);
        $this->assertCount(0, $this->vermerke());
    }

    public function test_ohne_personen_zeile_sind_alle_knoepfe_aus(): void
    {
        $akte = $this->akte();
        $daten = $this->kasten($akte->id)->kasten();

        $this->assertTrue($daten['ohne_person']);
        $this->assertTrue($daten['knoepfe_aus']);
        $this->assertFalse($daten['kann_einladen']);

        $k = $this->kasten($akte->id);
        $k->oeffne('einladung_neu');
        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();
        $this->assertStringContainsString('keine Personen-Zeile', $k->modalFehler);
        $this->assertNull($k->einladungsCode);
    }

    public function test_einladung_neu_zeigt_link_und_code_einmal_und_schliessen_leert(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $personId = $this->person([], $akte);
        $k = $this->kasten($akte->id);
        $this->assertTrue($k->kasten()['kann_einladen']);
        $this->assertSame('nicht eingeladen', $k->kasten()['status_text']);

        $k->oeffne('einladung_neu');
        $k->notiz = 'Einladung per Telefon angekündigt.';
        $k->bestaetige();

        $this->assertTrue($k->einladungOffen);
        $this->assertSame($personId, KontoWriter::personFuerEinladung((string) $k->einladungsCode));
        $this->assertStringContainsString((string) $k->einladungsCode, (string) $k->einladungsLink);
        $this->assertSame(HrAktionErgebnis::OK, $k->meldungStufe);
        $this->assertStringNotContainsString((string) $k->einladungsCode, $k->meldung);

        $k->schliesseEinladung();
        $this->assertFalse($k->einladungOffen);
        $this->assertNull($k->einladungsCode);
        $this->assertNull($k->einladungsLink);
    }

    public function test_zuruecksetzen_nur_fuer_registrierte(): void
    {
        $akte = $this->akte(['birth_date' => '1990-05-17']);
        $this->person(['password_hash' => Hash::make('ganz-geheim-2026'), 'registered_at' => '2026-10-05 10:00:00', 'letzte_anmeldung_at' => '2026-10-11 08:15:00'], $akte);

        $daten = $this->kasten($akte->id)->kasten();

        $this->assertSame('registriert am 05.10.2026', $daten['status_text']);
        $this->assertSame('11.10.2026, 08:15', $daten['letzte_anmeldung']);
        $this->assertTrue($daten['kann_zuruecksetzen']);
        $this->assertFalse($daten['kann_einladen']);
        $this->assertSame('im neuen Portal seit 01.10.2026', $daten['pilot']);
    }

    public function test_rote_box_der_akte_oeffnet_das_notiz_modal_des_kastens(): void
    {
        $this->assertFalse(method_exists(Show::class, 'unlockPortal'), 'ersetzt durch PortalEntsperrung (Spec §5.3)');
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/show.blade.php');
        $this->assertStringContainsString("\$dispatch('konto-kasten-portal-entsperren')", $blade);
        $this->assertStringContainsString('<livewire:recruiting.employees.konto-kasten', $blade);
        $this->assertStringContainsString('id="konto"', $blade);

        $ma = $this->akte();
        $rg = $this->akte(['company' => 'RG', 'portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: X']);
        $this->person([], $ma, $rg);
        $k = $this->kasten($ma->id);

        $k->oeffnePortalEntsperren();
        $this->assertSame('portal_entsperren', $k->aktion);
        $this->assertSame($rg->id, $k->aktionEmployeeId, 'die gesperrte Schwester-Akte, nicht die angezeigte');

        $k->notiz = 'Dispo hat bestätigt.';
        $k->bestaetige();
        $this->assertNull(DB::table('rec_employees')->where('id', $rg->id)->value('portal_locked_at'));
        $this->assertSame('portal_entsperrt', $this->vermerke()->sole()->art);
    }

    public function test_verlauf_zeigt_die_letzten_zehn_beider_akten_mit_benutzer(): void
    {
        DB::table('users')->insert(['id' => 42, 'name' => 'Hanna HR', 'email' => 'hanna@example.org', 'created_at' => now(), 'updated_at' => now()]);
        $ma = $this->akte();
        $rg = $this->akte(['company' => 'RG']);
        $personId = $this->person([], $ma, $rg);
        for ($i = 1; $i <= 12; $i++) {
            \Carbon\Carbon::setTestNow(sprintf('2026-10-12 10:%02d:00', $i));
            RecHrVermerk::anfuegen($this->team, $i % 2 ? $personId : null, $i % 2 ? null : $rg->id, 'kontaktiert', "Notiz {$i}", null, 'ok', null, 42);
        }

        $verlauf = $this->kasten($ma->id)->kasten()['verlauf'];

        $this->assertCount(10, $verlauf);
        $this->assertSame('Notiz 12', $verlauf[0]['notiz']);
        $this->assertSame('Hanna HR', $verlauf[0]['wer']);
        $this->assertSame('Kontaktiert', $verlauf[0]['was']);
    }

    public function test_stillgelegte_person_zeigt_die_zielzeile_und_keine_knoepfe(): void
    {
        $akte = $this->akte();
        $ziel = $this->person([], $this->akte());
        $this->person(['merged_into_person_id' => $ziel, 'phone' => '+4915177777777'], $akte);

        $daten = $this->kasten($akte->id)->kasten();

        $this->assertSame($ziel, $daten['stillgelegt_ziel']);
        $this->assertTrue($daten['knoepfe_aus']);
        $this->assertFalse($daten['kann_einladen']);
        $this->assertFalse($daten['kann_zuruecksetzen']);
    }
}
```

- [ ] **Step 2: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter KontoKastenTest`
Expected: FAIL — `Class "Platform\Recruiting\Livewire\Employees\KontoKasten" not found`.

- [ ] **Step 3: `HrMitarbeiterListen::kasten()` ergänzen** (nach `abzeichen()`, `use Platform\Recruiting\Models\RecHrVermerk;` ergänzen)

```php
    /** Daten des Konto-Kastens einer Akte (Spec HR-Bereich §4) — null, wenn die Akte nicht im Team ist. */
    public function kasten(int $teamId, int $employeeId): ?array
    {
        $akte = DB::table('rec_employees')->where('team_id', $teamId)->where('id', $employeeId)->first(self::AKTEN_SPALTEN);
        if ($akte === null) {
            return null;
        }
        $jetzt = now()->format('Y-m-d H:i:s');
        $person = $akte->rec_person_id !== null
            ? DB::table('rec_persons')->where('id', (int) $akte->rec_person_id)->where('team_id', $teamId)->first()
            : null;
        $akten = $person !== null
            ? DB::table('rec_employees')->where('team_id', $teamId)->where('rec_person_id', (int) $person->id)->orderBy('id')->get(self::AKTEN_SPALTEN)
            : collect([$akte]);
        $menschen = self::menschen($akten);
        $schluessel = $person !== null ? 'p:' . (int) $person->id : 'e:' . (int) $akte->id;
        $gruende = $this->sperrGruende($teamId, $menschen, $akten, true)[$schluessel] ?? [];

        $pilotSeit = $akten->pluck('portal_v2_since')->filter()->min();
        $altesPortal = $akten->flatMap(fn ($a) => [$a->portal_verified_at, $a->portal_last_seen_at])->filter()->max();
        $verlauf = RecHrVermerk::letzte($teamId, $person !== null ? (int) $person->id : null, $akten->pluck('id')->map(fn ($v) => (int) $v)->all(), 10)
            ->map(fn ($v) => [
                'wann'     => Carbon::parse((string) $v->created_at)->format('d.m.Y, H:i'),
                'was'      => RecHrVermerk::ART_LABELS[$v->art] ?? (string) $v->art,
                'wer'      => (string) ($v->benutzer ?? '—'),
                'notiz'    => $v->notiz,
                'ergebnis' => $v->ergebnis,
            ])->all();

        $stillgelegt = $person !== null && $person->merged_into_person_id !== null ? (int) $person->merged_into_person_id : null;
        $gesperrt = $person !== null && $person->locked_at !== null;
        $registriert = $person !== null && $person->password_hash !== null;
        $knoepfeAus = $person === null || $stillgelegt !== null;

        return [
            'ohne_person'        => $person === null,
            'stillgelegt_ziel'   => $stillgelegt,
            'person_id'          => $person !== null ? (int) $person->id : null,
            'status_code'        => $person !== null ? KontoStatus::code($person, $jetzt) : null,
            'status_text'        => $person !== null ? KontoStatus::text($person, $jetzt) : 'keine Personen-Zeile',
            'nummer'             => $person !== null && $person->phone !== null ? '…' . NummernwechselTafel::kurz((string) $person->phone) : '—',
            'letzte_anmeldung'   => $person !== null && $person->letzte_anmeldung_at !== null ? Carbon::parse((string) $person->letzte_anmeldung_at)->format('d.m.Y, H:i') : '—',
            'altes_portal'       => $altesPortal !== null ? Carbon::parse((string) $altesPortal)->format('d.m.Y, H:i') : '—',
            'pilot'              => $pilotSeit !== null ? 'im neuen Portal seit ' . Carbon::parse((string) $pilotSeit)->format('d.m.Y') : 'noch altes Portal',
            'gruende'            => $gruende,
            'antrag_offen'       => $person !== null && $person->wechsel_wirksam_ab !== null,
            'verlauf'            => $verlauf,
            'kann_einladen'      => !$knoepfeAus && !$gesperrt && !$registriert,
            'kann_zuruecksetzen' => !$knoepfeAus && !$gesperrt && $registriert,
            'knoepfe_aus'        => $knoepfeAus,
        ];
    }
```

- [ ] **Step 4: Komponente schreiben** (`src/Livewire/Employees/KontoKasten.php`)

```php
<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\SperrGruende;

/**
 * Konto-Kasten in der Mitarbeiterakte (Spec HR-Bereich §4). Eigene
 * Kind-Komponente, damit Show.php nicht waechst. Die Knoepfe liegen NUR hier
 * (der Pilot-Ueberblick verlinkt hierher). Schreibt ausschliesslich ueber
 * HrKontoAktionen.
 *
 * EINMAL-ANZEIGE (Spec §4.3): Link und Code kommen aus dem Ergebnis, stehen
 * in #[Locked]-Properties und werden beim Schliessen geleert — nie im Log,
 * nie im Vermerk, nie in der Meldung.
 */
class KontoKasten extends Component
{
    public const AKTIONEN = [
        'einladung_neu'          => 'Einladung neu',
        'konto_zuruecksetzen'    => 'Konto zurücksetzen',
        'portal_entsperren'      => 'Portal entsperren',
        'konto_entsperren'       => 'Konto entsperren',
        'bremse_loesen'          => 'Anmelde-Bremse lösen',
        'notfallsperre_aufheben' => 'Notfallsperre aufheben',
    ];

    #[Locked] public int $employeeId;

    public bool $modalOffen = false;
    #[Locked] public string $aktion = '';
    #[Locked] public ?int $aktionEmployeeId = null;
    #[Locked] public string $modalFehler = '';
    public string $notiz = '';

    #[Locked] public string $meldung = '';
    #[Locked] public string $meldungStufe = '';

    public bool $einladungOffen = false;
    #[Locked] public ?string $einladungsLink = null;
    #[Locked] public ?string $einladungsCode = null;

    public function mount(int $employeeId): void
    {
        $this->employeeId = $employeeId;
    }

    #[Computed]
    public function kasten(): ?array
    {
        return app(HrMitarbeiterListen::class)->kasten($this->teamId(), $this->employeeId);
    }

    /** Der rote Kasten "MA-Portal-Zugang gesperrt" in show.blade.php oeffnet dieses Modal. */
    #[On('konto-kasten-portal-entsperren')]
    public function oeffnePortalEntsperren(): void
    {
        $this->oeffne('portal_entsperren');
    }

    public function oeffne(string $aktion): void
    {
        $this->schliesse();
        if (!array_key_exists($aktion, self::AKTIONEN)) {
            return;
        }
        $kasten = $this->kasten();
        if ($kasten === null) {
            $this->meldung = HrKontoAktionen::NICHT_GEFUNDEN;
            $this->meldungStufe = HrAktionErgebnis::FEHLER;

            return;
        }
        if ($aktion === 'portal_entsperren') {
            // Die gesperrte Akte aus dem Grund (kann die Schwester-Akte sein), serverseitig gesetzt.
            $grund = collect($kasten['gruende'])->firstWhere('code', SperrGruende::DISPO);
            $this->aktionEmployeeId = (int) ($grund['employee_id'] ?? $this->employeeId);
        }
        $this->aktion = $aktion;
        $this->modalOffen = true;
    }

    public function bestaetige(): void
    {
        if (!$this->modalOffen || !array_key_exists($this->aktion, self::AKTIONEN)) {
            return;
        }
        $a = app(HrKontoAktionen::class);
        $personId = (int) (DB::table('rec_employees')->where('id', $this->employeeId)->value('rec_person_id') ?? 0);

        $e = match ($this->aktion) {
            'einladung_neu'          => $a->ladeNeuEin($this->employeeId, $this->notiz),
            'konto_zuruecksetzen'    => $a->setzeKontoZurueck($this->employeeId, $this->notiz),
            'portal_entsperren'      => $a->entsperrePortal((int) ($this->aktionEmployeeId ?? $this->employeeId), $this->notiz),
            'konto_entsperren'       => $a->entsperreKonto($personId, $this->notiz),
            'bremse_loesen'          => $a->loeseBremse($personId, $this->notiz),
            'notfallsperre_aufheben' => $a->hebeNotfallSperreAuf($personId, $this->notiz),
        };

        if ($e->stufe === HrAktionErgebnis::FEHLER) {
            $this->modalFehler = $e->meldung;

            return;
        }

        $this->schliesse();
        $this->meldung = $e->meldung;
        $this->meldungStufe = $e->stufe;
        if ($e->einladungsCode !== null) {
            $this->einladungsLink = $e->einladungsLink;
            $this->einladungsCode = $e->einladungsCode;
            $this->einladungOffen = true;
        }
        unset($this->kasten);
    }

    public function schliesse(): void
    {
        $this->modalOffen = false;
        $this->aktion = '';
        $this->aktionEmployeeId = null;
        $this->modalFehler = '';
        $this->notiz = '';
        $this->meldung = '';
        $this->meldungStufe = '';
    }

    public function schliesseEinladung(): void
    {
        $this->einladungOffen = false;
        $this->einladungsLink = null;
        $this->einladungsCode = null;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.konto-kasten');
    }

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }
}
```

- [ ] **Step 5: View schreiben** (`resources/views/livewire/employees/konto-kasten.blade.php`)

```blade
<div class="rounded-lg border border-[var(--ui-border)] bg-white p-4">
    @php
        $k = $this->kasten;
        $knopf = 'rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50';
        $meldungKlasse = match ($meldungStufe) {
            'ok' => 'bg-emerald-50 border-emerald-200 text-emerald-800',
            'hinweis' => 'bg-amber-50 border-amber-200 text-amber-900',
            'fehler' => 'bg-red-50 border-red-200 text-red-800',
            default => '',
        };
        $titel = \Platform\Recruiting\Livewire\Employees\KontoKasten::AKTIONEN[$aktion] ?? '';
        $antragLink = route('recruiting.dashboard.hr-desk') . '?tab=mitarbeiter&liste=antraege';
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">Konto</h3>
        @if ($k !== null)
            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">{{ $k['status_text'] }}</span>
        @endif
    </div>

    @if ($meldung !== '')
        <div class="mt-3 rounded-lg border px-3 py-2 text-sm {{ $meldungKlasse }}">{{ $meldung }}</div>
    @endif

    @if ($k === null)
        <p class="mt-2 text-sm text-[var(--ui-muted)]">Keine Konto-Daten.</p>
    @elseif ($k['ohne_person'])
        <p class="mt-2 text-sm text-amber-900">Diese Akte hat noch keine Personen-Zeile — Konto-Funktionen erst nach <code>recruiting:personen-anlegen</code>.</p>
    @else
        @if ($k['stillgelegt_ziel'] !== null)
            <p class="mt-2 text-sm text-amber-900">Diese Personen-Zeile ist stillgelegt; die Anstellungen hängen jetzt an Personen-Zeile #{{ $k['stillgelegt_ziel'] }}.</p>
        @endif
        <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-1 text-sm md:grid-cols-2">
            <div><dt class="inline text-[var(--ui-muted)]">Benutzername:</dt> <dd class="inline tabular-nums">{{ $k['nummer'] }}</dd></div>
            <div><dt class="inline text-[var(--ui-muted)]">Letzte Anmeldung (Konto):</dt> <dd class="inline">{{ $k['letzte_anmeldung'] }}</dd></div>
            <div><dt class="inline text-[var(--ui-muted)]">Altes Portal zuletzt:</dt> <dd class="inline">{{ $k['altes_portal'] }}</dd></div>
            <div><dt class="inline text-[var(--ui-muted)]">Portal:</dt> <dd class="inline">{{ $k['pilot'] }}</dd></div>
        </dl>
        @if ($k['antrag_offen'])
            <p class="mt-2 text-sm text-amber-900">Es liegt ein offener Nummernwechsel-Antrag vor — <a href="{{ $antragLink }}" class="font-semibold underline">HR-Schreibtisch → Mitarbeiter → Konto-Anträge</a>.</p>
        @endif
    @endif

    @if ($k !== null && $k['gruende'] !== [])
        <div class="mt-3 flex flex-col gap-1.5">
            @foreach ($k['gruende'] as $g)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700">{{ $g['text'] }}</span>
                    @if ($g['aktion'] !== null && !$k['knoepfe_aus'])
                        <button type="button" class="{{ $knopf }}" wire:click="oeffne('{{ $g['aktion'] }}')" wire:loading.attr="disabled" wire:target="oeffne">{{ $g['knopf'] }}</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($k !== null && !$k['knoepfe_aus'])
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($k['kann_einladen'])
                <button type="button" class="{{ $knopf }}" wire:click="oeffne('einladung_neu')" wire:loading.attr="disabled" wire:target="oeffne">Einladung neu</button>
            @endif
            @if ($k['kann_zuruecksetzen'])
                <button type="button" class="{{ $knopf }} border-red-300 text-red-700" wire:click="oeffne('konto_zuruecksetzen')" wire:loading.attr="disabled" wire:target="oeffne">Konto zurücksetzen</button>
            @endif
        </div>
    @endif

    @if ($k !== null && $k['verlauf'] !== [])
        <div class="mt-4">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)]">Verlauf</h4>
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($k['verlauf'] as $v)
                    <li><span class="text-[var(--ui-muted)]">{{ $v['wann'] }} · {{ $v['wer'] }}:</span> {{ $v['was'] }}
                        @if ($v['notiz'] !== null)
                            — {{ $v['notiz'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui-modal size="md" model="modalOffen">
        <x-slot name="header">{{ $titel }}</x-slot>
        <div class="space-y-3 p-4">
            @if ($aktion === 'konto_zuruecksetzen')
                <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900">Das Passwort wird gelöscht, laufende Anmeldungen enden sofort. Der Mensch registriert sich neu — mit dem neuen Code und seinem Geburtsdatum.</div>
            @endif
            @if ($modalFehler !== '')
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ $modalFehler }}</div>
            @endif
            <label class="block text-sm font-medium text-[var(--ui-secondary)]">Notiz (Pflicht, 5–500 Zeichen)</label>
            <textarea wire:model="notiz" rows="3" maxlength="500" class="w-full rounded-md border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2 text-sm"></textarea>
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary-outline" wire:click="schliesse">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="bestaetige" wire:loading.attr="disabled" wire:target="bestaetige">Bestätigen</x-ui-button>
        </x-slot>
    </x-ui-modal>

    <x-ui-modal size="md" model="einladungOffen">
        <x-slot name="header">Einladung — nur jetzt sichtbar</x-slot>
        <div class="space-y-3 p-4 text-sm">
            <div><span class="text-[var(--ui-muted)]">Link:</span> <code class="break-all">{{ $einladungsLink }}</code></div>
            <div><span class="text-[var(--ui-muted)]">Code:</span> <code class="text-lg font-semibold tracking-widest">{{ $einladungsCode }}</code></div>
            <p class="text-amber-900">Der Code ist ein Geheimnis: nur an den Menschen selbst, nicht in eine Gruppen-Unterhaltung. Er gilt sieben Tage und wird nicht noch einmal angezeigt.</p>
        </div>
        <x-slot name="footer">
            <x-ui-button variant="primary" wire:click="schliesseEinladung">Weitergegeben — schließen</x-ui-button>
        </x-slot>
    </x-ui-modal>
</div>
```

- [ ] **Step 6: `Show` umbauen**

`src/Livewire/Employees/Show.php`: die Methode `unlockPortal()` samt ihres Docblocks („HR-Entsperrung des MA-Portal-Zugangs … idempotent, wie das Setzen selbst.“) vollständig löschen.

`resources/views/livewire/employees/show.blade.php` — im roten Kasten den Knopf (:220-226) ersetzen durch:

```blade
                    <button type="button" wire:click="$dispatch('konto-kasten-portal-entsperren')"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 transition-colors">
                        @svg('heroicon-o-lock-open', 'w-4 h-4')
                        Portalzugang entsperren
                    </button>
```

und unmittelbar vor der Zeile `            {{-- Felder — responsive grid (1 col mobile, 2 col md, 3 col xl) --}}` einfügen:

```blade
            {{-- Konto-Kasten (Spec HR-Bereich §4) — Anker #konto für den Pilot-Überblick --}}
            <div id="konto" class="mt-4">
                <livewire:recruiting.employees.konto-kasten :employee-id="$employee->id" :key="'konto-kasten-' . $employee->id" />
            </div>
```

`src/Services/PortalAuth.php:236` im Docblock von `istDispoGesperrt()`: `(Show.php::unlockPortal, Zeile 354)` → `(PortalEntsperrung::fuerAnstellung)`.

- [ ] **Step 7: Tests und Blade-Prüfung — grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'KontoKastenTest|VertragAusAkteOberflaecheTest|DispoPortalLockTest|PortalAuthKontoTest|SharedPartialContractTest|BladeCompileIntegrityTest'`
Expected: PASS.
Run: `php tools/blade-check.php resources/views/livewire/employees/konto-kasten.blade.php && php tools/blade-check.php resources/views/livewire/employees/show.blade.php`
Expected: Exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Services/HrMitarbeiterListen.php src/Livewire/Employees/KontoKasten.php resources/views/livewire/employees/konto-kasten.blade.php src/Livewire/Employees/Show.php resources/views/livewire/employees/show.blade.php src/Services/PortalAuth.php tests/Integration/KontoKastenTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Konto-Kasten in der Mitarbeiterakte, Entsperren nur noch mit Notiz

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---
### Task 9: Notfallweg-Team-Schalter — öffentliche Seite, Stundenlauf, Einstellungs-Modal

**Files:**
- Modify: `src/Livewire/Public/KontoAnmelden.php` — `notfallAnfordern()` (:942, nach `anmeldefaehigePersonFuerNummer`), `notfallBestaetigen()` (nach dem Server-Riegel-Block :1021-1029)
- Modify: `src/Console/Commands/KontoZuruecksetzen.php` — `wendeFaelligeAn()` (:236-312), `zustand()` (:338-341)
- Modify: `src/Livewire/Applicant/ApplicantSettingsModal.php` — `save()` (:269-282), Imports
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` — nach dem Mindestlohn-Feld (`x-ui-input-text name="settings.minimum_wage_hourly"` …`/>`, ~:275-284), vor dem schließenden `</div>` des Reiters „general“
- Modify (Tests): `tests/Integration/KontoZuruecksetzenTest.php` — `kanalAufsetzen()` Settings-JSON; neue Tests + Helfer `setzeTeamSchalter()`; `tests/Integration/SettingsModalToggleWriteTest.php` — `authStub()` bekommt `id()`, neue Tests
- Test: wie oben

**Interfaces:**
- Consumes: `KontoWriter::notfallWegAn()`, `NummernwechselTafel::ZUSTAND_OFFEN|ZUSTAND_NOTFALLWEG_AUS` (3), `HrEinstellungen::NOTFALL_WEG|ERINNERT_TAGE|RUHE_TAGE|begrenze()` (1).
- Produces: Log-Zeilen `recruiting.konto.weg4_abgeschaltet` mit `['schritt' => 'anfordern'|'bestaetigen', 'ebene' => 'team']`, `recruiting.konto.notfallweg_schalter` mit `['team_id', 'user_id', 'an']`; `--faellig` überspringt „Notfallweg aus“ mit exit 0.

- [ ] **Step 1: Bestehende Weg-4-Tests auf „Team-Schalter an“ stellen**

In `tests/Integration/KontoZuruecksetzenTest.php`, `kanalAufsetzen()`, die Settings-Zeile ersetzen durch:

```php
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            // Spec HR-Bereich §6: Weg 4 braucht Server-Riegel UND Team-Schalter.
            // Diese Klasse prueft, was Weg 4 TUT — der Team-Schalter steht an;
            // die Faelle "Team aus" stellen ihn ausdruecklich ab.
            'settings' => json_encode(['auto_pilot_wa_account_id' => $kontoId, 'konto_notfall_weg_aktiv' => true]),
        ]);
```

Und neben `schalteWeg4Ab()` den Helfer ergänzen:

```php
    /** Den Team-Schalter konto_notfall_weg_aktiv eines Teams setzen (legt die Zeile bei Bedarf an). */
    private function setzeTeamSchalter(bool $an, int $team = self::TEAM): void
    {
        $zeile = DB::table('rec_applicant_settings')->where('team_id', $team)->first();
        $settings = $zeile ? (array) json_decode((string) $zeile->settings, true) : [];
        $settings['konto_notfall_weg_aktiv'] = $an;
        $zeile
            ? DB::table('rec_applicant_settings')->where('team_id', $team)->update(['settings' => json_encode($settings)])
            : DB::table('rec_applicant_settings')->insert(['team_id' => $team, 'settings' => json_encode($settings)]);
    }
```

- [ ] **Step 2: Failing tests schreiben** (in `tests/Integration/KontoZuruecksetzenTest.php`, nach `test_der_abgeschaltete_weg4_laeuft_nicht_an_der_ip_bremse_vorbei`)

```php
    /** Spec HR-Bereich §6: Server an + Team aus -> kein Versand, Antwort zeichengleich. */
    public function test_team_schalter_aus_antwortet_wie_ein_fehlgeschlagener_versuch(): void
    {
        $mitFalschenZiffern = $this->seite();
        $mitFalschenZiffern->zumNotfall();
        $mitFalschenZiffern->nummer = self::NUMMER_GETIPPT;
        $mitFalschenZiffern->geburtsdatum = self::GEBURT;
        $mitFalschenZiffern->ausweis = '9999';
        $mitFalschenZiffern->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $mitFalschenZiffern->notfallAnfordern($this->sender());
        $vergleich = $this->rendere($mitFalschenZiffern);
        $this->assertNotSame('', trim($vergleich));

        $this->setzeTeamSchalter(false);
        $seite = $this->notfallVersuch();

        $this->assertSame([], $this->meta->calls, 'Team aus: kein Code');
        $this->assertSame($vergleich, $this->rendere($seite));
        $zeilen = $this->logZeilen('recruiting.konto.weg4_abgeschaltet');
        $this->assertCount(1, $zeilen);
        $this->assertSame(['schritt' => 'anfordern', 'ebene' => 'team'], $zeilen[0]['daten']);
    }

    public function test_server_aus_und_team_an_verschickt_nichts_beide_an_verschickt(): void
    {
        $this->schalteWeg4Ab();
        $this->notfallVersuch();
        $this->assertSame([], $this->meta->calls);

        $this->container->make('config')->set('recruiting.konto.weg4_aktiv', true);
        $this->notfallVersuch();
        $this->assertCount(1, $this->meta->calls, 'beide Riegel offen: Versand');
    }

    public function test_person_aus_team_mit_schalter_aus_bekommt_keinen_code_auch_wenn_ein_anderes_team_an_ist(): void
    {
        $andere = $this->person('+4915122222222', 'p-team4', ['team_id' => 4]);
        $this->anstellung($andere, 'tok-team4', ['team_id' => 4, 'phone' => '+4915122222222']);

        $seite = $this->seite();
        $seite->zumNotfall();
        $seite->nummer = '0151 22222222';
        $seite->geburtsdatum = self::GEBURT;
        $seite->ausweis = '0T47';
        $seite->neueNummer = self::NEUE_NUMMER_GETIPPT;
        $seite->notfallAnfordern($this->sender());

        $this->assertSame([], $this->meta->calls, 'Team 3 ist an, Team 4 (die Person) nicht');
    }

    public function test_bestaetigen_mit_sitzung_von_vor_dem_abschalten_legt_keinen_antrag_an(): void
    {
        $seite = $this->notfallBisZumCode();
        $this->setzeTeamSchalter(false);

        $seite->notfallBestaetigen();

        $this->assertSame(KontoAnmelden::MELDUNG_ZURUECK, $seite->fehler);
        $this->assertNull($this->zeile()->wechsel_neue_nummer);
        $this->assertSame('bestaetigen', $this->logZeilen('recruiting.konto.weg4_abgeschaltet')[0]['daten']['schritt']);
    }

    public function test_faellig_mit_team_schalter_aus_wendet_nicht_an_und_endet_mit_null(): void
    {
        DB::table('rec_persons')->where('id', $this->personId)->update([
            'wechsel_neue_nummer' => self::NEUE_NUMMER, 'wechsel_beantragt_at' => '2026-09-28 10:00:00',
            'wechsel_wirksam_ab' => '2026-09-29 10:00:00', 'wechsel_quelle' => 'notfall',
        ]);
        $this->setzeTeamSchalter(false);

        [$code, $ausgabe] = $this->kommando(['--faellig' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(self::NUMMER, $this->zeile()->phone);
        $this->assertStringContainsString('0 angewendet, 0 noch nicht faellig, 0 gescheitert, 1 liegen bei HR.', $ausgabe);
        $this->assertStringContainsString('Notfallweg', $ausgabe);

        $this->setzeTeamSchalter(true);
        $this->kommando(['--faellig' => true]);
        $this->assertSame(self::NEUE_NUMMER, $this->zeile()->phone, 'wieder an: der Stundenlauf wendet an');
    }
```

In `tests/Integration/SettingsModalToggleWriteTest.php`: in `authStub()` der äußersten anonymen Klasse (die mit `user()`) die Methode ergänzen:

```php
            public function id(): ?int
            {
                return 42;
            }
```

und die Tests ergänzen (Imports: `use Platform\Recruiting\Support\HrEinstellungen;`, `use Illuminate\Container\Container;` ist schon da):

```php
    public function testNotfallwegSchalterUndZahlenWerdenGespeichertUndBegrenzt(): void
    {
        $this->bestehendeZeileOhneDenSchluessel();
        $modal = new ApplicantSettingsModal();
        $modal->openSettings();

        $this->assertFalse($modal->settings['konto_notfall_weg_aktiv'], 'Vorgabe aus');
        $this->assertSame(5, $modal->settings['hr_erinnert_tage']);
        $this->assertSame(3, $modal->settings['hr_kontaktiert_ruhe_tage']);

        $modal->settings['konto_notfall_weg_aktiv'] = true;
        $modal->settings['hr_erinnert_tage'] = '99';
        $modal->settings['hr_kontaktiert_ruhe_tage'] = '';
        $modal->save();

        $json = $this->rohesJson();
        $this->assertStringContainsString('"konto_notfall_weg_aktiv":true', $json);
        $this->assertStringContainsString('"hr_erinnert_tage":60', $json);
        $this->assertStringContainsString('"hr_kontaktiert_ruhe_tage":3', $json);
        $this->assertTrue(HrEinstellungen::notfallWegAktiv(self::TEAM));
    }

    public function testKippenDesNotfallwegsStehtImLogNurBeiAenderung(): void
    {
        $log = new class {
            public array $zeilen = [];
            public function __call($m, $a) { $this->zeilen[] = ['nachricht' => (string) ($a[0] ?? ''), 'daten' => (array) ($a[1] ?? [])]; }
        };
        Container::getInstance()->instance('log', $log);
        Facade::clearResolvedInstance('log');
        $this->bestehendeZeileOhneDenSchluessel();

        $an = new ApplicantSettingsModal();
        $an->openSettings();
        $an->settings['konto_notfall_weg_aktiv'] = true;
        $an->save();

        $gleich = new ApplicantSettingsModal();
        $gleich->openSettings();
        $gleich->save();

        $zeilen = array_values(array_filter($log->zeilen, fn ($z) => $z['nachricht'] === 'recruiting.konto.notfallweg_schalter'));
        $this->assertCount(1, $zeilen);
        $this->assertSame(['team_id' => self::TEAM, 'user_id' => 42, 'an' => true], $zeilen[0]['daten']);

        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstance('log');
    }

    public function testBladeHatCheckboxUndZahlenfelderUndKeinSelect(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');

        $this->assertMatchesRegularExpression('/type="checkbox"\s+wire:model="settings\.konto_notfall_weg_aktiv"/', $blade);
        $this->assertMatchesRegularExpression('/name="settings\.hr_erinnert_tage"[^>]*type="number"/s', $blade);
        $this->assertMatchesRegularExpression('/name="settings\.hr_kontaktiert_ruhe_tage"[^>]*type="number"/s', $blade);
        $this->assertDoesNotMatchRegularExpression('/x-ui-input-select[^>]*(konto_notfall|hr_erinnert|hr_kontaktiert)/s', $blade);
        $this->assertStringContainsString('RECRUITING_KONTO_WEG4_AKTIV', $blade);
    }
```

- [ ] **Step 3: Tests laufen lassen — rot**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'KontoZuruecksetzenTest|SettingsModalToggleWriteTest'`
Expected: FAIL — die neuen Tests (Code geht raus bei Team aus; `--faellig` wendet an; Schlüssel/Logzeile fehlen).

- [ ] **Step 4: `KontoAnmelden` — Team-Schalter**

In `notfallAnfordern()` direkt nach der Zeile `$personId = KontoWriter::anmeldefaehigePersonFuerNummer(null, $this->nummer);` (:942) einfügen:

```php

                // SPEC HR-BEREICH §6: der TEAM-Schalter des Teams der Person
                // (KontoWriter::notfallWegAn: Server-Riegel UND Team). Erst
                // hier, weil die oeffentliche Seite kein Team hat. Aus = kein
                // Versand und DIESELBE Antwort wie bei falschen Nachweisen;
                // die Zeile steht hinter der IP-Bremse (darfAnfordern).
                if ($personId !== null && !KontoWriter::notfallWegAn($personId)) {
                    Log::warning('recruiting.konto.weg4_abgeschaltet', ['schritt' => 'anfordern', 'ebene' => 'team']);
                    $personId = null;
                }
```

In `notfallBestaetigen()` direkt NACH dem bestehenden Block `if (!self::weg4Aktiv()) { … return; }` (:1021-1029) einfügen:

```php
        // Dieselbe Frage fuer das TEAM der Person aus der Sitzung (Spec §6):
        // eine Sitzung von vor dem Abschalten kommt nicht durch.
        if (!KontoWriter::notfallWegAn($personId)) {
            if ($this->darfAnfordern()) {
                Log::warning('recruiting.konto.weg4_abgeschaltet', ['schritt' => 'bestaetigen', 'ebene' => 'team']);
            }

            $this->code = '';
            $this->fehler = self::MELDUNG_ZURUECK;

            return;
        }
```

- [ ] **Step 5: `--faellig` mit Team-Schalter**

`src/Console/Commands/KontoZuruecksetzen.php`: `zustand()` (:338-341) Körper ersetzen:

```php
        return NummernwechselTafel::zustand($zeile, KontoWriter::notfallWegAn((int) $zeile->id));
```

In `wendeFaelligeAn()`: nach `$liegtBeiHr = 0;` die Zeile `$notfallwegAus = 0;` ergänzen; den Block

```php
            if ($this->zustand($zeile) !== 'offen') {
                $liegtBeiHr++;

                continue;
            }
```

ersetzen durch

```php
            $zustand = $this->zustand($zeile);
            if ($zustand !== NummernwechselTafel::ZUSTAND_OFFEN) {
                $liegtBeiHr++;
                if ($zustand === NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS) {
                    // Spec HR-Bereich §6: Ausschalten laesst nichts still wirksam werden.
                    $notfallwegAus++;
                }

                continue;
            }
```

und den Schluss-Block `if ($liegtBeiHr > 0) { … }` ersetzen durch

```php
        if ($notfallwegAus > 0) {
            $this->line(sprintf(
                '%d Notfall-Antrag/Anträge warten auf HR, weil der Notfallweg (Server oder Team) aus ist — '
                . 'stoppen oder freigeben unter HR-Schreibtisch → Mitarbeiter → Konto-Anträge.',
                $notfallwegAus,
            ));
        }

        if ($liegtBeiHr > $notfallwegAus) {
            $this->line('Die Antraege gesperrter oder stillgelegter Personen werden nie angewendet — '
                . 'ansehen mit --offen, wegraeumen mit --stopp=<Person>.');
        }
```

- [ ] **Step 6: Einstellungs-Modal**

`src/Livewire/Applicant/ApplicantSettingsModal.php` — Imports ergänzen:

```php
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Support\HrEinstellungen;
```

In `save()` zwischen dem Auto-Start-Block und `$this->settingsModel->settings = $this->settings;` einfügen:

```php
        // HR-Bereich (Spec §6/§7.2): Zahlen begrenzt speichern, Schalter als bool;
        // ein Kippen des Notfallwegs gehoert ins Log (wer, wann, an/aus).
        $vorher = (bool) ($this->settingsModel->getSetting(HrEinstellungen::NOTFALL_WEG) ?? false);
        $this->settings[HrEinstellungen::NOTFALL_WEG] = (bool) ($this->settings[HrEinstellungen::NOTFALL_WEG] ?? false);
        $this->settings[HrEinstellungen::ERINNERT_TAGE] = HrEinstellungen::begrenze($this->settings[HrEinstellungen::ERINNERT_TAGE] ?? null, 1, 60, 5);
        $this->settings[HrEinstellungen::RUHE_TAGE] = HrEinstellungen::begrenze($this->settings[HrEinstellungen::RUHE_TAGE] ?? null, 1, 30, 3);
```

und direkt nach `$this->settingsModel->save();` einfügen:

```php
        if ($vorher !== $this->settings[HrEinstellungen::NOTFALL_WEG]) {
            Log::notice('recruiting.konto.notfallweg_schalter', [
                'team_id' => (int) $this->settingsModel->team_id,
                'user_id' => Auth::id(),
                'an'      => $this->settings[HrEinstellungen::NOTFALL_WEG],
            ]);
        }
```

`resources/views/livewire/applicant/applicant-settings-modal.blade.php` — direkt nach dem schließenden `/>` des Mindestlohn-Feldes (`x-ui-input-text name="settings.minimum_wage_hourly" …`) einfügen:

```blade

                    {{-- Mitarbeiterkonto & Erinnerungen (Spec HR-Bereich 2026-10-12 §6/§7.2).
                         Rohe Checkbox wie die Nachbarn (kein x-ui-input-checkbox, KEIN Select:
                         Select-Speicherfehler). Zahlen als x-ui-input-text type=number wie der Mindestlohn. --}}
                    @php
                        $serverRiegelZu = !(bool) config('recruiting.konto.weg4_aktiv', false);
                    @endphp
                    <div class="space-y-4 border-t border-[var(--ui-border)]/40 pt-4">
                        <h4 class="text-sm font-semibold text-[var(--ui-secondary)]">Mitarbeiterkonto &amp; Erinnerungen</h4>
                        <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox"
                                       wire:model="settings.konto_notfall_weg_aktiv"
                                       class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                                <div>
                                    <span class="text-sm font-medium text-[var(--ui-secondary)]">Notfallweg ‚Nummer und Passwort verloren‘ erlauben</span>
                                    <p class="text-xs text-[var(--ui-muted)] mt-0.5">
                                        Anträge erscheinen unter HR-Schreibtisch → Mitarbeiter → Konto-Anträge und werden nach 24 Stunden wirksam,
                                        wenn niemand stoppt. Nur einschalten, wenn verabredet ist, wer die Liste täglich ansieht.
                                    </p>
                                    @if ($serverRiegelZu)
                                        <p class="text-xs text-[var(--ui-muted)] mt-1">Auf dem Server noch abgeschaltet (RECRUITING_KONTO_WEG4_AKTIV) — der Haken wirkt erst danach.</p>
                                    @endif
                                </div>
                            </label>
                        </div>
                        <x-ui-input-text
                            name="settings.hr_erinnert_tage"
                            label="„Erinnert, aber keine Reaktion“ ab (Tage nach der Erinnerung)"
                            wire:model="settings.hr_erinnert_tage"
                            type="number"
                            step="1"
                            min="1"
                            max="60"
                            placeholder="5"
                            hint="Ab so vielen Tagen ohne Reaktion erscheint der Mensch auf dem HR-Schreibtisch (1–60)."
                        />
                        <x-ui-input-text
                            name="settings.hr_kontaktiert_ruhe_tage"
                            label="Ruhezeit nach „Kontaktiert“ (Tage)"
                            wire:model="settings.hr_kontaktiert_ruhe_tage"
                            type="number"
                            step="1"
                            min="1"
                            max="30"
                            placeholder="3"
                            hint="So lange ruht die Zeile nach einem Anruf; ist danach noch etwas offen, kommt sie zurück (1–30)."
                        />
                    </div>
```

- [ ] **Step 7: Tests und Blade-Prüfung — grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'KontoZuruecksetzenTest|SettingsModalToggleWriteTest|SettingsModalEntangleKeysTest|SettingsModalCertificateToggleTest|KontoAnmeldenTest'`
Expected: PASS.
Run: `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php`
Expected: Exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Public/KontoAnmelden.php src/Console/Commands/KontoZuruecksetzen.php src/Livewire/Applicant/ApplicantSettingsModal.php resources/views/livewire/applicant/applicant-settings-modal.blade.php tests/Integration/KontoZuruecksetzenTest.php tests/Integration/SettingsModalToggleWriteTest.php
git commit -m "$(cat <<'EOF'
feat(recruiting): Notfallweg-Team-Schalter auf Seite, Stundenlauf und Einstellungen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---

### Task 10: Abschluss — Spec-Abgleich, ganze Suite, Blade-Prüfung, Mutationsproben

**Files:**
- Modify: `docs/superpowers/specs/2026-10-12-hr-bereich-mitarbeiter-design.md` (§3.3, §5.4, §7.2/§7.3, §9.6, §11, neuer Abschnitt „Umsetzungsentscheidungen“)

**Interfaces:**
- Consumes: alles aus Task 1–9.
- Produces: grüne Suite, sauberer Blade-Check, Mutationsproben-Tabelle im Commit-Text.

- [ ] **Step 1: Spec nachziehen** — folgende Änderungen wörtlich einarbeiten:
  - §3.3 nach dem Absatz „„Kontaktiert“ …“: „Ausnahme (Umsetzung): Liegt der nächste Einsatz vor dem oder am Tag des Ruhe-Endes, ruht die Zeile nicht — sie bleibt mit dem Hinweis „kontaktiert — ruht nicht, weil der nächste Einsatz (…) vor dem Ruhe-Ende liegt“ sichtbar.“
  - §3.3 Tabelle „Erneut erinnern“, Zeile Vertrag: „7 Tage ab max(`sent_at`, letzter Vermerk `erneut_erinnert` mit Ergebnis `sent`)“.
  - §5.4: „Die Sperre speichert ihr Ende (ISO-Zeitpunkt) statt `true`; `gebremsteNummern()` liefert nur die gebremsten Nummern mit „frei ab“ (Altwert `true` → unbekannt, Text „frei spätestens nach 15 Minuten“).“
  - §7.2 (Einstellungen): Hinweis „Leer/unlesbar → Vorgabe (nicht 1).“; §7.3 ersetzen durch „Keine Änderung an `rec_employees`, `rec_employee_proofs`, `rec_document_recipients`, `rec_contracts`; an `rec_persons` nur die neue Spalte `sitzungen_ungueltig_ab` (§7.2 Spalte).“
  - §9.6: „Gesetzt bei **Konto zurücksetzen** und **Notfallsperre aufheben** (`notfallsperre_aufgehoben`).“; Liste der Prüfstellen um „`PortalShell::berechtigterMitarbeiter()` setzt `unverified`“ präzisieren; „Einen Portal-Download-Controller für Nachweise gibt es nicht; die `KontoAnmelden`-Weiche hat keine eigene Sitzungsprüfung (nur put/forget).“
  - §11 Punkt 2: „Forge-Deploy **mit `migrate`** (ZWEI Migrationen: `rec_hr_vermerke`, `rec_persons.sitzungen_ungueltig_ab`).“
  - Neuer Abschnitt „## 13. Umsetzungsentscheidungen (Plan 2026-10-12)“ mit genau den Punkten aus Step 1 plus: „`Bote::fuer()` übernimmt die Regel unverändert (kleinste Akte mit `portal_v2_since`); HR übergibt nur aktive Akten.“, „`EinladungsRiegel::ohneWhatsapp()` liest die DB (zeichengleicher Umzug), `grund()` ist rein.“, „Dispo-Chip in Liste/Kasten: aktive Akten der Personen-Klammer; Entsperren über die Dispo-Gruppe (GD-9).“, „Stillgelegte Anträge erscheinen über die Akten der Zielzeile und sind stoppbar.“, „Freigabe-Hinweisstatus steht als `ergebnis` im Vermerk.“

- [ ] **Step 2: Ganze Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS, 0 Fehler, 0 Warnungen (die Suite läuft mit `failOnWarning`).

- [ ] **Step 3: Blade-Prüfung aller berührten Views**

Run: `php tools/blade-check.php resources/views/livewire/hr-desk/mitarbeiter.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/partials/mensch.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/partials/seiten.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/index.blade.php && php tools/blade-check.php resources/views/livewire/employees/konto-kasten.blade.php && php tools/blade-check.php resources/views/livewire/employees/show.blade.php && php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php`
Expected: Exit 0. Zusätzlich `grep -nE '[A-Za-z0-9]@(if|else|endif|foreach|endforeach)' resources/views/livewire/hr-desk/*.blade.php resources/views/livewire/hr-desk/partials/*.blade.php resources/views/livewire/employees/konto-kasten.blade.php` → keine Treffer (keine an Wortzeichen geklebten Direktiven).

- [ ] **Step 4: Mutationsproben** — je Zeile die Änderung von Hand machen, den genannten Test laufen lassen, ROT bestätigen, mit `git checkout -- <datei>` zurücknehmen:

| # | Mutation (Datei: Änderung) | Erwartet rot |
|---|---|---|
| 1 | `HrMitarbeiterListen::gesperrt()`: `->where('team_id', $teamId)` in der Akten-Abfrage entfernen | `HrMitarbeiterListenTest::test_team_b_mit_derselben_nummer_erscheint_nirgends_und_wird_nicht_genannt` |
| 2 | `KontoWriter::sperrAbfrage()`: `->lockForUpdate()` entfernen | `KontoWriterHrTest::test_alle_drei_wege_lesen_die_zeile_gesperrt_in_einer_transaktion` |
| 3 | `KontoWriter::stoppeNummerwechsel()`: `DB::transaction(…)` auflösen und mit dem alten `DB::table('rec_persons')->…->first()` lesen | dito (Transaktionsebene 0) |
| 4 | `HrKontoAktionen::notizFehler()`: `return null;` als erste Zeile | `HrKontoAktionenTest::test_notiz_pflicht_5_bis_500_zeichen` |
| 5 | `KontoAnmelden::notfallAnfordern()`: den Team-Schalter-Block entfernen | `KontoZuruecksetzenTest::test_team_schalter_aus_antwortet_wie_ein_fehlgeschlagener_versuch` |
| 6 | `HrKontoAktionen::inPause()`: `return null;` als erste Zeile | `HrErinnernTest::test_doppelklick_und_pause_ein_versand` |
| 7 | `HrKontoAktionen::personImTeam()`: `->where('team_id', $teamId)` entfernen | `HrKontoAktionenTest::test_fremdes_team_wird_bei_jeder_aktion_abgewiesen` |
| 8 | `PortalAuth::anmeldeZeitpunkt()`: `return PHP_INT_MAX;` für Nicht-Strings | `SitzungGueltigTest::test_altwert_true_stirbt_mit_dem_zuruecksetzen` |
| 9 | `HrMitarbeiterListen::erinnert()`: `$ruht = $ruheBis !== null;` | `HrMitarbeiterListenTest::test_kontaktiert_ruht_nicht_wenn_der_naechste_einsatz_in_die_ruhezeit_faellt` |
| 10 | `HrMitarbeiterListen::menschen()`: Schlüssel immer `'e:' . $a->id` | `HrMitarbeiterListenTest::test_rg_und_ma_derselben_person_sind_eine_zeile_in_allen_listen` |
| 11 | `HrDesk\Mitarbeiter`: `#[Locked]` an `aktionPersonId` entfernen | `HrDeskMitarbeiterTest::test_aktionskontext_ist_gegen_wire_set_gesperrt` |
| 12 | `HrKontoAktionen::einladung()`: Token zusätzlich als Notiz-Anhang in den Vermerk schreiben (`$notiz . ' ' . $token`) | `HrKontoAktionenTest::test_einladungs_klartext_steht_in_keinem_log_und_keinem_vermerk` |
| 13 | `KontoZuruecksetzen::zustand()`: zurück auf `NummernwechselTafel::zustand($zeile)` | `KontoZuruecksetzenTest::test_faellig_mit_team_schalter_aus_wendet_nicht_an_und_endet_mit_null` |

Run je Probe: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter '<Testname>'` → FAIL; nach `git checkout -- <datei>` → PASS. Bleibt eine Probe grün, ist der zugehörige Test „grünes Nichts“ (Checkliste `reference_pruefmuster_gruenes_nichts`) und muss vor dem Commit nachgeschärft werden.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-10-12-hr-bereich-mitarbeiter-design.md
git commit -m "$(cat <<'EOF'
docs(recruiting): Spec HR-Bereich an die Umsetzung angeglichen, Mutationsproben 1-13 rot bestaetigt

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda
EOF
)"
```

---

## Spec-Abdeckung (Selbstprüfung)

| Spec | Task |
|---|---|
| §1 Reiter „Mitarbeiter“ mit vier Listen, Zähler-Abzeichen | 6, 7 |
| §1 Konto-Kasten mit Einladung neu / Zurücksetzen / Entsperren, Pflichtnotiz, Verlauf | 5, 8 |
| §1/§6 Team-Schalter Notfallweg + Server-Riegel | 3 (`notfallWegAn`), 9 |
| §2 Befund: Drossel lesbar, fünf Sperrgründe, `code_versuche` kein Grund | 4, 6 |
| §3.1 Anträge, Stoppen, Freigeben, Zustände, gestoppte 30 Tage + Notfallsperren | 3, 5, 6, 7 |
| §3.2 Gesperrt, eine `Cache::many`-Abfrage, mehrdeutig ohne Fremdauskunft | 4, 6, 7 |
| §3.3 Erinnert, vier Quellen, Sortierung, Kontaktiert-Ruhe, Erneut erinnern je Sender, Laufsperre, Bote | 4, 5, 6, 7 |
| §3.4 Pilot-Überblick, Filter-Chips, `KontoStatus` | 4, 6, 7 |
| §4 Konto-Kasten, Einmal-Anzeige, `EinladungsRiegel`, stillgelegt/ohne Person | 4, 5, 8 |
| §5.1–5.5 Schreibwege, Wettlauf, `PortalEntsperrung`, `HrKontoAktionen` | 3, 4, 5 |
| §7.1 `rec_hr_vermerke`, §7.2 Spalte + Einstellungen | 1, 9 |
| §8 Komponenten/Blade-Hausregeln | 7, 8, 9, 10 |
| §9.1–9.5 Team-Scope, Locked, Freigeben, ZAS, Geheimnisse | 5, 6, 7, 8 |
| §9.6 Sitzungen beenden, eine Prüfstelle | 2, 5 |
| §10 Tests + Mutationsproben | jede Task, 10 |
| §11 Deploy (zwei Migrationen) | 10 (Spec-Abgleich) |
