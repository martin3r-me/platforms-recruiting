<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\KontoWriter;
use Throwable;

/**
 * Der HR-Knopf zu den Wegen zurueck ins Konto (Spec §5, Canvas 1789).
 *
 * Er traegt VIER Aufgaben, und sie gehoeren zusammen, weil sie dieselbe
 * Frage beantworten — "jemand kommt nicht mehr in sein Konto, was tut HR?":
 *
 *  --person= --nummer=   WEG 5: "Gar nichts geht. HR traegt die neue Nummer
 *                        ein, Einladung geht neu raus." Die Nummer wandert
 *                        sofort, und es entsteht eine frische Einladung.
 *  --offen               Die Arbeitsliste zu WEG 4: welche Notfall-Antraege
 *                        laufen, und bis wann kann HR sie stoppen?
 *  --stopp=              WEG 4, das Stopp-Recht innerhalb der 24 Stunden.
 *  --faellig             WEG 4, die andere Haelfte: faellige Antraege
 *                        anwenden. Gehoert in den Zeitplan (stuendlich).
 *
 * WARUM --faellig UEBERHAUPT GEBRAUCHT WIRD. Alle anderen Wege wirken in dem
 * Augenblick, in dem der Mensch den Code eingibt. Weg 4 nicht: zwischen
 * Beweis und Wirkung liegen 24 Stunden. Niemand ist danach noch da, der ihn
 * ausloesen koennte — die Person kommt ja gerade NICHT in ihr Konto. Ohne
 * einen Lauf von aussen bliebe jeder Antrag ewig liegen, und Weg 4 waere ein
 * Formular, das nichts bewirkt.
 *
 * KENNUNGEN IN DER AUSGABE, NIE NAMEN (Muster:
 * recruiting:mitarbeiter-grenzfaelle). Und von Nummern nur die letzten vier
 * Stellen — dieselbe Kuerzung wie im Protokoll. Die eine Ausnahme ist der
 * Einladungs-Token aus Weg 5: ohne ihn kann HR die Einladung nicht
 * weitergeben, und er ist genau dafuer da. Er steht auf dem Bildschirm und
 * geht NICHT ins Log.
 *
 * WIEDERHOLBAR: --faellig ueberspringt, was nicht (mehr) faellig ist;
 * --stopp meldet ehrlich, wenn gar nichts offen war. Weg 5 ist bewusst NICHT
 * wiederholbar-stillschweigend: jeder Aufruf erzeugt eine NEUE Einladung und
 * entwertet damit die vorige (KontoWriter::ladeEin). Das ist richtig so —
 * wer den Knopf zweimal drueckt, will die zweite Einladung.
 */
class KontoZuruecksetzen extends Command
{
    protected $signature = 'recruiting:konto-zuruecksetzen
        {--person= : Personen-Kennung (rec_persons.id) fuer Weg 5}
        {--nummer= : Die neue Handynummer fuer Weg 5}
        {--offen : Die offenen Notfall-Antraege aus Weg 4 anzeigen}
        {--stopp= : Einen offenen Notfall-Antrag stoppen (Personen-Kennung)}
        {--faellig : Faellige Notfall-Antraege anwenden (gehoert in den Zeitplan)}
        {--team= : Nur dieses Team (wirkt auf --offen und --faellig)}
        {--dry-run : Nur zeigen, was passieren wuerde}';

    protected $description = 'Konto-Rueckwege fuer HR: neue Nummer eintragen (Weg 5), Notfall-Antraege ansehen, stoppen oder anwenden (Weg 4)';

    public function handle(): int
    {
        $teamId = $this->option('team') !== null ? (int) $this->option('team') : null;

        // GENAU EINE Aufgabe je Aufruf. Zwei zugleich waeren nicht bloss
        // unuebersichtlich: "--stopp=5 --faellig" liesse offen, ob der
        // gestoppte Antrag vorher noch angewendet wird.
        $aufgaben = array_filter([
            'weg5'    => $this->option('person') !== null || $this->option('nummer') !== null,
            'offen'   => (bool) $this->option('offen'),
            'stopp'   => $this->option('stopp') !== null,
            'faellig' => (bool) $this->option('faellig'),
        ]);

        if (count($aufgaben) !== 1) {
            $this->error('Bitte genau eines angeben: --person mit --nummer, --offen, --stopp= oder --faellig.');

            return self::FAILURE;
        }

        return match (array_key_first($aufgaben)) {
            'weg5'    => $this->weg5(),
            'offen'   => $this->zeigeOffene($teamId),
            'stopp'   => $this->stoppe((int) $this->option('stopp')),
            default   => $this->wendeFaelligeAn($teamId),
        };
    }

    // ------------------------------------------------------------------ Weg 5

    /**
     * "HR traegt die neue Nummer ein, Einladung geht neu raus."
     *
     * DIE REIHENFOLGE: erst die Nummer, dann die Einladung. Andersherum ginge
     * die Einladung an eine Nummer, die gleich nicht mehr gilt — und der
     * Mensch bekaeme sie auf dem Geraet, das er verloren hat. (Verschickt
     * wird sie hier ohnehin noch nicht; das ist Aufgabe 10. Bis dahin liest
     * HR den Code aus dieser Ausgabe vor.)
     */
    private function weg5(): int
    {
        $personId = (int) $this->option('person');
        $nummer   = trim((string) $this->option('nummer'));

        if ($personId <= 0 || $nummer === '') {
            $this->error('Weg 5 braucht BEIDE Angaben: --person=<id> --nummer=<handynummer>.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("Person {$personId}: Nummer wuerde auf ...{$this->kurz($nummer)} gesetzt, danach neue Einladung.");

            return self::SUCCESS;
        }

        try {
            $alt = KontoWriter::setzeNummerDurchHr($personId, $nummer);
        } catch (Throwable $e) {
            $this->error("Person {$personId}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("Person {$personId}: Nummer steht jetzt auf ...{$this->kurz($nummer)}.");

        if ($alt !== null) {
            $this->hinweisAnAlteNummer($personId, $alt);
        }

        // Die Einladung ist der zweite Teil von Weg 5 — ohne sie haette der
        // Mensch zwar die richtige Nummer, aber weiterhin kein Passwort.
        // Scheitert sie, ist der Nummernwechsel trotzdem vollzogen; das
        // gehoert gesagt, sonst laeuft HR im falschen Glauben weiter.
        try {
            $token = KontoWriter::ladeEin($personId);
        } catch (Throwable $e) {
            $this->error("Person {$personId}: Nummer gesetzt, aber die Einladung scheiterte — {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("Person {$personId}: Einladungscode {$token} (gilt sieben Tage, ersetzt jede fruehere Einladung).");
        $this->line('Der Code ist ein Geheimnis: nur an den Menschen selbst, nicht in eine Gruppen-Unterhaltung.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ Weg 4

    private function zeigeOffene(?int $teamId): int
    {
        $offene = KontoWriter::offeneNummernwechsel($teamId);

        if ($offene === []) {
            $this->line('Kein offener Notfall-Antrag.');

            return self::SUCCESS;
        }

        $this->table(
            ['Person', 'Team', 'alt', 'neu', 'beantragt', 'wirksam ab', 'Quelle'],
            array_map(fn (object $zeile): array => [
                (int) $zeile->id,
                $zeile->team_id,
                '...' . $this->kurz((string) ($zeile->phone ?? '')),
                '...' . $this->kurz((string) $zeile->wechsel_neue_nummer),
                (string) $zeile->wechsel_beantragt_at,
                (string) $zeile->wechsel_wirksam_ab,
                (string) $zeile->wechsel_quelle,
            ], $offene),
        );

        $this->line('Stoppen mit: recruiting:konto-zuruecksetzen --stopp=<Person>');

        return self::SUCCESS;
    }

    private function stoppe(int $personId): int
    {
        if ($personId <= 0) {
            $this->error('--stopp braucht eine Personen-Kennung.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("Person {$personId}: ein offener Antrag wuerde gestoppt.");

            return self::SUCCESS;
        }

        if (!KontoWriter::stoppeNummerwechsel($personId)) {
            // Kein Fehler, sondern eine Auskunft: vielleicht ist der Antrag
            // schon angewendet, vielleicht hat ein Kollege ihn gestoppt.
            $this->line("Person {$personId}: kein offener Antrag.");

            return self::SUCCESS;
        }

        $this->line("Person {$personId}: Antrag gestoppt, die Nummer bleibt, wie sie ist.");

        return self::SUCCESS;
    }

    /**
     * Die faelligen Antraege anwenden.
     *
     * JE ANTRAG EIN EIGENER VERSUCH, und ein Fehlschlag raeumt den Lauf nicht
     * ab: scheitert einer (die Zielnummer gehoert im Team inzwischen jemand
     * anderem), bleibt sein Antrag stehen und taucht weiter unter --offen auf
     * — genau richtig, denn diesen Fall muss ein Mensch klaeren (Spec §6.2).
     * Die uebrigen laufen weiter; sonst haelt ein einziger Grenzfall alle
     * anderen auf.
     */
    private function wendeFaelligeAn(?int $teamId): int
    {
        $offene = KontoWriter::offeneNummernwechsel($teamId);

        $angewendet = 0;
        $offenGeblieben = 0;
        $gescheitert = 0;

        foreach ($offene as $zeile) {
            $personId = (int) $zeile->id;

            if ($this->option('dry-run')) {
                $this->line("Person {$personId}: wirksam ab {$zeile->wechsel_wirksam_ab}.");

                continue;
            }

            try {
                $ergebnis = KontoWriter::wendeNummernwechselAn($personId);
            } catch (Throwable $e) {
                $gescheitert++;
                $this->error("Person {$personId}: {$e->getMessage()}");

                continue;
            }

            if ($ergebnis === null) {
                // Noch nicht faellig — der Normalfall fuer alles, was heute
                // beantragt wurde.
                $offenGeblieben++;

                continue;
            }

            $angewendet++;
            $this->line("Person {$personId}: Nummer steht jetzt auf ...{$this->kurz($ergebnis['neu'])}.");

            if ($ergebnis['alt'] !== null) {
                $this->hinweisAnAlteNummer($personId, $ergebnis['alt']);
            }
        }

        if ($this->option('dry-run')) {
            $this->line(sprintf('%d offene Antraege (Probelauf, nichts geaendert).', count($offene)));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d angewendet, %d noch nicht faellig, %d gescheitert.',
            $angewendet,
            $offenGeblieben,
            $gescheitert,
        ));

        return $gescheitert > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ----------------------------------------------------------------- intern

    /**
     * Der Hinweis an die alte Nummer (Spec §5, Begleitregel).
     *
     * Ein Fehlschlag dreht NICHTS zurueck: der Wechsel ist vollzogen, und
     * "sofern noch zustellbar" heisst genau das — bei einer verlorenen Karte
     * ist der Fehlschlag der Normalfall. Er wird bloss gesagt, damit HR es
     * weiss.
     */
    private function hinweisAnAlteNummer(int $personId, string $alteNummer): void
    {
        $status = app(NummernwechselHinweisSender::class)->sende($personId, $alteNummer);

        if ($status !== NummernwechselHinweisSender::STATUS_SENT) {
            $this->line("Person {$personId}: Hinweis an die alte Nummer nicht zugestellt (Naeheres im Log).");
        }
    }

    /** Die letzten vier Stellen — dieselbe Kuerzung wie im Protokoll. */
    private function kurz(string $nummer): string
    {
        return substr($nummer, -4);
    }
}
