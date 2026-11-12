<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\ZasStatus;

/**
 * „Wer ist an dem Tag frei?“ je Schicht (Markus 12.11.2026): Personen mit
 * passender Taetigkeit, die an dem Tag weder gebucht sind noch zugesagt haben —
 * zum persoenlichen Ansprechen ausserhalb der Wellen. Rein rechnend, auf den
 * Daten von DispoJobKandidaten; nichts wird versendet.
 *
 * Wie beim Empfaengerplaner faellt niemand still heraus: jeder Ausschluss wird
 * gezaehlt. Anders als dort bleiben schon Angeschriebene OHNE Antwort in der
 * Liste — genau die sollen nachgefasst werden.
 */
final class DispoJobFreieListe
{
    public const STAND_ZUGESAGT = 'zugesagt';
    public const STAND_ABGESAGT = 'abgesagt';
    public const STAND_ANGESCHRIEBEN = 'angeschrieben';

    public const OHNE = [
        'gebucht'    => 'an dem Tag schon gebucht',
        'zugesagt'   => 'haben für den Tag zugesagt',
        'abgesagt'   => 'haben abgesagt',
        'gesperrt'   => 'Portal gesperrt',
        'taetigkeit' => 'ohne passende Tätigkeit',
    ];

    /**
     * Aus der Datenbank: Stand je Person in DIESER Schicht, Filialen aller Wellen der
     * Anfrage (ohne Welle: alle), dann fuerSchicht().
     *
     * @return array{zeilen: list<array<string,mixed>>, ohne: array<string,int>, bloecke: ?list<int|null>}
     */
    public static function laden(int $teamId, RecDispoJobRequest $anfrage, RecDispoJobShift $schicht): array
    {
        $tag = $schicht->datum->format('Y-m-d');
        $daten = DispoJobKandidaten::laden($teamId, [$tag], now()->format('Y-m'));

        // Stand je Datensatz, der das Angebot bekam; der gespeicherte Anker ist nur Rueckfall (siehe heutigeAnker).
        $roh = [];
        RecDispoJobOfferShift::query()->where('shift_id', $schicht->id)
            ->with('offer:id,rec_employee_id,anchor_employee_id,sent_at')
            ->get(['id', 'offer_id', 'accepted_at', 'declined_at'])
            ->each(function ($os) use (&$roh) {
                if ($os->offer === null) {
                    return;
                }
                $neu = match (true) {
                    $os->accepted_at !== null => self::STAND_ZUGESAGT,
                    $os->declined_at !== null => self::STAND_ABGESAGT,
                    // Fehlversand (kein sent_at) hat die Person nicht erreicht.
                    $os->offer->sent_at !== null => self::STAND_ANGESCHRIEBEN,
                    default => null,
                };
                if ($neu !== null) {
                    $roh[] = [(int) $os->offer->rec_employee_id, (int) $os->offer->anchor_employee_id, $neu];
                }
            });
        $zugesagtRoh = DispoJobSchonZugesagt::laden($teamId);

        $anker = self::heutigeAnker(
            array_merge(array_column($roh, 0), array_column($roh, 1), array_keys($zugesagtRoh)),
            array_map('intval', array_column($daten['kandidaten'], 'anchor'))
        );
        $stand = [];
        foreach ($roh as [$employeeId, $gespeichert, $neu]) {
            $a = $anker[$employeeId] ?? $anker[$gespeichert] ?? $gespeichert;
            // Zusage/Absage gewinnt gegen ein weiteres, unbeantwortetes Angebot derselben Person.
            if (($stand[$a] ?? self::STAND_ANGESCHRIEBEN) === self::STAND_ANGESCHRIEBEN) {
                $stand[$a] = $neu;
            }
        }
        $schonZugesagt = [];
        foreach ($zugesagtRoh as $gespeichert => $schluessel) {
            $a = $anker[(int) $gespeichert] ?? (int) $gespeichert;
            $schonZugesagt[$a] = array_merge($schonZugesagt[$a] ?? [], $schluessel);
        }

        $wellen = $anfrage->waves()->get(['id', 'ma_bloecke']);
        $bloecke = null;
        if ($wellen->isNotEmpty()) {
            $bloecke = [];
            foreach ($wellen as $w) {
                foreach ((array) $w->ma_bloecke as $b) {
                    $bloecke[$b === null ? 'ohne' : 'b' . (int) $b] = $b === null ? null : (int) $b;
                }
            }
            $bloecke = array_values($bloecke);
        }

        return self::fuerSchicht($daten, ['datum' => $tag, 'taetigkeit' => (string) $schicht->taetigkeit], $stand, $schonZugesagt, $bloecke)
            + ['bloecke' => $bloecke];
    }

    /**
     * Angebote und Zusagen tragen den Anker vom Tag des Versands. Der Anker einer Person
     * (kleinste aktive ID ihrer Gruppe) wandert aber, wenn ihr aeltester Datensatz
     * deaktiviert oder ein aelterer dazugepaart wird — dann stuende jemand mit Zusage
     * als „frei, noch nicht angeschrieben“ in der Liste (Review 12.11.2026). Deshalb:
     * jede gespeicherte ID auf den HEUTIGEN Anker abbilden. Erst ueber die Gruppe der
     * ID selbst; ist sie inaktiv, ueber ihren CRM-Kontakt zu den aktiven Geschwistern.
     *
     * @param list<int> $ids gespeicherte Datensatz- und Anker-IDs
     * @param list<int> $heutige Anker der heutigen Kandidaten
     * @return array<int,int> ID => heutiger Anker (nur aufgeloeste)
     */
    private static function heutigeAnker(array $ids, array $heutige): array
    {
        $heutigeSet = array_fill_keys($heutige, true);
        $out = [];
        $offen = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) {
                continue;
            }
            if (isset($heutigeSet[$id])) {
                $out[$id] = $id;
            } else {
                $offen[] = $id;
            }
        }
        if ($offen === []) {
            return $out;
        }

        $resolver = app(DispoIdentityResolver::class);
        $inaktiv = [];
        foreach ($resolver->groupsFor($offen) as $id => $gruppe) {
            $a = (int) min($gruppe);
            if (isset($heutigeSet[$a])) {
                $out[(int) $id] = $a;
            } else {
                $inaktiv[] = (int) $id;
            }
        }

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
        if ($inaktiv === [] || $teamId <= 0) {
            return $out;
        }
        $kontakte = CrmContactLink::query()
            ->where('linkable_type', (new RecEmployee())->getMorphClass())->where('team_id', $teamId)
            ->whereIn('linkable_id', $inaktiv)
            ->get(['contact_id', 'linkable_id']);
        $aktive = $resolver->employeeIdsByContact($kontakte->pluck('contact_id')->map(fn ($v) => (int) $v)->all());
        $geschwister = [];
        foreach ($kontakte as $link) {
            $erste = $aktive[(int) $link->contact_id][0] ?? null;
            if ($erste !== null) {
                $geschwister[(int) $link->linkable_id] = (int) $erste;
            }
        }
        if ($geschwister !== []) {
            $gruppen = $resolver->groupsFor(array_values(array_unique($geschwister)));
            foreach ($geschwister as $id => $aktiv) {
                $a = (int) min($gruppen[$aktiv] ?? [$aktiv]);
                if (isset($heutigeSet[$a])) {
                    $out[$id] = $a;
                }
            }
        }

        return $out;
    }

    /**
     * @param array{kandidaten: list<array<string,mixed>>, gebucht: array<int,array<string,true>>, reihenfolge?: array{einsaetze?: array<int,int>}} $daten DispoJobKandidaten::laden()
     * @param array{datum:string, taetigkeit:string} $schicht
     * @param array<int,string> $stand Anker => STAND_* in DIESER Schicht (ohne Eintrag = nie angeschrieben)
     * @param array<int,list<string>> $schonZugesagt Anker => schichtSchluessel() aller kuenftigen Zusagen (DispoJobSchonZugesagt)
     * @param ?list<int|null> $bloecke Filialen der Anfrage (null im Array = ohne Kostenstelle); null = alle
     * @return array{zeilen: list<array{anchor:int, employee_id:int, name:string, phone:string, block:?int, status:string, einsaetze:int, angeschrieben:bool}>, ohne: array<string,int>}
     */
    public static function fuerSchicht(array $daten, array $schicht, array $stand, array $schonZugesagt = [], ?array $bloecke = null): array
    {
        $tag = substr((string) $schicht['datum'], 0, 10);
        $taetigkeit = mb_strtolower(trim((string) $schicht['taetigkeit']));
        $ohneKostenstelle = $bloecke !== null && in_array(null, $bloecke, true);
        $blockSet = $bloecke === null ? null : array_fill_keys(array_map('intval', array_filter($bloecke, fn ($b) => $b !== null)), true);

        $ohne = array_fill_keys(array_keys(self::OHNE), 0);
        $zeilen = [];
        foreach ($daten['kandidaten'] as $k) {
            $anchor = (int) $k['anchor'];
            $block = $k['block'] ?? null;
            if ($blockSet !== null && !($block === null ? $ohneKostenstelle : isset($blockSet[(int) $block]))) {
                continue;
            }

            $eigene = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $k['taetigkeiten'] ?? []);
            if (!in_array($taetigkeit, $eigene, true)) {
                $ohne['taetigkeit']++;
                continue;
            }
            if (isset($daten['gebucht'][$anchor][$tag])) {
                $ohne['gebucht']++;
                continue;
            }
            $hier = $stand[$anchor] ?? null;
            if ($hier === self::STAND_ZUGESAGT || self::zusageAmTag($schonZugesagt[$anchor] ?? [], $tag)) {
                $ohne['zugesagt']++;
                continue;
            }
            if ($hier === self::STAND_ABGESAGT) {
                $ohne['abgesagt']++;
                continue;
            }
            if (!empty($k['portal_locked'])) {
                $ohne['gesperrt']++;
                continue;
            }

            $zeilen[] = [
                'anchor'        => $anchor,
                'employee_id'   => (int) $k['employee_id'],
                'name'          => (string) ($k['name'] ?? $k['first_name'] ?? ('#' . $anchor)),
                'phone'         => trim((string) ($k['phone'] ?? '')),
                // Beschriftung macht die Seite (DispoJobKandidaten::blockLabel braucht die Konfiguration).
                'block'         => $block === null ? null : (int) $block,
                'status'        => (string) ($k['status'] ?? ZasStatus::GO),
                'einsaetze'     => (int) ($daten['reihenfolge']['einsaetze'][$anchor] ?? 0),
                'angeschrieben' => $hier === self::STAND_ANGESCHRIEBEN,
            ];
        }

        // Wie die Wellen: wer am wenigsten Einsaetze hat, steht oben; dann nach Name.
        usort($zeilen, fn ($a, $b) => [$a['einsaetze'], mb_strtolower($a['name']), $a['anchor']] <=> [$b['einsaetze'], mb_strtolower($b['name']), $b['anchor']]);

        return ['zeilen' => $zeilen, 'ohne' => $ohne];
    }

    /** Eine Zusage zaehlt fuer den ganzen Tag (Regel „einmal pro Tag“) — egal in welcher Anfrage. */
    private static function zusageAmTag(array $schluessel, string $tag): bool
    {
        foreach ($schluessel as $s) {
            if ((explode('|', (string) $s)[1] ?? '') === $tag) {
                return true;
            }
        }

        return false;
    }
}
