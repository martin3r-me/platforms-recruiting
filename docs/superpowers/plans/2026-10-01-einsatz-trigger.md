# Einsatz-Trigger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein fest gebuchter Einsatz loest automatisch eine Pruefung aus, die dem Menschen seine offenen Punkte im Portal zeigt und ihn einmal darauf hinweist.

**Architecture:** Der reine Pruefer (`ProofChecklist`) und der personenweite Leser (`ProofReader`) existieren bereits. Ergaenzt werden: die Vereinigung der Pflichten ueber alle Anstellungen, die Unterscheidung K.-o.-Punkt gegen normalen Punkt, der Einsatz-Bezug, die Nachrichtenregeln und ein Kommando, das nach dem Dispo-Import laeuft.

**Tech Stack:** Laravel 11, Livewire 3, PHPUnit (tests/Unit pur, tests/Integration mit handgebautem Container + Capsule + SQLite).

**Spec:** `docs/superpowers/specs/2026-10-01-einsatz-trigger-design.md`

**Branch:** `feat/ma-konto`, Ausgangsstand `9879022` — **3138 Tests, 14229 Assertions, gruen.**

## Global Constraints

- **Das Portal traegt die Aufgaben, die Nachricht ist nur der Wegweiser** (Spec §2.1). Die Liste steht vollstaendig im Portal; die Nachricht sagt nur, dass es etwas zu tun gibt.
- **Ausgeloest wird bei `STATUS_AUFTRAG` (1)**, nicht bei `STATUS_ANGEBOT` (0) — und nur, wenn der Einsatz mindestens die Mindest-Vorlaufzeit entfernt ist (Spec §2.2).
- **Die Aufgabenliste wird berechnet, nicht gespeichert** (Spec §3.2). Gespeichert wird nur, was die Nachrichtenregeln brauchen.
- **OBSERVER-FREI, wo `rec_employees` oder `rec_dispo_assignments` beschrieben werden** — eine Pruefung ist keine fachliche Aenderung und darf `zas_changed_at` nicht setzen (Vorfall 02.09.2026, volle Zeilen in `updates.csv`).
- **Jeder Mensch in seinem eigenen Fehlerkaefig** — ein Datensatz, der stolpert, darf den Lauf nicht beenden.
- **Die drei Zahlen stehen als Konstanten an einer Stelle:** Mindest-Vorlauf **4 Tage**, Erinnerung **2 Tage** vorher, Mindestpause **7 Tage** (Spec §7).
- **In den Tests stehen die Zahlen AUSGESCHRIEBEN**, nie aus der Konstante gelesen — sonst kann die Mutation der Konstante nicht fallen.
- `tests/Unit` ist **pur** (kein Framework, keine DB, keine Fassaden, keine Uhr). `tests/Integration` baut Container + Capsule + SQLite von Hand, Vorbild `tests/Integration/PersonLinkerTest.php`, inklusive `Facade::clearResolvedInstances()`. Migrationen laufen in Tests **nicht**.
- Kommentare auf Deutsch, sie erklaeren das WARUM. **Keine typografischen Anfuehrungszeichen.**
- `php -l` auf jede geaenderte PHP-Datei, `php tools/blade-check.php` auf jedes Blade (**nicht** `php -l`).
- Gesamtlauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`.
- **Erst committen, dann mutieren** — `git checkout` hat in diesem Zweig schon viermal einen ungesicherten Stand weggeraeumt.

---

## Dateien

| Datei | Verantwortung |
|---|---|
| `src/Support/PersonPflichten.php` | **rein**: Pflichten ueber alle Anstellungen vereinigen |
| `src/Support/ProofTypes.php` | **geaendert**: welche Codes sind K.-o. |
| `src/Services/ProofReader.php` | **geaendert**: benutzt die vereinigten Pflichten |
| `src/Support/EinsatzBezug.php` | **rein**: welcher Einsatz gehoert zu einem offenen Punkt |
| `src/Support/TriggerRegeln.php` | **rein**: wann geht eine Nachricht raus |
| `database/migrations/2026_10_01_000001_add_trigger_state.php` | Fingerabdruck + Erinnerung |
| `database/migrations/2026_10_01_000002_add_employee_to_hr_desk_cases.php` | HR-Fall auch ohne Bewerber |
| `src/Services/OffenePunkte.php` | der Leseweg: Liste mit Einsatz-Bezug |
| `src/Services/Comms/AufgabenSender.php` | WhatsApp-Versand der Aufgaben-Nachricht |
| `src/Console/Commands/EinsatzPruefung.php` | der Ausloeser |

---

### Task 1: Pflichten ueber alle Anstellungen vereinigen

**Files:**
- Create: `src/Support/PersonPflichten.php`
- Test: `tests/Unit/PersonPflichtenTest.php`

**Interfaces:**
- Consumes: `ProofTypes::requiredFor(array $mitarbeiter): list<string>` (vorhanden)
- Produces: `PersonPflichten::vereinige(array $anstellungen): list<string>` — `$anstellungen` ist eine Liste von Feldern mit den Schluesseln `is_eu_citizen`, `employment_type`, `is_first_aider`.

**Warum:** `ProofReader::checklist()` ruft `requiredFor()` heute mit **einer** Anstellung. Wer bei RG als Student und bei MA als Aushilfe gefuehrt wird, sieht je nach Anstellung eine andere Pflichtliste — die Immatrikulation taucht mal auf und mal nicht (Spec §2.5).

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_zwei_anstellungen_ergeben_die_vereinigung_der_pflichten(): void
{
    $pflichten = PersonPflichten::vereinige([
        ['is_eu_citizen' => true,  'employment_type' => 'student',  'is_first_aider' => false],
        ['is_eu_citizen' => true,  'employment_type' => 'aushilfe', 'is_first_aider' => false],
    ]);

    // Die Immatrikulation kommt aus der einen Anstellung und darf nicht
    // verschwinden, nur weil die andere sie nicht verlangt.
    $this->assertContains('immatrikulation', $pflichten);
    $this->assertContains('ausweis', $pflichten);
}

public function test_eine_nicht_eu_anstellung_zieht_die_ganze_person_mit(): void
{
    $pflichten = PersonPflichten::vereinige([
        ['is_eu_citizen' => true,  'employment_type' => null, 'is_first_aider' => false],
        ['is_eu_citizen' => false, 'employment_type' => null, 'is_first_aider' => false],
    ]);

    // Die Staatsangehoerigkeit gehoert zum Menschen, nicht zur Anstellung —
    // widersprechen sich die Datensaetze, gilt die strengere Lesart.
    $this->assertContains('aufenthaltstitel', $pflichten);
    $this->assertContains('arbeitsgenehmigung', $pflichten);
}

public function test_jede_pflicht_steht_genau_einmal(): void
{
    $pflichten = PersonPflichten::vereinige([
        ['is_eu_citizen' => true, 'employment_type' => 'student', 'is_first_aider' => true],
        ['is_eu_citizen' => true, 'employment_type' => 'student', 'is_first_aider' => true],
    ]);

    $this->assertSame(array_values(array_unique($pflichten)), $pflichten);
}

public function test_ohne_anstellung_gibt_es_keine_pflichten(): void
{
    $this->assertSame([], PersonPflichten::vereinige([]));
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonPflichten`
Expected: FAIL mit `Class "Platform\Recruiting\Support\PersonPflichten" not found`

- [ ] **Step 3: Umsetzen**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Die Pflichtnachweise eines MENSCHEN, nicht einer Anstellung.
 *
 * ProofReader::current() liest die vorhandenen Nachweise laengst ueber die
 * ganze Person (PersonScopeResolver). Die PFLICHTEN kamen bisher aus der
 * einen Anstellung, mit der jemand gerade zu tun hatte — wer bei RG als
 * Student und bei MA als Aushilfe gefuehrt wird, sah je nach Anstellung eine
 * andere Liste.
 *
 * Vereinigt wird, nicht geschnitten: ein Dokument, das EINE Anstellung
 * verlangt, braucht der Mensch. Widersprechen sich die Datensaetze bei der
 * Staatsangehoerigkeit, gilt damit automatisch die strengere Lesart — das ist
 * gewollt, denn die Arbeitsberechtigung ist die teurere Richtung zum Irren.
 */
final class PersonPflichten
{
    /**
     * @param list<array{is_eu_citizen?:bool|null, employment_type?:string|null, is_first_aider?:bool|null}> $anstellungen
     * @return list<string>
     */
    public static function vereinige(array $anstellungen): array
    {
        $alle = [];

        foreach ($anstellungen as $anstellung) {
            foreach (ProofTypes::requiredFor($anstellung) as $code) {
                $alle[] = $code;
            }
        }

        return array_values(array_unique($alle));
    }
}
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonPflichten`
Expected: PASS, 4 Tests

- [ ] **Step 5: Mutationsprobe**

`array_unique` entfernen → `test_jede_pflicht_steht_genau_einmal` muss rot werden.
Die Schleife auf `$anstellungen[0]` beschraenken → die ersten beiden Tests muessen rot werden.
Danach `git checkout --` und `git status` pruefen.

- [ ] **Step 6: Commit**

```bash
git add src/Support/PersonPflichten.php tests/Unit/PersonPflichtenTest.php
git commit -m "feat(recruiting): Pflichtnachweise gehoeren dem Menschen, nicht der Anstellung"
```

---

### Task 2: Der Leser benutzt die vereinigten Pflichten

**Files:**
- Modify: `src/Services/ProofReader.php:37-53` (`checklist()`)
- Test: `tests/Integration/ProofReaderPersonPflichtenTest.php`

**Interfaces:**
- Consumes: `PersonPflichten::vereinige()` (Task 1), `PersonScopeResolver::forEmployee(RecEmployee): array{ids: list<int>, abweichend: list<int>}`
- Produces: unveraendert `ProofReader::checklist(RecEmployee $employee, ?string $heute = null): list<array{code:string, label:string, status:string, valid_until:?string, offen:bool}>`

**Bindende Vorgabe:** Die Signatur bleibt gleich. Das Portal, `ProofReminderSender` und `openCount()` haengen daran — hier aendert sich **was** geprueft wird, nicht **wie** gefragt wird.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_die_checkliste_nimmt_die_pflichten_beider_anstellungen(): void
{
    // Zwei Anstellungen derselben Person: eine als Student, eine als Aushilfe.
    $person = $this->personAnlegen();
    $rg = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'student']);
    $ma = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);

    $codes = array_column((new ProofReader())->checklist($ma), 'code');

    // Angemeldet ueber die Aushilfen-Anstellung, trotzdem steht die
    // Immatrikulation auf der Liste — der Mensch studiert ja.
    $this->assertContains('immatrikulation', $codes);
}
```

**Pflichttest aus der Pruefung von Aufgabe 1 — die gefaehrlichere Richtung:**

```php
public function test_eine_beendete_anstellung_ergibt_nicht_alles_erledigt(): void
{
    // vereinige([]) gibt [] — und mit dem is_active-Filter unten kommt bei
    // einem beendeten Mitarbeiter eine LEERE Pflichtliste heraus. Die Ansicht
    // liest daraus "alles erledigt" statt "kein Nachweis da".
    //
    // Im Portal unmoeglich (verifyPortalAccess verlangt is_active), in der
    // HR-Mitarbeiterakte sehr wohl: Livewire/Employees/Show.php:247 ruft
    // checklist() auch fuer beendete Mitarbeiter.
    $person = $this->personAnlegen();
    $beendet = $this->mitarbeiter(['rec_person_id' => $person, 'is_active' => false]);

    $checkliste = (new ProofReader())->checklist($beendet);

    // Der Ausweis ist Pflicht fuer JEDEN — er darf nicht verschwinden, nur
    // weil die Anstellung beendet ist.
    $this->assertContains('ausweis', array_column($checkliste, 'code'));
}
```

**Entscheide beim Bauen und begruende es im Bericht:** faellt der `is_active`-Filter fuer
den Fall weg, dass **keine** aktive Anstellung uebrig bleibt (dann zaehlen alle), oder
bleibt er und die leere Liste wird oben abgefangen? Beides ist vertretbar — die leere
Pflichtliste ist es nicht.

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ProofReaderPersonPflichten`
Expected: FAIL — `immatrikulation` fehlt in der Liste

- [ ] **Step 3: Umsetzen**

In `ProofReader::checklist()` den Block

```php
$pflicht = ProofTypes::requiredFor([
    'is_eu_citizen'   => $employee->is_eu_citizen,
    'employment_type' => $employee->employment_type,
    'is_first_aider'  => $employee->is_first_aider,
]);
```

ersetzen durch

```php
// Die Pflichten gehoeren dem Menschen (Spec 2.5): wer bei RG als Student und
// bei MA als Aushilfe gefuehrt wird, braucht die Immatrikulation — egal, mit
// welcher Anstellung er gerade zu tun hat. Die vorhandenen Nachweise liest
// current() ohnehin schon ueber die ganze Person.
$anstellungen = RecEmployee::query()
    ->whereIn('id', $this->scope->forEmployee($employee)['ids'])
    ->where('is_active', true)
    ->get(['is_eu_citizen', 'employment_type', 'is_first_aider'])
    ->map(fn (RecEmployee $a) => [
        'is_eu_citizen'   => $a->is_eu_citizen,
        'employment_type' => $a->employment_type,
        'is_first_aider'  => $a->is_first_aider,
    ])
    ->all();

$pflicht = PersonPflichten::vereinige($anstellungen);
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (Gesamtlauf)
Expected: alles gruen. **Faellt hier ein Bestandstest, ist das ein Befund und kein Grund zum Anpassen** — er beschreibt dann eine Erwartung, die mit der neuen Regel bricht. Erst verstehen, dann entscheiden.

- [ ] **Step 5: Mutationsprobe**

`PersonPflichten::vereinige($anstellungen)` zurueck auf die Ein-Anstellungs-Fassung → der neue Test muss rot werden.
`->where('is_active', true)` entfernen → ein eigener Test mit einer beendeten Anstellung, deren Typ eine zusaetzliche Pflicht ergaebe, muss rot werden. Diesen Test mitschreiben.

- [ ] **Step 6: Commit**

```bash
git add src/Services/ProofReader.php tests/Integration/ProofReaderPersonPflichtenTest.php
git commit -m "feat(recruiting): die Checkliste fragt den Menschen, nicht die eine Anstellung"
```

---

### Task 3: K.-o.-Punkte und die abgeleitete Sperre

**Files:**
- Modify: `src/Support/ProofTypes.php` (Konstante + Methode)
- Create: `src/Support/Arbeitserlaubnis.php`
- Test: `tests/Unit/ArbeitserlaubnisTest.php`

**Interfaces:**
- Consumes: die Checklisten-Zeilen aus `ProofChecklist::build()` — `list<array{code:string, status:string, ...}>`, Status `fehlt` / `abgelaufen` / `laeuft_ab` / `ok`
- Produces:
  ```php
  ProofTypes::KO_CODES;                                   // list<string>
  ProofTypes::istKo(string $code): bool;
  Arbeitserlaubnis::istGesperrt(array $checkliste): bool; // true = darf nicht arbeiten
  Arbeitserlaubnis::gruende(array $checkliste): list<string>; // die betroffenen Codes
  ```

**Bindende Vorgabe (Spec §2.6):** Die Sperre wird **abgeleitet, nicht gespeichert** — ein gespeicherter Zustand veraltet in dem Augenblick, in dem ein Dokument ablaeuft.

**Welche Codes sind K.-o.:** `aufenthaltstitel` und `arbeitsgenehmigung`. **Nicht** `nationalpass` und **nicht** `visum`: Markus' Folie 14 nennt ausdruecklich „Aufenthaltstitel / Arbeitserlaubnis". Ein fehlender Nationalpass ist ein Dokumentenmangel, kein Arbeitsverbot.

**`laeuft_ab` sperrt NICHT.** Nur `fehlt` und `abgelaufen`. Wer in dreissig Tagen ablaeuft, darf heute arbeiten — sonst sperrt die Vorlaufzeit Menschen, die alles richtig gemacht haben.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_ein_abgelaufener_aufenthaltstitel_sperrt(): void
{
    $this->assertTrue(Arbeitserlaubnis::istGesperrt([
        ['code' => 'aufenthaltstitel', 'status' => 'abgelaufen'],
        ['code' => 'ausweis',          'status' => 'ok'],
    ]));
}

public function test_eine_fehlende_arbeitsgenehmigung_sperrt(): void
{
    $this->assertTrue(Arbeitserlaubnis::istGesperrt([
        ['code' => 'arbeitsgenehmigung', 'status' => 'fehlt'],
    ]));
}

public function test_ein_fehlender_ausweis_sperrt_nicht(): void
{
    // Folie 15: arbeiten trotz unvollstaendiger Akte. Nur die rechtliche
    // Grundlage sperrt, nicht jedes fehlende Papier.
    $this->assertFalse(Arbeitserlaubnis::istGesperrt([
        ['code' => 'ausweis', 'status' => 'fehlt'],
    ]));
}

public function test_ein_bald_ablaufender_titel_sperrt_nicht(): void
{
    // Sonst sperrt die Vorlaufzeit Menschen, die alles richtig gemacht haben.
    $this->assertFalse(Arbeitserlaubnis::istGesperrt([
        ['code' => 'aufenthaltstitel', 'status' => 'laeuft_ab'],
    ]));
}

public function test_ein_fehlender_nationalpass_sperrt_nicht(): void
{
    // Folie 14 nennt Aufenthaltstitel und Arbeitserlaubnis, nicht jedes
    // Papier der Gruppe nicht_eu.
    $this->assertFalse(Arbeitserlaubnis::istGesperrt([
        ['code' => 'nationalpass', 'status' => 'fehlt'],
    ]));
}

public function test_die_gruende_nennen_die_betroffenen_codes(): void
{
    $gruende = Arbeitserlaubnis::gruende([
        ['code' => 'aufenthaltstitel',   'status' => 'abgelaufen'],
        ['code' => 'arbeitsgenehmigung', 'status' => 'fehlt'],
        ['code' => 'ausweis',            'status' => 'fehlt'],
    ]);

    $this->assertSame(['aufenthaltstitel', 'arbeitsgenehmigung'], $gruende);
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter Arbeitserlaubnis`
Expected: FAIL mit `Class "Platform\Recruiting\Support\Arbeitserlaubnis" not found`

- [ ] **Step 3: Umsetzen**

In `ProofTypes` ergaenzen:

```php
/**
 * Die Nachweise, deren Fehlen ein ARBEITSVERBOT bedeutet — nicht bloss eine
 * unvollstaendige Akte.
 *
 * Markus' Canvas-Abgleich, Folie 14, nennt ausdruecklich „Aufenthaltstitel /
 * Arbeitserlaubnis". Der Nationalpass und das Visumsblatt stehen bewusst
 * NICHT hier: sie gehoeren zur Gruppe nicht_eu, sind aber Dokumentenmangel
 * und kein Verbot. Folie 15 dazu: arbeiten trotz unvollstaendiger Akte.
 */
public const KO_CODES = ['aufenthaltstitel', 'arbeitsgenehmigung'];

public static function istKo(string $code): bool
{
    return in_array($code, self::KO_CODES, true);
}
```

Neue Datei `src/Support/Arbeitserlaubnis.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Darf dieser Mensch arbeiten?
 *
 * ABGELEITET, NIE GESPEICHERT (Spec 2.6). Ein gespeicherter Zustand veraltet
 * in dem Augenblick, in dem ein Dokument ablaeuft — und genau dann waere er
 * falsch.
 *
 * Nur fehlend oder abgelaufen sperrt. „Laeuft ab" sperrt NICHT: sonst sperrt
 * die Vorlaufzeit von sechzig Tagen Menschen, die alles richtig gemacht
 * haben.
 *
 * ACHTUNG, Reichweite: eine Sperre hier ist heute eine Sperre UNSERES
 * PORTALS. Wir koennen niemanden aus der Disposition nehmen — der Rueckkanal
 * zu ZAS traegt nur Bestaetigungen (Spec 5). Bis der Export erweitert ist,
 * muss ein Mensch handeln; deshalb der HR-Fall.
 */
final class Arbeitserlaubnis
{
    private const SPERRENDE_ZUSTAENDE = [ProofChecklist::FEHLT, ProofChecklist::ABGELAUFEN];

    /** @param list<array{code:string, status:string}> $checkliste */
    public static function istGesperrt(array $checkliste): bool
    {
        return self::gruende($checkliste) !== [];
    }

    /**
     * @param list<array{code:string, status:string}> $checkliste
     * @return list<string> die betroffenen Codes, in der Reihenfolge der Checkliste
     */
    public static function gruende(array $checkliste): array
    {
        $gruende = [];

        foreach ($checkliste as $zeile) {
            if (ProofTypes::istKo($zeile['code'] ?? '')
                && in_array($zeile['status'] ?? '', self::SPERRENDE_ZUSTAENDE, true)) {
                $gruende[] = $zeile['code'];
            }
        }

        return $gruende;
    }
}
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter Arbeitserlaubnis`
Expected: PASS, 6 Tests

- [ ] **Step 5: Mutationsprobe**

`KO_CODES` um `nationalpass` erweitern → `test_ein_fehlender_nationalpass_sperrt_nicht` muss rot werden.
`SPERRENDE_ZUSTAENDE` um `ProofChecklist::LAEUFT_AB` erweitern → `test_ein_bald_ablaufender_titel_sperrt_nicht` muss rot werden.
**Nicht** die Konstanten gegen Zahlen mutieren — Code und Test lesen dieselben Konstanten nur in `ProofChecklist`; die Statuswerte stehen in den Tests ausgeschrieben.

- [ ] **Step 6: Commit**

```bash
git add src/Support/ProofTypes.php src/Support/Arbeitserlaubnis.php tests/Unit/ArbeitserlaubnisTest.php
git commit -m "feat(recruiting): die harte Sperre ist abgeleitet und nennt ihre Gruende"
```

---

### Task 4: Die Zustandsspalten

**Files:**
- Create: `database/migrations/2026_10_01_000001_add_trigger_state.php`
- Modify: `src/Models/RecPerson.php` (`$casts`, Kommentar), `src/Models/RecDispoAssignment.php` (`$fillable`, `$casts`)
- Test: `tests/Integration/TriggerStateSchemaTest.php`

**Interfaces:**
- Produces: Spalten `rec_persons.aufgaben_signatur` (string 64, nullable), `rec_persons.aufgaben_gemeldet_at` (timestamp, nullable), `rec_dispo_assignments.aufgaben_erinnert_at` (timestamp, nullable)

**Warum an der Person und nicht an der Anstellung** (Spec §4): sonst bekaeme ein Mensch mit zwei Anstellungen zwei Nachrichten mit demselben Inhalt.

**Warum eine eigene Migrationsdatei:** eine bestehende zu aendern laeuft dort, wo sie schon gelaufen ist, nie wieder an. In diesem Zweig gilt das als Regel.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_die_drei_spalten_stehen_im_schema(): void
{
    $this->assertTrue(Schema::hasColumn('rec_persons', 'aufgaben_signatur'));
    $this->assertTrue(Schema::hasColumn('rec_persons', 'aufgaben_gemeldet_at'));
    $this->assertTrue(Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at'));
}

public function test_die_signatur_steht_nicht_in_fillable(): void
{
    // Geschrieben wird ausschliesslich ueber den Query Builder im Kommando —
    // observer-frei, damit eine Pruefung keinen ZAS-Marker setzt.
    $this->assertNotContains('aufgaben_signatur', (new RecPerson())->getFillable());
    $this->assertNotContains('aufgaben_gemeldet_at', (new RecPerson())->getFillable());
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TriggerStateSchema`
Expected: FAIL — die Spalten fehlen

- [ ] **Step 3: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Zustand, den die Nachrichtenregeln brauchen — mehr nicht.
 *
 * Die Aufgabenliste selbst wird NICHT gespeichert (Spec 3.2): sie aendert
 * sich taeglich, weil Nachweise ablaufen und Einsaetze dazukommen. Eine
 * gespeicherte Liste waere nach einer Nacht falsch.
 *
 * Die Signatur haengt an der PERSON, nicht an der Anstellung — sonst bekaeme
 * ein Mensch mit zwei Anstellungen zwei Nachrichten mit demselben Inhalt.
 * Die Erinnerung haengt an der EINBUCHUNG, weil sie sich auf genau diesen
 * Einsatz bezieht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'aufgaben_signatur')) {
                $table->string('aufgaben_signatur', 64)->nullable()->after('letzte_anmeldung_at');
            }
            if (!Schema::hasColumn('rec_persons', 'aufgaben_gemeldet_at')) {
                $table->timestamp('aufgaben_gemeldet_at')->nullable()->after('aufgaben_signatur');
            }
        });

        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at')) {
                $table->timestamp('aufgaben_erinnert_at')->nullable()->after('reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            foreach (['aufgaben_signatur', 'aufgaben_gemeldet_at'] as $spalte) {
                if (Schema::hasColumn('rec_persons', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });

        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at')) {
                $table->dropColumn('aufgaben_erinnert_at');
            }
        });
    }
};
```

In `RecPerson::$casts` ergaenzen: `'aufgaben_gemeldet_at' => 'datetime'`.
In `RecDispoAssignment::$casts` ergaenzen: `'aufgaben_erinnert_at' => 'datetime'`.
**`$fillable` bleibt unberuehrt** — der Massenzuweisungs-Waechter aus dem Konto-Zweig verlangt zu jeder gesperrten Spalte einen ausgeschriebenen Grund; den oben genannten eintragen.

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alles gruen, inklusive `MassenzuweisungGeschlosseneWeltTest`

- [ ] **Step 5: Migration gegen SQLite fahren**

Im Integrationstest, der das Schema ohnehin von Hand baut, zusaetzlich einen Fall, der
die Migration selbst zweimal anwendet und danach zuruecknimmt:

```php
public function test_die_migration_ist_idempotent_und_umkehrbar(): void
{
    $migration = require base_path('database/migrations/2026_10_01_000001_add_trigger_state.php');

    $migration->up();
    $migration->up(); // zweiter Lauf darf nicht werfen (hasColumn-Wache)
    $this->assertTrue(Schema::hasColumn('rec_persons', 'aufgaben_signatur'));

    $migration->down();
    $this->assertFalse(Schema::hasColumn('rec_persons', 'aufgaben_signatur'));

    $migration->up(); // und wieder hoch, damit die uebrigen Tests laufen
}
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_01_000001_add_trigger_state.php src/Models/RecPerson.php src/Models/RecDispoAssignment.php tests/Integration/TriggerStateSchemaTest.php
git commit -m "feat(recruiting): die drei Spalten, die die Nachrichtenregeln brauchen"
```

---

### Task 5: Der HR-Fall auch ohne Bewerber

**Files:**
- Create: `database/migrations/2026_10_01_000002_add_employee_to_hr_desk_cases.php`
- Modify: `src/Models/RecHrDeskCase.php` (`$fillable`, neuer Grund)
- Test: `tests/Integration/HrDeskCaseFuerMitarbeiterTest.php`

**Interfaces:**
- Produces: Spalte `rec_hr_desk_cases.rec_employee_id` (nullable, indiziert), Konstante `RecHrDeskCase::REASON_WORK_PERMIT = 'work_permit'`

**Warum:** `rec_hr_desk_cases` haengt heute ausschliesslich an `rec_applicant_id`. Wer ueber ZAS kam und nie Bewerber war — das ist der Grossteil des Bestands — kann gar keinen HR-Fall bekommen. Die harte Sperre braucht aber genau fuer diese Menschen einen.

**`rec_applicant_id` wird nullable**, falls es das nicht schon ist. Ein Fall haengt danach an einem von beiden.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_ein_fall_kann_an_einem_mitarbeiter_haengen(): void
{
    $fall = RecHrDeskCase::create([
        'uuid'            => (string) Str::uuid(),
        'rec_employee_id' => 42,
        'team_id'         => 6,
        'reason'          => RecHrDeskCase::REASON_WORK_PERMIT,
        'status'          => RecHrDeskCase::STATUS_OPEN,
        'opened_at'       => now(),
    ]);

    $this->assertSame(42, $fall->fresh()->rec_employee_id);
    $this->assertNull($fall->fresh()->rec_applicant_id);
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HrDeskCaseFuerMitarbeiter`
Expected: FAIL — Spalte fehlt

- [ ] **Step 3: Umsetzen**

Migration nach dem Muster aus Task 4, mit `hasColumn`-Wache in `up()` und `down()`:

```php
$table->unsignedBigInteger('rec_employee_id')->nullable()->index()->after('rec_applicant_id');
```

In `RecHrDeskCase`:

```php
// Die Arbeitserlaubnis fehlt oder ist abgelaufen (Canvas-Abgleich Folie 14).
// Angelegt vom Einsatz-Trigger, nicht aus dem Bewerberprozess — deshalb
// haengt dieser Grund am MITARBEITER und nicht am Bewerber.
public const REASON_WORK_PERMIT = 'work_permit';
```

`'rec_employee_id'` in `$fillable` aufnehmen und das Label fuer die Oberflaeche ergaenzen (dort, wo die uebrigen `REASON_*` ihre deutschen Labels bekommen).

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`

- [ ] **Step 5: Mutationsprobe**

`rec_employee_id` wieder aus `$fillable` nehmen → der neue Test muss rot werden.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_01_000002_add_employee_to_hr_desk_cases.php src/Models/RecHrDeskCase.php tests/Integration/HrDeskCaseFuerMitarbeiterTest.php
git commit -m "feat(recruiting): ein HR-Fall kann auch an einem Mitarbeiter ohne Bewerbung haengen"
```

---

### Task 6: Der Einsatz-Bezug

**Files:**
- Create: `src/Support/EinsatzBezug.php`
- Test: `tests/Unit/EinsatzBezugTest.php`

**Interfaces:**
- Produces:
  ```php
  EinsatzBezug::naechster(array $einsaetze, string $heute): ?array;
  EinsatzBezug::loestAus(array $einsatz, string $heute, int $vorlaufTage): bool;
  EinsatzBezug::erinnerungFaellig(array $einsatz, string $heute, int $schwelleTage): bool;
  ```
  `$einsatz` ist ein Feld mit mindestens `datum` (`Y-m-d`) und `status_id` (int).

**Die drei Zahlen** stehen in dieser Klasse als Konstanten: `VORLAUF_TAGE = 4`, `ERINNERUNG_TAGE = 2`, `PAUSE_TAGE = 7` (Spec §7). Sie werden hereingereicht, damit die Tests sie ausschreiben koennen.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_ein_angebot_loest_nichts_aus(): void
{
    // STATUS_ANGEBOT = 0. Angefragt ist nicht gebucht.
    $this->assertFalse(EinsatzBezug::loestAus(
        ['datum' => '2026-10-20', 'status_id' => 0], '2026-10-01', 4
    ));
}

public function test_ein_auftrag_mit_genug_vorlauf_loest_aus(): void
{
    $this->assertTrue(EinsatzBezug::loestAus(
        ['datum' => '2026-10-20', 'status_id' => 1], '2026-10-01', 4
    ));
}

public function test_vier_tage_vorlauf_reichen_genau(): void
{
    $this->assertTrue(EinsatzBezug::loestAus(
        ['datum' => '2026-10-05', 'status_id' => 1], '2026-10-01', 4
    ));
}

public function test_drei_tage_vorlauf_reichen_nicht(): void
{
    // Darunter ist die Nachricht keine Hilfe mehr, sondern Stoerung.
    $this->assertFalse(EinsatzBezug::loestAus(
        ['datum' => '2026-10-04', 'status_id' => 1], '2026-10-01', 4
    ));
}

public function test_ein_vergangener_einsatz_loest_nichts_aus(): void
{
    $this->assertFalse(EinsatzBezug::loestAus(
        ['datum' => '2026-09-30', 'status_id' => 1], '2026-10-01', 4
    ));
}

public function test_zwei_tage_vorher_ist_die_erinnerung_faellig(): void
{
    $this->assertTrue(EinsatzBezug::erinnerungFaellig(
        ['datum' => '2026-10-03', 'status_id' => 1], '2026-10-01', 2
    ));
}

public function test_drei_tage_vorher_noch_nicht(): void
{
    $this->assertFalse(EinsatzBezug::erinnerungFaellig(
        ['datum' => '2026-10-04', 'status_id' => 1], '2026-10-01', 2
    ));
}

public function test_der_naechste_einsatz_ist_der_zeitlich_naechste_kommende(): void
{
    $naechster = EinsatzBezug::naechster([
        ['datum' => '2026-10-20', 'status_id' => 1],
        ['datum' => '2026-10-05', 'status_id' => 1],
        ['datum' => '2026-09-28', 'status_id' => 1],
    ], '2026-10-01');

    $this->assertSame('2026-10-05', $naechster['datum']);
}

public function test_ohne_kommenden_einsatz_gibt_es_keinen_bezug(): void
{
    $this->assertNull(EinsatzBezug::naechster([
        ['datum' => '2026-09-28', 'status_id' => 1],
    ], '2026-10-01'));
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EinsatzBezug`
Expected: FAIL mit `Class ... not found`

- [ ] **Step 3: Umsetzen**

```php
<?php

namespace Platform\Recruiting\Support;

use DateTimeImmutable;

/**
 * Welcher Einsatz gehoert zu einem offenen Punkt — und loest er ueberhaupt
 * etwas aus?
 *
 * Rein: keine Datenbank, keine Uhr. Das Heute wird hereingereicht, die drei
 * Zahlen ebenfalls — sonst koennten die Tests sie nicht ausschreiben, und
 * eine Mutation der Konstante koennte nicht fallen.
 *
 * Ausgeloest wird nur bei AUFTRAG (status_id 1), nicht bei ANGEBOT (0):
 * angefragt ist nicht gebucht, und wer absagt, soll nichts bekommen.
 *
 * Die Mindest-Vorlaufzeit ist keine Bequemlichkeit: eine Nachricht, die am
 * Vorabend „lade deinen Ausweis hoch" sagt, ist keine Hilfe. Der Fall gehoert
 * dann auf die HR-Liste, nicht ins Handy des Mitarbeiters.
 */
final class EinsatzBezug
{
    public const VORLAUF_TAGE     = 4;
    public const ERINNERUNG_TAGE  = 2;
    public const PAUSE_TAGE       = 7;

    private const STATUS_AUFTRAG = 1;

    /** @param array{datum?:string, status_id?:int} $einsatz */
    public static function loestAus(array $einsatz, string $heute, int $vorlaufTage): bool
    {
        if ((int) ($einsatz['status_id'] ?? -1) !== self::STATUS_AUFTRAG) {
            return false;
        }

        $tage = self::tageBis($einsatz['datum'] ?? '', $heute);

        return $tage !== null && $tage >= $vorlaufTage;
    }

    /** @param array{datum?:string, status_id?:int} $einsatz */
    public static function erinnerungFaellig(array $einsatz, string $heute, int $schwelleTage): bool
    {
        if ((int) ($einsatz['status_id'] ?? -1) !== self::STATUS_AUFTRAG) {
            return false;
        }

        $tage = self::tageBis($einsatz['datum'] ?? '', $heute);

        return $tage !== null && $tage <= $schwelleTage;
    }

    /**
     * @param list<array{datum?:string, status_id?:int}> $einsaetze
     * @return array{datum?:string, status_id?:int}|null
     */
    public static function naechster(array $einsaetze, string $heute): ?array
    {
        $kommende = array_filter(
            $einsaetze,
            static fn (array $e) => (self::tageBis($e['datum'] ?? '', $heute) ?? -1) >= 0
        );

        if ($kommende === []) {
            return null;
        }

        usort($kommende, static fn ($a, $b) => ($a['datum'] ?? '') <=> ($b['datum'] ?? ''));

        return $kommende[0];
    }

    /** Tage von heute bis zum Einsatz; null, wenn das Datum unlesbar ist. */
    private static function tageBis(string $datum, string $heute): ?int
    {
        try {
            $ziel = new DateTimeImmutable($datum);
            $jetzt = new DateTimeImmutable($heute);
        } catch (\Throwable) {
            // Unlesbar heisst: loest nichts aus. Die sichere Richtung — ein
            // Schrottwert in der Datenbank darf keinen Versand ausloesen.
            return null;
        }

        if ($datum === '' || $heute === '') {
            return null;
        }

        return (int) $jetzt->setTime(0, 0)->diff($ziel->setTime(0, 0))->format('%r%a');
    }
}
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EinsatzBezug`
Expected: PASS, 9 Tests

- [ ] **Step 5: Mutationsprobe**

`>= $vorlaufTage` → `> $vorlaufTage` → `test_vier_tage_vorlauf_reichen_genau` muss rot werden.
`<= $schwelleTage` → `< $schwelleTage` → `test_zwei_tage_vorher_ist_die_erinnerung_faellig` muss rot werden.
Die Statuspruefung entfernen → `test_ein_angebot_loest_nichts_aus` muss rot werden.
`try/catch` entfernen und ein unlesbares Datum einspeisen → ein eigener Test dafuer muss rot werden; diesen mitschreiben.

- [ ] **Step 6: Commit**

```bash
git add src/Support/EinsatzBezug.php tests/Unit/EinsatzBezugTest.php
git commit -m "feat(recruiting): wann ein Einsatz etwas ausloest und welcher gemeint ist"
```

---

### Task 7: Die Nachrichtenregeln

**Files:**
- Create: `src/Support/TriggerRegeln.php`
- Test: `tests/Unit/TriggerRegelnTest.php`

**Interfaces:**
- Produces:
  ```php
  TriggerRegeln::signatur(array $offenePunkte): string;   // 64 Zeichen Hex
  TriggerRegeln::darfMelden(?string $letzteSignatur, ?string $gemeldetAm, string $neueSignatur, string $jetzt, int $pauseTage): bool;
  ```

**Die vier Bedingungen aus Spec §2.3** fuer die erste Nachricht: etwas ist offen · ueber genau diese Punkte wurde noch nicht informiert · die letzte Nachricht ist lange genug her · der Einsatz ist weit genug weg. Die vierte liegt in `EinsatzBezug` (Task 6), die ersten drei hier.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_ohne_offene_punkte_gibt_es_keine_signatur(): void
{
    $this->assertSame('', TriggerRegeln::signatur([]));
}

public function test_dieselben_punkte_ergeben_dieselbe_signatur(): void
{
    $a = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'immatrikulation']]);
    $b = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'immatrikulation']]);

    $this->assertSame($a, $b);
}

public function test_die_reihenfolge_aendert_die_signatur_nicht(): void
{
    // Sonst meldete eine andere Sortierung dieselben Punkte als neu.
    $a = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'immatrikulation']]);
    $b = TriggerRegeln::signatur([['code' => 'immatrikulation'], ['code' => 'ausweis']]);

    $this->assertSame($a, $b);
}

public function test_ein_zusaetzlicher_punkt_aendert_die_signatur(): void
{
    $a = TriggerRegeln::signatur([['code' => 'ausweis']]);
    $b = TriggerRegeln::signatur([['code' => 'ausweis'], ['code' => 'aufenthaltstitel']]);

    $this->assertNotSame($a, $b);
}

public function test_beim_ersten_mal_wird_gemeldet(): void
{
    $this->assertTrue(TriggerRegeln::darfMelden(null, null, 'abc', '2026-10-01 09:00:00', 7));
}

public function test_dieselben_punkte_werden_nicht_erneut_gemeldet(): void
{
    $this->assertFalse(TriggerRegeln::darfMelden('abc', '2026-09-01 09:00:00', 'abc', '2026-10-01 09:00:00', 7));
}

public function test_neue_punkte_werden_gemeldet_wenn_die_pause_um_ist(): void
{
    $this->assertTrue(TriggerRegeln::darfMelden('abc', '2026-09-20 09:00:00', 'xyz', '2026-10-01 09:00:00', 7));
}

public function test_neue_punkte_warten_bis_die_pause_um_ist(): void
{
    // Sieben Tage Pause: am sechsten Tag noch nicht.
    $this->assertFalse(TriggerRegeln::darfMelden('abc', '2026-09-25 09:00:00', 'xyz', '2026-10-01 09:00:00', 7));
}

public function test_am_achten_tag_ist_die_pause_um(): void
{
    $this->assertTrue(TriggerRegeln::darfMelden('abc', '2026-09-23 09:00:00', 'xyz', '2026-10-01 09:00:00', 7));
}

public function test_ohne_offene_punkte_wird_nie_gemeldet(): void
{
    $this->assertFalse(TriggerRegeln::darfMelden('abc', '2026-09-01 09:00:00', '', '2026-10-01 09:00:00', 7));
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TriggerRegeln`

- [ ] **Step 3: Umsetzen**

```php
<?php

namespace Platform\Recruiting\Support;

use DateTimeImmutable;

/**
 * Wann geht eine Nachricht raus — und wann bewusst nicht.
 *
 * Rein: keine Datenbank, keine Uhr, keine Konfiguration. Alles wird
 * hereingereicht.
 *
 * Die Signatur ist der Kern: sie beantwortet „wurde ueber GENAU DIESE Punkte
 * schon informiert". Sie ist sortierunabhaengig, sonst meldete eine andere
 * Reihenfolge dieselben Punkte als neu. Ein leeres Feld ergibt bewusst die
 * leere Signatur — „nichts offen" ist kein Zustand, ueber den man meldet.
 *
 * Markus' Folie 27 nennt „zu viele Nachrichten" als eines der drei Dinge, die
 * wir vermeiden muessen. Diese Klasse ist die Stelle, an der das durchgesetzt
 * wird.
 */
final class TriggerRegeln
{
    /** @param list<array{code?:string}> $offenePunkte */
    public static function signatur(array $offenePunkte): string
    {
        $codes = array_filter(array_map(
            static fn (array $p) => (string) ($p['code'] ?? ''),
            $offenePunkte
        ));

        if ($codes === []) {
            return '';
        }

        sort($codes);

        return hash('sha256', implode('|', $codes));
    }

    public static function darfMelden(
        ?string $letzteSignatur,
        ?string $gemeldetAm,
        string $neueSignatur,
        string $jetzt,
        int $pauseTage,
    ): bool {
        if ($neueSignatur === '') {
            return false;
        }

        if ($letzteSignatur === $neueSignatur) {
            return false;
        }

        if ($gemeldetAm === null || $gemeldetAm === '') {
            return true;
        }

        try {
            $zuletzt = new DateTimeImmutable($gemeldetAm);
            $heute   = new DateTimeImmutable($jetzt);
        } catch (\Throwable) {
            // Unlesbarer Zeitstempel: nicht melden. Die sichere Richtung ist
            // hier das Schweigen, nicht die Nachricht.
            return false;
        }

        return $zuletzt->modify('+' . $pauseTage . ' days') <= $heute;
    }
}
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TriggerRegeln`
Expected: PASS, 10 Tests

- [ ] **Step 5: Mutationsprobe**

`sort($codes)` entfernen → `test_die_reihenfolge_aendert_die_signatur_nicht` muss rot werden.
`$letzteSignatur === $neueSignatur` → `!==` → `test_dieselben_punkte_werden_nicht_erneut_gemeldet` muss rot werden.
`<=` → `<` beim Pausenvergleich → `test_am_achten_tag_ist_die_pause_um` muss rot werden.
Die Leer-Pruefung entfernen → `test_ohne_offene_punkte_wird_nie_gemeldet` muss rot werden.

- [ ] **Step 6: Commit**

```bash
git add src/Support/TriggerRegeln.php tests/Unit/TriggerRegelnTest.php
git commit -m "feat(recruiting): die Regeln, wann eine Aufgaben-Nachricht rausgeht"
```

---

### Task 8: Die Aufgabenliste mit Einsatz-Bezug

**Files:**
- Create: `src/Services/OffenePunkte.php`
- Test: `tests/Integration/OffenePunkteTest.php`

**Interfaces:**
- Consumes: `ProofReader::checklist()`, `PersonScopeResolver::forEmployee()`, `EinsatzBezug::naechster()`, `Arbeitserlaubnis::istGesperrt()`
- Produces:
  ```php
  OffenePunkte::fuer(RecEmployee $employee, ?string $heute = null): array{
      punkte: list<array{code:string, label:string, status:string, ko:bool}>,
      einsatz: ?array{datum:string, taetigkeit:?string, event:?string},
      gesperrt: bool,
  }
  ```

**Bindende Vorgabe (Spec §3.2):** Wird bei jedem Aufruf **berechnet**, nie gespeichert. Die Wirklichkeit aendert sich taeglich.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_die_liste_nennt_den_naechsten_einsatz_als_bezug(): void
{
    $person = $this->personAnlegen();
    $ma = $this->mitarbeiter(['rec_person_id' => $person]);
    $this->einbuchung($ma, ['datum' => '2026-10-12', 'status_id' => 1, 'taetigkeit' => 'Service']);

    $stand = (new OffenePunkte())->fuer($ma, '2026-10-01');

    $this->assertSame('2026-10-12', $stand['einsatz']['datum']);
    $this->assertSame('Service', $stand['einsatz']['taetigkeit']);
}

public function test_ohne_kommenden_einsatz_steht_kein_bezug_da(): void
{
    $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);

    $this->assertNull((new OffenePunkte())->fuer($ma, '2026-10-01')['einsatz']);
}

public function test_ein_fehlender_aufenthaltstitel_macht_den_punkt_zum_ko(): void
{
    $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen(), 'is_eu_citizen' => false]);

    $stand = (new OffenePunkte())->fuer($ma, '2026-10-01');
    $titel = $this->zeileMitCode($stand['punkte'], 'aufenthaltstitel');

    $this->assertTrue($titel['ko']);
    $this->assertTrue($stand['gesperrt']);
}

public function test_ein_fehlender_ausweis_sperrt_nicht(): void
{
    $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen(), 'is_eu_citizen' => true]);

    $stand = (new OffenePunkte())->fuer($ma, '2026-10-01');

    $this->assertFalse($stand['gesperrt']);
    $this->assertFalse($this->zeileMitCode($stand['punkte'], 'ausweis')['ko']);
}

public function test_erledigte_punkte_stehen_nicht_in_der_liste(): void
{
    $ma = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
    $this->nachweis($ma, 'ausweis');

    $codes = array_column((new OffenePunkte())->fuer($ma, '2026-10-01')['punkte'], 'code');

    $this->assertNotContains('ausweis', $codes);
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter OffenePunkte`

- [ ] **Step 3: Umsetzen**

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Arbeitserlaubnis;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Was ist offen, und wofuer.
 *
 * BERECHNET, NIE GESPEICHERT (Spec 3.2). Nachweise laufen ab, Einsaetze
 * kommen dazu — eine gespeicherte Liste waere nach einer Nacht falsch.
 *
 * Der Bezug ist der naechste KOMMENDE Auftrag. Er macht aus „Ausweis fehlt"
 * ein „Ausweis fehlt — gebraucht fuer deinen Einsatz am 12.10. in
 * Duesseldorf". Ohne kommenden Einsatz bleibt die Liste trotzdem richtig, nur
 * ohne Anlass.
 */
class OffenePunkte
{
    public function __construct(
        private readonly ProofReader $nachweise = new ProofReader(),
        private readonly PersonScopeResolver $scope = new PersonScopeResolver(),
    ) {
    }

    /**
     * @return array{punkte: list<array{code:string, label:string, status:string, ko:bool}>, einsatz: ?array{datum:string, taetigkeit:?string, event:?string}, gesperrt: bool}
     */
    public function fuer(RecEmployee $employee, ?string $heute = null): array
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

        return [
            'punkte'   => $punkte,
            'einsatz'  => $this->naechsterEinsatz($employee, $heute),
            'gesperrt' => Arbeitserlaubnis::istGesperrt($checkliste),
        ];
    }

    /** @return array{datum:string, taetigkeit:?string, event:?string}|null */
    private function naechsterEinsatz(RecEmployee $employee, string $heute): ?array
    {
        $einsaetze = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $this->scope->forEmployee($employee)['ids'])
            ->whereNull('missing_since')
            ->get(['datum', 'status_id', 'taetigkeit', 'rec_dispo_event_id'])
            ->map(fn (RecDispoAssignment $a) => [
                'datum'      => (string) $a->datum,
                'status_id'  => (int) $a->status_id,
                'taetigkeit' => $a->taetigkeit,
                'event'      => $a->event?->name,
            ])
            ->all();

        return EinsatzBezug::naechster($einsaetze, $heute);
    }
}
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter OffenePunkte`
Expected: PASS, 5 Tests

- [ ] **Step 5: Mutationsprobe**

Den `offen`-Filter entfernen → `test_erledigte_punkte_stehen_nicht_in_der_liste` muss rot werden.
`ProofTypes::istKo(...)` durch `false` ersetzen → der K.-o.-Test muss rot werden.
`whereNull('missing_since')` entfernen → ein eigener Test mit einer verschwundenen Einbuchung muss rot werden; diesen mitschreiben.

- [ ] **Step 6: Commit**

```bash
git add src/Services/OffenePunkte.php tests/Integration/OffenePunkteTest.php
git commit -m "feat(recruiting): was offen ist und fuer welchen Einsatz"
```

---

### Task 9: Der Versand

**Files:**
- Create: `src/Services/Comms/AufgabenSender.php`
- Modify: `config/recruiting.php` (Block `aufgaben.vorlagen`)
- Test: `tests/Integration/AufgabenSenderTest.php`

**Interfaces:**
- Consumes: `OffenePunkte::fuer()` (Task 8)
- Produces: `AufgabenSender::sende(RecEmployee $employee, array $stand, string $anlass): string` — Status `sent` / `failed` / `nicht_konfiguriert`. `$anlass` ist `'neu'` oder `'erinnerung'`.

**Vorbild ist `src/Services/Comms/EinmalcodeSender.php`** — und zwar wegen dessen zwei Altfehler-Abwehren, die hier genauso gelten:

1. `RecEmployee::sendPortalNotification()` meldet `ok: true` **ohne** `$message->status` zu pruefen — ein von Meta ABGELEHNTER Versand gilt dort als Erfolg. Dieser Sender prueft den Status und meldet `failed`.
2. `HoldingTemplateComponents::build()` setzt bei einem **unbekannten Platzhalter** still den Vornamen ein. Dieser Sender prueft die Platzhalter selbst und **lehnt den Versand ab**, statt Muell zu verschicken.

**Bindende Vorgaben:**
- Vorlagenname und Platzhalter kommen **aus der Konfiguration**, nicht aus dem Code. Fehlt die Einstellung, wird **nicht** verschickt und es gibt eine `Log::error`-Zeile — kein stiller Fehlschlag.
- **Keine vollstaendige Rufnummer ins Log.** Muster: die gekuerzte Form in `KontoWriter`.
- Der Versand beruehrt `rec_employees` **nicht**.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
public function test_ohne_vorlage_wird_nichts_verschickt(): void
{
    config(['recruiting.aufgaben.vorlagen.neu.name' => '']);

    $this->assertSame('nicht_konfiguriert', (new AufgabenSender())->sende($this->ma, $this->stand, 'neu'));
    $this->assertSame([], $this->meta->calls);
}

public function test_ein_von_meta_abgelehnter_versand_ist_kein_erfolg(): void
{
    $this->meta->lehntAb();

    $this->assertSame('failed', (new AufgabenSender())->sende($this->ma, $this->stand, 'neu'));
}

public function test_ein_unbefuellbarer_platzhalter_verhindert_den_versand(): void
{
    config(['recruiting.aufgaben.vorlagen.neu.platzhalter' => ['gibt_es_nicht']]);

    $this->assertSame('failed', (new AufgabenSender())->sende($this->ma, $this->stand, 'neu'));
    $this->assertSame([], $this->meta->calls);
}

public function test_die_volle_nummer_steht_in_keiner_log_zeile(): void
{
    (new AufgabenSender())->sende($this->ma, $this->stand, 'neu');

    foreach ($this->log->zeilen as $zeile) {
        $this->assertStringNotContainsString(self::NUMMER, json_encode($zeile));
    }
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter AufgabenSender`

- [ ] **Step 3: Konfiguration und Sender schreiben**

In `config/recruiting.php`:

```php
'aufgaben' => [
    // Zwei Anlaesse (Spec 2.3): die erste Nachricht und die Erinnerung, wenn
    // der Einsatz naeher rueckt. Ohne genehmigte Meta-Vorlage geht nichts
    // raus — die Aufgabenliste im Portal funktioniert davon unabhaengig.
    'vorlagen' => [
        'neu' => [
            'name'        => env('RECRUITING_AUFGABEN_VORLAGE_NEU', ''),
            'sprache'     => env('RECRUITING_AUFGABEN_VORLAGE_SPRACHE', 'de'),
            'platzhalter' => ['anzahl'],
        ],
        'erinnerung' => [
            'name'        => env('RECRUITING_AUFGABEN_VORLAGE_ERINNERUNG', ''),
            'sprache'     => env('RECRUITING_AUFGABEN_VORLAGE_SPRACHE', 'de'),
            'platzhalter' => ['anzahl', 'datum'],
        ],
    ],
],
```

```php
public function sende(RecEmployee $employee, array $stand, string $anlass): string
{
    $vorlage = (array) config('recruiting.aufgaben.vorlagen.' . $anlass, []);
    $name    = trim((string) ($vorlage['name'] ?? ''));

    if ($name === '') {
        // Kein stiller Fehlschlag: ohne Vorlage kann niemand erreicht werden,
        // und das ist ein Zustand, in dem die ganze Strecke tot ist.
        Log::error('recruiting.aufgaben.vorlage_fehlt', ['anlass' => $anlass]);

        return 'nicht_konfiguriert';
    }

    $werte = [
        'anzahl' => (string) count($stand['punkte']),
        'datum'  => (string) ($stand['einsatz']['datum'] ?? ''),
    ];

    $komponenten = [];
    foreach ((array) ($vorlage['platzhalter'] ?? []) as $platzhalter) {
        if (!array_key_exists($platzhalter, $werte) || $werte[$platzhalter] === '') {
            // HoldingTemplateComponents::build() wuerde hier still den Vornamen
            // einsetzen — der Mensch bekaeme seinen Namen statt der Zahl, und
            // Meta naehme es an. Lieber gar nicht senden.
            Log::warning('recruiting.aufgaben.platzhalter_unbekannt', [
                'anlass'      => $anlass,
                'platzhalter' => $platzhalter,
            ]);

            return 'failed';
        }

        $komponenten[] = [
            'type'           => 'text',
            'parameter_name' => strtolower($platzhalter),
            'text'           => $werte[$platzhalter],
        ];
    }

    $nachricht = $this->meta->sendTemplate($employee, $name, (string) ($vorlage['sprache'] ?? 'de'), $komponenten);

    // sendPortalNotification() meldet hier ok:true OHNE den Status zu pruefen —
    // ein von Meta abgelehnter Versand gilt dort als Erfolg, und jemand wartet
    // auf eine Nachricht, die nie ankam.
    if (($nachricht->status ?? '') === 'failed') {
        Log::warning('recruiting.aufgaben.abgelehnt', [
            'mitarbeiter'    => $employee->id,
            'nummer_endet_auf' => substr(PhoneE164::suffix((string) $employee->phone), -4),
        ]);

        return 'failed';
    }

    return 'sent';
}
```

**Die Nachricht nennt die Punkte nicht einzeln** — sie nennt ihre **Anzahl** und verweist
aufs Portal (Spec §2.1: das Portal traegt die Aufgaben). Waere die Nachricht der Traeger,
muesste jede Aenderung eine neue erzeugen.

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter AufgabenSender`

- [ ] **Step 5: Mutationsprobe**

Die Statuspruefung entfernen → `test_ein_von_meta_abgelehnter_versand_ist_kein_erfolg` muss rot werden.
Die Platzhalterpruefung entfernen → der entsprechende Test muss rot werden.
Die Kuerzung der Nummer entfernen → der Log-Test muss rot werden.
**Die Meta-Attrappe muss strenger sein als nachsichtig** — eine, die jeden Vorlagennamen und jeden Platzhalter annimmt, prueft nichts.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Comms/AufgabenSender.php config/recruiting.php tests/Integration/AufgabenSenderTest.php
git commit -m "feat(recruiting): die Aufgaben-Nachricht, die aufs Portal verweist statt alles aufzuzaehlen"
```

---

### Task 10: Der Ausloeser

**Files:**
- Create: `src/Console/Commands/EinsatzPruefung.php`
- Modify: `src/RecruitingServiceProvider.php` (Registrierung + Zeitplan)
- Test: `tests/Integration/EinsatzPruefungTest.php`

**Interfaces:**
- Consumes: `OffenePunkte::fuer()`, `TriggerRegeln::signatur()`, `TriggerRegeln::darfMelden()`, `EinsatzBezug::loestAus()`, `EinsatzBezug::erinnerungFaellig()`, `AufgabenSender::sende()`, `Arbeitserlaubnis::gruende()`
- Produces: Kommando `recruiting:einsatz-pruefung` mit `--team=`, `--ids=`, `--dry-run`

**Bindende Vorgaben:**
- **Laeuft NACH dem Dispo-Import, nicht darin** (Spec §3.3). Der Import soll importieren; stolpert die Pruefung, darf das die Lieferung nicht gefaehrden.
- **Jeder Mensch in seinem eigenen Fehlerkaefig.**
- **Observer-frei schreiben** — `DB::table(...)->update(...)`, nie ueber Eloquent.
- **`--dry-run` schreibt nichts und verschickt nichts.**
- Kennungen in der Ausgabe, **nie Namen**. Nie eine volle Rufnummer.
- **Ohne `--welle` kein Versand an alle** — die Pruefung laeuft vollstaendig, nur der Versand ist gedeckelt.

**ACHTUNG — ein HR-Fall ohne Bewerber ist heute UNSICHTBAR (Befund aus Aufgabe 5).**
`HrDeskCaseVisibility::openCases()` filtert mit `whereHas('applicant', ...)` und
`reasonCounts()` geht ueber `applicants()`. Beide hangeln sich am **Bewerber** entlang.
Ein Fall mit `rec_applicant_id = NULL` hat keinen — er faellt aus **beiden** Abfragen
heraus. Der Trigger legte damit Faelle an, **die niemand je sieht**, und die Sperre waere
genau so folgenlos wie die fehlende Verbindung zu ZAS.

**Und es ist kein vergessener Filter, sondern Absicht** (Befund der Aufgabe-4/5-Pruefung,
von mir am Code nachgelesen). `constrainApplicant()` filtert zusaetzlich mit
`whereDoesntHave('employee')` — der HR-Schreibtisch zeigt **bewusst** nur Bewerber, die
noch **keine** Mitarbeiter sind. Die Sperre ist also **doppelt**: einmal ueber den
fehlenden Bewerber, einmal ueber den vorhandenen Mitarbeiter.

**Damit ist es eine Entscheidung, keine Reparatur.** Zwei Wege, und der Umsetzer trifft
die Wahl und begruendet sie im Bericht:

- **Der HR-Schreibtisch nimmt Mitarbeiter-Faelle mit.** Dann muessen beide Filter
  aufgeweicht werden, und die urspruengliche Absicht („dieser Schreibtisch gehoert dem
  Bewerberprozess") faellt. Die Bewerber-Faelle duerfen sich dabei **nicht** aendern —
  ein eigener Test muss das festhalten.
- **Die Arbeitserlaubnis-Faelle bekommen eine eigene Stelle**, etwa eine Zeile im Bericht
  des Einsatz-Pruefungs-Kommandos. Dann bleibt der Schreibtisch, wie er ist, aber HR
  muss an zwei Orte schauen.

**Was NICHT geht:** den Fall anlegen und es dabei belassen. Dann ist die harte Sperre
genau so folgenlos wie die fehlende Verbindung zu ZAS.

**`RecHrDeskCase` hat heute keine `employee()`-Beziehung** — die gehoert dazu, egal
welcher Weg gewaehlt wird.

**Und die elfte Falle liegt hier bereit:** drei Dateien bauen `rec_hr_desk_cases` von
Hand **ohne** `rec_employee_id` — `HrDeskNoRoutingForEmployeesTest`,
`HrDeskCaseVisibilityTest`, `ManualBookingCandidatesTest`. Wer die Sichtbarkeit anfasst,
zieht diese Schemata mit, sonst prueft er gegen eine Spalte, die SQLite in ein
String-Literal verwandelt.

Zwei Pflichttests, wenn der erste Weg gewaehlt wird:

```php
public function test_ein_fall_ohne_bewerber_steht_im_hr_schreibtisch(): void
public function test_er_zaehlt_auch_in_den_gruenden_mit(): void
```

Und eine Mutation: das `whereHas('applicant', ...)` wieder bedingungslos machen — beide
Tests muessen rot werden.

**Ablauf je Mensch:**
1. Offene Punkte holen (Task 8)
2. Ist gesperrt? → HR-Fall `REASON_WORK_PERMIT` anlegen, falls noch keiner offen ist
3. Signatur bilden, `darfMelden()` fragen, Vorlauf ueber `loestAus()` pruefen → ggf. `sende(..., 'neu')`, dann Signatur und Zeitpunkt an der Person schreiben
4. Je Einbuchung: `erinnerungFaellig()` und noch nicht erinnert → `sende(..., 'erinnerung')`, dann `aufgaben_erinnert_at` schreiben

**ZWEI FUNDE DER PRUEFUNG ZU AUFGABE 6/7, BEIDE GEMESSEN — SIE LANDEN HIER, WEIL DIESE
AUFGABE DIE ZUSTANDSSPALTEN BESITZT. Ohne sie ist der Motor gebaut und trotzdem stumm.**

**ET-16 — das System verstummt dauerhaft, und zwar im Regelfall.** Die Signatur wird
heute nie geloescht, wenn nichts mehr offen ist. Gemessener Ablauf: 01.01. fehlt der
Ausweis, Nachricht geht raus, Signatur H(ausweis) steht an der Person. 30.01. ist der
Ausweis da, die Liste ist leer — die Signatur bleibt aber stehen. 20.07. laeuft genau
derselbe Ausweis ab: dieselbe Punktmenge, also dieselbe Signatur, also Schweigen. Und
zwar fuer immer. Das ist kein Randfall, das ist der Normalfall ablaufender Dokumente.
**Auflage: sobald die Liste leer ist, werden `aufgaben_signatur` und
`aufgaben_gemeldet_at` zurueckgesetzt** (observer-frei, wie alles hier). Das kann nicht
spammen, weil ueber eine leere Liste ohnehin nie etwas rausgeht. Pflichttest: der
Dreischritt oben, mit einer zweiten Nachricht am Ende.

**ET-15 — die Pause verschluckt den zweiten Einsatz.** Gemessen: 03.10. Nachricht zu
Einsatz E1 am 20.10. 05.10. kommt ein neuer Punkt dazu UND ein Einsatz E2 am 09.10.
`loestAus()` ist wahr, aber die Pause laeuft bis 10.10. — die Nachricht zu E2 kommt
**nie**, denn danach ist E2 vorbei. Uebrig bliebe nur die Erinnerung, also genau die
Nachricht, die der Docblock selbst als wertlos bezeichnet; und auch die kann ausfallen,
weil `aufgaben_erinnert_at` an der Einbuchung haengt und nicht an der Signatur.
**Auflage: die Pause darf eine Nachricht nie hinter den Einsatz schieben, zu dem sie
gehoert.** Konkret: liegt der ausloesende Einsatz vor dem Ende der Pause, gewinnt der
Einsatz. Pflichttest: der Ablauf oben, die Nachricht zu E2 muss rausgehen.

**ET-23 — `sent` ist KEIN Beweis der Zustellung. Aus der Pruefung zu Aufgabe 9.** Der
Status kommt allein aus dem HTTP-Ergebnis des Annahme-Aufrufs; dass eine Nachricht nicht
zugestellt werden konnte, meldet Meta erst spaeter per Webhook (Fall 131026). **Haekelt
dieses Kommando auf `sent` hin ab, bekommt der betroffene Mensch NIE WIEDER eine
Nachricht** — dieselbe Sackgasse wie ET-16, nur ueber einen anderen Weg.
**Auflage: beim naechsten Lauf die gespeicherte Nachricht nachlesen; steht sie auf
`failed`, die Signatur raeumen, damit es einen zweiten Versuch gibt.** Das ist nicht
perfekt — im Core-Webhook kann ein spaetes `sent` ein `failed` ueberschreiben, bekannter
Altfehler — aber strikt besser als Dauerschweigen.
**Die Gegenrichtung ist ebenfalls real:** faellt die Ausnahme NACH dem HTTP-POST (die
Anlage von Thread, Nachricht und Protokoll passiert erst danach), meldet der Sender
`failed`, obwohl die Nachricht drausen ist — dann sendet dieses Kommando erneut, und der
Mensch bekommt sie zweimal. Beim Zuschnitt der Wiederholung mitdenken.

**ET-18 — der tote Stempel. Aus der Pruefung zu Aufgabe 8, gemessen.** Verschwindet eine
Einbuchung aus der ZAS-Lieferung und taucht spaeter wieder auf, setzt
`ZasDispoWebexportImporter.php:193` beim Wiederauftauchen `missing_since => null`
zurueck — **`aufgaben_erinnert_at` bleibt aber stehen.** Die Wiederholungsbremse
unterdrueckt dann eine Erinnerung, die voellig berechtigt waere. **Auflage: beim
Wiederauftauchen auch den Stempel raeumen, mit Test.** In Aufgabe 8 selbst entsteht
uebrigens KEIN toter Bezug — sie rechnet jedes Mal neu, und `einsatz` wird dann `null`,
waehrend die Punkte stehen bleiben. Das ist fachlich richtig, hat aber eine Folge fuer
Aufgabe 11: **die Aufgabenzeile muss ohne Bezug tragen koennen.**

**ET-17 — den blinden Fleck sichtbar machen.** `OffenePunkte` benutzt bewusst nur
`['ids']` des `PersonScopeResolver` und verwirft `['abweichend']`, genau wie
`ProofReader`. Das ist richtig so (sonst waeren Nachweise und Einsaetze darueber uneins,
WER der Mensch ist), aber es passiert heute wortlos: ohne `rec_person_id` verlangt
Zweig 2 des Resolvers zusaetzlich dieselbe Handynummer, und weicht sie ab, verschwindet
der Einsatz der Schwester-Anstellung spurlos. **Auflage: das Kommando zaehlt die
Menschen mit nichtleerem `abweichend` und nennt die Zahl im Bericht** — eine Zeile, die
den Fleck sichtbar macht, statt ihn zu heilen. Geheilt wird er ueber `rec_person_id`,
und neue Anstellungen aus Funnel und ZAS entstehen weiterhin ohne, der Fleck waechst
also nach.

**Die drei Zahlen 4/2/7 stehen damit zur Frage an den Kunden** (der User hat sie
ausdruecklich als vorlaeufig markiert). Die Auflage oben ist die Reparatur, die
unabhaengig von den Zahlen traegt — sie macht die Kollision unmoeglich, statt sie
wegzurechnen.

- [ ] **Step 1: Die Tests schreiben**

```php
public function test_ein_angebot_loest_nichts_aus(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 0]);

    $this->laufe('2026-10-01');

    $this->assertSame([], $this->sender->versandt);
}

public function test_ein_auftrag_mit_vorlauf_meldet_die_offenen_punkte(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    $this->laufe('2026-10-01');

    $this->assertCount(1, $this->sender->versandt);
    $this->assertSame('neu', $this->sender->versandt[0]['anlass']);
}

public function test_derselbe_stand_meldet_kein_zweites_mal(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    $this->laufe('2026-10-01');
    $this->laufe('2026-10-02');

    $this->assertCount(1, $this->sender->versandt);
}

public function test_ein_neuer_offener_punkt_meldet_wieder(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);
    $this->laufe('2026-10-01');

    // Ein Nachweis laeuft ab: die Punkte sind nicht mehr dieselben.
    $this->nachweisLaeuftAb($ma, 'ausweis', '2026-10-05');

    // Erst nach der Pause von sieben Tagen (Zahl ausgeschrieben).
    $this->laufe('2026-10-09');

    $this->assertCount(2, $this->sender->versandt);
}

public function test_zwei_auftraege_am_selben_tag_ergeben_eine_nachricht(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);
    $this->einbuchung($ma, ['datum' => '2026-10-21', 'status_id' => 1]);

    $this->laufe('2026-10-01');

    // Die Signatur haengt an der Person, nicht an der Einbuchung — sonst
    // bekaeme ein Schub am Freitag drei gleiche Nachrichten.
    $this->assertCount(1, $this->sender->versandt);
}

public function test_die_erinnerung_geht_erst_kurz_vor_dem_einsatz(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

    $this->laufe('2026-10-01');          // erste Nachricht
    $this->laufe('2026-10-05');          // noch zu frueh fuer die Erinnerung
    $this->assertCount(1, $this->sender->versandt);

    $this->laufe('2026-10-08');          // zwei Tage vorher (Zahl ausgeschrieben)
    $this->assertCount(2, $this->sender->versandt);
    $this->assertSame('erinnerung', $this->sender->versandt[1]['anlass']);
}

public function test_eine_erinnerung_geht_nur_einmal_je_einbuchung(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

    $this->laufe('2026-10-01');
    $this->laufe('2026-10-08');
    $this->laufe('2026-10-09');

    $erinnerungen = array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung');
    $this->assertCount(1, $erinnerungen);
}

public function test_eine_fehlende_arbeitserlaubnis_oeffnet_einen_hr_fall(): void
{
    $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    $this->laufe('2026-10-01');

    $fall = RecHrDeskCase::query()->where('rec_employee_id', $ma->id)->first();
    $this->assertNotNull($fall);
    $this->assertSame(RecHrDeskCase::REASON_WORK_PERMIT, $fall->reason);
}

public function test_ein_zweiter_lauf_oeffnet_keinen_zweiten_hr_fall(): void
{
    $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    $this->laufe('2026-10-01');
    $this->laufe('2026-10-02');

    $this->assertSame(1, RecHrDeskCase::query()->where('rec_employee_id', $ma->id)->count());
}

public function test_dry_run_schreibt_nichts_und_verschickt_nichts(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    $this->laufe('2026-10-01', ['--dry-run' => true]);

    $this->assertSame([], $this->sender->versandt);
    $this->assertNull($this->person($ma)->aufgaben_signatur);
    $this->assertSame(0, RecHrDeskCase::query()->count());
}

public function test_ein_stolpernder_datensatz_beendet_den_lauf_nicht(): void
{
    $kaputt = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($kaputt, ['datum' => 'kein-datum', 'status_id' => 1]);

    $gut = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($gut, ['datum' => '2026-10-20', 'status_id' => 1]);

    $ergebnis = $this->laufe('2026-10-01');

    // Der gute Datensatz wurde bedient, der Lauf meldet den Fehlschlag.
    $this->assertCount(1, $this->sender->versandt);
    $this->assertSame(Command::FAILURE, $ergebnis);
}

public function test_die_pruefung_setzt_keinen_zas_marker(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    // Vorflug: der Beobachter ist registriert und wuerde anspringen.
    $this->assertTrue($this->beobachterIstScharf());

    DB::table('rec_employees')->where('id', $ma->id)->update(['updated_at' => self::ANGEFASST]);

    $this->laufe('2026-10-01');

    $zeile = DB::table('rec_employees')->where('id', $ma->id)->first();

    // Diese Zeile traegt: ein Eloquent-Schreibweg wuerde updated_at mitziehen.
    $this->assertSame(self::ANGEFASST, (string) $zeile->updated_at);
    // Nachrangig: phone und die Pruefspalten stehen bewusst NICHT in
    // RELEVANT_EMPLOYEE_FIELDS, der Marker allein bewiese also nichts.
    $this->assertNull($zeile->zas_changed_at);
}

public function test_die_ausgabe_nennt_keine_namen(): void
{
    $ma = $this->mitarbeiterOhneNachweise(['first_name' => 'Hannelore', 'last_name' => 'Kowalski']);
    $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

    $this->laufe('2026-10-01');

    $this->assertStringNotContainsString('Hannelore', $this->ausgabe);
    $this->assertStringNotContainsString('Kowalski', $this->ausgabe);
}
```

**`test_die_pruefung_setzt_keinen_zas_marker` ist der wichtigste und in diesem Zweig
schon dreimal stumm gruen geblieben.** Tragend ist die `updated_at`-Zusicherung, nicht
die auf `zas_changed_at` — und der Vorflug muss belegen, dass der Beobachter ueberhaupt
anspringen koennte, sonst prueft der Test eine Umgebung, die den Unterschied gar nicht
herstellen kann.

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EinsatzPruefung`

- [ ] **Step 3: Kommando schreiben, im ServiceProvider registrieren**

```php
protected $signature = 'recruiting:einsatz-pruefung
    {--team= : Nur dieses Team}
    {--ids= : Bestimmte Mitarbeiter-Kennungen, komma-getrennt}
    {--welle= : Hoechstens so viele Menschen auf einmal anschreiben}
    {--dry-run : Nur zeigen, was passieren wuerde}';
```

**Ohne `--welle` geht nichts an alle.** Dieselbe Bremse wie bei
`recruiting:konto-einladen`, und aus demselben Grund: ein Kommando, das WhatsApp
verschickt und keine Obergrenze kennt, ist eine Falle. Die Pruefung selbst laeuft
vollstaendig — gedeckelt wird nur der **Versand**, damit `--dry-run` und die HR-Liste
trotzdem die ganze Wahrheit zeigen.

**Wie viele es wirklich sind, weiss niemand vorher.** Getroffen wird nicht der Vorrat
(laut Vorflug haben 1321 von 1603 keinen Ausweis), sondern nur, wer gerade einen festen
Auftrag mit genug Vorlauf hat. Diese Zahl haengt daran, wie weit im Voraus die Dispo
bucht — deshalb steht der Probelauf in der Abnahme **vor** dem ersten scharfen Lauf.

Dazu zwei weitere Tests:

```php
public function test_ohne_welle_wird_niemand_angeschrieben(): void
{
    $this->mehrereMitAuftrag(3);

    $this->laufe('2026-10-01');            // ohne --welle

    $this->assertSame([], $this->sender->versandt);
    // Die Pruefung lief trotzdem: die Ausgabe nennt die drei Faelle.
    $this->assertStringContainsString('3', $this->ausgabe);
}

public function test_die_welle_haelt_ihre_grenze(): void
{
    $this->mehrereMitAuftrag(5);

    $this->laufe('2026-10-01', ['--welle' => 2]);

    $this->assertCount(2, $this->sender->versandt);
}
```

Im Zeitplan **nach** dem Dispo-Import, stuendlich:

```php
Schedule::command('recruiting:einsatz-pruefung')
    ->hourly()
    ->withoutOverlapping();
```

- [ ] **Step 4: Lauf zum Gruensehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alles gruen

- [ ] **Step 5: Mutationsprobe**

Je Regel eine, mindestens: Vorlaufpruefung weg · Signaturpruefung weg · Erinnerungs-Merker nicht gesetzt · HR-Fall-Dublettenpruefung weg · `--dry-run` schreibt doch · Eloquent statt Query Builder (→ `updated_at` springt).

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/EinsatzPruefung.php src/RecruitingServiceProvider.php tests/Integration/EinsatzPruefungTest.php
git commit -m "feat(recruiting): der Ausloeser — ein gebuchter Einsatz prueft, was fehlt"
```

---

### Task 11: Das Portal zeigt die Aufgaben mit Bezug

**Files:**
- Modify: `src/Livewire/Public/PortalShell.php`, `resources/views/livewire/public/portal-shell.blade.php`
- Test: `tests/Integration/PortalAufgabenBladeTest.php`

**Interfaces:**
- Consumes: `OffenePunkte::fuer()` (Task 8)

**Bindende Vorgaben:**
- Alles, was ueber Identitaet oder Zustand entscheidet, ist `#[Locked]` — der Waechter ist eine **geschlossene Welt** und verlangt zu jeder neuen oeffentlichen Eigenschaft eine Entscheidung.
- Blade-Fallen: **niemals `@if` inline in einem `x-ui-*`-Attribut**, kein `@php($x)` in Kurzform vor einem spaeteren `@php`-Block, keine Direktive an einem Wortzeichen (`da@if` kompiliert **nicht** und laesst stumm den falschen Zweig rendern).
- Die Seite **duzt** ab dem Portal (sie kennt die Anstellung und damit die Team-Einstellung).

- [ ] **Step 1: Test schreiben, rot sehen**

```php
public function test_die_huelle_nennt_den_einsatz_zum_offenen_punkt(): void
{
    $ma = $this->mitarbeiterOhneNachweise();
    $this->einbuchung($ma, ['datum' => '2026-10-12', 'status_id' => 1, 'taetigkeit' => 'Service']);

    $markup = $this->rendereHuelle($ma, '2026-10-01');

    $this->assertStringContainsString('Ausweis', $markup);
    // Der Bezug macht aus einer Liste eine Aufforderung.
    $this->assertStringContainsString('12.10.', $markup);
}

public function test_ein_ko_punkt_ist_als_solcher_erkennbar(): void
{
    $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);

    $markup = $this->rendereHuelle($ma, '2026-10-01');

    // Ein Arbeitsverbot darf nicht aussehen wie ein fehlendes Passfoto.
    $this->assertStringContainsString('Aufenthaltstitel', $markup);
    $this->assertStringContainsString('aufgabe-ko', $markup);
}

public function test_ohne_offene_punkte_steht_kein_kasten_da(): void
{
    $ma = $this->mitarbeiterMitAllenNachweisen();

    $markup = $this->rendereHuelle($ma, '2026-10-01');

    // Wer alles hat, soll nicht jeden Tag einen leeren Kasten sehen.
    $this->assertStringNotContainsString('aufgaben-kasten', $markup);
}

public function test_der_waechter_kennt_jede_neue_eigenschaft(): void
{
    // Die geschlossene Welt aus dem Konto-Zweig: jede oeffentliche
    // Eigenschaft steht in genau einer der beiden Listen.
    $this->assertWeltIstGeschlossen(PortalShell::class);
}
```

- [ ] **Step 2: Lauf zum Rotsehen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalAufgaben`
Expected: FAIL — die Aufgaben stehen noch nicht in der Huelle

- [ ] **Step 3: Komponente und Blade schreiben**

**Der Weg ist `ansichtsDaten()`, nicht eine Methode am Blade.** `PortalShell::render()`
reicht der Ansicht bereits ein Feld herein:

```php
return view('recruiting::livewire.public.portal-shell', $this->ansichtsDaten())
```

Dort kommt `'aufgaben' => (new OffenePunkte())->fuer($employee)` dazu.

**Keine neue oeffentliche Eigenschaft.** Zwei Gruende: der `#[Locked]`-Waechter ist eine
geschlossene Welt und verlangt zu jeder eine Entscheidung — und ein Zustand, der ueber
Identitaet Auskunft gibt, gehoert nicht in den Livewire-Schnappschuss (Befund aus
Aufgabe 9 des Konto-Zweigs: `#[Locked]` verhindert das Setzen, **nicht das Ausliefern**).
Eine **nicht oeffentliche** Methode waere ebenfalls falsch: das Blade koennte sie gar
nicht rufen.

Im Blade, in Blockform und mit vorberechneten Werten:

```blade
@if ($aufgaben['punkte'] !== [])
    <div class="aufgaben-kasten">
        @if ($aufgaben['einsatz'] !== null)
            @php
                $bezug = 'Fuer deinen Einsatz am '
                    . \Illuminate\Support\Carbon::parse($aufgaben['einsatz']['datum'])->format('d.m.')
                    . ($aufgaben['einsatz']['event'] ? ' — ' . $aufgaben['einsatz']['event'] : '');
            @endphp
            <p class="aufgaben-bezug">{{ $bezug }}</p>
        @endif

        @foreach ($aufgaben['punkte'] as $punkt)
            @php
                $klasse = $punkt['ko'] ? 'aufgabe aufgabe-ko' : 'aufgabe';
            @endphp
            <div class="{{ $klasse }}">{{ $punkt['label'] }}</div>
        @endforeach
    </div>
@endif
```

- [ ] **Step 4: `php tools/blade-check.php`, Gesamtlauf**

Run: `php tools/blade-check.php` und `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`

- [ ] **Step 5: Mutationsprobe**

Die `ko`-Klasse immer setzen → `test_ein_ko_punkt_ist_als_solcher_erkennbar` darf nicht
mehr unterscheiden, also muss ein Gegentest mit einem normalen Punkt rot werden; diesen
mitschreiben. Den `@if` auf die leere Liste entfernen → der dritte Test muss rot werden.

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(recruiting): das Portal sagt, was fehlt und fuer welchen Einsatz"
```

---

## Abnahme

1. Gesamtlauf gruen, Zahl deutlich ueber 3138.
2. `php artisan migrate` legt die drei Trigger-Spalten und `rec_hr_desk_cases.rec_employee_id` an.
3. Auf der Demo: eine Einbuchung mit `status_id = 1` und Datum in zehn Tagen anlegen, `recruiting:einsatz-pruefung --dry-run` fahren — der Mensch muss mit seinen offenen Punkten erscheinen, ohne dass etwas geschrieben wird.
3a. **Den Probelauf ueber den ganzen Bestand fahren und die Zahl notieren.** `recruiting:einsatz-pruefung --dry-run` ohne Einschraenkung zeigt, wie viele Menschen beim ersten scharfen Lauf angeschrieben wuerden. **Erst zaehlen, dann entscheiden, ob es eine Welle braucht, dann senden** — nicht umgekehrt.
4. Denselben Lauf scharf fahren, dann noch einmal: **beim zweiten Mal passiert nichts** (gleiche Signatur).
5. Im Portal erscheint die Aufgabenliste mit dem Einsatz als Bezug.
6. Ein Mensch ohne gueltige Arbeitserlaubnis erzeugt einen HR-Fall — und **nur einen**, auch bei mehrfachem Lauf.

## Was dieser Plan NICHT tut

- **Keine Vertragspruefung** (Markus' B2) — es gibt keine Regel „braucht dieser Mensch einen Vertrag", und Vertraege haengen am Bewerber.
- **Keine Stammdaten-Bestaetigung** — es gibt keinen Marker dafuer.
- **Kein Zahlungsstatus** (B5), **keine Agenda** (C4), **kein SEPA** (C5).
- **Keine Erweiterung des ZAS-Exports.** Die harte Sperre bleibt bis dahin laut, aber nicht bindend — wir koennen niemanden aus der Disposition nehmen. Das ist ein eigenes Thema mit Michel und Olaf, und neue Spalten werden **vorher angekuendigt**.
