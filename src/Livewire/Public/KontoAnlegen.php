<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\KontoWriter;
use Platform\Recruiting\Support\PasswortRegeln;

/**
 * Die Registrierung — die erste sichtbare Seite des Mitarbeiterkontos
 * (Canvas 68, Spec §3).
 *
 * HR verschickt eine Einladung, der Mensch tippt Geburtsdatum und Passwort,
 * danach hat er ein Konto. Drei Felder, mehr nicht: die Handynummer wird
 * NICHT abgefragt, sie steht durch den Token fest — er ging ja an sie.
 *
 * Geschrieben wird nichts hier, sondern in KontoWriter::registriere(): dort
 * liegen die Zwei-Nachweis-Regel, das Entwerten der Einladung und der
 * Pfeffer. Diese Seite ist die Oberflaeche davor und traegt nur, was nicht
 * zum Konto gehoert, sondern zur Seite:
 *
 *  - Sichtbarkeit: eine Einladung, die es nicht (mehr) gibt, ergibt 404.
 *  - Die zweite Drossel (s.u.).
 *  - Die Trennung zwischen "das Passwort taugt nicht" (eine Formularsache)
 *    und "die Nachweise stimmen nicht" (ein Rateversuch).
 *
 * WARUM DIE DROSSEL HIER TRAGEND IST (Ruling GD-4): der Token ist acht
 * Zeichen aus 31, das sind rund 850 Milliarden Moeglichkeiten bei sieben
 * Tagen Gueltigkeit — fuer sich genommen duenn. Er traegt nur, weil ohne das
 * richtige Geburtsdatum ein erratener Token nichts nuetzt. Und der zweite
 * Nachweis traegt nur, solange man ihn nicht durchprobieren kann: ein
 * plausibler Geburtsjahrgang-Bereich sind rund 25.000 Moeglichkeiten, an
 * einem Nachmittag durch. Deshalb zwei Bremsen:
 *
 *  1. Die Route selbst (throttle:20,1, siehe routes/public.php) gegen das
 *     Durchprobieren von TOKEN.
 *  2. Der Zaehler hier gegen das Durchprobieren des GEBURTSDATUMS bei
 *     bekanntem Token. Fuenf Fehlversuche je Einladung, eine Stunde lang.
 *
 * Beim Ueberschreiten antwortet die Seite wie bei einem ungueltigen Token:
 * 404. Eine eigene Meldung ("zu viele Versuche") waere die Auskunft, dass es
 * diesen Token ueberhaupt gibt.
 *
 * Sicherheit: dieselbe Lehre wie aus dem Auth-Bypass vom 19.08.2026 — alles,
 * was ueber Identitaet oder Zustand entscheidet, ist #[Locked]. $wire.set
 * kommt daran nicht vorbei.
 */
class KontoAnlegen extends Component
{
    /** Fuenf Fehlversuche je Einladung. */
    public const MAX_VERSUCHE = 5;

    /** Eine Stunde. Lang genug, dass Durchprobieren sich nicht lohnt. */
    public const SPERRE_SEKUNDEN = 3600;

    /**
     * Der Einladungs-Token, normalisiert (s. lesbarerToken()).
     *
     * #[Locked]: er IST der Besitznachweis. Wer ihn vom Browser aus setzen
     * koennte, braeuchte gar keine Einladung — er nimmt einfach die eines
     * anderen.
     */
    #[Locked] public string $token = '';

    /**
     * WESSEN Konto hier angelegt wird. #[Locked] aus demselben Grund wie
     * $employeeId in der Portal-Huelle: eine gesetzte fremde Kennung waere
     * ein fremdes Konto.
     */
    #[Locked] public ?int $personId = null;

    /**
     * 'formular' oder 'fertig'. #[Locked] — genau diese Art Eigenschaft war
     * der Bypass vom 19.08.2026 ($wire.set state=verified).
     */
    #[Locked] public string $state = 'formular';

    /** Kommt aus den Team-Einstellungen, nicht vom Menschen. */
    #[Locked] public bool $duzen = false;

    /**
     * Die drei Eingaben — bewusst NICHT gesperrt, sie kommen ja vom
     * Menschen. Ihre Sicherheit sitzt nicht in der Unveraenderlichkeit,
     * sondern darin, dass KontoWriter::registriere() bei JEDEM Aufruf beide
     * Nachweise erneut prueft.
     */
    public string $geburtsdatum = '';
    public string $passwort = '';
    public string $passwortWiederholung = '';

    public string $fehler = '';

    public function mount(string $token): void
    {
        $lesbar = self::lesbarerToken($token);
        $personId = KontoWriter::personFuerEinladung($lesbar);

        // Ungueltig, abgelaufen, verbraucht, gesperrt, stillgelegt — und
        // ebenso eine Einladung, an der schon zu oft geraten wurde: fuer
        // diesen Menschen gibt es die Seite schlicht nicht. Bewusst 404 und
        // nicht "Token ungueltig", das waere die Auskunft, dass es ihn gibt.
        if ($personId === null || $this->zuVieleFehlversuche($lesbar)) {
            abort(404);
        }

        $this->token = $lesbar;
        $this->personId = $personId;
        $this->duzen = $this->anredeFuer($personId);
    }

    public function registriere(): void
    {
        // Ein zweites Absenden desselben Formulars liefe in den
        // verbrauchten Token und zaehlte als Fehlversuch — obwohl es nur ein
        // Doppelklick war.
        if ($this->state === 'fertig' || $this->personId === null) {
            return;
        }

        if ($this->zuVieleFehlversuche($this->token)) {
            abort(404);
        }

        $this->fehler = '';

        // Die beiden Passwort-Pruefungen stehen VOR dem Schreiber, und zwar
        // nicht aus Bequemlichkeit: KontoWriter::registriere() wirft bei
        // einem Regelverstoss dieselbe Ausnahme wie bei falschen Nachweisen.
        // Wuerde sie unterschiedslos gezaehlt, sperrte sich mit einer
        // 404-Seite aus, wer fuenfmal ein zu kurzes Passwort tippt. Ein
        // Passwort-Fehler ist kein Rateversuch am zweiten Nachweis.
        // Geprueft wird mit DERSELBEN Klasse, die der Schreiber benutzt —
        // kein zweiter Massstab.
        if ($this->passwort !== $this->passwortWiederholung) {
            $this->fehler = $this->duzen
                ? 'Die beiden Passwörter sind nicht gleich. Bitte tippe sie noch einmal.'
                : 'Die beiden Passwörter sind nicht gleich. Bitte tippen Sie sie noch einmal.';

            return;
        }

        $passwortMeldung = PasswortRegeln::pruefe($this->passwort);
        if ($passwortMeldung !== null) {
            $this->fehler = $passwortMeldung;

            return;
        }

        try {
            KontoWriter::registriere($this->personId, $this->token, $this->geburtsdatum, $this->passwort);
        } catch (InvalidArgumentException) {
            // Die Ausnahme wird NICHT angezeigt und NICHT protokolliert: sie
            // benennt, welcher der beiden Nachweise nicht stimmte, und genau
            // das darf niemand erfahren. Die Einladung bleibt gueltig — ein
            // Tippfehler im Datum verbrennt sie nicht, die Grenze ist der
            // Zaehler.
            //
            // GEFANGEN WIRD PAUSCHAL, und das ist hier vertretbar, nicht
            // Schlamperei (Fund F7 der Pruefung). KontoWriter wirft aus FUENF
            // Gruenden dieselbe Ausnahme: falscher Nachweis, Passwortregel,
            // gesperrte Zeile, stillgelegte Zeile, verschwundene Zeile
            // ("Person X existiert nicht") — dazu der fehlende Pfeffer aus
            // der Konfiguration.
            //
            // Die Passwortregel ist oben schon abgefangen. Gesperrt und
            // stillgelegt fallen in personFuerEinladung() heraus und ergeben
            // 404 — ABER NUR NACH DEM STAND BEIM SEITENAUFRUF (Nachpruefung
            // zu F7): wer waehrend des offenen Formulars gesperrt oder
            // zusammengelegt wird, landet hier und bekommt einen Fehlversuch
            // gutgeschrieben. Dasselbe gilt fuer die verschwundene Zeile;
            // einen harten Loeschweg auf rec_persons gibt es
            // (SeedDemoEmployees), auf dem Demosystem ist das also real. Der
            // Pfeffer faellt in der Konfiguration auf app.key zurueck.
            //
            // Betrieblich ist das folgenlos: alle diese Faelle bedeuten
            // ohnehin "kein Konto fuer diesen Menschen", und der Zaehler
            // laeuft nach einer Stunde ab. Aber es heisst: der Zaehler ist
            // NICHT zeichengleich mit "falsche Nachweise", er ist es nur
            // praktisch. Wer eine der Absicherungen entfernt — die
            // Passwort-Vorpruefung oben oder die Vorauswahl in
            // personFuerEinladung() —, muss hier nach Fall unterscheiden,
            // sonst zaehlt ein Konfigurationsfehler als Rateversuch und
            // sperrt den Menschen fuer eine Stunde aus.
            RateLimiter::hit(self::drosselSchluessel($this->token), self::SPERRE_SEKUNDEN);

            $this->fehler = $this->duzen
                ? 'Das hat nicht geklappt. Bitte prüfe dein Geburtsdatum und versuche es noch einmal.'
                : 'Das hat nicht geklappt. Bitte prüfen Sie Ihr Geburtsdatum und versuchen Sie es noch einmal.';

            return;
        }

        RateLimiter::clear(self::drosselSchluessel($this->token));

        // Das Konto steht — das Passwort hat auf dieser Seite nichts mehr zu
        // suchen. Es faehrt sonst im Livewire-Schnappschuss weiter mit.
        $this->passwort = '';
        $this->passwortWiederholung = '';
        $this->geburtsdatum = '';
        $this->state = 'fertig';
    }

    public function render()
    {
        return view('recruiting::livewire.public.konto-anlegen')
            ->layout('recruiting::layouts.portal', [
                'title' => 'Konto einrichten · RheinGedeck',
            ]);
    }

    // ------------------------------------------------------------------ intern

    /**
     * Beim Einlesen grosszuegig, beim Pruefen streng.
     *
     * Ruling GD-4: der lesbare Code IST der Token, nicht eine Ableitung
     * davon — derselbe Wert steht im Link und im Eingabefeld. Wer ihn am
     * Rechner abtippt, tippt ihn klein, in Vierergruppen oder mit
     * Bindestrichen. Das sind Schreibweisen desselben Codes, keine anderen
     * Codes. Geprueft wird danach streng (hash_equals in
     * EinladungsToken::istGueltig), und das Alphabet kennt weder
     * Kleinbuchstaben noch Trennzeichen — es kann hier also nichts
     * zusammenfallen, was nicht dasselbe ist.
     *
     * Normalisiert wird VOR allem anderen, auch vor dem Drossel-Schluessel:
     * sonst schuettelte eine andere Schreibweise die Sperre ab (dieselbe
     * Falle wie beim Nummern-Schluessel in PortalAuth).
     */
    private static function lesbarerToken(string $eingabe): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $eingabe));
    }

    /**
     * Der Drossel-Schluessel.
     *
     * GEHASHT, weil der Token ein Geheimnis ist: roh stuende er eine Stunde
     * lang im Klartext in der Cache-Tabelle (der Wirt faehrt
     * CACHE_STORE=database) — waehrend KontoWriter sogar vor einer blossen
     * Log-Zeile die Handynummer auf vier Stellen kuerzt.
     *
     * Der 500er-Fund der Anmeldeseite (unbegrenzt lange Eingabe im
     * Schluessel, cache.key ist varchar(255) PRIMARY KEY) greift hier nicht
     * von selbst: GESCHRIEBEN wird der Zaehler nur in registriere(), und
     * dort steht in $token laengst ein nachgeschlagener, achtstelliger Wert
     * — die Pruefung in mount() liest bloss. Der Hash macht die Frage
     * trotzdem endgueltig gegenstandslos, statt sie von dieser Reihenfolge
     * abhaengig zu lassen. Wer hier einmal aus einer rohen Eingabe einen
     * Schluessel schreibt, hat den Fund zurueck.
     */
    private static function drosselSchluessel(string $token): string
    {
        return 'konto-anlegen:' . sha1($token);
    }

    private function zuVieleFehlversuche(string $token): bool
    {
        return RateLimiter::tooManyAttempts(self::drosselSchluessel($token), self::MAX_VERSUCHE);
    }

    /**
     * Die Anrede haengt am Team, und das Team haengt an der Anstellung —
     * nicht an der Person. Genommen wird bevorzugt eine aktive; wer gar
     * keine mehr hat, bekommt das Sie (der Vorgabewert der Einstellung).
     */
    private function anredeFuer(int $personId): bool
    {
        $anstellung = RecEmployee::query()
            ->where('rec_person_id', $personId)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        return $anstellung?->usesInformalAddress() ?? false;
    }
}
