<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stellt Mitarbeiter auf das neue Portal um — oder zurueck.
 *
 * Gate 5 aus Canvas 67: erst eine benannte Testgruppe, dann alle. Jede Welle
 * ist eine bewusste Handlung, und im Datensatz steht, wann sie stattfand.
 *
 * Geschrieben wird ueber den Query-Builder: Die Umstellung ist keine fachliche
 * Aenderung am Mitarbeiter und darf ihn nicht in den ZAS-Update-Export spuelen.
 * Dasselbe Muster wie beim ProofWriter und in PersonPairLinker::stamp.
 *
 * Bremse (Nachtrag 25.09.2026, RICHTIGGESTELLT 26.09.2026): das Umstellen
 * (NICHT --zurueck) verlangt eine ausdrueckliche Bestaetigung, sonst bricht
 * der Lauf ab, ohne etwas zu aendern. --zurueck ist die Notbremse und bleibt
 * immer unbestaetigt moeglich.
 *
 * Der urspruengliche Grund ist ueberholt: die Bremse sagte, wer umgestellt
 * werde, verliere "Stammdaten pflegen" und "die Pflichtfrage zum
 * Hauptarbeitgeber". Genau das haben die Aufgaben 4 bis 7 gebaut. Eine
 * Bremse, die einen falschen Grund nennt, wird beim naechsten Lesen
 * weggeraeumt ("das koennen wir doch laengst") -- und mit ihr die Auflagen,
 * die wirklich offen sind. Es sind drei:
 *
 *   1. Der Sichttest auf einem echten Geraet ist nicht gelaufen. Besonders
 *      die Live-Reaktivitaet beim Umstellen der Arbeitgeber-Frage auf "nein"
 *      ist nur als Datenkette geprueft, nie am Geraet gesehen.
 *   2. is_main_employer muss bei Bestandsteams im Lohn-Tracking stehen
 *      (RecApplicantSettings ['employee_payroll_tracked_fields']). Fehlt es
 *      dort, meldet der Wechsel nichts ans Lohnbuero -- und an der Angabe
 *      haengt die Steuerklasse.
 *   3. Der Arbeitgeber-Erklaertext wartet auf Freigabe durch jemanden, der
 *      die Lohnabrechnung verantwortet.
 *
 * Deshalb heisst die Option seit dem 26.09.2026
 * --ich-habe-den-sichttest-gemacht: sie benennt die Auflage, die der Mensch
 * am Geraet abhaken muss, statt einen Mangel wegzuwinken, den es nicht mehr
 * gibt.
 */
final class SwitchPortalVersion extends Command
{
    protected $signature = 'recruiting:portal-umstellen
        {--ids= : Mitarbeiter-Kennungen, komma-getrennt}
        {--alle : Alle aktiven Mitarbeiter}
        {--zurueck : Zurueck auf das alte Portal}
        {--team= : Nur dieses Team (mit --alle)}
        {--ich-habe-den-sichttest-gemacht : Bestaetigt das Umstellen -- drei Auflagen sind offen, siehe Klassenkommentar. --zurueck braucht die Bestaetigung nie.}
        {--dry-run : Nur zeigen, was passieren wuerde}';

    protected $description = 'Mitarbeiter auf das neue Mitarbeiterportal umstellen (Gate 5, Bestaetigung noetig) oder zurueck';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $zurueck = (bool) $this->option('zurueck');
        $alle    = (bool) $this->option('alle');

        // Bremse (Canvas 67 Nachtrag 25.09.2026, Text richtiggestellt
        // 26.09.2026). --zurueck ist die Notbremse und bleibt IMMER ohne
        // Bestaetigung moeglich -- sie darf nie klemmen. Der Lauf wird hier
        // abgebrochen, BEVOR irgendetwas gezaehlt oder geschrieben wird, auch
        // im Trockenlauf: er ist trotzdem "das Umstellen".
        if (!$zurueck && !(bool) $this->option('ich-habe-den-sichttest-gemacht')) {
            $this->error('Umstellen auf das neue Portal verlangt eine ausdrückliche Bestätigung.');
            $this->line('Drei Auflagen sind offen:');
            $this->line('  1. Der Sichttest auf einem echten Gerät ist nicht gelaufen —');
            $this->line('     vor allem die Arbeitgeber-Frage, wenn sie live auf "nein" gestellt wird.');
            $this->line('  2. is_main_employer muss bei Bestandsteams im Lohn-Tracking stehen');
            $this->line('     (employee_payroll_tracked_fields), sonst meldet der Wechsel nichts');
            $this->line('     ans Lohnbüro — und daran hängt die Steuerklasse.');
            $this->line('  3. Der Arbeitgeber-Erklärtext wartet auf Freigabe durch die Lohnabrechnung.');
            $this->line('Stattdessen: denselben Befehl mit --ich-habe-den-sichttest-gemacht');
            $this->line('wiederholen, erst wenn diese drei Punkte erledigt sind.');
            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', (string) $this->option('ids'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));

        if (!$alle && $ids === []) {
            $this->error('Bitte --ids=… oder --alle angeben.');
            return self::FAILURE;
        }
        if ($alle && $ids !== []) {
            $this->error('--alle und --ids schliessen einander aus.');
            return self::FAILURE;
        }

        $query = DB::table('rec_employees')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($alle, fn ($q) => $q->where('is_active', true))
            ->when($this->option('team') !== null, fn ($q) => $q->where('team_id', (int) $this->option('team')));

        // Nur die, bei denen sich wirklich etwas aendert — sonst meldet der
        // Lauf Zahlen, die nichts bewegt haben.
        $betroffen = (clone $query)
            ->when($zurueck, fn ($q) => $q->whereNotNull('portal_v2_since'))
            ->when(!$zurueck, fn ($q) => $q->whereNull('portal_v2_since'));

        $anzahl = (int) (clone $betroffen)->count();

        if ($anzahl === 0) {
            $this->info($zurueck
                ? 'Niemand ist auf dem neuen Portal — nichts zurueckzustellen.'
                : 'Alle Angesprochenen sind bereits umgestellt.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Trockenlauf: {$anzahl} Mitarbeiter wuerden " .
                ($zurueck ? 'auf das ALTE' : 'auf das NEUE') . ' Portal gestellt.');
            $this->line((clone $betroffen)->orderBy('id')->limit(50)->pluck('id')->implode(', '));
            return self::SUCCESS;
        }

        (clone $betroffen)->update(['portal_v2_since' => $zurueck ? null : now()]);

        $this->info("{$anzahl} Mitarbeiter auf das " . ($zurueck ? 'ALTE' : 'NEUE') . ' Portal gestellt.');

        if (!$zurueck) {
            // Token am Ende (Fixrunde 1, Aufgabe 4) — Meta-URL-Buttons
            // erlauben die Variable nur als Suffix, siehe routes/public.php.
            $this->line('Sie erreichen es unter /recruiting/mitarbeiter/neu/{token} — alle anderen sehen dort 404.');
        }

        return self::SUCCESS;
    }
}
