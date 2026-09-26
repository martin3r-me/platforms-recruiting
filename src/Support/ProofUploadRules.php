<?php

namespace Platform\Recruiting\Support;

/**
 * Regeln fuers Hochladen eines Nachweises: welche Datei ist zulaessig, und
 * welches Ablaufdatum. Die spaetere Livewire-Strecke fragt hier nur ab, sie
 * entscheidet nichts selbst — so bleibt die Entscheidung ohne Framework/DB
 * pruefbar (kein Formular noetig, um zu sehen, ob ein Datum durchgeht).
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class ProofUploadRules
{
    /** Handyfotos und PDF. Keine Office-Formate — die kann niemand ansehen. */
    public const MIME_TYPES = ['jpg', 'jpeg', 'png', 'heic', 'pdf'];
    public const MAX_KB = 12288;   // 12 MB, ein iPhone-Foto liegt bei 3-5
    public const MAX_JAHRE = 10;

    /**
     * Klartext-Grund, warum das Datum nicht geht — oder null, wenn alles passt.
     *
     * $duzen (Schlussfix F6, 26.09.2026): genau EINE dieser Meldungen spricht
     * den Menschen an ("Bitte trag ein ..."); sie stand nur in der Du-Form,
     * obwohl die Anrede im Portal an der Team-Einstellung haengt. Die
     * uebrigen vier beschreiben das DATUM und nicht den Menschen — sie
     * brauchen keine Anrede und bekommen auch keine.
     *
     * Der Parameter hat bewusst KEINE Vorgabe: der einzige Aufrufer
     * (PortalShell) kennt die Anrede, und ein neuer Aufrufer soll sich
     * entscheiden muessen, statt still zu duzen.
     */
    public static function pruefeDatum(string $code, ?string $datum, string $heute, bool $duzen): ?string
    {
        if (!ProofTypes::exists($code)) {
            return 'Diese Nachweisart kennen wir nicht.';
        }

        $hatAblauf = ProofTypes::hasExpiry($code);
        $roh = trim((string) $datum);

        if (!$hatAblauf) {
            return $roh === '' ? null : 'Diese Unterlage hat kein Ablaufdatum.';
        }

        if ($roh === '') {
            return $duzen
                ? 'Bitte trag ein, bis wann der Nachweis gültig ist.'
                : 'Bitte tragen Sie ein, bis wann der Nachweis gültig ist.';
        }
        if (!self::istEchtesDatum($roh)) {
            return 'Das Datum können wir nicht lesen.';
        }
        if ($roh < $heute) {
            return 'Dieses Datum liegt in der Vergangenheit.';
        }
        if ($roh > date('Y-m-d', strtotime($heute . ' +' . self::MAX_JAHRE . ' years'))) {
            return 'Das Datum liegt mehr als ' . self::MAX_JAHRE . ' Jahre in der Zukunft — bitte prüfen.';
        }

        return null;
    }

    /**
     * Nur echte Kalenderdaten im Format Y-m-d durchlassen — '0000-00-00' und
     * Freitext wie 'morgen' sind keine, auch wenn strtotime() sie erraet.
     */
    private static function istEchtesDatum(string $wert): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $m)) {
            return false;
        }

        return (int) $m[1] >= 1900 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
