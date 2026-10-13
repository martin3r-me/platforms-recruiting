<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecDispoJobWave;
use Symfony\Component\Uid\UuidV7;

/**
 * Test-Anfrage „Jobs anbieten“ zum Durchklicken (Demo): eine laufende Anfrage
 * mit drei Schichten, einer bereits „gesendeten“ Welle an die uebergebenen
 * Mitarbeiter und ein paar Zusagen — OHNE WhatsApp. Damit sind Uebersicht,
 * Anfrageseite (Fortschritt, Entscheidungshilfe, Abhaken) und die
 * Mitarbeiter-Seite sofort sichtbar.
 *
 * Erkennungszeichen fuer das Aufraeumen: die Welle traegt summary.test = true;
 * die Test-Veranstaltung den einsatz_ref TEST_REF. Echte Anfragen werden nie
 * angefasst.
 */
final class DispoJobTestdaten
{
    public const TEST_REF = 'TEST-JOBS';

    /**
     * Legt die Test-Veranstaltung an (oder nimmt die vorhandene) — ab
     * uebermorgen, zwei Tage.
     */
    public static function testVeranstaltung(int $filialNr): RecDispoEvent
    {
        $start = now()->addDays(2)->startOfDay();

        return RecDispoEvent::query()->updateOrCreate(
            ['einsatz_ref' => self::TEST_REF],
            [
                'name'       => 'TEST — Jobs anbieten',
                'filial_nr'  => $filialNr,
                'starts_on'  => $start->toDateString(),
                'ends_on'    => $start->copy()->addDay()->toDateString(),
                'venue_text' => 'Teststraße 1, 41061 Mönchengladbach',
            ]
        );
    }

    /**
     * @param list<int> $employeeIds Empfaenger der Welle (Reihenfolge = wer zusagt)
     * @return array{request_id:int, schichten:int, angebote:int, zusagen:int}
     */
    public static function anlegen(int $teamId, RecDispoEvent $event, array $employeeIds, int $zusagen = 3): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            throw new \InvalidArgumentException('Mindestens ein Mitarbeiter noetig.');
        }
        $tag1 = ($event->starts_on ?? now()->addDays(2))->toDateString();
        $tag2 = ($event->ends_on ?? $event->starts_on ?? now()->addDays(3))->toDateString();

        return DB::transaction(function () use ($teamId, $event, $employeeIds, $zusagen, $tag1, $tag2) {
            $request = RecDispoJobRequest::create([
                'uuid'                 => (string) UuidV7::generate(),
                'team_id'              => $teamId,
                'filial_nr'            => (int) ($event->filial_nr ?? 0),
                'zeitraum_von'         => $tag1,
                'zeitraum_bis'         => $tag2,
                'modus'                => RecDispoJobRequest::MODUS_SAMMELN,
                'anlass'               => 'dringend',
                'whatsapp_template_id' => null,
                'status'               => RecDispoJobRequest::STATUS_LAEUFT,
            ]);

            $schichten = [
                ['datum' => $tag1, 'von' => '16:00', 'bis' => '23:00', 'taetigkeit' => 'Servicekraft', 'gesucht' => 3],
                ['datum' => $tag1, 'von' => '18:00', 'bis' => '02:00', 'taetigkeit' => 'Logistik', 'gesucht' => 1],
                ['datum' => $tag2, 'von' => '10:00', 'bis' => '18:00', 'taetigkeit' => 'Servicekraft', 'gesucht' => 2],
            ];
            $shifts = [];
            foreach ($schichten as $s) {
                $shifts[] = $request->shifts()->create($s + ['rec_dispo_event_id' => (int) $event->id]);
            }

            $wave = $request->waves()->create([
                'nr'                      => 1,
                'ma_bloecke'              => [(int) ($event->filial_nr ?? 0)],
                'gruppen'                 => ['alle'],
                'nur_passende_taetigkeit' => false,
                'started_at'              => now()->subMinutes(30),
                'finished_at'             => now()->subMinutes(29),
                'job_uuid'                => (string) UuidV7::generate(),
                'summary'                 => ['test' => true, 'total' => count($employeeIds), 'sent' => count($employeeIds), 'failed' => 0, 'done' => true, 'errors' => []],
            ]);

            $offerShiftIds = [];
            foreach ($employeeIds as $i => $id) {
                $offer = $wave->offers()->create([
                    'request_id'         => $request->id,
                    'rec_employee_id'    => $id,
                    'anchor_employee_id' => $id,
                    'sent_at'            => now()->subMinutes(29),
                ]);
                foreach ($shifts as $j => $shift) {
                    $offerShiftIds[$i][$j] = (int) $offer->shifts()->create(['shift_id' => $shift->id])->id;
                }
            }

            // Zusagen nach festem Muster, damit jede Ansicht etwas zeigt: Person 1
            // nimmt Schicht 1+3, Person 2 Schicht 1 (schon in ZAS eingetragen),
            // Person 3 die Nachtschicht, weitere Personen Schicht 1 (Ueberfuellung
            // erst ab Person 4).
            $muster = [[0, 2], [0], [1], [0]];
            $gesetzt = 0;
            foreach ($employeeIds as $i => $id) {
                if ($gesetzt >= $zusagen) {
                    break;
                }
                foreach ($muster[min($i, count($muster) - 1)] as $j) {
                    $daten = ['accepted_at' => now()->subMinutes(20 - $i)];
                    if ($i === 1) {
                        $daten['zas_entered_at'] = now()->subMinutes(5);
                    }
                    RecDispoJobOfferShift::query()->whereKey($offerShiftIds[$i][$j])->update($daten);
                }
                $gesetzt++;
            }

            return ['request_id' => (int) $request->id, 'schichten' => count($shifts), 'angebote' => count($employeeIds), 'zusagen' => $gesetzt];
        });
    }

    /**
     * Entfernt alle Test-Anfragen (Welle mit summary.test) samt Schichten,
     * Angeboten, Zusagen und die Test-Veranstaltung, sofern an ihr keine
     * Einbuchungen haengen.
     *
     * @return array{anfragen:int, veranstaltung:bool}
     */
    public static function aufraeumen(): array
    {
        $requestIds = RecDispoJobWave::query()->whereNotNull('summary')->get(['request_id', 'summary'])
            ->filter(fn ($w) => (bool) (((array) $w->summary)['test'] ?? false))
            ->pluck('request_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        DB::transaction(function () use ($requestIds) {
            if ($requestIds === []) {
                return;
            }
            $offerIds = RecDispoJobOffer::query()->whereIn('request_id', $requestIds)->pluck('id')->all();
            RecDispoJobOfferShift::query()->whereIn('offer_id', $offerIds)->delete();
            RecDispoJobOffer::query()->whereIn('request_id', $requestIds)->delete();
            RecDispoJobWave::query()->whereIn('request_id', $requestIds)->delete();
            RecDispoJobShift::query()->whereIn('request_id', $requestIds)->delete();
            RecDispoJobRequest::query()->whereIn('id', $requestIds)->delete();
        });

        $event = RecDispoEvent::query()->where('einsatz_ref', self::TEST_REF)->first();
        $eventWeg = false;
        if ($event !== null && !$event->assignments()->exists()
            && !RecDispoJobShift::query()->where('rec_dispo_event_id', $event->id)->exists()) {
            $event->delete();
            $eventWeg = true;
        }

        return ['anfragen' => count($requestIds), 'veranstaltung' => $eventWeg];
    }
}
