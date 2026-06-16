<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsProviderConnection;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;

/**
 * Baut ein komplettes Direkteinstellungs-Set in EINER Transaktion auf:
 *
 *  1. Stelle (RecPosition) mit is_direct_hire=true und AutoPilot HART aus
 *     (auto_pilot_settings.auto_pilot_enabled=false + auto_start_auto_pilot=false).
 *  2. Zwei Phasen:
 *     - Phase 1 "Bewerbung" (vom RecPosition::created-Hook bereits angelegt) wird
 *       auf completion_type=manual / auto_advance=false UMGESTELLT (kein Duplikat).
 *     - Phase 2 "Datenerfassung" (order 2) mit completion_type=fields und
 *       completion_config.creates_employee_on_completion=true.
 *  3. Die ausgewählten Standard-Datenfelder als CoreExtraFieldDefinition auf Phase 2.
 *  4. Eine veröffentlichte Ausschreibung (RecPosting).
 *  5. Einen Eingang – entweder Referenz-Code (intake_mode='code') ODER einen
 *     dedizierten E-Mail-Kanal (intake_mode='mail'), exklusiv an die Ausschreibung
 *     gehängt, damit ApplicationMatchingService::isIntakeChannel() greift.
 *
 * @param array{
 *     title: string,
 *     team_id: int,
 *     created_by_user_id: int,
 *     owner_user_id: int,
 *     fields: array<int, string>,        // ausgewählte Feld-`name`s aus self::STANDARD_FIELDS
 *     intake_mode: 'code'|'mail',
 *     mail_prefix?: string               // nur bei intake_mode='mail'
 * } $input
 *
 * @return array{
 *     position: RecPosition,
 *     posting: RecPosting,
 *     ref_code: string|null,             // gesetzt bei intake_mode='code'
 *     channel: CommsChannel|null         // gesetzt bei intake_mode='mail'
 * }
 */
class DirectHireSetupService
{
    public const STANDARD_FIELDS = [
        ['name' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'required' => true],
        ['name' => 'nachname', 'label' => 'Nachname', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'E-Mail', 'type' => 'email', 'required' => true],
        ['name' => 'telefonnummer', 'label' => 'Telefonnummer', 'type' => 'phone', 'required' => true],
        ['name' => 'geburtsdatum', 'label' => 'Geburtsdatum', 'type' => 'text', 'required' => true],
        ['name' => 'ausweisnummer', 'label' => 'Ausweisnummer', 'type' => 'text', 'required' => true],
        ['name' => 'strasse', 'label' => 'Straße', 'type' => 'text', 'required' => true],
        ['name' => 'hausnummer', 'label' => 'Hausnummer', 'type' => 'text', 'required' => true],
        ['name' => 'plz', 'label' => 'PLZ', 'type' => 'text', 'required' => true],
        ['name' => 'stadt', 'label' => 'Stadt', 'type' => 'text', 'required' => true],
        ['name' => 'geburtsort', 'label' => 'Geburtsort', 'type' => 'text', 'required' => true],
        ['name' => 'steuer_id', 'label' => 'Steuer-ID', 'type' => 'text', 'required' => false],
        ['name' => 'sozialversicherungsnummer', 'label' => 'Sozialversicherungsnummer', 'type' => 'text', 'required' => false],
        ['name' => 'iban', 'label' => 'IBAN', 'type' => 'text', 'required' => false],
        ['name' => 'krankenkasse', 'label' => 'Krankenkasse', 'type' => 'text', 'required' => false],
        ['name' => 'foto_ausweis_vorderseite', 'label' => 'Foto Ausweis Vorderseite', 'type' => 'file', 'required' => true],
        ['name' => 'foto_ausweis_ruckseite', 'label' => 'Foto Ausweis Rückseite', 'type' => 'file', 'required' => true],
    ];

    /**
     * @param array{
     *     title: string,
     *     team_id: int,
     *     created_by_user_id: int,
     *     owner_user_id: int,
     *     fields: array<int, string>,
     *     intake_mode: 'code'|'mail',
     *     mail_prefix?: string
     * } $input
     *
     * @return array{
     *     position: RecPosition,
     *     posting: RecPosting,
     *     ref_code: string|null,
     *     channel: CommsChannel|null
     * }
     */
    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $position = RecPosition::create([
                'title' => $input['title'],
                'team_id' => $input['team_id'],
                'created_by_user_id' => $input['created_by_user_id'],
                'owned_by_user_id' => $input['owner_user_id'],
                'is_active' => true,
                'is_direct_hire' => true,
                'auto_pilot_settings' => ['auto_pilot_enabled' => false, 'auto_start_auto_pilot' => false],
            ]);

            // Phase 1 wird vom RecPosition::created-Hook als "Bewerbung" (order 1,
            // auto_advance=true) angelegt — hier nur umstellen, NICHT duplizieren.
            $phase1 = $position->phases()->where('order', 1)->firstOrFail();
            $phase1->update(['completion_type' => 'manual', 'auto_advance' => false]);

            $phase2 = $position->phases()->create([
                'team_id' => $input['team_id'],
                'name' => 'Datenerfassung',
                'order' => (int) ($position->phases()->max('order') ?? 0) + 1,
                'is_active' => true,
                'auto_advance' => false,
                'completion_type' => 'fields',
                'completion_config' => ['creates_employee_on_completion' => true],
            ]);

            $selected = array_filter(self::STANDARD_FIELDS, fn (array $f) => in_array($f['name'], $input['fields'], true));
            $order = 0;
            foreach ($selected as $field) {
                $order++;
                $this->createPhaseField($phase2, $field, $input['created_by_user_id'], $order);
            }

            $posting = RecPosting::create([
                'rec_position_id' => $position->id,
                'team_id' => $input['team_id'],
                'title' => $input['title'],
                'status' => 'published',
                'published_at' => now(),
                'is_active' => true,
                'created_by_user_id' => $input['created_by_user_id'],
            ]);

            $refCode = null;
            $channel = null;
            if ($input['intake_mode'] === 'code') {
                $refCode = $this->createRefCode($posting, $input['team_id']);
            } else {
                $channel = $this->createDedicatedChannel($posting, $input['mail_prefix'], $input['team_id'], $input['created_by_user_id']);
            }

            return ['position' => $position, 'posting' => $posting, 'ref_code' => $refCode, 'channel' => $channel];
        });
    }

    private function createRefCode(RecPosting $posting, int $teamId): string
    {
        $source = RecSourcePlatform::firstOrCreate(
            ['team_id' => $teamId, 'name' => 'Referenz-Code'],
            ['match_pattern' => '@@referenz-code-niemals-absender@@', /* Sentinel: matcht absichtlich NIE einen echten Absender — die Code-Stufe im Matching ist quellen-unabhängig. */ 'ref_parser' => 'ref_code', 'is_active' => true, 'priority' => 999],
        );
        if ($source->ref_parser !== 'ref_code') {
            $source->update(['ref_parser' => 'ref_code']);
        }
        do {
            $code = RefCodeParser::generate();
        } while (RecPostingExternalRef::where('rec_source_platform_id', $source->id)->where('external_ref', $code)->exists());

        RecPostingExternalRef::create([
            'rec_posting_id' => $posting->id,
            'rec_source_platform_id' => $source->id,
            'external_ref' => $code,
            'team_id' => $teamId,
        ]);

        return $code;
    }

    private function createDedicatedChannel(RecPosting $posting, string $mailPrefix, int $teamId, int $userId): CommsChannel
    {
        $referenceChannel = CommsChannel::query()
            ->where('team_id', $teamId)->where('type', 'email')->where('is_active', true)
            ->whereNotNull('sender_identifier')->first();
        if (!$referenceChannel) {
            throw new \RuntimeException('Kein bestehender E-Mail-Kanal im Team — Domain kann nicht abgeleitet werden.');
        }

        $domain = substr((string) $referenceChannel->sender_identifier, strrpos((string) $referenceChannel->sender_identifier, '@') + 1);
        $address = strtolower(trim($mailPrefix)) . '@' . $domain;

        $connection = CommsProviderConnection::forTeamProvider($referenceChannel->team, $referenceChannel->provider);
        if (!$connection) {
            throw new \RuntimeException('Keine aktive Provider-Connection für E-Mail-Kanäle gefunden.');
        }

        $exists = CommsChannel::query()
            ->where('team_id', $referenceChannel->team_id)
            ->where('type', 'email')
            ->where('sender_identifier', $address)
            ->exists();
        if ($exists) {
            throw new \RuntimeException("Die Adresse {$address} ist bereits vergeben.");
        }

        $channel = CommsChannel::create([
            'team_id' => $referenceChannel->team_id,
            'created_by_user_id' => $userId,
            'comms_provider_connection_id' => $connection->id,
            'type' => 'email',
            'provider' => $referenceChannel->provider,
            'name' => 'Direkteinstellung: ' . $posting->title,
            'sender_identifier' => $address,
            'visibility' => 'team',
            'is_active' => true,
            'meta' => [],
        ]);

        // Exklusive Verknüpfung an genau diese (offene) Ausschreibung →
        // ApplicationMatchingService::dedicatedPostingForChannel erkennt den Kanal
        // als Eingang (isIntakeChannel == true).
        $posting->commsChannels()->syncWithoutDetaching([$channel->id]);

        return $channel;
    }

    /**
     * Legt ein Phasen-Datenfeld an — exakt wie ManagePhaseExtraFieldsTool::createField:
     * polymorphe CoreExtraFieldDefinition mit context_type=RecPhase::class / context_id=phase->id.
     */
    private function createPhaseField(RecPhase $phase, array $field, int $userId, int $order): void
    {
        CoreExtraFieldDefinition::create([
            'team_id' => $phase->team_id,
            'created_by_user_id' => $userId,
            'context_type' => RecPhase::class,
            'context_id' => $phase->id,
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'is_required' => (bool) ($field['required'] ?? false),
            'is_mandatory' => false,
            'order' => $order,
            'options' => null,
        ]);

        $phase->clearExtraFieldDefinitionsCache();
    }
}
