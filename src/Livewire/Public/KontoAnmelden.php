<?php

namespace Platform\Recruiting\Livewire\Public;

use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Die Anmeldeseite des Mitarbeiterkontos (Canvas 68, Spec 2.1).
 *
 * Handynummer und Passwort, sonst nichts. Die Entscheidung "wer ist das?"
 * faellt in PortalAuth::anmeldenMitNummer(); diese Seite ist die Oberflaeche
 * davor und traegt nur, was zur SEITE gehoert und nicht zur Anmeldung:
 * das Weiterleitungsziel, den Haken "angemeldet bleiben" und das Oeffnen der
 * Portal-Sitzung.
 *
 * DAS ALTE VERFAHREN STEHT HIER NICHT DANEBEN. Geburtsdatum plus
 * Ausweis-Endziffern bleiben im Token-Portal erreichbar, solange die
 * Umstellung laeuft — aber NICHT auf dieser Seite. Der Grund ist zwingend:
 * der Benutzername ist die Handynummer und damit kein Geheimnis. Waere das
 * alte Verfahren hier als zweiter Weg offen, kaeme jeder, der eine Nummer
 * kennt, ueber die Nebentuer hinein, und die Umstellung machte das Portal
 * unsicherer als vorher. Deshalb gibt es auf dieser Seite kein Feld fuer
 * Geburtsdatum und keines fuer Ausweisziffern.
 *
 * DIE PERSONEN-/ANSTELLUNGS-FALLE (Befund der Aufgabe-5-Pruefung).
 * anmeldenMitNummer() liefert eine PERSONEN-Kennung (rec_persons.id),
 * PortalAuth::sessionKey() erwartet eine ANSTELLUNGS-Kennung
 * (rec_employees.id). Beide sind int, nichts im Typ haelt sie auseinander.
 * Wer die eine in die andere steckt, oeffnet mit einem Konto-Nachweis eine
 * FREMDE Portal-Sitzung — naemlich die der Anstellung, deren Nummer zufaellig
 * gleich der Personen-Kennung ist. Deshalb loest anstellungenVon() die Person
 * ausdruecklich in ihre Anstellungen auf, und nur deren Kennungen erreichen
 * sessionKey().
 *
 * OFFEN, BEWUSST NICHT GEBAUT (Ruling GD-10): "Angemeldet bleiben". Spec 2.1
 * nennt den Haken fuer das eigene Handy, und er stand hier schon einmal — er
 * setzte einen Merker in die Sitzung und bewirkte damit NICHTS. Eine
 * Verlaengerung braucht eine Middleware im Wirt, die die Lebensdauer dieser
 * Sitzung heraufsetzt; das liegt ausserhalb dieses Moduls und wartet auf eine
 * Freigabe. Bis dahin steht der Haken bewusst nicht da: ein
 * Kontrollkaestchen, das nichts tut, ist ein Versprechen, das nicht gehalten
 * wird — der Mensch hakt es an, fliegt nach zwei Stunden heraus und ruft HR
 * an. Was dabei NIE kommen darf, ist ein dauerhaftes Geheimnis im Browser
 * (ein "remember me"-Token): das waere ein zweiter Anmeldeweg ohne Passwort.
 *
 * DIE BREMSE liegt nicht an dieser Route, sondern in PortalAuth: fuenf
 * Versuche je Nummer und fuenfzehn Minuten, dazu dreissig Fehlversuche je
 * Stunde und IP (Ruling GD-11). Eine Route-Drossel haette hier wenig Wert,
 * weil die Anmeldeversuche ueber /livewire/update laufen und die GET-Adresse
 * /konto gar nicht wieder anfassen — sie ersetzt aber auch keine der beiden.
 *
 * Sicherheit: dieselbe Lehre wie aus dem Auth-Bypass vom 19.08.2026 — alles,
 * was ueber Identitaet oder Zustand entscheidet, ist #[Locked]. $wire.set
 * kommt daran nicht vorbei.
 */
class KontoAnmelden extends Component
{
    /**
     * EINE Meldung fuer jeden Fehlschlag.
     *
     * PortalAuth unterscheidet 'falsch' (falsches Passwort, unbekannte
     * Nummer, mehrdeutige Nummer) und 'gesperrt' (Drossel oder
     * Dispo-Eskalationsstufe 3). Nach aussen darf dieser Unterschied NICHT
     * sichtbar werden: wer die Nummer eines anderen kennt, erfuehre sonst an
     * der Meldung, dass es dazu ein Konto gibt und in welchem Zustand es ist.
     * Die Nummer ist der Benutzername und kein Geheimnis — sie steht in jedem
     * Telefonbuch der Kollegen.
     *
     * Der Hinweis auf spaeter steht deshalb IN derselben Meldung und nicht in
     * einer zweiten: die Drossel ist der einzige Fehlschlag, der von selbst
     * vergeht, und ohne den Satz sucht ein Gesperrter den Fehler bei sich.
     * Gesagt wird er allen gleichermassen.
     */
    public const MELDUNG = 'Das hat nicht geklappt. Bitte prüfen Sie Handynummer und Passwort '
        . 'und versuchen Sie es in einigen Minuten noch einmal.';

    /**
     * Die Routen, auf die nach der Anmeldung gesprungen werden darf.
     *
     * POSITIVLISTE, keine Sperrliste: alles, was hier nicht steht, wird
     * verworfen. Ein Weiterleiter, der jede Adresse annimmt, ist eine
     * Einladung — ein Link auf /konto?weiter=https://fremde.seite sieht fuer
     * den Menschen aus wie unsere Anmeldung und schickt ihn danach weiter.
     *
     * Beide Eintraege sind die Ziele aus Spec 2.2: die WhatsApp-Knoepfe
     * (Dokument, Erinnerung) fuehren auf das Portal, die Einsatz-Seite ist
     * die dort benannte Ausnahme (Spec 7).
     */
    public const ZIEL_ROUTEN = [
        'recruiting.public.portal-shell',
        'recruiting.public.employee-assignments',
    ];

    /**
     * 'formular' oder 'ohne-ziel'. #[Locked] — genau diese Art Eigenschaft
     * war der Bypass vom 19.08.2026 ($wire.set state=verified).
     */
    #[Locked] public string $state = 'formular';

    /**
     * Das Weiterleitungsziel, bereits geprueft (s. erlaubtesZiel()).
     *
     * #[Locked], obwohl der Mensch es ueber die Adresse ohnehin selbst
     * bestimmt: gepruft wird EINMAL, in mount(). Ohne die Sperre setzte
     * $wire.set nach der Pruefung einen beliebigen Wert, und die Positivliste
     * waere Zierat.
     */
    #[Locked] public string $weiter = '';

    /**
     * Die Eingaben — bewusst NICHT gesperrt, sie kommen ja vom Menschen. Ihre
     * Sicherheit sitzt nicht in der Unveraenderlichkeit, sondern darin, dass
     * anmelden() bei JEDEM Aufruf Nummer und Passwort erneut pruefen laesst.
     */
    public string $nummer = '';
    public string $passwort = '';

    public string $fehler = '';

    public function mount(Request $anfrage): void
    {
        // VERWORFEN, nicht bereinigt: ein fremdes Ziel wird zu einer leeren
        // Zeichenkette und faellt damit auf die eigene Startseite zurueck.
        // Wer es stattdessen "sauberrechnet" (Host abschneiden, Schema
        // entfernen), baut sich aus einer fremden Adresse eine eigene
        // zusammen und trifft am Ende doch etwas, das er nicht treffen
        // sollte.
        $roh = $anfrage->query('weiter', '');

        // NUR eine Zeichenkette kommt in Frage. "?weiter[]=a" liefert ein
        // Array, und jede Umwandlung davon nach string wirft "Array to string
        // conversion" — auf dem Wirt eine 500er-Antwort, von jedem beliebig
        // oft ausloesbar (Fund F2 der Pruefung).
        //
        // NICHT ueber $anfrage->string('weiter'): das ruft Str::of() auf, und
        // dessen Stringable-Bauer wandelt ebenfalls nach string um — nachge-
        // messen mit dieser Laravel-Fassung, die Warnung faellt dort genauso.
        // Die Frage nach dem Typ ist die einzige Fassung, die haelt.
        $this->weiter = self::erlaubtesZiel(is_string($roh) ? $roh : '');
    }

    public function anmelden(PortalAuth $auth): void
    {
        $this->fehler = '';

        // Leere Eingaben kosten keinen Versuch. Ueber die Oberflaeche sind sie
        // durch `required` kaum zu treffen, ueber $wire.call('anmelden') aber
        // trivial. Diese Meldung verraet nichts: sie haengt an der EIGENEN
        // Eingabe und nicht daran, was gespeichert ist.
        if (trim($this->nummer) === '' || $this->passwort === '') {
            $this->fehler = 'Bitte geben Sie Handynummer und Passwort ein.';

            return;
        }

        // Ruling GD-2: KEIN Team. Die Seite ist oeffentlich und hat keinen
        // Team-Kontext; die Suche laeuft ueber alle Teams und scheitert bei
        // mehr als einer lebenden Person mit derselben Antwort wie ein
        // falsches Passwort. Wer hier ein Team einsetzt, erfindet einen
        // Kontext, den die Seite nicht hat — und sperrt damit jeden aus, der
        // nicht in diesem Team steht.
        $ergebnis = $auth->anmeldenMitNummer(null, $this->nummer, $this->passwort);

        // Das Passwort hat nach dem Versuch auf der Seite nichts mehr zu
        // suchen — es faehrt sonst im Livewire-Schnappschuss weiter mit.
        $this->passwort = '';

        if ($ergebnis['status'] !== PortalAuth::OK || $ergebnis['personId'] === null) {
            // 'falsch' und 'gesperrt' muenden hier bewusst in DIESELBE
            // Meldung (s. MELDUNG).
            $this->fehler = self::MELDUNG;

            return;
        }

        $anstellungen = self::anstellungenVon($ergebnis['personId']);

        if ($anstellungen === []) {
            // Ueber diesen Weg nicht erreichbar: pruefeAnmeldung() laesst
            // niemanden ohne aktive Anstellung durch. Trotzdem fail closed —
            // ohne Anstellung gaebe es keinen Sitzungsschluessel, und eine
            // "erfolgreiche" Anmeldung ohne Sitzung waere eine Seite, die
            // stumm nichts tut.
            $this->fehler = self::MELDUNG;

            return;
        }

        // HIER liegt die Falle, vor der der Klassen-Docblock warnt: in
        // sessionKey() gehoert eine ANSTELLUNGS-Kennung, niemals die
        // Personen-Kennung aus $ergebnis.
        foreach ($anstellungen as $anstellung) {
            session()->put(PortalAuth::sessionKey((int) $anstellung->id), true);
        }

        $ziel = $this->weiter !== '' ? $this->weiter : self::startseiteFuer($anstellungen);

        if ($ziel === null) {
            // Angemeldet, aber es gibt nichts zu oeffnen: die Anstellung ist
            // noch nicht auf das neue Portal umgestellt (portal_v2_since).
            // Das darf der Angemeldete erfahren — er hat sein Passwort ja
            // gerade bewiesen.
            $this->state = 'ohne-ziel';

            return;
        }

        $this->redirect($ziel);
    }

    public function render()
    {
        return view('recruiting::livewire.public.konto-anmelden')
            ->layout('recruiting::layouts.portal', [
                'title' => 'Anmelden · RheinGedeck',
            ]);
    }

    // ------------------------------------------------------------------ intern

    /**
     * Die Anstellungen DIESER Person.
     *
     * Deckungsgleich mit KontoWriter::hatAktiveAnstellung() — dieselbe Menge,
     * die dort ueber "darf sich ueberhaupt anmelden?" entscheidet. Eine
     * andere Menge hier hiesse: jemand kommt durch die Anmeldung und bekommt
     * trotzdem keine Sitzung, oder umgekehrt.
     *
     * Die Sitzung wird fuer ALLE aktiven Anstellungen geoeffnet, nicht nur
     * fuer eine: das Konto gehoert der Person, und wer zwei Anstellungen hat
     * (RG und MA), soll nicht bei der zweiten wieder vor einer Anmeldung
     * stehen. Das ist keine Verschraenkung zweier Menschen, sondern die
     * Personen-Klammer — die Zeilen haengen ueber rec_person_id ausdruecklich
     * an derselben Person.
     *
     * @return list<object>
     */
    private static function anstellungenVon(int $personId): array
    {
        return RecEmployee::query()
            ->where('rec_person_id', $personId)
            ->where('is_active', 1)
            ->orderBy('id')
            ->get(['id', 'portal_token', 'portal_v2_since'])
            ->all();
    }

    /**
     * Wohin es ohne mitgegebenes Ziel geht: das neue Portal der ersten
     * Anstellung, die dorthin umgestellt ist.
     *
     * Die Reihenfolge ist die Kennung (orderBy id) und damit fest — ohne eine
     * feste Reihenfolge landete derselbe Mensch mal in der einen, mal in der
     * anderen Anstellung, je nachdem, wie die Datenbank die Zeilen liefert.
     * Ohne Token gibt es keine Adresse, ohne portal_v2_since antwortet die
     * Huelle mit 404 — beides faellt hier heraus, statt hinterher als leere
     * Seite aufzuschlagen.
     *
     * @param list<object> $anstellungen
     */
    private static function startseiteFuer(array $anstellungen): ?string
    {
        foreach ($anstellungen as $anstellung) {
            $token = (string) ($anstellung->portal_token ?? '');

            if ($token !== '' && $anstellung->portal_v2_since !== null) {
                return route('recruiting.public.portal-shell', ['token' => $token]);
            }
        }

        return null;
    }

    /**
     * Das mitgefuehrte Weiterleitungsziel — oder eine leere Zeichenkette.
     *
     * Spec 2.2, woertlich aus Canvas 68: "WhatsApp-Knoepfe fuehren zur
     * Login-Seite und nach dem Passwort direkt zum Ziel. Ein Link meldet nie
     * von selbst an." Das Ziel faehrt also in der Adresse mit — und genau
     * deshalb muss es geprueft werden.
     *
     * Geprueft wird in zwei Stufen, und beide sind noetig:
     *
     *  1. STRUKTUR: ein eigenes Ziel hat weder Schema noch Host. Diese Stufe
     *     steht VOR dem Nachschlagen, weil Request::create() aus
     *     "https://fremde.seite/einsaetze/x" klaglos einen Treffer auf
     *     /einsaetze/{token} macht — der Pfad passt ja. Ohne diese Stufe
     *     kaeme eine fremde Adresse durch die Positivliste.
     *  2. POSITIVLISTE: der Pfad muss eine EIGENE Route treffen, und deren
     *     Name muss in ZIEL_ROUTEN stehen. Nachgeschlagen wird im echten
     *     Routenverzeichnis und nicht an einer nachgebauten Pfadliste — eine
     *     zweite Fassung der Adressen driftet beim naechsten Umbau ab.
     *
     * Was diese Pruefung NICHT leistet: sie sagt nicht, ob der Token im Ziel
     * dem Angemeldeten gehoert. Das muss sie auch nicht — beide Zielseiten
     * entscheiden selbst ueber ihren Token (die Huelle ueber den
     * Sitzungsschluessel ihrer eigenen Anstellung, die Einsatz-Seite ueber
     * den Token selbst). Ein untergeschobener fremder Token fuehrt dort also
     * auf die Anmeldung und nicht in fremde Daten.
     */
    private static function erlaubtesZiel(string $eingabe): string
    {
        $roh = trim($eingabe);

        if ($roh === '') {
            return '';
        }

        // Steuerzeichen gehoeren in keine Adresse. Sie sind das Werkzeug, mit
        // dem eine Pruefung ueberlistet wird (ein Zeilenumbruch beendet fuer
        // manchen Leser die Zeichenkette, fuer den naechsten nicht).
        if (preg_match('/[\x00-\x1F\x7F]/', $roh) === 1) {
            return '';
        }

        // Rueckwaerts-Schraegstriche: Browser lesen sie wie Schraegstriche,
        // parse_url nicht. "/\fremde.seite" waere hier ein harmloser Pfad und
        // im Browser ein fremder Host.
        //
        // BERICHTIGT NACH DER MUTATION: diese Zeile faengt heute KEINEN Fall,
        // den nicht schon etwas anderes faengt — Symfony lehnt einen
        // Rueckwaerts-Schraegstrich in Request::create() selbst ab
        // ("A URI cannot contain a backslash"), und das ergibt unten im
        // try/catch dasselbe Ergebnis. Sie bleibt trotzdem stehen, weil die
        // Abwehr sonst an einem Fremdverhalten haengt, das niemand hier
        // nachliest; ein Test kann sie aber nicht gruen halten. Wer sie
        // entfernt, faellt in keinem Lauf auf.
        if (str_contains($roh, '\\')) {
            return '';
        }

        // Schemarelativ ("//fremde.seite/x") — der Browser liest "fremde.seite"
        // als Host, obwohl kein Schema dasteht.
        //
        // BERICHTIGT NACH DER MUTATION: diese Zeile und der Host-Eintrag in
        // der Liste unten decken DENSELBEN Fall ab; nimmt man eine von beiden
        // weg, wird kein Test rot, nimmt man beide weg, faellt die Abwehr.
        // Der urspruengliche Grund ("sie faengt auch '///x', wo parse_url
        // keinen Host findet") stimmt nicht: bei "///x" gibt parse_url
        // schlicht false zurueck, und das faengt die Wache darunter. Beide
        // bleiben stehen, weil jede fuer sich naheliegend ist und die
        // doppelte Abwehr nichts kostet.
        if (str_starts_with($roh, '//')) {
            return '';
        }

        $teile = parse_url($roh);

        if ($teile === false) {
            return '';
        }

        // Ein eigenes Ziel traegt NICHTS davon. Mit Schema oder Host waere es
        // eine fremde Adresse, mit Benutzer oder Kennwort eine Adresse, die
        // dem Menschen etwas anderes zeigt, als sie anspringt.
        foreach (['scheme', 'host', 'port', 'user', 'pass'] as $fremd) {
            if (isset($teile[$fremd])) {
                return '';
            }
        }

        $pfad = (string) ($teile['path'] ?? '');

        if ($pfad === '' || !str_starts_with($pfad, '/')) {
            return '';
        }

        try {
            $treffer = app('router')->getRoutes()->match(Request::create($pfad, 'GET'));
        } catch (\Throwable) {
            // Kein Treffer, falsche Methode, kaputter Pfad: alles dasselbe —
            // kein Ziel.
            return '';
        }

        if (!in_array((string) $treffer->getName(), self::ZIEL_ROUTEN, true)) {
            return '';
        }

        return $roh;
    }
}
