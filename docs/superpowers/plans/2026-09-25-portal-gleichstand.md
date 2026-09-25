# Portal-Gleichstand — das neue Mitarbeiterportal holt das alte ein

> **Fuer agentische Arbeiter:** PFLICHT-SUB-SKILL: `superpowers:subagent-driven-development`
> (empfohlen) oder `superpowers:executing-plans`. Schritte sind Checkboxen.

**Goal:** `PortalShell` kann alle 47 Felder, 31 Regeln, 11 Nebenwirkungen und 18
Eigenheiten des alten `EmployeePortal` — in der Optik des abgenommenen Entwurfs
(Gruppenzeilen, Vollstaendigkeitsring, Upload-Kacheln) statt als lange Liste —,
sodass die Bremse im Umstell-Kommando entfallen kann.

**Architecture:** Die Feldliste bleibt wo sie ist (`RecEmployee::editableFieldGroups()`);
das neue Portal liest sie und rendert sie als Gruppenzeilen, die einzeln in
einem Blatt geoeffnet, ausgefuellt und geschlossen werden. Alle Regeln wandern
in reine, frameworkfreie Klassen (`PortalFieldAccess`, `PortalFieldRelevance`,
`PortalProfileGuards`, `PortalProfileWriter`-Regelteil, `PortalCompleteness`,
`PortalSectionHints`, `PortalGroupSummary`), `PortalShell` bleibt die Huelle
darum. **Stammdaten werden ueber Eloquent geschrieben** — genau wie im alten
Portal —, damit die Beobachter von selbst den ZAS-Marker (N1), den Lohn-Trigger
(N2), den CRM-Telefonabgleich (N5) und die Leerraum-Mutatoren (N7) erledigen;
die fuenf Spalten mit Marker-Verbot (§3.2) stehen schlicht nicht in
`RELEVANT_EMPLOYEE_FIELDS`. Dateien laufen **nicht** ueber diesen Weg, sondern
weiter ueber `ProofWriter` (Query Builder, kein Marker) — damit ist R20/E8
bauartbedingt erfuellt.

**Tech Stack:** Laravel 11 / Livewire 3 · eigenes Portal-Layout ohne Tailwind
(`resources/views/layouts/portal.blade.php` + `portal-styles.blade.php`) ·
`ContextFileService` aus platforms-core fuer die Ablage · PHPUnit
(Unit pur, Integration mit handgebautem Container + Capsule/SQLite, kein testbench)

**Spec:** `docs/superpowers/specs/2026-09-25-portal-bestandsaufnahme.md`
(alle Verweise der Form R7, N5, E12, §3.2 zeigen dorthin — die Bestandsaufnahme
ist die Wahrheit, dieser Plan wiederholt sie nicht)

---

## Befund vorab: die Lohn-Trigger-Pruefung

Auftrag war, zu pruefen, ob `is_main_employer`/`other_employer` im Team-Setting
`employee_payroll_tracked_fields` stehen. Ergebnis:

| Spalte | in `RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields']` | in `PAYROLL_TRACKABLE_FIELDS` |
|---|---|---|
| `is_main_employer` | **JA** (`src/Models/RecApplicantSettings.php:127`) | ja, als „Hauptarbeitgeber" (`:154`) |
| `other_employer` | nein | nein |

**Also ist es eine Abweichung, und dieser Plan behebt sie.** Das alte Portal
schreibt beide Spalten ueber Eloquent (`EmployeePortal.php:373`) und loest damit
ueber `RecEmployeeExportObserver::trackPayrollChanges()` den Lohn-Trigger aus;
das neue schreibt sie ueber den Query Builder (`PortalShell.php:366-369`) und
loest ihn nicht aus. Zwei Praezisierungen, die in Task 4 gebraucht werden:

1. Die **Erstbefuellung zaehlt nicht** (`RecEmployeeExportObserver.php:237-240`).
   Die *erste* Antwort auf die Hauptarbeitgeber-Frage (`null` → `true`/`false`)
   setzt also auch im alten Portal keinen Lohn-Trigger. Der Unterschied trifft
   den **Wechsel** „ja" ↔ „nein" — und genau der ist der teure Fall, weil daran
   die Steuerklasse haengt.
2. `normalizePayrollValue()` macht aus `false` **kein** `null`
   (`:283-289`, nur Strings werden geleert). `false → true` ist damit eine echte
   Aenderung und wird getrackt.

Der Umbau auf Eloquent setzt **keinen** ZAS-Marker: beide Spalten fehlen in
`RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS` — das haelt
`tests/Integration/EmployerFieldsNoExportMarkerTest.php` fuer das alte Portal
schon heute fest. Aber: der bestehende Quelltext-Waechter
`tests/Integration/PortalShellEmployerWiringTest.php::test_geschrieben_wird_ueber_den_query_builder`
verlangt heute das Gegenteil und **muss in Task 4 umgeschrieben werden** — samt
Begruendung, warum die Entscheidung gedreht wurde.

---

## Global Constraints

Diese Regeln gelten fuer JEDE Aufgabe.

- **Kein Edit ausserhalb `platforms-recruiting`.** Core/CRM/HCM werden benutzt,
  nie geaendert.
- **Testlauf:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
  (das Modul hat kein eigenes `vendor/`). Nie mit `--order-by=random` laufen
  lassen, Grund steht in `phpunit.xml`.
- **Blade nie mit `php -l` pruefen**, sondern `php tools/blade-check.php <datei>`.
  Direktiven immer in Blockform, nie an Wortzeichen geklebt, `@php`-Bloecke immer
  mit `@endphp`, Vorberechnungen vor das Markup statt `@if` im Attribut.
- **Stammdaten ueber Eloquent** (`$employee->update([...])`) — das ist die
  getroffene Architekturentscheidung. Nebenwirkungen N1, N2, N5, N7 sollen
  feuern. **Nie** Eloquent fuer Massenlaeufe oder fuer Dateien/Nachweise.
- **Dateien niemals aus einem Formularwert schreiben** (R20/E8). Dateien kommen
  ausschliesslich ueber `ProofWriter` (Query Builder, kein Marker).
- **Am ZAS-Export aendert sich NICHTS**: keine neue Spalte, kein neues Feld,
  keine Aenderung an `RELEVANT_EMPLOYEE_FIELDS`.
- **Keine zweite Feldliste.** Es gibt genau eine Quelle fuer Felder
  (`RecEmployee::editableFieldGroups()`) und genau eine fuer die Zuordnung
  Datei-Spalte → Nachweisart (`ProofTypes`). Die Doppelliste aus §1.4 Punkt 2,
  die E7 verursacht hat, wird nicht nachgebaut.
- **Anrede:** jeder sichtbare Text als `$duzen`-Ternary, auch Ueberschriften,
  auch Fehlermeldungen, die der Mensch liest.
- **Sicherheit:** alles, was ueber Identitaet oder Zustand entscheidet, ist
  `#[Locked]`. Jede schreibende Aktion ruft zuerst
  `PortalShell::berechtigterMitarbeiter()` (deckt R12, R13, R14 plus `is_active`
  und `portal_v2_since` ab).
- **Keine `#[Computed]`-Eigenschaften in `PortalShell`.** Das alte Portal musste
  sie nach jedem Schreiben verwerfen (N11); die Huelle liest stattdessen bei
  jedem `render()` frisch. Wer doch eine einfuehrt, muss N11 nachbauen.
- **Kein neuer ZAS-/Meta-/Mail-Versand, kein Protokolleintrag** (§3.1).
- **Die Bremse `--profil-fehlt-mir-egal` bleibt bis Task 10 unangetastet.**
  Solange eine Aufgabe dieses Plans offen ist, darf niemand Echtes umgestellt
  werden.

---

## File Structure

| Datei | Verantwortung | Aufgabe |
|---|---|---|
| `src/Support/ProofTypes.php` | Rueckrichtung Altspalte → Nachweisart (erweitert) | 1 |
| `src/Support/PortalFieldRelevance.php` | R28, strikt, als einzige Quelle | 2 |
| `src/Support/PortalFieldAccess.php` | R27 + sichtbare Gruppen/Felder | 2 |
| `src/Support/PortalProfileGuards.php` | R15→R16→R17 in bindender Reihenfolge, E11 | 3 |
| `src/Services/PortalProfileWriter.php` | Schreibweg ueber Eloquent, R19–R23 | 4 |
| `src/Support/PortalSectionHints.php` | die zwei fest verdrahteten Erklaertexte (E15) | 5 |
| `src/Support/PortalGroupSummary.php` | die Zusammenfassungszeile je Gruppe | 5 |
| `src/Support/PortalCompleteness.php` | Ring-Prozent und die fehlenden Beschriftungen | 5 |
| `src/Livewire/Public/PortalShell.php` | Huelle: Gruppe oeffnen/speichern/schliessen | 6, 8 |
| `resources/views/livewire/public/portal-shell.blade.php` | Profil-Bereich, Gruppen-Blatt, Dokumente | 7, 8 |
| `resources/views/layouts/portal.blade.php` | drei CSS-Ergaenzungen (`.grouprow.tap`, `.up.tap`, Ring) | 7 |
| `src/Console/Commands/SwitchPortalVersion.php` | Bremse entfaellt | 10 |

---

### Task 1: Die Rueckrichtung des Nachweis-Katalogs

Damit ein Datei-Feld aus der Feldliste weiss, welches Nachweis-Blatt es oeffnet —
ohne eine zweite Zuordnungsliste (§1.4 Punkt 2, E7).

**Files:**
- Change: `src/Support/ProofTypes.php`
- Test: `tests/Unit/ProofTypesTest.php` (bestehende Datei erweitern)

**Interfaces:**
- Consumes: nichts (reine Klasse)
- Produces:
  - `ProofTypes::codeForLegacyColumn(string $spalte): ?string` — Nachweisart,
    zu deren `dateien` diese Altspalte gehoert; `null` bei unbekannter Spalte.
  - `ProofTypes::legacyFileColumnsAll(): list<string>` — alle 16 Altspalten,
    fuer den Abnahmetest in Task 9.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_altspalte_findet_ihre_nachweisart(): void
{
    $this->assertSame('ausweis', ProofTypes::codeForLegacyColumn('identity_card_front_file_id'));
    // Die Rueckseite gehoert zur selben Art — sonst braeuchte die Kachel
    // eine zweite Zuordnung, und genau die hat am 06.08. E7 verursacht.
    $this->assertSame('ausweis', ProofTypes::codeForLegacyColumn('identity_card_back_file_id'));
    $this->assertSame('selfie', ProofTypes::codeForLegacyColumn('selfie_file_id'));
    $this->assertSame('krankenkasse', ProofTypes::codeForLegacyColumn('health_insurance_card_file_id'));
    $this->assertSame('immatrikulation', ProofTypes::codeForLegacyColumn('immatrikulation_file_id'));
    $this->assertSame('schulbescheinigung', ProofTypes::codeForLegacyColumn('schulbescheinigung_file_id'));
    $this->assertSame('erstbescheinigung', ProofTypes::codeForLegacyColumn('erstbescheinigung_file_id'));
    $this->assertSame('ersthelfer', ProofTypes::codeForLegacyColumn('first_aider_certificate_file_id'));
}

public function test_unbekannte_spalte_liefert_nichts(): void
{
    $this->assertNull(ProofTypes::codeForLegacyColumn('iban'));
    $this->assertNull(ProofTypes::codeForLegacyColumn(''));
}

public function test_alle_acht_datei_felder_des_alten_portals_sind_abgedeckt(): void
{
    // Die acht FILE_FIELDS aus EmployeePortal.php:93-102. Faellt hier eines
    // heraus, bekaeme es im neuen Portal eine Kachel ohne Ziel — dieselbe
    // Luecke wie E7, nur andersherum.
    $acht = [
        'identity_card_front_file_id', 'identity_card_back_file_id',
        'selfie_file_id', 'health_insurance_card_file_id',
        'immatrikulation_file_id', 'schulbescheinigung_file_id',
        'erstbescheinigung_file_id', 'first_aider_certificate_file_id',
    ];
    foreach ($acht as $spalte) {
        $this->assertNotNull(ProofTypes::codeForLegacyColumn($spalte), "Keine Nachweisart fuer {$spalte}");
    }
}

public function test_alle_altspalten_sind_eindeutig(): void
{
    $alle = ProofTypes::legacyFileColumnsAll();
    $this->assertSame(array_values(array_unique($alle)), $alle);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ProofTypesTest`
Erwartet: FEHLER, `Call to undefined method … codeForLegacyColumn()`.

- [ ] **Schritt 3: Minimal bauen**

```php
    /**
     * Die Rueckrichtung: zu welcher Nachweisart gehoert diese Altspalte?
     *
     * Damit kommt ein Datei-Feld aus RecEmployee::editableFieldGroups() ohne
     * eine zweite Zuordnungsliste zu seinem Upload-Blatt. Genau so eine
     * zweite Liste (EmployeePortal::FILE_FIELDS neben $fileUploadProps im
     * Blade) hat am 06.08.2026 zwei Felder ohne Knopf zurueckgelassen.
     *
     * Vorder- und Rueckseite liefern dieselbe Art — das Blatt fragt beide ab.
     */
    public static function codeForLegacyColumn(string $spalte): ?string
    {
        if ($spalte === '') {
            return null;
        }
        foreach (self::TYPES as $code => $art) {
            if (in_array($spalte, $art['dateien'], true)) {
                return $code;
            }
        }

        return null;
    }

    /** @return list<string> Alle Altspalten des Katalogs, Reihenfolge wie TYPES. */
    public static function legacyFileColumnsAll(): array
    {
        $alle = [];
        foreach (self::TYPES as $art) {
            foreach ($art['dateien'] as $spalte) {
                $alle[] = $spalte;
            }
        }

        return $alle;
    }
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen**
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): Nachweis-Katalog kennt die Rueckrichtung von der Altspalte zur Art`

---

### Task 2: Feldzugang — wer sieht welche Gruppe, welches Feld

Beantwortet die Frage aus dem Zuschnitt: **Ja, `RecEmployee::editableFieldGroups()`
liefert §1 bereits und wird wiederverwendet.** Es entscheidet die
Bescheinigungs-Gruppe nach `employment_type` (R29, `RecEmployee.php:482-488`)
und nimmt die Non-EU-Gruppe nur bei `is_eu_citizen === false` auf (R30, `:491-496`).
**Nicht** enthalten ist `visible_if` (R27) — das wertet heute die Komponente aus
(`EmployeePortal::fieldIsVisible()`, `631-646`) und muss deshalb in eine gemeinsame,
pruefbare Klasse. Dasselbe gilt fuer `required_if` (R28), das heute im Modell
haengt (`RecEmployee::fieldIsRelevant()`, `544-552`) und in Task 5 auch vom
Vollstaendigkeitsring gebraucht wird.

**Files:**
- Create: `src/Support/PortalFieldRelevance.php`
- Create: `src/Support/PortalFieldAccess.php`
- Change: `src/Models/RecEmployee.php` (`fieldIsRelevant()` delegiert)
- Change: `src/Livewire/Public/EmployeePortal.php` (`fieldIsVisible()` delegiert)
- Test: `tests/Unit/PortalFieldAccessTest.php`
- Test: `tests/Unit/PortalFieldRelevanceTest.php`

**Interfaces:**
- Consumes: `PortalBoolValue::parse(mixed $raw): ?bool`
- Produces:
  - `PortalFieldRelevance::istRelevant(array $meta, array $datensatz): bool` — R28
  - `PortalFieldAccess::istSichtbar(array $meta, array $datensatz, array $formwerte): bool` — R27
  - `PortalFieldAccess::sichtbareGruppen(array $gruppen, array $datensatz, array $formwerte): array`
    — `array<string, array<string, array<string,mixed>>>`; leere Gruppen fallen raus
  - `PortalFieldAccess::sichtbareFelderFlach(array $gruppen, array $datensatz, array $formwerte): array`
    — `array<string, array<string,mixed>>`
- Wird gebraucht von: Task 4 (Whitelist-Schnitt), Task 5 (Ring), Task 6/7 (Render)

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

`tests/Unit/PortalFieldRelevanceTest.php`:

```php
public function test_feld_ohne_bedingung_ist_immer_relevant(): void
{
    $this->assertTrue(PortalFieldRelevance::istRelevant(['type' => 'text'], []));
}

public function test_strikter_vergleich_trennt_unbeantwortet_von_nein(): void
{
    // R28: is_first_aider ist dreiwertig. Ein lockerer Vergleich wuerde
    // null (unbeantwortet) mit false (Nein) verwechseln — dann haette jeder
    // Nicht-Ersthelfer dauerhaft zwei rote Felder (E9).
    $meta = ['type' => 'date', 'required_if' => ['is_first_aider' => true]];

    $this->assertTrue(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => true]));
    $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => false]));
    $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => null]));
    $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_first_aider' => 1]));
    $this->assertFalse(PortalFieldRelevance::istRelevant($meta, []));
}

public function test_other_employer_ist_nur_bei_nein_pflicht(): void
{
    $meta = ['type' => 'text', 'required_if' => ['is_main_employer' => false]];

    $this->assertTrue(PortalFieldRelevance::istRelevant($meta, ['is_main_employer' => false]));
    $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_main_employer' => true]));
    $this->assertFalse(PortalFieldRelevance::istRelevant($meta, ['is_main_employer' => null]));
}
```

`tests/Unit/PortalFieldAccessTest.php`:

```php
public function test_feld_ohne_bedingung_ist_immer_sichtbar(): void
{
    $this->assertTrue(PortalFieldAccess::istSichtbar(['type' => 'text'], [], []));
}

public function test_unbeantwortet_versteckt_nichts(): void
{
    // R27: sichtbar, solange die Bedingung nicht ausdruecklich widerlegt ist
    // — sonst saehe niemand, dass nach einem "nein" noch etwas verlangt wird.
    $meta = ['type' => 'text', 'visible_if' => ['is_main_employer' => false]];

    $this->assertTrue(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => null], []));
    $this->assertTrue(PortalFieldAccess::istSichtbar($meta, [], []));
}

public function test_der_formularwert_schlaegt_den_datensatz(): void
{
    // E16: bei "ja" verschwindet das Namensfeld SOFORT, nicht erst nach dem
    // Speichern. Deshalb wird gegen den Formularwert gemessen.
    $meta = ['type' => 'text', 'visible_if' => ['is_main_employer' => false]];

    $this->assertFalse(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => false], ['is_main_employer' => '1']));
    $this->assertTrue(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => true], ['is_main_employer' => '0']));
    // Leerer Formularwert ist keine Aussage — dann entscheidet der Datensatz.
    $this->assertFalse(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => true], ['is_main_employer' => '']));
}

public function test_ja_nein_wird_ueber_PortalBoolValue_gelesen(): void
{
    // E13: eine einzige Quelle. 'nein' muss dasselbe heissen wie '0'.
    $meta = ['type' => 'text', 'visible_if' => ['is_main_employer' => false]];

    $this->assertTrue(PortalFieldAccess::istSichtbar($meta, [], ['is_main_employer' => 'nein']));
    $this->assertFalse(PortalFieldAccess::istSichtbar($meta, [], ['is_main_employer' => 'Ja']));
}

public function test_leere_gruppen_fallen_heraus(): void
{
    // Gegenteil von §1.4 Punkt 4: das alte Blade rendert eine leere Gruppe
    // als leere Karte mit Ueberschrift. Das neue laesst sie weg.
    $gruppen = [
        'Arbeitgeber' => [
            'is_main_employer' => ['type' => 'bool', 'label' => 'Hauptarbeitgeber?'],
            'other_employer'   => ['type' => 'text', 'label' => 'Wer ist es dann?',
                                   'visible_if' => ['is_main_employer' => false]],
        ],
        'Leer' => [
            'nur_bei_nein' => ['type' => 'text', 'label' => 'X',
                               'visible_if' => ['is_main_employer' => false]],
        ],
    ];

    $sichtbar = PortalFieldAccess::sichtbareGruppen($gruppen, ['is_main_employer' => true], []);

    $this->assertSame(['Arbeitgeber'], array_keys($sichtbar));
    $this->assertSame(['is_main_employer'], array_keys($sichtbar['Arbeitgeber']));
}

public function test_flache_liste_traegt_die_metadaten_weiter(): void
{
    $gruppen = ['A' => ['feld' => ['type' => 'date', 'label' => 'Feld', 'maxlength' => 128]]];

    $flach = PortalFieldAccess::sichtbareFelderFlach($gruppen, [], []);

    $this->assertSame(['feld'], array_keys($flach));
    $this->assertSame(128, $flach['feld']['maxlength']);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "PortalFieldAccessTest|PortalFieldRelevanceTest"`
Erwartet: FEHLER, beide Klassen nicht gefunden.

- [ ] **Schritt 3: Minimal bauen**

```php
final class PortalFieldRelevance
{
    /**
     * R28 — bedingte Pflicht, strikter Vergleich gegen den DATENSATZ.
     *
     * Bewusst nicht gegen den Formularwert: die Pflicht beschreibt den
     * gespeicherten Zustand, nicht die gerade getippte Absicht.
     *
     * @param array<string,mixed> $datensatz gecastete Attributwerte
     */
    public static function istRelevant(array $meta, array $datensatz): bool
    {
        foreach (($meta['required_if'] ?? []) as $feld => $erwartet) {
            if (($datensatz[$feld] ?? null) !== $erwartet) {
                return false;
            }
        }

        return true;
    }
}

final class PortalFieldAccess
{
    /**
     * R27 — dreiwertig: sichtbar, solange die Bedingung nicht ausdruecklich
     * widerlegt ist. Gemessen gegen den Formularwert, ersatzweise gegen den
     * Datensatz (E16).
     */
    public static function istSichtbar(array $meta, array $datensatz, array $formwerte): bool
    {
        foreach (($meta['visible_if'] ?? []) as $feld => $erwartet) {
            $roh = array_key_exists($feld, $formwerte) && trim((string) $formwerte[$feld]) !== ''
                ? $formwerte[$feld]
                : ($datensatz[$feld] ?? null);

            $ist = is_bool($erwartet) ? PortalBoolValue::parse($roh) : $roh;

            if ($ist !== null && $ist !== $erwartet) {
                return false;
            }
        }

        return true;
    }

    /**
     * Die Gruppen, die dieser Mensch sieht — leere fallen heraus.
     *
     * @return array<string, array<string, array<string,mixed>>>
     */
    public static function sichtbareGruppen(array $gruppen, array $datensatz, array $formwerte): array
    {
        $aus = [];
        foreach ($gruppen as $gruppe => $felder) {
            $sichtbar = [];
            foreach ($felder as $schluessel => $meta) {
                if (self::istSichtbar($meta, $datensatz, $formwerte)) {
                    $sichtbar[$schluessel] = $meta;
                }
            }
            if ($sichtbar !== []) {
                $aus[$gruppe] = $sichtbar;
            }
        }

        return $aus;
    }

    /** @return array<string, array<string,mixed>> */
    public static function sichtbareFelderFlach(array $gruppen, array $datensatz, array $formwerte): array
    {
        $flach = [];
        foreach (self::sichtbareGruppen($gruppen, $datensatz, $formwerte) as $felder) {
            foreach ($felder as $schluessel => $meta) {
                $flach[$schluessel] = $meta;
            }
        }

        return $flach;
    }
}
```

Dazu die beiden Delegationen, damit es keine zweite Wahrheit gibt:

```php
// RecEmployee::fieldIsRelevant() — Rumpf ersetzen, Docblock behalten
public function fieldIsRelevant(array $meta): bool
{
    $werte = [];
    foreach (array_keys($meta['required_if'] ?? []) as $feld) {
        $werte[$feld] = $this->getAttribute($feld);   // gecastet, dreiwertig
    }

    return PortalFieldRelevance::istRelevant($meta, $werte);
}

// EmployeePortal::fieldIsVisible() — Rumpf ersetzen, Docblock behalten
private function fieldIsVisible(RecEmployee $employee, array $meta): bool
{
    $datensatz = [];
    foreach (array_keys($meta['visible_if'] ?? []) as $feld) {
        $datensatz[$feld] = $employee->getAttribute($feld);
    }

    return PortalFieldAccess::istSichtbar($meta, $datensatz, $this->fieldValues);
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen** — und zusaetzlich die
  Bestandssuiten des alten Portals, weil sie mit umgezogen sind:
  `--filter "PortalEmployerFieldsTest|PortalFirstAiderFieldsTest|PortalMainEmployerRequiredTest|PortalNationalityRequiredTest|EmployeeNachweisUebersichtTest"`
- [ ] **Schritt 5: Festschreiben** — `refactor(recruiting): Sichtbarkeit und bedingte Pflicht des Portals als reine, geteilte Regeln`

---

### Task 3: Die drei Waechter in der bindenden Reihenfolge

§2.1 ist bindend: `FirstAiderDateGuard` → `NationalityRequiredGuard` →
`MainEmployerRequiredGuard`, jeder mit Early-Return. Neu ist, dass im neuen
Portal **immer nur eine Gruppe** im Formular steht — die Rueckfaelle auf den
Datensatz sind damit nicht mehr der Sonderfall, sondern der Normalfall. Genau
deshalb ist die `(string)`-Falle (E11/R18) hier gefaehrlicher als im alten
Portal und bekommt einen eigenen Test.

**Files:**
- Create: `src/Support/PortalProfileGuards.php`
- Test: `tests/Unit/PortalProfileGuardsTest.php`

**Interfaces:**
- Consumes: `FirstAiderDateGuard::error(mixed, mixed, mixed, bool): ?string`,
  `NationalityRequiredGuard::error(mixed): ?string`,
  `MainEmployerRequiredGuard::error(mixed, mixed): ?string`
- Produces:
  - `PortalProfileGuards::fehler(array $formwerte, array $datensatz): ?string`
    mit `$datensatz` als
    `array{is_first_aider:?bool, first_aider_valid_until:mixed, first_aider_certificate_file_id:?int, nationality:?string, is_main_employer:?bool, other_employer:?string}`
- Wird gebraucht von: Task 4

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
private function datensatz(array $ueberschreiben = []): array
{
    return array_merge([
        'is_first_aider'                  => null,
        'first_aider_valid_until'         => null,
        'first_aider_certificate_file_id' => null,
        'nationality'                     => 'DE',
        'is_main_employer'                => true,
        'other_employer'                  => null,
    ], $ueberschreiben);
}

public function test_vollstaendiger_zustand_laesst_durch(): void
{
    $this->assertNull(PortalProfileGuards::fehler([], $this->datensatz()));
}

public function test_ersthelfer_kommt_vor_staatsangehoerigkeit(): void
{
    // §2.1: Early-Return. Wer den ersten Waechter nicht passiert, sieht die
    // spaeteren Fehler nie — das ist Teil des Verhaltens, keine Reihenfolge
    // aus Bequemlichkeit.
    $fehler = PortalProfileGuards::fehler(
        ['is_first_aider' => '1'],
        $this->datensatz(['nationality' => null, 'is_main_employer' => null]),
    );

    $this->assertStringContainsString('Ersthelfer', $fehler);
}

public function test_staatsangehoerigkeit_kommt_vor_arbeitgeber(): void
{
    $fehler = PortalProfileGuards::fehler(
        [],
        $this->datensatz(['nationality' => '', 'is_main_employer' => null]),
    );

    $this->assertStringContainsString('Staatsangeh', $fehler);
}

public function test_ersthelfer_verlangt_datum_UND_datei(): void
{
    // R15: Dokumentpflicht, also $requireCertificate = true. HR ruft
    // denselben Waechter ohne den vierten Parameter.
    $nurDatum = PortalProfileGuards::fehler(
        ['is_first_aider' => '1', 'first_aider_valid_until' => '2027-01-01'],
        $this->datensatz(),
    );
    $this->assertStringContainsString('Nachweis', $nurDatum);

    $beides = PortalProfileGuards::fehler(
        ['is_first_aider' => '1', 'first_aider_valid_until' => '2027-01-01'],
        $this->datensatz(['first_aider_certificate_file_id' => 42]),
    );
    $this->assertNull($beides);
}

public function test_die_datei_kommt_vom_datensatz_nicht_aus_dem_formular(): void
{
    // R15/E8: Dateien laufen nie ueber Formularwerte. Ein manipulierter POST
    // darf den Waechter nicht mit einer erfundenen File-Id passieren.
    $fehler = PortalProfileGuards::fehler(
        [
            'is_first_aider' => '1',
            'first_aider_valid_until' => '2027-01-01',
            'first_aider_certificate_file_id' => '999',
        ],
        $this->datensatz(),
    );

    $this->assertStringContainsString('Nachweis', $fehler);
}

public function test_wer_ordentlich_nein_geantwortet_hat_kann_weiter_speichern(): void
{
    // E11/R18 — die teuerste Zeile des ganzen Umbaus. (string) false ergibt
    // '', also genau die Form, die der Waechter als "unbeantwortet" liest.
    // Im neuen Portal steht is_main_employer bei fast jedem Speichern gar
    // nicht im Formular, der Rueckfall ist also der Normalfall.
    $fehler = PortalProfileGuards::fehler(
        ['shirt_size' => 'M'],
        $this->datensatz(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']),
    );

    $this->assertNull($fehler);
}

public function test_unbeantworteter_hauptarbeitgeber_blockt(): void
{
    // R17 — Endzustandspruefung: blockt auch ein Speichern, das nur die
    // Schuhgroesse aendert.
    $fehler = PortalProfileGuards::fehler(
        ['shoe_size' => '43'],
        $this->datensatz(['is_main_employer' => null]),
    );

    $this->assertStringContainsString('Hauptarbeitgeber', $fehler);
}

public function test_nein_ohne_namen_blockt_und_zu_lang_blockt(): void
{
    $ohneNamen = PortalProfileGuards::fehler(
        ['is_main_employer' => '0', 'other_employer' => ''],
        $this->datensatz(),
    );
    $this->assertNotNull($ohneNamen);

    $zuLang = PortalProfileGuards::fehler(
        ['is_main_employer' => '0', 'other_employer' => str_repeat('a', 129)],
        $this->datensatz(),
    );
    $this->assertStringContainsString('zu lang', $zuLang);
}

public function test_staatsangehoerigkeit_faellt_auf_den_datensatz_zurueck(): void
{
    // R16: das Formular schickt den Schluessel nicht mit, wenn gerade eine
    // andere Gruppe offen ist.
    $this->assertNull(PortalProfileGuards::fehler(['iban' => 'DE02'], $this->datensatz()));
    $this->assertNotNull(PortalProfileGuards::fehler(['iban' => 'DE02'], $this->datensatz(['nationality' => null])));
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalProfileGuardsTest`
Erwartet: FEHLER, Klasse nicht gefunden.

- [ ] **Schritt 3: Minimal bauen**

```php
final class PortalProfileGuards
{
    /**
     * Die drei Waechter des Mitarbeiterportals, in der bindenden Reihenfolge
     * aus EmployeePortal::saveAll() (§2.1 der Bestandsaufnahme): Ersthelfer,
     * Staatsangehoerigkeit, Hauptarbeitgeber — jeder mit Early-Return.
     *
     * Die Reihenfolge ist Verhalten, keine Formalie: eine parallele
     * Sammelvalidierung wuerde andere Fehlertexte zeigen als heute.
     *
     * Im neuen Portal steht immer nur EINE Gruppe im Formular. Die Rueckfaelle
     * auf den Datensatz sind damit der Normalfall — und der Rueckfall beim
     * dreiwertigen is_main_employer geht ausdruecklich NICHT ueber (string):
     * (string) false ergibt '', also genau die Form, die der Waechter als
     * "unbeantwortet" liest. Wer ordentlich "nein" geantwortet hat, koennte
     * dann nie wieder speichern.
     *
     * @param array<string,string> $formwerte  rohe wire:model-Werte der offenen Gruppe
     * @param array<string,mixed>  $datensatz  gecastete Werte des Mitarbeiters
     */
    public static function fehler(array $formwerte, array $datensatz): ?string
    {
        // 1. Ersthelfer, MIT Dokumentpflicht. Die File-Id kommt vom Datensatz,
        //    nie aus dem Formular (R15, E8).
        $fehler = FirstAiderDateGuard::error(
            $formwerte['is_first_aider'] ?? self::dreiwertig($datensatz['is_first_aider'] ?? null),
            $formwerte['first_aider_valid_until'] ?? ($datensatz['first_aider_valid_until'] ?? null),
            $datensatz['first_aider_certificate_file_id'] ?? null,
            true,
        );
        if ($fehler !== null) {
            return $fehler;
        }

        // 2. Staatsangehoerigkeit (R16)
        $fehler = NationalityRequiredGuard::error(
            $formwerte['nationality'] ?? ($datensatz['nationality'] ?? null),
        );
        if ($fehler !== null) {
            return $fehler;
        }

        // 3. Haupt-/Nebenarbeitgeber (R17, R18)
        return MainEmployerRequiredGuard::error(
            $formwerte['is_main_employer'] ?? self::dreiwertig($datensatz['is_main_employer'] ?? null),
            $formwerte['other_employer'] ?? ($datensatz['other_employer'] ?? null),
        );
    }

    /** null bleibt null, true/false werden '1'/'0' — NIE (string) (E11). */
    private static function dreiwertig(?bool $wert): ?string
    {
        return $wert === null ? null : ($wert ? '1' : '0');
    }
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen**
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): die drei Portal-Waechter als eine Kaskade mit der bindenden Reihenfolge`

---

### Task 4: Der Schreibweg ueber Eloquent

**Files:**
- Create: `src/Services/PortalProfileWriter.php`
- Test: `tests/Integration/PortalProfileWriterTest.php`
- Change: `tests/Integration/PortalShellEmployerWiringTest.php` (Aussage dreht sich)

**Interfaces:**
- Consumes: `PortalProfileGuards::fehler()`, `PortalBoolValue::parse()`,
  `RecEmployee::editableFieldsFlat()`, `PortalFieldAccess::sichtbareFelderFlach()`
- Produces:
  - `PortalProfileWriter::speichere(RecEmployee $employee, array $formwerte, ?string $nurGruppe = null): array`
    → `array{ok:bool, fehler:?string, meldung:?string}`
    - `ok=false, fehler='…'` wenn ein Waechter blockt (R15–R18)
    - `ok=true, meldung='Keine Aenderungen.'` bei leerem Diff (R23)
    - `ok=true, meldung='Gespeichert.'` sonst
- Wird gebraucht von: Task 6

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

Aufbau wie `tests/Integration/PortalShellEmployerTest.php` (Container + Capsule,
SQLite im Speicher, kein testbench) — **mit Event-Dispatcher und registriertem
`RecEmployeeExportObserver`**, sonst prueft der Test die Nebenwirkungen nicht.

```php
public function test_text_und_lookup_werden_getrimmt_und_leer_wird_null(): void
{
    $ma = $this->mitarbeiter(['city' => 'Koeln']);

    $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => '  Duesseldorf  ', 'zip' => '']);

    $this->assertTrue($ergebnis['ok']);
    $this->assertSame('Duesseldorf', $ma->fresh()->city);
    $this->assertNull($ma->fresh()->zip);
}

public function test_ja_nein_laeuft_ueber_PortalBoolValue(): void
{
    $ma = $this->mitarbeiter();

    (new PortalProfileWriter())->speichere($ma, ['has_car' => 'ja']);

    $this->assertTrue($ma->fresh()->has_car);
}

public function test_datei_felder_werden_beim_speichern_uebersprungen(): void
{
    // R20/E8: ein manipulierter POST darf keine fremde File-Id setzen und
    // keinen gerade geprueften Nachweis im selben Zug leeren.
    $ma = $this->mitarbeiter(['selfie_file_id' => 7]);

    (new PortalProfileWriter())->speichere($ma, ['selfie_file_id' => '0', 'city' => 'Bonn']);

    $this->assertSame(7, $ma->fresh()->selfie_file_id);
}

public function test_unbekannte_schluessel_werden_uebersprungen(): void
{
    // R19: Schutz gegen manipulierten POST.
    $ma = $this->mitarbeiter();

    (new PortalProfileWriter())->speichere($ma, ['personnel_number' => 'MA99999', 'city' => 'Bonn']);

    $this->assertNull($ma->fresh()->personnel_number);
}

public function test_nur_die_offene_gruppe_wird_geschrieben(): void
{
    // Verschaerfung von R19 fuer das gruppenweise Speichern: aus dem Blatt
    // "Arbeitskleidung" darf niemand die Hauptarbeitgeber-Angabe umstellen.
    $ma = $this->mitarbeiter(['is_main_employer' => true]);

    (new PortalProfileWriter())->speichere($ma, ['shirt_size' => 'L', 'is_main_employer' => '0'], 'Arbeitskleidung');

    $this->assertSame('L', $ma->fresh()->shirt_size);
    $this->assertTrue($ma->fresh()->is_main_employer);
}

public function test_ja_leert_den_anderen_arbeitgeber(): void
{
    // R21: die Spalte ist ausschliesslich die Antwort auf "wenn nicht wir,
    // wer dann" — sonst stuende Mueller als Hauptarbeitgeber.
    $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']);

    (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '1'], 'Arbeitgeber');

    $this->assertTrue($ma->fresh()->is_main_employer);
    $this->assertNull($ma->fresh()->other_employer);
}

public function test_leerer_diff_meldet_keine_aenderungen_und_schreibt_nicht(): void
{
    // R23
    $ma = $this->mitarbeiter(['city' => 'Koeln', 'updated_at' => '2020-01-01 00:00:00']);

    $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => 'Koeln']);

    $this->assertTrue($ergebnis['ok']);
    $this->assertSame('Keine Aenderungen.', $ergebnis['meldung']);
    $this->assertSame('2020-01-01 00:00:00', (string) $ma->fresh()->getRawOriginal('updated_at'));
}

public function test_waechter_blockt_und_es_wird_gar_nichts_geschrieben(): void
{
    // R15–R17: Endzustandspruefung. Auch ein Speichern, das nur die
    // Schuhgroesse aendert, wird abgewiesen.
    $ma = $this->mitarbeiter(['nationality' => null, 'city' => 'Koeln']);

    $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => 'Bonn']);

    $this->assertFalse($ergebnis['ok']);
    $this->assertStringContainsString('Staatsangeh', $ergebnis['fehler']);
    $this->assertSame('Koeln', $ma->fresh()->city);
}

public function test_leerraum_wird_aus_steuer_id_und_sv_nummer_entfernt(): void
{
    // N7/R31 — faellt ueber die Eloquent-Mutatoren von selbst an. Genau
    // deshalb schreibt dieser Weg nicht ueber den Query Builder.
    $ma = $this->mitarbeiter();

    (new PortalProfileWriter())->speichere($ma, [
        'steuer_id' => "12 345 678 901",
        'sozialversicherungsnummer' => "65 170 839 K 003",
    ]);

    $this->assertSame('12345678901', $ma->fresh()->steuer_id);
    $this->assertSame('65170839K003', $ma->fresh()->sozialversicherungsnummer);
}

public function test_relevantes_feld_setzt_den_zas_marker(): void
{
    // N1
    $ma = $this->mitarbeiter(['zas_changed_at' => null]);

    (new PortalProfileWriter())->speichere($ma, ['city' => 'Bonn']);

    $this->assertNotNull($ma->fresh()->zas_changed_at);
}

public function test_die_fuenf_verbotenen_spalten_setzen_keinen_marker(): void
{
    // §3.2 — je ein Vorfall dahinter. phone: Katona RG999999.
    // is_main_employer/other_employer: Vorfall 02.09.2026 (volle Zeilen).
    $ma = $this->mitarbeiter(['zas_changed_at' => null, 'is_main_employer' => false, 'other_employer' => 'A']);

    (new PortalProfileWriter())->speichere($ma, ['phone' => '017612345678'], 'Kontakt');
    $this->assertNull($ma->fresh()->zas_changed_at);

    (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '1'], 'Arbeitgeber');
    $this->assertNull($ma->fresh()->zas_changed_at);
}

public function test_wechsel_beim_hauptarbeitgeber_setzt_den_lohn_trigger(): void
{
    // N2 + §3.3 — is_main_employer steht in
    // RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields'].
    // Das alte Portal loest ihn aus, das neue tat es bis hierher NICHT, weil
    // es ueber den Query Builder schrieb. Genau diese Abweichung schliesst
    // dieser Test.
    $ma = $this->mitarbeiter(['is_main_employer' => true, 'payroll_data_changed_at' => null]);

    (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '0', 'other_employer' => 'Mueller GmbH'], 'Arbeitgeber');

    $frisch = $ma->fresh();
    $this->assertNotNull($frisch->payroll_data_changed_at);
    $this->assertStringContainsString('is_main_employer', (string) $frisch->payroll_data_changed_fields);
    // ... und trotzdem kein ZAS-Marker.
    $this->assertNull($frisch->zas_changed_at);
}

public function test_die_erste_antwort_ist_eine_erstbefuellung_und_loest_nichts_aus(): void
{
    // Observer:237-240 — old === null zaehlt nicht. Gilt im alten wie im
    // neuen Portal, hier festgehalten, damit niemand den fehlenden Trigger
    // spaeter fuer einen Fehler haelt.
    $ma = $this->mitarbeiter(['is_main_employer' => null, 'payroll_data_changed_at' => null]);

    (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '1'], 'Arbeitgeber');

    $this->assertNull($ma->fresh()->payroll_data_changed_at);
}
```

Dazu in `PortalShellEmployerWiringTest` der gedrehte Waechter — der alte Test
`test_geschrieben_wird_ueber_den_query_builder` wird ersetzt:

```php
public function test_arbeitgeber_felder_laufen_ueber_den_gemeinsamen_schreibweg(): void
{
    // GEDREHT am 25.09.2026. Vorher verlangte dieser Test DB::table(...) fuer
    // is_main_employer/other_employer. Grund damals: die Entscheidung "ZAS
    // sieht die Angabe nicht" explizit festhalten. Grund jetzt: derselbe
    // Query Builder unterschlaegt den LOHN-TRIGGER, und is_main_employer
    // steht in RecApplicantSettings::DEFAULT_SETTINGS
    // ['employee_payroll_tracked_fields'] — das alte Portal meldet den
    // Wechsel ans Lohnbuero, das neue tat es nicht.
    //
    // Der ZAS-Schutz bleibt: beide Spalten fehlen in
    // RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS. Er wird jetzt
    // durch PortalProfileWriterTest::test_die_fuenf_verbotenen_spalten_
    // setzen_keinen_marker gemessen statt durch die Schreibart.
    $src = $this->quelle();

    $this->assertStringNotContainsString('speichereArbeitgeber', $src);
    $this->assertStringContainsString('PortalProfileWriter', $src);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalProfileWriterTest`
Erwartet: FEHLER, Klasse nicht gefunden.

- [ ] **Schritt 3: Minimal bauen**

```php
final class PortalProfileWriter
{
    /**
     * Stammdaten aus dem Mitarbeiterportal speichern — UEBER ELOQUENT.
     *
     * Das ist eine bewusste Entscheidung und der Grund, warum die
     * Beobachter hier anspringen sollen:
     *   N1  ZAS-Export-Marker fuer die 42 relevanten Spalten (§3.2)
     *   N2  Lohn-Trigger fuer 13 Spalten (§3.3), inkl. is_main_employer
     *   N5  CRM-Telefonabgleich, wenn sich phone aendert (Vorfall RG19734)
     *   N7  Leerraum raus aus steuer_id und sozialversicherungsnummer
     * Die fuenf Spalten mit Marker-Verbot (§3.2) stehen schlicht nicht in
     * RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS — das regelt sich
     * also von allein und braucht hier keine zweite Liste.
     *
     * DATEIEN laufen NICHT hier durch, sondern ueber ProofWriter (Query
     * Builder). Ein 'file'-Typ wird immer uebersprungen (R20/E8).
     *
     * @param array<string,string> $formwerte  rohe wire:model-Werte
     * @param ?string $nurGruppe  Name der offenen Gruppe; alles ausserhalb
     *                            wird verworfen (Verschaerfung von R19)
     * @return array{ok:bool, fehler:?string, meldung:?string}
     */
    public function speichere(RecEmployee $employee, array $formwerte, ?string $nurGruppe = null): array
    {
        $gruppen = $employee->editableFieldGroups();
        $erlaubt = $employee->editableFieldsFlat();

        if ($nurGruppe !== null) {
            $formwerte = array_intersect_key($formwerte, $gruppen[$nurGruppe] ?? []);
        }

        $fehler = PortalProfileGuards::fehler($formwerte, [
            'is_first_aider'                  => $employee->is_first_aider,
            'first_aider_valid_until'         => $employee->first_aider_valid_until?->format('Y-m-d'),
            'first_aider_certificate_file_id' => $employee->first_aider_certificate_file_id,
            'nationality'                     => $employee->nationality,
            'is_main_employer'                => $employee->is_main_employer,
            'other_employer'                  => $employee->other_employer,
        ]);
        if ($fehler !== null) {
            return ['ok' => false, 'fehler' => $fehler, 'meldung' => null];
        }

        $updates = [];
        foreach ($formwerte as $feld => $wert) {
            if (!array_key_exists($feld, $erlaubt)) {
                continue;                       // R19
            }
            $typ = $erlaubt[$feld]['type'] ?? 'text';
            if ($typ === 'file') {
                continue;                       // R20/E8
            }
            $wert = is_string($wert) ? trim($wert) : $wert;

            $updates[$feld] = $typ === 'bool'
                ? PortalBoolValue::parse($wert)                       // R22, E13
                : (($wert === '' || $wert === null) ? null : $wert);  // R22
        }

        // R21: "ja" leert den anderen Arbeitgeber — auch wenn im Formular noch
        // etwas stand oder ein alter Wert am Datensatz hing.
        $flagNachher = array_key_exists('is_main_employer', $updates)
            ? $updates['is_main_employer']
            : $employee->is_main_employer;
        if ($flagNachher === true && array_key_exists('other_employer', $erlaubt)) {
            $updates['other_employer'] = null;
        }

        if ($updates === []) {
            return ['ok' => true, 'fehler' => null, 'meldung' => 'Keine Aenderungen.'];   // R23
        }

        $employee->update($updates);

        // isDirty war vor dem update(); wurde nichts wirklich veraendert,
        // meldet Eloquent leere getChanges() — dann ist es keine Aenderung.
        return $employee->wasChanged()
            ? ['ok' => true, 'fehler' => null, 'meldung' => 'Gespeichert.']
            : ['ok' => true, 'fehler' => null, 'meldung' => 'Keine Aenderungen.'];
    }
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen** — dazu die
  Bestandssuiten: `--filter "EmployerFieldsNoExportMarkerTest|PortalShellEmployerTest|PortalShellEmployerWiringTest"`.
  `PortalShellEmployerTest` wird in Task 6 nachgezogen; bis dahin darf sie rot
  stehen, wenn der Commit beide Aufgaben zusammen abschliesst — sonst Task 6
  direkt anschliessen.
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): Portal-Stammdaten ueber Eloquent, damit ZAS-Marker, Lohn-Trigger und Telefonabgleich anspringen`

---

### Task 5: Ring, Zusammenfassungszeilen und die zwei Erklaertexte

Die Bestandsaufnahme nennt die zwei fest verdrahteten Texte als das, was am
ehesten vergessen wird (§7, Punkt 1) — der Arbeitgeber-Text ist der einzige
Ort im Produkt, der den Minijob-Sonderfall erklaert (E15), und er hat heute
keinen Test.

**Files:**
- Create: `src/Support/PortalSectionHints.php`
- Create: `src/Support/PortalGroupSummary.php`
- Create: `src/Support/PortalCompleteness.php`
- Test: `tests/Unit/PortalSectionHintsTest.php`
- Test: `tests/Unit/PortalGroupSummaryTest.php`
- Test: `tests/Unit/PortalCompletenessTest.php`

**Interfaces:**
- Consumes: `PortalFieldRelevance::istRelevant()`
- Produces:
  - `PortalSectionHints::fuer(string $gruppe, bool $duzen): ?string`
  - `PortalGroupSummary::zeile(array $felder, array $anzeigewerte): string` —
    `$anzeigewerte` ist `array<string,string>` (bereits formatiert, s. Task 6)
  - `PortalCompleteness::stand(array $felder, array $datensatz): array` →
    `array{prozent:int, gesamt:int, gefuellt:int, fehlend:list<string>}`
    (`fehlend` traegt Beschriftungen, nicht Spaltennamen)
- Wird gebraucht von: Task 6, 7, 9

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

`tests/Unit/PortalSectionHintsTest.php`:

```php
public function test_arbeitgeber_text_nennt_den_minijob(): void
{
    // E15: "Der Job, bei dem du am meisten verdienst" war sachlich falsch.
    // Ein 556-Euro-Minijob woanders zaehlt NICHT mit — bei Schuelern und
    // Studenten, also der Mehrheit der Zielgruppe, ist das der Normalfall.
    foreach ([true, false] as $duzen) {
        $text = PortalSectionHints::fuer('Arbeitgeber', $duzen);

        $this->assertNotNull($text);
        $this->assertStringContainsString('Minijob', $text);
        $this->assertStringNotContainsString('am meisten verdienst', $text);
        $this->assertStringNotContainsString('am meisten verdienen', $text);
    }
}

public function test_arbeitgeber_text_laedt_zum_nachfragen_ein(): void
{
    $this->assertStringContainsString('Frag uns', PortalSectionHints::fuer('Arbeitgeber', true));
    $this->assertStringContainsString('Fragen Sie uns', PortalSectionHints::fuer('Arbeitgeber', false));
}

public function test_arbeitsschutz_text_nennt_beide_pflichten(): void
{
    foreach ([true, false] as $duzen) {
        $text = PortalSectionHints::fuer('Arbeitsschutz', $duzen);

        $this->assertNotNull($text);
        $this->assertStringContainsString('Ersthelfer', $text);
        // Datum UND Schein — R15 verlangt beides.
        $this->assertMatchesRegularExpression('/g(ü|ue)ltig/i', $text);
        $this->assertStringContainsString('Schein', $text);
    }
}

public function test_alle_anderen_gruppen_haben_keinen_text(): void
{
    $this->assertNull(PortalSectionHints::fuer('Bankdaten', true));
    $this->assertNull(PortalSectionHints::fuer('Kontakt', false));
}

public function test_du_und_sie_sind_wirklich_verschieden(): void
{
    $this->assertNotSame(
        PortalSectionHints::fuer('Arbeitgeber', true),
        PortalSectionHints::fuer('Arbeitgeber', false),
    );
}
```

`tests/Unit/PortalGroupSummaryTest.php`:

```php
public function test_zeile_reiht_die_vorhandenen_werte(): void
{
    $felder = ['email' => ['label' => 'Email'], 'phone' => ['label' => 'Telefon']];

    $this->assertSame(
        'kevin.m@web.de · 0176 22 45 991',
        PortalGroupSummary::zeile($felder, ['email' => 'kevin.m@web.de', 'phone' => '0176 22 45 991']),
    );
}

public function test_leere_werte_fallen_weg(): void
{
    $felder = ['email' => ['label' => 'Email'], 'phone' => ['label' => 'Telefon']];

    $this->assertSame('kevin.m@web.de', PortalGroupSummary::zeile($felder, ['email' => 'kevin.m@web.de', 'phone' => '']));
}

public function test_ganz_leere_gruppe_sagt_es_deutlich(): void
{
    $this->assertSame('Noch nichts hinterlegt', PortalGroupSummary::zeile(['email' => ['label' => 'Email']], []));
}

public function test_zeile_wird_gekuerzt(): void
{
    $felder = [];
    $werte = [];
    foreach (range(1, 20) as $i) {
        $felder["f{$i}"] = ['label' => "F{$i}"];
        $werte["f{$i}"] = "Wert{$i}";
    }

    $zeile = PortalGroupSummary::zeile($felder, $werte);

    $this->assertLessThanOrEqual(80, mb_strlen($zeile));
    $this->assertStringEndsWith('…', $zeile);
}
```

`tests/Unit/PortalCompletenessTest.php`:

```php
public function test_prozent_zaehlt_nur_relevante_felder(): void
{
    // R28: Ersthelfer-Datum und -Schein zaehlen bei "Nein" nicht mit —
    // sonst staende der Ring bei jedem Nicht-Ersthelfer dauerhaft unter 100 %.
    $felder = [
        'email' => ['label' => 'Email'],
        'first_aider_valid_until' => ['label' => 'Bis', 'required_if' => ['is_first_aider' => true]],
    ];

    $stand = PortalCompleteness::stand($felder, ['email' => 'a@b.de', 'is_first_aider' => false]);

    $this->assertSame(100, $stand['prozent']);
    $this->assertSame(1, $stand['gesamt']);
    $this->assertSame([], $stand['fehlend']);
}

public function test_fehlende_felder_kommen_mit_beschriftung(): void
{
    $felder = ['email' => ['label' => 'Email'], 'shoe_size' => ['label' => 'Schuhgroesse (Zahl)']];

    $stand = PortalCompleteness::stand($felder, ['email' => 'a@b.de']);

    $this->assertSame(50, $stand['prozent']);
    $this->assertSame(['Schuhgroesse (Zahl)'], $stand['fehlend']);
}

public function test_leerstring_und_leeres_array_gelten_als_fehlend(): void
{
    $felder = ['a' => ['label' => 'A'], 'b' => ['label' => 'B'], 'c' => ['label' => 'C']];

    $stand = PortalCompleteness::stand($felder, ['a' => '', 'b' => [], 'c' => null]);

    $this->assertSame(0, $stand['prozent']);
    $this->assertCount(3, $stand['fehlend']);
}

public function test_die_null_ist_kein_leerer_wert(): void
{
    // Kinderzahl 0 und Steuerklasse sind echte Antworten.
    $stand = PortalCompleteness::stand(['number_of_children' => ['label' => 'Kinder']], ['number_of_children' => 0]);

    $this->assertSame(100, $stand['prozent']);
}

public function test_ohne_felder_steht_der_ring_auf_hundert(): void
{
    $this->assertSame(100, PortalCompleteness::stand([], [])['prozent']);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter "PortalSectionHintsTest|PortalGroupSummaryTest|PortalCompletenessTest"`
Erwartet: FEHLER, drei Klassen nicht gefunden.

- [ ] **Schritt 3: Minimal bauen**

```php
final class PortalSectionHints
{
    /**
     * Die zwei Erklaertexte, die es im alten Portal nur im Blade gab
     * (employee-portal.blade.php:255-270) und die von keiner generischen
     * Feldruebernahme mitgenommen werden.
     *
     * Der Arbeitgeber-Text ist BEWUSST NICHT "wo du am meisten verdienst":
     * das ist eine Faustregel, keine Regel. Man hat genau EIN erstes
     * Dienstverhaeltnis, jedes weitere laeuft ueber Steuerklasse VI — welches
     * das erste ist, entscheidet der Mitarbeiter. Und ein Minijob zaehlt
     * nicht mit, was bei Schuelern und Studenten der Normalfall ist (E15).
     *
     * OFFEN laut Commit 0f9cffa: die Formulierung soll noch jemand freigeben,
     * der die Lohnabrechnung verantwortet. Bis dahin bleibt sie woertlich so.
     */
    public static function fuer(string $gruppe, bool $duzen): ?string
    {
        return match ($gruppe) {
            'Arbeitsschutz' => $duzen
                ? 'Bist du Ersthelfer? Wenn ja, trag bitte das Gültigkeitsdatum ein und lade deinen Ersthelfer-Schein hoch — ohne beides können wir nicht speichern. Wenn nein, wähl einfach „Nein".'
                : 'Sind Sie Ersthelfer? Wenn ja, tragen Sie bitte das Gültigkeitsdatum ein und laden Sie Ihren Ersthelfer-Schein hoch — ohne beides können wir nicht speichern. Wenn nein, wählen Sie einfach „Nein".',
            'Arbeitgeber' => $duzen
                ? 'Wenn du nur bei uns arbeitest, sind wir dein Hauptarbeitgeber — dann wähl „Ja" und lass das Feld darunter leer. Arbeitest du noch woanders, kannst du trotzdem nur bei einem Arbeitgeber der Hauptarbeitgeber sein. Ist das ein anderer, wähl „Nein" und trag ihn ein. Ein Minijob zählt dabei nicht mit. Du weißt es nicht sicher? Frag uns kurz — die Angabe wirkt sich auf deine Steuer aus.'
                : 'Wenn Sie nur bei uns arbeiten, sind wir Ihr Hauptarbeitgeber — dann wählen Sie „Ja" und lassen das Feld darunter leer. Arbeiten Sie noch woanders, können Sie trotzdem nur bei einem Arbeitgeber den Hauptarbeitgeber haben. Ist das ein anderer, wählen Sie „Nein" und tragen ihn ein. Ein Minijob zählt dabei nicht mit. Sie wissen es nicht sicher? Fragen Sie uns kurz — die Angabe wirkt sich auf Ihre Steuer aus.',
            default => null,
        };
    }
}

final class PortalGroupSummary
{
    private const MAX = 80;

    /**
     * Die eine Zeile unter dem Gruppennamen (.grouprow .v im Entwurf).
     *
     * @param array<string,array<string,mixed>> $felder
     * @param array<string,string> $anzeigewerte bereits formatierte Werte
     */
    public static function zeile(array $felder, array $anzeigewerte): string
    {
        $teile = [];
        foreach (array_keys($felder) as $schluessel) {
            $wert = trim((string) ($anzeigewerte[$schluessel] ?? ''));
            if ($wert !== '') {
                $teile[] = $wert;
            }
        }

        if ($teile === []) {
            return 'Noch nichts hinterlegt';
        }

        $zeile = implode(' · ', $teile);

        return mb_strlen($zeile) > self::MAX
            ? mb_substr($zeile, 0, self::MAX - 1) . '…'
            : $zeile;
    }
}

final class PortalCompleteness
{
    /**
     * Der Ring aus dem Entwurf. Gezaehlt werden nur SICHTBARE und nach R28
     * RELEVANTE Felder — sonst staende bei jedem Nicht-Ersthelfer dauerhaft
     * ein unerfuellbarer Rest.
     *
     * @param array<string,array<string,mixed>> $felder  sichtbare Felder (flach)
     * @param array<string,mixed> $datensatz             gecastete Attributwerte
     * @return array{prozent:int, gesamt:int, gefuellt:int, fehlend:list<string>}
     */
    public static function stand(array $felder, array $datensatz): array
    {
        $gesamt = 0;
        $gefuellt = 0;
        $fehlend = [];

        foreach ($felder as $schluessel => $meta) {
            if (!PortalFieldRelevance::istRelevant($meta, $datensatz)) {
                continue;
            }
            $gesamt++;
            $wert = $datensatz[$schluessel] ?? null;
            if ($wert === null || $wert === '' || $wert === []) {
                $fehlend[] = (string) ($meta['label'] ?? $schluessel);
                continue;
            }
            $gefuellt++;
        }

        return [
            'prozent'  => $gesamt === 0 ? 100 : (int) round($gefuellt / $gesamt * 100),
            'gesamt'   => $gesamt,
            'gefuellt' => $gefuellt,
            'fehlend'  => $fehlend,
        ];
    }
}
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen**
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): Vollstaendigkeitsring, Gruppenzeilen und die zwei Erklaertexte als pruefbare Regeln`

---

### Task 6: `PortalShell` verdrahten — Gruppe oeffnen, ausfuellen, zurueck

**Files:**
- Change: `src/Livewire/Public/PortalShell.php`
- Test: `tests/Integration/PortalShellProfilTest.php`
- Change: `tests/Integration/PortalShellEmployerTest.php` (ruft jetzt den
  Gruppen-Weg statt `speichereArbeitgeber()`)

**Interfaces:**
- Consumes: `PortalFieldAccess::sichtbareGruppen()`, `PortalFieldAccess::sichtbareFelderFlach()`,
  `PortalProfileWriter::speichere()`, `PortalCompleteness::stand()`,
  `PortalGroupSummary::zeile()`, `PortalSectionHints::fuer()`,
  `ProofTypes::codeForLegacyColumn()`, `PortalShell::berechtigterMitarbeiter()`
- Produces (fuer Task 7, das Blade):
  - `#[Locked] public ?string $profilGruppe` · `public array $profilWerte`
    · `public string $profilFehler` · `public string $profilMeldung`
  - `PortalShell::oeffneGruppe(string $gruppe): void`
  - `PortalShell::schliesseGruppe(): void`
  - `PortalShell::speichereGruppe(): void`
  - `PortalShell::lookupOptionen(string $lookup): array` (`array<string,string>`)
  - Render-Daten: `profilGruppen` (`array<string, array{felder:array, zeile:string, offen:int}>`),
    `profilStand` (`array{prozent:int, gesamt:int, gefuellt:int, fehlend:list<string>}`),
    `profilFelder` (`array<string, array{type:string, label:string, lookup:?string,
    options:?list<string>, maxlength:?int, live:bool, fehlt:bool}>` — die
    Nicht-Datei-Felder der offenen Gruppe; `fehlt` kommt aus
    `PortalFieldRelevance::istRelevant()` plus Leerwert und faerbt den Rand rot (E2)),
    `profilHinweis` (`?string` aus `PortalSectionHints::fuer()`),
    `nurLesen` (`array<string, array{label:string, value:string}>` aus
    `RecEmployee::readOnlyDisplayFields()`),
    `kacheln` (`list<array{code:string, label:string, da:bool}>` — je Datei-Feld
    der sichtbaren Gruppen eine, `code` aus `ProofTypes::codeForLegacyColumn()`,
    doppelte Codes zusammengefasst)
- Entfaellt: `speichereArbeitgeber()`, `$arbeitgeberIstHaupt`,
  `$arbeitgeberAnderer`, `$arbeitgeberFehler` — die Gruppe „Arbeitgeber" ist ab
  jetzt eine Gruppe wie jede andere. `arbeitgeberAufgabe()` **bleibt**: sie ist
  die synthetische Aufgabe im Start-Bereich und oeffnet jetzt die Gruppe.

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

Aufbau wie `PortalShellEmployerTest` (Container + Capsule + Session + Cache).

```php
public function test_ohne_anmeldung_wird_keine_gruppe_geoeffnet(): void
{
    // R3/R12: kein Feldladen ohne gueltigen Zustand. Anders als im alten
    // Portal, das loadFieldValues() schon in mount() ruft (§6).
    $shell = $this->shell($ma = $this->mitarbeiter(), angemeldet: false);

    $shell->oeffneGruppe('Bankdaten');

    $this->assertNull($shell->profilGruppe);
    $this->assertSame([], $shell->profilWerte);
}

public function test_unbekannte_gruppe_laeuft_still_ins_leere(): void
{
    $shell = $this->shell($this->mitarbeiter());

    $shell->oeffneGruppe('Gibtesnicht');

    $this->assertNull($shell->profilGruppe);
}

public function test_nicht_sichtbare_gruppe_laesst_sich_nicht_oeffnen(): void
{
    // R30: Non-EU-Gruppe nur bei is_eu_citizen === false. Bei null gibt es
    // sie nicht — auch nicht ueber $wire.call('oeffneGruppe', …).
    $shell = $this->shell($this->mitarbeiter(['is_eu_citizen' => null]));

    $shell->oeffneGruppe('Aufenthalt (Non-EU)');

    $this->assertNull($shell->profilGruppe);
}

public function test_oeffnen_belegt_die_felder_der_gruppe_vor(): void
{
    $shell = $this->shell($this->mitarbeiter(['iban' => 'DE02', 'city' => 'Koeln']));

    $shell->oeffneGruppe('Bankdaten');

    $this->assertSame('Bankdaten', $shell->profilGruppe);
    $this->assertSame('DE02', $shell->profilWerte['iban']);
    // Nichts aus anderen Gruppen faehrt mit.
    $this->assertArrayNotHasKey('city', $shell->profilWerte);
}

public function test_datei_felder_stehen_nicht_im_formular(): void
{
    // R20/E8 und §1.4 Punkt 2: kein zweiter Upload-Weg, keine zweite Liste.
    $shell = $this->shell($this->mitarbeiter());

    $shell->oeffneGruppe('Ausweis');

    $this->assertArrayHasKey('identity_card_valid_until', $shell->profilWerte);
    $this->assertArrayNotHasKey('identity_card_front_file_id', $shell->profilWerte);
}

public function test_speichern_schreibt_und_schliesst(): void
{
    $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => true]);
    $shell = $this->shell($ma);

    $shell->oeffneGruppe('Bankdaten');
    $shell->profilWerte['iban'] = 'DE89370400440532013000';
    $shell->speichereGruppe();

    $this->assertSame('DE89370400440532013000', $ma->fresh()->iban);
    $this->assertNull($shell->profilGruppe);
    $this->assertSame('', $shell->profilFehler);
}

public function test_waechter_haelt_das_blatt_offen_und_die_eingaben_stehen(): void
{
    // Early-Return OHNE Neuladen — die Eingaben bleiben stehen
    // (EmployeePortal.php:288).
    $ma = $this->mitarbeiter(['nationality' => null, 'is_main_employer' => true]);
    $shell = $this->shell($ma);

    $shell->oeffneGruppe('Arbeitskleidung');
    $shell->profilWerte['shirt_size'] = 'L';
    $shell->speichereGruppe();

    $this->assertSame('Arbeitskleidung', $shell->profilGruppe);
    $this->assertSame('L', $shell->profilWerte['shirt_size']);
    $this->assertStringContainsString('Staatsangeh', $shell->profilFehler);
    $this->assertNull($ma->fresh()->shirt_size);
}

public function test_gesperrter_zugang_speichert_nicht(): void
{
    // R14: frisch aus der DB, nicht aus dem Sitzungszustand.
    $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => true]);
    $shell = $this->shell($ma);
    $shell->oeffneGruppe('Bankdaten');
    $shell->profilWerte['iban'] = 'DE02';

    DB::table('rec_employees')->where('id', $ma->id)->update(['portal_locked_at' => now()]);
    $shell->speichereGruppe();

    $this->assertNull($ma->fresh()->iban);
    $this->assertSame('gesperrt', $shell->state);
}

public function test_hauptarbeitgeber_ist_eine_gruppe_wie_jede_andere(): void
{
    $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => null]);
    $shell = $this->shell($ma);

    $shell->oeffneGruppe('Arbeitgeber');
    $shell->profilWerte['is_main_employer'] = '0';
    $shell->profilWerte['other_employer'] = 'Mueller GmbH';
    $shell->speichereGruppe();

    $this->assertFalse($ma->fresh()->is_main_employer);
    $this->assertSame('Mueller GmbH', $ma->fresh()->other_employer);
}

public function test_die_synthetische_aufgabe_verschwindet_nach_der_antwort(): void
{
    $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => null]);
    $shell = $this->shell($ma);

    $shell->oeffneGruppe('Arbeitgeber');
    $shell->profilWerte['is_main_employer'] = '1';
    $shell->speichereGruppe();

    $this->assertNotNull($ma->fresh()->is_main_employer);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellProfilTest`
Erwartet: FEHLER, `oeffneGruppe()` gibt es nicht.

- [ ] **Schritt 3: Minimal bauen**

```php
    /**
     * Welche Gruppe gerade im Blatt offen ist.
     *
     * #[Locked], weil sie entscheidet, WELCHE Felder geschrieben werden --
     * dieselbe Lehre wie aus dem Auth-Bypass. Gesetzt wird sie nur von
     * oeffneGruppe(), und die prueft den Namen gegen die fuer DIESEN Menschen
     * sichtbaren Gruppen (R27/R29/R30).
     */
    #[Locked] public ?string $profilGruppe = null;

    /**
     * Die Eingaben des offenen Blatts -- NICHT gesperrt, sie kommen vom
     * Menschen. Die Sicherheit sitzt in speichereGruppe() ueber
     * berechtigterMitarbeiter() und im Schnitt auf die offene Gruppe.
     */
    public array $profilWerte = [];
    public string $profilFehler = '';
    public string $profilMeldung = '';

    public function oeffneGruppe(string $gruppe): void
    {
        $this->profilFehler = '';
        $this->profilMeldung = '';
        $this->profilGruppe = null;
        $this->profilWerte = [];

        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null) {
            return;
        }

        $gruppen = $employee->editableFieldGroups();
        $sichtbar = PortalFieldAccess::sichtbareGruppen(
            $gruppen,
            $this->datensatzWerte($employee, $gruppen),
            [],   // beim Oeffnen gibt es noch keine Formulareingaben
        );
        if (!array_key_exists($gruppe, $sichtbar)) {
            return;   // kaputter Link, veraltetes Snapshot -- still ins Leere
        }

        $this->profilGruppe = $gruppe;
        foreach ($sichtbar[$gruppe] as $schluessel => $meta) {
            if (($meta['type'] ?? 'text') === 'file') {
                continue;   // Dateien laufen ueber das Nachweis-Blatt (R20)
            }
            $this->profilWerte[$schluessel] = $this->formularwert($employee, $schluessel);
        }
    }

    public function schliesseGruppe(): void
    {
        $this->profilGruppe = null;
        $this->profilWerte = [];
        $this->profilFehler = '';
        $this->profilMeldung = '';
    }

    public function speichereGruppe(): void
    {
        $employee = $this->berechtigterMitarbeiter();
        if ($employee === null || $this->profilGruppe === null) {
            return;
        }

        $ergebnis = app(PortalProfileWriter::class)
            ->speichere($employee, $this->profilWerte, $this->profilGruppe);

        if (!$ergebnis['ok']) {
            // Blatt bleibt offen, Eingaben bleiben stehen.
            $this->profilFehler = (string) $ergebnis['fehler'];

            return;
        }

        $this->profilFehler = '';
        $this->profilMeldung = (string) $ergebnis['meldung'];
        $this->profilGruppe = null;
        $this->profilWerte = [];
    }
```

Dazu in `render()`: `profilGruppen`, `profilStand`, `profilFelder`,
`profilHinweis`, `nurLesen` und `kacheln` bauen; `speichereArbeitgeber()` und
die drei `arbeitgeber*`-Eigenschaften entfernen; `arbeitgeberAufgabe()` bleibt,
ihr Klick ruft im Blade jetzt `oeffneGruppe('Arbeitgeber')`.

Private Hilfsmethoden, alle mit fester Signatur, damit Blade und Tests sie
treffen:

- `datensatzWerte(RecEmployee $employee, array $gruppen): array<string,mixed>` —
  die **gecasteten** Attributwerte aller Felder aus `$gruppen`, gelesen mit
  `getAttribute()`, nicht mit `getAttributes()`. Der Unterschied ist tragend:
  `visible_if` und `required_if` vergleichen strikt gegen `true`/`false`/`null`,
  und die Rohwerte aus der Datenbank waeren `1`/`0`/`null` (R27, R28).
- `formularwert(RecEmployee $employee, string $feld): string` — Datum →
  `Y-m-d`, bool → `'1'`/`'0'`, `null` → `''`; woertlich wie
  `EmployeePortal::loadFieldValues()` (`232-249`).
- `anzeigewert(RecEmployee $employee, string $feld, array $meta): string` —
  Datum → `d.m.Y`, bool → Ja/Nein, lookup → Beschriftung, `inline_select` und
  `text` roh; woertlich wie `EmployeePortal::formatDisplayValue()` (`547-559`)
  inkl. des `default`-Zweigs, in den `inline_select` faellt (§1.4 Punkt 1).
- `lookupOptionen(string $lookup): array<string,string>` — **oeffentlich**, weil
  das Blade sie ruft; mit Request-Cache, `CoreLookup::where('name', …)`,
  `\Throwable` → leeres Array (wie `EmployeePortal::lookupOptionsFor()`).

- [ ] **Schritt 4: Test laufen lassen, gruen sehen** — dazu
  `--filter "PortalShellEmployerTest|PortalShellEmployerWiringTest|PortalShellArbeitgeberAufgabeTest|PortalShellUploadTest|PortalShellVerifyTest"`
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): das neue Portal pflegt Stammdaten gruppenweise`

---

### Task 7: Die Oberflaeche — Gruppen als Zeilen, Ring, Kacheln, Blatt

Der Profil-Bereich folgt `resources/mockups/crew-portal.html`, Abschnitt
`pane-me` (Zeilen 387-413): Ring-Zeile, Hinweis, `.uploads`-Kacheln,
`.card` mit `.grouprow`-Zeilen und Chevron. Das Gruppen-Blatt ist dasselbe
Gestell wie das Nachweis-Blatt (`.upload-overlay` / `.upload-sheet`), damit es
keine zweite Sprache gibt.

**Files:**
- Change: `resources/views/livewire/public/portal-shell.blade.php`
- Change: `resources/views/layouts/portal.blade.php` (drei CSS-Ergaenzungen)
- Test: `tests/Integration/PortalShellProfilBladeTest.php` (Quelltext-Waechter
  am Blade, Muster `PortalShellEmployerWiringTest` — die Komponente laesst sich
  in dieser Suite nicht rendern, es fehlt das `view`-Binding)

**Interfaces:**
- Consumes: die Render-Daten aus Task 6
- Produces: nichts fuer spaetere Aufgaben ausser dem gerenderten Markup

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
private function blade(): string
{
    return file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
}

public function test_das_profil_zeigt_den_ring(): void
{
    $blade = $this->blade();

    $this->assertStringContainsString('ring-row', $blade);
    // Der Entwurf hat 85 % fest in der CSS. Der echte Wert muss als
    // Inline-Stil mitkommen, sonst zeigt der Ring bei jedem dasselbe.
    $this->assertStringContainsString('conic-gradient', $blade);
    $this->assertStringContainsString('$profilStand[\'prozent\']', $blade);
}

public function test_gruppen_sind_antippbare_zeilen(): void
{
    $blade = $this->blade();

    $this->assertStringContainsString('grouprow tap', $blade);
    $this->assertStringContainsString('wire:click="oeffneGruppe(', $blade);
    $this->assertStringContainsString('chev', $blade);
}

public function test_datei_felder_erscheinen_als_kacheln_und_oeffnen_das_nachweis_blatt(): void
{
    $blade = $this->blade();

    $this->assertStringContainsString('class="uploads"', $blade);
    $this->assertStringContainsString('oeffneUpload(', $blade);
    // Kein zweiter Upload-Weg: im Profil gibt es KEIN eigenes Datei-Feld
    // (R20/E8) und keine zweite Zuordnungsliste (E7).
    $this->assertStringNotContainsString('wire:model="upload' , $blade);
}

public function test_das_gruppen_blatt_traegt_den_erklaertext(): void
{
    $blade = $this->blade();

    $this->assertStringContainsString('$profilHinweis', $blade);
}

public function test_pflichtfelder_bekommen_einen_roten_rand(): void
{
    // E2: roter Rand statt "noch nicht eingetragen" an jedem leeren Feld.
    $this->assertStringContainsString("'fehlt'", $this->blade());
}

public function test_das_lebendige_ja_nein_gibt_es_nur_wo_es_gebraucht_wird(): void
{
    // E16: 'live' nur beim Hauptarbeitgeber -- alle anderen Ja/Nein-Felder
    // bleiben bei der gesammelten Uebertragung, kein zusaetzlicher Serverweg.
    $blade = $this->blade();

    $this->assertStringContainsString("\$feld['live']", $blade);
    $this->assertStringContainsString('wire:model.live="profilWerte.', $blade);
    $this->assertStringContainsString('wire:model.defer="profilWerte.', $blade);
}

public function test_maxlength_kommt_aus_der_feld_definition(): void
{
    // E14
    $this->assertStringContainsString("\$feld['maxlength']", $this->blade());
}

public function test_es_gibt_weiterhin_kein_inputmode(): void
{
    // E3 -- Login-Blocker vom 06.08.2026. Gilt fuer das ganze Blade.
    $this->assertStringNotContainsString('inputmode', $this->blade());
}

public function test_nur_lese_felder_sind_als_solche_gekennzeichnet(): void
{
    // §1.2
    $blade = $this->blade();

    $this->assertStringContainsString('$nurLesen', $blade);
    $this->assertStringContainsString('nicht änderbar', $blade);
}

public function test_der_gruppenname_steht_nicht_in_einer_zweiten_liste(): void
{
    // §1.4 Punkt 2: die Doppelliste ist der Grund fuer E7. Im Blade darf
    // kein @php-Block eine eigene Feld- oder Datei-Zuordnung aufmachen.
    $this->assertStringNotContainsString('fileUploadProps', $this->blade());
}

public function test_blade_kompiliert(): void
{
    $datei = dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php';
    $werkzeug = dirname(__DIR__, 2) . '/tools/blade-check.php';

    exec('php ' . escapeshellarg($werkzeug) . ' ' . escapeshellarg($datei), $ausgabe, $code);

    $this->assertSame(0, $code, implode("\n", $ausgabe));
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellProfilBladeTest`
Erwartet: FEHLER, keine Ring-Zeile, keine Gruppenzeilen.

- [ ] **Schritt 3: Minimal bauen**

Im `pane` `tab === 'me'` wird der bisherige Arbeitgeber-Kasten durch diese
Reihenfolge ersetzt (Anstellungen und Abmelden bleiben, wo sie sind):

1. `greet`
2. **Ring-Zeile**

```blade
@php
    $prozent = $profilStand['prozent'];
    $ringStil = 'background: conic-gradient(var(--brand) 0 ' . $prozent
        . '%, var(--surface-3) ' . $prozent . '% 100%)';
    $ringTitel = $prozent === 100
        ? 'Alles vollständig'
        : ($prozent >= 80 ? 'Fast vollständig' : 'Da fehlt noch einiges');
    $ringText = $profilStand['fehlend'] === []
        ? ($duzen ? 'Wir haben alles, was wir von dir brauchen.' : 'Wir haben alles, was wir von Ihnen brauchen.')
        : ($duzen ? 'Es fehlen noch: ' : 'Es fehlen noch: ')
            . implode(', ', array_slice($profilStand['fehlend'], 0, 3))
            . (count($profilStand['fehlend']) > 3 ? ' und weitere' : '') . '.';
@endphp
<div class="ring-row">
    <div class="ring" style="{{ $ringStil }}"><b>{{ $prozent }}%</b></div>
    <div class="txt">
        <div class="t">{{ $ringTitel }}</div>
        <div class="s">{{ $ringText }}</div>
    </div>
</div>
```

3. **Kacheln** — `.uploads` mit einer `.up`-Kachel je Nachweisart aus `$kacheln`,
   `wire:click="oeffneUpload('…')"`, `.filled` wenn vorhanden.
4. **„Deine Angaben"** — `.card` mit einer `.grouprow.tap` je Eintrag aus
   `$profilGruppen`: Name, `PortalGroupSummary`-Zeile, `.chev`; bei offenen
   Pflichtfeldern ein `.dot crit`.
5. **„Einträge bei Bewerbung"** — `.card` mit `.grouprow` je `$nurLesen`,
   Abzeichen „nicht änderbar".
6. **Das Gruppen-Blatt** als Ueberlagerung, gebaut wie das Nachweis-Blatt:

```blade
@if ($profilGruppe !== null)
    <div class="upload-overlay" wire:click.self="schliesseGruppe">
        <form class="upload-sheet" wire:submit="speichereGruppe">
            <div class="upload-head">
                <h3>{{ $profilGruppe }}</h3>
                <button type="button" class="upload-close" wire:click="schliesseGruppe" aria-label="Schließen">&times;</button>
            </div>
            @if ($profilHinweis !== null)
                <p class="upload-sub">{{ $profilHinweis }}</p>
            @endif

            @foreach ($profilFelder as $schluessel => $feld)
                @php
                    // Vorberechnen statt @if im Attribut — Hausregel.
                    $randKlasse = $feld['fehlt'] ? 'feld fehlt' : 'feld';
                    $optionen   = $feld['type'] === 'lookup' ? $this->lookupOptionen($feld['lookup'] ?? '') : [];
                @endphp
                <label class="{{ $randKlasse }}">
                    <span class="n">{{ $feld['label'] }}</span>

                    @if ($feld['type'] === 'lookup')
                        <select wire:model.defer="profilWerte.{{ $schluessel }}">
                            <option value="">— bitte wählen —</option>
                            @foreach ($optionen as $wert => $text)
                                <option value="{{ $wert }}">{{ $text }}</option>
                            @endforeach
                        </select>

                    @elseif ($feld['type'] === 'bool')
                        {{-- 'live' nur dort, wo ein anderes Feld an der Auswahl
                             haengt (Hauptarbeitgeber, E16). Alle anderen
                             Ja/Nein-Felder bleiben bei der gesammelten
                             Uebertragung — kein zusaetzlicher Serverweg. --}}
                        @if (!empty($feld['live']))
                            <select wire:model.live="profilWerte.{{ $schluessel }}">
                                <option value="">— bitte wählen —</option>
                                <option value="1">Ja</option>
                                <option value="0">Nein</option>
                            </select>
                        @else
                            <select wire:model.defer="profilWerte.{{ $schluessel }}">
                                <option value="">— bitte wählen —</option>
                                <option value="1">Ja</option>
                                <option value="0">Nein</option>
                            </select>
                        @endif

                    @elseif ($feld['type'] === 'date')
                        {{-- Kein style="color-scheme: light" am Feld: das macht
                             die Regel :root { color-scheme: light } im Layout
                             fuer die ganze Seite (E4). --}}
                        <input type="date" wire:model.defer="profilWerte.{{ $schluessel }}">

                    @elseif ($feld['type'] === 'inline_select')
                        {{-- Fuenfter Typ, den der Docblock nicht kennt (§1.4
                             Punkt 1): Wert und Beschriftung sind derselbe
                             String — tax_class 1..6, shirt_size S..XL. --}}
                        <select wire:model.defer="profilWerte.{{ $schluessel }}">
                            <option value="">— bitte wählen —</option>
                            @foreach (($feld['options'] ?? []) as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>

                    @else
                        {{-- maxlength aus der Feld-Definition, wo eine steht
                             (E14). Der harte Schutz sitzt im Waechter. KEIN
                             inputmode (E3). --}}
                        @if (!empty($feld['maxlength']))
                            <input type="text" wire:model.defer="profilWerte.{{ $schluessel }}"
                                   maxlength="{{ $feld['maxlength'] }}" autocomplete="off">
                        @else
                            <input type="text" wire:model.defer="profilWerte.{{ $schluessel }}"
                                   autocomplete="off">
                        @endif
                    @endif
                </label>
            @endforeach

            @if ($profilFehler !== '')
                <div class="alert crit">
                    <span class="dot crit" style="margin-top:6px"></span>
                    <div class="txt">{{ $profilFehler }}</div>
                </div>
            @endif

            <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="speichereGruppe">Speichern</button>
            <button type="button" class="btn" wire:click="schliesseGruppe">Abbrechen</button>
        </form>
    </div>
@endif
```

Die fuenf Typ-Zweige entsprechen `employee-portal.blade.php:294-383` — ohne den
`file`-Zweig, den es im Profil nicht mehr gibt (R20/E8). Die Ja/Nein-Bindung
steht bewusst als zwei ausgeschriebene Zweige da und nicht als vorberechneter
Attribut-String: `wire:model.live` und `wire:model.defer` sind fuer Livewire
verschiedene Attribute, ein zusammengesetzter String waere still wirkungslos
(dieselbe Klasse Fehler wie an Wortzeichen geklebte Direktiven).

CSS-Ergaenzungen in `resources/views/layouts/portal.blade.php` (im
`@verbatim`-Block, nicht in `portal-styles.blade.php` — dort steht der
abgenommene Entwurf unveraendert):

```css
.portal-body .grouprow.tap { cursor: pointer; background: none; border: none;
    border-bottom: 1px solid var(--line); width: 100%; text-align: left; font: inherit; color: inherit }
.portal-body .grouprow.tap:hover { background: var(--surface-2) }
.portal-body .up.tap { cursor: pointer; font: inherit; color: inherit; width: 100% }
.portal-body .feld.fehlt input, .portal-body .feld.fehlt select { border-color: var(--crit) }
```

- [ ] **Schritt 4: Test laufen lassen, gruen sehen** — dazu
  `php tools/blade-check.php resources/views/livewire/public/portal-shell.blade.php`
  und `php tools/blade-check.php resources/views/layouts/portal.blade.php`
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): Profil im neuen Portal als Gruppenzeilen mit Vollstaendigkeitsring`

---

### Task 8: Vertraege und Zertifikate im Dokumente-Bereich

Ohne diese Aufgabe verliert ein umgestellter Mensch den Weg zur Unterschrift —
das alte Portal ist der einzige Ort, an dem er seine offenen Vertraege sieht
(§5 Punkte 10/11). E17 haengt an der **gerenderten** Blade und ist per
Quelltext-Diff leicht zu verlieren.

**Files:**
- Change: `src/Livewire/Public/PortalShell.php` (Methode `dokumente()`)
- Change: `resources/views/livewire/public/portal-shell.blade.php`
- Test: `tests/Integration/PortalShellDokumenteTest.php`

**Interfaces:**
- Consumes: `RecEmployee::applicant`, `TrainingCertificatePortalRows::append()`,
  `TrainingCertificatePortalRows::row()`, `RecTrainingCertificate`,
  `HasPublicFormLink::getOrCreatePublicFormLink()`
- Produces: Render-Daten `dokumente` als
  `list<array{id:int, display_name:string, status:string, signed_at:mixed, completed_at:mixed, sign_url:string, pdf_url:?string}>`
  — dieselbe Form wie `EmployeePortal::contracts()`, damit die Zeilenlogik
  eins zu eins uebernommen werden kann

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_stornierte_vertraege_werden_nicht_gezeigt(): void
{
    // §5 Punkt 11
    $dokumente = $this->dokumenteFuer($this->mitarbeiterMitVertraegen(['sent', 'cancelled']));

    $this->assertCount(1, $dokumente);
    $this->assertSame('sent', $dokumente[0]['status']);
}

public function test_pdf_gibt_es_erst_bei_completed(): void
{
    $offen = $this->dokumenteFuer($this->mitarbeiterMitVertraegen(['sent']));
    $this->assertNull($offen[0]['pdf_url']);

    $fertig = $this->dokumenteFuer($this->mitarbeiterMitVertraegen(['completed']));
    $this->assertNotNull($fertig[0]['pdf_url']);
}

public function test_das_zertifikat_behauptet_keine_unterschrift(): void
{
    // E17: die Zertifikat-Zeile traegt das AUSSTELLUNGSDATUM in signed_at und
    // gewinnt damit die Bedingung "completed || signed_at". Der issued-Zweig
    // MUSS im Blade vor der Unterschrieben-Bedingung stehen. Gemessen an der
    // gerenderten Blade, wie in PortalCertificateBadgeTest -- ein
    // Quelltext-Diff verliert diese Reihenfolge lautlos.
    $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');

    $issued = strpos($blade, "'issued'");
    $signed = strpos($blade, "'completed'");

    $this->assertNotFalse($issued, 'Der issued-Zweig fehlt');
    $this->assertNotFalse($signed);
    $this->assertLessThan($signed, $issued, 'Der issued-Zweig muss VOR der Unterschrieben-Bedingung stehen (E17)');
}

public function test_ohne_bewerber_gibt_es_keine_dokumente_und_keinen_fehler(): void
{
    $this->assertSame([], $this->dokumenteFuer($this->mitarbeiter()));
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellDokumenteTest`
Erwartet: FEHLER, `dokumente()` gibt es nicht.

- [ ] **Schritt 3: Minimal bauen**

`PortalShell::dokumente(RecEmployee $employee): array` uebernimmt den Rumpf von
`EmployeePortal::contracts()` (`658-701`) samt `certificateRows()` (`718-730`),
mit dem Kommentar zu N8: schon das **Anzeigen** legt `CorePublicFormLink`-Zeilen
an — je eine fuer den Bewerber und fuer jeden nicht stornierten Vertrag. Das ist
im alten Portal genauso und bleibt so; wer es aendert, aendert die
PDF-Pruefung im `ContractPdfController` mit.

Im Blade wandert die Liste in den `docs`-Bereich, oberhalb der Nachweise, als
`.card` mit `.doc`-Zeilen aus dem Entwurf (`.docicon`, `.title`, `.sub`,
`.row` mit `.mini` / `.mini.primary`) — die CSS dafuer liegt schon in
`portal-styles.blade.php:150-160`. Die Statuszweige werden woertlich aus
`employee-portal.blade.php:131-162` uebernommen, **`issued` zuerst**.

- [ ] **Schritt 4: Test laufen lassen, gruen sehen** — dazu
  `--filter "PortalCertificateBadgeTest|PortalCertificateWiringTest|TrainingCertificatePortalRowsTest"`
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): Vertraege und Zertifikate im neuen Portal`

---

### Task 9: Die Abnahme gegen die Liste

Ein Test, der belegt, dass kein Feld und keine Regel fehlt. Er ersetzt kein
Sichtprüfen, aber er faellt um, sobald jemand ein Feld herausnimmt.

**Files:**
- Create: `tests/Integration/PortalGleichstandTest.php`

**Interfaces:**
- Consumes: alles Bisherige
- Produces: nichts

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
/**
 * Abnahme gegen docs/superpowers/specs/2026-09-25-portal-bestandsaufnahme.md.
 *
 * Zuordnung Test → Regel:
 *   §1.1 (47 Felder)  test_alle_siebenundvierzig_felder_sind_erreichbar
 *   §1.2              test_die_zwei_nur_lese_felder_werden_gezeigt
 *   §1.3              test_die_ausgeschlossenen_felder_bleiben_ausgeschlossen
 *   §1.4 Punkt 1      test_inline_select_wird_gerendert
 *   §1.4 Punkt 2, E7  test_es_gibt_keine_zweite_datei_liste
 *   §1.4 Punkt 3, E15 test_die_zwei_erklaertexte_stehen_im_neuen_portal
 *   §2.1, R15-R18     test_die_waechter_kaskade_ist_verdrahtet
 *   R19-R23           PortalProfileWriterTest
 *   R24-R26           PortalShellUploadTest (Bestand)
 *   R27, R29, R30     test_sichtbarkeit_nach_eu_status_und_beschaeftigung
 *   R28               PortalFieldRelevanceTest
 *   R31, N7           PortalProfileWriterTest
 *   N1, N2, §3.2/3.3  PortalProfileWriterTest
 *   E3                PortalShellProfilBladeTest
 *   E11               PortalProfileGuardsTest
 *   E17               PortalShellDokumenteTest
 *   E18               EmployeePortalV2RedirectTest (Bestand)
 *   §6                PortalAuthTest, PortalShellVerifyTest (Bestand)
 */
public function test_alle_siebenundvierzig_felder_sind_erreichbar(): void
{
    // Ein Mensch, bei dem JEDE Gruppe sichtbar ist: Non-EU und Schueler
    // decken zusammen 45 Felder ab; die zwei Immatrikulations-Felder
    // kommen aus dem zweiten Datensatz.
    $schueler = $this->mitarbeiter(['is_eu_citizen' => false, 'employment_type' => 'schueler']);
    $student  = $this->mitarbeiter(['is_eu_citizen' => false, 'employment_type' => 'student']);

    $erreichbar = array_merge(
        array_keys(PortalFieldAccess::sichtbareFelderFlach($schueler->editableFieldGroups(), [], [])),
        array_keys(PortalFieldAccess::sichtbareFelderFlach($student->editableFieldGroups(), [], [])),
    );
    $erreichbar = array_values(array_unique($erreichbar));

    $this->assertCount(47, $erreichbar, 'Die Bestandsaufnahme nennt 47 editierbare Felder');

    // Jedes Feld hat einen Weg: entweder ein Formularfeld im Gruppen-Blatt
    // oder -- als Datei -- eine Kachel, die ein Nachweis-Blatt oeffnet.
    $flach = $schueler->editableFieldsFlat() + $student->editableFieldsFlat();
    foreach ($erreichbar as $feld) {
        if (($flach[$feld]['type'] ?? 'text') === 'file') {
            $this->assertNotNull(
                ProofTypes::codeForLegacyColumn($feld),
                "Datei-Feld {$feld} hat keine Nachweisart -- es bekaeme eine Kachel ohne Ziel (E7)",
            );
            continue;
        }
        $this->assertArrayHasKey('label', $flach[$feld], "Feld {$feld} hat keine Beschriftung");
    }
}

public function test_die_zwei_nur_lese_felder_werden_gezeigt(): void
{
    $ma = $this->mitarbeiter(['identity_card_number' => 'L01X00T47', 'recruited_by_personnel_number' => 'MA123']);

    $this->assertSame(
        ['identity_card_number', 'recruited_by_personnel_number'],
        array_keys($ma->readOnlyDisplayFields()),
    );
    $this->assertStringContainsString('$nurLesen', $this->blade());
}

public function test_die_ausgeschlossenen_felder_bleiben_ausgeschlossen(): void
{
    // §1.3 -- first_name/birth_date/identity_card_number sind Login-Faktoren,
    // eine Aenderung wuerde aussperren.
    $flach = $this->mitarbeiter(['is_eu_citizen' => false])->editableFieldsFlat();

    foreach (['first_name', 'last_name', 'birth_date', 'identity_card_number',
              'is_eu_citizen', 'recruited_by_personnel_number', 'personnel_number'] as $feld) {
        $this->assertArrayNotHasKey($feld, $flach);
    }
}

public function test_inline_select_wird_gerendert(): void
{
    // §1.4 Punkt 1: ein fuenfter Typ, den der Docblock nicht kennt.
    $this->assertStringContainsString('inline_select', $this->blade());
}

public function test_es_gibt_keine_zweite_datei_liste(): void
{
    $quelle = file_get_contents((new ReflectionClass(PortalShell::class))->getFileName());

    $this->assertStringNotContainsString('FILE_FIELDS', $quelle);
    $this->assertStringNotContainsString('fileUploadProps', $this->blade());
    $this->assertStringContainsString('codeForLegacyColumn', $quelle);
}

public function test_die_zwei_erklaertexte_stehen_im_neuen_portal(): void
{
    // §7 Punkt 1: das, was beim Umbau am ehesten verschwindet -- und der
    // Arbeitgeber-Text ist der einzige Ort im Produkt, der den Minijob
    // erklaert. Ohne ihn waehlen Schueler und Studenten "Nein" und loesen
    // die falsche Steuerklasse aus.
    $quelle = file_get_contents((new ReflectionClass(PortalShell::class))->getFileName());

    $this->assertStringContainsString('PortalSectionHints', $quelle);
    $this->assertStringContainsString('$profilHinweis', $this->blade());
    $this->assertStringContainsString('Minijob', PortalSectionHints::fuer('Arbeitgeber', true));
    $this->assertStringContainsString('Schein', PortalSectionHints::fuer('Arbeitsschutz', true));
}

public function test_die_waechter_kaskade_ist_verdrahtet(): void
{
    $writer = file_get_contents((new ReflectionClass(PortalProfileWriter::class))->getFileName());
    $guards = file_get_contents((new ReflectionClass(PortalProfileGuards::class))->getFileName());

    $this->assertStringContainsString('PortalProfileGuards::fehler(', $writer);

    // §2.1: die Reihenfolge ist bindend.
    $erst = strpos($guards, 'FirstAiderDateGuard::error(');
    $zweit = strpos($guards, 'NationalityRequiredGuard::error(');
    $dritt = strpos($guards, 'MainEmployerRequiredGuard::error(');
    $this->assertLessThan($zweit, $erst);
    $this->assertLessThan($dritt, $zweit);

    // E11: kein (string)-Cast auf dem dreiwertigen Feld.
    $this->assertStringNotContainsString('(string) $datensatz[\'is_main_employer\']', $guards);
}

public function test_sichtbarkeit_nach_eu_status_und_beschaeftigung(): void
{
    // R29, R30
    $eu = $this->mitarbeiter(['is_eu_citizen' => true, 'employment_type' => 'aushilfe']);
    $this->assertArrayNotHasKey('Aufenthalt (Non-EU)', $eu->editableFieldGroups());
    $this->assertArrayNotHasKey('Schul-/Immatrikulationsbescheinigung', $eu->editableFieldGroups());

    $unbekannt = $this->mitarbeiter(['is_eu_citizen' => null]);
    $this->assertArrayNotHasKey('Aufenthalt (Non-EU)', $unbekannt->editableFieldGroups());

    $schueler = $this->mitarbeiter(['is_eu_citizen' => false, 'employment_type' => 'schueler']);
    $gruppen = $schueler->editableFieldGroups();
    $this->assertArrayHasKey('Aufenthalt (Non-EU)', $gruppen);
    $this->assertArrayHasKey('schulbescheinigung_file_id', $gruppen['Schul-/Immatrikulationsbescheinigung']);
    $this->assertArrayNotHasKey('immatrikulation_file_id', $gruppen['Schul-/Immatrikulationsbescheinigung']);
}

public function test_das_alte_portal_leitet_weiter_solange_es_steht(): void
{
    // E18/R2 -- muss bleiben, bis das alte Portal abgeschaltet ist.
    $alt = file_get_contents((new ReflectionClass(EmployeePortal::class))->getFileName());

    $this->assertStringContainsString('recruiting.public.portal-shell', $alt);
}
```

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**

Lauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalGleichstandTest`
Erwartet: FEHLER — mindestens die Feldzahl und die Erklaertexte.

- [ ] **Schritt 3: Luecken schliessen**, bis der Test gruen ist. Wird die Zahl
  47 verfehlt, ist zuerst zu pruefen, ob die Bestandsaufnahme oder der Code
  recht hat — **nicht** die Zahl im Test anpassen.

- [ ] **Schritt 4: Gesamtlauf** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`
  (Default-Reihenfolge, s. `phpunit.xml`)
- [ ] **Schritt 5: Festschreiben** — `test(recruiting): Abnahme des neuen Portals gegen die Bestandsaufnahme`

---

### Task 10: Die Bremse entfaellt

**Der Punkt, an dem `--profil-fehlt-mir-egal` verschwinden darf:** wenn Task 9
gruen ist **und** die Sichtprüfung auf demo mit einem echten Datensatz gelaufen
ist (alle 13 Gruppen geoeffnet, je ein Feld gespeichert, Ring bewegt sich,
beide Erklaertexte gelesen, ein Vertrag ueber den neuen Weg unterschrieben).
**Nicht frueher** — die Bremse ist die einzige Stelle, die verhindert, dass
jemand umgestellt wird, dessen Stammdaten dann nirgends mehr ankommen.

Was **bleibt**: die Weiche `R2/E18` im alten Portal
(`EmployeePortal.php:132-136`). Sie faellt erst, wenn das alte Portal samt
Route abgeschaltet wird — und das ist ein eigener Auftrag, nicht Teil dieses
Plans: alle bereits verschickten WhatsApp-Links zeigen auf `/mitarbeiter/{token}`.

**Files:**
- Change: `src/Console/Commands/SwitchPortalVersion.php`
- Change: `tests/Integration/SwitchPortalVersionTest.php`

**Interfaces:**
- Produces: `recruiting:portal-umstellen` ohne Bestaetigungsoption

- [ ] **Schritt 1: Den fehlschlagenden Test schreiben**

```php
public function test_umstellen_laeuft_jetzt_ohne_bestaetigung(): void
{
    // Die Bremse vom 25.09.2026 ist entfallen: das neue Portal pflegt
    // Stammdaten (PortalGleichstandTest) und kennt die Arbeitgeber-
    // Pflichtfrage als Gruppe wie jede andere.
    [$exitCode] = $this->runCommand(['--ids' => (string) $this->id]);

    $this->assertSame(0, $exitCode);
    $this->assertNotNull(DB::table('rec_employees')->where('id', $this->id)->value('portal_v2_since'));
}

public function test_die_option_gibt_es_nicht_mehr(): void
{
    $this->assertStringNotContainsString(
        'profil-fehlt-mir-egal',
        file_get_contents((new ReflectionClass(SwitchPortalVersion::class))->getFileName()),
    );
}
```

Die fuenf Bestandstests, die die Bremse messen
(`test_umstellen_ohne_bestaetigung_bricht_ab_und_aendert_nichts`,
`test_umstellen_mit_alle_ohne_bestaetigung_bricht_ebenfalls_ab`,
`test_trockenlauf_ohne_bestaetigung_bricht_ebenfalls_ab`,
`test_umstellen_mit_bestaetigung_laeuft`,
`test_umstellen_mit_bestaetigung_und_alle_laeuft`) werden entfernt bzw. auf die
Form ohne Option gezogen. Die drei `--zurueck`-Tests bleiben unveraendert —
die Notbremse bleibt.

- [ ] **Schritt 2: Test laufen lassen, Fehlschlag sehen**
- [ ] **Schritt 3: Option, Abbruch und den Warnblock aus `handle()` entfernen**,
  den Klassenkommentar um den Grund ergaenzen (Bremse gesetzt 25.09.2026,
  entfallen nach Gleichstand)
- [ ] **Schritt 4: Test laufen lassen, gruen sehen; danach Gesamtlauf**
- [ ] **Schritt 5: Festschreiben** — `feat(recruiting): das neue Portal ist Ersatz fuer das alte, Umstell-Bremse entfaellt`

---

## Abdeckung gegen die Bestandsaufnahme

| Abschnitt | Wo abgedeckt |
|---|---|
| §1.1, 47 Felder | 2 (Zugang), 4 (Schreiben), 7 (Render), 9 (Abnahme) |
| §1.2, Nur-Lese-Felder | 6 (`nurLesen`), 7, 9 |
| §1.3, Ausgeschlossene | 4 (R19), 9 |
| §1.4.1 `inline_select` | 6 (`anzeigewert`), 7, 9 |
| §1.4.2 Doppelliste / E7 | 1, 7, 9 — abgeschafft, nicht nachgebaut |
| §1.4.3 zwei Erklaertexte | 5, 7, 9 |
| §1.4.4 leere Gruppenkarte | **bewusst nicht** — s. unten |
| §2.1 Reihenfolge | 3, 9 |
| R1 | Bestand (`PortalShell::mount`, 404 statt Zustand — §6 nennt das strenger) |
| R2 | Bestand, bleibt bis zur Abschaltung (Task 10) |
| R3, R12, R13, R14 | Bestand (`berechtigterMitarbeiter`), 6 |
| R4–R11 | Bestand (`PortalAuth`, `PortalAuthTest`, `PortalShellVerifyTest`) |
| R15, R16, R17, R18 | 3 |
| R19, R20, R21, R22, R23 | 4 |
| R24, R25, R26 | Bestand (`speichereNachweis`, `PortalShellUploadTest`) |
| R27 | 2 |
| R28 | 2, 5 |
| R29, R30 | Modell (wiederverwendet), Test in 9 |
| R31 | 4 (Eloquent-Mutatoren) |
| N1, N2, N5, N7 | 4 |
| N3 | Bestand (`speichereNachweis`) |
| N4 | **bewusst abweichend** — Query Builder, s. unten |
| N6 | nicht erreichbar, nichts zu tun |
| N8 | 8 |
| N9, N10 | Bestand (`PortalAuth`, geteilte Schluessel) |
| N11 | Global Constraint: keine `#[Computed]` in `PortalShell` |
| §3.1 | nichts zu tun (kein Protokoll, keine Benachrichtigung, kein `portal_last_seen_at`) |
| §3.2 | 4 (Test der fuenf verbotenen Spalten) |
| §3.3 | 4 (Lohn-Trigger) |
| E1 | **nicht anwendbar** — Tailwind-Variable, neues Portal hat eigene CSS |
| E2 | 5 (`fehlend`), 7 (roter Rand), Label kommt aus dem Modell |
| E3 | 7, 9 |
| E4 | Layout (`:root { color-scheme: light }`) — **abweichende Umsetzung**, s. unten |
| E5 | Bestand (Anmeldeblatt der Huelle) |
| E6 | 6 (`#[Locked] $profilGruppe`) |
| E7 | 1, 7, 9 |
| E8 | 4 |
| E9 | 2, 5 |
| E10 | **nicht anwendbar** — eigenes Layout ohne Guest-Layout |
| E11 | 3 |
| E12 | Modell (Beschriftung „Wer ist es dann?") |
| E13 | 2, 3, 4 (`PortalBoolValue` ueberall) |
| E14 | 3 (Guard), 7 (`maxlength`) |
| E15 | 5 |
| E16 | 2 (`visible_if`), 7 (`live`) |
| E17 | 8 |
| E18 | Bestand, Test in 9; faellt erst mit der Abschaltung |
| §5 Punkte 1, 2, 5, 6 | Zugewinn des neuen Portals, keine Abnahmeanforderung |
| §5 Punkte 10, 11 | 8 |
| §6 gesamte Tabelle | Bestand; die Abweichungen sind dort schon als bewusst vermerkt |

### Was dieser Plan NICHT abdeckt — und warum

1. **§1.4 Punkt 4, die leere Gruppenkarte.** Das alte Blade rendert eine Gruppe
   ohne Felder als leere Karte mit Ueberschrift; die Bestandsaufnahme nennt
   selbst „unklar", ob das je auftritt. Das neue Portal laesst leere Gruppen
   weg (`PortalFieldAccess::sichtbareGruppen`, Test in Task 2). **Bewusst
   nicht** nachgebaut: eine leere Karte waere in der Zeilendarstellung eine
   Zeile, die beim Antippen ein leeres Blatt oeffnet.
2. **N4, `portal_verified_at` ueber Eloquent.** Das neue Portal schreibt den
   Stempel weiter ueber den Query Builder (`PortalShell.php:163-167`). §6 nennt
   das einen bewussten Unterschied; das Feld steht nicht in der Relevanzliste,
   es geht also kein Marker verloren.
3. **R26, die Roh-Ausnahmemeldung beim Upload.** Das alte Portal zeigt dem
   Mitarbeiter `$e->getMessage()`. Das neue zeigt einen festen Satz und
   `report()`-et die Ausnahme — die Bestandsaufnahme nennt das selbst einen
   Zugewinn und fuehrt es unter den offenen Punkten.
4. **E1 und E10.** Beide haengen an Tailwind bzw. am Guest-Layout aus
   platforms-core. Das neue Portal bringt sein eigenes Layout mit
   (`layouts/portal.blade.php`), in dem es weder `--ui-primary-dark` noch ein
   geerbtes `dark:text-white` gibt. **Nicht anwendbar**, nicht vergessen.
5. **E4, `style="color-scheme: light"` an jedem Datumsfeld.** Ersetzt durch die
   Regel `:root { color-scheme: light }` im Portal-Layout, die zusaetzlich einen
   dunklen Zweig hat. Gleiche Wirkung, eine Stelle statt sieben. **Abweichende
   Umsetzung, gleiche Zusage.**
6. **§5 Punkt 3 (Datei ansehen, herunterladen, ersetzen-und-loeschen).** Kann
   das alte Portal nicht, kann das neue nicht. Kein Rueckschritt, aber auch
   kein Fortschritt — bleibt offen.
7. **§5 Punkt 12 (Abmelden vom Sperr- oder Ratenbegrenzungs-Bildschirm).**
   Bleibt wie im alten Portal: den Knopf gibt es nur im angemeldeten Zustand.
8. **§5 Punkte 4, 7, 8, 13, 14** (Einsaetze, Konto/Canvas 68,
   `portal_last_seen_at`, Feldvalidierung, Mehrsprachigkeit). Weder alt noch
   neu — ausserhalb dieses Auftrags.
9. **Ein einziger „Alles speichern"-Knopf.** Das alte Portal speichert 47
   Felder auf einmal, das neue speichert gruppenweise. Die Waechter-Kaskade
   bleibt dabei eine Endzustandspruefung und blockt auch ein Speichern, das nur
   die Schuhgroesse aendert (R15–R17) — das ist Absicht und in Task 6 getestet.
   Der Preis: der Fehlertext nennt eine andere Gruppe als die offene. Deshalb
   traegt der Text weiterhin den vollen Satz mit dem Feldnamen, woertlich wie
   im alten Portal.
10. **Die Freigabe des Arbeitgeber-Erklaertexts** durch jemanden, der die
    Lohnabrechnung verantwortet (offener Punkt aus Commit `0f9cffa`). Der Text
    wandert woertlich mit; die Freigabe ist eine Frage an den Kunden, kein
    Bauteil.
11. **Doppeltuer bei fuenf Datumsfeldern.** `identity_card_valid_until`,
    `school_certificate_valid_until`, `first_aider_valid_until`,
    `residence_permit_valid_until` und `work_permit_valid_until` sind zugleich
    editierbare Profilfelder **und** `ablauf_spalte` einer Nachweisart
    (`ProofTypes`). Ueber das Profil geschrieben setzen sie den ZAS-Marker
    (wie im alten Portal), ueber das Nachweis-Blatt nicht (`ProofWriter`,
    Query Builder). Beide Wege bleiben — das Profil, weil die Bestandsaufnahme
    47 editierbare Felder verlangt; das Blatt, weil dort die Datei dazugehoert.
    **Bewusst so, hier festgehalten, damit es niemand fuer einen Fehler haelt.**

## Danach, nicht von mir abhaengig

- **Sichtprüfung auf demo** mit einem echten Datensatz, bevor Task 10 laeuft.
- **Freigabe des Arbeitgeber-Texts** durch die Lohnabrechnung (s. oben).
- **Abschaltung des alten Portals** samt Route `/mitarbeiter/{token}` — eigener
  Auftrag. Bis dahin bleibt die Weiche R2/E18 stehen; alle verschickten
  WhatsApp-Links zeigen dorthin.
- **`is_main_employer` im Einstellungs-Modal** bei Bestandsteams anhaken
  (§3.3 Falle b, Commit `4f232f0`): Teams mit eigener gespeicherter Feldauswahl
  bekommen den Lohn-Trigger sonst auch nach Task 4 nicht. Dabei die bekannte
  Falle beachten, dass Selects im Einstellungs-Modal nicht speichern.
