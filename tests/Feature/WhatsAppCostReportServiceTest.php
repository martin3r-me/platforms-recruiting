<?php

namespace Platform\Recruiting\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Platform\Recruiting\Services\WhatsAppCost\WhatsAppCostReportService;

class WhatsAppCostReportServiceTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('recruiting.whatsapp_costs.price_per_delivered_template', 0.055);
        $app['config']->set('recruiting.whatsapp_costs.currency', 'EUR');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('comms_whatsapp_threads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
        });

        Schema::create('comms_whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('comms_whatsapp_thread_id');
            $t->string('direction')->default('outbound');
            $t->string('template_name')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('sent_by_user_id')->nullable();
            $t->timestamp('delivered_at')->nullable();
        });

        // Thread 1 → Team 1, Thread 2 → Team 2
        DB::table('comms_whatsapp_threads')->insert([
            ['id' => 1, 'team_id' => 1],
            ['id' => 2, 'team_id' => 2],
        ]);
    }

    private function msg(array $attrs): void
    {
        DB::table('comms_whatsapp_messages')->insert(array_merge([
            'comms_whatsapp_thread_id' => 1,
            'direction' => 'outbound',
            'template_name' => 'reminder_de',
            'status' => 'delivered',
            'sent_by_user_id' => null,
            'delivered_at' => '2026-06-10 12:00:00',
        ], $attrs));
    }

    private function service(): WhatsAppCostReportService
    {
        return new WhatsAppCostReportService();
    }

    private function juneRange(): array
    {
        return [Carbon::parse('2026-06-01 00:00:00'), Carbon::parse('2026-06-30 23:59:59')];
    }

    public function test_counts_only_delivered_outbound_for_team(): void
    {
        $this->msg(['status' => 'delivered']);                 // zählt
        $this->msg(['status' => 'read']);                      // zählt
        $this->msg(['status' => 'sent']);                      // zählt NICHT
        $this->msg(['status' => 'failed']);                    // zählt NICHT
        $this->msg(['direction' => 'inbound', 'status' => 'delivered']); // zählt NICHT
        $this->msg(['comms_whatsapp_thread_id' => 2]);         // fremdes Team, zählt NICHT

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to);

        $this->assertSame(2, $report->totalCount);
    }

    public function test_manual_vs_automatic_split(): void
    {
        $this->msg(['sent_by_user_id' => null]);  // automatisch
        $this->msg(['sent_by_user_id' => null]);  // automatisch
        $this->msg(['sent_by_user_id' => 42]);    // manuell

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to);

        $this->assertSame(1, $report->manualCount);
        $this->assertSame(2, $report->automaticCount);
    }

    public function test_date_range_excludes_outside(): void
    {
        $this->msg(['delivered_at' => '2026-06-15 09:00:00']); // drin
        $this->msg(['delivered_at' => '2026-05-31 23:59:59']); // raus
        $this->msg(['delivered_at' => '2026-07-01 00:00:01']); // raus

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to);

        $this->assertSame(1, $report->totalCount);
    }

    public function test_type_filter_manual_only(): void
    {
        $this->msg(['sent_by_user_id' => null]);
        $this->msg(['sent_by_user_id' => 42]);

        [$from, $to] = $this->juneRange();
        $report = $this->service()->build(1, $from, $to, 'manual');

        $this->assertSame(1, $report->totalCount);
        $this->assertSame(1, $report->manualCount);
        $this->assertSame(0, $report->automaticCount);
    }
}
