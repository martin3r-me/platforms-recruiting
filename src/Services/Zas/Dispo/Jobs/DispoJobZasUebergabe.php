<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Hinweis je Zusage-Zeile zum Stand in ZAS (Spec 2026-10-30 §4/§5). Rein.
 *
 * ZAS meldet nicht zurueck, ob eine uebergebene Buchung angenommen wurde.
 * Ersatz: kam nach der Uebergabe (plus Abstand) eine Dispo-Lieferung und die
 * Zusage ist trotzdem nicht als Auftrag angekommen, steht sie in ZAS nicht
 * oder „zum Bearbeiten markiert“ — dann sieht Sheran das hier.
 */
final class DispoJobZasUebergabe
{
    /** Olaf stoesst den Webexport von Hand an — eine Lieferung kurz nach der Uebergabe kann unsere Datei noch nicht enthalten. */
    public const LIEFERUNG_ABSTAND_MINUTEN = 60;

    public const ART_ANGEKOMMEN = 'angekommen';
    public const ART_AUSSTEHEND = 'ausstehend';
    public const ART_UEBERGEBEN = 'uebergeben';
    public const ART_HAND = 'hand';
    /** ZAS fuehrt die Person an dem Tag auf dieser VA, aber in einer anderen Schicht (Markus 06.11.). */
    public const ART_ANDERS = 'anders';

    /**
     * @param ?string $andereZeit Schicht, in der ZAS die Person stattdessen fuehrt („17:00–03:00“), sonst null
     * @return array{art:string, text:string}|null null = nichts anzeigen (Haken wie bisher)
     */
    public static function hinweis(
        bool $angekommen,
        ?\DateTimeInterface $uebergebenAm,
        ?\DateTimeInterface $hakenAm,
        ?string $posten,
        ?string $personalnummer,
        ?\DateTimeInterface $letzteLieferung,
        ?string $andereZeit = null,
    ): ?array {
        if ($angekommen && $andereZeit !== null) {
            return ['art' => self::ART_ANDERS, 'text' => '✓ in ZAS, dort ' . $andereZeit];
        }
        if ($angekommen) {
            return ['art' => self::ART_ANGEKOMMEN, 'text' => '✓ in ZAS angekommen'];
        }
        if ($andereZeit !== null) {
            return ['art' => self::ART_ANDERS, 'text' => 'in ZAS auf ' . $andereZeit . ' · hier nicht'];
        }
        if ($uebergebenAm !== null) {
            $wann = $uebergebenAm->format('d.m. H:i');
            if (self::lieferungNachUebergabe($uebergebenAm, $letzteLieferung)) {
                return ['art' => self::ART_AUSSTEHEND, 'text' => 'an ZAS übergeben ' . $wann . ' · noch nicht angekommen'];
            }

            return ['art' => self::ART_UEBERGEBEN, 'text' => 'an ZAS übergeben ' . $wann];
        }
        if ($hakenAm !== null) {
            return null;
        }
        if (trim((string) $posten) === '') {
            return ['art' => self::ART_HAND, 'text' => 'von Hand eintragen: kein ZAS-Posten'];
        }
        if (trim((string) $personalnummer) === '') {
            return ['art' => self::ART_HAND, 'text' => 'von Hand eintragen: keine Personalnummer'];
        }
        if (!\Platform\Recruiting\Support\ZasPersonnelNumber::hasPrefix($personalnummer)) {
            // Reine Ziffern sind zwischen RG und MA nicht eindeutig — ZAS koennte falsch zuordnen.
            return ['art' => self::ART_HAND, 'text' => 'von Hand eintragen: Personalnummer ohne Firmenkennung'];
        }

        return null;
    }

    public static function lieferungNachUebergabe(\DateTimeInterface $uebergebenAm, ?\DateTimeInterface $letzteLieferung): bool
    {
        if ($letzteLieferung === null) {
            return false;
        }
        $grenze = \Carbon\CarbonImmutable::instance($uebergebenAm)->addMinutes(self::LIEFERUNG_ABSTAND_MINUTEN);

        return \Carbon\CarbonImmutable::instance($letzteLieferung)->greaterThanOrEqualTo($grenze);
    }
}
