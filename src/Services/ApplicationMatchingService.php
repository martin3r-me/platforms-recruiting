<?php

namespace Platform\Recruiting\Services;

use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecIntakeChannel;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;
use Platform\Recruiting\Services\RefParsers\RefParserRegistry;

class ApplicationMatchingService
{
    /**
     * Gate: Ist dieser Kanal überhaupt ein Bewerbungs-Eingang?
     * Intake-Registry ODER dedizierter Kanal (exklusiv an genau einer offenen Ausschreibung).
     */
    public function isIntakeChannel(CommsChannel $channel): bool
    {
        return RecIntakeChannel::isIntake($channel->id, $channel->team_id)
            || $this->dedicatedPostingForChannel($channel) !== null;
    }

    /**
     * Dedizierter Kanal = Kanal hängt an GENAU einer offenen Ausschreibung (Kampagnen-Fall).
     */
    public function dedicatedPostingForChannel(CommsChannel $channel): ?RecPosting
    {
        $postings = RecPosting::query()
            ->whereHas('commsChannels', fn ($q) => $q->where('comms_channels.id', $channel->id))
            ->open()
            ->limit(2)
            ->get();

        return $postings->count() === 1 ? $postings->first() : null;
    }

    /**
     * Stufe 1: dedizierter Kanal, dann Portal-Referenz via quellen-spezifischem Parser.
     * Liefert auch VIA_SUGGESTION, wenn eine Referenz auf eine geschlossene Ausschreibung zeigt.
     */
    public function matchDeterministic(
        CommsChannel $channel,
        ?RecSourcePlatform $source,
        ?string $subject,
        ?string $body,
    ): ?MatchResult {
        if ($dedicated = $this->dedicatedPostingForChannel($channel)) {
            return new MatchResult($dedicated, MatchResult::VIA_DEDICATED_CHANNEL);
        }

        // Stufe 1b: Referenz-Code (quellen-unabhängig — Codes kommen von beliebigen Absendern)
        if ($code = (new RefCodeParser())->extract($subject, $body)) {
            $posting = RecPostingExternalRef::query()
                ->where('team_id', $channel->team_id)
                ->where('external_ref', $code)
                ->whereHas('sourcePlatform', fn ($q) => $q->where('ref_parser', 'ref_code'))
                ->first()
                ?->posting;

            if ($posting && RecPosting::query()->open()->whereKey($posting->id)->exists()) {
                return new MatchResult($posting, MatchResult::VIA_EXTERNAL_REF);
            }
            if ($posting) {
                // Der Code benennt die Stelle eindeutig, auch wenn seine
                // Ausschreibung zu ist. Also über die Stelle auffangen statt in
                // die Inbox zu schieben — sonst landen Bewerber einer noch
                // laufenden Anzeige auf der Sammel-Ausschreibung "Sonstiges".
                if ($fallback = $this->fallbackPostingForPosition($posting, (int) $channel->team_id)) {
                    return new MatchResult(
                        $fallback,
                        MatchResult::VIA_POSITION_FALLBACK,
                        reason: 'Referenz-Code zeigt auf geschlossene Ausschreibung "' . $posting->title
                            . '" — aufgefangen über die Stelle',
                    );
                }

                return new MatchResult(
                    $posting,
                    MatchResult::VIA_SUGGESTION,
                    reason: 'Referenz-Code zeigt auf geschlossene Ausschreibung "' . $posting->title . '"',
                );
            }
            // Code ohne Treffer → normale Pipeline weiterlaufen lassen
        }

        if (!$source || !$source->ref_parser) {
            return null;
        }

        $ref = RefParserRegistry::for($source->ref_parser)?->extract($subject, $body);
        if (!$ref) {
            return null;
        }

        $posting = RecPostingExternalRef::query()
            ->where('rec_source_platform_id', $source->id)
            ->where('external_ref', $ref)
            ->where('team_id', $channel->team_id)
            ->first()
            ?->posting;

        if (!$posting) {
            return null;
        }

        $isOpen = RecPosting::query()->open()->whereKey($posting->id)->exists();
        if ($isOpen) {
            return new MatchResult($posting, MatchResult::VIA_EXTERNAL_REF);
        }

        // Spec: Referenz auf geschlossene Ausschreibung → kein Auto-Assign, Inbox-Vorschlag
        return new MatchResult(
            $posting,
            MatchResult::VIA_SUGGESTION,
            reason: 'Portal-Referenz zeigt auf geschlossene Ausschreibung "' . $posting->title . '"',
        );
    }

    /**
     * AUFFANGTOPF EINER STELLE: die älteste offene Ausschreibung dahinter.
     *
     * Warum die älteste und nicht ein eigenes Flag: auf allen vier
     * "allgemein"-Stellen ist das genau die jeweilige "Initiativ via
     * Webseite"-Ausschreibung (38-41) — sie wurden vor den Kampagnen-Anzeigen
     * angelegt. Kein neues Feld, keine Namenskonvention, die still bricht,
     * wenn jemand eine Anzeige umbenennt.
     *
     * Liefert null, wenn die Stelle KEINE offene Ausschreibung mehr hat (Stand
     * heute: "Düsseldorf - Messe"). Dann bleibt es beim Inbox-Vorschlag — ein
     * Auto-Assign auf eine fremde Stelle wäre geraten.
     */
    private function fallbackPostingForPosition(RecPosting $closed, int $teamId): ?RecPosting
    {
        if (!$closed->rec_position_id) {
            return null;
        }

        return RecPosting::query()
            ->forTeam($teamId)
            ->where('rec_position_id', $closed->rec_position_id)
            ->open()
            ->orderBy('id')
            ->first();
    }

    /**
     * Stufe 3: optionale Fallback-Ausschreibung des Intake-Kanals (nur wenn offen).
     */
    public function defaultPostingForChannel(CommsChannel $channel): ?RecPosting
    {
        $intake = RecIntakeChannel::query()
            ->where('comms_channel_id', $channel->id)
            ->where('is_active', true)
            ->first();

        $posting = $intake?->defaultPosting;
        if (!$posting) {
            return null;
        }

        return RecPosting::query()->open()->whereKey($posting->id)->exists() ? $posting : null;
    }
}
