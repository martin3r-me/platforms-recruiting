# Design: Vertrag aus der Mitarbeiterakte + MA-Vertragscheck

**Datum:** 2026-10-09
**Modul:** platforms-recruiting (kein Edit an Core/CRM/HCM)
**Branch:** feat/ma-konto (setzt Verträge an der Anstellung vom 07.10., das neue Portal und die Einsatz-Prüfung voraus)
**Status:** gebaut (Stand Task 9, nachgezogen auf den gebauten Stand)

---

## 1. Ziel

Zwei Dinge, die zusammen einen geschlossenen Ablauf ergeben:

1. **Vertrag aus der Akte.** HR öffnet die Mitarbeiterakte, klickt „Vertrag erstellen",
   wählt Vertragsart, Beginn, Ende und Zuschlag. Der Vertrag entsteht an genau dieser
   Anstellung (RG oder MA), geht als offener Punkt ins Portal, der Mitarbeiter bekommt
   eine WhatsApp und unterschreibt im Portal. Bisher ging das nur aus der Bewerbung —
   ein MA-Datensatz aus ZAS hat oft keine.
2. **MA-Vertragscheck.** Die stündliche Einsatz-Prüfung sieht jede Einbuchung mit
   MA-Personalnummer. Hat die MA-Anstellung dieser Person keinen unterschriebenen
   Arbeitsvertrag, der den Einsatztag abdeckt, entsteht ein Fall auf dem HR-Schreibtisch
   mit dem Weg „Vertrag erstellen". Ist der Vertrag unterschrieben, schließt sich der
   Fall von selbst.

Markus (Telefonat 09.10.): „Wenn ein Mitarbeiter als MA eingebucht ist, muss sichergestellt
und getriggert werden, dass die Person einen gültigen MA-Vertrag hat; der ist meist nur
einen Monat gültig." Die Regel, WANN ein Job MA erfordert, liegt in ZAS — wir sehen nur das
Ergebnis (MA-Personalnummer an der Buchung).

### Was bewusst NICHT drin ist

- **Kein automatischer Versand aus dem Check.** Dafür fehlen Beginn, Dauer und die
  Tätigkeit im Vertrag (offene Fragen an Markus, §9). Der Check endet beim HR-Fall; der
  Automat ist später eine Aufgabe: dieselbe Regel, dieselbe Erstellung, nur ohne Klick.
- **Keine neuen Vertragsarten.** Vorlagen (Logistik, Zapfer, …) legt HR wie bisher im
  Vorlagen-Bereich an, mit Gesellschaft und Tätigkeit. Welche es geben soll: offene
  Frage an Markus.
- **Kein E-Mail-Versand** (eigenes Paket). Der Hinweis geht per WhatsApp, das Portal
  zeigt den Vertrag auch ohne Nachricht.
- **Kein Umbau des Bewerbungs-Wegs.** Vertrag aus der Bewerbung bleibt wie er ist.
- **Keine Zusatzverträge (AT-*) und kein IFSG aus der Akte** in dieser Stufe — nur
  Arbeitsverträge (`AV-*`). Sonst müssten Pflichtkopplungen (AV zieht IFSG nach)
  nachgebaut werden, die für MA-Monatsverträge nicht geklärt sind.

---

## 2. Vertrag aus der Akte

### 2.1 Oberfläche (Employees/Show, Reiter Verträge)

Knopf **„Vertrag erstellen"** über der Vertragsliste. Fenster mit:

| Feld | Regel |
|---|---|
| Vertragsart | Select der aktiven Vorlagen mit `type = contract`, Code `AV-*`, `company = Akte.company`; Anzeige `name` (Tätigkeit in Klammern). Leer → Hinweis „Für die Gesellschaft {company} ist keine Arbeitsvertrags-Vorlage angelegt" |
| Beginn | Pflicht, Datum (`Y-m-d`-String-Property, nie datetime-Cast binden) |
| Ende | optional; leer → `RecContract::resolveContractDates` (Jahr, Monatsende). HR trägt für MA den Monat selbst ein |
| Zuschlag | Pflicht, Dezimal mit Komma erlaubt („0,60"); 0 erlaubt |

Knopf „Erstellen und senden". Danach steht der Vertrag in der Liste „offen" mit Beginn,
Ende, Zuschlag und Status „gesendet"; `wire:confirm` nicht nötig, die Aktion ist
umkehrbar (stornieren wie bisher).

Sperren: Akte inaktiv → Knopf aus. Die Überschneidungsprüfung gilt dem **ganzen Zeitraum**
[Beginn, aufgelöstes Ende] (leeres Ende = +1 Jahr; ein bestehender AV mit leerem Ende
gilt als offen) gegen jeden **nicht stornierten AV** dieser Anstellung → Fehlermeldung
„Für diesen Zeitraum gibt es bereits einen Arbeitsvertrag (#id, bis dd.mm.yyyy) — erst
stornieren oder neu ausstellen." Der Folgemonat ist frei. (Neu ausstellen bleibt der
bestehende Weg.)

Doppelklick-Schutz: die Prüfung läuft in der Transaktion nach `lockForUpdate` auf die
Anstellung; der Knopf ist per `wire:loading` gesperrt. Offene Verträge lassen sich in der
Akte **stornieren** (Aktion für Verträge mit Status `sent`/`pending`).

### 2.2 Erstellung (`VertragAusAkte`, neuer Service)

```php
final class VertragAusAkte   // Konstruktor nimmt den VertragHinweisSender
{
    /** @return RecContract  Status 'sent', Link angelegt, Hinweis versucht */
    public function erstellen(RecEmployee $anstellung, RecContractTemplate $vorlage, VertragsAngaben $angaben, ?int $userId): RecContract;
}
final class VertragsAngaben { public function __construct(public string $beginn, public ?string $ende, public float $zuschlag) {} }
```

Schritte, in einer Transaktion (bis auf den WhatsApp-Versand, der danach läuft):

1. Wächter: `$vorlage->giltFuerAnstellung($anstellung)` sonst Ausnahme; Code `AV-*`;
   Doppelabdeckung (§2.1) sonst Ausnahme.
2. Daten: `vertragsbeginn`, `vertragsende` (über `resolveContractDates`) und neu
   `zuschlag` als **Vertrags-Extrafeld** (`SeedRecContractExtraFields` um `zuschlag`
   ergänzt, **Typ text, deutsches Format „0,60"** — den Typ decimal gibt es im Core nicht,
   `number` verwirft das Komma still; ein Altfeld vom Typ `number` bekommt die Zahl).
   `rec_applicants.zuschlag` wird beim Erstellen aus der Akte **nicht** geschrieben: das
   Vertrags-Extrafeld ist die einzige Quelle. Sonst änderte sich der ZAS-Exportwert vor
   der Unterschrift ohne Marker/Lohn-Eintrag, und eine RG-Bewerbung bekäme den MA-Zuschlag.
   Der ZAS-Export dieses Zuschlags bleibt wie bisher (offene Frage E14 an Markus).
3. Inhalt: `personalized_content` über **`RecContractTemplate::personalizeFuerAnstellung(RecEmployee $anstellung, RecContract $vertrag): string`** (§2.3).
4. `RecContract::create([... 'rec_employee_id' => $anstellung->id, 'rec_applicant_id' => $anstellung->rec_applicant_id (darf null sein), 'status' => 'pending', 'created_by_user_id' => $userId])`,
   dann Extrafelder setzen, Inhalt rendern, `getOrCreatePublicFormLink()`,
   `status = sent`, `sent_at = now()`. **Eloquent ist hier richtig** (ein Vertrag, kein
   Massenlauf; der ZAS-Marker auf `contract_signed_at` kommt erst beim Unterschreiben).
5. Nach der Transaktion: `VertragHinweisSender::sende($anstellung)` (§2.4). Ergebnis in
   `rec_contracts.notes` als Zeile `Hinweis: sent|failed|no_phone|…` (keine neue Spalte).
   Ein Fehlschlag ist kein Abbruch — der Vertrag liegt im Portal.

### 2.3 Personalisierung ohne Bewerbung

`personalizeContent()` kennt nur Quellen am Bewerber (`contact.*`, `applicant.*`).
Vorlagen sollen **unverändert** weiter funktionieren, auch für Anstellungen ohne
Bewerbung. Deshalb bekommt der Platzhalter-Auflöser eine zweite Datenquelle mit
Vorrang-Kette, keine zweite Vorlagensprache:

| Quelle | ohne Bewerbung | mit Bewerbung |
|---|---|---|
| `contact.first_name/last_name/email/phone` | Anstellung | wie bisher (CRM-Kontakt), leer → Anstellung |
| `contact.address.*` | Anstellung (`street`, `house_number`, `zip`, `city`) | wie bisher, leer → Anstellung |
| `applicant.zuschlag` | Vertrags-Extrafeld `zuschlag` | Extrafeld, leer → Bewerber |
| `applicant.<spalte>` (z. B. birth_date, iban) | gleichnamige Spalte der Anstellung, sonst `''` | wie bisher, leer → Anstellung |
| `applicant.extra_field.*` | `''` | wie bisher |
| neu `employee.<spalte>` | Anstellung | Anstellung |
| `contract.*`, `settings.*`, `text:*`, `meta.*` | wie bisher | wie bisher |

Umgesetzt als `personalizeFuerAnstellung()` neben `personalizeContent()`, beide über
einen gemeinsamen privaten Auflöser mit optionalem `?RecApplicant` und `?RecEmployee`.
Bestehende Aufrufe von `personalizeContent(RecApplicant …)` ändern ihr Verhalten
nicht (Test: Vertrag aus der Bewerbung rendert byteidentisch wie vorher).

### 2.4 Hinweis per WhatsApp (`VertragHinweisSender`)

Kopie des Musters `DokumentHinweisSender`: aktiv, `portal_v2_since`, Nummer, Vorlage aus
Team-Einstellung **`employee_contract_wa_template_id`** (Einstellungs-Fenster, Reiter
Allgemein, **unter dem Ansprechpartner-Select**, „Vertrag zur Unterschrift —
WhatsApp-Template mit Portal-Link"), URL-Knopf
mit Portal-Token, Platzhalter nur `vorname`/`name`/`1`. **Fällt auf
`document_wa_template_id` zurück**, wenn der eigene Schlüssel leer ist (derselbe
universelle Text „im Portal liegt etwas für dich" passt). Statuswerte wie beim
Dokument-Sender.

### 2.5 Portal

`PortalShell::dokumente()` verlangt heute eine Bewerbung (`if (!$employee->applicant)
return []`). Neu:

- Verträge werden **über den Personen-Umfang** geladen (`PersonScopeResolver`, alle
  Anstellungen), `status ≠ cancelled`, Anzeige wie bisher plus Gesellschaft als
  Zusatz („Arbeitsvertrag · MA") wenn die Person mehr als eine Anstellung hat. Der
  Anzeigename kommt an einer Stelle: `VertragsAnzeige::name()`.
- `sign_url` nur bei `status = sent` (heute auch bei `pending`, der Link läuft dann
  ins Leere).
- `pdf_url` für unterschriebene Verträge: bisher über den Bewerber-Token. Neu eine
  Route `recruiting.public.contract-pdf-anstellung` mit dem **Vertrags**-Link-Token
  (`CorePublicFormLink` des Vertrags), Zugriff nur mit gültiger Portal-Sitzung der
  Person, die diese Anstellung umfasst (Muster `DokumentZugriff`). Die alte Route bleibt.
  `sign_url` und `pdf_url` laufen über den Vertrags-Link; die PDF-Route heißt
  `GET /mitarbeiter/vertrag/{token}` (`recruiting.public.contract-pdf-anstellung`).
  Zugriff verlangt eine **aktive** Anstellung im Personen-Umfang, auf die die Sitzung
  passt (nicht die Aktivität der Vertrags-Anstellung): der Vertrag einer inaktiven
  Schwester-Anstellung bleibt für dieselbe Person lesbar. Antwort mit
  `Cache-Control: private, no-store`. Das PDF-Rendern liegt im gemeinsamen Trait
  `RendersContractPdf` (Bewerber-Route und neue Route teilen es).
- **Offener Punkt:** `OffenePunkte::stand()` bekommt eine dritte Quelle
  `VertragLeser::offenePunkte($employee)`: je Vertrag mit `status = sent` der Person
  ein Punkt `{code: 'vertrag:<id>', label: '<Anzeigename> · <Gesellschaft>', status:
  'offen', ko: false, punkt: 'crit', text: 'Lesen und unterschreiben'}`. Für den
  Trigger (`fuerTrigger`) gilt dieselbe 7-Tage-Pause wie bei Dokumenten, gemessen an
  `sent_at`. Damit meldet die Einsatz-Prüfung den Vertrag mit, ohne eigenen Versand. Der offene Punkt trägt die
  Gesellschaft immer (`Arbeitsvertrag · MA`), die Vertragsliste nur bei mehr als einer
  Anstellung.

### 2.6 Unterschrift ohne Bewerbung

`ContractSigning` arbeitet schon null-sicher. Drei Stellen hängen an
`$contract->applicant?->employee` und müssen auf **`$contract->employee ?? $contract->applicant?->employee`** umgestellt werden:
`prefillEmployerDeclaration`, `applyEmployerDeclaration`, `applyDayBudget`. Dazu
`usesInformalAddress()` aus der Anstellung, wenn kein Bewerber. `buildPortalUrl` liefert
den **neuen** Portal-Link (`recruiting.public.portal-shell`, Token der Anstellung), wenn
`portal_v2_since` gesetzt ist — sonst wie bisher.

`RecContract` saved-Hook (`contract_signed_at`): bricht heute ohne Bewerber ab. Neu:
Anstellung = `$contract->employee ?? $applicant?->employee`; die „alle AV unterschrieben"-
Bedingung zählt die AV-Verträge **dieser Anstellung** (`rec_employee_id`), nicht des
Bewerbers. Zusätzlich beim Unterschreiben: `hr_data.contract_end_date` der Anstellung =
`vertragsende` des unterschriebenen AV, wenn leer oder älter — das ist „Befristet bis"
im ZAS-Export; der bisherige Weg (`avContractEndDate` über den Bewerber) bleibt als
Erstquelle und greift bei Bewerbungs-Verträgen weiterhin.

Die AV-Zählung je Anstellung gilt, wenn der Vertrag einen Anker (`rec_employee_id`) hat;
ohne Anker zählt wie bisher der Bewerber. `contract_end_date` wird nur bei Verträgen
**ohne** Bewerbung geschrieben (bei Bewerbungs-Verträgen bleibt `avContractEndDate()` die
Quelle). Auch `RePersonalizeContractsTool` kommt mit Verträgen ohne Bewerbung zurecht
(`personalizeFuerAnstellung`); das Neu-Rendern aus der Bewerbung (Applicant/Show) bleibt
für Verträge mit Bewerbung auf dem alten Pfad.

### 2.7 HR-Akte, Liste

`signedContracts()`/`openContracts()` zeigen zusätzlich Beginn, Ende, Zuschlag (aus
Extrafeldern; Alt-AV ohne Extrafeld: Zuschlag aus dem Code `AV-060` → 0,60 wie
`ReissueContractService`). Stornieren und Neu ausstellen bleiben.

PDF für HR ohne Bewerbung über `GET /employees/vertraege/{contractId}/pdf`
(`recruiting.employees.vertrag-pdf`, Team-geprüft). „Neu ausstellen" nur für Verträge mit
Bewerbung (`ReissueContractService` braucht sie); offene Verträge lassen sich in der Akte
stornieren.

---

## 3. MA-Vertragscheck

### 3.1 Reine Regel (`VertragsDeckung`, `src/Support`)

```php
final class VertragsDeckung
{
    /**
     * @param list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}> $vertraege  Verträge EINER Anstellung
     * @return array{deckung:'unterschrieben'|'unterwegs'|'keiner', vertrag_id:?int}
     */
    public static function amTag(array $vertraege, string $tag): array;
}
```

- `unterschrieben`: AV (`AV-*`), `status = completed`, `signed_at` gesetzt, nicht
  ersetzt, `beginn ≤ tag` und (`ende` leer oder `ende ≥ tag`).
- `unterwegs`: AV mit `status = sent`, Laufzeit deckt den Tag (oder Laufzeit leer).
- sonst `keiner`.
- AV ohne Laufzeitfelder (Altbestand vor den Extrafeldern) zählt als **deckend**,
  wenn unterschrieben — ein alter unbefristeter Vertrag darf keinen Fall erzeugen.

### 3.2 Einhängen in die Einsatz-Prüfung

In `EinsatzPruefung::lauf()`, je Person, **vor** dem `$punkte === []`-Kurzschluss
(sonst wird eine Person ohne andere offene Punkte nie geprüft):

1. `kommendeAuftraege($umfangIds)` → je Buchung die Gesellschaft. Sie ist der Präfix von
   `pnr_raw` (auch gekürzt, `MA878`), nur ohne Präfix die Firma der gebuchten Anstellung.
   Geprüft wird die Anstellung des Personen-Umfangs mit dieser Gesellschaft (sonst die
   gebuchte), und nur AV, deren Vorlage zu dieser Gesellschaft gehört. Der Vergleich der
   Gesellschaft ist case-insensitiv. Geprüft werden nur Buchungen, deren Gesellschaft in
   `contract_check_companies` steht (Team-Einstellung, Standard `['MA']`; Schalter im
   Einstellungs-Fenster als Checkbox je Gesellschaft RG/MA — kein Select, wegen des
   bekannten Select-Speicherproblems).
2. Verträge dieser Anstellung laden (`rec_employee_id`, nicht storniert, mit
   Extrafeldern) → `VertragsDeckung::amTag(…, $buchung->datum)`.
3. `keiner` → HR-Fall (§3.3). `unterwegs` → kein Fall; der Vertrag steht als offener
   Punkt im Portal (§2.5) und läuft über die normale Nachricht. `unterschrieben` →
   nichts, offene Fälle dieser Anstellung schließen (§3.4).
4. Befunde werden **je Anstellung aggregiert**: höchstens **ein** Fall je Anstellung und
   Lauf, auch bei mehreren ungedeckten Buchungen; der früheste ungedeckte Tag steht im
   Fall. Geschlossen wird nur, wenn alle Befunde der Anstellung `unterschrieben` sind.

Trockenlauf meldet die Fälle, schreibt nichts. Zähler im Bericht: „Vertragsprüfung:
n Buchungen geprüft, m ohne Vertrag, k Fälle neu, j Fälle geschlossen".

### 3.3 HR-Fall

`RecHrDeskCase` bekommt `REASON_CONTRACT_MISSING = 'contract_missing'`, Label
„Vertrag fehlt für Einsatz". Fall: `rec_employee_id` = die **betroffene Anstellung**
(MA-Zeile der Person; hat sie keine, die gebuchte Zeile als Rückfall), `rec_applicant_id = null`, `notes` = „MA-Einsatz am dd.mm.yyyy
(Veranstaltung, Tätigkeit) — kein unterschriebener Arbeitsvertrag der Gesellschaft MA
deckt diesen Tag.", `opened_at`. Dedupe: offener Fall mit diesem `reason` an dieser
Anstellung → kein zweiter (Notiz wird nicht fortgeschrieben; der früheste Einsatztag
reicht). `CONTRACT_BLOCKING_REASONS` **nicht** erweitern — der Fall blockiert nichts,
er fordert etwas an.

Auf dem HR-Schreibtisch zeigt der Fall den Knopf **„Vertrag erstellen"**, der die Akte
mit geöffnetem Fenster (§2.1) aufruft (`/employees/{id}?vertrag=neu&einsatz=Y-m-d`; der
Einsatztag wird aus der Fall-Notiz gelesen, `VertragsVorbelegung::einsatztagAusNotiz`).
Den Knopf gibt es nur, wenn die Firma der Akte zur Gesellschaft in der Fall-Notiz passt;
sonst steht dort ein Hinweis („erst Firma setzen / MA-Anstellung anlegen"), ebenso ein
neutraler Hinweis, wenn die Notiz nicht lesbar ist. Der Einsatztag liefert die Vorbelegung: Beginn vorbelegt mit dem Monatsersten des
Einsatztags, Ende mit dem Monatsletzten — nur Vorbelegung, HR ändert frei. (Die
Monatsregel ist eine Annahme aus „meist einen Monat gültig" und wird mit Markus'
Antwort ersetzt; sie steckt an EINER Stelle: `VertragsVorbelegung::fuerEinsatz()`.)

### 3.4 Schließen

Ein offener `contract_missing`-Fall wird im Lauf automatisch auf `STATUS_APPROVED`
gesetzt (`resolved_at = now()`, `resolution_notes = 'Automatisch: Arbeitsvertrag
unterschrieben (#id)'`), sobald `VertragsDeckung` für **alle** kommenden Buchungen
dieser Anstellung `unterschrieben` liefert. Verschwindet die Buchung (`missing_since`)
oder liegt sie in der Vergangenheit, bleibt der Fall offen bis HR ihn schließt —
ein stiller Abbau würde verbergen, dass jemand ohne Vertrag gearbeitet hat. Ein Fall an
einer gebuchten RG-Zeile schließt sich nicht automatisch, sobald eine MA-Zeile existiert
(HR schließt ihn von Hand).

---

## 4. Datenmodell

- Keine neue Tabelle, keine neue Spalte. Neu sind: Vertrags-Extrafeld `zuschlag`
  (Seed), Fallgrund `contract_missing` (Konstante), Einstellungen
  `employee_contract_wa_template_id` und `contract_check_companies` (JSON im
  Settings-Blob).
- Migration: **eine** — `2026_10_09_000003_make_rec_applicant_id_nullable_on_rec_contracts`
  (keine neue Spalte; `rec_contracts.rec_applicant_id` war `NOT NULL` mit Fremdschlüssel,
  ein Vertrag ohne Bewerbung ließ sich nicht speichern). Seed-Kommando
  `recruiting:seed-rec-contract-extra-fields` muss nach dem Deploy laufen (idempotent).

---

## 5. Einstellungen

Einstellungs-Fenster:
- Reiter Allgemein, unter dem Ansprechpartner-Select: „Vertrag zur Unterschrift — WhatsApp-Template mit Portal-Link"
  (Select wie beim Dokument-Template; leer = Dokument-Template wird genommen).
- Mitarbeiter/Lohn-Reiter: „Vertragsprüfung bei Einsätzen" mit zwei Checkboxen
  „Gesellschaft RG" (aus), „Gesellschaft MA" (an). Beide aus = Prüfung läuft nicht.

---

## 6. Tests

Integration (Capsule + SQLite, echte Migrationen), je eine Mutationsprobe genannt:

1. `VertragAusAkte` legt Vertrag mit `rec_employee_id`, Extrafeldern, `sent`, Link an;
   ohne Bewerbung rendert der Inhalt Name/Adresse aus der Anstellung. Probe: Vorrang-
   Kette entfernen → leerer Name.
2. Vorlage fremder Gesellschaft → Ausnahme. Doppelabdeckung → Ausnahme.
3. `personalizeContent(RecApplicant)` bleibt byteidentisch (Fixture-Vergleich).
4. Hinweis: Fallback auf `document_wa_template_id`; `failed` ist kein Erfolg; Status
   landet in `notes`.
5. Portal: Vertrag ohne Bewerbung erscheint; `pending` ohne `sign_url`; zweite
   Anstellung derselben Person sieht ihn; fremde Person nie (PDF-Route 403/404).
6. Offener Punkt `vertrag:<id>` in `fuer()` und `fuerTrigger()` (7-Tage-Pause ab
   `sent_at`). Probe: Pause entfernen.
7. Unterschrift ohne Bewerbung setzt `is_main_employer`, Tagekonto und
   `contract_signed_at`/`contract_end_date` an der Anstellung. Probe: Fallback
   `?? $contract->applicant?->employee` zurückdrehen.
8. `VertragsDeckung`: Tabelle (unterschrieben/unterwegs/keiner, Grenztage, Altvertrag
   ohne Laufzeit, ersetzter Vertrag, storniert).
9. Einsatz-Prüfung: MA-Buchung ohne Vertrag → genau ein Fall, zweiter Lauf → kein
   zweiter; RG-Buchung bei Standard-Einstellung → kein Fall; Vertrag `sent` → kein
   Fall; unterschrieben → Fall geschlossen; Trockenlauf schreibt nichts; Person ohne
   andere offene Punkte wird trotzdem geprüft (Probe: Check hinter den Kurzschluss
   schieben).
10. `MassenzuweisungGeschlosseneWeltTest` grün (keine neue Spalte); Blade-Check aller
    angefassten Vorlagen.

---

## 7. Sicherheit

- Vertrag erstellen: nur eingeloggte HR mit Zugriff auf die Akte (bestehende
  Komponente). Vorlage muss zum Team **und** zur Gesellschaft der Akte gehören.
- Portal: Vertrag und PDF nur für die Person, deren Umfang die Anstellung enthält;
  Sitzung wie bei Dokumenten geprüft, `#[Locked]`-Regeln des Portals unberührt.
- Unterschrift: unverändert über den Vertrags-Link-Token, nur `status = sent`.

---

## 8. Deploy

- **`migrate`** (eine Migration, siehe §4). `view:clear`. **`queue:restart` nicht nötig** (kein neuer Job; der
  Einsatz-Lauf ist ein Kommando).
- `php artisan recruiting:seed-rec-contract-extra-fields` (Extrafeld `zuschlag`).
- Vor dem Deploy die Fälle `mehrdeutig`/`firma_fehlt` aus `recruiting:vertraege-an-anstellung`
  klären. Ein Fall an einer gebuchten RG-Zeile schließt sich nicht automatisch, sobald eine
  MA-Zeile existiert (HR schließt von Hand).
- Die Migration auf der Demo mit MySQL prüfen (migrate + rollback), bevor gemerged wird.
- Einstellungen: WhatsApp-Template für Verträge (oder leer lassen → Dokument-Template);
  Vertragsprüfung MA steht standardmäßig an.
- Vorlagen: je Vertragsart eine Vorlage mit `company = MA` anlegen — ohne Vorlage ist
  der Knopf in MA-Akten leer, der Check erzeugt trotzdem Fälle.

---

## 9. Offene Fragen an Markus (ins Info-Dokument)

1. **Vertragsbeginn und -dauer bei MA:** Monatsanfang bis Monatsende? Tag der ersten
   Buchung? Wir belegen vorerst Monatserster/Monatsletzter des Einsatztags vor.
2. **Tätigkeit im Vertrag:** Welche Tätigkeit, wenn jemand für mehrere gebucht ist?
3. **Vertragsarten und Texte:** Welche Arbeitsverträge soll es je Gesellschaft geben
   (Logistik, Zapfer, Eventmitarbeiter, …), mit welchen Texten?
4. **Automatik:** Soll der Vertrag beim Check automatisch rausgehen, oder bleibt der
   Klick bei HR? (Technisch vorbereitet; eine Aufgabe nach der Antwort.)

---

## 10. Mutationsproben (Lauf am 2026-10-09)

Jede Mutation einzeln eingebaut, Filter gelaufen, Datei aus Sicherung zurückgespielt. Alle rot.

| # | Datei | Mutation | Ergebnis |
|---|---|---|---|
| 1 | `RecContractTemplate.php` | Zeile `$wert = $this->mitAnstellung(...)` in `personalize()` gelöscht | rot — `test_ohne_bewerbung_kommen_name_und_adresse_aus_der_anstellung` (+3) |
| 2 | `RecContract.php` | `anstellung()` nur über Bewerber | rot — `test_unterschrift_ohne_bewerbung_setzt_signed_at_und_befristung` (+5) |
| 3 | `RecContract.php` | `$avQuery` immer `$applicant->contracts()` | rot — `test_alle_av_unterschrieben_zaehlt_je_anstellung` (+3) |
| 4 | `VertragLeser.php` | `$grenze`-Bedingung gelöscht | rot — `test_offener_punkt_in_fuer_und_trigger_mit_pause_ab_sent_at` |
| 5 | `PortalShell.php` | `sign_url` immer Link | rot — `test_vertrag_ohne_bewerbung_erscheint_pending_ohne_signierlink` (+1) |
| 6 | `VertragPdfController.php` | `sitzungDeckt(...)` → `true` | rot — `test_pdf_zugriff_nur_mit_sitzung_der_person` (+2) |
| 7 | `VertragHinweisSender.php` | ohne Rückfall | rot — `test_ohne_eigene_vorlage_faellt_er_auf_die_dokumentvorlage_zurueck` |
| 8 | `EinsatzPruefung.php` | Kurzschluss vor den Vertragscheck | rot — `test_person_ohne_andere_offene_punkte_wird_trotzdem_geprueft` |
| 9 | `VertragsDeckung.php` | `laufzeitDeckt()`: `$b === null` → `$b !== null` | rot — `test_altvertrag_ohne_laufzeit_erzeugt_keinen_fall`, `test_deckung_am_tag` |
| 10 | `VertragsZeilen.php` | Firmenfilter gelöscht | rot — `test_rg_av_deckt_keinen_ma_einsatz` (+1) |
| 11 | `VertragsPruefung.php` | `$ziel` nur über gebuchte Zeile | rot — `test_buchung_an_der_rg_zeile_prueft_die_ma_anstellung` |
| 12 | `VertragsPruefung.php` | `prefixOf(...)` → `null` | rot — `test_gekuerzte_ma_nummer_ohne_firma_an_der_akte` (+4) |
| 13 | `VertragAusAkte.php` | Überschneidungs-Wächter gelöscht | rot — `test_doppelabdeckung_wird_abgelehnt_folgemonat_nicht` (+5) |
