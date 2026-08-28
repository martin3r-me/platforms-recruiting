<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\ContractDispatchService;

/**
 * Die Auswertung des Dispatch-Ergebnisses als reine Funktion — eine Quelle
 * fuer beide Aufrufer (Nachbereitungs-Bulk + HR-Schreibtisch).
 *
 * Warum es die Funktion gibt: die beiden Aufrufer haben dasselbe Array
 * unterschiedlich gelesen. Der Bulk zaehlte einen Fehler nur, wenn
 * message !== null war — und genau im Fall "kein Mitarbeiter-Datensatz"
 * war message null. Ergebnis: gruene Erfolgsmeldung, waehrend der Bewerber
 * gar keine Nachricht bekommen hatte (Bewerber #2381, 25.08.2026). Der
 * HR-Schreibtisch warnte an derselben Stelle korrekt.
 */
class ContractDispatchResultTest extends TestCase
{
    public function test_versand_ohne_portal_nachricht_ist_ein_fehler(): void
    {
        // Der Fall, der drei Tage lang als Erfolg durchging: Vertraege raus,
        // Portal-WA nicht — ohne Fehlertext, weil es keinen Mitarbeiter gab.
        $this->assertTrue(ContractDispatchService::isPortalFailure([
            'status'      => 'sent',
            'portal_sent' => false,
            'message'     => null,
        ]));
    }

    public function test_versand_mit_portal_fehlertext_ist_ein_fehler(): void
    {
        $this->assertTrue(ContractDispatchService::isPortalFailure([
            'status'      => 'sent',
            'portal_sent' => false,
            'message'     => 'Template nicht gefunden oder nicht genehmigt.',
        ]));
    }

    public function test_vollstaendiger_versand_ist_kein_fehler(): void
    {
        $this->assertFalse(ContractDispatchService::isPortalFailure([
            'status'      => 'sent',
            'portal_sent' => true,
            'message'     => null,
        ]));
    }

    public function test_uebersprungene_und_fehlgeschlagene_versaende_zaehlt_der_aufrufer_selbst(): void
    {
        // isPortalFailure beantwortet NUR "Vertraege raus, Portal-WA nicht".
        // status=error und status=skipped_already_sent haben eigene Zweige;
        // sie hier mitzuzaehlen wuerde sie doppelt zaehlen.
        $this->assertFalse(ContractDispatchService::isPortalFailure([
            'status'      => 'error',
            'portal_sent' => false,
            'message'     => 'Bewerber hat keinen Zuschlag gesetzt.',
        ]));
        $this->assertFalse(ContractDispatchService::isPortalFailure([
            'status'      => 'skipped_already_sent',
            'portal_sent' => false,
            'message'     => null,
        ]));
    }

    public function test_fehlender_mitarbeiter_hat_einen_eigenen_klartext(): void
    {
        // Der Grund muss im Flash und im Log lesbar sein — "Portal-WA
        // fehlgeschlagen" ohne Ursache schickt HR auf die falsche Faehrte
        // (Template? Nummer?), obwohl der Mitarbeiter-Datensatz fehlt.
        $this->assertStringContainsString(
            'Mitarbeiter',
            ContractDispatchService::NO_EMPLOYEE_MESSAGE
        );
    }
}
