<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Lohnangaben;
use Platform\Recruiting\Services\OffenePunkte;

/**
 * Was fehlt in der Akte, damit die Abrechnung stimmen kann: offene Nachweise
 * (OffenePunkte) plus lohnrelevante Angaben im Profil (Lohnangaben) — dieselbe
 * Liste wie der Portal-Start. Eine Stelle fuer Lohn-Klick, Parken und Freigabe.
 */
class LohnLuecken
{
    public function __construct(
        private readonly ?OffenePunkte $offenePunkte = null,
        private readonly ?Lohnangaben $lohnangaben = null,
    ) {}

    /** @return list<string> Beschriftungen, leer = vollstaendig */
    public function fuer(RecEmployee $employee): array
    {
        $g = $this->getrennt($employee);

        return array_values(array_unique([...$g['nachweise'], ...$g['angaben']]));
    }

    /**
     * Getrennt nach Herkunft: die Vorlage „Personalakte unvollständig“ geht
     * nur bei offenen Nachweisen raus (HrKontoAktionen::erinnereAufgaben).
     *
     * @return array{nachweise: list<string>, angaben: list<string>}
     */
    public function getrennt(RecEmployee $employee): array
    {
        $punkte = ($this->offenePunkte ?? app(OffenePunkte::class))->fuer($employee)['punkte'] ?? [];

        return [
            'nachweise' => array_values(array_column($punkte, 'label')),
            'angaben' => array_values(($this->lohnangaben ?? new Lohnangaben())->fehlendFuer($employee)),
        ];
    }

    /** Portal-Link nur im neuen Portal — das alte kennt die Nachreichung nicht. */
    public static function portalUrl(RecEmployee $employee): ?string
    {
        return $employee->portal_v2_since !== null && (string) $employee->portal_token !== ''
            ? route('recruiting.public.employee-portal', ['token' => $employee->portal_token])
            : null;
    }
}
