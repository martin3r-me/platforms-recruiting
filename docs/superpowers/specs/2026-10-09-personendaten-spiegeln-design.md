# Design: Personendaten auf alle Anstellungen spiegeln

**Datum:** 2026-10-09
**Modul:** platforms-recruiting (kein Edit an Core/CRM/HCM)
**Branch:** feat/ma-konto (setzt die Personen-Klammer `rec_persons` / `PersonScopeResolver` voraus)
**Status:** Design zur Freigabe

---

## 1. Ziel

Max Mustermann hat zwei Akten (RG und MA), aber eine Adresse, eine Bank, eine
Steuer-ID. Heute landet eine Änderung nur in der Akte, in der sie gemacht wurde —
im Portal, in der HR-Akte oder über den ZAS-Eingang. Die andere Akte behält den
alten Wert, und ZAS bekommt für diese Gesellschaft weiter den alten Stand.

Nach diesem Paket gilt: **Wer ein Personenfeld in einer Akte ändert, ändert es in
allen Akten dieser Person.** Beide Gesellschaften bekommen den ZAS-Marker, das
Lohnbüro bekommt die Änderung je Akte gemeldet. Für den Bestand, der heute schon
auseinanderläuft, gibt es ein Prüfkommando, das Abweichungen auflistet und nur auf
ausdrücklichen Befehl angleicht.

Markus' Frage vom 09.10.2026 („müssen wir neue Daten in beiden Akten eintragen?")
wird damit mit **Nein** beantwortet.

### Was bewusst NICHT drin ist

- Keine neue Tabelle, keine Migration. `rec_persons` bleibt Konto-Tabelle, die
  Stammdaten bleiben auf `rec_employees` (eine Spalte je Akte). Ein Umzug der
  Stammdaten an die Person wäre der saubere Endzustand, zieht aber ZAS-Export,
  Akte, Portal, Verträge und Importer auf einmal um — nicht vor dem Pilot.
- Kein Spiegeln gesellschaftsbezogener Felder (§2.2).
- Kein Spiegeln des Telefons und der Nachweise — die haben ihren eigenen,
  bereits gebauten Weg (`PersonLinker::setzeNummer`, `ProofWriter`).
- Keine Änderung am ZAS-Export-Format. Es kommen nur mehr Zeilen in die
  Aktualisierungsdatei (beide Gesellschaften statt einer). **Michel bekommt
  dazu eine Zeile Ankündigung** (Memory-Regel: Export-Änderungen vorher nennen).

---

## 2. Felder

### 2.1 Personenfelder (werden gespiegelt)

Festgelegt in einer Konstante `PersonenFelder::SPIEGELN` (neue Klasse
`src/Support/PersonenFelder.php`), eine Liste, keine zweite irgendwo:

| Gruppe | Spalten auf `rec_employees` |
|---|---|
| Name, Geburt | `first_name`, `last_name`, `birth_name`, `birth_date`, `birth_place`, `gender` |
| Kontakt | `email` |
| Adresse, Herkunft | `street`, `house_number`, `zip`, `city`, `country_code`, `birth_country`, `nationality`, `is_eu_citizen` |
| Ausweis | `identity_card_number`, `identity_card_valid_until`, `drivers_license_class` |
| Persönliches | `marital_status`, `number_of_children`, `religion`, `employment_type` |
| Bank | `iban`, `bic`, `bank_institute`, `account_holder` |
| Steuer, Versicherung | `tax_class`, `steuer_id`, `sozialversicherungsnummer`, `health_insurance` |
| Arbeitgeber | `is_main_employer`, `other_employer` |

**Steuerklasse — offene Frage ans Lohnbüro.** Markus sagt, RG und MA können
zeitweise gleichzeitig aktiv sein. Dann wäre der zweite Arbeitgeber üblicherweise
Steuerklasse 6 und die Steuerklasse gehörte je Gesellschaft. Entscheidung
Sebastian 09.10.: vorerst spiegeln. Damit das ohne Deploy umkehrbar ist, gibt es
die Team-Einstellung `tax_class_per_company` (Schalter im Einstellungs-Fenster →
Mitarbeiter, Standard **aus**). Steht er an, werden `tax_class`,
`is_main_employer` und `other_employer` nicht gespiegelt und das Prüfkommando
meldet Abweichungen in diesen Feldern nicht (Nachtrag 09.10., Review I1: die
Haupt-/Nebenarbeitgeber-Angabe stammt aus der §15/16-Erklärung EINES Vertrags
und ist die Grundlage für Steuerklasse 6 — sie gehört zur selben Frage). Ohne
Schalter bleiben beide gespiegelt (Entscheidung Sebastian). Text des Schalters:
„Steuerklasse und Haupt-/Nebenarbeitgeber je Gesellschaft führen". Der Punkt
steht auch im Info-Dokument für Markus.

### 2.2 Gesellschaftsfelder (bleiben je Akte)

`personnel_number`, `company`, `cost_center`, `rec_position_id`, `rec_applicant_id`,
`rec_zas_inbound_file_id`, `team_id`, `beschaftigungsort`, `art_der_tatigkeit`,
`umfang_der_tatigkeit`, `recruited_by_personnel_number`, `is_active`,
`employed_since`, `employment_ended_at`, alle `portal_*`-, `zas_*`- und
`payroll_*`-Spalten, `phone` (eigener Weg), alle Datei- und Ablaufspalten der
Nachweise (eigener Weg). Alles in `rec_employee_hr_data` (Verträge, Status,
Bewertungen, Tätigkeiten, Wäsche) bleibt je Akte.

### 2.3 Von ZAS geführte Werte (bleiben je Akte, kommen weiter von ZAS)

Die Regel „unsere Daten gewinnen" (§5.2, Paarung) gilt nur für die Personenfelder
aus §2.1. Diese Werte führt ZAS je Gesellschaft, der Import übernimmt sie
unverändert wie heute, der Spiegel fasst sie nie an:

| Wert | Spalte | Weg |
|---|---|---|
| Status (GO/MA …) | `rec_employee_hr_data.export_status` | `STATUS_SYNC_FIELDS` |
| MA seit | `rec_employee_hr_data.status_ma_since` | `STATUS_SYNC_FIELDS` |
| Tagekonto (Tage erlaubt / gearbeitet / Rest) | `rec_employee_hr_data.short_term_days_*` | Row-Mapper |
| Dispo-Tätigkeiten | `rec_employee_hr_data.dispo_taetigkeiten` | `ZasDispoTaetigkeitSync` |
| Personalnummer, Firma | `rec_employees.personnel_number`, `company` | nur in leere Felder |

**Offene Frage (Markus/ZAS), nicht Teil dieses Pakets:** Die 70-Tage-Grenze der
kurzfristigen Beschäftigung zählt je Mensch und Kalenderjahr über alle Arbeitgeber.
Liefert ZAS das Tagekonto je Gesellschaft, sieht unsere Akte bei RG und MA je
nur die Hälfte. Ob ZAS summiert oder getrennt liefert, ist zu klären; bis dahin
zeigt die Akte die Werte je Gesellschaft wie geliefert.

---

## 3. Wer gehört zu wem

Der Personen-Umfang kommt aus **`PersonScopeResolver::forEmployee()`** — derselbe
Weg, mit dem Portal, Akte und `ProofWriter` entscheiden, wessen Daten sie zeigen
und schreiben. Zweig 1 (`rec_person_id` gesetzt) liefert alle Akten der Person;
Zweig 2 (nur `person_key` + gleiche Nummer) ist der Übergang für Zeilen ohne
Personen-Zeile. Zeilen in `abweichend` (gleicher Marker, andere Nummer) werden
**nicht** beschrieben.

Gespiegelt wird auf **alle** Akten der Person, aktive wie ruhende wie
ausgeschiedene (Entscheidung Sebastian 09.10.: „wenn sich was ändert, dann auch für
beide"). Eine ruhende Akte hat beim Wiederaufleben so die richtigen Daten.

---

## 4. Der Spiegel (`PersonenSpiegel`)

Neue Klasse `src/Services/PersonenSpiegel.php`, der **einzige** Schreiber von
Personenfeldern auf Geschwister-Akten.

```php
final class PersonenSpiegel
{
    /**
     * @param  array<string,mixed> $werte   Feld => neuer Wert, bereits normalisiert
     *                                       (so, wie er in der Quell-Akte steht)
     * @param  bool $markerSetzen           ZAS-Marker auf den Geschwistern setzen
     * @param  bool $lohnVerfolgen          Lohn-Änderungsverfolgung je Geschwister
     * @return list<int>                    beschriebene Geschwister-Kennungen
     */
    public function spiegele(RecEmployee $quelle, array $werte, bool $markerSetzen, bool $lohnVerfolgen,
        array $nurInLeere = [], bool $offeneMarkerAuslassen = false): array;
}
```

Ablauf je Aufruf, in einer Transaktion:

1. `$werte` auf `PersonenFelder::SPIEGELN` schneiden; `tax_class`,
   `is_main_employer` und `other_employer` entfallen, wenn
   `tax_class_per_company` an ist. Bleibt nichts, passiert nichts. Die
   Einstellung wird nur gelesen, wenn eines dieser drei Felder dabei ist, und
   nie angelegt (fehlt die Zeile: Schalter aus).
2. Umfang über `PersonScopeResolver`, Quelle selbst ausgenommen. Keine
   Geschwister: nichts.
3. Je Geschwister: aktuelle Werte der betroffenen Felder lesen (Query-Builder).
   Nur Felder schreiben, deren Wert sich **unterscheidet** (Vergleich nach
   derselben Normalisierung wie `RecEmployeeExportObserver::normalizePayrollValue`:
   leer = null). Gleiche Werte werden nicht angefasst — sonst setzte jedes
   Speichern Marker auf beiden Akten. Felder in `$nurInLeere` werden nur in
   **leere** Geschwisterfelder geschrieben (Erstbefüllung §5.1, Rohwerte §5.2).
   Mit `$offeneMarkerAuslassen` bleiben Geschwister mit offenem
   `zas_changed_at` ganz außen vor (ZAS-Pfad §5.2).
4. Schreiben per **`DB::table('rec_employees')->update()`**, nie Eloquent. Damit
   feuert kein Observer auf dem Geschwister, es gibt keine Rückkopplung, und die
   ZAS-Regel „Massenläufe am Marker vorbei" bleibt verletzungsfrei — der Marker
   wird hier **ausdrücklich** gesetzt, nicht als Nebenwirkung:
   - `zas_changed_at = now()` auf dem Geschwister, wenn `$markerSetzen` und
     mindestens ein geschriebenes Feld in `RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS` steht.
   - Lohn-Verfolgung, wenn `$lohnVerfolgen`: je geschriebenem Feld aus
     `employee_payroll_tracked_fields` (Team des Geschwisters) ein Eintrag
     `{field, old, new, at}` an `payroll_data_changed_fields` des Geschwisters,
     mit **dessen** altem Wert; Erstbefüllung (alt = null) zählt wie heute nicht.
     Dafür wird die bestehende Logik aus `trackPayrollChanges` in eine statische
     Methode mit Signatur `(int $employeeId, int $teamId, array $aenderungen)`
     gezogen, die Observer und Spiegel gemeinsam benutzen — keine Kopie.
   - `updated_at = now()`.
5. Rückgabe der beschriebenen Kennungen (für Log und Tests).

Mutatoren des Modells (Leerraum raus aus `steuer_id` / `sozialversicherungsnummer`)
greifen am Query-Builder nicht. Deshalb nimmt der Spiegel die Werte **aus der
gespeicherten Quell-Akte** (`$quelle->getAttributes()`-Rohwerte nach dem Save),
nicht aus der Eingabe — die sind schon normalisiert.

---

## 5. Die Schreibwege

### 5.1 Portal, HR-Akte, Vertragsunterschrift, Backfill über Eloquent

Alle diese Wege speichern über Eloquent (`$employee->update()` / `save()`), und
auf `RecEmployee::updated` hängt bereits `RecEmployeeExportObserver`. Dort kommt
**ein dritter Listener** dazu, in `safelyRun` wie die anderen:

```php
RecEmployee::updated(static function (RecEmployee $employee): void {
    self::safelyRun(function () use ($employee): void {
        $geaendert = array_intersect_key($employee->getAttributes(), array_flip(array_keys($employee->getChanges())));
        app(PersonenSpiegel::class)->spiegele($employee, $geaendert, markerSetzen: true, lohnVerfolgen: true);
    }, 'rec_employee.updated.spiegel', $employee->id);
});
```

Damit sind **ohne Einzelumbau** abgedeckt: `PortalProfileWriter` (neues Portal),
`EmployeePortal` (altes Portal), `Employees/Show::saveAll`, `ContractSigning`
(Arbeitgeber-Erklärung), `BackfillEmployeeFieldsFromApplicant`. Die Reihenfolge
der Listener ist egal: Marker und Lohn-Eintrag der Quelle schreibt der Observer
wie heute auf der Quelle, der Spiegel schreibt nur Geschwister.

**Erstbefüllung füllt nur Leeres** (Nachtrag 09.10., Review I2): war das Feld
der Quelle vor dem Speichern leer, schreibt der Spiegel den neuen Wert nur in
**leere** Geschwisterfelder. Nur eine echte Änderung (alter Wert nicht leer)
überschreibt nach „letzter Schreiber gewinnt". Grund: `BackfillEmployeeFieldsFromApplicant`,
die HR-Ersterfassung und die Arbeitgeber-Erklärung füllen leere Felder — ohne
diese Regel schöbe ein Nachtrag aus einer alten Bewerbung den aktuellen Wert
der anderen Akte weg (mit Marker). Wer eine Erstangabe bewusst übertragen will,
nimmt das Prüfkommando mit `--nach`.

Rückkopplung ist ausgeschlossen, weil Geschwister per Query-Builder beschrieben
werden (kein `updated`-Ereignis). Zusätzlich hält `PersonenSpiegel` einen
statischen Wächter (`$laeuft`), der einen verschachtelten Aufruf sofort
zurückkehren lässt — Gürtel und Hosenträger, gemessen im Test.

**Fehlerfall:** Scheitert der Spiegel (Ausnahme), bleibt die Quell-Akte
gespeichert, `safelyRun` loggt `rec_employee.updated.spiegel`, die Geschwister
bleiben alt. Das Prüfkommando (§6) findet den Fall beim nächsten Lauf. Kein
Rollback der Quelle: ein Mitarbeiter, dessen „Gespeichert." verschwindet, weil
die Schwester-Akte klemmt, wäre schlimmer.

### 5.2 Nicht über Eloquent: Query-Builder-Schreiber

- **ZAS-Eingang** (`ZasInboundEmployeeImporter::syncMatchedFields`): nach dem
  Update der getroffenen Akte ruft der Importer den Spiegel **ausdrücklich** mit
  den überschriebenen `$employeeFields`:
  `spiegele($existing, $employeeFields, markerSetzen: true, lohnVerfolgen: false)`.
  - Marker **ja** auf den Geschwistern: ZAS hat den Wert für die MA-Akte
    geliefert, weiß aber nichts von der RG-Akte — die muss in die nächste
    Aktualisierungsdatei. Die getroffene Akte selbst bekommt wie bisher keinen
    Marker (kein Echo).
  - Lohn-Verfolgung **nein**: Werte aus ZAS lösen heute keine Lohn-Verfolgung
    aus (Kommentar in `syncMatchedFields`), das gilt auch für den Spiegel.
  - Geschwister, die **nicht** `isZasOwned()` sind (Bewerbungs-Akten), werden
    trotzdem beschrieben: der Schutz „ZAS überschreibt keine Bewerbungs-Akte"
    gilt dem Zufallstreffer über die Personalnummer, nicht dem bewusst
    verklammerten Menschen. `OVERWRITE_PROTECTED` greift schon vorher — was
    dort steht, kommt gar nicht erst in `$employeeFields`.
  - **Offener Marker** (Nachtrag 09.10., Review C1): Hat die getroffene Akte
    ein offenes `zas_changed_at`, ist unsere Änderung auf dem Weg zu ZAS und die
    Lieferung für die Personenfelder veraltet. Dann überschreibt der Import
    deren Personenfelder (`PersonenFelder::SPIEGELN`) **nicht**; die übrigen
    Felder und der Status-Abgleich laufen wie bisher. Ebenso lässt der Spiegel
    auf diesem Weg Geschwister mit offenem Marker aus. Sonst drehte eine
    veraltete Lieferung eine Portal-Änderung auf **beiden** Akten zurück
    (Portal RG → Spiegel MA → alte ZAS-Zeile für MA → zurück auf RG). Preis:
    eine echte Korrektur bei ZAS kommt erst eine Lieferung nach unserem Export an.
  - **Rohwerte ohne Lookup-Treffer** (`$mapped['unmatched']`, z. B.
    `nationality = 'Syrisch'`) schreibt der Import nur in leere Felder; der
    Spiegel hält sich daran und füllt mit ihnen nur **leere** Geschwisterfelder
    (Review I3) — ein sauberer Code (`SYR`) bei der anderen Akte bleibt.
  - Liefert dieselbe Datei RG- und MA-Zeile mit **verschiedenen** Werten, gewinnt
    die später verarbeitete Zeile; beide Akten bekommen diesen Wert, beide
    kommen in die Aktualisierungsdatei zurück. Die Abweichung liegt dann bei ZAS
    und wird dort sichtbar. Bewusst kein Sonderfall.
- **Backfill-Kommandos per Query-Builder** (`BackfillNationality`,
  `BackfillEmployerDeclaration`, `NormalizeEmployeePhones`): laufen vor dem
  Backfill der Personen-Zeilen oder betreffen `phone`. Kein Umbau; wer sie nach
  dem Spiegel noch einmal laufen lässt, nimmt das Prüfkommando hinterher.
- **Paarung beim ZAS-Eingang** (`PersonPairLinker::pairIfExact`, ZAS legt für
  einen bekannten Menschen einen neuen Datensatz der anderen Gesellschaft an):
  **Datenhoheit liegt bei uns** (Entscheidung Sebastian 09.10.). Von ZAS brauchen
  wir nur die Information „es gibt jetzt auch eine MA-Anstellung" mit ihrer
  Personalnummer. Deshalb übernimmt die neue Akte direkt nach dem Verklammern
  die Personenfelder der **bestehenden** Akte:
  `PersonenSpiegel::uebernimmBeiPaarung($bestehendeId, $neueId)` (zweiseitig).
  - Unsere nicht-leeren Werte gehen in die neue Akte. Ist ein Feld bei uns leer
    und hat ZAS einen Wert geliefert, wird er in unsere Akte nachgetragen —
    außer bei Feldern aus `ZasInboundEmployeeImporter::OVERWRITE_PROTECTED`
    (`identity_card_number` als Login-Faktor, `country_code`, das der Mapper
    ohne `Land` auf `'de'` erfindet; Review I4). Ein nicht-leerer Wert bei uns
    wird nie überschrieben. Das gilt auch, wenn die bestehende Akte selbst
    ZAS-Bestand ist.
  - Marker auf jeder Akte, die beschrieben wurde, sofern sich ein RELEVANTES Feld
    geändert hat — ZAS bekommt so für den MA-Datensatz unsere Werte zurück. Kein
    Lohn-Eintrag: die neue Akte hat noch keine Lohnhistorie.
  - Gilt nur für die automatische Paarung beim Eingang (neue Akte ist eindeutig
    die ZAS-Akte). Beim Hand-Link / Audit-Kommando (`PersonPairLinker::stamp`
    über `recruiting:person-pair-audit`) ist nicht klar, welche Akte führt —
    dort zeigt das Prüfkommando (§6) die Abweichung, HR gleicht mit `--nach` an.
- **`CreateEmployeeFromApplicantService` (Neuanlage):** `created` spiegelt nicht
  (es gibt noch keine Geschwister; die Paarung kommt danach, §1).

---

## 6. Prüfkommando `recruiting:personendaten-abgleich`

Neues Kommando `src/Console/Commands/PersonendatenAbgleich.php`.

```
recruiting:personendaten-abgleich {--team=} {--person=} {--nach=} {--felder=}
```

- **Ohne `--nach`: nur lesen.** Für jede Person mit mindestens zwei Akten
  (Umfang über `PersonScopeResolver`, Zweig 1 und 2) und mindestens einem
  Personenfeld (§2.1), das zwischen den Akten abweicht (Normalisierung wie §4
  Schritt 3): eine Tabelle `Person | Feld | Akte A (PNr) | Akte B (PNr) | …`.
  Am Ende eine Summe: Personen geprüft, Personen mit Abweichung, Felder
  abweichend. `tax_class`, `is_main_employer` und `other_employer` entfallen bei
  `tax_class_per_company`. Der Lesemodus legt keine Einstellungs-Zeile an.
- **`--person=<rec_person_id> --nach=<rec_employee_id>`: angleichen.** Alle
  Geschwister der Person bekommen die Personenfelder der genannten Akte über
  `PersonenSpiegel::spiegele(..., markerSetzen: true, lohnVerfolgen: true)` —
  derselbe Schreiber, dieselben Marker. Vorher wird die Tabelle dieser Person
  gezeigt; ohne `--person` ist `--nach` ein Fehler. **Kein `--alle --nach`**:
  pauschales Überschreiben gibt es nicht.
- `--felder=street,zip` grenzt die Ausgabe auf Felder ein (für große Listen) —
  und zusammen mit `--nach` auch das Angleichen: übertragen wird nur, was
  angesehen wurde (Review I5).
- `--team` grenzt die Quell-Akten ein, nicht den Menschen (wie bei
  `portal-umstellen`).

Vor dem Pilot läuft das Kommando auf der Produktion im Lesemodus; die Liste geht
an HR, die je Person entscheidet. Erwartung aus dem Vorflug des Konto-Pakets:
354 Paare, Abweichungen bisher ungezählt.

---

## 7. Einstellung

`RecApplicantSettings::DEFAULT_SETTINGS['tax_class_per_company'] = false`. Ein
Schalter im Einstellungs-Fenster → Lohn, Text: „Steuerklasse und
Haupt-/Nebenarbeitgeber je Gesellschaft führen" (mit Erklärung: diese drei
Felder bleiben von der Spiegelung ausgenommen). Der Schlüssel bleibt
`tax_class_per_company`. Als Boolean-Schalter, kein
Select — das bekannte Select-Speicherproblem im Einstellungs-Fenster wird so
umgangen.

---

## 8. Tests

Integration (Capsule + SQLite, Muster `ZasExportMarkerGateTest` mit registriertem
Observer), je ein Test, der rot wird, wenn die Regel fehlt:

1. Portal-Speichern der Adresse an der RG-Akte: MA-Akte hat die neue Adresse,
   beide `zas_changed_at` gesetzt, Lohn-Eintrag an beiden mit je eigenem `old`.
2. Gleicher Wert auf beiden: Geschwister unangetastet, kein Marker, kein Eintrag.
3. `tax_class` bei `tax_class_per_company = true`: nicht gespiegelt, Rest schon.
4. Gesellschaftsfeld (`cost_center`) ändern: Geschwister unverändert.
5. `abweichend`-Zeile (gleicher Marker, andere Nummer): nicht beschrieben.
6. Rückkopplung: Speichern der Quelle führt zu genau einem Spiegel-Lauf (Zähler
   im Spiegel, Wächter gemessen).
7. ZAS-Eingang überschreibt `street` an der MA-Akte: RG-Akte hat den Wert und den
   Marker, MA-Akte hat den Marker **nicht**, kein Lohn-Eintrag an beiden.
8. ZAS-Eingang: Geschwister ohne `isZasOwned()` wird trotzdem beschrieben.
8a. Paarung beim ZAS-Eingang: neue MA-Akte trägt danach unsere Adresse/Bank
    (ZAS-Wert überschrieben), Marker gesetzt; ein bei uns leeres Feld behält den
    ZAS-Wert und wird in die bestehende Akte nachgetragen.
9. Spiegel wirft: Quelle bleibt gespeichert, Log-Zeile, Geschwister alt.
10. Prüfkommando listet Abweichung, zählt richtig, schreibt im Lesemodus nichts;
    `--person --nach` gleicht an und setzt Marker; `--nach` ohne `--person`
    bricht ab.
11. `MassenzuweisungGeschlosseneWeltTest` bleibt grün (keine neue Spalte).
12. Mutationsproben (Memory `reference_pruefmuster_gruenes_nichts`): Query-Builder
    gegen Eloquent im Spiegel tauschen → Test 6 muss rot werden; `RELEVANT_EMPLOYEE_FIELDS`-
    Prüfung entfernen → Test 4 (Marker) rot.

---

## 9. Deploy, Ankündigung, Offenes

- Keine Migration. `view:clear` (Einstellungs-Fenster). Kein `queue:restart`.
- Nach dem Deploy: `recruiting:personendaten-abgleich` im Lesemodus, Liste an HR.
- Michel: „Stammdaten-Änderungen einer Person kommen ab jetzt für RG- und
  MA-Datensatz gemeinsam in der Aktualisierungsdatei. Keine neue Spalte."
- **Offen für Markus / Lohnbüro:** Steuerklasse je Person oder je Gesellschaft
  (§2.1). Bis zur Antwort: gespiegelt, Schalter vorhanden.
- **Nicht Teil dieses Pakets, als nächstes:** Trigger „Einbuchung mit
  MA-Personalnummer → gültiger MA-Vertrag am Veranstaltungstag" (Markus,
  09.10.); offen dort: Vertragsbeginn und -ende.
