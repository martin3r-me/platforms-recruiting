<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Versendet Bestaetigungs-Templates ueber den Dispo-Kanal.
 *
 * Kanal-Aufloesung ueber DispoChannelResolver::resolveForEvent() (Filial-Kanal
 * der Veranstaltung, sonst Default-Dispo-Kanal) — identisch zur Eskalation,
 * damit Erstversand und Erinnerungen/Alarm von derselben Nummer kommen.
 * Components werden hier selbst gebaut — bewusst NICHT sendManualTemplate
 * (bekannter Form-Token-Bug).
 *
 * Body-Variablen-Vertrag des genehmigten Meta-Templates (Namenskonvention
 * `dispo_einsatz_bestaetigung`), Reihenfolge ist bindend, alle Werte MUESSEN
 * nicht-leere Strings sein (Meta lehnt leere Parameter ab):
 * {{1}} Vorname (contact['first_name'], Fallback contact['name'] falls leer — Meta lehnt leere Parameter ab)
 * {{2}} erster Einsatztag (d.m.Y)
 * {{3}} VA-Name (event->name ?? event->einsatz_ref)
 * {{4}} Vorlauf-Minuten (event->vorlauf_minuten, Modal-Pflichtfeld; ?? 0 nur Belt-and-Braces)
 * {{5}} Schichtzeit des ERSTEN Einsatztags, siehe firstShiftLabel()
 * URL-Button (index 0): portal_token
 */
class DispoConfirmationSender
{
    public function __construct(private DispoEmployeeGateway $gateway) {}

    /**
     * @param list<array{employee_id:int, phone:string, assignment_ids:list<int>, first_datum:string, is_reminder:bool}> $recipients
     * @return array{ok: bool, message: ?string, sent: int, failed: list<array{employee_id:int, error:string}>}
     */
    public function send(RecDispoEvent $event, array $recipients, int $templateId): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)
            || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return ['ok' => false, 'message' => 'WhatsApp-Integrations-Modul nicht verfuegbar.', 'sent' => 0, 'failed' => []];
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'message' => 'Template nicht gefunden oder nicht genehmigt.', 'sent' => 0, 'failed' => []];
        }

        $channel = app(\Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver::class)->resolveForEvent($event);
        if (!$channel) {
            return ['ok' => false, 'message' => 'Kein aktiver Kanal fuer diese Veranstaltung.', 'sent' => 0, 'failed' => []];
        }

        $contacts = $this->gateway->contacts(array_column($recipients, 'employee_id'));
        $service  = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);

        $assignmentTimes = collect();
        if ($recipients !== []) {
            $assignmentTimes = RecDispoAssignment::query()
                ->whereIn('id', array_merge(...array_column($recipients, 'assignment_ids')))
                ->get(['id', 'datum', 'von', 'bis'])
                ->keyBy('id');
        }

        $sent = 0;
        $failed = [];

        foreach ($recipients as $recipient) {
            $contact = $contacts[$recipient['employee_id']] ?? null;
            // Dispo-Identitaet: Nummer kann vom Geschwister-Datensatz stammen (sendPreview-Fallback),
            // deshalb recipient['phone'] vor contacts()-Nummer beruecksichtigen.
            $phone = $contact['phone'] ?? ($recipient['phone'] ?? null);
            if ($contact === null || $phone === null) {
                $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => 'Kontaktdaten nicht mehr verfuegbar'];
                continue;
            }

            try {
                $components = [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => ($contact['first_name'] !== '' ? $contact['first_name'] : $contact['name'])],
                            ['type' => 'text', 'text' => \Carbon\Carbon::parse($recipient['first_datum'])->format('d.m.Y')],
                            ['type' => 'text', 'text' => (string) ($event->name ?? $event->einsatz_ref)],
                            ['type' => 'text', 'text' => (string) ($event->vorlauf_minuten ?? 0)],
                            ['type' => 'text', 'text' => $this->firstShiftLabel($recipient['assignment_ids'], $assignmentTimes)],
                        ],
                    ],
                    [
                        'type'       => 'button',
                        'sub_type'   => 'url',
                        'index'      => 0,
                        'parameters' => [['type' => 'text', 'text' => $contact['portal_token']]],
                    ],
                ];

                $message = $service->sendTemplate(
                    channel:      $channel,
                    to:           $phone,
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                );

                if (($message->status ?? null) === 'failed') {
                    $failed[] = [
                        'employee_id' => $recipient['employee_id'],
                        'error'       => (string) ($message->meta_payload['error']['message'] ?? 'Meta hat den Versand abgelehnt.'),
                    ];
                    continue;
                }

                RecDispoAssignment::query()
                    ->whereIn('id', $recipient['assignment_ids'])
                    ->update([
                        'reminder_sent_at'    => now(),
                        'reminder_message_id' => $message->id ?? null,
                    ]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Dispo-Bestaetigung: Versand fehlgeschlagen', [
                    'event_id' => $event->id, 'employee_id' => $recipient['employee_id'], 'error' => $e->getMessage(),
                ]);
                $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Schichtzeit-Label fuer {{5}}: der chronologisch ERSTE Einsatztag dieses
     * Empfaengers auf dieser VA. Sortierung [datum, von] aufsteigend, dabei
     * steht eine Zeile ohne von-Zeit innerhalb desselben Tages hinten.
     * "16:00 bis 22:00", nur-von -> "16:00", keine von-Zeit -> Fallback
     * "siehe Infoseite" (Meta akzeptiert keine leeren Parameter).
     *
     * @param list<int> $assignmentIds
     */
    private function firstShiftLabel(array $assignmentIds, \Illuminate\Support\Collection $times): string
    {
        $first = collect($assignmentIds)
            ->map(fn (int $id) => $times->get($id))
            ->filter()
            ->sort(function ($a, $b) {
                $byDatum = $a->datum <=> $b->datum;
                if ($byDatum !== 0) {
                    return $byDatum;
                }

                return ($a->von ?? '99:99') <=> ($b->von ?? '99:99');
            })
            ->first();

        if ($first === null || $first->von === null) {
            return 'siehe Infoseite';
        }

        return $first->bis !== null ? "{$first->von} bis {$first->bis}" : $first->von;
    }
}
