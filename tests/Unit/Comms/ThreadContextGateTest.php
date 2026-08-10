<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ThreadContextGate;

final class ThreadContextGateTest extends TestCase
{
    // ── blocksIntake ─────────────────────────────────────────────────

    public static function allowedContextsProvider(): array
    {
        return [
            'kein Kontext (neuer Thread)' => [null],
            'leerer String'               => [''],
            'Bewerber (Morph-Alias)'      => ['rec_applicant'],
            'Bewerber (volle Klasse)'     => ['Platform\\Recruiting\\Models\\RecApplicant'],
            'CRM-Kontakt (volle Klasse)'  => ['Platform\\Crm\\Models\\CrmContact'],
            'CRM-Kontakt (Morph-Alias)'   => ['crm_contact'],
        ];
    }

    #[DataProvider('allowedContextsProvider')]
    public function test_erlaubte_kontexte_blocken_nicht(?string $contextModel): void
    {
        $this->assertFalse(ThreadContextGate::blocksIntake($contextModel));
    }

    public static function blockedContextsProvider(): array
    {
        return [
            'HCM-Onboarding'          => ['hcm_onboarding'],
            'HCM-Onboarding (Klasse)' => ['Platform\\Hcm\\Models\\HcmOnboarding'],
            'Helpdesk-Ticket'         => ['helpdesk_ticket'],
            'Mitarbeiter'             => ['Platform\\Recruiting\\Models\\RecEmployee'],
            'CRM-Company'             => ['Platform\\Crm\\Models\\CrmCompany'],
            'CRM-Company (Alias)'     => ['crm_company'],
        ];
    }

    #[DataProvider('blockedContextsProvider')]
    public function test_fachkontexte_blocken_weiterhin(string $contextModel): void
    {
        $this->assertTrue(ThreadContextGate::blocksIntake($contextModel));
    }

    // ── isBareContactContext ─────────────────────────────────────────

    public function test_crm_kontakt_ist_bare_contact(): void
    {
        $this->assertTrue(ThreadContextGate::isBareContactContext('Platform\\Crm\\Models\\CrmContact'));
        $this->assertTrue(ThreadContextGate::isBareContactContext('crm_contact'));
    }

    public function test_andere_kontexte_sind_kein_bare_contact(): void
    {
        $this->assertFalse(ThreadContextGate::isBareContactContext(null));
        $this->assertFalse(ThreadContextGate::isBareContactContext('rec_applicant'));
        $this->assertFalse(ThreadContextGate::isBareContactContext('hcm_onboarding'));
        $this->assertFalse(ThreadContextGate::isBareContactContext('Platform\\Crm\\Models\\CrmCompany'));
    }
}
