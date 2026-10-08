<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * "Im Portal liegt ein Dokument fuer dich" (Spec 2026-10-08, §4). Universeller
 * Text: Vorname plus Knopf ins Portal, kein Dokumenttitel — welches Dokument,
 * sagt das Portal.
 *
 * Vorlage aus der TEAM-EINSTELLUNG `document_wa_template_id` ueber dieselbe
 * Kette wie Zertifikat und Fristenlauf (HoldingTemplateSender::resolveTarget:
 * Settings → Template → Account → Kanal). Die Komponenten baut dieser Sender
 * SELBST, nicht ueber HoldingTemplateComponents::build(): die setzt bei einem
 * unbekannten Platzhalter still den Vornamen ein (Muster ProofReminderSender).
 *
 * Drei Abwehren wie AufgabenSender:
 *  1. `$message->status === 'failed'` ist ein Fehlschlag, kein Erfolg.
 *  2. Ein Platzhalter, den wir nicht befuellen koennen, verhindert den Versand.
 *  3. sendTemplate() im try/catch — ein Meta-Ausfall kostet einen Empfaenger.
 *
 * Wer `portal_v2_since` nicht hat, bekommt NICHTS: der Knopf fuehrte ins alte
 * Portal, das keine Dokumente kennt (Ruling C2 des Fristenlaufs).
 *
 * Nicht final: DokumentService nimmt ihn als Abhaengigkeit, Tests ersetzen ihn
 * durch eine Unterklasse ohne Meta.
 */
class DokumentHinweisSender
{
    public const SETTINGS_KEY = 'document_wa_template_id';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NO_PHONE = 'no_phone';
    public const STATUS_NICHT_KONFIGURIERT = 'nicht_konfiguriert';
    public const STATUS_VORLAGE_UNTAUGLICH = 'vorlage_untauglich';
    public const STATUS_ALTES_PORTAL = 'altes_portal';

    /** Was HoldingTemplateComponents als Vorname erkennt — dieselbe Liste, damit beide Wege dasselbe meinen. */
    private const NAME_PLATZHALTER = ['name', 'vorname', '1'];

    public static function istErfolg(string $status): bool
    {
        return $status === self::STATUS_SENT;
    }

    public function sende(RecEmployee $employee): string
    {
        if ($employee->portal_v2_since === null) {
            return self::STATUS_ALTES_PORTAL;
        }

        $nummer = PhoneE164::normalize($employee->phone);
        if ($nummer === null || $nummer === '') {
            Log::error('recruiting.dokumente.keine_nummer', ['employee_id' => $employee->id]);

            return self::STATUS_NO_PHONE;
        }

        $ziel = app(HoldingTemplateSender::class)->resolveTarget((int) $employee->team_id, self::SETTINGS_KEY);
        if ($ziel['error'] !== null) {
            Log::error('recruiting.dokumente.nicht_konfiguriert', ['team_id' => $employee->team_id, 'fehler' => $ziel['error']]);

            return self::STATUS_NICHT_KONFIGURIERT;
        }
        $vorlage = $ziel['template'];
        $teile = (array) ($vorlage->components ?? []);

        if (WhatsAppTemplateUrlButtons::dynamicIndexes($teile) === []) {
            Log::error('recruiting.dokumente.vorlage_ohne_link', ['vorlage' => $vorlage->name]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $unbekannt = $this->unbefuellbarePlatzhalter($teile);
        if ($unbekannt !== []) {
            Log::error('recruiting.dokumente.platzhalter_unbekannt', ['vorlage' => $vorlage->name, 'platzhalter' => $unbekannt]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $vorname = trim((string) $employee->first_name);
        if ($vorname === '') {
            Log::warning('recruiting.dokumente.kein_vorname', ['employee_id' => $employee->id]);

            return self::STATUS_FAILED;
        }

        $token = trim((string) $employee->portal_token);
        if ($token === '') {
            Log::error('recruiting.dokumente.kein_portal_token', ['employee_id' => $employee->id]);

            return self::STATUS_FAILED;
        }
        $knopf = ApplicantTemplateSender::buildTokenComponents($teile, $token);
        if (!$knopf['ok']) {
            Log::error('recruiting.dokumente.knopf_nicht_eindeutig', ['vorlage' => $vorlage->name, 'fehler' => $knopf['error']]);

            return self::STATUS_VORLAGE_UNTAUGLICH;
        }

        $parameter = [];
        foreach ($this->rumpfPlatzhalter($teile) as $name) {
            $parameter[] = ['type' => 'text', 'parameter_name' => strtolower($name), 'text' => $vorname];
        }
        $components = array_merge([['type' => 'body', 'parameters' => $parameter]], $knopf['components']);

        try {
            $nachricht = app(WhatsAppMetaService::class)->sendTemplate(
                channel:      $ziel['channel'],
                to:           $nummer,
                templateName: (string) $vorlage->name,
                components:   $components,
                languageCode: (string) ($vorlage->language ?? 'de'),
            );
        } catch (\Throwable $e) {
            Log::warning('recruiting.dokumente.ausnahme', ['employee_id' => $employee->id, 'fehler' => $e->getMessage()]);

            return self::STATUS_FAILED;
        }

        if (($nachricht->status ?? null) === 'failed') {
            Log::warning('recruiting.dokumente.abgelehnt', [
                'employee_id' => $employee->id,
                'meta'        => $nachricht->meta_payload ?? null,
            ]);

            return self::STATUS_FAILED;
        }

        return self::STATUS_SENT;
    }

    /** @return list<string> alle {{...}} im BODY, in Fundreihenfolge, ohne Doppelte */
    private function rumpfPlatzhalter(array $teile): array
    {
        $namen = [];
        foreach ($teile as $teil) {
            if (($teil['type'] ?? '') !== 'BODY') {
                continue;
            }
            preg_match_all('/\{\{(\w+)\}\}/', (string) ($teil['text'] ?? ''), $treffer);
            foreach ($treffer[1] as $name) {
                if (!in_array($name, $namen, true)) {
                    $namen[] = $name;
                }
            }
        }

        return $namen;
    }

    /** @return list<string> Platzhalter, die kein Vorname sind — mehr kennt dieser Sender nicht */
    private function unbefuellbarePlatzhalter(array $teile): array
    {
        return array_values(array_filter(
            $this->rumpfPlatzhalter($teile),
            static fn (string $n) => !in_array(strtolower($n), self::NAME_PLATZHALTER, true)
        ));
    }
}
