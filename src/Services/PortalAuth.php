<?php

namespace Platform\Recruiting\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
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
 */
final class PortalAuth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

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
     *  - GESPERRT gibt es auf zwei Wegen: die Drossel (die jede Nummer
     *    trifft, auch eine, zu der es gar kein Konto gibt) und die
     *    Dispo-Sperre. Letztere wird erst NACH dem Passwort ausgewertet —
     *    andersherum erfuehre jeder, der bloss die Nummer kennt, dass es
     *    dazu ein Konto gibt und dass es gesperrt ist.
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
        $schluessel = $this->nummernSchluessel($nummer);

        // Die Drossel steht VOR dem Passwortvergleich, und das ist Absicht:
        // dahinter gestellt braeche sie die Antwortzeit-Eigenschaft zwar
        // nicht, verloere aber ihre Wirkung — dann kostete jeder Versuch
        // eines Gesperrten weiterhin einen vollen bcrypt-Lauf, und genau den
        // soll die Sperre einsparen. Sie verraet dabei nichts: ihr
        // Schluessel entsteht allein aus der Eingabe, ohne einen Blick in
        // die Datenbank, und der Zaehler laeuft bei "falsches Passwort" und
        // "gibt es nicht" gleich weiter.
        if ($this->isRateLimited($schluessel)) {
            return ['status' => self::GESPERRT, 'personId' => null];
        }

        $personId = KontoWriter::pruefeAnmeldung($teamId, $nummer, $passwort);

        if ($personId === null) {
            $versuche = $this->recordFailure($schluessel);

            return [
                'status'   => $versuche >= self::MAX_ATTEMPTS ? self::GESPERRT : self::FALSCH,
                'personId' => null,
            ];
        }

        // Das Passwort stimmte — der Zaehler gehoert abgeraeumt, auch wenn
        // die Dispo-Sperre gleich zuschlaegt: das war kein Rateversuch.
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
     * (Show.php::unlockPortal, Zeile 354), und genau diese lesen der
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

    /** @return int Anzahl der Fehlversuche nach diesem hier. */
    private function recordFailure(string $token): int
    {
        $versuche = ((int) $this->store()->get($this->attemptsKey($token), 0)) + 1;
        $this->store()->put($this->attemptsKey($token), $versuche, now()->addMinutes(self::LOCKOUT_MINUTES));

        if ($versuche >= self::MAX_ATTEMPTS) {
            $this->store()->put($this->lockoutKey($token), true, now()->addMinutes(self::LOCKOUT_MINUTES));
        }

        return $versuche;
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
}
