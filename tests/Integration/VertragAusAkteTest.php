<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Console\Commands\SeedRecContractExtraFields;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Services\VertragAusAkte;
use Platform\Recruiting\Services\VertragsAngaben;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Spec Vertrag aus der Akte §2.2, Tests 1, 2, 4. */
final class VertragAusAkteTest extends TestCase
{
    use VertragAusAkteHarness;

    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        $this->sender = new class extends VertragHinweisSender {
            public array $an = [];
            public string $antwort = 'sent';
            public ?\Throwable $wirft = null;
            public function sende(RecEmployee $employee): string
            {
                $this->an[] = (int) $employee->id;
                if ($this->wirft !== null) {
                    throw $this->wirft;
                }
                return $this->antwort;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function service(): VertragAusAkte
    {
        return new VertragAusAkte($this->sender);
    }

    private function maVorlage(): \Platform\Recruiting\Models\RecContractTemplate
    {
        return $this->vorlage('AV-MA-LOG', 'MA', [
            'name' => 'Arbeitsvertrag MA Logistik', 'taetigkeit' => 'logistiker',
            'content' => '<p>{{vorname}} {{nachname}} | {{beginn}}–{{ende}} | {{zuschlag}} €</p>',
            'field_mappings' => [
                'vorname' => 'contact.first_name', 'nachname' => 'contact.last_name',
                'beginn' => 'contract.extra_field.vertragsbeginn', 'ende' => 'contract.extra_field.vertragsende',
                'zuschlag' => 'applicant.zuschlag',
            ],
        ]);
    }

    /** Spec-Test 1 + Review-Focus 4 ("0,60"). */
    public function test_legt_vertrag_an_der_anstellung_an_mit_feldern_link_und_status_sent(): void
    {
        $ma = $this->anstellung();

        $v = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), 42);

        $v = $v->fresh();
        $this->assertSame($ma->id, (int) $v->rec_employee_id);
        $this->assertNull($v->rec_applicant_id);
        $this->assertSame('sent', $v->status);
        $this->assertNotNull($v->sent_at);
        $this->assertSame(42, (int) $v->created_by_user_id);
        $this->assertSame('2026-10-01', $v->getExtraField('vertragsbeginn'));
        $this->assertSame('2026-10-31', $v->getExtraField('vertragsende'));
        $this->assertSame('0,60', $v->getExtraField('zuschlag'));
        $this->assertSame('<p>Mia Muster | 2026-10-01–2026-10-31 | 0,60 €</p>', $v->personalized_content);
        $this->assertSame(1, CorePublicFormLink::query()->where('linkable_type', RecContract::class)->where('linkable_id', $v->id)->count(), 'Signaturlink angelegt');
        $this->assertSame("Aus der Mitarbeiterakte erstellt.\nHinweis: sent", $v->notes);
        $this->assertSame([$ma->id], $this->sender->an);
    }

    public function test_ende_leer_rechnet_wie_resolveContractDates(): void
    {
        $v = $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', null, 0.0), null);

        $this->assertSame('2027-09-30', $v->fresh()->getExtraField('vertragsende'));
        $this->assertSame('0,00', $v->fresh()->getExtraField('zuschlag'), '0 ist erlaubt');
    }

    /** Spec-Test 2. */
    public function test_vorlage_fremder_gesellschaft_wird_abgelehnt(): void
    {
        $ma = $this->anstellung();

        try {
            $this->service()->erstellen($ma, $this->vorlage('AV-default', 'RG'), new VertragsAngaben('2026-10-01', null, 0.6), null);
            $this->fail('Ausnahme erwartet');
        } catch (\DomainException $e) {
            $this->assertSame('Die Vorlage AV-default gehört zur Gesellschaft RG, diese Akte zu MA.', $e->getMessage());
        }
        $this->assertSame(0, RecContract::count());
        $this->assertSame([], $this->sender->an);
    }

    public function test_nur_arbeitsvertraege(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Aus der Akte entstehen nur Arbeitsverträge');

        $this->service()->erstellen($this->anstellung(), $this->vorlage('IFSG', 'MA'), new VertragsAngaben('2026-10-01', null, 0.6), null);
    }

    public function test_inaktive_akte(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Die Akte ist deaktiviert');

        $this->service()->erstellen($this->anstellung(['is_active' => false]), $this->maVorlage(), new VertragsAngaben('2026-10-01', null, 0.6), null);
    }

    public function test_ungueltige_daten(): void
    {
        foreach ([['', null], ['2026-10-01', '2026-09-30'], ['2026-02-30', null]] as [$beginn, $ende]) {
            try {
                $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben($beginn, $ende, 0.6), null);
                $this->fail("Ausnahme erwartet fuer {$beginn}/{$ende}");
            } catch (\DomainException) {
                // erwartet
            }
        }
        $this->assertSame(0, RecContract::count());
    }

    /** Spec-Test 2 — Doppelabdeckung. */
    public function test_doppelabdeckung_wird_abgelehnt_folgemonat_nicht(): void
    {
        $ma = $this->anstellung();
        $alt = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2026-09-30 10:00:00', 'completed_at' => '2026-09-30 10:00:00'],
            ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        try {
            $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-15', null, 0.6), null);
            $this->fail('Ausnahme erwartet');
        } catch (\DomainException $e) {
            $this->assertSame(
                "Für diesen Zeitraum gibt es bereits einen Arbeitsvertrag (#{$alt->id}, bis 31.10.2026) — erst stornieren oder neu ausstellen.",
                $e->getMessage()
            );
        }

        $neu = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-11-01', '2026-11-30', 0.6), null);
        $this->assertSame('sent', $neu->status);
    }

    public function test_stornierter_vertrag_blockiert_nicht(): void
    {
        $ma = $this->anstellung();
        $this->vertragAn($ma, $this->maVorlage(), ['status' => 'cancelled'], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->assertSame('sent', $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null)->status);
    }

    /**
     * Ruling Task 4: Quelle des Zuschlags ist das Vertrags-Extrafeld. Der
     * Bewerber-Wert bleibt — sonst aendert sich der ZAS-Exportwert vor der
     * Unterschrift und eine RG-Bewerbung bekaeme den MA-Zuschlag.
     */
    public function test_mit_bewerbung_bleibt_der_zuschlag_am_bewerber_unveraendert(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 1.0);
        $ma = $this->verknuepfen($this->anstellung(), $b);

        $v = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);

        $this->assertSame($b->id, (int) $v->rec_applicant_id);
        $this->assertSame('0,60', $v->fresh()->getExtraField('zuschlag'));
        $this->assertEqualsWithDelta(1.0, (float) DB::table('rec_applicants')->where('id', $b->id)->value('zuschlag'), 0.0001);
    }

    /** Ruling Task 4 F1: der ganze Zeitraum zaehlt, nicht nur der Beginn. */
    public function test_doppelabdeckung_prueft_den_ganzen_zeitraum(): void
    {
        $ma = $this->anstellung();
        $oktober = $this->vertragAn($ma, $this->maVorlage(), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        try {
            $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-09-15', '2026-10-31', 0.6), null);
            $this->fail('Ausnahme erwartet: Beginn vor dem bestehenden Vertrag, Ende darin');
        } catch (\DomainException $e) {
            $this->assertStringContainsString("#{$oktober->id}, bis 31.10.2026", $e->getMessage());
        }
        $this->assertSame(1, RecContract::count());
        $this->assertSame([], $this->sender->an);
    }

    public function test_leeres_ende_ueber_einen_schon_ausgestellten_folgemonat_wird_abgelehnt(): void
    {
        $ma = $this->anstellung();
        $november = $this->vertragAn($ma, $this->maVorlage(), [], ['vertragsbeginn' => '2026-11-01', 'vertragsende' => '2026-11-30']);

        try {
            $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', null, 0.6), null);
            $this->fail('Ausnahme erwartet: leeres Ende wird ein Jahr und liegt ueber dem November');
        } catch (\DomainException $e) {
            $this->assertStringContainsString("#{$november->id}, bis 30.11.2026", $e->getMessage());
        }
        $this->assertSame(1, RecContract::count());
    }

    public function test_angrenzende_zeitraeume_sind_erlaubt(): void
    {
        $ma = $this->anstellung();
        $this->vertragAn($ma, $this->maVorlage(), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $neu = $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-09-01', '2026-09-30', 0.6), null);

        $this->assertSame('sent', $neu->status);
    }

    /** Altbestand: unterschriebener AV ohne Laufzeitfelder deckt alles. */
    public function test_alter_av_ohne_laufzeit_blockiert_als_unbefristet(): void
    {
        $ma = $this->anstellung();
        $alt = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2025-03-01 10:00:00', 'completed_at' => '2025-03-01 10:00:00']);

        try {
            $this->service()->erstellen($ma, $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
            $this->fail('Ausnahme erwartet');
        } catch (\DomainException $e) {
            $this->assertSame(
                "Für diesen Zeitraum gibt es bereits einen Arbeitsvertrag (#{$alt->id}, unbefristet) — erst stornieren oder neu ausstellen.",
                $e->getMessage()
            );
        }
    }

    /** Fremdes Team, inaktive Vorlage, Zertifikat statt Vertrag. */
    public function test_nicht_verfuegbare_vorlagen_werden_abgelehnt(): void
    {
        $vorlagen = [
            'fremdes Team' => $this->vorlage('AV-FREMD', 'MA', ['team_id' => $this->team + 1]),
            'inaktiv'      => $this->vorlage('AV-INAKTIV', 'MA', ['is_active' => false]),
            'Zertifikat'   => $this->vorlage('ZERT-ERSTE-HILFE', 'MA', ['type' => \Platform\Recruiting\Models\RecContractTemplate::TYPE_CERTIFICATE]),
        ];

        foreach ($vorlagen as $fall => $vorlage) {
            try {
                $this->service()->erstellen($this->anstellung(), $vorlage, new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
                $this->fail("Ausnahme erwartet: {$fall}");
            } catch (\DomainException $e) {
                $this->assertSame('Diese Vorlage steht für diese Akte nicht zur Verfügung.', $e->getMessage(), $fall);
            }
        }
        $this->assertSame(0, RecContract::count());
        $this->assertSame([], $this->sender->an);
    }

    public function test_zuschlag_nan_und_unendlich_werden_abgelehnt(): void
    {
        foreach ([NAN, INF, -INF] as $wert) {
            try {
                $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', $wert), null);
                $this->fail('Ausnahme erwartet fuer ' . var_export($wert, true));
            } catch (\DomainException $e) {
                $this->assertSame('Der Zuschlag muss zwischen 0 und 999,99 liegen.', $e->getMessage());
            }
        }
        $this->assertSame(0, RecContract::count());
    }

    /** Spec-Test 4 — ein Fehlschlag ist kein Abbruch, der Status steht in notes. */
    public function test_hinweis_fehlschlag_ist_kein_abbruch(): void
    {
        $this->sender->antwort = 'failed';
        $v1 = $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
        $this->assertSame('sent', $v1->fresh()->status);
        $this->assertStringEndsWith('Hinweis: failed', $v1->fresh()->notes);

        $this->sender->wirft = new \RuntimeException('Meta down');
        $service = $this->service();
        $v2 = $service->erstellen($this->anstellung(['personnel_number' => 'MA4712']), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);
        $this->assertStringEndsWith('Hinweis: failed', $v2->fresh()->notes);
        $this->assertSame('failed', $service->letzterHinweis());
    }

    public function test_zahlfeld_bekommt_eine_zahl(): void
    {
        DB::table('core_extra_field_definitions')->where('name', 'zuschlag')->update(['type' => 'number']);
        $this->extraFieldCacheLeeren();

        $v = $this->service()->erstellen($this->anstellung(), $this->maVorlage(), new VertragsAngaben('2026-10-01', '2026-10-31', 0.6), null);

        $this->assertSame(0.6, $v->fresh()->getExtraField('zuschlag'), 'Ein Altfeld vom Typ Zahl verwirft "0,60" still — dort steht die Zahl');
    }

    public function test_seed_legt_zuschlag_als_textfeld_an_und_ist_idempotent(): void
    {
        DB::table('core_extra_field_definitions')->delete();
        $this->vorlage('AV-MA-LOG', 'MA');

        $this->seed();
        $this->seed();

        $zeilen = DB::table('core_extra_field_definitions')->where('team_id', $this->team)->where('context_type', RecContract::class)->orderBy('order')->get();
        $this->assertSame(['vertragsbeginn', 'vertragsende', 'zuschlag', 'herkunft'], $zeilen->pluck('name')->all());
        $this->assertSame('text', $zeilen->firstWhere('name', 'zuschlag')->type);
        $this->assertSame('text', $zeilen->firstWhere('name', 'herkunft')->type, 'Schlussreview N1: Herkunftsmerker');
        $this->assertSame(0, (int) $zeilen->firstWhere('name', 'herkunft')->is_required);
    }

    private function seed(): void
    {
        $command = new SeedRecContractExtraFields();
        $command->setLaravel(new VertragAusAkteFakeLaravel());
        $this->assertSame(0, $command->run(new ArrayInput([], $command->getDefinition()), new BufferedOutput()));
    }
}

/** Command::run() braucht runningUnitTests() und make() (Muster EinsatzPruefungFakeLaravel). */
final class VertragAusAkteFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
