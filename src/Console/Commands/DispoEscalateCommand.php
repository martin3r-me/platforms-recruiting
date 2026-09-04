<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationConfig;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationPlanner;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityGroups;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Eskalations-Engine (Spec §3): laeuft alle 5 Minuten, verankert am
 * konfigurierten inbound_team_id (CLI/Scheduler-Kontext -> kein auth()).
 * Zielmenge seit Runde 3: heute + morgen, Modus pro VA (DispoEscalationConfig).
 *
 * Stufe 1 (time_1) / Stufe 2 (time_2): Reminder-/Final-Template ueber den
 * per Filiale aufgeloesten Kanal (DispoChannelResolver::resolveForEvent).
 * Stufe 3 (time_3): Einbuchung wird als "nicht bestaetigt" markiert
 * (deletion_marked_at), das MA-Portal gesperrt (DispoEmployeeGateway::lockPortal)
 * und die Einbuchung fuer den aggregierten Alarm gesammelt.
 *
 * Die HOECHSTE faellige Stufe je Lauf bestimmt DispoEscalationPlanner::dueStage
 * (reine Entscheidung, inkl. Fairness-Guard) — dieses Command liest/schreibt
 * nur, es enthaelt keine eigene Zeit-Logik ausser dem Aufbau von $times/$now.
 *
 * Meta-Falle: ein Sende-Versuch stempelt die Stufe immer (feuert genau
 * einmal); $message->status === 'failed' wird NIE als Erfolg gewertet,
 * sondern nur geloggt (kein Retry-Spam alle 5 Minuten).
 *
 * Batch-Robustheit: JEDE einzelne Sende-Operation (Stufe 1/2, Stufe-3-Block,
 * Alarm) steckt in ihrem eigenen try/catch — eine geworfene Exception
 * (Netzwerk/Timeout) darf NIE die restliche Zielmenge abbrechen, sonst geht
 * z. B. der Alarm fuer bereits per deletion_marked_at rausgenommene MA still
 * verloren (naechster Lauf schliesst sie ja aus der Zielmenge aus). Eine
 * geworfene Exception bei Stufe 1/2 stempelt NICHT (transienter Fehler heilt
 * sich im 14-16-Uhr-Fenster selbst); ein Meta-`failed`-Status (kein Wurf) wird
 * weiterhin gestempelt (definitive Ablehnung, Retry bringt nichts).
 *
 * Seit Dispo-Identitaet: Reminder pro Person/VA, Sperre fuer alle Datensaetze,
 * Alarm zaehlt Personen.
 *
 * @see self::escalate() Reine Engine-Logik ohne $this->option()/$this->warn(),
 *      per Probe-Muster (siehe ReconcileApplicantPositionsGateTest) ohne
 *      Artisan-Lebenszyklus direkt aufrufbar — siehe
 *      tests/Integration/DispoEscalateCommandTest.php.
 */
class DispoEscalateCommand extends Command
{
    protected $signature = 'recruiting:dispo-escalate {--now=} {--dry-run}';
    protected $description = 'Dispo-Eskalation: 14/15 Uhr Reminder, 16 Uhr Rausnahme + Portalsperre + Alarm';

    public function handle(DispoEscalationPlanner $planner, DispoChannelResolver $resolver, DispoEmployeeGateway $gateway): int
    {
        $now = $this->option('now')
            ? new \DateTimeImmutable((string) $this->option('now'), new \DateTimeZone('Europe/Berlin'))
            : new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $dryRun = (bool) $this->option('dry-run');

        $report = $this->escalate($planner, $resolver, $gateway, $now, $dryRun,
            function (string $type, string $text): void {
                $type === 'warn' ? $this->warn($text) : $this->info($text);
            });

        if ($dryRun) {
            $this->info(sprintf(
                '[DRY-RUN] Zielmenge %d, faellig: Stufe 1=%d, Stufe 2=%d, Stufe 3=%d',
                $report['population'], $report['stage1'], $report['stage2'], $report['stage3']
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Die eigentliche Eskalations-Logik, herausgehoben aus handle() — OHNE
     * jeden Zugriff auf $this->option()/$this->warn() & Co., damit sie ohne
     * Artisan (kein Input/Output, kein Service Container) direkt aufrufbar
     * ist. $emit ist optional: handle() reicht einen Callback durch der die
     * Meldungen ausgibt; Tests lassen ihn weg und werten stattdessen die
     * Rueckgabe (Zaehler) sowie den DB-Zustand aus.
     *
     * @return array{skipped:bool, population:int, stage1:int, stage2:int, stage3:int}
     */
    protected function escalate(
        DispoEscalationPlanner $planner,
        DispoChannelResolver $resolver,
        DispoEmployeeGateway $gateway,
        \DateTimeImmutable $now,
        bool $dryRun = false,
        ?callable $emit = null,
    ): array {
        $emit ??= function (string $type, string $text): void {};

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        if (!$settings->getSetting('dispo_escalation_enabled')) {
            return ['skipped' => true, 'population' => 0, 'stage1' => 0, 'stage2' => 0, 'stage3' => 0];
        }

        // Schonfrist vor Stufe 3 (Stunden). Unkonfiguriert = 6; ein explizit
        // gespeichertes 0 schaltet sie aus.
        $graceRaw = $settings->getSetting('dispo_escalation_grace_hours');
        $graceHours = ($graceRaw === null || $graceRaw === '') ? 6 : max(0, (int) $graceRaw);

        $defaults = [
            1 => (string) ($settings->getSetting('dispo_escalation_time_1') ?: '14:00'),
            2 => (string) ($settings->getSetting('dispo_escalation_time_2') ?: '15:00'),
            3 => (string) ($settings->getSetting('dispo_escalation_time_3') ?: '16:00'),
        ];
        $today    = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        // Runde 3 (#5): Zielmenge = Einsaetze von HEUTE und MORGEN; pro VA entscheidet
        // der Modus (Vortag -> morgen, Einsatztag -> heute), Zeiten kommen aus dem
        // VA-Override oder dem Team-Default. Planner bleibt unveraendert.
        // Runde 4 (#4): Modus "datum" erweitert die Zielmenge um ALLE kommenden
        // Einsatztage der VA, wenn heute das gewaehlte Eskalationsdatum ist (auch
        // Einsaetze, die noch mehrere Tage in der Zukunft liegen).
        $assignments = RecDispoAssignment::query()
            ->where(function ($q) use ($today, $tomorrow) {
                $q->whereDate('datum', $today)
                  ->orWhereDate('datum', $tomorrow)
                  ->orWhere(fn ($q2) => $q2->whereDate('datum', '>=', $today)
                      ->whereHas('event', fn ($e) => $e
                          ->where('escalation_day', DispoEscalationConfig::DAY_DATUM)
                          ->whereDate('escalation_date', $today)))
                  // Eskalation pro Sendung (Kunde 04.09.): Zeilen mit eigenem Plan,
                  // dessen Stufe-1-Tag erreicht ist — unabhaengig vom VA-Modus.
                  ->orWhere(fn ($q3) => $q3->whereDate('datum', '>=', $today)
                      ->whereNotNull('escalation_due_1_at')
                      ->whereDate('escalation_due_1_at', '<=', $today));
            })
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNotNull('reminder_sent_at')
            ->whereNull('confirmed_at')
            ->whereNull('declined_at')
            ->whereNull('deletion_marked_at')
            ->whereNull('missing_since')
            ->whereNotNull('rec_employee_id')
            ->with('event')
            // Reihenfolge = frueheste Schicht zuerst: der Dedup pro Person/VA schickt
            // den Reminder mit den Zeiten der ERSTEN Zeile.
            ->orderBy('datum')->orderBy('von')
            ->get()
            ->filter(function (RecDispoAssignment $a) use ($defaults, $today) {
                // Eigener Sendungs-Plan schlaegt den VA-Modus: die Zeile ist dran,
                // sobald ihr Stufe-1-Tag erreicht ist (die Uhrzeit prueft der Planner).
                if ($a->escalation_due_1_at !== null) {
                    return $a->escalation_due_1_at->format('Y-m-d') <= $today;
                }
                $cfg = self::configFor($a, $defaults);
                return DispoEscalationConfig::appliesOn($cfg['day'], $a->datum->format('Y-m-d'), $today, $cfg['date']);
            })
            ->values();

        // Dispo-Identitaet: Gruppen/kanonische id EINMAL je Lauf ueber die
        // (bereits gefilterte) Zielmenge bestimmen — mehrere MA-Datensaetze
        // derselben Person (gemeinsamer CRM-Kontakt) teilen sich Reminder und
        // Sperre, siehe DispoIdentityResolver/DispoIdentityGroups.
        $groups = app(DispoIdentityResolver::class)->groupsFor(
            $assignments->pluck('rec_employee_id')->unique()->map(fn ($v) => (int) $v)->all()
        );
        $canon = DispoIdentityGroups::canonicalMap($groups);

        // Dedup je Lauf: "{kanonischeId}|{eventId}|{stage}" -> Message-Id (oder
        // null) des ZUERST versendeten Reminders dieser Person/VA/Stufe. Der
        // zweite Datensatz derselben Person wird nur noch gestempelt.
        $sentInRun = [];

        $counts = [1 => 0, 2 => 0, 3 => 0, 'stage3_spared' => 0];

        /** @var array<int, list<RecDispoAssignment>> $removedByEvent */
        $removedByEvent = [];

        foreach ($assignments as $a) {
            // Zeilen-Plan (Eskalation pro Sendung) vor VA-Plan; $times bleibt fuer
            // die {{5}}-Frist in Stufe 2 in beiden Faellen gefuellt.
            if ($a->escalation_due_1_at !== null && $a->escalation_due_2_at !== null && $a->escalation_due_3_at !== null) {
                // Wanduhr-Semantik wie bei den HH:MM-Stufen: die gespeicherten
                // Plan-Zeiten sind lokale Uhrzeiten (so wie eingegeben) — in der
                // Zeitzone von $now interpretieren, NICHT als UTC-Instant (der
                // Carbon-Cast wuerde sonst je nach App-Timezone verschieben).
                $due = [
                    1 => new \DateTimeImmutable($a->escalation_due_1_at->format('Y-m-d H:i:s'), $now->getTimezone()),
                    2 => new \DateTimeImmutable($a->escalation_due_2_at->format('Y-m-d H:i:s'), $now->getTimezone()),
                    3 => new \DateTimeImmutable($a->escalation_due_3_at->format('Y-m-d H:i:s'), $now->getTimezone()),
                ];
                $times = [1 => $due[1]->format('H:i'), 2 => $due[2]->format('H:i'), 3 => $due[3]->format('H:i')];
                $stage = $planner->dueStageAt($this->state($a), $now, $due, $graceHours);
            } else {
                $times = self::configFor($a, $defaults)['times'];
                $stage = $planner->dueStage($this->state($a), $now, $times, $graceHours);
            }
            if ($stage === null) {
                continue;
            }
            // Kunde 04.09. (Lehre aus RG19734): wen NIE eine Nachricht erreicht
            // hat (aktiver Zustellfehler), den nimmt Stufe 3 nicht raus — er
            // gehoert in den Nummern-Nachzug (Filter "Nicht zugestellt") und
            // bleibt offen. Rausnahme nur fuer Erreichte, die schweigen.
            if ($stage === 3 && $a->hasActiveDeliveryFailure()) {
                $counts['stage3_spared']++;
                $emit('warn', "Stufe 3 verschont Einbuchung #{$a->id} ({$a->pnr_raw}): nie zugestellt - Nummer reparieren + neu senden");
                continue;
            }

            $counts[$stage]++;
            if ($dryRun) {
                continue;
            }

            if ($stage === 3) {
                $deletionSaved = false;
                try {
                    $a->deletion_marked_at = now();
                    $a->save();
                    $deletionSaved = true;
                } catch (\Throwable $e) {
                    $emit('warn', "Stufe 3 uebersprungen (Markierung fehlgeschlagen) fuer Einbuchung #{$a->id}: {$e->getMessage()}");
                }

                if ($deletionSaved) {
                    try {
                        $gateway->lockPortal(
                            $groups[(int) $a->rec_employee_id] ?? [(int) $a->rec_employee_id],
                            sprintf('Dispo: Einsatz %s am %s nicht bestaetigt', $a->event->einsatz_ref, $a->datum->format('d.m.Y'))
                        );
                    } catch (\Throwable $e) {
                        // Deletion ist bereits gespeichert — die Sperre holt HR/der naechste
                        // Lauf nach; Batch nicht deswegen abbrechen.
                        $emit('warn', "Portalsperre fehlgeschlagen fuer MA {$a->rec_employee_id} (Einbuchung #{$a->id}): {$e->getMessage()}");
                    }
                    $removedByEvent[$a->rec_dispo_event_id][] = $a;
                }
                continue;
            }

            // Dispo-Identitaet: fuer diese Person/VA/Stufe in diesem Lauf bereits
            // verschickt (zweiter Datensatz derselben Person) -> nur stempeln,
            // NICHT nochmal senden.
            //
            // $sentInRun wird erst NACH erfolgreichem Send gesetzt: wirft der erste
            // Send einer Person (Netzwerk), versucht der Geschwister-Datensatz seinen
            // eigenen Send — lieber im seltenen Fehlerfall doppelt erinnern als still
            // verpassen (Exception-Pfad stempelt bewusst nicht).
            $personKey = ($canon[(int) $a->rec_employee_id] ?? (int) $a->rec_employee_id) . '|' . $a->rec_dispo_event_id . '|' . $stage;
            if (array_key_exists($personKey, $sentInRun)) {
                if ($stage === 1) {
                    $a->escalation_1_at = now();
                    $a->escalation_1_message_id = $sentInRun[$personKey];
                } else {
                    $a->escalation_2_at = now();
                    $a->escalation_2_message_id = $sentInRun[$personKey];
                }
                $a->save();
                continue;
            }

            $channel = $resolver->resolveForEvent($a->event);
            $templateId = $settings->getSetting($stage === 1 ? 'dispo_escalation_template_1_id' : 'dispo_escalation_template_2_id');
            if ($channel === null || !$templateId) {
                $emit('warn', "Stufe {$stage} uebersprungen (kein Kanal/Template) fuer Einbuchung #{$a->id}");
                continue;
            }

            $contact = $gateway->contacts([(int) $a->rec_employee_id])[(int) $a->rec_employee_id] ?? null;
            if ($contact === null || $contact['phone'] === null) {
                $emit('warn', "Stufe {$stage} uebersprungen (keine Rufnummer) fuer Einbuchung #{$a->id}");
                continue;
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find((int) $templateId);
            if (!$template || $template->status !== 'APPROVED') {
                $emit('warn', "Stufe {$stage} uebersprungen (Template nicht gefunden/genehmigt) fuer Einbuchung #{$a->id}");
                continue;
            }

            $bodyParams = [
                ['type' => 'text', 'text' => ($contact['first_name'] !== '' ? $contact['first_name'] : $contact['name'])],
                ['type' => 'text', 'text' => (string) ($a->event->name ?? $a->event->einsatz_ref)],
                ['type' => 'text', 'text' => $a->datum->format('d.m.Y')],
                ['type' => 'text', 'text' => $this->shiftLabel($a->von, $a->bis)],
            ];
            // Runde 3: Stufe-3-Uhrzeit ist pro VA variabel -> der finale Reminder darf sie
            // als {{5}} tragen. Meta prueft die Parameterzahl exakt, deshalb NUR mitschicken,
            // wenn der Template-Body den Platzhalter enthaelt (altes 4er-Template laeuft weiter,
            // Template-Wechsel braucht keinen synchronen Deploy).
            if ($stage === 2 && self::templateUsesPlaceholder($template, '{{5}}')) {
                $bodyParams[] = ['type' => 'text', 'text' => $times[3]];
            }

            $components = [
                [
                    'type'       => 'body',
                    'parameters' => $bodyParams,
                ],
                [
                    'type'       => 'button',
                    'sub_type'   => 'url',
                    'index'      => 0,
                    'parameters' => [['type' => 'text', 'text' => $contact['portal_token']]],
                ],
            ];

            try {
                $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendTemplate(
                    channel:      $channel,
                    to:           $contact['phone'],
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                );
            } catch (\Throwable $e) {
                // KEIN Stempel — transienter Fehler (Netzwerk/Timeout) heilt sich im
                // 14-16-Uhr-Fenster beim naechsten Lauf selbst. Batch laeuft weiter.
                $emit('warn', "Stufe {$stage} Sende-Fehler fuer Einbuchung #{$a->id}: {$e->getMessage()}");
                continue;
            }

            // Meta-Falle: Stufe stempeln (feuert einmal, egal ob Meta ablehnt) —
            // Fehlschlag wird geloggt, nicht alle 5 Minuten neu versucht.
            if ($stage === 1) {
                $a->escalation_1_at = now();
                $a->escalation_1_message_id = $message->id ?? null;
            } else {
                $a->escalation_2_at = now();
                $a->escalation_2_message_id = $message->id ?? null;
            }
            $a->save();
            $sentInRun[$personKey] = $message->id ?? null;

            if (($message->status ?? null) === 'failed') {
                $emit('warn', "Reminder Stufe {$stage} nicht zugestellt (MA {$a->rec_employee_id}, Einbuchung #{$a->id})");
            }
        }

        if (!$dryRun) {
            $this->sendAlarms($removedByEvent, $canon, $settings, $resolver, $teamId, $emit);
        }

        return [
            'skipped'    => false,
            'population' => $assignments->count(),
            'stage1'     => $counts[1],
            'stage2'     => $counts[2],
            'stage3'     => $counts[3],
            'stage3_spared' => $counts['stage3_spared'],
        ];
    }

    /**
     * @param array<int, list<RecDispoAssignment>> $removedByEvent
     * @param array<int,int> $canon employee_id => kanonische id (Dispo-Identitaet)
     */
    private function sendAlarms(array $removedByEvent, array $canon, RecApplicantSettings $settings, DispoChannelResolver $resolver, int $teamId, callable $emit): void
    {
        $alarmTemplateId = $settings->getSetting('dispo_alarm_template_id');

        foreach ($removedByEvent as $eventId => $rows) {
            /** @var RecDispoEvent $event */
            $event = $rows[0]->event;
            $fs = RecDispoFilialeSettings::query()->where('team_id', $teamId)->where('filial_nr', $event->filial_nr)->first();
            $channel = $resolver->resolveForEvent($event);

            if (!$fs?->duty_phone || !$alarmTemplateId || $channel === null) {
                $emit('warn', "Alarm VA {$eventId} uebersprungen (kein Diensthandy/Kanal/Template)");
                continue;
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find((int) $alarmTemplateId);
            if (!$template || $template->status !== 'APPROVED') {
                $emit('warn', "Alarm VA {$eventId} uebersprungen (Template nicht gefunden/genehmigt)");
                continue;
            }

            $components = [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string) ($event->name ?? $event->einsatz_ref)],
                        ['type' => 'text', 'text' => (string) count(array_unique(array_map(
                            fn (RecDispoAssignment $r) => $canon[(int) $r->rec_employee_id] ?? (int) $r->rec_employee_id,
                            $rows
                        )))],
                    ],
                ],
            ];

            try {
                $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendTemplate(
                    channel:      $channel,
                    // Diensthandy ebenfalls E.164 (der 015...-ohne-+49-Fall hat den
                    // Alarm schon einmal gekillt, Memory dispo_alarm_duty_phone).
                    to:           \Platform\Recruiting\Support\PhoneE164::normalize($fs->duty_phone) ?? $fs->duty_phone,
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                );
            } catch (\Throwable $e) {
                // alarm_message_id bleibt ungesetzt — geloggt, Batch laeuft weiter
                // (die naechsten Alarme anderer VAs sollen nicht mit abbrechen).
                $emit('warn', "Alarm VA {$eventId}: Sende-Fehler: {$e->getMessage()}");
                continue;
            }

            $event->alarm_message_id = $message->id ?? null;
            $event->save();

            if (($message->status ?? null) === 'failed') {
                $emit('warn', "Alarm VA {$eventId} nicht zugestellt");
            }
        }
    }

    /** @return array{reminder_sent_at:?\DateTimeImmutable, confirmed_at:?\DateTimeImmutable, escalation_1_at:?\DateTimeImmutable, escalation_2_at:?\DateTimeImmutable, deletion_marked_at:?\DateTimeImmutable} */
    private function state(RecDispoAssignment $a): array
    {
        return [
            'reminder_sent_at'   => self::toImmutable($a->reminder_sent_at),
            'confirmed_at'       => self::toImmutable($a->confirmed_at),
            'escalation_1_at'    => self::toImmutable($a->escalation_1_at),
            'escalation_2_at'    => self::toImmutable($a->escalation_2_at),
            'declined_at'        => self::toImmutable($a->declined_at),
            'deletion_marked_at' => self::toImmutable($a->deletion_marked_at),
        ];
    }

    /** @param array{1:string,2:string,3:string} $defaults */
    private static function configFor(RecDispoAssignment $a, array $defaults): array
    {
        $e = $a->event;

        return DispoEscalationConfig::effective(
            $e?->escalation_day, $e?->escalation_time_1, $e?->escalation_time_2, $e?->escalation_time_3, $defaults,
            $e?->escalation_date?->format('Y-m-d')
        );
    }

    private static function toImmutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        return new \DateTimeImmutable((string) $value);
    }

    /**
     * Schichtzeit-Label des EINEN Einsatztags dieser Zeile (anders als bei der
     * Erstbestaetigung ist die Eskalation immer schon auf einen Tag gefiltert,
     * datum = morgen (Vortag) bzw. heute (Einsatztag)). "16:00 bis 22:00", nur-von -> "16:00", keine von-Zeit
     * -> Fallback "siehe Infoseite" (Meta akzeptiert keine leeren Parameter).
     */
    /** Enthaelt der BODY des Meta-Templates den Platzhalter (z. B. "{{5}}")? */
    private static function templateUsesPlaceholder(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate $template, string $placeholder): bool
    {
        foreach ((array) ($template->components ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY'
                && str_contains((string) ($component['text'] ?? ''), $placeholder)) {
                return true;
            }
        }

        return false;
    }

    private function shiftLabel(?string $von, ?string $bis): string
    {
        if ($von === null || $von === '') {
            return 'siehe Infoseite';
        }
        return $bis !== null && $bis !== '' ? "{$von} bis {$bis}" : $von;
    }
}
