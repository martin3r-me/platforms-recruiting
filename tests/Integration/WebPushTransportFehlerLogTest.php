<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Push\WebPushTransport;

/**
 * Gesamtpruefung I4 (13.11.2026): WebPushTransport schluckte jeden Fehler ohne Text.
 * Jetzt eine Logzeile mit Grund und nur dem Host des Endpunkts — hoechstens einmal je
 * Instanz (= je Job-Lauf, der Transport wird je handle() frisch aufgeloest). Die
 * Bibliothek fehlt im Test-vendor; geprueft wird die eigene Log-Methode.
 */
class WebPushTransportFehlerLogTest extends TestCase
{
    private const ENDPUNKT = 'https://fcm.googleapis.com/fcm/send/geheimerPfad123:APA91bXyz';
    private const P256DH = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
    private const AUTH = 'tBHItJI5svbpez7KI4CCXg';

    /** @var list<array{0:string,1:string,2:array}> */
    public static array $zeilen = [];
    public static bool $logWirft = false;

    protected function setUp(): void
    {
        self::$zeilen = [];
        self::$logWirft = false;
        $c = Container::getInstance();
        $c->instance('log', new class {
            public function __call($stufe, $args)
            {
                if (WebPushTransportFehlerLogTest::$logWirft) {
                    throw new \RuntimeException('Log kaputt');
                }
                WebPushTransportFehlerLogTest::$zeilen[] = [$stufe, $args[0] ?? '', $args[1] ?? []];
            }
        });
        Facade::setFacadeApplication($c);
        Facade::clearResolvedInstance('log');
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstance('log');
    }

    private static function transport(): WebPushTransport
    {
        return new class extends WebPushTransport {
            public function melden(string $grund, string $endpunkt, array $geheim = []): void
            {
                $this->fehlerMelden($grund, $endpunkt, $geheim);
            }
        };
    }

    public function test_grund_mit_host_einmal_je_instanz_ohne_endpunkt_und_schluessel(): void
    {
        $t = self::transport();
        $grund = 'Client error: `POST ' . self::ENDPUNKT . '` resulted in a `403 Forbidden` response: the key ' . self::P256DH . ' / ' . self::AUTH;
        $t->melden($grund, self::ENDPUNKT, [self::P256DH, self::AUTH]);
        $t->melden('zweiter Fehler', self::ENDPUNKT);

        $this->assertCount(1, self::$zeilen, 'hoechstens einmal je Instanz');
        [$stufe, $nachricht, $kontext] = self::$zeilen[0];
        $this->assertSame('warning', $stufe);
        $this->assertSame('recruiting.portal_push.transport_fehler', $nachricht);
        $this->assertSame('fcm.googleapis.com', $kontext['host']);
        $this->assertStringContainsString('403 Forbidden', $kontext['grund']);
        $alles = json_encode(self::$zeilen);
        $this->assertStringNotContainsString('geheimerPfad123', $alles, 'nie der ganze Endpunkt');
        $this->assertStringNotContainsString(self::P256DH, $alles, 'keine Schluessel');
        $this->assertStringNotContainsString(self::AUTH, $alles, 'keine Schluessel');

        self::transport()->melden('neuer Lauf', self::ENDPUNKT);
        $this->assertCount(2, self::$zeilen, 'neue Instanz = neuer Job-Lauf meldet wieder');
    }

    public function test_grund_wird_gekuerzt_und_leerer_grund_bekommt_platzhalter(): void
    {
        self::transport()->melden(str_repeat('x', 2000), self::ENDPUNKT);
        self::transport()->melden('   ', 'kein-url');

        $this->assertSame(300, mb_strlen(self::$zeilen[0][2]['grund']));
        $this->assertSame('(ohne Text)', self::$zeilen[1][2]['grund']);
        $this->assertNull(self::$zeilen[1][2]['host']);
    }

    public function test_werfendes_log_wirft_nicht(): void
    {
        self::$logWirft = true;
        self::transport()->melden('egal', self::ENDPUNKT);
        $this->assertSame([], self::$zeilen);
    }

    public function test_senden_meldet_ausnahme_und_nicht_erfolg(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Push/WebPushTransport.php');
        $this->assertStringContainsString('$this->fehlerMelden((string) $report->getReason(), $endpunkt, $geheim);', $src);
        $this->assertStringContainsString('$this->fehlerMelden($e->getMessage(), $endpunkt, $geheim);', $src);
    }
}
