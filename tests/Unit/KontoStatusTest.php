<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\KontoStatus;

final class KontoStatusTest extends TestCase
{
    private const JETZT = '2026-10-12 10:00:00';

    private function p(array $f): object
    {
        return (object) array_merge([
            'password_hash' => null, 'registered_at' => null, 'invited_at' => null,
            'invite_expires_at' => null, 'invite_used_at' => null, 'letzte_anmeldung_at' => null,
        ], $f);
    }

    public function test_jeder_zustand_mit_text(): void
    {
        $this->assertSame(KontoStatus::NICHT_EINGELADEN, KontoStatus::code($this->p([]), self::JETZT));
        $this->assertSame('nicht eingeladen', KontoStatus::text($this->p([]), self::JETZT));

        $eingeladen = $this->p(['invited_at' => '2026-10-12 09:00:00', 'invite_expires_at' => '2026-10-19 09:00:00']);
        $this->assertSame(KontoStatus::EINGELADEN, KontoStatus::code($eingeladen, self::JETZT));
        $this->assertSame('eingeladen am 12.10.2026 (gilt bis 19.10.2026)', KontoStatus::text($eingeladen, self::JETZT));

        $genauAbgelaufen = $this->p(['invited_at' => '2026-10-05 10:00:00', 'invite_expires_at' => self::JETZT]);
        $this->assertSame(KontoStatus::ABGELAUFEN, KontoStatus::code($genauAbgelaufen, self::JETZT), 'abgelaufen GENAU an invite_expires_at');
        $eineSekundeVorher = $this->p(['invited_at' => '2026-10-05 10:00:00', 'invite_expires_at' => '2026-10-12 10:00:01']);
        $this->assertSame(KontoStatus::EINGELADEN, KontoStatus::code($eineSekundeVorher, self::JETZT));

        $registriert = $this->p(['password_hash' => 'x', 'registered_at' => '2026-10-05 10:00:00']);
        $this->assertSame(KontoStatus::REGISTRIERT, KontoStatus::code($registriert, self::JETZT));
        $this->assertSame('registriert am 05.10.2026', KontoStatus::text($registriert, self::JETZT));

        $zurueck = $this->p(['registered_at' => '2026-10-05 10:00:00', 'invited_at' => '2026-10-12 09:30:00', 'invite_expires_at' => '2026-10-19 09:30:00']);
        $this->assertSame(KontoStatus::ZURUECKGESETZT, KontoStatus::code($zurueck, self::JETZT));
        $this->assertSame('zurückgesetzt am 12.10.2026 (wartet auf neues Passwort)', KontoStatus::text($zurueck, self::JETZT));
    }

    public function test_filter(): void
    {
        $this->assertSame(['alle', 'nicht_eingeladen'], KontoStatus::filter($this->p([]), self::JETZT));
        $this->assertSame(['alle', 'eingeladen'], KontoStatus::filter($this->p(['invited_at' => '2026-10-12 09:00:00', 'invite_expires_at' => '2026-10-19 09:00:00']), self::JETZT));
        $this->assertSame(['alle', 'abgelaufen'], KontoStatus::filter($this->p(['invited_at' => '2026-10-01 09:00:00', 'invite_expires_at' => '2026-10-08 09:00:00']), self::JETZT));
        $this->assertSame(['alle', 'nie_angemeldet'], KontoStatus::filter($this->p(['password_hash' => 'x', 'registered_at' => '2026-10-05 10:00:00']), self::JETZT));
        $this->assertSame(['alle', 'lange_nicht'], KontoStatus::filter($this->p(['password_hash' => 'x', 'registered_at' => '2026-09-01 10:00:00', 'letzte_anmeldung_at' => '2026-09-28 10:00:00']), self::JETZT), 'genau 14 Tage');
        $this->assertSame(['alle'], KontoStatus::filter($this->p(['password_hash' => 'x', 'registered_at' => '2026-09-01 10:00:00', 'letzte_anmeldung_at' => '2026-10-11 10:00:00']), self::JETZT));
        $this->assertSame(['alle', 'nicht_eingeladen', 'eingeladen', 'abgelaufen', 'nie_angemeldet', 'lange_nicht'], array_keys(KontoStatus::FILTER));
    }
}
