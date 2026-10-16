# Design: Infos im Mitarbeiterportal (Willkommen, News)

Stand: 16.10.2026 · Zweig `feat/portal-infos` (auf `feat/ma-konto` @ 7f6800e4) · setzt auf
`docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` (S-Dok) auf.

## 1. Ziel

Markus (Mail 14./15.10.2026): Die lange Willkommens-WhatsApp nach der Schulung (Sherans Text:
Ansprechpartner je Region, Kleidung, Portal, Zeiterfassung, erste Einsätze) soll nicht mehr
von Hand verschickt werden. Stattdessen:

1. Der neue Mitarbeiter sieht die Willkommensinfo **beim ersten Öffnen des neuen Portals**,
   sauber gegliedert, mit Knöpfen für Links und Telefonnummern, und hakt sie ab.
2. Danach bleibt sie im Portal **jederzeit abrufbar**.
3. HR kann jederzeit weitere Infos einstellen („News"). Der Mitarbeiter bekommt die kurze
   Universal-WhatsApp („im Portal ist etwas für dich offen"), liest im Portal, hakt ab.
   Per WhatsApp geht nichts Langes mehr raus.

Sebastian: „nur eine neue Art, die HR oder Dispo bei Dokumenten auswählt". Genau das.

### Was bewusst NICHT drin ist

- Kein eigenes Modul, keine eigene Empfängerlogik, kein eigener Versand, kein eigener
  HR-Überblick: alles läuft über `rec_documents` / `rec_document_recipients` (S-Dok).
- Keine Infos für den Bestand: Umgestellte Bestandsmitarbeiter haben die Willkommens-WhatsApp
  schon bekommen (Sebastian 15.10.). Die Automatik trifft nur Anstellungen, die NACH der
  Veröffentlichung der Info angelegt wurden.
- Ansprechpartner nicht aus den Filial-Einstellungen, sondern als Text (Version 1).
- Kein Nachweisblatt-PDF für Infos (Kenntnisnahme hat auch bei PDFs keines, S-Dok §5.4).
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

Neue Kategorie in `DokumentKategorie`: `info` (Label „Info / News"), Vorbelegung
`action = acknowledge`. Eine Info hat immer `content_markdown`, nie eine Datei; die
Kategorie entscheidet, welches Formular HR sieht und wie das Portal rendert.
`DokumentKategorie::istText(string $category): bool` ist die eine Stelle für diese Regel.

Invarianten (im Service erzwungen):
- `info` ⇔ `content_markdown !== null` ⇔ Datei-Spalten NULL.
- `auto_neue_mitarbeiter` nur bei `info` mit `action = acknowledge`.
- `action = sign` ist für `info` nicht erlaubt (nichts zu unterschreiben ohne Datei).

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
- Titel, Kategorie fest `info`, Aktion fest „zur Kenntnis nehmen".
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

Regel: Für jede Info mit `auto_neue_mitarbeiter = true`, nicht gelöscht, Team der
Anstellung, **`published_at < rec_employees.created_at`** der Anstellung, ohne vorhandene
Empfängerzeile für eine Anstellung dieser Person (`person_key`-Scope wie
`DokumentLeser`): Empfängerzeile anlegen (ohne WhatsApp — der Mensch ist gerade im Portal,
die Info erscheint sofort, §5.1). Rückgabe: Zahl der neu angelegten Zeilen (Log-Zeile
`recruiting.dokumente.auto_zugestellt`).

- Idempotent: zweiter Aufruf legt nichts an (Unique `rec_document_id` + `rec_employee_id`
  plus Prüfung über den Personen-Scope).
- Query Builder, keine Schreibzugriffe auf `rec_employees`.
- Bestand ist automatisch draußen: seine Anstellungen sind vor jeder Info angelegt.
- Zweite Anstellung derselben Person (RG+MA): eine Zeile je Person, nicht je Anstellung
  (Scope über `person_key`, wie der Dokumente-Versand entdoppelt).
- Kein eigener Zeitplan, kein Kommando: der Trigger ist das Öffnen des Portals.

## 5. Portal (`PortalShell`)

### 5.1 Beim ersten Öffnen im Vordergrund

Hat die Person ungelesene Infos (`category = info`, `acknowledged_at IS NULL`, nicht
zurückgezogen), zeigt der Startbildschirm **vor** „Noch zu erledigen" einen Kasten
„Neu für dich" mit der ältesten ungelesenen Info: Titel, gerenderter Text (§5.3), Knopf
„Gelesen". Klick setzt `first_viewed_at` (falls leer) und `acknowledged_at` in einer
Transaktion (erster Zeitpunkt gewinnt), Kasten zeigt die nächste ungelesene Info oder
verschwindet. Kein Haken „gelesen und verstanden" nötig (Kenntnisnahme-Haken bleibt den
PDFs; eine Info ist gelesen, wenn man „Gelesen" drückt — Markus: „einmal zum Abhaken").

Ungelesene Infos sind zugleich offene Punkte (S-Dok §5.2, Code `dokument:<id>`): sie
stehen also auch unter „Noch zu erledigen" und zählen in die Einsatz-Prüfung — die
meldet sie als neuen Punkt genau einmal (cedb8544). Das ist gewollt: Wer die Info drei
Wochen ignoriert, hört beim nächsten Einsatz einmal davon.

### 5.2 Reiter „Dokumente & Infos"

Der Reiter „Dokumente" heißt künftig „Dokumente & Infos". Infos stehen in einem eigenen
Block oben („Infos", neueste zuerst, gelesen/ungelesen), darunter Dokumente und Verträge
wie heute. Klick öffnet die Info **inline** (ausgeklappt, kein Download-Route, keine neue
Seite), setzt `first_viewed_at`. „Gelesen" wie §5.1.

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
Fortschritt „gelesen x von y" (= `acknowledged_at`). Zusätzlich bei Infos mit Automatik:
Abzeichen „automatisch an Neue seit <published_at>" und die Zahl der automatisch
zugestellten Zeilen. Zurückziehen wie heute.

## 7. Nachricht

Keine neue Vorlage. Infos mit gewählten Empfängern nutzen den bestehenden
`DokumentHinweisSender` (Universalvorlage „im Portal ist etwas für dich offen",
Feld „Dokument liegt im Portal"). Automatisch zugestellte Infos (§4) verschicken nichts.

## 8. Deploy

- Eine Migration (`add_info_fields_to_rec_documents`): drei neue Spalten, fünf Spalten
  nullable per `change()` (MySQL `migrate --pretend` auf der Demo lesen).
- Keine neue Einstellung, kein neuer Zeitplan, kein `queue:restart`-Grund über den
  bestehenden Dokumente-Job hinaus. `view:clear`.
- Checkliste `deploy-checkliste-ma-konto.md`: eigener Abschnitt „Paket Infos" nach dem
  Merge von `feat/ma-konto`; Sichttest: Info anlegen → Vorschau → als Neuer öffnen →
  Kasten „Neu für dich" → Gelesen → Reiter.
- Reihenfolge: `feat/ma-konto` zuerst auf main, dann dieser Zweig (ff). Rollback: Code
  zurück genügt, Spalten sind additiv/nullable.

## 9. Tests

Integration (Capsule+SQLite, Muster `DokumentEmpfaengerAuswahlTest`, PortalShell-Tests):

1. `bereitstellenText`: Info ohne Datei, `file_sha256 = sha256(text)`, Kategorie `info`,
   Aktion `acknowledge`; `sign` → Ausnahme; leerer Text → Ausnahme; leere Empfänger ohne
   Haken → Ausnahme, mit Haken → ok.
2. Automatik: Anstellung angelegt NACH `published_at` → Zeile beim ersten Öffnen; VOR →
   keine; zweiter Aufruf → nichts Neues; RG+MA derselben Person → eine Zeile; zurückgezogene
   Info → nichts; fremdes Team → nichts.
3. Portal: ungelesene Info → Kasten „Neu für dich" mit gerendertem Text; „Gelesen" setzt
   `acknowledged_at` + `first_viewed_at`, zweites „Gelesen" ändert nichts; danach kein
   Kasten; Info im Reiter mit Status; fremde Empfänger-ID → verweigert.
4. `InfoText::html`: Überschrift/Fett/Liste/Link gerendert; `<script>` und
   `javascript:`-Link entfernt; Telefonnummer → `tel:`-Link; `h1` → `h2`; `<img>` entfernt.
5. Offener Punkt `dokument:<id>` für ungelesene Info; nach „Gelesen" weg.
6. HR-Überblick: Info in Liste, Fortschritt zählt `acknowledged_at`.
7. Mutationsproben: Stichtags-Vergleich umdrehen → Test 2 rot; `html_input strip`
   entfernen → Test 4 rot; Scope-Prüfung in „Gelesen" entfernen → Test 3 rot.

Hausregeln: Blade ohne inline `@if` in Komponenten-Attributen, `@php` nur als Block,
`#[Locked]` auf identitätstragenden Properties, keine Eloquent-Schreibzugriffe auf
`rec_employees`, `php tools/blade-check.php` auf jeder geänderten Blade.

## 10. Offen (nicht blockierend)

- Text der Willkommensinfo: Sherans Fassung wird von HR im Editor eingepflegt; der Satz zu
  den Zugangsdaten bleibt (gilt fürs ZAS-Portal).
- Ansprechpartner später aus den Filial-Einstellungen (`rec_dispo_filiale_settings`), wenn
  HR das will.
- „Dokumente"-Reiter-Name im HR-Bereich bleibt; nur im Portal heißt es „Dokumente & Infos".
