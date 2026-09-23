<?php

namespace Platform\Recruiting\Support;

/**
 * Wessen Nachweise gehoeren zu dieser Person?
 *
 * Der `person_key` allein reicht nicht. Er steuert ab dem Portal, WAS ein
 * angemeldeter Mensch sieht — Ausweis, Bankdaten, Vertraege. Eine falsche
 * Paarung waere damit kein Statistikfehler mehr, sondern fremde Daten auf dem
 * Bildschirm. Deshalb verlangen wir ein zweites, unabhaengiges Merkmal:
 * dieselbe Handynummer (Regel aus Canvas 68).
 *
 * Aus dem Bestand am 23.09.2026: 336 Gruppen, davon 332 mit gleicher Nummer.
 * Vier Faelle fallen durch — zu wenige fuer einen eigenen Ablauf im Portal,
 * sie gehen auf die HR-Liste. Im Zweifel zeigen wir WENIGER, nicht mehr.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PersonProofScope
{
    /**
     * @param  array{id:int, person_key:?string, phone:?string}        $self
     * @param  list<array{id:int, person_key:?string, phone:?string}>  $geschwister
     *         Datensaetze mit demselben person_key, ohne den eigenen.
     * @return array{ids: list<int>, abweichend: list<int>}
     *         ids — deren Nachweise gelten als die der Person (immer inkl. self)
     *         abweichend — gleicher Marker, andere Nummer: gehoert auf die HR-Liste
     */
    public static function resolve(array $self, array $geschwister): array
    {
        $ids = [(int) $self['id']];
        $abweichend = [];

        $eigenerKey = trim((string) ($self['person_key'] ?? ''));
        $eigenerSuffix = PhoneE164::suffix($self['phone'] ?? null);

        foreach ($geschwister as $kandidat) {
            $key = trim((string) ($kandidat['person_key'] ?? ''));

            // Ohne gemeinsamen Marker ist es keine Geschwisterzeile — die
            // gehoert hier gar nicht her und zaehlt auch nicht als Zweifelsfall.
            if ($eigenerKey === '' || $key === '' || $key !== $eigenerKey) {
                continue;
            }

            $suffix = PhoneE164::suffix($kandidat['phone'] ?? null);

            // Leere Suffixe duerfen sich NICHT gegenseitig bestaetigen:
            // zwei Datensaetze ohne brauchbare Nummer sind kein Nachweis.
            if ($eigenerSuffix !== '' && $suffix === $eigenerSuffix) {
                $ids[] = (int) $kandidat['id'];
                continue;
            }

            $abweichend[] = (int) $kandidat['id'];
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'abweichend' => array_values(array_unique($abweichend)),
        ];
    }
}
