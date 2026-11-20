<?php

namespace Platform\Recruiting\Services\Zeit;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecZeitWaesche;
use Platform\Recruiting\Services\Zeit\Waesche\WaescheArtikel;
use Platform\Recruiting\Services\Zeit\Waesche\WaescheRechner;
use Platform\Recruiting\Zeit\ZeitAkteur;
use Platform\Recruiting\Zeit\ZeitEintrag;

/**
 * Wäsche an der Einbuchung (Markus 18./20.11.2026: „Die Wäsche muss direkt mit dazu erfasst
 * werden können“): der Teamleiter bzw. die Disposition trägt im Fenster „Zeit“ ein, welche
 * Teile der Mitarbeiter bekommen hat, und hakt die Rückgabe ab. Sobald die Zeit erledigt
 * ist, ist auch die Wäsche gesperrt — sie geht mit derselben Zeile an ZAS.
 *
 * Ergebnisse: ok | gesperrt | ungueltig | aus | ohne_person | weg
 */
class DispoWaesche
{
    public const MAX_ANZAHL = 20;
    public const MAX_PREIS_CENT = 100000;

    public function __construct(private readonly ZeitSchalter $schalter) {}

    /**
     * Posten und Rechnung fuer das Fenster.
     *
     * @return array{posten: list<array{id:int, name:string, anzahl:int, zurueck:int, sonstiges:bool, preis:string}>, abzug:string, fehlt:list<string>, kunde:array<string,int>}
     */
    public function liste(RecDispoAssignment $a): array
    {
        $zeilen = $this->posten($a);
        $r = WaescheRechner::rechne(array_map(fn (RecZeitWaesche $w) => $w->posten(), $zeilen));

        return [
            'posten' => array_map(fn (RecZeitWaesche $w) => [
                'id'        => (int) $w->id,
                'name'      => (string) $w->bezeichnung,
                'anzahl'    => (int) $w->anzahl,
                'zurueck'   => (int) $w->zurueck,
                'sonstiges' => $w->artikel_key === WaescheArtikel::SONSTIGES,
                'preis'     => WaescheRechner::euro((int) $w->preis_cent),
            ], $zeilen),
            'abzug' => WaescheRechner::euro($r['abzugCent']),
            'fehlt' => $r['fehlt'],
            'kunde' => $r['kunde'],
        ];
    }

    /** Ein Teil ausgeben: fester Artikel (key) oder „sonstiges“ mit Name und Preis in Cent. */
    public function ausgeben(RecDispoAssignment $a, string $key, int $anzahl, ZeitAkteur $akteur, string $name = '', int $preisCent = 0): string
    {
        if ($anzahl < 1 || $anzahl > self::MAX_ANZAHL) {
            return 'ungueltig';
        }
        if ($key === WaescheArtikel::SONSTIGES) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]+/u', ' ', $name)));
            if ($name === '' || mb_strlen($name) > 80 || $preisCent < 0 || $preisCent > self::MAX_PREIS_CENT) {
                return 'ungueltig';
            }
            $artikel = WaescheArtikel::sonstiges($name, $preisCent);
        } else {
            $artikel = WaescheArtikel::fest()[$key] ?? null;
            if ($artikel === null) {
                return 'ungueltig';
            }
        }

        return $this->schreiben($a, function () use ($a, $artikel, $anzahl, $akteur) {
            RecZeitWaesche::create([
                'team_id'                 => $this->schalter->teamId() ?: null,
                'rec_dispo_assignment_id' => (int) $a->id,
                'rec_employee_id'         => (int) $a->rec_employee_id,
                'artikel_key'             => $artikel->key,
                'bezeichnung'             => $artikel->name,
                'preis_cent'              => $artikel->preisCent,
                'reinigung_lohn_cent'     => $artikel->reinigungLohnCent,
                'leihe_kunde'             => $artikel->leiheKunde,
                'anzahl'                  => $anzahl,
                'zurueck'                 => 0,
                'ausgegeben_von'          => mb_substr($akteur->name, 0, 120),
            ]);

            return 'ok';
        });
    }

    /** Wie viele Teile dieses Postens zurück sind (0 bis Anzahl). */
    public function zurueck(RecDispoAssignment $a, int $postenId, int $zurueck, ZeitAkteur $akteur): string
    {
        return $this->schreiben($a, function () use ($a, $postenId, $zurueck, $akteur) {
            $w = $this->einPosten($a, $postenId);
            if ($w === null) {
                return 'weg';
            }
            if ($zurueck < 0 || $zurueck > (int) $w->anzahl) {
                return 'ungueltig';
            }
            $w->update(['zurueck' => $zurueck, 'geaendert_von' => mb_substr($akteur->name, 0, 120)]);

            return 'ok';
        });
    }

    public function entfernen(RecDispoAssignment $a, int $postenId): string
    {
        return $this->schreiben($a, function () use ($a, $postenId) {
            $w = $this->einPosten($a, $postenId);
            if ($w === null) {
                return 'weg';
            }
            $w->delete();

            return 'ok';
        });
    }

    /**
     * Rechnung je Einbuchung fuer den ZAS-Export (nur die Person, die gerade eingebucht ist).
     *
     * @param array<int, int> $personJeEinbuchung Einbuchungs-ID => Mitarbeiter-ID
     * @return array<int, array{abzugCent:int, kunde:array<string,int>, fehlt:list<string>, ausgegeben:list<string>, allesZurueck:bool}>
     */
    public function rechnungen(array $personJeEinbuchung): array
    {
        if ($personJeEinbuchung === []) {
            return [];
        }
        $gruppen = [];
        $zeilen = RecZeitWaesche::query()
            ->whereIn('rec_dispo_assignment_id', array_keys($personJeEinbuchung))
            ->orderBy('id')
            ->get();
        foreach ($zeilen as $w) {
            $aid = (int) $w->rec_dispo_assignment_id;
            if (($personJeEinbuchung[$aid] ?? null) !== (int) $w->rec_employee_id) {
                continue;
            }
            $gruppen[$aid][] = $w->posten();
        }

        return array_map(fn (array $posten) => WaescheRechner::rechne($posten), $gruppen);
    }

    /** @return list<RecZeitWaesche> */
    private function posten(RecDispoAssignment $a): array
    {
        if (empty($a->rec_employee_id)) {
            return [];
        }

        return RecZeitWaesche::query()
            ->where('rec_dispo_assignment_id', $a->id)
            ->where('rec_employee_id', $a->rec_employee_id)
            ->orderBy('id')
            ->get()->all();
    }

    private function einPosten(RecDispoAssignment $a, int $postenId): ?RecZeitWaesche
    {
        return RecZeitWaesche::query()
            ->whereKey($postenId)
            ->where('rec_dispo_assignment_id', $a->id)
            ->where('rec_employee_id', $a->rec_employee_id)
            ->first();
    }

    /**
     * Schreibweg mit Sperrpruefung: Schalter an, Person da, Zeit nicht erledigt. Der Zeiteintrag
     * wird dabei gesperrt gelesen — „Erledigt“ (Stempeluhr::sperren) sperrt dieselbe Zeile, beide
     * laufen also nacheinander, nie durcheinander.
     *
     * @param \Closure(): string $schritt
     */
    private function schreiben(RecDispoAssignment $a, \Closure $schritt): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        if (empty($a->rec_employee_id)) {
            return 'ohne_person';
        }

        return DB::connection((new ZeitEintrag())->getConnectionName())->transaction(function () use ($a, $schritt) {
            $e = ZeitEintrag::query()
                ->where('person_typ', DispoZeitBezug::PERSON_TYP)
                ->where('person_id', (int) $a->rec_employee_id)
                ->where('bezug_typ', DispoZeitBezug::BEZUG_TYP)
                ->where('bezug_id', (int) $a->id)
                ->lockForUpdate()
                ->first();
            if ($e !== null && $e->istGesperrt()) {
                return 'gesperrt';
            }

            return $schritt();
        });
    }
}
