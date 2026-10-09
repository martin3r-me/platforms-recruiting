<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * MA-Vertragscheck je Mensch (Spec Vertrag aus der Akte §3.2). Liest nur.
 *
 * WELCHE GESELLSCHAFT: der Praefix der Personalnummer AN DER BUCHUNG
 * (`MA878` → MA) — ZAS entscheidet, wann ein Job MA erfordert, wir sehen nur
 * das Ergebnis. Gekuerzte Nummern tragen denselben Praefix. Nur ohne Praefix
 * zaehlt die Firma der gebuchten Anstellung.
 *
 * WELCHE ANSTELLUNG: die des Personen-Umfangs mit dieser Gesellschaft — nicht
 * zwingend die, an der die Buchung haengt (Doppelbeschaeftigte RG+MA,
 * Review-Focus 3). Gibt es keine, die gebuchte.
 *
 * WELCHE VERTRAEGE: AV dieser Anstellung, deren VORLAGE zur Gesellschaft
 * gehoert (VertragsZeilen mit Firma).
 *
 * Je Anstellung EIN Befund: keiner, sobald eine Buchung ungedeckt ist
 * (erster_tag = frueheste, $kommende kommt nach Datum sortiert); sonst
 * unterwegs, sonst unterschrieben.
 */
final class VertragsPruefung
{
    /**
     * Geprueft werden nur Buchungen bis heute + HORIZONT_TAGE (Schlussreview
     * I2): Einsaetze in Monaten erzeugen jetzt noch keinen Fall. Die EINE
     * Stelle fuer diese Zahl.
     */
    public const HORIZONT_TAGE = 30;

    /** Letzter gepruefter Tag (Y-m-d) fuer den Stichtag $heute. */
    public static function horizontBis(string $heute): string
    {
        return (new \DateTimeImmutable($heute, new \DateTimeZone('UTC')))
            ->modify('+' . self::HORIZONT_TAGE . ' days')->format('Y-m-d');
    }

    /** @var array<string, list<array>> */
    private array $zeilen = [];

    /**
     * @param  list<int>  $umfangIds
     * @param  iterable<\Platform\Recruiting\Models\RecDispoAssignment>  $kommende  frueheste zuerst
     * @param  list<string>  $firmen  Grossbuchstaben
     * @param  ?string  $bis  letzter gepruefter Tag (horizontBis()); null = alle
     * @return list<array{anstellung_id:int, team_id:int, firma:string, deckung:string, vertrag_id:?int, buchungen:int, ohne:int, erster_tag:?string, event:?string, taetigkeit:?string}>
     */
    public function pruefe(array $umfangIds, iterable $kommende, array $firmen, ?string $bis = null): array
    {
        if ($firmen === []) {
            return [];
        }

        /** @var Collection<int, RecEmployee>|null $anstellungen */
        $anstellungen = null;
        $befunde = [];

        foreach ($kommende as $buchung) {
            $tag = $buchung->datum?->format('Y-m-d') ?? '';
            if ($tag === '' || ($bis !== null && $tag > $bis)) {
                continue;
            }

            $firma = ZasPersonnelNumber::prefixOf((string) $buchung->pnr_raw);
            if ($firma === null) {
                $anstellungen ??= $this->anstellungen($umfangIds);
                $firma = self::firmaVon($anstellungen->firstWhere('id', (int) $buchung->rec_employee_id));
            }
            if ($firma === null || !in_array($firma, $firmen, true)) {
                continue;
            }

            $anstellungen ??= $this->anstellungen($umfangIds);
            $ziel = $anstellungen->first(fn (RecEmployee $a) => self::firmaVon($a) === $firma)
                ?? $anstellungen->firstWhere('id', (int) $buchung->rec_employee_id);
            if ($ziel === null) {
                continue;
            }

            $schluessel = $ziel->id . '|' . $firma;
            $this->zeilen[$schluessel] ??= VertragsZeilen::fuerAnstellung((int) $ziel->id, $firma);
            $ergebnis = VertragsDeckung::amTag($this->zeilen[$schluessel], $tag);

            $b = $befunde[$schluessel] ?? [
                'anstellung_id' => (int) $ziel->id,
                'team_id'       => (int) $ziel->team_id,
                'firma'         => $firma,
                'deckung'       => VertragsDeckung::UNTERSCHRIEBEN,
                'vertrag_id'    => null,
                'buchungen'     => 0,
                'ohne'          => 0,
                'erster_tag'    => null,
                'event'         => null,
                'taetigkeit'    => null,
            ];
            $b['buchungen']++;

            if ($ergebnis['deckung'] === VertragsDeckung::KEINER) {
                $b['ohne']++;
                $b['deckung'] = VertragsDeckung::KEINER;
                if ($b['erster_tag'] === null) {
                    $b['erster_tag'] = $tag;
                    $b['event'] = $buchung->event?->name;
                    $b['taetigkeit'] = $buchung->taetigkeit;
                }
            } elseif ($ergebnis['deckung'] === VertragsDeckung::UNTERWEGS) {
                if ($b['deckung'] !== VertragsDeckung::KEINER) {
                    $b['deckung'] = VertragsDeckung::UNTERWEGS;
                }
            } else {
                $b['vertrag_id'] = $ergebnis['vertrag_id'];
            }

            $befunde[$schluessel] = $b;
        }

        return array_values($befunde);
    }

    /** @param list<int> $umfangIds */
    private function anstellungen(array $umfangIds): Collection
    {
        return RecEmployee::query()->whereIn('id', $umfangIds)->orderBy('id')->get(['id', 'team_id', 'company']);
    }

    private static function firmaVon(?RecEmployee $a): ?string
    {
        $f = strtoupper(trim((string) $a?->company));

        return $f === '' ? null : $f;
    }
}
