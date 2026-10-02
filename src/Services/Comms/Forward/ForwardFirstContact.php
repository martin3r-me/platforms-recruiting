<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;

/**
 * Erstnachricht aus einer Weiterleitung (Spec 02.10.2026): t_com_gen ueber die
 * HR-Nummer. Kanal kommt aus HoldingTemplateSender::resolveTemplate(),
 * Versand + Kontopruefung + {{name}} aus ApplicantTemplateSender — keine
 * dritte Kopie dieser Regeln.
 *
 * Der neue HR-Thread steht erst in der Chat-Liste, wenn der MA antwortet
 * (InboxQuery filtert auf last_inbound_at) — bis dahin zeigt ihn der Reiter
 * "Weitergeleitet".
 */
final class ForwardFirstContact
{
    public const TEMPLATE_NAME = 't_com_gen';

    public function __construct(
        private readonly HoldingTemplateSender $targets,
        private readonly ApplicantTemplateSender $sender,
        private readonly ForwardHrThreadLookup $lookup,
    ) {}

    /** @return array{ok: bool, error: ?string} */
    public function send(RecConversationForward $forward, ?object $user): array
    {
        // Die Erstnachricht-Regel ist zielgebunden: t_com_gen ueber die HR-Nummer.
        // Ein kuenftiges Ziel bringt seine eigene Regel mit.
        if ((string) $forward->target !== ForwardTargets::HR) {
            return ['ok' => false, 'error' => 'Erstnachricht gibt es nur für HR-Weiterleitungen.'];
        }
        if (!$forward->isOpen()) {
            return ['ok' => false, 'error' => 'Diese Weiterleitung ist schon erledigt.'];
        }
        if ($forward->first_contact_at !== null) {
            return ['ok' => false, 'error' => 'Die Erstnachricht wurde schon gesendet.'];
        }

        $firstName = $this->firstNameFor($forward);
        if ($firstName === '') {
            return ['ok' => false, 'error' => 'Kein MA zugeordnet – die Vorlage braucht den Vornamen.'];
        }

        $teamId = (int) $forward->team_id;
        $template = $this->template($teamId);
        if ($template === null) {
            return ['ok' => false, 'error' => 'Vorlage „Gespräch starten" (t_com_gen) ist nicht freigegeben.'];
        }

        $target = $this->targets->resolveTemplate($teamId, (int) $template->id);
        if ($target['error'] !== null || !$target['channel'] instanceof CommsChannel) {
            return ['ok' => false, 'error' => (string) ($target['error'] ?? 'Kein HR-Kanal gefunden.')];
        }

        $digits = preg_replace('/\D+/', '', (string) $forward->phone) ?? '';
        $thread = $this->lookup->find($teamId, (string) $forward->phone)
            ?? CommsWhatsAppThread::findOrCreateForPhone($target['channel'], '+' . $digits);

        $result = $this->sender->send($thread, (int) $template->id, null, $user, $firstName);
        if (!$result['ok']) {
            $forward->update(['last_error' => mb_substr((string) $result['error'], 0, 500)]);

            return ['ok' => false, 'error' => $result['error']];
        }

        // Ab hier ist die WhatsApp raus — der Kontext ist Komfort, kein Erfolgskriterium.
        if ($forward->rec_employee_id) {
            try {
                $thread->addContext((new RecEmployee())->getMorphClass(), (int) $forward->rec_employee_id, 'dispo_forward');
            } catch (\Throwable $e) {
                Log::warning('[DispoForward] Thread-Kontext fehlgeschlagen (WhatsApp ist raus): ' . $e->getMessage(), ['forward_id' => $forward->id]);
            }
        }

        $forward->update([
            'target_thread_id' => (int) $thread->id,
            'first_contact_at' => now(),
            'first_contact_by_user_id' => isset($user->id) ? (int) $user->id : null,
            'last_error' => null,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** HR-Thread mit offenem 24h-Fenster — dann braucht es keine Vorlage. */
    public function windowOpen(RecConversationForward $forward, ?\DateTimeInterface $now = null): ?CommsWhatsAppThread
    {
        $thread = $this->lookup->find((int) $forward->team_id, (string) $forward->phone);
        if ($thread === null || !DispoTimeCalculator::isReplyWindowOpen($thread->last_inbound_at, $now ?? now())) {
            return null;
        }

        return $thread;
    }

    public function firstNameFor(RecConversationForward $forward): string
    {
        if (!$forward->rec_employee_id) {
            return '';
        }

        return trim((string) (RecEmployee::query()->whereKey($forward->rec_employee_id)->value('first_name') ?? ''));
    }

    private function template(int $teamId): ?IntegrationsWhatsAppTemplate
    {
        if (!class_exists(IntegrationsWhatsAppTemplate::class)) {
            return null;
        }
        $accountId = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('auto_pilot_wa_account_id');
        $query = IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED')
            ->where('name', self::TEMPLATE_NAME);
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderByDesc('id')->first();
    }
}
