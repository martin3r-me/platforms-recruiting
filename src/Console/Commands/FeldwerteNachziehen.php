<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Zieht Feldwerte nach, die beim Stellen-/Phasenabgleich unter den Feldern
 * einer FRUEHEREN Stelle liegen geblieben sind — fuer Bewerbungen, deren Phase
 * schon zur aktuellen Stelle gehoert.
 *
 * Anlass: Lauf von recruiting:reconcile-applicant-positions am 05.10.2026 bei
 * 1114/1130. Die Phase wurde umgesetzt, die Feldwerte brachen beim ersten
 * doppelten Feldnamen ab (Unique-Index). Danach sah die Bewerbung "sauber"
 * aus, und kein Abgleich haette die restlichen Werte noch angefasst.
 *
 * Trockenlauf = echter Lauf in einer Transaktion, die zurueckgerollt wird. So
 * zeigt er exakt, was passieren wuerde, ohne die Logik doppelt zu fuehren.
 *
 * Aufruf:
 *   php artisan recruiting:feldwerte-nachziehen 1114 1130 --dry-run
 *   php artisan recruiting:feldwerte-nachziehen 1114 1130
 */
class FeldwerteNachziehen extends Command
{
    protected $signature = 'recruiting:feldwerte-nachziehen
        {applicant* : Bewerber-IDs}
        {--dry-run : Nur anzeigen, nichts schreiben}';

    protected $description = 'Haengt liegengebliebene Feldwerte einer frueheren Stelle an die gleichnamigen Felder der aktuellen Stelle';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('TROCKENLAUF — es wird nichts geschrieben.');
        }

        $ergebnis = self::nachziehen(
            array_map('intval', (array) $this->argument('applicant')),
            $dryRun,
            fn (string $zeile) => $this->line($zeile),
        );

        return $ergebnis['fehler'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<int> $ids
     * @return array{verschoben:int, geloescht:int, fehler:int}
     */
    public static function nachziehen(array $ids, bool $dryRun, callable $emit): array
    {
        $summe = ['verschoben' => 0, 'geloescht' => 0, 'fehler' => 0];

        foreach ($ids as $id) {
            $applicant = RecApplicant::find($id);
            if (!$applicant) {
                $emit(" #{$id}: nicht gefunden");
                $summe['fehler']++;
                continue;
            }

            $stelle = $applicant->primaryPosition();
            $vorher = self::werte($applicant);
            $connection = $applicant->getConnection();
            $connection->beginTransaction();

            try {
                $applicant->feldwerteAnAktuelleStelleHaengen();
                $nachher = self::werte($applicant);
                $dryRun ? $connection->rollBack() : $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                $emit(" #{$id}: Fehler — {$e->getMessage()}");
                $summe['fehler']++;
                continue;
            }

            $verschoben = [];
            $geloescht = [];
            foreach ($vorher as $wertId => [$defId, $name]) {
                if (!isset($nachher[$wertId])) {
                    $geloescht[] = $name;
                } elseif ($nachher[$wertId][0] !== $defId) {
                    $verschoben[] = $name;
                }
            }

            $summe['verschoben'] += count($verschoben);
            $summe['geloescht'] += count($geloescht);

            $emit(sprintf(
                ' #%d (Stelle "%s"): %d verschoben%s%s',
                $id,
                $stelle?->title ?? '—',
                count($verschoben),
                $verschoben ? ' [' . implode(', ', $verschoben) . ']' : '',
                $geloescht ? ' — ' . count($geloescht) . ' doppelt und verworfen [' . implode(', ', $geloescht) . ']' : '',
            ));
        }

        $emit(sprintf('Verschoben: %d, verworfen: %d, Fehler: %d', $summe['verschoben'], $summe['geloescht'], $summe['fehler']));

        return $summe;
    }

    /** @return array<int, array{0:int, 1:string}> Wert-ID => [Definition-ID, Feldname] */
    private static function werte(RecApplicant $applicant): array
    {
        return $applicant->extraFieldValues()->with('definition:id,name')->get()
            ->mapWithKeys(fn ($v) => [(int) $v->id => [(int) $v->definition_id, (string) ($v->definition?->name ?? '?')]])
            ->all();
    }
}
