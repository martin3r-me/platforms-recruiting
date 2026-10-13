<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * Regeln der drei Schritte „Neue Anfrage“ — rein, damit sie ohne Livewire
 * testbar sind. kannWeiter() liefert die Meldung fuer den Disponenten oder null.
 *
 * Erwartete Schluessel in $state: heute, filial_nr, von, bis, event_ids,
 * gesucht (Schluessel => Zahl), andere (list<{gesucht}>), anlass, template_id
 * (die zum Anlass hinterlegte Vorlage),
 * vorlagen_problem, gruppen, bloecke, modus.
 */
final class DispoJobCreateState
{
    /** @return array<int,string> */
    public static function schritte(): array
    {
        return [1 => 'Was suchst du?', 2 => 'Schichten', 3 => 'Senden'];
    }

    public static function kannWeiter(int $schritt, array $state): ?string
    {
        return match ($schritt) {
            1 => self::wasSuchstDu($state),
            2 => self::summeGesucht($state) > 0 ? null : 'Trag bei mindestens einer Schicht ein, wie viele Leute du suchst.',
            3 => self::empfaenger($state),
            default => null,
        };
    }

    /** Filiale, Zeitraum, Veranstaltungen — die erste Meldung gewinnt. */
    private static function wasSuchstDu(array $state): ?string
    {
        if (!self::istZahl($state['filial_nr'] ?? null)) {
            return 'Bitte wähle eine Filiale.';
        }
        if (($z = self::zeitraumProblem($state)) !== null) {
            return $z;
        }

        return array_filter((array) ($state['event_ids'] ?? []), fn ($id) => (int) $id > 0) !== []
            ? null : 'Bitte wähle mindestens eine Veranstaltung.';
    }

    /** Alle Schritte der Reihe nach — die erste Meldung gewinnt (vor dem Anlegen). */
    public static function alles(array $state): ?string
    {
        foreach (array_keys(self::schritte()) as $s) {
            if (($fehler = self::kannWeiter($s, $state)) !== null) {
                return $fehler;
            }
        }

        return null;
    }

    public static function summeGesucht(array $state): int
    {
        $summe = 0;
        foreach ((array) ($state['gesucht'] ?? []) as $n) {
            $summe += max(0, self::zahl($n));
        }
        foreach ((array) ($state['andere'] ?? []) as $a) {
            $summe += max(0, self::zahl(is_array($a) ? ($a['gesucht'] ?? 0) : 0));
        }

        return $summe;
    }

    public static function gueltigesDatum(mixed $v): bool
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Oeffentlich: die Veranstaltungsliste im Schritt 1 filtert live und braucht nur den Zeitraum. */
    public static function zeitraumProblem(array $state): ?string
    {
        $von = $state['von'] ?? '';
        $bis = $state['bis'] ?? '';
        if (!self::gueltigesDatum($von) || !self::gueltigesDatum($bis)) {
            return 'Bitte gib ein gültiges Von- und Bis-Datum an.';
        }
        if ($von < (string) ($state['heute'] ?? '')) {
            return 'Der Zeitraum darf nicht in der Vergangenheit beginnen.';
        }
        if ($bis < $von) {
            return 'Das Bis-Datum liegt vor dem Von-Datum.';
        }

        return null;
    }

    private static function empfaenger(array $state): ?string
    {
        if (!DispoJobAnlass::istGueltig((string) ($state['anlass'] ?? ''))) {
            return 'Bitte wähle einen Anlass.';
        }
        if (!self::istZahl($state['template_id'] ?? null)) {
            return 'Für diesen Anlass ist noch keine Vorlage hinterlegt — bitte in den Einstellungen hinterlegen.';
        }
        if (($state['vorlagen_problem'] ?? null) !== null) {
            return (string) $state['vorlagen_problem'];
        }
        if ((array) ($state['bloecke'] ?? []) === []) {
            return 'Bitte wähle mindestens eine Mitarbeiterfiliale.';
        }
        if ((array) ($state['gruppen'] ?? []) === []) {
            return 'Bitte wähle mindestens eine Gruppe.';
        }
        if (!in_array($state['modus'] ?? '', [RecDispoJobRequest::MODUS_SAMMELN, RecDispoJobRequest::MODUS_ZUERST], true)) {
            return 'Bitte wähle, wie die Zusagen gezählt werden.';
        }

        return null;
    }

    private static function istZahl(mixed $v): bool
    {
        return (is_int($v) && $v > 0) || (is_string($v) && ctype_digit($v) && (int) $v > 0);
    }

    private static function zahl(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        $s = trim((string) $v);

        return preg_match('/^-?\d+$/', $s) === 1 ? (int) $s : 0;
    }
}
