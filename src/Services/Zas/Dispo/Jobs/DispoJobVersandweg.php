<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasStatus;

/**
 * Jobs anbieten: Schalter, Freigeber und Wegwahl (Spec 2026-11-13, 6.1-6.3).
 *
 * Schalter aus = der heutige Stand (WhatsApp sofort, keine Freigabe). Der
 * Freigeber wird per E-MAIL geführt (kleingeschrieben verglichen), weil das SSO
 * Konten anhand der E-Mail anlegt — gleiches Muster wie DispoAccess.
 */
final class DispoJobVersandweg
{
    public const SETTING_AKTIV = 'dispo_job_versandweg_aktiv';
    public const SETTING_FREIGEBER = 'dispo_job_freigeber_email';

    public const WEG_WHATSAPP = 'whatsapp';
    public const WEG_PORTAL = 'portal';

    /** Anlaesse mit Wegwahl; „dringend“ geht immer per WhatsApp. */
    private const WAEHLBARE_ANLAESSE = ['vier_wochen', 'naechste_woche'];

    public static function aktiv(RecApplicantSettings $s): bool
    {
        return (bool) $s->getSetting(self::SETTING_AKTIV);
    }

    public static function freigeberEmail(RecApplicantSettings $s): ?string
    {
        return self::normalisiert($s->getSetting(self::SETTING_FREIGEBER));
    }

    public static function istFreigeber(RecApplicantSettings $s, mixed $user): bool
    {
        $freigeber = self::freigeberEmail($s);
        $email = self::normalisiert($user?->email ?? null);

        return $freigeber !== null && $email !== null && $freigeber === $email;
    }

    /**
     * Sebastian 13.11.2026: ohne Freigeber darf jeder (Ersteintrag beim Rollout),
     * danach nur noch der Freigeber — sonst trägt sich ein Disponent selbst ein.
     */
    public static function darfEinstellungenAendern(?string $freigeberEmail, mixed $user): bool
    {
        $freigeber = self::normalisiert($freigeberEmail);
        if ($freigeber === null) {
            return true;
        }

        return $freigeber === self::normalisiert($user?->email ?? null);
    }

    public static function waehlbar(bool $aktiv, string $anlass): bool
    {
        return $aktiv && in_array($anlass, self::WAEHLBARE_ANLAESSE, true);
    }

    /**
     * @return array{weg:string, freigabe_pflicht:bool}
     */
    public static function fuerAnfrage(bool $aktiv, string $anlass, string $wunsch): array
    {
        if (!self::waehlbar($aktiv, $anlass)) {
            return ['weg' => self::WEG_WHATSAPP, 'freigabe_pflicht' => false];
        }
        if ($wunsch === self::WEG_PORTAL) {
            return ['weg' => self::WEG_PORTAL, 'freigabe_pflicht' => false];
        }

        return ['weg' => self::WEG_WHATSAPP, 'freigabe_pflicht' => true];
    }

    /**
     * Aktive Mitarbeiter mit Status GO oder MA, die noch nicht aufs neue Portal
     * umgestellt sind (Spec 6.3). Statusquelle wie DispoJobKandidaten: export_status
     * am HR-Datensatz, ohne Wert/ohne Datensatz = GO.
     */
    public static function ohneNeuesPortal(int $teamId): int
    {
        return RecEmployee::query()
            ->with('hrData:id,rec_employee_id,export_status')
            ->where('team_id', $teamId)->where('is_active', true)->whereNull('portal_v2_since')
            ->get(['id'])
            ->filter(function ($e) {
                $status = ZasStatus::normalize($e->hrData?->export_status) ?? ZasStatus::GO;

                return $status === ZasStatus::GO || $status === ZasStatus::MA;
            })
            ->count();
    }

    private static function normalisiert(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) ($email ?? '')));

        return $email === '' ? null : $email;
    }
}
