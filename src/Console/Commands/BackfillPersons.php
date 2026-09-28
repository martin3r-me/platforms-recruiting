<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\PersonLinker;
use Platform\Recruiting\Support\PersonGroupPlanner;
use Throwable;

/**
 * Gibt jedem Menschen im Bestand seine Personen-Zeile (Spec 2026-09-28,
 * Paragraph 4.3, "Backfill"). Laeuft einmal ueber rund 1300 Mitarbeiter auf
 * der Produktion und danach bei Bedarf erneut, ohne etwas doppelt anzulegen.
 *
 * Betrachtet ALLE Mitarbeiter, auch inaktive -- sonst bekommt ein
 * Rueckkehrer bei seiner naechsten Anstellung eine zweite Person statt
 * seiner alten.
 *
 * Wiederholbar (Ruling T4-A, Fixrunde 1): "wiederholbar" heisst nicht nur,
 * dass ein zweiter identischer Lauf nichts aendert, sondern auch, dass ein
 * SPAETERER Lauf mit einem neu dazugekommenen Geschwister (gleicher
 * person_key, z. B. RG+MA-Paarung nach einem vorherigen Backfill) dieses
 * Geschwister an die ALTE Person haengt, nicht an eine zweite. Reines
 * whereNull('rec_person_id') reicht dafuer NICHT: das neue Geschwister ist
 * ungebunden, aber das schon gebundene Gegenstueck faellt dann aus der
 * Abfrage, und PersonLinker::verbinde()s eigene "vorhandene Zeile
 * wiederverwenden"-Pruefung sieht die gebundene Kennung gar nicht -- der
 * Rueckkehrer bekaeme lautlos eine zweite Person. Deshalb liest
 * mitarbeiterFuerLauf() zusaetzlich alle (auch schon gebundenen)
 * Geschwister eines noch offenen person_key mit. Eine Gruppe, in der
 * bereits ALLE Mitglieder gebunden sind, taucht dadurch gar nicht erst in
 * der Abfrage auf -- sonst arbeitete jeder Lauf den ganzen Bestand erneut
 * durch.
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
 * Projekt schon mehrfach beseitigt hat. Wirft verbinde() (etwa weil zwei
 * schon gebundene Geschwister an VERSCHIEDENEN Personen haengen -- ein
 * echter Konflikt, den nur ein Mensch aufloesen kann), wird sofort
 * zurueckgerollt und die Ausnahme durchgereicht (kein try/finally-Ersatz,
 * der sie verschluckt).
 *
 * Erkennungsrezept fuer "Nummer nicht gesetzt (Dublette oder unlesbar)"
 * (aus dem Docblock von PersonLinker::verbinde()): NIE Werte vergleichen --
 * verbinde() normalisiert die Nummer selbst, der gespeicherte Wert ist also
 * nicht zeichengleich mit dem uebergebenen. Der einzige verlaessliche Beleg
 * ist: eine Nummer wurde uebergeben, und an der (NEU angelegten) Zeile
 * steht keine. Bei einer wiederverwendeten Zeile (ein Geschwister war schon
 * gebunden) schreibt verbinde() gar keine Nummer -- dort wird deshalb gar
 * nicht erst geprueft, sonst zaehlte der Stand einer fremden, frueheren
 * Zuordnung als Kollision dieses Laufs.
 *
 * M5-Hinweis (Fixrunde 1): der Zaehler heisst bewusst NICHT "... Dublette",
 * denn PhoneE164::normalize() liefert bei einer schlicht unlesbaren
 * Schreibweise ebenfalls null, und PersonLinker behandelt das identisch zu
 * einer Kollision. "Dublette" waere fuer diesen Fall eine falsche Angabe an
 * HR -- gesucht wuerde nach einem Menschen, der die Nummer schon traegt,
 * und den gibt es nicht.
 *
 * I3-Hinweis (Fixrunde 1): ein Trockenlauf legt die Personen-Zeilen WIRKLICH
 * an (siehe oben) und rollt danach nur zurueck. InnoDB rollt den
 * AUTO_INCREMENT-Zaehler von rec_persons bei einem ROLLBACK NICHT zurueck --
 * ein Trockenlauf, der z. B. 900 Personen anlegen wuerde, verbraucht also
 * 900 Kennungen, obwohl strukturell nichts bleibt. Das ist gewollt und kein
 * Fehler: der fachliche Schluessel ist die uuid, Luecken in den
 * Auto-Increment-Kennungen schaden nichts. Nur damit sich in einem Jahr
 * niemand ueber Luecken erschrickt und nach einem Datenverlust sucht.
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

        $mitarbeiter = $this->mitarbeiterFuerLauf();
        $plan = PersonGroupPlanner::plan($mitarbeiter);

        $teamJeMitarbeiter = [];
        $warGebundenJeMitarbeiter = [];
        foreach ($mitarbeiter as $m) {
            $teamJeMitarbeiter[$m['id']] = $m['team_id'];
            $warGebundenJeMitarbeiter[$m['id']] = $m['war_gebunden'];
        }

        $vorherPersonen = (int) DB::table('rec_persons')->count();
        $nummernUneinig = 0;
        $kollisionen = 0;
        $neuVerbunden = 0;

        // Trockenlauf und echter Lauf nehmen denselben Weg durch den
        // einzigen Schreiber -- siehe Klassenkommentar. Bei einem Fehler
        // wird SOFORT zurueckgerollt und die Ausnahme durchgereicht (I2,
        // Fixrunde 1): ohne das explizite catch bliebe die Transaktion nach
        // einem Fehler offen, weil das commit/rollback danach nie erreicht
        // wird.
        DB::beginTransaction();

        try {
            foreach ($plan['gruppen'] as $gruppe) {
                if ($gruppe['phone_uneinig']) {
                    $nummernUneinig++;
                }

                $ids = $gruppe['ids']; // bereits aufsteigend sortiert (PersonGroupPlanner::plan())
                $teamId = $teamJeMitarbeiter[$ids[0]] ?? null;

                // War VOR diesem Lauf schon irgendjemand in der Gruppe
                // gebunden? Dann reicht verbinde() die vorhandene Zeile
                // durch und schreibt KEINE neue Nummer -- die
                // Kollisionspruefung unten gilt nur fuer neu angelegte
                // Zeilen.
                $warSchonGebunden = false;
                foreach ($ids as $id) {
                    if ($warGebundenJeMitarbeiter[$id] ?? false) {
                        $warSchonGebunden = true;
                        break;
                    }
                }

                $personId = PersonLinker::verbinde($ids, $teamId, $gruppe['phone']);

                foreach ($ids as $id) {
                    if (!($warGebundenJeMitarbeiter[$id] ?? false)) {
                        $neuVerbunden++;
                    }
                }

                if (!$warSchonGebunden
                    && $gruppe['phone'] !== null
                    && DB::table('rec_persons')->where('id', $personId)->value('phone') === null) {
                    $kollisionen++;
                }
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $nachherPersonen = (int) DB::table('rec_persons')->count();

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        $this->schreibe('Personen angelegt: ' . ($nachherPersonen - $vorherPersonen));
        $this->schreibe('Anstellungen verbunden: ' . $neuVerbunden);
        $this->schreibe('Nummern uneinig: ' . $nummernUneinig);
        $this->schreibe('Nummer nicht gesetzt (Dublette oder unlesbar): ' . $kollisionen);

        return self::SUCCESS;
    }

    /**
     * Alle Datensaetze, die dieser Lauf betrachten muss: jeder ungebundene
     * Mitarbeiter PLUS jedes -- auch schon gebundene -- Geschwister mit
     * demselben person_key (Ruling T4-A, siehe Klassenkommentar).
     *
     * @return list<array{id:int, team_id:?int, person_key:?string, phone:?string, updated_at:?string, war_gebunden:bool}>
     */
    private function mitarbeiterFuerLauf(): array
    {
        $teamOption = $this->option('team');
        $basis = fn () => DB::table('rec_employees')
            ->when($teamOption !== null, fn ($q) => $q->where('team_id', (int) $teamOption));

        $offeneMarker = $basis()
            ->whereNull('rec_person_id')
            ->whereNotNull('person_key')
            ->where('person_key', '!=', '')
            ->distinct()
            ->pluck('person_key')
            ->all();

        return $basis()
            ->where(function ($q) use ($offeneMarker) {
                $q->whereNull('rec_person_id');
                if ($offeneMarker !== []) {
                    $q->orWhereIn('person_key', $offeneMarker);
                }
            })
            ->orderBy('id')
            ->get(['id', 'team_id', 'person_key', 'phone', 'updated_at', 'rec_person_id'])
            ->map(fn ($row) => [
                'id'           => (int) $row->id,
                'team_id'      => $row->team_id !== null ? (int) $row->team_id : null,
                'person_key'   => $row->person_key,
                'phone'        => $row->phone,
                'updated_at'   => $row->updated_at,
                'war_gebunden' => $row->rec_person_id !== null,
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
