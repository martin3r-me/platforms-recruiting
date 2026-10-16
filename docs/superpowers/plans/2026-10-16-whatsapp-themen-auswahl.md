# WhatsApp-Themen-Auswahl + „Offen lassen" — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Schreibt ein aktiver Mitarbeiter nach 8 Stunden Ruhe auf den HR-Kanal, fragt das System per WhatsApp-Knöpfen nach dem Thema (Disposition / Lohn & Gehalt / HR allgemein) und leitet Disposition zur Dispo um bzw. beantwortet Lohn aus der Akte; HR kann Chats bewusst „offen lassen".

**Architecture:** Ein Handler im Inbound-Listener (vor OOO) entscheidet über den Auslöser und schickt die Themen-Frage als Meta-Interactive über einen kleinen Sender-Wrapper. Ein zweiter Handler verarbeitet die Klicks (IDs `topic_*`, `dispo_<channel_id>`): Dispo = Filial-Auswahl aus `DispoForwardChannels`, dann wa.me-Link **und** automatische Weiterleitung über `ConversationForwarder`, dann Stempel `routed_dispo`; Lohn = Antwort aus `OffenePunkte`. Zustand je Anliegen in `rec_conversation_topics`, Marker „offen lassen" in `rec_conversation_followups`; beides fließt in `InboxQuery`/`InboxRow` als Chips und Filter.

**Tech Stack:** Laravel 12, Livewire 3, platform-crm `WhatsAppMetaService::sendInteractive()` (803308f), PHPUnit 11 mit Capsule+SQLite (Vorbild `tests/Integration/QuickReplyStamperTest.php`), reine Klassen unit-getestet (Vorbild `QuickReply`).

**Spec:** `docs/superpowers/specs/2026-10-16-whatsapp-themen-auswahl-design.md` (S-Themen); Grundlage `2026-10-16-kommunikation-gelesen-erledigt-design.md`.

## Global Constraints

- Zweig `feat/ma-konto`. Nichts außerhalb des Moduls (platform-crm nur lesen). Tests: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`, Suite grün (`grep -q "^OK"`, Baseline 4915) vor jedem Commit.
- Feature-Schalter `comms_topic_enabled` Default **aus**. Ruhezeit `comms_topic_quiet_hours` Default **8** (Stunden, in beide Richtungen, inkl. Auto-Antworten).
- Auslöser nur auf HR-Kanälen (`RecruitingChannelResolver::channelIds($teamId)`), nur aktive Mitarbeiter (`EmployeeSenderResolver` Status `EMPLOYEE`), kein Button-Klick, kein `message_type = audio`; Bilder/Dokumente lösen aus.
- Meta-Grenzen: `button` ≤ 3 Knöpfe, Titel ≤ 20 Zeichen; `list` ≤ 10 Zeilen, Titel ≤ 24; `cta_url` genau ein Knopf. Button-IDs fest: `topic_dispo`, `topic_lohn`, `topic_hr`, `dispo_<channel_id>`.
- Alle Auto-Sends `isAutoReply: true`. Drei Abwehren wie `AufgabenSender`: Status `failed` = Fehlschlag, kein stiller Platzhalter, `send*()` im try/catch; ein Fehler kostet nie den Inbound-Flow.
- Texte als `$duzen`-Ternary (Team-Setting `use_informal_address` über `PublicAddressStyle::informal()`); Vorgabetexte in `ThemenTexte`, überschreibbar per Team-Einstellung.
- Stempel nur über `RecConversationHandled` (upsert-Muster `QuickReplyStamper`), nie `is_unread` direkt. Themen-Klicks (`topic_*`, `dispo_*`) stempelt **nur** der Themen-Handler, nie `QuickReplyStamper`.
- „Offen lassen"-Marker löst sich **nur** durch Klick „Erledigt" (kein Auto-Clear durch Antwort).
- Blade-Hausregeln (kein inline `@if` in Komponenten-Attributen, `@php` nur Block, keine geklebten Direktiven; `php tools/blade-check.php`), Settings als rohe `<input>`/`<select wire:model>` (Entangle-Falle). `#[Locked]` auf identitätstragenden Properties. Keine Eloquent-Schreibzugriffe auf `rec_employees`.
- Commit-Trailer: `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda`.

## Review Focus

1. **Antwort auf unsere eigene Nachricht innerhalb 8 h** (MA antwortet auf Erinnerung/Kampagne): darf KEINE Themen-Frage auslösen — Ruhezeit zählt Ausgänge inkl. Auto-Antworten. → Task 3 Test.
2. **Bewerber (kein aktiver MA) oder Dispo-Kanal:** nie eine Themen-Frage. → Task 3 Tests.
3. **Manipulierte Klick-ID** (`dispo_999` fremder Kanal, `topic_x`): nichts senden, nichts stempeln, nichts weiterleiten. → Task 4 Test.
4. **Doppelter Webhook** (Meta wiederholt den Klick): Themen-Klick idempotent — kein zweiter wa.me-Link, keine zweite Weiterleitung. → Task 4 Test (gleiche `origin_message_id`/Klick-ID bereits verarbeitet).
5. **Meta lehnt den Send ab** (`status = failed`): Zustand bleibt `asked`/unverändert, HR-Chat bleibt offen, Log-Zeile; kein Stempel `routed_dispo` ohne erfolgreichen Link. → Task 4 Test.

---

### Task 1: Schema, Modelle, Einstellungen, reine Klassen `ThemenTexte` und `ThemenAusloeser`

**Files:**
- Create: `database/migrations/2026_10_17_000001_create_rec_conversation_topics_table.php`
- Create: `database/migrations/2026_10_17_000002_create_rec_conversation_followups_table.php`
- Create: `src/Models/RecConversationTopic.php`, `src/Models/RecConversationFollowup.php`
- Modify: `src/Models/RecConversationHandled.php` (Konstante `REASON_ROUTED_DISPO = 'routed_dispo'`)
- Modify: `src/Models/RecApplicantSettings.php` (DEFAULT_SETTINGS)
- Create: `src/Support/Comms/ThemenTexte.php`, `src/Support/Comms/ThemenAusloeser.php`
- Test: `tests/Unit/Comms/ThemenTexteTest.php`, `tests/Unit/Comms/ThemenAusloeserTest.php`

**Interfaces:**
- Produces: Tabellen (unten); `RecConversationTopic` (`topic`: `dispo|lohn|hr|null`, `state`: `asked|chosen|akte_hint_sent|lohn_info_sent|routed`, `dispo_channel_id`, `origin_message_id`, `asked_at`, `chosen_at`); `RecConversationFollowup`; Settings-Keys `comms_topic_enabled` (false), `comms_topic_quiet_hours` (8), `comms_topic_lohn_url` (null), `comms_topic_text_ask`, `comms_topic_text_lohn_offen`, `comms_topic_text_lohn_ok` (alle null = Vorgabe); `ThemenTexte::frage(bool $duzen, ?string $override, ?string $oooPrefix): string`, `ThemenTexte::knoepfe(): list<array{id:string,title:string}>`, `ThemenTexte::lohnOffen(bool, ?string, list<string> $punkte): string`, `ThemenTexte::lohnOk(bool, ?string): string`, `ThemenTexte::filialKnopf(string $label): string` (≤ 20 Zeichen), `ThemenTexte::MAX_BUTTON = 20`, `MAX_LIST = 24`; `ThemenAusloeser::qualifiziert(array $k): ?string` (null = auslösen, sonst Grund).

- [ ] **Step 1: Failing Unit-Tests**

`tests/Unit/Comms/ThemenTexteTest.php`:
```php
<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Comms\ThemenTexte;

final class ThemenTexteTest extends TestCase
{
    public function test_drei_knoepfe_mit_festen_ids_und_titel_hoechstens_20_zeichen(): void
    {
        $k = ThemenTexte::knoepfe();
        $this->assertSame(['topic_dispo', 'topic_lohn', 'topic_hr'], array_column($k, 'id'));
        $this->assertSame(['Disposition', 'Lohn & Gehalt', 'HR allgemein'], array_column($k, 'title'));
        foreach ($k as $knopf) {
            $this->assertLessThanOrEqual(ThemenTexte::MAX_BUTTON, mb_strlen($knopf['title']));
        }
    }

    public function test_frage_in_du_und_sie_form_und_mit_abwesenheitsvorspann(): void
    {
        $this->assertStringContainsString('Worum geht es', ThemenTexte::frage(true, null, null));
        $this->assertStringContainsString('du', ThemenTexte::frage(true, null, null));
        $this->assertStringContainsString('Sie', ThemenTexte::frage(false, null, null));
        $mit = ThemenTexte::frage(true, null, 'Wir sind bis 20.10. nicht erreichbar.');
        $this->assertStringStartsWith('Wir sind bis 20.10. nicht erreichbar.', $mit);
        $this->assertStringContainsString('Worum geht es', $mit);
    }

    public function test_team_text_ueberschreibt_die_vorgabe(): void
    {
        $this->assertSame('Hallo! Was brauchst du?', ThemenTexte::frage(true, 'Hallo! Was brauchst du?', null));
    }

    public function test_lohn_offen_nennt_die_punkte(): void
    {
        $t = ThemenTexte::lohnOffen(true, null, ['Ausweis', 'Bankverbindung']);
        $this->assertStringContainsString('Ausweis', $t);
        $this->assertStringContainsString('Bankverbindung', $t);
        $this->assertStringContainsString('Abrechnung', $t);
    }

    public function test_filialknopf_wird_auf_20_zeichen_gekuerzt(): void
    {
        $this->assertSame('Dispo Mönchengladba…', ThemenTexte::filialKnopf('Dispo Mönchengladbach'));
        $this->assertSame('Dispo Köln', ThemenTexte::filialKnopf('Dispo Köln'));
    }
}
```

`tests/Unit/Comms/ThemenAusloeserTest.php` — `qualifiziert()` bekommt ein Array und gibt `null` (auslösen) oder einen Grund zurück:
```php
<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Comms\ThemenAusloeser;

final class ThemenAusloeserTest extends TestCase
{
    private function k(array $o = []): array
    {
        return $o + [
            'enabled' => true, 'hr_kanal' => true, 'aktiver_ma' => true,
            'quick_reply' => false, 'message_type' => 'text',
            'letzte_aktivitaet_ts' => null, 'jetzt_ts' => 1_000_000, 'ruhe_stunden' => 8,
            'fremder_kontext' => false,
        ];
    }

    public function test_loest_aus_wenn_alles_passt(): void
    {
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k()));
    }

    public function test_gruende(): void
    {
        $this->assertSame('schalter_aus', ThemenAusloeser::qualifiziert($this->k(['enabled' => false])));
        $this->assertSame('kein_hr_kanal', ThemenAusloeser::qualifiziert($this->k(['hr_kanal' => false])));
        $this->assertSame('kein_aktiver_ma', ThemenAusloeser::qualifiziert($this->k(['aktiver_ma' => false])));
        $this->assertSame('button_klick', ThemenAusloeser::qualifiziert($this->k(['quick_reply' => true])));
        $this->assertSame('sprachnachricht', ThemenAusloeser::qualifiziert($this->k(['message_type' => 'audio'])));
        $this->assertSame('fremder_kontext', ThemenAusloeser::qualifiziert($this->k(['fremder_kontext' => true])));
    }

    public function test_bilder_und_dokumente_loesen_aus(): void
    {
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k(['message_type' => 'image'])));
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k(['message_type' => 'document'])));
    }

    public function test_ruhezeit_in_beide_richtungen(): void
    {
        $jetzt = 1_000_000;
        $this->assertSame('ruhezeit', ThemenAusloeser::qualifiziert($this->k(['letzte_aktivitaet_ts' => $jetzt - 7 * 3600, 'jetzt_ts' => $jetzt])));
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k(['letzte_aktivitaet_ts' => $jetzt - 8 * 3600 - 1, 'jetzt_ts' => $jetzt])));
        $this->assertSame('ruhezeit', ThemenAusloeser::qualifiziert($this->k(['letzte_aktivitaet_ts' => $jetzt - 8 * 3600, 'jetzt_ts' => $jetzt])), 'genau 8h ist noch Ruhezeit');
    }
}
```

- [ ] **Step 2: Rot laufen lassen** (`../../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Comms/ThemenTexteTest.php tests/Unit/Comms/ThemenAusloeserTest.php` → Klassen fehlen).

- [ ] **Step 3: Migrationen**

`2026_10_17_000001_create_rec_conversation_topics_table.php` (Muster: `2026_09_15_000002_create_rec_conversation_handled_table.php`):
```php
Schema::create('rec_conversation_topics', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('team_id')->index();
    $table->unsignedBigInteger('comms_whatsapp_thread_id')->index();
    $table->string('topic', 16)->nullable();          // dispo | lohn | hr
    $table->string('state', 24);                       // asked | chosen | akte_hint_sent | lohn_info_sent | routed
    $table->unsignedBigInteger('dispo_channel_id')->nullable();
    $table->unsignedBigInteger('origin_message_id')->nullable();   // der getippte Eingang, der die Frage ausgeloest hat
    $table->unsignedBigInteger('ask_message_id')->nullable();      // unsere Frage (comms_whatsapp_messages.id)
    $table->timestamp('asked_at');
    $table->timestamp('chosen_at')->nullable();
    $table->timestamps();
    $table->index(['comms_whatsapp_thread_id', 'asked_at']);
});
```
`2026_10_17_000002_create_rec_conversation_followups_table.php`:
```php
Schema::create('rec_conversation_followups', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('team_id')->index();
    $table->unsignedBigInteger('comms_whatsapp_thread_id')->index();
    $table->unsignedBigInteger('marked_by_user_id')->nullable();
    $table->timestamp('marked_at');
    $table->timestamp('cleared_at')->nullable();
    $table->unsignedBigInteger('cleared_by_user_id')->nullable();
    $table->timestamps();
    $table->index(['comms_whatsapp_thread_id', 'cleared_at']);
});
```
Beide mit `down()` = `dropIfExists`. Keine DB-FKs auf CRM-/Core-Tabellen (Muster im Modul).

- [ ] **Step 4: Modelle, Konstante, Settings**

`RecConversationTopic`: `$table`, `$fillable` alle Spalten, Casts `asked_at`/`chosen_at` datetime, Konstanten `TOPIC_DISPO='dispo'`, `TOPIC_LOHN='lohn'`, `TOPIC_HR='hr'`, `STATE_ASKED='asked'`, `STATE_CHOSEN='chosen'`, `STATE_AKTE_HINT='akte_hint_sent'`, `STATE_LOHN_INFO='lohn_info_sent'`, `STATE_ROUTED='routed'`. `RecConversationFollowup`: analog. `RecConversationHandled::REASON_ROUTED_DISPO = 'routed_dispo'` mit Kommentar.

`RecApplicantSettings::DEFAULT_SETTINGS` (bei den `comms_*`-Keys, mit Kommentar S-Themen §2.1/§2.4):
```php
'comms_topic_enabled' => false,          // Themen-Auswahl fuer Mitarbeiter (S-Themen) — Default aus, Rollout bewusst
'comms_topic_quiet_hours' => 8,          // Ruhezeit in Stunden, in beide Richtungen
'comms_topic_lohn_url' => null,          // Login-Anleitung fuer den Lohn-Zweig; leer = Text ohne Knopf
'comms_topic_text_ask' => null,          // Team-Texte (null = Vorgabe aus ThemenTexte)
'comms_topic_text_lohn_offen' => null,
'comms_topic_text_lohn_ok' => null,
```

- [ ] **Step 5: Reine Klassen**

`src/Support/Comms/ThemenTexte.php`:
```php
<?php

namespace Platform\Recruiting\Support\Comms;

/**
 * Texte und Knoepfe der Themen-Auswahl (S-Themen §2.2–2.4). Vorgaben in du/Sie,
 * ein Team-Text ueberschreibt die Vorgabe 1:1. Meta-Grenzen stehen hier als
 * Konstanten und werden beim Bauen erzwungen — ein zu langer Titel liesse Meta
 * den ganzen Send ablehnen.
 */
final class ThemenTexte
{
    public const MAX_BUTTON = 20;
    public const MAX_LIST = 24;

    /** @return list<array{id:string,title:string}> */
    public static function knoepfe(): array
    {
        return [
            ['id' => 'topic_dispo', 'title' => 'Disposition'],
            ['id' => 'topic_lohn',  'title' => 'Lohn & Gehalt'],
            ['id' => 'topic_hr',    'title' => 'HR allgemein'],
        ];
    }

    public static function frage(bool $duzen, ?string $teamText, ?string $oooVorspann): string
    {
        $text = self::oderVorgabe($teamText, $duzen
            ? 'Danke für deine Nachricht! Worum geht es? Wähl unten aus, dann landet dein Anliegen gleich an der richtigen Stelle.'
            : 'Danke für Ihre Nachricht! Worum geht es? Wählen Sie unten aus, dann landet Ihr Anliegen gleich an der richtigen Stelle.');
        $vorspann = trim((string) $oooVorspann);

        return $vorspann === '' ? $text : $vorspann . "\n\n" . $text;
    }

    public static function filialFrage(bool $duzen): string
    {
        return $duzen ? 'Welche Disposition ist für dich zuständig?' : 'Welche Disposition ist für Sie zuständig?';
    }

    public static function dispoLink(bool $duzen, string $label): string
    {
        return $duzen
            ? "Alles klar. Mit dem Knopf unten schreibst du der {$label} direkt — deine Nachricht ist schon vorbereitet, du musst nur noch senden."
            : "Alles klar. Mit dem Knopf unten schreiben Sie der {$label} direkt — Ihre Nachricht ist schon vorbereitet, Sie müssen nur noch senden.";
    }

    /** @param list<string> $punkte */
    public static function lohnOffen(bool $duzen, ?string $teamText, array $punkte): string
    {
        $liste = '• ' . implode("\n• ", $punkte);
        $text = self::oderVorgabe($teamText, $duzen
            ? "Damit deine Abrechnung stimmen kann, fehlt in deiner Akte noch:\n{PUNKTE}\nBitte reich das im Mitarbeiterportal nach. Danach schauen wir uns deine Frage an."
            : "Damit Ihre Abrechnung stimmen kann, fehlt in Ihrer Akte noch:\n{PUNKTE}\nBitte reichen Sie das im Mitarbeiterportal nach. Danach schauen wir uns Ihre Frage an.");

        return str_replace('{PUNKTE}', $liste, $text);
    }

    public static function lohnOk(bool $duzen, ?string $teamText): string
    {
        return self::oderVorgabe($teamText, $duzen
            ? 'Hast du deine Stundenübersicht und Abrechnung schon kontrolliert? Dann kannst du uns gezielt sagen, was nicht passt. Wie du dich einloggst, zeigt der Knopf unten.'
            : 'Haben Sie Ihre Stundenübersicht und Abrechnung schon kontrolliert? Dann können Sie uns gezielt sagen, was nicht passt. Wie Sie sich einloggen, zeigt der Knopf unten.');
    }

    public static function filialKnopf(string $label): string
    {
        return mb_strlen($label) <= self::MAX_BUTTON ? $label : mb_substr($label, 0, self::MAX_BUTTON - 1) . '…';
    }

    private static function oderVorgabe(?string $teamText, string $vorgabe): string
    {
        $t = trim((string) $teamText);

        return $t === '' ? $vorgabe : $t;
    }
}
```

`src/Support/Comms/ThemenAusloeser.php`:
```php
<?php

namespace Platform\Recruiting\Support\Comms;

/**
 * Entscheidung ohne DB (S-Themen §2.1): loest dieser Eingang die Themen-Frage
 * aus? null = ja, sonst der Grund (fuer Log und Tests). Die Ruhezeit zaehlt die
 * JUENGSTE Aktivitaet im Thread in beide Richtungen, auch Auto-Antworten —
 * wer auf unsere Nachricht antwortet, hat kein neues Anliegen.
 *
 * @param array{enabled:bool, hr_kanal:bool, aktiver_ma:bool, quick_reply:bool, message_type:string,
 *              letzte_aktivitaet_ts:?int, jetzt_ts:int, ruhe_stunden:int, fremder_kontext:bool} $k
 */
final class ThemenAusloeser
{
    public static function qualifiziert(array $k): ?string
    {
        if (!$k['enabled']) {
            return 'schalter_aus';
        }
        if (!$k['hr_kanal']) {
            return 'kein_hr_kanal';
        }
        if ($k['fremder_kontext']) {
            return 'fremder_kontext';
        }
        if (!$k['aktiver_ma']) {
            return 'kein_aktiver_ma';
        }
        if ($k['quick_reply']) {
            return 'button_klick';
        }
        if ($k['message_type'] === 'audio') {
            return 'sprachnachricht';
        }
        $letzte = $k['letzte_aktivitaet_ts'];
        if ($letzte !== null && ($k['jetzt_ts'] - $letzte) <= $k['ruhe_stunden'] * 3600) {
            return 'ruhezeit';
        }

        return null;
    }
}
```

- [ ] **Step 6: Grün, Suite grün** (die Migrationen laufen in `DokumenteHarness`/`HrBereichHarness` mit, falls die alle `database/migrations/*.php` laden — bestehende Tests müssen grün bleiben).

- [ ] **Step 7: Commit**
```bash
git add database/migrations/2026_10_17_000001_create_rec_conversation_topics_table.php database/migrations/2026_10_17_000002_create_rec_conversation_followups_table.php src/Models/RecConversationTopic.php src/Models/RecConversationFollowup.php src/Models/RecConversationHandled.php src/Models/RecApplicantSettings.php src/Support/Comms/ThemenTexte.php src/Support/Comms/ThemenAusloeser.php tests/Unit/Comms/ThemenTexteTest.php tests/Unit/Comms/ThemenAusloeserTest.php
git commit -m "feat(recruiting): Themen-Auswahl — Schema, Einstellungen, Texte und Ausloeser-Regel"
```

---

### Task 2: `InteraktivSender` (Wrapper um `sendInteractive`) und `ThemenAuswahlEmpfaenger`

**Files:**
- Create: `src/Services/Comms/InteraktivSender.php`
- Create: `src/Services/Comms/ThemenAuswahlEmpfaenger.php`
- Test: `tests/Integration/InteraktivSenderTest.php`

**Interfaces:**
- Produces: `InteraktivSender::knoepfe(CommsChannel $channel, string $to, string $body, list<array{id:string,title:string}> $knoepfe): array{status:string, message_id:?int, error:?string}`; `::liste(CommsChannel, string $to, string $body, string $knopfTitel, list<array{id:string,title:string}> $zeilen): array`; `::linkKnopf(CommsChannel, string $to, string $body, string $knopfTitel, string $url): array`; Status-Konstanten `STATUS_SENT='sent'`, `STATUS_FAILED='failed'`, `STATUS_ERROR='error'`. Alle Sends `isAutoReply: true`. `ThemenAuswahlEmpfaenger::aktiverMitarbeiter(string $phone, int $teamId): ?int` (employee_id oder null; Status `EMPLOYEE` aus `EmployeeSenderResolver`, sonst null).

- [ ] **Step 1: Failing Test** — `InteraktivSenderTest` ersetzt `WhatsAppMetaService::sendInteractive` über eine anonyme Unterklasse (Muster: wie `DokumentHinweisSender`-Tests den Sender faken; prüfe `tests/Integration/*Dokument*HinweisSender*` bzw. `AufgabenSenderTest`, wie dort `WhatsAppMetaService` im Container gebunden wird) und prüft:
  - `knoepfe()` baut `['type' => 'button', 'body' => ['text' => $body], 'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => 'topic_dispo', 'title' => 'Disposition']], …]]]`, `displayBody = $body`, `isAutoReply = true`; Rückgabe `sent` + `message_id` wenn `$message->status !== 'failed'`.
  - `knoepfe()` mit 4 Knöpfen → `InvalidArgumentException` (Meta-Grenze), ohne Send.
  - Titel > 20 → Ausnahme.
  - `liste()` baut `['type' => 'list', 'body' => …, 'action' => ['button' => $knopfTitel, 'sections' => [['rows' => [['id' => …, 'title' => …], …]]]]]`; > 10 Zeilen → Ausnahme.
  - `linkKnopf()` baut `['type' => 'cta_url', 'body' => …, 'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $knopfTitel, 'url' => $url]]]`.
  - `$message->status === 'failed'` → `STATUS_FAILED` mit `error` aus `meta_payload.error.message`; Ausnahme aus `sendInteractive` → `STATUS_ERROR`, nichts fliegt.
  - `ThemenAuswahlEmpfaenger`: Stub-Resolver → `EMPLOYEE` liefert id; `NONE`/`AMBIGUOUS` → null.

- [ ] **Step 2: Rot.**

- [ ] **Step 3: Klassen**

`InteraktivSender`:
```php
final class InteraktivSender
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';

    public function __construct(private readonly ?WhatsAppMetaService $meta = null) {}

    /** @param list<array{id:string,title:string}> $knoepfe */
    public function knoepfe(CommsChannel $channel, string $to, string $body, array $knoepfe): array
    {
        if (count($knoepfe) < 1 || count($knoepfe) > 3) {
            throw new \InvalidArgumentException('Meta erlaubt 1 bis 3 Knöpfe.');
        }
        foreach ($knoepfe as $k) {
            if (mb_strlen($k['title']) > ThemenTexte::MAX_BUTTON) {
                throw new \InvalidArgumentException("Knopf-Titel länger als 20 Zeichen: {$k['title']}");
            }
        }
        $interactive = [
            'type' => 'button',
            'body' => ['text' => $body],
            'action' => ['buttons' => array_map(fn ($k) => ['type' => 'reply', 'reply' => ['id' => $k['id'], 'title' => $k['title']]], $knoepfe)],
        ];

        return $this->senden($channel, $to, $interactive, $body);
    }

    // liste(), linkKnopf() analog mit den Grenzen 10 / 24 bzw. genau ein Knopf.

    private function senden(CommsChannel $channel, string $to, array $interactive, string $displayBody): array
    {
        try {
            $message = ($this->meta ?? app(WhatsAppMetaService::class))->sendInteractive($channel, $to, $interactive, $displayBody, null, true);
        } catch (\Throwable $e) {
            Log::warning('recruiting.themen.send_fehler', ['to' => PhoneE164::suffix($to), 'error' => $e->getMessage()]);

            return ['status' => self::STATUS_ERROR, 'message_id' => null, 'error' => $e->getMessage()];
        }
        if (($message->status ?? null) === 'failed') {
            $fehler = (string) ($message->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.');

            return ['status' => self::STATUS_FAILED, 'message_id' => (int) $message->id, 'error' => $fehler];
        }

        return ['status' => self::STATUS_SENT, 'message_id' => (int) $message->id, 'error' => null];
    }
}
```
(`PhoneE164::suffix()` existiert — Signatur prüfen; wie bei `EinmalcodeSender` nur die letzten Ziffern loggen.)

`ThemenAuswahlEmpfaenger`:
```php
final class ThemenAuswahlEmpfaenger
{
    public function __construct(private readonly ?EmployeeSenderResolver $resolver = null) {}

    /** Aktiver Mitarbeiter hinter dieser Nummer, eindeutig — sonst null. Eine Stelle, damit die Personen-Klammer sie spaeter ersetzen kann (S-Themen §2.1). */
    public function aktiverMitarbeiter(string $phone, int $teamId): ?int
    {
        $r = ($this->resolver ?? app(EmployeeSenderResolver::class))->resolve($phone, $teamId);

        return $r['status'] === EmployeeSenderResolver::EMPLOYEE ? $r['employee_id'] : null;
    }
}
```

- [ ] **Step 4: Grün, Suite grün, Commit**
```bash
git add src/Services/Comms/InteraktivSender.php src/Services/Comms/ThemenAuswahlEmpfaenger.php tests/Integration/InteraktivSenderTest.php
git commit -m "feat(recruiting): Themen-Auswahl — InteraktivSender (Knoepfe, Liste, Link) mit den drei Abwehren"
```

---

### Task 3: `ThemenAuswahlHandler` — Auslöser, Nachricht 1, Listener-Hook, Abwesenheit kombiniert

**Files:**
- Create: `src/Services/Comms/ThemenAuswahlHandler.php`
- Modify: `src/Listeners/HandleWhatsAppInboundForRecruiting.php` (Hook VOR `oooAutoReply->handle`, OOO überspringen, wenn gesendet)
- Modify: `src/Services/Comms/OooAutoReplyHandler.php` (öffentliche Hilfsmethode `vorspann(int $teamId): ?string` — OOO-Text aus den Einstellungen, null wenn OOO inaktiv; die Logik `OooMode::isActive(...)` dorthin ziehen, `handle()` nutzt sie)
- Test: `tests/Integration/ThemenAuswahlHandlerTest.php` (Capsule-Harness wie `QuickReplyStamperTest`, zusätzlich `rec_conversation_topics`, `rec_applicant_settings`; `ThemenAuswahlEmpfaenger` und `InteraktivSender` als Stubs in den Konstruktor)

**Interfaces:**
- Consumes: `ThemenAusloeser`, `ThemenTexte`, `InteraktivSender::knoepfe()`, `ThemenAuswahlEmpfaenger`, `RecruitingChannelResolver::channelIds()`, `ThreadContextGate::blocksIntakeAny()`, `QuickReply::isQuickReply()`, `OooAutoReplyHandler::vorspann()`, `PublicAddressStyle::informal()`.
- Produces: `ThemenAuswahlHandler::beiEingang(CommsChannel, CommsWhatsAppThread, CommsWhatsAppMessage): string` — `'gesendet'` oder `'uebersprungen:<grund>'`; legt bei `gesendet` eine `RecConversationTopic`-Zeile `state=asked, topic=null, origin_message_id, ask_message_id, asked_at=now` an.

- [ ] **Step 1: Failing Tests**
```php
public function test_erster_eingang_eines_aktiven_mitarbeiters_bekommt_die_themen_frage(): void
{   // Thread auf HR-Kanal, Settings enabled, Empfänger-Stub → 42, Sender-Stub zeichnet auf
    $status = $this->handler()->beiEingang($this->kanal, $thread, $msg);
    $this->assertSame('gesendet', $status);
    $this->assertSame('topic_dispo', $this->gesendet[0]['knoepfe'][0]['id']);
    $zeile = RecConversationTopic::first();
    $this->assertSame('asked', $zeile->state); $this->assertNull($zeile->topic);
    $this->assertSame((int) $msg->id, (int) $zeile->origin_message_id);
}
public function test_antwort_innerhalb_der_ruhezeit_loest_nichts_aus(): void  // unser Ausgang (auch is_auto_reply=true) vor 2h → 'uebersprungen:ruhezeit'
public function test_bewerber_dispo_kanal_button_klick_sprachnachricht_schalter_aus(): void // je ein Fall, je Grund
public function test_bild_loest_aus(): void
public function test_abwesenheit_aktiv_ergibt_eine_kombinierte_nachricht(): void // OOO-Settings an → Body beginnt mit Vorspann, Knöpfe dabei, nur EIN Send
public function test_meta_lehnt_ab_dann_keine_topic_zeile(): void // Sender-Stub liefert failed → 'uebersprungen:send_failed', keine Zeile
public function test_sie_form_wenn_team_nicht_duzt(): void
```

- [ ] **Step 2: Rot.**

- [ ] **Step 3: Handler**
```php
final class ThemenAuswahlHandler
{
    public function __construct(
        private readonly ?InteraktivSender $sender = null,
        private readonly ?ThemenAuswahlEmpfaenger $empfaenger = null,
        private readonly ?OooAutoReplyHandler $ooo = null,
    ) {}

    public function beiEingang(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): string
    {
        $teamId = (int) $channel->team_id;
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $phone = (string) ($thread->remote_phone_number ?? '');
        $kontexte = $thread->contexts()->pluck('context_model')->push($thread->context_model)->filter()->unique()->all();

        $grund = ThemenAusloeser::qualifiziert([
            'enabled'              => (bool) $settings->getSetting('comms_topic_enabled', false),
            'hr_kanal'             => in_array((int) $channel->id, array_map('intval', RecruitingChannelResolver::channelIds($teamId)), true),
            'fremder_kontext'      => ThreadContextGate::blocksIntakeAny($kontexte),
            'aktiver_ma'           => $phone !== '' && ($this->empfaenger ?? app(ThemenAuswahlEmpfaenger::class))->aktiverMitarbeiter($phone, $teamId) !== null,
            'quick_reply'          => QuickReply::isQuickReply($message->meta_payload),
            'message_type'         => (string) ($message->message_type ?? 'text'),
            'letzte_aktivitaet_ts' => $this->letzteAktivitaetVor($thread, $message),
            'jetzt_ts'             => ($message->sent_at ?? $message->created_at ?? now())->getTimestamp(),
            'ruhe_stunden'         => max(1, (int) $settings->getSetting('comms_topic_quiet_hours', 8)),
        ]);
        if ($grund !== null) {
            return 'uebersprungen:' . $grund;
        }

        $duzen = PublicAddressStyle::informal($settings->getSetting('use_informal_address'));
        $vorspann = ($this->ooo ?? app(OooAutoReplyHandler::class))->vorspann($teamId);
        $body = ThemenTexte::frage($duzen, $settings->getSetting('comms_topic_text_ask'), $vorspann);

        $r = ($this->sender ?? app(InteraktivSender::class))->knoepfe($channel, $phone, $body, ThemenTexte::knoepfe());
        if ($r['status'] !== InteraktivSender::STATUS_SENT) {
            Log::warning('recruiting.themen.frage_nicht_gesendet', ['thread_id' => $thread->id, 'status' => $r['status'], 'error' => $r['error']]);

            return 'uebersprungen:send_' . $r['status'];
        }
        RecConversationTopic::create([
            'team_id' => $teamId, 'comms_whatsapp_thread_id' => (int) $thread->id,
            'topic' => null, 'state' => RecConversationTopic::STATE_ASKED,
            'origin_message_id' => (int) $message->id, 'ask_message_id' => $r['message_id'], 'asked_at' => now(),
        ]);

        return 'gesendet';
    }

    /** Juengste Nachricht im Thread VOR dieser — egal welche Richtung, egal ob Auto-Antwort. */
    private function letzteAktivitaetVor(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): ?int
    {
        $ts = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', (int) $thread->id)
            ->where('id', '<', (int) $message->id)
            ->max(DB::raw('COALESCE(sent_at, created_at)'));

        return $ts ? \Carbon\Carbon::parse((string) $ts)->getTimestamp() : null;
    }
}
```
`OooAutoReplyHandler::vorspann(int $teamId): ?string`: gibt bei aktivem OOO „Wir sind vom dd.mm. bis dd.mm. nicht erreichbar und ab dd.mm. wieder da." (Felder aus den Settings; fehlende Teile weglassen) zurück, sonst null. `handle()` bleibt unverändert, nutzt aber dieselbe `OooMode::isActive`-Prüfung über eine private Methode, damit es eine Stelle bleibt.

Listener (`HandleWhatsAppInboundForRecruiting::handle`), **vor** dem OOO-Block:
```php
        // Themen-Auswahl fuer Mitarbeiter (S-Themen §2.1): VOR dem OOO-Handler —
        // bei aktiver Abwesenheit traegt die Themen-Frage den Abwesenheitstext
        // selbst, eine zweite Nachricht gaebe es sonst doppelt.
        $themenStatus = 'uebersprungen:fehler';
        try {
            $themenStatus = app(ThemenAuswahlHandler::class)->beiEingang($channel, $thread, $message);
        } catch (\Throwable $e) {
            Log::warning('[Recruiting] Themen-Auswahl fehlgeschlagen', ['thread_id' => $thread->id, 'error' => $e->getMessage()]);
        }
```
und der OOO-Aufruf wird zu `if ($themenStatus !== 'gesendet') { try { $this->oooAutoReply->handle(...) } … }`. Test im Listener-Umfeld: bestehender Test für den OOO-Pfad muss grün bleiben (grep `OooAutoReply` in tests/).

- [ ] **Step 4: Grün, Suite grün, Commit**
```bash
git add src/Services/Comms/ThemenAuswahlHandler.php src/Services/Comms/OooAutoReplyHandler.php src/Listeners/HandleWhatsAppInboundForRecruiting.php tests/Integration/ThemenAuswahlHandlerTest.php
git commit -m "feat(recruiting): Themen-Auswahl — Ausloeser, Themen-Frage per Knoepfen, Abwesenheit in einer Nachricht"
```

---

### Task 4: `ThemenKlickHandler` — Dispo (Filialen, wa.me-Link, Auto-Weiterleitung, Stempel), Lohn, HR; Stamper-Ausnahme

**Files:**
- Create: `src/Services/Comms/ThemenKlickHandler.php`
- Modify: `src/Services/Comms/QuickReply.php` (neu `auswahlId(?array $metaPayload): ?string`, `istThemenKlick(?array): bool`)
- Modify: `src/Services/Comms/QuickReplyStamper.php` (`qualifies()` → false bei `istThemenKlick`)
- Modify: `src/Listeners/HandleWhatsAppInboundForRecruiting.php` (Klick-Handler vor `QuickReplyStamper`; bei `verarbeitet` OOO überspringen)
- Test: `tests/Integration/ThemenKlickHandlerTest.php`, Erweiterung `tests/Integration/QuickReplyStamperTest.php`, `tests/Unit/Comms/QuickReplyTest.php`

**Interfaces:**
- Consumes: `InteraktivSender::knoepfe/liste/linkKnopf`, `DispoForwardChannels::options($teamId)` (`channel_id`, `label`), `CommsChannel::sender_identifier` (Nummer), `ConversationForwarder::forward(...)`, `OffenePunkte::fuer(RecEmployee)` (Punkte mit `label`), `RecConversationHandled` upsert, `route('recruiting.public.employee-portal', ['token' => …])`.
- Produces: `ThemenKlickHandler::beiEingang(CommsChannel, CommsWhatsAppThread, CommsWhatsAppMessage): string` — `'verarbeitet:<id>'`, `'ignoriert:<grund>'`.

- [ ] **Step 1: Failing Tests**
```php
public function test_topic_dispo_schickt_die_filialen_als_knoepfe(): void          // 3 Kanäle → knoepfe() mit ids dispo_<id>, Zeile topic=dispo state=chosen
public function test_mehr_als_drei_filialen_als_liste(): void
public function test_dispo_klick_schickt_link_leitet_weiter_und_stempelt(): void
{   // Ursprung "Komme morgen 30 min später", Kanal 7 Nummer +4921178177723
    $this->assertSame('cta_url', $this->gesendet[0]['interactive']['type']);
    $this->assertSame('https://wa.me/4921178177723?text=' . rawurlencode('Komme morgen 30 min später'), $this->gesendet[0]['interactive']['action']['parameters']['url']);
    $f = RecConversationForward::first();   // target dispo, target_channel_id 7, forwarded_by_user_id null, messages[0].message_id = origin
    $h = RecConversationHandled::first();   // handled_reason routed_dispo, handled_at = Klick-Zeit
    $this->assertSame('routed', RecConversationTopic::latest('id')->first()->state);
}
public function test_fremde_kanal_id_wird_ignoriert(): void          // dispo_999 → 'ignoriert:unbekannte_filiale', kein Send, kein Forward, kein Stempel
public function test_doppelter_webhook_verarbeitet_den_klick_nur_einmal(): void // gleiche message_id zweimal → zweiter Aufruf 'ignoriert:schon_verarbeitet'
public function test_meta_lehnt_link_ab_dann_kein_stempel_und_keine_weiterleitung(): void
public function test_topic_lohn_mit_offenen_punkten_nennt_sie_und_verlinkt_das_portal(): void   // portal_v2_since gesetzt → cta_url mit Portal-Token-URL; state akte_hint_sent; KEIN Stempel
public function test_topic_lohn_ohne_portal_v2_nur_text(): void
public function test_topic_lohn_akte_vollstaendig_mit_login_url(): void    // comms_topic_lohn_url gesetzt → cta_url; ohne → knoepfe? nein: Text ohne Knopf (sendText? — s. Step 3)
public function test_topic_hr_setzt_nur_das_thema(): void
public function test_klick_ohne_offene_frage_wird_ignoriert(): void   // keine asked-Zeile in der Ruhezeit → 'ignoriert:keine_frage'
// QuickReplyStamperTest: Themen-Klick (interactive button_reply id topic_lohn) → qualifies() false, kein Stempel
// QuickReplyTest: auswahlId liest meta_payload.interactive.button_reply.id bzw. list_reply.id; istThemenKlick true für topic_*/dispo_*
```

- [ ] **Step 2: Rot.**

- [ ] **Step 3: Umsetzung**

`QuickReply::auswahlId()`: `$p['interactive']['button_reply']['id'] ?? $p['interactive']['list_reply']['id'] ?? null`. `istThemenKlick()`: `str_starts_with($id, 'topic_') || str_starts_with($id, 'dispo_')`.

`ThemenKlickHandler::beiEingang()`:
1. `$id = QuickReply::auswahlId($message->meta_payload)`; `null` oder kein Themen-Klick → `ignoriert:kein_themen_klick`.
2. Aktuelles Anliegen: jüngste `RecConversationTopic` des Threads mit `asked_at >= jetzt − ruhe_stunden`; fehlt → `ignoriert:keine_frage`. Idempotenz: ist `last_click_message_id` (neue Spalte? — nein: `chosen_at` + Vergleich reicht nicht) → **Spalte `last_click_message_id` in Task-1-Migration ergänzen** (nullable); ist sie `>= message.id` → `ignoriert:schon_verarbeitet`.
3. `topic_dispo`: Optionen = `DispoForwardChannels::options($teamId)`; leer → `ignoriert:keine_filialen`; ≤ 3 → `knoepfe()` mit `['id' => 'dispo_'.$channel_id, 'title' => ThemenTexte::filialKnopf($label)]`, sonst `liste()` (Titel ≤ 24 analog). Erfolg → Zeile: `topic=dispo, state=chosen, chosen_at, last_click_message_id`.
4. `dispo_<id>`: id muss in den Optionen sein, sonst `ignoriert:unbekannte_filiale`. Kanal laden (`CommsChannel` team-geprüft), Nummer = Ziffern aus `sender_identifier`. Ursprungstext = `body` der `origin_message_id` (leer bei Medien → nur „Hallo, ich habe eine Frage zu meinem Einsatz." als Vorbelegung), `mb_substr(…, 0, 1000)`. `linkKnopf($channel, $phone, ThemenTexte::dispoLink($duzen, $label), 'Zur ' . $label, 'https://wa.me/' . $nummer . '?text=' . rawurlencode($text))`. **Nur bei `sent`:** `ConversationForwarder::forward($teamId, $thread, [$originId], 'Automatisch: Mitarbeiter hat Thema Disposition gewählt', $employeeId, $displayName, null, ForwardTargets::DISPO, ForwardTargets::SOURCE_HR, $channelId)` (Fehler loggen, nicht werfen) **und** Stempel-Upsert wie `QuickReplyStamper::stampIfQuickReply` mit `handled_reason = REASON_ROUTED_DISPO`, `handled_at = Klick-sent_at`. Zeile: `state=routed, dispo_channel_id, last_click_message_id`. Bei `failed/error`: Zeile unverändert, Log.
5. `topic_lohn`: `$employee = RecEmployee::find($empfaengerId)` (über `ThemenAuswahlEmpfaenger`); `$punkte = app(OffenePunkte::class)->fuer($employee)['punkte']` (Form prüfen: `['punkte' => list<array{code,label,…}>]`). Offen → Text `ThemenTexte::lohnOffen(..., array_column($punkte,'label'))`, mit `portal_v2_since` als `linkKnopf(..., 'Zum Portal', route('recruiting.public.employee-portal', ['token' => $employee->portal_token]))`, sonst als reiner Text über `WhatsAppMetaService::sendText(...)` (Signatur prüfen; `isAutoReply`-Parameter? falls `sendText` kein Auto-Reply-Flag hat, über `knoepfe()` mit einem Knopf „Alles klar" `id=topic_ack` senden — dann `topic_ack` in `istThemenKlick` aufnehmen und als no-op verarbeiten). State `akte_hint_sent`. Vollständig → `lohnOk`-Text, mit `comms_topic_lohn_url` als `linkKnopf(..., 'Anleitung', $url)`, sonst Text. State `lohn_info_sent`. **Kein Stempel**, Chat bleibt offen.
6. `topic_hr`: Zeile `topic=hr, state=chosen`. Kein Send.
7. Rückgabe `verarbeitet:<id>`.

Listener: Klick-Handler **vor** `QuickReplyStamper`; `$klick = …->beiEingang(...)` im try/catch; OOO-Block zusätzlich überspringen, wenn `str_starts_with($klick, 'verarbeitet:')`. `QuickReplyStamper::qualifies()`: erste Zeile `if (QuickReply::istThemenKlick($message->meta_payload)) return false;` mit Kommentar S-Themen §2.6.

- [ ] **Step 4: Grün, Suite grün, Commit**
```bash
git add src/Services/Comms/ThemenKlickHandler.php src/Services/Comms/QuickReply.php src/Services/Comms/QuickReplyStamper.php src/Listeners/HandleWhatsAppInboundForRecruiting.php database/migrations/2026_10_17_000001_create_rec_conversation_topics_table.php tests/Integration/ThemenKlickHandlerTest.php tests/Integration/QuickReplyStamperTest.php tests/Unit/Comms/QuickReplyTest.php
git commit -m "feat(recruiting): Themen-Auswahl — Klicks: Dispo mit Filialen, wa.me-Link, Auto-Weiterleitung und Stempel; Lohn aus der Akte; HR"
```

---

### Task 5: Thema in der Kommunikations-Ansicht (Chips, Filter, Vermerk)

**Files:**
- Modify: `src/Services/Comms/InboxQuery.php` (`scored()`: `topic`-Daten je Thread laden; Zeilen `'topic' => ?string, 'topic_state' => ?string, 'topic_dispo_label' => ?string`; Filter `topic`), `src/Services/Comms/InboxFilter.php` (`public readonly string $topic = 'all'`), `src/Services/Comms/InboxRow.php` (`?string $topic = null, ?string $topicLabel = null, ?string $topicNote = null`), `src/Livewire/Conversations/Inbox.php` (`public string $topic = 'all'`, `setTopic()`, Filter-Übergabe, `counts` um `topics` je Thema), `resources/views/livewire/conversations/inbox.blade.php` (Pillen „Thema: Dispo n · Lohn n · HR n" neben Alle/Bewerber/Mitarbeiter; Chip in Zeile und Kopf; Vermerk „Akte unvollständig · Hinweis gesendet" bei `akte_hint_sent`; Ausgänge `message_type = interactive` wie Text, Präfix „[Auswahl]")
- Test: `tests/Integration/InboxTopicTest.php` (Vorbild `InboxQueryCompletenessTest`)

**Interfaces:**
- Consumes: `RecConversationTopic` (jüngste Zeile je Thread), `DispoForwardChannels::labelFor($teamId, $channelId)`.
- Produces: `InboxRow->topic` (`dispo|lohn|hr|null`), `topicLabel` („Lohn", „HR", „Dispo → Köln"), `topicNote` („Akte unvollständig · Hinweis gesendet" | „Login-Hinweis gesendet" | null); `counts['topics'] = ['dispo' => n, 'lohn' => n, 'hr' => n]`.

- [ ] **Step 1: Failing Tests** — Zeile mit `topic=lohn, state=akte_hint_sent` → Row `topicLabel 'Lohn'`, `topicNote 'Akte unvollständig · Hinweis gesendet'`; `routed` mit Kanal → `'Dispo → Köln'`; Filter `topic='lohn'` liefert nur diese; Thread ohne Zeile → null; Zeile älter als die aktuelle Ruhezeit und ohne Thema (`asked`, nie geklickt) → kein Chip; `counts['topics']` stimmt.
- [ ] **Step 2: Rot. Step 3: Umsetzung** — in `scored()` nach `$handledAt`: `$topics = $this->aktuelleThemen($teamId, $threadIds)` (eine Abfrage: je Thread die jüngste Zeile mit `topic IS NOT NULL`, Join auf nichts; Dispo-Label über `DispoForwardChannels::labelFor`). Filter in der bestehenden Filtermethode (Z. ~324) analog `kind`. `setTopic()` wie `setKind()`. Blade: Pillen-Gruppe hinter den Kind-Tabs (Z. ~194), Chip in Zeile hinter `contextLabel` (Z. ~314), Kopf hinter `$dispoChip` (Z. ~405). Chip-Farben: Dispo violett (wie `dispoChip` open), Lohn amber, HR gray.
- [ ] **Step 4: blade-check, grün, Suite grün, Commit**
```bash
git commit -m "feat(recruiting): Kommunikation — Thema als Chip, Vermerk und Filter"
```

---

### Task 6: „Offen lassen" (Teil B)

**Files:**
- Create: `src/Services/Comms/FollowupMarker.php` (`markieren(int $teamId, int $threadId, ?int $userId): void` — upsert auf die offene Zeile (keine zweite, solange `cleared_at IS NULL`); `erledigen(int $teamId, int $threadId, ?int $userId): bool`; `offeneFuer(int $teamId, array $threadIds): array<int, array{by:?string, at:int}>` mit Benutzername-Join wie `HrMitarbeiterListen::gestoppte()`)
- Modify: `InboxQuery` (`'followup' => ?array` je Zeile; Filter `level === 'followup'`: nur markierte, **unabhängig** von `is_unread`; `counts['followup']`), `InboxRow` (`?array $followup = null`), `Inbox.php` (Aktionen `offenLassen()`, `followupErledigen()` auf `selectedThreadId` team-geprüft; Pille `['key' => 'followup', 'label' => 'Offen', 'value' => $counts['followup']]` nach „Verpasst"), Blade (Knöpfe im Kopf neben „ungelesen schließen": „Offen lassen" wenn kein Marker, sonst „Erledigt"; Chip „offen gelassen von Clara · 18:05" in Zeile und Kopf)
- Test: `tests/Integration/InboxFollowupTest.php`

- [ ] **Step 1: Failing Tests** — markieren → Zeile mit User; zweites markieren legt keine zweite Zeile an; `offeneFuer` liefert Name + Zeit; erledigen setzt `cleared_at`/`cleared_by_user_id` und ist danach nicht mehr in `offeneFuer`; **eine menschliche Antwort löst den Marker nicht** (Ausgang `is_auto_reply=false` nach `marked_at` → Marker bleibt); Filter `followup` zeigt markierte gelesene Chats; `counts['followup']`; fremdes Team → `erledigen` false. Mutationsprobe: Auto-Clear durch Antwort einbauen → Test rot.
- [ ] **Step 2: Rot. Step 3: Umsetzung.** In `InboxQuery::scored()` die Filterregel: `if ($filter->level === 'followup') return $row['followup'] !== null;` **vor** der `is_unread`-Prüfung (Z. ~335), damit gelesene markierte Chats sichtbar bleiben. `Inbox::setLevel('followup')` funktioniert über die bestehende Methode.
- [ ] **Step 4: blade-check, grün, Suite grün, Commit**
```bash
git commit -m "feat(recruiting): Kommunikation — Offen lassen (Wiedervorlage) mit Pille, Chip und bewusstem Erledigen"
```

---

### Task 7: Einstellungen, Doku, Checkliste

**Files:**
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (im Block „Kommunikations-Übersicht & Eskalation", nach dem OOO-Abschnitt: Überschrift „Themen-Auswahl für Mitarbeiter (WhatsApp)"; rohe Checkbox `settings.comms_topic_enabled`; `x-ui-input-text type=number` `comms_topic_quiet_hours` (1–48); `x-ui-input-text` `comms_topic_lohn_url`; drei `<textarea wire:model>` für die Texte mit Hinweis „leer = Vorgabe" und den Platzhalter `{PUNKTE}` beim Lohn-offen-Text; Hinweis: Filialen kommen aus den Dispo-Kanälen (Dispo-Einstellungen), hier nichts einzutragen)
- Modify: `src/Livewire/Applicant/ApplicantSettingsModal.php` nur, falls `save()` Keys einzeln behandelt (prüfen; `array_merge(DEFAULT_SETTINGS, …)` übernimmt neue Keys automatisch)
- Modify: `docs/meingedeck/deploy-checkliste-ma-konto.md`: Tabelle 4.3 zwei Zeilen (`2026_10_17_000001`, `…000002`), Migrationszahl 21 → 23, `migrate:status`-grep um `|2026_10_17`, Abschnitt 2.3 die sechs Keys (Schalter AUS lassen), Abschnitt 4.7 `comms_topic_enabled` = aus, neuer Abschnitt „4.13 Themen-Auswahl einschalten (nach Sichttest, Texte von Markus abgenommen)": Dispo-Kanäle prüfen (`DispoForwardChannels::options` ≥ 1), Testnummer eines aktiven MA, Schalter an, Testnachricht, Klick-Durchlauf, Dispo-Postfach zeigt Karte, HR-Chat gestempelt `routed_dispo`; Sichttest „Offen lassen". `tests/Unit/DeployChecklisteInfosTest.php` mitziehen.
- Modify: Spec S-Themen: Nachtrag „Umgesetzt mit Plan 2026-10-16-whatsapp-themen-auswahl.md; Abweichungen: …" (nur falls welche).
- Test: `tests/Unit/ThemenSettingsTest.php` (DEFAULT_SETTINGS enthält die sechs Keys mit den Vorgaben; Blade enthält die sechs `wire:model`-Bindungen — String-Prüfung wie die anderen Settings-Tests)

- [ ] Steps: Tests rot → Blade/Settings → `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php` → grün → Suite grün → Commit
```bash
git commit -m "feat(recruiting): Themen-Auswahl — Einstellungen (Schalter, Ruhezeit, Texte, Login-URL), Checkliste 4.13"
```

---

## Self-Review

- **Spec-Abdeckung:** §2.1 Auslöser → T1 (`ThemenAusloeser`) + T3; §2.2 Nachricht 1 → T1/T2/T3; §2.3 Dispo (Filialen aus `DispoForwardChannels`, wa.me, Auto-Weiterleitung, `routed_dispo`) → T4; §2.4 Lohn → T4; §2.5 HR → T4; §2.6 Stamper-Ausnahme → T4; §2.7 Daten → T1 (+ `last_click_message_id`, `ask_message_id`); §2.8 Ansicht → T5; §3 Offen lassen (bewusst lösen) → T6; §4 Reihenfolge/Schalter → T7; §5 entschiedene Fragen → T3 (Abwesenheit), T6 (beide Knöpfe), T4 (Filialen), T7 (Texte/URL als Settings), T3 (je Thread).
- **Typen:** `InteraktivSender::knoepfe/liste/linkKnopf` liefern `array{status, message_id, error}` — T3/T4 lesen `status`/`message_id`; `ThemenAuswahlEmpfaenger::aktiverMitarbeiter(): ?int` in T3/T4; `RecConversationTopic`-Konstanten in T3/T4/T5; `QuickReply::istThemenKlick/auswahlId` in T4.
- **Review Focus:** 1 → T3 Ruhezeit-Test (Auto-Antwort zählt); 2 → T3 Bewerber/Dispo-Kanal; 3 → T4 fremde Kanal-ID; 4 → T4 doppelter Webhook (`last_click_message_id`); 5 → T4 Meta lehnt ab.
- **Platzhalter:** keine; Signaturen, die im Code nachzusehen sind, sind als „prüfen" markiert (`sendText`-Auto-Reply-Flag, `OffenePunkte::fuer()`-Form, `PhoneE164::suffix`).
