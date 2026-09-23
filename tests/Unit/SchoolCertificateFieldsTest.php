<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SchoolCertificateFields;

final class SchoolCertificateFieldsTest extends TestCase
{
    public function test_schueler_braucht_schulbescheinigung(): void
    {
        $this->assertSame(
            ['schulbescheinigung_file_id', 'school_certificate_valid_until'],
            SchoolCertificateFields::forEmploymentType('schueler'),
        );
    }

    public function test_student_braucht_immatrikulation(): void
    {
        $this->assertSame(
            ['immatrikulation_file_id', 'school_certificate_valid_until'],
            SchoolCertificateFields::forEmploymentType('student'),
        );
    }

    /**
     * "Student erwerbst." kam bisher ueber die Alias-Tabelle als `student`
     * bei uns an und forderte damit die Immatrikulation. Seit der Wert ein
     * eigener Lookup-Eintrag ist (Clara-Liste 28.08.2026), muss die Regel
     * ausdruecklich mitwandern — sonst verloeren genau diese Leute die
     * Nachweispflicht, und BeschErforderlich kippte Richtung ZAS auf Nein.
     * Ein erwerbstaetiger Student ist weiterhin eingeschrieben.
     */
    public function test_erwerbstaetiger_student_braucht_ebenfalls_die_immatrikulation(): void
    {
        $this->assertSame(
            ['immatrikulation_file_id', 'school_certificate_valid_until'],
            SchoolCertificateFields::forEmploymentType('student_erwerbstaetig'),
        );
    }

    public function test_alle_anderen_typen_brauchen_keine_bescheinigung(): void
    {
        // zwischen_schule_studium und fsj sind neu (Clara-Liste 28.08.2026)
        // und bewusst an keine Bescheinigung gekoppelt; ob ein FSJ einen
        // Nachweis braucht, ist Rueckfrage an den Kunden.
        $types = [
            'arbeitslos', 'erwerbstaetig', 'hausmann_frau', 'azubi', 'rentner',
            'zwischen_schule_studium', 'fsj',
        ];
        foreach ($types as $type) {
            $this->assertSame([], SchoolCertificateFields::forEmploymentType($type), $type);
        }
    }

    /**
     * Der ZAS-Export kodierte dieselbe Frage bisher mit einer ZWEITEN Liste
     * (`in_array($type, ['schueler','student'])` im ZasEmployeeFieldResolver).
     * Zwei Listen fuer eine Regel waren genau die Falle, in die ein neuer
     * Status laufen konnte — deshalb gibt es jetzt eine Quelle.
     */
    public function test_nachweispflicht_ist_eine_einzige_quelle(): void
    {
        $this->assertTrue(SchoolCertificateFields::requiresCertificate('schueler'));
        $this->assertTrue(SchoolCertificateFields::requiresCertificate('student'));
        $this->assertTrue(SchoolCertificateFields::requiresCertificate('student_erwerbstaetig'));

        $this->assertFalse(SchoolCertificateFields::requiresCertificate('fsj'));
        $this->assertFalse(SchoolCertificateFields::requiresCertificate('zwischen_schule_studium'));
        $this->assertFalse(SchoolCertificateFields::requiresCertificate('rentner'));
        $this->assertFalse(SchoolCertificateFields::requiresCertificate(null));
    }

    public function test_leerer_typ_zeigt_keine_bescheinigung(): void
    {
        // employment_type wird in P1 abgefragt und ist praktisch immer
        // gesetzt — falls doch leer: keine Bescheinigungs-Felder zeigen
        // (Absprache 2026-08-06).
        $this->assertSame([], SchoolCertificateFields::forEmploymentType(null));
        $this->assertSame([], SchoolCertificateFields::forEmploymentType(''));
    }
}
