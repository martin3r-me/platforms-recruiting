<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPerson;
use ReflectionClass;

/**
 * "EIN SCHREIBER" war eine Namensliste — dieser Test macht daraus eine
 * geschlossene Welt.
 *
 * DER BEFUND (Schlusspruefung B2): die `#[Locked]`-Waechter dieses Zweigs
 * wurden nach Aufgabe 6 auf geschlossene Welten umgebaut, weil eine
 * Aufzaehlung von Namen kein Waechter ist — sie deckt, was jemand einmal
 * hingeschrieben hat, und schweigt zu allem, was danach dazukommt. Bei den
 * `$fillable`-Waechtern ist das nicht nachgezogen worden. Belegt: die fuenf
 * Spalten des Nummernwechsels in `RecPerson::$fillable` eingetragen — Suite
 * gruen. `rec_person_id` in `RecEmployee::$fillable` eingetragen — Suite
 * gruen, obwohl Ruling T3-D ausdruecklich sagt, "EIN SCHREIBER" sei damit
 * eine Eigenschaft des Modells und keine Verabredung mehr.
 *
 * DIE FORM. Jede Spalte der beiden Tabellen steht in genau EINER von zwei
 * Listen: beschreibbar, oder gesperrt mit ausgeschriebenem Grund. Geprueft
 * wird beides gegeneinander:
 *
 *  1. beschreibbar + gesperrt ergibt GENAU die Spalten der Tabelle. Eine
 *     neue Spalte faellt in keine der beiden Listen und macht den Test rot
 *     — der Naechste muss entscheiden, statt es zu vergessen.
 *  2. `$fillable` ist GENAU die Liste "beschreibbar". Ein Eintrag mehr ist
 *     rot, ein Eintrag weniger auch.
 *
 * WOHER DIE WELT KOMMT. Nicht aus einer Liste im Test (das waere dieselbe
 * Namensliste eine Ebene hoeher), sondern aus den Migrationen dieses Moduls:
 * sie laufen hier gegen eine frische SQLite-Datenbank, und danach wird die
 * Spaltenliste abgefragt. Die Migrationen sind die einzige Stelle, an der
 * eine neue Spalte entsteht.
 *
 * WARUM `getFillable()` UND NICHT `isFillable()`. Im ganzen Baum rufen 94
 * Testdateien `Model::unguard()` und KEINE EINZIGE `reguard()`. `$unguarded`
 * ist eine statische Eigenschaft; einmal abgeschaltet, liefert
 * `isFillable()` fuer den Rest des Prozesses zu JEDEM Feld true. Ein
 * Waechter darueber waere im Gesamtlauf wertlos und einzeln gruen (geparkter
 * Fund aus Aufgabe 1).
 *
 * Und `newInstanceWithoutConstructor()` statt `new`: das Modell soll fuer
 * diese Frage weder booten noch eine Verbindung brauchen.
 */
final class MassenzuweisungGeschlosseneWeltTest extends TestCase
{
    /**
     * Migrationen, die in dieser Umgebung nicht laufen KOENNEN — je mit
     * Grund. Die Liste ist Teil der Zusicherung: scheitert eine ANDERE, ist
     * der Test rot. Ohne sie koennte eine kaputte Migration die Welt still
     * verkleinern, und der Waechter waere gruen, weil er dann eben weniger
     * Spalten kennt.
     */
    private const MIGRATIONEN_OHNE_LAUF = [
        // Reine Daten-Migration; sie liest core_extra_field_definitions, eine
        // Tabelle aus platforms-core. Sie legt keine Spalte an den beiden
        // Tabellen an, auf die es hier ankommt.
        '2026_04_12_000003_migrate_extra_fields_to_phases.php',
    ];

    /**
     * rec_persons: was per Eloquent-Massenzuweisung geschrieben werden darf.
     *
     * @var list<string>
     */
    private const PERSONS_BESCHREIBBAR = ['uuid', 'team_id', 'email'];

    /**
     * rec_persons: was NICHT massenzuweisbar ist — und warum.
     *
     * @var array<string, string>
     */
    private const PERSONS_GESPERRT = [
        'id'         => 'Schluessel, vergibt die Datenbank.',
        'created_at' => 'Zeitstempel, vergibt der Schreiber selbst.',
        'updated_at' => 'Zeitstempel, vergibt der Schreiber selbst.',

        'phone' => 'Der spaetere Benutzername. Er muss auf ALLE Anstellungen mitwandern '
            .'(PersonLinker::setzeNummer); ein Update daran vorbei schickt den naechsten '
            .'Einmalcode ans alte Geraet, und der Mensch sperrt sich selbst aus.',
        'merged_into_person_id' => 'Das Stilllegen selbst, mit den vier Wachen aus '
            .'PersonLinker::fuehreZusammen() — sonst entsteht ein Ring, an dem sich niemand '
            .'mehr anmelden kann.',

        'password_hash' => 'Die Anmeldung. Schreibt nur KontoWriter, ueber Hash::make().',
        'registered_at' => 'Die Anmeldung. Schreibt nur KontoWriter.',
        'invited_at'    => 'Setzt ladeEin() im selben Schreibvorgang wie den Einladungs-Hash. '
            .'recruiting:konto-einladen liest die Spalte als "schon eingeladen" (KontoEinladen.php:517) '
            .'und ueberspringt danach — ein Stempel ohne Einladung liesse Menschen still aus der Welle fallen.',
        'locked_at' => 'Das Tor, an dem offeneZeile(), personFuerEinladung() und darfSichAnmelden() '
            .'haengen. Massenzuweisbar liesse sich eine Sperre mit einem beliebigen Update AUFHEBEN — '
            .'und die Sperre ist die einzige Handhabe, die HR gegen ein Konto hat.',

        'invite_token_hash' => 'Einladungs-Geheimnis, nur KontoWriter.',
        'invite_expires_at' => 'Einladungs-Geheimnis, nur KontoWriter.',
        'invite_used_at'    => 'Einladungs-Geheimnis, nur KontoWriter.',

        'code_hash'        => 'Einmalcode, nur KontoWriter.',
        'code_expires_at'  => 'Einmalcode, nur KontoWriter.',
        'code_versuche'    => 'Der Rateversuch-Zaehler des Einmalcodes; ein Update darauf hebt die Bremse auf.',
        'code_zweck'       => 'Einmalcode, nur KontoWriter — der Zweck ist die Trennung der Wege.',
        'code_neue_nummer' => 'Einmalcode, nur KontoWriter — das Ziel eines Nummernwechsels.',

        'letzte_anmeldung_at' => 'Stempelt pruefeAnmeldung() (Ruling GD-8).',

        'wechsel_neue_nummer'  => 'Das 24-Stunden-Fenster von Weg 4; ein Antrag entsteht nur ueber beantrageNummerwechselMitCode().',
        'wechsel_beantragt_at' => 'Das 24-Stunden-Fenster von Weg 4.',
        'wechsel_wirksam_ab'   => 'Das 24-Stunden-Fenster von Weg 4 — massenzuweisbar liesse sich die Frist auf jetzt ziehen.',
        'wechsel_quelle'       => 'Das 24-Stunden-Fenster von Weg 4.',

        'notfall_gesperrt_bis' => 'Die Sperre nach einem gestoppten Antrag (Ruling GD-13); '
            .'massenzuweisbar waere das Stopp-Recht von HR wieder aussitzbar.',

        'aufgaben_signatur' => 'Der Zustand des Einsatz-Triggers. Geschrieben wird er ausschliesslich '
            .'ueber den Query Builder im Kommando, also beobachter-frei: per Eloquent liefe der '
            .'Beobachter-Lauf mit und setzte den ZAS-Export-Marker. Eine blosse PRUEFUNG, die niemandes '
            .'Daten aendert, spuelte damit den Bestand in die naechste ZAS-Update-Datei (Vorfall 02.09.2026).',
        'aufgaben_gemeldet_at' => 'Der Zustand des Einsatz-Triggers, aus demselben Grund wie '
            .'aufgaben_signatur beobachter-frei. Zusaetzlich ist der Stempel die einzige Bremse gegen '
            .'eine zweite Nachricht mit demselben Inhalt — massenzuweisbar liesse er sich auf null '
            .'setzen und die Bremse damit loesen.',
        'aufgaben_nachricht_id' => 'Der Verweis auf die zuletzt verschickte Aufgaben-Nachricht (ET-23). '
            .'Beobachter-frei aus demselben Grund wie die beiden Spalten darueber; zusaetzlich ist er '
            .'die einzige Handhabe, mit der ein spaeter per Webhook gemeldetes "failed" ueberhaupt '
            .'auffaellt — massenzuweisbar liesse er sich auf eine fremde Nachricht zeigen lassen.',
    ];

    /**
     * rec_employees: was NICHT massenzuweisbar ist — und warum. Alles
     * uebrige ist beschreibbar; die Tabelle traegt 94 Spalten, und sie hier
     * ein zweites Mal auszuschreiben hiesse, dieselbe Namensliste zu bauen,
     * die dieser Test gerade abschafft.
     *
     * @var array<string, string>
     */
    private const EMPLOYEES_GESPERRT = [
        'id'         => 'Schluessel, vergibt die Datenbank.',
        'created_at' => 'Zeitstempel, vergibt Eloquent.',
        'updated_at' => 'Zeitstempel, vergibt Eloquent.',

        'rec_person_id' => 'Ruling T3-D: die Personen-Zuordnung setzt ausschliesslich PersonLinker, '
            .'per DB::table(). Ein $employee->update([...]) liefe per Eloquent MIT vollem '
            .'Beobachter-Lauf — also mit genau dem Export-Marker, den dieser ganze Zweig '
            .'vermeidet; eine Einladungswelle spuelte den Bestand in die naechste ZAS-Update-Datei.',
        'person_key' => 'Der Paarungs-Marker RG/MA. Ihn setzt PersonPairLinker per DB::table(), '
            .'aus demselben Grund: er ist keine fachliche Aenderung am Mitarbeiter und darf '
            .'zas_changed_at nicht setzen.',
    ];

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository([]));

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('config');
        $container->forgetInstance('db');
        $container->forgetInstance('db.schema');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /**
     * Faehrt alle Migrationen des Moduls und gibt zurueck, welche dabei
     * geworfen haben.
     *
     * @return list<string>
     */
    private function migrationenFahren(): array
    {
        $dateien = glob(__DIR__.'/../../database/migrations/*.php');
        sort($dateien);

        $gescheitert = [];

        foreach ($dateien as $datei) {
            $migration = require $datei;

            try {
                $migration->up();
            } catch (\Throwable $e) {
                $gescheitert[] = basename($datei);
            }
        }

        return $gescheitert;
    }

    /** @return list<string> */
    private function fillableVon(string $klasse): array
    {
        /** @var Model $modell */
        $modell = (new ReflectionClass($klasse))->newInstanceWithoutConstructor();

        return $modell->getFillable();
    }

    /**
     * @param  array<string, string>  $gesperrt
     * @param  list<string>           $beschreibbar
     */
    private function assertGeschlosseneWelt(string $tabelle, string $klasse, array $beschreibbar, array $gesperrt): void
    {
        $welt = Capsule::schema()->getColumnListing($tabelle);

        // VORFLUG. Ohne ihn waere der Test gruen, wenn die Migrationen gar
        // nichts angelegt haetten: drei leere Mengen sind gleich (die
        // neunte der zehn Varianten).
        $this->assertGreaterThan(
            10,
            count($welt),
            "{$tabelle}: die Migrationen haben die Tabelle offenbar nicht angelegt — dann prueft dieser Test nichts",
        );
        $this->assertContains('id', $welt, "{$tabelle}: die Spaltenliste kommt nicht aus der Datenbank");

        $gesperrteNamen = array_keys($gesperrt);

        $doppelt = array_intersect($beschreibbar, $gesperrteNamen);
        $this->assertSame(
            [],
            array_values($doppelt),
            "{$tabelle}: diese Spalten stehen in BEIDEN Listen — jede gehoert in genau eine",
        );

        foreach ($gesperrt as $spalte => $grund) {
            $this->assertNotSame(
                '',
                trim($grund),
                "{$tabelle}.{$spalte}: eine gesperrte Spalte braucht einen ausgeschriebenen Grund",
            );
        }

        $eingeordnet = array_merge($beschreibbar, $gesperrteNamen);
        sort($eingeordnet);
        $sortierteWelt = $welt;
        sort($sortierteWelt);

        $this->assertSame(
            $sortierteWelt,
            $eingeordnet,
            "{$tabelle}: jede Spalte der Tabelle muss in GENAU EINER der beiden Listen stehen. "
            .'Neu und noch nirgends eingeordnet: '.implode(', ', array_diff($sortierteWelt, $eingeordnet)).'. '
            .'Eingeordnet, aber nicht (mehr) in der Tabelle: '.implode(', ', array_diff($eingeordnet, $sortierteWelt)).'.',
        );

        $fillable = $this->fillableVon($klasse);
        sort($fillable);
        $sortiertBeschreibbar = $beschreibbar;
        sort($sortiertBeschreibbar);

        $this->assertSame(
            $sortiertBeschreibbar,
            $fillable,
            "{$tabelle}: \$fillable muss GENAU die beschreibbaren Spalten tragen. "
            .'Zuviel in $fillable: '.implode(', ', array_diff($fillable, $sortiertBeschreibbar)).'. '
            .'Fehlt in $fillable: '.implode(', ', array_diff($sortiertBeschreibbar, $fillable)).'.',
        );
    }

    public function test_nur_die_benannten_migrationen_laufen_hier_nicht(): void
    {
        $gescheitert = $this->migrationenFahren();
        sort($gescheitert);

        $erwartet = self::MIGRATIONEN_OHNE_LAUF;
        sort($erwartet);

        $this->assertSame(
            $erwartet,
            $gescheitert,
            'Eine Migration scheitert hier, die nicht auf der benannten Liste steht. Solange das so ist, '
            .'kennt der Massenzuweisungs-Waechter womoeglich weniger Spalten, als es gibt — und waere '
            .'gruen, weil er sie nicht sieht.',
        );
    }

    public function test_rec_persons_ist_eine_geschlossene_welt(): void
    {
        $this->migrationenFahren();

        $this->assertGeschlosseneWelt(
            'rec_persons',
            RecPerson::class,
            self::PERSONS_BESCHREIBBAR,
            self::PERSONS_GESPERRT,
        );
    }

    /**
     * Bei rec_employees ist die Gegenrichtung ausgeschrieben: gesperrt sind
     * fuenf Spalten, beschreibbar ist der Rest. Das bleibt eine geschlossene
     * Welt — "beschreibbar" wird aus der Tabelle MINUS der Sperrliste
     * gebildet, und weil $fillable danach exakt uebereinstimmen muss, faellt
     * eine neue Spalte trotzdem auf: sie ist dann in "beschreibbar", steht
     * aber nicht in $fillable.
     */
    public function test_rec_employees_ist_eine_geschlossene_welt(): void
    {
        $this->migrationenFahren();

        $welt         = Capsule::schema()->getColumnListing('rec_employees');
        $beschreibbar = array_values(array_diff($welt, array_keys(self::EMPLOYEES_GESPERRT)));

        $this->assertGeschlosseneWelt(
            'rec_employees',
            RecEmployee::class,
            $beschreibbar,
            self::EMPLOYEES_GESPERRT,
        );
    }
}
