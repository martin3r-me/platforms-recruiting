<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\PortalVorlagen;

final class PortalVorlagenTest extends TestCase
{
    public function test_rumpfplatzhalter_reihenfolge_doppelte_und_nur_body(): void
    {
        $komponenten = [
            ['type' => 'HEADER', 'text' => 'Hallo {{kopf}}'],
            ['type' => 'BODY', 'text' => 'Hi {{Name}}, Code {{code}}, nochmal {{Name}}'],
            ['type' => 'FOOTER', 'text' => '{{fuss}}'],
        ];
        $this->assertSame(['Name', 'code'], PortalVorlagen::rumpfPlatzhalter($komponenten));
        $this->assertSame([], PortalVorlagen::rumpfPlatzhalter([]));
    }

    public function test_knopf_mit_parameter_alle_faelle(): void
    {
        $knoepfe = fn (array $k) => [['type' => 'BODY', 'text' => 'x'], ['type' => 'BUTTONS', 'buttons' => $k]];

        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'URL', 'url' => 'https://a.de/x']])));
        $this->assertTrue(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'URL', 'url' => 'https://a.de/{{1}}']])));
        $this->assertTrue(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'COPY_CODE', 'example' => 'X']])));
        $this->assertTrue(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'otp']])));
        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'QUICK_REPLY', 'text' => 'ok']])));
        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter([['type' => 'BODY', 'text' => 'x']]));
        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter([]));
    }

    public function test_vorgaben_stehen_in_default_settings(): void
    {
        foreach ([PortalVorlagen::CODE_PASSWORT, PortalVorlagen::CODE_NUMMERNWECHSEL, PortalVorlagen::CODE_NOTFALL,
            PortalVorlagen::HINWEIS_ALTE_NUMMER, PortalVorlagen::AUFGABEN_NEU, PortalVorlagen::EINLADUNG] as $schluessel) {
            $this->assertArrayHasKey($schluessel, RecApplicantSettings::DEFAULT_SETTINGS);
            $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS[$schluessel]);
        }
        $this->assertFalse(RecApplicantSettings::DEFAULT_SETTINGS[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);
    }

    /** Acht seit Markus 12.10.2026 („Offene Aufgaben — Erinnerung" entfallen), neun seit der Einladung zum Konto (19.10.2026). */
    public function test_feldnamen_decken_genau_die_neun_schluessel_ab(): void
    {
        $this->assertSame([
            'konto_code_passwort_wa_template_id'       => 'Code: Passwort vergessen',
            'konto_code_nummernwechsel_wa_template_id' => 'Code: neue Nummer bestätigen',
            'konto_code_notfall_wa_template_id'        => 'Code: Notfall-Weg',
            'konto_hinweis_alte_nummer_wa_template_id' => 'Hinweis an die alte Nummer',
            'aufgaben_neu_wa_template_id'              => 'Offene Aufgaben — neu',
            'konto_einladung_wa_template_id'           => 'Einladung zum Konto',
            'proof_reminder_wa_template_id'            => 'Nachweis läuft ab (Fristenlauf)',
            'document_wa_template_id'                  => 'Dokument liegt im Portal',
            'employee_contract_wa_template_id'         => 'Vertrag zur Unterschrift',
        ], PortalVorlagen::FELDNAMEN);
    }

    public function test_kopfzeile_braucht_parameter_alle_faelle(): void
    {
        $body = ['type' => 'BODY', 'text' => 'x'];

        $this->assertFalse(PortalVorlagen::brauchtKopfParameter([['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Fest'], $body]));
        $this->assertTrue(PortalVorlagen::brauchtKopfParameter([['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hallo {{1}}'], $body]));
        $this->assertTrue(PortalVorlagen::brauchtKopfParameter([['type' => 'HEADER', 'format' => 'image'], $body]));
        $this->assertTrue(PortalVorlagen::brauchtKopfParameter([['type' => 'HEADER', 'format' => 'DOCUMENT'], $body]));
        $this->assertFalse(PortalVorlagen::brauchtKopfParameter([$body]));
        $this->assertFalse(PortalVorlagen::brauchtKopfParameter([]));
    }

    public function test_einladungs_knopf_wird_am_pfad_der_konto_anlage_erkannt(): void
    {
        $knoepfe = static fn (array $b): array => [['type' => 'BODY', 'text' => 'x'], ['type' => 'BUTTONS', 'buttons' => $b]];

        $this->assertSame([1], PortalVorlagen::einladungsKnopfPositionen($knoepfe([
            ['type' => 'QUICK_REPLY', 'text' => 'ok'],
            ['type' => 'URL', 'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/konto/anlegen/{{1}}'],
        ])));
        $this->assertSame([], PortalVorlagen::einladungsKnopfPositionen($knoepfe([
            ['type' => 'URL', 'url' => 'https://meingedeck.de/recruiting/mitarbeiter/neu/{{1}}'],
        ])), 'Portal-Link ist kein Einladungs-Knopf');
        $this->assertSame([], PortalVorlagen::einladungsKnopfPositionen($knoepfe([
            ['type' => 'URL', 'url' => 'https://a.de/recruiting/konto/anlegen'],
        ])), 'ohne Variable kein Einladungs-Knopf');
        $this->assertSame([], PortalVorlagen::einladungsKnopfPositionen([]));
    }
}
