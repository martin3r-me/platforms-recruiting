<?php

namespace Platform\Recruiting\Support;

/**
 * Die EINE Darstellung der offenen Notfall-Antraege aus Weg 4 (Spec §5).
 *
 * WARUM SIE HIER LIEGT UND NICHT IM KOMMANDO. Zwei Stellen zeigen dieselben
 * Antraege: recruiting:konto-zuruecksetzen --offen (die Arbeitsliste, an der
 * HR stoppt) und recruiting:konto-einladen --bericht (der Stand, den HR
 * ohnehin liest — ohne ihn erfaehrt niemand von einem laufenden Antrag, und
 * ein Stopp-Recht, von dem niemand erfaehrt, ist keines).
 *
 * Zwei Fassungen derselben Tafel laufen auseinander. In diesem Zweig ist
 * genau das schon zweimal passiert, und die Folge waere hier besonders
 * unangenehm: HR saehe denselben Antrag an zwei Stellen verschieden — einmal
 * mit dem Zustand, der erklaert, warum er liegen bleibt, einmal ohne.
 *
 * KUERZUNG DER NUMMERN: nur die letzten vier Stellen, dieselbe Kuerzung wie
 * im Protokoll. Eine volle Rufnummer gehoert auf keinen Bildschirm und in
 * kein Log.
 *
 * Reine Logik (kein Framework, kein DB-Zugriff) — die Zeilen kommen aus
 * KontoWriter::offeneNummernwechsel().
 */
final class NummernwechselTafel
{
    /**
     * Die Auskunft, wenn nichts offen ist.
     *
     * Auch sie steht hier und nicht zweimal ausgeschrieben: "kein Antrag"
     * ist die haeufigste Antwort, und zwei Schreibweisen davon saehen aus
     * wie zwei verschiedene Aussagen.
     */
    public const LEER = 'Kein offener Notfall-Antrag.';

    /** @return list<string> */
    public static function kopf(): array
    {
        return ['Person', 'Team', 'alt', 'neu', 'beantragt', 'wirksam ab', 'Quelle', 'Zustand'];
    }

    /**
     * @param  list<object>  $offene  aus KontoWriter::offeneNummernwechsel()
     * @return list<list<int|string>>
     */
    public static function zeilen(array $offene): array
    {
        return array_map(static fn (object $zeile): array => [
            (int) $zeile->id,
            (string) ($zeile->team_id ?? ''),
            '...' . self::kurz((string) ($zeile->phone ?? '')),
            '...' . self::kurz((string) $zeile->wechsel_neue_nummer),
            (string) $zeile->wechsel_beantragt_at,
            (string) $zeile->wechsel_wirksam_ab,
            (string) $zeile->wechsel_quelle,
            self::zustand($zeile),
        ], $offene);
    }

    /**
     * Warum ein Antrag nicht angewendet wird, obwohl er in der Liste steht
     * (Befund G5).
     *
     * Gesperrte und stillgelegte Zeilen kommen seit G5 mit in die Liste —
     * ihr Antrag laesst sich nie anwenden (KontoWriter::wendeNummernwechselAn()
     * geht durch offeneZeile()), und ohne Anzeige stuenden sie dort als
     * unerklaerliche Dauergaeste. Stoppen kann HR sie trotzdem.
     *
     * Diese Antwort entscheidet mehr als die Optik: recruiting:konto-zuruecksetzen
     * --faellig ueberspringt anhand von ihr, was nur zur Anzeige dasteht
     * (Befund N2) — ohne das liefe der stuendliche Lauf dauerhaft auf exit 1.
     */
    public static function zustand(object $zeile): string
    {
        if (($zeile->merged_into_person_id ?? null) !== null) {
            return 'stillgelegt, wird nie angewendet';
        }

        if (($zeile->locked_at ?? null) !== null) {
            return 'gesperrt, wird nie angewendet';
        }

        return 'offen';
    }

    /** Die letzten vier Stellen — dieselbe Kuerzung wie im Protokoll. */
    public static function kurz(?string $nummer): string
    {
        return substr((string) $nummer, -4);
    }
}
