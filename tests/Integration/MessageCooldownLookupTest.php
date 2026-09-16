<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Support\MessageCooldown;

/**
 * Die DB-Seite des Cooldowns: welcher Log-Eintrag zaehlt als „da ging etwas
 * raus"? Der Vorfall 15.09.2026 haengt genau daran — die Kampagne schreibt
 * `campaign_sent`, die Warteliste `waitlist_slot_available_sent`, und beide
 * muss der Auto-Pilot sehen, obwohl sie nicht von ihm stammen.
 *
 * Gegenprobe ist genauso wichtig: `silent` faellt bei jedem Minutenlauf an
 * (Ticket 2026-08-28-autopilot-silent-log-flood) und duerfte, wenn er mitzaehlte,
 * den Auto-Piloten dauerhaft stilllegen.
 */
final class MessageCooldownLookupTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $this->capsule->schema()->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type', 30);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function log(int $applicantId, string $type, string $at): void
    {
        $row = new RecAutoPilotLog(['rec_applicant_id' => $applicantId, 'type' => $type, 'summary' => $type]);
        $row->created_at = $at;
        $row->save();
    }

    public function testOhneLogEintraegeGibtEsKeineLetzteNachricht(): void
    {
        $this->assertNull(MessageCooldown::lastOutboundAt(1));
    }

    /** Der Fall aus dem Vorfall: die Kampagne ist der juengste Sender, nicht der Auto-Pilot. */
    public function testKampagnenVersandZaehlt(): void
    {
        $this->log(2, 'campaign_sent', '2026-09-15 19:08:06');

        $this->assertSame('2026-09-15 19:08:06', MessageCooldown::lastOutboundAt(2));
    }

    /**
     * Die eigenen Sendungen des Auto-Piloten zaehlen NICHT. Seine Taktung
     * regelt auto_pilot_reminder_interval_hours (pro Stelle 1-168 h
     * einstellbar); zaehlte der Cooldown sie mit, wuerde eine Stelle mit
     * 12-Stunden-Takt von der 24-Stunden-Ruhefrist ueberstimmt — der Waechter
     * wuerde langsamer machen, was er gar nicht schuetzen soll.
     */
    public function testEigeneSendungenDesAutoPilotenZaehlenNicht(): void
    {
        $this->log(7, 'campaign_sent', '2026-09-15 19:08:06');
        $this->log(7, 'template_sent', '2026-09-16 09:00:00');
        $this->log(7, 'reminder_sent', '2026-09-16 10:00:00');

        $this->assertSame('2026-09-15 19:08:06', MessageCooldown::lastOutboundAt(7));
    }

    public function testWartelistenBenachrichtigungZaehlt(): void
    {
        $this->log(3, 'waitlist_slot_available_sent', '2026-09-15 18:59:42');

        $this->assertSame('2026-09-15 18:59:42', MessageCooldown::lastOutboundAt(3));
    }

    /** `silent` faellt jede Minute an — zaehlte er mit, schwiege der Auto-Pilot fuer immer. */
    public function testStilleUndFehlerEintraegeZaehlenNicht(): void
    {
        $this->log(4, 'campaign_sent', '2026-09-14 10:00:00');
        $this->log(4, 'silent', '2026-09-16 09:00:00');
        $this->log(4, 'error', '2026-09-16 09:30:00');
        $this->log(4, 'phase_advanced', '2026-09-16 09:45:00');

        $this->assertSame('2026-09-14 10:00:00', MessageCooldown::lastOutboundAt(4));
    }

    public function testFremdeBewerberBleibenDraussen(): void
    {
        $this->log(5, 'campaign_sent', '2026-09-15 19:08:06');

        $this->assertNull(MessageCooldown::lastOutboundAt(6));
    }
}
