<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;

/**
 * Vier-Augen-Freigabe fuer WhatsApp-Anfragen „Jobs anbieten“ (Spec 2026-11-13 §6.4,
 * Markus 13.11.2026). Nur der hinterlegte Freigeber entscheidet; die Pruefung steht
 * hier und nicht nur in der Ansicht. Jede Entscheidung ist ein bedingtes Update —
 * Doppelklick oder zweiter Tab treffen 0 Zeilen und starten nichts ein zweites Mal.
 *
 * Nicht final: Tests ueberschreiben welleStarten() und zaehlen ohne Bus
 * (gleiches Muster wie SendDispoJobWave::folgewelleStarten).
 */
class DispoJobFreigabe
{
    public const OK = 'ok';
    public const NICHT_BERECHTIGT = 'nicht_berechtigt';
    public const NICHT_WARTEND = 'nicht_wartend';
    /** Entschieden und gespeichert, aber keine wartende Welle da (Altfall: vorher abgebrochen) — es startet nichts. */
    public const KEINE_WELLE = 'keine_welle';

    public const GRUND_MAX = 255;

    public static function freigeben(RecDispoJobRequest $r, mixed $user, int $teamId): string
    {
        if (!DispoJobVersandweg::istFreigeber(RecApplicantSettings::getOrCreateForTeam($teamId), $user)) {
            return self::NICHT_BERECHTIGT;
        }
        $userId = self::userId($user);
        $n = self::wartendQuery()->whereKey($r->id)->where('team_id', $teamId)
            ->update(['freigegeben_at' => now(), 'freigegeben_by_user_id' => $userId, 'updated_at' => now()]);
        if ($n !== 1) {
            return self::NICHT_WARTEND;
        }
        $r->refresh();

        return self::ersteWelleStarten($r, $teamId, $userId) ? self::OK : self::KEINE_WELLE;
    }

    /** Freigeber aus den Einstellungen des Teams der Anfrage (Show laedt sie team-gescopt). */
    public static function ablehnen(RecDispoJobRequest $r, mixed $user, string $grund): string
    {
        $teamId = (int) $r->team_id;
        if (!DispoJobVersandweg::istFreigeber(RecApplicantSettings::getOrCreateForTeam($teamId), $user)) {
            return self::NICHT_BERECHTIGT;
        }
        $grund = mb_substr(trim($grund), 0, self::GRUND_MAX);
        $n = self::wartendQuery()->whereKey($r->id)->where('team_id', $teamId)->update([
            'freigabe_abgelehnt_at'         => now(),
            'freigabe_abgelehnt_by_user_id' => self::userId($user),
            'freigabe_ablehnungsgrund'      => $grund !== '' ? $grund : null,
            'updated_at'                    => now(),
        ]);
        if ($n !== 1) {
            return self::NICHT_WARTEND;
        }
        $r->refresh();

        return self::OK;
    }

    /**
     * „Stattdessen im Portal bereitstellen“ nach einer Ablehnung — darf jeder Disponent,
     * im Portal geht nichts per WhatsApp raus. Die Ablehnung bleibt als Verlauf stehen;
     * ohne Pflicht gilt darfSenden(), Welle 1 startet.
     */
    public static function insPortal(RecDispoJobRequest $r, int $teamId, ?int $userId): string
    {
        $n = RecDispoJobRequest::query()->whereKey($r->id)->where('team_id', $teamId)
            ->where('freigabe_pflicht', true)->whereNull('freigegeben_at')->whereNotNull('freigabe_abgelehnt_at')
            ->whereNull('closed_at')->where('status', '!=', RecDispoJobRequest::STATUS_ABGESCHLOSSEN)
            ->update(['versandweg' => RecDispoJobRequest::WEG_PORTAL, 'freigabe_pflicht' => false, 'updated_at' => now()]);
        if ($n !== 1) {
            return self::NICHT_WARTEND;
        }
        $r->refresh();

        return self::ersteWelleStarten($r, $teamId, $userId) ? self::OK : self::KEINE_WELLE;
    }

    /** Zaehler fuer Uebersicht und Sidebar. Wirft nie (Sidebar). */
    public static function wartend(?int $teamId = null): int
    {
        try {
            $teamId ??= (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));
            if ($teamId <= 0) {
                return 0;
            }

            return self::wartendQuery()->where('team_id', $teamId)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Gleiche Menge wie RecDispoJobRequest::wartetAufFreigabe(), plus Status. */
    private static function wartendQuery()
    {
        return RecDispoJobRequest::query()
            ->where('freigabe_pflicht', true)
            ->whereNull('freigegeben_at')
            ->whereNull('freigabe_abgelehnt_at')
            ->whereNull('closed_at')
            ->where('status', '!=', RecDispoJobRequest::STATUS_ABGESCHLOSSEN);
    }

    /**
     * Erste ungestartete Welle (beim Anlegen geschrieben, nicht eingereiht). Ein beim
     * Anlegen gewaehlter Start, der inzwischen vorbei ist, gilt als „sofort“; ein
     * kuenftiger bleibt als Verzoegerung. false = keine Welle da (Gesamtpruefung I2:
     * die Ansicht sagt es, statt „Welle 1 startet“ zu melden).
     */
    private static function ersteWelleStarten(RecDispoJobRequest $r, int $teamId, ?int $userId): bool
    {
        $wave = RecDispoJobWave::query()->where('request_id', $r->id)
            ->whereNull('started_at')->whereNull('finished_at')->orderBy('nr')->first();
        if ($wave === null) {
            return false; // Altfall: vorher abgebrochen — „Neue Welle“ geht jetzt ohne weitere Freigabe
        }
        if ($wave->scheduled_for !== null && !$wave->scheduled_for->isFuture()) {
            $wave->update(['scheduled_for' => null]);
        }
        static::welleStarten($wave, $teamId, $userId);

        return true;
    }

    /** In die Warteschlange — getrennt, damit Tests ohne Bus zaehlen koennen. */
    protected static function welleStarten(RecDispoJobWave $wave, int $teamId, ?int $userId): void
    {
        DispoJobAnfrageAnlegen::starten($wave, $teamId, $userId);
    }

    private static function userId(mixed $user): ?int
    {
        $id = $user?->id ?? null;

        return $id !== null ? (int) $id : null;
    }
}
