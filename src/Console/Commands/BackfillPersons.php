<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\PersonLinker;
use Platform\Recruiting\Support\PersonGroupPlanner;

/**
 * Gibt jedem Menschen im Bestand seine Personen-Zeile (Spec 2026-09-28,
 * Paragraph 4.3, "Backfill"). Laeuft einmal ueber rund 1300 Mitarbeiter auf
 * der Produktion und danach bei Bedarf erneut, ohne etwas doppelt anzulegen.
 *
 * Betrachtet ALLE Mitarbeiter, auch inaktive -- sonst bekommt ein
 * Rueckkehrer bei seiner naechsten Anstellung eine zweite Person statt
 * seiner alten.
 *
 * Wiederholbar: Datensaetze mit gesetzter rec_person_id werden erst gar
 * nicht gelesen (whereNull). Ein zweiter Lauf sieht also nur noch das, was
 * seit dem letzten Mal neu dazugekommen ist, und legt nichts erneut an.
 *
 * EIN SCHREIBER: dieses Kommando schreibt rec_person_id nicht selbst. Jede
 * Gruppe aus PersonGroupPlanner::plan() geht unveraendert an
 * PersonLinker::verbinde() -- die Kollisionsregel aus Ruling T1-A
 * (rec_persons traegt unique(team_id, phone); eine im Team schon vergebene
 * Nummer wird nie ein zweites Mal geschrieben) sitzt dort und wird hier
 * NICHT nachgebaut. Fuer den Trockenlauf heisst das: der echte Schreiber
 * laeuft mit, die Transaktion wird am Ende nur zurueckgerollt statt
 * bestaetigt -- eine zweite, selbst gebaute Kollisionspruefung wuerde die
 * Regel an zwei Stellen leben lassen, genau die Doppelung, die dieses
 * Projekt schon mehrfach beseitigt hat.
 *
 * Erkennungsrezept fuer "Nummer wegen Dublette nicht gesetzt" (aus dem
 * Docblock von PersonLinker::verbinde()): NIE Werte vergleichen -- verbinde()
 * normalisiert die Nummer selbst, der gespeicherte Wert ist also nicht
 * zeichengleich mit dem uebergebenen. Der einzige verlaessliche Beleg ist:
 * eine Nummer wurde uebergeben, und an der Zeile steht keine.
 */
final class BackfillPersons extends Command
{
    protected $signature = 'recruiting:personen-anlegen
        {--team= : Nur Mitarbeiter dieses Teams}
        {--dry-run : Nur zaehlen, nichts schreiben}';

    protected $description = 'Jedem Mitarbeiter seine Personen-Zeile geben (Backfill, wiederholbar)';

    /**
     * Die vier Zusammenfassungszeilen, zusaetzlich zur Konsolenausgabe auch
     * hier gesammelt, damit Testcode sie ohne eigenen BufferedOutput lesen
     * kann (siehe BackfillPersonsTest::lauf()).
     *
     * @var list<string>
     */
    private array $ausgabeZeilen = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $mitarbeiter = $this->ungebundeneMitarbeiter();
        $plan = PersonGroupPlanner::plan($mitarbeiter);

        $teamJeMitarbeiter = [];
        foreach ($mitarbeiter as $m) {
            $teamJeMitarbeiter[$m['id']] = $m['team_id'];
        }

        $vorherPersonen = (int) DB::table('rec_persons')->count();
        $nummernUneinig = 0;
        $kollisionen = 0;

        // Trockenlauf und echter Lauf nehmen denselben Weg durch den
        // einzigen Schreiber -- siehe Klassenkommentar.
        DB::beginTransaction();

        foreach ($plan['gruppen'] as $gruppe) {
            if ($gruppe['phone_uneinig']) {
                $nummernUneinig++;
            }

            // gruppe['ids'] ist bereits aufsteigend sortiert
            // (PersonGroupPlanner::plan()) -- die kleinste Kennung bestimmt
            // das Team, falls eine Gruppe (Datenfehler) mehrere traegt.
            $teamId = $teamJeMitarbeiter[$gruppe['ids'][0]] ?? null;

            $personId = PersonLinker::verbinde($gruppe['ids'], $teamId, $gruppe['phone']);

            if ($gruppe['phone'] !== null
                && DB::table('rec_persons')->where('id', $personId)->value('phone') === null) {
                $kollisionen++;
            }
        }

        $nachherPersonen = (int) DB::table('rec_persons')->count();

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->schreibe('Personen angelegt: ' . ($nachherPersonen - $vorherPersonen));
        $this->schreibe('Anstellungen verbunden: ' . count($mitarbeiter));
        $this->schreibe('Nummern uneinig: ' . $nummernUneinig);
        $this->schreibe('Nummer wegen Dublette nicht gesetzt: ' . $kollisionen);

        return self::SUCCESS;
    }

    /**
     * Alle Mitarbeiter ohne Personen-Zeile, in der Form, die
     * PersonGroupPlanner::plan() verlangt. Ein is_active-Filter fehlt hier
     * bewusst -- inaktive Mitarbeiter brauchen ihre Person genauso, sonst
     * bekommt ein Rueckkehrer bei der naechsten Anstellung eine zweite.
     *
     * @return list<array{id:int, team_id:?int, person_key:?string, phone:?string, updated_at:?string}>
     */
    private function ungebundeneMitarbeiter(): array
    {
        return DB::table('rec_employees')
            ->whereNull('rec_person_id')
            ->when($this->option('team') !== null, fn ($q) => $q->where('team_id', (int) $this->option('team')))
            ->orderBy('id')
            ->get(['id', 'team_id', 'person_key', 'phone', 'updated_at'])
            ->map(fn ($row) => [
                'id'         => (int) $row->id,
                'team_id'    => $row->team_id !== null ? (int) $row->team_id : null,
                'person_key' => $row->person_key,
                'phone'      => $row->phone,
                'updated_at' => $row->updated_at,
            ])
            ->all();
    }

    private function schreibe(string $zeile): void
    {
        $this->line($zeile);
        $this->ausgabeZeilen[] = $zeile;
    }

    /** Testzugriff (siehe BackfillPersonsTest::lauf()) -- die vier Zusammenfassungszeilen als Text. */
    public function ausgabe(): string
    {
        return implode("\n", $this->ausgabeZeilen);
    }
}
