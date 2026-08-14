<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Versendet Bestaetigungs-Templates ueber den Dispo-Kanal.
 *
 * Kanal-Aufloesung nach bestehendem Muster (RecEmployee::sendPortalNotification):
 * Template traegt whatsapp_account_id -> Account.phone_number -> CommsChannel
 * via sender_identifier. Components werden hier selbst gebaut — bewusst NICHT
 * sendManualTemplate (bekannter Form-Token-Bug).
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

        $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($template->whatsapp_account_id);
        if (!$account || !$account->active) {
            return ['ok' => false, 'message' => 'WhatsApp-Account des Templates nicht aktiv.', 'sent' => 0, 'failed' => []];
        }

        $channel = \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();
        if (!$channel) {
            return ['ok' => false, 'message' => 'Kein aktiver Kanal fuer den Template-Account.', 'sent' => 0, 'failed' => []];
        }

        $contacts = $this->gateway->contacts(array_column($recipients, 'employee_id'));
        $service  = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);

        $sent = 0;
        $failed = [];

        foreach ($recipients as $recipient) {
            $contact = $contacts[$recipient['employee_id']] ?? null;
            if ($contact === null || $contact['phone'] === null) {
                $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => 'Kontaktdaten nicht mehr verfuegbar'];
                continue;
            }

            try {
                $components = [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $contact['name']],
                            ['type' => 'text', 'text' => \Carbon\Carbon::parse($recipient['first_datum'])->format('d.m.Y')],
                            ['type' => 'text', 'text' => (string) ($event->name ?? $event->einsatz_ref)],
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
                    to:           $contact['phone'],
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                );

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
}
