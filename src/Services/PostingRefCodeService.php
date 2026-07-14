<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\RefParsers\RefCodeParser;

/**
 * Erzeugt/liest den RG-XXXX-Referenz-Code einer Ausschreibung.
 * Der Code lebt als RecPostingExternalRef unter der synthetischen
 * Source-Platform "Referenz-Code" (ref_parser='ref_code') und wird
 * in Matching-Stufe 1 quellen-unabhängig aufgelöst.
 */
class PostingRefCodeService
{
    /** Sentinel: matcht absichtlich NIE einen echten Absender — die Code-Stufe im Matching ist quellen-unabhängig. */
    private const SENTINEL_PATTERN = '@@referenz-code-niemals-absender@@';

    public const SOURCE_NAME = 'Referenz-Code';

    /** Idempotent: liefert den bestehenden Code oder erzeugt genau einen neuen. */
    public function ensure(RecPosting $posting): string
    {
        $existing = $this->codeFor($posting);
        if ($existing !== null) {
            return $existing;
        }

        $source = $this->sourcePlatform((int) $posting->team_id);

        do {
            $code = RefCodeParser::generate();
        } while (RecPostingExternalRef::query()
            ->where('rec_source_platform_id', $source->id)
            ->where('external_ref', $code)
            ->exists());

        RecPostingExternalRef::create([
            'rec_posting_id' => $posting->id,
            'rec_source_platform_id' => $source->id,
            'external_ref' => $code,
            'team_id' => $posting->team_id,
        ]);

        return $code;
    }

    /**
     * Reiner Lookup ohne Seiteneffekte (für UI/Anzeige).
     * Auflösung über ref_parser='ref_code' — dieselbe Semantik wie
     * Matching-Stufe 1b (ApplicationMatchingService.php:58) und
     * FlynkPostingReconciler::refCodeOf(). Der Name SOURCE_NAME ist
     * nur Natural Key fürs Anlegen, nie fürs Auflösen.
     */
    public function codeFor(RecPosting $posting): ?string
    {
        // Deterministisch ältester Eintrag (= auto-generierter Code), sonst könnte der Flynk-Hash zwischen Läufen kippen.
        return RecPostingExternalRef::query()
            ->where('rec_posting_id', $posting->id)
            ->whereHas('sourcePlatform', fn ($q) => $q->where('ref_parser', 'ref_code'))
            ->orderBy('id')
            ->value('external_ref');
    }

    private function sourcePlatform(int $teamId): RecSourcePlatform
    {
        $source = RecSourcePlatform::firstOrCreate(
            ['team_id' => $teamId, 'name' => self::SOURCE_NAME],
            ['match_pattern' => self::SENTINEL_PATTERN, 'ref_parser' => 'ref_code', 'is_active' => true, 'priority' => 999],
        );
        if ($source->ref_parser !== 'ref_code') {
            $source->update(['ref_parser' => 'ref_code']);
        }

        return $source;
    }
}
