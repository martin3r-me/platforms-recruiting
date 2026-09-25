<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmployerDeclaration;

/**
 * Die Arbeitgeber-Erklaerung aus dem Unterschriften-Schritt (Markus
 * 24.09.2026). Sie wird beim Signieren neben §15/§16 gespeichert und von
 * dort auf den Mitarbeiter uebernommen.
 *
 * BEWUSST NICHT im Vertragsdokument (Entscheidung 25.09.2026): Markus hat
 * eine Auswahl verlangt, keine Aenderung am Vertragstext. Deshalb steht hier
 * nur die Zuordnung Daten -> Mitarbeiter-Spalten, kein HTML.
 */
final class EmployerDeclarationTest extends TestCase
{
    public function test_schueler_und_studenten_bekommen_hauptarbeitgeber_vorausgewaehlt(): void
    {
        $this->assertSame(EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::defaultRoleFor('schueler'));
        $this->assertSame(EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::defaultRoleFor('student'));
    }

    /**
     * Der seit 23.09.2026 eigene Wert "Student erwerbstaetig" sagt
     * ausdruecklich, dass die Person nebenher arbeitet. Ihr
     * "Hauptarbeitgeber" vorzugeben widerspraeche ihrer eigenen Angabe.
     */
    public function test_erwerbstaetiger_student_wird_nicht_vorbelegt(): void
    {
        $this->assertNull(EmployerDeclaration::defaultRoleFor('student_erwerbstaetig'));
    }

    public function test_alle_anderen_werden_nicht_vorbelegt(): void
    {
        foreach (['arbeitslos', 'erwerbstaetig', 'azubi', 'rentner', 'fsj', 'hausmann_frau', '', null] as $typ) {
            $this->assertNull(EmployerDeclaration::defaultRoleFor($typ), var_export($typ, true));
        }
    }

    public function test_hauptarbeitgeber_wird_zu_true(): void
    {
        $this->assertSame(
            ['is_main_employer' => true, 'other_employer' => null],
            EmployerDeclaration::toEmployeeAttributes([
                EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN,
            ]),
        );
    }

    public function test_nebenarbeitgeber_wird_zu_false_mit_namen(): void
    {
        $this->assertSame(
            ['is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH'],
            EmployerDeclaration::toEmployeeAttributes([
                EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_SECONDARY,
                EmployerDeclaration::KEY_OTHER => '  Musterkantine GmbH  ',
            ]),
        );
    }

    public function test_hauptarbeitgeber_mit_nebenjob_behaelt_den_namen(): void
    {
        $this->assertSame(
            ['is_main_employer' => true, 'other_employer' => 'Musterkantine GmbH'],
            EmployerDeclaration::toEmployeeAttributes([
                EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_MAIN,
                EmployerDeclaration::KEY_OTHER => 'Musterkantine GmbH',
            ]),
        );
    }

    /**
     * Die Erklaerung ist der Stand vom Unterschriftstag und damit
     * ausdruecklich: "kein weiterer Arbeitgeber". Ein alter Wert am
     * Mitarbeiter muss deshalb geleert werden, nicht stehenbleiben.
     */
    public function test_leerer_name_leert_das_feld_ausdruecklich(): void
    {
        $out = EmployerDeclaration::toEmployeeAttributes([
            EmployerDeclaration::KEY_ROLE  => EmployerDeclaration::ROLE_MAIN,
            EmployerDeclaration::KEY_OTHER => '   ',
        ]);

        $this->assertArrayHasKey('other_employer', $out);
        $this->assertNull($out['other_employer']);
    }

    /**
     * §15/§16-Daten aus der Zeit vor dieser Erweiterung duerfen nichts am
     * Mitarbeiter anfassen — sonst setzte ein Alt-Vertrag beim naechsten
     * Anlauf stillschweigend Felder.
     */
    public function test_daten_ohne_erklaerung_liefern_nichts(): void
    {
        $this->assertSame([], EmployerDeclaration::toEmployeeAttributes([]));
        $this->assertSame([], EmployerDeclaration::toEmployeeAttributes([
            'par15_has_previous' => false,
            'par16_was_jobseeking' => false,
        ]));
        $this->assertSame([], EmployerDeclaration::toEmployeeAttributes([
            EmployerDeclaration::KEY_ROLE => '',
        ]));
    }

    public function test_unbekannte_rolle_liefert_nichts(): void
    {
        $this->assertSame([], EmployerDeclaration::toEmployeeAttributes([
            EmployerDeclaration::KEY_ROLE => 'vielleicht',
        ]));
    }

    /**
     * NEUAUSSTELLUNG EINES VERTRAGS ist der gefaehrliche Fall (Befund
     * Review 25.09.2026): Ein Schueler hat im Portal "nein, Hauptarbeitgeber
     * ist Mueller GmbH" angegeben. HR stellt den Arbeitsvertrag wegen einer
     * Lohnerhoehung neu aus. Wuerde die Maske stur aus "Ich bin" vorbelegen,
     * staende der Haken wieder auf "Hauptarbeitgeber" — und ein
     * Durchklicken kippte die Steuerklasse.
     *
     * Eine bereits gegebene Antwort gewinnt deshalb immer vor der
     * Vorbelegung.
     */
    public function test_vorhandene_antwort_gewinnt_vor_der_vorbelegung(): void
    {
        $this->assertSame(
            EmployerDeclaration::ROLE_SECONDARY,
            EmployerDeclaration::initialRole(false, 'schueler'),
            'Ein Schueler, der "nein" gesagt hat, darf nicht auf "ja" zurueckfallen.',
        );
        $this->assertSame(
            EmployerDeclaration::ROLE_MAIN,
            EmployerDeclaration::initialRole(true, 'student_erwerbstaetig'),
        );
    }

    public function test_ohne_vorhandene_antwort_greift_die_vorbelegung(): void
    {
        $this->assertSame(EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::initialRole(null, 'schueler'));
        $this->assertSame(EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::initialRole(null, 'student'));
        $this->assertNull(EmployerDeclaration::initialRole(null, 'erwerbstaetig'));
        $this->assertNull(EmployerDeclaration::initialRole(null, 'student_erwerbstaetig'));
        $this->assertNull(EmployerDeclaration::initialRole(null, null));
    }

    public function test_die_auswahl_ist_pflicht_und_auf_zwei_werte_begrenzt(): void
    {
        $rules = EmployerDeclaration::rules();

        $this->assertArrayHasKey('employerRole', $rules);
        $this->assertStringContainsString('required', $rules['employerRole']);
        $this->assertStringContainsString('in:haupt,neben', $rules['employerRole']);
    }

    public function test_der_name_ist_nur_bei_nebenarbeitgeber_pflicht(): void
    {
        $rules = EmployerDeclaration::rules();

        $this->assertSame('nullable|required_if:employerRole,neben|string|max:128', $rules['employerOther']);
    }

    public function test_die_laengengrenze_passt_zur_spalte(): void
    {
        // rec_employees.other_employer ist string(128). Eine laengere Eingabe
        // wuerde beim Uebertrag auf den Mitarbeiter einen 22001-Abbruch
        // ausloesen — derselbe Fehler, der am 25.08.2026 die MA-Anlage
        // gekillt hat. Deshalb schon im Formular begrenzen.
        $this->assertStringContainsString('max:128', EmployerDeclaration::rules()['employerOther']);
    }

    public function test_die_meldungen_gibt_es_in_du_und_sie(): void
    {
        $du  = EmployerDeclaration::messages(true);
        $sie = EmployerDeclaration::messages(false);

        $this->assertNotSame($du, $sie);
        foreach (['employerRole.required', 'employerOther.required_if'] as $key) {
            $this->assertArrayHasKey($key, $du, $key);
            $this->assertArrayHasKey($key, $sie, $key);
            $this->assertNotSame('', trim($du[$key]));
        }
    }
}
