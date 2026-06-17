# WhatsApp-Template-Kosten-Übersicht — Design

**Datum:** 2026-06-17
**Modul:** platforms-recruiting
**Status:** Entwurf zur Freigabe

## Ziel

Der Kunde soll einen Überblick über die Kosten bekommen, die durch das Versenden
von WhatsApp-Templates entstehen. Ein kleines Dashboard mit Kennzahlen, Filtern
(Zeitraum, manuell/automatisch) und einer Aufschlüsselung pro Template.

Kosten = **Anzahl erfolgreich zugestellter Templates × konfigurierbarer Flat-Preis**.
Da der Kunde ausschließlich **Utility-Templates ("Verwaltung") an DE-Empfänger**
versendet und direkt über die Meta Cloud API (kein BSP-Aufschlag), ist der Preis
pro Versand quasi konstant — ein einziger konfigurierbarer Cent-Betrag reicht.

## Scope & Nicht-Ziele (YAGNI)

**Im Scope:**
- Read-only-Dashboard im Recruiting-Modul, neuer Seitenleisten-Punkt **"WhatsApp-Kosten"**.
- Zählt **alle** erfolgreich **zugestellten** Team-Sends (nicht nur Recruiting-getriggerte).
- Filter: Zeitraum + Typ (alle / manuell / automatisch).
- Aufschlüsselung pro `template_name`.

**Nicht-Ziele:**
- **Kein Schreiben/keine Änderung im crm-Modul.** Rein lesender Zugriff auf crm-Tabellen.
- **Keine feingranulare Quellen-Trennung** (AutoPilot vs. Interview-Reminder vs.
  Buchungslink). Es gibt nur **manuell vs. automatisch**. Feiner Split wäre eine
  spätere Ausbaustufe und erforderte ein Tagging pro Versand im crm-Sendepfad.
- **Keine echte Meta-Abrechnung** via `pricing_analytics`-API. Dashboard zeigt
  bewusst **"geschätzte Kosten"**.
- Keine Migration, kein neues DB-Schema.
- Keine Verrechnung/Rechnungsstellung — reine Transparenz/Übersicht.

## Datenquelle

Alle WhatsApp-Sends laufen durch die **eine** zentrale
`Platform\Crm\Services\Comms\WhatsAppMetaService` (crm-Modul). Recruiting hat
keinen eigenen Sendeweg. Pro Versand existiert ein Datensatz in
`comms_whatsapp_messages`. Wir lesen daraus aggregiert:

| Bedeutung | Quelle | Bedingung |
|---|---|---|
| erfolgreich zugestellt | `comms_whatsapp_messages.status` | `in ('delivered','read')` |
| nur ausgehend | `comms_whatsapp_messages.direction` | `= 'outbound'` |
| Team-Scope | `comms_whatsapp_threads.team_id` | join über `comms_whatsapp_thread_id` |
| Zeitraum | `comms_whatsapp_messages.delivered_at` | Datumsbereich |
| Template | `comms_whatsapp_messages.template_name` | Gruppierung |
| manuell vs. automatisch | `comms_whatsapp_messages.sent_by_user_id` | gesetzt = **manuell**, `null` = **automatisch (System)** |

> **Wichtig — Tabellen liegen im crm-Modul, nicht in recruiting.** Zugriff ist rein
> lesend (Aggregat-Queries), kein Write. `comms_whatsapp_messages` hat **kein**
> `team_id` — Team-Isolation läuft über den Join auf `comms_whatsapp_threads.team_id`.

### Definition manuell / automatisch

- **Manuell**: `sent_by_user_id` ist gesetzt — ein:e Nutzer:in hat aktiv gesendet
  (Bewerber-Template-Button oder eingebettete CRM-Comms-Chat-Komponente).
- **Automatisch (System)**: `sent_by_user_id` ist `null` — System-getriggert. Bündelt
  AutoPilot, Interview-Reminder, Buchungslinks und Vertrags-/Portal-Links. Im UI
  bewusst als **"Automatisch (System)"** beschriftet, damit es nicht als
  "nur AutoPilot" missverstanden wird.

## Kostenberechnung

Konfigurierbar in `config/recruiting.php`:

```php
'whatsapp_costs' => [
    'price_per_delivered_template' => 0.055, // EUR, Meta Utility/DE, Stand 04/2026
    'currency' => 'EUR',
],
```

- Kosten = `Anzahl zugestellter Templates × price_per_delivered_template`.
- Preis nicht hardcoden — Meta passt die Rate ~quartalsweise an.
- Dashboard beschriftet Beträge als **"geschätzte Kosten"**.

> **Genauigkeits-Hinweis:** Utility-Templates sind kostenlos, wenn sie innerhalb eines
> offenen 24h-Service-Fensters gesendet werden. Eine reine `Anzahl × Flat-Preis`-
> Rechnung **überschätzt** daher leicht. Für v1 bewusst akzeptiert (Label
> "geschätzt"); exakte Beträge wären später via Meta `pricing_analytics`-API möglich.

## Architektur

```
Recruiting-Modul (alles NEU, alles read-only):

  WhatsAppCostController / Route  →  Seitenleisten-Punkt "WhatsApp-Kosten"
      ↓
  Livewire: WhatsAppCosts (Komponente + Blade-View)
      ↓  ruft auf
  WhatsAppCostReportService   [kapselt die Aggregat-Queries]
      ↓  liest (read-only)
  comms_whatsapp_messages  ⋈  comms_whatsapp_threads   (crm-Tabellen)
```

- **`WhatsAppCostReportService`** (neu, recruiting): kapselt sämtliche Queries.
  - Eingabe: `team_id`, Zeitraum (von/bis), Typ-Filter (alle/manuell/automatisch).
  - Ausgabe: Kennzahlen (Gesamt-Anzahl, Gesamt-Kosten, Anzahl+Kosten manuell,
    Anzahl+Kosten automatisch) **und** Template-Breakdown (`template_name`, Anzahl,
    Kosten), absteigend nach Anzahl.
  - Preis aus Config; Kostenrechnung zentral hier. UI-unabhängig, klar testbar.
- **`WhatsAppCosts`-Livewire-Komponente** (neu, recruiting): hält Filter-State,
  ruft den Service auf, rendert. Keine Query-Logik in der Komponente.
- **Blade-View** (neu, recruiting): Kennzahlen-Karten + Filter + Breakdown-Tabelle.
  Nutzt vorhandene `x-ui-*`-Komponenten (Tailwind-Paket) gemäß Modul-Konventionen.
- Keine Migration, kein Write, keine crm-Änderung.

Begründung Service-Schicht: Die Aggregat-Queries (Join, Status-/Zeitraum-/Typ-Filter,
Kostenrechnung) sind die eigentliche Logik und müssen isoliert testbar sein, ohne
Livewire-/UI-Drumherum. Die Komponente bleibt dünn.

## UI

Neue Seite, erreichbar über neuen Seitenleisten-Punkt **"WhatsApp-Kosten"**.

- **Kennzahlen (oben):**
  - Zugestellte Templates gesamt
  - Geschätzte Kosten gesamt
  - davon manuell (Anzahl + Kosten)
  - davon automatisch (Anzahl + Kosten)
- **Filter:**
  - Zeitraum: Datumsbereich mit Presets (z.B. "dieser Monat", "letzter Monat").
    Default: laufender Monat.
  - Typ: alle / manuell / automatisch.
- **Breakdown-Tabelle:** pro `template_name` → Name · Anzahl · geschätzte Kosten,
  absteigend nach Anzahl.

## Testing

Service-Tests (`WhatsAppCostReportService`) mit Fixtures in
`comms_whatsapp_messages` / `comms_whatsapp_threads`:

- **Team-Isolation:** Sends fremder Teams werden nicht gezählt.
- **Status-Filter:** `sent`/`pending`/`failed` zählen **nicht**, nur `delivered`/`read`.
- **Richtung:** nur `outbound`.
- **manuell/automatisch:** korrekter Split über `sent_by_user_id`
  (gesetzt → manuell, null → automatisch).
- **Zeitraum:** Grenzen inklusiv/exklusiv korrekt; Sends außerhalb fallen raus.
- **Kostenrechnung:** Anzahl × Config-Preis; Summen manuell + automatisch = Gesamt.
- **Breakdown:** korrekte Gruppierung und Sortierung pro `template_name`.

## Offene Punkte / spätere Ausbaustufen

- **Feiner Quellen-Split** (AutoPilot / Reminder / Buchung / manuell): erfordert ein
  `trigger_source`-Tagging pro Versand im crm-Sendepfad → separate Iteration mit
  ausdrücklicher Freigabe für crm-Änderungen.
- **Exakte Meta-Kosten** via `pricing_analytics`-API statt Flat-Preis-Schätzung.
- **Performance:** Bei sehr großen Datenmengen ggf. Index-Bedarf auf crm-Tabellen
  (delivered_at / status) — wäre eine crm-Änderung, daher bewusst nicht in v1.
