<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FirstAiderDateGuard;

class FirstAiderDateGuardTest extends TestCase
{
    public function test_blocks_truthy_flag_without_date(): void
    {
        foreach (['1', 'true', 'ja', 'Ja', ' 1 '] as $flag) {
            $error = FirstAiderDateGuard::error($flag, '');
            $this->assertNotNull($error, "Flag '$flag' ohne Datum muss blocken");
            $this->assertStringContainsString('Ersthelfer', $error);
        }
    }

    public function test_blocks_whitespace_only_date(): void
    {
        $this->assertNotNull(FirstAiderDateGuard::error('1', '   '));
    }

    public function test_passes_with_date(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('1', '2027-03-01'));
        $this->assertNull(FirstAiderDateGuard::error('ja', '2027-03-01'));
    }

    public function test_passes_when_not_set(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('0', ''));
        $this->assertNull(FirstAiderDateGuard::error('nein', ''));
        $this->assertNull(FirstAiderDateGuard::error('', ''));
        $this->assertNull(FirstAiderDateGuard::error(null, null));
    }

    /**
     * Portal-Zweig (Spec 2026-09-01): dort ist zusaetzlich der hochgeladene
     * Schein Pflicht. Der vierte Parameter schaltet die Dokumentpflicht ein —
     * HR ruft ohne ihn auf und bleibt damit unveraendert.
     */
    public function test_certificate_is_not_required_unless_switched_on(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('1', '2027-03-01', null));
    }

    public function test_blocks_missing_certificate_when_required(): void
    {
        $error = FirstAiderDateGuard::error('1', '2027-03-01', null, true);
        $this->assertNotNull($error);
        $this->assertStringContainsString('Schein', $error);
    }

    public function test_treats_empty_and_zero_file_id_as_missing(): void
    {
        foreach ([null, '', '   ', '0', 0] as $fileId) {
            $this->assertNotNull(
                FirstAiderDateGuard::error('1', '2027-03-01', $fileId, true),
                'File-Id ' . var_export($fileId, true) . ' muss als fehlend gelten',
            );
        }
    }

    public function test_names_both_gaps_when_date_and_certificate_missing(): void
    {
        $error = FirstAiderDateGuard::error('1', '', null, true);
        $this->assertNotNull($error);
        $this->assertStringContainsString('gueltig bis', $error);
        // 'Schein' allein waere kein Beleg — das steht schon im Feldnamen
        // "Ersthelfer-Schein gueltig bis". Der Upload muss eigens benannt sein.
        $this->assertStringContainsString('hochladen', $error);
    }

    public function test_passes_with_date_and_certificate(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('1', '2027-03-01', 42, true));
        $this->assertNull(FirstAiderDateGuard::error('ja', '2027-03-01', '42', true));
    }

    public function test_certificate_requirement_ignored_when_flag_is_no(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('0', '', null, true));
        $this->assertNull(FirstAiderDateGuard::error('', '', null, true));
    }
}
