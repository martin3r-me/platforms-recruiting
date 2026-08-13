# Design: AV-default fix in der Schulungsnachbereitung

**Datum:** 2026-06-10
**Modul:** platforms-recruiting
**Status:** Design abgestimmt, vor Implementierung

## Problem

In der Schulungsnachbereitung (`InterviewBookings/Index`) wählt HR die Vertragsvorlage aktuell aus
einem Dropdown der alten AV-NNN-Varianten (`availableContractTemplates()`, Filter `code like 'AV-%'`).
Seit der Zuschlag ein eigenes Datenfeld ist (`rec_applicants.zuschlag`), gibt es nur noch **eine**
generische Vorlage **AV-default** (id 16, `field_mappings.zuschlag = applicant.zuschlag`). Die AV-NNN
sind obsolet für neue Vergaben.

## Ziel

Die Vorlage ist ab jetzt **fix AV-default** und nicht mehr wählbar — HR trägt nur noch Zuschlag +
Vertragsbeginn ein und versendet.

## Abgestimmte Entscheidungen

| Thema | Entscheidung |
|---|---|
| Zuweisung | **Auto + read-only**: AV-default wird automatisch gesetzt, keine Auswahl mehr |
| Identifikation | Über `code = 'AV-default'` (aktiv, Team) — keine Hardcoded-ID |
| Alt-AV-NNN | **Aktiv lassen**, nur nirgends mehr anbieten (Dropdown entfällt) |
| Bestand | Unberührt (frozen `personalized_content` + eigene `rec_contract_template_id`) |

## Architektur

### 1. Default-Vorlage auflösen
Neue Helfer-Auflösung: das aktive Template mit `code = 'AV-default'` im Team des Bewerbers.
Zentral, damit UI + Versand dieselbe Quelle nutzen.

### 2. Aktivierung (einmalig, Konfiguration)
AV-default (id 16) ist aktuell `is_active = false` → muss `true` werden. Einmaliger Config-Schritt
(per MCP/UI), **kein Code**. Im Fehlerfall (kein aktives AV-default) greift der bestehende
„keine Vorlage"-Stopp → nichts wird versehentlich ohne Vorlage versendet.

### 3. Schulungsnachbereitung-UI (`InterviewBookings/Index` + Blade)
- Das Vorlagen-`<select>` wird durch eine **read-only Anzeige** ersetzt („Arbeitsvertrag default"
  bzw. der Name des aufgelösten Default-Templates). Kein Dropdown mehr.
- Zuschlag-Feld + Vertragslaufzeit bleiben unverändert.
- **Auto-Zuweisung** von `applicant.contract_template_id = <AV-default id>`:
  - beim Setzen von „Teilgenommen" (`updateStatus` → 'attended'), wenn noch keine Vorlage gesetzt,
  - **plus** defensiv direkt vor dem Versand (`sendContractsBulk`): eligible Bewerber ohne
    `contract_template_id` bekommen den Default zugewiesen, bevor `SendContractsService::send` läuft.
  So bleibt der bestehende Versand-/Eligibility-Pfad (der eine gesetzte `contract_template_id`
  erwartet) unverändert funktionsfähig.

### 4. Eligibility / Button-State (`bulkSendState`)
- Das `missing_templates`-Gate entfällt als Blocker (Vorlage ist immer der Default). Stattdessen:
  ist **kein aktives AV-default** auffindbar, wird das als eigener Fehlerzustand angezeigt
  (statt eines wählbaren Dropdowns) und der Versand bleibt blockiert.
- Unverändert bleiben die Gates: **Zuschlag** gesetzt, **Vertragsbeginn** gesetzt,
  **Rechtsstatus** (Nicht-EU) geprüft.

### 5. Alt-AV-NNN
Bleiben `is_active = true` (Alt-Verträge referenzieren sie, ZAS-Code-Fallback liest den Code),
werden aber nicht mehr angeboten (das Dropdown ist entfernt). `availableContractTemplates()` wird
durch `defaultContractTemplate()` ersetzt/ergänzt.

### 6. Bestehende/versendete Verträge
Komplett unberührt: `RecContract.personalized_content` ist eingefroren, `rec_contract_template_id`
zeigt weiter auf die jeweils alte AV-NNN. Keine Migration, kein Backfill.

## Nicht-Ziele
- Keine Deaktivierung/Löschung der AV-NNN.
- Keine Änderung am Zuschlag-Datenfeld (bereits umgesetzt).
- Kein Default für andere Stellen/Teams als über den Code `AV-default` (ein Default pro Team).

## Testing / Verifikation
Kein Modul-Test-Harness → manuell im Host:
- AV-default aktiv → Schulungsnachbereitung zeigt „Arbeitsvertrag default" read-only, kein Dropdown.
- Bewerber „Teilgenommen" → `contract_template_id` automatisch = AV-default.
- Versand-Gates: ohne Zuschlag/Vertragsbeginn blockiert; mit beidem versendbar; Nicht-EU-Block aktiv.
- Gerenderter Vertrag zieht Zuschlag korrekt (bereits verifiziert).
- AV-default versehentlich inaktiv → Fehlerhinweis + Versand blockiert (kein stiller Fehlversand).
- Alt-Schulungen mit versendeten AV-NNN-Verträgen: unverändert „Verträge versendet".
