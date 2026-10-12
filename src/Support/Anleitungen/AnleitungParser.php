<?php

namespace Platform\Recruiting\Support\Anleitungen;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Datei -> Anleitung (Spec 2026-10-12, Entscheidung 2). Zeilenbasiert:
 * Kopf zwischen ---, Folien ab "## ", in der Folie Bloecke getrennt durch
 * Leerzeilen. Markdown der Bloecke rendert CommonMark mit gesperrtem HTML.
 * Wirft nie — Fehler werden als Anleitung::kaputt gemeldet.
 */
final class AnleitungParser
{
    public const BEREICHE = ['Bewerber', 'Schulungen', 'Mitarbeiter', 'Verträge', 'Dispo'];

    private const BILDNAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(png|jpe?g|webp)$/';

    private static ?GithubFlavoredMarkdownConverter $md = null;

    public static function parse(string $slug, string $inhalt): Anleitung
    {
        try {
            return self::lesen($slug, $inhalt);
        } catch (AnleitungFehler $e) {
            return Anleitung::kaputt($slug, $e->getMessage());
        }
    }

    /** @return array<string, string|true> */
    public static function rezept(string $text): array
    {
        preg_match_all('/([a-z]+)(?:=(?:"([^"]*)"|(\S+)))?/u', $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $out = [];
        foreach ($m as $t) {
            // "…" -> Gruppe 2, nackter Wert -> Gruppe 3, reiner Schalter -> true
            $out[$t[1]] = $t[2] ?? $t[3] ?? true;
        }

        return $out;
    }

    private static function lesen(string $slug, string $inhalt): Anleitung
    {
        $inhalt = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\x{FEFF}/u', '', $inhalt) ?? $inhalt);
        if (!preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $inhalt, $m)) {
            throw new AnleitungFehler('Kopf fehlt (--- … --- am Dateianfang)');
        }
        $kopf = self::kopf($m[1]);
        $folien = self::folien($m[2], $kopf['bereich']);

        return new Anleitung($slug, $kopf['titel'], $kopf['bereich'], $kopf['stand'], $kopf['seiten'], $folien);
    }

    /** @return array{titel:string, bereich:string, stand:string, seiten:list<string>} */
    private static function kopf(string $text): array
    {
        $werte = [];
        foreach (explode("\n", $text) as $zeile) {
            $zeile = trim(preg_replace('/\s+#.*$/u', '', $zeile) ?? $zeile);
            if ($zeile === '') {
                continue;
            }
            if (!preg_match('/^([a-z]+):\s*(.*)$/u', $zeile, $kv)) {
                throw new AnleitungFehler('Kopfzeile unlesbar: ' . $zeile);
            }
            $werte[$kv[1]] = trim($kv[2]);
        }

        $titel = $werte['titel'] ?? '';
        if ($titel === '') {
            throw new AnleitungFehler('Kopf: titel fehlt');
        }
        $bereich = $werte['bereich'] ?? '';
        if (!in_array($bereich, self::BEREICHE, true)) {
            throw new AnleitungFehler('Kopf: Bereich „' . $bereich . '“ unbekannt (erlaubt: ' . implode(', ', self::BEREICHE) . ')');
        }
        $stand = $werte['stand'] ?? '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $stand);
        if (!$d || $d->format('Y-m-d') !== $stand) {
            throw new AnleitungFehler('Kopf: stand muss JJJJ-MM-TT sein');
        }
        $seiten = [];
        $roh = $werte['seiten'] ?? '';
        if ($roh !== '') {
            $seiten = array_values(array_filter(array_map('trim', explode(',', trim($roh, '[] ')))));
        }

        return ['titel' => $titel, 'bereich' => $bereich, 'stand' => $stand, 'seiten' => $seiten];
    }

    /** @return list<Folie> */
    private static function folien(string $koerper, string $bereich): array
    {
        $teile = preg_split('/^## /m', "\n" . $koerper);
        $vorspann = trim((string) array_shift($teile));
        if ($vorspann !== '') {
            throw new AnleitungFehler('Text vor der ersten Folie (## …)');
        }
        if ($teile === []) {
            throw new AnleitungFehler('Keine Folie (## Kicker | Titel)');
        }

        $folien = [];
        foreach ($teile as $i => $teil) {
            $folien[] = self::folie($i + 1, $teil, $bereich);
        }

        return $folien;
    }

    private static function folie(int $nr, string $teil, string $bereich): Folie
    {
        [$kopfzeile, $rest] = array_pad(explode("\n", $teil, 2), 2, '');
        $kopfzeile = trim($kopfzeile);
        if (str_contains($kopfzeile, '|')) {
            [$kicker, $titel] = array_map('trim', explode('|', $kopfzeile, 2));
        } else {
            [$kicker, $titel] = [$bereich, $kopfzeile];
        }

        $einleitung = [];
        $schritte = [];
        $merksatz = [];
        $tabelle = [];
        $bild = null;

        $bloecke = preg_split('/\n\s*\n/', trim($rest)) ?: [];
        while ($bloecke !== []) {
            $block = trim((string) array_shift($bloecke));
            if ($block === '') {
                continue;
            }
            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)\s*(.*)$/s', $block, $b)) {
                if ($bild !== null) {
                    throw new AnleitungFehler("Folie {$nr}: mehr als ein Bild");
                }
                if (!preg_match(self::BILDNAME, $b[2])) {
                    throw new AnleitungFehler("Folie {$nr}: Bildname „{$b[2]}“ ungültig (nur Dateiname, png/jpg/webp)");
                }
                $weiter = $b[3];
                $rezept = [];
                if (preg_match('/^<!--\s*shot:(.*?)-->/s', $weiter, $r)) {
                    $rezept = self::rezept($r[1]);
                    $weiter = substr($weiter, strlen($r[0]));
                }
                $bild = new Bild($b[2], trim($b[1]), $rezept);
                // Steht ohne Leerzeile weiterer Text unter Bild/Rezept, wird er normal weiterverarbeitet.
                $weiter = trim($weiter);
                if ($weiter !== '') {
                    array_unshift($bloecke, $weiter);
                }
                continue;
            }
            $block = trim(preg_replace('/<!--.*?-->/s', '', $block) ?? $block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^>/', $block)) {
                $merksatz[] = preg_replace('/^>\s?/m', '', $block);
            } elseif (str_starts_with($block, '|')) {
                $tabelle[] = $block;
            } elseif (preg_match('/^(\d+\.|[-*])\s/', $block)) {
                $schritte[] = $block;
            } else {
                $einleitung[] = $block;
            }
        }

        $schritteMd = implode("\n", $schritte);
        $anzahl = preg_match_all('/^(\d+\.|[-*])\s/m', $schritteMd);

        if ($einleitung === [] && $schritte === [] && $merksatz === [] && $tabelle === [] && $bild === null) {
            throw new AnleitungFehler("Folie {$nr} ist leer");
        }

        return new Folie(
            $nr,
            $kicker,
            $titel,
            $einleitung === [] ? null : self::html(implode("\n\n", $einleitung)),
            $schritte === [] ? null : self::html($schritteMd),
            $anzahl,
            $bild,
            $merksatz === [] ? null : self::html(implode("\n\n", $merksatz)),
            $tabelle === [] ? null : self::html(implode("\n\n", $tabelle)),
        );
    }

    private static function html(string $markdown): string
    {
        self::$md ??= new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return trim(self::$md->convert($markdown)->getContent());
    }
}
