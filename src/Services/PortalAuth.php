<?php

namespace Platform\Recruiting\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Die Anmeldeschicht des Mitarbeiterportals — als eigene Schicht, damit das
 * Konto aus Canvas 68 spaeter eingehaengt werden kann, ohne die Huelle
 * anzufassen (Bauvorschrift aus Canvas 67, Eintrag 1788).
 *
 * Heute beantwortet sie die Frage „wer ist das?" mit Token plus Geburtsdatum
 * und Ausweis-Endziffern. Spaeter mit Handynummer und Passwort. Die vier
 * Portal-Bereiche wissen nichts davon, wie die Antwort zustande kam.
 *
 * Was hier NICHT liegt: der Datenabgleich selbst (RecEmployee::verifyPortalAccess)
 * und das Sitzungs-Flag — Ersteres gehoert zum Mitarbeiter, Letzteres zur
 * Oberflaeche.
 *
 * Der Versuchszaehler haengt am TOKEN, nicht am Mitarbeiter: Wer einen fremden
 * Link durchprobiert, soll sich nicht durch Wechseln der Kennung freischalten.
 *
 * Seit dem Konto (Canvas 68) gibt es einen ZWEITEN Einstieg:
 * anmeldenMitNummer() mit Handynummer und Passwort. Beide laufen waehrend der
 * Umstellung nebeneinander, aber sie sind NICHT verschraenkt — kein
 * Konto-Nachweis oeffnet den Token-Weg und umgekehrt. Der Grund: heute ist der
 * Token das Geheimnis, kuenftig ist der Benutzername die Handynummer, und die
 * ist keines. Ein Weg, der beides mischt, waere unsicherer als der
 * Ist-Zustand.
 *
 * Die Drossel ist fuer beide Einstiege DIESELBE Mechanik (fuenf Versuche,
 * fuenfzehn Minuten, dieselben Zaehler und derselbe Speicher) — nur der
 * Schluessel unterscheidet sich: dort der Token, hier die Nummer. Zwei
 * aehnliche Mechaniken nebeneinander waeren die Stelle, an der die eine
 * repariert und die andere vergessen wird.
 *
 * Seit Ruling GD-11 gibt es beim Konto-Einstieg eine ZWEITE Bremse je IP
 * (dreissig Fehlversuche je Stunde, s. MAX_IP_ATTEMPTS). Sie trifft den
 * umgekehrten Angriff: EIN verbreitetes Passwort gegen VIELE Nummern laeuft
 * an der Nummern-Bremse vorbei, weil jede Nummer ihren eigenen Zaehler hat.
 * Beide Bremsen benutzen dieselbe Mechanik, nur mit anderer Grenze und
 * anderer Dauer.
 */
final class PortalAuth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    /**
     * Ruling GD-11: die ZWEITE Bremse, je IP.
     *
     * Die Bremse darueber haengt an der NUMMER und trifft damit nur, wer
     * viele Passwoerter gegen EINE Nummer probiert. Der umgekehrte Weg — EIN
     * verbreitetes Passwort gegen VIELE Nummern — laeuft an ihr vorbei, denn
     * jede Nummer bekommt ihren eigenen Zaehler und keiner erreicht die
     * Fuenf. Handynummern lassen sich der Reihe nach durchzaehlen, und jeder
     * Versuch kostet uns einen vollen bcrypt-Lauf plus eine Zeile in der
     * Cache-Tabelle. Genau diese Kosten deckelt diese Bremse — sie greift
     * deshalb VOR dem Passwortvergleich.
     *
     * DREISSIG, nicht fuenf: hinter einer Firmen-IP koennen viele Menschen
     * sitzen. Ein echter Mensch scheitert zwei- bis dreimal, nicht
     * dreissigmal; wer dreissig Fehlversuche in einer Stunde braucht, sucht
     * nicht sein Passwort.
     *
     * KEIN ZEITKANAL: der Zaehler entsteht allein aus der Adresse der
     * Verbindung, ohne einen Blick in die Datenbank, und er laeuft bei
     * "falsches Passwort" und "gibt es gar nicht" gleich weiter. Er verraet
     * also nichts darueber, ob es zu einer Nummer ein Konto gibt — dieselbe
     * Begruendung wie bei der Nummern-Bremse.
     *
     * WELCHE Adresse das ist, entscheidet ipSchluessel() — und zwar
     * ausdruecklich NICHT ueber $request->ip() (Ruling GD-12, Begruendung
     * dort).
     */
    public const MAX_IP_ATTEMPTS = 30;

    /** Eine Stunde. Passend zu MAX_IP_ATTEMPTS, an genau einer Stelle. */
    public const IP_LOCKOUT_MINUTES = 60;

    public const OK      = 'ok';
    public const FALSCH  = 'falsch';
    public const GESPERRT = 'gesperrt';

    public function __construct(private readonly ?CacheRepository $cache = null) {}

    public function employeeForToken(string $token): ?RecEmployee
    {
        $employee = RecEmployee::query()->where('portal_token', $token)->first();

        return ($employee && $employee->is_active) ? $employee : null;
    }

    /** Eskalations-Stufe-3-Sperre aus der Dispo (DispoEmployeeGateway::lockPortal). */
    public function isLocked(RecEmployee $employee): bool
    {
        return $employee->portal_locked_at !== null;
    }

    public function isRateLimited(string $token): bool
    {
        return (bool) $this->store()->get($this->lockoutKey($token), false);
    }

    /**
     * Ein Anmeldeversuch.
     *
     * @return array{status: string, verbleibend: int}
     */
    public function attempt(RecEmployee $employee, string $token, string $birthDate, string $idLast4): array
    {
        if ($this->isRateLimited($token)) {
            return ['status' => self::GESPERRT, 'verbleibend' => 0];
        }

        if ($employee->verifyPortalAccess(trim($birthDate), trim($idLast4))) {
            $this->clearFailures($token);

            return ['status' => self::OK, 'verbleibend' => self::MAX_ATTEMPTS];
        }

        $versuche = $this->recordFailure($token);
        $verbleibend = max(0, self::MAX_ATTEMPTS - $versuche);

        return [
            'status'      => $verbleibend === 0 ? self::GESPERRT : self::FALSCH,
            'verbleibend' => $verbleibend,
        ];
    }

    /**
     * Ein Anmeldeversuch mit Handynummer und Passwort — der Konto-Einstieg.
     *
     * Die Entscheidung "wer ist das?" trifft KontoWriter::pruefeAnmeldung();
     * hier liegt nur, was an die ANMELDUNG gehoert und nicht an das Konto:
     * die Drossel und die Dispo-Sperre.
     *
     * Ruling GD-2: $teamId darf null sein und ist es auf der oeffentlichen
     * Anmeldeseite auch — die hat keinen Team-Kontext. Dann wird ueber alle
     * Teams gesucht. Der Parameter wird nur durchgereicht; die Regel dazu
     * (und das fail closed bei mehreren lebenden Personen) lebt in
     * KontoWriter und wird hier NICHT nachgebaut.
     *
     * Keine der drei Antworten verraet, ob es die Nummer gibt:
     *  - FALSCH steht fuer falsches Passwort, unbekannte Nummer, gesperrtes
     *    oder stillgelegtes Konto und mehrdeutige Nummer gleichermassen —
     *    KontoWriter gibt dafuer schon zeichengleich null zurueck.
     *  - GESPERRT gibt es auf drei Wegen: die Nummern-Drossel (die jede
     *    Nummer trifft, auch eine, zu der es gar kein Konto gibt), die
     *    IP-Drossel (Ruling GD-11, die jede Anfrage von dieser Adresse
     *    trifft) und die Dispo-Sperre. Letztere wird erst NACH dem Passwort
     *    ausgewertet — andersherum erfuehre jeder, der bloss die Nummer
     *    kennt, dass es dazu ein Konto gibt und dass es gesperrt ist. Die
     *    ersten beiden haengen an nichts Gespeichertem und verraten daher
     *    nichts.
     *
     * Zur Antwortzeit: pruefeAnmeldung() rechnet mit Absicht IMMER einen
     * Passwortvergleich, auch ins Leere, damit die Dauer nicht verraet, ob
     * ein Konto existiert. Vor diesem Aufruf steht deshalb nichts, was
     * frueher zurueckkehren koennte, ausser der Drossel — und die trifft
     * jede Nummer gleich, ob es sie gibt oder nicht.
     *
     * @return array{status: string, personId: ?int}
     */
    public function anmeldenMitNummer(?int $teamId, string $nummer, string $passwort): array
    {
        $schluessel   = $this->nummernSchluessel($nummer);
        $ipSchluessel = $this->ipSchluessel();

        // Die Drossel steht VOR dem Passwortvergleich, und das ist Absicht:
        // dahinter gestellt braeche sie die Antwortzeit-Eigenschaft zwar
        // nicht, verloere aber ihre Wirkung — dann kostete jeder Versuch
        // eines Gesperrten weiterhin einen vollen bcrypt-Lauf, und genau den
        // soll die Sperre einsparen. Sie verraet dabei nichts: ihr
        // Schluessel entsteht allein aus der Eingabe, ohne einen Blick in
        // die Datenbank, und der Zaehler laeuft bei "falsches Passwort" und
        // "gibt es nicht" gleich weiter.
        // Dasselbe gilt fuer die IP-Bremse (Ruling GD-11): sie soll den
        // bcrypt-Lauf einsparen, nicht bloss die Antwort aendern.
        if ($this->isRateLimited($schluessel) || $this->isRateLimited($ipSchluessel)) {
            return ['status' => self::GESPERRT, 'personId' => null];
        }

        $personId = KontoWriter::pruefeAnmeldung($teamId, $nummer, $passwort);

        if ($personId === null) {
            $versuche   = $this->recordFailure($schluessel);
            $ipVersuche = $this->recordFailure($ipSchluessel, self::MAX_IP_ATTEMPTS, self::IP_LOCKOUT_MINUTES);

            return [
                'status'   => ($versuche >= self::MAX_ATTEMPTS || $ipVersuche >= self::MAX_IP_ATTEMPTS)
                    ? self::GESPERRT
                    : self::FALSCH,
                'personId' => null,
            ];
        }

        // Das Passwort stimmte — der Zaehler DER NUMMER gehoert abgeraeumt,
        // auch wenn die Dispo-Sperre gleich zuschlaegt: das war kein
        // Rateversuch.
        //
        // DER IP-ZAEHLER BLEIBT STEHEN, und das ist der Punkt: wer viele
        // Nummern durchprobiert, hat in aller Regel ein eigenes Konto zur
        // Hand. Duerfte er damit zwischendurch seinen IP-Zaehler abraeumen,
        // waere die Bremse mit einem einzigen richtigen Passwort alle
        // dreissig Versuche wieder auf null — also keine Bremse.
        $this->clearFailures($schluessel);

        if ($this->istDispoGesperrt($personId)) {
            // Bewusst OHNE personId: "gesperrt" heisst kein Zutritt, und wer
            // keine Kennung bekommt, kann sie auch nicht versehentlich als
            // Identitaet weiterverwenden.
            return ['status' => self::GESPERRT, 'personId' => null];
        }

        return ['status' => self::OK, 'personId' => $personId];
    }

    /**
     * Die Eskalations-Stufe-3-Sperre aus der Dispo, fuer den ganzen Menschen.
     *
     * KontoWriter prueft portal_locked_at bewusst NICHT: die Spalte haengt an
     * der ANSTELLUNG, nicht an der Person, und ist damit keine Frage des
     * Kontos. Die Antwort auf sie gehoert hierher.
     *
     * Ruling GD-9: Gefragt wird GENAU DIE MENGE, DIE DIE ENTSPERRUNG BEDIENT
     * — die Dispo-Identitaetsgruppe aus DispoIdentityResolver. Sie gruppiert
     * ueber den gemeinsamen CRM-Kontakt und nur ueber AKTIVE Anstellungen des
     * Anker-Teams; genau diese Gruppe sperrt die Eskalation
     * (DispoEmployeeGateway::lockPortal), genau diese entsperrt HR
     * (PortalEntsperrung::fuerAnstellung), und genau diese lesen der
     * Einsatz-Bereich (EmployeeAssignments) und der Anhang-Abruf
     * (DispoAttachmentController).
     *
     * Eine selbstgebaute Abfrage ueber rec_person_id waere eine ZWEITE
     * Wahrheit, und die beiden erreichen einander nicht:
     *  - Eine Sperre an einer Anstellung ausserhalb der Gruppe liesse sich
     *    nicht mehr aufheben. HR drueckt den Knopf, bekommt "Portalzugang
     *    entsperrt" — und der Mensch kommt trotzdem nicht hinein, ohne
     *    erkennbaren Grund und nur per SQL zu heilen.
     *  - Umgekehrt liegt ein gesperrter Geschwister-Datensatz mit fehlendem
     *    oder abweichendem rec_person_id ausserhalb der Personen-Klammer: im
     *    Einsatz-Bereich zu, an der Anmeldung offen.
     *
     * Der Preis: eine Sperre an einer inzwischen BEENDETEN Anstellung greift
     * hier nicht mehr, weil die Gruppe nur aktive kennt. Das ist die
     * gewollte Richtung — eine Eskalationsstufe bezieht sich auf laufende
     * Einsaetze, und eine Sperre, die niemand mehr aufheben kann, ist
     * schlimmer als eine, die zu frueh endet. Deshalb werden auch nur AKTIVE
     * Anstellungen als Anker in groupsFor() gegeben: ein inaktiver Anker
     * faellt dort auf sich selbst zurueck und brauchte genau die Sperre
     * wieder ein, die HR nicht mehr erreicht.
     */
    private function istDispoGesperrt(int $personId): bool
    {
        $anker = RecEmployee::query()
            ->where('rec_person_id', $personId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($anker === []) {
            // Kommt ueber diesen Weg nicht vor: pruefeAnmeldung() laesst
            // niemanden ohne aktive Anstellung durch.
            return false;
        }

        $gruppe = [];
        foreach (app(DispoIdentityResolver::class)->groupsFor($anker) as $teilgruppe) {
            $gruppe = array_merge($gruppe, $teilgruppe);
        }

        return RecEmployee::query()
            ->whereIn('id', array_values(array_unique($gruppe)))
            ->whereNotNull('portal_locked_at')
            ->exists();
    }

    /**
     * Der Drossel-Schluessel der Nummer.
     *
     * Er haengt an der NORMALISIERTEN Nummer, nicht am getippten Text: sonst
     * schuettelt man die Sperre durch eine andere Schreibweise derselben
     * Nummer ab ("0151 ..." statt "+49151 ..."), und die Drossel waere
     * wirkungslos. Und er haengt an der Nummer, nicht an der gefundenen
     * Person: eine unbekannte Nummer wird genauso gedrosselt wie eine
     * bekannte, sonst waere der Unterschied im Verhalten genau die Auskunft,
     * die die Meldungen vermeiden.
     *
     * Eine unlesbare Eingabe bekommt ihren getrimmten Rohtext als Grundlage
     * - sie findet ohnehin nie ein Konto, soll aber trotzdem zaehlen.
     *
     * GEHASHT, und zwar aus zwei Gruenden:
     *  - Die Eingabe kommt von einer oeffentlichen Seite und ist UNBEGRENZT
     *    lang. Der Wirt faehrt CACHE_STORE=database, und cache.key ist
     *    varchar(255) PRIMARY KEY: ab rund 207 Zeichen Eingabe wirft das
     *    Schreiben des Zaehlers SQLSTATE[22001] - eine 500er-Antwort auf der
     *    Anmeldeseite, ausloesbar von jedem. Der Token-Weg hat das nicht,
     *    dort erreicht nur ein gefundener Mitarbeiter den Zaehler. Ein Hash
     *    ist immer gleich lang.
     *  - Sonst stuende die vollstaendige Handynummer fuenfzehn Minuten im
     *    Klartext in der Cache-Tabelle - waehrend KontoWriter dieselbe Nummer
     *    vor einer Log-Zeile mit Datenschutz-Begruendung auf vier Stellen
     *    kuerzt.
     * Kein kryptografischer Hash noetig: hier wird nichts geprueft, nur ein
     * Schluessel gebildet. Die Normalisierung laeuft VOR dem Hashen, sonst
     * ergaeben zwei Schreibweisen derselben Nummer wieder zwei Zaehler.
     *
     * Der Vorsatz "nummer:" haelt die beiden Einstiege auseinander: Token und
     * Nummer teilen sich denselben Speicher und dieselben Zaehler, aber nie
     * einen Schluessel. Eine gesperrte Nummer sperrt den Token-Weg NICHT mit
     * (sonst sperrte ein Fremder einen Mitarbeiter aus, indem er dessen
     * oeffentlich bekannte Nummer durchprobiert), und ein gesperrter Token
     * nicht das Konto.
     */
    private function nummernSchluessel(string $nummer): string
    {
        return 'nummer:' . hash('xxh128', PhoneE164::normalize($nummer) ?? trim($nummer));
    }

    public function clearFailures(string $token): void
    {
        $this->store()->forget($this->attemptsKey($token));
        $this->store()->forget($this->lockoutKey($token));
    }

    /**
     * Welche dieser Nummern sind gerade gebremst (Spec HR-Bereich §5.4)?
     * Der Schluessel bleibt privat hier; EIN many() fuer alle — bei
     * CACHE_STORE=database ist das eine Abfrage (whereIn), keine je Person.
     *
     * @param  list<string>  $nummern
     * @return array<string, ?string>  nur die gebremsten: Nummer (wie uebergeben) => "frei ab" (ISO) oder null (Altwert true)
     */
    public function gebremsteNummern(array $nummern): array
    {
        if ($nummern === []) {
            return [];
        }

        $jeSchluessel = [];
        foreach ($nummern as $nummer) {
            $jeSchluessel[$this->lockoutKey($this->nummernSchluessel((string) $nummer))] = (string) $nummer;
        }

        $gebremst = [];
        foreach ($this->store()->many(array_keys($jeSchluessel)) as $schluessel => $wert) {
            if (!$wert) {
                continue;
            }
            $gebremst[$jeSchluessel[$schluessel]] = is_string($wert) ? $wert : null;
        }

        return $gebremst;
    }

    /** HR loest die Nummern-Bremse (Spec §5.4). Die IP-Bremse bleibt unberuehrt. */
    public function loeseNummernBremse(string $nummer): void
    {
        $this->clearFailures($this->nummernSchluessel($nummer));
    }

    /**
     * Ein Fehlversuch auf einem Schluessel.
     *
     * Grenze und Dauer sind Parameter mit Vorgabewerten, damit die IP-Bremse
     * DIESELBE Mechanik benutzt wie die Nummern-Bremse (Ruling GD-11) —
     * dieselben Zaehler, dieselben Schluesselformen, derselbe Speicher. Zwei
     * aehnliche Mechaniken nebeneinander waeren genau die Stelle, an der die
     * eine repariert und die andere vergessen wird; davor warnt schon der
     * Klassen-Docblock.
     *
     * @return int Anzahl der Fehlversuche nach diesem hier.
     */
    private function recordFailure(string $token, int $grenze = self::MAX_ATTEMPTS, int $minuten = self::LOCKOUT_MINUTES): int
    {
        $versuche = ((int) $this->store()->get($this->attemptsKey($token), 0)) + 1;
        $this->store()->put($this->attemptsKey($token), $versuche, now()->addMinutes($minuten));

        if ($versuche >= $grenze) {
            // Der Wert ist das ENDE der Sperre (Spec HR-Bereich §3.2: "frei ab 14:32").
            // Wahr wie zuvor true — isRateLimited() und das alte Portal lesen (bool).
            $ende = now()->addMinutes($minuten);
            $this->store()->put($this->lockoutKey($token), $ende->toIso8601String(), $ende);
        }

        return $versuche;
    }

    /**
     * Der Drossel-Schluessel der Adresse, von der die Anfrage kommt.
     *
     * NICHT $request->ip(), UND DAS IST DER GANZE PUNKT (Ruling GD-12).
     *
     * Der Wirt vertraut ALLEN Vermittlern: meingedeck/bootstrap/app.php
     * setzt trustProxies(at: '*', headers: HEADER_X_FORWARDED_FOR | ...).
     * Damit liest Laravel die Adresse aus der Kopfzeile X-Forwarded-For, und
     * die schreibt der Anfragende selbst. Nachgemessen mit genau dieser
     * Einstellung: bei REMOTE_ADDR 203.0.113.7 und der Kopfzeile 9.9.9.9
     * liefert ip() den Wert 9.9.9.9.
     *
     * Eine Bremse auf dieser Grundlage waere schlimmer als gar keine:
     *  - Sie deckelte die bcrypt-Kosten NICHT — ein neuer Kopfzeilen-Wert je
     *    Anfrage gibt einen frischen Zaehler, und genau das, wofuer Ruling
     *    GD-11 gebaut wurde, fiele aus.
     *  - Sie waere eine AUSSPERR-WAFFE: dreissig Fehlversuche mit der
     *    Buero-Adresse in der Kopfzeile sperrten dieses Buero eine Stunde
     *    lang aus, und die Leute dort saehen dieselbe Meldung wie bei
     *    falschem Passwort. Niemand kaeme darauf, woran es liegt.
     *
     * Genommen wird deshalb REMOTE_ADDR, die Adresse der TCP-Verbindung. Sie
     * steht fest, bevor irgendeine Kopfzeile gelesen wird, und laesst sich
     * vom Anfragenden nicht setzen.
     *
     * WANN DAS HIER BRICHT: kommt je ein CDN oder ein echter Vermittler
     * davor, ist REMOTE_ADDR dessen Adresse — dann fallen ALLE Anfragen in
     * einen Topf, und die Bremse sperrt alle zugleich aus. Dann, und erst
     * dann, gehoert trustProxies im Wirt auf die tatsaechliche
     * Vermittler-Adresse eingeengt (statt '*'); ab da ist $request->ip()
     * wieder die richtige Quelle, und diese Methode gehoert zurueckgebaut.
     *
     * GEHASHT, aus denselben zwei Gruenden wie bei der Nummer: eine IP ist
     * ein personenbezogenes Datum und hat im Klartext nichts in der
     * Cache-Tabelle zu suchen, und ein Hash ist immer gleich lang (cache.key
     * ist varchar(255) PRIMARY KEY).
     *
     * OHNE ANFRAGE — auf der Konsole, in Tests ohne gebundene Anfrage —
     * faellt alles auf EINEN Schluessel. Das ist gewollt und harmlos: ueber
     * diesen Weg meldet sich niemand von der Konsole an, und ein gemeinsamer
     * Zaehler ist strenger als gar keiner.
     */
    private function ipSchluessel(): string
    {
        $anfrage = app()->bound('request') ? app('request') : null;
        $ip = $anfrage instanceof Request ? (string) $anfrage->server->get('REMOTE_ADDR', '') : '';

        return 'ip:' . hash('xxh128', $ip);
    }

    private function store(): CacheRepository
    {
        return $this->cache ?? Cache::store();
    }

    private function attemptsKey(string $token): string
    {
        return "employee_portal_attempts:{$token}";
    }

    /**
     * DIESELBEN Schluessel wie im alten EmployeePortal — solange beide
     * nebeneinander laufen, muss eine Sperre in beiden gelten. Ein eigener
     * Schluessel hiesse: im alten gesperrt, im neuen offen.
     */
    private function lockoutKey(string $token): string
    {
        return "employee_portal_locked:{$token}";
    }

    public static function sessionKey(int $employeeId): string
    {
        return "employee_portal_verified:{$employeeId}";
    }

    /**
     * Sitzungs-Merke: welche Anstellungen hat eine KONTO-Anmeldung geoeffnet?
     *
     * Die Konto-Seite leitet nach der Anmeldung weiter; ihre Liste
     * ($geoeffnet) stirbt mit der Komponente. Damit "Abmelden" im Portal
     * weiss, dass die Sitzung aus dem Konto kam und WELCHE Schluessel
     * dazugehoeren, liegt dieselbe Liste hier in der Sitzung. Eine Abfrage
     * ueber die Person beim Abmelden waere eine zweite Wahrheit und traefe im
     * Zweifel andere Zeilen als das Oeffnen.
     */
    public const KONTO_ANMELDUNG = 'recruiting.konto.anmeldung';

    /** @param list<int> $kennungen Anstellungs-Kennungen, die die Konto-Anmeldung geoeffnet hat. */
    public static function merkeKontoAnmeldung(array $kennungen): void
    {
        app('session')->put(self::KONTO_ANMELDUNG, array_values(array_map('intval', $kennungen)));
    }

    /**
     * Die Anstellungen der Konto-Anmeldung, zu der DIESE Anstellung gehoert —
     * oder null, wenn ihre Sitzung nicht aus dem Konto kam (reiner Token-Weg).
     *
     * @return list<int>|null
     */
    public static function kontoAnmeldungVon(int $employeeId): ?array
    {
        $liste = app('session')->get(self::KONTO_ANMELDUNG);
        if (!is_array($liste)) {
            return null;
        }
        $liste = array_values(array_map('intval', $liste));

        return in_array($employeeId, $liste, true) ? $liste : null;
    }

    /**
     * DIE EINE Regel fuer das Ende einer Konto-Anmeldung: genau die
     * Sitzungen, die sie geoeffnet hat, und die Merke selbst. Benutzt von
     * KontoAnmelden::abmelden() und PortalShell::logout().
     *
     * @param list<int> $kennungen
     */
    public static function beendeKontoAnmeldung(array $kennungen): void
    {
        $sitzung = app('session');
        foreach ($kennungen as $kennung) {
            $sitzung->forget(self::sessionKey((int) $kennung));
        }
        $sitzung->forget(self::KONTO_ANMELDUNG);
    }

    /**
     * Was eine frische Portal-Sitzung speichert: den ANMELDEZEITPUNKT statt
     * true (Spec HR-Bereich §9.6). Eine Stelle fuer alle drei Schreibstellen
     * (KontoAnmelden, PortalShell, EmployeePortal).
     */
    public static function anmeldeWert(): string
    {
        return now()->toIso8601String();
    }

    /**
     * DIE EINE Pruefstelle: gilt die Portal-Sitzung fuer diese Anstellung noch?
     *
     * Gueltig ist sie, wenn ein Sitzungswert existiert UND der
     * Anmeldezeitpunkt NACH rec_persons.sitzungen_ungueltig_ab der Person
     * liegt (gesetzt von KontoWriter: HR-Zuruecksetzen, Aufheben der Notfallsperre). Ein Altwert
     * true (Sitzungen von vor §9.6) gilt als Zeitpunkt 0 — er lebt bis zum
     * ersten Zuruecksetzen und stirbt dann. Ohne Personen-Zeile: gueltig wie
     * bisher. Ungueltige Schluessel werden verworfen.
     *
     * Eine zweite Pruefung mit eigener Logik ist verboten — sonst gibt es
     * wieder eine Seite, die den Fremden weiter bedient. Die Spalte wird ueber
     * first() gelesen, damit Testschemata ohne sie wie "nie ungueltig" wirken.
     *
     * @param  object|null  $sitzung  Session-Store (Request-Session); ohne Angabe app('session').
     */
    public static function sitzungGueltig(int $employeeId, ?object $sitzung = null): bool
    {
        $sitzung ??= app('session');
        $schluessel = self::sessionKey($employeeId);
        $wert = $sitzung->get($schluessel);

        if ($wert === null || $wert === false) {
            return false;
        }

        // (int): NULL und Unsinn werden 0 — auch der String, den SQLite fuer
        // eine fehlende Spalte liefert. 0 ist keine Person: wie bisher gueltig.
        $personId = (int) DB::table('rec_employees')->where('id', $employeeId)->value('rec_person_id');
        if (!$personId) {
            return true;
        }

        $person = DB::table('rec_persons')->where('id', $personId)->first();
        $grenzeRoh = $person->sitzungen_ungueltig_ab ?? null;
        if ($grenzeRoh === null) {
            return true;
        }

        $grenze = strtotime((string) $grenzeRoh);
        if ($grenze !== false && self::anmeldeZeitpunkt($wert) <= $grenze) {
            $sitzung->forget($schluessel);

            return false;
        }

        return true;
    }

    /** Altwert true und Unlesbares = 0 (die strenge Richtung). */
    public static function anmeldeZeitpunkt(mixed $wert): int
    {
        if (!is_string($wert)) {
            return 0;
        }
        $zeit = strtotime($wert);

        return $zeit === false ? 0 : $zeit;
    }
}
