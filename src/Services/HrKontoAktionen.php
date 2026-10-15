<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Console\Commands\SendProofReminders;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrVermerk;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Support\Bote;
use Platform\Recruiting\Support\EinladungsRiegel;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\HrAktionErgebnis;
use Platform\Recruiting\Support\HrEinstellungen;
use Platform\Recruiting\Support\NummernwechselTafel;
use Platform\Recruiting\Support\TriggerRegeln;
use Platform\Recruiting\Support\VertragErinnerungsPause;
use Platform\Recruiting\Support\VertragsHerkunft;

/**
 * Der EINE Schreib-Eingang des HR-Bereichs (Spec 2026-10-12 §5.5) — fuer
 * Schreibtisch und Akte dieselbe Stelle. Jede Aktion:
 *  1. Team aus Auth::user()->currentTeam (nie aus dem Request),
 *  2. Notiz pruefen (5–500 Zeichen, getrimmt) — die Pflicht-Notiz fuer
 *     JEDE entsperrende, zuruecksetzende, freigebende oder stoppende Aktion
 *     wird HIER geprueft, auch fuer die Dispo-Entsperrung (PortalEntsperrung
 *     selbst prueft keine Notiz),
 *  3. Person/Akte im Team laden — sonst "Nicht gefunden." (keine
 *     Unterscheidung fremd/nicht vorhanden),
 *  4. zustaendigen Schreiber bzw. Sender rufen (sitzungen_ungueltig_ab setzt
 *     der KontoWriter selbst, nicht diese Klasse),
 *  5. NUR bei Wirkung: Vermerk + Log-Zeile recruiting.hr.konto_aktion (ohne
 *     Notiz, ohne Nummer, ohne Einladungs-Klartext). Einladungs-Link und
 *     -Code reisen ausschliesslich im HrAktionErgebnis zur Einmal-Anzeige.
 *
 * Sender werden untypisiert ueber app() geholt (einige sind final; Tests
 * binden Attrappen). Schreibzugriffe an rec_employees nur per Query Builder.
 */
final class HrKontoAktionen
{
    public const NOTIZ_MIN = 5;
    public const NOTIZ_MAX = 500;
    public const NICHT_GEFUNDEN = 'Nicht gefunden.';
    public const BEZUG_AUFGABEN = 'aufgaben';

    /**
     * Vermerk-Ergebnis, wenn "Konto zuruecksetzen" einen liegenden
     * Nummernwechsel-Antrag mit abgeraeumt hat (KontoWriter meldet
     * antrag_entfernt). Bewusst allgemein und nicht "Notfall": abgeraeumt
     * wird jede Quelle. Passt in rec_hr_vermerke.ergebnis (40).
     */
    public const ANTRAG_ENTFERNT = 'offener Nummernwechsel-Antrag entfernt';

    /**
     * Stillgelegte Personen-Zeile (merged_into_person_id): nur "Antrag
     * stoppen" geht (Spec §3.1/§4.1) — jede andere Aktion prueft das neu,
     * egal was die Liste angezeigt hat (§9.1).
     */
    public const STILLGELEGT = 'Diese Personen-Zeile ist stillgelegt — die Akten hängen an einer anderen Zeile. Hier geht nur „Antrag stoppen“.';

    /** Sperre je Erinnerungs-Bezug (Dokument/Vertrag): zwei parallele Klicks verschicken nicht doppelt. */
    public const ERINNERN_SPERRE = 'recruiting:hr-erinnern:';

    private const SPERRE_SEKUNDEN = 120;

    /** HR-taugliche Texte zu Sender-Status (Spec §3.3: Fehlschlag gelb mit Grund). */
    private const STATUS_TEXT = [
        'nicht_konfiguriert'           => 'Vorlage fehlt',
        'not_configured'               => 'Vorlage fehlt',
        'vorlage_untauglich'           => 'Vorlage passt nicht',
        'template_without_url_button'  => 'Vorlage passt nicht (kein Link-Knopf)',
        'template_unknown_placeholder' => 'Vorlage passt nicht (unbekannter Platzhalter)',
        'no_phone'                     => 'keine Nummer',
        'failed'                       => 'Versand abgelehnt',
        'altes_portal'                 => 'nicht im neuen Portal',
        'inaktiv'                      => 'Akte nicht aktiv',
        'erledigt'                     => 'schon erledigt',
        'zurueckgezogen'               => 'zurückgezogen',
        'nur_ablegen'                  => 'nur zur Ablage, keine Handlung nötig',
    ];

    private const RIEGEL_TEXT = [
        EinladungsRiegel::KEINE_NUMMER      => 'Keine Handynummer hinterlegt — ohne Nummer gibt es keinen Weg ins Konto.',
        EinladungsRiegel::KEIN_GEBURTSDATUM => 'Kein Geburtsdatum hinterlegt — ohne Geburtsdatum scheitert die Registrierung. Bitte erst in der Akte nachtragen.',
        EinladungsRiegel::KEIN_WHATSAPP     => 'Die Nummer ist als „kein WhatsApp“ bekannt — die Einladung käme nicht an.',
    ];

    public function __construct(private readonly ?PortalAuth $auth = null) {}

    // ------------------------------------------------------------ Scope

    public static function notizFehler(string $notiz, bool $pflicht = true): ?string
    {
        $laenge = mb_strlen((string) RecHrVermerk::bereinige($notiz));
        if ($laenge === 0 && !$pflicht) {
            return null;
        }
        if ($laenge < self::NOTIZ_MIN) {
            return 'Bitte eine Notiz mit mindestens 5 Zeichen eingeben — sie steht im Verlauf.';
        }
        if ($laenge > self::NOTIZ_MAX) {
            return 'Die Notiz ist zu lang (höchstens 500 Zeichen).';
        }

        return null;
    }

    /**
     * Person im Team: rec_persons.team_id = Team UND eine Akte im Team — an
     * der Person selbst oder (stillgelegte Zeile) an der Zielzeile.
     */
    public static function personImTeam(int $teamId, ?int $personId): ?object
    {
        if ($personId === null || $personId <= 0) {
            return null;
        }

        return DB::table('rec_persons')
            ->where('id', $personId)
            ->where('team_id', $teamId)
            ->where(function ($q) use ($teamId) {
                $q->whereExists(fn ($e) => $e->selectRaw('1')->from('rec_employees')
                    ->whereColumn('rec_employees.rec_person_id', 'rec_persons.id')
                    ->where('rec_employees.team_id', $teamId))
                  ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('rec_employees')
                    ->whereColumn('rec_employees.rec_person_id', 'rec_persons.merged_into_person_id')
                    ->where('rec_employees.team_id', $teamId));
            })
            ->first();
    }

    public static function akteImTeam(int $teamId, ?int $employeeId): ?RecEmployee
    {
        if ($employeeId === null || $employeeId <= 0) {
            return null;
        }

        return RecEmployee::query()->where('team_id', $teamId)->find($employeeId);
    }

    /**
     * Die Person einer Akte — aber nur, wenn sie im Team liegt und nicht
     * stillgelegt ist. Sonst null: der Vermerk bleibt dann an der Akte und
     * landet nie an einer teamfremden Person (Fix-Runde 1, Minor 4).
     */
    public static function personDerAkteImTeam(int $teamId, RecEmployee $akte): ?int
    {
        if ($akte->rec_person_id === null) {
            return null;
        }
        $person = self::personImTeam($teamId, (int) $akte->rec_person_id);

        return $person !== null && $person->merged_into_person_id === null ? (int) $person->id : null;
    }

    public static function erinnernSperre(string $bezug): string
    {
        return self::ERINNERN_SPERRE . $bezug;
    }

    /**
     * Die Personen-Zeile gehoert diesem Team — ohne Akten-Bedingung. Nur fuer
     * "Antrag stoppen": der Antrag einer aktenlosen Person ist sichtbar und
     * muss stoppbar bleiben (Ruling Fix-Runde 1 zu Aufgabe 6, M2).
     */
    public static function antragsPersonImTeam(int $teamId, ?int $personId): bool
    {
        return $personId !== null && $personId > 0
            && DB::table('rec_persons')->where('id', $personId)->where('team_id', $teamId)->exists();
    }

    public static function sichtbar(int $teamId, ?int $personId, ?int $employeeId): bool
    {
        return $personId !== null
            ? self::personImTeam($teamId, $personId) !== null
            : self::akteImTeam($teamId, $employeeId) !== null;
    }

    /** Ende der 7-Tage-Pause ab einem Stempel (EinsatzBezug::PAUSE_TAGE) — oder null ohne Stempel. */
    public static function pauseBis(?string $stempel): ?Carbon
    {
        return $stempel === null || $stempel === '' ? null : Carbon::parse($stempel)->addDays(EinsatzBezug::PAUSE_TAGE);
    }

    public static function statusText(string $status): string
    {
        return self::STATUS_TEXT[$status] ?? $status;
    }

    // ------------------------------------------------------------ Konto-Antraege

    public function stoppeAntrag(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        // Stoppen ist IMMER erlaubt (Ruling Fix-Runde 1 zu Aufgabe 6, M2): die Zeile
        // muss nur diesem Team gehoeren — eine Akte im Team braucht es hier nicht,
        // sonst laege der Antrag einer aktenlosen Person sichtbar, aber unstoppbar.
        if (!self::antragsPersonImTeam($teamId, $personId)) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }

        if (!KontoWriter::stoppeNummerwechsel($personId)) {
            $jetzt = (string) DB::table('rec_persons')->where('id', $personId)->value('phone');

            return HrAktionErgebnis::hinweis(sprintf(
                'Der Antrag war schon erledigt (angewendet, freigegeben oder gestoppt). Die Nummer steht jetzt auf …%s.',
                NummernwechselTafel::kurz($jetzt),
            ));
        }

        $zeile = DB::table('rec_persons')->where('id', $personId)->first(['phone', 'notfall_gesperrt_bis']);
        $this->vermerk($teamId, $personId, null, 'antrag_gestoppt', $notiz, 'nummernwechsel', 'ok');

        return HrAktionErgebnis::ok(sprintf(
            'Antrag gestoppt. Die Nummer bleibt …%s. Der Notfallweg ist für diese Person bis %s gesperrt.',
            NummernwechselTafel::kurz((string) $zeile->phone),
            Carbon::parse((string) $zeile->notfall_gesperrt_bis)->format('d.m.Y'),
        ));
    }

    public function gibAntragFrei(int $personId, string $erwartetBeantragtAt, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $person = self::lebendePerson($teamId, $personId);
        if ($person instanceof HrAktionErgebnis) {
            return $person;
        }
        if ($person->wechsel_neue_nummer !== null && $person->wechsel_quelle !== KontoWriter::ZWECK_NOTFALL) {
            return HrAktionErgebnis::fehler('Freigeben gibt es nur für Notfall-Anträge („Nummer und Passwort verloren“). Diesen Antrag können Sie nur stoppen.');
        }
        $neuKurz = NummernwechselTafel::kurz((string) $person->wechsel_neue_nummer);

        try {
            $ergebnis = KontoWriter::gibNummernwechselFrei($personId, $erwartetBeantragtAt);
        } catch (\InvalidArgumentException $e) {
            // NUR die Weigerung aus PersonLinker::setzeNummer() ("Nummer gehoert
            // im Team schon Person X") — andere Fehler (DB, Sperre) fliegen
            // weiter und werden nicht als Nummernkonflikt erklaert.
            // NIE $e->getMessage(): setzeNummer() setzt die volle Nummer
            // woertlich in seine Ausnahme (Task-3-Befund). Ins Log gehen nur
            // die Ausnahme-Klasse und die letzten vier Stellen.
            Log::warning('recruiting.konto.hr_nummernwechsel_fehler', [
                'person_id'        => $personId,
                'nummer_endet_auf' => $neuKurz,
                'fehler_art'       => $e::class,
            ]);

            return HrAktionErgebnis::fehler(sprintf(
                'Die Nummer …%s ließ sich nicht setzen — sie gehört im Team vermutlich schon einer anderen Akte. Der Antrag bleibt stehen; bitte erst die andere Akte klären.',
                $neuKurz,
            ));
        }

        if ($ergebnis === null) {
            return HrAktionErgebnis::hinweis('Der Antrag hat sich inzwischen geändert (angewendet, gestoppt, gesperrt oder neu gestellt). Bitte die Liste neu ansehen.');
        }

        $hinweis = $ergebnis['alt'] !== null
            ? (string) app(NummernwechselHinweisSender::class)->sende($personId, $ergebnis['alt'])
            : 'keine_alte_nummer';
        $this->vermerk($teamId, $personId, null, 'antrag_freigegeben', $notiz, 'nummernwechsel', $hinweis);

        return HrAktionErgebnis::ok(sprintf(
            'Freigegeben. Die Nummer steht jetzt auf …%s. Der Mensch kann jetzt mit „Passwort vergessen“ ein neues Passwort setzen.%s',
            NummernwechselTafel::kurz($ergebnis['neu']),
            $hinweis === NummernwechselHinweisSender::STATUS_SENT ? '' : ' Der Hinweis an die alte Nummer ging nicht raus.',
        ));
    }

    // ------------------------------------------------------------ Sperren

    public function entsperreKonto(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if (($p = self::lebendePerson($teamId, $personId)) instanceof HrAktionErgebnis) {
            return $p;
        }
        if (!KontoWriter::entsperreKontoDurchHr($personId)) {
            return HrAktionErgebnis::hinweis('Das Konto war nicht (mehr) gesperrt.');
        }
        $this->vermerk($teamId, $personId, null, 'konto_entsperrt', $notiz, null, 'ok');

        return HrAktionErgebnis::ok('Konto entsperrt.');
    }

    public function loeseBremse(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        // Die Bremse haengt an der NUMMER und gilt in jedem Team
        // (PortalAuth::loeseNummernBremse). Die Absicherung ist, dass die
        // Nummer aus der im Team geladenen Personen-Zeile kommt (nie aus dem
        // Request). Restrisiko, so akzeptiert: traegt eine Person in einem
        // anderen Team dieselbe Nummer, wird deren Bremse mit geloest.
        $person = self::lebendePerson($teamId, $personId);
        if ($person instanceof HrAktionErgebnis) {
            return $person;
        }
        $nummer = (string) $person->phone;
        if ($nummer === '' || $this->portalAuth()->gebremsteNummern([$nummer]) === []) {
            return HrAktionErgebnis::hinweis('Die Anmeldung war nicht (mehr) gebremst.');
        }
        $this->portalAuth()->loeseNummernBremse($nummer);
        $this->vermerk($teamId, $personId, null, 'bremse_geloest', $notiz, null, 'ok');

        return HrAktionErgebnis::ok(sprintf('Bremse gelöst. Die Anmeldung mit …%s geht wieder.', NummernwechselTafel::kurz($nummer)));
    }

    public function hebeNotfallSperreAuf(int $personId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if (($p = self::lebendePerson($teamId, $personId)) instanceof HrAktionErgebnis) {
            return $p;
        }
        if (!KontoWriter::hebeNotfallSperreAuf($personId)) {
            return HrAktionErgebnis::hinweis('Der Notfallweg war nicht (mehr) gesperrt.');
        }
        // Spec §9.6: "Entsperren nach Notfallsperre" beendet laufende Sitzungen —
        // das setzt KontoWriter im selben Update (Ruling Task 3), hier nicht erneut.
        $this->vermerk($teamId, $personId, null, 'notfallsperre_aufgehoben', $notiz, null, 'ok');

        return HrAktionErgebnis::ok('Notfallsperre aufgehoben. Laufende Portal-Anmeldungen dieser Person sind beendet.');
    }

    public function entsperrePortal(int $employeeId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $akte = self::akteImTeam($teamId, $employeeId);
        if ($akte === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        // Nur Akten des eigenen Teams — die Dispo-Gruppe kann darueber hinausreichen.
        $anzahl = app(PortalEntsperrung::class)->fuerAnstellung($employeeId, $teamId);
        if ($anzahl === 0) {
            return HrAktionErgebnis::hinweis('Das Portal war nicht (mehr) gesperrt.');
        }
        $personId = self::personDerAkteImTeam($teamId, $akte);
        $this->vermerk($teamId, $personId, $employeeId, 'portal_entsperrt', $notiz, null, (string) $anzahl);

        return HrAktionErgebnis::ok($anzahl > 1
            ? sprintf('Portal entsperrt — auch für %d verknüpfte Akte(n) derselben Person.', $anzahl - 1)
            : 'Portal entsperrt.');
    }

    // ------------------------------------------------------------ Einladung / Zuruecksetzen (aus der Akte)

    public function ladeNeuEin(int $employeeId, string $notiz): HrAktionErgebnis
    {
        return $this->einladung($employeeId, $notiz, false);
    }

    public function setzeKontoZurueck(int $employeeId, string $notiz): HrAktionErgebnis
    {
        return $this->einladung($employeeId, $notiz, true);
    }

    private function einladung(int $employeeId, string $notiz, bool $zuruecksetzen): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        $akte = self::akteImTeam($teamId, $employeeId);
        if ($akte === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($akte->rec_person_id === null) {
            return HrAktionErgebnis::fehler('Diese Akte hat noch keine Personen-Zeile — Konto-Funktionen erst nach recruiting:personen-anlegen.');
        }
        $personId = (int) $akte->rec_person_id;
        $person = self::personImTeam($teamId, $personId);
        if ($person === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($person->merged_into_person_id !== null) {
            return HrAktionErgebnis::fehler(self::STILLGELEGT);
        }
        if ($person->locked_at !== null) {
            return HrAktionErgebnis::fehler('Das Konto ist gesperrt — bitte erst entsperren.');
        }
        if ($zuruecksetzen && $person->password_hash === null) {
            return HrAktionErgebnis::fehler('Das Konto ist noch nicht registriert — dafür gibt es „Einladung neu“.');
        }
        if (!$zuruecksetzen && $person->password_hash !== null) {
            return HrAktionErgebnis::fehler('Das Konto ist schon registriert — dafür gibt es „Konto zurücksetzen“.');
        }
        $grund = EinladungsRiegel::fuerPerson($personId);
        if ($grund !== null) {
            return HrAktionErgebnis::fehler(self::RIEGEL_TEXT[$grund]);
        }

        if ($zuruecksetzen) {
            // Sitzungen beendet KontoWriter im selben Update (Ruling Task 3).
            $r = KontoWriter::setzeKontoZurueckDurchHr($personId);
            $token = $r['token'];
            $ergebnis = $r['antrag_entfernt'] ? self::ANTRAG_ENTFERNT : 'ok';
            $this->vermerk($teamId, $personId, $employeeId, 'konto_zurueckgesetzt', $notiz, null, $ergebnis);
            $meldung = 'Konto zurückgesetzt. Das alte Passwort gilt nicht mehr, laufende Anmeldungen sind beendet. Die gespeicherte Telefonnummer bleibt unverändert.'
                . ($r['antrag_entfernt'] ? ' Dabei wurde ein ' . self::ANTRAG_ENTFERNT . '.' : '');
        } else {
            $token = KontoWriter::ladeEin($personId);
            $this->vermerk($teamId, $personId, $employeeId, 'einladung_neu', $notiz, null, 'ok');
            $meldung = 'Neue Einladung erstellt. Frühere Einladungen gelten nicht mehr.';
        }

        return HrAktionErgebnis::einladung($meldung, route('recruiting.public.konto-anlegen', ['token' => $token]), $token);
    }

    // ------------------------------------------------------------ Erinnert, aber keine Reaktion

    public function kontaktiert(?int $personId, ?int $employeeId, string $notiz): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        if ($personId !== null) {
            if (($p = self::lebendePerson($teamId, $personId)) instanceof HrAktionErgebnis) {
                return $p;
            }
            $employeeId = null;
        } else {
            $akte = self::akteImTeam($teamId, $employeeId);
            if ($akte === null) {
                return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
            }
            // Ein Mensch = eine Zeile: die Ruhe gehoert an die Person — aber
            // nur an eine Person DIESES Teams; sonst bleibt sie an der Akte.
            $personId = self::personDerAkteImTeam($teamId, $akte);
            if ($personId !== null) {
                $employeeId = null;
            }
        }

        $bis = now()->addDays(HrEinstellungen::kontaktiertRuheTage($teamId));
        $this->vermerk($teamId, $personId, $employeeId, 'kontaktiert', $notiz, null, 'ok', $bis);

        return HrAktionErgebnis::ok(sprintf(
            'Als kontaktiert vermerkt. Die Zeile ruht bis %s; ist danach noch etwas offen, kommt sie von selbst zurück.',
            $bis->format('d.m.Y'),
        ));
    }

    public function erinnereErneut(?int $personId, ?int $employeeId, string $bezug, string $notiz = ''): HrAktionErgebnis
    {
        $teamId = $this->team();
        if (($f = self::notizFehler($notiz, false)) !== null) {
            return HrAktionErgebnis::fehler($f);
        }
        [$art, $id] = array_pad(explode(':', $bezug, 2), 2, '');

        return match ($art) {
            self::BEZUG_AUFGABEN => $this->erinnereAufgaben($teamId, $personId, $notiz),
            'nachweis'           => $this->erinnereNachweis($teamId, (int) $id, $notiz),
            'dokument'           => $this->erinnereDokument($teamId, (int) $id, $notiz),
            'vertrag'            => $this->erinnereVertrag($teamId, (int) $id, $notiz),
            default              => HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN),
        };
    }

    private function erinnereAufgaben(int $teamId, ?int $personId, string $notiz): HrAktionErgebnis
    {
        if (($p = self::lebendePerson($teamId, $personId)) instanceof HrAktionErgebnis) {
            return $p;
        }

        // Dieselbe Laufsperre wie die Einsatz-Pruefung: nie zwei Nachrichten
        // im selben Augenblick. ERST die Sperre, DANN Stempel und Pause lesen
        // (Fix-Runde 1, Important 1): wer vorher liest, verpasst den Stempel,
        // den der Stundenlauf waehrenddessen setzt, und schickt doppelt.
        $sperre = Cache::lock(EinsatzPruefung::LAUF_SPERRE, self::SPERRE_SEKUNDEN);
        if (!$sperre->get()) {
            return HrAktionErgebnis::hinweis('Gerade läuft die Einsatz-Prüfung — bitte in ein paar Minuten erneut versuchen.');
        }

        try {
            $person = self::lebendePerson($teamId, $personId);
            if ($person instanceof HrAktionErgebnis) {
                return $person;
            }
            if (($pause = $this->inPause($person->aufgaben_gemeldet_at)) !== null) {
                return $pause;
            }
            $umfang = DB::table('rec_employees')->where('rec_person_id', $personId)->where('team_id', $teamId)
                ->where('is_active', 1)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $bote = Bote::fuer($umfang);
            if ($bote === null) {
                return HrAktionErgebnis::hinweis('Nicht verschickt: nicht im neuen Portal — die Erinnerung führt ins neue Portal.');
            }

            $stand = app(OffenePunkte::class)->fuer($bote);
            if ($stand['punkte'] === []) {
                return HrAktionErgebnis::hinweis('Inzwischen ist nichts mehr offen — keine Erinnerung nötig.');
            }
            $sender = app(AufgabenSender::class);
            // Kundenentscheidung Markus 12.10.2026: es gibt nur noch die
            // Aufgaben-Vorlage 'neu' (eine Info, offen im Portal) — die
            // eigene Erinnerungs-Vorlage ist entfallen. HR „Erneut erinnern"
            // schickt dieselbe Nachricht noch einmal.
            $status = (string) $sender->sende($bote, $stand, 'neu');

            // F1 der Einsatz-Pruefung: ohne brauchbare Vorlage kein Stempel.
            //
            // Review K3 (15.10.): gestempelt wird NUR bei STATUS_SENT — alle
            // vier Spalten. Vorher schrieb ein Fehlschlag Stempel und
            // Nachricht-ID (Signatur/Codes nicht): die Einsatz-Pruefung las
            // dann "letzte Nachricht gescheitert" (ET-23), raeumte Signatur
            // UND Codes der frueheren, zugestellten Meldung ab und schickte
            // nach der Pause dieselbe Punktliste noch einmal — gegen die
            // Zusage "nur bei neuem Punkt". Bei Fehlschlag bleibt jetzt der
            // vorige (erfolgreiche) Stand stehen; HR sieht die Meldung des
            // Fehlschlags wie bisher (versandErgebnis + Vermerk). ET-23 in
            // der Einsatz-Pruefung selbst bleibt unveraendert.
            if ($status === AufgabenSender::STATUS_SENT) {
                DB::table('rec_persons')->where('id', $personId)->update([
                    'aufgaben_gemeldet_at'  => now(),
                    'aufgaben_nachricht_id' => $sender->letzteNachrichtId(),
                    'aufgaben_signatur'     => TriggerRegeln::signatur($stand['punkte']),
                    // Nachtrag 13.10.2026: auch die gemeldeten CODES — sonst
                    // stuenden an der Person noch die Codes der vorigen
                    // Meldung, und die Einsatz-Pruefung hielte einen hier
                    // gerade gemeldeten Punkt fuer neu und meldete doppelt.
                    'aufgaben_codes'        => TriggerRegeln::codesFuerSpalte($stand['punkte']),
                ]);
            }
            $this->vermerk($teamId, $personId, null, 'erneut_erinnert', $notiz, self::BEZUG_AUFGABEN, $status);

            return $this->versandErgebnis($status);
        } finally {
            $sperre->release();
        }
    }

    private function erinnereNachweis(int $teamId, int $proofId, string $notiz): HrAktionErgebnis
    {
        $lies = fn () => DB::table('rec_employee_proofs as p')
            ->join('rec_employees as e', 'e.id', '=', 'p.rec_employee_id')
            ->where('p.id', $proofId)
            ->where('e.team_id', $teamId)
            ->whereNull('p.superseded_at')
            ->first(['p.id', 'p.rec_employee_id', 'p.proof_type_code', 'p.valid_until', 'p.reminded_at', 'e.portal_v2_since']);
        if ($lies() === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }

        // Dieselbe Sperre wie recruiting:nachweise-erinnern: kein Versand
        // parallel zum Lauf; reminded_at erst unter der Sperre lesen.
        return $this->unterSperre(SendProofReminders::LOCK_KEY, 'Gerade läuft die Nachweis-Erinnerung — bitte in ein paar Minuten erneut versuchen.', function () use ($lies, $teamId, $proofId, $notiz) {
            $zeile = $lies();
            if ($zeile === null) {
                return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
            }
            if ($zeile->portal_v2_since === null) {
                return HrAktionErgebnis::hinweis('Nicht verschickt: nicht im neuen Portal — Nachweis-Erinnerungen gehen nur dorthin.');
            }
            if (($pause = $this->inPause($zeile->reminded_at)) !== null) {
                return $pause;
            }

            $ma = RecEmployee::find((int) $zeile->rec_employee_id);
            $r = app(ProofReminderSender::class)->send($ma, [
                'proof_id'        => (int) $zeile->id,
                'rec_employee_id' => (int) $zeile->rec_employee_id,
                'code'            => (string) $zeile->proof_type_code,
                'valid_until'     => substr((string) $zeile->valid_until, 0, 10),
            ]);
            $status = (string) $r['status'];
            if ($status === ProofReminderSender::STATUS_SENT) {
                DB::table('rec_employee_proofs')->where('id', $proofId)->update(['reminded_at' => now()]);
            }
            $this->vermerk($teamId, self::personDerAkteImTeam($teamId, $ma), (int) $ma->id, 'erneut_erinnert', $notiz, 'nachweis:' . $proofId, $status);

            return $this->versandErgebnis($status);
        });
    }

    private function erinnereDokument(int $teamId, int $recipientId, string $notiz): HrAktionErgebnis
    {
        $lies = fn () => RecDocumentRecipient::query()
            ->whereKey($recipientId)
            ->whereIn('rec_employee_id', fn ($q) => $q->select('id')->from('rec_employees')->where('team_id', $teamId))
            ->first();
        if ($lies() === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        $bezug = 'dokument:' . $recipientId;

        // notified_at stempelt der Sender erst NACH dem Versand — darum Sperre
        // je Bezug und unter ihr neu lesen; ein paralleler Klick weicht.
        return $this->unterSperre(self::erinnernSperre($bezug), self::GERADE_IN_ARBEIT, function () use ($lies, $teamId, $bezug, $notiz) {
            $z = $lies();
            if ($z === null) {
                return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
            }
            if (($pause = $this->inPause($z->notified_at?->format('Y-m-d H:i:s'))) !== null) {
                return $pause;
            }
            $status = (string) app(DokumentService::class)->erneutSenden($z);
            $akte = RecEmployee::find((int) $z->rec_employee_id);
            $this->vermerk($teamId, self::personDerAkteImTeam($teamId, $akte), (int) $akte->id, 'erneut_erinnert', $notiz, $bezug, $status);

            return $this->versandErgebnis($status);
        });
    }

    private function erinnereVertrag(int $teamId, int $contractId, string $notiz): HrAktionErgebnis
    {
        $lies = fn () => RecContract::query()
            ->whereKey($contractId)
            ->whereIn('rec_employee_id', fn ($q) => $q->select('id')->from('rec_employees')->where('team_id', $teamId))
            ->first();
        $v = $lies();
        if ($v === null || $v->status !== 'sent' || !VertragsHerkunft::ausAkte($v)) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        $bezug = VertragErinnerungsPause::bezug($contractId);

        return $this->unterSperre(self::erinnernSperre($bezug), self::GERADE_IN_ARBEIT, function () use ($lies, $teamId, $contractId, $bezug, $notiz) {
            $v = $lies();
            if ($v === null || $v->status !== 'sent') {
                return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
            }
            // Die Pausen-Regel steht an EINER Stelle (Ruling R3) — dieselbe fragen die Listen.
            $stempel = VertragErinnerungsPause::stempel(
                $v->sent_at,
                VertragErinnerungsPause::letzteErinnerungen($teamId, [$contractId])[$contractId] ?? null,
            );
            if (($pause = $this->inPause($stempel)) !== null) {
                return $pause;
            }
            $anstellung = RecEmployee::find((int) $v->rec_employee_id);
            $status = (string) app(VertragHinweisSender::class)->sende($anstellung);
            $this->vermerk($teamId, self::personDerAkteImTeam($teamId, $anstellung), (int) $anstellung->id, 'erneut_erinnert', $notiz, $bezug, $status);

            return $this->versandErgebnis($status);
        });
    }

    // ------------------------------------------------------------ intern

    private const GERADE_IN_ARBEIT = 'Diese Erinnerung wird gerade schon verschickt — bitte gleich die Liste neu ansehen.';

    /**
     * Person im Team UND nicht stillgelegt — sonst das passende Ergebnis
     * ("Nicht gefunden." bzw. STILLGELEGT).
     */
    private static function lebendePerson(int $teamId, ?int $personId): object
    {
        $person = self::personImTeam($teamId, $personId);
        if ($person === null) {
            return HrAktionErgebnis::fehler(self::NICHT_GEFUNDEN);
        }
        if ($person->merged_into_person_id !== null) {
            return HrAktionErgebnis::fehler(self::STILLGELEGT);
        }

        return $person;
    }

    /** Fuehrt $tu unter einer Cache-Sperre aus; ist sie belegt, gelber Hinweis statt Warten. */
    private function unterSperre(string $schluessel, string $besetzt, callable $tu): HrAktionErgebnis
    {
        $sperre = Cache::lock($schluessel, self::SPERRE_SEKUNDEN);
        if (!$sperre->get()) {
            return HrAktionErgebnis::hinweis($besetzt);
        }
        try {
            return $tu();
        } finally {
            $sperre->release();
        }
    }

    private function inPause(?string $stempel): ?HrAktionErgebnis
    {
        $bis = self::pauseBis($stempel);
        if ($bis !== null && now()->lt($bis)) {
            return HrAktionErgebnis::hinweis(sprintf(
                'Erneut erinnern geht erst ab %s (%d Tage nach der letzten Erinnerung).',
                $bis->format('d.m.Y'),
                EinsatzBezug::PAUSE_TAGE,
            ));
        }

        return null;
    }

    private function versandErgebnis(string $status): HrAktionErgebnis
    {
        return $status === 'sent'
            ? HrAktionErgebnis::ok('Erinnerung verschickt.')
            : HrAktionErgebnis::hinweis('Nicht verschickt: ' . self::statusText($status) . '.');
    }

    private function vermerk(int $teamId, ?int $personId, ?int $employeeId, string $art, string $notiz, ?string $bezug, ?string $ergebnis, ?\DateTimeInterface $ruheBis = null): void
    {
        $userId = Auth::id();
        RecHrVermerk::anfuegen($teamId, $personId, $employeeId, $art, trim($notiz) === '' ? null : $notiz, $bezug, $ergebnis, $ruheBis, $userId !== null ? (int) $userId : null);

        Log::notice('recruiting.hr.konto_aktion', [
            'team_id'     => $teamId,
            'person_id'   => $personId,
            'employee_id' => $employeeId,
            'art'         => $art,
            'user_id'     => $userId,
            'ergebnis'    => $ergebnis,
        ]);
    }

    /** Team NUR aus der Anmeldung (nie aus dem Request). Ohne Team: Abbruch statt Raten. */
    private function team(): int
    {
        $teamId = Auth::user()?->currentTeam?->id;
        if ($teamId === null) {
            throw new \RuntimeException('HR-Aktion ohne angemeldeten Benutzer mit aktuellem Team.');
        }

        return (int) $teamId;
    }

    private function portalAuth(): PortalAuth
    {
        return $this->auth ?? new PortalAuth();
    }
}
