# Vertrag an der Anstellung — Design

**Stand:** 07.10.2026 · **Zweig:** main (unabhaengig von feat/ma-konto) · **Stufe 1 von 2**

**Ziel:** Ein Arbeitsvertrag weiss, zu welcher **Anstellung** er gehoert — und damit zu
welcher GmbH. Heute haengt er nur am Bewerber, und ein Bewerber kann zwei Anstellungen
haben (RheinGedeck und MA). Das erzeugt einen Anzeigefehler, der **bereits live** ist.

---

## 1. Anlass — ein Fehler, der heute schon da ist

ZAS fuehrt zwei Firmen. Wer fuer beide arbeitet, hat bei uns **zwei** `rec_employees`-Zeilen
mit zwei Personalnummern (z. B. `RG18231` und `MA18232`), beide an **derselben** Bewerbung.
Der Arbeitsvertrag haengt nur an der Bewerbung (`rec_contracts.rec_applicant_id`) und
traegt **keine Firma** — weder er noch seine Vorlage. `team_id` hilft nicht, beide GmbHs
liegen im selben Team.

Zwei Stellen lesen Vertraege ueber den Umweg `Mitarbeiter → Bewerber → Vertraege`:

- `src/Livewire/Employees/Show.php:335` — die HR-Mitarbeiterakte
- `src/Livewire/Public/EmployeePortal.php:651` — das Mitarbeiterportal

Folge: Bei jedem Menschen mit zwei Anstellungen zeigt die **MA-Akte den
RheinGedeck-Arbeitsvertrag** — beschriftet als „Arbeitsvertrag", ohne Firmenhinweis, im
Portal fuer den Menschen selbst sichtbar. Ein Dokument, das rechtlich nur fuer die
andere GmbH gilt.

**Gemessen (Bericht `recruiting:report-signed-without-employee`, 05.10.2026):**

| Gruppe | Anzahl |
|---|---|
| Bewerber mit signiertem AV-Vertrag | 399 |
| davon Mitarbeiter, verlinkt | 290 |
| davon Mitarbeiter, Link fehlt | 66 |
| davon pruefen (mehrdeutig) | 10 |
| davon kein Mitarbeiter | 33 |

Unter den 290 verlinkten stehen Menschen mit zwei Personalnummern — bei denen besteht
der Fehler **heute**. Unter den 66 unverlinkten wuerde das Heilen der Verknuepfung
(`--backfill-links`, Probelauf: 64 Verknuepfungen fuer 49 Bewerber) bei **15 Menschen**
beide Anstellungen an die Bewerbung haengen und den Fehler sofort erzeugen. Deshalb ist
dieses Backfill **gestoppt**, bis dieses Paket live ist.

**Alle heute vorhandenen Vertraege sind RheinGedeck-Vertraege** (Aussage des Kunden,
05.10.). Das macht den Altbestand eindeutig zuordenbar.

## 2. Befund — was heute gilt und was das Design bestimmt

**Der Vertrag entsteht VOR der Anstellung.** Der Bewerber unterschreibt, danach legt der
Phase-4-Hook (`CreateEmployeeFromApplicantService`) den Mitarbeiter an — er liest dabei
sogar schon das Vertragsende aus dem AV-Vertrag (Zeile 387 ff.). Der Anker kann also
**nicht beim Anlegen des Vertrags** gesetzt werden; es gibt zu dem Zeitpunkt keine
Anstellung. Er wird beim Anlegen des **Mitarbeiters** nachgezogen.

**Sieben Stellen legen Vertraege an:**

| Stelle | Wann | Anstellung bekannt? |
|---|---|---|
| `Services/SendContractsService.php:111/133/167` | Versand aus der Nachbereitung (AV, IFSG, Zusatz) | nein |
| `Livewire/Applicant/Show.php:804` | Vertrag von Hand zuweisen | meist nein |
| `Tools/CreateContractTool.php:100` | MCP-Werkzeug | meist nein |
| `Livewire/DirectHire/Index.php:428` | Direkteinstellung — Vertrag **und** Mitarbeiter in einem Zug | ja, danach |
| `Services/ReissueContractService.php:249` | Neuausstellung eines Vertrags | ja (ueber den alten Vertrag) |

**Die Standard-Firma bei der MA-Anlage ist RG** (`config('recruiting.zas.company_prefix')`,
`ZasPersonnelNumber::DEFAULT_PREFIX = 'RG'`). Die zweite Anstellung (MA) entsteht nur
ueber den ZAS-Inbound und bekommt dabei **keine** Vertraege — das ist richtig so.

**Am Mitarbeiter gibt es keine Vertrags-Beziehung.** `RecEmployee` kennt `contracts()`
nicht; `RecApplicant` hat `contracts()` (hasMany), `employee()` (hasOne — bei zwei
Anstellungen mehrdeutig, liefert eine beliebige) und `employees()` (hasMany).

**Die Vormerkung fuer den Vertragsversand** (`rec_contract_send_reservations`, 06.10.)
haengt ebenfalls nur an `rec_applicant_id`. Siehe §6.

## 3. Design Stufe 1 — der Anker

### 3.1 Schema

Eine Migration, drei Spalten:

```
rec_contracts.rec_employee_id            unsignedBigInteger, nullable, index
rec_contract_templates.company           string(10), NOT NULL, default 'RG'
rec_contract_templates.taetigkeit        string(50), nullable
```

Nullbar, weil ein Vertrag im Bewerberstadium **noch keine** Anstellung hat — das ist
der Normalzustand bis zur MA-Anlage, kein Fehler. In `$fillable` aufnehmen.

**Die zwei Vorlagen-Spalten** sind der Teil von Stufe 2, der jetzt nichts kostet und
spaeter eine zweite Strukturmigration spart (Kunde, 07.10.: „alle, die den AV-default
gekriegt haben, haben einen Arbeitsvertrag fuer RheinGedeck als Eventmitarbeiter;
kuenftig kommt fuer MA ein Logistiker, eine Servicekraft dazu"). Heute weiss eine
Vorlage ueber sich nur ihren `code`, und das ganze Haus entscheidet an dessen Praefix:
`AV-` = Arbeitsvertrag (§15/§16-Vorschalt, Vertragsende-Uebernahme in
`CreateEmployeeFromApplicantService:390`), `AT-140` = Resttage, `IFSG` = Belehrung.
Firma und Taetigkeit stehen **nirgends**. Mit den zwei Spalten gilt:

- `company` sagt, **fuer welche GmbH** ein Vertrag aus dieser Vorlage gilt. Werte wie
  `rec_employees.company` (`RG`/`MA`), damit die Zuordnung Vorlage → Anstellung ein
  Gleichheitsvergleich ist und kein Mapping. Default `RG` fuellt alle Bestandszeilen
  (Annahme 1) ohne eigenen Schreiblauf.
- `taetigkeit` sagt, **als was** jemand mit diesem Vertrag arbeitet. Freier
  Schluessel in Stufe 1 (`eventmitarbeiter`, spaeter `logistiker`, `servicekraft`),
  kein Lookup, keine Validierung ausser „nicht leer bei `AV-`-Vorlagen". Das Backfill
  (§3.5) setzt `eventmitarbeiter` an alle `AV-`-Vorlagen; Belehrung und
  Zusatzvereinbarungen bleiben NULL, sie sind keine Taetigkeit.
- **Kein Schnappschuss am Vertrag.** Der Vertrag erreicht Firma und Taetigkeit ueber
  seine Vorlage (`contractTemplate->company`). Ein Vertrag wechselt nie die Vorlage,
  und eine Vorlage wechselt nach Gebrauch nicht die Firma — wer das will, legt eine
  neue Vorlage an (Regel aus §5: anderer Rechtstext → eigene Vorlage). Das
  Vorlagen-Formular zeigt beide Felder; `company` ist **gesperrt, sobald die Vorlage
  einen Vertrag erzeugt hat** (Test 11).

Der `code`-Praefix bleibt, was er ist: die **Art** des Dokuments (Arbeitsvertrag /
Zusatzvereinbarung / Belehrung). Ihn in eine Spalte zu heben waere richtig, ist aber
ein eigenes Paket mit 22 Guards (docs/zertifikat/guard-landkarte-511451c.md). Nicht
hier.

**Kein Fremdschluessel-Constraint**, und zwar bewusst anders als beim Bewerber:
`rec_applicant_id` ist `constrained(...)->cascadeOnDelete()` (Migration
2026_04_15_100000) — Bewerber loeschen loescht seine Vertraege, das ist gewollt, der
Vertrag ist sein Archivstueck. Die Anstellung ist dagegen nur ein **Verweis**: eine
`rec_employees`-Zeile kann aus dem ZAS-Bestand neu entstehen oder ersetzt werden, und
der Vertrag darf das ueberleben. Ein `nullOnDelete` waere die Alternative; es wuerde
aber das `change()`-/MySQL-Thema wiederholen, das auf `feat/ma-konto` bei
`2026_10_01_000002_add_employee_to_hr_desk_cases` Zeit gekostet hat (die Migration
liegt noch nicht auf main; ein FK-Umbau ist in der SQLite-Suite nicht belegbar). Also: Spalte plus Index, kein
Constraint. Verwaiste Verweise faengt der Backfill-Bericht (§3.5).

### 3.2 Beziehungen

```php
RecEmployee::contracts()   hasMany(RecContract::class, 'rec_employee_id')
RecContract::employee()    belongsTo(RecEmployee::class, 'rec_employee_id')
```

`RecApplicant::contracts()` bleibt unveraendert — die Bewerberseite, das
Bewerberportal, das Dashboard und der Versand-Riegel lesen weiter dort.

### 3.3 Wann der Anker gesetzt wird

**a) Bei der MA-Anlage aus dem Bewerber** (`CreateEmployeeFromApplicantService`) —
der Hauptweg: nach dem Anlegen des Mitarbeiters bekommen **alle** Vertraege des
Bewerbers mit `rec_employee_id IS NULL`, **deren Vorlage die Firma der neuen
Anstellung traegt**, die neue Anstellung. Heute ist das deckungsgleich mit „alle"
(Anstellung RG, Vorlagen RG) — die Regel ist trotzdem von Anfang an die allgemeine,
damit ein kuenftiger MA-Vertrag am Bewerber **nicht** an eine RG-Anstellung rutscht.
Alle Arten, nicht nur AV: IFSG-Belehrung und Zusatzvereinbarungen gehoeren zur
selben Anstellung. Schreibweg:
Query Builder auf `rec_contracts` (der `RecContract::booted()`-Hook schreibt beim
Signieren auf die HR-Daten; ein Backfill-Update darf ihn nicht erneut ausloesen).

**b) Direkteinstellung** (`DirectHire/Index.php:createEmployeeWithContract`) ruft
denselben Dienst — ist damit abgedeckt, sobald der Hook in a) **nach** der
Vertragsanlage laeuft. Die Reihenfolge dort ist nachzusehen und mit einem Test
festzunageln.

**c) Neuausstellung** (`ReissueContractService`): der neue Vertrag **kopiert**
`rec_employee_id` vom alten. Heute kopiert er nur `rec_applicant_id` (Zeile 250) —
ohne diese Ergaenzung verloere jeder neu ausgestellte Vertrag seine Anstellung.

**d) Vertrag fuer einen Bewerber, der schon Mitarbeiter ist** (Applicant/Show,
SendContractsService, CreateContractTool — z. B. ein Zusatzvertrag fuer einen
Bestandsmitarbeiter): **die Anstellung des Bewerbers, deren `company` gleich der
`company` der Vorlage ist; keine solche → NULL; mehrere solche → die mit der
kleinsten Kennung, und ein Log-Eintrag.** Heute heisst das fuer jede Vorlage „die
RG-Anstellung"; sobald es eine MA-Vorlage gibt, heisst es fuer die „die
MA-Anstellung" — ohne dass an dieser Stelle noch etwas geaendert wird. Nicht
`$applicant->employee()` benutzen (hasOne, mehrdeutig), sondern
`employees()->where('company', $template->company)`. Diese Auswahl gehoert an **eine**
Stelle (`RecContractTemplate::anstellungFuer(RecApplicant)` oder ein kleiner Dienst),
die alle Anlagepfade rufen — nicht viermal ausgeschrieben.

**e) Die zweite Anstellung aus ZAS** bekommt nichts. Kein Code noetig — nur ein Test,
der es festhaelt (§3.7, Fall 3). Stufe 2 wird hier ansetzen: ein MA-Vertrag, der am
Bewerber wartet, weil die MA-Anstellung noch nicht da war, muesste beim Eintreffen der
MA-Anstellung aus ZAS nachgezogen werden. Nicht in diesem Paket; mit der Regel aus d)
ist es dann ein Aufruf im Inbound.

### 3.4 Anzeige

`Employees/Show.php:335` und `EmployeePortal.php:651` lesen kuenftig
`$employee->contracts` statt `$employee->applicant->contracts`. Der `applicantToken`
fuer die Vertragslinks kommt weiterhin vom Bewerber (der Link gehoert dem Menschen,
nicht der Anstellung) — nur die **Menge** der Vertraege kommt von der Anstellung.

In der MA-Akte traegt jede Vertragszeile kuenftig die Vorlagen-Merkmale als Text:
„Arbeitsvertrag RheinGedeck · Eventmitarbeiter". Firma ueber eine kleine Beschriftung
(`RG` → RheinGedeck, `MA` → der zweite GmbH-Name aus derselben Quelle wie der
ZAS-Export), Taetigkeit als Label, leer bleibt leer. Nur Anzeige, keine Logik; sie
macht sichtbar, was Schritt 0 des Backfills gesetzt hat.

**Kein Uebergangs-Rueckfall** auf den Bewerber. Ein Rueckfall wuerde bei den
Zwei-Anstellungs-Faellen exakt den Fehler wieder einfuehren, den dieses Paket
beseitigt. Stattdessen garantiert die Deploy-Reihenfolge (§3.6), dass die Anzeige
nie ohne gefuellten Anker laeuft.

### 3.5 Backfill-Kommando `recruiting:vertraege-an-anstellung`

Trockenlauf-faehig (`--dry-run`), idempotent (nur `rec_employee_id IS NULL`),
observer-frei (Query Builder), **nie** eine Eloquent-Schreibung auf `rec_employees`
(sonst ZAS-Aenderungsmarker, Vorfall 02.09.).

**Schritt 0 — Vorlagen typisieren.** Alle `rec_contract_templates` mit `code LIKE
'AV-%'` und `taetigkeit IS NULL` bekommen `taetigkeit = 'eventmitarbeiter'`.
`company` steht durch den Spalten-Default schon auf `RG`. Der Bericht nennt je Vorlage
Code und die beiden Werte, damit man es einmal mit Augen sieht. Idempotent.

**Schritt 1 — Vertraege anhaengen.** Regel je Vertrag mit leerem Anker, ueber
`rec_applicant_id → rec_employees`, mit `$firma = contractTemplate.company`:

| Anstellungen des Bewerbers | Ergebnis | Zaehler |
|---|---|---|
| mindestens eine mit `company = $firma` | diese Anstellung (bei mehreren: kleinste Kennung, melden) | `zugeordnet` |
| keine mit `$firma`, eine oder mehrere andere | **NULL lassen**, melden | `firma_fehlt` |
| keine Anstellung | NULL lassen | `ohne_anstellung` |

Das ist dieselbe Regel wie 3.3 d) — **derselbe Code**, nicht eine zweite Fassung.
Heute ist `$firma` fuer jede Vorlage `RG`; die Tabelle liest sich also wie vorher
(„RG-Anstellung oder nichts").

Warum `firma_fehlt` **nicht** an die einzige anders-firmige Anstellung gehaengt wird:
ein RG-Vertrag an der MA-Akte waere genau der Fehler, den dieses Paket beseitigt — nur
in kleiner. Lieber bleibt er beim Bewerber sichtbar und der Bericht nennt den Fall; das
ist dann Handarbeit mit Blick auf den Menschen.

Die 33 ohne Anstellung bleiben beim Bewerber sichtbar (`Applicant/Show` liest dort) und
tauchen in keiner Mitarbeiterakte auf — Entscheidung des Kunden 06.10.: „wenn sie nicht
von ZAS zu uns rueber gekommen sind, sind sie wohl kein Mitarbeiter."

Bericht: Kennungen, nie Namen. Rueckgabewert `FAILURE` nur bei Datenbankfehlern, nicht
bei `mehrdeutig` (das ist ein Befund, kein Fehlschlag).

### 3.6 Deploy-Reihenfolge — die Falle

Drei Dinge greifen ineinander und duerfen nicht einzeln passieren:

1. Die **Anzeige** liest die neue Spalte. Laeuft sie, bevor der Anker gefuellt ist,
   verschwinden die Vertraege aus **allen** Akten — auch den 290, bei denen heute
   alles stimmt.
2. Das **Vertrags-Backfill** braucht die Bewerber↔Mitarbeiter-Verknuepfung. Bei den
   66 unverlinkten findet es sonst keine Anstellung.
3. Das **Verknuepfungs-Backfill** (`report-signed-without-employee --backfill-links`)
   erzeugt den Anzeigefehler, solange die Anzeige noch ueber den Bewerber geht.

Deshalb in **einem** Wartungsfenster, in dieser Reihenfolge:

```
php artisan migrate --force
php artisan recruiting:report-signed-without-employee --backfill-links --skip-tests
php artisan recruiting:vertraege-an-anstellung --dry-run      # Zahlen ansehen
php artisan recruiting:vertraege-an-anstellung
php artisan view:clear
```

Zwischen Schritt 2 und 4 zeigen die Akten der 49 neu verlinkten Mitarbeiter kurz
keine Vertraege — Minuten, in denen niemand schaut. Der Fehler „RG-Vertrag in der
MA-Akte" tritt zu **keinem** Zeitpunkt auf, weil die Anzeige bereits umgestellt ist.

Kein `queue:restart` (kein Job). meingedeck-Bump nach dem Push.

### 3.7 Tests — die tragenden Zusicherungen

Jede mit einer Mutation, die sie roetet. Festes Testdatum in der **Vergangenheit**.

1. **MA-Anlage zieht alle Vertraege nach.** Bewerber mit AV + IFSG + AT, wird
   Mitarbeiter → alle drei tragen `rec_employee_id` der neuen Anstellung.
   Mutation: Hook entfernen → rot.
2. **Die MA-Akte zeigt NUR die Vertraege dieser Anstellung.** Ein Bewerber mit RG- und
   MA-Anstellung, RG-Vertrag am RG → die MA-Akte zeigt **keinen** Vertrag, die RG-Akte
   einen. Das ist der Fund selbst. Mutation: Anzeige zurueck auf
   `applicant->contracts` → rot. **Beide Richtungen** pruefen (RG sieht ihn, MA nicht)
   — eine Umfangs-Zusicherung nur in einer Richtung ist das Muster, an dem dieser
   Zweig schon zweimal gescheitert ist.
3. **Die ZAS-Anstellung bekommt nichts.** Zweite Anstellung ueber den Inbound → ihre
   `contracts()` ist leer. Mutation: Hook an der falschen Stelle (am Bewerber statt an
   der neuen Anstellung) → rot.
4. **Neuausstellung kopiert den Anker.** Mutation: Kopie entfernen → rot.
5. **Backfill folgt der Firma der Vorlage.** Bewerber mit RG+MA, Vertrag aus einer
   RG-Vorlage ohne Anker → RG. Mutation: `first()` ohne Firmen-Filter → rot
   (Testdaten so bauen, dass MA die kleinere Kennung hat, sonst ist die Mutation
   strukturell gruen). **Gegenprobe im selben Test:** Vertrag aus einer Vorlage mit
   `company = MA` → MA-Anstellung. Mutation: `'RG'` fest verdrahtet statt
   `$template->company` → rot. Ohne die Gegenprobe prueft der Test nur den
   Ist-Zustand und nicht die Regel.
6. **Backfill laesst `mehrdeutig` stehen** und meldet. Mutation: trotzdem setzen → rot.
7. **Backfill ist observer-frei.** Vor/nach `zas_changed_at` und `updated_at` an
   `rec_employees` identisch. Mutation: Eloquent-Save einsetzen → rot (den echten
   Observer im Testaufbau registrieren, sonst prueft der Test nichts — Vorflug).
8. **Portal zeigt nur eigene Vertraege.** Wie 2, aber am `EmployeePortal`.
9. **Vertrag fuer Bestandsmitarbeiter** (3.3 d): Bewerber mit RG-Anstellung bekommt
   einen Zusatzvertrag → Anker gesetzt; Bewerber ohne Anstellung → NULL.
10. **MA-Anlage haengt nur firmengleiche Vertraege an.** Bewerber mit einem Vertrag aus
    einer RG-Vorlage und einem aus einer MA-Vorlage, wird RG-Mitarbeiter → nur der
    RG-Vertrag traegt die Anstellung, der MA-Vertrag bleibt NULL. Mutation:
    Firmen-Filter im Hook entfernen → rot. (Die MA-Vorlage gibt es in prod noch nicht;
    der Test baut sie selbst — er sichert die Regel, nicht den Bestand.)
11. **Vorlagen-Firma ist nach erstem Gebrauch gesperrt.** Vorlage ohne Vertrag:
    `company` aenderbar; Vorlage mit einem Vertrag: Aenderung wird verweigert (Model-
    Hook, nicht nur Formular — die MCP-Tools `UpdateContractTemplateTool` schreiben am
    Formular vorbei). Mutation: Hook entfernen → rot.
12. **Schritt 0 des Backfills** setzt `eventmitarbeiter` an `AV-`-Vorlagen und laesst
    `AT-140` und `IFSG` leer. Mutation: Praefix-Filter entfernen → rot.

Nicht vergessen: die bestehenden Tests zu `Employees/Show` und `EmployeePortal`
bauen heute Vertraege nur am Bewerber. Sie muessen den Anker mitsetzen — **migrieren,
nicht loeschen**. Zusicherungen vorher/nachher zaehlen.

## 4. Nicht Teil dieses Pakets

- Die **Auswahlregel** (Anstellung + Taetigkeit → Vorlage) — Stufe 2 (§5). Firma und
  Taetigkeit an der Vorlage sind dagegen drin (§3.1).
- **Vertrag zuweisen aus der MA-Akte.** Heute kann `Employees/Show` nur neu ausstellen
  (`reissueContract`), nie einen Vertrag neu zuweisen — das kann allein
  `Applicant/Show::assignContract`. Ein MA-Vertrag fuer einen bestehenden Mitarbeiter
  braucht genau diesen Knopf in der Akte: Vorlagenauswahl **gefiltert auf die Firma
  dieser Anstellung**, Anker direkt diese Anstellung (keine Regel noetig, die
  Anstellung ist bekannt), `rec_applicant_id` vom verlinkten Bewerber. Dafuer muss
  `createSingleContract` aus `Applicant/Show` in einen Dienst, den beide Seiten rufen.
  Naechstes Paket nach Stufe 1, klein; es setzt den Anker und die Vorlagen-Firma
  voraus und sonst nichts. Bis dahin: MA-Vertraege gibt es nicht, also fehlt auch
  nichts.
- Eine **Laufzeit** am Vertrag als Spalte. Heute liegt das Vertragsende an drei Orten
  (Zusatzfeld am Vertrag, `contract_end_date` an den HR-Daten,
  `vertragsende_vorschlag` am Bewerber). Das bleibt so; es ist fuer den Anker nicht
  noetig und ein eigenes Thema (Vertragsbedarf, B2).
- Die **Vormerkung** umhaengen (§6).
- Das Verknuepfungs-Backfill selbst — es existiert und ist geprueft; es wird hier nur
  in die richtige Reihenfolge gebracht.

## 5. Ausblick Stufe 2 — Typisierung (nicht bauen, nur festhalten)

Der Kunde will weitere Vertragstypen (z. B. ein eigener Logistiker-Vertrag). Dazu
drei Befunde:

**Das Haus hat diese Lektion schon einmal gelernt.** Die alten Vorlagen `AV-010` bis
`AV-260` kodierten den **Zuschlag** im Code, mit dem Betrag fest im Text. Das wurde
aufgegeben: die heutige `AV-default` traegt `{{zuschlag}}` als Platzhalter, und
`ReissueContractService` verweigert bewusst die Arbeit an den alten Varianten, „statt
still ein Dokument mit dem alten Betrag auszustellen." Daraus die Regel fuer alles
Weitere: **anderer Rechtstext → eigene Vorlage; anderer Wert → Platzhalter, nie eine
Vorlage.**

**Eine eigene Vorlage je Vertragstyp kann das System heute schon, und seit Stufe 1
weiss sie auch, wofuer sie gilt** (`company`, `taetigkeit`, §3.1). Damit ist ein
Logistiker-Vertrag fuer MA in Stufe 2 **nur eine neue Vorlagenzeile**: `code
AV-MA-LOG`, `company MA`, `taetigkeit logistiker`, eigener Rechtstext. Anhaengen,
Anzeige, Backfill und Neuausstellung kennen ihn schon. Was in Stufe 2 fehlt, ist der
**Auswaehler**: die Regel, die aus Anstellung und Taetigkeit die passende Vorlage
bestimmt, statt dass HR sie von Hand waehlt — Markus' **B2** („Vertragsbedarf —
Regeln automatisch bestimmen"), Folie 7 und 8 des Canvas-Abgleichs. Dazu das
Nachziehen aus §3.3 e) (MA-Anstellung trifft ein, MA-Vertrag wartet am Bewerber) und
die Vormerkung (§6).

**Der Auswaehler hat keine Daten.** Am Mitarbeiter gibt es nur `employment_type`
(Aushilfe/Student/Schueler — eine rechtliche Kategorie), kein Feld fuer Beruf oder
Taetigkeit. ZAS sollte `Qualifikation` und `DispoTaetigkeiten` liefern; wir lesen
beide seit Juli, geliefert wurden sie in **keiner** von 579 Lieferungen. Entweder ZAS
liefert, oder HR pflegt es bei uns — und dann gehoert es ohnehin zu uns (Datenhoheit
nach Stichtag).

Stufe 2 setzt Stufe 1 voraus. Stufe 1 ist ohne Stufe 2 sinnvoll.

## 6. Folgefrage — die Vormerkung haengt am selben falschen Anker

`rec_contract_send_reservations` (06.10.) traegt `rec_applicant_id`, kein
`rec_employee_id`. Heute harmlos: die Vormerkung gilt dem Bewerber **vor** seiner
MA-Anlage, da gibt es nur eine kuenftige Anstellung (RG). Sie wird zur Frage, sobald
Stufe 2 einen Vertrag fuer die **zweite** Anstellung versendet — dann muss die
Vormerkung wissen, fuer welche. Nicht in diesem Paket umhaengen; in Stufe 2 mitziehen.

Beim Zusammenfuehren mit `feat/ma-konto` (06.10.) hat es genau hier gekracht:
`HrDeskRoutingService::approveCase()` stoesst die Vormerkung an, und die Wache gegen
Faelle **ohne** Bewerber (Einsatz-Trigger) muss **davor** stehen. Wer die Vormerkung
spaeter anfasst, trifft diese Stelle wieder.

## 6a. Nachzug in `feat/ma-konto` — eine Stelle, beim Merge faellig

Dieses Paket baut **allein auf main**. Alles, was es anfasst, liegt dort:
`CreateEmployeeFromApplicantService`, `ReissueContractService`, `Employees/Show`,
`EmployeePortal`, `Applicant/Show`, `report-signed-without-employee`. Nichts davon
wartet auf den Zweig.

Der Zweig hat aber **einen dritten Leser**: `PortalShell.php:585` (Portal v2) liest
`$employee->applicant->contracts` — woertlich aus `EmployeePortal::contracts()`
uebernommen. Wird der Zweig nach diesem Paket gemergt, muss diese Stelle genau wie
§3.4 auf `$employee->contracts` umgestellt werden, mit demselben Test wie Fall 8.
Sonst zeigt das neue Portal den Fehler, den das alte nicht mehr zeigt. Der Merge
faellt dort nicht als Konflikt auf (die Datei existiert auf main nicht) — es ist ein
**stiller** Nachzug, deshalb steht er hier.

## 7. Annahmen, auf denen das Design steht

1. **Alle vorhandenen Vertraege sind RG-Vertraege, alle `AV-`-Vorlagen sind
   Arbeitsvertraege fuer RheinGedeck als Eventmitarbeiter.** (Kunde, 05.10. und
   07.10.) Darauf stehen der Spalten-Default `company = RG` und Schritt 0 des
   Backfills. Faellt die Annahme fuer einzelne Vorlagen, werden deren Spalten **vor**
   Schritt 1 von Hand gesetzt — dann ordnet Schritt 1 schon richtig zu.
2. **Die zweite Anstellung entsteht nur ueber ZAS**, nie ueber den Funnel. Wuerde der
   Funnel kuenftig MA-Anstellungen anlegen, braeuchte 3.3 a) die Firma als Parameter.
3. **Der Vertragslink gehoert dem Menschen**, nicht der Anstellung — deshalb bleibt
   `applicantToken` am Bewerber. Wenn das Portal einmal je Anstellung getrennt wird,
   ist das neu zu bewerten.
