<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Soll-Mitarbeiterzahl je Kostenstelle (Hunderterblock) mit „gültig ab“. Eine Zeile je Änderung;
 * gelesen wird immer der jüngste Eintrag, der am Stichtag schon galt.
 */
class RecKostenstellenSoll extends Model
{
    public const HERKUNFT_MANUELL = 'manuell';
    public const HERKUNFT_BERECHNET = 'berechnet';

    protected $table = 'rec_kostenstellen_soll';

    protected $fillable = ['team_id', 'kostenstelle', 'soll', 'gueltig_ab', 'herkunft', 'created_by_user_id'];

    protected $casts = [
        'team_id' => 'integer',
        'kostenstelle' => 'integer',
        'soll' => 'integer',
        'gueltig_ab' => 'date:Y-m-d',
    ];

    /**
     * Soll je Kostenstelle, das am Stichtag galt.
     *
     * @return array<int,int> kostenstelle => soll
     */
    public static function geltend(int $teamId, string $stichtagYmd): array
    {
        $out = [];
        $zeilen = static::query()
            ->where('team_id', $teamId)
            ->where('gueltig_ab', '<=', $stichtagYmd)
            ->orderBy('gueltig_ab')
            ->orderBy('id')
            ->get(['kostenstelle', 'soll']);
        foreach ($zeilen as $z) {
            // Aufsteigend sortiert: der juengste Eintrag ueberschreibt die aelteren.
            $out[(int) $z->kostenstelle] = (int) $z->soll;
        }

        return $out;
    }
}
