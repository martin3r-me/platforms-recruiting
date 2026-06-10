# Design: Versand-Button konsolidieren (MA-Portal als Default)

**Datum:** 2026-06-10
**Modul:** platforms-recruiting
**Status:** Design abgestimmt, vor Implementierung

## Problem / Ziel

In der Schulungsnachbereitung (`InterviewBookings/Index`) gibt es zwei Versand-Buttons:
- „Verträge versenden" (`sendContractsBulk`) → erzeugt Verträge + schickt die **Vertrags-Portal-WA** (`contract_wa_template_id`, `/recruiting/portal/…`).
- „Portallink versenden NICHT NUTZEN" (`sendPortalLinkBulk`, amber) → erzeugt **dieselben** Verträge (via `SendContractsService::send(..., skipNotification=true)`) und schickt stattdessen die **Mitarbeiter-Portal-WA** (`employee_portal_wa_template_id` / `ma_portal_template`, `/recruiting/mitarbeiter/…`, deckt Vertrag-Signatur + Datenergänzung ab).

Der vom Code-Autor dokumentierte finale Workflow: den Portal-Button zum Default machen, den reinen „Verträge versenden" ablösen. Genau das setzen wir um.

## Entscheidungen

| Thema | Entscheidung |
|---|---|
| „Verträge versenden"-Button | **Entfernen** (UI) |
| Portal-Button | Bleibt Aktion `sendPortalLinkBulk`; **Primär-Stil**, Label **„Portallink & Verträge versenden"**, „NICHT NUTZEN" raus, Confirm entschärft |
| Sperr-Zustände | Bleiben am (jetzt einzigen) Button: `no_attended`, `no_default_template`, `missing_dates`, `missing_zuschlag`, `all_already_sent`; Labels an „Portallink & Verträge" angepasst |
| `sendContractsBulk()` (Methode) | **Bleibt erhalten** (unsurfaced, harmlos) — Vertrags-Portal-Pfad bleibt verfügbar, falls je gebraucht |
| Verträge/Mitarbeiter-Erzeugung | Unverändert (gleicher `SendContractsService`, gleiche Eligibility) |

## Architektur (nur Blade)

Datei: `resources/views/livewire/interview-bookings/index.blade.php`, Button-Block im `bulkSendState`-`@if`/`@else`.

- **`@else` (ready)**: die zwei Buttons durch **einen** Primär-Button ersetzen:
  - `wire:click="sendPortalLinkBulk"`
  - `wire:confirm="Verträge erzeugen und Portal-Link an alle anwesenden Bewerber senden?"`
  - Stil `bg-[var(--ui-primary)] text-white`
  - Label „Portallink & Verträge versenden"
- **Disabled-State-Zweige**: Label-Präfix von „Verträge versenden — …" auf „Portallink & Verträge — …" angleichen (Zustände + Logik unverändert).
- Der `all_already_sent`-Zweig bleibt (zeigt „Alle Verträge versendet").

Keine PHP-Änderung nötig (Methoden `sendPortalLinkBulk` + `bulkSendState` bleiben wie sie sind; `sendContractsBulk` bleibt ungenutzt erhalten).

## Voraussetzung (Konfiguration)

Damit die **MA-Portal-WA** rausgeht, muss `employee_portal_wa_template_id` (+ `employee_portal_wa_account_id`) in den Bewerber-Einstellungen gesetzt sein (`RecEmployee::sendPortalNotification()` löst das auf). Template `ma_portal_template` existiert (APPROVED). Vor dem Scharfschalten kurz prüfen — fehlt es, wird der Vertrag erzeugt, aber keine Portal-WA verschickt (Vertragsversand läuft trotzdem durch, Hinweis im AutoPilotLog).

## Nicht-Ziele
- Keine Änderung an `SendContractsService`, Eligibility, Verträgen, Bestand.
- Keine WA-Template-Konsolidierung darüber hinaus.

## Verifikation (manuell im Host)
- Schulungsnachbereitung zeigt **nur einen** Button „Portallink & Verträge versenden" (primär), kein „Verträge versenden", kein „NICHT NUTZEN".
- Sperren greifen weiter: ohne Zuschlag/Vertragsbeginn/Default disabled.
- Klick → Verträge erzeugt + Mitarbeiter angelegt + MA-Portal-WhatsApp kommt an (sofern `employee_portal_wa_template_id` gesetzt).
