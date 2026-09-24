# Nachweis-Selbstbedienung (Schritt 3) — Implementierungsplan

> **Fuer agentische Arbeiter:** PFLICHT-SUB-SKILL: `superpowers:subagent-driven-development`
> (empfohlen) oder `superpowers:executing-plans`. Schritte sind Checkboxen.

**Goal:** Mitarbeiter laden ihre Nachweise im Portal selbst hoch, das System
erinnert vor Ablauf genau einmal, HR sieht was eingeht und bestaetigt nur dort,
wo eine Einsatzsperre dranhaengt.

**Architecture:** Auf der Datenbasis aus Schritt 1 (`rec_employee_proofs`,
`ProofTypes`, `ProofWriter`, `ProofReader`) und der Huelle aus Schritt 2
(`PortalShell`). Neu sind vier Dinge: eine Upload-Strecke in der Huelle, ein
reiner Planer fuer den Fristenlauf plus Kommando, der Portal-Link in der
Erinnerung, und zwei HR-Ansichten. Regeln liegen in pruefbaren, reinen Klassen;
Livewire-Komponenten und Kommandos sind nur Huellen darum.

**Tech Stack:** Laravel 11 / Livewire 3 · `ContextFileService` aus platforms-core
fuer die Ablage · `WhatsAppMetaService` aus platforms-crm fuer den Versand ·
PHPUnit (Unit pur, Integration mit Capsule+SQLite)

**Spec:** `docs/superpowers/specs/2026-09-22-mitarbeiterportal-nachweise-design.md`

## Global Constraints

- **Kein Edit ausserhalb `platforms-recruiting`.** Core/CRM/HCM werden nur
  benutzt, nie geaendert. Ohne ausdrueckliche Erlaubnis kein Ausnahmefall.
- **Testlauf:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` — das
  Modul hat kein eigenes `vendor/`.
- **Blade nie mit `php -l` pruefen**, sondern `php tools/blade-check.php <datei>`.
  Direktiven immer in Blockform, nie an Wortzeichen geklebt, keine
  `@php`/`@verbatim`-Woerter in Blade-Kommentaren (eigener Waechter-Test).
- **Massenschreibvorgaenge ueber den Query Builder**, nie ueber Eloquent:
  `RecEmployeeExportObserver` stempelt sonst `zas_changed_at` und die Zeilen
  landen in der naechsten `updates.csv` fuer ZAS (Vorfall 02.09.2026).
- **Keine neuen ZAS-Exportspalten.** Fiktionsbescheinigung, Visumsblatt und
  Nationalpass haben bewusst keine Exportspalte.
- **Anrede:** jeder sichtbare Text als `$duzen`-Ternary, auch Ueberschriften.
- **Sicherheit:** In Livewire-Komponenten ist alles `#[Locked]`, was ueber
  Identitaet oder Zustand entscheidet. Jede Aktion prueft erneut: aktiv,
  nicht gesperrt, `portal_v2_since` gesetzt.
- **Neue oeffentliche Routen** muessen in
  `tests/Integration/TrainingCertificatePublicRouteTest.php::SPAETER` eingetragen
  werden, sonst schlaegt der Bestandswaechter fehl.

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `src/Support/ProofUploadRules.php` | rein: welche Dateien, wie gross, welches Datum ist plausibel |
| `src/Support/ProofReminderPlanner.php` | rein: wer bekommt heute eine Erinnerung |
| `src/Services/ProofReminderSender.php` | eine Erinnerung verschicken, Ergebnis melden |
| `src/Console/Commands/SendProofReminders.php` | Huelle um Planer + Sender |
| `src/Livewire/Public/PortalShell.php` | Upload-Strecke (erweitert) |
| `src/Livewire/Employees/ProofInbox.php` | HR: was ist neu eingegangen |
| `resources/views/livewire/...` | Ansichten |

---

### Task 1: `ProofUploadRules` — die Regeln des Hochladens

**Files:**
- Create: `src/Support/ProofUploadRules.php`
- Test: `tests/Unit/ProofUploadRulesTest.php`

**Interfaces:**
- Consumes: `ProofTypes::exists()`, `ProofTypes::hasExpiry()`
- Produces:
  - `ProofUploadRules::MIME_TYPES: list<string>`
  - `ProofUploadRules::MAX_KB: int`
  - `ProofUploadRules::pruefeDatum(string $code, ?string $datum, string $heute): ?string`
    — Klartext-Grund oder `null` wenn in Ordnung

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_art_mit_ablauf_verlangt_ein_datum(): void
{
    $this->assertSame(
        'Bitte trag ein, bis wann der Nachweis gültig ist.',
        ProofUploadRules::pruefeDatum('ausweis', null, '2026-09-24'),
    );
}

public function test_datum_in_der_vergangenheit_wird_abgewiesen(): void
{
    // Sonst legt jemand einen abgelaufenen Ausweis ab und die Aufgabe
    // verschwindet, obwohl sich nichts gebessert hat.
    $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2026-09-23', '2026-09-24'));
}

public function test_heute_ist_noch_gueltig(): void
{
    $this->assertNull(ProofUploadRules::pruefeDatum('ausweis', '2026-09-24', '2026-09-24'));
}

public function test_mehr_als_zehn_jahre_wird_abgewiesen(): void
{
    $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2040-01-01', '2026-09-24'));
}

public function test_art_ohne_ablauf_nimmt_kein_datum(): void
{
    $this->assertNotNull(ProofUploadRules::pruefeDatum('selfie', '2030-01-01', '2026-09-24'));
    $this->assertNull(ProofUploadRules::pruefeDatum('selfie', null, '2026-09-24'));
}

public function test_unbekannte_art_wird_abgewiesen(): void
{
    $this->assertNotNull(ProofUploadRules::pruefeDatum('gibtesnicht', null, '2026-09-24'));
}

public function test_unsinniges_datum_wird_abgewiesen(): void
{
    // '0000-00-00' hat in Schritt 1 schon einmal eine Falle gestellt.
    $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '0000-00-00', '2026-09-24'));
    $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', 'morgen', '2026-09-24'));
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ProofUploadRulesTest`
Erwartet: FEHLER, Klasse nicht gefunden.

- [ ] **Schritt 3: Die Klasse schreiben**

```php
final class ProofUploadRules
{
    /** Handyfotos und PDF. Keine Office-Formate — die kann niemand ansehen. */
    public const MIME_TYPES = ['jpg', 'jpeg', 'png', 'heic', 'pdf'];
    public const MAX_KB = 12288;   // 12 MB, ein iPhone-Foto liegt bei 3-5
    public const MAX_JAHRE = 10;

    public static function pruefeDatum(string $code, ?string $datum, string $heute): ?string
    {
        if (!ProofTypes::exists($code)) {
            return 'Diese Nachweisart kennen wir nicht.';
        }

        $hatAblauf = ProofTypes::hasExpiry($code);
        $roh = trim((string) $datum);

        if (!$hatAblauf) {
            return $roh === '' ? null : 'Diese Unterlage hat kein Ablaufdatum.';
        }
        if ($roh === '') {
            return 'Bitte trag ein, bis wann der Nachweis gültig ist.';
        }
        if (!self::istEchtesDatum($roh)) {
            return 'Das Datum können wir nicht lesen.';
        }
        if ($roh < $heute) {
            return 'Dieses Datum liegt in der Vergangenheit.';
        }
        if ($roh > date('Y-m-d', strtotime($heute . ' +' . self::MAX_JAHRE . ' years'))) {
            return 'Das Datum liegt mehr als ' . self::MAX_JAHRE . ' Jahre in der Zukunft — bitte prüfen.';
        }

        return null;
    }

    private static function istEchtesDatum(string $wert): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m)) {
            return false;
        }

        return (int) $m[1] >= 1900 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ProofUploadRulesTest`
Erwartet: 7 gruen.

- [ ] **Schritt 5: Festschreiben**

```bash
git add src/Support/ProofUploadRules.php tests/Unit/ProofUploadRulesTest.php
git commit -m "feat(recruiting): Regeln fuers Hochladen von Nachweisen"
```

---

### Task 2: Upload-Strecke im Portal

**Files:**
- Modify: `src/Livewire/Public/PortalShell.php`
- Modify: `resources/views/livewire/public/portal-shell.blade.php`
- Modify: `resources/views/layouts/portal.blade.php` (Stile fuer die Upload-Kachel)
- Test: `tests/Integration/PortalShellUploadTest.php`

**Interfaces:**
- Consumes: `ProofUploadRules::pruefeDatum()`, `ProofWriter::store()`, `ProofReader::checklist()`
- Produces: `PortalShell::oeffneUpload(string $code)`, `PortalShell::speichereNachweis()`,
  oeffentliche Eigenschaften `$uploadCode`, `$uploadGueltigBis`, `$uploadDatei`, `$uploadFehler`

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_upload_legt_den_nachweis_an_und_raeumt_die_aufgabe_weg(): void
{
    $ma = $this->angemeldeterMitarbeiter();
    $shell = $this->shell($ma);

    $shell->oeffneUpload('ausweis');
    $shell->uploadGueltigBis = '2032-01-31';
    $shell->uploadDatei = UploadedFile::fake()->image('ausweis.jpg');
    $shell->speichereNachweis();

    $this->assertSame('', $shell->uploadFehler);
    $nachweis = RecEmployeeProof::where('rec_employee_id', $ma->id)->aktuell()->first();
    $this->assertSame('ausweis', $nachweis->proof_type_code);
    $this->assertSame('2032-01-31', $nachweis->valid_until->toDateString());
}

public function test_falsches_datum_legt_nichts_an(): void
{
    $ma = $this->angemeldeterMitarbeiter();
    $shell = $this->shell($ma);

    $shell->oeffneUpload('ausweis');
    $shell->uploadGueltigBis = '2020-01-01';
    $shell->uploadDatei = UploadedFile::fake()->image('alt.jpg');
    $shell->speichereNachweis();

    $this->assertNotSame('', $shell->uploadFehler);
    $this->assertSame(0, RecEmployeeProof::count());
}

public function test_ohne_anmeldung_wird_nichts_gespeichert(): void
{
    // Der gefaehrlichste Fall: $wire.call('speichereNachweis') ohne Anmeldung.
    $ma = $this->mitarbeiter();
    $shell = $this->shell($ma);
    $shell->state = 'unverified';
    $shell->uploadCode = 'ausweis';
    $shell->uploadGueltigBis = '2032-01-31';
    $shell->uploadDatei = UploadedFile::fake()->image('x.jpg');

    $shell->speichereNachweis();

    $this->assertSame(0, RecEmployeeProof::count());
}

public function test_upload_setzt_keinen_export_marker(): void
{
    $ma = $this->angemeldeterMitarbeiter();
    $gefeuert = false;
    RecEmployee::updated(function () use (&$gefeuert) { $gefeuert = true; });

    $shell = $this->shell($ma);
    $shell->oeffneUpload('ausweis');
    $shell->uploadGueltigBis = '2032-01-31';
    $shell->uploadDatei = UploadedFile::fake()->image('a.jpg');
    $shell->speichereNachweis();

    $this->assertFalse($gefeuert, 'Das Hochladen darf keinen ZAS-Export ausloesen.');
    $this->assertNull(DB::table('rec_employees')->find($ma->id)->zas_changed_at);
}

public function test_unbekannte_art_wird_abgewiesen(): void
{
    $ma = $this->angemeldeterMitarbeiter();
    $shell = $this->shell($ma);
    $shell->uploadCode = 'gibtesnicht';   // an oeffneUpload() vorbei gesetzt
    $shell->uploadDatei = UploadedFile::fake()->image('x.jpg');

    $shell->speichereNachweis();

    $this->assertSame(0, RecEmployeeProof::count());
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellUploadTest`
Erwartet: FEHLER, `oeffneUpload` gibt es nicht.

- [ ] **Schritt 3: Die Strecke bauen**

In `PortalShell`: `use Livewire\WithFileUploads;` und

```php
    /** Nicht gesperrt — der Mensch waehlt sie ja aus. Geprueft wird beim Speichern. */
    public ?string $uploadCode = null;
    public string $uploadGueltigBis = '';
    public $uploadDatei = null;
    public string $uploadFehler = '';

    public function oeffneUpload(string $code): void
    {
        $this->uploadCode = ProofTypes::exists($code) ? $code : null;
        $this->uploadGueltigBis = '';
        $this->uploadDatei = null;
        $this->uploadFehler = '';
    }

    public function speichereNachweis(): void
    {
        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null || $this->uploadCode === null) {
            return;
        }

        $fehler = ProofUploadRules::pruefeDatum(
            $this->uploadCode,
            $this->uploadGueltigBis,
            now()->toDateString(),
        );
        if ($fehler !== null) {
            $this->uploadFehler = $fehler;

            return;
        }

        $this->validate([
            'uploadDatei' => 'required|file|mimes:' . implode(',', ProofUploadRules::MIME_TYPES)
                . '|max:' . ProofUploadRules::MAX_KB,
        ]);

        try {
            $ergebnis = app(ContextFileService::class)->uploadForContext(
                $this->uploadDatei, 'rec_employee', $employee->id,
                ['team_id' => $employee->team_id, 'user_id' => null],
            );
        } catch (\Throwable $e) {
            $this->uploadFehler = 'Das Hochladen hat nicht geklappt. Bitte versuch es noch einmal.';
            report($e);

            return;
        }

        app(ProofWriter::class)->store($employee, $this->uploadCode, [
            'file_id'     => (int) $ergebnis['id'],
            'valid_until' => ProofTypes::hasExpiry($this->uploadCode) ? $this->uploadGueltigBis : null,
            'uploaded_via' => 'employee',
        ]);

        $this->uploadCode = null;
        $this->uploadDatei = null;
        $this->uploadGueltigBis = '';
        $this->uploadFehler = '';
    }
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellUploadTest`
Erwartet: 5 gruen.

- [ ] **Schritt 5: Die Ansicht — Aufgaben werden anklickbar**

Jede `.task`-Zeile mit `wire:click="oeffneUpload('{{ $aufgabe['code'] }}')"`, darunter
ein Feld fuer das Datum und eines fuer die Datei. `capture="environment"` am
Datei-Feld, damit das Handy die Kamera anbietet. Datum als
`x-ui`-freies `<input type="date">` — das Portal benutzt keine `x-ui`-Bausteine.
**`$aufgabe['code']` muss dafuer in `PortalShell::dekoriert()` mitgereicht werden**
(steht heute nicht drin).

Pruefen mit: `php tools/blade-check.php resources/views/livewire/public/portal-shell.blade.php`

- [ ] **Schritt 6: Gesamtlauf und festschreiben**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
git add -A && git commit -m "feat(recruiting): Nachweise im Portal selbst hochladen"
```

---

### Task 3: `ProofReminderPlanner` — wer bekommt heute eine Erinnerung

**Files:**
- Create: `src/Support/ProofReminderPlanner.php`
- Test: `tests/Unit/ProofReminderPlannerTest.php`

**Interfaces:**
- Consumes: `ProofTypes::leadDays()`
- Produces: `ProofReminderPlanner::plan(array $nachweise, string $heute, ?string $stichtag): list<array{proof_id:int, rec_employee_id:int, code:string, valid_until:string}>`

**Der Stichtag ist der Kern dieser Aufgabe.** Im Bestand sind rund 540 Nachweise
bereits abgelaufen, dazu 283, die in 60 Tagen fallen. Ohne Bremse gingen am Tag
der Freischaltung ueber 500 WhatsApps raus. Erinnert wird nur an Fristen, die
**nach** dem Stichtag liegen; der Altbestand steht sichtbar im Portal, ungefragt.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
private function nachweis(array $a = []): array
{
    return array_merge([
        'id' => 1, 'rec_employee_id' => 10, 'proof_type_code' => 'ausweis',
        'valid_until' => '2026-10-10', 'reminded_at' => null, 'superseded_at' => null,
    ], $a);
}

public function test_erinnert_innerhalb_der_vorlaufzeit(): void
{
    // ausweis: 30 Tage Vorlauf
    $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2026-10-10'])], '2026-09-24', null);
    $this->assertCount(1, $plan);
}

public function test_erinnert_nicht_zu_frueh(): void
{
    $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2027-05-01'])], '2026-09-24', null);
    $this->assertSame([], $plan);
}

public function test_erinnert_nur_einmal(): void
{
    $plan = ProofReminderPlanner::plan([$this->nachweis(['reminded_at' => '2026-09-20 08:00:00'])], '2026-09-24', null);
    $this->assertSame([], $plan);
}

public function test_abgeloeste_fassung_zaehlt_nicht(): void
{
    $plan = ProofReminderPlanner::plan([$this->nachweis(['superseded_at' => '2026-09-01 10:00:00'])], '2026-09-24', null);
    $this->assertSame([], $plan);
}

public function test_stichtag_haelt_den_altbestand_zurueck(): void
{
    // Das ist die Bremse gegen die 500-WhatsApp-Welle.
    $plan = ProofReminderPlanner::plan([
        $this->nachweis(['id' => 1, 'valid_until' => '2026-08-01']),   // vor dem Stichtag
        $this->nachweis(['id' => 2, 'valid_until' => '2026-10-10']),   // danach
    ], '2026-09-24', '2026-10-01');

    $this->assertSame([2], array_column($plan, 'proof_id'));
}

public function test_ohne_stichtag_kommt_auch_der_altbestand(): void
{
    $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2026-08-01'])], '2026-09-24', null);
    $this->assertCount(1, $plan);
}

public function test_arten_ohne_ablauf_kommen_nie(): void
{
    $plan = ProofReminderPlanner::plan([$this->nachweis(['proof_type_code' => 'selfie', 'valid_until' => null])], '2026-09-24', null);
    $this->assertSame([], $plan);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ProofReminderPlannerTest`

- [ ] **Schritt 3: Den Planer schreiben**

```php
final class ProofReminderPlanner
{
    /**
     * @param  list<array{id:int, rec_employee_id:int, proof_type_code:string,
     *                    valid_until:?string, reminded_at:?string, superseded_at:?string}> $nachweise
     * @param  string      $heute    Y-m-d
     * @param  string|null $stichtag Y-m-d — Fristen VOR diesem Tag bleiben stumm.
     *                               Das ist die Bremse gegen die Altbestands-Welle.
     * @return list<array{proof_id:int, rec_employee_id:int, code:string, valid_until:string}>
     */
    public static function plan(array $nachweise, string $heute, ?string $stichtag): array
    {
        $faellig = [];
        foreach ($nachweise as $n) {
            $bis = trim((string) ($n['valid_until'] ?? ''));
            $code = (string) ($n['proof_type_code'] ?? '');

            if ($bis === '' || !ProofTypes::exists($code) || !ProofTypes::hasExpiry($code)) {
                continue;   // ohne Frist gibt es nichts zu erinnern
            }
            if (($n['superseded_at'] ?? null) !== null) {
                continue;   // abgeloeste Fassung — der Nachfolger zaehlt
            }
            if (($n['reminded_at'] ?? null) !== null) {
                continue;   // genau eine Erinnerung, danach steht es im Portal
            }
            if ($stichtag !== null && $bis < $stichtag) {
                continue;   // Altbestand: sichtbar im Portal, aber ungefragt
            }

            $vorlauf = ProofTypes::leadDays($code);
            $fenster = date('Y-m-d', strtotime($heute . ' +' . $vorlauf . ' days'));
            if ($bis > $fenster) {
                continue;   // noch zu frueh
            }

            $faellig[] = [
                'proof_id'        => (int) $n['id'],
                'rec_employee_id' => (int) $n['rec_employee_id'],
                'code'            => $code,
                'valid_until'     => $bis,
            ];
        }

        // Das Dringendste zuerst — wenn --limit greift, soll es das Richtige treffen.
        usort($faellig, fn ($a, $b) => [$a['valid_until'], $a['proof_id']]
                                   <=> [$b['valid_until'], $b['proof_id']]);

        return $faellig;
    }
}
```

Beachte: Ein bereits **abgelaufener** Nachweis (`valid_until < $heute`) faellt
ebenfalls ins Fenster und wird erinnert — das ist gewollt. Der Stichtag ist die
Bremse dafuer, nicht eine Abfrage auf „noch gueltig".

- [ ] **Schritt 4: Test laufen lassen, gruen sehen**

Erwartet: 7 gruen.

- [ ] **Schritt 5: Festschreiben**

```bash
git add src/Support/ProofReminderPlanner.php tests/Unit/ProofReminderPlannerTest.php
git commit -m "feat(recruiting): Planer fuer den Fristenlauf, mit Stichtag gegen die Altbestands-Welle"
```

---

### Task 4: Fristenlauf — Sender und Kommando

**Files:**
- Create: `src/Services/ProofReminderSender.php`
- Create: `src/Console/Commands/SendProofReminders.php`
- Modify: `src/RecruitingServiceProvider.php` (Kommando eintragen, Zeitplan)
- Test: `tests/Integration/SendProofRemindersTest.php`

**Interfaces:**
- Consumes: `ProofReminderPlanner::plan()`, `RecApplicantSettings::getSetting()`, `WhatsAppMetaService::sendTemplate()`
- Produces: `ProofReminderSender::send(RecEmployee $ma, array $eintrag): array{status:string, error:?string}`

**Aufruf:** `php artisan recruiting:nachweise-erinnern {--team=} {--stichtag=} {--dry-run} {--limit=} {--auch-altes-portal}`

**Zwei Dinge, die im Bestand schiefgegangen sind und hier nicht wieder passieren:**

1. `RecEmployee::sendPortalNotification()` stempelt einen von Meta abgelehnten
   Versand als Erfolg. Der Sender hier setzt `reminded_at` **nur nach
   erfolgreichem** `sendTemplate()` — sonst gilt jemand als erinnert, der nie
   etwas bekommen hat.
2. `sendManualTemplate` hat einen Formular-Token in jeden URL-Knopf gehaengt
   (Fall Theo Wirtz). Der Knopf hier bekommt **ausschliesslich** den
   Portal-Token, und ein Test haelt das fest.

**Welches Portal der Knopf oeffnet** (in der Selbstpruefung aufgefallen, stand
vorher nirgends): Seit Schritt 2 gibt es zwei. Die Erinnerung muss dorthin
fuehren, wo der Mensch die Aufgabe auch erledigen kann — und das ist nur das
neue Portal. Also:

```php
$ziel = $employee->portal_v2_since !== null
    ? route('recruiting.public.portal-shell', ['token' => $employee->portal_token])
    : route('recruiting.public.employee-portal', ['token' => $employee->portal_token]);
```

Solange jemand noch auf dem alten Portal ist, kann er den Nachweis nicht
hochladen. **Folge fuer den Betrieb: der Fristenlauf geht erst scharf, wenn die
Zielgruppe umgestellt ist** — sonst erinnern wir an etwas, das die Leute nicht
erledigen koennen. Der Lauf bekommt dafuer `--nur-neues-portal` (Vorgabe: an),
das alle ohne `portal_v2_since` ueberspringt.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_trockenlauf_schreibt_nichts(): void
{
    // ... Nachweis mit Frist in 10 Tagen anlegen
    $this->artisan('recruiting:nachweise-erinnern', ['--dry-run' => true])->assertSuccessful();
    $this->assertNull(DB::table('rec_employee_proofs')->find(1)->reminded_at);
}

public function test_gescheiterter_versand_setzt_keinen_marker(): void
{
    // Der teuerste Fehler: als erinnert gelten, ohne erinnert worden zu sein.
    // Sender-Attrappe wirft.
    $this->assertNull(DB::table('rec_employee_proofs')->find(1)->reminded_at);
}

public function test_erfolgreicher_versand_setzt_den_marker_genau_einmal(): void
{
    // zweimal laufen lassen, nur eine Nachricht
}

public function test_der_knopf_traegt_nur_den_portal_token(): void
{
    // Kein Formular-Token, kein Fremd-Parameter (Fall Theo Wirtz).
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**
- [ ] **Schritt 3: Sender und Kommando schreiben** — `reminded_at` per Query
      Builder setzen (Modell-Ereignisse vermeiden), Ergebnis je Person zaehlen
- [ ] **Schritt 4: Test laufen lassen, gruen sehen**
- [ ] **Schritt 5: Festschreiben**

---

### Task 5: HR-Sicht — was ist eingegangen, was ist offen

**Files:**
- Create: `src/Livewire/Employees/ProofInbox.php`
- Create: `resources/views/livewire/employees/proof-inbox.blade.php`
- Modify: `src/Livewire/Employees/Show.php` (Nachweis-Block je Person)
- Modify: `routes/web.php`
- Test: `tests/Integration/ProofInboxTest.php`

**Interfaces:**
- Consumes: `ProofReader::checklist()`, `RecEmployeeProof::aktuell()`
- Produces: `ProofInbox::bestaetige(int $proofId)` — setzt `confirmed_by_user_id` + `confirmed_at`

**Die Bestaetigungspflicht gilt nur fuer zwei Arten:** Aufenthaltstitel und
Arbeitsgenehmigung. An ihnen haengt die harte Einsatzsperre, deshalb schaut dort
ein Mensch auf das Datum. Alles andere gilt sofort als erledigt — vom Kunden am
22.09. ausdruecklich so bestaetigt: HR prueft ausschliesslich Lohnrelevantes.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_liste_zeigt_neu_eingegangene_zuerst(): void { }

public function test_nur_aufenthaltstitel_und_arbeitsgenehmigung_verlangen_bestaetigung(): void
{
    $this->assertTrue(ProofTypes::needsHrConfirmation('aufenthaltstitel'));
    $this->assertTrue(ProofTypes::needsHrConfirmation('arbeitsgenehmigung'));
    $this->assertFalse(ProofTypes::needsHrConfirmation('ausweis'));
    $this->assertFalse(ProofTypes::needsHrConfirmation('selfie'));
}

public function test_bestaetigen_setzt_keinen_export_marker(): void { }
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**
- [ ] **Schritt 3: `ProofTypes::needsHrConfirmation()` ergaenzen, Ansicht bauen**
- [ ] **Schritt 4: Test laufen lassen, gruen sehen**
- [ ] **Schritt 5: Festschreiben**

---

## Danach, nicht von mir abhaengig

- **Meta-Vorlage** fuer die Ablauf-Erinnerung: Text von RHEINGEDECK,
  Genehmigung ein bis drei Werktage. Einreichen, sobald der Text da ist —
  nicht auf den Code warten.
- **Entscheidung Markus:** Stichtag fuer den Altbestand (die rund 540
  abgelaufenen Nachweise). Ohne Entscheidung laeuft das Kommando nur im
  Trockenlauf.
- **Fristenlauf gegen echte Daten** auf demo, bevor er auf prod scharf geht.
