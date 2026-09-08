<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmployeeMatchResolver;

/**
 * Zuordnung Bewerber -> Mitarbeiter ohne rec_applicant_id.
 *
 * Der erste Test haelt den Fehler fest, an dem die Handabfrage vom 08.09.2026
 * gescheitert ist: der Vor-/Nachnamen-Dreher war als
 * `mitarbeiterName IN (bewerberName, gedreht(mitarbeiterName))` geschrieben.
 * Das vergleicht den MA mit sich selbst, ist fuer jeden MA mit gleichem Vor-
 * und Nachnamen („Ali Ali") oder leerer Namenshaelfte wahr — und liefert
 * diese MA als Treffer bei JEDEM Bewerber. Im Livebestand waren das fuenf
 * Dauergaeste (RG0, RG17599, RG17757, RG18113, RG18271) in jeder Zeile, was
 * 84 fehlende Verknuepfungen als „ist Mitarbeiter" getarnt hat.
 */
class EmployeeMatchResolverTest extends TestCase
{
    /** MA, deren Namenshaelften gleich oder leer sind, matchen niemanden fremd. */
    public function test_employee_with_identical_or_empty_name_halves_does_not_match_everyone(): void
    {
        $employees = [
            ['id' => 1, 'personnel_number' => 'RG17757', 'first_name' => 'Ali', 'last_name' => 'Ali', 'birth_date' => '1997-01-01'],
            ['id' => 2, 'personnel_number' => 'RG0', 'first_name' => '', 'last_name' => 'Mustermann', 'birth_date' => null],
            ['id' => 3, 'personnel_number' => 'RG18113', 'first_name' => 'Elias', 'last_name' => '', 'birth_date' => null],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 796,
            'names' => [['first' => 'Sara', 'last' => 'Youssfi']],
            'birth_date' => '2004-09-04',
        ], $employees);

        $this->assertSame([], $hits);
        $this->assertSame(EmployeeMatchResolver::VERDICT_NONE, EmployeeMatchResolver::verdict($hits));
    }

    /** Derselbe MA wird beim gleichnamigen Bewerber aber gefunden. */
    public function test_identical_name_halves_still_match_their_own_applicant(): void
    {
        $employees = [
            ['id' => 1, 'personnel_number' => 'RG17757', 'first_name' => 'Ali', 'last_name' => 'Ali', 'birth_date' => '1997-01-01'],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 988,
            'names' => [['first' => 'Ali', 'last' => 'Ali']],
            'birth_date' => '1997-01-01',
        ], $employees);

        $this->assertCount(1, $hits);
        $this->assertSame(EmployeeMatchResolver::PASS_NAME, $hits[0]['pass']);
        $this->assertTrue($hits[0]['birth_match']);
        $this->assertSame(EmployeeMatchResolver::VERDICT_UNLINKED, EmployeeMatchResolver::verdict($hits));
    }

    public function test_reversed_and_diacritic_names_match(): void
    {
        $employees = [
            ['id' => 5, 'personnel_number' => 'RG17745', 'first_name' => 'Böyükbas', 'last_name' => 'Emre', 'birth_date' => '2007-12-01'],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 354,
            'names' => [['first' => 'Emre', 'last' => 'Boyukbas']],
            'birth_date' => '2007-12-01',
        ], $employees);

        $this->assertCount(1, $hits);
        $this->assertSame(EmployeeMatchResolver::PASS_NAME, $hits[0]['pass']);
    }

    /** Verstuemmelter Extra-Feld-Name: „Ochir Ocirzumaev" vs. MA „Ochir Zumaev". */
    public function test_mangled_applicant_name_matches_weakly_via_contained_surname(): void
    {
        $employees = [
            ['id' => 7, 'personnel_number' => 'RG17834', 'first_name' => 'Ochir', 'last_name' => 'Zumaev', 'birth_date' => null],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 1122,
            'names' => [['first' => 'Ochir', 'last' => 'Ocirzumaev']],
            'birth_date' => '2007-07-24',
        ], $employees);

        $this->assertCount(1, $hits);
        $this->assertSame(EmployeeMatchResolver::PASS_CONTAINS, $hits[0]['pass']);
        $this->assertSame(EmployeeMatchResolver::WEAK, $hits[0]['strength']);
        $this->assertSame(EmployeeMatchResolver::VERDICT_CHECK, EmployeeMatchResolver::verdict($hits));
    }

    /**
     * Gleiches Geburtsdatum, anderer Name = Hinweis, kein Beweis. Im Bestand
     * teilen drei Menschen den 16.10.2006.
     */
    public function test_birthdate_only_is_weak(): void
    {
        $employees = [
            ['id' => 9, 'personnel_number' => 'MA18071', 'first_name' => 'Laura Sophie', 'last_name' => 'Heiden', 'birth_date' => '2006-10-16'],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 758,
            'names' => [['first' => 'Laetitia', 'last' => 'Barbian']],
            'birth_date' => '2006-10-16',
        ], $employees);

        $this->assertSame(EmployeeMatchResolver::PASS_BIRTHDATE, $hits[0]['pass']);
        $this->assertSame(EmployeeMatchResolver::VERDICT_CHECK, EmployeeMatchResolver::verdict($hits));
    }

    public function test_link_beats_every_other_pass(): void
    {
        $employees = [
            ['id' => 11, 'personnel_number' => '#8', 'first_name' => 'Shashi', 'last_name' => 'Kumar', 'birth_date' => '1990-05-15', 'rec_applicant_id' => 1860],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 1860,
            'names' => [['first' => 'Suresh', 'last' => 'Kumar']],
            'birth_date' => '1990-05-15',
        ], $employees);

        $this->assertSame(EmployeeMatchResolver::PASS_LINK, $hits[0]['pass']);
        $this->assertSame(EmployeeMatchResolver::VERDICT_LINKED, EmployeeMatchResolver::verdict($hits));
    }

    /** Zwei Firmen in ZAS: RG- und MA-Nummer am selben Menschen sind beide echt. */
    public function test_two_employee_rows_for_one_person_are_both_returned(): void
    {
        $employees = [
            ['id' => 21, 'personnel_number' => 'RG17825', 'first_name' => 'Alaa', 'last_name' => 'Arwani', 'birth_date' => '1983-08-10'],
            ['id' => 22, 'personnel_number' => 'MA18117', 'first_name' => 'Alaa', 'last_name' => 'Arwani', 'birth_date' => '1983-08-10'],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 1090,
            'names' => [['first' => 'Alaa', 'last' => 'Arwani']],
            'birth_date' => '1983-08-10',
        ], $employees);

        $this->assertCount(2, $hits);
        $this->assertSame(
            [21, 22],
            EmployeeMatchResolver::linkableEmployeeIds($hits, [21 => $employees[0], 22 => $employees[1]])
        );
    }

    /** Nur Voll-Name PLUS Geburtsdatum tragen einen automatischen Link. */
    public function test_weak_hits_and_already_linked_employees_are_not_linkable(): void
    {
        $employees = [
            // schwacher Pass
            ['id' => 31, 'personnel_number' => 'RG1', 'first_name' => 'Ochir', 'last_name' => 'Zumaev', 'birth_date' => null],
            // Voll-Name, aber Geburtsdatum fehlt am MA
            ['id' => 32, 'personnel_number' => 'RG2', 'first_name' => 'Sara', 'last_name' => 'Youssfi', 'birth_date' => null],
            // Voll-Name + Geburtsdatum, aber schon verlinkt (an einen anderen Bewerber)
            ['id' => 33, 'personnel_number' => 'RG3', 'first_name' => 'Sara', 'last_name' => 'Youssfi', 'birth_date' => '2004-09-04', 'rec_applicant_id' => 4711],
        ];
        $byId = [31 => $employees[0], 32 => $employees[1], 33 => $employees[2]];

        $hits = EmployeeMatchResolver::match([
            'id' => 796,
            'names' => [['first' => 'Sara', 'last' => 'Youssfi'], ['first' => 'Ochir', 'last' => 'Ocirzumaev']],
            'birth_date' => '2004-09-04',
        ], $employees);

        $this->assertSame([], EmployeeMatchResolver::linkableEmployeeIds($hits, $byId));
    }

    /** Kaputte Datumswerte des Altbestands („0002-06-15") tragen keinen Treffer. */
    public function test_implausible_birthdate_does_not_match(): void
    {
        $employees = [
            ['id' => 41, 'personnel_number' => 'RG9', 'first_name' => 'Egal', 'last_name' => 'Wer', 'birth_date' => '0002-06-15'],
        ];

        $hits = EmployeeMatchResolver::match([
            'id' => 2122,
            'names' => [['first' => 'Janna', 'last' => 'Gierenz']],
            'birth_date' => '0002-06-15',
        ], $employees);

        $this->assertSame([], $hits);
    }

    /** Namen aus CRM und Extra-Feld weichen ab — beide muessen greifen. */
    public function test_both_name_sources_are_used(): void
    {
        $employees = [
            ['id' => 51, 'personnel_number' => 'RG17786', 'first_name' => 'Leni Emily', 'last_name' => 'Runtenberg', 'birth_date' => '2005-01-15'],
        ];

        $viaExtraFieldOnly = EmployeeMatchResolver::match([
            'id' => 746,
            'names' => [['first' => 'Leni', 'last' => 'Runtenberg']],
            'birth_date' => '2005-01-15',
        ], $employees);

        $this->assertCount(1, $viaExtraFieldOnly);
        $this->assertSame(EmployeeMatchResolver::PASS_SURNAME, $viaExtraFieldOnly[0]['pass']);

        $viaCrmSpelling = EmployeeMatchResolver::match([
            'id' => 746,
            'names' => [
                ['first' => 'Leni', 'last' => 'Runtenberg'],
                ['first' => 'Leni Emily', 'last' => 'Runtenberg'],
            ],
            'birth_date' => '2005-01-15',
        ], $employees);

        $this->assertSame(EmployeeMatchResolver::PASS_NAME, $viaCrmSpelling[0]['pass']);
    }
}
