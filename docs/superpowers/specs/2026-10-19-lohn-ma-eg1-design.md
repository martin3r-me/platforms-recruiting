# Lohn MA: Entgeltgruppe 1 + Angleich-Zuschlag — Design

Stand 19.10.2026. Zweig `feat/ma-konto`. Gehört VOR den Merge (Abschnitt 1).

## 1. Warum, und warum jetzt

Der ZAS-Export (`ZasEmployeeFieldResolver`, Spalten `Grundlohn`/`Zuschlag`) kennt nur RG: Mindestlohn aus den Team-Einstellungen plus Zuschlag aus der Bewerbung. Für MA-Anstellungen (Lease, Tarif DGB/GVP) ist das falsch; seit 15.10. (5aa7112d) liefern MA-Zeilen beide Zellen leer, auf Prod liefern sie heute noch 13,90 + Bewerbungs-Zuschlag.

Markus, Kommentar E14 (15.10.): „Der Lohn aus EG 1 (Grundlohn) muss immer mit einem Zuschlag hinterlegt werden, dass der Mitarbeiter mindestens den identischen Gesamtlohn erhält. Wenn der Mitarbeiter in der RG Steuerklasse 1–5 hat, in der MA dann die 6 bekommt, bekommt er € 1,- mehr in der MA immer automatisch. **Da bitte den Export sichern vor dem Start**, sonst sind die Daten in ZAS für den Lohn fehlerhaft." Kommentar E15 (16.10.): „ja, es gilt für alle Mitarbeiter EG 1. Nur MA immer EG 1 ohne Zuschlag." Lesart (Sebastian 19.10.): „nur MA" = Person ausschließlich bei MA angestellt, dann gibt es keinen RG-Lohn zum Angleichen.

Mit dem Merge von `feat/ma-konto` setzen Deploy-Schritte Export-Marker (Personen anlegen, Je-Gesellschaft-Regeln); MA-Zeilen gehen danach in Wellen an ZAS und landen im Lohn. Deshalb muss die MA-Lohnlogik im selben Deploy live sein.

Tarif: `251112_GVP-Basistarifwerk.pdf` (DGB/GVP, Stand Januar 2026), Anlage zum Entgelttarifvertrag, Entgeltgruppe 1.

## 2. Begriffe

- **RG** / **MA**: `rec_employees.company` (`'RG'`/`'MA'`, leer = RG; Normalisierung wie `ZasEmployeeFieldResolver::isMaAnstellung`).
- **EG 1**: Tarif-Stundensatz Entgeltgruppe 1, datiert (Abschnitt 4).
- **RG-Zuschlag**: Zuschlag der Person bei RG. Quellen in dieser Reihenfolge: (1) Bewerbung — `rec_applicants.zuschlag`, Fallback AV-Code (`ZuschlagWert::ausAvCode`), leer = 0,00 (Bewerbung vorhanden = bekannt); (2) **ZAS** — `rec_employee_hr_data.zas_zuschlag` der RG-Anstellung, aber nur wenn `zas_grundlohn` > 0 (Grundlohn 0 heißt bei ZAS „Zeile nicht gepflegt", der Zuschlag daneben ist dann keine Angabe; Befund CSV 19.10.: 125 solcher Zeilen); (3) manuell — `rec_employees.zuschlag_manuell`. Die ZAS-Werte liest der Inbound seit main fff2ad69 (19.10.) bei jeder Lieferung mit, nur lesend.
- **RG-Gesamtlohn** = `minimum_wage_hourly` (Team-Einstellung, 13,90) + RG-Zuschlag.
- **RG-Schwester** einer MA-Anstellung: die aktive RG-Anstellung derselben Person (Abschnitt 6).

## 3. Die Regel

Für jede MA-Anstellung beim Export:

| Fall | Lage | Grundlohn | Zuschlag |
|---|---|---|---|
| 1 `angleich` | RG-Schwester mit Bewerbung | EG 1 | max(0, RG-Gesamtlohn − EG 1) + Klasse-6-Euro |
| 1b `zas` | RG-Schwester ohne Bewerbung, ZAS-Zuschlag geliefert (`zas_grundlohn` > 0) | EG 1 | wie Fall 1, RG-Zuschlag = `zas_zuschlag` |
| 2 `unbekannt` | RG-Schwester ohne Bewerbung, kein ZAS-Wert, `zuschlag_manuell` leer | EG 1 | **leer** |
| 3 `manuell` | RG-Schwester ohne Bewerbung, kein ZAS-Wert, `zuschlag_manuell` gesetzt | EG 1 | wie Fall 1, RG-Zuschlag = `zuschlag_manuell` |
| 4 `nur_ma` | keine aktive RG-Schwester | EG 1 | 0,00 + Klasse-6-Euro |

- **Klasse-6-Euro**: +1,00 € wenn `tax_class` der MA-Anstellung `'6'` ist. `tax_class` leer → kein Euro (nichts raten; die Je-Gesellschaft-Regel setzt die 6 ohnehin, sobald die RG-Seite Haupt ist).
- Rundung auf 2 Nachkommastellen, Format wie heute `number_format(…, 2, ',', '.')`.
- Fall 4 schickt ausdrücklich `0,00`, nicht leer: ein bekannter Wert ist kein unbekannter.
- Fall 2 ist der **einzige** Fall mit leerer Zelle. Ob ZAS leer als „nicht anfassen" liest, ist offen (Olaf-Frage seit 15.10.). Leer ist nicht schlechter als der heutige Stand des Zweigs (alle MA-Zeilen leer).
- RG-Zeilen: Grundlohn unverändert (Mindestlohn). Zuschlag: Bewerbung wie heute; ohne Bewerbung dieselbe Kette wie oben — `zas_zuschlag` (bei `zas_grundlohn` > 0), sonst `zuschlag_manuell`, sonst leer. Der ZAS-Wert geht damit als derselbe Wert zurück, den ZAS schon hat; die leere Zelle beim RG-Bestand verschwindet in fast allen Fällen von selbst. Eine Wahrheit für beide Zeilen der Person.

**Begründung** (Text für Akte, Kachel, Vorflug-CSV), aus Teilen gebaut, z. B.
`EG 1 15,33 € · Angleich an RG 15,00 € (13,90 + 1,10) → 0,00 € · Steuerklasse 6 +1,00 € · Zuschlag 1,00 €`
bzw. `EG 1 15,33 € · RG-Zuschlag unbekannt (keine Bewerbung, nichts eingetragen) · Zuschlag leer`
bzw. `EG 1 15,33 € · nur MA, kein Angleich · Zuschlag 0,00 €`.

## 4. Entgeltgruppe 1, datiert

`src/Support/EntgeltgruppeEins.php` (reine Klasse, keine DB):

| gilt ab | Satz |
|---|---|
| 2026-01-01 | 14,96 |
| 2026-09-01 | 15,33 |
| 2027-04-01 | 15,87 |

- `satz(string $datum): float` — die Stufe mit dem größten `ab <= datum`; Datum vor der ersten Stufe → erste Stufe (es gibt keinen früheren Tarif, und nichts darf leer bleiben).
- `stufen(): list<array{ab:string, satz:float}>` für Anzeige/Checkliste.
- Export rechnet mit `now()->toDateString()` (Exportzeitpunkt = Lohnzeitpunkt).
- Kein Settings-Wert, keine DB: der Tarif ist öffentlich und datiert; ein frei editierbarer Wert ohne Datum stünde im April 2027 falsch da.
- Nächste Stufe 01.04.2027: alle MA-Zeilen müssen neu raus (Abschnitt 8, Kommando). Steht als datierter Punkt in der Checkliste.

## 5. Manuelles Feld `zuschlag_manuell`

- Migration (additiv, `down`): `rec_employees.zuschlag_manuell` decimal(5,2) nullable, nach `tax_class`. Model: fillable + cast `decimal:2`.
- Bedeutung: RG-Zuschlag der Person, von HR eingetragen, **nur** wirksam an einer RG-Anstellung **ohne** Bewerbung und **ohne** brauchbaren ZAS-Wert. Hat die RG-Anstellung eine Bewerbung, gewinnt die Bewerbung; liefert ZAS einen Wert (Grundlohn > 0), gewinnt ZAS (eine Wahrheit; das Feld wird nur gezeigt, wenn beides fehlt).
- Akte (RG-Anstellung ohne `rec_applicant_id`): Feld „RG-Zuschlag laut ZAS (€/h)" in der Gruppe „ZAS / Abrechnung (HR-only)". Eingabe wie überall beim Zuschlag als Text („1,10"); `saveAll()` wandelt mit `ZuschlagWert::ausEingabe`, ungültig → `flashError` „RG-Zuschlag: bitte einen Betrag wie 1,10 eintragen", nichts gespeichert (Muster `FirstAiderDateGuard`). Leeren = `null`.
- Später, wenn Olaf `Zuschlag` in der Lieferung mitgibt, füllt der Inbound dasselbe Feld. (Nicht Teil dieser Spec.)

## 6. RG-Schwester finden

`src/Services/LohnMaErmittlung.php` (Lese-Service mit Query Builder):

1. `rec_person_id` gesetzt → aktive (`is_active = 1`) Anstellungen derselben Person mit `company = 'RG'` oder leer, außer der MA-Anstellung selbst; bei mehreren die jüngste (`id` max).
2. sonst `person_key` → gleiche Suche über `PersonScopeResolver::forEmployee()`-IDs (nur `ids`, nicht `abweichend`).
3. nichts → Fall 4.

Eine **inaktive** RG-Anstellung zählt nicht: Wer bei RG ausgeschieden ist, ist „nur MA".

`ermitteln(RecEmployee $ma, ?string $datum = null): LohnMa` liefert das Ergebnis; `LohnMa` (reine Wertklasse in `src/Support/LohnMa.php`) rechnet aus `(eg1, mindestlohn, hatRg, rgZuschlag|null, quelle ∈ bewerbung|zas|manuell|null, steuerklasse|null)` die Tabelle aus Abschnitt 3 und baut die Begründung. `LohnMa::fall` ∈ `angleich|zas|unbekannt|manuell|nur_ma`; `quelle` bestimmt den Fall bei bekanntem RG-Zuschlag. Die RG-Schwester wird mit ihren `hr_data`-Werten `zas_grundlohn`/`zas_zuschlag` gelesen (Left Join). Kette: Bewerbung (`rec_applicants.zuschlag`, sonst AV-Code, sonst 0,00) → ZAS (`zas_zuschlag`, nur wenn `zas_grundlohn` > 0) → manuell → unbekannt.

## 7. Export

`ZasEmployeeFieldResolver::getGrundlohn/getZuschlag`: MA-Zweig ruft `LohnMaErmittlung` (einmal je Zeile, Ergebnis am Resolver je Employee-ID gemerkt). RG-Zweig ohne Bewerbung: dieselbe Kette über `LohnMaErmittlung::rgZuschlag()` — **`zas_zuschlag`** (bei `zas_grundlohn` > 0) → **`zuschlag_manuell`** → leer; mit Bewerbung wie heute. Spaltenzahl und -reihenfolge unverändert (`test_lohnspalten_bleiben_an_ihrem_platz` bleibt).

## 8. Neu exportieren, wenn sich die Grundlage ändert

Heute markiert nichts eine Lohnänderung an der Bewerbung für den Mitarbeiter-Export. Neu:

- **`rec_applicants.zuschlag` geändert** (`RecApplicant::saved`, `getChanges()` enthält `zuschlag`): Marker auf alle `rec_employees` mit dieser `rec_applicant_id` **und** auf alle aktiven MA-Schwestern dieser Personen. Ort: `RecEmployeeExportObserver` (dort liegen schon die Fremd-Listener `RecContract::saved`, `RecPosition::updated`).
- **`zuschlag_manuell` geändert**: in `RELEVANT_EMPLOYEE_FIELDS` (markiert die RG-Zeile) **plus** Marker auf die aktiven MA-Schwestern. Umsetzung: eigener Listener-Block im Observer, gleicher Schwester-Lookup wie Abschnitt 6 (über `rec_person_id`, sonst `person_key`).
- **ZAS liefert einen anderen `zas_zuschlag`/`zas_grundlohn` für eine RG-Zeile** (Inbound, `RecEmployeeHrData::saved` mit diesen Feldern in `getChanges()`): Marker auf die aktiven MA-Schwestern — der MA-Zuschlag rechnet daraus. Die RG-Zeile selbst bleibt ohne Marker (Inbound ist kein Echo; der Importer stellt ihren Marker ohnehin wieder her).
- **`tax_class`** ist schon relevant; die Je-Gesellschaft-Regel markiert die Schwester.
- **Tarifstufe / Mindestlohn-Änderung**: Kommando `recruiting:lohn-ma-export` (Abschnitt 9) mit `--markieren`.
- Marker immer per Query Builder (`zas_changed_at = now()`), wie `markEmployeeId`.

## 9. Kommando `recruiting:lohn-ma-export` (Vorflug + Marker)

```
php artisan recruiting:lohn-ma-export                 # Bericht + CSV, schreibt keine Marker
php artisan recruiting:lohn-ma-export --markieren     # zusätzlich zas_changed_at auf alle aktiven MA-Zeilen
php artisan recruiting:lohn-ma-export --team=1 --datum=2027-04-01
```

- Läuft über alle aktiven MA-Anstellungen (`company = 'MA'`, `is_active = 1`), optional je Team, Stichtag für EG 1 wählbar (Default heute).
- Ausgabe Konsole: Zahlen je Fall (`angleich`, `zas`, `manuell`, `unbekannt`, `nur_ma`), Summe, davon Steuerklasse 6, Vergleich mit dem ZAS-Stand der MA-Zeile (`gleich` / `abweichend` / `zas_leer`), Pfad der CSV.
- CSV nach `storage/app/recruiting/lohn-ma-<datum>-<zeit>.csv`: `employee_id, personnel_number, name, person_id, rg_employee_id, fall, grundlohn, zuschlag, steuerklasse, zas_grundlohn, zas_zuschlag, vergleich, begruendung`. `vergleich`: `gleich`, wenn unser Grundlohn und Zuschlag den ZAS-Werten der MA-Zeile entsprechen; `zas_leer`, wenn ZAS nichts oder Grundlohn 0 liefert; sonst `abweichend`. Nur Zahl für die Ankündigung, keine Abarbeitungsliste (Sebastian 19.10.: veraltete ZAS-Stände interessieren nicht, die Rechnung muss stimmen). Personenbezogen → bleibt auf dem Server, nie in Chat/Mail.
- `--markieren`: setzt `zas_changed_at` auf alle Zeilen des Laufs (Query Builder, Zähler vorher/nachher ausgegeben). Vorher Olaf ankündigen (Abschnitt 12).
- Gehört in den Zeitplan **nicht** (bewusst manuell, datiert in der Checkliste: 01.04.2027 Tarifstufe; 01.01.2027 Mindestlohn 14,60, falls die Einstellung geändert wird).

## 10. HR-Schreibtisch, Reiter „Mitarbeiter", Unterliste „Lohn"

- `Mitarbeiter::LISTEN` + `'lohn' => 'Lohn: RG-Zuschlag fehlt'`, `#[Computed] lohn()`, `grundZahlen()['lohn']`, Blade-Block `@if ($liste === 'lohn')` nach dem Muster „Erinnert".
- `HrMitarbeiterListen::lohn(int $teamId, string $suche = ''): array` — eine Zeile je Person (Muster `menschen()`): alle aktiven MA-Anstellungen des Teams, deren Ermittlung Fall 2 ergibt. Seit dem ZAS-Inbound ist das selten (nur Personen, deren RG-Zeile bei ZAS Grundlohn 0 hat oder noch nie geliefert wurde); die Liste bleibt als Netz. Zeile: `schluessel, person_id, employee_id, name, akten, employee_ids, rg_employee_id, ma_employee_id, begruendung`.
- Zeile zeigt Name, Akten-Chips (Partial `mensch`), Text „RG-Zuschlag unbekannt, MA bekommt bis dahin keinen Zuschlag", ein Eingabefeld (Text, „1,10") und „Speichern". Speichern: `speichereZuschlag(mixed $rgEmployeeId, mixed $wert)` → Team-Prüfung, `ZuschlagWert::ausEingabe`, ungültig → Meldung, sonst Eloquent-`update(['zuschlag_manuell' => …])` an der RG-Akte (Observer setzt Marker, Abschnitt 8), `zaehlerVergessen`, `EVENT_GEAENDERT`, Meldung „Gespeichert: 1,10 € — RG- und MA-Zeile gehen an ZAS".
- Zähler: nur im Unter-Reiter, **nicht** im roten Abzeichen des Hauptreiters. Begründung: Nach dem Start steht dort der gesamte ZAS-Bestand mit RG+MA ohne Bewerbung; ein dauerhaft großes rotes Abzeichen verdeckt die Anträge und Sperren, die täglich zählen.
- Suche nach Name wie in den anderen Listen.

## 11. Akte der MA-Anstellung: Block „Lohn an ZAS"

Über den Feldgruppen, unter dem Konto-Kasten, nur bei `company = 'MA'`: Grundlohn, Zuschlag (oder „leer"), Begründung, Stand „EG 1 gilt seit 01.09.2026". Bei Fall 2 Link „RG-Zuschlag in der RG-Akte eintragen" auf die RG-Schwester (Anker auf die Gruppe). Kein eigener Livewire-Kasten: `#[Computed] lohnMa()` in `Employees\Show` + Partial `_lohn-ma.blade.php`. Nur lesen.

## 12. Deploy, Ankündigung, Checkliste

- Migration läuft mit 4.3 (Zahl 23 → 24, `migrate:status`-grep, Tabelle 4.3).
- **2.4 Olaf**: Ankündigungstext ersetzen: „MA-Zeilen liefern ab dem Start Grundlohn = Tarif EG 1 (15,33) und einen berechneten Zuschlag; Zuschlag bleibt leer, wo wir den RG-Lohn nicht kennen (Zahl aus dem Vorflug). Keine neue Spalte." Dazu die drei Fragen: Prüfregeln HR-Controlling, Grundlohn/Zuschlag RG in der Lieferung zu uns, leere Zelle.
- **4.4 neuer Schritt** vor dem Einschalten der Einsatz-Prüfung: `recruiting:lohn-ma-export` (ohne `--markieren`) → Zahlen lesen, CSV gegen Olafs Stand legen → Ankündigung → `--markieren` erst, wenn Olaf die Werte kennt. Hinweis: Marker-Zähler aus dem Vorspann von 4.4 bewegt sich hier bewusst.
- **6. Nach dem Deploy**: Sichttest Akte-Block, Unterliste „Lohn", Eintrag speichern, Marker an RG- und MA-Zeile prüfen; datierte Punkte 01.01.2027 / 01.04.2027.
- Memory `project_lohn_ma_eg1_paket` nachführen.

## 13. Tests

- Unit `EntgeltgruppeEinsTest`: drei Stufen, Grenztage (31.08. → 14,96, 01.09. → 15,33), Datum vor 2026 → 14,96.
- Unit `LohnMaTest`: je Fall aus Abschnitt 3, Klasse-6-Euro, Rundung (13,90 + 1,436 − 15,33), Begründungstexte. Mutationsprobe: `max(0, …)` entfernen → rot (Fall RG-Lohn unter EG 1 würde negativ).
- Integration `ZasLohnspaltenMaAnstellungTest` (umbenannt in `ZasLohnspaltenTest`): Fälle 1, 1b, 2, 3, 4 über den Resolver mit Person (`rec_person_id`), ohne Person (`person_key`), inaktive RG-Schwester, ZAS-Wert mit Grundlohn 0 zählt nicht, Bewerbung schlägt ZAS, ZAS schlägt manuell, RG-Zeile ohne Bewerbung liefert ZAS- bzw. manuellen Wert, Spaltenplatz unverändert.
- Integration `LohnMaReExportTest`: Bewerbungs-Zuschlag ändern → RG + MA markiert; `zuschlag_manuell` ändern → RG + MA markiert; `zas_zuschlag` an der RG-`hr_data` ändern → nur MA markiert; fremde Person nicht markiert. Mutationsprobe: Schwester-Lookup entfernen → rot.
- Integration `HrMitarbeiterListenLohnTest`: nur Fall 2 in der Liste, Team-Grenze, Suche; `speichereZuschlag` mit ungültigem Wert speichert nichts, mit gültigem Wert verschwindet die Zeile.
- Integration `LohnMaExportCommandTest`: Zahlen je Fall, CSV-Zeilen, `--markieren` setzt genau die MA-Zeilen des Laufs.
- `DeployChecklisteInfosTest` (Migrationsliste) mitziehen.
- Blade: `php tools/blade-check.php` auf jede geänderte Blade, Hausregeln.

## 14. Nicht in dieser Spec

MA-Vertragstext und Vertrags-Automatik (E10/E13), Zahlungsstatus (Folie 14), Tausch Haupt/Neben nach Stunden, Stichproben, Erfahrungs-/Branchen-/Nacht-/Feiertagszuschläge des Tarifs (Lohnbüro). Der Inbound für `Grundlohn`/`Zuschlag` ist auf main gebaut (fff2ad69) und wird hier nur gelesen.

## 15. Offen / Risiken

- „Nur MA ohne Zuschlag" als „keine RG-Anstellung" gelesen. Markus bekommt die Lesart als Feststellung; widerspricht er, ändert sich nur `LohnMa` (eine Klasse, ein Test).
- Leere Zelle bei ZAS (Fall 2) — Olaf; durch den ZAS-Inbound nur noch für Zeilen relevant, die ZAS selbst nicht gepflegt hat (Grundlohn 0) oder nie geliefert wurden.
- **Befund CSV 19.10. (437 Personen mit RG+MA):** ZAS rechnet heute schon „Grundlohn EG 1, Zuschlag = RG-Gesamt − EG 1" (248 von 317 gepflegten Zeilen passen, Rest veraltete Stände); +1 € nur bei 79 Zeilen — die Steuerklasse an der MA-Zeile muss gepflegt sein, sonst geht der Zuschlag ohne Euro raus (sichtbar in der Begründung). Veraltete ZAS-Stände (14,96 / 14,53 / Grundlohn 0) werden durch den Export korrigiert; Vorflug liefert die Zahl für die Ankündigung.
- `tax_class` leer an der MA-Zeile → kein Euro. Sichtbar in der Begründung („Steuerklasse unbekannt").
