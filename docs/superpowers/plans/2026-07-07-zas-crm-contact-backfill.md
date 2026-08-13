# ZAS-CRM-Kontakt-Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Für Mitarbeiter ohne CRM-Kontakt-Link (primär: aus ZAS importierte MA) den passenden CRM-Kontakt finden (E-Mail → Telefon) oder neu anlegen und via `crm_contact_links` verlinken — damit `sendPortalNotification()` (WhatsApp-Portal-Einladung) und die Kommunikations-Welt ohne Sonderfälle funktionieren.

**Architecture:** Ein Service `ZasEmployeeContactLinker` mit getrennten Schritten `decide()` (reine Entscheidung — trägt den `--dry-run`) und `execute()` (schreibt in Transaction). Drumherum ein idempotenter Artisan-Command `recruiting:zas-crm-contact-backfill` (Auswahl: aktive MA ohne `crmContactLinks` → zweiter Lauf findet nichts mehr). Später kann der Importer denselben Service als Einzeiler-Hook nutzen (bewusst NICHT in diesem Plan).

**Tech Stack:** Laravel, bestehende CRM-Models (`Platform\Crm\Models\*` — nur *genutzt*, kein CRM-Code geändert), `libphonenumber` (via CRM-Abhängigkeit vorhanden).

## Global Constraints

- **Keine PHPUnit-Harness** (bewusst). Verifikation = `php -l` + Server-`--dry-run` als End-to-End-Test (DB-lastige Logik ist lokal nicht lauffähig). Erwarteter Prod-Testfall: Markus (#34) — hat E-Mail + Telefon, existiert als GF vermutlich schon im CRM → sollte als `LINK` (Match) erscheinen, nicht als `CREATE`.
- **Kein Edit außerhalb `platforms-recruiting`** — CRM-Models werden nur importiert/genutzt (etabliertes Muster: `CreateEmployeeFromApplicantService`, `IncomingApplicationService`).
- **Non-destruktiv:** bestehende Kontakte werden nur verlinkt, niemals verändert (keine Daten-Anreicherung am gematchten Kontakt).
- **`linkable_type` via `$employee->getMorphClass()`** — `RecEmployee` ist NICHT in der Morph-Map → FQCN. Niemals String hardcoden.
- **`team_id` + `created_by_user_id` explizit setzen** — der `CrmContactLink::booted()`-Autofill greift nur mit eingeloggtem User; der Command läuft unauthentifiziert.
- Branch `feat/zas-crm-contact-backfill`, am Ende Merge → main + meingedeck-Bump.

## Design-Entscheidungen (aus Brainstorming + CRM-Recherche)

- **Match-Kaskade** (Basis: Konvention aus `IncomingApplicationService::findExistingCrmContact`, verschärft nach Review):
  1. E-Mail via `whereRaw('LOWER(email_address) = ?')` (kollationsunabhängig — das CRM speichert gemischte Schreibweise, kein Lowercase-Mutator), team-gescoped, nur aktive Kontakte. **Nur linken wenn GENAU EIN Treffer** — bei mehreren → `skip` mit Grund `mehrdeutig` (Blind-Match würde die spätere Portal-Einladung an die falsche Person schicken).
  2. Telefon: MA-Nummer **erst per libphonenumber normalisieren** (Region DE → E164 → Ziffern; „0151 23456789" → `4915123456789`) — der naive Ziffern-Suffix scheitert an führender Null vs. `49` (empirisch verifiziert). Nicht parsebar/ungültig → kein Phone-Match-Versuch. Match auf `international` ODER `raw_input`; ebenfalls **nur bei genau einem Treffer**, sonst `skip mehrdeutig`.
  3. kein Treffer → `CrmContact` neu (first/last_name Pflicht, birth_date, `contact_status_id` ACTIVE, alle Lookups nullable) + E-Mail (`email_type_id` PRIVATE→aktiv→beliebig) + Telefon (libphonenumber parse Region DE → E164/`national`/`country_code`; `phone_type_id` MOBILE→aktiv→beliebig; invalid/unparsebar → Kontakt OHNE Telefon + Warnung — sonst stünde eine Nummer ohne `international` da, die `sendPortalNotification` eh nicht nutzen kann)
- **`created_by_user_id = null`** ist belegt safe: `crm_contacts` nullable ab initial; `crm_contact_links` nullable seit Migration `2026_02_18_220000`; Konvention für unauthentifizierte Flows ist explizit `null` (Präzedenz: `IncomingApplicationService:268`). Kein System-User vorhanden/nötig.
- **Doppel-Link möglich, kein DB-Netz:** Unique-Constraint liegt auf dem Tripel `(contact_id, linkable_type, linkable_id)` — zwei MA können denselben Kontakt verlinken. Deshalb der Eindeutigkeits-Guard in der Kaskade (statt Vertrauen auf die DB).
- **Command-Auswahl:** Default = ALLE aktiven MA ohne `crmContactLinks` (heilt auch etwaige Altfälle); `--imported-only` schränkt auf `rec_zas_inbound_file_id IS NOT NULL` ein; `--limit=N` für Etappen. Idempotenz über die Auswahl selbst — kein neuer Marker nötig.
- **Fehler pro MA isoliert** (try/catch, Zähler `failed`), Schreiben je MA in `DB::transaction`.
- **Kein Name am MA** (first+last leer) → `skip` mit Grund (CrmContact verlangt beide NOT NULL).

---

### Task 1: Service `ZasEmployeeContactLinker`

**Files:**
- Create: `src/Services/Zas/ZasEmployeeContactLinker.php`

**Interfaces:**
- Produces: `decide(RecEmployee $employee): array` → `['action'=>'link','contact_id'=>int,'matched_by'=>'email'|'phone','contact_name'=>string]` | `['action'=>'create','email'=>?string,'phone'=>?string]` | `['action'=>'skip','reason'=>string]`.
- Produces: `execute(RecEmployee $employee, array $decision, ?int $userId = null): array` → `['contact_id'=>int,'warnings'=>string[]]` (nur für action link/create aufrufen).

- [ ] **Step 1: Service schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmContactStatus;
use Platform\Crm\Models\CrmEmailType;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Findet (oder erstellt) den CRM-Kontakt zu einem RecEmployee und verlinkt
 * ihn via crm_contact_links — Grundlage fuer Kommunikation (WhatsApp-Portal-
 * Einladung, Threads) bei MA, die nicht aus dem Recruiting-Flow stammen.
 * Der ZAS-Import legt keine Kontakte an; Recruiting-MA bekommen sie beim
 * Anlegen aus dem Bewerber gespiegelt (CreateEmployeeFromApplicantService).
 *
 * Match-Kaskade (non-destruktiv, Konvention wie IncomingApplicationService):
 *   1. E-Mail exakt (lowercased, team-gescoped, aktive Kontakte)
 *   2. Telefon per Ziffern-Suffix auf international/raw_input
 *   3. kein Treffer -> neuen Kontakt anlegen (Name, Geburtsdatum,
 *      E-Mail + Telefon als primaere Eintraege)
 *
 * decide() trifft nur die Entscheidung (traegt den --dry-run des Commands),
 * execute() schreibt — getrennt, damit Match-Entscheidungen VOR dem
 * Ausfuehren geprueft werden koennen (falscher Match = WhatsApp mit
 * Login-Hinweisen an die falsche Person).
 */
class ZasEmployeeContactLinker
{
    public function decide(RecEmployee $employee): array
    {
        if ($employee->crmContactLinks()->exists()) {
            return ['action' => 'skip', 'reason' => 'hat bereits CRM-Kontakt-Link'];
        }

        $email = mb_strtolower(trim((string) $employee->email));
        $phone = trim((string) $employee->phone);

        if ($email !== '') {
            // LOWER() statt Kollations-Vertrauen: CRM speichert gemischte
            // Schreibweise (kein Lowercase-Mutator am Model).
            $matches = CrmContact::where('team_id', $employee->team_id)
                ->where('is_active', true)
                ->whereHas('emailAddresses', fn ($q) => $q->whereRaw('LOWER(email_address) = ?', [$email]))
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $this->linkDecision($matches->first(), 'email');
            }
            if ($matches->count() > 1) {
                return ['action' => 'skip', 'reason' => "mehrdeutig: E-Mail '{$email}' matcht mehrere Kontakte — bitte manuell zuordnen"];
            }
        }

        // Suffix-Match nur mit normalisierter Nummer (E164-Ziffern) — der
        // naive Ziffern-Vergleich scheitert an fuehrender 0 vs. Laendercode
        // ("0151..." matcht nie "+49151..."). Unparsebar -> kein Match-Versuch.
        $needle = $this->normalizedPhoneDigits($phone);
        if ($needle !== null) {
            $matches = CrmContact::where('team_id', $employee->team_id)
                ->where('is_active', true)
                ->whereHas('phoneNumbers', function ($q) use ($needle) {
                    $q->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $needle])
                      ->orWhereRaw("REPLACE(REPLACE(raw_input, ' ', ''), '-', '') LIKE ?", ['%' . $needle]);
                })
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $this->linkDecision($matches->first(), 'phone');
            }
            if ($matches->count() > 1) {
                return ['action' => 'skip', 'reason' => 'mehrdeutig: Telefon matcht mehrere Kontakte — bitte manuell zuordnen'];
            }
        }

        if (trim((string) $employee->first_name) === '' && trim((string) $employee->last_name) === '') {
            return ['action' => 'skip', 'reason' => 'kein Name am MA (CRM-Kontakt braucht first/last_name)'];
        }

        return [
            'action' => 'create',
            'email'  => $email !== '' ? $email : null,
            'phone'  => $phone !== '' ? $phone : null,
        ];
    }

    /**
     * Fuehrt eine decide()-Entscheidung aus (nur action link/create).
     *
     * @return array{contact_id: int, warnings: string[]}
     */
    public function execute(RecEmployee $employee, array $decision, ?int $userId = null): array
    {
        return DB::transaction(function () use ($employee, $decision, $userId) {
            $warnings = [];

            if ($decision['action'] === 'link') {
                $contactId = (int) $decision['contact_id'];
            } else {
                $contact = CrmContact::create([
                    'first_name'         => trim((string) $employee->first_name) ?: '-',
                    'last_name'          => trim((string) $employee->last_name) ?: '-',
                    'birth_date'         => $employee->birth_date,
                    'team_id'            => $employee->team_id,
                    'created_by_user_id' => $userId,
                    // Fallback-Kette wie bei email/phone-Typen: ACTIVE ist per
                    // Seeder ueblich, aber nicht DB-garantiert. Spalte ist
                    // nullable — explizites null wuerde aber auch den
                    // Spalten-Default (1) aushebeln -> lieber bester Treffer.
                    'contact_status_id'  => CrmContactStatus::where('code', 'ACTIVE')->value('id')
                        ?? CrmContactStatus::where('is_active', true)->value('id')
                        ?? CrmContactStatus::query()->value('id'),
                    'is_active'          => true,
                ]);
                $contactId = $contact->id;

                if (!empty($decision['email'])) {
                    $emailTypeId = CrmEmailType::where('code', 'PRIVATE')->value('id')
                        ?? CrmEmailType::where('is_active', true)->value('id')
                        ?? CrmEmailType::query()->value('id');
                    if ($emailTypeId) {
                        $contact->emailAddresses()->create([
                            'email_address' => $decision['email'],
                            'email_type_id' => $emailTypeId,
                            'is_primary'    => true,
                            'is_active'     => true,
                        ]);
                    } else {
                        $warnings[] = 'kein CrmEmailType vorhanden — E-Mail nicht angelegt';
                    }
                }

                if (!empty($decision['phone'])) {
                    $phoneResult = $this->createPhone($contact, $decision['phone']);
                    if ($phoneResult !== true) {
                        $warnings[] = $phoneResult;
                    }
                }
            }

            CrmContactLink::firstOrCreate([
                'contact_id'    => $contactId,
                'linkable_type' => $employee->getMorphClass(),
                'linkable_id'   => $employee->id,
            ], [
                'team_id'            => $employee->team_id,
                'created_by_user_id' => $userId,
            ]);

            return ['contact_id' => $contactId, 'warnings' => $warnings];
        });
    }

    /** Einheitliches link-Decision-Shape. */
    protected function linkDecision(CrmContact $contact, string $matchedBy): array
    {
        return [
            'action'       => 'link',
            'contact_id'   => $contact->id,
            'matched_by'   => $matchedBy,
            'contact_name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
        ];
    }

    /**
     * Normalisiert eine Roh-Nummer zu E164-Ziffern fuer den Suffix-Match
     * ("0151 23456789" -> "4915123456789"). Unparsebar/ungueltig -> null.
     */
    protected function normalizedPhoneDigits(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($raw, 'DE');
            if (!$util->isValidNumber($parsed)) {
                return null;
            }
        } catch (NumberParseException) {
            return null;
        }
        return preg_replace('/[^0-9]/', '', $util->format($parsed, PhoneNumberFormat::E164));
    }

    /**
     * Telefonnummer parsen (libphonenumber, Region DE) + anlegen.
     * Ungueltig/unparsebar -> Kontakt bleibt ohne Nummer (Warn-Text zurueck);
     * eine Nummer ohne `international` koennte sendPortalNotification eh
     * nicht nutzen.
     *
     * @return true|string true bei Erfolg, sonst Warn-Text
     */
    protected function createPhone(CrmContact $contact, string $raw): bool|string
    {
        $phoneTypeId = CrmPhoneType::where('code', 'MOBILE')->value('id')
            ?? CrmPhoneType::where('is_active', true)->value('id')
            ?? CrmPhoneType::query()->value('id');
        if (!$phoneTypeId) {
            return 'kein CrmPhoneType vorhanden — Telefon nicht angelegt';
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($raw, 'DE');
            if (!$util->isValidNumber($parsed)) {
                return "Telefon '{$raw}' ungueltig — nicht angelegt";
            }
        } catch (NumberParseException) {
            return "Telefon '{$raw}' nicht parsebar — nicht angelegt";
        }

        $contact->phoneNumbers()->create([
            'raw_input'     => $raw,
            'international' => $util->format($parsed, PhoneNumberFormat::E164),
            'national'      => $util->format($parsed, PhoneNumberFormat::NATIONAL),
            'country_code'  => $util->getRegionCodeForNumber($parsed),
            'phone_type_id' => (int) $phoneTypeId,
            'is_primary'    => true,
            'is_active'     => true,
        ]);

        return true;
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Services/Zas/ZasEmployeeContactLinker.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Services/Zas/ZasEmployeeContactLinker.php
git commit -m "feat(zas): ZasEmployeeContactLinker — CRM-Kontakt matchen/anlegen + verlinken"
```

---

### Task 2: Command `recruiting:zas-crm-contact-backfill` + Registrierung

**Files:**
- Create: `src/Console/Commands/ZasCrmContactBackfill.php`
- Modify: `src/RecruitingServiceProvider.php` (Command im `$this->commands([...])`-Block ergänzen)

**Interfaces:**
- Consumes: `ZasEmployeeContactLinker::decide()/execute()` (Task 1).
- Produces: `php artisan recruiting:zas-crm-contact-backfill {--dry-run} {--limit=} {--imported-only}`.

- [ ] **Step 1: Command schreiben**

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeContactLinker;

/**
 * Backfill: CRM-Kontakte fuer Mitarbeiter ohne crm_contact_links —
 * primaer fuer aus ZAS importierte MA (der Import legt keine Kontakte an;
 * Recruiting-MA bekommen sie beim Anlegen gespiegelt). Ohne Kontakt sind
 * MA in der Kommunikations-Welt unsichtbar (keine WhatsApp-Portal-
 * Einladung, keine Thread-Zuordnung).
 *
 * Match-Kaskade je MA: E-Mail exakt -> Telefon (Ziffern-Suffix) -> neu.
 * Non-destruktiv: bestehende Kontakte werden nur verlinkt, nie veraendert.
 * Idempotent: Auswahl = MA ohne Link -> zweiter Lauf findet nichts mehr.
 *
 * Aufruf:
 *   php artisan recruiting:zas-crm-contact-backfill --dry-run
 *   php artisan recruiting:zas-crm-contact-backfill --limit=100
 *   php artisan recruiting:zas-crm-contact-backfill --imported-only
 *
 * Siehe docs/meingedeck/zas-mitarbeiter-import.md
 */
class ZasCrmContactBackfill extends Command
{
    protected $signature = 'recruiting:zas-crm-contact-backfill
        {--dry-run : Nur Match-Entscheidungen anzeigen, nichts schreiben}
        {--limit= : Max. Anzahl MA in diesem Lauf}
        {--imported-only : Nur aus ZAS importierte MA (rec_zas_inbound_file_id gesetzt)}';

    protected $description = 'Verlinkt/erstellt CRM-Kontakte fuer MA ohne Kontakt-Link (ZAS-Import-Backfill)';

    public function handle(ZasEmployeeContactLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $query = RecEmployee::query()
            ->where('is_active', true)
            ->whereDoesntHave('crmContactLinks')
            ->orderBy('id');

        if ($this->option('imported-only')) {
            $query->whereNotNull('rec_zas_inbound_file_id');
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $employees = $query->get();
        $counts = ['link' => 0, 'create' => 0, 'skip' => 0, 'failed' => 0];

        foreach ($employees as $employee) {
            $name = trim(($employee->last_name ?? '') . ', ' . ($employee->first_name ?? ''));
            try {
                $decision = $linker->decide($employee);

                if ($decision['action'] === 'skip') {
                    $counts['skip']++;
                    $this->line("SKIP   #{$employee->id} {$name} — {$decision['reason']}");
                    continue;
                }

                if ($decision['action'] === 'link') {
                    $counts['link']++;
                    $this->line(($dryRun ? 'WUERDE ' : '') . "LINK   #{$employee->id} {$name} -> Kontakt #{$decision['contact_id']} ({$decision['contact_name']}, Match: {$decision['matched_by']})");
                } else {
                    $counts['create']++;
                    $this->line(($dryRun ? 'WUERDE ' : '') . "CREATE #{$employee->id} {$name} (E-Mail: " . ($decision['email'] ?? '—') . ', Tel: ' . ($decision['phone'] ?? '—') . ')');
                }

                if (!$dryRun) {
                    $result = $linker->execute($employee, $decision);
                    foreach ($result['warnings'] as $w) {
                        $this->warn("       #{$employee->id}: {$w}");
                    }
                }
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->error("FEHLER #{$employee->id} {$name} — {$e->getMessage()}");
            }
        }

        $this->newLine();
        $mode = $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT';
        $this->info("{$mode}: {$counts['link']} verlinkt, {$counts['create']} neu angelegt, {$counts['skip']} uebersprungen, {$counts['failed']} Fehler — von {$employees->count()} MA ohne Kontakt-Link");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
```

- [ ] **Step 2: Im ServiceProvider registrieren**

In `src/RecruitingServiceProvider.php` im bestehenden `$this->commands([ ... ])`-Block (bei den anderen `Console\Commands\…::class`-Einträgen) ergänzen:
```php
            \Platform\Recruiting\Console\Commands\ZasCrmContactBackfill::class,
```
(Exakten Stil der Nachbarzeilen übernehmen — kurzer Klassenname mit `use` oder FQCN, wie die bestehenden Einträge es tun.)

- [ ] **Step 3: Lint**

Run: `php -l src/Console/Commands/ZasCrmContactBackfill.php && php -l src/RecruitingServiceProvider.php`
Expected: 2× „No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
git add src/Console/Commands/ZasCrmContactBackfill.php src/RecruitingServiceProvider.php
git commit -m "feat(zas): Command recruiting:zas-crm-contact-backfill (dry-run/limit/imported-only)"
```

---

### Task 3: Doku

**Files:**
- Modify: `/Users/shaustein/Documents/dev/docs/meingedeck/zas-mitarbeiter-import.md` (liegt außerhalb des Repos — kein Commit)

- [ ] **Step 1: Neuen Abschnitt ergänzen** (nach „Herkunfts-Marker"):

Abschnitt `## CRM-Kontakt-Backfill (nach dem Import)` mit: Warum (importierte MA sind ohne CRM-Kontakt in der Kommunikations-Welt unsichtbar — WhatsApp-Portal-Einladung `sendPortalNotification()` zieht die Nummer vom Kontakt), Command-Aufrufe (`--dry-run`/`--limit`/`--imported-only`), Match-Kaskade (E-Mail → Telefon → neu; non-destruktiv), Idempotenz (Auswahl = ohne Link), Hinweis: Match-Entscheidungen VOR dem Echtlauf im dry-run prüfen (falscher Match = Einladung an falsche Person), geplanter Ablauf: Import → Backfill → Portal-Invites (separates Feature, noch nicht gebaut).

---

### Abschluss (nach Review): Merge + Deploy + Verifikation

- [ ] Merge `feat/zas-crm-contact-backfill` → `main` (--no-ff), Branch löschen, Push.
- [ ] meingedeck: `composer update martin3r/platform-recruiting` + composer.lock committen + pushen. (Keine Migration nötig — es entstehen keine neuen Spalten.)
- [ ] Server: ERST Kandidaten zählen (Default-Auswahl kann mehr als nur Importierte enthalten — Recruiting-MA ohne CRM-Link sind möglich):
  ```bash
  php artisan tinker --execute="print('ohne Link gesamt: '.\Platform\Recruiting\Models\RecEmployee::where('is_active',true)->whereDoesntHave('crmContactLinks')->count().PHP_EOL.'davon importiert: '.\Platform\Recruiting\Models\RecEmployee::where('is_active',true)->whereDoesntHave('crmContactLinks')->whereNotNull('rec_zas_inbound_file_id')->count().PHP_EOL);"
  ```
- [ ] Dann `php artisan recruiting:zas-crm-contact-backfill --dry-run` → Kandidaten-Anzahl muss zur Zählung passen; jede Match-Entscheidung prüfen (Markus #34 ist der bekannte Fall: als GF vermutlich `WUERDE LINK (Match: email|phone)` — ein `CREATE` bei ihm wäre ein Hinweis, dass die Kaskade den Bestandskontakt nicht findet → erst klären, nicht ausführen).
- [ ] Bei plausiblen Entscheidungen: echt laufen lassen (ggf. mit `--limit` in Etappen), danach Gegenprobe: zweiter `--dry-run` → „0 MA ohne Kontakt-Link" (bzw. nur noch begründete SKIPs).

## Self-Review

- **decide/execute-Trennung** trägt den dry-run vollständig (keine Schreib-Seiteneffekte in decide). ✅
- **Morph-Falle** (FQCN statt Alias) via `getMorphClass()` abgedeckt; unique-Triple + `firstOrCreate` macht das Verlinken idempotent. ✅
- **Unauth-Kontext**: team_id/created_by explizit — booted()-Autofill wird nicht gebraucht. ✅
- **Pflicht-FKs** (`phone_type_id`, `email_type_id`, first/last_name) mit Fallback-Ketten bzw. Skip-Guard behandelt. ✅
- **Placeholder-Scan:** keine. **Typen:** decide()-Shapes stimmen zwischen Task 1 (Producer) und Task 2 (Consumer) überein. ✅
