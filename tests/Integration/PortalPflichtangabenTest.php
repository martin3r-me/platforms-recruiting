<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PortalProfileGuards;
use ReflectionMethod;

/**
 * SCHLUSSFIX F1 — die abgenommene Kostenseite des Deadlock-Rulings.
 *
 * Beim Deadlock-Fix (Aufgabe 6, C1) wurde entschieden, dass die Waechter beim
 * gruppenweisen Speichern nur noch fuer Felder der OFFENEN Gruppe blocken.
 * Die Abweichung wurde ausdruecklich damit begruendet: "Einziger verbliebener
 * Druck: Ring und Offen-Zaehler."
 *
 * Diese Minderung existierte nicht in der behaupteten Form:
 *
 *  (a) Der Offen-Zaehler enthielt die Pflichtangaben gar nicht. Er wurde aus
 *      den Nachweisen plus der Arbeitgeber-Frage gebildet; nationality und
 *      die Ersthelfer-Kopplung kamen darin NICHT vor.
 *  (b) Der Start-Bildschirm widersprach dem Ring WOERTLICH: bei offen === 0
 *      sagte Start "Wir haben alles, was wir von dir brauchen", derselbe Satz
 *      stand im Ring-Text und mass dort etwas anderes. Alle Nachweise da,
 *      Arbeitgeber beantwortet, Staatsangehoerigkeit leer -> Start sagte
 *      "Alles vollstaendig", das Profil "92 % — es fehlt die
 *      Staatsangehoerigkeit".
 *  (c) Der Ring nannte hoechstens drei Dinge in Katalogreihenfolge und
 *      mischte Pflicht mit Kosmetik. Die Staatsangehoerigkeit ist das LETZTE
 *      Feld der Gruppe Adresse -- drei fruehere Luecken genuegten, und die
 *      Pflichtangabe stand nicht mehr im Satz. Der rote Gruppenpunkt
 *      unterschied ebenfalls nicht zwischen "blockiert das Speichern" und
 *      "folgenlos".
 *
 * Gemessen wird hier am ECHTEN Weg: ansichtsDaten() der echten Komponente
 * (dieselbe Datenmenge, die render() an die Ansicht gibt) und, fuer den
 * Start-Satz, das GERENDERTE Blade-Stueck -- nicht der Quelltext.
 *
 * Die Menge der Pflichtangaben wird nirgends abgetippt: sie kommt aus
 * PortalProfileGuards::WAECHTER, also aus den Waechtern selbst.
 */
final class PortalPflichtangabenTest extends TestCase
{
    private string $tmpDir = '';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000001_add_full_field_set_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php',
            'database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php',
            'database/migrations/2026_08_06_000001_add_erstbescheinigung_file_id_to_rec_employees.php',
            'database/migrations/2026_09_01_000001_add_first_aider_certificate_file_id_to_rec_employees.php',
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php',
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
            // Der Offen-Zaehler liest auch die Nachweise -- ohne diese
            // Tabelle koennte er gar nicht gebildet werden.
            'database/migrations/2026_09_23_000001_create_rec_employee_proofs_table.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_employee_proofs')->delete();
        $this->tmpDir = sys_get_temp_dir() . '/portal-pflicht-' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    /**
     * Ein Mensch, dem NICHTS fehlt -- alle 47 Felder belegt, alle
     * Pflichtnachweise vorhanden. Von hier aus wird je Test genau eine
     * Luecke gerissen; sonst misst man ein Durcheinander statt einer Ursache.
     */
    private function mitarbeiter(array $ueberschreiben = []): RecEmployee
    {
        $ma = RecEmployee::create(array_merge([
            'team_id'          => 913,
            'first_name'       => 'Erika',
            'last_name'        => 'Musterfrau',
            'portal_token'     => 'tok-' . uniqid('', true),
            'is_active'        => true,
            'portal_v2_since'  => '2026-09-24 08:00:00',
            'is_eu_citizen'    => true,
            'employment_type'  => 'aushilfe',
            'is_first_aider'   => false,
            'is_main_employer' => true,
            'nationality'      => 'deutsch',
        ], $ueberschreiben));

        // Der Pflichtnachweis "ausweis" (immer verlangt) -- ohne ihn zaehlt
        // die Nachweisliste eine offene Aufgabe, die mit F1 nichts zu tun hat.
        Capsule::table('rec_employee_proofs')->insert([
            'uuid'            => 'p-' . uniqid('', true),
            'team_id'         => 913,
            'rec_employee_id' => $ma->id,
            'proof_type_code' => 'ausweis',
            'file_id'         => 5001,
            'valid_until'     => '2036-01-01',
            'version'         => 1,
            'uploaded_via'    => 'employee',
            'created_at'      => '2026-09-24 08:00:00',
            'updated_at'      => '2026-09-24 08:00:00',
        ]);

        return $ma->fresh();
    }

    /** Eine aktuelle Nachweis-Zeile fuer diesen Menschen. */
    private function nachweis(RecEmployee $ma, string $code, ?string $gueltigBis, ?int $fileId = 5001): void
    {
        Capsule::table('rec_employee_proofs')->insert([
            'uuid'            => 'p-' . uniqid('', true),
            'team_id'         => 913,
            'rec_employee_id' => $ma->id,
            'proof_type_code' => $code,
            'file_id'         => $fileId,
            'valid_until'     => $gueltigBis,
            'version'         => 1,
            'uploaded_via'    => 'employee',
            'created_at'      => '2026-09-24 08:00:00',
            'updated_at'      => '2026-09-24 08:00:00',
        ]);
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';
        $shell->duzen = true;

        return $shell;
    }

    /** Die Daten, die render() an die Ansicht gibt -- am echten Weg gemessen. */
    private function ansicht(PortalShell $shell): array
    {
        $methode = new ReflectionMethod($shell, 'ansichtsDaten');
        $methode->setAccessible(true);

        return $methode->invoke($shell);
    }

    private function blade(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php',
        );
    }

    /**
     * Rendert den Start-Block ("Deine Unterlagen") MIT dem @php-Block, der
     * $allesDa berechnet -- also genau die Kette, die (b) betraf.
     *
     * Geschnitten wird an zwei Markierungen, die es je genau einmal gibt;
     * beide werden geprueft, damit der Test nicht still ins Leere rendert,
     * falls jemand das Blade umbaut.
     */
    private function rendereStart(array $variablen): string
    {
        $blade = $this->blade();

        $vonPhp = strpos($blade, '@php' . "\n" . '            // Schlussfix F1');
        $this->assertNotFalse($vonPhp, 'Der Rechenblock des Start-Bereichs ist nicht mehr auffindbar.');
        $bisPhp = strpos($blade, '@endphp', $vonPhp);
        $this->assertNotFalse($bisPhp);
        $rechnung = substr($blade, $vonPhp, $bisPhp + strlen('@endphp') - $vonPhp);

        $vonNext = strpos($blade, '<div class="next">');
        $this->assertNotFalse($vonNext, 'Der Start-Block "Deine Unterlagen" ist nicht mehr auffindbar.');
        $block = substr($blade, $vonNext, $this->bisZumEndeDesIfBlocks($blade, $vonNext) - $vonNext);

        return $this->rendere($rechnung . "\n" . $block, $variablen);
    }

    /**
     * Endposition hinter dem `</div>`, das auf die AUSGEGLICHENE Schliessung
     * der @if-Kette folgt. Ein naiver Schnitt auf das erste @endif haette
     * mitten im Block geendet (der Else-Zweig hat ein eigenes @if) und ein
     * Bruchstueck gerendert, das zufaellig noch kompiliert.
     */
    private function bisZumEndeDesIfBlocks(string $blade, int $von): int
    {
        $tiefe = 0;
        $ende = null;
        if (preg_match_all('/@(if|endif)\b/', $blade, $treffer, PREG_OFFSET_CAPTURE, $von)) {
            foreach ($treffer[1] as $i => [$wort, $wo]) {
                $tiefe += $wort === 'if' ? 1 : -1;
                if ($tiefe === 0) {
                    $ende = $treffer[0][$i][1] + strlen($treffer[0][$i][0]);
                    break;
                }
            }
        }
        $this->assertNotNull($ende, 'Keine ausgeglichene @if/@endif-Kette gefunden.');

        $schluss = strpos($blade, '</div>', $ende);
        $this->assertNotFalse($schluss);

        return $schluss + strlen('</div>');
    }

    /** Ein Blade-Stueck mit dem ECHTEN Compiler uebersetzen und ausfuehren. */
    private function rendere(string $ausschnitt, array $variablen): string
    {
        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $datei = $this->tmpDir . '/stueck-' . md5($ausschnitt) . '.php';
        file_put_contents($datei, $compiler->compileString($ausschnitt));

        $variablen['__datei'] = $datei;

        $lauf = static function (array $__v): string {
            extract($__v);
            ob_start();
            include $__v['__datei'];

            return (string) ob_get_clean();
        };

        return $lauf($variablen);
    }

    /** Der Start-Satz, gerendert aus den ECHTEN Ansichtsdaten dieses Menschen. */
    private function startSatz(RecEmployee $ma): string
    {
        $daten = $this->ansicht($this->shell($ma));

        return $this->rendereStart([
            'offen'           => $daten['offen'],
            'pflichtAufgaben' => $daten['pflichtAufgaben'],
            'aufgaben'        => $daten['aufgaben'],
            'duzen'           => true,
        ]);
    }

    // -----------------------------------------------------------------
    // (a) Die Pflichtangaben stehen im Offen-Zaehler
    // -----------------------------------------------------------------

    public function test_die_pflichtangaben_kommen_aus_den_waechtern_und_nicht_aus_einer_liste(): void
    {
        // Wer einen Waechter ergaenzt, ergaenzt ihn ueberall. Waere die
        // Anzeige-Menge abgetippt, wuerde dieser Test das nicht merken --
        // deshalb wird hier die Quelle selbst festgehalten.
        $this->assertSame(
            ['ersthelfer', 'staatsangehoerigkeit', 'hauptarbeitgeber'],
            array_keys(PortalProfileGuards::WAECHTER),
            'Die Waechter-Kaskade hat sich geaendert — die Pflichtangaben-Anzeige muss mitwandern.',
        );
    }

    public function test_fehlende_staatsangehoerigkeit_zaehlt_im_offen_wert(): void
    {
        $vollstaendig = $this->ansicht($this->shell($this->mitarbeiter()));
        $ohne = $this->ansicht($this->shell($this->mitarbeiter(['nationality' => null])));

        $this->assertSame(
            $vollstaendig['offen'] + 1,
            $ohne['offen'],
            '(a) Die Staatsangehoerigkeit kommt im Offen-Zaehler nicht vor.',
        );
        $this->assertSame(['nationality'], array_column($ohne['pflicht'], 'feld'));
    }

    public function test_unbeantwortete_arbeitgeber_frage_zaehlt_weiterhin(): void
    {
        $daten = $this->ansicht($this->shell($this->mitarbeiter(['is_main_employer' => null])));

        $this->assertSame(['is_main_employer'], array_column($daten['pflicht'], 'feld'));
        $this->assertSame(1, $daten['offen']);
        // Der gewachsene Satz mit der Steuerklasse bleibt -- er erklaert,
        // warum diese Zeile ganz oben steht.
        $this->assertStringContainsString('Steuerklasse', $daten['pflichtAufgaben'][0]['text']);
    }

    public function test_der_doppel_null_fall_zeigt_beide_pflichtangaben(): void
    {
        // Genau der Fall, der vor C1 das ganze Profil einfror. Er darf jetzt
        // speichern — aber beide Angaben muessen sichtbar bleiben.
        $daten = $this->ansicht($this->shell($this->mitarbeiter([
            'nationality'      => null,
            'is_main_employer' => null,
        ])));

        $this->assertSame(['nationality', 'is_main_employer'], array_column($daten['pflicht'], 'feld'));
        $this->assertSame(2, $daten['offen']);
    }

    public function test_die_ersthelfer_kopplung_zaehlt_mit(): void
    {
        // "Ja" mit Schein, aber OHNE Datum. Der Waechter blockt das Speichern
        // der Gruppe Arbeitsschutz -- die Nachweisliste sagt dagegen "liegt
        // vor", denn der Schein IST da. Genau diese Luecke hatte vorher gar
        // keinen Druck: kein Nachweis offen, kein Pflichtfeld im Zaehler.
        $ma = $this->mitarbeiter([
            'is_first_aider'                  => true,
            'first_aider_valid_until'         => null,
            'first_aider_certificate_file_id' => 4242,
        ]);
        $this->nachweis($ma, 'ersthelfer', null, 4242);

        $daten = $this->ansicht($this->shell($ma));

        $this->assertSame(
            [],
            array_column(array_filter($daten['aufgaben'], fn ($a) => $a['offen']), 'code'),
            'Der Schein liegt vor — die Nachweisliste darf nichts melden.',
        );
        $this->assertSame(['first_aider_valid_until'], array_column($daten['pflicht'], 'feld'));
        $this->assertSame(1, $daten['offen']);
    }

    public function test_wer_kein_ersthelfer_ist_bekommt_keine_pflichtangabe(): void
    {
        // "Bei nein passiert nichts" -- sonst haette jeder Nicht-Ersthelfer
        // dauerhaft zwei unerfuellbare Punkte im Zaehler.
        $nein = $this->ansicht($this->shell($this->mitarbeiter(['is_first_aider' => false])));
        $this->assertSame([], $nein['pflicht']);

        // Und auch das UNBEANTWORTETE "Ersthelfer?" ist keine Pflichtangabe:
        // der Waechter blockt dort nicht.
        $offen = $this->ansicht($this->shell($this->mitarbeiter(['is_first_aider' => null])));
        $this->assertSame([], $offen['pflicht']);
    }

    public function test_der_name_des_anderen_arbeitgebers_ist_nur_bei_nein_pflicht(): void
    {
        $nein = $this->ansicht($this->shell($this->mitarbeiter([
            'is_main_employer' => false,
            'other_employer'   => null,
        ])));
        $this->assertSame(['other_employer'], array_column($nein['pflicht'], 'feld'));

        $ja = $this->ansicht($this->shell($this->mitarbeiter([
            'is_main_employer' => true,
            'other_employer'   => null,
        ])));
        $this->assertSame([], $ja['pflicht']);
    }

    public function test_der_ersthelfer_schein_zaehlt_nicht_zweimal(): void
    {
        // Der Schein ist Pflichtangabe UND Nachweisaufgabe. Wer "Ja" sagt und
        // keinen Schein hat, hat EINE offene Sache, nicht zwei.
        $ma = $this->mitarbeiter([
            'is_first_aider'                  => true,
            'first_aider_valid_until'         => '2030-01-01',
            'first_aider_certificate_file_id' => null,
        ]);
        $daten = $this->ansicht($this->shell($ma));

        $codes = array_column(array_filter($daten['aufgaben'], fn ($a) => $a['offen']), 'code');
        $this->assertContains('ersthelfer', $codes, 'Der fehlende Schein steht gar nicht in der Aufgabenliste.');
        $this->assertSame(
            [],
            array_column($daten['pflicht'], 'feld'),
            'Der Schein wird zweimal gezaehlt — einmal als Nachweis, einmal als Pflichtangabe.',
        );
        $this->assertSame(1, $daten['offen']);
    }

    // -----------------------------------------------------------------
    // (b) Der Start-Bildschirm sagt nicht mehr "alles vollstaendig"
    // -----------------------------------------------------------------

    public function test_start_sagt_nicht_alles_vollstaendig_wenn_die_staatsangehoerigkeit_fehlt(): void
    {
        // DAS Szenario aus dem Befund: alle Nachweise da, Arbeitgeber
        // beantwortet, Staatsangehoerigkeit leer.
        $satz = $this->startSatz($this->mitarbeiter(['nationality' => null]));

        $this->assertStringNotContainsString('Alles vollständig', $satz);
        $this->assertStringNotContainsString('Wir haben alles, was wir von dir brauchen', $satz);
        $this->assertStringContainsString('Ein Punkt offen', $satz);
        $this->assertStringContainsString('Staatsangehörigkeit', $satz);
    }

    public function test_start_sagt_alles_vollstaendig_wenn_wirklich_alles_da_ist(): void
    {
        // Gegenprobe: ohne sie waere der Test oben auch gruen, wenn der Satz
        // nie erschiene.
        $satz = $this->startSatz($this->mitarbeiter());

        $this->assertStringContainsString('Alles vollständig', $satz);
        $this->assertStringContainsString('Wir haben alles, was wir von dir brauchen', $satz);
    }

    public function test_start_sagt_nicht_alles_vollstaendig_wenn_die_arbeitgeber_frage_offen_ist(): void
    {
        $satz = $this->startSatz($this->mitarbeiter(['is_main_employer' => null]));

        $this->assertStringNotContainsString('Alles vollständig', $satz);
        $this->assertStringContainsString('Hauptarbeitgeber', $satz);
    }

    // -----------------------------------------------------------------
    // (c) Der Ring nennt die Pflichtangaben IMMER
    // -----------------------------------------------------------------

    public function test_der_ring_nennt_die_pflichtangabe_auch_wenn_gekuerzt_wird(): void
    {
        // Die Staatsangehoerigkeit ist das LETZTE Feld der Gruppe Adresse.
        // Drei fruehere Luecken genuegten frueher, damit sie aus dem Satz
        // fiel -- hier sind es weit mehr als drei.
        $ma = $this->mitarbeiter([
            'nationality'   => null,
            'birth_name'    => null,
            'birth_place'   => null,
            'religion'      => null,
            'street'        => null,
            'house_number'  => null,
        ]);
        $daten = $this->ansicht($this->shell($ma));

        $satz = $this->ringSatz($daten);

        $this->assertStringContainsString('Staatsangehörigkeit', $satz, '(c) Die Pflichtangabe faellt aus dem Ring-Satz.');
        $this->assertStringContainsString('und weitere', $satz, 'Es wird gar nicht gekuerzt — der Test misst nichts.');
        // Und sie steht VORNE, nicht irgendwo.
        $this->assertStringStartsWith('Es fehlen noch: Staatsangehörigkeit', $satz);
    }

    /**
     * Baut den Ring-Satz mit DERSELBEN Rechnung wie das Blade -- aus dem
     * Blade geschnitten, nicht nachgebaut.
     */
    private function ringSatz(array $daten): string
    {
        $blade = $this->blade();
        $von = strpos($blade, '@php' . "\n" . '                    $prozent = $profilStand[');
        $this->assertNotFalse($von, 'Die Ring-Rechnung ist nicht mehr auffindbar.');
        $bis = strpos($blade, '@endphp', $von);
        $this->assertNotFalse($bis);
        $rechnung = substr($blade, $von, $bis + strlen('@endphp') - $von);

        return trim($this->rendere($rechnung . "\n{{ \$ringText }}", [
            'pflicht'     => $daten['pflicht'],
            'profilStand' => $daten['profilStand'],
            'duzen'       => true,
        ]));
    }

    // -----------------------------------------------------------------
    // (d) Der rote Punkt unterscheidet Pflicht von Kosmetik
    // -----------------------------------------------------------------

    public function test_der_gruppenpunkt_unterscheidet_pflicht_von_kosmetik(): void
    {
        $ma = $this->mitarbeiter([
            'nationality' => null,   // Pflicht, Gruppe "Adresse"
            'birth_name'  => null,   // folgenlos, Gruppe "Persoenliches"
        ]);
        $gruppen = $this->ansicht($this->shell($ma))['profilGruppen'];

        $this->assertSame('crit', $gruppen['Adresse']['punkt'], 'Die blockierende Luecke sieht aus wie jede andere.');
        $this->assertTrue($gruppen['Adresse']['pflicht']);

        $this->assertGreaterThan(0, $gruppen['Persoenliches']['offen']);
        $this->assertSame('warn', $gruppen['Persoenliches']['punkt'], 'Ein fehlender Geburtsname wiegt so schwer wie eine Pflichtangabe.');
        $this->assertFalse($gruppen['Persoenliches']['pflicht']);
    }
}
