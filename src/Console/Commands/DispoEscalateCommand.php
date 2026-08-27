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
        $assignments = RecDispoAssignment::query()
            ->where(fn ($q) => $q->whereDate('datum', $today)->orWhereDate('datum', $tomorrow))
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNotNull('reminder_sent_at')
            ->whereNull('confirmed_at')
            ->whereNull('deletion_marked_at')
            ->whereNull('missing_since')
            ->whereNotNull('rec_employee_id')
            ->with('event')
            ->get()
            ->filter(function (RecDispoAssignment $a) use ($defaults, $today) {
                $cfg = self::configFor($a, $defaults);
                return DispoEscalationConfig::appliesOn($cfg['day'], $a->datum->format('Y-m-d'), $today);
            })
            ->values();

        $counts = [1 => 0, 2 => 0, 3 => 0];

        /** @var array<int, list<RecDispoAssignment>> $removedByEvent */
        $removedByEvent = [];

        foreach ($assignments as $a) {
            $times = self::configFor($a, $defaults)['times'];
            $stage = $planner->dueStage($this->state($a), $now, $times);
            if ($stage === null) {
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
                            (int) $a->rec_employee_id,
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

            $components = [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => ($contact['first_name'] !== '' ? $contact['first_name'] : $contact['name'])],
                        ['type' => 'text', 'text' => (string) ($a->event->name ?? $a->event->einsatz_ref)],
                        ['type' => 'text', 'text' => $a->datum->format('d.m.Y')],
                        ['type' => 'text', 'text' => $this->shiftLabel($a->von, $a->bis)],
                    ],
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

            if (($message->status ?? null) === 'failed') {
                $emit('warn', "Reminder Stufe {$stage} nicht zugestellt (MA {$a->rec_employee_id}, Einbuchung #{$a->id})");
            }
        }

        if (!$dryRun) {
            $this->sendAlarms($removedByEvent, $settings, $resolver, $teamId, $emit);
        }

        return [
            'skipped'    => false,
            'population' => $assignments->count(),
            'stage1'     => $counts[1],
            'stage2'     => $counts[2],
            'stage3'     => $counts[3],
        ];
    }

    /** @param array<int, list<RecDispoAssignment>> $removedByEvent */
    private function sendAlarms(array $removedByEvent, RecApplicantSettings $settings, DispoChannelResolver $resolver, int $teamId, callable $emit): void
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
                        ['type' => 'text', 'text' => (string) count($rows)],
                    ],
                ],
            ];

            try {
                $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendTemplate(
                    channel:      $channel,
                    to:           $fs->duty_phone,
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
            'deletion_marked_at' => self::toImmutable($a->deletion_marked_at),
        ];
    }

    /** @param array{1:string,2:string,3:string} $defaults */
    private static function configFor(RecDispoAssignment $a, array $defaults): array
    {
        $e = $a->event;

        return DispoEscalationConfig::effective(
            $e?->escalation_day, $e?->escalation_time_1, $e?->escalation_time_2, $e?->escalation_time_3, $defaults
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
    private function shiftLabel(?string $von, ?string $bis): string
    {
        if ($von === null || $von === '') {
            return 'siehe Infoseite';
        }
        return $bis !== null && $bis !== '' ? "{$von} bis {$bis}" : $von;
    }
}
