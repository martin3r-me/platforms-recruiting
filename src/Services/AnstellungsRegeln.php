<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Support\PersonenFelder;

/**
 * Was zwischen den Anstellungen EINES Menschen (RG und MA, Personen-Klammer)
 * gelten muss, wenn an einer davon Steuerklasse oder Haupt-/Nebenarbeitgeber
 * gesetzt wird. EINE Stelle fuer die Regel — alle Schreibwege laufen hier
 * durch (Eloquent-Speichern ueber RecEmployeeExportObserver, der
 * Massenlauf recruiting:backfill-employer-declaration ausdruecklich).
 *
 * Kundenentscheidung Markus 12.10.2026:
 *   E7 Steuerklasse: „nein, muss abweichend sein, wenn 1 Vergeben, dann die
 *      zweite Karte 6" — hoechstens EINE Anstellung hat eine Klasse != 6.
 *      Klasse != 6 an einer Anstellung setzt die andere auf 6. Klasse 6
 *      setzen aendert die andere nicht, ein leerer Wert auch nicht.
 *   E8 Haupt-/Nebenarbeitgeber: „auch hier, immer nur 1 x moeglich, nicht
 *      den gleichen Status 2 x vergeben" — hoechstens EINE Anstellung ist
 *      Hauptarbeitgeber. Haupt=ja an einer setzt die andere auf nein.
 *      „Beide Neben" (Hauptarbeitgeber ist ein Dritter) bleibt erlaubt; sagt
 *      der Mensch „anderer Arbeitgeber X", gilt das fuer beide Anstellungen.
 *      Das ist die EINZIGE Stelle, an der other_employer auf die Schwester
 *      geschrieben wird, und nur, wenn sie selbst nicht Hauptarbeitgeber ist.
 *   Krankenkasse (Generalprobe 13.10.): je Anstellung, keine Folge auf die
 *      Schwester — sie steht nur deshalb nicht hier, weil es nichts zu tun gibt.
 *
 * Bewusst NICHT angefasst (konservativ, keine stillen Aenderungen):
 *  - inaktive Schwestern (beendete Anstellung): ihre Werte sind Historie,
 *    ein Umschreiben erzeugte nur einen Export ohne Nutzen;
 *  - other_employer der Schwester, wenn sie auf „Neben" gesetzt wird — ihr
 *    Hauptarbeitgeber ist dann die Quelle, das traegt der Mensch selbst ein
 *    (das Portal fragt „Wer ist es dann?" als Pflichtangabe nach);
 *  - das Leeren eines Wertes zieht nichts nach.
 *
 * Geschrieben wird per Query-Builder (kein updated-Ereignis, keine
 * Rueckkopplung, wie PersonenSpiegel). Weil damit die Beobachter nicht
 * anspringen, setzt die Regel ZAS-Marker und Lohn-Eintrag AUSDRUECKLICH —
 * es ist eine echte Datenaenderung, ZAS braucht die Werte je Gesellschaft
 * (vgl. die Lohn-Falle im PortalShell-Docblock zu speichereGruppe()).
 */
class AnstellungsRegeln
{
    public const NEBEN_STEUERKLASSE = '6';

    /** Felder, auf die die Regel reagiert. */
    public const AUSLOESER = ['tax_class', 'is_main_employer', 'other_employer'];

    /**
     * Die reine Regel: welche Werte bekommt die Schwester?
     *
     * @param  array<string,mixed> $quelle      Endstand der Quelle (mind. tax_class, is_main_employer, other_employer)
     * @param  list<string>        $geaendert   an der Quelle geaenderte Felder
     * @param  array<string,mixed> $schwester   aktueller Stand der Schwester
     * @return array<string,mixed> Feld => neuer Wert (nur echte Aenderungen)
     */
    public static function folgenFuerSchwester(array $quelle, array $geaendert, array $schwester): array
    {
        $neu = [];
        $norm = fn (string $feld, array $zeile) => PersonenFelder::normalisiere($feld, $zeile[$feld] ?? null);

        // E8: Haupt=ja hier -> Schwester ist Nebenarbeitgeber.
        $quelleHaupt = $norm('is_main_employer', $quelle);
        if (in_array('is_main_employer', $geaendert, true)
            && $quelleHaupt === '1'
            && $norm('is_main_employer', $schwester) !== '0') {
            $neu['is_main_employer'] = false;
        }

        // E8, „beide Neben": der andere Arbeitgeber X gilt fuer beide —
        // aber nicht fuer eine Schwester, die selbst Hauptarbeitgeber ist.
        $x = $norm('other_employer', $quelle);
        if ((in_array('other_employer', $geaendert, true) || in_array('is_main_employer', $geaendert, true))
            && $quelleHaupt === '0'
            && $x !== null
            && $norm('is_main_employer', $schwester) !== '1'
            && $norm('other_employer', $schwester) !== $x) {
            $neu['other_employer'] = $x;
        }

        // E7: Klasse != 6 hier -> Schwester bekommt 6. Leer und 6 aendern nichts.
        $klasse = $norm('tax_class', $quelle);
        if (in_array('tax_class', $geaendert, true)
            && $klasse !== null
            && $klasse !== self::NEBEN_STEUERKLASSE
            && $norm('tax_class', $schwester) !== self::NEBEN_STEUERKLASSE) {
            $neu['tax_class'] = self::NEBEN_STEUERKLASSE;
        }

        return $neu;
    }

    /**
     * Folgeaenderungen berechnen, ohne zu schreiben.
     *
     * @param  list<string> $geaendert
     * @return list<array{id:int, company:?string, aenderungen:array<string,array{old:mixed,new:mixed}>}>
     */
    public function folgen(RecEmployee $quelle, array $geaendert): array
    {
        $geaendert = array_values(array_intersect($geaendert, self::AUSLOESER));
        if ($geaendert === [] || $quelle->id === null) {
            return [];
        }

        // Schwestern finden wie der Spiegel (person_key / rec_persons) —
        // keine eigene Paarungslogik.
        $ids = app(PersonenSpiegel::class)->geschwister($quelle);
        if ($ids === []) {
            return [];
        }

        $quelleWerte = [];
        foreach (self::AUSLOESER as $feld) {
            $quelleWerte[$feld] = $quelle->getAttribute($feld);
        }

        $folgen = [];
        $zeilen = DB::table('rec_employees')->whereIn('id', $ids)->where('is_active', true)->orderBy('id')
            ->get(array_merge(['id', 'team_id', 'company'], self::AUSLOESER));
        foreach ($zeilen as $zeile) {
            $alt = (array) $zeile;
            $neu = self::folgenFuerSchwester($quelleWerte, $geaendert, $alt);
            if ($neu === []) {
                continue;
            }
            $aenderungen = [];
            foreach ($neu as $feld => $wert) {
                $aenderungen[$feld] = ['old' => $alt[$feld] ?? null, 'new' => $wert];
            }
            $folgen[] = [
                'id'          => (int) $zeile->id,
                'team_id'     => $zeile->team_id !== null ? (int) $zeile->team_id : null,
                'company'     => $zeile->company !== null ? (string) $zeile->company : null,
                'aenderungen' => $aenderungen,
            ];
        }

        return $folgen;
    }

    /**
     * Berechnen und schreiben. Marker und Lohn-Eintrag wie bei einem
     * normalen Feld-Speichern; nur der Massenlauf (Backfill) schaltet beides
     * ab, wie er es fuer die Quelle selbst auch tut.
     *
     * @param  list<string> $geaendert
     * @return list<array{id:int, company:?string, aenderungen:array<string,array{old:mixed,new:mixed}>}>
     */
    public function anwenden(RecEmployee $quelle, array $geaendert, bool $markerSetzen = true, bool $lohnVerfolgen = true): array
    {
        $folgen = $this->folgen($quelle, $geaendert);
        if ($folgen === []) {
            return [];
        }

        DB::transaction(function () use ($folgen, $markerSetzen, $lohnVerfolgen) {
            foreach ($folgen as $folge) {
                $update = [];
                foreach ($folge['aenderungen'] as $feld => $w) {
                    $update[$feld] = is_bool($w['new']) ? (int) $w['new'] : $w['new'];
                }
                $update['updated_at'] = now();
                if ($markerSetzen && array_intersect(array_keys($folge['aenderungen']), RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS) !== []) {
                    $update['zas_changed_at'] = now();
                }
                DB::table('rec_employees')->where('id', $folge['id'])->update($update);

                if ($lohnVerfolgen) {
                    RecEmployeeExportObserver::verfolgeLohn($folge['id'], $folge['team_id'], $folge['aenderungen']);
                }
            }
        });

        return $folgen;
    }

    /**
     * Eine NEUE Anstellung kommt zu einem Menschen dazu (Nachrunde 12.10.2026,
     * Koordinator zu Bedenken 4): ZAS-Neuanlage mit Auto-Paarung
     * (ZasInboundEmployeeImporter nach uebernimmBeiPaarung) und Anlage aus
     * der Bewerbung (CreateEmployeeFromApplicantService nach linkPerson).
     * Die VORHANDENEN Anstellungen sind Quelle, die neue bekommt die Folgen:
     * vorhandene Haupt -> neue Neben, vorhandene Klasse != 6 -> neue 6.
     * Der andere Arbeitgeber X wird nur in ein LEERES Feld der neuen
     * Anstellung getragen (eine von ZAS mitgelieferte Angabe bleibt).
     *
     * Bewusst NICHT fuer das Verbinden zweier BESTEHENDER Anstellungen
     * (Audit --link/--pair, recruiting:personen-anlegen): dort gibt es keine
     * „neue" Seite, die Regel muesste raten. Solche Verstoesse listet
     * recruiting:personendaten-abgleich --je-gesellschaft fuer HR.
     *
     * Marker ja (ZAS muss Neben/6 fuer die neue Gesellschaft kennen), kein
     * Lohn-Eintrag (Anlage, keine Aenderung — wie uebernimmBeiPaarung).
     *
     * @return list<array{id:int, team_id:?int, company:?string, aenderungen:array<string,array{old:mixed,new:mixed}>}>
     */
    public function beiNeuerAnstellung(RecEmployee|int $neu, bool $markerSetzen = true): array
    {
        // Mit Modell: keine Abfrage, solange keine Geschwister erkennbar sind
        // (die Query-Zahl der MA-Anlage ist festgenagelt, EmployeeCreationCertificateTest).
        $neu = $neu instanceof RecEmployee ? $neu : RecEmployee::find($neu);
        if ($neu === null || !$neu->is_active) {
            return [];
        }
        $neueId = (int) $neu->id;
        $ids = app(PersonenSpiegel::class)->geschwister($neu);
        if ($ids === []) {
            return [];
        }

        $stand = (array) DB::table('rec_employees')->where('id', $neueId)
            ->first(array_merge(['id', 'team_id', 'company'], self::AUSLOESER));
        $vorhandene = DB::table('rec_employees')->whereIn('id', $ids)->where('is_active', true)->orderBy('id')
            ->get(self::AUSLOESER);

        $aenderungen = [];
        foreach ($vorhandene as $v) {
            foreach (self::folgenFuerSchwester((array) $v, self::AUSLOESER, $stand) as $feld => $wert) {
                if (array_key_exists($feld, $aenderungen)) {
                    continue;   // erste vorhandene Anstellung gewinnt
                }
                if ($feld === 'other_employer' && PersonenFelder::normalisiere($feld, $stand[$feld] ?? null) !== null) {
                    continue;
                }
                $aenderungen[$feld] = ['old' => $stand[$feld] ?? null, 'new' => $wert];
                $stand[$feld] = $wert;
            }
        }
        if ($aenderungen === []) {
            return [];
        }

        $update = [];
        foreach ($aenderungen as $feld => $w) {
            $update[$feld] = is_bool($w['new']) ? (int) $w['new'] : $w['new'];
        }
        $update['updated_at'] = now();
        if ($markerSetzen) {
            $update['zas_changed_at'] = now();
        }
        DB::table('rec_employees')->where('id', $neueId)->update($update);

        return [[
            'id'          => $neueId,
            'team_id'     => $stand['team_id'] !== null ? (int) $stand['team_id'] : null,
            'company'     => $stand['company'] !== null ? (string) $stand['company'] : null,
            'aenderungen' => $aenderungen,
        ]];
    }

    /**
     * Verstoesse unter den AKTIVEN Anstellungen eines Menschen — nur lesend,
     * fuer die HR-Liste (recruiting:personendaten-abgleich --je-gesellschaft).
     *
     * @param  list<array<string,mixed>> $akten  je Akte mind. is_active, is_main_employer, tax_class
     * @return list<string> 'haupt' (mehr als eine Haupt), 'steuerklasse' (mehr als eine != 6)
     */
    public static function verstoesse(array $akten): array
    {
        $aktiv = array_values(array_filter($akten, fn (array $a) => (bool) ($a['is_active'] ?? false)));
        $haupt = count(array_filter($aktiv, fn (array $a) => PersonenFelder::normalisiere('is_main_employer', $a['is_main_employer'] ?? null) === '1'));
        $klasse = count(array_filter($aktiv, function (array $a) {
            $k = PersonenFelder::normalisiere('tax_class', $a['tax_class'] ?? null);

            return $k !== null && $k !== self::NEBEN_STEUERKLASSE;
        }));

        $aus = [];
        if ($haupt > 1) {
            $aus[] = 'haupt';
        }
        if ($klasse > 1) {
            $aus[] = 'steuerklasse';
        }

        return $aus;
    }

    /**
     * Hinweissatz fuer HR (Akte): „Anstellung MA wurde auf Steuerklasse 6 /
     * Nebenarbeitgeber gesetzt." Leer, wenn es keine Folgen gab.
     *
     * @param list<array{id:int, company:?string, aenderungen:array<string,array{old:mixed,new:mixed}>}> $folgen
     */
    public static function hinweisHr(array $folgen): string
    {
        $saetze = [];
        foreach ($folgen as $folge) {
            $firma = trim((string) ($folge['company'] ?? '')) ?: ('#' . $folge['id']);
            $saetze[] = 'Anstellung ' . $firma . ' wurde auf ' . implode(' / ', self::teile($folge['aenderungen'])) . ' gesetzt.';
        }

        return implode(' ', $saetze);
    }

    /**
     * Hinweissatz fuer das Portal — sichtbar, nicht still (Auftrag Punkt 4).
     *
     * @param list<array{id:int, company:?string, aenderungen:array<string,array{old:mixed,new:mixed}>}> $folgen
     */
    public static function hinweisPortal(array $folgen, bool $duzen): string
    {
        $labels = (array) config('recruiting.zas.company_labels', []);
        $saetze = [];
        foreach ($folgen as $folge) {
            $code = trim((string) ($folge['company'] ?? ''));
            $firma = $code !== '' ? (string) ($labels[$code] ?? $code) : 'RheinGedeck';
            $saetze[] = ($duzen ? 'Für deine Anstellung bei ' : 'Für Ihre Anstellung bei ')
                . $firma . ' gilt jetzt: ' . implode(', ', self::teile($folge['aenderungen'])) . '.';
        }

        return implode(' ', $saetze);
    }

    /** @param array<string,array{old:mixed,new:mixed}> $aenderungen @return list<string> */
    private static function teile(array $aenderungen): array
    {
        $teile = [];
        if (array_key_exists('tax_class', $aenderungen)) {
            $teile[] = 'Steuerklasse ' . $aenderungen['tax_class']['new'];
        }
        if (array_key_exists('is_main_employer', $aenderungen)) {
            $teile[] = 'Nebenarbeitgeber';
        }
        if (array_key_exists('other_employer', $aenderungen)) {
            $teile[] = 'Hauptarbeitgeber „' . $aenderungen['other_employer']['new'] . '“';
        }

        return $teile;
    }
}
