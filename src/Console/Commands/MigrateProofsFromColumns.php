<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Support\ProofMigrationPlanner;
use Platform\Recruiting\Support\ProofTypes;
use Symfony\Component\Uid\UuidV7;

/**
 * Zieht die Nachweise aus den 16 Dateispalten in rec_employee_proofs.
 *
 * Laeuft einmal vor der Freischaltung — und danach gefahrlos beliebig oft:
 * Was je Person und Art schon einen Nachweis hat, wird uebergangen. Die
 * Altspalten bleiben unangetastet, sie bedienen weiter den ZAS-Export.
 *
 * Geschrieben wird ueber den Query-Builder, nicht ueber Eloquent — derselbe
 * Grund wie beim ProofWriter: kein Anfassen von rec_employees, kein
 * Export-Marker. Hier wird ohnehin nur in die neue Tabelle geschrieben.
 */
final class MigrateProofsFromColumns extends Command
{
    protected $signature = 'recruiting:nachweise-umziehen
        {--team= : Nur Mitarbeiter dieses Teams}
        {--dry-run : Nur zaehlen, nichts schreiben}';

    protected $description = 'Nachweise aus den alten Dateispalten in rec_employee_proofs uebernehmen';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $spalten = ['id', 'team_id', 'person_key'];
        foreach (ProofTypes::all() as $code) {
            $spalten = array_merge($spalten, ProofTypes::legacyFileColumns($code));
            if ($ablauf = ProofTypes::legacyExpiryColumn($code)) {
                $spalten[] = $ablauf;
            }
        }
        $spalten = array_values(array_unique($spalten));

        $employees = DB::table('rec_employees')
            ->when($this->option('team') !== null, fn ($q) => $q->where('team_id', (int) $this->option('team')))
            ->orderBy('id')
            ->get($spalten)
            ->map(fn ($r) => (array) $r)
            ->all();

        if ($employees === []) {
            $this->warn('Keine Mitarbeiter gefunden.');
            return self::SUCCESS;
        }

        $teamJeId = [];
        foreach ($employees as $zeile) {
            $teamJeId[(int) $zeile['id']] = $zeile['team_id'] ?? null;
        }

        $plan = ProofMigrationPlanner::plan($employees);
        $vorhanden = $this->vorhandeneNachweise();

        $angelegt = [];
        $uebersprungen = 0;

        foreach ($plan as $eintrag) {
            $person = ProofMigrationPlanner::personSchluessel(
                $eintrag['person_key'],
                $eintrag['rec_employee_id'],
            );

            if (isset($vorhanden[$person . '|' . $eintrag['proof_type_code']])) {
                $uebersprungen++;
                continue;
            }

            if (!$dryRun) {
                DB::table('rec_employee_proofs')->insert([
                    'uuid'            => (string) UuidV7::generate(),
                    'team_id'         => $teamJeId[$eintrag['rec_employee_id']] ?? null,
                    'rec_employee_id' => $eintrag['rec_employee_id'],
                    'person_key'      => $eintrag['person_key'],
                    'proof_type_code' => $eintrag['proof_type_code'],
                    'file_id'         => $eintrag['file_id'],
                    'file_back_id'    => $eintrag['file_back_id'],
                    'valid_until'     => $eintrag['valid_until'],
                    'version'         => 1,
                    'uploaded_via'    => 'import',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            $angelegt[$eintrag['proof_type_code']] = ($angelegt[$eintrag['proof_type_code']] ?? 0) + 1;
        }

        ksort($angelegt);
        $zeilen = [];
        foreach ($angelegt as $code => $anzahl) {
            $zeilen[] = [$code, ProofTypes::label($code), $anzahl];
        }

        $this->info(sprintf(
            '%d Mitarbeiter geprueft, %d Nachweise %s, %d uebersprungen (schon vorhanden).%s',
            count($employees),
            array_sum($angelegt),
            $dryRun ? 'wuerden angelegt' : 'angelegt',
            $uebersprungen,
            $dryRun ? ' — Trockenlauf, nichts geschrieben.' : '',
        ));

        if ($zeilen !== []) {
            $this->table(['Art', 'Bezeichnung', 'Anzahl'], $zeilen);
        }

        return self::SUCCESS;
    }

    /** @return array<string,true> Schluessel "person|art" der bereits vorhandenen Nachweise. */
    private function vorhandeneNachweise(): array
    {
        $out = [];
        DB::table('rec_employee_proofs')
            ->select(['rec_employee_id', 'person_key', 'proof_type_code'])
            ->orderBy('id')
            ->chunk(1000, function ($zeilen) use (&$out) {
                foreach ($zeilen as $z) {
                    $person = ProofMigrationPlanner::personSchluessel(
                        $z->person_key,
                        (int) $z->rec_employee_id,
                    );
                    $out[$person . '|' . $z->proof_type_code] = true;
                }
            });

        return $out;
    }
}
