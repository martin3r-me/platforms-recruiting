<?php

namespace Platform\Recruiting\Zeit;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Stempeln, Korrigieren, Leeren, Umhaengen, Sperren — jeder schreibende Weg
 * laeuft in einer Transaktion mit Zeilensperre und schreibt den Verlauf im
 * selben Schritt (Spec 2026-11-16 §2.1).
 */
class Stempeluhr
{
    public const ART_SELBST = 'selbst';
    public const ART_STELLVERTRETEND = 'stellvertretend';
    public const ART_KORREKTUR = 'korrektur';
    public const ART_SPERRE = 'sperre';

    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private ZeitRegeln $regeln)
    {
    }

    /** ok | schon | zu_frueh | zu_spaet | gesperrt */
    public function kommen(ZeitBezug $b, ZeitAkteur $a, string $art, ?\DateTimeInterface $zeit = null): string
    {
        $zeit = $this->zeitpunkt($zeit);

        return $this->verbindung()->transaction(function () use ($b, $a, $art, $zeit) {
            $e = $this->lade($b);
            if ($e !== null) {
                if ($e->istGesperrt()) {
                    return 'gesperrt';
                }
                if ($e->kommen_at !== null) {
                    return 'schon';
                }
            }

            if ($art === self::ART_SELBST) {
                $frueh = $this->regeln->fruehestesKommen($b);
                if ($frueh === null || $zeit->lt($frueh)) {
                    return 'zu_frueh';
                }
                $spaet = $this->regeln->spaetestesStempeln($b);
                if ($spaet !== null && $zeit->gt($spaet)) {
                    return 'zu_spaet';
                }
            }

            $e ??= $this->lege($b);
            // Nur nach verlorenem Rennen moeglich: der andere Aufruf war schneller.
            if ($e->istGesperrt()) {
                return 'gesperrt';
            }
            if ($e->kommen_at !== null) {
                return 'schon';
            }

            $this->schreibe($e, $b, ['kommen_at' => $zeit], $a, $art, null);

            return 'ok';
        });
    }

    /** ok | kein_kommen | schon | zu_spaet | gesperrt */
    public function gehen(ZeitBezug $b, ZeitAkteur $a, string $art, ?\DateTimeInterface $zeit = null): string
    {
        $zeit = $this->zeitpunkt($zeit);

        return $this->verbindung()->transaction(function () use ($b, $a, $art, $zeit) {
            $e = $this->lade($b);
            if ($e === null) {
                return 'kein_kommen';
            }
            if ($e->istGesperrt()) {
                return 'gesperrt';
            }
            if ($e->kommen_at === null) {
                return 'kein_kommen';
            }
            if ($e->gehen_at !== null) {
                return 'schon';
            }

            if ($art === self::ART_SELBST) {
                // Gegen den uebergebenen (aktuellen) Plan: eine verlaengerte Schicht gilt sofort.
                $spaet = $this->regeln->spaetestesStempeln($b);
                if ($spaet !== null && $zeit->gt($spaet)) {
                    return 'zu_spaet';
                }
            }

            $this->schreibe($e, $b, ['gehen_at' => $zeit], $a, $art, null);

            return 'ok';
        });
    }

    /**
     * $werte: kommen_at, gehen_at, pause_minuten, bemerkung — nur vorhandene Schluessel werden angefasst.
     * Zeit-Instanzen werden in die PHP-Standard-Zeitzone umgerechnet; Strings ohne Zone gelten als schon dort
     * (der Aufrufer rechnet um).
     * ok | gesperrt | ungueltig | unveraendert
     */
    public function korrigieren(ZeitBezug $b, ZeitAkteur $a, array $werte, string $grund): string
    {
        if (trim($grund) === '') {
            return 'ungueltig';
        }

        return $this->verbindung()->transaction(function () use ($b, $a, $werte, $grund) {
            $e = $this->lade($b);
            if ($e !== null && $e->istGesperrt()) {
                return 'gesperrt';
            }

            $neu = [];
            foreach (['kommen_at', 'gehen_at', 'pause_minuten', 'bemerkung'] as $feld) {
                if (array_key_exists($feld, $werte)) {
                    $neu[$feld] = $werte[$feld];
                }
            }

            $kommen = array_key_exists('kommen_at', $neu) ? $this->alsZeit($neu['kommen_at']) : $e?->kommen_at;
            $gehen = array_key_exists('gehen_at', $neu) ? $this->alsZeit($neu['gehen_at']) : $e?->gehen_at;
            if ($kommen === null && $gehen !== null) {
                return 'ungueltig';
            }
            if ($kommen !== null && $gehen !== null && $gehen->lt($kommen)) {
                return 'ungueltig';
            }
            if (array_key_exists('pause_minuten', $neu) && $neu['pause_minuten'] !== null) {
                if (!is_numeric($neu['pause_minuten']) || (int) $neu['pause_minuten'] < 0 || (int) $neu['pause_minuten'] > 600) {
                    return 'ungueltig';
                }
            }

            if ($this->aenderungen($e, $neu) === []) {
                return 'unveraendert';
            }

            $e ??= $this->lege($b);
            if ($e->istGesperrt()) {
                return 'gesperrt';
            }
            $this->schreibe($e, $b, $neu, $a, self::ART_KORREKTUR, $grund);

            return 'ok';
        });
    }

    /** Haengt den Datensatz dieser Person an einen anderen Bezug; kein Verlauf, weil sich kein Zeitwert aendert. */
    public function umhaengen(string $bezugTyp, int $vonBezugId, int $nachBezugId, string $personTyp, int $personId): void
    {
        $this->verbindung()->transaction(function () use ($bezugTyp, $vonBezugId, $nachBezugId, $personTyp, $personId) {
            $e = ZeitEintrag::query()
                ->where('person_typ', $personTyp)->where('person_id', $personId)
                ->where('bezug_typ', $bezugTyp)->where('bezug_id', $vonBezugId)
                ->lockForUpdate()->first();
            if ($e === null) {
                return;
            }
            if (ZeitEintrag::finde($personTyp, $personId, $bezugTyp, $nachBezugId) !== null) {
                return;
            }

            try {
                $this->verbindung()->transaction(fn () => $e->update(['bezug_id' => $nachBezugId]));
            } catch (QueryException) {
                // Das Ziel entstand gerade eben fuer diese Person: dann gilt dort der vorhandene Datensatz.
            }
        });
    }

    /** ok | schon */
    public function sperren(ZeitEintrag $e, ZeitAkteur $a, string $grund): string
    {
        return $this->verbindung()->transaction(function () use ($e, $a, $grund) {
            $e = ZeitEintrag::query()->whereKey($e->getKey())->lockForUpdate()->firstOrFail();
            if ($e->istGesperrt()) {
                return 'schon';
            }

            $jetzt = $this->zeitpunkt(null);
            $e->gesperrt_at = $jetzt;
            $e->gesperrt_grund = $grund;
            $e->save();
            $this->verlaufszeile($e, 'gesperrt_at', null, $jetzt->format(self::FORMAT), self::ART_SPERRE, $a, $grund);

            return 'ok';
        });
    }

    /** ok | nicht_gesperrt | ungueltig (leerer Grund) */
    public function entsperren(ZeitEintrag $e, ZeitAkteur $a, string $grund): string
    {
        if (trim($grund) === '') {
            return 'ungueltig';
        }

        return $this->verbindung()->transaction(function () use ($e, $a, $grund) {
            $e = ZeitEintrag::query()->whereKey($e->getKey())->lockForUpdate()->firstOrFail();
            if (!$e->istGesperrt()) {
                return 'nicht_gesperrt';
            }

            $alt = $e->gesperrt_at->format(self::FORMAT);
            $e->gesperrt_at = null;
            $e->gesperrt_grund = null;
            $e->save();
            $this->verlaufszeile($e, 'gesperrt_at', $alt, null, self::ART_SPERRE, $a, $grund);

            return 'ok';
        });
    }

    private function verbindung(): Connection
    {
        return ZeitEintrag::query()->getConnection();
    }

    private function zeitpunkt(?\DateTimeInterface $zeit): Carbon
    {
        return Carbon::instance($zeit ?? Carbon::now())->setTimezone(date_default_timezone_get());
    }

    private function inStandardZone(?\DateTimeInterface $z): ?Carbon
    {
        return $z === null ? null : Carbon::instance($z)->setTimezone(date_default_timezone_get());
    }

    private function alsZeit(mixed $wert): ?Carbon
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        // Zonenlose Strings gelten als Standard-Zeitzone; Instanzen werden dorthin umgerechnet.
        $zeit = $wert instanceof \DateTimeInterface ? Carbon::instance($wert) : Carbon::parse($wert, date_default_timezone_get());

        return $zeit->setTimezone(date_default_timezone_get());
    }

    /** Gesperrt lesen: Zeile des Datensatzes mit Zeilensperre. */
    private function lade(ZeitBezug $b): ?ZeitEintrag
    {
        return ZeitEintrag::query()
            ->where('person_typ', $b->personTyp)->where('person_id', $b->personId)
            ->where('bezug_typ', $b->bezugTyp)->where('bezug_id', $b->bezugId)
            ->lockForUpdate()->first();
    }

    /** Legt den Datensatz an; verliert das den Wettlauf am Unique-Index, wird der Gewinner gelesen. */
    private function lege(ZeitBezug $b): ZeitEintrag
    {
        try {
            return $this->verbindung()->transaction(fn () => ZeitEintrag::create([
                'team_id'     => $b->teamId,
                'person_typ'  => $b->personTyp,
                'person_id'   => $b->personId,
                'bezug_typ'   => $b->bezugTyp,
                'bezug_id'    => $b->bezugId,
                'tag'         => $b->tag,
                'soll_von_at' => $this->inStandardZone($b->sollVon),
                'soll_bis_at' => $this->inStandardZone($b->sollBis),
            ]));
        } catch (QueryException $ex) {
            $e = $this->lade($b);
            if ($e === null) {
                throw $ex;
            }

            return $e;
        }
    }

    /** @return array<string, array{0: ?string, 1: ?string}> Feld => [alt, neu], nur echte Aenderungen. */
    private function aenderungen(?ZeitEintrag $e, array $neu): array
    {
        $diff = [];
        foreach ($neu as $feld => $wert) {
            $alt = $e === null ? null : $this->text($feld, $e->{$feld});
            $neuText = $this->text($feld, $wert);
            if ($alt !== $neuText) {
                $diff[$feld] = [$alt, $neuText];
            }
        }

        return $diff;
    }

    /** Zeitwerte als Y-m-d H:i:s, Pause als Ziffern, leer als null. */
    private function text(string $feld, mixed $wert): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        if ($feld === 'kommen_at' || $feld === 'gehen_at') {
            return $this->alsZeit($wert)->format(self::FORMAT);
        }
        if ($feld === 'pause_minuten') {
            return (string) (int) $wert;
        }

        $s = trim((string) $wert);

        return $s === '' ? null : $s;
    }

    /** Zieht den Plan aus dem Bezug nach, setzt die geaenderten Felder und schreibt je Feld eine Verlaufszeile. */
    private function schreibe(ZeitEintrag $e, ZeitBezug $b, array $neu, ZeitAkteur $a, string $art, ?string $grund): void
    {
        $diff = $this->aenderungen($e, $neu);

        $e->team_id = $b->teamId;
        $e->tag = $b->tag;
        $e->soll_von_at = $this->inStandardZone($b->sollVon);
        $e->soll_bis_at = $this->inStandardZone($b->sollBis);
        foreach ($diff as $feld => [$alt, $neuText]) {
            if ($feld === 'kommen_at' || $feld === 'gehen_at') {
                $e->{$feld} = $this->alsZeit($neu[$feld]);
            } elseif ($feld === 'pause_minuten') {
                $e->pause_minuten = $neuText === null ? null : (int) $neuText;
            } else {
                $e->{$feld} = $neuText;
            }
        }
        $e->save();

        foreach ($diff as $feld => [$alt, $neuText]) {
            $this->verlaufszeile($e, $feld, $alt, $neuText, $art, $a, $grund);
        }
    }

    private function verlaufszeile(ZeitEintrag $e, string $feld, ?string $alt, ?string $neu, string $art, ZeitAkteur $a, ?string $grund): void
    {
        ZeitVerlauf::create([
            'rec_zeit_eintrag_id' => $e->getKey(),
            'feld'                => $feld,
            'alt'                 => $alt,
            'neu'                 => $neu,
            'art'                 => $art,
            'akteur_typ'          => $a->typ,
            'akteur_id'           => $a->id,
            'akteur_name'         => $a->name,
            'grund'               => $grund,
        ]);
    }
}
