<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;

class SendInterviewReminders extends Command
{
    protected $signature = 'recruiting:send-interview-reminders';

    protected $description = 'Sendet WhatsApp-Erinnerungen für anstehende Interview-Termine.';

    public function handle(): int
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            $this->warn('WhatsApp-Integrations-Modul nicht verfügbar.');
            return Command::SUCCESS;
        }

        $interviews = RecInterview::query()
            ->whereNotNull('reminder_wa_template_id')
            ->whereNotNull('reminder_hours_before')
            ->whereIn('status', ['planned', 'confirmed'])
            ->where('starts_at', '>', now())
            ->whereRaw('starts_at <= DATE_ADD(NOW(), INTERVAL reminder_hours_before HOUR)')
            ->get();

        if ($interviews->isEmpty()) {
            $this->info('Keine fälligen Erinnerungen.');
            return Command::SUCCESS;
        }

        $this->info("Verarbeite {$interviews->count()} Interview(s) mit fälligen Erinnerungen...");

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($interviews as $interview) {
            $bookings = $interview->bookings()
                ->whereNull('reminder_sent_at')
                ->where('status', '!=', 'cancelled')
                ->with(['applicant.crmContactLinks.contact.phoneNumbers', 'applicant.legalStatus'])
                ->get();

            if ($bookings->isEmpty()) {
                $this->line("  Interview #{$interview->id}: keine offenen Buchungen.");
                continue;
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($interview->reminder_wa_template_id);
            if (!$template || $template->status !== 'APPROVED') {
                $this->warn("  Interview #{$interview->id}: Template nicht gefunden oder nicht APPROVED.");
                continue;
            }

            $channel = $this->resolveWhatsAppChannel($template);
            if (!$channel) {
                $this->warn("  Interview #{$interview->id}: Kein aktiver WhatsApp-Kanal gefunden.");
                continue;
            }

            foreach ($bookings as $booking) {
                // Nicht-EU-Bewerber, die noch nicht von HR geprueft wurden,
                // landen auf dem HR-Schreibtisch — bis dahin keine Schulungs-
                // Erinnerung. Gleiche Regel wie beim Vertrags-/Portal-Versand.
                if ($booking->applicant && $booking->applicant->isLegalStatusUnchecked()) {
                    $this->line("  Buchung #{$booking->id}: Rechtsstatus-Pruefung offen, Erinnerung übersprungen.");
                    $skipped++;
                    continue;
                }

                $phoneNumber = $this->findPhoneNumber($booking);
                if (!$phoneNumber) {
                    $this->line("  Buchung #{$booking->id}: Keine Telefonnummer gefunden, übersprungen.");
                    $skipped++;
                    continue;
                }

                try {
                    $components = $interview->resolveTemplateComponents(
                        $template->components ?? [],
                        $booking,
                    );

                    $service = app(WhatsAppMetaService::class);
                    $message = $service->sendTemplate(
                        channel: $channel,
                        to: $phoneNumber->international,
                        templateName: $template->name,
                        components: $components,
                        languageCode: $template->language ?? 'de',
                    );

                    // Link thread to applicant context
                    if ($message->thread && $booking->applicant) {
                        $message->thread->addContext(
                            get_class($booking->applicant),
                            $booking->applicant->id,
                            'interview_reminder',
                        );
                    }

                    $booking->update(['reminder_sent_at' => now()]);
                    $sent++;
                    $this->line("  Buchung #{$booking->id}: Erinnerung gesendet an {$phoneNumber->international}");
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  Buchung #{$booking->id}: Fehler: {$e->getMessage()}");
                }
            }
        }

        $this->info("Fertig. Gesendet: {$sent}, Übersprungen: {$skipped}, Fehler: {$errors}");

        return Command::SUCCESS;
    }

    private function resolveWhatsAppChannel(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate $template): ?CommsChannel
    {
        $account = $template->whatsappAccount;
        if (!$account || !$account->active) {
            return null;
        }

        return CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();
    }

    private function findPhoneNumber(RecInterviewBooking $booking): ?CrmPhoneNumber
    {
        $applicant = $booking->applicant;
        if (!$applicant) {
            return null;
        }

        foreach ($applicant->crmContactLinks as $link) {
            $contact = $link->contact;
            if (!$contact) {
                continue;
            }

            $primary = $contact->phoneNumbers
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereNotNull('international')
                ->first();

            if ($primary) {
                return $primary;
            }

            $fallback = $contact->phoneNumbers
                ->where('is_active', true)
                ->whereNotNull('international')
                ->first();

            if ($fallback) {
                return $fallback;
            }
        }

        return null;
    }
}
