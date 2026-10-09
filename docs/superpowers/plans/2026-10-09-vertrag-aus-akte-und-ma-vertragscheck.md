# Vertrag aus der Akte + MA-Vertragscheck Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR legt aus der Mitarbeiterakte einen Arbeitsvertrag an genau dieser Anstellung an (auch ohne Bewerbung), der Mitarbeiter bekommt einen WhatsApp-Hinweis und unterschreibt im neuen Portal; die stündliche Einsatz-Prüfung öffnet einen HR-Fall, wenn eine MA-Buchung keinen unterschriebenen Arbeitsvertrag hat, und schließt ihn, sobald einer unterschrieben ist.

**Architecture:** Reine Regeln (`VertragsDeckung`, `VertragsVorbelegung`, `ZuschlagWert`) in `src/Support`, ein Datenlader (`VertragsZeilen`), ein Anlage-Service (`VertragAusAkte`), ein Hinweis-Sender als Unterklasse des Dokument-Senders, ein Portal-Leser (`VertragLeser`) als dritte Quelle von `OffenePunkte`, eine Prüf-Klasse (`VertragsPruefung`), die `EinsatzPruefung` vor ihrem `$punkte === []`-Kurzschluss ruft. Die Personalisierung bekommt eine zweite Datenquelle (Anstellung) über einen gemeinsamen privaten Auflöser; der Bewerbungsweg bleibt byteidentisch.

**Tech Stack:** PHP 8.4, Laravel 12.69, Livewire 3.8, PHPUnit 11 (Capsule + SQLite, kein testbench), DomPDF.

**Spec:** `docs/superpowers/specs/2026-10-09-vertrag-aus-akte-und-ma-vertragscheck-design.md`

## Global Constraints

- Arbeitsverzeichnis: `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting-portal`, Branch `feat/ma-konto`. Ein Commit je Task, kein Push.
- Tests laufen IMMER über `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (aus dem Modulverzeichnis; einzeln mit `--filter`). Nie `--order-by=random` (siehe Kommentar in `phpunit.xml`).
- Jede angefasste Blade wird mit `php tools/blade-check.php <datei>` geprüft. `php -l` auf `.blade.php` prüft nichts.
- Keine Edits außerhalb dieses Moduls (platforms-core, CRM, HCM, meingedeck) — vorher fragen.
- Keine neue Tabelle, keine neue Spalte; `MassenzuweisungGeschlosseneWeltTest` bleibt grün. **Einzige Ausnahme, und sie ist Pflicht:** die Migration `2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts` (Task 2). `rec_contracts.rec_applicant_id` ist seit `2026_04_15_100000` `NOT NULL` mit Fremdschlüssel — ohne diese Änderung lässt sich ein Vertrag für eine Anstellung ohne Bewerbung nicht speichern (Spec §4 sagt „keine Migration"; das ist dort übersehen und wird in Task 9 nachgezogen). Deploy braucht damit `migrate`.
- Nie `wire:model` auf ein Feld mit `datetime`/`date`-Cast binden; Datumsfelder im Livewire-Zustand sind `?string`-Properties im Format `Y-m-d` (geleertes `type=date` schickt `null`).
- Blade-Hausregeln: kein Inline-`@if` in Komponenten-Attributen (vorberechnen), keine an Wortzeichen geklebten Direktiven (`da@if` kompiliert nicht), `@php … @endphp` nur in Block-Form; in `applicant-settings-modal.blade.php` die Wörter der PHP-Block-Direktiven auch nicht in Blade-Kommentaren schreiben (BladeCompileIntegrityTest).
- UI-Texte deutsch mit Umlauten. Mitarbeiter-sichtbare Texte (Portal, Unterschriftsseite) über das bestehende `$duzen`-Muster.
- Schreibvorgänge an `rec_employees` in Massenpfaden (Einsatz-Prüfung) nur über den Query Builder; diese Pläne schreiben dort gar nicht. HR-Fälle (`rec_hr_desk_cases`) und Verträge werden per Eloquent angelegt (kein ZAS-Marker dran).
- Vertrags-Extrafeld `zuschlag` hat Typ `text` und speichert das deutsche Format `"0,60"` (wie `ReissueContractService::createSuccessor()` es schon schreibt; den Typ `decimal` gibt es im Core nicht, `number` würde `"0,60"` still verwerfen).
- Commit-Nachrichten enden mit der Zeile `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Alt-AV ohne Laufzeit-Extrafelder** (unterschrieben vor den Feldern `vertragsbeginn`/`vertragsende`) muss als deckend gelten — sonst bekommt der ganze Bestand HR-Fälle. Gepinnt: Task 1 `VertragsDeckungTest` Fall „Altvertrag ohne Laufzeit deckt", Task 8 `test_altvertrag_ohne_laufzeit_erzeugt_keinen_fall`.
2. **MA-Buchung mit gekürzter Personalnummer** (`pnr_raw = 'MA878'`, Akte `MA1000000878`, Firma an der Akte leer) muss als MA erkannt und an der Akte geprüft werden — die Firma kommt aus dem Präfix, nicht aus einem Nummernvergleich. Gepinnt: Task 8 `test_gekuerzte_ma_nummer_ohne_firma_an_der_akte`.
3. **Person mit RG- und MA-Anstellung, Buchung hängt an der RG-Zeile** (`pnr_raw = 'MA353'`): der unterschriebene RG-AV darf nicht decken; der Fall gehört an die MA-Zeile. Gepinnt: Task 8 `test_buchung_an_der_rg_zeile_prueft_die_ma_anstellung` (Zielzeile) und `test_rg_av_deckt_keinen_ma_einsatz` (Firmenfilter).
4. **Zuschlag „0,60" mit Komma**: Eingabe, Speicherung, Vertragstext und Liste müssen `0,60` zeigen, nicht `0` oder leer. Gepinnt: Task 1 `ZuschlagWertTest`, Task 4 `test_legt_vertrag_an_der_anstellung_an_…`, Task 7 `test_erstellen_mit_komma_zuschlag_landet_in_der_liste`.
5. **Portal zeigt Verträge einer fremden Person / unbeschriftet die der anderen Anstellung**: fremde Person nie (auch nicht per PDF-Link), Schwester-Anstellung nur mit Gesellschaft im Namen. Gepinnt: Task 6 `test_schwester_anstellung_sieht_den_vertrag_mit_gesellschaft_fremde_person_nie`, `test_pdf_zugriff_nur_mit_sitzung_der_person`.

---

## Datei-Landkarte

| Datei | Verantwortung | Task |
|---|---|---|
| `src/Support/VertragsDeckung.php` (neu) | Deckt ein AV einen Tag? Überschneidung bei Neuanlage. Datum normalisieren. | 1 |
| `src/Support/VertragsVorbelegung.php` (neu) | Monatserster/-letzter, Fall-Notiz bauen und lesen | 1 |
| `src/Support/ZuschlagWert.php` (neu) | Zuschlag lesen/formatieren, Eingabe prüfen, Alt-Code `AV-060` | 1 |
| `database/migrations/2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts.php` (neu) | `rec_applicant_id` nullable | 2 |
| `src/Models/RecContractTemplate.php` | `personalizeFuerAnstellung()`, gemeinsamer Auflöser | 2 |
| `tests/Integration/VertragAusAkteHarness.php` (neu) | gemeinsame Welt (echte Migrationen) für Tasks 2, 4–8 | 2 |
| `src/Services/Comms/DokumentHinweisSender.php` | Schlüssel über `vorlagenSchluessel()` | 3 |
| `src/Services/Comms/VertragHinweisSender.php` (neu) | eigener Schlüssel, Rückfall auf Dokument-Template | 3 |
| `src/Models/RecApplicantSettings.php` | Defaults `employee_contract_wa_template_id`, `contract_check_companies` | 3, 8 |
| `src/Services/VertragsAngaben.php`, `VertragsZeilen.php`, `VertragAusAkte.php` (neu) | Anlage aus der Akte | 4 |
| `src/Console/Commands/SeedRecContractExtraFields.php` | Feld `zuschlag` | 4 |
| `src/Models/RecContract.php` | `anstellung()`, saved-Hook ohne Bewerbung | 5 |
| `src/Livewire/Public/ContractSigning.php` | Anstellung statt `applicant->employee`, Portal-Link | 5 |
| `src/Services/VertragLeser.php` (neu), `src/Services/OffenePunkte.php` | Verträge der Person, offene Punkte | 6 |
| `src/Livewire/Public/PortalShell.php` + Blade | Vertragsliste über die Person, Klick auf `vertrag:` | 6 |
| `src/Http/Controllers/VertragPdfController.php` (neu), `routes/public.php`, `routes/web.php` | PDF Portal (Sitzung) und HR (Team) | 6, 7 |
| `src/Livewire/Employees/Show.php` + Blade | Fenster „Vertrag erstellen", Listen-Spalten, Stornieren | 7 |
| `src/Services/VertragsPruefung.php` (neu), `src/Console/Commands/EinsatzPruefung.php`, `src/Models/RecHrDeskCase.php`, HR-Schreibtisch-Blade, Einstellungs-Blade | MA-Vertragscheck | 8 |

---

### Task 1: Reine Regeln — VertragsDeckung, VertragsVorbelegung, ZuschlagWert

**Files:**
- Create: `src/Support/VertragsDeckung.php`
- Create: `src/Support/VertragsVorbelegung.php`
- Create: `src/Support/ZuschlagWert.php`
- Test: `tests/Unit/VertragsDeckungTest.php`, `tests/Unit/VertragsVorbelegungTest.php`, `tests/Unit/ZuschlagWertTest.php`

**Interfaces:**
- Consumes: `Platform\Recruiting\Support\YmdDate::isValid(string): bool`
- Produces:
  - `VertragsDeckung::UNTERSCHRIEBEN|UNTERWEGS|KEINER` (`'unterschrieben'|'unterwegs'|'keiner'`)
  - `VertragsDeckung::amTag(array $vertraege, string $tag): array{deckung:string, vertrag_id:?int}` — `$vertraege` = `list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}>`
  - `VertragsDeckung::ueberschneidung(array $vertraege, string $tag): ?array{id:int, ende:?string}`
  - `VertragsDeckung::istAv(?string $code): bool`
  - `VertragsDeckung::datum(mixed $wert): ?string` (Y-m-d oder null; akzeptiert `Y-m-d…`, `d.m.Y`, `DateTimeInterface`)
  - `VertragsVorbelegung::fuerEinsatz(string $einsatztag): ?array{beginn:string, ende:string}`
  - `VertragsVorbelegung::notiz(string $einsatztag, ?string $event, ?string $taetigkeit, string $firma): string`
  - `VertragsVorbelegung::einsatztagAusNotiz(?string $notiz): ?string`
  - `ZuschlagWert::ausEingabe(?string $roh): ?float`, `ZuschlagWert::lesen(mixed $wert): ?float`, `ZuschlagWert::format(float $zuschlag): string`, `ZuschlagWert::ausAvCode(?string $code): ?float`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/VertragsDeckungTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VertragsDeckung;

/**
 * Spec Vertrag aus der Akte §3.1 — wann deckt ein Arbeitsvertrag EINER
 * Anstellung einen Einsatztag. Review-Focus 1: der Altbestand ohne
 * Laufzeitfelder darf keinen HR-Fall erzeugen.
 */
final class VertragsDeckungTest extends TestCase
{
    private static function av(int $id, string $status, ?string $beginn, ?string $ende, bool $ersetzt = false, string $code = 'AV-MA-LOG'): array
    {
        return [
            'id'         => $id,
            'code'       => $code,
            'status'     => $status,
            'signed_at'  => $status === 'completed' ? '2026-09-30 12:00:00' : null,
            'superseded' => $ersetzt,
            'beginn'     => $beginn,
            'ende'       => $ende,
        ];
    }

    public static function faelle(): array
    {
        $oktober = fn (int $id, string $status = 'completed') => self::av($id, $status, '2026-10-01', '2026-10-31');

        return [
            'unterschrieben, Tag mitten drin'     => [[$oktober(1)], '2026-10-15', 'unterschrieben', 1],
            'Grenztag Beginn'                     => [[$oktober(1)], '2026-10-01', 'unterschrieben', 1],
            'Grenztag Ende'                       => [[$oktober(1)], '2026-10-31', 'unterschrieben', 1],
            'Tag nach dem Ende'                   => [[$oktober(1)], '2026-11-01', 'keiner', null],
            'Tag vor dem Beginn'                  => [[$oktober(1)], '2026-09-30', 'keiner', null],
            'Altvertrag ohne Laufzeit deckt'      => [[self::av(2, 'completed', null, null)], '2027-03-01', 'unterschrieben', 2],
            'nur Beginn, offenes Ende'            => [[self::av(3, 'completed', '2026-01-01', null)], '2030-01-01', 'unterschrieben', 3],
            'deutsches Datumsformat'              => [[self::av(4, 'completed', '01.10.2026', '31.10.2026')], '2026-10-31', 'unterschrieben', 4],
            'ersetzter Vertrag deckt nicht'       => [[self::av(5, 'completed', '2026-10-01', '2026-10-31', true)], '2026-10-15', 'keiner', null],
            'storniert deckt nicht'               => [[$oktober(6, 'cancelled')], '2026-10-15', 'keiner', null],
            'versendet ist unterwegs'             => [[$oktober(7, 'sent')], '2026-10-15', 'unterwegs', 7],
            'versendet ohne Laufzeit ist unterwegs' => [[self::av(8, 'sent', null, null)], '2026-10-15', 'unterwegs', 8],
            'versendet ausserhalb ist keiner'     => [[$oktober(9, 'sent')], '2026-11-15', 'keiner', null],
            'pending zaehlt nicht'                => [[$oktober(10, 'pending')], '2026-10-15', 'keiner', null],
            'unterschrieben sticht unterwegs'     => [[$oktober(11, 'sent'), $oktober(12)], '2026-10-15', 'unterschrieben', 12],
            'IFSG ist kein Arbeitsvertrag'        => [[self::av(13, 'completed', null, null, false, 'IFSG')], '2026-10-15', 'keiner', null],
            'blanker Altcode AV zaehlt'           => [[self::av(14, 'completed', null, null, false, 'AV')], '2026-10-15', 'unterschrieben', 14],
            'completed ohne signed_at deckt nicht' => [[['id' => 15, 'code' => 'AV-1', 'status' => 'completed', 'signed_at' => null, 'superseded' => false, 'beginn' => null, 'ende' => null]], '2026-10-15', 'keiner', null],
            'leere Liste'                         => [[], '2026-10-15', 'keiner', null],
        ];
    }

    #[DataProvider('faelle')]
    public function test_deckung_am_tag(array $vertraege, string $tag, string $deckung, ?int $id): void
    {
        $this->assertSame(['deckung' => $deckung, 'vertrag_id' => $id], VertragsDeckung::amTag($vertraege, $tag));
    }

    public function test_ueberschneidung_sieht_jeden_nicht_stornierten_av(): void
    {
        $pending = self::av(1, 'pending', '2026-10-01', '2026-10-31');
        $this->assertSame(['id' => 1, 'ende' => '2026-10-31'], VertragsDeckung::ueberschneidung([$pending], '2026-10-15'));
        $this->assertNull(VertragsDeckung::ueberschneidung([$pending], '2026-11-01'), 'Folgemonat ist frei');

        $alt = self::av(2, 'completed', null, null);
        $this->assertSame(['id' => 2, 'ende' => null], VertragsDeckung::ueberschneidung([$alt], '2026-10-15'), 'unbefristet blockiert');

        $this->assertNull(VertragsDeckung::ueberschneidung([self::av(3, 'cancelled', null, null)], '2026-10-15'));
        $this->assertNull(VertragsDeckung::ueberschneidung([self::av(4, 'completed', null, null, true)], '2026-10-15'));
        $this->assertNull(VertragsDeckung::ueberschneidung([self::av(5, 'completed', null, null, false, 'IFSG')], '2026-10-15'));
    }

    public function test_datum_normalisiert(): void
    {
        $this->assertSame('2026-10-01', VertragsDeckung::datum('2026-10-01'));
        $this->assertSame('2026-10-01', VertragsDeckung::datum('2026-10-01 00:00:00'));
        $this->assertSame('2026-10-01', VertragsDeckung::datum('01.10.2026'));
        $this->assertSame('2026-10-01', VertragsDeckung::datum(new \DateTimeImmutable('2026-10-01 13:00:00')));
        $this->assertNull(VertragsDeckung::datum('2026-02-30'));
        $this->assertNull(VertragsDeckung::datum(''));
        $this->assertNull(VertragsDeckung::datum(null));
        $this->assertNull(VertragsDeckung::datum('demnaechst'));
    }
}
```

`tests/Unit/VertragsVorbelegungTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VertragsVorbelegung;

final class VertragsVorbelegungTest extends TestCase
{
    public function test_monatserster_und_monatsletzter(): void
    {
        $this->assertSame(['beginn' => '2026-10-01', 'ende' => '2026-10-31'], VertragsVorbelegung::fuerEinsatz('2026-10-20'));
        $this->assertSame(['beginn' => '2028-02-01', 'ende' => '2028-02-29'], VertragsVorbelegung::fuerEinsatz('2028-02-10'), 'Schaltjahr');
        $this->assertNull(VertragsVorbelegung::fuerEinsatz('20.10.2026'));
        $this->assertNull(VertragsVorbelegung::fuerEinsatz(''));
    }

    public function test_notiz_und_rueckweg(): void
    {
        $notiz = VertragsVorbelegung::notiz('2026-10-20', 'Messe Düsseldorf', 'Service', 'MA');

        $this->assertSame(
            'MA-Einsatz am 20.10.2026 (Messe Düsseldorf, Service) — kein unterschriebener Arbeitsvertrag der Gesellschaft MA deckt diesen Tag.',
            $notiz
        );
        $this->assertSame('2026-10-20', VertragsVorbelegung::einsatztagAusNotiz($notiz));
    }

    public function test_notiz_ohne_event_und_taetigkeit(): void
    {
        $this->assertSame(
            'MA-Einsatz am 03.11.2026 — kein unterschriebener Arbeitsvertrag der Gesellschaft MA deckt diesen Tag.',
            VertragsVorbelegung::notiz('2026-11-03', null, '  ', 'MA')
        );
    }

    public function test_fremde_notiz_liefert_keinen_tag(): void
    {
        $this->assertNull(VertragsVorbelegung::einsatztagAusNotiz('Einsatz-Pruefung: Arbeitserlaubnis fehlt.'));
        $this->assertNull(VertragsVorbelegung::einsatztagAusNotiz(null));
        $this->assertNull(VertragsVorbelegung::einsatztagAusNotiz('MA-Einsatz am 31.02.2026'));
    }
}
```

`tests/Unit/ZuschlagWertTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ZuschlagWert;

/** Review-Focus 4: "0,60" mit Komma darf nirgends zu 0 oder leer werden. */
final class ZuschlagWertTest extends TestCase
{
    public function test_eingabe(): void
    {
        $this->assertSame(0.6, ZuschlagWert::ausEingabe('0,60'));
        $this->assertSame(0.6, ZuschlagWert::ausEingabe('0.6'));
        $this->assertSame(0.0, ZuschlagWert::ausEingabe('0'));
        $this->assertSame(1.6, ZuschlagWert::ausEingabe(' 1,6 '));
        $this->assertNull(ZuschlagWert::ausEingabe(''));
        $this->assertNull(ZuschlagWert::ausEingabe('abc'));
        $this->assertNull(ZuschlagWert::ausEingabe('-1'));
        $this->assertNull(ZuschlagWert::ausEingabe('1,234'));
        $this->assertNull(ZuschlagWert::ausEingabe(null));
    }

    public function test_lesen_und_formatieren(): void
    {
        $this->assertSame(0.6, ZuschlagWert::lesen('0,60'));
        $this->assertSame(0.6, ZuschlagWert::lesen('0.60'));
        $this->assertSame(0.6, ZuschlagWert::lesen(0.6));
        $this->assertSame(1.0, ZuschlagWert::lesen(1));
        $this->assertNull(ZuschlagWert::lesen(''));
        $this->assertNull(ZuschlagWert::lesen(null));
        $this->assertNull(ZuschlagWert::lesen('null Komma sechs'));
        $this->assertSame('0,60', ZuschlagWert::format(0.6));
        $this->assertSame('0,00', ZuschlagWert::format(0.0));
        $this->assertSame('1.234,50', ZuschlagWert::format(1234.5));
    }

    public function test_alter_code(): void
    {
        $this->assertSame(0.6, ZuschlagWert::ausAvCode('AV-060'));
        $this->assertSame(2.6, ZuschlagWert::ausAvCode('AV-260'));
        $this->assertNull(ZuschlagWert::ausAvCode('AV-default'));
        $this->assertNull(ZuschlagWert::ausAvCode('AV-MA-LOG'));
        $this->assertNull(ZuschlagWert::ausAvCode(null));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragsDeckungTest|VertragsVorbelegungTest|ZuschlagWertTest'`
Expected: FAIL / Error „Class "Platform\Recruiting\Support\VertragsDeckung" not found" (und die beiden anderen).

- [ ] **Step 3: Write the implementation**

`src/Support/VertragsDeckung.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Deckt ein Arbeitsvertrag EINER Anstellung einen Einsatztag? (Spec Vertrag
 * aus der Akte §3.1). Rein, ohne Datenbank — die Zeilen baut
 * Services\VertragsZeilen aus rec_contracts und den Vertrags-Extrafeldern.
 *
 * ALTBESTAND: ein unterschriebener AV OHNE Laufzeitfelder deckt jeden Tag.
 * Vor den Extrafeldern vertragsbeginn/vertragsende gab es keine Laufzeit am
 * Vertrag; ein alter unbefristeter Vertrag darf keinen HR-Fall erzeugen.
 * Ein unlesbares Datum zaehlt aus demselben Grund wie ein fehlendes.
 */
final class VertragsDeckung
{
    public const UNTERSCHRIEBEN = 'unterschrieben';
    public const UNTERWEGS = 'unterwegs';
    public const KEINER = 'keiner';

    /**
     * @param  list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}>  $vertraege  Vertraege EINER Anstellung
     * @return array{deckung:string, vertrag_id:?int}
     */
    public static function amTag(array $vertraege, string $tag): array
    {
        $unterschrieben = null;
        $unterwegs = null;

        foreach ($vertraege as $v) {
            if (!self::istAv($v['code']) || $v['superseded'] || !self::laufzeitDeckt($v['beginn'], $v['ende'], $tag)) {
                continue;
            }
            if ($v['status'] === 'completed' && $v['signed_at'] !== null && $v['signed_at'] !== '') {
                $unterschrieben = max($unterschrieben ?? 0, (int) $v['id']);
            } elseif ($v['status'] === 'sent') {
                $unterwegs = max($unterwegs ?? 0, (int) $v['id']);
            }
        }

        if ($unterschrieben !== null) {
            return ['deckung' => self::UNTERSCHRIEBEN, 'vertrag_id' => $unterschrieben];
        }
        if ($unterwegs !== null) {
            return ['deckung' => self::UNTERWEGS, 'vertrag_id' => $unterwegs];
        }

        return ['deckung' => self::KEINER, 'vertrag_id' => null];
    }

    /**
     * Der Waechter gegen Doppelabdeckung bei der Neuanlage (Spec §2.1): jeder
     * nicht stornierte, nicht ersetzte AV, dessen Laufzeit den Tag abdeckt —
     * egal in welchem Status. Bei mehreren der juengste.
     *
     * @param  list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}>  $vertraege
     * @return array{id:int, ende:?string}|null
     */
    public static function ueberschneidung(array $vertraege, string $tag): ?array
    {
        $treffer = null;

        foreach ($vertraege as $v) {
            if (!self::istAv($v['code']) || $v['superseded'] || $v['status'] === 'cancelled') {
                continue;
            }
            if (!self::laufzeitDeckt($v['beginn'], $v['ende'], $tag)) {
                continue;
            }
            if ($treffer === null || (int) $v['id'] > $treffer['id']) {
                $treffer = ['id' => (int) $v['id'], 'ende' => self::datum($v['ende'])];
            }
        }

        return $treffer;
    }

    /** Arbeitsvertrag = Code `AV-…` oder der blanke Altcode `AV`. */
    public static function istAv(?string $code): bool
    {
        $c = trim((string) $code);

        return $c === 'AV' || str_starts_with($c, 'AV-');
    }

    /** Y-m-d aus `Y-m-d…`, `d.m.Y` oder einem Datumsobjekt — sonst null. */
    public static function datum(mixed $wert): ?string
    {
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }

        $s = trim((string) $wert);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return YmdDate::isValid($m[1]) ? $m[1] : null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];

            return YmdDate::isValid($ymd) ? $ymd : null;
        }

        return null;
    }

    private static function laufzeitDeckt(mixed $beginn, mixed $ende, string $tag): bool
    {
        $b = self::datum($beginn);
        $e = self::datum($ende);

        return ($b === null || $b <= $tag) && ($e === null || $e >= $tag);
    }
}
```

`src/Support/VertragsVorbelegung.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Vorbelegung des Fensters "Vertrag erstellen" aus einem Einsatztag und die
 * Notiz des HR-Falls "Vertrag fehlt fuer Einsatz" (Spec §3.3).
 *
 * DIE MONATSREGEL IST EINE ANNAHME aus Markus' "meist einen Monat gueltig"
 * (Telefonat 09.10.2026, offene Frage 1 im Spec §9). Sie steht NUR hier —
 * kommt seine Antwort, wird diese eine Methode ersetzt.
 *
 * notiz() und einsatztagAusNotiz() gehoeren zusammen: der HR-Schreibtisch
 * liest den Tag aus der Notiz zurueck (keine neue Spalte, Spec §4).
 */
final class VertragsVorbelegung
{
    /** @return array{beginn:string, ende:string}|null */
    public static function fuerEinsatz(string $einsatztag): ?array
    {
        if (!YmdDate::isValid($einsatztag)) {
            return null;
        }
        $tag = new \DateTimeImmutable($einsatztag, new \DateTimeZone('UTC'));

        return ['beginn' => $tag->format('Y-m-01'), 'ende' => $tag->format('Y-m-t')];
    }

    public static function notiz(string $einsatztag, ?string $event, ?string $taetigkeit, string $firma): string
    {
        $klammer = implode(', ', array_filter(
            [trim((string) $event), trim((string) $taetigkeit)],
            static fn (string $s) => $s !== ''
        ));

        return sprintf(
            '%s-Einsatz am %s%s — kein unterschriebener Arbeitsvertrag der Gesellschaft %s deckt diesen Tag.',
            $firma,
            self::deutsch($einsatztag),
            $klammer !== '' ? ' (' . $klammer . ')' : '',
            $firma
        );
    }

    public static function einsatztagAusNotiz(?string $notiz): ?string
    {
        if (!preg_match('/-Einsatz am (\d{2})\.(\d{2})\.(\d{4})/', (string) $notiz, $m)) {
            return null;
        }
        $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];

        return YmdDate::isValid($ymd) ? $ymd : null;
    }

    private static function deutsch(string $ymd): string
    {
        [$jahr, $monat, $tag] = explode('-', $ymd) + [null, null, null];

        return $tag . '.' . $monat . '.' . $jahr;
    }
}
```

`src/Support/ZuschlagWert.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Der Zuschlag in Euro je Stunde — EINE Stelle fuer Eingabe, Lesen und
 * Anzeige. Gespeichert wird er am Vertrag als Text im deutschen Format
 * ("0,60"), wie ReissueContractService::createSuccessor() es schon tut;
 * gelesen wird alles, was je dort stand ("0,60", "0.60", 0.6).
 */
final class ZuschlagWert
{
    /** HR-Eingabe: Ziffern, optional Komma/Punkt, hoechstens zwei Stellen (wie reissueContract()). */
    public static function ausEingabe(?string $roh): ?float
    {
        $s = trim((string) $roh);
        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $s)) {
            return null;
        }

        return round((float) str_replace(',', '.', $s), 2);
    }

    public static function lesen(mixed $wert): ?float
    {
        if (is_int($wert) || is_float($wert)) {
            return round((float) $wert, 2);
        }
        $s = str_replace(',', '.', trim((string) $wert));

        return ($s !== '' && is_numeric($s)) ? round((float) $s, 2) : null;
    }

    public static function format(float $zuschlag): string
    {
        return number_format($zuschlag, 2, ',', '.');
    }

    /** Alt-Varianten mit Betrag im Code: AV-060 → 0,60 (wie ZasEmployeeFieldResolver::getZuschlag()). */
    public static function ausAvCode(?string $code): ?float
    {
        if (!preg_match('/^AV-(\d{3})$/', trim((string) $code), $m)) {
            return null;
        }

        return round(((int) $m[1]) / 100, 2);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragsDeckungTest|VertragsVorbelegungTest|ZuschlagWertTest'`
Expected: PASS (alle Fälle grün).

- [ ] **Step 5: Commit**

```bash
git add src/Support/VertragsDeckung.php src/Support/VertragsVorbelegung.php src/Support/ZuschlagWert.php tests/Unit/VertragsDeckungTest.php tests/Unit/VertragsVorbelegungTest.php tests/Unit/ZuschlagWertTest.php
git commit -m "feat(recruiting): Regeln Vertragsdeckung, Vorbelegung und Zuschlag fuer den MA-Vertragscheck

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Vertrag ohne Bewerbung speicherbar + Personalisierung aus der Anstellung

**Files:**
- Create: `database/migrations/2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts.php`
- Create: `tests/Integration/VertragAusAkteHarness.php`
- Modify: `src/Models/RecContractTemplate.php:243-351` (`personalizeContent()`, Kopf und zwei Stellen in `resolveSource()`)
- Test: `tests/Integration/PersonalisierungAnstellungTest.php`

**Interfaces:**
- Consumes: `ZuschlagWert::lesen()`, `ZuschlagWert::format()` (Task 1)
- Produces:
  - `RecContractTemplate::personalizeFuerAnstellung(RecEmployee $anstellung, RecContract $vertrag): string`
  - `RecContractTemplate::personalizeContent(RecApplicant $applicant, ?RecContract $contract = null): string` — Verhalten unverändert
  - Quelle `employee.<spalte>` in Vorlagen-Mappings (nur über `personalizeFuerAnstellung`)
  - Trait `Platform\Recruiting\Tests\Integration\VertragAusAkteHarness` mit `weltAufbauen()`, `weltAbbauen()`, `anstellung(array $set = []): RecEmployee`, `vorlage(string $code, string $company = 'MA', array $set = []): RecContractTemplate`, `vertragAn(RecEmployee $a, RecContractTemplate $t, array $set = [], array $felder = []): RecContract`, `bewerberMitKontakt(string $vorname, string $nachname, ?float $zuschlag = 1.5): RecApplicant`, `verknuepfen(RecEmployee $a, RecApplicant $b): RecEmployee`, `personVerbinden(RecEmployee ...$anstellungen): int`, `extraFieldCacheLeeren(): void`, Property `int $team = 7`
  - Schema: `rec_contracts.rec_applicant_id` nullable

- [ ] **Step 1: Write the harness and the byte-identity pin (on the OLD code)**

`tests/Integration/VertragAusAkteHarness.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Gemeinsame Welt fuer "Vertrag aus der Akte" (Tasks 2, 4-8): Container +
 * Capsule/SQLite je TEST frisch, Schema aus den ECHTEN Migrationen (Core-
 * Extrafelder, Lookups, Public-Form-Links, CRM-Kontakte, alle eigenen).
 * Muster VertragAnDerAnstellungTest + DokumenteHarness.
 *
 * Der Extrafeld-Cache (statisch, Schluessel Klasse:id) wird je Test geleert —
 * in einer frischen Datenbank kehren die ids wieder, und ein alter Eintrag
 * zeigte auf Definitionen, die es nicht mehr gibt.
 */
trait VertragAusAkteHarness
{
    protected int $team = 7;

    protected function weltAufbauen(): void
    {
        $container = Container::getInstance();
        Container::setInstance($container);
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => [
                'company_prefix' => 'RG',
                'company_labels' => ['RG' => 'RheinGedeck GmbH', 'MA' => 'MA Dienstleistung für die Gastronomie UG'],
            ]],
        ]));
        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });
        $container->instance('url', new class {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return '/' . $name . '/' . http_build_query($parameters);
            }
        });
        $team = $this->team;
        $container->singleton(AuthFactory::class, function () use ($team) {
            return new class($team) implements AuthFactory {
                public function __construct(private int $teamId) {}
                public function guard($name = null) { return $this; }
                public function user(): object { return (object) ['currentTeam' => (object) ['id' => $this->teamId]]; }
                public function check() { return false; }
                public function id() { return null; }
                public function shouldUse($name) {}
            };
        });
        $container->alias(AuthFactory::class, 'auth');

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->extraFieldCacheLeeren();
        $this->migrationenFahren();
        $this->vertragsFelderAnlegen();

        Carbon::setTestNow('2026-10-09 10:00:00');
    }

    protected function weltAbbauen(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['config', 'events', 'log', 'url', 'db', 'db.schema'] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        $this->extraFieldCacheLeeren();
    }

    protected function extraFieldCacheLeeren(): void
    {
        foreach ([RecContract::class, RecApplicant::class, RecContractTemplate::class] as $klasse) {
            foreach (['extraFieldDefinitionsCache', 'extraFieldInheritanceStack'] as $name) {
                (new \ReflectionProperty($klasse, $name))->setValue(null, []);
            }
        }
    }

    /** Ohne diese Definitionen ist setExtraField() ein stiller No-Op (siehe ReissueContractTest). */
    private function vertragsFelderAnlegen(): void
    {
        foreach ([['vertragsbeginn', 'Vertragsbeginn', 'date', 10], ['vertragsende', 'Vertragsende', 'date', 20], ['zuschlag', 'Zuschlag (€/Std)', 'text', 30]] as [$name, $label, $typ, $order]) {
            CoreExtraFieldDefinition::create([
                'team_id' => $this->team, 'context_type' => RecContract::class, 'context_id' => null,
                'name' => $name, 'label' => $label, 'type' => $typ, 'order' => $order,
            ]);
        }
    }

    private function migrationenFahren(): void
    {
        $core = $this->paketWurzel(User::class);
        $crm  = $this->paketWurzel(CrmContact::class);
        $own  = dirname(__DIR__, 2);

        $fremd = [];
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_02_07_000001_create_core_extra_field_definitions_table.php',
            '2026_02_07_000002_create_core_extra_field_values_table.php',
            '2026_02_08_120000_add_is_mandatory_to_core_extra_field_definitions_table.php',
            '2026_02_12_000001_add_llm_verification_to_extra_fields.php',
            '2026_02_12_000002_add_auto_fill_to_extra_fields.php',
            '2026_02_12_000003_create_core_lookups_tables.php',
            '2026_02_16_000001_add_visibility_config_to_extra_field_definitions.php',
            '2026_02_23_000001_create_core_public_form_links_table.php',
            '2026_03_19_000001_add_description_to_core_extra_field_definitions_table.php',
        ] as $datei) {
            $fremd[] = $core . '/database/migrations/' . $datei;
        }
        $crmDateien = array_merge(
            glob($crm . '/database/migrations/*postal_address*.php') ?: [],
            glob($crm . '/database/migrations/*phone_number*.php') ?: [],
            glob($crm . '/database/migrations/*email_address*.php') ?: [],
            [
                $crm . '/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
                $crm . '/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
                $crm . '/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
                $crm . '/database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php',
            ],
        );
        usort($crmDateien, fn (string $a, string $b) => strcmp(basename($a), basename($b)));

        $eigene = glob($own . '/database/migrations/*.php') ?: [];
        sort($eigene);

        foreach (array_merge($fremd, array_values(array_unique($crmDateien)), $eigene) as $pfad) {
            if (!file_exists($pfad)) {
                throw new \RuntimeException("Migration fehlt: {$pfad}");
            }
            (require $pfad)->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        $dir = dirname((new \ReflectionClass($klasse))->getFileName());
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $klasse);
    }

    // ---- Fixtures --------------------------------------------------------

    protected function anstellung(array $set = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => $this->team, 'first_name' => 'Mia', 'last_name' => 'Muster',
            'company' => 'MA', 'personnel_number' => 'MA4711', 'is_active' => true,
            'street' => 'Ringstraße', 'house_number' => '5', 'zip' => '40210', 'city' => 'Düsseldorf',
            'birth_date' => '2001-04-03', 'email' => 'mia@example.org', 'phone' => '+4915112345678',
            'employment_type' => 'aushilfe', 'is_eu_citizen' => true, 'is_first_aider' => false,
        ], $set));
    }

    protected function vorlage(string $code, string $company = 'MA', array $set = []): RecContractTemplate
    {
        return RecContractTemplate::create(array_merge([
            'team_id' => $this->team, 'name' => $code, 'code' => $code, 'company' => $company,
            'is_active' => true, 'content' => '<p>{{vorname}} {{nachname}}</p>',
            'field_mappings' => ['vorname' => 'contact.first_name', 'nachname' => 'contact.last_name'],
        ], $set));
    }

    /** Vertrag an einer Anstellung; Extrafelder werden NACH dem Anlegen gesetzt. */
    protected function vertragAn(RecEmployee $a, RecContractTemplate $t, array $set = [], array $felder = []): RecContract
    {
        $v = RecContract::create(array_merge([
            'rec_applicant_id' => $a->rec_applicant_id, 'rec_employee_id' => $a->id,
            'rec_contract_template_id' => $t->id, 'team_id' => $a->team_id,
            'status' => 'sent', 'sent_at' => '2026-09-20 09:00:00', 'personalized_content' => '<p>Vertrag</p>',
        ], $set));
        foreach ($felder as $name => $wert) {
            $v->setExtraField($name, $wert);
        }

        return $v;
    }

    protected function bewerberMitKontakt(string $vorname, string $nachname, ?float $zuschlag = 1.5): RecApplicant
    {
        $b = RecApplicant::create(['team_id' => $this->team, 'is_active' => true, 'auto_pilot' => false, 'zuschlag' => $zuschlag]);
        $k = CrmContact::create(['team_id' => $this->team, 'is_active' => true, 'first_name' => $vorname, 'last_name' => $nachname]);
        $b->crmContactLinks()->create(['contact_id' => $k->id, 'team_id' => $this->team]);

        return $b;
    }

    protected function verknuepfen(RecEmployee $a, RecApplicant $b): RecEmployee
    {
        DB::table('rec_employees')->where('id', $a->id)->update(['rec_applicant_id' => $b->id]);

        return $a->fresh();
    }

    /** Eine Personen-Zeile fuer mehrere Anstellungen — Query Builder, wie PersonLinker. */
    protected function personVerbinden(RecEmployee ...$anstellungen): int
    {
        $personId = (int) DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-' . bin2hex(random_bytes(6)), 'team_id' => $this->team,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rec_employees')->whereIn('id', array_map(fn (RecEmployee $a) => $a->id, $anstellungen))
            ->update(['rec_person_id' => $personId]);

        return $personId;
    }
}
```

`tests/Integration/PersonalisierungAnstellungTest.php` (zunächst NUR der Pin):

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecContract;

/**
 * Spec Vertrag aus der Akte §2.3 — Personalisierung mit der Anstellung als
 * zweiter Quelle. Der Bewerbungsweg (personalizeContent) bleibt
 * byteidentisch: der erste Test laeuft VOR dem Umbau gruen und danach immer
 * noch (zusaetzlich zu PlaceholderResolutionPinTest).
 */
final class PersonalisierungAnstellungTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    public function test_bewerbungsweg_rendert_byteidentisch(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 1.5);
        $t = $this->vorlage('AV-default', 'RG', [
            'content' => '<p>{{vorname}} {{nachname}}|{{ort}}|{{zuschlag}}|{{beginn}}|{{lohn}}|{{txt}}|{{heute}}|{{neu}}</p>',
            'field_mappings' => [
                'vorname'  => 'contact.first_name',
                'nachname' => 'contact.last_name',
                'ort'      => 'contact.address.city',
                'zuschlag' => 'applicant.zuschlag',
                'beginn'   => 'contract.extra_field.vertragsbeginn',
                'lohn'     => 'settings.minimum_wage_hourly',
                'txt'      => 'text:Hallo',
                'heute'    => 'meta.datum_heute',
                'neu'      => 'employee.first_name',
            ],
        ]);
        $v = RecContract::create([
            'rec_applicant_id' => $b->id, 'rec_contract_template_id' => $t->id, 'team_id' => $this->team,
            'status' => 'pending', 'personalized_content' => '',
        ]);
        $v->setExtraField('vertragsbeginn', '2026-11-01');

        $this->assertSame(
            '<p>Max Muster||1,50|2026-11-01|13,90|Hallo|09.10.2026|</p>',
            $t->personalizeContent($b, $v),
            'employee.* gibt es im Bewerbungsweg nicht — leer wie jedes unbekannte Praefix'
        );
    }
}
```

- [ ] **Step 2: Run the pin against the OLD code**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonalisierungAnstellungTest`
Expected: PASS. (Schlägt er hier fehl, stimmt die Welt nicht — erst den Harness reparieren, NICHT den erwarteten String.)

- [ ] **Step 3: Write the failing tests for the employee path**

An `PersonalisierungAnstellungTest` anhängen:

```php
    /** Probe: mitAnstellung()-Aufruf in personalize() entfernen → hier leerer Name, rot. */
    public function test_ohne_bewerbung_kommen_name_und_adresse_aus_der_anstellung(): void
    {
        $ma = $this->anstellung();
        $t = $this->vorlage('AV-MA-LOG', 'MA', [
            'content' => '<p>{{vorname}} {{nachname}}, {{strasse}} {{nr}}, {{plz}} {{ort}}, geb. {{geb}}, {{mail}}</p>',
            'field_mappings' => [
                'vorname' => 'contact.first_name', 'nachname' => 'contact.last_name',
                'strasse' => 'contact.address.street', 'nr' => 'contact.address.house_number',
                // Live-Vorlagen mappen postal_code (PlaceholderResolutionPinTest), die Anstellung heisst zip.
                'plz' => 'contact.address.postal_code', 'ort' => 'contact.address.city',
                'geb' => 'contact.birth_date', 'mail' => 'contact.email',
            ],
        ]);
        $v = $this->vertragAn($ma, $t, ['status' => 'pending', 'sent_at' => null]);

        $this->assertNull($v->rec_applicant_id, 'Vorflug: dieser Vertrag hat keine Bewerbung');
        $this->assertSame(
            '<p>Mia Muster, Ringstraße 5, 40210 Düsseldorf, geb. 03.04.2001, mia@example.org</p>',
            $t->personalizeFuerAnstellung($ma, $v)
        );
    }

    public function test_zuschlag_kommt_aus_dem_vertragsfeld_und_faellt_auf_den_bewerber_zurueck(): void
    {
        $t = $this->vorlage('AV-MA-LOG', 'MA', ['content' => '<p>{{z}}</p>', 'field_mappings' => ['z' => 'applicant.zuschlag']]);

        $ohne = $this->anstellung();
        $v1 = $this->vertragAn($ohne, $t, [], ['zuschlag' => '0,60']);
        $this->assertSame('<p>0,60</p>', $t->personalizeFuerAnstellung($ohne, $v1), 'ohne Bewerbung: Vertragsfeld');

        $mit = $this->verknuepfen($this->anstellung(['personnel_number' => 'MA4712']), $this->bewerberMitKontakt('Max', 'Muster', 1.5));
        $v2 = $this->vertragAn($mit, $t);
        $this->assertSame('<p>1,50</p>', $t->personalizeFuerAnstellung($mit, $v2), 'Feld leer → Bewerber');

        $v3 = $this->vertragAn($mit, $t, [], ['zuschlag' => '0,60']);
        $this->assertSame('<p>0,60</p>', $t->personalizeFuerAnstellung($mit, $v3), 'Feld gewinnt vor dem Bewerber');
    }

    public function test_spalten_extrafelder_und_gesperrte_spalten_ohne_bewerbung(): void
    {
        $ma = $this->anstellung(['iban' => 'DE02120300000000202051', 'birth_place' => 'Köln']);
        $t = $this->vorlage('AV-MA-LOG', 'MA', [
            'content' => '<p>{{iban}}|{{ort}}|{{geb}}|{{tok}}|{{rel}}</p>',
            'field_mappings' => [
                'iban' => 'applicant.iban',
                'ort'  => 'applicant.extra_field.geburtsort',
                'geb'  => 'employee.birth_place',
                'tok'  => 'employee.portal_token',
                'rel'  => 'employee.applicant',
            ],
        ]);
        $v = $this->vertragAn($ma, $t);

        $this->assertSame('<p>DE02120300000000202051||Köln||</p>', $t->personalizeFuerAnstellung($ma, $v),
            'Bewerber-Extrafeld ohne Bewerbung leer (Spec §2.3); Portal-Token und Relationen nie im Vertrag');
    }

    public function test_mit_bewerbung_fuellt_die_anstellung_nur_leere_werte(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $ma = $this->verknuepfen($this->anstellung(), $b);
        $t = $this->vorlage('AV-MA-LOG', 'MA', [
            'content' => '<p>{{vorname}} {{ort}}</p>',
            'field_mappings' => ['vorname' => 'contact.first_name', 'ort' => 'contact.address.city'],
        ]);
        $v = $this->vertragAn($ma, $t);

        $this->assertSame('<p>Max Düsseldorf</p>', $t->personalizeFuerAnstellung($ma, $v),
            'CRM-Kontakt gewinnt (Max statt Mia); Adresse fehlt dort → Anstellung');
    }
```

- [ ] **Step 4: Run to verify they fail**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonalisierungAnstellungTest`
Expected: der Pin PASS, die vier neuen FAIL — zuerst mit `Integrity constraint violation: 19 NOT NULL constraint failed: rec_contracts.rec_applicant_id` bzw. `Call to undefined method …personalizeFuerAnstellung()`.

- [ ] **Step 5: Write the migration**

`database/migrations/2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertrag aus der Mitarbeiterakte (Spec 2026-10-09 §2.2): ein MA-Datensatz
 * aus ZAS hat oft KEINE Bewerbung. rec_contracts.rec_applicant_id war seit
 * 2026_04_15 NOT NULL — ein solcher Vertrag liess sich nicht speichern.
 *
 * Keine neue Spalte, nur die Nullbarkeit. Fremdschluessel und Indizes
 * bleiben (unter SQLite baut Laravel die Tabelle dabei neu auf und uebernimmt
 * beides — geprueft am 09.10.2026).
 *
 * down(): scheitert, sobald Vertraege ohne Bewerbung existieren — gewollt,
 * ein stilles Loeschen waere schlimmer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('rec_applicant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('rec_applicant_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 6: Implement the personalization**

In `src/Models/RecContractTemplate.php`:

(a) Imports ergänzen (unter `use Platform\Recruiting\Support\AnstellungsZuordnung;`):

```php
use Platform\Recruiting\Support\ZuschlagWert;
```

(b) Unter `public const CERTIFICATE_CODE_PREFIX = 'ZERT-';` zwei Konstanten:

```php
    /**
     * contact.<feld> → Spalte der Anstellung, wo die Namen abweichen
     * (Spec Vertrag aus der Akte §2.3). Die Live-Vorlagen mappen
     * contact.address.postal_code; die Anstellung heisst zip.
     */
    private const KONTAKT_AUF_ANSTELLUNG = [
        'address.street'       => 'street',
        'address.house_number' => 'house_number',
        'address.postal_code'  => 'zip',
        'address.zip'          => 'zip',
        'address.city'         => 'city',
    ];

    /** Spalten, die in keinem Vertrag stehen duerfen — ein Portal-Token im PDF waere ein Schluessel zum Konto. */
    private const NIE_IM_VERTRAG = ['portal_token', 'uuid', 'person_key', 'rec_person_id', 'portal_locked_reason'];
```

(c) Die Methode `personalizeContent()` (Zeilen 243–275, von `public function personalizeContent(` bis zur schließenden `}` nach `return $content;`) komplett ersetzen durch:

```php
    public function personalizeContent(RecApplicant $applicant, ?RecContract $contract = null): string
    {
        return $this->personalize($applicant, null, $contract);
    }

    /**
     * Personalisierung fuer einen Vertrag aus der Mitarbeiterakte (Spec
     * §2.3): dieselbe Vorlagensprache, die Anstellung als zweite Quelle.
     * Ohne Bewerbung ist sie die einzige Quelle fuer contact.* und
     * applicant.<spalte>; mit Bewerbung fuellt sie nur leere Werte. Neu:
     * employee.<spalte>. applicant.zuschlag liest zuerst das Vertragsfeld.
     */
    public function personalizeFuerAnstellung(RecEmployee $anstellung, RecContract $vertrag): string
    {
        return $this->personalize($anstellung->applicant, $anstellung, $vertrag);
    }

    /**
     * Der gemeinsame Auflöser. Ohne Anstellung ($anstellung === null) ist das
     * exakt der alte personalizeContent()-Weg — gepinnt in
     * PersonalisierungAnstellungTest::test_bewerbungsweg_rendert_byteidentisch
     * und PlaceholderResolutionPinTest.
     */
    private function personalize(?RecApplicant $applicant, ?RecEmployee $anstellung, ?RecContract $contract): string
    {
        $content = $this->content ?? '';
        $mappings = $this->field_mappings ?? [];

        if (empty($mappings) || empty($content)) {
            return $content;
        }

        $contactModel = null;
        if ($applicant !== null) {
            $applicant->load([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
                'crmContactLinks.contact.postalAddresses',
            ]);
            $contactModel = $applicant->crmContactLinks->first()?->contact;
        }

        // Eine Resolver-Instanz pro Dokument: der Label-Cache lebt genau so
        // lange wie dieser Render-Vorgang. Bewusst kein Singleton — ein
        // langlebiger Queue-Worker wuerde sonst veraltete Labels ausliefern.
        $lookups = new ZasLookupResolver();

        $replacements = [];
        foreach ($mappings as $placeholder => $source) {
            $wert = $this->resolveSource($source, $applicant, $contactModel, $contract, $lookups);
            if ($anstellung !== null) {
                $wert = $this->mitAnstellung((string) $source, $wert, $anstellung, $contract);
            }
            $replacements['{{' . $placeholder . '}}'] = $wert;
        }

        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        // Strip white/near-white color styles from TinyMCE dark mode artifacts
        $content = preg_replace('/color:\s*(?:white|#fff(?:fff)?|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))\s*;?/i', '', $content);

        return $content;
    }

    /** Vorrang-Kette der Anstellung (Spec §2.3, Tabelle). */
    private function mitAnstellung(string $source, string $wert, RecEmployee $anstellung, ?RecContract $contract): string
    {
        if (str_starts_with($source, 'employee.')) {
            return $this->anstellungsWert($anstellung, substr($source, strlen('employee.')));
        }

        if ($source === 'applicant.zuschlag') {
            $feld = ZuschlagWert::lesen($contract?->getExtraField('zuschlag'));

            return $feld !== null ? ZuschlagWert::format($feld) : $wert;
        }

        if ($wert !== '') {
            return $wert;
        }

        if (str_starts_with($source, 'contact.')) {
            $feld = substr($source, strlen('contact.'));

            return $this->anstellungsWert($anstellung, self::KONTAKT_AUF_ANSTELLUNG[$feld] ?? $feld);
        }

        if (str_starts_with($source, 'applicant.') && !str_starts_with($source, 'applicant.extra_field.')) {
            return $this->anstellungsWert($anstellung, substr($source, strlen('applicant.')));
        }

        return $wert;
    }

    /**
     * Nur echte Spalten der geladenen Zeile — getAttribute() wuerde sonst
     * Relationen nachladen (employee.applicant). Datum wie im CRM-Zweig d.m.Y.
     */
    private function anstellungsWert(RecEmployee $anstellung, string $spalte): string
    {
        if ($spalte === '' || in_array($spalte, self::NIE_IM_VERTRAG, true)
            || !array_key_exists($spalte, $anstellung->getAttributes())) {
            return '';
        }

        $wert = $anstellung->getAttribute($spalte);
        if ($wert instanceof \DateTimeInterface) {
            return Carbon::instance($wert)->format('d.m.Y');
        }
        if (is_bool($wert)) {
            return $wert ? 'ja' : 'nein';
        }
        if (is_array($wert)) {
            return implode(', ', array_map('strval', $wert));
        }

        return trim((string) ($wert ?? ''));
    }
```

(d) In `resolveSource()` die Signatur (Zeile 277) ersetzen:

```php
    private function resolveSource(string $source, ?RecApplicant $applicant, $contact, ?RecContract $contract, ?ZasLookupResolver $lookups = null): string
```

(e) Im `applicant.`-Zweig direkt nach `if (str_starts_with($source, 'applicant.')) {` als erste Zeile einfügen:

```php
            if ($applicant === null) {
                return '';
            }
```

(f) Im `settings.`-Zweig die Zeile

```php
            $settings = RecApplicantSettings::getOrCreateForTeam($applicant->team_id);
```

ersetzen durch

```php
            $settings = RecApplicantSettings::getOrCreateForTeam($applicant?->team_id ?? $this->team_id);
```

- [ ] **Step 7: Run the tests**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'PersonalisierungAnstellungTest|PlaceholderResolutionPinTest|ReissueContractTest|ContractPdfRegressionTest|MassenzuweisungGeschlosseneWeltTest|VertragAnDerAnstellungTest'`
Expected: PASS. `MassenzuweisungGeschlosseneWeltTest` bleibt grün (die Migration ändert keine Spalte an `rec_employees`/`rec_persons` und wirft unter SQLite nicht).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts.php src/Models/RecContractTemplate.php tests/Integration/VertragAusAkteHarness.php tests/Integration/PersonalisierungAnstellungTest.php
git commit -m "feat(recruiting): Vertrag ohne Bewerbung speicherbar, Personalisierung aus der Anstellung (Bewerbungsweg byteidentisch)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: VertragHinweisSender + Einstellung

**Files:**
- Modify: `src/Services/Comms/DokumentHinweisSender.php:73` (Schlüssel über Methode)
- Create: `src/Services/Comms/VertragHinweisSender.php`
- Modify: `src/Models/RecApplicantSettings.php:74` (Default)
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (Select nach `settings.default_contact_user_id`)
- Test: `tests/Integration/VertragHinweisSenderTest.php`

**Interfaces:**
- Consumes: `HoldingTemplateSender::resolveTarget(int $teamId, string $settingsKey)`, `RecApplicantSettings::getOrCreateForTeam()`
- Produces:
  - `VertragHinweisSender extends DokumentHinweisSender`, `VertragHinweisSender::SETTINGS_KEY = 'employee_contract_wa_template_id'`, `sende(RecEmployee $employee): string` (Statuswerte wie `DokumentHinweisSender::STATUS_*`)
  - `DokumentHinweisSender::vorlagenSchluessel(int $teamId): string` (protected)
  - Setting `employee_contract_wa_template_id` (Default `null`)

- [ ] **Step 1: Write the failing test**

`tests/Integration/VertragHinweisSenderTest.php`:

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
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;

/**
 * Spec Vertrag aus der Akte §2.4 — Muster DokumentHinweisSenderTest: echte
 * Tabellen fuer Einstellungen, Konto, Kanal und Vorlagen; Meta als Attrappe.
 */
final class VertragHinweisSenderTest extends TestCase
{
    private const TEAM = 3;
    private const NUMMER = '+4915111111111';
    private const TOKEN = 'portal-token-vertrag';
    private const ANGEFASST = '2026-10-09 09:00:00';

    private Capsule $capsule;
    private object $meta;
    private int $kontoId;
    private int $dokumentVorlage;
    private int $vertragVorlage;

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
        $container->instance(HoldingTemplateSender::class, new HoldingTemplateSender(new class extends WhatsAppMetaService {}));

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
        $this->dokumentVorlage = $this->vorlageAnlegen('dokument_hinweis');
        $this->vertragVorlage = $this->vorlageAnlegen('vertrag_hinweis');
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['log', 'config', 'db', 'db.schema', WhatsAppMetaService::class, HoldingTemplateSender::class] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_eigene_vertragsvorlage_gewinnt(): void
    {
        $this->einstellungen([
            DokumentHinweisSender::SETTINGS_KEY => $this->dokumentVorlage,
            VertragHinweisSender::SETTINGS_KEY  => $this->vertragVorlage,
        ]);

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, (new VertragHinweisSender())->sende($this->mitarbeiter()));
        $this->assertSame('vertrag_hinweis', $this->meta->calls[0]['templateName']);
        $this->assertSame(self::TOKEN, $this->meta->calls[0]['components'][1]['parameters'][0]['text']);
    }

    /** Probe: Rueckfall in vorlagenSchluessel() entfernen → nicht_konfiguriert, rot. */
    public function test_ohne_eigene_vorlage_faellt_er_auf_die_dokumentvorlage_zurueck(): void
    {
        $this->einstellungen([DokumentHinweisSender::SETTINGS_KEY => $this->dokumentVorlage]);

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, (new VertragHinweisSender())->sende($this->mitarbeiter()));
        $this->assertSame('dokument_hinweis', $this->meta->calls[0]['templateName']);
    }

    public function test_ohne_jede_vorlage_nicht_konfiguriert(): void
    {
        $this->einstellungen([]);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new VertragHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_meta_ablehnung_ist_failed(): void
    {
        $this->einstellungen([VertragHinweisSender::SETTINGS_KEY => $this->vertragVorlage]);
        $this->meta->lehntAb();

        $status = (new VertragHinweisSender())->sende($this->mitarbeiter());

        $this->assertSame(DokumentHinweisSender::STATUS_FAILED, $status);
        $this->assertFalse(DokumentHinweisSender::istErfolg($status));
    }

    public function test_dokument_sender_bleibt_bei_seinem_schluessel(): void
    {
        $this->einstellungen([VertragHinweisSender::SETTINGS_KEY => $this->vertragVorlage]);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new DokumentHinweisSender())->sende($this->mitarbeiter()),
            'Der Rueckfall gilt nur in eine Richtung: der Dokument-Hinweis nimmt nie die Vertragsvorlage');
    }

    public function test_einstellung_hat_default_und_steht_im_fenster(): void
    {
        $this->assertArrayHasKey(VertragHinweisSender::SETTINGS_KEY, RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS[VertragHinweisSender::SETTINGS_KEY]);

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $select = strpos($blade, 'name="settings.employee_contract_wa_template_id"');
        $anker = strpos($blade, 'wire:model.live="settings.default_contact_user_id"');
        $this->assertIsInt($select);
        $this->assertIsInt($anker);
        $this->assertGreaterThan($anker, $select, 'Hausregel: Neues kommt unter das Ansprechpartner-Select');
        $this->assertStringContainsString('Vertrag zur Unterschrift — WhatsApp-Template mit Portal-Link', $blade);
    }

    private function mitarbeiter(): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId([
            'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
            'phone' => self::NUMMER, 'portal_token' => self::TOKEN, 'is_active' => 1,
            'portal_v2_since' => self::ANGEFASST, 'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);

        return RecEmployee::find($id);
    }

    private function metaAttrappe(): object
    {
        return new class {
            public array $calls = [];
            private bool $lehntAb = false;
            public function lehntAb(): void { $this->lehntAb = true; }
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false)
            {
                $this->calls[] = compact('channel', 'to', 'templateName', 'components', 'languageCode');
                if ($this->lehntAb) {
                    return new class { public string $status = 'failed'; public array $meta_payload = ['error' => ['message' => 'abgelehnt']]; };
                }
                return new class { public int $id = 4713; public string $status = 'sent'; public array $meta_payload = []; };
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
            'uuid' => 'acc-vertrag', 'phone_number' => '+49 160 5552002', 'title' => 'Recruiting-WABA', 'active' => true, 'user_id' => 1,
        ]);
        DB::table('comms_channels')->insert([
            'team_id' => self::TEAM, 'name' => 'Recruiting WhatsApp', 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552002', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $this->kontoId]),
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }

    private function einstellungen(array $weitere): void
    {
        DB::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(array_merge(['auto_pilot_wa_account_id' => $this->kontoId], $weitere)),
        ]);
    }

    private function vorlageAnlegen(string $name): int
    {
        return (int) DB::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . $name, 'external_id' => 'ext-' . $name, 'name' => $name, 'language' => 'de',
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

- [ ] **Step 2: Run to verify it fails**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VertragHinweisSenderTest`
Expected: FAIL / Error `Class "Platform\Recruiting\Services\Comms\VertragHinweisSender" not found`.

- [ ] **Step 3: Implement**

(a) `src/Services/Comms/DokumentHinweisSender.php` — die Zeile

```php
        $ziel = app(HoldingTemplateSender::class)->resolveTarget((int) $employee->team_id, self::SETTINGS_KEY);
```

ersetzen durch

```php
        $ziel = app(HoldingTemplateSender::class)->resolveTarget((int) $employee->team_id, $this->vorlagenSchluessel((int) $employee->team_id));
```

und vor `/** @return list<string> alle {{...}} im BODY …` einfügen:

```php
    /**
     * Welcher Einstellungs-Schluessel die Vorlage traegt. Eigene Methode,
     * damit VertragHinweisSender (Spec Vertrag aus der Akte §2.4) seinen
     * Schluessel mit Rueckfall auf diesen setzen kann, ohne den Versandweg
     * zu kopieren.
     */
    protected function vorlagenSchluessel(int $teamId): string
    {
        return self::SETTINGS_KEY;
    }

```

(b) `src/Services/Comms/VertragHinweisSender.php`:

```php
<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * "Im Portal liegt ein Vertrag zur Unterschrift" (Spec Vertrag aus der Akte
 * §2.4). Derselbe Weg wie der Dokument-Hinweis — aktiv, portal_v2_since,
 * Nummer, URL-Knopf mit Portal-Token, Platzhalter nur vorname/name/1,
 * `failed` ist kein Erfolg —, nur mit eigener Vorlage.
 *
 * RUECKFALL: ist `employee_contract_wa_template_id` leer, nimmt er
 * `document_wa_template_id` — der universelle Text "im Portal liegt etwas
 * fuer dich" passt auch hier. Die Gegenrichtung gibt es nicht.
 */
class VertragHinweisSender extends DokumentHinweisSender
{
    public const SETTINGS_KEY = 'employee_contract_wa_template_id';

    protected function vorlagenSchluessel(int $teamId): string
    {
        $eigene = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting(self::SETTINGS_KEY);

        return ((int) $eigene) > 0 ? self::SETTINGS_KEY : parent::SETTINGS_KEY;
    }
}
```

(c) `src/Models/RecApplicantSettings.php` — direkt unter `'document_wa_template_id' => null,` einfügen:

```php
        // Vertrag aus der Akte (Spec 2026-10-09, §2.4): Hinweis "Vertrag zur
        // Unterschrift im Portal". Leer = VertragHinweisSender nimmt
        // document_wa_template_id.
        'employee_contract_wa_template_id' => null,
```

(d) `resources/views/livewire/applicant/applicant-settings-modal.blade.php` — direkt NACH dem schließenden `/>` des Selects mit `wire:model.live="settings.default_contact_user_id"` (vor `{{-- Auto-Assign Owner --}}`) einfügen:

```blade
                    @if(!empty($this->availableWhatsAppTemplates))
                        <x-ui-input-select
                            :value="$settings['employee_contract_wa_template_id'] ?? null"
                            name="settings.employee_contract_wa_template_id"
                            label="Vertrag zur Unterschrift — WhatsApp-Template mit Portal-Link"
                            :options="$this->availableWhatsAppTemplates"
                            optionValue="id"
                            optionLabel="label"
                            :nullable="true"
                            nullLabel="– wie Dokumente –"
                            wire:model.live="settings.employee_contract_wa_template_id"
                        />
                        <p class="text-xs text-[var(--ui-muted)] -mt-2">
                            Geht raus, wenn HR in der Mitarbeiterakte einen Vertrag erstellt. Leer gelassen wird das
                            Dokumente-Template genommen. Vorlage mit &#123;&#123;vorname&#125;&#125; und einem URL-Knopf,
                            dessen Variable am Ende steht.
                        </p>
                    @endif
```

- [ ] **Step 4: Run tests and blade check**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragHinweisSenderTest|DokumentHinweisSenderTest|SettingsModal'`
Expected: PASS.
Run: `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php`
Expected: Exit 0.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Comms/DokumentHinweisSender.php src/Services/Comms/VertragHinweisSender.php src/Models/RecApplicantSettings.php resources/views/livewire/applicant/applicant-settings-modal.blade.php tests/Integration/VertragHinweisSenderTest.php
git commit -m "feat(recruiting): WhatsApp-Hinweis fuer Vertraege aus der Akte, Rueckfall auf das Dokument-Template

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: VertragAusAkte — Anlage, Wächter, Hinweis, Zuschlag-Feld

**Files:**
- Create: `src/Services/VertragsAngaben.php`
- Create: `src/Services/VertragsZeilen.php`
- Create: `src/Services/VertragAusAkte.php`
- Modify: `src/Console/Commands/SeedRecContractExtraFields.php:14-33`
- Test: `tests/Integration/VertragAusAkteTest.php`

**Interfaces:**
- Consumes: `VertragsDeckung::ueberschneidung/istAv/datum` (Task 1), `ZuschlagWert::format` (Task 1), `RecContractTemplate::personalizeFuerAnstellung()` (Task 2), `VertragHinweisSender::sende()` (Task 3), `RecContractTemplate::giltFuerAnstellung()`, `RecContract::resolveContractDates()`
- Produces:
  - `new VertragsAngaben(string $beginn, ?string $ende, float $zuschlag)` (readonly public Properties)
  - `VertragsZeilen::fuerAnstellung(int $anstellungId, ?string $firma = null): list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}>` — nur AV, nicht storniert, optional nach Vorlagen-Firma
  - `VertragAusAkte::erstellen(RecEmployee $anstellung, RecContractTemplate $vorlage, VertragsAngaben $angaben, ?int $userId): RecContract` — wirft `\DomainException` mit HR-tauglicher Meldung
  - `VertragAusAkte::letzterHinweis(): ?string`
  - Vertragsnotiz: `"Aus der Mitarbeiterakte erstellt.\nHinweis: <status>"`

- [ ] **Step 1: Write the failing test**

`tests/Integration/VertragAusAkteTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Console\Commands\SeedRecContractExtraFields;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Services\VertragAusAkte;
use Platform\Recruiting\Services\VertragsAngaben;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Spec Vertrag aus der Akte §2.2, Tests 1, 2, 4. */
final class VertragAusAkteTest extends TestCase
{
    use VertragAusAkteHarness;

    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        $this->sender = new class extends VertragHinweisSender {
            public array $an = [];
            public string $antwort = 'sent';
            public ?\Throwable $wirft = null;
            public function sende(RecEmployee $employee): string
            {
                $this->an[] = (int) $employee->id;
                if ($this->wirft !== null) {
                    throw $this->wirft;
                }
                return $this->antwort;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function service(): VertragAusAkte
    {
        return new VertragAusAkte($this->sender);
    }

    private function maVorlage(): \Platform\Recruiting\Models\RecContractTemplate
    {
        return $this->vorlage('AV-MA-LOG', 'MA', [
            'name' => 'Arbeitsvertrag MA Logistik', 'taetigkeit' => 'logistiker',
            'content' => '<p>{{vorname}} {{nachname}} | {{beginn}}–{{ende}} | {{zuschlag}} €</p>',
            'field_mappings' => [
                'vorname' => 'contact.first_name', 'nachname' => 'contact.last_name',
                'beginn' => 'contract.extra_field.vertragsbeginn', 'ende' => 'contract.extra_field.vertragsende',
                'zuschlag' => 'applicant.zuschlag',
            ],
        ]);
    }

    /** Spec-Test 1 + Review-Focus 4 ("0,60"). */
    public function test_legt_vertrag_an_der_anstellung_an_mit_feldern_link_und_status_sent(): void
    {
        $ma = $this->anstellung();

        $v = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), 42);

        $v = $v->fresh();
        $this->assertSame($ma->id, (int) $v->rec_employee_id);
        $this->assertNull($v->rec_applicant_id);
        $this->assertSame('sent', $v->status);
        $this->assertNotNull($v->sent_at);
        $this->assertSame(42, (int) $v->created_by_user_id);
        $this->assertSame('2026-10-01', $v->getExtraField('vertragsbeginn'));
        $this->assertSame('2026-10-31', $v->getExtraField('vertragsende'));
        $this->assertSame('0,60', $v->getExtraField('zuschlag'));
        $this->assertSame('<p>Mia Muster | 2026-10-01–2026-10-31 | 0,60 €</p>', $v->personalized_content);
        $this->assertSame(1, CorePublicFormLink::query()->where('linkable_type', RecContract::class)->where('linkable_id', $v->id)->count(), 'Signaturlink angelegt');
        $this->assertSame("Aus der Mitarbeiterakte erstellt.\nHinweis: sent", $v->notes);
        $this->assertSame([$ma->id], $this->sender->an);
    }

    public function test_ende_leer_rechnet_wie_resolveContractDates(): void
    {
        $v = $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', null, 0.0), null);

        $this->assertSame('2027-09-30', $v->fresh()->getExtraField('vertragsende'));
        $this->assertSame('0,00', $v->fresh()->getExtraField('zuschlag'), '0 ist erlaubt');
    }

    /** Spec-Test 2. */
    public function test_vorlage_fremder_gesellschaft_wird_abgelehnt(): void
    {
        $ma = $this->anstellung();

        try {
            $this->service()->erstellen($ma, $this->vorlage('AV-default', 'RG'), new VertragsAngaben('2026-10-01', null, 0.6), null);
            $this->fail('Ausnahme erwartet');
        } catch (\DomainException $e) {
            $this->assertSame('Die Vorlage AV-default gehört zur Gesellschaft RG, diese Akte zu MA.', $e->getMessage());
        }
        $this->assertSame(0, RecContract::count());
        $this->assertSame([], $this->sender->an);
    }

    public function test_nur_arbeitsvertraege(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Aus der Akte entstehen nur Arbeitsverträge');

        $this->service()->erstellen($this->anstellung(), $this->vorlage('IFSG', 'MA'), new VertragsAngaben('2026-10-01', null, 0.6), null);
    }

    public function test_inaktive_akte(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Die Akte ist deaktiviert');

        $this->service()->erstellen($this->anstellung(['is_active' => false]), $this->maVorlage(), new VertragsAngaben('2026-10-01', null, 0.6), null);
    }

    public function test_ungueltige_daten(): void
    {
        foreach ([['', null], ['2026-10-01', '2026-09-30'], ['2026-02-30', null]] as [$beginn, $ende]) {
            try {
                $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben($beginn, $ende, 0.6), null);
                $this->fail("Ausnahme erwartet fuer {$beginn}/{$ende}");
            } catch (\DomainException) {
                // erwartet
            }
        }
        $this->assertSame(0, RecContract::count());
    }

    /** Spec-Test 2 — Doppelabdeckung. */
    public function test_doppelabdeckung_wird_abgelehnt_folgemonat_nicht(): void
    {
        $ma = $this->anstellung();
        $alt = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2026-09-30 10:00:00', 'completed_at' => '2026-09-30 10:00:00'],
            ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        try {
            $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-15', null, 0.6), null);
            $this->fail('Ausnahme erwartet');
        } catch (\DomainException $e) {
            $this->assertSame(
                "Für diesen Zeitraum gibt es bereits einen Arbeitsvertrag (#{$alt->id}, bis 31.10.2026) — erst stornieren oder neu ausstellen.",
                $e->getMessage()
            );
        }

        $neu = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-11-01', '2026-11-30', 0.6), null);
        $this->assertSame('sent', $neu->status);
    }

    public function test_stornierter_vertrag_blockiert_nicht(): void
    {
        $ma = $this->anstellung();
        $this->vertragAn($ma, $this->maVorlage(), ['status' => 'cancelled'], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->assertSame('sent', $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null)->status);
    }

    public function test_mit_bewerbung_wird_der_zuschlag_auch_am_bewerber_gesetzt(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 1.0);
        $ma = $this->verknuepfen($this->anstellung(), $b);

        $v = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);

        $this->assertSame($b->id, (int) $v->rec_applicant_id);
        $this->assertEqualsWithDelta(0.6, (float) $b->fresh()->zuschlag, 0.0001);
    }

    /** Spec-Test 4 — ein Fehlschlag ist kein Abbruch, der Status steht in notes. */
    public function test_hinweis_fehlschlag_ist_kein_abbruch(): void
    {
        $this->sender->antwort = 'failed';
        $v1 = $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
        $this->assertSame('sent', $v1->fresh()->status);
        $this->assertStringEndsWith('Hinweis: failed', $v1->fresh()->notes);

        $this->sender->wirft = new \RuntimeException('Meta down');
        $service = $this->service();
        $v2 = $service->erstellen($this->anstellung(['personnel_number' => 'MA4712']), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
        $this->assertStringEndsWith('Hinweis: failed', $v2->fresh()->notes);
        $this->assertSame('failed', $service->letzterHinweis());
    }

    public function test_zahlfeld_bekommt_eine_zahl(): void
    {
        DB::table('core_extra_field_definitions')->where('name', 'zuschlag')->update(['type' => 'number']);
        $this->extraFieldCacheLeeren();

        $v = $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);

        $this->assertSame(0.6, $v->fresh()->getExtraField('zuschlag'), 'Ein Altfeld vom Typ Zahl verwirft "0,60" still — dort steht die Zahl');
    }

    public function test_seed_legt_zuschlag_als_textfeld_an_und_ist_idempotent(): void
    {
        DB::table('core_extra_field_definitions')->delete();
        $this->vorlage('AV-MA-LOG', 'MA');

        $this->seed();
        $this->seed();

        $zeilen = DB::table('core_extra_field_definitions')->where('team_id', $this->team)->where('context_type', RecContract::class)->orderBy('order')->get();
        $this->assertSame(['vertragsbeginn', 'vertragsende', 'zuschlag'], $zeilen->pluck('name')->all());
        $this->assertSame('text', $zeilen->firstWhere('name', 'zuschlag')->type);
    }

    private function seed(): void
    {
        $command = new SeedRecContractExtraFields();
        $command->setLaravel(new VertragAusAkteFakeLaravel());
        $this->assertSame(0, $command->run(new ArrayInput([], $command->getDefinition()), new BufferedOutput()));
    }
}

/** Command::run() braucht runningUnitTests() und make() (Muster EinsatzPruefungFakeLaravel). */
final class VertragAusAkteFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VertragAusAkteTest`
Expected: FAIL / Error `Class "Platform\Recruiting\Services\VertragAusAkte" not found`.

- [ ] **Step 3: Implement**

`src/Services/VertragsAngaben.php`:

```php
<?php

namespace Platform\Recruiting\Services;

/** Was HR im Fenster "Vertrag erstellen" eintraegt (Spec §2.1). Datumswerte Y-m-d. */
final class VertragsAngaben
{
    public function __construct(
        public readonly string $beginn,
        public readonly ?string $ende,
        public readonly float $zuschlag,
    ) {
    }
}
```

`src/Services/VertragsZeilen.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Support\VertragsDeckung;

/**
 * Die Arbeitsvertraege EINER Anstellung in der Form, die VertragsDeckung
 * erwartet. Eine Stelle fuer Neuanlage (Doppelabdeckung) und Einsatz-
 * Pruefung — zwei Lader liefen auseinander.
 *
 * $firma filtert nach der Gesellschaft der VORLAGE: an einer Anstellung
 * haengt ein RG-AV nur, wenn jemand ihn falsch angehaengt hat — er darf dann
 * trotzdem keinen MA-Einsatz decken (Review-Focus 3).
 * Vorlagen mit SoftDelete zaehlen mit (wie ContractAnchorService).
 */
final class VertragsZeilen
{
    /** @return list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}> */
    public static function fuerAnstellung(int $anstellungId, ?string $firma = null): array
    {
        $vertraege = RecContract::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('status', '!=', 'cancelled')
            ->with(['contractTemplate' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')
            ->get();

        $zeilen = [];
        foreach ($vertraege as $v) {
            $vorlage = $v->contractTemplate;
            if ($vorlage === null || !VertragsDeckung::istAv($vorlage->code)) {
                continue;
            }
            if ($firma !== null && strtoupper(trim((string) $vorlage->company)) !== strtoupper(trim($firma))) {
                continue;
            }
            $zeilen[] = [
                'id'         => (int) $v->id,
                'code'       => (string) $vorlage->code,
                'status'     => (string) $v->status,
                'signed_at'  => $v->signed_at?->format('Y-m-d H:i:s'),
                'superseded' => $v->superseded_by_contract_id !== null,
                'beginn'     => VertragsDeckung::datum($v->getExtraField('vertragsbeginn')),
                'ende'       => VertragsDeckung::datum($v->getExtraField('vertragsende')),
            ];
        }

        return $zeilen;
    }
}
```

`src/Services/VertragAusAkte.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\YmdDate;
use Platform\Recruiting\Support\ZuschlagWert;

/**
 * Arbeitsvertrag aus der Mitarbeiterakte (Spec 2026-10-09 §2.2).
 *
 * EIN Vertrag, kein Massenlauf — Eloquent ist hier richtig. Der ZAS-Marker
 * auf contract_signed_at kommt erst beim Unterschreiben (RecContract::saved).
 *
 * Transaktion fuer Anlage, Felder, Inhalt, Link und Status; der WhatsApp-
 * Hinweis laeuft DANACH und darf scheitern — der Vertrag liegt im Portal,
 * der Status steht in notes ("Hinweis: …", keine neue Spalte).
 *
 * Nur Arbeitsvertraege (AV-*), kein IFSG/AT-* (Spec §1 "nicht drin").
 */
final class VertragAusAkte
{
    private ?string $letzterHinweis = null;

    public function __construct(private readonly ?VertragHinweisSender $hinweis = null)
    {
    }

    public function erstellen(RecEmployee $anstellung, RecContractTemplate $vorlage, VertragsAngaben $angaben, ?int $userId): RecContract
    {
        $this->letzterHinweis = null;
        $this->pruefen($anstellung, $vorlage, $angaben);
        $daten = RecContract::resolveContractDates($angaben->beginn, $angaben->ende);

        $vertrag = DB::transaction(function () use ($anstellung, $vorlage, $angaben, $userId, $daten) {
            $vertrag = RecContract::create([
                'rec_applicant_id'         => $anstellung->rec_applicant_id,
                'rec_employee_id'          => $anstellung->id,
                'rec_contract_template_id' => $vorlage->id,
                'team_id'                  => $anstellung->team_id,
                'status'                   => 'pending',
                'personalized_content'     => '',
                'created_by_user_id'       => $userId,
                'notes'                    => 'Aus der Mitarbeiterakte erstellt.',
            ]);

            $vertrag->setExtraField('vertragsbeginn', $daten['vertragsbeginn']);
            $vertrag->setExtraField('vertragsende', $daten['vertragsende']);
            $vertrag->setExtraField('zuschlag', $this->zuschlagFuerFeld($vertrag, $angaben->zuschlag));

            // Wie ReissueContractService: Lohn-Export und Altpfade lesen den
            // Zuschlag am Bewerber.
            $bewerbung = $anstellung->applicant;
            if ($bewerbung !== null) {
                $bewerbung->zuschlag = $angaben->zuschlag;
                $bewerbung->save();
            }

            $vertrag->personalized_content = $vorlage->personalizeFuerAnstellung($anstellung->fresh() ?? $anstellung, $vertrag);
            $vertrag->getOrCreatePublicFormLink();
            $vertrag->status = 'sent';
            $vertrag->sent_at = now();
            $vertrag->save();

            return $vertrag;
        });

        $this->letzterHinweis = $this->hinweisSenden($anstellung);
        $vertrag->notes = trim((string) $vertrag->notes . "\nHinweis: " . $this->letzterHinweis);
        $vertrag->save();

        return $vertrag;
    }

    public function letzterHinweis(): ?string
    {
        return $this->letzterHinweis;
    }

    private function pruefen(RecEmployee $anstellung, RecContractTemplate $vorlage, VertragsAngaben $angaben): void
    {
        if (!$anstellung->is_active) {
            throw new \DomainException('Die Akte ist deaktiviert — es entsteht kein neuer Vertrag.');
        }
        if ((int) $vorlage->team_id !== (int) $anstellung->team_id || !$vorlage->is_active
            || $vorlage->type !== RecContractTemplate::TYPE_CONTRACT) {
            throw new \DomainException('Diese Vorlage steht für diese Akte nicht zur Verfügung.');
        }
        if (!str_starts_with((string) $vorlage->code, 'AV-')) {
            throw new \DomainException('Aus der Akte entstehen nur Arbeitsverträge (Vorlagen-Code AV-…).');
        }
        if (!$vorlage->giltFuerAnstellung($anstellung)) {
            throw new \DomainException(sprintf(
                'Die Vorlage %s gehört zur Gesellschaft %s, diese Akte zu %s.',
                $vorlage->code,
                $vorlage->company,
                trim((string) $anstellung->company) !== '' ? $anstellung->company : '—'
            ));
        }
        if (!YmdDate::isValid($angaben->beginn)) {
            throw new \DomainException('Bitte einen gültigen Vertragsbeginn eintragen.');
        }
        if ($angaben->ende !== null && (!YmdDate::isValid($angaben->ende) || $angaben->ende < $angaben->beginn)) {
            throw new \DomainException('Das Vertragsende muss ein Datum am oder nach dem Beginn sein.');
        }
        if ($angaben->zuschlag < 0 || $angaben->zuschlag >= 1000) {
            throw new \DomainException('Der Zuschlag muss zwischen 0 und 999,99 liegen.');
        }

        $konflikt = VertragsDeckung::ueberschneidung(VertragsZeilen::fuerAnstellung((int) $anstellung->id), $angaben->beginn);
        if ($konflikt !== null) {
            throw new \DomainException(sprintf(
                'Für diesen Zeitraum gibt es bereits einen Arbeitsvertrag (#%d, %s) — erst stornieren oder neu ausstellen.',
                $konflikt['id'],
                $konflikt['ende'] !== null ? 'bis ' . self::deutsch($konflikt['ende']) : 'unbefristet'
            ));
        }
    }

    /**
     * Das Feld ist ab dem Seed Text ("0,60"). Hat ein Team es frueher als
     * Zahl angelegt, verwirft setTypedValue() das Komma still — dort steht
     * deshalb die Zahl.
     */
    private function zuschlagFuerFeld(RecContract $vertrag, float $zuschlag): string
    {
        $typ = $vertrag->getExtraFieldDefinitions()->firstWhere('name', 'zuschlag')?->type;

        return $typ === 'number' ? (string) $zuschlag : ZuschlagWert::format($zuschlag);
    }

    private function hinweisSenden(RecEmployee $anstellung): string
    {
        try {
            return ($this->hinweis ?? app(VertragHinweisSender::class))->sende($anstellung->fresh() ?? $anstellung);
        } catch (\Throwable $e) {
            Log::warning('[VertragAusAkte] Hinweis nicht versendet', [
                'rec_employee_id' => $anstellung->id,
                'error'           => $e->getMessage(),
            ]);

            return DokumentHinweisSender::STATUS_FAILED;
        }
    }

    private static function deutsch(string $ymd): string
    {
        [$j, $m, $t] = explode('-', $ymd) + [null, null, null];

        return $t . '.' . $m . '.' . $j;
    }
}
```

`src/Console/Commands/SeedRecContractExtraFields.php` — `$description` ersetzen durch

```php
    protected $description = 'Legt die Extra-Field-Definitions vertragsbeginn, vertragsende und zuschlag auf rec_contract-Kontext an (für jedes Team das bereits rec_contract_templates hat). Idempotent via Unique-Key (team_id, context_type, context_id, name).';
```

und in `DEFINITIONS` nach dem `vertragsende`-Eintrag ergänzen:

```php
        // Vertrag aus der Akte (Spec 2026-10-09 §2.2): Text, deutsches Format
        // "0,60" — wie ReissueContractService es schreibt und HR es tippt.
        // NICHT 'number': setTypedValue() verwirft "0,60" dort still.
        [
            'name'        => 'zuschlag',
            'label'       => 'Zuschlag (€/Std)',
            'type'        => 'text',
            'is_required' => false,
            'order'       => 30,
        ],
```

- [ ] **Step 4: Run tests**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragAusAkteTest|ReissueContractTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/VertragsAngaben.php src/Services/VertragsZeilen.php src/Services/VertragAusAkte.php src/Console/Commands/SeedRecContractExtraFields.php tests/Integration/VertragAusAkteTest.php
git commit -m "feat(recruiting): VertragAusAkte — Arbeitsvertrag an der Anstellung mit Laufzeit, Zuschlag, Signaturlink und Hinweis

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Unterschrift ohne Bewerbung (ContractSigning + RecContract-Hook)

**Files:**
- Modify: `src/Models/RecContract.php:62-100` (saved-Hook) + neue Methode `anstellung()`
- Modify: `src/Livewire/Public/ContractSigning.php:106` (duzen), `:167-188` (`prefillEmployerDeclaration`), `:409-429` (`applyEmployerDeclaration`), `:444-452` (`applyDayBudget`), `:496-508` (`buildPortalUrl`)
- Modify: `tests/Integration/PortalShellDokumenteTest.php` (Core-Extrafeld-Migrationen ergänzen, falls rot)
- Test: `tests/Integration/VertragUnterschriftOhneBewerbungTest.php`

**Interfaces:**
- Consumes: `VertragsDeckung::istAv/datum` (Task 1), Harness (Task 2), `VertragAusAkte` nicht nötig
- Produces:
  - `RecContract::anstellung(): ?RecEmployee` — `$this->employee ?? $this->applicant?->employee`
  - Hook: zählt bei gesetztem `rec_employee_id` die AV dieser Anstellung; setzt bei Verträgen OHNE Bewerbung zusätzlich `hr_data.contract_end_date` (wenn leer oder älter)
  - `ContractSigning::buildPortalUrl()` → `recruiting.public.portal-shell` mit `portal_token`, wenn `portal_v2_since` gesetzt

- [ ] **Step 1: Write the failing test**

`tests/Integration/VertragUnterschriftOhneBewerbungTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\ContractSigning;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\EmployerDeclaration;
use ReflectionMethod;

/**
 * Spec Vertrag aus der Akte §2.6, Test 7. Probe: RecContract::anstellung()
 * auf `return $this->applicant?->employee;` zurueckdrehen → hier rot.
 */
final class VertragUnterschriftOhneBewerbungTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function unterschreiben(RecContract $v, string $am = '2026-10-02 12:00:00'): void
    {
        $v->update(['status' => 'completed', 'signed_at' => $am, 'completed_at' => $am]);
    }

    public function test_anstellung_kommt_vom_vertrag_dann_vom_bewerber(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $this->assertSame($ma->id, $v->anstellung()?->id);

        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG1']), $b);
        $bewerbungsVertrag = RecContract::create([
            'rec_applicant_id' => $b->id, 'rec_contract_template_id' => $this->vorlage('AV-default', 'RG')->id,
            'team_id' => $this->team, 'status' => 'sent', 'personalized_content' => '',
        ]);
        $this->assertSame($rg->id, $bewerbungsVertrag->anstellung()?->id, 'ohne Anker: wie bisher ueber den Bewerber');
    }

    public function test_unterschrift_ohne_bewerbung_setzt_signed_at_und_befristung(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->unterschreiben($v);

        $hr = $ma->fresh()->hrData;
        $this->assertNotNull($hr, 'HR-Daten angelegt');
        $this->assertSame('2026-10-02', $hr->contract_signed_at?->toDateString());
        $this->assertSame('2026-10-31', $hr->contract_end_date?->toDateString());
    }

    public function test_befristung_wird_nur_verlaengert_nie_verkuerzt(): void
    {
        $ma = $this->anstellung();
        $ma->ensureHrData()->update(['contract_end_date' => '2026-12-31']);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->unterschreiben($v);

        $this->assertSame('2026-12-31', $ma->fresh()->hrData->contract_end_date?->toDateString());
    }

    /** Probe: Zaehlung im Hook zurueck auf $applicant->contracts() → rot (der offene RG-AV blockiert). */
    public function test_alle_av_unterschrieben_zaehlt_je_anstellung(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG9']), $b);
        $ma = $this->verknuepfen($this->anstellung(['personnel_number' => 'MA9']), $b);
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'));          // offen, an RG
        $maVertrag = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));

        $this->unterschreiben($maVertrag);

        $this->assertSame('2026-10-02', $ma->fresh()->hrData?->contract_signed_at?->toDateString(), 'MA ist vollstaendig unterschrieben');
        $this->assertNull($rg->fresh()->hrData?->contract_signed_at, 'RG nicht');
    }

    public function test_zwei_offene_av_derselben_anstellung_warten_aufeinander(): void
    {
        $ma = $this->anstellung();
        $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $zweiter = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'));

        $this->unterschreiben($zweiter);

        $this->assertNull($ma->fresh()->hrData?->contract_signed_at);
    }

    public function test_bewerbungsvertrag_schreibt_die_befristung_nicht_dort_bleibt_der_alte_weg(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG2']), $b);
        $v = $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), [], ['vertragsende' => '2026-10-31']);

        $this->unterschreiben($v);

        $this->assertSame('2026-10-02', $rg->fresh()->hrData?->contract_signed_at?->toDateString());
        $this->assertNull($rg->fresh()->hrData?->contract_end_date, 'Bewerbungs-Vertraege: Erstquelle bleibt avContractEndDate()');
    }

    public function test_arbeitgeber_erklaerung_und_tagekonto_ohne_bewerbung(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $daten = ['par15_has_previous' => false, 'par15_entries' => [], EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::KEY_OTHER => null];

        $signing = new ContractSigning();
        $this->privat($signing, 'applyEmployerDeclaration')->invoke($signing, $v, $daten);
        $this->privat($signing, 'applyDayBudget')->invoke($signing, $v, $daten);

        $ma = $ma->fresh();
        $this->assertTrue($ma->is_main_employer);
        $this->assertSame(70, (int) $ma->hrData?->short_term_days_allowed, 'Default-Grenze 70, §15 "nein"');
    }

    public function test_mount_ohne_bewerbung_duzt_nach_team_und_belegt_vor(): void
    {
        DB::table('rec_applicant_settings')->insert(['team_id' => $this->team, 'settings' => json_encode(['use_informal_address' => true])]);
        $ma = $this->anstellung(['is_main_employer' => true]);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $token = $v->getOrCreatePublicFormLink()->token;

        $signing = new ContractSigning();
        $signing->mount($token);

        $this->assertSame('form', $signing->state);
        $this->assertTrue($signing->duzen);
        $this->assertSame(EmployerDeclaration::ROLE_MAIN, $signing->employerRole);
    }

    public function test_portal_link_fuehrt_ins_neue_portal_wenn_umgestellt(): void
    {
        $ma = $this->anstellung(['portal_v2_since' => '2026-10-01 00:00:00']);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $signing = new ContractSigning();

        $url = $this->privat($signing, 'buildPortalUrl')->invoke($signing, $v);

        $this->assertSame('/recruiting.public.portal-shell/' . http_build_query(['token' => $ma->fresh()->portal_token]), $url);

        $alt = $this->anstellung(['personnel_number' => 'MA4799']);
        $this->assertNull($this->privat($signing, 'buildPortalUrl')->invoke($signing, $this->vertragAn($alt, $this->vorlage('AV-MA-ZAP'))),
            'ohne Bewerbung und ohne neues Portal gibt es keinen Rueckweg');
    }

    private function privat(object $o, string $name): ReflectionMethod
    {
        $m = new ReflectionMethod($o, $name);
        $m->setAccessible(true);

        return $m;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VertragUnterschriftOhneBewerbungTest`
Expected: FAIL — `Call to undefined method …RecContract::anstellung()` und Assertion-Fehler (kein `contract_signed_at`).

- [ ] **Step 3: Implement RecContract**

In `src/Models/RecContract.php` alles von der Kommentarzeile `// ZAS-Export-Snapshot: wenn ein AV-Vertrag signiert wird und der` (Zeile 58) bis einschließlich der schließenden `}` von `booted()` (Zeile 101, direkt vor `public function applicant(): BelongsTo`) ersetzen durch den folgenden Block. Er schließt `booted()` selbst und hängt zwei neue Methoden an:

```php
        // ZAS-Export-Snapshot: wird ein AV unterschrieben und gehoert der
        // Vertrag zu einer Anstellung, steht "Vertrag zurueck am" an deren
        // HR-Daten (contract_signed_at) — sobald ALLE nicht stornierten AV
        // DIESER Anstellung unterschrieben sind (Spec Vertrag aus der Akte
        // §2.6). Ohne Anker (Bewerberstadium, Altbestand) zaehlt wie bisher
        // der Bewerber. Idempotent; ein HR-Override (neuer) bleibt stehen.
        static::saved(function (self $contract) {
            if (!$contract->signed_at) {
                return;
            }
            $employee = $contract->anstellung();
            if (!$employee) {
                return;
            }
            $applicant = $contract->applicant;
            if ($contract->rec_employee_id === null && !$applicant) {
                return;
            }
            $avQuery = $contract->rec_employee_id !== null
                ? self::query()->where('rec_employee_id', $contract->rec_employee_id)
                : $applicant->contracts();
            $avContracts = $avQuery
                ->whereNotIn('status', ['cancelled'])
                ->whereHas('contractTemplate', fn ($q) => $q->where('code', 'like', 'AV-%'))
                ->get();
            if ($avContracts->isEmpty()) {
                return;
            }
            if (!$avContracts->every(fn ($c) => $c->signed_at !== null)) {
                return;
            }
            $latestSigned = $avContracts
                ->filter(fn ($c) => $c->signed_at !== null)
                ->sortByDesc('signed_at')
                ->first()?->signed_at;
            if (!$latestSigned) {
                return;
            }

            $hrData = $employee->ensureHrData();
            $updates = [];
            // Nur ueberschreiben wenn aelter — HR-Manueller Override darf nicht weg
            if ($hrData->contract_signed_at === null || $hrData->contract_signed_at->lt($latestSigned)) {
                $updates['contract_signed_at'] = $latestSigned->toDateString();
            }

            // "Befristet bis" (Spec §2.6) — NUR fuer Vertraege ohne Bewerbung.
            // Bei Bewerbungs-Vertraegen bleibt avContractEndDate() ueber den
            // Bewerber die Erstquelle (ZasEmployeeFieldResolver).
            if ($contract->rec_applicant_id === null) {
                $ende = self::vertragsendeFuerHrDaten($contract);
                if ($ende !== null && ($hrData->contract_end_date === null || $hrData->contract_end_date->toDateString() < $ende)) {
                    $updates['contract_end_date'] = $ende;
                }
            }

            if ($updates !== []) {
                $hrData->update($updates);
            }
        });
    }

    /**
     * Vertragsende eines unterschriebenen AV als Y-m-d, oder null. Darf die
     * Unterschrift nie kippen — der Vertrag ist in diesem Moment gespeichert.
     */
    private static function vertragsendeFuerHrDaten(self $contract): ?string
    {
        try {
            if (!\Platform\Recruiting\Support\VertragsDeckung::istAv($contract->contractTemplate?->code)) {
                return null;
            }

            return \Platform\Recruiting\Support\VertragsDeckung::datum($contract->getExtraField('vertragsende'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[RecContract] Vertragsende nicht gelesen', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Die Anstellung dieses Vertrags (Spec Vertrag aus der Akte §2.6): der
     * Anker am Vertrag, sonst — Bewerberstadium, Altbestand — die Anstellung
     * des Bewerbers. Eine Stelle fuer Unterschrift, Hook und PDF-Zugriff.
     */
    public function anstellung(): ?RecEmployee
    {
        return $this->employee ?? $this->applicant?->employee;
    }
```

Danach folgt unverändert `public function applicant(): BelongsTo`. Prüfen mit `php -l src/Models/RecContract.php` → „No syntax errors".

- [ ] **Step 4: Implement ContractSigning**

In `src/Livewire/Public/ContractSigning.php`:

(a) Zeile 106 ersetzen:

```php
        $this->duzen = $contract->applicant?->usesInformalAddress()
            ?? $contract->employee?->usesInformalAddress()
            ?? false;
```

(b) Rumpf von `prefillEmployerDeclaration()` (der `try { … }`-Block) ersetzen:

```php
        try {
            $applicant = $contract->applicant;
            $employee = $contract->anstellung();
            if (!$applicant && !$employee) {
                return;
            }

            $this->employerRole = EmployerDeclaration::initialRole(
                $employee?->is_main_employer,
                $applicant ? $this->applicantEmploymentType($applicant) : null,
            );
            // Auch den Namen vorbelegen: ohne ihn wuerde ein Durchklicken
            // einen vorhandenen Eintrag leeren — toEmployeeAttributes liefert
            // other_employer bewusst auch dann, wenn das Feld leer ist.
            $this->employerOther = $employee?->other_employer;
        } catch (\Throwable) {
            // Keine Vorbelegung, aber die Seite laedt.
        }
```

(c) In `applyEmployerDeclaration()` die Zeile `$employee = $contract->applicant?->employee;` ersetzen durch

```php
            $employee = $contract->anstellung();
```

(d) In `applyDayBudget()` die Zeile `$employee = $contract->applicant?->employee;` ersetzen durch

```php
            $employee = $contract->anstellung();
```

(e) `buildPortalUrl()` komplett ersetzen:

```php
    /**
     * Rueckweg nach der Unterschrift. Ist die Anstellung auf das neue Portal
     * umgestellt, fuehrt er dorthin (Spec Vertrag aus der Akte §2.6) — sonst
     * wie bisher ins Bewerber-Portal, und ohne Bewerbung gibt es keinen.
     */
    private function buildPortalUrl(RecContract $contract): ?string
    {
        try {
            $anstellung = $contract->anstellung();
            $token = trim((string) $anstellung?->portal_token);
            if ($anstellung !== null && $anstellung->portal_v2_since !== null && $token !== '') {
                return route('recruiting.public.portal-shell', ['token' => $token]);
            }

            $applicant = $contract->applicant;
            if (!$applicant) {
                return null;
            }
            $link = $applicant->getOrCreatePublicFormLink();

            return route('recruiting.public.applicant-portal', ['token' => $link->token]);
        } catch (\Throwable) {
            return null;
        }
    }
```

- [ ] **Step 5: Run tests (targeted + neighbours)**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragUnterschriftOhneBewerbungTest|ContractSigning|PortalShellDokumenteTest|VertragAnDerAnstellung|ReissueContractTest|ReportSignedWithoutEmployee|BackfillEmployerDeclarationTest|BackfillShortTermDayBudgetTest|VertraegeAnAnstellungCommandTest|SignedEmployerDeclarationTest|StatisticsTablesRenderTest'`
Expected: PASS. Falls `PortalShellDokumenteTest` mit `no such table: core_extra_field_definitions` rot wird (der Hook liest jetzt bei Verträgen ohne Bewerbung das Vertragsende — dort haben alle Verträge eine Bewerbung, also sollte es nicht auftreten), in dessen `runRealMigrations()`-Liste die zwei Zeilen
`[$core, 'database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php'],`
`[$core, 'database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php'],`
vor die erste `$own`-Zeile setzen.

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecContract.php src/Livewire/Public/ContractSigning.php tests/Integration/VertragUnterschriftOhneBewerbungTest.php
git commit -m "feat(recruiting): Unterschrift ohne Bewerbung — Anstellung am Vertrag, AV-Zaehlung je Anstellung, Befristet bis, Rueckweg ins neue Portal

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(`tests/Integration/PortalShellDokumenteTest.php` mit aufnehmen, falls in Step 5 geändert.)

---

### Task 6: Portal — Verträge der Person, PDF mit Sitzung, offener Punkt `vertrag:<id>`

**Files:**
- Create: `src/Services/VertragLeser.php`
- Modify: `src/Services/OffenePunkte.php:66-120` (Konstruktor + `stand()`)
- Modify: `src/Livewire/Public/PortalShell.php` (`dokumente()` :713-751, neue Methode `oeffneVertrag()` nach `oeffneDokument()`, `ansichtsDaten()` Zähler `offen`)
- Create: `src/Http/Controllers/VertragPdfController.php`
- Modify: `routes/public.php` (neue Route nach `recruiting.public.dokument`)
- Modify: `resources/views/livewire/public/portal-shell.blade.php:268-270` (Klick) und `:465` (Unterschreiben-Knopf)
- Modify: `tests/Integration/PortalShellDokumenteTest.php` (zwei Erwartungen), `tests/Integration/OffenePunkteTest.php`, `tests/Integration/PortalAufgabenBladeTest.php`, `tests/Integration/PortalPflichtangabenTest.php`, `tests/Integration/EinsatzPruefungTest.php` (Vertrags-Migrationen)
- Test: `tests/Integration/VertragImPortalTest.php`

**Interfaces:**
- Consumes: `RecContract::anstellung()` (Task 5), `PersonScopeResolver::forEmployee()`, `DokumentZugriff::entscheide()`, `DokumentDownloadController::sitzungDeckt()/gesperrt()`, `EinsatzBezug::PAUSE_TAGE`
- Produces:
  - `VertragLeser::vertraege(RecEmployee $e): Collection<RecContract>` (Person, nicht storniert, Vorlage geladen, nach id)
  - `VertragLeser::anstellungsAnzahl(RecEmployee $e): int`
  - `VertragLeser::offenePunkte(RecEmployee $e, ?int $ohneFrischVersandteTage = null, ?string $heute = null): list<array{code:string, label:string, status:string, ko:bool, punkt:string, text:string}>`
  - `VertragLeser::signierLink(RecEmployee $e, int $vertragId): ?string`
  - `VertragLeser::anzeigename(?string $code, ?string $name): string`
  - `OffenePunkte::__construct(…, VertragLeser $vertraege = new VertragLeser())`
  - `PortalShell::oeffneVertrag(int $vertragId): void`
  - Route `recruiting.public.contract-pdf-anstellung` → `GET /mitarbeiter/vertrag/{token}`
  - `VertragPdfController::vertragZumToken(string $token): ?RecContract`, `VertragPdfController::zugriff(?RecContract $vertrag, callable $hatSitzung): int`

- [ ] **Step 1: Write the failing test**

`tests/Integration/VertragImPortalTest.php`:

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
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Http\Controllers\VertragPdfController;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\VertragLeser;
use ReflectionMethod;

/** Spec Vertrag aus der Akte §2.5, Tests 5 und 6; Review-Focus 5. */
final class VertragImPortalTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function dokumente(RecEmployee $e): array
    {
        $shell = new PortalShell();
        $m = new ReflectionMethod($shell, 'dokumente');
        $m->setAccessible(true);

        return $m->invoke($shell, $e);
    }

    private function unterschrieben(RecEmployee $a, string $code = 'AV-MA-LOG'): RecContract
    {
        return $this->vertragAn($a, $this->vorlage($code), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00']);
    }

    public function test_vertrag_ohne_bewerbung_erscheint_pending_ohne_signierlink(): void
    {
        $ma = $this->anstellung();
        $offen = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $pending = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'), ['status' => 'pending', 'sent_at' => null]);
        $this->vertragAn($ma, $this->vorlage('AV-MA-ALT'), ['status' => 'cancelled']);

        $zeilen = $this->dokumente($ma);

        $this->assertSame([$offen->id, $pending->id], array_column($zeilen, 'id'), 'storniert bleibt weg');
        $this->assertStringContainsString('recruiting.public.contract-signing', (string) $zeilen[0]['sign_url']);
        $this->assertNull($zeilen[1]['sign_url'], 'pending: der Link liefe ins Leere');
        $this->assertSame(0, CorePublicFormLink::query()->where('linkable_type', RecContract::class)->where('linkable_id', $pending->id)->count(),
            'Anzeigen legt fuer pending keinen Link an');
        $this->assertSame('Arbeitsvertrag', $zeilen[0]['display_name'], 'eine Anstellung: ohne Gesellschaft');
    }

    public function test_pdf_link_fuer_unterschriebene_ueber_den_vertragstoken(): void
    {
        $ma = $this->anstellung();
        $v = $this->unterschrieben($ma);

        $zeile = $this->dokumente($ma)[0];

        $this->assertNull($zeile['sign_url']);
        $this->assertSame('/recruiting.public.contract-pdf-anstellung/' . http_build_query(['token' => $v->publicFormLink->token]), $zeile['pdf_url']);
    }

    /** Review-Focus 5. Probe: dokumente() zurueck auf $employee->contracts() → Schwester-Richtung rot. */
    public function test_schwester_anstellung_sieht_den_vertrag_mit_gesellschaft_fremde_person_nie(): void
    {
        $ma = $this->anstellung();
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4711']);
        $this->personVerbinden($ma, $rg);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $fremd = $this->anstellung(['personnel_number' => 'MA9999', 'first_name' => 'Fremd']);

        $ausRg = $this->dokumente($rg->fresh());
        $this->assertSame([$v->id], array_column($ausRg, 'id'));
        $this->assertSame('Arbeitsvertrag · MA', $ausRg[0]['display_name']);

        $this->assertSame([], $this->dokumente($fremd));
        $this->assertNull(app(VertragLeser::class)->signierLink($fremd, $v->id), 'fremde ID aus der Anfrage: nichts');
        $this->assertStringContainsString('recruiting.public.contract-signing', (string) app(VertragLeser::class)->signierLink($rg->fresh(), $v->id));
    }

    /** Spec-Test 5 — PDF-Route 403/404. Probe: sitzungDeckt() durch true ersetzen → rot. */
    public function test_pdf_zugriff_nur_mit_sitzung_der_person(): void
    {
        $ma = $this->anstellung();
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4711']);
        $this->personVerbinden($ma, $rg);
        $fremd = $this->anstellung(['personnel_number' => 'MA9999']);
        $v = $this->unterschrieben($ma);
        $token = $v->getOrCreatePublicFormLink()->token;
        $sitzung = fn (int $id) => fn (string $key) => $key === PortalAuth::sessionKey($id);

        $vertrag = VertragPdfController::vertragZumToken($token);
        $this->assertSame($v->id, $vertrag?->id);
        $this->assertSame(200, VertragPdfController::zugriff($vertrag, $sitzung($ma->id)));
        $this->assertSame(200, VertragPdfController::zugriff($vertrag, $sitzung($rg->id)), 'Schwester-Sitzung genuegt');
        $this->assertSame(403, VertragPdfController::zugriff($vertrag, $sitzung($fremd->id)));
        $this->assertSame(403, VertragPdfController::zugriff($vertrag, fn () => false));
        $this->assertSame(404, VertragPdfController::zugriff(VertragPdfController::vertragZumToken('gibt-es-nicht'), $sitzung($ma->id)));

        $offen = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'));
        $this->assertSame(404, VertragPdfController::zugriff($offen, $sitzung($ma->id)), 'nicht unterschrieben: kein PDF');

        DB::table('rec_employees')->where('id', $ma->id)->update(['portal_locked_at' => '2026-10-08 10:00:00']);
        $this->assertSame(403, VertragPdfController::zugriff($vertrag->fresh(), $sitzung($ma->id)), 'Portalsperre');
    }

    /** Spec-Test 6. Probe: Pause in VertragLeser::offenePunkte() entfernen → zweite Zusicherung rot. */
    public function test_offener_punkt_in_fuer_und_trigger_mit_pause_ab_sent_at(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-05 10:00:00']);
        $punkt = ['code' => 'vertrag:' . $v->id, 'label' => 'Arbeitsvertrag · MA', 'status' => 'offen', 'ko' => false, 'punkt' => 'crit', 'text' => 'Lesen und unterschreiben'];

        $this->assertContains($punkt, (new OffenePunkte())->fuer($ma, '2026-10-09')['punkte']);
        $this->assertNotContains($punkt, (new OffenePunkte())->fuerTrigger($ma, '2026-10-09')['punkte'], 'vor 4 Tagen versandt: Trigger schweigt');
        $this->assertContains($punkt, (new OffenePunkte())->fuerTrigger($ma, '2026-10-13')['punkte'], 'nach der Pause wieder dabei');
    }

    public function test_unterschriebener_vertrag_ist_kein_offener_punkt(): void
    {
        $ma = $this->anstellung();
        $this->unterschrieben($ma);

        $this->assertSame([], app(VertragLeser::class)->offenePunkte($ma));
    }

    public function test_blade_klick_und_knopf(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');

        $this->assertStringContainsString("str_starts_with(\$offenerPunkt['code'], 'vertrag:')", $blade);
        $this->assertStringContainsString("'oeffneVertrag(' . (int) substr(\$offenerPunkt['code'], 8) . ')'", $blade);
        $this->assertStringContainsString("\$dokZeigtUnterschreiben = !\$dok['signed_at'] && !empty(\$dok['sign_url']);", $blade);
    }

    public function test_route_traegt_den_token_am_ende(): void
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
            'https://mitarbeiter.rheingedeck.de/recruiting/mitarbeiter/vertrag/abc123',
            $url->route('recruiting.public.contract-pdf-anstellung', ['token' => 'abc123'])
        );
        Facade::setFacadeApplication(Container::getInstance());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VertragImPortalTest`
Expected: FAIL / Error `Class "Platform\Recruiting\Services\VertragLeser" not found`.

- [ ] **Step 3: Implement VertragLeser + OffenePunkte**

`src/Services/VertragLeser.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Die Vertraege EINES Menschen im Portal (Spec Vertrag aus der Akte §2.5):
 * alle Anstellungen des Personen-Umfangs, nicht storniert. Fremde IDs aus
 * der Anfrage laufen ueber signierLink() und damit ueber dieselbe Menge —
 * es gibt keinen zweiten Weg zu einem Vertrag (Muster DokumentLeser).
 */
class VertragLeser
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver())
    {
    }

    /** @return Collection<int, RecContract> */
    public function vertraege(RecEmployee $employee): Collection
    {
        return RecContract::query()
            ->whereIn('rec_employee_id', $this->scope->forEmployee($employee)['ids'])
            ->where('status', '!=', 'cancelled')
            ->with('contractTemplate')
            ->orderBy('id')
            ->get();
    }

    public function anstellungsAnzahl(RecEmployee $employee): int
    {
        return count($this->scope->forEmployee($employee)['ids']);
    }

    /**
     * Versendete Vertraege als offene Punkte. Mit $ohneFrischVersandteTage
     * (Einsatz-Pruefung) fallen Vertraege weg, die in den letzten N Tagen
     * versandt wurden — sie hatten ihren eigenen Hinweis (Muster
     * DokumentLeser::offenePunkte, gemessen an sent_at).
     *
     * @return list<array{code:string, label:string, status:string, ko:bool, punkt:string, text:string}>
     */
    public function offenePunkte(RecEmployee $employee, ?int $ohneFrischVersandteTage = null, ?string $heute = null): array
    {
        $grenze = $ohneFrischVersandteTage === null
            ? null
            : \Carbon\Carbon::parse($heute ?? now()->toDateString())->subDays($ohneFrischVersandteTage);

        $punkte = [];
        foreach ($this->vertraege($employee) as $v) {
            if ($v->status !== 'sent') {
                continue;
            }
            if ($grenze !== null && $v->sent_at !== null && $v->sent_at->greaterThan($grenze)) {
                continue;
            }
            $firma = trim((string) $v->contractTemplate?->company);
            $punkte[] = [
                'code'   => 'vertrag:' . $v->id,
                'label'  => self::anzeigename($v->contractTemplate?->code, $v->contractTemplate?->name) . ($firma !== '' ? ' · ' . $firma : ''),
                'status' => 'offen',
                'ko'     => false,
                'punkt'  => 'crit',
                'text'   => 'Lesen und unterschreiben',
            ];
        }

        return $punkte;
    }

    /** Signierlink eines versendeten Vertrags DIESER Person — sonst null. */
    public function signierLink(RecEmployee $employee, int $vertragId): ?string
    {
        $v = $this->vertraege($employee)->firstWhere('id', $vertragId);
        if ($v === null || $v->status !== 'sent') {
            return null;
        }

        return route('recruiting.public.contract-signing', ['token' => $v->getOrCreatePublicFormLink()->token]);
    }

    public static function anzeigename(?string $code, ?string $name): string
    {
        return match (true) {
            $code !== null && str_starts_with($code, 'AV-') => 'Arbeitsvertrag',
            $code === 'IFSG'                                => 'Infektionsschutzgesetz',
            $code !== null && str_starts_with($code, 'AT-') => 'Zusatzvereinbarung',
            default                                         => $name ?? 'Vertrag',
        };
    }
}
```

`src/Services/OffenePunkte.php`:
(a) Konstruktor-Parameterliste um eine Zeile nach `private readonly DokumentLeser $dokumente = new DokumentLeser(),` ergänzen:

```php
        private readonly VertragLeser $vertraege = new VertragLeser(),
```

(b) In `stand()` direkt nach der Zeile `$punkte = array_merge($punkte, $this->dokumente->offenePunkte($employee, $ohneFrischGemeldeteTage, $heute));` einfügen:

```php

        // Dritte Quelle (Spec Vertrag aus der Akte §2.5): versendete
        // Vertraege zur Unterschrift, Code 'vertrag:<id>'. Dieselbe Pause wie
        // bei Dokumenten, gemessen an sent_at — so meldet die Einsatz-Pruefung
        // den Vertrag mit, ohne eigenen Versand.
        $punkte = array_merge($punkte, $this->vertraege->offenePunkte($employee, $ohneFrischGemeldeteTage, $heute));
```

(c) Docblock von `fuerTrigger()` ergänzen: „… ohne Dokumente UND Verträge, die in den letzten PAUSE_TAGE ihre eigene WhatsApp bekommen haben …".

- [ ] **Step 4: Implement PDF controller + route**

`src/Http/Controllers/VertragPdfController.php`:

```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Support\DokumentZugriff;

/**
 * Vertrags-PDF aus dem neuen Portal (Spec Vertrag aus der Akte §2.5) — ueber
 * den VERTRAGS-Link-Token, aber nur mit verifizierter Portal-Sitzung einer
 * Anstellung derselben Person (Muster DokumentDownloadController). Der Token
 * allein oeffnet nichts; die alte Route ueber den Bewerber-Token bleibt.
 */
class VertragPdfController extends Controller
{
    use RendersContractPdf;

    public function __invoke(Request $request, string $token)
    {
        $vertrag = self::vertragZumToken($token);
        $code = self::zugriff($vertrag, fn (string $key) => $request->session()->has($key));
        abort_if($code !== 200, $code);

        return $this->pdfAntwort($vertrag);
    }

    public static function vertragZumToken(string $token): ?RecContract
    {
        $link = CorePublicFormLink::query()->where('token', $token)->first();
        if (!$link || !$link->isValid()) {
            return null;
        }
        $vertrag = $link->linkable;

        return $vertrag instanceof RecContract ? $vertrag : null;
    }

    /**
     * 404: kein Vertrag, nicht unterschrieben, keine Anstellung (kein
     * Existenz-Orakel). 403: keine Sitzung der Person oder Sperre. Sonst 200.
     */
    public static function zugriff(?RecContract $vertrag, callable $hatSitzung): int
    {
        $anstellung = $vertrag?->anstellung();
        $scopeIds = $anstellung ? app(PersonScopeResolver::class)->forEmployee($anstellung)['ids'] : [];
        $gesperrt = $scopeIds !== []
            && RecEmployee::query()->whereIn('id', $scopeIds)->whereNotNull('portal_locked_at')->exists();

        return DokumentZugriff::entscheide(
            $vertrag !== null && $anstellung !== null && $vertrag->status === 'completed' && $vertrag->signed_at !== null,
            DokumentDownloadController::sitzungDeckt($scopeIds, $hatSitzung),
            DokumentDownloadController::gesperrt($gesperrt, $anstellung),
            false,
        );
    }

    private function pdfAntwort(RecContract $vertrag)
    {
        $vertrag->loadMissing('contractTemplate');
        $anstellung = $vertrag->anstellung();
        $name = trim(($anstellung?->first_name ?? '') . ' ' . ($anstellung?->last_name ?? ''));

        $html = view('recruiting::pdf.contract', [
            'contract'      => $vertrag,
            'candidateName' => $name !== '' ? $name : null,
            'contentForPdf' => $this->prepareContractContentForPdf($vertrag),
        ])->render();

        return Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->download(Str::slug($vertrag->contractTemplate?->name ?? 'Vertrag') . '.pdf');
    }
}
```

`routes/public.php` — direkt nach dem Block der Route `recruiting.public.dokument` (nach `->where('uuid', '[0-9a-fA-F-]{36}');`) einfügen:

```php

// Vertrags-PDF aus dem neuen Portal (Spec Vertrag aus der Akte §2.5): Token
// des VERTRAGS am URL-Ende, Zugriff nur mit Portal-Sitzung der Person.
// Keine Kollision mit /mitarbeiter/neu/{token} und /mitarbeiter/dokument/{uuid}:
// gleiche Segmentzahl, aber anderes festes Wort.
Route::get('/mitarbeiter/vertrag/{token}', \Platform\Recruiting\Http\Controllers\VertragPdfController::class)
    ->name('recruiting.public.contract-pdf-anstellung');
```

- [ ] **Step 5: Implement PortalShell + Blade**

`src/Livewire/Public/PortalShell.php`:
(a) Import ergänzen: `use Platform\Recruiting\Services\VertragLeser;` und `use Platform\Recruiting\Models\RecContract;`.

(b) Nach der Methode `oeffneDokument()` einfügen:

```php
    /**
     * Klick auf einen offenen Punkt 'vertrag:<id>' (Spec Vertrag aus der Akte
     * §2.5). Die ID kommt aus dem Browser — signierLink() prueft sie gegen die
     * Vertraege DIESER Person; eine fremde ID fuehrt nirgendwohin.
     */
    public function oeffneVertrag(int $vertragId): void
    {
        $employee = $this->berechtigterMitarbeiter();
        $link = $employee ? app(VertragLeser::class)->signierLink($employee, $vertragId) : null;
        if ($link === null) {
            return;
        }

        $this->redirect($link);
    }
```

(c) `dokumente()` (Docblock ab „Vertraege DIESER Anstellung“ bis Methodenende) ersetzen:

```php
     * Vertraege DER PERSON (Spec Vertrag aus der Akte §2.5): alle Anstellungen
     * des Personen-Umfangs, nicht storniert — ein Vertrag aus der MA-Akte
     * erscheint auch im Portal der RG-Anstellung, dann mit Gesellschaft im
     * Namen ("Arbeitsvertrag · MA"). Keine Bewerbung noetig.
     * sign_url nur bei 'sent' (pending lief ins Leere); pdf_url ueber den
     * VERTRAGS-Token und die Sitzungspruefung von VertragPdfController.
     *
     * @return list<array{id:int|string, display_name:string, status:string, signed_at:mixed, completed_at:mixed, sign_url:?string, pdf_url:?string}>
     */
    private function dokumente(RecEmployee $employee): array
    {
        $leser = app(VertragLeser::class);
        $mehrere = $leser->anstellungsAnzahl($employee) > 1;

        $contractRows = $leser->vertraege($employee)
            ->map(function (RecContract $c) use ($mehrere) {
                $firma = trim((string) $c->contractTemplate?->company);

                return [
                    'id'           => $c->id,
                    'display_name' => VertragLeser::anzeigename($c->contractTemplate?->code, $c->contractTemplate?->name)
                        . ($mehrere && $firma !== '' ? ' · ' . $firma : ''),
                    'status'       => $c->status,
                    'signed_at'    => $c->signed_at,
                    'completed_at' => $c->completed_at,
                    'sign_url'     => $c->status === 'sent'
                        ? route('recruiting.public.contract-signing', ['token' => $c->getOrCreatePublicFormLink()->token])
                        : null,
                    'pdf_url'      => $c->status === 'completed'
                        ? route('recruiting.public.contract-pdf-anstellung', ['token' => $c->getOrCreatePublicFormLink()->token])
                        : null,
                ];
            })
            ->values()
            ->all();

        if (!$employee->applicant) {
            return $contractRows;
        }

        return TrainingCertificatePortalRows::append(
            $contractRows,
            $this->zertifikatZeilen((int) $employee->applicant->id)
        );
    }
```

(d) In `ansichtsDaten()` nach `$offeneDokumente = …;` einfügen:

```php
        $offeneVertraege = $employee ? count(app(VertragLeser::class)->offenePunkte($employee)) : 0;
```

und `'offen' => $offenAusNachweisen + count($pflichtAufgaben) + $offeneDokumente,` ersetzen durch

```php
            'offen'             => $offenAusNachweisen + count($pflichtAufgaben) + $offeneDokumente + $offeneVertraege,
```

`resources/views/livewire/public/portal-shell.blade.php`:
(a) Den Ausdruck

```php
                                $offenerPunktKlick = str_starts_with($offenerPunkt['code'], 'dokument:')
                                    ? 'oeffneDokument(' . (int) substr($offenerPunkt['code'], 9) . ')'
                                    : "oeffneUpload('" . $offenerPunkt['code'] . "')";
```

ersetzen durch

```php
                                if (str_starts_with($offenerPunkt['code'], 'dokument:')) {
                                    $offenerPunktKlick = 'oeffneDokument(' . (int) substr($offenerPunkt['code'], 9) . ')';
                                } elseif (str_starts_with($offenerPunkt['code'], 'vertrag:')) {
                                    $offenerPunktKlick = 'oeffneVertrag(' . (int) substr($offenerPunkt['code'], 8) . ')';
                                } else {
                                    $offenerPunktKlick = "oeffneUpload('" . $offenerPunkt['code'] . "')";
                                }
```

(b) Die Zeile

```php
                                $dokZeigtUnterschreiben = !$dok['signed_at'] && in_array($dok['status'], ['sent', 'in_progress'], true);
```

ersetzen durch

```php
                                $dokZeigtUnterschreiben = !$dok['signed_at'] && !empty($dok['sign_url']);
```

- [ ] **Step 6: Adjust existing tests**

`tests/Integration/PortalShellDokumenteTest.php`:
- In `test_portal_zeigt_nur_die_vertraege_der_eigenen_anstellung` die Zeile mit `'/contract/' . $vertrag->id . '/pdf'` ersetzen durch
  ```php
        $this->assertStringContainsString('/recruiting/mitarbeiter/vertrag/', (string) $rgDokumente[0]['pdf_url'], 'PDF-Link ueber den Vertrags-Token (Spec Vertrag aus der Akte §2.5)');
  ```
- In `test_anzeigen_legt_nicht_mehr_public_form_link_zeilen_an_als_das_alte_portal` `assertSame(3, …)` ersetzen durch `assertSame(2, CorePublicFormLink::count(), 'Je ein Vertrags-Link fuer sent und completed; der Bewerber-Token wird nicht mehr gebraucht');` und den Docblock-Satz „=> 1 (Bewerber) + 2 (Vertraege) = 3 Zeilen" in „=> 2 Zeilen (seit Vertrag aus der Akte kein Bewerber-Token mehr)" ändern.

`tests/Integration/OffenePunkteTest.php`, `tests/Integration/PortalAufgabenBladeTest.php`, `tests/Integration/EinsatzPruefungTest.php` — direkt nach dem vorhandenen `foreach (['2026_10_09_000001_create_rec_documents_table', …] as $m) { … }`-Block einfügen:

```php
        // Vertrag aus der Akte (Task 6): OffenePunkte liest jetzt auch rec_contracts.
        foreach ([
            '2026_04_15_100000_create_rec_contract_tables',
            '2026_08_12_000001_add_type_to_rec_contract_templates',
            '2026_08_21_000002_add_superseded_by_to_rec_contracts',
            '2026_10_07_000002_add_employee_anchor_to_contracts',
        ] as $m) {
            (require dirname(__DIR__, 2) . '/database/migrations/' . $m . '.php')->up();
        }
```

`tests/Integration/PortalPflichtangabenTest.php` — in der Pfadliste nach `'database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php',` ergänzen:

```php
            // Vertrag aus der Akte: dokumente() und OffenePunkte lesen rec_contracts auch ohne Bewerbung.
            'database/migrations/2026_04_15_100000_create_rec_contract_tables.php',
            'database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php',
            'database/migrations/2026_08_21_000002_add_superseded_by_to_rec_contracts.php',
            'database/migrations/2026_10_07_000002_add_employee_anchor_to_contracts.php',
```

(Steht `create_rec_contract_tables` dort schon, nur die fehlenden ergänzen.)

- [ ] **Step 7: Run tests**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragImPortalTest|PortalShell|Portal|OffenePunkte|EinsatzPruefungTest|AufgabenSender|DokumentLeser|DokumentDownload'`
Expected: PASS. Jede weitere Testklasse, die mit `no such table: rec_contracts` (oder `rec_contract_templates`) rot wird, bekommt denselben Vier-Migrationen-Block wie oben an der Stelle, an der sie ihre Dokument-Migrationen fährt.
Run: `php tools/blade-check.php resources/views/livewire/public/portal-shell.blade.php`
Expected: Exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Services/VertragLeser.php src/Services/OffenePunkte.php src/Livewire/Public/PortalShell.php src/Http/Controllers/VertragPdfController.php routes/public.php resources/views/livewire/public/portal-shell.blade.php tests/Integration/VertragImPortalTest.php tests/Integration/PortalShellDokumenteTest.php tests/Integration/OffenePunkteTest.php tests/Integration/PortalAufgabenBladeTest.php tests/Integration/PortalPflichtangabenTest.php tests/Integration/EinsatzPruefungTest.php
git commit -m "feat(recruiting): Portal zeigt Vertraege der Person, Unterschrift als offener Punkt, PDF nur mit Portal-Sitzung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Akte — Fenster „Vertrag erstellen", Listen mit Beginn/Ende/Zuschlag, Stornieren, HR-PDF

**Files:**
- Modify: `src/Livewire/Employees/Show.php` (Properties nach `$reissueOpenMode`, `mount()` :413-420, `signedContracts()` :465-501, `openContracts()` :512-541, `openReissueModal()` :573-599, neue Methoden nach `reissueContract()`)
- Modify: `resources/views/livewire/employees/show.blade.php` (vor `{{-- Signierte Vertraege (Download) --}}` :441, in beiden Listen, nach dem Reissue-Modal :778)
- Modify: `src/Http/Controllers/VertragPdfController.php` (HR-Methoden)
- Modify: `routes/web.php` (vor `/employees/{employee}` :78)
- Test: `tests/Integration/VertragAusAkteOberflaecheTest.php`

**Interfaces:**
- Consumes: `VertragAusAkte`, `VertragsAngaben` (Task 4), `VertragsVorbelegung::fuerEinsatz`, `ZuschlagWert`, `VertragsDeckung::datum` (Task 1), `VertragHinweisSender` (Task 3)
- Produces:
  - `Show` Properties: `bool $vertragModalShow`, `?string $vertragVorlageId`, `?string $vertragBeginn`, `?string $vertragEnde`, `?string $vertragZuschlag`
  - `Show::vertragsVorlagen(): list<array{id:int, label:string}>` (#[Computed])
  - `Show::openVertragModal(?string $einsatztag = null): void`, `closeVertragModal(): void`, `vertragErstellen(): void`, `vertragStornieren(int $contractId): void`
  - Listenzeilen tragen zusätzlich `beginn`, `ende` (d.m.Y|null), `zuschlag` ("0,60"|null); offene zusätzlich `can_cancel`
  - Akte-Aufruf `?vertrag=neu&einsatz=Y-m-d` öffnet das Fenster vorbelegt (für Task 8)
  - Route `recruiting.employees.vertrag-pdf` (`/employees/vertraege/{contractId}/pdf`), `VertragPdfController::hrVertrag(int $teamId, int $contractId): ?RecContract`

- [ ] **Step 1: Write the failing test**

`tests/Integration/VertragAusAkteOberflaecheTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\VertragPdfController;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;

/** Spec Vertrag aus der Akte §2.1 und §2.7 — die Akte ohne Livewire-Laufzeit (Muster VertragAnDerAnstellungTest). */
final class VertragAusAkteOberflaecheTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        Container::getInstance()->instance(VertragHinweisSender::class, new class extends VertragHinweisSender {
            public function sende(RecEmployee $employee): string { return 'altes_portal'; }
        });
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(VertragHinweisSender::class);
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function akte(RecEmployee $e): Show
    {
        $show = new Show();
        $show->employeeId = $e->id;

        return $show;
    }

    private function maVorlage(array $set = []): RecContractTemplate
    {
        return $this->vorlage('AV-MA-LOG', 'MA', array_merge(['name' => 'AV MA Logistik', 'taetigkeit' => 'logistiker'], $set));
    }

    public function test_fenster_mit_einsatztag_ist_vorbelegt(): void
    {
        $this->maVorlage();
        $akte = $this->akte($this->anstellung());

        $akte->openVertragModal('2026-10-20');

        $this->assertTrue($akte->vertragModalShow);
        $this->assertSame('2026-10-01', $akte->vertragBeginn);
        $this->assertSame('2026-10-31', $akte->vertragEnde);
        $this->assertSame('', $akte->vertragZuschlag);
        $this->assertNotSame('', $akte->vertragVorlageId, 'genau eine Vorlage: vorgewaehlt');

        $leer = $this->akte($this->anstellung(['personnel_number' => 'MA2']));
        $leer->openVertragModal('kaputt');
        $this->assertSame('', $leer->vertragBeginn);
    }

    public function test_vertragsarten_nur_aktive_av_der_gesellschaft(): void
    {
        $passend = $this->maVorlage();
        $this->vorlage('AV-default', 'RG');
        $this->vorlage('AV-MA-ALT', 'MA', ['is_active' => false]);
        $this->vorlage('IFSG', 'MA');

        $this->assertSame([['id' => $passend->id, 'label' => 'AV MA Logistik (Logistiker)']], $this->akte($this->anstellung())->vertragsVorlagen());
    }

    /** Review-Focus 4. */
    public function test_erstellen_mit_komma_zuschlag_landet_in_der_liste(): void
    {
        $vorlage = $this->maVorlage();
        $ma = $this->anstellung();
        $akte = $this->akte($ma);
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragZuschlag = '0,60';

        $akte->vertragErstellen();

        $this->assertNull($akte->flashError);
        $vertrag = RecContract::query()->where('rec_employee_id', $ma->id)->firstOrFail();
        $this->assertStringContainsString('Arbeitsvertrag #' . $vertrag->id . ' erstellt', (string) $akte->flash);
        $this->assertStringContainsString('noch nicht auf das neue Portal umgestellt', (string) $akte->flash);
        $this->assertFalse($akte->vertragModalShow);

        $zeile = $this->akte($ma)->openContracts()[0];
        $this->assertSame($vertrag->id, $zeile['id']);
        $this->assertSame('01.10.2026', $zeile['beginn']);
        $this->assertSame('31.10.2026', $zeile['ende']);
        $this->assertSame('0,60', $zeile['zuschlag']);
        $this->assertNotNull($zeile['sign_url']);
        $this->assertTrue($zeile['can_cancel']);
        $this->assertFalse($zeile['can_reissue'], 'ohne Bewerbung kein Neu-Ausstellen (ReissueContractService braucht sie)');
    }

    public function test_eingabefehler_und_inaktive_akte(): void
    {
        $vorlage = $this->maVorlage();
        $akte = $this->akte($this->anstellung());
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;

        $akte->vertragZuschlag = 'abc';
        $akte->vertragErstellen();
        $this->assertSame('Zuschlag muss eine Zahl sein (z. B. 0,60).', $akte->flashError);

        $akte->vertragZuschlag = '0,60';
        $akte->vertragVorlageId = '';
        $akte->vertragErstellen();
        $this->assertSame('Bitte eine Vertragsart wählen.', $akte->flashError);

        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragBeginn = '';
        $akte->vertragErstellen();
        $this->assertSame('Bitte einen Vertragsbeginn eintragen.', $akte->flashError);
        $this->assertSame(0, RecContract::count());

        $inaktiv = $this->akte($this->anstellung(['personnel_number' => 'MA3', 'is_active' => false]));
        $inaktiv->openVertragModal();
        $this->assertFalse($inaktiv->vertragModalShow);
        $this->assertSame('Die Akte ist deaktiviert — es entsteht kein neuer Vertrag.', $inaktiv->flashError);
    }

    public function test_doppelabdeckung_kommt_als_meldung(): void
    {
        $vorlage = $this->maVorlage();
        $ma = $this->anstellung();
        $this->vertragAn($ma, $vorlage, [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);
        $akte = $this->akte($ma);
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragZuschlag = '0,60';

        $akte->vertragErstellen();

        $this->assertStringContainsString('bereits einen Arbeitsvertrag', (string) $akte->flashError);
        $this->assertTrue($akte->vertragModalShow, 'Fenster bleibt offen');
    }

    public function test_unterschriebene_ohne_bewerbung_mit_hr_pdf_und_altcode_zuschlag(): void
    {
        $ma = $this->anstellung();
        $neu = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00'],
            ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31', 'zuschlag' => '0,60']);
        $alt = $this->vertragAn($ma, $this->vorlage('AV-060', 'MA'), ['status' => 'completed', 'signed_at' => '2025-01-02 12:00:00', 'completed_at' => '2025-01-02 12:00:00']);

        $zeilen = collect($this->akte($ma)->signedContracts())->keyBy('id');

        $this->assertSame('/recruiting.employees.vertrag-pdf/' . http_build_query(['contractId' => $neu->id]), $zeilen[$neu->id]['pdf_url']);
        $this->assertSame('0,60', $zeilen[$neu->id]['zuschlag']);
        $this->assertSame('0,60', $zeilen[$alt->id]['zuschlag'], 'Alt-AV ohne Feld: aus dem Code');
        $this->assertNull($zeilen[$alt->id]['beginn']);
    }

    public function test_offenen_vertrag_stornieren_unterschriebenen_nicht(): void
    {
        $ma = $this->anstellung();
        $offen = $this->vertragAn($ma, $this->maVorlage());
        $fertig = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00']);
        $akte = $this->akte($ma);

        $akte->vertragStornieren($offen->id);
        $this->assertSame('cancelled', $offen->fresh()->status);
        $this->assertStringContainsString('storniert', (string) $akte->flash);

        $akte->vertragStornieren($fertig->id);
        $this->assertSame('completed', $fertig->fresh()->status);
        $this->assertSame('Nur offene Verträge dieser Akte lassen sich hier stornieren.', $akte->flashError);
    }

    public function test_neu_ausstellen_ohne_bewerbung_meldet_statt_abzustuerzen(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->maVorlage());
        $akte = $this->akte($ma);

        $akte->openReissueModal($v->id);

        $this->assertFalse($akte->reissueModalShow);
        $this->assertSame('Neu ausstellen geht nur bei Verträgen aus einer Bewerbung — diesen Vertrag stornieren und neu erstellen.', $akte->flashError);
    }

    public function test_hr_pdf_nur_im_eigenen_team(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00']);
        $offen = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'));

        $this->assertSame($v->id, VertragPdfController::hrVertrag($this->team, $v->id)?->id);
        $this->assertNull(VertragPdfController::hrVertrag($this->team + 1, $v->id));
        $this->assertNull(VertragPdfController::hrVertrag($this->team, $offen->id));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter VertragAusAkteOberflaecheTest`
Expected: FAIL — `Call to undefined method …Show::openVertragModal()`.

- [ ] **Step 3: Implement the HR PDF**

In `src/Http/Controllers/VertragPdfController.php` nach `__invoke()` einfügen:

```php
    /** HR-Download aus der Akte (Spec §2.7) — im Modul-Guard, Mandant = aktives Team, sonst 404. */
    public function hr(int $contractId)
    {
        $vertrag = self::hrVertrag((int) auth()->user()->currentTeam->id, $contractId);
        abort_if($vertrag === null, 404);

        return $this->pdfAntwort($vertrag);
    }

    public static function hrVertrag(int $teamId, int $contractId): ?RecContract
    {
        return RecContract::query()
            ->where('team_id', $teamId)
            ->where('status', 'completed')
            ->with('contractTemplate')
            ->find($contractId);
    }
```

`routes/web.php` — direkt VOR `Route::get('/employees/{employee}', \Platform\Recruiting\Livewire\Employees\Show::class)` einfügen:

```php
// Vertrag aus der Akte (Spec 2026-10-09 §2.7): PDF fuer HR auch ohne Bewerbung.
Route::get('/employees/vertraege/{contractId}/pdf', [\Platform\Recruiting\Http\Controllers\VertragPdfController::class, 'hr'])
    ->name('recruiting.employees.vertrag-pdf')
    ->whereNumber('contractId');
```

- [ ] **Step 4: Implement Employees/Show**

(a) Imports ergänzen:

```php
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Services\VertragAusAkte;
use Platform\Recruiting\Services\VertragsAngaben;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\VertragsVorbelegung;
use Platform\Recruiting\Support\VorlagenMerkmale;
use Platform\Recruiting\Support\YmdDate;
use Platform\Recruiting\Support\ZuschlagWert;
```

(b) Nach `public bool $reissueOpenMode = false;` einfügen:

```php

    // Vertrag aus der Akte (Spec 2026-10-09 §2.1). Strings, nicht int/Datum:
    // ein geleertes Select/type=date schickt null bzw. '' — eine getypte
    // int-Property wuerde beim Hydrieren mit TypeError abbrechen, und an
    // einen Datums-Cast wird nie gebunden.
    public bool $vertragModalShow = false;
    public ?string $vertragVorlageId = '';
    public ?string $vertragBeginn = '';
    public ?string $vertragEnde = '';
    public ?string $vertragZuschlag = '';
```

(c) `mount()` ersetzen:

```php
    public function mount(int $employee): void
    {
        $this->employeeId = $employee;
        $emp = $this->employee();
        if ($emp) {
            $this->loadFieldValues($emp);

            // Vom HR-Schreibtisch (Fall "Vertrag fehlt fuer Einsatz"): Fenster
            // gleich offen, vorbelegt aus dem Einsatztag (Spec §3.3).
            if (request()->query('vertrag') === 'neu') {
                $einsatz = request()->query('einsatz');
                $this->openVertragModal(is_string($einsatz) ? $einsatz : null);
            }
        }
    }
```

(d) `signedContracts()` (Docblock bleibt, Rumpf ersetzen):

```php
    #[Computed]
    public function signedContracts(): array
    {
        $emp = $this->employee();
        if (!$emp) {
            return [];
        }
        $applicantToken = null;

        return $emp->contracts
            ->filter(fn ($c) => $c->status === 'completed' && $c->signed_at)
            ->map(function ($c) use ($emp, &$applicantToken) {
                $code = $c->contractTemplate?->code;
                $displayName = match (true) {
                    $code !== null && str_starts_with($code, 'AV-') => 'Arbeitsvertrag (' . $code . ')',
                    $code === 'IFSG'                                => 'Infektionsschutzgesetz',
                    $code !== null && str_starts_with($code, 'AT-') => 'Zusatzvereinbarung (' . $code . ')',
                    default                                         => $c->contractTemplate?->name ?? 'Vertrag',
                };
                $isAv = $code !== null && (str_starts_with($code, 'AV-') || $code === 'AV');
                $ausBewerbung = $c->rec_applicant_id !== null && $emp->applicant !== null
                    && (int) $c->rec_applicant_id === (int) $emp->applicant->id;
                if ($ausBewerbung) {
                    // Alter Weg: ContractPdfController validiert ueber den Bewerber-Token.
                    $applicantToken ??= $emp->applicant->getOrCreatePublicFormLink()->token;
                    $pdfUrl = route('recruiting.public.contract-pdf', ['token' => $applicantToken, 'contractId' => $c->id]);
                } else {
                    $pdfUrl = route('recruiting.employees.vertrag-pdf', ['contractId' => $c->id]);
                }

                return [
                    'id'            => $c->id,
                    'display_name'  => $displayName,
                    'merkmale'      => VorlagenMerkmale::zeile($c->contractTemplate?->company, $c->contractTemplate?->taetigkeit),
                    'signed_at'     => $c->signed_at,
                    'pdf_url'       => $pdfUrl,
                    'superseded_by' => $c->superseded_by_contract_id,
                    // Ersetzen gilt nur fuer Arbeitsvertraege, nur einmal und
                    // nur mit Bewerbung (ReissueContractService braucht sie).
                    'can_reissue'   => $isAv && $c->superseded_by_contract_id === null && $c->rec_applicant_id !== null,
                ] + $this->laufzeitUndZuschlag($c);
            })
            ->values()
            ->toArray();
    }
```

(e) `openContracts()` (Docblock bleibt, Rumpf ersetzen):

```php
    #[Computed]
    public function openContracts(): array
    {
        $emp = $this->employee();
        if (!$emp) {
            return [];
        }

        return $emp->contracts
            ->filter(fn ($c) => !in_array($c->status, ['completed', 'cancelled'], true))
            ->map(function ($c) {
                $code = $c->contractTemplate?->code;

                return [
                    'id'           => $c->id,
                    'display_name' => $c->contractTemplate?->name ?? 'Vertrag',
                    'merkmale'     => VorlagenMerkmale::zeile($c->contractTemplate?->company, $c->contractTemplate?->taetigkeit),
                    'code'         => $code,
                    'status'       => $c->status,
                    'sent_at'      => $c->sent_at,
                    'can_reissue'  => $code !== null && (str_starts_with($code, 'AV-') || $code === 'AV') && $c->rec_applicant_id !== null,
                    'can_cancel'   => true,
                    'sign_url'     => $c->publicFormLink
                        ? route('recruiting.public.contract-signing', ['token' => $c->publicFormLink->token])
                        : null,
                ] + $this->laufzeitUndZuschlag($c);
            })
            ->values()
            ->toArray();
    }

    /**
     * Beginn, Ende, Zuschlag aus den Vertrags-Extrafeldern (Spec §2.7).
     * Alt-AV ohne Zuschlagsfeld: aus dem Code (AV-060 → 0,60).
     *
     * @return array{beginn:?string, ende:?string, zuschlag:?string}
     */
    private function laufzeitUndZuschlag(RecContract $c): array
    {
        $beginn = VertragsDeckung::datum($c->getExtraField('vertragsbeginn'));
        $ende = VertragsDeckung::datum($c->getExtraField('vertragsende'));
        $zuschlag = ZuschlagWert::lesen($c->getExtraField('zuschlag')) ?? ZuschlagWert::ausAvCode($c->contractTemplate?->code);

        return [
            'beginn'   => $beginn !== null ? \Carbon\Carbon::parse($beginn)->format('d.m.Y') : null,
            'ende'     => $ende !== null ? \Carbon\Carbon::parse($ende)->format('d.m.Y') : null,
            'zuschlag' => $zuschlag !== null ? ZuschlagWert::format($zuschlag) : null,
        ];
    }
```

(f) In `openReissueModal()` nach dem Block `if (!$contract) { … return; }` einfügen:

```php
        if ($contract->rec_applicant_id === null || $emp->applicant === null) {
            $this->flashError = 'Neu ausstellen geht nur bei Verträgen aus einer Bewerbung — diesen Vertrag stornieren und neu erstellen.';

            return;
        }
```

(g) Nach `reissueContract()` (vor `public function updatedDokumentKategorie()`) einfügen:

```php
    /**
     * Die Vertragsarten dieser Akte (Spec §2.1): aktive Vorlagen vom Typ
     * Vertrag, Code AV-*, Gesellschaft = Gesellschaft der Akte.
     *
     * @return list<array{id:int, label:string}>
     */
    #[Computed]
    public function vertragsVorlagen(): array
    {
        $emp = $this->employee();
        $firma = trim((string) $emp?->company);
        if (!$emp || $firma === '') {
            return [];
        }

        return RecContractTemplate::query()
            ->where('team_id', $emp->team_id)
            ->where('type', RecContractTemplate::TYPE_CONTRACT)
            ->where('is_active', true)
            ->where('code', 'like', 'AV-%')
            ->where('company', $firma)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'taetigkeit'])
            ->map(fn (RecContractTemplate $t) => [
                'id'    => (int) $t->id,
                'label' => $t->name . (trim((string) $t->taetigkeit) !== '' ? ' (' . VorlagenMerkmale::taetigkeit($t->taetigkeit) . ')' : ''),
            ])
            ->values()
            ->all();
    }

    /** $einsatztag (Y-m-d) belegt Beginn/Ende vor — nur Vorbelegung, HR aendert frei. */
    public function openVertragModal(?string $einsatztag = null): void
    {
        $this->flash = null;
        $this->flashError = null;

        $emp = $this->employee();
        if (!$emp) {
            $this->flashError = 'Mitarbeiter nicht gefunden.';

            return;
        }
        if (!$emp->is_active) {
            $this->flashError = 'Die Akte ist deaktiviert — es entsteht kein neuer Vertrag.';

            return;
        }

        $vorbelegung = $einsatztag !== null ? VertragsVorbelegung::fuerEinsatz($einsatztag) : null;
        $vorlagen = $this->vertragsVorlagen();

        $this->vertragVorlageId = count($vorlagen) === 1 ? (string) $vorlagen[0]['id'] : '';
        $this->vertragBeginn = $vorbelegung['beginn'] ?? '';
        $this->vertragEnde = $vorbelegung['ende'] ?? '';
        $this->vertragZuschlag = '';
        $this->vertragModalShow = true;
    }

    public function closeVertragModal(): void
    {
        $this->vertragModalShow = false;
        $this->vertragVorlageId = '';
        $this->vertragBeginn = '';
        $this->vertragEnde = '';
        $this->vertragZuschlag = '';
    }

    /** "Erstellen und senden" — die Arbeit macht VertragAusAkte, hier nur Eingabe und Rueckmeldung. */
    public function vertragErstellen(): void
    {
        $this->flash = null;
        $this->flashError = null;

        $emp = $this->employee();
        if (!$emp) {
            $this->flashError = 'Mitarbeiter nicht gefunden.';

            return;
        }

        $vorlageId = (int) $this->vertragVorlageId;
        $erlaubt = in_array($vorlageId, array_column($this->vertragsVorlagen(), 'id'), true);
        $vorlage = $erlaubt ? RecContractTemplate::query()->where('team_id', $emp->team_id)->find($vorlageId) : null;
        if (!$vorlage) {
            $this->flashError = 'Bitte eine Vertragsart wählen.';

            return;
        }

        $beginn = trim((string) $this->vertragBeginn);
        if (!YmdDate::isValid($beginn)) {
            $this->flashError = 'Bitte einen Vertragsbeginn eintragen.';

            return;
        }
        $ende = trim((string) $this->vertragEnde);

        $zuschlag = ZuschlagWert::ausEingabe($this->vertragZuschlag);
        if ($zuschlag === null) {
            $this->flashError = 'Zuschlag muss eine Zahl sein (z. B. 0,60).';

            return;
        }

        $service = app(VertragAusAkte::class);
        try {
            $vertrag = $service->erstellen($emp, $vorlage, new VertragsAngaben($beginn, $ende !== '' ? $ende : null, $zuschlag), auth()->id());
        } catch (\DomainException $e) {
            $this->flashError = $e->getMessage();

            return;
        }

        $this->closeVertragModal();
        unset($this->employee, $this->signedContracts, $this->openContracts);

        $this->flash = 'Arbeitsvertrag #' . $vertrag->id . ' erstellt — er liegt im Portal zur Unterschrift. '
            . self::hinweisSatz($service->letzterHinweis());
    }

    /** Nur offene Vertraege DIESER Akte (Spec §2.1 "umkehrbar") — der Signaturlink stirbt mit. */
    public function vertragStornieren(int $contractId): void
    {
        $this->flash = null;
        $this->flashError = null;

        $vertrag = $this->employee()?->contracts->firstWhere('id', $contractId);
        if (!$vertrag || in_array($vertrag->status, ['completed', 'cancelled'], true)) {
            $this->flashError = 'Nur offene Verträge dieser Akte lassen sich hier stornieren.';

            return;
        }

        $vertrag->status = 'cancelled';
        $vertrag->notes = trim((string) $vertrag->notes . "\nStorniert in der Mitarbeiterakte am " . now()->format('d.m.Y H:i') . '.');
        $vertrag->save();

        unset($this->employee, $this->signedContracts, $this->openContracts);
        $this->flash = 'Vertrag #' . $vertrag->id . ' storniert — sein Signaturlink funktioniert nicht mehr.';
    }

    private static function hinweisSatz(?string $status): string
    {
        return match ($status) {
            'sent'               => 'Die WhatsApp mit dem Portal-Link ist raus.',
            'altes_portal'       => 'Keine WhatsApp: der Mitarbeiter ist noch nicht auf das neue Portal umgestellt — den Link unten unter „Offene Verträge" kopieren.',
            'no_phone'           => 'Keine WhatsApp: in der Akte steht keine gültige Handynummer.',
            'nicht_konfiguriert' => 'Keine WhatsApp: in den Einstellungen ist kein Template für Verträge oder Dokumente hinterlegt.',
            'vorlage_untauglich' => 'Keine WhatsApp: das hinterlegte Template passt nicht (Portal-Knopf fehlt).',
            default              => 'Die WhatsApp konnte nicht verschickt werden — den Link unten unter „Offene Verträge" kopieren.',
        };
    }
```

- [ ] **Step 5: Implement the Blade**

`resources/views/livewire/employees/show.blade.php`:

(a) Direkt VOR `{{-- Signierte Vertraege (Download) --}}` einfügen:

```blade
            {{-- Vertrag aus der Akte (Spec 2026-10-09 §2.1) --}}
            <div class="mt-6 flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-[var(--ui-secondary)]">Verträge</h3>
                @if($employee?->is_active)
                    <button type="button" wire:click="openVertragModal"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-[var(--ui-border)] text-[var(--ui-secondary)] bg-white text-xs font-medium rounded-md hover:bg-[var(--ui-muted-5)] transition-colors">
                        @svg('heroicon-o-document-plus', 'w-3.5 h-3.5')
                        Vertrag erstellen
                    </button>
                @else
                    <span class="text-xs text-[var(--ui-muted)]">Akte deaktiviert — kein neuer Vertrag</span>
                @endif
            </div>
```

(b) In der Liste „Unterschriebene Vertraege" direkt nach dem `@if($c['superseded_by']) … @endif`-Block (innerhalb des `<div class="flex items-center gap-2 text-sm flex-wrap">`) einfügen:

```blade
                                    @php
                                        $cLaufzeit = implode(' · ', array_filter([
                                            $c['beginn'] ? 'Beginn ' . $c['beginn'] : null,
                                            $c['ende'] ? 'Ende ' . $c['ende'] : null,
                                            $c['zuschlag'] ? 'Zuschlag ' . $c['zuschlag'] . ' €' : null,
                                        ]));
                                    @endphp
                                    @if($cLaufzeit !== '')
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $cLaufzeit }}</span>
                                    @endif
```

(c) In der Liste „Offene Vertraege" direkt nach dem `<span>` mit `versendet am` / `noch nicht versendet` einfügen:

```blade
                                    @php
                                        $ocLaufzeit = implode(' · ', array_filter([
                                            $oc['beginn'] ? 'Beginn ' . $oc['beginn'] : null,
                                            $oc['ende'] ? 'Ende ' . $oc['ende'] : null,
                                            $oc['zuschlag'] ? 'Zuschlag ' . $oc['zuschlag'] . ' €' : null,
                                        ]));
                                    @endphp
                                    @if($ocLaufzeit !== '')
                                        <span class="text-xs text-[var(--ui-muted)]">{{ $ocLaufzeit }}</span>
                                    @endif
```

und im rechten Knopf-Bereich (`<div class="flex items-center gap-2">`) VOR `@if($oc['can_reissue'])` einfügen:

```blade
                                    @if($oc['can_cancel'])
                                        <button type="button" wire:click="vertragStornieren({{ $oc['id'] }})"
                                                wire:confirm="Vertrag stornieren? Der Signaturlink funktioniert danach nicht mehr."
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-red-200 text-red-700 bg-white text-xs font-medium rounded-md hover:bg-red-50 transition-colors">
                                            Stornieren
                                        </button>
                                    @endif
```

(d) Direkt NACH dem schließenden `</x-ui-modal>` des Reissue-Fensters (vor `{{-- Sticky Save --}}`) einfügen:

```blade
            {{-- Vertrag erstellen (Spec 2026-10-09 §2.1) --}}
            <x-ui-modal size="sm" model="vertragModalShow">
                <x-slot name="header">Vertrag erstellen</x-slot>
                @php
                    $vertragVorlagen = $this->vertragsVorlagen;
                    $vertragFirma = trim((string) ($employee?->company ?? ''));
                @endphp
                <div class="p-4 space-y-4">
                    @if(empty($vertragVorlagen))
                        <p class="text-sm text-amber-700">
                            Für die Gesellschaft {{ $vertragFirma !== '' ? $vertragFirma : '—' }} ist keine Arbeitsvertrags-Vorlage angelegt.
                        </p>
                    @else
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Vertragsart</label>
                            <select wire:model="vertragVorlageId" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm bg-white">
                                <option value="">– Vertragsart wählen –</option>
                                @foreach($vertragVorlagen as $vv)
                                    <option value="{{ $vv['id'] }}">{{ $vv['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Beginn</label>
                            <input type="date" wire:model="vertragBeginn"
                                   class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Ende</label>
                            <input type="date" wire:model="vertragEnde"
                                   class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
                            <p class="text-xs text-[var(--ui-muted)] mt-1">
                                Leer = ein Jahr ab Beginn, zum Monatsende. Für MA-Monatsverträge den Monatsletzten eintragen.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-[var(--ui-secondary)] mb-1">Zuschlag (€/Std)</label>
                            <input type="text" wire:model="vertragZuschlag" placeholder="0,60"
                                   class="w-full border border-[var(--ui-border)] rounded-md px-3 py-1.5 text-sm" />
                            <p class="text-xs text-[var(--ui-muted)] mt-1">0 ist erlaubt.</p>
                        </div>
                    @endif
                </div>
                <x-slot name="footer">
                    <div class="flex items-center justify-end gap-2">
                        <x-ui-button variant="secondary" wire:click="closeVertragModal">Abbrechen</x-ui-button>
                        @if(!empty($vertragVorlagen))
                            <x-ui-button variant="primary" wire:click="vertragErstellen"
                                         wire:loading.attr="disabled" wire:target="vertragErstellen">
                                Erstellen und senden
                            </x-ui-button>
                        @endif
                    </div>
                </x-slot>
            </x-ui-modal>
```

- [ ] **Step 6: Run tests + blade check**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'VertragAusAkteOberflaecheTest|VertragAnDerAnstellung|EmployeeNachweisUebersichtTest|EmployeeActivityLogTest|EmployeeSearchJumpTest|ReissueContractTest'`
Expected: PASS.
Run: `php tools/blade-check.php resources/views/livewire/employees/show.blade.php`
Expected: Exit 0.

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/Employees/Show.php resources/views/livewire/employees/show.blade.php src/Http/Controllers/VertragPdfController.php routes/web.php tests/Integration/VertragAusAkteOberflaecheTest.php
git commit -m "feat(recruiting): Akte — Vertrag erstellen, Beginn/Ende/Zuschlag in den Listen, offene Vertraege stornieren, HR-PDF ohne Bewerbung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: MA-Vertragscheck in der Einsatz-Prüfung + HR-Fall + Einstellung + Knopf am Schreibtisch

**Files:**
- Modify: `src/Models/RecHrDeskCase.php` (Konstante + Label)
- Modify: `src/Models/RecApplicantSettings.php` (Default `contract_check_companies`)
- Create: `src/Services/VertragsPruefung.php`
- Modify: `src/Console/Commands/EinsatzPruefung.php` (Imports, `$z`, Einhängepunkt vor `if ($punkte === [])` :381, `kommende`-Zeile :439, neue Methoden, `bericht()`)
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (Reiter `payroll`)
- Modify: `resources/views/livewire/hr-desk/index.blade.php` (nach dem Notiz-Block)
- Test: `tests/Integration/EinsatzPruefungVertragTest.php`

**Interfaces:**
- Consumes: `VertragsDeckung` (Task 1), `VertragsVorbelegung::notiz/einsatztagAusNotiz` (Task 1), `VertragsZeilen::fuerAnstellung(int, ?string)` (Task 4), `ZasPersonnelNumber::prefixOf()`, Akte-Aufruf `?vertrag=neu&einsatz=` (Task 7)
- Produces:
  - `RecHrDeskCase::REASON_CONTRACT_MISSING = 'contract_missing'`, Label `'Vertrag fehlt für Einsatz'` (nicht in `CONTRACT_BLOCKING_REASONS`)
  - Setting `contract_check_companies` (Default `['MA']`; `[]` = Prüfung aus)
  - `VertragsPruefung::pruefe(array $umfangIds, iterable $kommende, array $firmen): list<array{anstellung_id:int, team_id:int, firma:string, deckung:string, vertrag_id:?int, buchungen:int, ohne:int, erster_tag:?string, event:?string, taetigkeit:?string}>`
  - Bericht-Zeile `Vertragsprüfung: n Buchungen geprüft, m ohne Vertrag, k Fälle neu, j Fälle geschlossen.`

- [ ] **Step 1: Write the failing test**

`tests/Integration/EinsatzPruefungVertragTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\OffenePunkte;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Spec Vertrag aus der Akte §3, Test 9; Review-Focus 1-3. Echte Migrationen
 * (VertragAusAkteHarness), Sender und Cache als Attrappe, ohne --welle —
 * es geht nichts raus, die Pruefung laeuft trotzdem vollstaendig.
 */
final class EinsatzPruefungVertragTest extends TestCase
{
    use VertragAusAkteHarness;

    private int $n = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        $c = Container::getInstance();
        $c->instance('cache', $this->cacheAttrappe());
        $c->instance(AufgabenSender::class, new class {
            public function sende(RecEmployee $e, array $stand, string $anlass): string { return 'sent'; }
            public function letzteNachrichtId(): ?int { return null; }
        });
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('cache');
        $c->forgetInstance(AufgabenSender::class);
        $this->weltAbbauen();
        parent::tearDown();
    }

    // ---- Grundregeln ---------------------------------------------------

    public function test_ma_buchung_ohne_vertrag_oeffnet_genau_einen_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711', ['taetigkeit' => 'Service'], 'Messe Düsseldorf');

        $erste = $this->laufe('2026-10-09');
        $this->laufe('2026-10-10');

        $faelle = $this->vertragsFaelle();
        $this->assertCount(1, $faelle, 'zweiter Lauf: kein zweiter Fall');
        $fall = $faelle->first();
        $this->assertSame($ma->id, (int) $fall->rec_employee_id);
        $this->assertNull($fall->rec_applicant_id);
        $this->assertSame($this->team, (int) $fall->team_id);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $fall->status);
        $this->assertSame('MA-Einsatz am 20.10.2026 (Messe Düsseldorf, Service) — kein unterschriebener Arbeitsvertrag der Gesellschaft MA deckt diesen Tag.', $fall->notes);
        $this->assertStringContainsString('Vertragsprüfung: 1 Buchungen geprüft, 1 ohne Vertrag, 1 Fälle neu, 0 Fälle geschlossen.', $erste);
        $this->assertNotContains(RecHrDeskCase::REASON_CONTRACT_MISSING, RecHrDeskCase::CONTRACT_BLOCKING_REASONS);
    }

    public function test_zwei_buchungen_ein_fall_mit_dem_fruehesten_tag(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-27', 'MA4711');
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $this->laufe('2026-10-09');

        $this->assertCount(1, $this->vertragsFaelle());
        $this->assertStringStartsWith('MA-Einsatz am 20.10.2026', $this->vertragsFaelle()->first()->notes);
    }

    public function test_rg_buchung_bei_standard_einstellung_ohne_fall(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG14']);
        $this->einbuchung($rg, '2026-10-20', 'RG14');

        $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
    }

    public function test_einstellung_steuert_die_gesellschaften(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG14']);
        $this->einbuchung($rg, '2026-10-20', 'RG14');
        $ma = $this->maAnstellung(['personnel_number' => 'MA15']);
        $this->einbuchung($ma, '2026-10-20', 'MA15');

        $this->einstellung([]);
        $this->laufe('2026-10-09');
        $this->assertCount(0, $this->vertragsFaelle(), 'beide aus = Pruefung laeuft nicht');

        $this->einstellung(['RG']);
        $this->laufe('2026-10-10');
        $this->assertSame([$rg->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_versendeter_vertrag_ist_kein_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
    }

    public function test_unterschriebener_vertrag_schliesst_den_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->laufe('2026-10-09');
        $this->assertCount(1, $this->vertragsFaelle());

        $v = $this->unterschrieben($ma, '2026-10-01', '2026-10-31');
        $ausgabe = $this->laufe('2026-10-10');

        $fall = RecHrDeskCase::query()->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)->first();
        $this->assertSame(RecHrDeskCase::STATUS_APPROVED, $fall->status);
        $this->assertNotNull($fall->resolved_at);
        $this->assertSame('Automatisch: Arbeitsvertrag unterschrieben (#' . $v->id . ')', $fall->resolution_notes);
        $this->assertStringContainsString('0 Fälle neu, 1 Fälle geschlossen.', $ausgabe);
    }

    public function test_verschwundene_buchung_laesst_den_fall_offen(): void
    {
        $ma = $this->maAnstellung();
        $id = $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->laufe('2026-10-09');

        DB::table('rec_dispo_assignments')->where('id', $id)->update(['missing_since' => '2026-10-09 12:00:00']);
        $this->unterschrieben($ma, '2026-11-01', '2026-11-30');
        $this->laufe('2026-10-10');

        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $this->vertragsFaelle()->first()->status, 'kein stiller Abbau');
    }

    public function test_trockenlauf_schreibt_nichts_meldet_aber(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $ausgabe = $this->laufe('2026-10-09', ['--dry-run' => true]);

        $this->assertCount(0, $this->vertragsFaelle());
        $this->assertStringContainsString('Vertragsprüfung: 1 Buchungen geprüft, 1 ohne Vertrag, 1 Fälle neu, 0 Fälle geschlossen.', $ausgabe);
    }

    /** Probe: den Check hinter den $punkte === []-Kurzschluss schieben → rot. */
    public function test_person_ohne_andere_offene_punkte_wird_trotzdem_geprueft(): void
    {
        $ma = $this->maAnstellung();
        $this->alleNachweiseErbringen($ma);
        $this->einbuchung($ma, '2026-10-20', 'MA4711');

        $this->laufe('2026-10-09');

        $this->assertCount(1, $this->vertragsFaelle());
    }

    // ---- Review-Focus ----------------------------------------------------

    /** Review-Focus 1. */
    public function test_altvertrag_ohne_laufzeit_erzeugt_keinen_fall(): void
    {
        $ma = $this->maAnstellung();
        $this->einbuchung($ma, '2026-10-20', 'MA4711');
        $this->vertragAn($ma, $this->vorlage('AV-MA-ALT'), ['status' => 'completed', 'signed_at' => '2024-05-02 12:00:00', 'completed_at' => '2024-05-02 12:00:00']);

        $this->laufe('2026-10-09');

        $this->assertCount(0, $this->vertragsFaelle());
    }

    /** Review-Focus 2: Firma aus dem Praefix der gekuerzten Nummer, Akte ohne Firma. */
    public function test_gekuerzte_ma_nummer_ohne_firma_an_der_akte(): void
    {
        $ma = $this->maAnstellung(['company' => null, 'personnel_number' => 'MA1000000878']);
        $this->einbuchung($ma, '2026-10-20', 'MA878');

        $this->laufe('2026-10-09');

        $this->assertSame([$ma->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all());
    }

    /** Probe: Firmenfilter in VertragsZeilen entfernen → der RG-AV deckt, kein Fall, rot. */
    public function test_rg_av_deckt_keinen_ma_einsatz(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG77']);
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-01-01', 'vertragsende' => '2026-12-31']);
        $this->einbuchung($rg, '2026-10-20', 'MA77');   // MA-Buchung, aber keine MA-Akte

        $this->laufe('2026-10-09');

        $this->assertSame([$rg->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all(),
            'Ohne MA-Akte haengt der Fall an der gebuchten Zeile — gedeckt hat der RG-Vertrag nicht');
    }

    /** Review-Focus 3. Probe: Ziel immer die gebuchte Zeile (`$anstellungen->first(...)` streichen) → Fall an RG, rot. */
    public function test_buchung_an_der_rg_zeile_prueft_die_ma_anstellung(): void
    {
        $rg = $this->maAnstellung(['company' => 'RG', 'personnel_number' => 'RG353']);
        $ma = $this->maAnstellung(['personnel_number' => 'MA353']);
        $this->personVerbinden($rg, $ma);
        // Ein falsch angehaengter RG-AV an der RG-Zeile, Laufzeit deckt den Tag.
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), ['status' => 'completed', 'signed_at' => '2026-09-01 12:00:00', 'completed_at' => '2026-09-01 12:00:00'],
            ['vertragsbeginn' => '2026-01-01', 'vertragsende' => '2026-12-31']);
        $this->einbuchung($rg, '2026-10-20', 'MA353');

        $this->laufe('2026-10-09');

        $this->assertSame([$ma->id], $this->vertragsFaelle()->pluck('rec_employee_id')->map(fn ($id) => (int) $id)->all(),
            'Der Fall gehoert an die MA-Zeile, der RG-AV deckt keinen MA-Einsatz');
    }

    // ---- Verdrahtung -----------------------------------------------------

    public function test_einstellung_und_schreibtisch_sind_verdrahtet(): void
    {
        $this->assertSame(['MA'], RecApplicantSettings::DEFAULT_SETTINGS['contract_check_companies']);
        $this->assertSame('Vertrag fehlt für Einsatz', RecHrDeskCase::REASON_LABELS[RecHrDeskCase::REASON_CONTRACT_MISSING]);

        $modal = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $this->assertSame(2, substr_count($modal, 'wire:model="settings.contract_check_companies"'));
        $this->assertStringContainsString('Vertragsprüfung bei Einsätzen', $modal);

        $desk = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/hr-desk/index.blade.php');
        $this->assertStringContainsString('REASON_CONTRACT_MISSING', $desk);
        $this->assertStringContainsString('VertragsVorbelegung::einsatztagAusNotiz', $desk);
        $this->assertStringContainsString('Vertrag erstellen', $desk);
    }

    // ---- Fixtures --------------------------------------------------------

    private function maAnstellung(array $set = []): RecEmployee
    {
        return $this->anstellung(array_merge([
            'personnel_number' => 'MA4711', 'portal_v2_since' => '2026-09-24 00:00:00',
            'phone' => '+49151' . str_pad((string) $this->n++, 8, '0', STR_PAD_LEFT),
        ], $set));
    }

    private function unterschrieben(RecEmployee $a, string $beginn, string $ende): \Platform\Recruiting\Models\RecContract
    {
        return $this->vertragAn($a, $this->vorlage('AV-MA-' . $this->n++), ['status' => 'completed', 'signed_at' => '2026-10-09 08:00:00', 'completed_at' => '2026-10-09 08:00:00'],
            ['vertragsbeginn' => $beginn, 'vertragsende' => $ende]);
    }

    private function einbuchung(RecEmployee $a, string $datum, string $pnr, array $set = [], ?string $event = null): int
    {
        $eventId = (int) DB::table('rec_dispo_events')->insertGetId([
            'uuid' => 'ev-' . $this->n++, 'einsatz_ref' => 'EV-' . $this->n, 'name' => $event,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ]);

        return (int) DB::table('rec_dispo_assignments')->insertGetId(array_merge([
            'uuid' => 'as-' . $this->n++, 'ds_ref' => 'DS-' . $this->n, 'rec_dispo_event_id' => $eventId,
            'pnr_raw' => $pnr, 'rec_employee_id' => $a->id, 'datum' => $datum, 'status_id' => 1,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ], $set));
    }

    private function einstellung(array $firmen): void
    {
        DB::table('rec_applicant_settings')->where('team_id', $this->team)->delete();
        DB::table('rec_applicant_settings')->insert(['team_id' => $this->team, 'settings' => json_encode(['contract_check_companies' => $firmen])]);
    }

    private function vertragsFaelle()
    {
        return RecHrDeskCase::query()->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)->orderBy('id')->get();
    }

    /** Wie EinsatzPruefungTest::alleNachweiseErbringen() — die Liste kommt aus OffenePunkte selbst. */
    private function alleNachweiseErbringen(RecEmployee $a): void
    {
        foreach ((new OffenePunkte())->fuer($a, '2026-10-09')['punkte'] as $punkt) {
            DB::table('rec_employee_proofs')->insert([
                'uuid' => 'pf-' . $this->n++, 'team_id' => $this->team, 'rec_employee_id' => $a->id,
                'proof_type_code' => $punkt['code'], 'valid_until' => '2030-12-31',
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
            ]);
        }
        $this->assertSame([], (new OffenePunkte())->fuer($a, '2026-10-09')['punkte'], 'Vorflug: wirklich nichts mehr offen');
    }

    private function laufe(string $datum, array $optionen = []): string
    {
        Carbon::setTestNow($datum . ' 09:00:00');
        $command = new EinsatzPruefung();
        $command->setLaravel(new EinsatzPruefungVertragFakeLaravel());
        $output = new BufferedOutput();
        $command->run(new ArrayInput($optionen, $command->getDefinition()), $output);

        return $output->fetch();
    }

    private function cacheAttrappe(): object
    {
        return new class {
            public array $gehalten = [];
            public function lock(string $name, int $sekunden = 0): object
            {
                return new class($this, $name) {
                    public function __construct(private object $speicher, private string $name) {}
                    public function get(): bool
                    {
                        if (isset($this->speicher->gehalten[$this->name])) {
                            return false;
                        }
                        $this->speicher->gehalten[$this->name] = true;
                        return true;
                    }
                    public function release(): bool
                    {
                        unset($this->speicher->gehalten[$this->name]);
                        return true;
                    }
                };
            }
        };
    }
}

final class EinsatzPruefungVertragFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EinsatzPruefungVertragTest`
Expected: FAIL — `Undefined constant …RecHrDeskCase::REASON_CONTRACT_MISSING`.

- [ ] **Step 3: Implement model constants + default**

`src/Models/RecHrDeskCase.php` — nach `public const REASON_WORK_PERMIT = 'work_permit';` einfügen:

```php

    // Eine MA-Buchung ohne unterschriebenen Arbeitsvertrag der Gesellschaft,
    // der den Einsatztag deckt (Spec Vertrag aus der Akte §3.3). Angelegt von
    // der Einsatz-Pruefung, haengt am MITARBEITER. Blockiert nichts — er
    // fordert etwas an; deshalb NICHT in CONTRACT_BLOCKING_REASONS.
    public const REASON_CONTRACT_MISSING = 'contract_missing';
```

und in `REASON_LABELS` nach der `REASON_WORK_PERMIT`-Zeile:

```php
        self::REASON_CONTRACT_MISSING => 'Vertrag fehlt für Einsatz',
```

`src/Models/RecApplicantSettings.php` — nach `'employee_contract_wa_template_id' => null,` einfügen:

```php
        // MA-Vertragscheck der Einsatz-Pruefung (Spec 2026-10-09 §3.2): fuer
        // Buchungen dieser Gesellschaften muss ein unterschriebener AV den
        // Tag decken. Checkboxen statt Select (Select-Speicherproblem).
        // [] = Pruefung aus.
        'contract_check_companies' => ['MA'],
```

- [ ] **Step 4: Implement VertragsPruefung**

`src/Services/VertragsPruefung.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * MA-Vertragscheck je Mensch (Spec Vertrag aus der Akte §3.2). Liest nur.
 *
 * WELCHE GESELLSCHAFT: der Praefix der Personalnummer AN DER BUCHUNG
 * (`MA878` → MA) — ZAS entscheidet, wann ein Job MA erfordert, wir sehen nur
 * das Ergebnis. Gekuerzte Nummern tragen denselben Praefix. Nur ohne Praefix
 * zaehlt die Firma der gebuchten Anstellung.
 *
 * WELCHE ANSTELLUNG: die des Personen-Umfangs mit dieser Gesellschaft — nicht
 * zwingend die, an der die Buchung haengt (Doppelbeschaeftigte RG+MA,
 * Review-Focus 3). Gibt es keine, die gebuchte.
 *
 * WELCHE VERTRAEGE: AV dieser Anstellung, deren VORLAGE zur Gesellschaft
 * gehoert (VertragsZeilen mit Firma).
 *
 * Je Anstellung EIN Befund: keiner, sobald eine Buchung ungedeckt ist
 * (erster_tag = frueheste, $kommende kommt nach Datum sortiert); sonst
 * unterwegs, sonst unterschrieben.
 */
final class VertragsPruefung
{
    /** @var array<string, list<array>> */
    private array $zeilen = [];

    /**
     * @param  list<int>  $umfangIds
     * @param  iterable<\Platform\Recruiting\Models\RecDispoAssignment>  $kommende  frueheste zuerst
     * @param  list<string>  $firmen  Grossbuchstaben
     * @return list<array{anstellung_id:int, team_id:int, firma:string, deckung:string, vertrag_id:?int, buchungen:int, ohne:int, erster_tag:?string, event:?string, taetigkeit:?string}>
     */
    public function pruefe(array $umfangIds, iterable $kommende, array $firmen): array
    {
        if ($firmen === []) {
            return [];
        }

        /** @var Collection<int, RecEmployee>|null $anstellungen */
        $anstellungen = null;
        $befunde = [];

        foreach ($kommende as $buchung) {
            $tag = $buchung->datum?->format('Y-m-d') ?? '';
            if ($tag === '') {
                continue;
            }

            $firma = ZasPersonnelNumber::prefixOf((string) $buchung->pnr_raw);
            if ($firma === null) {
                $anstellungen ??= $this->anstellungen($umfangIds);
                $firma = self::firmaVon($anstellungen->firstWhere('id', (int) $buchung->rec_employee_id));
            }
            if ($firma === null || !in_array($firma, $firmen, true)) {
                continue;
            }

            $anstellungen ??= $this->anstellungen($umfangIds);
            $ziel = $anstellungen->first(fn (RecEmployee $a) => self::firmaVon($a) === $firma)
                ?? $anstellungen->firstWhere('id', (int) $buchung->rec_employee_id);
            if ($ziel === null) {
                continue;
            }

            $schluessel = $ziel->id . '|' . $firma;
            $this->zeilen[$schluessel] ??= VertragsZeilen::fuerAnstellung((int) $ziel->id, $firma);
            $ergebnis = VertragsDeckung::amTag($this->zeilen[$schluessel], $tag);

            $b = $befunde[$schluessel] ?? [
                'anstellung_id' => (int) $ziel->id,
                'team_id'       => (int) $ziel->team_id,
                'firma'         => $firma,
                'deckung'       => VertragsDeckung::UNTERSCHRIEBEN,
                'vertrag_id'    => null,
                'buchungen'     => 0,
                'ohne'          => 0,
                'erster_tag'    => null,
                'event'         => null,
                'taetigkeit'    => null,
            ];
            $b['buchungen']++;

            if ($ergebnis['deckung'] === VertragsDeckung::KEINER) {
                $b['ohne']++;
                $b['deckung'] = VertragsDeckung::KEINER;
                if ($b['erster_tag'] === null) {
                    $b['erster_tag'] = $tag;
                    $b['event'] = $buchung->event?->name;
                    $b['taetigkeit'] = $buchung->taetigkeit;
                }
            } elseif ($ergebnis['deckung'] === VertragsDeckung::UNTERWEGS) {
                if ($b['deckung'] !== VertragsDeckung::KEINER) {
                    $b['deckung'] = VertragsDeckung::UNTERWEGS;
                }
            } else {
                $b['vertrag_id'] = $ergebnis['vertrag_id'];
            }

            $befunde[$schluessel] = $b;
        }

        return array_values($befunde);
    }

    /** @param list<int> $umfangIds */
    private function anstellungen(array $umfangIds): Collection
    {
        return RecEmployee::query()->whereIn('id', $umfangIds)->orderBy('id')->get(['id', 'team_id', 'company']);
    }

    private static function firmaVon(?RecEmployee $a): ?string
    {
        $f = strtoupper(trim((string) $a?->company));

        return $f === '' ? null : $f;
    }
}
```

- [ ] **Step 5: Hook into EinsatzPruefung**

In `src/Console/Commands/EinsatzPruefung.php`:

(a) Imports ergänzen:

```php
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\VertragsPruefung;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\VertragsVorbelegung;
```

(b) Nach `private const SPERRE_SEKUNDEN = 1800;` einfügen:

```php

    /** @var array<int, list<string>> contract_check_companies je Team, einmal je Lauf gelesen */
    private array $vertragsFirmenJeTeam = [];
```

(c) Im `$z`-Array nach `'faelle_neu'      => 0,` ergänzen:

```php
            'vertrag_buchungen'   => 0,
            'vertrag_ohne'        => 0,
            'vertrag_faelle_neu'  => 0,
            'vertrag_geschlossen' => 0,
```

(d) Direkt VOR dem Block

```php
                // ---- Ist nichts offen, ist auch nichts zu melden und nichts
                // zu erinnern. Vorher raeumen (ET-16), dann fertig.
                if ($punkte === []) {
```

einfügen:

```php
                // ---- MA-Vertragscheck (Spec Vertrag aus der Akte §3.2). VOR
                // dem Kurzschluss darunter: wer sonst nichts offen hat, wuerde
                // sonst nie geprueft. Dieselbe Menge kommender Auftraege wie
                // fuer Anlass und Erinnerung (eine Abfrage, kein Auseinanderlaufen).
                $kommende = $this->kommendeAuftraege($umfang['ids'], $heute);
                $this->vertragsPruefung($rep, $umfang['ids'], $kommende, $dryRun, $z);

```

(e) Die beiden Zeilen

```php
                $kommende  = $this->kommendeAuftraege($umfang['ids'], $heute);
                $ausloeser = $this->ausloesenderEinsatz($kommende, $heute);
```

ersetzen durch

```php
                $ausloeser = $this->ausloesenderEinsatz($kommende, $heute);
```

(f) Nach der Methode `oeffneFall()` einfügen:

```php
    /**
     * Spec Vertrag aus der Akte §3.2-§3.4. Faelle per Eloquent (kein
     * ZAS-Marker an rec_hr_desk_cases); der Trockenlauf zaehlt nur.
     *
     * @param  list<int>  $umfangIds
     * @param  \Illuminate\Support\Collection<int, RecDispoAssignment>  $kommende
     * @param  array<string,int>  $z
     */
    private function vertragsPruefung(RecEmployee $rep, array $umfangIds, $kommende, bool $dryRun, array &$z): void
    {
        $firmen = $this->vertragsFirmen((int) $rep->team_id);

        foreach ((new VertragsPruefung())->pruefe($umfangIds, $kommende, $firmen) as $befund) {
            $z['vertrag_buchungen'] += $befund['buchungen'];
            $z['vertrag_ohne'] += $befund['ohne'];

            if ($befund['deckung'] === VertragsDeckung::KEINER) {
                if (!$this->hatOffenenVertragsFall($befund['anstellung_id'])) {
                    if (!$dryRun) {
                        $this->oeffneVertragsFall($befund);
                    }
                    $z['vertrag_faelle_neu']++;
                }

                continue;
            }

            // §3.4: erst wenn ALLE kommenden Buchungen dieser Anstellung
            // unterschrieben gedeckt sind. "unterwegs" schliesst nicht.
            if ($befund['deckung'] === VertragsDeckung::UNTERSCHRIEBEN) {
                $z['vertrag_geschlossen'] += $this->schliesseVertragsFaelle($befund['anstellung_id'], $befund['vertrag_id'], $dryRun);
            }
        }
    }

    /**
     * Ohne Settings-Zeile oder ohne Schluessel: Default ['MA']. Query Builder
     * statt getOrCreateForTeam() — eine Pruefung legt keine Zeilen an.
     *
     * @return list<string>
     */
    private function vertragsFirmen(int $teamId): array
    {
        if (!array_key_exists($teamId, $this->vertragsFirmenJeTeam)) {
            $roh = DB::table('rec_applicant_settings')->where('team_id', $teamId)->value('settings');
            $settings = is_string($roh) ? (json_decode($roh, true) ?: []) : (is_array($roh) ? $roh : []);
            $wert = array_key_exists('contract_check_companies', $settings)
                ? $settings['contract_check_companies']
                : RecApplicantSettings::DEFAULT_SETTINGS['contract_check_companies'];

            $this->vertragsFirmenJeTeam[$teamId] = array_values(array_unique(array_filter(
                array_map(static fn ($f) => strtoupper(trim((string) $f)), (array) $wert),
                static fn (string $f) => $f !== ''
            )));
        }

        return $this->vertragsFirmenJeTeam[$teamId];
    }

    private function hatOffenenVertragsFall(int $anstellungId): bool
    {
        return RecHrDeskCase::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->exists();
    }

    /** @param array{anstellung_id:int, team_id:int, firma:string, erster_tag:?string, event:?string, taetigkeit:?string} $befund */
    private function oeffneVertragsFall(array $befund): void
    {
        RecHrDeskCase::create([
            'rec_applicant_id' => null,
            'rec_employee_id'  => $befund['anstellung_id'],
            'team_id'          => $befund['team_id'],
            'reason'           => RecHrDeskCase::REASON_CONTRACT_MISSING,
            'status'           => RecHrDeskCase::STATUS_OPEN,
            'opened_at'        => now(),
            'notes'            => VertragsVorbelegung::notiz((string) $befund['erster_tag'], $befund['event'], $befund['taetigkeit'], $befund['firma']),
        ]);
    }

    private function schliesseVertragsFaelle(int $anstellungId, ?int $vertragId, bool $dryRun): int
    {
        $faelle = RecHrDeskCase::query()
            ->where('rec_employee_id', $anstellungId)
            ->where('reason', RecHrDeskCase::REASON_CONTRACT_MISSING)
            ->where('status', RecHrDeskCase::STATUS_OPEN)
            ->get();

        if (!$dryRun) {
            foreach ($faelle as $fall) {
                $fall->update([
                    'status'           => RecHrDeskCase::STATUS_APPROVED,
                    'resolved_at'      => now(),
                    'resolution_notes' => sprintf('Automatisch: Arbeitsvertrag unterschrieben (#%d)', (int) $vertragId),
                ]);
            }
        }

        return $faelle->count();
    }
```

(g) In `bericht()` direkt nach dem `if ($gesperrt !== []) { … }`-Block einfügen:

```php

        $this->line(sprintf(
            'Vertragsprüfung: %d Buchungen geprüft, %d ohne Vertrag, %d Fälle neu, %d Fälle geschlossen.',
            $z['vertrag_buchungen'],
            $z['vertrag_ohne'],
            $z['vertrag_faelle_neu'],
            $z['vertrag_geschlossen'],
        ));
```

(h) Klassen-Docblock: im Abschnitt über `rec_hr_desk_cases` einen Satz ergänzen: „Zweiter Fall-Grund: `contract_missing` (MA-Vertragscheck), ebenfalls per `create()`; er schließt sich selbst, sobald ein unterschriebener AV alle kommenden Buchungen der Anstellung deckt."

- [ ] **Step 6: Settings checkboxes + HR desk button**

`resources/views/livewire/applicant/applicant-settings-modal.blade.php` — im Reiter `@elseif($activeTab === 'payroll')` direkt nach dem `<div>`-Block mit `wire:model="settings.tax_class_per_company"` (vor `@foreach($this->payrollFieldGroups …`) einfügen:

```blade
                <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                    <div class="text-sm font-medium text-[var(--ui-secondary)]">Vertragsprüfung bei Einsätzen</div>
                    <p class="text-xs text-[var(--ui-muted)] mt-0.5 mb-3">
                        Die stündliche Einsatz-Prüfung legt einen Fall auf den HR-Schreibtisch, wenn jemand für eine
                        dieser Gesellschaften eingebucht ist und kein unterschriebener Arbeitsvertrag den Einsatztag deckt.
                        Beide aus = keine Prüfung.
                    </p>
                    <label class="flex items-center gap-2 cursor-pointer text-sm">
                        <input type="checkbox"
                               wire:model="settings.contract_check_companies"
                               value="RG"
                               class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                        <span class="text-[var(--ui-secondary)]">Gesellschaft RG</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer text-sm mt-2">
                        <input type="checkbox"
                               wire:model="settings.contract_check_companies"
                               value="MA"
                               class="w-4 h-4 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                        <span class="text-[var(--ui-secondary)]">Gesellschaft MA</span>
                    </label>
                </div>
```

`resources/views/livewire/hr-desk/index.blade.php` — direkt nach dem Block

```blade
                                @if($case->notes)
                                    <div class="text-xs text-[var(--ui-muted)] mt-2 italic">
                                        Notiz: {{ $case->notes }}
                                    </div>
                                @endif
```

einfügen:

```blade
                                {{-- Vertrag fehlt für Einsatz (Spec 2026-10-09 §3.3): Akte mit offenem Fenster, vorbelegt aus dem Einsatztag der Notiz. --}}
                                @php
                                    $vertragsFallLink = null;
                                    if ($case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_CONTRACT_MISSING && $case->rec_employee_id) {
                                        $vertragsFallTag = \Platform\Recruiting\Support\VertragsVorbelegung::einsatztagAusNotiz($case->notes);
                                        $vertragsFallLink = route('recruiting.employees.show', array_filter([
                                            'employee' => $case->rec_employee_id,
                                            'vertrag'  => 'neu',
                                            'einsatz'  => $vertragsFallTag,
                                        ]));
                                    }
                                @endphp
                                @if($vertragsFallLink)
                                    <div class="mt-3">
                                        <a href="{{ $vertragsFallLink }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-[var(--ui-primary)] text-[var(--ui-primary)] bg-white text-xs font-medium rounded-md hover:bg-[var(--ui-muted-5)] transition-colors">
                                            @svg('heroicon-o-document-plus', 'w-3.5 h-3.5')
                                            Vertrag erstellen
                                        </a>
                                    </div>
                                @endif
```

- [ ] **Step 7: Run tests + blade checks**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'EinsatzPruefung|HrDesk|SettingsModal|TriggerStateSchemaTest'`
Expected: PASS (inkl. des unveränderten `EinsatzPruefungTest` — dort tragen alle Buchungen `RG14`, die Prüfung fragt also keine Verträge ab).
Run: `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php && php tools/blade-check.php resources/views/livewire/hr-desk/index.blade.php`
Expected: Exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Models/RecHrDeskCase.php src/Models/RecApplicantSettings.php src/Services/VertragsPruefung.php src/Console/Commands/EinsatzPruefung.php resources/views/livewire/applicant/applicant-settings-modal.blade.php resources/views/livewire/hr-desk/index.blade.php tests/Integration/EinsatzPruefungVertragTest.php
git commit -m "feat(recruiting): MA-Vertragscheck in der Einsatz-Pruefung — HR-Fall 'Vertrag fehlt fuer Einsatz', schliesst sich bei Unterschrift, Schalter je Gesellschaft

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Spec nachziehen, ganze Suite, Blade-Checks, Mutationsproben

**Files:**
- Modify: `docs/superpowers/specs/2026-10-09-vertrag-aus-akte-und-ma-vertragscheck-design.md`

**Interfaces:**
- Consumes: alles aus Tasks 1–8
- Produces: grüne Suite, Spec = gebauter Stand, Tabelle der Mutationsproben mit Ergebnis

- [ ] **Step 1: Ganze Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: PASS (Zahl der Tests notieren). Bei Rot: zuerst prüfen, ob die Klasse eine handgebaute Welt ohne `rec_contracts` hat (Fix = Vier-Migrationen-Block aus Task 6 Step 6), sonst systematisch debuggen — keine Erwartung lockern, ohne den Grund zu kennen.

- [ ] **Step 2: Alle angefassten Blades**

Run:
```bash
for f in resources/views/livewire/employees/show.blade.php resources/views/livewire/public/portal-shell.blade.php resources/views/livewire/applicant/applicant-settings-modal.blade.php resources/views/livewire/hr-desk/index.blade.php; do php tools/blade-check.php "$f" || echo "ROT: $f"; done
```
Expected: kein `ROT:`.

- [ ] **Step 3: Mutationsproben**

Jede Probe einzeln: Änderung einbauen, Filter laufen lassen, Ergebnis notieren, mit `git checkout -- <datei>` zurück. Erwartet ist jeweils ROT.

| # | Datei | Mutation | Filter | Erwartet rot |
|---|---|---|---|---|
| 1 | `src/Models/RecContractTemplate.php` | in `personalize()` die Zeile `$wert = $this->mitAnstellung(...)` löschen | `PersonalisierungAnstellungTest` | `test_ohne_bewerbung_kommen_name_und_adresse_aus_der_anstellung` |
| 2 | `src/Models/RecContract.php` | `anstellung()` → `return $this->applicant?->employee;` | `VertragUnterschriftOhneBewerbungTest` | u. a. `test_unterschrift_ohne_bewerbung_setzt_signed_at_und_befristung` |
| 3 | `src/Models/RecContract.php` | `$avQuery` immer `$applicant->contracts()` | `VertragUnterschriftOhneBewerbungTest` | `test_alle_av_unterschrieben_zaehlt_je_anstellung` |
| 4 | `src/Services/VertragLeser.php` | in `offenePunkte()` die `$grenze`-Bedingung löschen | `VertragImPortalTest` | `test_offener_punkt_in_fuer_und_trigger_mit_pause_ab_sent_at` |
| 5 | `src/Livewire/Public/PortalShell.php` | `'sign_url' => $c->status === 'sent' ? … : null` → immer Link | `VertragImPortalTest` | `test_vertrag_ohne_bewerbung_erscheint_pending_ohne_signierlink` |
| 6 | `src/Http/Controllers/VertragPdfController.php` | `DokumentDownloadController::sitzungDeckt(...)` → `true` | `VertragImPortalTest` | `test_pdf_zugriff_nur_mit_sitzung_der_person` |
| 7 | `src/Services/Comms/VertragHinweisSender.php` | `return self::SETTINGS_KEY;` ohne Rückfall | `VertragHinweisSenderTest` | `test_ohne_eigene_vorlage_faellt_er_auf_die_dokumentvorlage_zurueck` |
| 8 | `src/Console/Commands/EinsatzPruefung.php` | Einhängeblock (Task 8 Step 5d) hinter das `if ($punkte === []) { … continue; }` verschieben | `EinsatzPruefungVertragTest` | `test_person_ohne_andere_offene_punkte_wird_trotzdem_geprueft` |
| 9 | `src/Support/VertragsDeckung.php` | `laufzeitDeckt()`: `$b === null || …` → `$b !== null && …` | `VertragsDeckungTest|EinsatzPruefungVertragTest` | „Altvertrag ohne Laufzeit deckt", `test_altvertrag_ohne_laufzeit_erzeugt_keinen_fall` |
| 10 | `src/Services/VertragsZeilen.php` | Firmenfilter (`if ($firma !== null && …) continue;`) löschen | `EinsatzPruefungVertragTest` | `test_rg_av_deckt_keinen_ma_einsatz` |
| 11 | `src/Services/VertragsPruefung.php` | `$ziel = $anstellungen->first(…) ?? …` → nur `$anstellungen->firstWhere('id', (int) $buchung->rec_employee_id)` | `EinsatzPruefungVertragTest` | `test_buchung_an_der_rg_zeile_prueft_die_ma_anstellung` |
| 12 | `src/Services/VertragsPruefung.php` | `ZasPersonnelNumber::prefixOf(...)` → `null` | `EinsatzPruefungVertragTest` | `test_gekuerzte_ma_nummer_ohne_firma_an_der_akte` |
| 13 | `src/Services/VertragAusAkte.php` | `ueberschneidung`-Wächter löschen | `VertragAusAkteTest|VertragAusAkteOberflaecheTest` | `test_doppelabdeckung_wird_abgelehnt_folgemonat_nicht`, `test_doppelabdeckung_kommt_als_meldung` |

Bleibt eine Probe grün, ist der zugehörige Test wertlos — Test schärfen, Probe wiederholen, erst dann weiter.

- [ ] **Step 4: Spec nachziehen**

In `docs/superpowers/specs/2026-10-09-vertrag-aus-akte-und-ma-vertragscheck-design.md`:

1. §2.2 Schritt 2: „`zuschlag` als **Vertrags-Extrafeld** (… Typ decimal)" ersetzen durch „`zuschlag` als **Vertrags-Extrafeld** (`SeedRecContractExtraFields` um `zuschlag` ergänzt, **Typ text, deutsches Format „0,60"** — den Typ decimal gibt es im Core nicht, `number` verwirft das Komma still; ein Altfeld vom Typ `number` bekommt die Zahl)".
2. §4 „Migration: **keine**." ersetzen durch „Migration: **eine** — `2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts` (keine neue Spalte; `rec_contracts.rec_applicant_id` war `NOT NULL` mit Fremdschlüssel, ein Vertrag ohne Bewerbung ließ sich nicht speichern). Seed-Kommando `recruiting:seed-rec-contract-extra-fields` muss nach dem Deploy laufen (idempotent)." und in §4 erste Zeile „Keine neue Tabelle, keine neue Spalte." stehen lassen.
3. §8: „Keine Migration." ersetzen durch „**`migrate`** (eine Migration, siehe §4)."; `php artisan recruiting:seed-contract-extra-fields` ersetzen durch `php artisan recruiting:seed-rec-contract-extra-fields`.
4. §2.5 ergänzen: „`sign_url` und `pdf_url` über den Vertrags-Link; die PDF-Route heißt `GET /mitarbeiter/vertrag/{token}` (`recruiting.public.contract-pdf-anstellung`). Der offene Punkt trägt die Gesellschaft immer (`Arbeitsvertrag · MA`), die Vertragsliste nur bei mehr als einer Anstellung."
5. §2.6 ergänzen: „Die AV-Zählung je Anstellung gilt, wenn der Vertrag einen Anker (`rec_employee_id`) hat; ohne Anker zählt wie bisher der Bewerber. `contract_end_date` wird nur bei Verträgen **ohne** Bewerbung geschrieben (bei Bewerbungs-Verträgen bleibt `avContractEndDate()` die Quelle)."
6. §2.7 ergänzen: „PDF für HR ohne Bewerbung über `GET /employees/vertraege/{contractId}/pdf` (`recruiting.employees.vertrag-pdf`, Team-geprüft). „Neu ausstellen" nur für Verträge mit Bewerbung (`ReissueContractService` braucht sie); offene Verträge lassen sich in der Akte stornieren."
7. §3.2 Schritt 1 ergänzen: „Die Gesellschaft einer Buchung ist der Präfix von `pnr_raw` (auch gekürzt, `MA878`), nur ohne Präfix die Firma der gebuchten Anstellung. Geprüft wird die Anstellung des Personen-Umfangs mit dieser Gesellschaft (sonst die gebuchte), und nur AV, deren Vorlage zu dieser Gesellschaft gehört."
8. §3.3 ergänzen: „Der Knopf am Schreibtisch öffnet `/employees/{id}?vertrag=neu&einsatz=Y-m-d`; der Einsatztag wird aus der Fall-Notiz gelesen (`VertragsVorbelegung::einsatztagAusNotiz`)."
9. Neuer Abschnitt am Ende „## 10. Mutationsproben (Lauf am <Datum>)" mit der Tabelle aus Step 3 und je Zeile dem Ergebnis („rot — <Testname>").

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-10-09-vertrag-aus-akte-und-ma-vertragscheck-design.md
git commit -m "docs(recruiting): Spec Vertrag aus der Akte auf gebauten Stand (Migration, Zuschlag als Text, Routen, Firmenregel), Mutationsproben

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Spec-Abdeckung (Selbstprüfung)

| Spec | Task |
|---|---|
| §2.1 Oberfläche, Sperren, Meldung Doppelabdeckung | 4 (Wächter), 7 (Fenster, Knopf aus bei inaktiv) |
| §2.2 VertragAusAkte, VertragsAngaben, Extrafelder, Bewerber-Zuschlag, Link, `sent`, notes | 4 |
| §2.3 Personalisierung mit Vorrang-Kette, byteidentisch | 2 |
| §2.4 VertragHinweisSender + Rückfall + Einstellung | 3 |
| §2.5 Portal über Personen-Umfang, `sign_url` nur `sent`, PDF-Route mit Sitzung, `vertrag:<id>` + 7-Tage-Pause | 6 |
| §2.6 ContractSigning (3 Stellen + duzen + Portal-Link), Hook ohne Bewerber, `contract_end_date` | 5 |
| §2.7 Listen mit Beginn/Ende/Zuschlag, Alt-Code | 7 |
| §3.1 VertragsDeckung inkl. Altbestand | 1 |
| §3.2 Einhängen vor dem Kurzschluss, Gesellschaften-Einstellung, ein Fall je Anstellung, Trockenlauf, Bericht | 8 |
| §3.3 Fall-Grund, Label, Notiz, Dedupe, nicht blockierend, Knopf + Vorbelegung | 1, 7, 8 |
| §3.4 Auto-Schließen, verschwundene Buchung bleibt offen | 8 |
| §5 Einstellungen (Select, Checkboxen) | 3, 8 |
| §6 Tests 1–10 inkl. Proben | 2–9 |
| §8 Deploy (migrate, Seed, view:clear) | 9 (Spec) |
