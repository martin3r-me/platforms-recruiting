<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Support\LangeSitzung;

/**
 * Raeumt die Sitzungstabelle anstelle der Lotterie des Datenbank-Treibers
 * (die ist abgeschaltet, s. LangeSitzung). Gehoert stuendlich in den
 * Zeitplan: ohne diesen Lauf waechst die Tabelle unbegrenzt.
 *
 * Kurze Sitzungen gehen nach der normalen Lebensdauer des Wirts
 * (config('session.lifetime')), eingetragene lange Portal-Sitzungen nach
 * 180 Tagen ohne Benutzung. Bei einem anderen Treiber als database ist der
 * Lauf ein No-op — dort raeumt der Treiber weiter selbst.
 */
class SitzungenAufraeumen extends Command
{
    protected $signature = 'recruiting:sitzungen-aufraeumen
        {--dry-run : Nur zaehlen, nichts loeschen}';

    protected $description = 'Abgelaufene Sitzungen loeschen (ersetzt die Lotterie; lange Portal-Sitzungen 180 Tage)';

    public function handle(): int
    {
        if (!LangeSitzung::moeglich()) {
            $this->line('Sitzungs-Treiber ist nicht database — nichts zu tun.');

            return self::SUCCESS;
        }

        $trocken = (bool) $this->option('dry-run');
        $ergebnis = LangeSitzung::raeumeAuf(null, $trocken);

        $this->line(sprintf(
            '%sKurze Sitzungen abgelaufen: %d, laenger als 180 Tage ruhend: %d, verwaiste Eintragungen: %d',
            $trocken ? '[dry-run] ' : '',
            $ergebnis['abgelaufen_kurz'],
            $ergebnis['abgelaufen_lang'],
            $ergebnis['eintragungen'],
        ));

        return self::SUCCESS;
    }
}
