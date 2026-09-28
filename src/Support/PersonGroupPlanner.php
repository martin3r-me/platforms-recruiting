<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Anstellungen teilen sich eine Personen-Zeile?
 *
 * Der Backfill darf nicht raten. Zusammengezogen wird NUR bei hartem Beleg:
 * derselbe nicht-leere person_key. Ein leerer Marker gruppiert nie — zwei
 * Datensaetze ohne Marker sind kein Beleg fuer denselben Menschen (dasselbe
 * Prinzip wie in PersonProofScope: im Zweifel weniger zusammenfassen).
 *
 * Die Nummer der Gruppe entscheidet der juengste Datensatz mit Nummer
 * (Canvas 68: "der zuletzt geaenderte Wert gewinnt"). Bei gleichem
 * Zeitstempel gewinnt die kleinere Kennung, damit zwei Laeufe dasselbe
 * Ergebnis liefern — ein Backfill, der beim Wiederholen andere Nummern
 * setzt, waere nicht pruefbar.
 *
 * phone_uneinig meldet Gruppen, in denen zwei VERSCHIEDENE Nummern stehen.
 * Verglichen wird ueber PhoneE164::suffix(), sonst zaehlte ""+49 152 ..." gegen
 * "0152 ..." als Streit, obwohl es dieselbe Nummer ist.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PersonGroupPlanner
{
    /**
     * @param  list<array{id:int, person_key:?string, phone:?string, updated_at:?string}>  $employees
     * @return array{gruppen: list<array{ids: list<int>, phone: ?string, phone_uneinig: bool}>}
     */
    public static function plan(array $employees): array
    {
        $roh = [];
        foreach ($employees as $mitarbeiter) {
            $key = trim((string) ($mitarbeiter['person_key'] ?? ''));
            // Leerer Marker: eigene Gruppe. Der Schluessel muss trotzdem
            // eindeutig sein, sonst faenden sich alle Markerlosen zusammen.
            $schluessel = $key === '' ? 'allein:' . (int) $mitarbeiter['id'] : 'marker:' . $key;
            $roh[$schluessel][] = $mitarbeiter;
        }

        $gruppen = [];
        foreach ($roh as $gruppe) {
            usort($gruppe, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

            $gruppen[] = [
                'ids' => array_map(fn ($m) => (int) $m['id'], $gruppe),
                'phone' => self::juengsteNummer($gruppe),
                'phone_uneinig' => self::uneinig($gruppe),
            ];
        }

        usort($gruppen, fn ($a, $b) => $a['ids'][0] <=> $b['ids'][0]);

        return ['gruppen' => $gruppen];
    }

    /** @param list<array{id:int, phone:?string, updated_at:?string}> $gruppe */
    private static function juengsteNummer(array $gruppe): ?string
    {
        $mitNummer = array_values(array_filter(
            $gruppe,
            fn ($m) => trim((string) ($m['phone'] ?? '')) !== '',
        ));
        if ($mitNummer === []) {
            return null;
        }

        usort($mitNummer, function ($a, $b) {
            $zeit = strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));

            return $zeit !== 0 ? $zeit : ((int) $a['id'] <=> (int) $b['id']);
        });

        return trim((string) $mitNummer[0]['phone']);
    }

    /** @param list<array{phone:?string}> $gruppe */
    private static function uneinig(array $gruppe): bool
    {
        $suffixe = [];
        foreach ($gruppe as $mitarbeiter) {
            $suffix = PhoneE164::suffix($mitarbeiter['phone'] ?? null);
            if ($suffix !== '') {
                $suffixe[$suffix] = true;
            }
        }

        return count($suffixe) > 1;
    }
}
