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

        // Die Person wurde ueber eine andere Weiterleitung schon angeschrieben:
        // kein zweites "Gespraech starten", diese Weiterleitung haengt sich an
        // denselben HR-Chat.
        $sibling = $this->siblingFirstContact($forward);
        if ($sibling !== null) {
            $forward->update([
                'target_thread_id' => (int) $sibling->target_thread_id,
                'first_contact_at' => $sibling->first_contact_at,
                'first_contact_by_user_id' => $sibling->first_contact_by_user_id,
            ]);

            return ['ok' => true, 'error' => null];
        }

        $firstName = $this->firstNameFor($forward);
        if ($firstName === '') {
            return ['ok' => false, 'error' => 'Kein MA zugeordnet – die Vorlage braucht den Vornamen.'];
        }

        $teamId = (int) $forward->team_id;
        if ($this->accountId($teamId) === null) {
            return ['ok' => false, 'error' => 'Kein WhatsApp-Konto für die HR-Kommunikation eingestellt.'];
        }
        $template = $this->template($teamId);
        if ($template === null) {
            return ['ok' => false, 'error' => 'Vorlage „Gespräch starten" (t_com_gen) ist nicht freigegeben.'];
        }

        $target = $this->targets->resolveTemplate($teamId, (int) $template->id);
        if ($target['error'] !== null || !$target['channel'] instanceof CommsChannel) {
            return ['ok' => false, 'error' => (string) ($target['error'] ?? 'Kein HR-Kanal gefunden.')];
        }
        // Harte Sperre: nie ueber die Dispo-Nummer an den MA schreiben.
        if (in_array((int) $target['channel']->id, ForwardHrThreadLookup::dispoChannelIds(), true)) {
            return ['ok' => false, 'error' => 'Der HR-Kanal ist zugleich ein Dispo-Kanal – Versand gesperrt.'];
        }

        // Meta normalisiert "to" auf E.164 und schreibt die Vorlage in den
        // "+"-Thread — ein Altthread "49…" waere nicht der, in dem sie landet.
        $digits = preg_replace('/\D+/', '', (string) $forward->phone) ?? '';
        $thread = CommsWhatsAppThread::findOrCreateForPhone($target['channel'], '+' . $digits);

        // Atomarer Claim gegen Doppelklick / zwei HR-User auf demselben Datensatz.
        $userId = isset($user->id) ? (int) $user->id : null;
        $claimedAt = now();
        $claimed = RecConversationForward::query()->whereKey($forward->id)->whereNull('first_contact_at')
            ->update(['first_contact_at' => $claimedAt, 'first_contact_by_user_id' => $userId]);
        if ($claimed === 0) {
            return ['ok' => false, 'error' => 'Die Erstnachricht wurde schon gesendet.'];
        }

        $result = $this->sender->send($thread, (int) $template->id, null, $user, $firstName);
        if (!$result['ok']) {
            // Claim zuruecknehmen — ein Fehlversand setzt nichts.
            $forward->update([
                'first_contact_at' => null,
                'first_contact_by_user_id' => null,
                'last_error' => mb_substr((string) $result['error'], 0, 500),
            ]);

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

        // first_contact_at steht schon (Claim) — im Speicher nachziehen.
        $forward->forceFill(['first_contact_at' => $claimedAt, 'first_contact_by_user_id' => $userId])->syncOriginalAttributes(['first_contact_at', 'first_contact_by_user_id']);
        $forward->update([
            'target_thread_id' => (int) $thread->id,
            'last_error' => null,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Andere Weiterleitung derselben Person (Team, Ziel, Nummer), die schon
     * angeschrieben und einem HR-Chat zugeordnet ist — die juengste.
     */
    public function siblingFirstContact(RecConversationForward $forward): ?RecConversationForward
    {
        $digits = preg_replace('/\D+/', '', (string) $forward->phone) ?? '';
        if ($digits === '') {
            return null;
        }

        return RecConversationForward::query()
            ->where('team_id', (int) $forward->team_id)
            ->where('target', (string) $forward->target)
            ->whereIn('phone', ['+' . $digits, $digits])
            ->whereKeyNot($forward->id)
            ->whereNotNull('first_contact_at')
            ->whereNotNull('target_thread_id')
            ->orderByDesc('first_contact_at')
            ->orderByDesc('id')
            ->first();
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

    private function accountId(int $teamId): ?int
    {
        $accountId = RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('auto_pilot_wa_account_id');

        return $accountId ? (int) $accountId : null;
    }

    /** Ohne eingestelltes HR-Konto keine Vorlage — nie t_com_gen irgendeines Kontos. */
    private function template(int $teamId): ?IntegrationsWhatsAppTemplate
    {
        $accountId = $this->accountId($teamId);
        if ($accountId === null || !class_exists(IntegrationsWhatsAppTemplate::class)) {
            return null;
        }

        return IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED')
            ->where('name', self::TEMPLATE_NAME)
            ->where('whatsapp_account_id', $accountId)
            ->orderByDesc('id')
            ->first();
    }
}
