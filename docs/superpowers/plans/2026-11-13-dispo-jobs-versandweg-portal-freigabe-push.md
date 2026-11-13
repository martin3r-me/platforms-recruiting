# Jobs anbieten: Versandweg, Freigabe, Angebote im Portal, Push — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Monats- und Wochenübersichten gehen wahlweise ins Mitarbeiterportal oder (nach Freigabe) per WhatsApp raus; das Portal zeigt Angebote, lässt sich als App ablegen und schickt Push.

**Architecture:** Der Weg (`whatsapp`|`portal`) und die Freigabepflicht stehen an der Anfrage; `SendDispoJobWave` bleibt der eine Ort für Wellen und verzweigt nur beim Zustellen. Die Angebotsliste wird aus `JobOffers` in einen Dienst gezogen, damit Portal-Karte und Liste dieselbe Menge zeigen. Push hängt hinter einer kleinen Schnittstelle mit Null-Variante, damit Wellen nie davon abhängen.

**Tech Stack:** Laravel/Livewire 3, Alpine, eigenes Portal-CSS (kein Tailwind im Portal), `minishlink/web-push`, PHPUnit (Unit rein, Integration mit Capsule + SQLite).

**Spec:** `docs/superpowers/specs/2026-11-13-dispo-jobs-versandweg-portal-freigabe-push-design.md` — jede Aufgabe liest die dort genannten Abschnitte mit.

## Global Constraints

- Zweig `feat/dispo-jobs-versandweg`. Kein Merge, kein Push, kein Bump. Nichts außerhalb von `platforms-recruiting` ändern (auch nicht `meingedeck`).
- Tests: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` (kein eigenes vendor). Blade: `php tools/blade-check.php`. Kein `order-by=random`.
- Testkonvention: `tests/Unit` rein rechnend, `tests/Integration` mit handgebautem Container + Capsule (Muster `tests/Integration/DispoJobOfferSenderTest.php`, `SendDispoJobWaveJobTest.php`).
- Schalter aus (`dispo_job_versandweg_aktiv` fehlt/false) ⇒ Verhalten für Disponenten und Versand exakt wie vorher. Bestehende Tests bleiben unverändert grün.
- `dringend` wird nie verzögert, nie umgeleitet, nie freigabepflichtig.
- Push-Fehler dürfen keine Welle abbrechen. Push-Texte ohne Kunde, Ort, Uhrzeit.
- Portal-Ansichten: kein Tailwind, keine `x-ui-*`; Texte als `$duzen`-Ternary. Blade: keine Direktiven an Wortzeichen kleben, kein inline `@php(...)`, Werte vorberechnen.
- Assets nie publizieren; Manifest, Service Worker und Symbole laufen über Modul-Routen.
- Commit-Stil wie `git log` (deutsch, `feat(recruiting): …`), Trailer `Co-Authored-By: Claude <noreply@anthropic.com>`. Nie bare `git stash`.
- Kommentardichte und Benennung wie im umliegenden Code (deutsche Dienstnamen, kurze Begründungs-Kommentare mit Datum „Markus 13.11.2026“).

## Review Focus

1. Freigabe kommt Tage nach dem Anlegen, `scheduled_for` von Welle 1 liegt in der Vergangenheit oder war „später starten“ → Welle startet bei Freigabe sofort, genau einmal. (Test in Task 5)
2. Doppelklick auf „Freigeben“ bzw. Freigeben und Ablehnen fast gleichzeitig → genau eine Entscheidung gewinnt, Welle 1 wird höchstens einmal eingereiht. (Task 5)
3. Team ohne jede hinterlegte WhatsApp-Vorlage → Portal-Anfrage anlegen, Welle 1 und „Neue Welle“ funktionieren. (Task 3 + 4)
4. Derselbe Push-Endpunkt meldet sich erneut oder für eine andere Person an (geteiltes Handy) → ein Abo je Endpunkt, es gehört der zuletzt angemeldeten Person. (Task 7)
5. Zusage im eingebetteten Bereich → Karte auf der Startseite zählt sofort neu; null Angebote → keine Karte, kein leerer Kasten. (Task 6)

---

### Task 1: Datenmodell

**Files:**
- Create: `database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php`
- Create: `database/migrations/2026_11_13_000002_create_rec_push_abos_table.php`
- Create: `src/Models/RecPushAbo.php`
- Modify: `src/Models/RecDispoJobRequest.php`, `src/Models/RecDispoJobOffer.php`
- Test: `tests/Integration/DispoJobVersandwegTablesTest.php` (Muster `DispoJobTablesTest.php`)

**Interfaces — Produces:**
- `rec_dispo_job_requests`: `versandweg` string(16) default `'whatsapp'`; `freigabe_pflicht` bool default false; `freigabe_beantragt_at`, `freigabe_beantragt_by_user_id`; `freigegeben_at`, `freigegeben_by_user_id`; `freigabe_abgelehnt_at`, `freigabe_abgelehnt_by_user_id`, `freigabe_ablehnungsgrund` string(255) — alle nullable.
- `rec_dispo_job_offers`: `kanal` string(16) default `'whatsapp'`.
- `rec_push_abos`: `id`, `rec_employee_id` (index), `endpoint` text, `endpoint_hash` char(64) unique (sha256 des Endpunkts), `p256dh` string(255), `auth` string(255), `user_agent` string(255) nullable, `last_ok_at` nullable, timestamps.
- `RecDispoJobRequest::WEG_WHATSAPP = 'whatsapp'`, `WEG_PORTAL = 'portal'`; Casts für die neuen Felder; fillable ergänzt.
- `RecDispoJobRequest::istPortal(): bool`
- `RecDispoJobRequest::darfSenden(): bool` — `!freigabe_pflicht || freigegeben_at !== null`
- `RecDispoJobRequest::wartetAufFreigabe(): bool` — `freigabe_pflicht && freigegeben_at === null && freigabe_abgelehnt_at === null && closed_at === null`
- `RecDispoJobRequest::freigabeAbgelehnt(): bool` — `freigabe_pflicht && freigegeben_at === null && freigabe_abgelehnt_at !== null`
- `RecPushAbo` (Tabelle `rec_push_abos`), `RecPushAbo::hash(string $endpoint): string`.

- [ ] Test schreiben: Migrationen laufen auf SQLite; Altzeile ohne neue Felder hat `versandweg === 'whatsapp'`, `darfSenden() === true`, `wartetAufFreigabe() === false`; die vier Zustandsmethoden für pflicht/freigegeben/abgelehnt/geschlossen; zweites Abo mit gleichem `endpoint_hash` scheitert am Unique.
- [ ] Rot laufen lassen, implementieren, grün laufen lassen. Wo Integrationstests ihre Migrationen einzeln laden (`runMigrations()` in bestehenden Tests), die neuen dort ergänzen, wo die Tabellen gebraucht werden.
- [ ] Ganze Suite, Commit.

### Task 2: Schalter, Freigeber, gesperrte Einstellungen

Spec 6.1, 6.3 (Warnzahl).

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobVersandweg.php`
- Modify: `src/Livewire/Dispo/Settings.php`, `resources/views/livewire/dispo/settings.blade.php`
- Test: `tests/Unit/DispoJobVersandwegTest.php`, `tests/Integration/DispoSettingsVersandwegTest.php`

**Interfaces — Produces (alle statisch):**
- `SETTING_AKTIV = 'dispo_job_versandweg_aktiv'`, `SETTING_FREIGEBER = 'dispo_job_freigeber_email'`
- `aktiv(RecApplicantSettings $s): bool`
- `freigeberEmail(RecApplicantSettings $s): ?string` — getrimmt, kleingeschrieben, leer ⇒ null
- `istFreigeber(RecApplicantSettings $s, mixed $user): bool` — E-Mail des Nutzers (trim, lower) gleich Freigeber; ohne Nutzer/E-Mail false
- `darfEinstellungenAendern(?string $freigeberEmail, mixed $user): bool` — kein Freigeber ⇒ true, sonst nur der Freigeber
- `waehlbar(bool $aktiv, string $anlass): bool` — nur `vier_wochen`, `naechste_woche` bei aktiv
- `fuerAnfrage(bool $aktiv, string $anlass, string $wunsch): array{weg:string, freigabe_pflicht:bool}` — nicht wählbar ⇒ `whatsapp`/false; Wunsch `portal` ⇒ `portal`/false; sonst `whatsapp`/true
- `ohneNeuesPortal(int $teamId): int` — aktive Mitarbeiter mit Status GO oder MA (dieselbe Statusquelle wie `DispoJobKandidaten`/`DispoJobRecipientPlanner::stati`) und leerem `portal_v2_since`

**Settings-Seite:** Abschnitt „Jobs anbieten: Versandweg“ mit Schalter (Beschriftung aus Spec 6.1) und Feld Freigeber-E-Mail. Beim Speichern: darf der Nutzer nicht (`darfEinstellungenAendern` gegen den **gespeicherten** Freigeber), bleiben diese zwei Werte unverändert und die übrigen Einstellungen werden trotzdem gespeichert; die Felder sind dann in der Ansicht gesperrt mit dem Hinweis „Nur der Freigeber kann das ändern.“ Warnungen: Schalter an ohne Freigeber (Text Spec 6.1); `ohneNeuesPortal > 0` (Text Spec 6.3).

- [ ] Unit-Tests: Tabelle für `fuerAnfrage` (aus × drei Anlässe × beide Wünsche; an × drei Anlässe × beide Wünsche), `waehlbar`, `istFreigeber` mit `" Markus@RG.de "` gegen `markus@rg.de`, `darfEinstellungenAendern` (leer/Freigeber/Fremder).
- [ ] Integration: Fremder speichert ⇒ zwei Schlüssel unverändert, ein anderer Schlüssel (z. B. `dispo_escalation_time_1`) geändert; Freigeber speichert ⇒ geändert; kein Freigeber ⇒ jeder darf. `ohneNeuesPortal` zählt nur aktiv + GO/MA + `portal_v2_since` leer.
- [ ] Implementieren, `php tools/blade-check.php`, Suite, Commit.

### Task 3: Portal-Welle und Sicherung im Job

Spec 6.3, 6.4 (Sicherung), 4.

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobPortalBereitsteller.php`
- Create: `src/Services/Push/PortalPush.php` (Interface), `src/Services/Push/KeinPush.php`
- Modify: `src/Jobs/SendDispoJobWave.php`, `src/Services/Zas/Dispo/Jobs/DispoJobAnfrageAnlegen.php`, `src/RecruitingServiceProvider.php` (Bindung `PortalPush` → `KeinPush`)
- Test: `tests/Integration/SendDispoJobWaveJobTest.php` (erweitern), `tests/Integration/DispoJobPortalBereitstellerTest.php`

**Interfaces:**
- Consumes: Task 1 (Spalten, `darfSenden()`, `istPortal()`).
- Produces:
  - `DispoJobPortalBereitsteller::bereitstellen(RecDispoJobWave $wave, array $recipients): array{ok:bool, message:?string, sent:int, failed:list<array{anchor:int, error:string}>}` — gleiche Empfänger- und Ergebnisform wie `DispoJobOfferSender::send`; legt Angebot + Schichten an, `kanal = 'portal'`, `sent_at = now()`; schon angeschriebene Person ⇒ `failed` mit „in dieser Anfrage schon angeschrieben“; wirft nie.
  - `interface PortalPush { public function aktiv(): bool; public function neueAngebote(array $employeeIds): void; public function probe(int $employeeId): void; }` — `KeinPush`: `aktiv()` false, Rest tut nichts.
  - `DispoJobAnfrageAnlegen::anlegen()` nimmt zusätzlich `versandweg` (Vorgabe `whatsapp`) und `freigabe_pflicht` (Vorgabe false); `template_id` ≤ 0 ⇒ `whatsapp_template_id = null`; bei Pflicht `freigabe_beantragt_at = now()`, `freigabe_beantragt_by_user_id = $userId`.

**Job-Änderungen in `handle()`:**
1. Direkt nach der `closed_at`-Prüfung, vor jedem Schreiben: `if (!$request->darfSenden()) return;` (Welle bleibt unbeansprucht).
2. `DispoJobVorlagenWaechter` und die Prüfung „keine Vorlage gewählt“ nur bei Weg `whatsapp`.
3. Je Paket: Portal ⇒ `DispoJobPortalBereitsteller`, sonst `DispoJobOfferSender`. Zähler und Folgewelle unverändert.
4. Nach den Paketen einer Portal-Welle, in eigenem `try/catch (\Throwable)`: `app(PortalPush::class)->neueAngebote($employeeIds)` mit den `employee_id` der erfolgreich Bereitgestellten.

- [ ] Tests (neu, bestehende unverändert lassen):
  - Portal-Welle: Angebote mit `kanal === 'portal'` und `sent_at`, WhatsApp-Stub `calls === 0`, `summary['sent']` = Empfängerzahl, Folgewelle entsteht bei Rest > 0 und Abstand, `welle_max` greift.
  - Portal-Anfrage mit `whatsapp_template_id = null` und ohne jede Vorlage in der Tabelle läuft durch (Review Focus 3).
  - `freigabe_pflicht = true`, `freigegeben_at = null`: `started_at` bleibt null, keine Angebote, kein WhatsApp-Aufruf; nach Setzen von `freigegeben_at` sendet derselbe Job.
  - Dieselbe Sperre für eine automatische Folgewelle (`auto = true`) derselben Anfrage.
  - `anlass = 'dringend'`, `freigabe_pflicht = false`: sendet wie bisher.
  - `PortalPush`-Attrappe wirft in `neueAngebote` ⇒ Welle hat `finished_at`, Angebote stehen.
  - `PortalPush`-Attrappe bekommt genau die `employee_id` der Welle; bei WhatsApp-Welle keinen Aufruf.
- [ ] Implementieren, Suite, Commit.

### Task 4: Anlegen mit Versandweg

Spec 6.2.

**Files:**
- Modify: `src/Livewire/Dispo/Jobs/Create.php`, `resources/views/livewire/dispo/jobs/create.blade.php`, ggf. `src/Services/Zas/Dispo/Jobs/DispoJobCreateState.php`
- Test: `tests/Integration/DispoJobCreateKomponenteTest.php`, `tests/Integration/DispoJobCreateFlowTest.php` (erweitern)

**Interfaces:**
- Consumes: `DispoJobVersandweg::aktiv/waehlbar/fuerAnfrage`, `DispoJobAnfrageAnlegen::anlegen` (Task 3).
- Produces: `Create::$versandweg` (`'portal'`|`'whatsapp'`), `#[Computed] wegWaehlbar(): bool`, `#[Computed] wegWhatsappMoeglich(): bool` (Vorlage für den Anlass hinterlegt).

**Verhalten:**
- Schalter aus: keine Auswahl, nichts anders (auch `anlassWaehlen`/`anlassVorbelegen` wie heute).
- Schalter an: `vier_wochen`/`naechste_woche` sind auch ohne hinterlegte Vorlage wählbar; `versandweg` wird bei jedem Anlasswechsel auf `portal` gesetzt, wenn wählbar, sonst `whatsapp`. Fehlt die Vorlage, ist „WhatsApp (braucht Freigabe)“ deaktiviert.
- Weg `portal`: `vorlagenProblem` ist null, Vorschau der WhatsApp-Vorlage entfällt; `DispoJobCreateState` verlangt keine Vorlage.
- `anlegenUndSenden()`: `fuerAnfrage(...)` entscheidet; bei `freigabe_pflicht` wird **nicht** `starten()` aufgerufen. Knopftext: Portal „Anlegen und im Portal bereitstellen“, WhatsApp mit Pflicht „Anlegen und Freigabe beantragen“, sonst wie heute.
- Der Client-Wert `versandweg` wird serverseitig nie vertraut: `fuerAnfrage` bekommt den echten Schalter und Anlass.

- [ ] Tests: Schalter aus ⇒ Anfrage `whatsapp`, Pflicht false, Welle eingereiht (wie bisher); Schalter an + `naechste_woche` + Portal ⇒ `versandweg = portal`, Vorlage null erlaubt, Welle eingereiht; Schalter an + WhatsApp ⇒ Pflicht true, `freigabe_beantragt_*` gesetzt, **keine** Welle eingereiht; Schalter an + `dringend` + manipuliertes `versandweg = 'portal'` ⇒ `whatsapp`, Pflicht false, eingereiht; Schalter aus + manipuliertes `portal` ⇒ `whatsapp`.
- [ ] Implementieren, Blade-Check, Suite, Commit.

### Task 5: Freigabe

Spec 6.4.

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobFreigabe.php`
- Modify: `src/Livewire/Dispo/Jobs/Show.php`, `resources/views/livewire/dispo/jobs/show.blade.php`, `src/Livewire/Dispo/Jobs/Index.php`, `resources/views/livewire/dispo/jobs/index.blade.php`, `resources/views/livewire/sidebar.blade.php`
- Test: `tests/Integration/DispoJobFreigabeTest.php`, `tests/Unit/DispoJobsRunde2WiringTest.php` (Verdrahtung ergänzen)

**Interfaces:**
- Consumes: Task 1, `DispoJobVersandweg::istFreigeber`, `DispoJobAnfrageAnlegen::starten`.
- Produces (statisch, Rückgabe `'ok'|'nicht_berechtigt'|'nicht_wartend'`):
  - `freigeben(RecDispoJobRequest $r, mixed $user, int $teamId): string`
  - `ablehnen(RecDispoJobRequest $r, mixed $user, string $grund): string` — Grund getrimmt, max. 255
  - `insPortal(RecDispoJobRequest $r, int $teamId, ?int $userId): string` — nur aus „abgelehnt“; setzt `versandweg = portal`, `freigabe_pflicht = false`, startet Welle 1; darf jeder Disponent
  - `wartend(?int $teamId = null): int`
  - Der Start der Welle geht über eine überschreibbare Stelle (wie `SendDispoJobWave::folgewelleStarten`), damit Tests ohne Bus zählen können.

**Regeln:**
- Entscheidung per bedingtem Update (`whereNull('freigegeben_at')->whereNull('freigabe_abgelehnt_at')`); nur bei 1 betroffener Zeile geht es weiter. Danach die erste ungestartete Welle: liegt `scheduled_for` in der Vergangenheit oder ist null, auf null setzen und sofort einreihen; liegt es in der Zukunft, mit dieser Verzögerung.
- `Show`: Zustandszeile mit Wer/Wann (beantragt, freigegeben, abgelehnt + Grund). Knöpfe „Freigeben“/„Ablehnen“ nur für den Freigeber, Prüfung zusätzlich im Dienst. Bestätigungstext wörtlich aus Spec 6.4, N = `welle_max` oder „alle passenden“. Bei „abgelehnt“: „Stattdessen im Portal bereitstellen“ und der bestehende Weg zum Schließen. `neueWelle()` verweigert, solange `!darfSenden()` („Die Anfrage wartet auf Freigabe.“ bzw. „Die Freigabe wurde abgelehnt.“), und prüft die Vorlage nur bei Weg `whatsapp`. Portal-Wellen heißen „im Portal bereitgestellt“ statt „gesendet“; der Weg steht im Kopf der Anfrage.
- `Index`: wartende Anfragen zuerst, Kennzeichen „wartet auf Freigabe“ / „Freigabe abgelehnt“, Zähler-Pille oben („N Anfragen warten auf Freigabe“). `sidebar`: derselbe Zähler neben dem bestehenden `dispoJobsOffen`, in `try/catch` wie die Nachbarn.

- [ ] Tests: Fremder ⇒ `nicht_berechtigt`, nichts geändert; Freigeber ⇒ `ok`, Felder gesetzt, genau ein Start; zweiter Aufruf ⇒ `nicht_wartend`, kein zweiter Start (Review Focus 2); ablehnen nach freigeben ⇒ `nicht_wartend`; `scheduled_for` gestern ⇒ Start ohne Verzögerung, `scheduled_for` null (Review Focus 1); ablehnen speichert Grund gekürzt; `insPortal` nur aus abgelehnt, danach `istPortal()` und `darfSenden()`; `wartend()` zählt geschlossene und entschiedene nicht; `Show::neueWelle` bei wartender Anfrage legt keine Welle an.
- [ ] Implementieren, Blade-Check, Suite, Commit.

### Task 6: Angebote im Portal

Spec 6.5.

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/JobOffersLaden.php`
- Modify: `src/Livewire/Public/JobOffers.php`, `resources/views/livewire/public/job-offers.blade.php`, `src/Livewire/Public/PortalShell.php`, `resources/views/livewire/public/portal-shell.blade.php`, `src/Livewire/Public/EmployeeAssignments.php`, `resources/views/livewire/public/employee-assignments.blade.php`
- Test: `tests/Integration/JobOffersLadenTest.php`, bestehende `JobOffersPageTest.php`/`JobOffersAnfrageSeiteTest.php` bleiben grün; Wiring-Test für die Blades

**Interfaces — Produces:**
- `JobOffersLaden::gruppen(array $employeeIds, int $ankerId, string $firstName, ?int $anfrageId = null): array` — genau die bisherige Rückgabe von `JobOffers::angebote()`; die Methode dort ruft nur noch den Dienst.
- `JobOffersLaden::zusagbar(array $gruppen): int` — Schichten mit `!accepted && !declined && !voll && !tag_belegt`.
- `JobOffersLaden::anzahl(array $employeeIds, int $ankerId): int` — `zusagbar(gruppen(...))`, 0 bei gesperrtem Zugang (`JobOffersZugang::gesperrt`).
- `JobOffers::mount(string $token, bool $eingebettet = false)`, `#[Locked] $eingebettet`; nach Zusage/Absage `dispatch('angebote-geaendert')`.

**Verhalten:**
- `job-offers.blade.php` eingebettet: Klasse `rg-crew eingebettet`, ohne eigenen Kopf/Begrüßung (Muster `employee-assignments.blade.php`); ohne Angebote ein kurzer Satz „Gerade gibt es keine freien Einsätze für dich.“ (Sie-Form nach Anrede der Person).
- `PortalShell`, Reiter `jobs`: oben Abschnitt „Freie Einsätze“ mit `<livewire:recruiting.public.job-offers :token="$token" :eingebettet="true" lazy wire:key="angebote-im-portal" />`, darunter wie bisher die Einsätze. (Entscheidung: kein fünfter Navigationspunkt.)
- `PortalShell`, Startseite: über „Dein nächster Einsatz“ eine antippbare Karte, nur bei `N > 0`: „1 neuer Einsatz für dich“ / „N neue Einsätze für dich“ bzw. Sie-Form; Tipp setzt `tab = 'jobs'`. Zahl aus `JobOffersLaden::anzahl`, nur im Zustand `verified`. `PortalShell` hört auf `angebote-geaendert` und rendert neu (Review Focus 5).
- `EmployeeAssignments`, nur **nicht** eingebettet: dieselbe Karte, als Link auf `route('recruiting.public.job-offers'…)` bzw. `/recruiting/jobs/{token}` (Routenname in `routes/public.php` nachsehen). Fehler beim Zählen ⇒ keine Karte, Seite bleibt (try/catch, Nebeninformation).
- Öffnet das Portal mit `?tab=jobs`, startet es im Reiter `jobs` (für den Push-Tipp, Task 8); nur die vier bekannten Reiternamen zulassen.

- [ ] Tests: `gruppen` liefert für die Fixtures aus `JobOffersPageTest` dasselbe wie zuvor (bestehende Tests grün); `zusagbar` zählt zugesagte, abgesagte, volle, tag-belegte nicht; `anzahl` = Zahl der Zeilen mit Zusage-Knopf; gesperrtes Portal ⇒ 0; Portal- und WhatsApp-Angebote (`kanal`) zählen beide; Angebot einer nicht laufenden Anfrage (wartet auf Freigabe ⇒ Status `entwurf`) zählt nicht.
- [ ] Implementieren, `php tools/blade-check.php`, `SharedPartialContractTest`, Suite, Commit.

### Task 7: Push im Server

Spec 6.7 (Speichern, Auslöser, Text, Technik).

**Files:**
- Create: `src/Services/Push/PushTransport.php` (Interface), `src/Services/Push/WebPushTransport.php`, `src/Services/Push/WebPortalPush.php`, `src/Services/Push/PushAbos.php`, `src/Jobs/SendPortalPush.php`
- Modify: `composer.json` (`"minishlink/web-push": "^9.0"`; falls mit PHP ^8.2 nicht auflösbar, die höchste passende Hauptversion und im Commit begründen), `config/recruiting.php` (`push.vapid.public|private|subject` aus `RECRUITING_VAPID_PUBLIC_KEY`, `RECRUITING_VAPID_PRIVATE_KEY`, `RECRUITING_VAPID_SUBJECT`), `src/RecruitingServiceProvider.php` (Bindung `PortalPush` → `WebPortalPush`, `PushTransport` → `WebPushTransport`)
- Test: `tests/Integration/PortalPushTest.php`

**Interfaces:**
- Consumes: `PortalPush` (Task 3), `RecPushAbo` (Task 1), `DispoIdentityResolver::groupFor`.
- Produces:
  - `interface PushTransport { /** @return 'ok'|'erloschen'|'fehler' */ public function senden(RecPushAbo $abo, array $payload): string; }`
  - `WebPushTransport`: nutzt `Minishlink\WebPush\WebPush`; HTTP 404/410 ⇒ `erloschen`; fehlt die Klasse oder ein Schlüssel ⇒ `fehler`, ohne zu werfen.
  - `WebPortalPush::aktiv()`: Schlüssel gesetzt **und** `class_exists(\Minishlink\WebPush\WebPush::class)`.
  - `WebPortalPush::neueAngebote(array $employeeIds)`: reiht `SendPortalPush` ein (nichts bei `!aktiv()` oder leerer Liste). `probe(int $employeeId)` ebenso mit Probetext.
  - `SendPortalPush(array $employeeIds, string $art)` (`'angebote'|'probe'`): je Person (über die Identitäts-Gruppe) alle Abos; Payload `{title, body, url}`; `ok` ⇒ `last_ok_at`, `erloschen` ⇒ Abo löschen, `fehler` ⇒ bleibt; wirft nie.
  - Texte: Angebote „Neue Einsätze für dich“ / „Schau ins Portal und sag zu.“; Probe „Benachrichtigungen sind eingeschaltet“ / „So melden wir uns bei neuen Einsätzen.“ URL: `/recruiting/mitarbeiter/neu/{portal_token}?tab=jobs` des Datensatzes, an dem das Abo hängt.
  - `PushAbos::speichern(int $employeeId, array $abo, ?string $userAgent): bool` — `$abo = ['endpoint' => …, 'keys' => ['p256dh' => …, 'auth' => …]]`; nur `https://`-Endpunkte, Längen geprüft; `updateOrCreate` über `endpoint_hash`, Person wird überschrieben (Review Focus 4). `PushAbos::loeschen(int $employeeId, array $gruppe, string $endpoint): void` — nur Abos der eigenen Gruppe.

- [ ] Tests mit Transport-Attrappe: Person mit zwei Abos ⇒ zwei Sendungen; `erloschen` ⇒ Abo weg; `fehler` ⇒ bleibt, Job endet ohne Ausnahme; Attrappe wirft ⇒ Job endet ohne Ausnahme; Abo am Alt-Datensatz derselben Gruppe wird erreicht; Payload-Text exakt wie oben und ohne VA-Namen; `speichern` zweimal gleicher Endpunkt für zwei Personen ⇒ eine Zeile, zweite Person; `http://`-Endpunkt abgelehnt; `loeschen` fremder Gruppe wirkungslos; `aktiv()` false ohne Schlüssel und dann kein Einreihen.
- [ ] Implementieren. Die Bibliothek ist in `meingedeck/vendor` nicht installiert und wird dort **nicht** installiert: Tests laufen nur über die Attrappe, `WebPushTransport` wird per `class_exists` geschützt und nicht im Test geladen.
- [ ] Suite, Commit.

### Task 8: Portal als App und Push einschalten

Spec 6.6, 6.7 (Einschalten).

**Files:**
- Create: `src/Http/Controllers/PortalAppController.php`, `resources/pwa/sw.js`, `resources/pwa/icon-192.png`, `resources/pwa/icon-512.png`, `resources/views/livewire/public/_portal-app-karte.blade.php`
- Modify: `routes/public.php`, `resources/views/layouts/portal.blade.php`, `src/Livewire/Public/PortalShell.php`, `resources/views/livewire/public/portal-shell.blade.php`
- Test: `tests/Unit/PortalAppControllerTest.php`, `tests/Integration/PortalPushAboTest.php`, Wiring-Test Blade

**Interfaces:**
- Consumes: `PortalPush::aktiv/probe`, `PushAbos` (Task 7), `?tab=jobs` (Task 6).
- Produces — Routen unter dem bestehenden Präfix `/recruiting`:
  - `GET /recruiting/portal-app/manifest/{token}.webmanifest` → `PortalAppController::manifest` — `name` „Mein Rheingedeck“, `short_name` „Rheingedeck“, `display` `standalone`, `scope` `/recruiting/`, `start_url` `/recruiting/mitarbeiter/neu/{token}`, `theme_color`/`background_color` `#0e535e`/`#EDF1F2`, zwei Symbole. Unbekannter Token ⇒ 404. Content-Type `application/manifest+json`.
  - `GET /recruiting/portal-app/sw.js` → `sw` mit `Service-Worker-Allowed: /recruiting/`, `Content-Type: application/javascript`.
  - `GET /recruiting/portal-app/icon-{size}.png` (`192|512`).
  - `PortalShell::pushAboSpeichern(array $abo): void` (nur `verified`; speichert, dann `probe`), `PortalShell::pushAboLoeschen(string $endpoint): void`.

**Verhalten:**
- Layout: `<link rel="manifest">` auf die Token-Route, `theme-color`, `apple-touch-icon`, `apple-mobile-web-app-capable`. Symbole: schlichtes Quadrat in `#0e535e` mit hellem „RG“, per Skript erzeugt (GD oder PIL), als Dateien eingecheckt.
- `sw.js`: nur `push` (zeigt `title`/`body`, merkt `url`) und `notificationclick` (vorhandenes Fenster fokussieren und dorthin navigieren, sonst öffnen). Kein Caching von Seiten.
- Karte auf der Startseite (nur `verified`), wegklickbar (Merker in `localStorage`, alles in try/catch):
  - läuft als App (`display-mode: standalone` oder `navigator.standalone`) ⇒ Installationsteil ausgeblendet;
  - `beforeinstallprompt` empfangen ⇒ Knopf „Auf den Startbildschirm“ ruft `prompt()`;
  - iPhone/iPad in Safari ⇒ Anleitung in zwei Schritten („Teilen“-Symbol, dann „Zum Home-Bildschirm“), kein Knopf;
  - sonst kein Installationsteil.
  - Push-Teil nur wenn `PortalPush::aktiv()` und der Browser `PushManager` + `Notification` kennt; auf dem iPhone außerhalb der App stattdessen der Hinweis „Erst auf den Startbildschirm legen, dann lassen sich Benachrichtigungen einschalten.“ Knopf „Benachrichtigungen einschalten“ ⇒ Service Worker registrieren (Scope `/recruiting/`), Erlaubnis nur nach diesem Tipp erfragen, mit dem öffentlichen VAPID-Schlüssel abonnieren, `$wire.pushAboSpeichern(...)`. Bei abgelehnter Erlaubnis ein ruhiger Satz, kein erneutes Fragen. Eingeschaltet ⇒ „Benachrichtigungen sind an“ + „Ausschalten“.
- Texte in `$duzen`-Form doppelt.

- [ ] Tests: Manifest für bekannten Token enthält `start_url` mit genau diesem Token und `scope /recruiting/`; unbekannter Token 404; `sw` setzt beide Header; `pushAboSpeichern` im Zustand ≠ `verified` speichert nichts; im Zustand `verified` entsteht ein Abo und `probe` wird einmal gerufen (Attrappe); `pushAboLoeschen` entfernt nur das eigene; Blade-Wiring (Karte nur im `verified`-Zweig, Manifest-Link im Layout).
- [ ] Implementieren, Blade-Check, Suite, Commit.

### Task 9: Abschluss

- [ ] Ganze Suite + `php tools/blade-check.php`; Zahlen notieren.
- [ ] Spec §9 um die tatsächlichen Deploy-Schritte ergänzen: Migrationen, `queue:restart`, `view:clear`, Befehl zum Erzeugen der VAPID-Schlüssel, drei Umgebungsvariablen, Hinweis dass der Bump die neue Abhängigkeit mitzieht.
- [ ] Commit. Kein Merge, kein Push.
