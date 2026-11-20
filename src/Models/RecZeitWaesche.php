<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\Recruiting\Services\Zeit\Waesche\WaescheArtikel;

/** Ein ausgegebener Wäsche-Posten an einer Einbuchung (siehe Migration 2026_11_20_000002). */
class RecZeitWaesche extends Model
{
    protected $table = 'rec_zeit_waesche';

    protected $guarded = [];

    protected $casts = [
        'preis_cent'          => 'integer',
        'reinigung_lohn_cent' => 'integer',
        'leihe_kunde'         => 'boolean',
        'anzahl'              => 'integer',
        'zurueck'             => 'integer',
    ];

    public function artikel(): WaescheArtikel
    {
        return new WaescheArtikel(
            (string) $this->artikel_key,
            (string) $this->bezeichnung,
            (int) $this->preis_cent,
            (int) $this->reinigung_lohn_cent,
            (bool) $this->leihe_kunde,
        );
    }

    /** @return array{artikel: WaescheArtikel, anzahl: int, zurueck: int} */
    public function posten(): array
    {
        return ['artikel' => $this->artikel(), 'anzahl' => (int) $this->anzahl, 'zurueck' => (int) $this->zurueck];
    }
}
