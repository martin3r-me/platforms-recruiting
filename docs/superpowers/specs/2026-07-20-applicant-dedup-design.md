# Bewerber-Dedup-Guard: Doppelversand bei gleicher Telefonnummer stoppen

**Datum:** 2026-07-20
**Status:** Entwurf zur Review

## Problem

Bewirbt sich dieselbe Person über mehrere Portal-Anzeigen (Kleinanzeigen, Indeed, …),
entstehen mehrere Bewerber-Datensätze: Die Portale vergeben pro Anzeige eine eigene
anonymisierte Relay-Adresse, sodass die bestehende Inbound-Dedup
(`IncomingApplicationService::findExistingApplicantByContact`) keinen gemeinsamen
Schlüssel findet. Die Telefonnummer wird erst nach der Anlage vom Enrichment aus dem
Mailtext extrahiert. Danach bespielen mehrere Auto-Piloten unabhängig voneinander
denselben WhatsApp-Chat (gleiche Nummer = gleicher Thread).

Realfall (15./16.07.2026): Bewerber #2378 und #2379 (gleiche Person, zwei
Kleinanzeigen-Anzeigen) — Erstkontakt-Template `t001` ging zweimal binnen einer Minute
raus, am Folgetag zwei Reminder binnen einer Sekunde, insgesamt 4 automatische
Nachrichten in einem Chat. Zusätzlich existieren zwei Magic-Links; nur der zuletzt
geklickte Datensatz läuft weiter, der andere hängt.

## Ziel

Pro Telefonnummer bespielt zu jedem Zeitpunkt höchstens EIN Bewerber-Datensatz den
Chat: das Original (Senior-Regel: kontaktiert vor unkontaktiert, dann kleinste ID)
sendet normal weiter, jeder juniore Datensatz mit derselben Nummer wird als mögliche
Dublette markiert und zur manuellen Prüfung gestoppt, bevor er sendet. Kein
Auto-Merge, kein stilles Unterdrücken — und niemals ein gegenseitiges Doppel-Flag,
das beide stumm schaltet.

## Lösung (Ansatz A: Guard im Auto-Pilot, ein Choke-Point)

Der Check sitzt in `ProcessAutoPilotApplicants` direkt vor dem Versand — dem einzigen
Punkt, an dem der Schaden (Doppel-Nachricht) entsteht. Damit sind alle Quellen
abgedeckt (Portal-Mail, CSV-Import, manuelle Anlage, WhatsApp-Direkteinstieg),
unabhängig davon, wie die Dublette zustande kam.

### 1. Datenmodell

Migration: neue nullable Spalte `duplicate_of_applicant_id` auf `rec_applicants`,
angelegt als `$table->foreignId('duplicate_of_applicant_id')->nullable()
->constrained('rec_applicants')->nullOnDelete()` — typgleich zu `$table->id()`
(unsignedBigInteger). Gesetzt bedeutet: „mögliche Dublette von #X, vom Auto-Pilot
gestoppt".

Auflösen in V1 manuell: Feld leeren + Auto-Pilot-Status zurücksetzen → Bewerber läuft
normal weiter.

### 2. Erkennungslogik: `DuplicateApplicantGuard` (pure, ohne DB)

Klasse `src/Support/DuplicateApplicantGuard.php`, analog `FirstAiderDateGuard`:
Entscheidungslogik pure und unit-testbar ohne Laravel/DB (Test-Konvention des
Moduls); die Match-Query als statische Methode daneben (Integration-getestet).

Eingabe: Kandidat (eigene ID + eigener Kontakt-Status
`auto_pilot_last_reminder_at`) + Liste der Nummern-Matches als einfache
Wertobjekte/Arrays (je Match: applicant_id, auto_pilot_last_reminder_at).
Ausgabe: „senden ok" oder „flaggen auf Original-ID #X".

Entscheidungsregeln (Senior-Regel als Totalordnung, ordnungsunabhängig):

- Eigene ID im Match-Set wird ignoriert.
- Kein Match oder keine Telefonnummer → senden ok (Verhalten wie heute).
- Ein Match ist **senior** gegenüber dem Kandidaten, wenn:
  1. Match kontaktiert (`auto_pilot_last_reminder_at` gesetzt), Kandidat nicht;
     ODER
  2. beide denselben Kontakt-Status haben (beide kontaktiert oder beide nicht)
     UND der Match die **kleinere** ID hat.
- Existiert mindestens ein seniorer Match → flaggen auf den ranghöchsten
  (kontaktierte vor unkontaktierten, innerhalb dessen kleinste ID).
- Kein seniorer Match → senden ok (der Kandidat ist selbst das Original).

Da die Ordnung total und für jedes Paar eindeutig ist, kann es nie ein
gegenseitiges Doppel-Flag geben — weder bei zwei frischen Dubletten im selben Run
(genau der mit der kleinsten ID sendet) noch bei zwei bereits kontaktierten
Bestandsfällen im Reminder-Zweig (der mit der kleineren ID remindert weiter, der
andere wird geflaggt). Die Verarbeitungsreihenfolge ist egal
(`DispatchAutoPilotApplicants` sortiert nach `updated_at asc`, nicht nach ID;
die Regel darf sich darauf nicht verlassen).

Die Kandidaten-Query (dünne Schicht im Command, nicht im Guard): anderer Bewerber,
gleiches Team, `is_active = true`, `rejected_at IS NULL`, gleiche Telefonnummer.
Verglichen wird gegen **jede aktive Nummer** der anderen Bewerber (nicht nur die
Primary — der WhatsApp-Inbound-Pfad kann dieselbe Nummer als Zweitnummer an einen
anderen Kontakt hängen). Geparkte / HR-Desk-Bewerber bleiben bewusst im Match-Set
(analog `findExistingApplicantByContact`): ein geparktes, kontaktiertes Original
besitzt den Chat weiterhin.

**Nummern-Vergleich: kanonische Digit-Form beidseitig in PHP, KEIN SQL-Strip.**
Die Live-Daten sind NICHT durchgängig E.164 — Stichprobe über 4 Alters-Kohorten
(200 von 1464 Bewerbern) fand im Altbestand `international`-Werte wie
`015151573284` (nationale 0-Notation, Bewerber #1023, aktiv), `17664744605` /
`17684305136` (nackt ohne Ländercode und ohne 0, #1012/#97) sowie mehrfach
`+49 163 7859873` (mit Leerzeichen). Zusätzlich schreibt der
ContactIndex-Fallback im CRM („Store raw if parsing fails",
`ContactIndex.php:232`) bei libphonenumber-Fehlern den ROHEN User-Input in
`international` — Slash/Klammern/Punkte sind also ein realer Schreibpfad. Jeder
SQL-seitige `REPLACE`-Strip mit fester Zeichenliste hätte damit eine stille
Restlücke, und die Frage „welche Zeichen strippt die Engine" (MySQL-Version,
REGEXP_REPLACE-Verfügbarkeit) wäre eine dauerhafte Kopplungsstelle.

Lösung: Es gibt genau EINE Strip-/Normalisierungs-Implementierung —
`DuplicateApplicantGuard::canonicalDigits()` (pure, PHP, `preg_replace`
total-strip aller Nicht-Ziffern inkl. Slash/Klammern/Punkt/NBSP). SQL filtert
nur strukturell (Team, aktiv, nicht rejected, aktive Nummer mit
`international NOT NULL`); der Nummern-Vergleich läuft in PHP über die
kanonische Form beider Seiten (`matchesFor()`). Symmetrie ist damit per
Konstruktion garantiert, DB-Engine egal.

Performance (`matchesFor()`): EIN flacher JOIN
(rec_applicants → crm_contact_links → crm_phone_numbers) mit genau drei
Select-Spalten, KEINE Eloquent-Hydration. Alle Join-Kanten sind in den
Create-Migrationen indiziert: `rec_applicants(team_id, is_active)`,
`crm_contact_links(linkable_type, linkable_id)`,
`crm_phone_numbers` `morphs('phoneable')`. Benchmark gegen das echte
Migrations-Schema (SQLite in-memory, nach ANALYZE): 1,5k Team-Bewerber
→ ~2 ms; 20k → ~19 ms, ~10 MB, 1 Query. Zum Vergleich: die zuerst erwogene
Eloquent-Variante (whereHas + Eager-Load über vier Modellebenen) lag bei 20k
bei ~1,1 s und ~260 MB Peak — deshalb verworfen. Achtung fürs Nachmessen:
SQLite OHNE ANALYZE wählte eine katastrophale Join-Reihenfolge (~66 s bei
20k); MySQL/InnoDB pflegt Statistiken automatisch, das Artefakt ist
SQLite-spezifisch. Der Guard läuft zudem nur unmittelbar vor einem
tatsächlichen Versand (Erstkontakt bzw. fälliger Reminder), nicht pro
Dispatch-Tick.

Kanonisierungs-Regeln (Reihenfolge): total-strip; Roh-Input mit `+` →
ländercodiert übernehmen (erhält +43 etc.); `00…` → Präfix strippen; `0…` →
`49`+NSN (disambiguiert Ortsnetze wie 0491 Leer korrekt); nackte `49…` → als
ländercodiert interpretiert (wa_id-Schreibweise; dokumentierte Ambiguität:
nackte 049x-Ortsnetz-NSN ohne 0 würde fehlinterpretiert → fail-open, kein
False-Flag; alle bekannten Schreibpfade nackter Werte sind ländercodiert,
deutsche Mobil-NSN beginnen mit 1); sonst nackte NSN → `49`+Ziffern.
`international IS NULL` ist nie Match-Kandidat (WA-Versand setzt es voraus).

Status: `canonicalDigits()` und `matchesFor()` sind bereits implementiert
(`src/Support/DuplicateApplicantGuard.php` — Support statt Services, analog
`FirstAiderDateGuard`) und getestet; die Entscheidungslogik (Senior-Regel)
folgt in der Implementierungsphase in derselben Klasse.

### 3. Integration in `ProcessAutoPilotApplicants::processApplicant`

Guard-Aufruf an zwei Stellen, jeweils direkt vor `sendMessageWithOverrides`:

- **Erstkontakt-Zweig** (heute Zeile ~203): verhindert neue Doppel-Erstkontakte.
- **Reminder-Zweig** (heute Zeile ~240): fängt Bestandsfälle, bei denen beide
  Datensätze bereits einen Erstkontakt bekommen haben.

Bei Treffer:

```
duplicate_of_applicant_id = <Original-ID>
auto_pilot_state_id       = review_needed
logAutoPilot('duplicate_detected',
    "Mögliche Dublette von #<Original-ID> (gleiche Telefonnummer) — Versand gestoppt.")
→ return, kein Versand
```

`review_needed` wird von `nextAutoPilotApplicant` bereits ausgeschlossen — der
Bewerber ist danach automatisch aus der Verarbeitung raus, kein weiterer Code nötig.

Timing/Races: Der Auto-Pilot fasst Bewerber erst nach abgeschlossenem Enrichment an
(Nummer steht dann in der DB). `DispatchAutoPilotApplicants` (Scheduler, everyMinute,
withoutOverlapping) ruft `Process` synchron nacheinander pro Bewerber auf — beim
Versand-Versuch des zweiten Bewerbers ist die Nummer des ersten immer sichtbar.

Deploy-Hinweis: Beide Commands laufen als Scheduler-artisan (frischer Prozess pro
Tick, `RecruitingServiceProvider::registerSchedule`), NICHT als Queue-Worker —
kein `queue:restart` nötig. Composer-Bump von meingedeck nach dem Push bleibt
Pflicht, sonst kommt der Code nicht live an.

Performance: Query läuft nur für sendewillige Bewerber (max. `--limit`, Default 20
pro Run) — unkritisch.

### 4. UI-Banner

`applicant/show.blade.php` (+ `Show.php`): Wenn `duplicate_of_applicant_id` gesetzt
ist, gelbes Hinweis-Banner oben auf der Bewerber-Seite:

> ⚠ Mögliche Dublette von Bewerber #X (gleiche Telefonnummer) — Auto-Pilot gestoppt

mit Link auf den anderen Bewerber. Reine Anzeige, kein neuer Livewire-State.
Wert im Blade vorberechnen (kein inline `@php(...)`, Block-Form — bekannte
Blade-Pitfalls des Moduls).

## Edge-Cases

- **Geteilte Nummer (Familie/WG):** False Positive → Mensch leert das Feld und setzt
  den Auto-Pilot-Status zurück. Deshalb bewusst kein Auto-Merge.
- **Original später abgelehnt/deaktiviert:** Zwei Teilfälle, beide GEWOLLT:
  (a) Junior wurde bereits geflaggt → Flag bleibt stehen, Entscheidung beim
  Abarbeiten von review_needed; V1 macht nichts Automatisches.
  (b) Junior war noch NICHT geflaggt, als das Original `rejected_at` bekam oder
  auf `is_active=false` ging → Original fällt aus dem Match-Set, der Junior
  sendet einen frischen Erstkontakt in denselben Chat. Das ist beabsichtigt:
  Neubewerbung nach Ablehnung/Schließung soll einen frischen Lauf bekommen, ein
  abgelehntes Original darf neue Bewerbungen nicht dauerhaft blockieren.
  (`is_active=false` ist fachlich „geschlossen/archiviert" — der Schalter in den
  Bewerber-Einstellungen bzw. Bulk-Archivierung alter Kohorten; „pausiert" ist
  separat `is_parked`, und geparkte Bewerber bleiben im Match-Set.)
- **Bestandsfall #2378/#2379:** kein Backfill nötig — beide stehen bereits auf
  review_needed mit ausgeschöpften Remindern; manuell auflösen (einen deaktivieren).

## Tests

`tests/Unit/DuplicateApplicantGuardTest.php`, reines PHPUnit ohne DB
(Runner: `meingedeck/vendor/bin/phpunit -c phpunit.xml`):

1. Kein Match → senden ok.
2. Ein kontaktierter Match → flaggen auf dessen ID.
3. Mehrere Matches, einer kontaktiert → flaggen auf den kontaktierten.
4. Mehrere kontaktierte Matches → flaggen auf kleinste ID der kontaktierten.
5. Alle unkontaktiert, Kandidat hat kleinste ID → senden ok (Kandidat ist Original).
6. Alle unkontaktiert, Match mit kleinerer ID existiert → flaggen auf diese ID.
7. Senior-Regel ordnungsunabhängig: zwei unkontaktierte Dubletten, beide
   Verarbeitungsreihenfolgen durchgespielt → immer sendet genau der mit der
   kleinsten ID, der andere flaggt auf ihn (kein Doppel-Flag).
8. Eigene ID im Match-Set → ignoriert (allein → senden ok).
9. Keine Telefonnummer vorhanden → senden ok.
10. Kandidat selbst bereits kontaktiert, unkontaktierter Match mit kleinerer ID →
    senden ok (kontaktiert schlägt ID-Vergleich; Reminder des Originals dürfen
    nicht durch einen später angelegten Datensatz blockiert werden).
11. Beide kontaktiert (Bestandsfall wie #2378/#2379 im Reminder-Zweig):
    Kandidat mit kleinerer ID → senden ok (remindert weiter); Kandidat mit
    größerer ID → flaggen auf die kleinere. Kein gegenseitiges Doppel-Flag.
12. `canonicalDigits()`: alle Schreibweisen derselben Nummer (E.164, nackt,
    0-Notation, nackt ohne 0, 0049, Spaces/Bindestrich/Slash/Klammern/Punkt/
    NBSP) → gleiche kanonische Form; Ortsnetz `0491…` (Leer) wird NICHT als
    Ländercode fehlgeparst; zwei Personen, deren Formen sich nur durch
    korrektes vs. fehlerhaftes 49-Stripping unterscheiden, kollidieren nicht;
    Auslandsnummern mit `+` bleiben erhalten; leere Eingaben → null.
    → BEREITS UMGESETZT: `tests/Unit/DuplicateApplicantGuardCanonicalTest.php`.

Zusätzlich Integrations-Tests der Match-Query — BEREITS UMGESETZT und Teil der
regulären Suite (`phpunit.xml`, Testsuite „Integration"):
`tests/Integration/DuplicateMatchQueryTest.php` (SQLite in-memory via Capsule,
echte Modelle, ohne Testbench; 7 Tests: E.164 beidseitig, Secondary-Nummer,
Legacy-Bestand ↔ sauberer Input inkl. Slash-Format, Ortsnetz-49 vs. Ländercode
ohne Kollision, Ausschlüsse Team/inaktiv/rejected/inaktive Nummer/ohne
international, Kontakt-Status im Ergebnis, leere Versand-Nummer). Das Schema
baut der Test aus den ECHTEN Migrationen beider Module (explizite Liste in
`runRealMigrations()`, Schema-/DB-Facades auf Capsule verdrahtet) — ein
Prod-Spaltenrename oder Relation-Key-Change schlägt im Test auf, statt still
grün zu laufen. Nötige Stubs: config-Repository (LogsActivity), Auth-Factory
ohne User (CrmContactLink::creating), Event-Dispatcher (uuid/public_token-
Hooks, im echten Schema NOT NULL). Gesamte Suite grün: 230 Tests /
591 Assertions am 2026-07-24.

## Bewusst nicht in V1 (Out of Scope)

- **Echte E-Mail als zweites hartes Signal** (Enrichment extrahiert oft die reale
  Adresse aus dem Anschreiben): fängt Dubletten ohne Nummer. Query-Struktur existiert
  bereits — guter Phase-2-Kandidat, wenn V1-Daten den Bedarf zeigen.
- **Auflöse-Buttons** („Keine Dublette" / „Deaktivieren") oder voller Merge-Flow.
- **Weiche Signale** (Namens-Match): dürfen nie stoppen, nur flaggen — erst bei
  nachgewiesenem Bedarf.
- **Kontakt-Dedup im CRM** (eigenes, modulübergreifendes Thema; ersetzt diesen Guard
  nicht, da das Problem auf Bewerber-Ebene liegt).

## Bekannte Restlücken

1. Keine Telefonnummer in der Bewerbung → kein Schlüssel; zwei E-Mails an zwei
   Relay-Adressen gehen weiter raus. Sobald eine Nummer nachgetragen wird, stoppt
   der Guard alle weiteren Sends.
2. Dieselbe Person mit zwei verschiedenen Nummern (oder Tippfehler) → kein Match.
3. Team-übergreifende Dubletten werden bewusst nicht geprüft.
