# Design: Infos im Mitarbeiterportal (Willkommen, News)

Stand: 16.10.2026 · Zweig `feat/ma-konto` (Paket ab 7f6800e4, fährt mit dem ma-konto-Deploy) · setzt auf
`docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` (S-Dok) auf.

## 1. Ziel

Markus (Mail 14./15.10.2026): Die lange Willkommens-WhatsApp nach der Schulung (Sherans Text:
Ansprechpartner je Region, Kleidung, Portal, Zeiterfassung, erste Einsätze) soll nicht mehr
von Hand verschickt werden. Stattdessen:

1. Der neue Mitarbeiter sieht die Willkommensinfo **beim ersten Öffnen des neuen Portals**,
   sauber gegliedert, mit Knöpfen für Links und Telefonnummern. Kein Haken, kein Knopf
   (Sebastian 16.10.: „bei Infos soll er gar nicht anklicken müssen").
2. Danach bleibt sie im Portal **jederzeit abrufbar**.
3. HR kann jederzeit weitere Infos einstellen („News"). Der Mitarbeiter bekommt die kurze
   Universal-WhatsApp („im Portal ist etwas für dich offen") und liest im Portal.
   Per WhatsApp geht nichts Langes mehr raus.

Sebastian: „nur eine neue Art, die HR oder Dispo bei Dokumenten auswählt". Genau das.

### Was bewusst NICHT drin ist

- Kein eigenes Modul, keine eigene Empfängerlogik, kein eigener Versand, kein eigener
  HR-Überblick: alles läuft über `rec_documents` / `rec_document_recipients` (S-Dok).
- Keine Infos für den Bestand: Umgestellte Bestandsmitarbeiter haben die Willkommens-WhatsApp
  schon bekommen (Sebastian 15.10.). Die Automatik trifft nur Anstellungen, die NACH der
  Veröffentlichung der Info angelegt wurden.
- Ansprechpartner nicht aus den Filial-Einstellungen, sondern als Text (Version 1).
- Kein Nachweisblatt-PDF, keine Bestätigung, kein offener Punkt: eine Info ist gesehen,
  sobald sie angezeigt wurde. Sie steht nie unter „Noch zu erledigen" und nie in der
  Einsatz-Prüfung.
- Keine Bilder, Tabellen, Code-Blöcke im Text. Keine Versionierung: eine geänderte Info ist
  eine neue Info (Dokumente sind nach Bereitstellen unveränderlich, S-Dok §2 Invarianten).

## 2. Datenmodell

Erweiterung von `rec_documents`, eine Migration, additiv, mit `down()`:

| Spalte | Typ | Zweck |
|---|---|---|
| `content_markdown` | text nullable | der Text (Markdown, aus dem Toast-UI-Editor) |
| `auto_neue_mitarbeiter` | bool default false | Haken „automatisch an neue Mitarbeiter" |
| `published_at` | datetime nullable | Zeitpunkt der Bereitstellung; Stichtag für die Automatik (`= created_at` beim Anlegen, eigene Spalte, damit es lesbar bleibt) |

`disk`, `stored_path`, `original_filename`, `file_sha256`, `file_size` werden **nullable**
(`change()` nur, wenn NOT NULL — Muster `2026_10_01_000002`). Für eine Info gilt:
`content_markdown` gesetzt, Datei-Spalten NULL; `file_sha256` trägt stattdessen
`sha256(content_markdown)` — dieselbe Spalte, dieselbe Bedeutung (Prüfsumme des
Inhalts), damit `DokumentNachweisDaten` und die Unterschrifts-Prüfung unverändert bleiben.

Neue Kategorie in `DokumentKategorie`: `info` (Label „Info / News"), Aktion fest
`none` (nur ablegen — S-Dok: bei `none` gibt es nur `abgelegt`/`gesehen`). Eine Info hat immer `content_markdown`, nie eine Datei; die
Kategorie entscheidet, welches Formular HR sieht und wie das Portal rendert.
`DokumentKategorie::istText(string $category): bool` ist die eine Stelle für diese Regel.

Invarianten (im Service erzwungen):
- `info` ⇔ `content_markdown !== null` ⇔ Datei-Spalten NULL.
- `auto_neue_mitarbeiter` nur bei `info`.
- `action` ist für `info` immer `none`; `acknowledge`/`sign` sind nicht erlaubt.

## 3. Bereitstellen

### 3.1 Service

`DokumentService::bereitstellenText(int $teamId, array $daten, string $markdown, list<int> $employeeIds, ?int $userId): array`
neben dem bestehenden `bereitstellen()` (PDF). Gemeinsamer privater Kern für Empfänger,
Entdoppelung über `person_key`, Benachrichtigung (Job `DokumentHinweiseVersenden`) — der
PDF-Weg wird NICHT umgebaut, nur sein Kern herausgezogen.

Validierung Text: nicht leer nach Trim, ≤ 20.000 Zeichen, Titel Pflicht. `$employeeIds`
darf **leer** sein, wenn `auto_neue_mitarbeiter` gesetzt ist (Info wartet auf Neue).
Sonst gilt wie heute: mindestens ein Empfänger.

### 3.2 Einstieg Seite „Dokumente" (`Livewire/Employees/Documents`)

Im Formular ein Umschalter **PDF | Info**. Bei „Info":
- Titel, Kategorie fest `info`, keine Aktionswahl (nur ablegen).
- Editor: Toast UI Editor aus der Host-App (`window.ToastUIEditor`, Muster
  `platform-notes` `note.blade.php`: `boot()` mit Rückfall auf `toastui:ready`),
  WYSIWYG, `hideModeSwitch`, Werkzeugleiste nur `heading, bold, italic | ul, ol | link`.
  Speichert Markdown in eine Livewire-Property (`$infoMarkdown`). **Rückfall:** fehlt
  `window.ToastUIEditor`, zeigt dasselbe Blade ein `<textarea>` mit Hinweis „Markdown".
- Haken „Automatisch an neue Mitarbeiter (beim ersten Öffnen des Portals)".
- Empfänger-Auswahl wie heute (Opt-in, e4c5aaa3); bei gesetztem Haken darf sie leer bleiben,
  die Bestätigung sagt dann „0 Empfänger jetzt, automatisch an alle Neuen ab heute".
- Vorschau-Knopf: rendert den Markdown mit demselben Renderer wie das Portal (§5.3) in
  einem Fenster — HR sieht, was der Mitarbeiter sieht.

Die Einstiege Akte und Veranstaltung (S-Dok §3.2/3.3) bleiben PDF-only. Nicht nötig für
Infos; wer von dort eine Info will, nimmt die Seite „Dokumente".

### 3.3 Zurückziehen

Wie S-Dok §7. Zusätzlich: Zurückziehen einer Info mit `auto_neue_mitarbeiter` setzt den
Haken auf false (sonst bekäme der nächste Neue eine zurückgezogene Info).

## 4. Automatik „an neue Mitarbeiter"

`DokumentAutoZustellung::fuerErstesOeffnen(RecEmployee $employee): int` — wird in
`PortalShell` genau an der Stelle gerufen, an der die Sitzung erstmals gültig wird
(`verify()` nach erfolgreicher Prüfung, und der Konto-Weg, der die Anstellung öffnet —
beide laufen durch `identitaetLaden()`; der Aufruf steht davor, einmal, idempotent).

Regel: Nur wenn die Person **neu** ist (Ruling Schlussreview I-4, 16.10.):
die geöffnete Anstellung kam über unser Recruiting (**`rec_applicant_id IS NOT NULL`**)
**und** die **früheste** Anstellung der Person (`MIN(created_at)` über alle IDs des
Personen-Scopes, RG+MA) liegt nach der Veröffentlichung. Von ZAS gelieferte
Anstellungen ohne Bewerbung (Stichtags-Vollimport, Nachlieferungen) lösen die Automatik
nie aus, auch wenn ihre Zeile erst nach der Info entsteht; eine Bestandsperson mit einer
späteren zweiten Anstellung bleibt Bestand. Dann für jede Info mit
`auto_neue_mitarbeiter = true`, nicht gelöscht, Team der Anstellung,
**`published_at < MIN(rec_employees.created_at)`** der Person (strikt), ohne vorhandene
Empfängerzeile für eine Anstellung dieser Person (`person_key`-Scope wie
`DokumentLeser`): Empfängerzeile anlegen (ohne WhatsApp — der Mensch ist gerade im Portal,
die Info erscheint sofort, §5.1). Rückgabe: Zahl der neu angelegten Zeilen (Log-Zeile
`recruiting.dokumente.auto_zugestellt`).

- Idempotent: zweiter Aufruf legt nichts an (Unique `rec_document_id` + `rec_employee_id`
  plus Prüfung über den Personen-Scope).
- Query Builder, keine Schreibzugriffe auf `rec_employees`.
- Bestand ist draußen: seine früheste Anstellung ist vor jeder Info angelegt, und
  ZAS-Anstellungen ohne Bewerbung zählen nie als neu.
- Zweite Anstellung derselben Person (RG+MA): eine Zeile je Person, nicht je Anstellung
  (Scope über `person_key`, wie der Dokumente-Versand entdoppelt).
- Kein eigener Zeitplan, kein Kommando: der Trigger ist das Öffnen des Portals.

## 5. Portal (`PortalShell`)

### 5.1 Beim ersten Öffnen im Vordergrund

Hat die Person ungesehene Infos (`category = info`, `first_viewed_at IS NULL`, nicht
zurückgezogen), zeigt der Startbildschirm **vor** „Noch zu erledigen" einen Kasten
„Neu für dich" mit allen ungesehenen Infos (älteste zuerst): Titel, gerenderter Text
(§5.3). **Das Anzeigen selbst setzt `first_viewed_at`** (Query Builder, idempotent, beim
Rendern des Starts — nicht beim bloßen Laden eines anderen Reiters). Ein kleiner Knopf
„Schließen" klappt den Kasten für diese Sitzung zu (Livewire-Zustand, nichts in der DB);
beim nächsten Login kommt die Info nicht wieder hoch, weil sie gesehen ist.

Infos sind **keine offenen Punkte**: `OffenePunkte::fuer()` nimmt nur Empfängerzeilen mit
`action ∈ {acknowledge, sign}` (S-Dok §5.2) — bei `none` entsteht nichts. Damit stehen
Infos nie unter „Noch zu erledigen" und nie in der Einsatz-Prüfung. Ein Test sichert das.

### 5.2 Reiter „Dokumente & Infos"

Der Reiter „Dokumente" heißt künftig „Dokumente & Infos". Infos stehen in einem eigenen
Block oben („Infos", neueste zuerst, Abzeichen „Neu" solange `first_viewed_at` leer),
darunter Dokumente und Verträge wie heute. Klick klappt die Info **inline** auf (kein
Download, keine neue Seite) und setzt `first_viewed_at`, falls leer.

### 5.3 Rendern

`InfoText::html(string $markdown): string` — reine Klasse:

1. `Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false])`
   (league/commonmark aus dem Core, wie `platform-notes`).
2. Nachbearbeitung mit DOMDocument: `<a href="http(s)://…">` → Knopf-Klasse `info-link`
   (Label = Linktext, `target=_blank`, `rel=noopener`); Telefonnummern im Fließtext
   (`PhoneE164::normalize()` erkennt sie) → `<a href="tel:+49…" class="info-tel">`
   mit Label wie geschrieben; `<h1>` wird zu `<h2>` (der Titel ist die h1).
3. Erlaubte Elemente: `h2 h3 p strong em ul ol li a br hr`. Alles andere wird entfernt
   (zweite Sicherung nach `html_input strip`).

Portal-CSS: Überschriften, Absatzabstand, Knöpfe im Portal-Stil, am Handy volle Breite
(Muster 4c88b39c, Vertrag lesen am Handy).

### 5.4 Sichtbarkeit und Rechte

Unverändert S-Dok §5.1: nur Empfängerzeilen im Personen-Scope. Keine Download-Route für
Infos (kein Dateiinhalt). Aktionen nehmen die Empfänger-ID und prüfen sie gegen den Scope.

## 6. HR-Überblick

Seite „Dokumente" (S-Dok §8) zeigt Infos in derselben Liste mit Kategorie-Label „Info",
Fortschritt „gesehen x von y" (= `first_viewed_at`). Zusätzlich bei Infos mit Automatik:
Abzeichen „automatisch an Neue seit <published_at>" und die Zahl der automatisch
zugestellten Zeilen. Zurückziehen wie heute.

## 7. Nachricht

Keine neue Vorlage. Infos mit gewählten Empfängern nutzen den bestehenden
`DokumentHinweisSender` (Aktion none, aber `DokumentKategorie::brauchtHinweis`;
Universalvorlage „im Portal ist etwas für dich offen", Feld „Dokument liegt im Portal").
Automatisch zugestellte Zeilen tragen `notify_error = nicht_noetig` und werden nie angeschrieben.

## 8. Deploy

- Eine Migration (`add_info_fields_to_rec_documents`): drei neue Spalten, fünf Spalten
  nullable per `change()` (MySQL `migrate --pretend` auf der Demo lesen).
- Keine neue Einstellung, kein neuer Zeitplan, kein `queue:restart`-Grund über den
  bestehenden Dokumente-Job hinaus. `view:clear`.
- Checkliste `deploy-checkliste-ma-konto.md`: eigener Abschnitt „Paket Infos" nach dem
  Merge von `feat/ma-konto`; Sichttest: Info anlegen → Vorschau → als Neuer öffnen →
  Kasten „Neu für dich" → Schließen → erneut anmelden, kein Kasten → Reiter.
- Reihenfolge: `feat/ma-konto` zuerst auf main, dann dieser Zweig (ff). Rollback: Code
  zurück genügt, Spalten sind additiv/nullable.

## 9. Tests

Integration (Capsule+SQLite, Muster `DokumentEmpfaengerAuswahlTest`, PortalShell-Tests):

1. `bereitstellenText`: Info ohne Datei, `file_sha256 = sha256(text)`, Kategorie `info`,
   Aktion `none`; `acknowledge`/`sign` → Ausnahme; leerer Text → Ausnahme; leere Empfänger
   ohne Haken → Ausnahme, mit Haken → ok.
2. Automatik: Anstellung angelegt NACH `published_at` → Zeile beim ersten Öffnen; VOR →
   keine; zweiter Aufruf → nichts Neues; RG+MA derselben Person → eine Zeile; zurückgezogene
   Info → nichts; fremdes Team → nichts.
3. Portal: ungesehene Info → Kasten „Neu für dich" mit gerendertem Text, das Rendern
   setzt `first_viewed_at`; zweites Rendern ändert den Zeitpunkt nicht; nächster Login →
   kein Kasten; Info im Reiter mit Abzeichen „Neu" nur solange ungesehen; Aufklappen einer
   fremden Empfänger-ID → verweigert.
4. `InfoText::html`: Überschrift/Fett/Liste/Link gerendert; `<script>` und
   `javascript:`-Link entfernt; Telefonnummer → `tel:`-Link; `h1` → `h2`; `<img>` entfernt.
5. Keine offenen Punkte: eine ungesehene Info erzeugt in `OffenePunkte::fuer()` nichts
   und taucht in der Einsatz-Prüfung nicht auf.
6. HR-Überblick: Info in Liste, Fortschritt zählt `first_viewed_at`.
7. Mutationsproben: Stichtags-Vergleich umdrehen → Test 2 rot; `html_input strip`
   entfernen → Test 4 rot; Scope-Prüfung beim Aufklappen entfernen → Test 3 rot;
   `action` einer Info auf `acknowledge` zulassen → Test 5 rot.

Hausregeln: Blade ohne inline `@if` in Komponenten-Attributen, `@php` nur als Block,
`#[Locked]` auf identitätstragenden Properties, keine Eloquent-Schreibzugriffe auf
`rec_employees`, `php tools/blade-check.php` auf jeder geänderten Blade.

## 10. Offen (nicht blockierend)

- Text der Willkommensinfo: Sherans Fassung wird von HR im Editor eingepflegt; der Satz zu
  den Zugangsdaten bleibt (gilt fürs ZAS-Portal).
- Ansprechpartner später aus den Filial-Einstellungen (`rec_dispo_filiale_settings`), wenn
  HR das will.
- „Dokumente"-Reiter-Name im HR-Bereich bleibt; nur im Portal heißt es „Dokumente & Infos".
