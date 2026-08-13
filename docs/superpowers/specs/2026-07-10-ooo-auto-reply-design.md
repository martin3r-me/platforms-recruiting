# HR-Abwesenheitsmodus (OOO-Auto-Reply) — Design

**Datum:** 2026-07-10
**Module:** platforms-recruiting (Hauptteil) + platform-crm (kleiner, freigegebener Eingriff)
**Status:** Entwurf (zur Review)

## Problem / Ziel

HR soll auf der Conversations-Seite (`/recruiting/conversations`) einen
Abwesenheitsmodus aktivieren können. Solange er aktiv ist, bekommt jede
eingehende WhatsApp-Nachricht (Bewerber + Mitarbeiter) automatisch ein
Abwesenheits-Template. Kundenanforderung dazu: Das System muss erkennen, dass
es sich um eine OOO-Antwort handelt — die ursprüngliche Nachricht bleibt
**ungelesen** und zählt weiter als **unbeantwortet/„verpasst"**, damit HR nach
dem Urlaub alles auf einen Blick sieht.

## Entschiedene Anforderungen

- **Drosselung:** max. 1 OOO-Send pro Konversation je 24h (nicht pro Nachricht).
- **Reichweite:** Bewerber- UND Mitarbeiter-Threads (= alles, was die
  Conversations-Ansicht zeigt); Helpdesk-/Sales-/Fremd-Kontexte nie.
- **Schalter:** teamweit; An/Aus + Vorplanung mit `von`-Datum + automatisches
  Ende am `wieder_da`-Datum (lazy, kein Cron).
- **Template-Variablen:** `{{von}}`, `{{bis}}`, `{{wieder_da}}` (Datumsangaben,
  `d.m.Y`). KEIN `{{name}}` — das Template startet generisch („Vielen Dank für
  deine Nachricht…"), dadurch keine Vornamen-Auflösung nötig.
- **Erkennung „zählt nicht als Antwort":** explizites Flag `is_auto_reply` an
  der Outbound-Nachricht (CRM-Eingriff freigegeben) — KEINE
  template_name-Heuristik. Der Mechanismus ist generisch und wird auch vom
  bestehenden Voice-Note-Auto-Reply gesetzt (fixt dort einen bestätigten
  latenten Bug: Voice-Hinweise zählen heute fälschlich als Antwort).
- **AutoPilot-Sends zählen weiterhin als Antwort** (bestätigt gewollt): sie
  beantworten die Konversation inhaltlich; hängende AutoPilot-Fälle haben
  eigene Sichtbarkeit (auto_pilot_state, HR-Desk). Manuelle/Bulk-Sends zählen
  ebenfalls als Antwort.
- **Kein Backfill** des einen historischen Voice-Auto-Replies (Datenlage:
  insgesamt 1 Send, 24.06., Template `t_deny_audio` — lohnt keinen Code).

## Einstellungen (bestehender Key-Value-Store `RecApplicantSettings`)

| Key | Typ | Bedeutung |
|-----|-----|-----------|
| `comms_ooo_enabled` | bool | Modus gewünscht (roher Schalter) |
| `comms_ooo_from` | `Y-m-d` | Abwesend ab → `{{von}}`; vor diesem Tag: Zustand „geplant" |
| `comms_ooo_until` | `Y-m-d` | Letzter Abwesenheitstag → `{{bis}}` (nur Template-Variable) |
| `comms_ooo_back_at` | `Y-m-d` | Wieder da → `{{wieder_da}}` **+ Auto-Off-Datum** |
| `comms_ooo_template_id` | int | OOO-Template (Auswahl im Einstellungen-Modal, neben Holding-/Voice-Template) |

Keine Migration nötig (Store existiert). Validierung beim Aktivieren:
`from <= until < back_at` und `back_at > heute`; alle drei Daten Pflicht.

## Architektur / Komponenten

### 1. `OooMode` (neu, pure, `src/Services/Comms/OooMode.php`)

Alleinige Source of Truth für den Modus-Zustand. **Kein Render-Reset, kein
Zurückschreiben von `enabled` irgendwo** — der Zustand ergibt sich rein aus
den Werten:

```php
final class OooMode
{
    public const STATE_OFF = 'off';
    public const STATE_PENDING = 'pending'; // geplant, noch nicht begonnen
    public const STATE_ACTIVE = 'active';

    /** @param ?string $fromYmd/$backAtYmd 'Y-m-d' oder null; $todayYmd 'Y-m-d' */
    public static function state(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): string
    {
        if (!$enabled || $fromYmd === null || $backAtYmd === null) {
            return self::STATE_OFF;      // fehlende Daten → nie „ewig aktiv"
        }
        if ($todayYmd >= $backAtYmd) {
            return self::STATE_OFF;      // lazy Auto-Off ab dem Wieder-da-Tag
        }
        if ($todayYmd < $fromYmd) {
            return self::STATE_PENDING;  // Vorplanung: enabled, aber noch nicht los
        }
        return self::STATE_ACTIVE;
    }

    public static function isActive(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): bool
    {
        return self::state($enabled, $fromYmd, $backAtYmd, $todayYmd) === self::STATE_ACTIVE;
    }
}
```

(String-Vergleich reicht bei `Y-m-d` — lexikographisch = chronologisch.)

### 2. CRM-Eingriff (freigegeben): `is_auto_reply` an der Nachricht

- **Migration** (platform-crm): `comms_whatsapp_messages` + Spalte
  `is_auto_reply` (boolean, default false). Kein zusätzlicher Index nötig
  (bestehender Composite `wa_messages_thread_created_index` auf
  `(comms_whatsapp_thread_id, created_at)` + Index auf `direction` reichen;
  Filterung auf kleinen Per-Thread-Mengen).
- **Model:** `CommsWhatsAppMessage` fillable + cast.
- **`WhatsAppMetaService::sendTemplate(..., bool $isAutoReply = false)`** —
  neuer optionaler Parameter, wird ins Message-Create geschrieben.
  Rückwärtskompatibel; kein bestehender Aufrufer ändert sich.
- **Semantik:** `is_auto_reply = true` heißt „automatische Sofort-Quittung,
  zählt nicht als menschliche Antwort". Setzen NUR: OOO-Handler und
  Voice-Note-Handler. NICHT: manuelle Sends, Bulk-Eingangsbestätigung,
  AutoPilot-Phasen-Templates.
- Das Thread-Rollup (`last_outbound_at`, `last_message_preview`) wird beim
  Senden **unverändert** gebumpt — Chat-UIs bleiben konsistent; die Wertung
  passiert ausschließlich in der Verpasst-Query (s.u.).

### 3. `HoldingTemplateSender` / `HoldingTemplateComponents` — Erweiterung

- `HoldingTemplateComponents::build(array $components, string $firstName, array $namedValues = [])`:
  `$namedValues` (z.B. `['von' => '14.07.2026', 'bis' => …, 'wieder_da' => …]`)
  füllt gleichnamige `{{param}}`-Platzhalter, Vorrang vor Beispielwerten;
  `{{name}}`-Logik unverändert (Holding/Voice unberührt). Pure, testbar.
- `sendToMany`/`sendOne`: optionale Parameter `array $namedValues = []` und
  `bool $isAutoReply = false`, beides nur durchgereicht.

### 4. `OooAutoReplyHandler` (neu, `src/Services/Comms/OooAutoReplyHandler.php`)

Eingehängt in `HandleWhatsAppInboundForRecruiting::handle` **vor** dem
Kontext-Gate (damit Mitarbeiter-Threads erfasst werden), in try/catch wie der
Voice-Hook — ein OOO-Fehler stoppt nie den Inbound-Flow. Gates in Reihenfolge:

1. **Kontext-Filter:** nur RecApplicant (morph/full), `RecEmployee::class`
   oder `context_model === null`. Alles andere (Helpdesk, Sales, …) → raus.
2. **`OooMode::isActive(...)`** mit heutigem Datum (Team-Timezone) → sonst raus.
3. **Blacklist-/Block-Gate (zweistufig):** Kontakt via `$thread->contact`,
   Fallback-Lookup über Telefonnummer nach dem CRM-Muster aus
   `WhatsAppMetaService::updateWhatsAppStatus()` (normalisieren, Match gegen
   `CrmPhoneNumber.international` in mehreren Schreibweisen: `+49…`, ohne
   `+`, raw). Skip wenn `is_blacklisted === true` ODER
   `contactStatus?->code === 'BLOCKED'`. Log-Event
   `ooo_autoreply_skipped_blacklisted`.
4. **Template konfiguriert + approved?** (bestehendes `resolveConfig` via
   Sender, settingsKey `comms_ooo_template_id`) → sonst raus.
5. **Throttle:** letzter Outbound auf dem Thread mit
   `template_name = <OOO-Template>` jünger als 24h → skip. Exakt das Muster
   aus `VoiceNoteAutoReplyHandler` (Keying: pro Thread pro template_name —
   Voice und OOO blockieren sich nachweislich nicht); Wiederverwendung des
   puren `VoiceNoteAutoReplyThrottle::shouldSkip()`.
6. **Senden:** `sendOne(..., namedValues: [von/bis/wieder_da als d.m.Y], isAutoReply: true)`.
7. **Log:** `CommsLog`-Event `ooo_autoreply_sent` (Erfolg/Fehler).

### 5. `VoiceNoteAutoReplyHandler` — minimale Anpassung

Setzt beim Senden künftig `isAutoReply: true`. **Sonst keinerlei
Verhaltensänderung** (gleiches Template, gleicher Throttle, gleiche Gates) —
nur die Wertung im Verpasst-Zähler ändert sich (Bugfix).

### 6. „Verpasst bleibt verpasst": `ConversationInboxService`

In `build()` UND `counts()` (Sidebar-Badge): statt `thread.last_outbound_at`
direkt zu verwenden, wird ein **effektiver letzter Ausgang** berechnet —
**eine** gruppierte Query (kein N+1):

```sql
SELECT comms_whatsapp_thread_id, MAX(created_at) AS last_human_outbound_at
FROM comms_whatsapp_messages
WHERE comms_whatsapp_thread_id IN (<Report-Thread-IDs>)
  AND direction = 'outbound'
  AND is_auto_reply = 0
GROUP BY comms_whatsapp_thread_id
```

**Warum `created_at` (nicht `sent_at`):** index-aligned mit dem bestehenden
Composite-Index `(comms_whatsapp_thread_id, created_at)`, konsistent mit dem
Throttle (`latest('created_at')`) und mit dem heutigen Rollup-Verhalten —
`last_outbound_at` wird auch bei fehlgeschlagenem Send gebumpt
(`sent_at ?? now()`), Fehlschläge zählen also heute schon als Antwort;
`created_at` ändert diese Semantik nicht und hat keine Null-Fälle.

**Null-Behandlung (kritisch):** Threads, deren einziger Outbound eine
Auto-Reply ist, fehlen im GROUP-BY-Ergebnis komplett — das ist der aktive
OOO-Normalfall, kein Sonderfall. `ConversationInboxService` behandelt
„Thread nicht im Ergebnis" als `lastOutboundAt = null` (= nie beantwortet).
**Ausdrücklich KEIN Fallback auf `thread.last_outbound_at`** — das Feld wurde
von der Auto-Reply gebumpt; ein Fallback würde still in genau den Bug
regressieren, den dieser Mechanismus fixt.

Dieser Wert ersetzt `last_outbound_at` als Input für
`ConversationEscalation::compute()` (die pure Klasse bleibt unverändert).
Wirkung:

- OOO-/Voice-Antworten zählen nicht als Antwort → Ampel/„verpasst" läuft
  weiter, als hätte niemand geantwortet — auch **nach** dem Urlaub, bis ein
  Mensch (oder AutoPilot) echt antwortet.
- `is_unread` bleibt unberührt — belegt: Inbound setzt es
  (`WhatsAppMetaService:251–255`), der Outbound-Send-Pfad schreibt nur
  `last_outbound_at` + Preview (`:433–437`); einzige `is_unread=false`-Schreiber
  sind `CommsWhatsAppThread::markAsRead()` (UI) und das MCP-PATCH-Tool.
- Die Query läuft **bewusst unconditional für alle Teams** (auch ohne
  OOO-Config): sie hängt am Flag, nicht an der Config, und fixt damit
  automatisch auch den Voice-Fall. Perf: eine gruppierte, index-gestützte
  Query pro build()/counts()-Aufruf, beschränkt auf die Thread-IDs des
  Reports (Threads mit ≥1 Eingang im Team) — kein N+1, Per-Thread-Mengen
  klein.
- Der template_name-Wechsel-Randfall des früheren Entwurfs **entfällt**
  (Flag statt Heuristik).

### 7. UI (Conversations-Seite, `Conversations/Index` + Blade)

Banner oben mit **drei Zuständen** aus `OooMode::state()` (nie aus dem rohen
`enabled`-Flag):

- **Aus:** Toggle „HR in Abwesenheit" → öffnet Mini-Formular: von (vorbefüllt
  heute), bis, wieder da (vorbefüllt bis+1, editierbar — Wochenend-Fall).
  Guard: Aktivieren nur, wenn OOO-Template konfiguriert (sonst Hinweis auf
  Einstellungen → Kommunikation).
- **Geplant:** „Abwesenheitsmodus geplant ab {von}" + Bearbeiten/Abbrechen.
- **Aktiv:** „Abwesenheitsmodus aktiv — wieder da am {wieder_da}" +
  Deaktivieren-Button (setzt `comms_ooo_enabled = false`).

Template-Auswahl: im bestehenden Einstellungen-Modal
(`applicant-settings-modal.blade.php`), gleiches Muster wie Holding-/Voice-
Template-Picker.

## Fehler- und Randfälle

- Send scheitert → CommsLog error, Inbound-Flow läuft weiter (try/catch).
- Template nicht mehr approved/gelöscht → `resolveConfig`-Fehler, kein Send,
  geloggt; Feature effektiv aus.
- Template nutzt eine Variable ohne Wert → bestehender Leer-Param-Guard
  (`hasEmptyRequiredParam`) überspringt den Send (verhindert Meta-Fehler 131008).
- `$thread->contact === null` UND kein Telefon-Match → kein Blacklist-Treffer
  → Send erlaubt (kontextloser Erstkontakt).
- `from`/`back_at` fehlen trotz `enabled` → `OooMode` → `off` (nie „ewig aktiv").
- AutoPilot läuft während OOO normal weiter (bewusst): neue Bewerber bekommen
  ggf. OOO-Notiz + Intake-Template — akzeptiert.

## Nebenbefund (out of scope, dokumentiert)

Die Blacklist (`is_blacklisted`) wird im gesamten WhatsApp-Inbound-Pfad
NICHT geprüft — eine gebannte Nummer, die erneut schreibt, legt heute einen
neuen Bewerber an. Der OOO-Handler bekommt sein eigenes Gate (s.o.); der
generelle Inbound-Gap ist ein separates Thema.

## Tests (pure-unit, Modul-Konvention)

- `OooMode::state/isActive` — Matrix: enabled × from (null/zukünftig/heute/
  vergangen) × backAt (null/vergangen/heute/zukünftig); erwartete Zustände
  off/pending/active inkl. Grenztage (today == from → active,
  today == backAt → off).
- `HoldingTemplateComponents::build` mit `namedValues`: Füllung von
  von/bis/wieder_da, Vorrang vor Beispielwerten, `{{name}}`-Logik intakt,
  fehlender Wert → leerer Param → `hasEmptyRequiredParam` greift.
- Bestehende `ConversationEscalation`-Tests bleiben unverändert gültig
  (pure Klasse untouched; nur ihr Input ändert sich).
- Wiring (Handler-Gates, Query, Blade, CRM-Spalte): manuelle Verifikation
  (kein testbench — Modul-Konvention). Manuelle Checkliste: Aktivieren mit
  Daten, Inbound → OOO kommt, 2. Inbound < 24h → kein 2. Send, Konversation
  bleibt ungelesen + „verpasst", geblockter Kontakt bekommt nichts,
  Zustand „geplant" feuert nicht, nach `wieder_da` feuert nichts mehr und
  Banner zeigt Aus. **Zusätzlich (Null-Fall):** aktiver OOO-Thread, dessen
  EINZIGER Outbound die Auto-Reply ist → erscheint weiterhin als
  „verpasst"/unbeantwortet (kein stiller Fallback auf
  `thread.last_outbound_at`).

## Betroffene Dateien

**platform-crm (freigegebener Eingriff):**
- Neu: Migration `add_is_auto_reply_to_comms_whatsapp_messages`
- Ändern: `src/Models/CommsWhatsAppMessage.php` (fillable/cast)
- Ändern: `src/Services/Comms/WhatsAppMetaService.php` (`sendTemplate`-Param → Message-Create)

**platforms-recruiting:**
- Neu: `src/Services/Comms/OooMode.php` + `tests/Unit/OooModeTest.php`
- Neu: `src/Services/Comms/OooAutoReplyHandler.php`
- Ändern: `src/Services/Comms/HoldingTemplateComponents.php` (+ Test-Erweiterung)
- Ändern: `src/Services/Comms/HoldingTemplateSender.php` (Durchreichen)
- Ändern: `src/Services/Comms/VoiceNoteAutoReplyHandler.php` (`isAutoReply: true`)
- Ändern: `src/Services/Comms/ConversationInboxService.php` (effektiver letzter Ausgang in build+counts)
- Ändern: `src/Listeners/HandleWhatsAppInboundForRecruiting.php` (Hook vor Kontext-Gate)
- Ändern: `src/Livewire/Conversations/Index.php` + `resources/views/livewire/conversations/index.blade.php` (Banner, 3 Zustände, Formular, Validierung)
- Ändern: Einstellungen-Modal (`applicant-settings-modal.blade.php` + zugehörige Livewire-Komponente): OOO-Template-Picker
