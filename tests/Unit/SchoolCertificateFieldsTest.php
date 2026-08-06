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

    public function test_alle_anderen_typen_brauchen_keine_bescheinigung(): void
    {
        foreach (['arbeitslos', 'erwerbstaetig', 'hausmann_frau', 'azubi', 'rentner'] as $type) {
            $this->assertSame([], SchoolCertificateFields::forEmploymentType($type), $type);
        }
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
