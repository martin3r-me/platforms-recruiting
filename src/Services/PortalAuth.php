<?php

namespace Platform\Recruiting\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Die Anmeldeschicht des Mitarbeiterportals — als eigene Schicht, damit das
 * Konto aus Canvas 68 spaeter eingehaengt werden kann, ohne die Huelle
 * anzufassen (Bauvorschrift aus Canvas 67, Eintrag 1788).
 *
 * Heute beantwortet sie die Frage „wer ist das?" mit Token plus Geburtsdatum
 * und Ausweis-Endziffern. Spaeter mit Handynummer und Passwort. Die vier
 * Portal-Bereiche wissen nichts davon, wie die Antwort zustande kam.
 *
 * Was hier NICHT liegt: der Datenabgleich selbst (RecEmployee::verifyPortalAccess)
 * und das Sitzungs-Flag — Ersteres gehoert zum Mitarbeiter, Letzteres zur
 * Oberflaeche.
 *
 * Der Versuchszaehler haengt am TOKEN, nicht am Mitarbeiter: Wer einen fremden
 * Link durchprobiert, soll sich nicht durch Wechseln der Kennung freischalten.
 */
final class PortalAuth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    public const OK      = 'ok';
    public const FALSCH  = 'falsch';
    public const GESPERRT = 'gesperrt';

    public function __construct(private readonly ?CacheRepository $cache = null) {}

    public function employeeForToken(string $token): ?RecEmployee
    {
        $employee = RecEmployee::query()->where('portal_token', $token)->first();

        return ($employee && $employee->is_active) ? $employee : null;
    }

    /** Eskalations-Stufe-3-Sperre aus der Dispo (DispoEmployeeGateway::lockPortal). */
    public function isLocked(RecEmployee $employee): bool
    {
        return $employee->portal_locked_at !== null;
    }

    public function isRateLimited(string $token): bool
    {
        return (bool) $this->store()->get($this->lockoutKey($token), false);
    }

    /**
     * Ein Anmeldeversuch.
     *
     * @return array{status: string, verbleibend: int}
     */
    public function attempt(RecEmployee $employee, string $token, string $birthDate, string $idLast4): array
    {
        if ($this->isRateLimited($token)) {
            return ['status' => self::GESPERRT, 'verbleibend' => 0];
        }

        if ($employee->verifyPortalAccess(trim($birthDate), trim($idLast4))) {
            $this->clearFailures($token);

            return ['status' => self::OK, 'verbleibend' => self::MAX_ATTEMPTS];
        }

        $versuche = $this->recordFailure($token);
        $verbleibend = max(0, self::MAX_ATTEMPTS - $versuche);

        return [
            'status'      => $verbleibend === 0 ? self::GESPERRT : self::FALSCH,
            'verbleibend' => $verbleibend,
        ];
    }

    public function clearFailures(string $token): void
    {
        $this->store()->forget($this->attemptsKey($token));
        $this->store()->forget($this->lockoutKey($token));
    }

    /** @return int Anzahl der Fehlversuche nach diesem hier. */
    private function recordFailure(string $token): int
    {
        $versuche = ((int) $this->store()->get($this->attemptsKey($token), 0)) + 1;
        $this->store()->put($this->attemptsKey($token), $versuche, now()->addMinutes(self::LOCKOUT_MINUTES));

        if ($versuche >= self::MAX_ATTEMPTS) {
            $this->store()->put($this->lockoutKey($token), true, now()->addMinutes(self::LOCKOUT_MINUTES));
        }

        return $versuche;
    }

    private function store(): CacheRepository
    {
        return $this->cache ?? Cache::store();
    }

    private function attemptsKey(string $token): string
    {
        return "employee_portal_attempts:{$token}";
    }

    /**
     * DIESELBEN Schluessel wie im alten EmployeePortal — solange beide
     * nebeneinander laufen, muss eine Sperre in beiden gelten. Ein eigener
     * Schluessel hiesse: im alten gesperrt, im neuen offen.
     */
    private function lockoutKey(string $token): string
    {
        return "employee_portal_locked:{$token}";
    }

    public static function sessionKey(int $employeeId): string
    {
        return "employee_portal_verified:{$employeeId}";
    }
}
