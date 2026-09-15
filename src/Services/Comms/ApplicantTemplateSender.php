<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Template-Versand aus der Kommunikation.
 *
 * Unterschied zu Applicant\Show::sendManualTemplate: dort wird der
 * Bewerber-Formular-Token an JEDES Template mit URL-Knopf gehaengt — auch an
 * eines, dessen URL gar keinen Platzhalter hat. Genau so landete der Token im
 * MA-Portal-Link (Fall Theo Wirtz). Hier entscheidet der Platzhalter.
 *
 * Fix-Runde 1 (Task 8): "hat der Knopf einen Platzhalter" UND "an welcher
 * Position sitzt er" beantwortet ausschliesslich WhatsAppTemplateUrlButtons
 * (Schwesterklasse-Konvention, siehe deren Docblock) — diese Klasse
 * dupliziert die Regel nicht mehr selbst. needsFormToken() bleibt als duenne
 * Huelle bestehen, damit der bestehende Unit-Test sinnvoll bleibt.
 *
 * Liefert ok/error statt zu werfen (Muster: DispoReplySender).
 */
final class ApplicantTemplateSender
{
    /**
     * Braucht dieses Template den Formular-Token? Duenne Huelle ueber
     * WhatsAppTemplateUrlButtons::dynamicIndexes() — pure, ohne Laravel,
     * damit im Unit-Test pruefbar. Die Entscheidung "ist dieser Knopf
     * dynamisch" liegt NUR dort, nicht hier ein zweites Mal.
     *
     * @param array<int, array<string, mixed>> $components Template-Komponenten von Meta
     */
    public static function needsFormToken(array $components): bool
    {
        return WhatsAppTemplateUrlButtons::dynamicIndexes($components) !== [];
    }

    /**
     * Baut den Button-Parameter fuer den Formular-Token an der TATSAECHLICH
     * gefundenen Position (statt hart an Index 0, der urspruengliche Fehler
     * dieses Senders). Pure — WhatsAppTemplateUrlButtons::dynamicIndexes()
     * liefert die Positionen, kein eigenes Scannen der Buttons hier.
     *
     * Mehr als ein dynamischer Knopf ist nicht eindeutig sendbar: lieber ein
     * sprechender Fehler als ein geratener Index.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array{ok: bool, error: ?string, components: array<int, array<string,mixed>>}
     */
    public static function buildTokenComponents(array $components, string $formToken): array
    {
        $dynamicIndexes = WhatsAppTemplateUrlButtons::dynamicIndexes($components);

        if (count($dynamicIndexes) > 1) {
            return [
                'ok' => false,
                'error' => 'Vorlage hat mehr als einen dynamischen URL-Knopf (Positionen '
                    . implode(', ', $dynamicIndexes) . ') — nicht eindeutig sendbar.',
                'components' => [],
            ];
        }

        if ($dynamicIndexes === []) {
            return ['ok' => true, 'error' => null, 'components' => []];
        }

        return [
            'ok' => true,
            'error' => null,
            'components' => [[
                'type' => 'button',
                'sub_type' => 'url',
                'index' => $dynamicIndexes[0],
                'parameters' => [['type' => 'text', 'text' => $formToken]],
            ]],
        ];
    }

    /** @return array{ok: bool, error: ?string} */
    public function send(
        CommsWhatsAppThread $thread,
        int $templateId,
        ?int $applicantId,
        mixed $sender,
    ): array {
        $template = IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'error' => 'Vorlage nicht gefunden oder nicht genehmigt.'];
        }

        $channel = CommsChannel::find($thread->comms_channel_id);
        if ($channel === null) {
            return ['ok' => false, 'error' => 'Kanal des Chats nicht gefunden.'];
        }

        $templateComponents = (array) ($template->components ?? []);
        $components = [];

        if (self::needsFormToken($templateComponents)) {
            $applicant = $applicantId ? RecApplicant::find($applicantId) : null;
            if ($applicant === null) {
                return ['ok' => false, 'error' => 'Diese Vorlage enthält einen Bewerber-Link — der Chat ist aber keinem Bewerber zugeordnet.'];
            }
            $publicUrl = $applicant->getPublicUrl();
            $formToken = basename(parse_url($publicUrl, PHP_URL_PATH));

            $built = self::buildTokenComponents($templateComponents, $formToken);
            if (!$built['ok']) {
                return ['ok' => false, 'error' => $built['error']];
            }
            $components = $built['components'];
        }

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel: $channel,
                to: (string) $thread->remote_phone_number,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
                sender: $sender,
            );

            if (($message->status ?? null) === 'failed') {
                return ['ok' => false, 'error' => 'Meta hat den Versand abgelehnt: '
                    . (string) ($message->meta_payload['error']['message'] ?? 'unbekannter Grund')];
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Senden fehlgeschlagen: ' . $e->getMessage()];
        }
    }
}
