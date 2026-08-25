<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundSizeGuard;

/**
 * Paketgroessen-Waechter fuer den MA-Eingang.
 *
 * Hintergrund: die Verarbeitung laeuft synchron im POST-Request. Bei ~100
 * Zeilen sind das 2-3 Sekunden (gemessen am Massenimport 2026-08-25), bei
 * vierstelligen Lieferungen wird es der Timeout — und der Abschlussbericht
 * sprengt zusaetzlich die notes-Spalte. Die Absprache mit ZAS lautet daher
 * "Pakete a ~100 Zeilen"; bisher war das reine Absprache ohne Zaun.
 *
 * Der Waechter macht daraus eine pruefbare Grenze: zu grosse Lieferung =>
 * klare Fehlermeldung an ZAS, statt halb durchzulaufen. Die Rohdatei wird
 * trotzdem gespeichert (Aufrufer-Verantwortung), damit wir sie selbst in
 * Portionen verarbeiten koennen.
 */
class ZasInboundSizeGuardTest extends TestCase
{
    public function test_lieferung_an_der_grenze_ist_erlaubt(): void
    {
        $this->assertNull(ZasInboundSizeGuard::rejectionReason(300, 300));
    }

    public function test_eine_zeile_zu_viel_wird_abgewiesen(): void
    {
        $reason = ZasInboundSizeGuard::rejectionReason(301, 300);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('301', $reason);
        $this->assertStringContainsString('300', $reason);
    }

    public function test_leere_lieferung_ist_kein_groessenproblem(): void
    {
        $this->assertNull(ZasInboundSizeGuard::rejectionReason(0, 300));
    }

    public function test_grenze_null_schaltet_den_waechter_ab(): void
    {
        // Notausgang fuer den Betrieb: Grenze auf 0 setzen und der Waechter
        // haelt niemanden auf (z. B. fuer eine bewusst grosse Sonderlieferung).
        $this->assertNull(ZasInboundSizeGuard::rejectionReason(5000, 0));
    }
}
