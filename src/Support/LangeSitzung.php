<?php

namespace Platform\Recruiting\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Auf diesem Geraet angemeldet bleiben" (Spec 2.1, Freigabe 13.10. —
 * loest das offene Ruling GD-10 ab).
 *
 * WAS DIE VERLAENGERUNG IST: die LEBENSDAUER DER SITZUNG SELBST, nichts
 * daneben. Es gibt keinen zweiten Anmeldeweg und kein dauerhaftes Geheimnis
 * im Browser ("remember me"-Token). Die Portal-Sitzung haelt dieselben
 * Schluessel wie immer (PortalAuth::sessionKey), und PortalAuth::
 * sitzungGueltig() prueft sie wie immer — inklusive
 * rec_persons.sitzungen_ungueltig_ab. Verlaengert wird nur, wie lange der
 * Server die Sitzungszeile annimmt und wie lange der Browser den
 * Sitzungs-Cookie behaelt: 180 Tage seit der LETZTEN Benutzung (gleitend,
 * weil last_activity bei jeder Anfrage nachgezogen wird), statt 2 Stunden.
 *
 * DREI TEILE, und erst alle drei zusammen verlaengern etwas:
 *
 *  1. DER MERKER-COOKIE (COOKIE). Wert "1", kein Geheimnis — er sagt nur
 *     "auf diesem Geraet wurde mit Haken angemeldet". Er ist der billige
 *     Vorfilter, damit Anfragen OHNE ihn keine einzige Abfrage kosten.
 *     ALLEIN OEFFNET ER NICHTS: wer ihn sich selbst setzt, bekommt hoechstens
 *     eine laengere Lebensdauer fuer eine Sitzung, die nichts enthaelt — und
 *     nicht einmal das (s. 2).
 *  2. DIE EINTRAGUNG (TABELLE). Bei der Anmeldung mit Haken wird die
 *     Kennung DIESER Sitzung eingetragen. Verlaengert wird nur eine Sitzung,
 *     deren Kennung dort steht. Damit haengt die Verlaengerung an einer
 *     serverseitigen Tatsache (eine echte Anmeldung mit Passwort hat diese
 *     Sitzung geoeffnet), nicht an einem Cookie, den sich jeder setzen kann.
 *  3. DIE MIDDLEWARE (Http\Middleware\LangeSitzungVerlaengern). Sie sitzt in
 *     der web-Gruppe VOR StartSession und setzt fuer diese eine Anfrage
 *     config('session.lifetime') herauf. Spaeter ginge es nicht: der
 *     DatabaseSessionHandler bekommt seine Minuten beim Bau, und gebaut wird
 *     er in StartSession.
 *
 * HR-SITZUNGEN BLEIBEN KURZ. Traegt die Sitzungszeile eine user_id (ein im
 * Backend angemeldeter Mensch — DatabaseSessionHandler schreibt sie bei
 * jedem Speichern), wird NICHT verlaengert, auch wenn Merker und Eintragung
 * stimmen. Das trifft den Fall "HR hat das Portal im selben Browser
 * ausprobiert". Mehr ist vor StartSession nicht wissbar; die Zeile ist es.
 *
 * DIE AUFRAEUMUNG. Der Datenbank-Treiber raeumt per Lotterie: jede Anfrage
 * loescht mit 2 % Wahrscheinlichkeit alle Zeilen, die laenger als die
 * Lebensdauer DIESER Anfrage ruhen. Eine normale 2-Stunden-Anfrage, die
 * gewinnt, raeumte also jede lange Sitzung ab. Deshalb wird die Lotterie
 * ausgeschaltet (schalteLotterieAb) und raeumeAuf() uebernimmt — stuendlich
 * im Zeitplan (recruiting:sitzungen-aufraeumen) mit genau der Regel des
 * Treibers fuer kurze Sitzungen und 180 Tagen fuer eingetragene.
 *
 * NUR MIT session.driver = database. Bei jedem anderen Treiber ist das hier
 * ein No-op (Lebensdauer und Lotterie bleiben unberuehrt); der Haken wird
 * dann zwar gespeichert, verlaengert aber nichts. Produktion und Demo laufen
 * mit database.
 */
final class LangeSitzung
{
    /** Der Merker-Cookie. Kein Geheimnis, Wert immer "1". */
    public const COOKIE = 'rg_portal_bleiben';

    /** 180 Tage in Minuten — die Einheit von config('session.lifetime'). */
    public const MINUTEN = 259200;

    public const TABELLE = 'rec_portal_lange_sitzungen';

    /** Ist die Verlaengerung mit diesem Sitzungs-Treiber ueberhaupt moeglich? */
    public static function moeglich(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Die Lotterie-Aufraeumung des Treibers abschalten (s. Klassen-Docblock).
     * Nur fuer den Datenbank-Treiber — bei jedem anderen bliebe sonst
     * niemand, der aufraeumt.
     */
    public static function schalteLotterieAb(): void
    {
        if (self::moeglich()) {
            config(['session.lottery' => [0, 100]]);
        }
    }

    /**
     * Anmeldung MIT Haken: diese Sitzung eintragen und den Merker setzen.
     *
     * Fehlt die Tabelle (Code schon live, migrate noch nicht gelaufen), wird
     * NICHTS gesetzt und die Anmeldung laeuft normal mit zwei Stunden — eine
     * Anmeldung darf an der Verlaengerung nie scheitern.
     */
    public static function beginne(int $personId): void
    {
        if (!self::tabelleDa()) {
            return;
        }

        $sitzungsId = (string) app('session')->getId();
        if ($sitzungsId === '') {
            return;
        }

        DB::table(self::TABELLE)->updateOrInsert(
            ['session_id' => $sitzungsId],
            ['rec_person_id' => $personId, 'created_at' => now()],
        );

        if (app()->bound('cookie')) {
            $korb = app('cookie');
            // Pfad und Domain: die Vorgaben des Korbs (aus der Session-Config),
            // damit beende() genau diesen Cookie wieder trifft. Secure,
            // HttpOnly, SameSite=Lax ausdruecklich.
            $korb->queue($korb->make(self::COOKIE, '1', self::MINUTEN, null, null, true, true, false, 'lax'));
        }
    }

    /**
     * Ende der langen Anmeldung auf diesem Geraet: Eintragung dieser Sitzung
     * weg, Merker weg. Benutzt von PortalAuth::beendeKontoAnmeldung() (beide
     * Abmelde-Knoepfe) und von der Anmeldung OHNE Haken.
     */
    public static function beende(): void
    {
        if (self::tabelleDa()) {
            $sitzungsId = (string) app('session')->getId();
            if ($sitzungsId !== '') {
                DB::table(self::TABELLE)->where('session_id', $sitzungsId)->delete();
            }
        }

        if (app()->bound('cookie')) {
            $korb = app('cookie');
            // Wie CookieJar::forget(), aber mit denselben Attributen wie beim
            // Setzen — ein Secure-Cookie wird nur von einem Secure-Set-Cookie
            // ueberschrieben.
            $korb->queue($korb->make(self::COOKIE, '', -2628000, null, null, true, true, false, 'lax'));
        }
    }

    /**
     * Gilt fuer DIESE Anfrage die lange Lebensdauer?
     *
     * Ja nur, wenn ALLES stimmt: Merker gesetzt, Sitzungs-Cookie vorhanden,
     * diese Sitzung bei einer Anmeldung mit Haken eingetragen, ihre Zeile
     * noch da und OHNE angemeldeten Backend-Benutzer. Jeder Zweifel — auch
     * ein Datenbankfehler — heisst nein: dann gelten die normalen zwei
     * Stunden, also genau das Verhalten von vorher.
     */
    public static function giltFuer(Request $anfrage): bool
    {
        if ($anfrage->cookies->get(self::COOKIE) !== '1') {
            return false;
        }

        $sitzungsId = $anfrage->cookies->get((string) config('session.cookie'));

        // Dieselbe Form, die Illuminate\Session\Store::isValidId verlangt.
        if (!is_string($sitzungsId) || preg_match('/^[a-zA-Z0-9]{40}$/', $sitzungsId) !== 1) {
            return false;
        }

        try {
            if (!DB::table(self::TABELLE)->where('session_id', $sitzungsId)->exists()) {
                return false;
            }

            $zeile = self::sitzungen()->where('id', $sitzungsId)->first(['user_id']);
        } catch (Throwable) {
            return false;
        }

        return $zeile !== null && $zeile->user_id === null;
    }

    /**
     * Die Aufraeumung anstelle der Lotterie (stuendlich im Zeitplan).
     *
     *  - Jede Sitzung, die laenger als 180 Tage ruht, geht.
     *  - Jede Sitzung, die laenger als die normale Lebensdauer des Wirts
     *    ruht (config('session.lifetime'), Vorgabe 120 Minuten), geht —
     *    AUSSER sie ist als lange Sitzung eingetragen und traegt keinen
     *    Backend-Benutzer. Das ist woertlich die Regel, die vorher die
     *    Lotterie anwandte (DatabaseSessionHandler::gc, "<="), nur dass
     *    eingetragene Portal-Sitzungen ausgenommen sind.
     *  - Eintragungen, deren Sitzungszeile nicht mehr existiert, gehen mit.
     *
     * OHNE die Nutzdaten zu lesen: entschieden wird ueber die Eintragung und
     * die Spalte user_id, nicht ueber ein unserialize() jeder Zeile.
     *
     * @return array{abgelaufen_lang: int, abgelaufen_kurz: int, eintragungen: int}
     */
    public static function raeumeAuf(?int $jetzt = null, bool $trocken = false): array
    {
        $jetzt ??= time();
        $grenzeLang = $jetzt - self::MINUTEN * 60;
        $grenzeKurz = $jetzt - ((int) config('session.lifetime', 120)) * 60;

        $ergebnis = ['abgelaufen_lang' => 0, 'abgelaufen_kurz' => 0, 'eintragungen' => 0];

        $alt = self::sitzungen()->where('last_activity', '<=', $grenzeLang);
        $ergebnis['abgelaufen_lang'] = $trocken ? $alt->count() : $alt->delete();

        // Die Menge der eingetragenen Kennungen ist klein (eine je Geraet mit
        // Haken). Sie wird einmal geladen statt per Unterabfrage, damit die
        // Aufraeumung auch dann stimmt, wenn die Sitzungen auf einer anderen
        // Verbindung liegen (config('session.connection')).
        $lang = array_flip(
            DB::table(self::TABELLE)->pluck('session_id')->map(fn ($id) => (string) $id)->all(),
        );

        self::sitzungen()
            ->where('last_activity', '<=', $grenzeKurz)
            ->where('last_activity', '>', $grenzeLang)
            ->select(['id', 'user_id'])
            ->chunkById(500, function ($zeilen) use ($lang, $trocken, &$ergebnis) {
                $weg = [];
                foreach ($zeilen as $zeile) {
                    $istLang = isset($lang[(string) $zeile->id]) && $zeile->user_id === null;
                    if (!$istLang) {
                        $weg[] = (string) $zeile->id;
                    }
                }
                if ($weg !== []) {
                    $ergebnis['abgelaufen_kurz'] += count($weg);
                    if (!$trocken) {
                        self::sitzungen()->whereIn('id', $weg)->delete();
                    }
                }
            }, 'id');

        foreach (array_chunk(array_keys($lang), 500) as $block) {
            $block = array_map('strval', $block);
            $da = self::sitzungen()->whereIn('id', $block)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $verwaist = array_values(array_diff($block, $da));
            if ($verwaist !== []) {
                $ergebnis['eintragungen'] += count($verwaist);
                if (!$trocken) {
                    DB::table(self::TABELLE)->whereIn('session_id', $verwaist)->delete();
                }
            }
        }

        return $ergebnis;
    }

    /** Die Sitzungstabelle des Wirts, auf ihrer eigenen Verbindung. */
    private static function sitzungen(): \Illuminate\Database\Query\Builder
    {
        return DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'));
    }

    private static function tabelleDa(): bool
    {
        try {
            return DB::connection()->getSchemaBuilder()->hasTable(self::TABELLE);
        } catch (Throwable) {
            return false;
        }
    }
}
