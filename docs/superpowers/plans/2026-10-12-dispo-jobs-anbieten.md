# Dispo „Jobs anbieten“ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Disponent wählt Veranstaltungen, Schichten und eine Zielgruppe, schickt WhatsApp-Wellen mit persönlichem Link; der Mitarbeiter sagt auf einer Seite „Ich bin dabei“; der Disponent sieht die Zusagen, trägt sie in ZAS ein und hakt ab.

**Architecture:** Fünf neue Tabellen (Anfrage, Schicht, Welle, Angebot, Angebot↔Schicht). Reine Rechenklassen (Zähler, Tagesprüfung, Empfängerplaner, Kostenstellen-Blöcke) ohne DB; ein DB-Lader baut die Eingaben; ein Queue-Job je Welle (Muster `SendNoAssignmentCampaign`, Fortschritt im Cache); ein Sender nach dem Muster `DispoInfoSender` (Vorlage mit `{{name}}` + Token-Button). Öffentliche Seite `/jobs/{token}` nach dem Muster `Public\EmployeeAssignments`. Drei Livewire-Seiten unter Disposition → Jobs anbieten.

**Tech Stack:** Laravel 11, Livewire 3, Eloquent, PHPUnit (Unit pur + Integration mit Capsule/SQLite), Tailwind-Klassen wie die bestehenden Dispo-Seiten.

**Spec:** `docs/superpowers/specs/2026-10-12-dispo-jobs-anbieten-design.md`

## Global Constraints

- Kundentexte in Du-Form, ohne technische Begriffe; auf der Mitarbeiter-Seite „Ich bin dabei“, nie „buchen“.
- Nichts wird nach ZAS geschrieben. Eine Zusage ist keine Einbuchung.
- Zeilenregel für „gebucht“/„Einsatz“: `status_id = 1` (Auftrag), `missing_since`, `deletion_marked_at`, `declined_at` alle null.
- Personen-Identität = `DispoIdentityResolver`-Gruppe; Anker = kleinste `rec_employees.id` der Gruppe.
- Mitarbeiterfiliale = Hunderterblock der Kostenstelle: `intdiv((int) kostenstelle, 100) * 100`; eigene `cost_center` vor `position->cost_center`; leer → null („ohne Kostenstelle“).
- Gruppen sind disjunkte Bänder `0`, `1-4`, `5-9`, `10-19`, `20+`; `alle` trifft jeden. Leere Gruppenauswahl = niemand.
- Vorlage: `{{name}}` optional im Text, **dynamischer URL-Button an Position 0**, Suffix = `portal_token`. Wächter wie `CampaignTemplatePreview::warnung`.
- Die Routen `recruiting.dispo.jobs.*` dürfen **nicht** in `RecruitingRouteGate::EVENT_ROUTES`.
- Tests laufen mit `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`.
- Blade-Prüfung mit `php tools/blade-check.php <view>`; Host-App dabei nicht booten.
- Commit-Trailer: `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## Review Focus

1. Eine Person mit RG- und MA-Datensatz, beide aktiv, einer ohne Nummer: genau **eine** Nachricht an den Datensatz mit Nummer; Zählung und Tagesprüfung über beide Datensätze. (Test in Task 4 und 6.)
2. Modus „Wer zuerst kommt“, zwei Zusagen gleichzeitig auf den letzten Platz: nur eine zählt, die zweite sieht „schon voll“. (Test in Task 8, Transaktion + Re-Check.)
3. Welle „in 30 Minuten“ geplant, der Disponent löscht die Anfrage vorher: der Job sendet nichts. (Test in Task 6.)
4. Kostenstelle `"MGL"` (nicht numerisch) oder `"0"`: landet in „ohne Kostenstelle“ bzw. Block 0 mit Label `#0`, kippt nichts. (Test in Task 1.)
5. Vorlage ohne dynamischen URL-Button: Senden gesperrt, kein Job wird gestartet. (Test in Task 9 über `DispoJobVorlagenWaechter`.)

---

### Task 1: Reine Rechenklassen — Kostenstellen-Blöcke, Einsatz-Zähler, Tagesprüfung

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/KostenstellenBloecke.php`
- Create: `src/Services/Zas/Dispo/Jobs/DispoEinsatzZaehler.php`
- Create: `src/Services/Zas/Dispo/Jobs/DispoTagesPruefung.php`
- Test: `tests/Unit/Dispo/Jobs/KostenstellenBloeckeTest.php`
- Test: `tests/Unit/Dispo/Jobs/DispoEinsatzZaehlerTest.php`
- Test: `tests/Unit/Dispo/Jobs/DispoTagesPruefungTest.php`

**Interfaces:**
- Produces: `KostenstellenBloecke::block(?string $eigene, ?string $stelle): ?int`, `KostenstellenBloecke::optionen(iterable $rows): list<array{block:?int, nummern:list<string>, count:int}>`
- Produces: `DispoEinsatzZaehler::proMonat(array $assignments, array $anchorByEmployee, string $monat): array<int,int>`, `::band(int): string`, `::trifft(int $anzahl, array $gruppen): bool`, `::BAENDER`
- Produces: `DispoTagesPruefung::gebuchteTage(array $assignments, array $anchorByEmployee): array<int, array<string,true>>`
- Produces: `DispoTagesPruefung::istEinsatz(array $a): bool` (die gemeinsame Zeilenregel)

- [ ] **Step 1: Failing tests schreiben**

`tests/Unit/Dispo/Jobs/KostenstellenBloeckeTest.php`:
```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\KostenstellenBloecke;

final class KostenstellenBloeckeTest extends TestCase
{
    public function testBlockIstDerHunderter(): void
    {
        $this->assertSame(400, KostenstellenBloecke::block('401', null));
        $this->assertSame(200, KostenstellenBloecke::block('200', null));
        $this->assertSame(100, KostenstellenBloecke::block(' 103 ', null));
    }

    public function testEigeneKostenstelleGehtVorDerStelle(): void
    {
        $this->assertSame(200, KostenstellenBloecke::block('201', '100'));
        $this->assertSame(100, KostenstellenBloecke::block('', '100'));
        $this->assertSame(100, KostenstellenBloecke::block(null, '100'));
    }

    public function testNichtNumerischOderLeerIstNull(): void
    {
        $this->assertNull(KostenstellenBloecke::block('MGL', null));
        $this->assertNull(KostenstellenBloecke::block(null, null));
        $this->assertNull(KostenstellenBloecke::block('', ''));
        $this->assertSame(0, KostenstellenBloecke::block('0', null));
    }

    public function testOptionenGruppierenUndZaehlen(): void
    {
        $rows = [
            ['cost_center' => '401', 'position_cost_center' => null],
            ['cost_center' => '400', 'position_cost_center' => null],
            ['cost_center' => null, 'position_cost_center' => '200'],
            ['cost_center' => 'MGL', 'position_cost_center' => null],
            ['cost_center' => '', 'position_cost_center' => ''],
        ];
        $this->assertSame([
            ['block' => 200, 'nummern' => ['200'], 'count' => 1],
            ['block' => 400, 'nummern' => ['400', '401'], 'count' => 2],
            ['block' => null, 'nummern' => [], 'count' => 2],
        ], KostenstellenBloecke::optionen($rows));
    }
}
```

`tests/Unit/Dispo/Jobs/DispoEinsatzZaehlerTest.php`:
```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoEinsatzZaehler;

final class DispoEinsatzZaehlerTest extends TestCase
{
    private static function zeile(int $emp, string $datum, array $extra = []): array
    {
        return $extra + ['employee_id' => $emp, 'datum' => $datum, 'status_id' => 1,
            'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null];
    }

    public function testZaehltNurEinsaetzeImMonatUeberDiePersonengruppe(): void
    {
        $rows = [
            self::zeile(1, '2026-10-03'),
            self::zeile(2, '2026-10-10'),            // MA-Datensatz derselben Person
            self::zeile(1, '2026-09-30'),            // Vormonat
            self::zeile(1, '2026-10-11', ['status_id' => 3]),
            self::zeile(1, '2026-10-12', ['missing_since' => '2026-10-01 00:00:00']),
            self::zeile(1, '2026-10-13', ['deletion_marked_at' => '2026-10-01 00:00:00']),
            self::zeile(1, '2026-10-14', ['declined_at' => '2026-10-01 00:00:00']),
            self::zeile(3, '2026-10-05'),
        ];
        $this->assertSame([1 => 2, 3 => 1], DispoEinsatzZaehler::proMonat($rows, [1 => 1, 2 => 1, 3 => 3], '2026-10'));
    }

    public function testBaender(): void
    {
        $this->assertSame('0', DispoEinsatzZaehler::band(0));
        $this->assertSame('1-4', DispoEinsatzZaehler::band(1));
        $this->assertSame('1-4', DispoEinsatzZaehler::band(4));
        $this->assertSame('5-9', DispoEinsatzZaehler::band(5));
        $this->assertSame('5-9', DispoEinsatzZaehler::band(9));
        $this->assertSame('10-19', DispoEinsatzZaehler::band(10));
        $this->assertSame('10-19', DispoEinsatzZaehler::band(19));
        $this->assertSame('20+', DispoEinsatzZaehler::band(20));
        $this->assertSame('20+', DispoEinsatzZaehler::band(57));
    }

    public function testTrifftVereinigungUndAlle(): void
    {
        $this->assertTrue(DispoEinsatzZaehler::trifft(0, ['0', '1-4']));
        $this->assertTrue(DispoEinsatzZaehler::trifft(3, ['0', '1-4']));
        $this->assertFalse(DispoEinsatzZaehler::trifft(5, ['0', '1-4']));
        $this->assertTrue(DispoEinsatzZaehler::trifft(57, ['alle']));
        $this->assertFalse(DispoEinsatzZaehler::trifft(0, []), 'leere Auswahl heisst niemand');
        $this->assertFalse(DispoEinsatzZaehler::trifft(0, ['quatsch']));
    }
}
```

`tests/Unit/Dispo/Jobs/DispoTagesPruefungTest.php`:
```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoTagesPruefung;

final class DispoTagesPruefungTest extends TestCase
{
    public function testGebuchteTageJePersonengruppe(): void
    {
        $rows = [
            ['employee_id' => 1, 'datum' => '2026-10-24', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
            ['employee_id' => 2, 'datum' => '2026-10-25 00:00:00', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
            ['employee_id' => 1, 'datum' => '2026-10-26', 'status_id' => 0, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
            ['employee_id' => 1, 'datum' => '2026-10-27', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => '2026-10-01'],
            ['employee_id' => 9, 'datum' => '2026-10-24', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
        ];
        $this->assertSame(
            [1 => ['2026-10-24' => true, '2026-10-25' => true], 9 => ['2026-10-24' => true]],
            DispoTagesPruefung::gebuchteTage($rows, [1 => 1, 2 => 1, 9 => 9])
        );
    }

    public function testUnbekannterMitarbeiterIstSeinEigenerAnker(): void
    {
        $rows = [['employee_id' => 5, 'datum' => '2026-10-24', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null]];
        $this->assertSame([5 => ['2026-10-24' => true]], DispoTagesPruefung::gebuchteTage($rows, []));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, Scheitern sehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Dispo/Jobs`
Expected: FAIL mit „Class … not found“.

- [ ] **Step 3: Implementieren**

`src/Services/Zas/Dispo/Jobs/KostenstellenBloecke.php`:
```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Mitarbeiterfiliale aus der Kostenstelle (Prod-Befund 12.10.2026: 100/101/102/103
 * = DUS & ES, 200–203 = MGL, 400–403 = CGN). Der Hunderterblock ist die Filiale,
 * die Unternummern laufen mit. Rein rechnend; Labels vergibt der Aufrufer
 * (Filialen::code), damit die Klasse ohne Container testbar bleibt.
 */
final class KostenstellenBloecke
{
    /** Eigene Kostenstelle vor der der Stelle; nicht numerisch oder leer -> null. */
    public static function block(?string $eigene, ?string $stelle): ?int
    {
        foreach ([$eigene, $stelle] as $wert) {
            $wert = trim((string) $wert);
            if ($wert !== '' && ctype_digit($wert)) {
                return intdiv((int) $wert, 100) * 100;
            }
            if ($wert !== '') {
                return null; // "MGL" o. ae.: gesetzt, aber unbrauchbar — nicht auf die Stelle zurueckfallen
            }
        }

        return null;
    }

    /**
     * @param iterable<array{cost_center:?string, position_cost_center:?string}> $rows
     * @return list<array{block:?int, nummern:list<string>, count:int}> sortiert nach Block, null zuletzt
     */
    public static function optionen(iterable $rows): array
    {
        $bloecke = [];
        $ohne = 0;
        foreach ($rows as $row) {
            $block = self::block($row['cost_center'] ?? null, $row['position_cost_center'] ?? null);
            if ($block === null) {
                $ohne++;
                continue;
            }
            $nummer = trim((string) ($row['cost_center'] ?? ''));
            if ($nummer === '' || !ctype_digit($nummer)) {
                $nummer = trim((string) ($row['position_cost_center'] ?? ''));
            }
            $bloecke[$block]['count'] = ($bloecke[$block]['count'] ?? 0) + 1;
            $bloecke[$block]['nummern'][$nummer] = true;
        }
        ksort($bloecke);

        $out = [];
        foreach ($bloecke as $block => $data) {
            $nummern = array_keys($data['nummern']);
            sort($nummern, SORT_NATURAL);
            $out[] = ['block' => $block, 'nummern' => array_values(array_map('strval', $nummern)), 'count' => $data['count']];
        }
        if ($ohne > 0) {
            $out[] = ['block' => null, 'nummern' => [], 'count' => $ohne];
        }

        return $out;
    }
}
```

`src/Services/Zas/Dispo/Jobs/DispoTagesPruefung.php`:
```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * „Ist die Person an Tag X irgendwo gebucht?“ — ueber alle Veranstaltungen und
 * Filialen, je Personengruppe (Anker). Rein rechnend.
 */
final class DispoTagesPruefung
{
    /** Gemeinsame Zeilenregel fuer Zaehler und Tagespruefung: Auftrag, nicht verschwunden/gemeldet/abgesagt. */
    public static function istEinsatz(array $a): bool
    {
        return (int) ($a['status_id'] ?? -1) === 1
            && empty($a['missing_since'])
            && empty($a['deletion_marked_at'])
            && empty($a['declined_at']);
    }

    /**
     * @param list<array{employee_id:int, datum:string, status_id:int, missing_since:?string, deletion_marked_at:?string, declined_at:?string}> $assignments
     * @param array<int,int> $anchorByEmployee employee_id => Anker (fehlt -> die id selbst)
     * @return array<int, array<string,true>> Anker => [Y-m-d => true]
     */
    public static function gebuchteTage(array $assignments, array $anchorByEmployee): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if (!self::istEinsatz($a)) {
                continue;
            }
            $emp = (int) $a['employee_id'];
            $anchor = $anchorByEmployee[$emp] ?? $emp;
            $out[$anchor][substr((string) $a['datum'], 0, 10)] = true;
        }
        ksort($out);

        return $out;
    }
}
```

`src/Services/Zas/Dispo/Jobs/DispoEinsatzZaehler.php`:
```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Einsaetze im Kalendermonat je Personengruppe und die Gruppen-Baender des
 * Kunden (PDF „Mitarbeitergruppen“): 0 / weniger als 5 / weniger als 10 /
 * weniger als 20 / alle. Die Baender sind DISJUNKT, damit „naechste Gruppe“ in
 * Welle 2 nicht dieselben Leute trifft wie Welle 1.
 */
final class DispoEinsatzZaehler
{
    /** Schluessel => Beschriftung (Kundenwort zuerst, Band in Klammern). */
    public const BAENDER = [
        '0'     => '0 Einsätze',
        '1-4'   => 'weniger als 5 (1–4)',
        '5-9'   => 'weniger als 10 (5–9)',
        '10-19' => 'weniger als 20 (10–19)',
        '20+'   => '20 und mehr',
        'alle'  => 'alle verfügbaren Mitarbeiter',
    ];

    /**
     * @param list<array<string,mixed>> $assignments wie DispoTagesPruefung
     * @param array<int,int> $anchorByEmployee
     * @return array<int,int> Anker => Anzahl (nur Anker mit mindestens einem Einsatz)
     */
    public static function proMonat(array $assignments, array $anchorByEmployee, string $monat): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if (!DispoTagesPruefung::istEinsatz($a)) {
                continue;
            }
            if (substr((string) $a['datum'], 0, 7) !== $monat) {
                continue;
            }
            $emp = (int) $a['employee_id'];
            $anchor = $anchorByEmployee[$emp] ?? $emp;
            $out[$anchor] = ($out[$anchor] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    public static function band(int $anzahl): string
    {
        return match (true) {
            $anzahl <= 0 => '0',
            $anzahl <= 4 => '1-4',
            $anzahl <= 9 => '5-9',
            $anzahl <= 19 => '10-19',
            default => '20+',
        };
    }

    /** @param list<string> $gruppen leer = niemand; 'alle' = jeder */
    public static function trifft(int $anzahl, array $gruppen): bool
    {
        if ($gruppen === []) {
            return false;
        }
        if (in_array('alle', $gruppen, true)) {
            return true;
        }

        return in_array(self::band($anzahl), $gruppen, true);
    }
}
```

- [ ] **Step 4: Tests laufen lassen, grün sehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Dispo/Jobs`
Expected: PASS, 9 Tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/Jobs tests/Unit/Dispo/Jobs
git commit -m "feat(recruiting): Jobs anbieten — Kostenstellen-Bloecke, Einsatz-Zaehler, Tagespruefung (rein)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Empfängerplaner (rein)

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobRecipientPlanner.php`
- Test: `tests/Unit/Dispo/Jobs/DispoJobRecipientPlannerTest.php`

**Interfaces:**
- Consumes: `DispoEinsatzZaehler::trifft`, `KostenstellenBloecke` (nur Werte)
- Produces: `DispoJobRecipientPlanner::plan(array $kandidaten, array $schichten, array $einsaetze, array $gebucht, array $schonAngeschrieben, array $auswahl): array{recipients: list<array{anchor:int, employee_id:int, phone:string, first_name:string, portal_token:string, shift_ids:list<int>}>, skipped: array<string,int>, skipped_ids: array<string,list<int>>}`
- Töpfe (Reihenfolge): `schon_angeschrieben`, `portal_gesperrt`, `ohne_nummer`, `ohne_taetigkeit`, `alle_tage_gebucht`. Block- und Gruppenfilter definieren die Grundmenge und zählen nicht als Topf.
- Kandidat: `array{anchor:int, employee_id:int, phone:?string, first_name:string, portal_token:string, block:?int, portal_locked:bool, taetigkeiten:list<string>}` — **eine Zeile je Person** (der Lader in Task 5 wählt je Gruppe den Datensatz mit Nummer).
- Schicht: `array{id:int, datum:string, taetigkeit:string}`.
- Auswahl: `array{gruppen:list<string>, bloecke:list<int|null>, nur_passend:bool}`; `null` in `bloecke` = „ohne Kostenstelle“.

- [ ] **Step 1: Failing test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner;

final class DispoJobRecipientPlannerTest extends TestCase
{
    private static function kandidat(int $id, array $extra = []): array
    {
        return $extra + ['anchor' => $id, 'employee_id' => $id, 'phone' => '+4917' . $id, 'first_name' => 'P' . $id,
            'portal_token' => 'tok' . $id, 'block' => 200, 'portal_locked' => false, 'taetigkeiten' => ['Zapfer']];
    }

    private static function schichten(): array
    {
        return [
            ['id' => 11, 'datum' => '2026-10-24', 'taetigkeit' => 'Zapfer'],
            ['id' => 12, 'datum' => '2026-10-25', 'taetigkeit' => 'Servicekraft'],
        ];
    }

    private static function auswahl(array $extra = []): array
    {
        return $extra + ['gruppen' => ['alle'], 'bloecke' => [200], 'nur_passend' => false];
    }

    public function testHappyPathBekommtAlleFreienSchichten(): void
    {
        $r = DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl());
        $this->assertSame([11, 12], $r['recipients'][0]['shift_ids']);
        $this->assertSame('+4171', $r['recipients'][0]['phone']);
        $this->assertSame(0, array_sum($r['skipped']));
    }

    public function testBlockUndGruppeBildenDieGrundmenge(): void
    {
        $k = [self::kandidat(1, ['block' => 100]), self::kandidat(2, ['block' => null]), self::kandidat(3)];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [3 => 7], [], [], self::auswahl(['bloecke' => [200, null], 'gruppen' => ['5-9']]));
        $this->assertSame([3], array_column($r['recipients'], 'anchor'));
        $this->assertSame(0, array_sum($r['skipped']), 'Grundmenge zaehlt nicht als Topf');
    }

    public function testLeereGruppenOderBloeckeHeissenNiemand(): void
    {
        $this->assertSame([], DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl(['gruppen' => []]))['recipients']);
        $this->assertSame([], DispoJobRecipientPlanner::plan([self::kandidat(1)], self::schichten(), [], [], [], self::auswahl(['bloecke' => []]))['recipients']);
    }

    public function testToepfeInReihenfolge(): void
    {
        $k = [
            self::kandidat(1),                                        // schon angeschrieben
            self::kandidat(2, ['portal_locked' => true, 'phone' => null]),
            self::kandidat(3, ['phone' => null]),
            self::kandidat(4, ['taetigkeiten' => ['Koch']]),          // ohne passende Taetigkeit
            self::kandidat(5),                                        // an beiden Tagen gebucht
        ];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [5 => ['2026-10-24' => true, '2026-10-25' => true]], [1 => true], self::auswahl(['nur_passend' => true]));
        $this->assertSame([], $r['recipients']);
        $this->assertSame(['schon_angeschrieben' => 1, 'portal_gesperrt' => 1, 'ohne_nummer' => 1, 'ohne_taetigkeit' => 1, 'alle_tage_gebucht' => 1], $r['skipped']);
        $this->assertSame([2], $r['skipped_ids']['portal_gesperrt'], 'gesperrt vor ohne Nummer');
    }

    public function testNurFreieTageUndPassendeSchichtenBleiben(): void
    {
        $k = [self::kandidat(1, ['taetigkeiten' => ['zapfer ']])];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [1 => ['2026-10-25' => true]], [], self::auswahl(['nur_passend' => true]));
        $this->assertSame([11], $r['recipients'][0]['shift_ids'], 'Taetigkeit case-insensitiv, 25. ist gebucht');
    }

    public function testOhnePassendFilterBleibenAlleSchichten(): void
    {
        $k = [self::kandidat(1, ['taetigkeiten' => []])];
        $r = DispoJobRecipientPlanner::plan($k, self::schichten(), [], [], [], self::auswahl(['nur_passend' => false]));
        $this->assertSame([11, 12], $r['recipients'][0]['shift_ids']);
    }
}
```

- [ ] **Step 2: Scheitern sehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Dispo/Jobs/DispoJobRecipientPlannerTest.php`
Expected: FAIL, Klasse fehlt.

- [ ] **Step 3: Implementieren**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Wer bekommt in dieser Welle ein Angebot — und welche Schichten stehen drin.
 * Rein rechnend (Muster DispoRecipientPlanner): nichts wird still uebersprungen,
 * jeder Ausschluss landet in einem Topf mit den Anker-IDs dahinter.
 *
 * Block- und Gruppenfilter sind die GRUNDMENGE (so hat der Disponent gewaehlt),
 * kein Topf. Die Toepfe erklaeren, warum jemand aus der Grundmenge nichts
 * bekommt. Reihenfolge: schon angeschrieben, Portal gesperrt, ohne Nummer,
 * ohne passende Taetigkeit, an allen Tagen gebucht.
 */
final class DispoJobRecipientPlanner
{
    public const TOEPFE = [
        'schon_angeschrieben' => 'in dieser Anfrage schon angeschrieben',
        'portal_gesperrt'     => 'Portal gesperrt',
        'ohne_nummer'         => 'ohne Handynummer',
        'ohne_taetigkeit'     => 'ohne passende Tätigkeit',
        'alle_tage_gebucht'   => 'an allen gewählten Tagen schon gebucht',
    ];

    /**
     * @param list<array{anchor:int, employee_id:int, phone:?string, first_name:string, portal_token:string, block:?int, portal_locked:bool, taetigkeiten:list<string>}> $kandidaten
     * @param list<array{id:int, datum:string, taetigkeit:string}> $schichten
     * @param array<int,int> $einsaetze Anker => Einsaetze im Monat
     * @param array<int,array<string,true>> $gebucht Anker => gebuchte Tage
     * @param array<int,true> $schonAngeschrieben Anker => true
     * @param array{gruppen:list<string>, bloecke:list<int|null>, nur_passend:bool} $auswahl
     * @return array{recipients: list<array{anchor:int, employee_id:int, phone:string, first_name:string, portal_token:string, shift_ids:list<int>}>, skipped: array<string,int>, skipped_ids: array<string,list<int>>}
     */
    public static function plan(array $kandidaten, array $schichten, array $einsaetze, array $gebucht, array $schonAngeschrieben, array $auswahl): array
    {
        $skipped = array_fill_keys(array_keys(self::TOEPFE), 0);
        $skippedIds = array_fill_keys(array_keys(self::TOEPFE), []);
        $recipients = [];

        $bloecke = $auswahl['bloecke'] ?? [];
        $gruppen = $auswahl['gruppen'] ?? [];
        $nurPassend = (bool) ($auswahl['nur_passend'] ?? false);
        $ohneKostenstelle = in_array(null, $bloecke, true);
        $blockSet = array_fill_keys(array_map('intval', array_filter($bloecke, fn ($b) => $b !== null)), true);

        foreach ($kandidaten as $k) {
            $anchor = (int) $k['anchor'];
            $block = $k['block'];
            $imBlock = $block === null ? $ohneKostenstelle : isset($blockSet[(int) $block]);
            if (!$imBlock || !DispoEinsatzZaehler::trifft((int) ($einsaetze[$anchor] ?? 0), $gruppen)) {
                continue;
            }
            if (isset($schonAngeschrieben[$anchor])) {
                self::topf($skipped, $skippedIds, 'schon_angeschrieben', $anchor);
                continue;
            }
            if (!empty($k['portal_locked'])) {
                self::topf($skipped, $skippedIds, 'portal_gesperrt', $anchor);
                continue;
            }
            $phone = trim((string) ($k['phone'] ?? ''));
            if ($phone === '') {
                self::topf($skipped, $skippedIds, 'ohne_nummer', $anchor);
                continue;
            }

            $eigene = array_fill_keys(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $k['taetigkeiten'] ?? []), true);
            $passend = $nurPassend
                ? array_values(array_filter($schichten, fn ($s) => isset($eigene[mb_strtolower(trim((string) $s['taetigkeit']))])))
                : $schichten;
            if ($passend === []) {
                self::topf($skipped, $skippedIds, 'ohne_taetigkeit', $anchor);
                continue;
            }

            $tage = $gebucht[$anchor] ?? [];
            $frei = array_values(array_filter($passend, fn ($s) => !isset($tage[substr((string) $s['datum'], 0, 10)])));
            if ($frei === []) {
                self::topf($skipped, $skippedIds, 'alle_tage_gebucht', $anchor);
                continue;
            }

            $recipients[] = [
                'anchor'       => $anchor,
                'employee_id'  => (int) $k['employee_id'],
                'phone'        => $phone,
                'first_name'   => (string) $k['first_name'],
                'portal_token' => (string) $k['portal_token'],
                'shift_ids'    => array_map(fn ($s) => (int) $s['id'], $frei),
            ];
        }

        return ['recipients' => $recipients, 'skipped' => $skipped, 'skipped_ids' => $skippedIds];
    }

    private static function topf(array &$skipped, array &$ids, string $topf, int $anchor): void
    {
        $skipped[$topf]++;
        $ids[$topf][] = $anchor;
    }
}
```

- [ ] **Step 4: Grün sehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Dispo/Jobs`
Expected: PASS, 15 Tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobRecipientPlanner.php tests/Unit/Dispo/Jobs/DispoJobRecipientPlannerTest.php
git commit -m "feat(recruiting): Jobs anbieten — Empfaengerplaner (rein, fuenf Toepfe)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Migrationen und Modelle

**Files:**
- Create: `database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php`
- Create: `src/Models/RecDispoJobRequest.php`, `src/Models/RecDispoJobShift.php`, `src/Models/RecDispoJobWave.php`, `src/Models/RecDispoJobOffer.php`, `src/Models/RecDispoJobOfferShift.php`
- Test: `tests/Integration/DispoJobTablesTest.php`

**Interfaces:**
- Produces: Modelle mit Konstanten `RecDispoJobRequest::MODUS_SAMMELN = 'sammeln'`, `MODUS_ZUERST = 'zuerst'`, `STATUS_ENTWURF/LAEUFT/ABGESCHLOSSEN`; Relationen `request->shifts()`, `->waves()`, `->offers()`; `wave->offers()`; `offer->shifts()` (hasMany `RecDispoJobOfferShift`); `shift->offerShifts()`.
- Produces: Test-Helfer-Muster `DispoJobTablesTest::bootCapsule()` — **die späteren Integrationstests kopieren setUpBeforeClass/runMigrations von hier.**

- [ ] **Step 1: Failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecDispoJobWave;

/** Tabellen + Relationen der Jobangebote. Muster DispoEmployeeAssignmentsTest (Capsule/SQLite). */
class DispoJobTablesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_anfrage_schicht_welle_angebot_haengen_zusammen(): void
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2026-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF, 'created_by_user_id' => 1]);
        $this->assertNotEmpty($request->uuid);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 20]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => 5, 'anchor_employee_id' => 5]);
        $offer->shifts()->create(['shift_id' => $shift->id]);

        $this->assertSame([200], RecDispoJobWave::find($wave->id)->ma_bloecke);
        $this->assertSame(1, RecDispoJobShift::find($shift->id)->offerShifts()->count());
        $this->assertSame($request->id, RecDispoJobOffer::find($offer->id)->request->id);
        $this->assertSame(1, $request->offers()->count());
    }
}
```

- [ ] **Step 2: Scheitern sehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/DispoJobTablesTest.php`
Expected: FAIL, Migration fehlt.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispo „Jobs anbieten“ (Spec 2026-10-12): Anfrage -> Schichten, Wellen,
 * Angebote (eine Person je Welle), Angebot<->Schicht mit Zusage und ZAS-Haken.
 * Keine Fremdschluessel ueber Modulgrenzen; Mitarbeiter-IDs als blosse Zahlen
 * (Muster rec_dispo_assignments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_job_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('team_id')->index();
            $t->unsignedInteger('filial_nr');
            $t->date('zeitraum_von');
            $t->date('zeitraum_bis');
            $t->string('modus', 16)->default('sammeln');
            $t->unsignedBigInteger('whatsapp_template_id')->nullable();
            $t->string('status', 16)->default('entwurf')->index();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedBigInteger('rec_dispo_event_id')->index();
            $t->date('datum');
            $t->string('von', 8)->nullable();
            $t->string('taetigkeit', 190);
            $t->unsignedInteger('gesucht')->default(0);
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'rec_dispo_event_id', 'datum', 'von', 'taetigkeit'], 'rec_dispo_job_shifts_eindeutig');
        });

        Schema::create('rec_dispo_job_waves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedInteger('nr');
            $t->json('ma_bloecke');
            $t->json('gruppen');
            $t->boolean('nur_passende_taetigkeit')->default(true);
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->uuid('job_uuid')->nullable();
            $t->json('summary')->nullable();
            $t->timestamps();
        });

        Schema::create('rec_dispo_job_offers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wave_id')->index();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedBigInteger('rec_employee_id')->index();
            $t->unsignedBigInteger('anchor_employee_id')->index();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('send_error', 500)->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'anchor_employee_id'], 'rec_dispo_job_offers_eine_je_person');
        });

        Schema::create('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offer_id')->index();
            $t->unsignedBigInteger('shift_id')->index();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('zas_entered_at')->nullable();
            $t->unsignedBigInteger('zas_entered_by_user_id')->nullable();
            $t->unsignedBigInteger('zas_assignment_id')->nullable();
            $t->timestamps();
            $t->unique(['offer_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
```

- [ ] **Step 4: Modelle**

`src/Models/RecDispoJobRequest.php`:
```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\UuidV7;

/** Anfrage „Jobs anbieten“ (Spec 2026-10-12). */
class RecDispoJobRequest extends Model
{
    public const MODUS_SAMMELN = 'sammeln';
    public const MODUS_ZUERST = 'zuerst';
    public const STATUS_ENTWURF = 'entwurf';
    public const STATUS_LAEUFT = 'laeuft';
    public const STATUS_ABGESCHLOSSEN = 'abgeschlossen';

    protected $table = 'rec_dispo_job_requests';
    protected $fillable = ['uuid', 'team_id', 'filial_nr', 'zeitraum_von', 'zeitraum_bis', 'modus', 'whatsapp_template_id', 'status', 'created_by_user_id', 'closed_at'];
    protected $casts = ['filial_nr' => 'integer', 'zeitraum_von' => 'date:Y-m-d', 'zeitraum_bis' => 'date:Y-m-d', 'whatsapp_template_id' => 'integer', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->uuid)) {
                $m->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function shifts(): HasMany { return $this->hasMany(RecDispoJobShift::class, 'request_id'); }
    public function waves(): HasMany { return $this->hasMany(RecDispoJobWave::class, 'request_id'); }
    public function offers(): HasMany { return $this->hasMany(RecDispoJobOffer::class, 'request_id'); }

    public function laeuft(): bool { return $this->status === self::STATUS_LAEUFT && $this->closed_at === null; }
}
```

`src/Models/RecDispoJobShift.php`:
```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecDispoJobShift extends Model
{
    protected $table = 'rec_dispo_job_shifts';
    protected $fillable = ['request_id', 'rec_dispo_event_id', 'datum', 'von', 'taetigkeit', 'gesucht', 'closed_at'];
    protected $casts = ['datum' => 'date:Y-m-d', 'gesucht' => 'integer', 'closed_at' => 'datetime'];

    public function request(): BelongsTo { return $this->belongsTo(RecDispoJobRequest::class, 'request_id'); }
    public function event(): BelongsTo { return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id'); }
    public function offerShifts(): HasMany { return $this->hasMany(RecDispoJobOfferShift::class, 'shift_id'); }
}
```

`src/Models/RecDispoJobWave.php`:
```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecDispoJobWave extends Model
{
    protected $table = 'rec_dispo_job_waves';
    protected $fillable = ['request_id', 'nr', 'ma_bloecke', 'gruppen', 'nur_passende_taetigkeit', 'scheduled_for', 'started_at', 'finished_at', 'job_uuid', 'summary'];
    protected $casts = ['nr' => 'integer', 'ma_bloecke' => 'array', 'gruppen' => 'array', 'nur_passende_taetigkeit' => 'boolean',
        'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'summary' => 'array'];

    public function request(): BelongsTo { return $this->belongsTo(RecDispoJobRequest::class, 'request_id'); }
    public function offers(): HasMany { return $this->hasMany(RecDispoJobOffer::class, 'wave_id'); }
}
```

`src/Models/RecDispoJobOffer.php`:
```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein Angebot = eine Person in einer Welle (die Nachricht). Die Schichten haengen in offer_shifts. */
class RecDispoJobOffer extends Model
{
    protected $table = 'rec_dispo_job_offers';
    protected $fillable = ['wave_id', 'request_id', 'rec_employee_id', 'anchor_employee_id', 'message_id', 'sent_at', 'send_error'];
    protected $casts = ['message_id' => 'integer', 'sent_at' => 'datetime'];

    public function request(): BelongsTo { return $this->belongsTo(RecDispoJobRequest::class, 'request_id'); }
    public function wave(): BelongsTo { return $this->belongsTo(RecDispoJobWave::class, 'wave_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(RecEmployee::class, 'rec_employee_id'); }
    public function shifts(): HasMany { return $this->hasMany(RecDispoJobOfferShift::class, 'offer_id'); }
}
```

`src/Models/RecDispoJobOfferShift.php`:
```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecDispoJobOfferShift extends Model
{
    protected $table = 'rec_dispo_job_offer_shifts';
    protected $fillable = ['offer_id', 'shift_id', 'accepted_at', 'zas_entered_at', 'zas_entered_by_user_id', 'zas_assignment_id'];
    protected $casts = ['accepted_at' => 'datetime', 'zas_entered_at' => 'datetime', 'zas_assignment_id' => 'integer'];

    public function offer(): BelongsTo { return $this->belongsTo(RecDispoJobOffer::class, 'offer_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(RecDispoJobShift::class, 'shift_id'); }
}
```

- [ ] **Step 5: Grün sehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Integration/DispoJobTablesTest.php`
Expected: PASS, 1 Test.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php src/Models/RecDispoJob*.php tests/Integration/DispoJobTablesTest.php
git commit -m "feat(recruiting): Jobs anbieten — Tabellen und Modelle (Anfrage, Schicht, Welle, Angebot)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Kandidaten-Lader (DB) — Personen, Blöcke, Zähler, Tagesprüfung

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobKandidaten.php`
- Test: `tests/Integration/DispoJobKandidatenTest.php`

**Interfaces:**
- Consumes: Task 1 Klassen, `DispoIdentityResolver::groupsFor`, `DispoEmployeeGateway::contacts`, `RecEmployeeHrData.dispo_taetigkeiten`, `KostenstellenBloecke`.
- Produces: `DispoJobKandidaten::laden(int $teamId, array $tage /* list<Y-m-d> */, string $monat): array{kandidaten: list<array{anchor,employee_id,phone,first_name,portal_token,block,portal_locked,taetigkeiten,name}>, einsaetze: array<int,int>, gebucht: array<int,array<string,true>>}`
- Produces: `DispoJobKandidaten::blockOptionen(int $teamId): list<array{block:?int, label:string, nummern:list<string>, count:int}>` (Label über `Filialen::code` mit Rückfall `#<block>` bzw. „ohne Kostenstelle“).

- [ ] **Step 1: Failing test** (Capsule wie Task 3; Migrationen zusätzlich `rec_employees`, `rec_employee_hr_data`, `rec_positions` — die Pfade mit `ls database/migrations | grep -E "rec_employees|hr_data|rec_positions"` ermitteln; **auch** `2026_07_07_000001_add_cost_center_to_rec_employees.php` und `2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php` laden; falls weitere `add_*_to_rec_employees`-Migrationen Spalten liefern, die `create` noch nicht hat (`phone`, `portal_token`, `portal_locked_at`, `is_active`, `team_id`), ebenfalls laden — der Test nennt beim Scheitern die fehlende Spalte.)

```php
public function test_eine_zeile_je_person_mit_nummer_block_taetigkeiten_und_zaehlern(): void
{
    // Zwei Datensaetze derselben Person (inbound_team_id = 0 -> keine Gruppierung, also hier
    // zwei Personen; die Gruppierung selbst deckt DispoIdentityGroupsTest ab).
    $a = $this->employee(['first_name' => 'Anna', 'phone' => '0176 1', 'cost_center' => '401', 'portal_token' => 'ta']);
    $b = $this->employee(['first_name' => 'Ben', 'phone' => null, 'cost_center' => '', 'portal_token' => 'tb']);
    $c = $this->employee(['first_name' => 'Cem', 'phone' => '0176 3', 'cost_center' => null, 'portal_token' => 'tc', 'portal_locked_at' => '2026-10-01 00:00:00', 'is_active' => false]);
    Capsule::table('rec_employee_hr_data')->insert(['rec_employee_id' => $a, 'dispo_taetigkeiten' => json_encode(['Zapfer', 'Logistiker']), 'created_at' => now(), 'updated_at' => now()]);
    $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
    foreach (['2026-10-24', '2026-10-02', '2026-09-30'] as $d) {
        RecDispoAssignment::create(['ds_ref' => 'd' . $d, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => $d, 'status_id' => 1, 'taetigkeit' => 'Zapfer']);
    }

    $r = DispoJobKandidaten::laden(3, ['2026-10-24', '2026-10-25'], '2026-10');

    $this->assertCount(2, $r['kandidaten'], 'inaktive fallen raus');
    $anna = $r['kandidaten'][0];
    $this->assertSame(['anchor' => $a, 'block' => 400, 'taetigkeiten' => ['Zapfer', 'Logistiker'], 'portal_locked' => false], array_intersect_key($anna, array_flip(['anchor', 'block', 'taetigkeiten', 'portal_locked'])));
    $this->assertStringStartsWith('+49', $anna['phone']);
    $this->assertNull($r['kandidaten'][1]['block']);
    $this->assertSame([$a => 2], $r['einsaetze']);
    $this->assertSame([$a => ['2026-10-24' => true]], $r['gebucht'], 'nur die gefragten Tage');
}

public function test_block_optionen_mit_labels(): void
{
    $this->employee(['cost_center' => '201']);
    $this->employee(['cost_center' => null]);
    $o = DispoJobKandidaten::blockOptionen(3);
    $this->assertSame([200, null], array_column($o, 'block'));
    $this->assertSame('ohne Kostenstelle', $o[1]['label']);
}
```
`employee()` legt einen aktiven `rec_employees`-Datensatz in Team 3 mit Defaults an und gibt die id zurück. Config im Test: `['recruiting' => ['zas' => ['inbound_team_id' => 0], 'filialen' => [200 => ['code' => 'MGL', 'name' => 'Mönchengladbach']]]]`.

- [ ] **Step 2: Scheitern sehen** — Run wie Task 3 mit der neuen Datei. Expected: FAIL, Klasse fehlt.

- [ ] **Step 3: Implementieren**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\Filialen;

/**
 * Baut die Eingaben des Empfaengerplaners aus der Datenbank: eine Zeile je
 * PERSON (Anker der Identitaetsgruppe), der Datensatz mit Handynummer gewinnt,
 * Taetigkeiten und Sperren werden ueber die ganze Gruppe vereinigt.
 */
final class DispoJobKandidaten
{
    public const OHNE_KOSTENSTELLE = 'ohne Kostenstelle';

    /**
     * @param list<string> $tage Y-m-d der angebotenen Schichten
     * @return array{kandidaten: list<array<string,mixed>>, einsaetze: array<int,int>, gebucht: array<int,array<string,true>>}
     */
    public static function laden(int $teamId, array $tage, string $monat): array
    {
        $employees = RecEmployee::query()
            ->with(['position:id,cost_center', 'hrData:id,rec_employee_id,dispo_taetigkeiten'])
            ->where('team_id', $teamId)->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'rec_position_id', 'first_name', 'last_name', 'phone', 'portal_token', 'portal_locked_at', 'cost_center']);
        if ($employees->isEmpty()) {
            return ['kandidaten' => [], 'einsaetze' => [], 'gebucht' => []];
        }

        $ids = $employees->pluck('id')->map(fn ($v) => (int) $v)->all();
        $groups = app(DispoIdentityResolver::class)->groupsFor($ids);
        $anchorByEmployee = [];
        foreach ($groups as $id => $group) {
            $anchorByEmployee[$id] = min($group);
        }
        $contacts = app(DispoEmployeeGateway::class)->contacts($ids);

        $byAnchor = [];
        foreach ($employees as $e) {
            $id = (int) $e->id;
            $anchor = $anchorByEmployee[$id] ?? $id;
            $contact = $contacts[$id] ?? null;
            $phone = $contact['phone'] ?? null;
            $taet = array_values(array_filter(array_map('strval', (array) ($e->hrData?->dispo_taetigkeiten ?? []))));
            $row = &$byAnchor[$anchor];
            $row ??= ['anchor' => $anchor, 'employee_id' => $id, 'phone' => null, 'first_name' => (string) $e->first_name,
                'name' => trim($e->first_name . ' ' . $e->last_name), 'portal_token' => (string) $e->portal_token,
                'block' => KostenstellenBloecke::block($e->cost_center, $e->position?->cost_center), 'portal_locked' => false, 'taetigkeiten' => []];
            if ($row['phone'] === null && $phone !== null && trim($phone) !== '') {
                // Der Datensatz MIT Nummer bekommt die Nachricht (und seinen Token).
                $row['employee_id'] = $id;
                $row['phone'] = $phone;
                $row['portal_token'] = (string) $e->portal_token;
                $row['first_name'] = (string) $e->first_name;
            }
            $row['portal_locked'] = $row['portal_locked'] || $e->portal_locked_at !== null;
            $row['block'] ??= KostenstellenBloecke::block($e->cost_center, $e->position?->cost_center);
            $row['taetigkeiten'] = array_values(array_unique(array_merge($row['taetigkeiten'], $taet)));
            unset($row);
        }
        ksort($byAnchor);

        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $ids)
            ->where(fn ($q) => $q->whereIn(\Illuminate\Support\Facades\DB::raw('substr(datum, 1, 7)'), [$monat])
                ->orWhereIn(\Illuminate\Support\Facades\DB::raw('substr(datum, 1, 10)'), $tage))
            ->get(['rec_employee_id', 'datum', 'status_id', 'missing_since', 'deletion_marked_at', 'declined_at'])
            ->map(fn ($a) => ['employee_id' => (int) $a->rec_employee_id, 'datum' => $a->datum?->format('Y-m-d') ?? '',
                'status_id' => (int) $a->status_id, 'missing_since' => $a->missing_since, 'deletion_marked_at' => $a->deletion_marked_at, 'declined_at' => $a->declined_at])
            ->all();

        $gebucht = DispoTagesPruefung::gebuchteTage($assignments, $anchorByEmployee);
        $tageSet = array_fill_keys($tage, true);
        foreach ($gebucht as $anchor => $days) {
            $gebucht[$anchor] = array_intersect_key($days, $tageSet);
            if ($gebucht[$anchor] === []) {
                unset($gebucht[$anchor]);
            }
        }

        return [
            'kandidaten' => array_values($byAnchor),
            'einsaetze'  => DispoEinsatzZaehler::proMonat($assignments, $anchorByEmployee, $monat),
            'gebucht'    => $gebucht,
        ];
    }

    /** @return list<array{block:?int, label:string, nummern:list<string>, count:int}> */
    public static function blockOptionen(int $teamId): array
    {
        $rows = RecEmployee::query()->with('position:id,cost_center')
            ->where('team_id', $teamId)->where('is_active', true)
            ->get(['id', 'rec_position_id', 'cost_center'])
            ->map(fn ($e) => ['cost_center' => $e->cost_center, 'position_cost_center' => $e->position?->cost_center !== null ? (string) $e->position->cost_center : null])
            ->all();

        return array_map(fn ($o) => $o + ['label' => self::blockLabel($o['block'])], KostenstellenBloecke::optionen($rows));
    }

    public static function blockLabel(?int $block): string
    {
        if ($block === null) {
            return self::OHNE_KOSTENSTELLE;
        }

        return Filialen::code($block) ?? ('#' . $block);
    }
}
```
Hinweis: `substr(datum, 1, 7)` läuft auf MySQL und SQLite; `datum` ist `date`. Bei MySQL-Problemen mit `whereIn` auf `DB::raw` stattdessen `whereBetween('datum', [$monat.'-01', $monat.'-31'])->orWhereIn('datum', $tage)`.

- [ ] **Step 4: Grün sehen** — Run: `… tests/Integration/DispoJobKandidatenTest.php`. Expected: PASS, 2 Tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobKandidaten.php tests/Integration/DispoJobKandidatenTest.php
git commit -m "feat(recruiting): Jobs anbieten — Kandidaten-Lader (eine Zeile je Person, Bloecke, Zaehler, Tagespruefung)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Vorlagen-Wächter und Sender

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobVorlagenWaechter.php`
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobOfferSender.php`
- Test: `tests/Unit/Dispo/Jobs/DispoJobVorlagenWaechterTest.php`
- Test: `tests/Integration/DispoJobOfferSenderTest.php`

**Interfaces:**
- Consumes: `WhatsAppTemplateUrlButtons::hasDynamicAt`, `WhatsAppTemplateBodyVariables::names`, `CampaignTemplatePreview::render` (Muster), `DispoChannelResolver::resolveForEvent`, `WhatsAppMetaService::sendTemplate`.
- Produces: `DispoJobVorlagenWaechter::problem(mixed $components): ?string` (null = ok).
- Produces: `DispoJobOfferSender::send(RecDispoJobWave $wave, array $recipients, int $templateId): array{ok:bool, message:?string, sent:int, failed:list<array{anchor:int, error:string}>}` — legt je Empfänger ein `RecDispoJobOffer` + `RecDispoJobOfferShift`s an, stempelt `sent_at`/`message_id` oder `send_error`.

- [ ] **Step 1: Failing tests**

Wächter:
```php
public function testOhneDynamischenButtonAnPositionNull(): void
{
    $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}']];
    $this->assertStringContainsString('Link-Button', (string) DispoJobVorlagenWaechter::problem($c));
}
public function testFremdePlatzhalter(): void
{
    $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}, {{datum}}'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://x/jobs/{{1}}', 'text' => 'Ansehen']]]];
    $this->assertStringContainsString('datum', (string) DispoJobVorlagenWaechter::problem($c));
}
public function testPasst(): void
{
    $c = [['type' => 'BODY', 'text' => 'Hallo {{name}}'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://x/jobs/{{1}}', 'text' => 'Ansehen']]]];
    $this->assertNull(DispoJobVorlagenWaechter::problem($c));
    $this->assertNull(DispoJobVorlagenWaechter::problem(json_encode($c)));
}
```
(Die genaue Button-Struktur, die `WhatsAppTemplateUrlButtons::hasDynamicAt` erwartet, aus `tests/Unit/WhatsAppTemplateUrlButtonsTest.php` übernehmen.)

Sender (Capsule wie Task 3 plus `integrations_whatsapp_*`-Migrationen wie `DispoInfoSenderTest`, Meta-Stub und Kanal-Stub von dort kopieren):
```php
public function test_legt_angebote_an_und_sendet_name_und_token(): void
{
    $templateId = $this->template('Hi {{name}}');
    [$request, $wave, $shift] = $this->anfrageMitWelle();
    $recipients = [['anchor' => 5, 'employee_id' => 5, 'phone' => '+491761', 'first_name' => 'Anna', 'portal_token' => 'tok5', 'shift_ids' => [$shift->id]]];

    $r = (new DispoJobOfferSender())->send($wave, $recipients, $templateId);

    $this->assertSame(1, $r['sent']);
    $this->assertSame([['type' => 'text', 'text' => 'tok5']], $this->stub->log[0]['components'][1]['parameters']);
    $offer = RecDispoJobOffer::where('wave_id', $wave->id)->first();
    $this->assertSame(5, (int) $offer->anchor_employee_id);
    $this->assertNotNull($offer->sent_at);
    $this->assertSame([$shift->id], $offer->shifts()->pluck('shift_id')->map(fn ($v) => (int) $v)->all());
}
public function test_fehlschlag_wird_am_angebot_gestempelt(): void
{
    $templateId = $this->template('Hi');
    [$request, $wave, $shift] = $this->anfrageMitWelle();
    $this->stub->failFor = ['+491761'];
    $r = (new DispoJobOfferSender())->send($wave, [['anchor' => 5, 'employee_id' => 5, 'phone' => '+491761', 'first_name' => 'A', 'portal_token' => 't', 'shift_ids' => [$shift->id]]], $templateId);
    $this->assertSame(0, $r['sent']);
    $this->assertSame('kaputt', RecDispoJobOffer::first()->send_error);
}
public function test_zweites_angebot_an_dieselbe_person_in_derselben_anfrage_wird_nicht_angelegt(): void
{ /* Unique (request, anchor): zweiter Aufruf -> failed mit Hinweis, kein zweiter Meta-Call */ }
```

- [ ] **Step 2: Scheitern sehen** — beide Dateien. Expected: FAIL, Klassen fehlen.

- [ ] **Step 3: Implementieren**

`DispoJobVorlagenWaechter`:
```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Support\WhatsAppTemplateBodyVariables;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/** Dieselben zwei Sende-Guards wie CampaignTemplatePreview::warnung — fuer Jobangebote. */
final class DispoJobVorlagenWaechter
{
    public static function problem(mixed $components): ?string
    {
        $list = is_string($components) ? (json_decode($components, true) ?: []) : (is_array($components) ? $components : []);
        $list = array_values(array_filter(is_array($list) ? $list : [], 'is_array'));
        if ($list === []) {
            return 'Die Vorlage ist leer oder nicht lesbar.';
        }
        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($list, 0)) {
            return 'Die Vorlage hat keinen Link-Button mit Platzhalter an erster Stelle — ohne ihn gibt es keinen persönlichen Link.';
        }
        $fremd = array_values(array_filter(WhatsAppTemplateBodyVariables::names($list),
            fn (string $n): bool => !in_array(strtolower($n), ['name', 'vorname', '1'], true)));
        if ($fremd !== []) {
            return 'Die Vorlage hat Platzhalter außer dem Vornamen (' . implode(', ', $fremd) . ') — die könnten wir nicht füllen.';
        }

        return null;
    }
}
```

`DispoJobOfferSender` (Muster `DispoInfoSender`, Abweichungen: Kanal über ein transientes Event mit `filial_nr` der Anfrage — `resolveForEvent(new RecDispoEvent(['filial_nr' => $request->filial_nr]))` —, Angebote anlegen):
```php
public function send(RecDispoJobWave $wave, array $recipients, int $templateId): array
{
    $template = IntegrationsWhatsAppTemplate::find($templateId);
    if (!$template || $template->status !== 'APPROVED') {
        return ['ok' => false, 'message' => 'Vorlage nicht gefunden oder nicht freigegeben (Disposition → Einstellungen).', 'sent' => 0, 'failed' => []];
    }
    $request = $wave->request;
    $channel = app(DispoChannelResolver::class)->resolveForEvent(new RecDispoEvent(['filial_nr' => $request->filial_nr]));
    if (!$channel) { return ['ok' => false, 'message' => 'Kein WhatsApp-Kanal auflösbar (Filiale/Standard prüfen).', 'sent' => 0, 'failed' => []]; }

    $usesName = self::bodyUsesName($template);   // wie DispoInfoSender
    $service = app(WhatsAppMetaService::class);
    try { $sender = auth()->user(); } catch (\Throwable) { $sender = null; }
    $sent = 0; $failed = [];

    foreach ($recipients as $r) {
        $offer = RecDispoJobOffer::query()->where('request_id', $request->id)->where('anchor_employee_id', $r['anchor'])->first();
        if ($offer !== null) {
            $failed[] = ['anchor' => (int) $r['anchor'], 'error' => 'in dieser Anfrage schon angeschrieben'];
            continue;
        }
        $offer = RecDispoJobOffer::create(['wave_id' => $wave->id, 'request_id' => $request->id,
            'rec_employee_id' => $r['employee_id'], 'anchor_employee_id' => $r['anchor']]);
        foreach ($r['shift_ids'] as $shiftId) {
            RecDispoJobOfferShift::create(['offer_id' => $offer->id, 'shift_id' => $shiftId]);
        }

        $components = [];
        if ($usesName) { /* wie DispoInfoSender, 'zusammen' als Rueckfall */ }
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => $r['portal_token']]]];

        try {
            $message = $service->sendTemplate(channel: $channel, to: (string) $r['phone'], templateName: $template->name,
                components: $components, languageCode: $template->language ?? 'de', sender: $sender);
            if (($message->status ?? null) === 'failed') {
                $error = (string) ($message->meta_payload['error']['message'] ?? 'Meta: failed');
                $offer->update(['message_id' => $message->id ?? null, 'send_error' => $error]);
                $failed[] = ['anchor' => (int) $r['anchor'], 'error' => $error];
                continue;
            }
            $offer->update(['message_id' => $message->id ?? null, 'sent_at' => now()]);
            $sent++;
        } catch (\Throwable $e) {
            $offer->update(['send_error' => mb_substr($e->getMessage(), 0, 500)]);
            $failed[] = ['anchor' => (int) $r['anchor'], 'error' => $e->getMessage()];
        }
    }

    return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed];
}
```
Ein Angebot mit `send_error` bleibt bestehen (die Person gilt als angeschrieben — sonst würde die nächste Welle sie erneut treffen, obwohl im Chat ein „nicht zugestellt“ steht; der Reiter „Nicht zugestellt“ zeigt sie).

- [ ] **Step 4: Grün sehen** — Run beide Dateien. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobVorlagenWaechter.php src/Services/Zas/Dispo/Jobs/DispoJobOfferSender.php tests/Unit/Dispo/Jobs/DispoJobVorlagenWaechterTest.php tests/Integration/DispoJobOfferSenderTest.php
git commit -m "feat(recruiting): Jobs anbieten — Vorlagen-Waechter und Angebots-Sender

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Queue-Job je Welle

**Files:**
- Create: `src/Jobs/SendDispoJobWave.php`
- Test: `tests/Integration/SendDispoJobWaveJobTest.php`

**Interfaces:**
- Consumes: `DispoJobKandidaten::laden`, `DispoJobRecipientPlanner::plan`, `DispoJobOfferSender::send`, `RecDispoJobWave`.
- Produces: `SendDispoJobWave::__construct(int $waveId, int $teamId, ?int $userId)`, `::cacheKey(string $uuid)`, `::initialProgress(int)`, `::handle(Cache $cache, DispoJobOfferSender $sender)`. Fortschritt `{total,sent,failed,skipped,done,errors}` + `skipped_toepfe`.

- [ ] **Step 1: Failing test** (Capsule wie Task 4 + Vorlagen-Tabellen; Cache = `new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore())`; Sender als Attrappe, die `sent` zählt und Angebote selbst anlegt — oder der echte Sender mit Meta-Stub)

```php
public function test_welle_plant_beim_start_neu_sendet_und_schliesst(): void
{ /* 2 Kandidaten, einer am Tag gebucht -> 1 gesendet, skipped alle_tage_gebucht=1, wave.finished_at gesetzt, summary enthaelt Toepfe, request.status = laeuft */ }

public function test_geplante_welle_in_der_zukunft_sendet_nichts(): void
{ /* scheduled_for = +30min -> handle() returnt, started_at bleibt null, kein Sender-Aufruf */ }

public function test_geloeschte_welle_sendet_nichts(): void
{ /* waveId existiert nicht -> handle() returnt ohne Exception */ }

public function test_schon_gestartete_welle_laeuft_nicht_zweimal(): void
{ /* started_at gesetzt -> kein Sender-Aufruf */ }
```

- [ ] **Step 2: Scheitern sehen.** Expected: FAIL, Klasse fehlt.

- [ ] **Step 3: Implementieren** (Muster `SendNoAssignmentCampaign`: `ShouldQueue`, `$tries = 1`, `$timeout = 1800`, `failed()`/`markFailed()` identisch übernehmen)

```php
public function handle(Cache $cache, DispoJobOfferSender $sender): void
{
    $wave = RecDispoJobWave::query()->with('request')->find($this->waveId);
    if ($wave === null || $wave->started_at !== null) {
        return;
    }
    if ($wave->scheduled_for !== null && $wave->scheduled_for->isFuture()) {
        return; // abgebrochen/verschoben — der Disponent hat eine neue Welle geplant
    }
    $request = $wave->request;
    if ($request === null || $request->closed_at !== null) {
        return;
    }
    $wave->update(['started_at' => now(), 'job_uuid' => $wave->job_uuid ?: (string) \Symfony\Component\Uid\UuidV7::generate()]);
    $key = self::cacheKey($wave->job_uuid);

    $schichten = $request->shifts()->whereNull('closed_at')->whereDate('datum', '>=', now()->toDateString())->get()
        ->map(fn ($s) => ['id' => (int) $s->id, 'datum' => $s->datum->format('Y-m-d'), 'taetigkeit' => (string) $s->taetigkeit])->all();
    $tage = array_values(array_unique(array_column($schichten, 'datum')));
    $daten = DispoJobKandidaten::laden($this->teamId, $tage, now()->format('Y-m'));
    $schon = RecDispoJobOffer::query()->where('request_id', $request->id)->pluck('anchor_employee_id')->mapWithKeys(fn ($v) => [(int) $v => true])->all();
    $plan = DispoJobRecipientPlanner::plan($daten['kandidaten'], $schichten, $daten['einsaetze'], $daten['gebucht'], $schon,
        ['gruppen' => (array) $wave->gruppen, 'bloecke' => (array) $wave->ma_bloecke, 'nur_passend' => (bool) $wave->nur_passende_taetigkeit]);

    $progress = self::initialProgress(count($plan['recipients']));
    $progress['skipped'] = array_sum($plan['skipped']);
    $progress['skipped_toepfe'] = $plan['skipped'];
    $cache->put($key, $progress, self::CACHE_TTL_SECONDS);

    if ($request->status === RecDispoJobRequest::STATUS_ENTWURF) {
        $request->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
    }

    // Senden in Paketen, damit der Fortschritt waehrend des Laufs sichtbar ist.
    foreach (array_chunk($plan['recipients'], 10) as $paket) {
        if ($request->whatsapp_template_id === null) {
            $progress['failed'] += count($paket);
            $this->keepError($progress, 'keine Vorlage gewählt');
            continue;
        }
        $r = $sender->send($wave, $paket, (int) $request->whatsapp_template_id);
        if (!$r['ok']) {
            $progress['failed'] += count($paket);
            $this->keepError($progress, (string) $r['message']);
        } else {
            $progress['sent'] += $r['sent'];
            $progress['failed'] += count($r['failed']);
            foreach ($r['failed'] as $f) { $this->keepError($progress, 'MA ' . $f['anchor'] . ': ' . $f['error']); }
        }
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    }

    $progress['done'] = true;
    $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    $wave->update(['finished_at' => now(), 'summary' => $progress]);
}
```

- [ ] **Step 4: Grün sehen.** Expected: PASS, 4 Tests.

- [ ] **Step 5: Commit**

```bash
git add src/Jobs/SendDispoJobWave.php tests/Integration/SendDispoJobWaveJobTest.php
git commit -m "feat(recruiting): Jobs anbieten — Queue-Job je Welle (plant beim Start, Fortschritt im Cache)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: ZAS-Abgleich der Zusagen

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobZasAbgleich.php`
- Modify: `src/Services/Zas/Dispo/ZasDispoWebexportImporter.php` (nach der Transaktion, vor `return $summary`; in `try/catch`, Fehler in `$summary['jobangebote_fehler']`)
- Test: `tests/Integration/DispoJobZasAbgleichTest.php`

**Interfaces:**
- Produces: `DispoJobZasAbgleich::run(): int` (Anzahl neu gesetzter `zas_assignment_id`).

- [ ] **Step 1: Failing test**

```php
public function test_zusage_wird_mit_angekommener_einbuchung_verknuepft(): void
{
    // Angebot: Person 5 (Gruppe [5]), Schicht VA 1 am 24.10., accepted_at gesetzt
    // Einbuchung: VA 1, 24.10., rec_employee_id 5, status 1 -> zas_assignment_id = dessen id
    // Einbuchung an anderem Tag / anderer VA / status 3 / missing -> bleibt null
    // Nicht zugesagte Angebots-Schicht -> bleibt null
}
```

- [ ] **Step 2: Scheitern sehen.**

- [ ] **Step 3: Implementieren**

```php
public function run(): int
{
    $offen = RecDispoJobOfferShift::query()->with(['offer:id,rec_employee_id,anchor_employee_id', 'shift:id,rec_dispo_event_id,datum'])
        ->whereNotNull('accepted_at')->whereNull('zas_assignment_id')->get();
    if ($offen->isEmpty()) { return 0; }

    $employeeIds = $offen->pluck('offer.rec_employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    $groups = app(DispoIdentityResolver::class)->groupsFor($employeeIds);
    $gesetzt = 0;
    foreach ($offen as $os) {
        $ids = $groups[(int) $os->offer->rec_employee_id] ?? [(int) $os->offer->rec_employee_id];
        $a = RecDispoAssignment::query()->whereIn('rec_employee_id', $ids)
            ->where('rec_dispo_event_id', $os->shift->rec_dispo_event_id)
            ->whereDate('datum', $os->shift->datum->format('Y-m-d'))
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')->whereNull('deletion_marked_at')
            ->orderBy('id')->first(['id']);
        if ($a !== null) {
            $os->update(['zas_assignment_id' => (int) $a->id]);
            $gesetzt++;
        }
    }
    return $gesetzt;
}
```
Importer-Einbau (nach `DB::transaction(...)`, nur im Nicht-Dry-Run):
```php
try {
    $summary['jobangebote_verknuepft'] = app(\Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasAbgleich::class)->run();
} catch (\Throwable $e) {
    $summary['jobangebote_fehler'] = $e->getMessage();
    Log::warning('ZAS dispo import: Jobangebote-Abgleich fehlgeschlagen', ['error' => $e->getMessage()]);
}
```

- [ ] **Step 4: Grün sehen** + bestehende Importer-Tests: `… tests/Integration/ZasDispoWebexportImporter*`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobZasAbgleich.php src/Services/Zas/Dispo/ZasDispoWebexportImporter.php tests/Integration/DispoJobZasAbgleichTest.php
git commit -m "feat(recruiting): Jobs anbieten — Zusagen mit angekommenen ZAS-Einbuchungen verknuepfen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Mitarbeiter-Seite `/jobs/{token}`

**Files:**
- Create: `src/Livewire/Public/JobOffers.php`
- Create: `resources/views/livewire/public/job-offers.blade.php`
- Create: `resources/views/livewire/public/_crew-stil.blade.php` (Style-Block aus `employee-assignments.blade.php` Zeilen 5–115 **verschieben**; dort durch `@include('recruiting::livewire.public._crew-stil')` ersetzen)
- Modify: `routes/public.php` (nach der Einsatz-Seite)
- Test: `tests/Integration/JobOffersPageTest.php` (Logik über eine extrahierte, reine Klasse — siehe Step 3), `tests/Unit/Dispo/Jobs/JobOffersSichtbarkeitTest.php`

**Interfaces:**
- Consumes: `RecDispoJobOfferShift`, `DispoTagesPruefung::istEinsatz`, `DispoIdentityResolver::groupFor`.
- Produces: Route `recruiting.public.job-offers` (`/jobs/{token}`); `JobOffersSichtbarkeit::filtern(array $angebote, array $gebuchteTage, string $heute): list<...>` (rein) — ein Angebot ist sichtbar, wenn Anfrage läuft, Schicht offen, Tag ≥ heute, Tag nicht gebucht.
- Produces: `JobOffers::zusagen(int $offerShiftId)`: Transaktion; im Modus `zuerst` zählt sie `accepted_at` der Schicht gegen `gesucht` und schließt (`closed_at`) bei voll; (N+1)-te Zusage wird abgelehnt und die Seite zeigt „schon voll“.

- [ ] **Step 1: Failing tests**

Rein (`JobOffersSichtbarkeitTest`):
```php
public function testNurLaufendeOffeneFreieKuenftigeSchichten(): void
{
    $a = fn (array $x) => $x + ['offer_shift_id' => 1, 'datum' => '2026-10-24', 'laeuft' => true, 'closed' => false, 'accepted' => false];
    $rows = [
        $a(['offer_shift_id' => 1]),
        $a(['offer_shift_id' => 2, 'laeuft' => false]),
        $a(['offer_shift_id' => 3, 'closed' => true]),
        $a(['offer_shift_id' => 4, 'datum' => '2026-10-19']),
        $a(['offer_shift_id' => 5, 'datum' => '2026-10-25']),           // gebucht
        $a(['offer_shift_id' => 6, 'accepted' => true, 'closed' => true]), // eigene Zusage bleibt sichtbar
    ];
    $sichtbar = JobOffersSichtbarkeit::filtern($rows, ['2026-10-25' => true], '2026-10-20');
    $this->assertSame([1, 6], array_column($sichtbar, 'offer_shift_id'));
}
```
Integration (`JobOffersPageTest`, Capsule): `zusagen()` im Modus `zuerst` mit `gesucht = 1`: erste Zusage setzt `accepted_at` und `closed_at`; zweite Zusage (anderes Angebot, dieselbe Schicht) bleibt ohne `accepted_at`. Dazu: eigene Zusage ist idempotent. Die Livewire-Klasse ohne Container testen, indem die Logik in `JobOffersZusage::ausfuehren(int $offerShiftId, array $employeeIds): string` (`'ok'|'voll'|'weg'`) liegt und der Test diese Klasse aufruft.

- [ ] **Step 2: Scheitern sehen.**

- [ ] **Step 3: Implementieren**

`src/Services/Zas/Dispo/Jobs/JobOffersSichtbarkeit.php` (rein) und `JobOffersZusage.php` (DB, Transaktion):
```php
public static function ausfuehren(int $offerShiftId, array $employeeIds): string
{
    return DB::transaction(function () use ($offerShiftId, $employeeIds) {
        $os = RecDispoJobOfferShift::query()->lockForUpdate()->with(['offer', 'shift.request'])->find($offerShiftId);
        if ($os === null || !in_array((int) $os->offer->rec_employee_id, $employeeIds, true)) { return 'weg'; }
        if ($os->accepted_at !== null) { return 'ok'; }
        $shift = $os->shift; $request = $shift->request;
        if (!$request->laeuft() || $shift->closed_at !== null || $shift->datum->lt(now()->startOfDay())) { return $shift->closed_at !== null ? 'voll' : 'weg'; }
        if ($request->modus === RecDispoJobRequest::MODUS_ZUERST) {
            $zusagen = RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->lockForUpdate()->count();
            if ($shift->gesucht > 0 && $zusagen >= $shift->gesucht) { $shift->update(['closed_at' => now()]); return 'voll'; }
            if ($shift->gesucht > 0 && $zusagen + 1 >= $shift->gesucht) { $shift->update(['closed_at' => now()]); }
        }
        $os->update(['accepted_at' => now()]);
        return 'ok';
    });
}
```
SQLite kennt `lockForUpdate` nicht als Sperre, akzeptiert es aber; im Test genügt die Zählung.

`Public\JobOffers` (Muster `EmployeeAssignments`: `#[Locked] token/employeeId/employeeIds/firstName/tokenInvalid/portalLocked`, `mount()` identisch inkl. Sperre, **ohne** `portal_last_seen_at`-Stempel). `#[Computed] angebote()`: lädt `RecDispoJobOfferShift` mit `offer` (rec_employee_id in Gruppe), `shift.event`, `shift.request`; gebuchte Tage via `DispoTagesPruefung::gebuchteTage` über die Einsätze der Gruppe an den Schicht-Tagen; dann `JobOffersSichtbarkeit::filtern`; Gruppierung nach VA → Tag → Schichten; je Schicht `frei = gesucht - zusagen` (Modus `zuerst`). `zusagen(int $id)` ruft `JobOffersZusage::ausfuehren`, setzt `$this->hinweis` („Danke! Du bekommst eine Bestätigung, sobald du eingeteilt bist.“ / „Diese Schicht ist schon voll — danke fürs Melden.“) und `unset($this->angebote)`.

View `job-offers.blade.php`: `<div class="rg-crew">` + `@include('recruiting::livewire.public._crew-stil')`, Appbar wie die Einsatz-Seite, Gruß „Hallo {{ $firstName }}“, Erklärzeile „Hier stehen Einsätze, für die wir noch Leute suchen — nur an Tagen, an denen du frei bist.“ Je VA eine `.evcard` mit Name, Ort; je Tag Datum; je Schicht Zeile „17:00 · Zapfer“ + Knopf **„Ich bin dabei“** (`wire:click="zusagen({{ $id }})"`), nach Zusage grüner Haken „Du bist dabei — wir melden uns.“; Modus `zuerst`: „noch {{ $frei }} frei“, bei voll ohne Knopf „schon voll — danke fürs Melden“. Leerzustand: „Gerade gibt es keine offenen Einsätze für dich.“ Sperrzustand und ungültiger Token wie die Einsatz-Seite (Texte übernehmen).

Route:
```php
// Jobangebote (Spec 2026-10-12): token-only wie die Einsatz-Seite, Token als URL-Suffix.
Route::get('/jobs/{token}', \Platform\Recruiting\Livewire\Public\JobOffers::class)
    ->name('recruiting.public.job-offers');
```

- [ ] **Step 4: Grün sehen** + `php tools/blade-check.php resources/views/livewire/public/job-offers.blade.php resources/views/livewire/public/employee-assignments.blade.php`. Expected: PASS; Blade ohne Fehler.

- [ ] **Step 5: Commit**

```bash
git add routes/public.php src/Livewire/Public/JobOffers.php src/Services/Zas/Dispo/Jobs/JobOffers*.php resources/views/livewire/public tests/Integration/JobOffersPageTest.php tests/Unit/Dispo/Jobs/JobOffersSichtbarkeitTest.php
git commit -m "feat(recruiting): Jobs anbieten — Mitarbeiter-Seite /jobs/{token} (nur freie Tage, Ich bin dabei, Modus zuerst)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Einstellungen, Routen, Gate, Sidebar, VA-Knopf

**Files:**
- Modify: `src/Livewire/Dispo/Settings.php` (`public array $jobOfferTemplateIds = []`; laden/speichern unter Key `dispo_job_offer_template_ids` als `list<int>`)
- Modify: `resources/views/livewire/dispo/settings.blade.php` (Mehrfachauswahl „Vorlagen für Jobangebote“, Hinweistext: „Für Disposition → Jobs anbieten. Text nur mit Vorname, Link-Button führt auf die Jobseite des Mitarbeiters.“)
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobTemplateSettings.php` (rein: `normalisieren`, `lesen`; Konstante `KEY = 'dispo_job_offer_template_ids'`)
- Create: `src/Livewire/Dispo/Jobs/Index.php`, `Create.php`, `Show.php` als Platzhalter (siehe Interfaces) + `resources/views/livewire/dispo/jobs/_platzhalter.blade.php`
- Modify: `src/Services/Zas/Dispo/DispoTemplateLabels.php` (`forTeam`: zusätzlich alle IDs aus `dispo_job_offer_template_ids` → Label „Jobangebot“)
- Modify: `routes/web.php` (drei Routen), `resources/views/livewire/sidebar.blade.php` (Eintrag „Jobs anbieten“, Icon `heroicon-o-megaphone`, nur im vollen Zweig, direkt nach „Veranstaltungen“)
- Modify: `resources/views/livewire/dispo/events/show.blade.php` (Knopf „Jobs anbieten“ neben „Info an Crew“, nur `@if (!$eventOnly)`: `<a href="{{ route('recruiting.dispo.jobs.create', ['event' => $this->eventId]) }}" class="rounded bg-white px-3 py-1.5 text-sm font-medium text-blue-700 ring-1 ring-blue-200 hover:bg-blue-50">Jobs anbieten</a>`)
- Test: `tests/Unit/RecruitingRouteGateTest.php` (+2 Tests), `tests/Unit/Dispo/Jobs/JobOfferTemplateSettingsTest.php` (reine Normalisierung)

**Interfaces:**
- Produces: Settings-Key `dispo_job_offer_template_ids` (list<int>), reine Hilfe `DispoJobTemplateSettings::normalisieren(array $raw): list<int>` (nur Ziffern, >0, dedupliziert, sortiert) und `::lesen(RecApplicantSettings $s): list<int>`.
- Produces: Routen `recruiting.dispo.jobs.index` (`/dispo-jobs`), `.create` (`/dispo-jobs/neu`), `.show` (`/dispo-jobs/{requestId}`, `whereNumber`). Livewire-Klassen entstehen in Task 10; in diesem Task zeigen die Routen bereits auf `\Platform\Recruiting\Livewire\Dispo\Jobs\Index|Create|Show` — die Klassen werden als **leere Komponenten mit `render()` auf eine Platzhalter-View** angelegt, damit die Route-Registrierung nicht bricht (Task 10 füllt sie).

- [ ] **Step 1: Failing tests**

```php
// RecruitingRouteGateTest
public function test_nur_veranstaltungen_kommt_nicht_an_jobs_anbieten(): void
{
    $this->assertSame('recruiting.dispo.events.index', RecruitingRouteGate::redirectTo(eventOnly: true, trainingLeader: false, routeName: 'recruiting.dispo.jobs.index'));
    $this->assertSame('recruiting.dispo.events.index', RecruitingRouteGate::redirectTo(eventOnly: true, trainingLeader: false, routeName: 'recruiting.dispo.jobs.create'));
}
public function test_normaler_nutzer_darf_jobs_anbieten(): void
{
    $this->assertNull(RecruitingRouteGate::redirectTo(eventOnly: false, trainingLeader: false, routeName: 'recruiting.dispo.jobs.show'));
}
// JobOfferTemplateSettingsTest
public function testNormalisiert(): void
{
    $this->assertSame([3, 12], DispoJobTemplateSettings::normalisieren(['12', '3', 'x', '0', '', 12, null]));
}
```

- [ ] **Step 2: Scheitern sehen** (Gate-Tests passieren vermutlich schon — dann ist das die Bestätigung, dass die Positivliste greift; der Settings-Test scheitert).

- [ ] **Step 3: Implementieren** (Settings: `<select multiple wire:model="jobOfferTemplateIds" size="5">` mit `$this->templates`; in `save()`: `$settings->setSetting('dispo_job_offer_template_ids', DispoJobTemplateSettings::normalisieren($this->jobOfferTemplateIds))`; in `mount()`: `$this->jobOfferTemplateIds = array_map('strval', DispoJobTemplateSettings::lesen($settings))`.)

- [ ] **Step 4: Grün sehen** + `php tools/blade-check.php` über `settings.blade.php`, `sidebar.blade.php`, `events/show.blade.php`. Ganze Unit-Suite: `… tests/Unit`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A src/Livewire/Dispo src/Services/Zas/Dispo routes/web.php resources/views/livewire tests/Unit
git commit -m "feat(recruiting): Jobs anbieten — Vorlagen-Einstellung, Routen, Sidebar, Knopf auf der VA-Seite

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Disponenten-Seiten — Übersicht, Anfrage anlegen (5 Schritte), Anfrage-Seite (Wellen, Rückmeldungen)

**Files:**
- Create: `src/Livewire/Dispo/Jobs/Index.php`, `Create.php`, `Show.php` (die Platzhalter aus Task 9 ersetzen)
- Create: `resources/views/livewire/dispo/jobs/index.blade.php`, `create.blade.php`, `show.blade.php`
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobSchichten.php` (rein: aus Einbuchungen einer VA die Schicht-Optionen je Tag: `optionen(iterable $assignments, array $tage): array<Y-m-d, list<array{von:string, taetigkeit:string, zeilen:int, platzhalter:int}>>`; `platzhalter` = Zeilen mit `pnr_raw` in `DispoQualifikationExtractor::PLATZHALTER_PNR` — nur Anzeige „laut ZAS N unbesetzt“)
- Test: `tests/Unit/Dispo/Jobs/DispoJobSchichtenTest.php`, `tests/Integration/DispoJobCreateFlowTest.php` (die reinen Zustandsübergänge in `DispoJobCreateState` testen, s. u.)

**Interfaces:**
- Consumes: alles Vorige; `Filialen::options`, `RecDispoEvent`, `CampaignTemplatePreview::render` (Vorschau), `DispoJobVorlagenWaechter::problem`, `DispoJobTemplateSettings::lesen`, `DispoJobKandidaten`, `DispoJobRecipientPlanner` (für die Zahlen vor dem Senden), `SendDispoJobWave`.
- Produces: `DispoJobCreateState` (rein): `schritte()`, `kannWeiter(int $schritt, array $state): ?string` (Fehlermeldung oder null) — Regeln: Schritt 1 Filiale gesetzt; 2 Zeitraum gültig, `von >= heute`, `bis >= von`; 3 mindestens eine VA; 4 mindestens eine Schicht mit `gesucht > 0`; 5 Vorlage gewählt und `problem === null`.

- [ ] **Step 1: Failing tests** — `DispoJobSchichtenTest` (Optionen je Tag, Platzhalter zählen, verschwundene Zeilen nicht), `DispoJobCreateStateTest` (jede Regel einzeln, Mutationsprobe: `gesucht = 0` überall → Fehler).

- [ ] **Step 2: Scheitern sehen.**

- [ ] **Step 3: Implementieren**

`Create.php` — `#[Url] public ?int $event = null;` → `mount()`: wenn gesetzt und VA existiert: `filialNr = event.filial_nr`, Zeitraum = `max(heute+1, starts_on)` bis `ends_on ?? starts_on`, `eventIds = [event]`, `schritt = 4`. Sonst Schritt 1, Filiale aus `session('dispo_jobs_filiale')`, Zeitraum morgen bis +28 Tage. Properties: `int $schritt`, `string $filialNr`, `string $von`, `string $bis`, `array $eventIds`, `array $tage` (event_id => list<Y-m-d>), `array $gesucht` (key `"{event}|{datum}|{von}|{taetigkeit}"` => int), `array $andere` (freie Schichten: `{event, datum, von, taetigkeit, gesucht}`), `string $modus`, `string $templateId`, `array $maBloecke`, `array $gruppen`, `bool $nurPassend = true`, `string $startModus = 'jetzt'`, `int $minuten = 30`.
`#[Computed]`: `filialOptionen()` (vorkommende `filial_nr` wie `Events\Index::filialeOptions`), `events()` (Query wie `Events\Index` mit Filiale + Zeitraum, ohne verschwundene Zeilen), `schichtOptionen()` (je gewählter VA: `DispoJobSchichten::optionen`), `blockOptionen()` (`DispoJobKandidaten::blockOptionen`; Vorbelegung `maBloecke = [filialNr]` beim Eintritt in Schritt 5, wenn der Block vorkommt), `vorlagen()` (IDs aus Settings → `IntegrationsWhatsAppTemplate`), `vorschau()` (`CampaignTemplatePreview::render(components)['text']`), `vorlagenProblem()` (`DispoJobVorlagenWaechter::problem`), `zahlen()` (Kandidaten laden + Planer mit `schonAngeschrieben = []` → `['empfaenger' => n, 'toepfe' => skipped, 'namen' => …]`).
`weiter()`/`zurueck()` über `DispoJobCreateState::kannWeiter`. `anlegenUndSenden()`: Transaktion → `RecDispoJobRequest` (status `entwurf`), Schichten (aus `gesucht` > 0 und `andere`), Welle 1 (`scheduled_for = startModus === 'spaeter' ? now()->addMinutes($minuten) : null`), dann `SendDispoJobWave::dispatch($wave->id, $teamId, $userId)` mit `->delay($wave->scheduled_for)` falls gesetzt; `session(['dispo_jobs_filiale' => $filialNr])`; redirect `recruiting.dispo.jobs.show`.
Team-ID wie `Settings::teamId()`.

`Show.php` — `mount(int $requestId)`: Anfrage team-gescopt laden, sonst 404. Abschnitte: Kopf (Filiale, Zeitraum, Modus, Vorlage, Status, „Anfrage abschließen“ mit `wire:confirm`), Schichten mit Zählern `gesucht · zugesagt · in ZAS eingetragen · angekommen`, Wellen-Liste (nr, Gruppen-Labels, Blöcke-Labels, geplant/gestartet/fertig, Zusammenfassung mit Töpfen; laufende Welle `wire:poll.3s` über `Cache::get(SendDispoJobWave::cacheKey(job_uuid))`; geplante Welle: „Abbrechen“ = Welle löschen, wenn `started_at === null`), „Neue Welle“ (Gruppen, Blöcke, Haken, Startmodus — gleiche Vorschau der Zahlen wie Schritt 5, aber mit `schonAngeschrieben` aus den Angeboten), Rückmeldungen je Schicht (Name → MA-Akte, Zusage-Zeit, Nummer, Haken „in ZAS eingetragen“ `wire:click="zasHaken(id)"`, Badge „✓ in ZAS angekommen“ wenn `zas_assignment_id`).
`neueWelle()` legt `RecDispoJobWave` mit `nr = max+1` an und dispatcht wie oben. `zasHaken(int $offerShiftId)` toggelt `zas_entered_at`/`zas_entered_by_user_id` (team-gescopt über die Anfrage). `abschliessen()` setzt `status`/`closed_at`.

`Index.php` — Liste der Anfragen des Teams (neueste zuerst): Filiale, Zeitraum, Schichten-Zahl, Status, Zusagen gesamt, letzte Welle; Knopf „Neue Anfrage“. Anfragen, deren letzter Schicht-Tag vorbei ist und die noch laufen, werden beim Laden der Liste automatisch abgeschlossen (`closed_at = now()`, Status `abgeschlossen`).

Views im Stil der Dispo-Seiten (`p-6 space-y-6`, Karten `rounded-lg border border-gray-200 bg-white p-4`, Pillen wie die Statusfilter der VA-Seite). Schritte als Leiste „1 Filiale · 2 Zeitraum · 3 Veranstaltungen · 4 Schichten · 5 Empfänger“. Schritt 4: je VA Tages-Pillen, darunter Tabelle „Beginn | Tätigkeit | Zeilen | laut ZAS unbesetzt | gesucht“ mit Zahlenfeld; „Andere Schicht hinzufügen“ (Beginn, Tätigkeit aus Lookup `dispo_taetigkeit` des Teams, gesucht). Schritt 5: Blöcke als Haken (Label + Untertitel Nummern + Anzahl), Gruppen als Haken (Labels `DispoEinsatzZaehler::BAENDER`), Haken „nur mit passender Tätigkeit“ mit Zahl, Vorlage als Auswahl + Sprechblase (Muster `no-assignment-campaign.blade.php`), Modus als zwei Radios mit je einer Erklärzeile, Zahlen-Kasten „Sendet an N Personen“ + Töpfe aufklappbar (`<details>`), Start „Jetzt senden“ / „In [30] Minuten senden“, Knopf „Anfrage anlegen und Welle 1 starten“ mit `wire:confirm`.

- [ ] **Step 4: Grün sehen** — Unit + die neuen Integrationstests; `php tools/blade-check.php` über die drei Views; danach **gesamte Suite**: `… -c phpunit.xml`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Dispo/Jobs src/Services/Zas/Dispo/Jobs resources/views/livewire/dispo/jobs tests
git commit -m "feat(recruiting): Jobs anbieten — Uebersicht, Anfrage in fuenf Schritten, Wellen und Rueckmeldungen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Anleitung und Deploy-Notiz

**Files:**
- Create: `docs/anleitungen/dispo-jobs-anbieten.md` (Format aus `docs/anleitungen/README.md`; `bereich: Dispo`, `seiten: [recruiting.dispo.jobs.index, recruiting.dispo.jobs.create, recruiting.dispo.jobs.show]`; Folien: „Wann hilft das?“, „Anfrage anlegen in fünf Schritten“, „Wellen“, „Was der Mitarbeiter sieht“ (Bild `manuell`), „Zusagen abhaken“, „Gut zu wissen“ (Tabelle: Zusage ist keine Einbuchung; eine Nachricht je Person und Anfrage; nur freie Tage; Vorlagen in den Einstellungen))
- Modify: `resources/views/livewire/dispo/jobs/index.blade.php` (das „?“: `@include('recruiting::anleitungen._hilfe', ['route' => 'recruiting.dispo.jobs.index'])` neben der Überschrift)
- Test: `tests/Unit/Anleitungen/AnleitungenInhaltTest.php` läuft über alle Dateien — muss grün bleiben.

- [ ] **Step 1:** Anleitung schreiben, Bilder mit Rezepten (`<!-- shot: seite=/recruiting/dispo-jobs/neu ... -->`), noch ohne PNGs.
- [ ] **Step 2:** Run `… tests/Unit/Anleitungen`. Expected: PASS.
- [ ] **Step 3: Commit**

```bash
git add docs/anleitungen/dispo-jobs-anbieten.md resources/views/livewire/dispo/jobs/index.blade.php
git commit -m "docs(recruiting): Anleitung „Jobs anbieten“

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Auslieferung (nach Merge + Bump)

`migrate` (eine Migration, fünf Tabellen) · `queue:restart` (neuer Job) · `view:clear`. Dann drei Meta-Vorlagen anlegen (URL-Button `https://mitarbeiter.rheingedeck.de/recruiting/jobs/{{1}}` — den genauen Host-Pfad aus der Einsatz-Seiten-Vorlage übernehmen), in Dispo-Einstellungen wählen, Testanfrage gegen eine Test-VA mit der eigenen Nummer.
