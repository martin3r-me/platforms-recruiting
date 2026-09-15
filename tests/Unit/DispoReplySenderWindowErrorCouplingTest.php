<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Inbox::sendReply() (Kommunikations-Seite) erkennt den Fenster-Fehler des
 * geteilten DispoReplySender NUR ueber
 * str_contains($result['error'], '24h-Fenster') und schreibt genau diesen
 * einen Fall in eine fachlich passende Meldung um (der Dispo-Satz nennt eine
 * "Veranstaltung", was hier falsch waere). DispoReplySender selbst gehoert
 * der Dispo und wird von der Kommunikations-Seite NICHT veraendert.
 *
 * Diese Kopplung ist unsichtbar im Code: aendert sich der Wortlaut in
 * DispoReplySender so, dass er "24h-Fenster" nicht mehr enthaelt, liefert
 * str_contains() still false — Inbox::sendReply() zeigt dann den
 * Dispo-Satz mit der Veranstaltung an, der auf der Kommunikations-Seite
 * fachlich falsch ist. Kein Aufrufer bemerkt das, solange sich niemand die
 * Fehlermeldung im Browser ansieht.
 *
 * Dieser Test liest NUR den Quelltext von DispoReplySender (kein Laravel-
 * Bootstrap noetig, die Klasse selbst wird nicht instanziiert und nicht
 * veraendert) und nagelt fest: der Fenster-Fehlertext, den
 * DispoTimeCalculator::isReplyWindowOpen() auf false ausloest, muss die
 * Zeichenfolge "24h-Fenster" enthalten. Aendert sich das, wird DIESER Test
 * rot statt eines verwirrten Nutzers auf /conversations-neu.
 */
class DispoReplySenderWindowErrorCouplingTest extends TestCase
{
    private const MATCHED_SUBSTRING = '24h-Fenster';

    public function testFensterFehlertextEnthaeltDieVonInboxErkannteZeichenfolge(): void
    {
        $pfad = dirname(__DIR__, 2) . '/src/Services/Zas/Dispo/DispoReplySender.php';
        $this->assertFileExists($pfad, 'DispoReplySender.php wurde verschoben oder umbenannt.');

        $quelle = (string) file_get_contents($pfad);

        // Zielt genau auf den return-Zweig direkt nach dem
        // isReplyWindowOpen()-Check (nicht auf irgendeine andere der
        // mehreren 'error'-Zeilen in dieser Klasse).
        $treffer = preg_match(
            '/isReplyWindowOpen\([^{]*?\)\)\s*\{\s*return \[\'ok\' => false, \'error\' => \'([^\']*)\'\];/u',
            $quelle,
            $m
        );

        $this->assertSame(
            1,
            $treffer,
            'Der return-Zweig direkt nach isReplyWindowOpen(...) in DispoReplySender::send() '
                . 'wurde nicht gefunden oder umgebaut — Inbox::sendReply() kann den Fenster-Fehler '
                . 'dann nicht mehr per str_contains(...) erkennen und umformulieren. '
                . 'Bitte Inbox::sendReply() an den neuen Aufbau anpassen.'
        );

        $this->assertStringContainsString(
            self::MATCHED_SUBSTRING,
            $m[1],
            'Der Fenster-Fehlertext in DispoReplySender::send() enthaelt "' . self::MATCHED_SUBSTRING . '" '
                . 'nicht mehr. Inbox::sendReply() erkennt diesen Fall darueber und formuliert ihn fachlich '
                . 'um (statt des Dispo-Satzes mit der Veranstaltung) — ohne die Zeichenfolge faellt der '
                . 'Fall lautlos auf den unveraenderten Dispo-Satz zurueck. Entweder den Wortlaut in '
                . 'DispoReplySender wieder anpassen oder Inbox::sendReply() UND diesen Test bewusst '
                . 'auf die neue Zeichenfolge umstellen.'
        );
    }
}
