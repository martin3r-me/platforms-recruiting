<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobGedeckt;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasAbgleich;

/**
 * Einmalige Bereinigung (Markus 06.11., Anfrage 16): bis zum Fix galten zwei
 * Schichten mit gleichem Beginn und anderem Ende als eine, jede Zusage landete
 * auf beiden. Hier bleibt je Person und Tag EINE Zusage stehen:
 *  1. die Zeile, die schon in ZAS ist (uebergeben, Haken oder angekommen),
 *  2. sonst die Zeile auf der Schicht --behalten (die Schicht, die im Link stand),
 *  3. gibt es keine davon: Abbruch mit Hinweis — nichts raten.
 * Die anderen Zeilen verlieren ihre Zusage (accepted_at = null), bleiben als
 * Angebot bestehen. Ohne --ausfuehren nur Vorschau.
 */
class DispoZusagenEntdoppelnCommand extends Command
{
    protected $signature = 'recruiting:dispo-zusagen-entdoppeln {requestId} {--behalten=} {--ausfuehren}';
    protected $description = 'Doppelte Zusagen einer Anfrage (gleicher Beginn, anderes Ende) auf eine Schicht zurueckfuehren';

    public function handle(): int
    {
        $request = RecDispoJobRequest::query()->find((int) $this->argument('requestId'));
        if ($request === null) {
            $this->error('Anfrage nicht gefunden.');
            return self::FAILURE;
        }
        $behalten = $this->option('behalten') !== null ? (int) $this->option('behalten') : null;
        if ($behalten !== null && !$request->shifts()->whereKey($behalten)->exists()) {
            $this->error('--behalten ist keine Schicht dieser Anfrage.');
            return self::FAILURE;
        }
        $ausfuehren = (bool) $this->option('ausfuehren');

        $zeilen = RecDispoJobOfferShift::query()
            ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
            ->whereNotNull('accepted_at')->whereNull('declined_at')
            ->with(['offer.employee:id,first_name,last_name', 'shift'])
            ->get();

        // Gruppe = Person + VA + Tag + Beginn + Taetigkeit (der alte Schluessel ohne Ende).
        $gruppen = [];
        foreach ($zeilen as $z) {
            if ($z->shift === null || $z->offer === null) {
                continue;
            }
            $k = $z->offer_id . '|' . $z->shift->rec_dispo_event_id . '|' . $z->shift->datum->format('Y-m-d') . '|' . trim((string) $z->shift->von) . '|' . mb_strtolower(trim((string) $z->shift->taetigkeit));
            $gruppen[$k][] = $z;
        }

        $entfernen = [];
        /** @var array<int, int> offer_shift_id => zas_assignment_id, das mit der Zusage geloest wird */
        $loesen = [];
        $offen = 0;
        foreach ($gruppen as $g) {
            if (count($g) < 2) {
                continue;
            }
            $inZas = array_values(array_filter($g, fn ($z) => $z->zas_assignment_id !== null || $z->zas_exported_at !== null || $z->zas_entered_at !== null));
            // Prod 06.11.: beide Zeilen zeigten auf DIESELBE ZAS-Buchung (alter Abgleich nur nach
            // Tag). Dann bleibt nur die Zeile, deren Schicht zur Buchung passt; die andere verliert
            // Zusage UND Verknuepfung.
            $inZas = $this->gleicheBuchungAufloesen($inZas, $loesen);
            $bleibt = $inZas !== [] ? $inZas : array_values(array_filter($g, fn ($z) => $behalten !== null && (int) $z->shift_id === $behalten));
            $name = trim(($g[0]->offer->employee?->last_name ?? '') . ', ' . ($g[0]->offer->employee?->first_name ?? ''));
            if ($bleibt === []) {
                $offen++;
                $this->warn(sprintf('%s: %s — keine Zeile in ZAS und keine auf --behalten, bleibt unveraendert', $name,
                    implode(' / ', array_map(fn ($z) => self::zeit($z), $g))));
                continue;
            }
            $bleibtIds = array_map(fn ($z) => (int) $z->id, $bleibt);
            foreach ($g as $z) {
                if (!in_array((int) $z->id, $bleibtIds, true)) {
                    $entfernen[] = $z;
                    $this->line(sprintf('%s: Zusage auf %s entfernen, bleibt auf %s%s', $name, self::zeit($z),
                        implode(' + ', array_map(fn ($b) => self::zeit($b), $bleibt)), $inZas !== [] ? ' (in ZAS)' : ''));
                }
            }
        }

        $this->info(sprintf('%d Zusagen zu entfernen, %d Personen ohne Entscheidung.', count($entfernen), $offen));
        if (!$ausfuehren) {
            $this->line('Vorschau — mit --ausfuehren wird geschrieben.');
            return self::SUCCESS;
        }
        $entfernt = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($entfernen, $loesen, $request, &$entfernt) {
            $schichten = [];
            foreach ($entfernen as $z) {
                // Bedingt: hat ZAS die Zeile inzwischen abgeholt oder wurde sie eingetragen, bleibt sie (Review 06.11.).
                $q = RecDispoJobOfferShift::query()->whereKey($z->id)
                    ->whereNotNull('accepted_at')
                    ->whereNull('zas_exported_at')->whereNull('zas_entered_at');
                if (isset($loesen[(int) $z->id])) {
                    // Falsche Verknuepfung (gleiche Buchung wie die Schwester): nur loesen, wenn sie noch dieselbe ist.
                    $entfernt += $q->where('zas_assignment_id', $loesen[(int) $z->id])->update(['accepted_at' => null, 'zas_assignment_id' => null]);
                } else {
                    $entfernt += $q->whereNull('zas_assignment_id')->update(['accepted_at' => null]);
                }
                $schichten[(int) $z->shift_id] = true;
            }
            foreach (array_keys($schichten) as $shiftId) {
                $shift = $request->shifts()->whereKey($shiftId)->first();
                if ($shift !== null) {
                    DispoJobGedeckt::nachzaehlen($request, $shift);
                }
            }
        }, 3);
        $this->info(sprintf('%d Zusagen entfernt.', $entfernt));

        return self::SUCCESS;
    }

    /**
     * Zeigen mehrere Zeilen derselben Gruppe auf dieselbe ZAS-Buchung, bleibt nur die Zeile,
     * deren Schicht (inkl. Zeitvarianten des Postens) zur Buchung passt; die anderen kommen
     * in $loesen (Zusage und Verknuepfung weg). Passt keine oder alle, bleibt alles.
     *
     * @param list<RecDispoJobOfferShift> $inZas
     * @param array<int,int> $loesen offer_shift_id => zas_assignment_id
     * @return list<RecDispoJobOfferShift>
     */
    private function gleicheBuchungAufloesen(array $inZas, array &$loesen): array
    {
        $jeBuchung = [];
        foreach ($inZas as $z) {
            if ($z->zas_assignment_id !== null) {
                $jeBuchung[(int) $z->zas_assignment_id][] = $z;
            }
        }
        $raus = [];
        foreach ($jeBuchung as $assignmentId => $zeilen) {
            if (count($zeilen) < 2) {
                continue;
            }
            $buchung = RecDispoAssignment::query()->find($assignmentId);
            if ($buchung === null) {
                continue;
            }
            $varianten = DispoJobZasAbgleich::zeitvarianten(array_map(fn ($z) => $z->shift, $zeilen));
            $passend = array_values(array_filter($zeilen, fn ($z) => DispoJobZasAbgleich::zeitPasstEine($buchung, $varianten[(int) $z->shift_id] ?? [[$z->shift->von, $z->shift->bis]])));
            if ($passend === [] || count($passend) === count($zeilen)) {
                continue;
            }
            $passendIds = array_map(fn ($z) => (int) $z->id, $passend);
            foreach ($zeilen as $z) {
                if (!in_array((int) $z->id, $passendIds, true)) {
                    $loesen[(int) $z->id] = (int) $assignmentId;
                    $raus[(int) $z->id] = true;
                }
            }
        }

        return array_values(array_filter($inZas, fn ($z) => !isset($raus[(int) $z->id])));
    }

    private static function zeit(RecDispoJobOfferShift $z): string
    {
        return '#' . $z->shift_id . ' ' . \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($z->shift->von, $z->shift->bis);
    }
}
