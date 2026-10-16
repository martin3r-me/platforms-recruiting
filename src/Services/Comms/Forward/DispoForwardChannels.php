<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Support\Filialen;

/**
 * Wohin HR an die Dispo weiterleiten kann (Spec 16.10.2026): JEDER aktive
 * Kanal des Dispo-WABA-Kontos — dieselbe Menge wie das Dispo-Postfach
 * (DispoChannelResolver::dispoChannelIds). Ein neuer Dispo-Kanal steht damit
 * ohne Deploy zur Auswahl.
 *
 * Beschriftung: Filialname aus rec_dispo_filiale_settings + Support\Filialen
 * ("Dispo Köln"). Ein Kanal ohne Filiale bleibt waehlbar und traegt seinen
 * Kanalnamen bzw. die Nummer — nie unbeschriftet.
 */
final class DispoForwardChannels
{
    /** @param ?list<int> $channelIds Override fuer Tests; sonst das Dispo-Kanal-Set. */
    public function __construct(private readonly ?array $channelIds = null) {}

    /** @var array<int, list<array{channel_id: int, filial_nr: ?int, label: string}>> je Team, pro Instanz */
    private array $memo = [];

    /** @return list<array{channel_id: int, filial_nr: ?int, label: string}> */
    public function options(int $teamId): array
    {
        return $this->memo[$teamId] ??= $this->build($teamId);
    }

    /** @return list<array{channel_id: int, filial_nr: ?int, label: string}> */
    private function build(int $teamId): array
    {
        // HR-Kanaele nie als Dispo-Ziel — auch nicht, wenn sie (Fehlkonfiguration,
        // gemeinsames WABA-Konto) im Dispo-Set auftauchen. Spiegel von
        // ForwardHrThreadLookup, das Dispo-Kanaele aus dem HR-Set nimmt (Review K-a).
        $channelIds = array_values(array_diff(
            $this->channelIds ?? self::dispoChannelIds(),
            self::recruitingChannelIds($teamId),
        ));
        if ($channelIds === []) {
            return [];
        }

        $channels = CommsChannel::query()
            ->whereIn('id', $channelIds)
            ->where('team_id', $teamId)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->get(['id', 'name', 'sender_identifier']);

        $filialeByChannel = RecDispoFilialeSettings::query()
            ->where('team_id', $teamId)
            ->whereIn('comms_channel_id', $channels->pluck('id')->all())
            ->pluck('filial_nr', 'comms_channel_id')
            ->map(fn ($nr) => (int) $nr)
            ->all();

        $options = $channels->map(function (CommsChannel $c) use ($filialeByChannel) {
            $nr = $filialeByChannel[(int) $c->id] ?? null;
            $name = $nr !== null ? Filialen::name($nr) : null;

            return [
                'channel_id' => (int) $c->id,
                'filial_nr' => $nr,
                'label' => 'Dispo ' . ($name ?? (trim((string) $c->name) !== '' ? trim((string) $c->name) : (string) $c->sender_identifier)),
            ];
        })->all();

        // Filialen in Nummernreihenfolge, Kanaele ohne Filiale dahinter.
        usort($options, static fn (array $a, array $b) => [$a['filial_nr'] === null, $a['filial_nr'], $a['channel_id']]
            <=> [$b['filial_nr'] === null, $b['filial_nr'], $b['channel_id']]);

        return array_values($options);
    }

    /** Beschriftung eines Kanals, null wenn er (nicht mehr) zur Auswahl gehoert. */
    public function labelFor(int $teamId, ?int $channelId): ?string
    {
        if ($channelId === null) {
            return null;
        }
        foreach ($this->options($teamId) as $option) {
            if ($option['channel_id'] === $channelId) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * Vorbelegung im Weiterleiten-Fenster, in dieser Reihenfolge:
     *  1. die Filiale, der der MA zugeordnet ist ($filialNr = Kostenstelle,
     *     siehe RecEmployee::dispoFilialNr()) — wenn sie einen Kanal hat;
     *  2. sonst der Dispo-Kanal, ueber den diese Nummer zuletzt geschrieben hat;
     *  3. gibt es nur EINEN Kanal, ist er es.
     * Sonst null: HR waehlt selbst.
     */
    public function preferredFor(int $teamId, string $phone, ?int $filialNr = null): ?int
    {
        $options = $this->options($teamId);
        $ids = array_column($options, 'channel_id');
        if ($ids === []) {
            return null;
        }
        if ($filialNr !== null) {
            foreach ($options as $option) {
                if ($option['filial_nr'] === $filialNr) {
                    return $option['channel_id'];
                }
            }
        }
        if (count($ids) === 1) {
            return $ids[0];
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        $letzter = \Platform\Crm\Models\CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $ids)
            ->whereIn('remote_phone_number', ['+' . $digits, $digits])
            ->get(['comms_channel_id', 'last_inbound_at', 'last_outbound_at'])
            ->sortByDesc(fn ($t) => max($t->last_inbound_at?->getTimestamp() ?? 0, $t->last_outbound_at?->getTimestamp() ?? 0))
            ->first();

        return $letzter !== null && ($letzter->last_inbound_at !== null || $letzter->last_outbound_at !== null)
            ? (int) $letzter->comms_channel_id
            : null;
    }

    /** @return list<int> */
    private static function recruitingChannelIds(int $teamId): array
    {
        try {
            return array_map('intval', \Platform\Recruiting\Services\Comms\RecruitingChannelResolver::channelIds($teamId));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<int> */
    private static function dispoChannelIds(): array
    {
        try {
            return array_map('intval', DispoChannelResolver::dispoChannelIds());
        } catch (\Throwable) {
            return [];
        }
    }
}
