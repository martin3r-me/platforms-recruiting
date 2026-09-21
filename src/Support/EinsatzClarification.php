<?php

namespace Platform\Recruiting\Support;

/**
 * KLAERUNG IM DISPO-ABGLEICH (Kundenwunsch 21.09.2026).
 *
 * Die Statistik kann je Teilgenommenem nur eine Frage stellen: gibt es eine
 * Dispo-Zuweisung zu seiner ZAS-Personalnummer? Nein heisst „ohne Einsatz" —
 * und damit Arbeitsliste. Warum es keine Zuweisung gibt, weiss aber nur der
 * Mensch: „faengt erst naechsten Monat an", „mit der Dispo geklaert, es passte
 * nur noch kein Termin". Genau diese Faelle bekommen einen Haken und wandern
 * in einen vierten Topf — sie sind kein Erfolg (kein Einsatz) und kein
 * Handlungsbedarf.
 *
 * Der Haken ist DATIERBAR: „faengt naechsten Monat an" laeuft ab. Ohne
 * Wiedervorlage waere der Mensch fuer immer aus der Liste und die Zahl wuerde
 * still luegen, wenn er im Oktober doch nicht anfaengt.
 *
 * Pure Entscheidungslogik ohne Framework (Muster SeatStandbyPolicy,
 * BookingAftercare) — der Haken haengt an der BUCHUNG, nicht am Bewerber: die
 * Arbeitsliste ist die einzelne Schulung, und wer in einem halben Jahr eine
 * zweite Runde mitmacht, bringt das alte Haekchen nicht mit.
 */
final class EinsatzClarification
{
    /**
     * Gilt der Haken heute noch?
     *
     * @param  ?string  $geklaertAt        Zeitstempel des Hakens (null = nicht abgehakt)
     * @param  ?string  $wiedervorlageAm   Y-m-d, ab dem die Person wieder auf der Liste steht (null = dauerhaft)
     * @param  string   $heuteYmd          Y-m-d des Bezugstags
     */
    public static function isActive(?string $geklaertAt, ?string $wiedervorlageAm, string $heuteYmd): bool
    {
        if ($geklaertAt === null || trim($geklaertAt) === '') {
            return false;
        }
        if ($wiedervorlageAm === null || trim($wiedervorlageAm) === '') {
            return true;
        }

        // Fail-visible: ein unlesbares Datum nimmt niemanden dauerhaft aus der
        // Arbeitsliste — lieber einmal zu viel nachfassen als einen Menschen
        // still verschwinden lassen.
        if (!YmdDate::isValid($wiedervorlageAm)) {
            return false;
        }

        // Y-m-d ist lexikographisch sortierbar; „wieder anzeigen ab" heisst:
        // AM Wiedervorlage-Tag ist der Fall wieder offen.
        return $heuteYmd < $wiedervorlageAm;
    }

    /**
     * Der Topf einer Bewerbung im Dispo-Abgleich. Einzige Quelle der
     * Zuordnung — der Assigner verteilt nur noch, was hier entschieden wird.
     *
     * @param  ?string  $einsatzFlag  EinsatzLookup::FLAG_* (deployed/none/unverifiable)
     * @return ?string  im_einsatz | geklaert | ohne_einsatz | einsatz_unpruefbar
     */
    public static function topf(?string $einsatzFlag, bool $geklaertAktiv): ?string
    {
        return match ($einsatzFlag) {
            'deployed' => 'im_einsatz',
            'none' => $geklaertAktiv ? 'geklaert' : 'ohne_einsatz',
            'unverifiable' => 'einsatz_unpruefbar',
            default => null,
        };
    }
}
