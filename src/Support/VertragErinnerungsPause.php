<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ab wann darf HR einen offenen Vertrag "erneut erinnern" (Spec HR-Bereich
 * §3.3, Zeile Vertrag)? 7 Tage ab max(sent_at, letzter Vermerk
 * `erneut_erinnert` mit Ergebnis `sent` zu genau diesem Vertrag).
 *
 * EINE STELLE (Ruling R3): HrKontoAktionen (Versand) und die HR-Listen
 * (Chip "erinnern ab …") fragen beide hier. Zwei Fassungen liefen
 * auseinander — die Liste zeigte dann einen Knopf, den der Versand abweist,
 * oder umgekehrt.
 *
 * Das Pausen-Ende selbst rechnet HrKontoAktionen::pauseBis() aus dem
 * Stempel (EinsatzBezug::PAUSE_TAGE).
 */
final class VertragErinnerungsPause
{
    public const PRAEFIX = 'vertrag:';

    public static function bezug(int $vertragId): string
    {
        return self::PRAEFIX . $vertragId;
    }

    /**
     * Letzte ERFOLGREICHE HR-Erinnerung je Vertrag — EINE Abfrage fuer die
     * ganze Menge, nur Vermerke des Teams.
     *
     * @param  list<int>  $vertragIds
     * @return array<int, string>  Vertrag-ID => 'Y-m-d H:i:s' (nur Vertraege mit Erinnerung)
     */
    public static function letzteErinnerungen(int $teamId, array $vertragIds): array
    {
        if ($vertragIds === []) {
            return [];
        }

        $zeilen = DB::table('rec_hr_vermerke')
            ->where('team_id', $teamId)
            ->where('art', 'erneut_erinnert')
            ->where('ergebnis', 'sent')
            ->whereIn('bezug', array_map(static fn ($id): string => self::bezug((int) $id), $vertragIds))
            ->groupBy('bezug')
            ->selectRaw('bezug, max(created_at) as zuletzt')
            ->pluck('zuletzt', 'bezug')
            ->all();

        $ergebnis = [];
        foreach ($zeilen as $bezug => $zuletzt) {
            $ergebnis[(int) substr((string) $bezug, strlen(self::PRAEFIX))] = date('Y-m-d H:i:s', strtotime((string) $zuletzt));
        }

        return $ergebnis;
    }

    /**
     * Der spaetere von beiden Zeitpunkten als 'Y-m-d H:i:s' — oder null,
     * wenn es keinen gibt.
     */
    public static function stempel(\DateTimeInterface|string|null $sentAt, ?string $letzteErinnerung): ?string
    {
        $kandidaten = [];
        if ($sentAt instanceof \DateTimeInterface) {
            $kandidaten[] = $sentAt->format('Y-m-d H:i:s');
        } elseif ($sentAt !== null && $sentAt !== '') {
            $kandidaten[] = date('Y-m-d H:i:s', strtotime($sentAt));
        }
        if ($letzteErinnerung !== null && $letzteErinnerung !== '') {
            $kandidaten[] = date('Y-m-d H:i:s', strtotime($letzteErinnerung));
        }

        return $kandidaten === [] ? null : max($kandidaten);
    }
}
