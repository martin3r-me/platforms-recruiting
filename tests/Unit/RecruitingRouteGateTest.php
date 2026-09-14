<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\RecruitingRouteGate;

/**
 * Welche Route ein eingeschraenktes Konto sehen darf — als reine Funktion,
 * damit die Entscheidung pruefbar ist statt in der Middleware zu verschwinden.
 *
 * Der teure Irrtum, gegen den diese Tests stehen: Die Middleware hat bis
 * 14.09.2026 NUR "Nur Veranstaltungen"-Konten eingeschraenkt. Eine zweite
 * Stufe dazuzunehmen, ohne sie hier einzutragen, haette Teamleiter-Konten zu
 * normalen Nutzern gemacht — mit Bewerberakten, Loehnen und allen sechs
 * Vertragsversand-Knoepfen. Ein Zugang, der nur etwas ERLAUBT und nichts
 * verbietet, ist kein Gate.
 */
class RecruitingRouteGateTest extends TestCase
{
    public function test_normaler_nutzer_wird_nirgends_umgeleitet(): void
    {
        $this->assertNull(RecruitingRouteGate::redirectTo(
            eventOnly: false,
            trainingLeader: false,
            routeName: 'recruiting.dashboard',
        ));
    }

    public function test_teamleiter_kommt_nicht_an_die_bewerberliste(): void
    {
        // Der Kern der Absicherung: die grosse Buchungsliste traegt Lohnfelder
        // und Vertragsversand. Ohne sie gibt es auch keinen Livewire-Snapshot,
        // ueber den man ihre Methoden aufrufen koennte.
        $this->assertSame(
            'recruiting.training-review.index',
            RecruitingRouteGate::redirectTo(
                eventOnly: false,
                trainingLeader: true,
                routeName: 'recruiting.interview-bookings.index',
            ),
        );
    }

    public function test_teamleiter_kommt_nicht_an_bewerberakte_und_direkteinstellung(): void
    {
        foreach ([
            'recruiting.applicants.show',
            'recruiting.applicants.index',
            'recruiting.dashboard.hr-desk',
            'recruiting.contracts.index',
        ] as $route) {
            $this->assertSame(
                'recruiting.training-review.index',
                RecruitingRouteGate::redirectTo(false, true, $route),
                "Route {$route} darf fuer Teamleiter nicht offen sein.",
            );
        }
    }

    public function test_teamleiter_darf_seine_eigene_ansicht(): void
    {
        $this->assertNull(RecruitingRouteGate::redirectTo(false, true, 'recruiting.training-review.index'));
        $this->assertNull(RecruitingRouteGate::redirectTo(false, true, 'recruiting.training-review.show'));
    }

    public function test_veranstaltungskonto_darf_die_bewertungsansicht_nicht(): void
    {
        // Die Listen sind getrennt: wer nur Veranstaltungen sehen darf,
        // bewertet nicht.
        $this->assertSame(
            'recruiting.dispo.events.index',
            RecruitingRouteGate::redirectTo(true, false, 'recruiting.training-review.index'),
        );
    }

    public function test_konto_auf_beiden_listen_darf_beides(): void
    {
        $this->assertNull(RecruitingRouteGate::redirectTo(true, true, 'recruiting.dispo.events.index'));
        $this->assertNull(RecruitingRouteGate::redirectTo(true, true, 'recruiting.training-review.show'));
        $this->assertSame(
            'recruiting.dispo.events.index',
            RecruitingRouteGate::redirectTo(true, true, 'recruiting.dashboard'),
        );
    }

    public function test_fremde_module_bleiben_unangetastet(): void
    {
        $this->assertNull(RecruitingRouteGate::redirectTo(true, true, 'crm.contacts.index'));
        $this->assertNull(RecruitingRouteGate::redirectTo(false, true, 'platform.logout'));
    }

    public function test_core_landeseite_fuehrt_direkt_zur_erlaubten_ansicht(): void
    {
        // Nach dem SSO-Login nicht erst durchs Dashboard klicken.
        $this->assertSame(
            'recruiting.training-review.index',
            RecruitingRouteGate::redirectTo(false, true, 'platform.dashboard'),
        );
    }
}
