<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EvaluationTransfer;

class EvaluationTransferTest extends TestCase
{
    public function test_kopiert_alle_acht_felder_auf_eine_leere_hr_data_row(): void
    {
        $applicant = [
            'rating_erscheinungsbild' => 4,
            'rating_fachkompetenz'    => 3,
            'rating_auffassungsgabe'  => 5,
            'rating_auftreten'        => 4,
            'rating_teamintegration'  => 2,
            'evaluation_note'         => 'Sehr zuverlaessig.',
            'linen_package_items'     => ['hemd'],
            'qualifications'          => ['service'],
        ];

        $copy = EvaluationTransfer::valuesToCopy($applicant, []);

        $this->assertSame($applicant, $copy);
    }

    public function test_ueberschreibt_niemals_einen_bestehenden_hr_data_wert(): void
    {
        $copy = EvaluationTransfer::valuesToCopy(
            ['rating_auftreten' => 4, 'evaluation_note' => 'neu'],
            ['rating_auftreten' => 2, 'evaluation_note' => null],
        );

        $this->assertArrayNotHasKey('rating_auftreten', $copy);
        $this->assertSame(['evaluation_note' => 'neu'], $copy);
    }

    public function test_ueberspringt_leere_quellwerte(): void
    {
        $copy = EvaluationTransfer::valuesToCopy(
            [
                'rating_auftreten'    => null,
                'evaluation_note'     => '',
                'linen_package_items' => [],
                'qualifications'      => ['service'],
            ],
            [],
        );

        $this->assertSame(['qualifications' => ['service']], $copy);
    }

    public function test_normalisiert_beim_kopieren(): void
    {
        // Unsinnige Sterne und leere Listeneintraege duerfen nicht auf hrData
        // landen — sonst wandert Muell in den ZAS-Export.
        $copy = EvaluationTransfer::valuesToCopy(
            [
                'rating_auftreten'    => '9',
                'rating_fachkompetenz' => '3',
                'linen_package_items' => ['hemd', '', null],
            ],
            [],
        );

        $this->assertArrayNotHasKey('rating_auftreten', $copy);
        $this->assertSame(3, $copy['rating_fachkompetenz']);
        $this->assertSame(['hemd'], $copy['linen_package_items']);
    }

    public function test_leere_quelle_ergibt_leeres_ergebnis(): void
    {
        $this->assertSame([], EvaluationTransfer::valuesToCopy([], []));
    }

    public function test_ist_idempotent_bei_doppellauf(): void
    {
        $applicant = ['rating_auftreten' => 4, 'qualifications' => ['service']];

        $first = EvaluationTransfer::valuesToCopy($applicant, []);
        $this->assertNotSame([], $first);

        // Zweiter Lauf gegen die inzwischen befuellte hrData-Row.
        $second = EvaluationTransfer::valuesToCopy($applicant, $first);
        $this->assertSame([], $second);
    }
}
