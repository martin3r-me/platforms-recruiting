<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CertificateIssuanceEligibility;

/**
 * Die Freigabe-Regel fuer die Zertifikat-Checkbox am HR-Schreibtisch.
 *
 * ABWEICHUNG VOM TASK-BRIEF (Signatur), begruendet: der Brief-Entwurf hatte als
 * zweite Bedingung $templateExists. Mit dem Zuschnitt v3 gibt es keine
 * Zertifikat-Vorlage mehr (der Inhalt ist festes HTML in
 * Support/TrainingCertificateContent), also auch keine Vorlagen-Existenz, die
 * man pruefen koennte. An genau dieser Stelle steht jetzt der Team-Schalter
 * issue_training_certificates — dieselbe Rolle: "ist die Ausstellung ueberhaupt
 * moeglich", nur aus der anderen Quelle.
 *
 * KEIN Fall-Grund in der Regel, und das ist Absicht: auch ein
 * no_german_knowledge- oder minor-Fall hat an der Schulung teilgenommen. Eine
 * in_array($reason, [...])-Bedingung waere die naheliegende Verengung und wuerde
 * dem Bewerber den Nachweis genau dann verweigern, wenn ein neuer Grund
 * dazukommt, an den niemand gedacht hat.
 */
class CertificateIssuanceEligibilityTest extends TestCase
{
    public function testAlleBedingungenErfuellt(): void
    {
        $this->assertTrue(CertificateIssuanceEligibility::isAvailable(true, true, false));
    }

    public function testOhneAttendedBuchungNicht(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(false, true, false));
    }

    /** Der Team-Schalter aus = die Checkbox erscheint gar nicht. */
    public function testOhneEingeschaltetesFeatureNicht(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(true, false, false));
    }

    /**
     * Bereits ausgestellt = nichts mehr anzubieten. issue() ist idempotent und
     * gibt das bestehende Zertifikat zurueck, ein zweiter Haken waere also
     * harmlos — aber eine angebotene Aktion, die nichts tut, ist eine Luege im
     * UI.
     */
    public function testBereitsAusgestelltNicht(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(true, true, true));
    }

    /**
     * Die drei Bedingungen sind ein UND, keine Mehrheitsentscheidung: jede
     * einzelne fehlende reicht. Der Test steht hier, weil eine Regel aus drei
     * Bool-Parametern der klassische Ort fuer ein verrutschtes || ist — mit
     * ($a || $b) && !$c bleiben die vier Tests darueber gruen.
     */
    public function testJedeEinzelneVerletzungReichtAus(): void
    {
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(false, false, false));
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(false, true, true));
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(true, false, true));
        $this->assertFalse(CertificateIssuanceEligibility::isAvailable(false, false, true));
    }
}
