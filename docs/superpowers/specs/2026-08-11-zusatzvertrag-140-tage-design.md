# Zusatzvertrag „Erklärung 140-Tage-Tätigkeit" — Design

**Datum:** 2026-08-11
**Modul:** platforms-recruiting
**Quelle:** `/Users/shaustein/Documents/dev/docs/zusatzvertrag/RG_Erklärung 120-Tage-Tätigkeit.docx`

## Ziel

Die Erklärung zur Aufnahme einer 140-Tage-Tätigkeit soll auf dem HR-Schreibtisch
als Zusatzvertrag auswählbar sein, personalisiert im Bewerber-Portal erscheinen
und dort elektronisch unterschrieben werden können.

## Ausgangslage

Die Zusatzvertrags-Funktion ist vollständig gebaut, aber nie befüllt worden:

* `RecApplicantLegalStatus.additional_contract_template_id` hält die Zuweisung.
* Der HR-Schreibtisch zeigt im Dropdown alle aktiven Vorlagen mit
  `code LIKE 'AT-%'` (`src/Livewire/HrDesk/Index.php:208-218`), validiert die
  Auswahl gegen dasselbe Kriterium (`:250-280`).
* `SendContractsService` legt den zugewiesenen Zusatzvertrag beim Vertragsversand
  automatisch an, personalisiert ihn und stempelt ihn als versendet
  (`src/Services/SendContractsService.php:137-200`).
* Mitarbeiter-Portal und MA-Akte labeln `AT-*` als „Zusatzvereinbarung".

Im Team RHEINGEDECK-HR (ID 3) existiert **keine** Vorlage mit `AT-`-Code — das
Dropdown ist heute leer. Diese Erklärung wird die erste.

**Inhaltliche Klärung:** Der Dateiname sagt „120-Tage", der Dokumenttext
durchgehend „140 Tage nach §9 Nr. 9 ArGV". Entschieden: **140**, Text wird
wörtlich übernommen.

## Was gebaut wird

### 1. Vertragsvorlage `AT-140`

Ein Datensatz in `rec_contract_templates`, Team 3, direkt über die Platform
angelegt — **nicht** als Seeder/Command im Repo. Begründung: Der Text soll im
Vorlagen-Editor pflegbar sein; eine Kopie im Code würde auseinanderlaufen,
sobald HR etwas ändert. Das entspricht dem Umgang mit allen bestehenden Vorlagen.

| Feld | Wert |
|---|---|
| `code` | `AT-140` |
| `name` | `Erklärung 140-Tage-Tätigkeit` |
| `is_active` | `true` |
| `requires_signature` | `true` |
| `sort_order` | `0` |

Der `name` ist **bewerbersichtbar**: Im Bewerber-Portal fallen `AT-*`-Verträge in
den Standardzweig und werden mit dem Vorlagennamen gelistet
(`src/Livewire/Public/ApplicantPortal.php:56-61`) — anders als im MA-Portal, wo
sie „Zusatzvereinbarung" heißen. Deshalb ein schlichter, verständlicher Name.

Der Inhalt ist das Word-Dokument als HTML nachgebaut, im Stil der bestehenden
Vorlagen: Überschrift, Personenblock, 140-Tage-Absatz, Verpflichtungs- und
Haftungsklausel, Datum/Ort/Unterschrift, Anlagenliste. Wortlaut unverändert.

### 2. Feld-Mappings

| Platzhalter | Quelle |
|---|---|
| `{{kontakt_vorname}}` | `contact.first_name` |
| `{{kontakt_nachname}}` | `contact.last_name` |
| `{{kontakt_geburtsdatum}}` | `contact.birth_date` |
| `{{kontakt_geburtsort}}` | `applicant.extra_field.geburtsort` |
| `{{nationalitaet}}` | `applicant.extra_field.nationalitaet` |
| `{{pass_nr}}` | `applicant.extra_field.ausweisnummer` |
| `{{kontakt_strasse}}` | `contact.address.street` |
| `{{kontakt_hausnr}}` | `contact.address.house_number` |
| `{{kontakt_plz}}` | `contact.address.postal_code` |
| `{{kontakt_ort}}` | `contact.address.city` |
| `{{datum_heute}}` | `meta.datum_heute` |
| `{{resttage}}` | **bewusst nicht gemappt** — siehe 4. |

Ein separates Passnummer-Feld existiert nicht; bei Nicht-EU-Bewerbern ist
`ausweisnummer` das Passdokument-Feld.

### 3. Lookup-Labels im Vorlagen-Renderer

`RecContractTemplate::personalizeContent()` gibt bei Lookup-Feldern heute den
gespeicherten Rohwert aus — `tr` statt `Türkei`. Für `{{nationalitaet}}` ist das
unbrauchbar.

Fix: In `resolveSource()` wird beim Zweig `applicant.extra_field.*` geprüft, ob
die Feld-Definition ein Lookup ist; wenn ja, wird das Label aufgelöst. Die
Auflösungslogik existiert bereits als `Services\Zas\ZasLookupResolver` (liest
`core_lookup_values`, cached pro Definition) und wird wiederverwendet.

*Risiko:* Die Änderung greift zentral für alle Vertragsvorlagen. Geprüft: Kein
bestehendes Mapping zeigt auf ein Lookup-Feld (alle 10 Vorlagen im Team gemappt
auf `contact.*`, `geburtsort`, `settings.*`, `contract.extra_field.*`). Das
Verhalten für Nicht-Lookups bleibt unverändert und wird durch Test abgesichert.

### 4. Resttage-Abfrage beim Signieren

Die Lücke „im laufenden Kalenderjahr noch ___ Tage zur Verfügung" hat keine
Datenquelle — die Zahl hängt an Beschäftigungen bei anderen Arbeitgebern.

**Entscheidung: Der Bewerber trägt sie beim Unterschreiben ein.** Das Dokument
ist seiner Natur nach eine Selbstauskunft („Hiermit erklärt Herr/Frau … dass
er/sie …"), und die Haftungsklausel darunter trägt nur, wenn die Zahl vom
Beschäftigten selbst stammt. HR könnte sie ohnehin nur erfragen.

Umsetzung in `src/Livewire/Public/ContractSigning.php`: Der bestehende
Vorschalt-Schritt ist heute hart auf `AV-`-Codes verdrahtet (`:69-72`). Er wird
um einen zweiten Typ ergänzt, ausgelöst über eine Code-Konstante
(`RESTTAGE_CODES = ['AT-140']`) statt eines weiteren `if` — eine künftige
Variante ist damit eine Zeile.

Der Schritt zeigt:

* Pflicht-Zahlenfeld „Wie viele der 140 genehmigungsfreien Tage stehen dir in
  diesem Kalenderjahr noch zur Verfügung?", Bereich 0–140, vorbelegt mit 140
* Hinweis, dass ggf. eine Bescheinigung über bereits gearbeitete Tage
  nachzureichen ist
* Duz-/Siez-Variante analog zum bestehenden Flow (`$duzen`)

Die Zahl ersetzt `{{resttage}}` im angezeigten Inhalt, **bevor** das Dokument in
Schritt 2 gerendert wird — der Bewerber unterschreibt sichtbar das fertige
Papier. Beim Speichern wird die ersetzte Fassung in `personalized_content`
persistiert und die Zahl zusätzlich strukturiert in `pre_signing_data` abgelegt
(analog `par15_entries`), damit sie auswertbar bleibt.

Der Platzhalter überlebt die Personalisierung, weil `personalizeContent()` nur
über die gemappten Schlüssel ersetzt — ein nicht gemappter Platzhalter bleibt
unverändert im Inhalt stehen.

## Ablauf

1. HR sieht den Nicht-EU-Fall auf dem HR-Schreibtisch und wählt im
   Zusatzvertrag-Dropdown „AT-140 — Erklärung 140-Tage-Tätigkeit".
2. Beim Vertragsversand legt `SendContractsService` die Erklärung neben
   Arbeitsvertrag und IFSG an, personalisiert sie und stempelt sie als versendet.
3. Der Bewerber erhält die WhatsApp-Portal-Benachrichtigung (ein Link, keine
   Dokumente im Anhang).
4. Im Portal liegen die Verträge als Liste. Er tippt die Erklärung an,
   beantwortet die Resttage-Frage, sieht das fertige Dokument, unterschreibt.
5. Das signierte PDF liegt im Portal und in der MA-Akte („Zusatzvereinbarung").

An dieser Kette wird nichts geändert.

## Verhalten, das aus dem Bestand folgt

* **Opt-in.** Ohne Auswahl im Dropdown passiert nichts. Es gibt keine Automatik,
  die aus „Nicht-EU-Bürger" die Erklärung ableitet — das ist eine HR-Entscheidung
  am konkreten Aufenthaltstitel.
* **Zuweisung vor Versand.** Wird sie nachgeholt, holt ein erneuter Versand die
  Erklärung nach; Arbeitsvertrag und IFSG werden dabei wiederverwendet, nicht
  dupliziert (`SendContractsService`, idempotent über Template-ID).
* **Entfernen wirkt nicht rückwirkend.** Ein bereits erzeugter Vertrag bleibt
  bestehen und muss bei Bedarf in der Vertragsliste storniert werden.
* **Portal-Sichtbarkeit hängt nicht am Versand.** Das Bewerber-Portal listet alle
  Verträge außer `cancelled` — ein Vertrag ist ab Anlage sichtbar und signierbar.

## Tests

Reines PHPUnit ohne Laravel/DB (Modul-Konvention). Damit das trägt, werden zwei
Entscheidungen aus ihrem DB-Kontext herausgeschnitten:

* **Welcher Vorschalt-Schritt gilt für einen Vertragscode?** Als reine statische
  Funktion `preSigningTypeForCode(?string $code): ?string` (Rückgabe `'par1516'`,
  `'resttage'` oder `null`). `ContractSigning::mount()` ruft sie nur noch auf.
* **Wie wird ein Lookup-Wert formatiert?** Als reine Funktion, die eine fertige
  `value => label`-Map und den Wert entgegennimmt. Das DB-Laden der Map bleibt in
  `ZasLookupResolver` und ist nicht Teil der Unit-Tests.

Geprüft wird:

* `preSigningTypeForCode`: `AT-140` → `resttage`, `AV-default`/`AV-010` →
  `par1516`, `IFSG` und `null` → kein Schritt.
* Lookup-Formatierung: `tr` → `Türkei`; Multi-Select-Array → Labels
  komma-separiert; unbekannter Wert fällt auf den Rohwert zurück.
* Nicht-Lookup-Werte bleiben unverändert — Regressionsschutz für die zehn
  bestehenden Vorlagen.
* Nicht gemappter Platzhalter `{{resttage}}` überlebt die Ersetzungslogik von
  `personalizeContent()`.
* Resttage-Ersetzung erzeugt den erwarteten Inhalt und ersetzt mehrfaches
  Vorkommen vollständig.

## Bekannte Einschränkungen

* Sind `nationalitaet` oder `ausweisnummer` beim Bewerber leer, bleiben die
  entsprechenden Stellen im Dokument leer. Es gibt keine Vollständigkeitsprüfung
  vor dem Versand — HR sieht das Ergebnis im Portal bzw. im PDF.
* Die Anlagenliste (Immatrikulationsbescheinigung, Kopie Aufenthaltsgenehmigung,
  Aufstellung aller Beschäftigungen) ist statischer Text. Es wird nicht
  nachgehalten, ob die Anlagen tatsächlich vorliegen.

## Bewusst nicht Teil dieser Iteration

* Keine automatische Zuweisung anhand von Aufenthaltstitel oder Status.
* Keine Kontingentverwaltung über Kalenderjahre hinweg.
* Keine Verknüpfung der Resttage-Angabe mit den §15-Vorbeschäftigungsdaten des
  Arbeitsvertrags, obwohl diese fachlich verwandt sind.
