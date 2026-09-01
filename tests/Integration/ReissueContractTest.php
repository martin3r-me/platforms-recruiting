<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Services\ReissueContractService;

/**
 * ReissueContractService — "Vertrag neu ausstellen".
 *
 * WOGEGEN DIESE TESTS SCHUETZEN: der teuerste Fehler in diesem Bereich ist
 * nicht ein fehlender Nachfolger, sondern ein VERAENDERTER VORGAENGER. Ein
 * unterschriebener Arbeitsvertrag ist ein Beleg; wird sein
 * personalized_content neu gerendert, springt das Datum im Dokument auf
 * heute und die Lohnwerte auf die heutigen (genau das tut der "Felder"-Button
 * in der Bewerber-Akte, siehe Applicant/Show::saveContractFields). Fall 2
 * nagelt deshalb Zeichen fuer Zeichen fest, dass der alte Vertrag bis auf
 * notes und superseded_by_contract_id unberuehrt bleibt.
 *
 * Harness wie in PlaceholderResolutionPinTest: handgebauter Container plus
 * Capsule, Schema aus den ECHTEN Migrationen. Kein testbench im Modul.
 */
class ReissueContractTest extends TestCase
{
    private const TEAM = 7;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        // LogsActivity (CrmContact) verlangt config(); leere Events = keine Hooks.
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher feuern die creating-Hooks nicht (uuid, public_token)
        // — das echte Schema verlangt sie als NOT NULL.
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        // HasPublicFormLink::getOrCreatePublicFormLink und CrmContactLink::creating
        // fragen auth() — Stub ohne User.
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
    }

    // -----------------------------------------------------------------
    // Faelle
    // -----------------------------------------------------------------

    /**
     * FALL 1 — der Nachfolger traegt den NEUEN Betrag.
     *
     * Das ist die ganze fachliche Begruendung des Features: {{zuschlag}}
     * haengt an applicant.zuschlag und wird bei jedem Rendern neu aufgeloest.
     * Wer die Zuschlagsquelle spaeter auf einen Snapshot umstellt, muss hier
     * rot werden.
     */
    public function test_nachfolger_traegt_den_neuen_zuschlag(): void
    {
        [$applicant, $template, $old] = $this->signedAvFixture(0.60);

        $result = (new ReissueContractService())->reissue(
            $old, 1.60, ReissueContractService::REASON_CORRECTION
        );
        $new = $result['contract'];

        $this->assertStringContainsString('Zuschlag: 1,60', $new->personalized_content);
        $this->assertStringNotContainsString('0,60', $new->personalized_content);
        $this->assertSame('sent', $new->status);
        $this->assertNotNull($new->sent_at);
        $this->assertNull($new->signed_at, 'Ein frisch ausgestellter Vertrag ist nicht unterschrieben.');
        $this->assertSame($template->id, $new->rec_contract_template_id);
        $this->assertSame(1.60, (float) $applicant->fresh()->zuschlag);
    }

    /**
     * FALL 2 — der wertvollste Fall: der Vorgaenger bleibt Beleg.
     */
    public function test_vorgaenger_bleibt_bis_auf_verweis_und_notiz_unberuehrt(): void
    {
        [, , $old] = $this->signedAvFixture(0.60);

        $contentBefore = $old->personalized_content;
        $signatureBefore = $old->signature_data;
        $signedAtBefore = $old->signed_at->toIso8601String();
        $completedAtBefore = $old->completed_at->toIso8601String();

        $result = (new ReissueContractService())->reissue(
            $old, 1.60, ReissueContractService::REASON_CORRECTION
        );

        $reloaded = RecContract::find($old->id);
        $this->assertSame($contentBefore, $reloaded->personalized_content,
            'Der Vertragstext eines unterschriebenen Vertrags darf sich NIE aendern.');
        $this->assertSame($signatureBefore, $reloaded->signature_data);
        $this->assertSame($signedAtBefore, $reloaded->signed_at->toIso8601String());
        $this->assertSame($completedAtBefore, $reloaded->completed_at->toIso8601String());
        $this->assertSame('completed', $reloaded->status);

        $this->assertSame($result['contract']->id, $reloaded->superseded_by_contract_id);
        $this->assertStringContainsString('Ersetzt am', (string) $reloaded->notes);
        $this->assertStringContainsString('0,60 → 1,60', (string) $reloaded->notes);
    }

    /**
     * FALL 3 — kein IFSG, kein Zusatzvertrag, kein zweiter Automatismus.
     * Genau zwei Vertraege beim Bewerber: der alte und der neue.
     */
    public function test_haengt_nichts_zusaetzliches_an(): void
    {
        [$applicant, , $old] = $this->signedAvFixture(0.60);
        $this->makeContract($applicant, $this->makeTemplate('IFSG', '<p>Belehrung</p>', []), [
            'status'       => 'completed',
            'signed_at'    => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        (new ReissueContractService())->reissue($old, 1.60, ReissueContractService::REASON_CORRECTION);

        $this->assertSame(3, RecContract::where('rec_applicant_id', $applicant->id)->count(),
            'Alter AV + signierter IFSG + neuer AV — kein zweiter IFSG.');
        $this->assertSame(1, RecContract::where('rec_applicant_id', $applicant->id)
            ->whereHas('contractTemplate', fn ($q) => $q->where('code', 'IFSG'))
            ->count());
    }

    /** FALL 4 — Erhoehung landet in den Lohnaenderungen. */
    public function test_erhoehung_meldet_dem_lohnbuero(): void
    {
        [$applicant, , $old] = $this->signedAvFixture(0.60);
        $employeeId = $this->makeEmployee($applicant);

        $result = (new ReissueContractService())->reissue(
            $old, 1.60, ReissueContractService::REASON_RAISE
        );

        $this->assertTrue($result['payroll_reported']);

        $row = DB::table('rec_employees')->where('id', $employeeId)->first();
        $this->assertNotNull($row->payroll_data_changed_at);
        $entries = json_decode($row->payroll_data_changed_fields, true);
        $this->assertCount(1, $entries);
        $this->assertSame('zuschlag', $entries[0]['field']);
        $this->assertSame('0,60', $entries[0]['old']);
        $this->assertSame('1,60', $entries[0]['new']);
    }

    /**
     * FALL 5 — Korrektur meldet NICHT.
     *
     * Der ersetzte Vertrag ist nie wirksam geworden; das Lohnbuero hat den
     * alten Betrag nie abgerechnet. Eine Meldung "0,60 → 1,60" wuerde dort
     * eine Aenderung behaupten, die es nicht gab.
     */
    public function test_korrektur_meldet_nicht(): void
    {
        [$applicant, , $old] = $this->signedAvFixture(0.60);
        $employeeId = $this->makeEmployee($applicant);

        $result = (new ReissueContractService())->reissue(
            $old, 1.60, ReissueContractService::REASON_CORRECTION
        );

        $this->assertFalse($result['payroll_reported']);
        $row = DB::table('rec_employees')->where('id', $employeeId)->first();
        $this->assertNull($row->payroll_data_changed_at);
        $this->assertNull($row->payroll_data_changed_fields);
    }

    /** FALL 6 — zweimal ersetzen geht nicht. */
    public function test_bereits_ersetzter_vertrag_wird_abgelehnt(): void
    {
        [, , $old] = $this->signedAvFixture(0.60);
        (new ReissueContractService())->reissue($old, 1.60, ReissueContractService::REASON_CORRECTION);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/bereits durch #\d+ ersetzt/');
        (new ReissueContractService())->reissue(
            RecContract::find($old->id), 2.10, ReissueContractService::REASON_CORRECTION
        );
    }

    /**
     * FALL 7 — Altbestands-Variante mit literal eingebackenem Betrag wird
     * abgelehnt, statt still ein textgleiches Dokument auszustellen.
     */
    public function test_variante_mit_festem_betrag_wird_abgelehnt(): void
    {
        $applicant = $this->makeApplicant(0.60);
        // Wie AV-060 nach CreateArbeitsvertragVariants: Betrag im Text,
        // zuschlag-Mapping entfernt.
        $variant = $this->makeTemplate('AV-060', '<p>Zuschlag: 0,60 €</p>', ['heute' => 'meta.datum_heute']);
        $old = $this->makeContract($applicant, $variant, [
            'status'       => 'completed',
            'signed_at'    => now()->subDays(2),
            'completed_at' => now()->subDays(2),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/loest \{\{zuschlag\}\} nicht/');
        (new ReissueContractService())->reissue($old, 1.60, ReissueContractService::REASON_CORRECTION);
    }

    /**
     * FALL 8 — ein noch offener Vertrag ist kein Fall fuer diesen Weg. Dafuer
     * gibt es die Duplikat-Pruefung in assignContract(), die den offenen
     * storniert. Ein signierter darf so nie behandelt werden.
     */
    public function test_offener_vertrag_wird_abgelehnt(): void
    {
        $applicant = $this->makeApplicant(0.60);
        $template = $this->avDefaultTemplate();
        $open = $this->makeContract($applicant, $template, ['status' => 'sent', 'sent_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nur ein unterschriebener Vertrag/');
        (new ReissueContractService())->reissue($open, 1.60, ReissueContractService::REASON_CORRECTION);
    }

    /**
     * FALL 9 — das Praedikat hinter dem IFSG-Auto-Anhaenger.
     *
     * hasNonCancelledForTemplate() muss einen COMPLETED Vertrag sehen. Die
     * alte Fassung fragte nur nach pending/sent/in_progress und legte deshalb
     * ein zweites Exemplar an, das der Mitarbeiter erneut unterschreiben
     * sollte. Storniertes zaehlt weiter nicht.
     */
    public function test_praedikat_sieht_unterschriebene_und_ignoriert_stornierte(): void
    {
        $applicant = $this->makeApplicant(0.60);
        $ifsg = $this->makeTemplate('IFSG', '<p>Belehrung</p>', []);

        $this->assertFalse(RecContract::hasNonCancelledForTemplate($applicant->id, $ifsg->id));

        $contract = $this->makeContract($applicant, $ifsg, [
            'status'       => 'completed',
            'signed_at'    => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        $this->assertTrue(RecContract::hasNonCancelledForTemplate($applicant->id, $ifsg->id),
            'Ein unterschriebener IFSG muss den Auto-Anhaenger stoppen.');

        $contract->update(['status' => 'cancelled']);
        $this->assertFalse(RecContract::hasNonCancelledForTemplate($applicant->id, $ifsg->id),
            'Ein stornierter Vertrag zaehlt nicht.');
    }

    // -----------------------------------------------------------------
    // reissueOpen() — derselbe Handgriff VOR der Unterschrift
    // -----------------------------------------------------------------

    /**
     * FALL 10 — der Regelfall aus der Praxis: der Vertrag ist raus, noch
     * nicht unterschrieben, und der Lohn soll hoeher sein. Der Nachfolger
     * traegt den neuen Betrag, der Vorgaenger ist storniert.
     *
     * Warum storniert und nicht ueberschrieben: der Signaturlink zeigt auf
     * das eingefrorene personalized_content. Wer den Text unter einem
     * lebenden Link austauscht, laesst den Bewerber etwas anderes
     * unterschreiben als das, was er geoeffnet hat. ContractSigning laesst
     * nur status="sent" durch — die Stornierung macht den alten Link tot.
     */
    public function test_offener_vertrag_wird_durch_neuen_mit_neuem_zuschlag_ersetzt(): void
    {
        [$applicant, $template, $open] = $this->openAvFixture(0.60);

        $result = (new ReissueContractService())->reissueOpen($open, 1.60);
        $new = $result['contract'];

        $this->assertStringContainsString('Zuschlag: 1,60', $new->personalized_content);
        $this->assertStringNotContainsString('0,60', $new->personalized_content);
        $this->assertSame('sent', $new->status);
        $this->assertNotNull($new->sent_at);
        $this->assertNull($new->signed_at);
        $this->assertSame($template->id, $new->rec_contract_template_id);
        $this->assertSame(1.60, (float) $applicant->fresh()->zuschlag);

        $reloaded = RecContract::find($open->id);
        $this->assertSame('cancelled', $reloaded->status,
            'Der alte Vertrag muss storniert sein — sonst bleibt sein Signaturlink offen.');
        $this->assertSame($new->id, $reloaded->superseded_by_contract_id);
        $this->assertStringContainsString('0,60 → 1,60', (string) $reloaded->notes);
        $this->assertStringContainsString('vor Unterschrift', (string) $reloaded->notes);
    }

    /**
     * FALL 11 — kein Wort ans Lohnbuero.
     *
     * Der stornierte Vertrag ist nie wirksam geworden; abgerechnet wurde
     * nach ihm nie. Eine Meldung "0,60 → 1,60" wuerde dort eine Aenderung
     * behaupten, die es nicht gab — deshalb kennt dieser Weg auch keinen
     * Grund zum Auswaehlen.
     */
    public function test_offener_vertrag_meldet_nie_dem_lohnbuero(): void
    {
        [$applicant, , $open] = $this->openAvFixture(0.60);
        $employeeId = $this->makeEmployee($applicant);

        $result = (new ReissueContractService())->reissueOpen($open, 1.60);

        $this->assertFalse($result['payroll_reported']);
        $row = DB::table('rec_employees')->where('id', $employeeId)->first();
        $this->assertNull($row->payroll_data_changed_at);
        $this->assertNull($row->payroll_data_changed_fields);
    }

    /** FALL 12 — auch hier haengt sich kein zweiter IFSG an. */
    public function test_offener_vertrag_haengt_nichts_zusaetzliches_an(): void
    {
        [$applicant, , $open] = $this->openAvFixture(0.60);
        $this->makeContract($applicant, $this->makeTemplate('IFSG', '<p>Belehrung</p>', []), [
            'status'  => 'sent',
            'sent_at' => now()->subDay(),
        ]);

        (new ReissueContractService())->reissueOpen($open, 1.60);

        $this->assertSame(3, RecContract::where('rec_applicant_id', $applicant->id)->count(),
            'Alter AV (storniert) + offener IFSG + neuer AV — kein zweiter IFSG.');
    }

    /**
     * FALL 13 — der Vertragsbeginn des Vorgaengers wandert mit.
     *
     * Er haengt an contract.extra_field.vertragsbeginn, nicht am Bewerber:
     * ohne Uebernahme rendert der Nachfolger ein leeres Datum, und HR
     * merkt es erst am fertigen Dokument.
     */
    public function test_offener_vertrag_uebernimmt_den_vertragsbeginn(): void
    {
        $this->ensureContractDateDefinitions();
        [, , $open] = $this->openAvFixture(0.60);
        $open->setExtraField('vertragsbeginn', '2026-10-01');

        $new = (new ReissueContractService())->reissueOpen($open, 1.60)['contract'];

        $this->assertSame('2026-10-01', $new->getExtraField('vertragsbeginn'));
    }

    /** FALL 14 — ein ausdruecklich mitgegebener Beginn schlaegt den alten. */
    public function test_offener_vertrag_nimmt_den_uebergebenen_vertragsbeginn(): void
    {
        $this->ensureContractDateDefinitions();
        [, , $open] = $this->openAvFixture(0.60);
        $open->setExtraField('vertragsbeginn', '2026-10-01');

        $new = (new ReissueContractService())->reissueOpen($open, 1.60, '2026-11-15')['contract'];

        $this->assertSame('2026-11-15', $new->getExtraField('vertragsbeginn'));
    }

    /**
     * FALL 15 — ein unterschriebener Vertrag darf hier NIE durch.
     *
     * Dieser Weg storniert den Vorgaenger. Auf einen Beleg angewendet
     * hiesse das: eine geleistete Unterschrift wird entwertet. Dafuer gibt
     * es reissue(), das den Vorgaenger unangetastet laesst.
     */
    public function test_unterschriebener_vertrag_wird_von_reissue_open_abgelehnt(): void
    {
        [, , $signed] = $this->signedAvFixture(0.60);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/bereits unterschrieben/');
        (new ReissueContractService())->reissueOpen($signed, 1.60);
    }

    /** FALL 16 — ein stornierter Vertrag ist nichts, was man ersetzt. */
    public function test_stornierter_vertrag_wird_von_reissue_open_abgelehnt(): void
    {
        [, , $open] = $this->openAvFixture(0.60);
        $open->update(['status' => 'cancelled']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/storniert/');
        (new ReissueContractService())->reissueOpen($open, 1.60);
    }

    /** FALL 17 — die Vorlagen-Pruefung gilt vor der Unterschrift genauso. */
    public function test_offene_variante_mit_festem_betrag_wird_abgelehnt(): void
    {
        $applicant = $this->makeApplicant(0.60);
        $variant = $this->makeTemplate('AV-060', '<p>Zuschlag: 0,60 €</p>', ['heute' => 'meta.datum_heute']);
        $open = $this->makeContract($applicant, $variant, ['status' => 'sent', 'sent_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/loest \{\{zuschlag\}\} nicht/');
        (new ReissueContractService())->reissueOpen($open, 1.60);
    }

    // -----------------------------------------------------------------
    // Vertragsende — der Wert des Vorgaengers ist die Vorgabe
    // -----------------------------------------------------------------

    /**
     * FALL 18 — ein von Hand gesetztes Ende ueberlebt das Neu-Ausstellen.
     *
     * resolveContractDates() rechnet aus dem Beginn ein Ende (+1 Jahr,
     * Monatsanfang, −1 Tag), sobald keins mitkommt. Wurde am Vorgaenger eine
     * abweichende Befristung eingetragen — Saisonende, 70-Tage-Vertrag —,
     * ersetzte diese Automatik sie stillschweigend: der Nachfolger lief
     * ploetzlich laenger als vereinbart, sichtbar nur im fertigen Dokument.
     */
    public function test_offener_vertrag_uebernimmt_ein_abweichendes_vertragsende(): void
    {
        $this->ensureContractDateDefinitions();
        [, , $open] = $this->openAvFixture(0.60);
        $open->setExtraField('vertragsbeginn', '2026-09-01');
        $open->setExtraField('vertragsende', '2026-12-31');

        $new = (new ReissueContractService())->reissueOpen($open, 1.60)['contract'];

        $this->assertSame('2026-12-31', $new->getExtraField('vertragsende'),
            'Die Befristung des Vorgaengers darf nicht von der Automatik ueberschrieben werden.');
    }

    /** FALL 19 — ein ausdruecklich mitgegebenes Ende schlaegt das alte. */
    public function test_offener_vertrag_nimmt_das_uebergebene_vertragsende(): void
    {
        $this->ensureContractDateDefinitions();
        [, , $open] = $this->openAvFixture(0.60);
        $open->setExtraField('vertragsbeginn', '2026-09-01');
        $open->setExtraField('vertragsende', '2026-12-31');

        $new = (new ReissueContractService())->reissueOpen($open, 1.60, null, '2027-03-31')['contract'];

        $this->assertSame('2027-03-31', $new->getExtraField('vertragsende'));
    }

    /**
     * FALL 20 — ohne Ende am Vorgaenger bleibt es bei der Automatik.
     * Der Regelfall darf sich durch die Uebernahme nicht aendern.
     */
    public function test_ohne_vertragsende_rechnet_die_automatik_weiter(): void
    {
        $this->ensureContractDateDefinitions();
        [, , $open] = $this->openAvFixture(0.60);
        $open->setExtraField('vertragsbeginn', '2026-09-01');

        $new = (new ReissueContractService())->reissueOpen($open, 1.60)['contract'];

        $this->assertSame('2027-08-31', $new->getExtraField('vertragsende'));
    }

    /** FALL 21 — derselbe Schutz gilt am unterschriebenen Vertrag. */
    public function test_unterschriebener_vertrag_uebernimmt_ein_abweichendes_vertragsende(): void
    {
        $this->ensureContractDateDefinitions();
        [, , $old] = $this->signedAvFixture(0.60);
        $old->setExtraField('vertragsbeginn', '2026-09-01');
        $old->setExtraField('vertragsende', '2026-12-31');

        $new = (new ReissueContractService())->reissue(
            $old, 1.60, ReissueContractService::REASON_CORRECTION
        )['contract'];

        $this->assertSame('2026-12-31', $new->getExtraField('vertragsende'));
    }

    // -----------------------------------------------------------------
    // Fixtures — legen pro Test neue Zeilen an, loeschen nichts (HasExtraFields
    // cacht Definitionen statisch unter "Klasse:id"; wiederverwendete IDs nach
    // einem delete() liessen einen Test den Definitionssatz eines anderen sehen).
    // -----------------------------------------------------------------

    /** @return array{0: RecApplicant, 1: RecContractTemplate, 2: RecContract} */
    private function signedAvFixture(float $zuschlag): array
    {
        $applicant = $this->makeApplicant($zuschlag);
        $template = $this->avDefaultTemplate();
        $contract = $this->makeContract($applicant, $template, [
            'status'               => 'completed',
            'signed_at'            => now()->subDays(2),
            'completed_at'         => now()->subDays(2),
            'sent_at'              => now()->subDays(3),
            'signature_data'       => 'data:image/png;base64,AAAA',
            'personalized_content' => $template->personalizeContent($applicant),
        ]);

        return [$applicant, $template, $contract];
    }

    /** @return array{0: RecApplicant, 1: RecContractTemplate, 2: RecContract} */
    private function openAvFixture(float $zuschlag): array
    {
        $applicant = $this->makeApplicant($zuschlag);
        $template = $this->avDefaultTemplate();
        $contract = $this->makeContract($applicant, $template, [
            'status'               => 'sent',
            'sent_at'              => now()->subDays(3),
            'personalized_content' => $template->personalizeContent($applicant),
        ]);

        return [$applicant, $template, $contract];
    }

    /**
     * Die Definitionen, die SeedRecContractExtraFields live anlegt. Ohne sie
     * ist setExtraField() ein stiller No-Op — ein Test ohne diese Zeile
     * pruefte nur, dass nichts passiert.
     */
    private function ensureContractDateDefinitions(): void
    {
        foreach ([['vertragsbeginn', 'Vertragsbeginn', 10], ['vertragsende', 'Vertragsende', 20]] as [$name, $label, $order]) {
            if (CoreExtraFieldDefinition::where('team_id', self::TEAM)
                ->where('context_type', RecContract::class)
                ->where('name', $name)->exists()) {
                continue;
            }

            CoreExtraFieldDefinition::create([
                'team_id'      => self::TEAM,
                'context_type' => RecContract::class,
                'context_id'   => null,
                'name'         => $name,
                'label'        => $label,
                'type'         => 'date',
                'order'        => $order,
            ]);
        }
    }

    private function avDefaultTemplate(): RecContractTemplate
    {
        return $this->makeTemplate(
            'AV-default',
            '<p>Zuschlag: {{zuschlag}} €</p>',
            ['zuschlag' => 'applicant.zuschlag']
        );
    }

    private function makeTemplate(string $code, string $content, array $mappings): RecContractTemplate
    {
        return RecContractTemplate::create([
            'name'           => $code,
            'code'           => $code,
            'content'        => $content,
            'field_mappings' => $mappings,
            'is_active'      => true,
            'team_id'        => self::TEAM,
        ]);
    }

    private function makeApplicant(?float $zuschlag): RecApplicant
    {
        return RecApplicant::create([
            'team_id'  => self::TEAM,
            'zuschlag' => $zuschlag,
        ]);
    }

    private function makeContract(RecApplicant $applicant, RecContractTemplate $template, array $attributes): RecContract
    {
        return RecContract::create(array_merge([
            'rec_applicant_id'         => $applicant->id,
            'rec_contract_template_id' => $template->id,
            'team_id'                  => self::TEAM,
            'personalized_content'     => '<p>x</p>',
            'status'                   => 'pending',
        ], $attributes));
    }

    /** Nur die Spalten, die der Service anfasst — per Insert, ohne Modell. */
    private function makeEmployee(RecApplicant $applicant): int
    {
        return (int) DB::table('rec_employees')->insertGetId([
            'uuid'             => 'emp-' . $applicant->id . '-' . self::TEAM,
            'team_id'          => self::TEAM,
            'rec_applicant_id' => $applicant->id,
            'is_active'        => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);
        $crm = self::packageRootOf(CrmContact::class);
        $own = dirname(__DIR__, 2);

        $files = [
            // Core: Extra-Fields (setExtraField auf dem Vertrag) + Lookups
            // (ZasLookupResolver wird von personalizeContent instanziiert).
            [$core, 'database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php'],
            [$core, 'database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php'],
            [$core, 'database/migrations/2026_02_08_120000_add_is_mandatory_to_core_extra_field_definitions_table.php'],
            [$core, 'database/migrations/2026_02_12_000001_add_llm_verification_to_extra_fields.php'],
            [$core, 'database/migrations/2026_02_12_000002_add_auto_fill_to_extra_fields.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
            [$core, 'database/migrations/2026_02_16_000001_add_visibility_config_to_extra_field_definitions.php'],
            [$core, 'database/migrations/2026_03_19_000001_add_description_to_core_extra_field_definitions_table.php'],
            // CRM: personalizeContent laedt crmContactLinks.contact.*
            [$crm, 'database/migrations/2024_01_01_000013_create_crm_postal_addresses_table.php'],
            [$crm, 'database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php'],
            [$crm, 'database/migrations/2024_01_01_000015_create_crm_email_addresses_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php'],
            [$crm, 'database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php'],
            // Recruiting
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_04_12_000002_add_rec_phase_id_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_04_15_100000_create_rec_contract_tables.php'],
            [$own, 'database/migrations/2026_04_29_000005_add_contract_template_id_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_04_30_000001_add_import_source_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_06_05_000001_add_payroll_tracking_to_rec_employees.php'],
            [$own, 'database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php'],
            // Die Spalte, um die es hier geht.
            [$own, 'database/migrations/2026_08_21_000002_add_superseded_by_to_rec_contracts.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }
}
