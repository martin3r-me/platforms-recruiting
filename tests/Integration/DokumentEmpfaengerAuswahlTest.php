<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Documents;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentEmpfaengerSuche;
use Platform\Recruiting\Services\DokumentService;

/**
 * Empfaengerwahl der Seite Dokumente ist OPT-IN: niemand ist vorausgewaehlt,
 * Haken bleiben ueber Suchen hinweg stehen, versendet wird nur nach der
 * Rueckfrage und nur an die team-geprueften ids der Auswahl.
 */
final class DokumentEmpfaengerAuswahlTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private string $pdfPfad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $container = Container::getInstance();
        $container->singleton(\Livewire\EventBus::class);
        $container->instance(AuthFactory::class, new class implements AuthFactory {
            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function user(): object
            {
                return (object) ['id' => 7, 'currentTeam' => (object) ['id' => 3]];
            }

            public function id(): int
            {
                return 7;
            }
        });
        $container->instance(DokumentService::class, new DokumentService($this->speicher));
        $this->pdfPfad = $this->root . '/upload.pdf';
        file_put_contents($this->pdfPfad, self::PDF);
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance(AuthFactory::class);
        $c->forgetInstance(DokumentService::class);
        $c->forgetInstance(\Livewire\EventBus::class);
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function seite(): Documents
    {
        $s = new Documents();
        ComputedWiring::wire($s);

        return $s;
    }

    private function datei(): object
    {
        return new class($this->pdfPfad) {
            public function __construct(private string $pfad)
            {
            }

            public function getRealPath(): string
            {
                return $this->pfad;
            }

            public function getClientOriginalName(): string
            {
                return 'Hausordnung.pdf';
            }
        };
    }

    private function suchen(Documents $s, string $suche): void
    {
        $s->suche = $suche;
        $s->updated('suche');
    }

    public function test_startet_ohne_auswahl_und_ohne_rueckfrage(): void
    {
        $this->anstellung();
        $this->anstellung(['first_name' => 'Ben']);
        $s = $this->seite();

        $this->assertSame([], $s->ausgewaehlt);
        $this->assertSame([], $s->gewaehlteIds());
        $this->assertSame([], $s->ausgewaehltZeilen());
        $this->assertCount(2, $s->kandidaten(), 'die Treffer sind sichtbar, aber nicht angehakt');

        $s->datei = $this->datei();
        $s->titel = 'Hausordnung';
        $s->bereitstellenPruefen();
        $this->assertFalse($s->bestaetigungOffen, 'bei 0 Empfaengern keine Rueckfrage');
        $this->assertSame('Bitte mindestens einen Empfänger wählen.', $s->flashError);

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/documents.blade.php');
        $this->assertStringContainsString('$sendenGesperrt = $gewaehlt === 0;', $blade);
        $this->assertStringContainsString('wire:click="bereitstellenPruefen" wire:loading.attr="disabled" wire:target="bereitstellenPruefen,bereitstellen,datei" @disabled($sendenGesperrt)', $blade);
        $this->assertStringContainsString('An {{ $gewaehlt }} Personen bereitstellen', $blade);
        $this->assertStringNotContainsString('waehlerOffen', $blade, 'kein Einklappen der Trefferliste mehr');
    }

    public function test_einzeln_an_und_ab(): void
    {
        $a = $this->anstellung();
        $b = $this->anstellung(['first_name' => 'Ben']);
        $s = $this->seite();

        $s->toggle((int) $b->id);
        $this->assertSame([(int) $b->id], $s->gewaehlteIds());
        $s->toggle((int) $a->id);
        $this->assertSame([(int) $a->id, (int) $b->id], $s->gewaehlteIds(), 'sortiert nach Name');
        $s->toggle((int) $b->id);
        $this->assertSame([(int) $a->id], $s->gewaehlteIds());
    }

    /** 29.10.: ein Mensch mit RG und MA ist ein Haken; Anhaken ueber die zweite Anstellung landet beim Traeger. */
    public function test_ein_mensch_ist_ein_haken(): void
    {
        $rg = $this->anstellung(['first_name' => 'Clara', 'last_name' => 'Domes', 'company' => 'RG', 'personnel_number' => 'RG1', 'rec_person_id' => 5]);
        $ma = $this->anstellung(['first_name' => 'Clara', 'last_name' => 'Domes', 'company' => 'MA', 'personnel_number' => 'MA2', 'rec_person_id' => 5]);
        $s = $this->seite();

        $this->assertCount(1, $s->kandidaten());
        $s->ausgewaehlt = [(int) $ma->id, (int) $rg->id];
        $s->updated('ausgewaehlt');
        $this->assertSame([(int) $rg->id], $s->gewaehlteIds());
        $this->assertCount(1, $s->ausgewaehltZeilen());
        $s->toggle((int) $rg->id);
        $this->assertSame([], $s->gewaehlteIds(), 'Abhaken nimmt die ganze Person heraus');

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/documents.blade.php');
        $this->assertStringContainsString("{{ \$k['nummern'] }}", $blade);
    }

    /** Unter „Firma: MA“ angehakt → bleibt die MA-Anstellung, auch nach dem Filterwechsel; der Haken steht trotzdem. */
    public function test_gewaehlte_anstellung_bleibt_ueber_filterwechsel(): void
    {
        $rg = $this->anstellung(['first_name' => 'Mia', 'company' => 'RG', 'personnel_number' => 'RG7', 'rec_person_id' => 9]);
        $ma = $this->anstellung(['first_name' => 'Mia', 'company' => 'MA', 'personnel_number' => 'MA143424', 'rec_person_id' => 9]);
        $s = $this->seite();
        $s->firma = 'MA';
        $s->updated('firma');
        $s->toggle((int) $s->kandidaten()[0]['id']);
        $this->assertSame([(int) $ma->id], $s->gewaehlteIds());

        $s->firma = '';
        $s->updated('firma');
        $this->assertSame([(int) $ma->id], $s->gewaehlteIds(), 'Filterwechsel aendert die Wahl nicht');
        $this->assertSame((int) $rg->id, $s->kandidaten()[0]['id']);
        $s->toggle((int) $rg->id);
        $this->assertSame([], $s->gewaehlteIds(), 'Haken ueber die andere Anstellung nimmt die Person heraus');

        $s->toggle((int) $rg->id);
        $s->entfernen((int) $rg->id);
        $this->assertSame([], $s->gewaehlteIds());
    }

    /** Review 29.10.: "Alle Treffer" unter Firma MA uebernimmt die MA-Anstellung, auch wenn die Person vorher ueber RG gewaehlt war. */
    public function test_alle_treffer_uebernimmt_die_wahl_des_filters(): void
    {
        $rg = $this->anstellung(['first_name' => 'Mia', 'company' => 'RG', 'rec_person_id' => 9]);
        $ma = $this->anstellung(['first_name' => 'Mia', 'company' => 'MA', 'rec_person_id' => 9]);
        $s = $this->seite();
        $s->toggle((int) $rg->id);
        $s->firma = 'MA';
        $s->updated('firma');
        $s->alleTrefferAn();

        $this->assertSame([(int) $ma->id], $s->gewaehlteIds());
    }

    public function test_alle_treffer_an_und_ab_betrifft_nur_die_sichtbaren_treffer(): void
    {
        $anna = $this->anstellung();
        $ben = $this->anstellung(['first_name' => 'Ben', 'last_name' => 'Berg']);
        $bea = $this->anstellung(['first_name' => 'Bea', 'last_name' => 'Berg']);
        $s = $this->seite();

        $s->toggle((int) $anna->id);
        $this->suchen($s, 'Berg');
        $s->alleTrefferAn();
        $this->assertEqualsCanonicalizing([(int) $anna->id, (int) $ben->id, (int) $bea->id], $s->gewaehlteIds());

        $s->alleTrefferAb();
        $this->assertSame([(int) $anna->id], $s->gewaehlteIds(), 'Anna stammt aus einer frueheren Suche und bleibt');
    }

    public function test_alle_treffer_gesperrt_wenn_die_liste_abgeschnitten_ist(): void
    {
        $zeilen = [];
        for ($i = 0; $i <= DokumentEmpfaengerSuche::LIMIT; $i++) {
            $zeilen[] = ['team_id' => 3, 'uuid' => 'viele-' . $i, 'first_name' => 'P' . $i, 'last_name' => 'Viele', 'is_active' => true];
        }
        foreach (array_chunk($zeilen, 100) as $paket) {
            DB::table('rec_employees')->insert($paket);
        }
        $s = $this->seite();

        $this->assertTrue($s->abgeschnitten());
        $s->alleTrefferAn();
        $this->assertSame([], $s->ausgewaehlt);
        $this->assertStringContainsString('bitte Filter eingrenzen', (string) $s->flashError);
    }

    public function test_auswahl_bleibt_ueber_suchwechsel_stehen(): void
    {
        $anna = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'Alpha']);
        $ben = $this->anstellung(['first_name' => 'Ben', 'last_name' => 'Beta']);
        $s = $this->seite();

        $this->suchen($s, 'Alpha');
        $this->assertSame([(int) $anna->id], array_column($s->kandidaten(), 'id'));
        $s->toggle((int) $anna->id);

        $this->suchen($s, 'Beta');
        $this->assertSame([(int) $ben->id], array_column($s->kandidaten(), 'id'));
        $s->toggle((int) $ben->id);

        $s->firma = 'MA';
        $s->updated('firma');

        $this->assertSame([(int) $anna->id, (int) $ben->id], $s->gewaehlteIds());
        $this->assertSame(['Anna Alpha', 'Ben Beta'], array_column($s->ausgewaehltZeilen(), 'name'));
    }

    public function test_entfernen_und_leeren(): void
    {
        $a = $this->anstellung();
        $b = $this->anstellung(['first_name' => 'Ben']);
        $s = $this->seite();
        $s->alleTrefferAn();

        $s->entfernen((int) $a->id);
        $this->assertSame([(int) $b->id], $s->gewaehlteIds());

        $s->toggle((int) $a->id);
        $s->auswahlLeeren();
        $this->assertSame([], $s->ausgewaehlt);
    }

    public function test_bereitstellen_ohne_rueckfrage_tut_nichts(): void
    {
        $a = $this->anstellung();
        $s = $this->seite();
        $s->toggle((int) $a->id);
        $s->datei = $this->datei();
        $s->titel = 'Hausordnung';

        $s->bereitstellen();

        $this->assertSame(0, RecDocument::query()->count());
        $this->assertSame([(int) $a->id], $s->ausgewaehlt, 'nichts zurueckgesetzt');
    }

    public function test_bereitstellen_sendet_nur_an_die_gepruefte_auswahl(): void
    {
        $anna = $this->anstellung();
        $ben = $this->anstellung(['first_name' => 'Ben']);
        $nichtGewaehlt = $this->anstellung(['first_name' => 'Cleo']);
        $fremd = $this->anstellung(['first_name' => 'Fremd', 'team_id' => 4]);
        $s = $this->seite();
        $s->toggle((int) $anna->id);
        $s->toggle((int) $ben->id);
        // Browser-Eingabe: fremdes Team und eine unbekannte id untergeschoben.
        $s->ausgewaehlt = [...$s->ausgewaehlt, (int) $fremd->id, 999999];
        $s->datei = $this->datei();
        $s->titel = 'Hausordnung';

        $this->assertSame([(int) $anna->id, (int) $ben->id], $s->gewaehlteIds(), 'Anzeige zaehlt nur Team-Mitarbeiter');
        $this->assertSame(['Anna Test', 'Ben Test'], array_column($s->ausgewaehltZeilen(), 'name'));

        $s->bereitstellenPruefen();
        $this->assertTrue($s->bestaetigungOffen);
        $s->bereitstellen();

        $this->assertNull($s->flashError);
        $this->assertSame(1, RecDocument::query()->count());
        $empfaenger = RecDocumentRecipient::query()->orderBy('rec_employee_id')->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([(int) $anna->id, (int) $ben->id], $empfaenger);
        $this->assertNotContains((int) $nichtGewaehlt->id, $empfaenger);
        $this->assertNotContains((int) $fremd->id, $empfaenger);

        $this->assertSame([], $s->ausgewaehlt, 'nach dem Versand ist die Auswahl leer');
        $this->assertNull($s->datei);
        $this->assertSame('', $s->titel);
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('An 2 Personen abgelegt.', $s->flash);
    }

    public function test_rueckfrage_braucht_datei_und_titel(): void
    {
        $a = $this->anstellung();
        $s = $this->seite();
        $s->toggle((int) $a->id);

        $s->bereitstellenPruefen();
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('Bitte eine PDF auswählen.', $s->flashError);

        $s->datei = $this->datei();
        $s->bereitstellenPruefen();
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('Bitte einen Titel angeben.', $s->flashError);
    }

    public function test_rueckfrage_im_blade_nennt_titel_anzahl_altes_portal_und_aktion(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/documents.blade.php');
        $this->assertStringContainsString('<x-ui-modal wire:model="bestaetigungOffen"', $blade);
        $this->assertStringContainsString('„{{ $bestaetigungTitel }}“ an {{ $gewaehlt }} Personen bereitstellen?', $blade);
        $this->assertStringContainsString('{{ $ohnePortal }} davon im alten Portal oder inaktiv (keine WhatsApp).', $blade);
        $this->assertStringContainsString('Der Mitarbeiter soll: {{ $aktionLabel }}.', $blade);
        $this->assertStringContainsString('wire:click="bereitstellen"', $blade);
        $this->assertStringContainsString("wire:click=\"\$set('bestaetigungOffen', false)\">Abbrechen", $blade);
        $this->assertStringContainsString('Alle Treffer auswählen', $blade);
        $this->assertStringContainsString('Auswahl leeren', $blade);
        $this->assertStringContainsString('davon im alten Portal oder inaktiv — bekommen keine WhatsApp', $blade);
    }
}
