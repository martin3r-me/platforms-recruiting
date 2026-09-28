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
 * TEIL-COMMITS STATT EINER GROSSEN TRANSAKTION (C1, Schlusspruefung):
 * jede Gruppe bekommt ihre eigene Transaktion und wird sofort bestaetigt.
 * Die Alles-oder-nichts-Eigenschaft ist damit BEWUSST aufgegeben, und zwar
 * aus diesem Grund: der Lauf muss neben dem laufenden Betrieb laufen
 * koennen. In EINER Transaktion haelt InnoDB die Zeilensperren von rund
 * 2600 Schreibanweisungen bis zum Commit. Oeffnet in dieser Zeit ein
 * Mitarbeiter seine Einsatzliste, schreibt EmployeeAssignments
 * portal_last_seen_at auf genau diese Zeilen -- Lock Wait, nach 50 s ein
 * 500er im Portal; dasselbe trifft HR-Speichervorgaenge, die Dispo und den
 * ZAS-Import, der selbst transaktional auf dieselben Zeilen schreibt (im
 * schlechten Fall ein Deadlock, der den Backfill abraeumt). Der Gegenwert
 * der grossen Transaktion ist gering, weil dieser Lauf wiederholbar ist:
 * eine schon verbundene Gruppe wird beim naechsten Lauf uebersprungen, ein
 * halber Zustand ist also kein Schaden, sondern der Normalfall eines
 * abgebrochenen Laufs. Wiederholbarkeit ist hier mehr wert als
 * Atomaritaet. Atomar bleibt, was atomar sein MUSS: die einzelne Gruppe
 * (Personen-Zeile anlegen + alle ihre Anstellungen daranhaengen) -- eine
 * halb verbundene Gruppe waere sehr wohl ein Schaden.
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
 * KENNUNGEN WERDEN IMMER MITGEDRUCKT (I3, Schlusspruefung): das Vorbild
 * recruiting:mitarbeiter-grenzfaelle nennt in der Uebersicht nur Zahlen und
 * liefert die Kennungen auf Abruf (--fall=). Das geht hier NICHT: dieses
 * Kommando kann man nicht nachtraeglich fragen. Nach dem Lauf sind genau
 * die gemeldeten Gruppen gebunden und fallen damit aus mitarbeiterFuerLauf()
 * heraus -- eine Abruf-Option druckte also eine leere Liste. "Nummern
 * uneinig: 47" ohne Kennungen ist fuer HR unbrauchbar, und ein zweiter Lauf
 * bringt sie nicht zurueck. Deshalb stehen die Kennungen unter der
 * Uebersicht, beide Sorten getrennt. NAMEN KOMMEN NIE MIT (wie im Vorbild)
 * -- eine Kennungsliste darf keinen Personendatensatz aus der Produktion
 * tragen.
 *
 * I3-Hinweis (Fixrunde 1): ein Trockenlauf legt die Personen-Zeilen WIRKLICH
 * an und rollt sie je Gruppe wieder zurueck. InnoDB rollt den
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
        {--dry-run : Jede Gruppe schreiben und sofort wieder zurueckrollen (hinterlaesst nichts)}';

    protected $description = 'Jedem Mitarbeiter seine Personen-Zeile geben (Backfill, wiederholbar)';

    /**
     * Die Zusammenfassungs- und Kennungszeilen, zusaetzlich zur
     * Konsolenausgabe auch hier gesammelt, damit Testcode sie ohne eigenen
     * BufferedOutput lesen kann (siehe BackfillPersonsTest::lauf()).
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

        $neuePersonen = 0;
        $neuVerbunden = 0;
        /** @var list<list<int>> $nummernUneinig */
        $nummernUneinig = [];
        /** @var list<list<int>> $kollisionen */
        $kollisionen = [];

        foreach ($plan['gruppen'] as $gruppe) {
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

            // Trockenlauf und echter Lauf nehmen denselben Weg durch den
            // einzigen Schreiber -- eine zweite, selbst gebaute
            // Kollisionspruefung wuerde die Regel aus Ruling T1-A an zwei
            // Stellen leben lassen, genau die Doppelung, die dieses Projekt
            // schon mehrfach beseitigt hat. Der Unterschied liegt allein im
            // Abschluss dieser einen Gruppen-Transaktion: bestaetigen oder
            // zurueckrollen. Weil der Rollback je Gruppe passiert, bleibt
            // auch ein abgebrochener Trockenlauf rueckstandsfrei -- eine
            // grosse Transaktion, die erst am Ende zurueckrollt, waere genau
            // das nicht.
            DB::beginTransaction();

            try {
                $personId = PersonLinker::verbinde($ids, $teamId, $gruppe['phone']);

                // MUSS innerhalb der Transaktion gelesen werden: im
                // Trockenlauf ist die Zeile nach dem Rollback weg.
                $nummerFehlt = !$warSchonGebunden
                    && $gruppe['phone'] !== null
                    && DB::table('rec_persons')->where('id', $personId)->value('phone') === null;
            } catch (Throwable $e) {
                // Nur DIESE Gruppe faellt zurueck; alle vorher bestaetigten
                // bleiben stehen (siehe Klassenkommentar). Der bis hierhin
                // gesammelte Bericht wird trotzdem gedruckt -- sonst waeren
                // die Kennungen der schon erledigten Grenzfaelle verloren,
                // und ein zweiter Lauf bringt sie nicht zurueck, weil die
                // Gruppen dann gebunden sind (siehe I3-Hinweis). Danach
                // fliegt die Ausnahme weiter: ein Konflikt, den nur ein
                // Mensch aufloesen kann, darf nicht verschluckt werden.
                DB::rollBack();
                $this->bericht($neuePersonen, $neuVerbunden, $nummernUneinig, $kollisionen);
                $this->schreibe('ABBRUCH bei Gruppe ' . $this->kennung($ids) . ': ' . $e->getMessage());

                throw $e;
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            // Erst nach dem Abschluss zaehlen -- gezaehlt wird, was steht.
            // "Personen angelegt" wird abgeleitet statt gemessen: eine
            // Zaehlung ueber die ganze Tabelle vor/nach dem Lauf waere im
            // Trockenlauf immer 0, weil jede Gruppe sofort zurueckrollt.
            // Die Ableitung ist exakt, denn verbinde() legt genau dann eine
            // neue Zeile an, wenn kein Mitglied der Gruppe schon gebunden
            // war.
            if (!$warSchonGebunden) {
                $neuePersonen++;
            }
            foreach ($ids as $id) {
                if (!($warGebundenJeMitarbeiter[$id] ?? false)) {
                    $neuVerbunden++;
                }
            }
            if ($gruppe['phone_uneinig']) {
                $nummernUneinig[] = $ids;
            }
            if ($nummerFehlt) {
                $kollisionen[] = $ids;
            }
        }

        $this->bericht($neuePersonen, $neuVerbunden, $nummernUneinig, $kollisionen);

        return self::SUCCESS;
    }

    /**
     * Uebersicht (Zahlen) und darunter die Kennungen der beiden
     * HR-Sorten -- siehe Klassenkommentar, warum die Kennungen immer
     * mitkommen und warum nie Namen.
     *
     * @param  list<list<int>>  $nummernUneinig
     * @param  list<list<int>>  $kollisionen
     */
    private function bericht(int $neuePersonen, int $neuVerbunden, array $nummernUneinig, array $kollisionen): void
    {
        $this->schreibe('Personen angelegt: ' . $neuePersonen);
        $this->schreibe('Anstellungen verbunden: ' . $neuVerbunden);
        $this->schreibe('Nummern uneinig: ' . count($nummernUneinig));
        $this->schreibe('Nummer nicht gesetzt (Dublette oder unlesbar): ' . count($kollisionen));

        $this->kennungen('Kennungen, Nummern uneinig', $nummernUneinig);
        $this->kennungen('Kennungen, Nummer nicht gesetzt (Dublette oder unlesbar)', $kollisionen);
    }

    /** @param list<list<int>> $gruppen */
    private function kennungen(string $ueberschrift, array $gruppen): void
    {
        if ($gruppen === []) {
            return;
        }

        $this->schreibe($ueberschrift . ' (Mitarbeiter-Kennungen, ohne Namen):');
        foreach ($gruppen as $ids) {
            $this->schreibe('  ' . $this->kennung($ids));
        }
    }

    /** @param list<int> $ids */
    private function kennung(array $ids): string
    {
        return implode(' + ', $ids);
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

    /** Testzugriff (siehe BackfillPersonsTest::lauf()) -- Uebersicht und Kennungen als Text. */
    public function ausgabe(): string
    {
        return implode("\n", $this->ausgabeZeilen);
    }
}
