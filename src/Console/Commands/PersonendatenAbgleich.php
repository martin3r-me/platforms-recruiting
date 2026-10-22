<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\AnstellungsRegeln;
use Platform\Recruiting\Services\PersonenSpiegel;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Support\PersonenFelder;

/**
 * Prueft, ob die Personenfelder aller Akten eines Menschen (RG, MA) gleich
 * sind, und listet jede Abweichung (Spec 2026-10-09 §6).
 *
 * Ohne --nach laeuft das Kommando rein lesend. Ein pauschales Ueberschreiben
 * gibt es bewusst nicht: angeglichen wird nur gezielt, fuer EINE Person
 * (--person) und von EINER benannten Akte aus (--nach). Uebertragen werden
 * dabei nur nicht-leere Werte dieser Akte; leere werden gemeldet, nie
 * uebertragen. Mit --felder nur diese Felder. Geschrieben wird ueber den PersonenSpiegel (Query-Builder,
 * Export-Marker + Lohn-Eintraege).
 *
 * Gruppen ohne rec_person_id (Paarung ueber person_key) werden mit
 * "key:<8 Zeichen>" gelistet, sind aber nicht adressierbar. *
 * --auffuellen (Spec 2026-10-22 §5): fuellt nur LEERE Personenfelder aktiver
 * Akten, und nur wenn alle gefuellten aktiven Akten der Person denselben
 * Wert tragen — also kein Ueberschreiben. Query Builder ohne Export-Marker
 * und ohne updated_at (Sebastian 22.10.), --dry-run zaehlt nur.
 *
 * --je-gesellschaft (Nachrunde Markus 12.10.2026, E7/E8): rein lesende
 * HR-Liste der Verstoesse gegen die Je-Gesellschaft-Regel unter den AKTIVEN
 * Anstellungen eines Menschen — mehr als eine Hauptarbeitgeber, mehr als
 * eine Steuerklasse 1-5 ('P' zaehlt nicht, 20.10.2026). Kein automatisches Heilen: HR entscheidet je
 * Person (in der Akte speichern, dann zieht AnstellungsRegeln die andere
 * Anstellung nach). Nicht kombinierbar mit --nach.
 */
final class PersonendatenAbgleich extends Command
{
    protected $signature = 'recruiting:personendaten-abgleich
        {--team= : Nur Akten dieses Teams als Ausgangspunkt}
        {--person= : rec_person_id — nur diese Person}
        {--nach= : rec_employee_id — Personenfelder dieser Akte auf alle Geschwister uebertragen (nur mit --person)}
        {--felder= : komma-getrennt, Ausgabe und Angleichen (--nach) auf diese Felder begrenzen}
        {--je-gesellschaft : nur lesen — Verstoesse beide Hauptarbeitgeber / beide Steuerklasse ungleich 6 als HR-Liste}
        {--auffuellen : leere Personenfelder AKTIVER Akten aus der aktiven Schwester fuellen (eindeutiger Wert), ohne ZAS-Marker}
        {--dry-run : mit --auffuellen: nur zaehlen, nichts schreiben}';

    protected $description = 'Personenfelder je Person ueber alle Akten vergleichen; mit --person --nach gezielt angleichen';

    public function handle(): int
    {
        $person = $this->option('person');
        $nach = $this->option('nach');
        foreach (['--person' => $person, '--nach' => $nach] as $name => $wert) {
            if ($wert !== null && $wert !== '' && !ctype_digit((string) $wert)) {
                $this->error("{$name} erwartet eine ganze Zahl (ID), bekam '{$wert}'. Es wird nichts geschrieben.");

                return self::FAILURE;
            }
        }
        $person = ($person === null || $person === '') ? null : (int) $person;
        $nach = ($nach === null || $nach === '') ? null : (int) $nach;

        if ($nach !== null && $person === null) {
            $this->error('--nach geht nur zusammen mit --person. Es wird nichts geschrieben.');

            return self::FAILURE;
        }

        // Review I2 (22.10.): --dry-run gilt nur fuer --auffuellen. Ohne diese
        // Bremse liefe "--nach ... --dry-run" still als echtes Angleichen mit
        // ZAS-Markern.
        if ($this->option('dry-run') && !$this->option('auffuellen')) {
            $this->error('--dry-run gibt es nur zusammen mit --auffuellen (ohne --nach liest das Kommando ohnehin nur). Es wird nichts geschrieben.');

            return self::FAILURE;
        }

        if ($this->option('auffuellen')) {
            if ($nach !== null || $this->option('je-gesellschaft')) {
                $this->error('--auffuellen geht nicht zusammen mit --nach oder --je-gesellschaft. Es wird nichts geschrieben.');

                return self::FAILURE;
            }
            $nurFelder = null;
            if (($f = trim((string) $this->option('felder'))) !== '') {
                $nurFelder = array_values(array_filter(array_map('trim', explode(',', $f))));
            }

            return $this->fuelleAuf($person, $nurFelder, (bool) $this->option('dry-run'));
        }

        if ($this->option('je-gesellschaft')) {
            if ($nach !== null) {
                $this->error('--je-gesellschaft liest nur und geht nicht zusammen mit --nach. Es wird nichts geschrieben.');

                return self::FAILURE;
            }

            return $this->jeGesellschaft($person);
        }

        $nurFelder = null;
        if (($f = trim((string) $this->option('felder'))) !== '') {
            $nurFelder = array_values(array_filter(array_map('trim', explode(',', $f))));
        }

        $gruppen = $this->gruppen($person);
        $zeilen = [];
        $personen = 0;
        $mitAbweichung = 0;
        $felderGesamt = 0;
        $alleIds = [];

        foreach ($gruppen as $g) {
            $personen++;
            $alleIds = array_merge($alleIds, $g['ids']);
            $abweichend = $this->abweichungen($g, $nurFelder);
            if ($abweichend === []) {
                continue;
            }
            $mitAbweichung++;
            foreach ($abweichend as $feld => $zellen) {
                $felderGesamt++;
                $zeilen[] = array_merge([$g['label'], $feld], $zellen);
            }
        }

        if ($nach !== null) {
            if (!in_array($nach, $alleIds, true)) {
                $this->error("Akte #{$nach} gehoert nicht zu Person {$person}. Es wird nichts geschrieben.");

                return self::FAILURE;
            }
        }

        if ($zeilen !== []) {
            $breite = max(array_map('count', $zeilen));
            $kopf = ['Person', 'Feld'];
            for ($i = 2; $i < $breite; $i++) {
                $kopf[] = 'Akte (PNr): Wert';
            }
            $zeilen = array_map(fn ($z) => array_pad($z, $breite, ''), $zeilen);
            $this->table($kopf, $zeilen);
        }

        $this->line("Geprueft: {$personen} Personen · mit Abweichung: {$mitAbweichung} · abweichende Felder: {$felderGesamt}");

        if ($nach !== null) {
            return $this->gleicheAn($nach, $nurFelder);
        }

        return self::SUCCESS;
    }

    /**
     * HR-Liste: je Person mit mindestens zwei aktiven Anstellungen die
     * Verstoesse gegen die Je-Gesellschaft-Regel (AnstellungsRegeln::verstoesse).
     * Schreibt nichts.
     */
    private function jeGesellschaft(?int $person): int
    {
        $zeilen = [];
        $personen = 0;
        $mitVerstoss = 0;
        foreach ($this->gruppen($person) as $g) {
            $akten = DB::table('rec_employees')->whereIn('id', $g['ids'])->orderBy('id')
                ->get(['id', 'personnel_number', 'company', 'first_name', 'last_name', 'is_active', 'is_main_employer', 'tax_class'])
                ->map(fn ($r) => (array) $r)->all();
            $aktiv = array_values(array_filter($akten, fn (array $a) => (bool) $a['is_active']));
            if (count($aktiv) < 2) {
                continue;
            }
            $personen++;
            $verstoesse = AnstellungsRegeln::verstoesse($aktiv);
            if ($verstoesse === []) {
                continue;
            }
            $mitVerstoss++;
            $name = trim(($aktiv[0]['first_name'] ?? '') . ' ' . ($aktiv[0]['last_name'] ?? ''));
            foreach ($verstoesse as $v) {
                $zellen = array_map(function (array $a) use ($v) {
                    $wert = $v === 'haupt'
                        ? match (PersonenFelder::normalisiere('is_main_employer', $a['is_main_employer'])) { '1' => 'Haupt', '0' => 'Neben', default => 'leer' }
                        : (PersonenFelder::normalisiere('tax_class', $a['tax_class']) ?? 'leer');

                    return sprintf('#%d (%s %s): %s', $a['id'], $a['company'] ?? '-', $a['personnel_number'] ?? '-', $wert);
                }, $aktiv);
                $zeilen[] = array_merge([
                    $g['label'],
                    $name !== '' ? $name : '-',
                    $v === 'haupt' ? 'beide Hauptarbeitgeber' : 'beide Steuerklasse ungleich 6',
                ], $zellen);
            }
        }

        if ($zeilen !== []) {
            $breite = max(array_map('count', $zeilen));
            $kopf = ['Person', 'Name', 'Verstoss'];
            for ($i = 3; $i < $breite; $i++) {
                $kopf[] = 'Akte (Firma PNr): Wert';
            }
            $this->table($kopf, array_map(fn ($z) => array_pad($z, $breite, ''), $zeilen));
        }

        $this->line("Geprueft: {$personen} Personen mit mehreren aktiven Anstellungen · mit Verstoss: {$mitVerstoss} (nur gelesen — HR entscheidet je Person)");

        return self::SUCCESS;
    }

    /**
     * Spec 2026-10-22 §5: je Person mit mindestens zwei AKTIVEN, nicht-Test-
     * Akten; je Personenfeld: genau ein gefuellter (normierter) Wert → in die
     * leeren aktiven Akten schreiben. Zwei Werte → Widerspruch, nichts
     * schreiben. Query Builder, kein zas_changed_at, kein updated_at
     * (Sebastian 22.10.: Variante A). Wiederholbar.
     *
     * @param list<string>|null $nurFelder
     */
    private function fuelleAuf(?int $person, ?array $nurFelder, bool $trocken): int
    {
        $markerVorher = DB::table('rec_employees')->whereNotNull('zas_changed_at')->count();
        $gefuellt = [];
        $widerspruch = [];
        $personen = 0;
        $akten = [];

        foreach ($this->gruppen($person) as $g) {
            $rows = DB::table('rec_employees')
                ->whereIn('id', $g['ids'])
                ->where('is_active', 1)
                ->where('is_test', false)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();
            if ($rows->count() < 2) {
                continue;
            }
            $teamId = $rows->first()->team_id !== null ? (int) $rows->first()->team_id : null;
            $felder = PersonenFelder::fuerTeam($teamId, $nurFelder);
            $schreiben = [];   // id => [feld => roh]

            foreach ($felder as $feld) {
                $quelle = null;
                $werte = [];
                $leere = [];
                foreach ($rows as $r) {
                    $n = PersonenFelder::normalisiere($feld, $r->{$feld} ?? null);
                    if ($n === null) {
                        $leere[] = (int) $r->id;
                        continue;
                    }
                    $werte[$n] = true;
                    $quelle ??= $r->{$feld};
                }
                if ($leere === [] || $werte === []) {
                    continue;
                }
                if (count($werte) > 1) {
                    $widerspruch[$feld] = ($widerspruch[$feld] ?? 0) + 1;
                    continue;
                }
                foreach ($leere as $id) {
                    $schreiben[$id][$feld] = $quelle;
                    $gefuellt[$feld] = ($gefuellt[$feld] ?? 0) + 1;
                    $akten[$id] = true;
                }
            }

            if ($schreiben !== []) {
                $personen++;
            }
            if (!$trocken) {
                foreach ($schreiben as $id => $set) {
                    DB::table('rec_employees')->where('id', $id)->update($set);
                }
            }
        }

        $alle = array_unique(array_merge(array_keys($gefuellt), array_keys($widerspruch)));
        sort($alle);
        if ($alle !== []) {
            $this->table(['Feld', 'gefuellt (Akten)', 'Widerspruch (Personen)'], array_map(
                fn ($f) => [$f, (string) ($gefuellt[$f] ?? 0), (string) ($widerspruch[$f] ?? 0)],
                $alle,
            ));
        }
        $this->line(sprintf('Personen mit Auffuellung: %d · Akten aufgefuellt: %d · Felder aufgefuellt: %d', $personen, count($akten), array_sum($gefuellt)));
        $this->line(sprintf('Marker vorher: %d, nachher: %d', $markerVorher, DB::table('rec_employees')->whereNotNull('zas_changed_at')->count()));
        $this->line($trocken ? 'Trockenlauf — nichts geschrieben.' : 'aufgefuellt: ' . array_sum($gefuellt));

        return self::SUCCESS;
    }

    /** @return list<array{ids: list<int>, label: string}> */
    private function gruppen(?int $person): array
    {
        $q = RecEmployee::query()->orderBy('id');
        if (($team = $this->option('team')) !== null && $team !== '') {
            $q->where('team_id', (int) $team);
        }
        if ($person !== null) {
            $q->where('rec_person_id', $person);
        }

        $resolver = new PersonScopeResolver();
        $gruppen = [];
        foreach ($q->get() as $akte) {
            $ids = $resolver->forEmployee($akte)['ids'];
            sort($ids);
            if (count($ids) < 2) {
                continue;
            }
            $schluessel = implode(',', $ids);
            if (isset($gruppen[$schluessel])) {
                continue;
            }
            $label = $akte->rec_person_id !== null
                ? 'person:' . $akte->rec_person_id
                : 'key:' . substr((string) $akte->person_key, 0, 8);
            $gruppen[$schluessel] = ['ids' => $ids, 'label' => $label];
        }

        return array_values($gruppen);
    }

    /**
     * @param  array{ids: list<int>, label: string} $g
     * @return array<string, list<string>> Feld => Zellen "#id (pnr): wert"
     */
    private function abweichungen(array $g, ?array $nurFelder): array
    {
        $rows = DB::table('rec_employees')->whereIn('id', $g['ids'])->orderBy('id')->get()->keyBy('id');
        $erste = $rows->first();
        $felder = PersonenFelder::fuerTeam($erste->team_id !== null ? (int) $erste->team_id : null);
        if ($nurFelder !== null) {
            $felder = array_values(array_intersect($felder, $nurFelder));
        }

        $aus = [];
        foreach ($felder as $feld) {
            $normiert = [];
            foreach ($rows as $r) {
                $normiert[] = PersonenFelder::normalisiere($feld, $r->{$feld} ?? null);
            }
            if (count(array_unique($normiert, SORT_STRING)) <= 1) {
                continue;
            }
            $zellen = [];
            foreach ($rows as $i => $r) {
                $w = PersonenFelder::normalisiere($feld, $r->{$feld} ?? null);
                $zellen[] = sprintf('#%d (%s): %s', $r->id, $r->personnel_number ?? '-', $w ?? 'leer');
            }
            $aus[$feld] = $zellen;
        }

        return $aus;
    }

    /** @param list<string>|null $nurFelder */
    private function gleicheAn(int $nach, ?array $nurFelder): int
    {
        $quelle = RecEmployee::find($nach);
        $raw = DB::table('rec_employees')->where('id', $nach)->first();
        if ($quelle === null || $raw === null) {
            $this->error("Akte #{$nach} nicht gefunden. Es wird nichts geschrieben.");

            return self::FAILURE;
        }
        // Feldmenge wie in der Auflistung (dort: Team der kleinsten Akte der
        // Gruppe) -- hier das Team der Quell-Akte; eine Person liegt in einem Team.
        $team = $quelle->team_id !== null ? (int) $quelle->team_id : null;

        $werte = [];
        $leer = [];
        // --felder gilt auch hier: uebertragen wird nur, was HR angesehen hat (Review I5).
        $felder = PersonenFelder::fuerTeam($team);
        if ($nurFelder !== null) {
            $felder = array_values(array_intersect($felder, $nurFelder));
        }
        foreach ($felder as $feld) {
            if (PersonenFelder::normalisiere($feld, $raw->{$feld} ?? null) === null) {
                $leer[] = $feld;
            } else {
                $werte[$feld] = $raw->{$feld};
            }
        }

        $ids = (new PersonenSpiegel())->spiegele($quelle, $werte, true, true);
        $this->line('Angeglichen: ' . ($ids === [] ? '-' : implode(', ', $ids)));
        if ($leer !== []) {
            $this->line("Nicht uebertragen (leer in #{$nach}): " . implode(', ', $leer));
        }

        return self::SUCCESS;
    }
}
