# Konto und Anmeldeschicht (Gate D) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Mitarbeiter legt sich einmal ein Konto an und meldet sich danach mit **Handynummer und Passwort** an — auf jedem Geraet, ohne Link, ohne Ausweis zur Hand.

**Architecture:** Die Anmeldeschicht `PortalAuth` bekommt einen **zweiten Einstieg** neben dem heutigen Token-Weg; beide laufen waehrend der Umstellung nebeneinander. Die Kontodaten liegen an der Personen-Zeile aus Stufe 1 (`rec_persons`). Ein einziger Schreiber (`KontoWriter`) fasst sie an, nach demselben Muster wie `PersonLinker`. Die Regeln (Passwort, Einladungs-Token, Einmalcode) sind **reine Logik** und getrennt testbar.

**Tech Stack:** Laravel 11, Livewire 3, PHPUnit (Unit pur / Integration mit handgebautem Capsule + SQLite), `Illuminate\Support\Facades\Hash`, Symfony UuidV7.

**Spec:** `docs/superpowers/specs/2026-09-28-mitarbeiterkonto-canvas68.md` — massgeblich sind **§2 (Das Konto)**, **§3 (Registrierung)** und **§5 (Zuruecksetzen, fuenf Wege)**. Autoritaet ist das Canvas 68, die Spec argumentiert daraus.

**Branch:** `feat/ma-konto`, aufsetzend auf dem Stand der Personen-Klammer (`7d2f5f8`).

## Global Constraints

Jede Aufgabe traegt diese Anforderungen implizit mit.

- **DAS ALTE VERFAHREN DARF NIE ALS ZWEITER ANMELDEWEG DANEBENSTEHEN.** Heute ist der TOKEN das Geheimnis; kuenftig ist der Benutzername die Handynummer, die kein Geheimnis ist. Bliebe „Geburtsdatum + Ausweis-Endziffern" als alternativer Login offen, ginge jeder, der die Nummer kennt, durch die Nebentuer — **unsicherer als der Ist-Zustand**. Ausweisziffern kommen nur in Weg 4 des Zuruecksetzens vor, dort mit zweitem Nachweis und HR-Stopp.
- **ZWEI-NACHWEIS-REGEL (tragend, Spec §2.4).** Ein Code allein reicht nie. Kontoanlage = Token + Geburtsdatum · Passwort zuruecksetzen = Code + Geburtsdatum · Nummernwechsel = Passwort + Code an die neue Nummer.
- **EIN SCHREIBER.** Nur `KontoWriter` setzt oder aendert die Kontofelder an `rec_persons`. Muster: `src/Services/PersonLinker.php`.
- **OBSERVER-FREI, wo `rec_employees` beruehrt wird.** Wandert die Nummer auf die Anstellungen, laeuft das ueber den Query Builder — eine Kontoaenderung darf `zas_changed_at` nicht setzen.
- **DIE NUMMER DER PERSON IST DIE WAHRHEIT.** Aendert sie sich, wird sie im selben Vorgang auf **alle** Anstellungen dieser Person geschrieben (Spec §4.3 Regel 5). Sonst geht der Einmalcode an eine andere Nummer als die Anmeldung — die Aussperrung aus §9.2.
- **`portal_locked_at` bleibt wirksam.** Die Dispo-Sperre (Eskalationsstufe 3) gilt auch fuer das Konto.
- **Geheimnisse werden nie im Klartext gespeichert.** Passwort, Einladungs-Token und Einmalcode liegen als Hash. Eine gestohlene Datenbank darf keine Konten aushaendigen.
- **Meldungen verraten nie, ob es eine Nummer gibt.** „Passwort vergessen" antwortet immer gleich (Canvas 1789), sonst kann man Nummern durchprobieren.
- Kommentare auf Deutsch, sie erklaeren das WARUM. Keine typografischen Anfuehrungszeichen.
- `tests/Unit` ist **pur** (kein Framework, keine DB, keine Facades). `tests/Integration` baut Container + Capsule + SQLite von Hand, Vorbild `tests/Integration/PersonLinkerTest.php`, inklusive `Facade::clearResolvedInstances()`.
- `php -l` auf jede geaenderte PHP-Datei. Blade wird mit `php tools/blade-check.php` geprueft, **nicht** mit `php -l`.
- Gesamtlauf: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`. Ausgangsstand: **2609 Tests, 12374 Assertions, gruen.**

### Entscheidung, die der Spec fehlt (Ruling GD-1)

Das Canvas nennt keine Obergrenze fuer Code-Anforderungen. Eine Nachricht kostet rund
7 Cent, und „Passwort vergessen" ist ein Knopf, den man haemmern kann.

> **Hoechstens 3 Einmalcodes je Nummer und Stunde, hoechstens 5 je Tag.** Beim
> Ueberschreiten aendert sich die Antwort **nicht** (sonst waere sie eine Auskunft
> darueber, dass es die Nummer gibt) — es wird nur nichts verschickt und eine Zeile
> geloggt.

Kostet falsch: Wer am selben Tag fuenfmal scheitert, muss bis zum naechsten Tag warten
oder HR anrufen. Die Grenzen stehen als Konstanten an einer Stelle und sind aenderbar.

---

## Dateien

| Datei | Verantwortung |
|---|---|
| `database/migrations/2026_09_29_000001_add_konto_felder_to_rec_persons.php` | Einladung, Code, Sperre |
| `src/Support/PasswortRegeln.php` | **rein**: was ein gueltiges Passwort ist |
| `src/Support/EinladungsToken.php` | **rein**: erzeugen (der Klartext IST der lesbare Code), Gueltigkeit |
| `src/Support/Einmalcode.php` | **rein**: erzeugen, pruefen, Versuchszaehler |
| `src/Support/CodeDrossel.php` | **rein**: Obergrenze je Nummer (Ruling GD-1) |
| `src/Services/KontoWriter.php` | der **eine** Schreiber der Kontofelder |
| `src/Services/Comms/EinmalcodeSender.php` | WhatsApp-Versand des Codes |
| `src/Livewire/Public/KontoAnlegen.php` (+ View) | Registrierung |
| `src/Livewire/Public/KontoAnmelden.php` (+ View) | Anmeldung, Passwort vergessen, Nummernwechsel |
| `src/Services/PortalAuth.php` | **geaendert**: zweiter Einstieg neben dem Token |
| `src/Console/Commands/KontoEinladen.php` | HR: Einladungen erzeugen und verschicken |

---

### Task 1: Die Kontofelder an der Personen-Zeile

**Files:**
- Create: `database/migrations/2026_09_29_000001_add_konto_felder_to_rec_persons.php`
- Modify: `src/Models/RecPerson.php`
- Test: `tests/Integration/KontoFelderTest.php`

**Interfaces:**
- Produces: Spalten an `rec_persons` — `invite_token_hash` (string 64, nullable), `invite_expires_at` (timestamp, nullable), `invite_used_at` (timestamp, nullable), `code_hash` (string 64, nullable), `code_expires_at` (timestamp, nullable), `code_versuche` (unsignedTinyInteger, default 0), `code_zweck` (string 20, nullable), `code_neue_nummer` (string 32, nullable), `letzte_anmeldung_at` (timestamp, nullable). Modell `RecPerson` verbirgt alle drei Hash-Spalten.

**Warum Hashes und keine Klartexte:** Ein gestohlener Datenbankauszug darf keine Konten
aushaendigen. Der Token steht genau einmal im Klartext — in der Nachricht an den Menschen.

**Warum `code_zweck` und `code_neue_nummer`:** Ein Code fuer „Passwort zuruecksetzen" darf
keinen Nummernwechsel bestaetigen. Und beim Nummernwechsel geht der Code an die **neue**
Nummer, die erst nach Bestaetigung die echte wird — bis dahin muss sie irgendwo stehen.

- [ ] **Step 1: Migration schreiben**

Nach dem Muster von `2026_09_28_000002_add_rec_person_id_to_rec_employees.php` (Spalten
einzeln mit `hasColumn`-Wache, `down()` als echte Umkehrung). Der Kopf-Docblock nennt,
**warum** die Geheimnisse als Hash liegen und warum Zweck und neue Nummer eigene Spalten
brauchen.

- [ ] **Step 2: Test schreiben, rot sehen**

`tests/Integration/KontoFelderTest.php`, Aufbau wie `tests/Integration/PersonLinkerTest.php`:

```php
public function test_die_geheimnisse_stehen_nicht_in_der_serialisierung(): void
{
    $person = RecPerson::query()->find($this->personAnlegen([
        'password_hash'    => 'geheim-hash',
        'invite_token_hash'=> 'token-hash',
        'code_hash'        => 'code-hash',
    ]));

    $serialisiert = $person->toArray();

    foreach (['password_hash', 'invite_token_hash', 'code_hash'] as $feld) {
        $this->assertArrayNotHasKey(
            $feld,
            $serialisiert,
            "{$feld} darf nie in einer Serialisierung landen — von dort geht es in Logs, Antworten und Fehlerseiten",
        );
    }
}

public function test_die_zeitstempel_kommen_als_datum_zurueck(): void
{
    $person = RecPerson::query()->find($this->personAnlegen([
        'invite_expires_at' => '2026-10-06 12:00:00',
    ]));

    $this->assertNotNull($person->invite_expires_at);
    $this->assertSame('2026-10-06', $person->invite_expires_at->format('Y-m-d'));
}
```

- [ ] **Step 3: Modell ergaenzen**

`$hidden` um die drei Hash-Spalten erweitern (`password_hash` steht schon drin),
`$casts` um die vier Zeitstempel, `$fillable` **NICHT** erweitern — Ruling T3-D aus der
Personen-Klammer gilt weiter: die Kontofelder schreibt nur `KontoWriter`, per Query
Builder.

- [ ] **Step 4: Gesamtlauf, gruen bestaetigen**

- [ ] **Step 5: `php -l`, Commit**

```bash
git add database/migrations/2026_09_29_000001_add_konto_felder_to_rec_persons.php \
        src/Models/RecPerson.php tests/Integration/KontoFelderTest.php
git commit -m "feat(recruiting): die Personen-Zeile bekommt ihre Kontofelder — Geheimnisse nur als Hash"
```

---

### Task 2: Die reinen Regeln

**Files:**
- Create: `src/Support/PasswortRegeln.php`, `src/Support/EinladungsToken.php`, `src/Support/Einmalcode.php`
- Test: `tests/Unit/PasswortRegelnTest.php`, `tests/Unit/EinladungsTokenTest.php`, `tests/Unit/EinmalcodeTest.php`

**Interfaces:**
- Produces:
  ```php
  PasswortRegeln::pruefe(string $passwort): ?string;   // null = in Ordnung, sonst die Meldung
  PasswortRegeln::MINDESTLAENGE;                        // 10

  EinladungsToken::erzeuge(string $pepper): array{klartext: string, hash: string};
  // klartext = 8 Zeichen ohne Verwechsler. Das IST der lesbare Code (Ruling GD-4),
  // keine zweite Ableitung. Der Link traegt denselben Wert als Pfadstueck.
  EinladungsToken::istGueltig(?string $hash, ?string $ablauf, ?string $benutztAm, string $klartext, string $jetzt, string $pepper): bool;
  EinladungsToken::GUELTIG_TAGE;                        // 7

  Einmalcode::erzeuge(string $pepper): array{klartext: string, hash: string};   // sechs Ziffern
  Einmalcode::istGueltig(?string $hash, ?string $ablauf, int $versuche, string $klartext, string $jetzt, string $pepper): bool;
  Einmalcode::GUELTIG_MINUTEN;                          // 10
  Einmalcode::MAX_VERSUCHE;                             // 5
  ```

**Bindende Vorgaben:**
- Passwort: **Mindestlaenge, kein Zwang zu Sonderzeichen** (Spec §2.3). Laenge 10, weil
  die Nummer als Benutzername oeffentlich ist und allein das Passwort traegt.
- **Ruling GD-4: ein Geheimnis, zwei Darreichungsformen.** Canvas 68 Eintrag 1740 sagt
  woertlich „als Link **und** als kurzen lesbaren Code ... am Rechner kann man den Code
  auch eintippen". Der Klartext ist deshalb selbst der abtippbare Acht-Zeichen-Code;
  eine Anzeige-Ableitung, die `istGueltig()` nicht kennt, koennte man nicht eintippen.
  Das Alphabet laesst `0/O` und `1/I/L` weg — der Code wird am Telefon vorgelesen.
- **Folgeauflage aus GD-4 fuer Aufgabe 6:** acht Zeichen aus 31 sind rund 8,5e11
  Moeglichkeiten bei sieben Tagen Gueltigkeit. Das traegt nur wegen der
  Zwei-Nachweis-Regel — die Registrierungsseite **muss** Fehlversuche drosseln.
- `istGueltig()` vergleicht **in konstanter Zeit** (`hash_equals`), nie mit `===`.
- **Ruling GD-5: die beiden kurzlebigen Geheimnisse werden gepfeffert.** `hash_hmac(
  'sha256', $klartext, $pepper)` statt `hash('sha256', ...)`. Ein ungesalzenes SHA-256
  ueber sechs Ziffern sind eine Million Moeglichkeiten — aus einem Datenbank-Abzug in
  Sekunden zurueckgerechnet, fuer alle Zeilen in einem Durchlauf. Die Zwei-Nachweis-Regel
  traegt hier NICHT: der zweite Nachweis ist das Geburtsdatum, und das steht in derselben
  Datenbank. Der Pfeffer wird als Parameter hereingereicht (die Klassen bleiben rein) und
  lebt spaeter in der `.env`, also gerade nicht im Abzug. Ausgabe bleibt 64 Zeichen Hex,
  **keine Migration**. Leerer Pfeffer -> `InvalidArgumentException`; still ungepfeffert
  weiterzurechnen saehe sicher aus und waere es nicht.
- **Das Passwort wird NICHT gepfeffert** — es bekommt `Hash::make()`. Geht der Pfeffer
  verloren, sterben offene Einladungen (sieben Tage) und laufende Codes (zehn Minuten);
  das ist verschmerzbar. Mitgepfeffert wuerde derselbe Verlust jeden Mitarbeiter
  dauerhaft aussperren. Die Asymmetrie ist Absicht.
- **Passwort: Steuerzeichen abweisen** (`\0` bis `\x1F`, `\x7F`) — sonst wirft
  `password_hash` spaeter `ValueError` und der Mitarbeiter sieht eine 500 statt einer
  Formularmeldung. Hoechstlaenge 200. **Nicht trimmen:** truege nur, wenn jeder Aufrufer
  beim Setzen UND beim Anmelden gleich trimmt — vergisst es einer, sperrt es alle
  lautlos aus. Der rohe String braucht keine Absprache zwischen zwei Stellen.
- Abgelaufen, schon benutzt oder zu viele Versuche → `false`, ohne zu verraten, welches
  davon zutraf.

- [ ] **Step 1: Die drei Tests schreiben, rot sehen**

`tests/Unit/EinmalcodeTest.php` (die anderen beiden analog):

```php
public function test_ein_code_hat_sechs_ziffern(): void
{
    ['klartext' => $k] = Einmalcode::erzeuge();
    $this->assertMatchesRegularExpression('/^\d{6}$/', $k);
}

public function test_zwei_codes_sind_verschieden(): void
{
    $a = Einmalcode::erzeuge()['klartext'];
    $b = Einmalcode::erzeuge()['klartext'];
    $this->assertNotSame($a, $b, 'ein vorhersagbarer Code ist kein Nachweis');
}

public function test_der_richtige_code_gilt(): void
{
    ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
    $this->assertTrue(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:05:00'));
}

public function test_ein_falscher_code_gilt_nicht(): void
{
    ['hash' => $h] = Einmalcode::erzeuge();
    $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, '000000', '2026-09-29 12:05:00'));
}

public function test_ein_abgelaufener_code_gilt_nicht(): void
{
    ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
    $this->assertFalse(Einmalcode::istGueltig($h, '2026-09-29 12:10:00', 0, $k, '2026-09-29 12:11:00'));
}

public function test_nach_zu_vielen_versuchen_gilt_auch_der_richtige_nicht(): void
{
    ['klartext' => $k, 'hash' => $h] = Einmalcode::erzeuge();
    $this->assertFalse(
        Einmalcode::istGueltig($h, '2026-09-29 12:10:00', Einmalcode::MAX_VERSUCHE, $k, '2026-09-29 12:05:00'),
        'sonst kann man sechs Ziffern in Ruhe durchprobieren',
    );
}

public function test_ohne_hash_gilt_nichts(): void
{
    $this->assertFalse(Einmalcode::istGueltig(null, null, 0, '123456', '2026-09-29 12:00:00'));
}
```

- [ ] **Step 2: Die drei Klassen schreiben**

Rein, kein Framework. Die Kopf-Docblocks nennen das WARUM: warum zehn Zeichen, warum
`hash_equals`, warum die Verwechsler fehlen, warum ein Versuchszaehler noetig ist
(sechs Ziffern sind in Minuten durchprobiert).

- [ ] **Step 3: Gesamtlauf gruen, `php -l`, Commit**

---

### Task 3: `CodeDrossel` — die Obergrenze (Ruling GD-1)

**Files:**
- Create: `src/Support/CodeDrossel.php`
- Test: `tests/Unit/CodeDrosselTest.php`

**Interfaces:**
- Produces:
  ```php
  /** @param list<string> $bisherigeAnforderungen  Zeitstempel Y-m-d H:i:s, neueste zuerst */
  CodeDrossel::darfSenden(array $bisherigeAnforderungen, string $jetzt): bool;
  CodeDrossel::MAX_JE_STUNDE;   // 3
  CodeDrossel::MAX_JE_TAG;      // 5
  ```

Rein, damit die Regel ohne Datenbank pruefbar ist.

- [ ] **Step 1: Test schreiben, rot sehen**

```php
public function test_ohne_vorgeschichte_darf_gesendet_werden(): void
{
    $this->assertTrue(CodeDrossel::darfSenden([], '2026-09-29 12:00:00'));
}

public function test_die_vierte_anforderung_in_einer_stunde_wird_gebremst(): void
{
    $this->assertFalse(CodeDrossel::darfSenden(
        ['2026-09-29 11:59:00', '2026-09-29 11:40:00', '2026-09-29 11:10:00'],
        '2026-09-29 12:00:00',
    ));
}

public function test_was_laenger_als_eine_stunde_her_ist_zaehlt_nicht_mehr_fuer_die_stunde(): void
{
    $this->assertTrue(CodeDrossel::darfSenden(
        ['2026-09-29 10:59:00', '2026-09-29 10:40:00', '2026-09-29 10:10:00'],
        '2026-09-29 12:00:00',
    ));
}

public function test_die_sechste_am_tag_wird_gebremst(): void
{
    $this->assertFalse(CodeDrossel::darfSenden([
        '2026-09-29 11:00:00', '2026-09-29 09:00:00', '2026-09-29 07:00:00',
        '2026-09-29 05:00:00', '2026-09-29 03:00:00',
    ], '2026-09-29 12:00:00'));
}

public function test_unlesbare_zeitstempel_werden_uebersprungen_und_bremsen_nicht(): void
{
    $this->assertTrue(CodeDrossel::darfSenden(['nicht-lesbar'], '2026-09-29 12:00:00'));
}
```

- [ ] **Step 2: Klasse schreiben, gruen, Commit**

---

### Task 4: `KontoWriter` — der eine Schreiber

**Files:**
- Create: `src/Services/KontoWriter.php`
- Modify: `config/recruiting.php` (Block `konto.pepper`)
- Test: `tests/Integration/KontoWriterTest.php`

**Interfaces:**
- Consumes: `PasswortRegeln`, `EinladungsToken`, `Einmalcode` (Task 2), Spalten aus Task 1.
- Produces:
  ```php
  /** Erzeugt einen Einladungs-Token, speichert nur den Hash, gibt den Klartext zurueck. */
  KontoWriter::ladeEin(int $personId): string;

  /** Token + Geburtsdatum + Passwort -> Konto steht. Wirft bei ungueltigem Token. */
  KontoWriter::registriere(int $personId, string $tokenKlartext, string $geburtsdatum, string $passwort): void;

  /** Prueft Nummer + Passwort. Gibt die Personen-Kennung zurueck oder null. */
  KontoWriter::pruefeAnmeldung(?int $teamId, string $nummer, string $passwort): ?int;

  /** Legt einen Einmalcode ab und gibt den Klartext zurueck (Versand macht der Sender). */
  KontoWriter::erzeugeCode(int $personId, string $zweck, ?string $neueNummer = null): string;

  /** Code einloesen. Wirft bei ungueltigem Code. */
  KontoWriter::loeseCodeEin(int $personId, string $zweck, string $codeKlartext): void;

  /** Neues Passwort setzen. Wirft, wenn das Passwort die Regeln verletzt. */
  KontoWriter::setzePasswort(int $personId, string $passwort): void;
  ```

**Bindende Vorgaben:**
- **Woher der Pfeffer kommt (Ruling GD-5).** `KontoWriter` ist die Stelle, die ihn
  liest und an `EinladungsToken`/`Einmalcode` weiterreicht. Neuer Eintrag in
  `config/recruiting.php` nach dem Muster der ZAS-Schluessel:
  ```php
  'konto' => [
      // Pfeffer fuer Einladungs-Token und Einmalcode. NICHT fuer das Passwort.
      // Faellt bewusst auf app.key zurueck: der steht auch nicht in der Datenbank,
      // ist auf jedem Host gesetzt, und so kann kein vergessener .env-Eintrag die
      // Kontoanlage auf prod stillegen. Ein eigener Wert geht vor, wenn gesetzt.
      'pepper' => env('RECRUITING_KONTO_PEPPER') ?: config('app.key'),
  ],
  ```
  Ein Wechsel des Pfeffers (oder des `APP_KEY`) macht offene Einladungen und laufende
  Codes ungueltig — sieben Tage beziehungsweise zehn Minuten. Passwoerter beruehrt er
  nicht, die haengen an `Hash::make()`.
- **`Einmalcode` kennt kein „benutzt" (Fund F13 der Pruefung).** Anders als
  `EinladungsToken` hat er kein `benutzt_at`. `loeseCodeEin()` muss den Code deshalb
  **selbst entwerten**: `code_hash`, `code_expires_at`, `code_zweck`, `code_neue_nummer`
  auf `null` und `code_versuche` auf `0`. Wer das vergisst, baut einen Code, der zehn Minuten
  lang beliebig oft gilt. Eigener Test dafuer:
  `test_ein_eingeloester_code_gilt_kein_zweites_mal`.
- Jeder Schreibzugriff auf `rec_persons` **und** `rec_employees` laeuft ueber den Query
  Builder. Beim Nummernwechsel wandert die Nummer ueber
  `PersonLinker::setzeNummer()` — **nicht** selbst geschrieben, die Regel „die Nummer
  wandert auf alle Anstellungen" lebt dort und nur dort.
- `pruefeAnmeldung()` prueft **immer** das Passwort, auch wenn es die Nummer nicht gibt
  (gegen einen Dummy-Hash) — sonst verraet die Antwortzeit, ob ein Konto existiert.
- Eine Person mit `locked_at` oder mit `merged_into_person_id` meldet sich **nie** an.
- Eine Person, deren Anstellungen alle inaktiv sind, meldet sich **nie** an (Canvas 1793:
  Konten Ausgeschiedener werden gesperrt, weil Nummern neu vergeben werden).

- [ ] **Step 1: Die Tests schreiben, rot sehen**

Pflichtfaelle in `tests/Integration/KontoWriterTest.php`:

```php
public function test_registrieren_setzt_passwort_und_verbraucht_den_token(): void
public function test_ein_zweites_mal_mit_demselben_token_geht_nicht(): void
public function test_ein_abgelaufener_token_geht_nicht(): void
public function test_falsches_geburtsdatum_geht_nicht(): void
public function test_anmelden_mit_richtigem_passwort(): void
public function test_anmelden_mit_falschem_passwort_scheitert(): void
public function test_eine_gesperrte_person_meldet_sich_nicht_an(): void
public function test_eine_stillgelegte_person_meldet_sich_nicht_an(): void
public function test_wer_nur_inaktive_anstellungen_hat_meldet_sich_nicht_an(): void
public function test_ein_code_fuer_passwort_gilt_nicht_fuer_den_nummernwechsel(): void
public function test_ein_eingeloester_code_gilt_kein_zweites_mal(): void
public function test_die_nummer_wandert_beim_wechsel_auf_alle_anstellungen(): void
public function test_kontoaenderungen_setzen_keinen_zas_marker(): void
```

**Der letzte ist der wichtigste und in diesem Zweig schon dreimal stumm gruen geblieben.**
`zas_changed_at` allein beweist nichts, weil die Kontofelder gar nicht in
`RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS` stehen. Der tragende Beleg ist,
dass sich `rec_employees.updated_at` **nicht** aendert — Eloquent fasst die Spalte bei
jedem Speichern an, der Query Builder nur auf ausdrueckliche Anweisung. Registrier dafuer
den echten Beobachter im Test, wie `tests/Integration/PersonLinkerTest.php` es vormacht
(Log-Attrappe binden, dann `Facade::clearResolvedInstances()` — sonst ReflectionException).

- [ ] **Step 2: Den Dienst schreiben**

- [ ] **Step 3: Mutationsprobe selbst fahren**

Stell einen Schreibweg testweise auf Eloquent um und belege, dass
`test_kontoaenderungen_setzen_keinen_zas_marker` rot wird. Zuruecknehmen,
`git status --short` zeigen.

- [ ] **Step 4: Gesamtlauf gruen, `php -l`, Commit**

---

### Task 5: Der zweite Einstieg in `PortalAuth`

**Files:**
- Modify: `src/Services/PortalAuth.php`
- Create: `database/migrations/2026_09_29_000002_add_phone_index_to_rec_persons.php`
- Test: `tests/Integration/PortalAuthKontoTest.php`

**Interfaces:**
- Consumes: `KontoWriter::pruefeAnmeldung()` (Task 4).
- Produces: `PortalAuth::anmeldenMitNummer(?int $teamId, string $nummer, string $passwort): array{status: string, personId: ?int}` — Status wie bisher `ok` / `falsch` / `gesperrt`.

**Warum hier und nicht neu:** Der Kopf von `PortalAuth` sagt seit dem Portal-Umbau:

> *„Heute beantwortet sie die Frage ‚wer ist das?' mit Token plus Geburtsdatum und
> Ausweis-Endziffern. Spaeter mit Handynummer und Passwort. Die vier Portal-Bereiche
> wissen nichts davon, wie die Antwort zustande kam."*

Das Konto wird **eingehaengt**, nicht eingebaut.

**Bindende Vorgaben:**
- Der bestehende Token-Weg bleibt **unveraendert** und muss weiter gruen sein. Beide laufen
  waehrend der Umstellung nebeneinander.
- Versuchszaehler und Sperre: **dieselbe Mechanik** wie heute (5 Versuche, 15 Minuten),
  aber der Schluessel haengt an der **Nummer**, nicht am Token — wer Nummern
  durchprobiert, soll sich nicht durch Wechseln freischalten.
- `portal_locked_at` sperrt auch hier.
- **Ein Index auf `phone` (Befund der Aufgabe-4-Pruefung).** `rec_persons` hat heute nur
  `unique(team_id, phone)`. Die Anmeldung nach Ruling GD-2 fragt bei `teamId = null`
  allein nach `phone` — dafuer ist ein zusammengesetzter Index mit `team_id` an erster
  Stelle unbrauchbar, die Tabelle wird voll gelesen. Vorher schraenkte `team_id` ein.
  Bei heutiger Groesse belanglos (ein paar tausend Zeilen gegen 170 ms bcrypt im selben
  Aufruf), aber es ist ein **oeffentlicher, durchprobierbarer Einstieg** — und die
  Anmeldeseite entsteht in Aufgabe 7. Eigene Migration, eine Zeile:
  ```php
  $table->index('phone', 'rec_persons_phone_index');
  ```
  Bewusst eine **zweite** Migration statt einer Ergaenzung der ersten: die Migration aus
  Aufgabe 1 koennte auf der Demo schon gelaufen sein, und eine nachtraeglich geaenderte
  Migration laeuft dort nie wieder an. `down()` nimmt den Index zurueck, mit
  `hasIndex`-Wache nach dem Muster der ersten Migration.

- [ ] **Step 1: Test schreiben, rot sehen**

```php
public function test_der_alte_token_weg_funktioniert_unveraendert(): void
public function test_anmelden_mit_nummer_und_passwort(): void
public function test_fuenf_fehlversuche_sperren_die_nummer(): void
public function test_die_sperre_haengt_an_der_nummer_nicht_am_token(): void
public function test_eine_dispo_gesperrte_person_kommt_nicht_rein(): void
```

- [ ] **Step 2: `anmeldenMitNummer()` ergaenzen, gruen, Commit**

---

### Task 6: Registrierung — die Seite

**Files:**
- Create: `src/Livewire/Public/KontoAnlegen.php` + `resources/views/livewire/public/konto-anlegen.blade.php`
- Modify: `routes/public.php`
- Test: `tests/Integration/KontoAnlegenTest.php`

**Route:** `Route::get('/konto/anlegen/{token}', KontoAnlegen::class)->name('recruiting.public.konto-anlegen');`
**Token am URL-Ende** — Meta-URL-Knoepfe erlauben die Variable nur als Suffix (dieselbe
Falle wie beim Portal, siehe `routes/public.php`).

**Drei Felder, mehr nicht** (Spec §3): Geburtsdatum, Passwort, Passwort wiederholen.
Die Nummer wird **nicht** abgefragt — sie steht durch den Token fest.

**Bindende Vorgaben:**
- Alles, was ueber Identitaet oder Zustand entscheidet, ist `#[Locked]`. Das ist der
  Auth-Bypass vom 19.08. (`$wire.set state=verified` umging die Pruefung) — er war
  verifiziert ausnutzbar.
- Kein Zahlentastatur-Zwang am Geburtsdatum (Login-Blocker vom 06.08.).
- Ungueltiger, abgelaufener oder verbrauchter Token → **404**, nicht „Token ungueltig".
  Letzteres waere eine Auskunft darueber, dass es den Token gibt.
- **Zwei Drosseln, aus Ruling GD-4 (tragend).** Der Token ist acht Zeichen aus 31 und
  sieben Tage gueltig; ohne Bremse ist er erratbar.
  1. **Die Route selbst** bekommt `->middleware('throttle:20,1')` — hoechstens 20
     Aufrufe je Minute und IP. Das trifft das Durchprobieren von Token. Zwanzig statt
     zehn, weil mehrere Mitarbeiter hinter derselben Firmen-IP sitzen koennen und ein
     Fehlalarm hier die Kontoanlage blockiert.
  2. **Das falsche Geburtsdatum** wird je Token gezaehlt:
     `RateLimiter::tooManyAttempts('konto-anlegen:' . sha1($token), 5)` vor der
     Pruefung, `RateLimiter::hit(..., 3600)` nach jedem Fehlschlag, `clear()` nach
     Erfolg. Das trifft das Durchprobieren des zweiten Nachweises bei bekanntem Token —
     ein plausibler Geburtsjahrgang-Bereich sind nur rund 25.000 Moeglichkeiten.
     Beim Ueberschreiten antwortet die Seite **wie bei einem ungueltigen Token: 404**.
     Eine eigene Meldung waere die Auskunft, dass es diesen Token gibt.
  `RateLimiter` ist hier erlaubt — die Drossel sitzt in der Livewire-Komponente, nicht
  in `src/Support`; die Reinheitsregel bindet die Regel-Klassen, nicht die Seite. Kein
  neuer Spaltenname, keine zweite Migration: der Zaehler lebt im Cache.

- [ ] **Step 1: Test schreiben, rot sehen** — gerenderte Durchlaeufe nach dem Muster von `tests/Integration/PortalShellProfilBladeTest.php`, plus ein Waechter, dass alle identitaetsentscheidenden Eigenschaften `#[Locked]` tragen (Muster: der E6-Test im Abnahmetest der Portal-Huelle).
- [ ] **Step 2: Komponente und Blade schreiben**
- [ ] **Step 3: `php tools/blade-check.php`, Gesamtlauf, Commit**

---

### Task 7: Anmelden — die Seite

**Files:**
- Create: `src/Livewire/Public/KontoAnmelden.php` + View
- Modify: `routes/public.php`
- Test: `tests/Integration/KontoAnmeldenTest.php`

**Route:** `Route::get('/konto', KontoAnmelden::class)->name('recruiting.public.konto');`

**Bindende Vorgaben:**
- **Ein Weiterleitungsziel wird mitgefuehrt** und nach erfolgreicher Anmeldung
  angesprungen (Spec §2.2: „WhatsApp-Knoepfe fuehren zur Login-Seite und nach dem Passwort
  direkt zum Ziel. Ein Link meldet nie von selbst an."). Das Ziel wird gegen eine
  **Positivliste eigener Routen** geprueft — ein offener Weiterleiter waere eine Einladung.
- „Angemeldet bleiben" verlaengert die Sitzung, setzt **kein** dauerhaftes Geheimnis in
  einen Cookie.
- `#[Locked]` wie in Task 6.
- **ACHTUNG, Verwechslungsgefahr mit Folgen (Befund der Aufgabe-5-Pruefung).**
  `PortalAuth::anmeldenMitNummer()` liefert eine **Personen**-Kennung (`rec_persons.id`);
  `PortalAuth::sessionKey(int $employeeId)` erwartet eine **Anstellungs**-Kennung
  (`rec_employees.id`). Beide sind `int`, nichts im Typ trennt sie. Wer die eine in die
  andere steckt, baut genau die **Verschraenkung**, die die Global Constraints
  verbieten: ein Konto-Nachweis oeffnete eine fremde Portal-Sitzung. Die Seite muss die
  Personen-Kennung ausdruecklich in die Anstellungen aufloesen, bevor sie eine Sitzung
  eroeffnet — und ein Test muss belegen, dass die Sitzung auf einer Anstellung DIESER
  Person steht.

- [ ] **Step 1: Test schreiben, rot sehen** — darunter: ein fremdes Weiterleitungsziel wird **verworfen**.
- [ ] **Step 2: Komponente und Blade, Commit**

---

### Task 8: Der Code-Versand

**Files:**
- Create: `src/Services/Comms/EinmalcodeSender.php`
- Test: `tests/Integration/EinmalcodeSenderTest.php`

**Interfaces:**
- Consumes: `KontoWriter::erzeugeCode()` (Task 4), `CodeDrossel` (Task 3).
- Produces: `EinmalcodeSender::sende(int $personId, string $zweck, ?string $anNummer = null): string` — Status `sent` / `failed` / `gedrosselt`.

**Vorbild ist `src/Services/ProofReminderSender.php`, und zwar wegen der drei Fehler, die
sein Docblock ausdruecklich benennt.** Zwei davon treffen hier genauso:

1. `RecEmployee::sendPortalNotification()` meldet `ok: true` direkt nach `sendTemplate()`,
   **ohne `$message->status` zu pruefen** — ein von Meta ABGELEHNTER Versand gilt dort als
   Erfolg. Dieser Sender prueft den Status und meldet `failed`. Sonst wartet jemand auf
   einen Code, der nie ankam, und das Protokoll sagt „verschickt".
2. `HoldingTemplateComponents::build()` setzt bei einem **unbekannten Platzhalter** still
   den Vornamen ein. Heisst der Platzhalter in der Meta-Vorlage anders als gedacht,
   bekaeme jemand seinen Vornamen statt des Codes zugeschickt — und Meta naehme es an.
   Dieser Sender prueft die Platzhalter selbst und **lehnt den Versand ab**, statt Muell
   zu verschicken.

**Bindende Vorgaben:**
- Die Drossel wird **vor** dem Erzeugen gefragt. Ein gedrosselter Versuch darf keinen
  neuen Code ablegen — sonst entwertet er den, der unterwegs ist.
- Die Antwort an den Menschen ist bei `gedrosselt` **dieselbe** wie bei `sent`.

- [ ] **Step 1: Test schreiben, rot sehen** — darunter: ein von Meta abgelehnter Versand ergibt `failed` (nicht `sent`), und ein unbekannter Platzhalter fuehrt zu **keinem** Versand.
- [ ] **Step 2: Sender schreiben, Commit**

---

### Task 9: Die fuenf Zuruecksetzen-Wege

**Files:**
- Modify: `src/Livewire/Public/KontoAnmelden.php` (+ View)
- Create: `src/Console/Commands/KontoZuruecksetzen.php` (Weg 5, HR)
- Test: `tests/Integration/KontoZuruecksetzenTest.php`

Die fuenf Wege stehen woertlich in **Spec §5**. Jeder braucht **zwei** Nachweise:

| # | Lage | Nachweise | HR |
|---|---|---|---|
| 1 | Neue Nummer, alte aktiv | angemeldet + Passwort, dann Code an die neue | nein |
| 2 | Neue Nummer, alte weg, Passwort bekannt | alte Nummer + Passwort, dann Code an die neue | nein |
| 3 | Passwort vergessen, Nummer aktiv | Code an die Nummer + Geburtsdatum | nein |
| 4 | Nummer weg **und** Passwort vergessen | Geburtsdatum + Ausweisziffern, Code an die neue | Meldung, 24h Stopp |
| 5 | Gar nichts geht | HR traegt die neue Nummer ein | ja |

**Begleitregeln (alle nicht verhandelbar, Canvas 1789):**
- Die **alte Nummer** bekommt einmalig einen Hinweis, dass die Nummer geaendert wurde,
  sofern noch zustellbar.
- „Passwort vergessen" **antwortet immer gleich**, egal ob die Nummer im System ist.
- **Jeder Wechsel wird protokolliert.**
- Weg 4 setzt den Wechsel erst nach **24 Stunden** wirksam; HR kann in dieser Zeit stoppen.

- [ ] **Step 1: Test je Weg schreiben, rot sehen** — dazu die drei Begleitregeln als eigene Zusicherungen, insbesondere: **die Antwort auf „Passwort vergessen" ist fuer eine unbekannte Nummer zeichengleich mit der fuer eine bekannte.**
- [ ] **Step 2: Wege 1 bis 3 bauen, Commit**
- [ ] **Step 3: Weg 4 mit 24-Stunden-Fenster bauen, Commit**
- [ ] **Step 4: Weg 5 als Kommando bauen, Commit**

---

### Task 10: HR sieht den Stand

**Files:**
- Create: `src/Console/Commands/KontoEinladen.php`
- Test: `tests/Integration/KontoEinladenTest.php`

```
recruiting:konto-einladen
    {--team= : Nur dieses Team}
    {--ids= : Bestimmte Personen, komma-getrennt}
    {--welle= : Hoechstens so viele auf einmal}
    {--dry-run : Nur zeigen, was passieren wuerde}
    {--bericht : Nur den Stand ausgeben, nichts verschicken}
```

`--bericht` liefert, was Spec §3 verlangt: **wer eingeladen ist, wer registriert ist, wer
nicht erreichbar ist** (keine Nummer, kein WhatsApp).

**Bindende Vorgaben:**
- Wiederholbar: wer schon ein Konto hat, wird uebersprungen.
- **Wer kein hinterlegtes Geburtsdatum hat, wird NICHT eingeladen** (Befund F6 der
  Aufgabe-6-Pruefung). Sonst bekommt der Mensch fuenfmal „pruef dein Geburtsdatum" und
  danach eine Stunde lang 404 — ohne dass irgendetwas an ihm falsch waere, und ohne dass
  er es je richtig machen koennte. Der Riegel gehoert an diesen Knopf, nicht an die
  Seite: die Registrierung kann den Fall nicht von einem Tippfehler unterscheiden.
  Diese Faelle gehoeren in die `--bericht`-Ausgabe unter „nicht erreichbar", mit eigenem
  Grund neben „keine Nummer".
- Ohne `--welle` **kein** Versand an alle — eine Welle ist eine bewusste Handlung.
- Kennungen in der Ausgabe, **nie Namen** (Muster: `recruiting:mitarbeiter-grenzfaelle`).
- **`letzte_anmeldung_at` heisst NICHT „war zuletzt im Portal" (Ruling GD-8).** Der
  Stempel sitzt in `KontoWriter::pruefeAnmeldung()` und wird gesetzt, sobald das
  Passwort stimmt — auch dann, wenn die Dispo-Sperre (`portal_locked_at`) den Menschen
  unmittelbar danach abweist. Er bedeutet also **„letzter erfolgreicher
  Passwortnachweis"**. Beschrifte die Spalte im Bericht entsprechend; „zuletzt
  angemeldet" waere bei einem gesperrten Konto eine Falschaussage, und HR entscheidet
  danach, ob ein Konto funktioniert.

- [ ] **Step 1: Test schreiben, rot sehen**
- [ ] **Step 2: Kommando bauen, im ServiceProvider registrieren, Commit**

---

## Abnahme

1. Gesamtlauf gruen, Zahl deutlich ueber 2609.
2. `php artisan migrate` legt die Kontofelder an.
3. Auf der Demo: eine Person per `recruiting:konto-einladen --ids=<id> --dry-run` ansehen, dann einladen, den Token aus der Ausgabe nehmen, `/recruiting/konto/anlegen/<token>` aufrufen, Konto anlegen, abmelden, unter `/recruiting/konto` mit Nummer und Passwort wieder anmelden.
4. „Passwort vergessen" fuer eine **unbekannte** Nummer liefert dieselbe Antwort wie fuer eine bekannte.
5. Der alte Token-Weg ins Portal funktioniert unveraendert.

## Was dieser Plan NICHT tut

- **Keine Einladungswellen an den Bestand** — das ist Gate E und braucht die genehmigte Meta-Vorlage.
- **Kein Umstecken der Portal-Anmeldung** — das ist Gate F. Bis dahin laufen beide Wege nebeneinander.
- **Keine Passkeys** — ausdrueckliche Ausbaustufe (Canvas 1744).
- **Kein Konto fuer Bewerber** — die Grenze ist die Vertragsunterschrift.
