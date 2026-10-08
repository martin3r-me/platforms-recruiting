# Absagen in Chat-Nachrichten erkennen — Plan

**Spec:** docs/superpowers/specs/2026-10-08-dispo-absage-erkennung-design.md
**Branch:** feat/dispo-absage-erkennung

## Global Constraints
- Nur platforms-recruiting; Core/CRM nur lesen.
- Die KI fuehrt nie eine Absage aus; Absage nur ueber `saveDecline()`.
- Massenwerte ohne Export-Marker (Assignments werden nicht beschrieben).
- Tests: Unit pur + Integration Capsule/SQLite, Runner
  `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`; Blade: `tools/blade-check.php`.

## Tasks

1. **Schema + Modell** — Migration `2026_10_08_000002_add_decline_check_to_rec_dispo_filiale_settings`
   (`decline_check_enabled_at`), Migration `2026_10_08_000003_create_rec_dispo_decline_checks_table`,
   Modell `RecDispoDeclineCheck` (Konstanten fuer outcome/review_status).
2. **Pure Bausteine** (Unit) — `DispoDeclinePrefilter::isObviousAck(string): bool`,
   `DispoDeclineVerdict::parse(string $raw, list<int> $candidateIds): ?array`,
   `DispoDeclineVerdict::shouldReport(array): bool`,
   `DispoDeclineAlarmComponents::build(string $name, string $event, string $dates, int $eventId, mixed $definition): array`,
   `DispoDeclineReview::isStillOpen(createdAt, list<assignment-state>): bool`.
3. **Kandidaten** (Integration) — `DispoDeclineCandidates::forMessage(CommsWhatsAppThread, CommsWhatsAppMessage, list<int> $channelIds): ?array{employee_id:int, by_event: array<int, list<RecDispoAssignment>>}`.
4. **Einstufung + Ablauf** (Integration mit Attrappe fuer OpenAiService) —
   `DispoDeclineClassifier::classify(...)`, `DispoDeclineCheckRunner::run(int $messageId, bool $finalAttempt)`,
   `CheckDispoDeclineJob`, Haken im Listener.
5. **Alarm** — `DispoDeclineAlarm::send(RecDispoEvent, RecEmployee-Name, Daten): ?int`, Setting `dispo_decline_alarm_template_id`.
6. **Pruefen in der VA** (Integration) — `DispoDeclineReview`: `openByEvent`, `openCountsByEvent`, `dismiss`, `acceptForPerson`.
7. **Oberflaeche** — Einstellungen (Schalter, Zaehler, Vorlage), VA-Zeile (Chip, Filter `suspected`),
   Chat-Banner + vorbelegtes Absage-Fenster, Uebersichts-Abzeichen.
8. **Abschluss** — volle Suite, blade-check, Pruef-Agent, Deploy-Notiz.
