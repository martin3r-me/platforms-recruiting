<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * ANSCHLUSS-NACHWEIS fuer Tabelle 2 (Schulungstermine mit Herkunft): laufen
 * Termin-Query und Kohorten-Zeilen zu EINER Tabellenzeile zusammen, ohne dass
 * dabei zwei Zaehlungen derselben Menschen entstehen?
 *
 * Warum das nicht als Unit-Test geht: die Zeile eines Termins mischt zwei
 * Quellen, und genau diese Trennung ist der Punkt —
 *  - die BELEGUNG (IST/SOLL) kommt aus der Termin-Query (Plaetze sind eine
 *    Eigenschaft des Termins),
 *  - die TRICHTER-Zahlen kommen aus den Assigner-Zeilen (dieselben Zeilen wie
 *    Tabelle 1, keine zweite Query).
 * Ob die Zusammenfuehrung stimmt, zeigt sich nur, wenn Query und Assigner
 * gemeinsam laufen.
 *
 * Aufbau wie StatisticsCohortWiringTest (Container + Capsule von Hand, ECHTE
 * Migrationen per glob, auth() als Attrappe, feste Uhr). Datensaetze per Query
 * Builder mit expliziter UUID, weil der Event-Dispatcher abgeschaltet ist.
 */
class StatisticsInterviewsTableTest extends TestCase
{
    private const TEAM = 4;

    /** Ausschreibungen der Filiale Essen (Stelle 1). */
    private const POSTING_SERVICE = 20;
    private const POSTING_BANKETT = 22;

    /** Ausschreibung der Filiale Wuppertal (Stelle 2). */
    private const POSTING_KUECHE = 21;

    /** Termin in Essen — Veranstaltungsort ist bewusst NICHT „Essen". */
    private const INTERVIEW_AUGUST = 300;

    /** Zweiter Termin in Essen, im Juli (Zeitraum-Filter). */
    private const INTERVIEW_JULI = 301;

    /** Termin der Filiale Wuppertal. */
    private const INTERVIEW_WUPPERTAL = 302;

    /** Inaktiver Termin (HR hat ihn stillgelegt) — nie in der Tabelle. */
    private const INTERVIEW_INAKTIV = 303;

    /**
     * AKTIVER Termin IM Zeitraum, aber ohne Stelle (rec_position_id ist nullable).
     * Er faellt durch den Ort-Filter, obwohl sein Teilnehmer zur Filiale Essen
     * gehoert — der dritte Grund, aus dem eine Zeile in Tabelle 2 fehlt.
     */
    private const INTERVIEW_OHNE_STELLE = 304;

    private const HEUTE = '2026-08-17 10:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            /** Urheber des Klaerungs-Hakens (einsatz_geklaert_by). */
            public function id(): int
            {
                return self::USER;
            }

            public function shouldUse($name)
            {
                // nicht benutzt: die Komponente ruft auth()->user() und auth()->id()
            }

            public const USER = 77;
        });

        // CrmContact zieht beim Hydrieren config() — minimaler leerer
        // Config-Store reicht (dieselbe Sorte Attrappe wie auth()).
        $container->instance('config', new \Illuminate\Config\Repository([]));

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Carbon::setTestNow();
    }

    /**
     * Die Komponente unter Test — als Probe-Unterklasse, weil
     * buildInterviewTable() protected ist: jede public Methode einer
     * Livewire-Komponente ist vom Client als Action aufrufbar, und diese erwartet
     * Eloquent-Objekte. Der Test kommt hier heran, die Produktions-Oberflaeche
     * bleibt zu. Die Probe ruft dieselben Methoden wie die Computed-Huelle
     * interviewTable() — nur eben als Methoden, weil Computed Properties den
     * Livewire-Lifecycle brauchen.
     */
    private function component(?string $ort = null, ?string $from = null, ?string $to = null): InterviewTableProbe
    {
        $component = new InterviewTableProbe();
        $component->ortFilter = $ort;
        $component->interviewFrom = $from;
        $component->interviewTo = $to;

        return $component;
    }

    /** @return array{rows:list<array>, outside:array{interviews:int, applications:int}} */
    private function tableOf(InterviewTableProbe $component): array
    {
        return $component->probeInterviewTable();
    }

    /** @return list<int> */
    private function interviewIds(InterviewTableProbe $component): array
    {
        return array_map('intval', $component->interviews()->pluck('id')->all());
    }

    public function test_termine_folgen_dem_ort_der_stelle_nicht_dem_veranstaltungsort(): void
    {
        // Tabelle 1 zeigt EINE Filiale — Tabelle 2 muss dieselbe zeigen, sonst
        // steht genau das Nebeneinander auf der Seite, das der Kunde reklamiert
        // hat. Eingegrenzt wird ueber die STELLE des Termins.
        $this->assertSame(
            [self::INTERVIEW_AUGUST, self::INTERVIEW_JULI],
            $this->interviewIds($this->component('Essen')),
            'nur Termine an Stellen der Filiale Essen, neueste zuerst',
        );

        // rec_interviews.location ist freier Text und der VERANSTALTUNGSORT: der
        // August-Termin findet am „Bahnhof Duisburg" statt und gehoert trotzdem
        // zur Filiale Essen. Ein Filter auf diese Spalte haette ihn verschluckt.
        $august = $this->component('Essen')->interviews()->firstWhere('id', self::INTERVIEW_AUGUST);
        $this->assertSame('Bahnhof Duisburg, Gleis 3', $august->location);

        // Ohne Ort-Filter keine Einschraenkung (Task 10 macht den Ort zur Pflicht):
        // dann ist auch der Termin OHNE Stelle dabei, den der Ort-Filter sonst
        // wegnimmt.
        $this->assertSame(
            [self::INTERVIEW_WUPPERTAL, self::INTERVIEW_OHNE_STELLE, self::INTERVIEW_AUGUST, self::INTERVIEW_JULI],
            $this->interviewIds($this->component()),
            'ohne Ort alle Filialen, weiter chronologisch absteigend',
        );
    }

    public function test_inaktiver_termin_taucht_nicht_auf(): void
    {
        // Termine haben kein is_test-Flag; inaktiv ist der einzige Weg, mit dem HR
        // einen Test-Termin aus der Statistik nimmt.
        $this->assertNotContains(self::INTERVIEW_INAKTIV, $this->interviewIds($this->component()));
    }

    public function test_geleertes_datumsfeld_schneidet_nichts_weg(): void
    {
        // Zwei Dinge, und sie sind zu trennen:
        //  - der ZUSTAND: ein geleertes x-ui-input-date liefert '', die Property
        //    haelt danach null. Nur zwei Zustaende (gesetzt / nicht gesetzt) —
        //    daran haengt jeder spaetere Leser, der auf `!== null` prueft, und DORT
        //    waere '' gefaehrlich, weil '2026-07-05' >= '' wahr ist;
        //  - die MENGE: sie bleibt unbeschnitten. Das gilt hier auch ohne den Hook,
        //    weil `when('')` die Klausel ueberspringt — der Hook repariert also
        //    keinen Filter, sondern haelt den Zustand sauber. Beides wird gepruft,
        //    damit die Zusicherung nicht an der Interna von when() haengt.
        $alle = $this->interviewIds($this->component('Essen'));

        $component = $this->component('Essen');
        $component->updatedInterviewFrom('');
        $component->updatedInterviewTo('');

        $this->assertNull($component->interviewFrom);
        $this->assertNull($component->interviewTo);
        $this->assertSame($alle, $this->interviewIds($component), 'geleerte Felder schneiden nichts weg');

        // Zum Gegenbeweis, dass der Filter ueberhaupt greift: gesetzte Werte
        // schneiden, und der Bis-Tag ist INKLUSIV (23:59:59 am Termin-Tag)
        $component->updatedInterviewFrom('2026-08-01');
        $this->assertSame([self::INTERVIEW_AUGUST], $this->interviewIds($component));

        $bis = $this->component('Essen');
        $bis->updatedInterviewTo('2026-07-05');
        $this->assertSame([self::INTERVIEW_JULI], $this->interviewIds($bis),
            'der Juli-Termin um 09:00 liegt im Bis-Tag');
    }

    public function test_belegung_kommt_vom_termin_der_trichter_aus_den_assigner_zeilen(): void
    {
        $component = $this->component('Essen');
        $table = $this->tableOf($component);

        $august = $this->rowOf($table, self::INTERVIEW_AUGUST);

        // Belegung: Eigenschaft des TERMINS (zentrale Zaehlregel seatTaking),
        // unabhaengig von der Kohorte. Vier Buchungen, davon eine mit
        // freigegebenem Platz (Standby) -> drei belegte Plaetze von acht.
        $this->assertSame(3, $august['seat_taking'], 'IST aus der Termin-Query');
        $this->assertSame(8, $august['max'], 'SOLL aus max_participants');

        // Trichter: aus den Assigner-Zeilen, also derselben Quelle wie Tabelle 1 —
        // ALLE Teilnehmer des Termins, auch der aus der Wuppertaler Anzeige (208)
        $this->assertSame(4, $component->countIn($august['rows'], 'ids'), 'vier Teilnehmer am Termin');
        $this->assertSame(1, $component->countIn($august['rows'], 'standby'));

        // Anzeige-Spalten des Termins
        $this->assertSame('Schulung', $august['type']);
        $this->assertSame('Bahnhof Duisburg, Gleis 3', $august['location']);
        $this->assertSame('Kellner (m/w/d)', $august['posting_title'], 'Ausschreibung des Termins');
        $this->assertTrue($august['has_posting']);

        // Termin OHNE Ausschreibung: Rueckfall auf den Titel des Termins
        $juli = $this->rowOf($table, self::INTERVIEW_JULI);
        $this->assertSame('Nachschulung Juli', $juli['posting_title']);
        $this->assertFalse($juli['has_posting']);
    }

    public function test_schulungs_detailansicht_liefert_kopf_kennzahlen_und_arbeitsliste(): void
    {
        // Die Detailansicht je Termin (User-Idee 09.09.2026): die Tabelle bleibt
        // schlank (eine Quote-Spalte), die Tiefe — Schulungsleiter, Toepfe,
        // Personenliste mit Einsatzdaten — liegt einen Klick dahinter.
        $component = $this->component('Essen');
        $cohort = $component->cohort();
        $detail = $component->terminDetailFor(self::INTERVIEW_AUGUST, $cohort['termin_rows'], $cohort['einsatz_info']);

        $this->assertSame('Bahnhof Duisburg, Gleis 3', $detail['interview']->location);
        $this->assertSame(['Ben Trainer', 'Clara Setzkorn'], $detail['leiter'], 'Schulungsleiter, alphabetisch');

        $this->assertSame(4, $detail['kennzahlen']['ids']);
        $this->assertSame(2, $detail['kennzahlen']['teilgenommen']);
        $this->assertSame(1, $detail['kennzahlen']['im_einsatz']);
        $this->assertSame(1, $detail['kennzahlen']['ohne_einsatz']);

        // Arbeitsliste: „Ohne Einsatz" zuerst (das ist die Nachverfolgungs-
        // Liste), dann nicht pruefbar, dann im Einsatz, dann der Rest.
        $ids = array_column($detail['personen'], 'id');
        $this->assertSame(208, $ids[0], 'ohne Einsatz zuoberst');
        $person208 = $detail['personen'][0];
        $this->assertSame('Bewerber #208', $person208['name'], 'ohne CRM-Kontakt der ehrliche Rueckfall');
        $this->assertSame('ohne_einsatz', $person208['topf']);
        $this->assertSame('Teilgenommen', $person208['status']);
        $this->assertSame(0, $person208['einsaetze']);
        $this->assertTrue($person208['hat_pnr']);

        $person204 = collect($detail['personen'])->firstWhere('id', 204);
        $this->assertSame('Anders, Zoe', $person204['name'], 'Name aus dem echten CRM-Link — der 500er-Pfad vom 09.09.');
        $this->assertSame('im_einsatz', $person204['topf']);
        $this->assertSame(3, $person204['einsaetze']);
        $this->assertSame('2026-09-10', $person204['erster_einsatz']);

        // Nicht-Teilgenommene stehen mit ihrem Status dabei (Vollstaendigkeit),
        // aber ohne Einsatz-Topf
        $person201 = collect($detail['personen'])->firstWhere('id', 201);
        $this->assertNull($person201['topf']);

        // Fremder/unbekannter Termin: fail-closed
        $this->assertNull($component->terminDetailFor(999999, $cohort['termin_rows'], $cohort['einsatz_info']));
    }

    public function test_sammelversand_adressiert_nur_den_topf_ohne_einsatz(): void
    {
        // Der Sammelversand „ohne Einsatz" (Clara, 14.09.2026) haengt an
        // GENAU der Liste, die das Modal unter diesem Chip zeigt — und an
        // keiner anderen. Beides wird hier gegen echte Daten geprueft: die
        // Ableitung der Empfaenger und der Chip als Schranke davor.
        $component = $this->component('Essen');
        $component->showTerminDetail = true;
        $component->terminDetailId = self::INTERVIEW_AUGUST;

        $component->terminDetailFilter = 'ohne_einsatz';
        $this->assertSame([208], $component->noAssignmentIds(), 'nur der pruefbare Teilnehmer ohne Zuweisung');
        $this->assertTrue($component->noAssignmentEnabled());

        // Jeder andere Chip schaltet den Versand ab — auch „alle Buchungen",
        // hinter dem Nicht-Erschienene und Abgesagte stehen.
        foreach (['teilgenommen', 'im_einsatz', 'einsatz_unpruefbar', 'alle'] as $chip) {
            $component->terminDetailFilter = $chip;
            $this->assertSame([], $component->noAssignmentIds(), "Chip {$chip} darf keinen Empfaenger liefern");
            $this->assertFalse($component->noAssignmentEnabled());
        }

        // Geschlossenes Modal: keine Empfaenger, egal was die Filter sagen.
        $component->terminDetailFilter = 'ohne_einsatz';
        $component->showTerminDetail = false;
        $this->assertSame([], $component->noAssignmentIds());
    }

    /**
     * Klaerung (Kundenwunsch 21.09.2026): Bewerber 208 hat teilgenommen, traegt
     * eine ZAS-Personalnummer und hat keine Zuweisung — er steht also in der
     * Arbeitsliste „ohne Einsatz", obwohl nichts im Argen ist („faengt erst
     * naechsten Monat an"). Ein Haken an der BUCHUNG nimmt ihn heraus, ein
     * Wiedervorlage-Datum bringt ihn zurueck.
     *
     * Die ganze Kette in einem Test, weil genau sie still brechen kann: Spalte →
     * Query → Flag am Booking-Array → Assigner-Topf → Modal → Sammelversand.
     */
    public function test_geklaerter_fall_verlaesst_die_arbeitsliste_bis_zur_wiedervorlage(): void
    {
        $vm = new CohortViewModel();

        try {
            // (1) Haken ohne Wiedervorlage: dauerhaft geklaert
            Capsule::table('rec_interview_bookings')->where('id', 408)->update([
                'einsatz_geklaert_at' => '2026-08-16 09:00:00',
                'einsatz_geklaert_note' => 'Faengt erst im Oktober an, mit der Dispo geklaert.',
                'einsatz_wiedervorlage_am' => null,
                'einsatz_geklaert_by' => 77,
            ]);

            $component = $this->component('Essen');
            $august = $this->rowOf($this->tableOf($component), self::INTERVIEW_AUGUST);
            $this->assertSame([208], $vm->resolveIds($august['rows'], ['scope' => 'all'], 'geklaert'));
            $this->assertSame([], $vm->resolveIds($august['rows'], ['scope' => 'all'], 'ohne_einsatz'),
                'der Geklaerte ist aus der Arbeitsliste raus');
            $this->assertSame([204], $vm->resolveIds($august['rows'], ['scope' => 'all'], 'im_einsatz'),
                'am Einsatz-Topf aendert der Haken nichts');
            $this->assertCount(2, $vm->resolveIds($august['rows'], ['scope' => 'all'], 'teilgenommen'),
                'der Nenner der Quote bleibt unberuehrt');

            // (2) Das Modal zeigt Haken, Notiz und die Buchung, an der er haengt
            $cohort = $component->cohort();
            $detail = $component->terminDetailFor(self::INTERVIEW_AUGUST, $cohort['termin_rows'], $cohort['einsatz_info']);
            $this->assertSame(1, $detail['kennzahlen']['geklaert']);
            $this->assertSame(0, $detail['kennzahlen']['ohne_einsatz']);

            $person208 = collect($detail['personen'])->firstWhere('id', 208);
            $this->assertSame('geklaert', $person208['topf']);
            $this->assertSame(408, $person208['booking_id'], 'ohne Buchung waere der Haken nicht setzbar');
            $this->assertSame('Faengt erst im Oktober an, mit der Dispo geklaert.', $person208['geklaert_note']);
            $this->assertNull($person208['geklaert_wiedervorlage'], 'ohne Datum gilt der Haken dauerhaft');

            // (3) Der Sammelversand sieht ihn nicht mehr — sonst haekelt man ab
            // und die Nachfass-Nachricht geht trotzdem raus.
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;
            $component->terminDetailFilter = 'ohne_einsatz';
            $this->assertSame([], $component->noAssignmentIds());
            $this->assertFalse($component->noAssignmentEnabled());

            // (4) Wiedervorlage HEUTE: der Fall ist wieder offen
            Capsule::table('rec_interview_bookings')->where('id', 408)
                ->update(['einsatz_wiedervorlage_am' => '2026-08-17']);
            $nachWiedervorlage = $this->component('Essen');
            $zeile = $this->rowOf($this->tableOf($nachWiedervorlage), self::INTERVIEW_AUGUST);
            $this->assertSame([208], $vm->resolveIds($zeile['rows'], ['scope' => 'all'], 'ohne_einsatz'),
                'am Wiedervorlage-Tag steht er wieder auf der Liste');
            $this->assertSame([], $vm->resolveIds($zeile['rows'], ['scope' => 'all'], 'geklaert'));

            // (5) Wiedervorlage MORGEN: heute noch geklaert
            Capsule::table('rec_interview_bookings')->where('id', 408)
                ->update(['einsatz_wiedervorlage_am' => '2026-08-18']);
            $zeileMorgen = $this->rowOf($this->tableOf($this->component('Essen')), self::INTERVIEW_AUGUST);
            $this->assertSame([208], $vm->resolveIds($zeileMorgen['rows'], ['scope' => 'all'], 'geklaert'));
        } finally {
            // Der Bestand ist klassenweit — was dieser Test setzt, muss er auch
            // wieder abraeumen, sonst faerbt er die Nachbartests ein.
            $this->raeumeKlaerungAb();
        }
    }

    /**
     * Die Schreibseite des Hakens. Sie ist die CLIENT-GRENZE: die booking_id
     * kommt aus dem Browser, also wird sie gegen die Personenliste des
     * GEOEFFNETEN Termins aufgeloest — was dort nicht steht, existiert fuer
     * diese Aktion nicht.
     */
    public function test_haken_setzen_speichert_notiz_datum_und_urheber(): void
    {
        try {
            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            $component->openKlaerung(408);
            $component->klaerungNote = 'Faengt erst im Oktober an.';
            $component->klaerungWiedervorlage = '2026-10-01';
            $component->saveKlaerung();

            $this->assertSame('', $component->klaerungError);
            $this->assertNull($component->klaerungBookingId, 'nach dem Speichern ist das Fenster zu');

            $booking = Capsule::table('rec_interview_bookings')->where('id', 408)->first();
            $this->assertNotNull($booking->einsatz_geklaert_at);
            $this->assertSame('Faengt erst im Oktober an.', $booking->einsatz_geklaert_note);
            $this->assertStringStartsWith('2026-10-01', (string) $booking->einsatz_wiedervorlage_am);
            $this->assertSame(77, (int) $booking->einsatz_geklaert_by);
        } finally {
            $this->raeumeKlaerungAb();
        }
    }

    /**
     * Die Begruendung ist OPTIONAL (Kundenwunsch 21.09.2026, gegen meinen
     * urspruenglichen Vorschlag): manchmal will HR nur schnell abhaken. Der
     * Haken wirkt dann genauso — in der Akte steht danach eben nur, WER
     * abgehakt hat, nicht warum.
     */
    public function test_haken_ohne_notiz_ist_erlaubt(): void
    {
        try {
            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            $component->openKlaerung(408);
            $component->klaerungNote = '   ';
            $component->saveKlaerung();

            $this->assertSame('', $component->klaerungError);
            $this->assertNull($component->klaerungBookingId, 'das Fenster ist zu');

            $booking = Capsule::table('rec_interview_bookings')->where('id', 408)->first();
            $this->assertNotNull($booking->einsatz_geklaert_at, 'der Haken sitzt');
            $this->assertNull($booking->einsatz_geklaert_note, 'nur Leerzeichen sind keine Notiz');

            // Der Akten-Eintrag sagt ehrlich, dass keine Begruendung da ist —
            // eine leere Anfuehrungszeichen-Huelse waere schlechter als der Satz.
            $log = Capsule::table('rec_auto_pilot_logs')
                ->where('rec_applicant_id', 208)->where('type', 'einsatz_klaerung_gesetzt')->first();
            $this->assertNotNull($log);
            $this->assertStringContainsString('ohne Begründung', $log->summary);
        } finally {
            $this->raeumeKlaerungAb();
        }
    }

    /**
     * Ein Haken OHNE Notiz darf genauso wenig unsichtbar liegen bleiben wie
     * einer mit (Review-Befund vom 21.09.). Die Ansicht haengte den Fall
     * vorher an der Notiz auf — ohne sie waere er wieder verschwunden.
     */
    public function test_haken_ohne_notiz_bleibt_an_einer_einsatz_zeile_sichtbar(): void
    {
        try {
            Capsule::table('rec_interview_bookings')->where('id', 403)->update([
                'einsatz_geklaert_at' => '2026-08-01 09:00:00',
                'einsatz_geklaert_note' => null,
            ]);

            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            $cohort = $component->cohort();
            $detail = $component->terminDetailFor(self::INTERVIEW_AUGUST, $cohort['termin_rows'], $cohort['einsatz_info']);
            $person204 = collect($detail['personen'])->firstWhere('id', 204);

            $this->assertSame('im_einsatz', $person204['topf']);
            $this->assertTrue($person204['hat_klaerung'], 'der Haken existiert, auch ohne Begruendung');
            $this->assertNull($person204['geklaert_note']);

            $component->removeKlaerung(403);
            $this->assertNull(Capsule::table('rec_interview_bookings')->where('id', 403)->value('einsatz_geklaert_at'));
        } finally {
            Capsule::table('rec_interview_bookings')->where('id', 403)->update([
                'einsatz_geklaert_at' => null, 'einsatz_geklaert_note' => null,
                'einsatz_wiedervorlage_am' => null, 'einsatz_geklaert_by' => null,
            ]);
            Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 204)->delete();
        }
    }

    public function test_wiedervorlage_muss_in_der_zukunft_liegen(): void
    {
        try {
            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;
            $component->openKlaerung(408);
            $component->klaerungNote = 'Kommt spaeter.';

            // HEUTE ist der 17.08.2026 — ein Datum von heute oder frueher waere
            // im selben Atemzug abgelaufen und der Haken damit sinnlos.
            foreach (['2026-08-17', '2026-07-01', '2026-02-30', 'bald'] as $unbrauchbar) {
                $component->klaerungWiedervorlage = $unbrauchbar;
                $component->saveKlaerung();
                $this->assertNotSame('', $component->klaerungError, "Datum {$unbrauchbar} darf nicht durchgehen");
                $this->assertNull(
                    Capsule::table('rec_interview_bookings')->where('id', 408)->value('einsatz_geklaert_at'),
                    "Datum {$unbrauchbar} darf nichts schreiben",
                );
            }
        } finally {
            $this->raeumeKlaerungAb();
        }
    }

    public function test_fremde_buchung_kann_nicht_abgehakt_werden(): void
    {
        try {
            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            // 404 gehoert zum JULI-Termin, 999 existiert nicht.
            foreach ([404, 999] as $fremd) {
                $component->openKlaerung($fremd);
                $this->assertNull($component->klaerungBookingId, "Buchung {$fremd} gehoert nicht in dieses Modal");

                // Auch der direkte Weg (gecraftetes $set) darf nichts schreiben.
                $component->klaerungBookingId = $fremd;
                $component->klaerungNote = 'egal';
                $component->klaerungWiedervorlage = '';
                $component->saveKlaerung();
                $this->assertNotSame('', $component->klaerungError);
                $component->removeKlaerung($fremd);
            }

            $this->assertNull(Capsule::table('rec_interview_bookings')->where('id', 404)->value('einsatz_geklaert_at'));
        } finally {
            $this->raeumeKlaerungAb();
            Capsule::table('rec_interview_bookings')->where('id', 404)->update([
                'einsatz_geklaert_at' => null, 'einsatz_geklaert_note' => null,
                'einsatz_wiedervorlage_am' => null, 'einsatz_geklaert_by' => null,
            ]);
        }
    }

    public function test_haken_entfernen_raeumt_die_klaerung_ab(): void
    {
        try {
            Capsule::table('rec_interview_bookings')->where('id', 408)->update([
                'einsatz_geklaert_at' => '2026-08-16 09:00:00',
                'einsatz_geklaert_note' => 'Faengt spaeter an.',
                'einsatz_wiedervorlage_am' => '2026-10-01',
                'einsatz_geklaert_by' => 77,
            ]);

            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;
            $component->removeKlaerung(408);

            $booking = Capsule::table('rec_interview_bookings')->where('id', 408)->first();
            $this->assertNull($booking->einsatz_geklaert_at);
            $this->assertNull($booking->einsatz_geklaert_note, 'die Notiz gehoert zum Haken und geht mit ihm');
            $this->assertNull($booking->einsatz_wiedervorlage_am);
            $this->assertNull($booking->einsatz_geklaert_by);

            $cohort = $component->cohort();
            $detail = $component->terminDetailFor(self::INTERVIEW_AUGUST, $cohort['termin_rows'], $cohort['einsatz_info']);
            $this->assertSame(1, $detail['kennzahlen']['ohne_einsatz'], 'er steht wieder auf der Arbeitsliste');
            $this->assertSame(0, $detail['kennzahlen']['geklaert']);
        } finally {
            $this->raeumeKlaerungAb();
        }
    }

    /**
     * Review-Befund 21.09.2026: ein Haken, der liegen bleibt, weil inzwischen
     * ein Einsatz kam, darf nicht UNSICHTBAR liegen bleiben. Sonst versteckt er
     * die Person still wieder, sobald die Zuweisung eines Tages wegfaellt
     * (ZAS-Re-Import, Storno) — genau das „still verschwinden lassen", gegen
     * das die ganze Regel gebaut ist.
     */
    public function test_liegengebliebener_haken_an_einer_einsatz_zeile_bleibt_sichtbar_und_loesbar(): void
    {
        try {
            // 204 ist im Einsatz (drei Zuweisungen) und traegt trotzdem noch
            // einen alten, dauerhaften Haken an seiner Buchung 403.
            Capsule::table('rec_interview_bookings')->where('id', 403)->update([
                'einsatz_geklaert_at' => '2026-08-01 09:00:00',
                'einsatz_geklaert_note' => 'Wollte spaeter starten.',
            ]);

            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            $cohort = $component->cohort();
            $detail = $component->terminDetailFor(self::INTERVIEW_AUGUST, $cohort['termin_rows'], $cohort['einsatz_info']);
            $person204 = collect($detail['personen'])->firstWhere('id', 204);
            $this->assertSame('im_einsatz', $person204['topf'], 'der Einsatz gewinnt gegen den Haken');
            $this->assertSame('Wollte spaeter starten.', $person204['geklaert_note'], 'die Notiz bleibt sichtbar');

            // Neu SETZEN geht hier nicht — die Person steht nicht auf der
            // Arbeitsliste, der Haken haette nichts zu klaeren.
            $component->klaerungBookingId = 403;
            $component->klaerungNote = 'Unsinn';
            $component->klaerungWiedervorlage = '';
            $component->saveKlaerung();
            $this->assertNotSame('', $component->klaerungError);
            $this->assertSame('Wollte spaeter starten.',
                Capsule::table('rec_interview_bookings')->where('id', 403)->value('einsatz_geklaert_note'));

            // LOESEN geht sehr wohl — sonst bliebe er fuer immer liegen.
            $component->removeKlaerung(403);
            $this->assertNull(Capsule::table('rec_interview_bookings')->where('id', 403)->value('einsatz_geklaert_at'));
        } finally {
            Capsule::table('rec_interview_bookings')->where('id', 403)->update([
                'einsatz_geklaert_at' => null, 'einsatz_geklaert_note' => null,
                'einsatz_wiedervorlage_am' => null, 'einsatz_geklaert_by' => null,
            ]);
            Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 204)->delete();
        }
    }

    /**
     * Review-Befund: die Schreib-Methoden sind gewoehnliche Livewire-Methoden.
     * Dass die Ansicht den Knopf nur an der Arbeitsliste zeigt, ist keine
     * Schranke — ein $wire.call auf eine No-Show-Buchung darf nichts stempeln.
     */
    public function test_haken_nur_an_der_arbeitsliste_nicht_an_beliebigen_buchungen(): void
    {
        try {
            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            // Buchung 401 gehoert zu 201: bestaetigt, nicht teilgenommen, also
            // in keinem Einsatz-Topf.
            $component->openKlaerung(401);
            $this->assertNull($component->klaerungBookingId, 'kein Fenster fuer eine Zeile ohne Topf');

            $component->klaerungBookingId = 401;
            $component->klaerungNote = 'Unsinn';
            $component->klaerungWiedervorlage = '';
            $component->saveKlaerung();

            $this->assertNotSame('', $component->klaerungError);
            $this->assertNull(Capsule::table('rec_interview_bookings')->where('id', 401)->value('einsatz_geklaert_at'));
        } finally {
            Capsule::table('rec_interview_bookings')->where('id', 401)->update([
                'einsatz_geklaert_at' => null, 'einsatz_geklaert_note' => null,
                'einsatz_wiedervorlage_am' => null, 'einsatz_geklaert_by' => null,
            ]);
        }
    }

    /**
     * Review-Befund: ein abgelaufenes Datum darf nicht vorbelegt werden — die
     * Pruefung wuerde es im selben Atemzug ablehnen, und der Anwender muesste
     * erst ein Feld leeren, das das Formular selbst gefuellt hat.
     */
    public function test_abgelaufene_wiedervorlage_wird_nicht_vorbelegt(): void
    {
        try {
            Capsule::table('rec_interview_bookings')->where('id', 408)->update([
                'einsatz_geklaert_at' => '2026-08-01 09:00:00',
                'einsatz_geklaert_note' => 'Wollte Mitte August starten.',
                'einsatz_wiedervorlage_am' => '2026-08-15',
            ]);

            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;
            $component->openKlaerung(408);

            $this->assertSame(408, $component->klaerungBookingId);
            $this->assertSame('Wollte Mitte August starten.', $component->klaerungNote, 'der Grund hilft beim Neusetzen');
            $this->assertSame('', $component->klaerungWiedervorlage, 'das vergangene Datum nicht');
        } finally {
            $this->raeumeKlaerungAb();
        }
    }

    /**
     * Die Begruendung muss den Haken UEBERLEBEN. Das Feld an der Buchung ist
     * ein Zustand — es wird beim Bearbeiten ueberschrieben und beim Entfernen
     * geleert. Die Frage „warum wurde damals nicht nachgefasst?" stellt sich
     * aber Monate spaeter, an der PERSON. Deshalb zusaetzlich ein Eintrag im
     * Aktivitaeten-Protokoll der Bewerberakte, den niemand ueberschreiben kann.
     */
    public function test_klaerung_landet_als_eintrag_in_der_bewerberakte(): void
    {
        try {
            $component = $this->component('Essen');
            $component->showTerminDetail = true;
            $component->terminDetailId = self::INTERVIEW_AUGUST;

            $component->openKlaerung(408);
            $component->klaerungNote = 'Faengt erst im Oktober an.';
            $component->klaerungWiedervorlage = '2026-10-01';
            $component->saveKlaerung();

            $gesetzt = Capsule::table('rec_auto_pilot_logs')
                ->where('rec_applicant_id', 208)->where('type', 'einsatz_klaerung_gesetzt')->first();
            $this->assertNotNull($gesetzt, 'ohne Eintrag waere die Begruendung nach dem Entfernen weg');
            $this->assertStringContainsString('Faengt erst im Oktober an.', $gesetzt->summary);
            $this->assertStringContainsString('Nina Personal', $gesetzt->summary, 'wer es behauptet hat');
            $this->assertStringContainsString('01.10.2026', $gesetzt->summary, 'bis wann es gelten sollte');
            $details = json_decode((string) $gesetzt->details, true);
            $this->assertSame(408, $details['booking_id']);
            $this->assertSame(self::INTERVIEW_AUGUST, $details['interview_id']);
            $this->assertSame(77, $details['user_id']);

            // Das Entfernen ist genauso eine Aussage und bekommt seine Zeile.
            $component->removeKlaerung(408);
            $aufgehoben = Capsule::table('rec_auto_pilot_logs')
                ->where('rec_applicant_id', 208)->where('type', 'einsatz_klaerung_aufgehoben')->first();
            $this->assertNotNull($aufgehoben);
            $this->assertStringContainsString('Nina Personal', $aufgehoben->summary);

            // Und der alte Eintrag steht weiterhin da — er ist die Historie.
            $this->assertSame(2, Capsule::table('rec_auto_pilot_logs')
                ->where('rec_applicant_id', 208)->whereIn('type', ['einsatz_klaerung_gesetzt', 'einsatz_klaerung_aufgehoben'])->count());
        } finally {
            Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 208)->delete();
            $this->raeumeKlaerungAb();
        }
    }

    /**
     * Buchung 408 UND ihre Akten-Eintraege: seit die Klaerung protokolliert
     * wird, hinterlaesst jeder Schreibtest Zeilen im Aktivitaeten-Log. Ohne das
     * Aufraeumen zaehlt der Protokoll-Test die Eintraege seiner Nachbarn mit.
     */
    private function raeumeKlaerungAb(): void
    {
        Capsule::table('rec_interview_bookings')->where('id', 408)->update([
            'einsatz_geklaert_at' => null,
            'einsatz_geklaert_note' => null,
            'einsatz_wiedervorlage_am' => null,
            'einsatz_geklaert_by' => null,
        ]);
        Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', 208)->delete();
    }

    public function test_schulung_zu_einsatz_drei_ehrliche_toepfe(): void
    {
        // Markus (09.09.2026): „Haben die Bestandenen Einsaetze — und wenn ja,
        // wie viele und ab wann?" Drei Toepfe je Termin, nur Teilgenommene:
        // im Einsatz / ohne Einsatz (pruefbar!) / nicht pruefbar (kein MA oder
        // keine ZAS-PersNr — darf NICHT still als „ohne Einsatz" zaehlen).
        $component = $this->component('Essen');
        $table = $this->tableOf($component);

        $august = $this->rowOf($table, self::INTERVIEW_AUGUST);
        $vm = new CohortViewModel();
        $this->assertSame([204], $vm->resolveIds($august['rows'], ['scope' => 'all'], 'im_einsatz'));
        $this->assertSame([208], $vm->resolveIds($august['rows'], ['scope' => 'all'], 'ohne_einsatz'));
        $this->assertSame([], $vm->resolveIds($august['rows'], ['scope' => 'all'], 'einsatz_unpruefbar'),
            'die uebrigen August-Buchungen sind nicht teilgenommen — kein Topf');

        // Juli: 202 hat teilgenommen, sein MA traegt KEINE PersNr → nicht pruefbar
        $juli = $this->rowOf($table, self::INTERVIEW_JULI);
        $this->assertSame([202], $vm->resolveIds($juli['rows'], ['scope' => 'all'], 'einsatz_unpruefbar'));

        // Anzahl + erstes Datum fuer den Drilldown; der Storno (status_id 3)
        // zaehlt nicht und stellt auch nicht das erste Datum
        $info = $component->cohort()['einsatz_info'];
        $this->assertSame(['count' => 3, 'first' => '2026-09-10', 'grund' => null], $info[204],
            'Einsaetze BEIDER Anstellungen zaehlen — die zweite haengt NUR ueber den person_key dran');
        $this->assertSame(['count' => 0, 'first' => null, 'grund' => null], $info[208],
            'prüfbar, obwohl nur der ZWEIT-Datensatz eine Nummer traegt');
        $this->assertSame('keine_pnr', $info[202]['grund']);
        $this->assertSame('kein_ma', $info[201]['grund']);

    }

    public function test_termin_zeile_zaehlt_alle_teilnehmer_des_termins_nicht_nur_die_der_filiale(): void
    {
        // Live-Befund (Schulung 25.08.2026): 16 Buchungen auf attended, die Zeile
        // zeigte 11 — vier Teilnehmer kamen ueber Koelner Anzeigen und fielen bei
        // Filiale „Duesseldorf" aus dem Trichter, obwohl die Schulung in
        // Duesseldorf stattfand und sie da waren. Wer teilgenommen hat, hat
        // teilgenommen: der Ort-, Taetigkeits- und Status-Filter gehoert an die
        // AUSSCHREIBUNGS-Zeilen (Tabelle 1), nicht an die Teilnehmer eines
        // Termins. Die Herkunft bleibt sichtbar — als Unterzeile, nicht als Sieb.
        $component = $this->component('Essen');
        $table = $this->tableOf($component);
        $august = $this->rowOf($table, self::INTERVIEW_AUGUST);

        $this->assertSame(4, $component->countIn($august['rows'], 'ids'), 'alle vier Buchungen des Termins');
        $this->assertSame(2, $component->countIn($august['rows'], 'teilgenommen'), '204 (Essen) UND 208 (Wuppertal)');
        $this->assertContains(
            self::POSTING_KUECHE,
            array_map(fn ($o) => $o['posting_id'], $august['origins']),
            'die fremde Herkunft steht als Unterzeile da, statt wegzufallen',
        );

        // Tabelle 1 bleibt die Filiale: 208 ist KEINE Essener Bewerbung
        $vm = new CohortViewModel();
        $this->assertNotContains(208, $vm->resolveIds($component->cohort()['rows'], ['scope' => 'all'], 'ids'),
            'die Ausschreibungs-Tabelle der Filiale Essen zaehlt ihn nicht');

        // Die Termin-Menge ist die UNGEFILTERTE Kohorte — und das Drill-down des
        // Termins loest genau die vier auf, die die Zeile zeigt
        $terminToken = $component->drillToken('interviews', 'Schulung August', [
            'interviews' => [self::INTERVIEW_AUGUST],
        ]);
        $this->assertSame(
            [201, 203, 204, 208],
            $vm->resolveIdsFromClient($component->cohort()['termin_rows'], $vm->decodeScope($terminToken), 'ids'),
        );

        // Die Fussnote „nicht in dieser Tabelle" bleibt auf die AUSWAHL bezogen:
        // 205 (inaktiver Termin) und 207 (Termin ohne Stelle) sind Essener
        // Bewerbungen, deren Termin fehlt. Wuppertaler Termine fehlen hier zwar
        // auch, sind aber keine Differenz zu Tabelle 1 — die zeigt sie ebenso
        // nicht.
        $this->assertSame(2, $table['outside']['interviews']);
        $this->assertSame(2, $table['outside']['applications']);
    }

    public function test_herkunft_summiert_sich_zur_zeile_des_termins(): void
    {
        // Dieselbe Zusicherung wie im Unit-Test, aber am ECHTEN Bestand: die
        // Unterzeilen sind die Assigner-Zeilen des Termins, nach Ausschreibung
        // gruppiert. Drei Ausschreibungen, vier Teilnehmer, keine Doppelzaehlung.
        $component = $this->component('Essen');
        $table = $this->tableOf($component);
        $august = $this->rowOf($table, self::INTERVIEW_AUGUST);

        $this->assertSame(
            [self::POSTING_BANKETT, self::POSTING_SERVICE, self::POSTING_KUECHE],
            array_map(fn ($o) => $o['posting_id'], $august['origins']),
            'eine Unterzeile pro Ausschreibung der Teilnehmer, alphabetisch nach Titel — auch die fremde Filiale',
        );

        foreach (['ids', 'gebucht', 'standby', 'unterschrieben'] as $column) {
            $herkunft = 0;
            foreach ($august['origins'] as $origin) {
                $herkunft += $component->countIn($origin['rows'], $column);
            }
            $this->assertSame(
                $component->countIn($august['rows'], $column),
                $herkunft,
                "Spalte {$column}: Summe der Herkunft = Zeile des Termins",
            );
        }

        // ... und das Drill-down-Token der Unterzeile trifft genau ihre Personen.
        // Geprueft wird der ganze Weg, den ein Klick nimmt: Token bauen (Komponente)
        // → dekodieren → gegen die FRISCHEN Zeilen aufloesen (ViewModel). Nur
        // drill() selbst bleibt aussen vor, weil es die Computed Property $cohort
        // liest, die es ohne Livewire-Lifecycle nicht gibt.
        $bankett = $august['origins'][0];
        $this->assertSame(self::POSTING_BANKETT, $bankett['posting_id']);
        $this->assertSame(1, $component->countIn($bankett['rows'], 'ids'));

        $vm = new CohortViewModel();
        $token = $component->drillToken('interviews_posting', 'Aushilfe Bankett', [
            'interviews' => [self::INTERVIEW_AUGUST], 'posting' => self::POSTING_BANKETT,
        ]);
        $this->assertSame(
            [204],
            $vm->resolveIdsFromClient($component->cohort()['termin_rows'], $vm->decodeScope($token), 'ids'),
        );

        // Der Termin selbst loest die Vereinigung auf — dieselbe Menge, die die
        // Zeile anzeigt, und dieselbe Tuer wie die Gesamt-Zeile (Liste mit einem
        // Eintrag)
        $terminToken = $component->drillToken('interviews', 'Schulung August', [
            'interviews' => [self::INTERVIEW_AUGUST],
        ]);
        $this->assertSame(
            [201, 203, 204, 208],
            $vm->resolveIdsFromClient($component->cohort()['termin_rows'], $vm->decodeScope($terminToken), 'ids'),
        );
    }

    public function test_summen_belegung_ist_aus_ihren_eigenen_zahlen_nachrechenbar(): void
    {
        // Am echten Bestand: August 3 von 8 belegt, Juli 1 von 4 — beide Termine
        // haben eine gepflegte Kapazitaet, also 4 von 12 und kein ausgelassener
        // Termin. Der Prozentwert (33 %) ist aus den beiden Zeilen darueber
        // nachrechenbar, und genau das war der Punkt: vorher zaehlte der Zaehler
        // mehr Termine als der Nenner.
        $component = $this->component('Essen');
        $table = $this->tableOf($component);
        $belegung = $component->belegungTotals($table['rows']);

        $this->assertSame(4, $belegung['taken']);
        $this->assertSame(12, $belegung['max']);
        $this->assertSame(0, $belegung['unlimited_interviews']);
        $this->assertStringContainsString('4 von 12 Plätzen belegt', $belegung['reason']);
        $this->assertStringNotContainsString('NICHT in dieser Summe', $belegung['reason']);

        // Zaehler und Nenner zaehlen dieselben Termine — die Summe der Zeilen
        // ergibt genau die Summe der Zeile
        $zeilenIst = 0;
        $zeilenSoll = 0;
        foreach ($table['rows'] as $row) {
            $zeilenIst += $row['seat_taking'];
            $zeilenSoll += (int) $row['max'];
        }
        $this->assertSame($zeilenIst, $belegung['taken']);
        $this->assertSame($zeilenSoll, $belegung['max']);
    }

    public function test_fussnote_der_belegung_nennt_nur_echte_differenzen(): void
    {
        // Randfall aus dem Re-Review: ein unbegrenzter Termin OHNE Buchung darf
        // benannt werden („1 Termin ohne Platzbegrenzung (0 belegte Plätze)"), aber
        // der Zusatz „deshalb ist die Summe kleiner" waere falsch — 2 = 2. Eine
        // benannte Differenz, die es nicht gibt, ist derselbe Regelbruch wie eine
        // falsche Quote, nur am Rand. Der Zusatz haengt deshalb an den belegten
        // PLAETZEN, nicht an der Zahl der Termine.
        $component = $this->component();

        $ohneBelegung = $component->belegungTotals([
            ['max' => 5, 'seat_taking' => 2],
            ['max' => null, 'seat_taking' => 0],
        ]);
        $this->assertSame(2, $ohneBelegung['taken']);
        $this->assertSame(5, $ohneBelegung['max']);
        $this->assertStringContainsString('1 Termin ohne Platzbegrenzung (0 belegte Plätze)', $ohneBelegung['reason']);
        $this->assertStringNotContainsString('deshalb ist die Summe kleiner', $ohneBelegung['reason']);

        // Mit belegten Plaetzen ist die Differenz echt — dann gehoert der Satz hin
        $mitBelegung = $component->belegungTotals([
            ['max' => 5, 'seat_taking' => 2],
            ['max' => null, 'seat_taking' => 3],
        ]);
        $this->assertStringContainsString('1 Termin ohne Platzbegrenzung (3 belegte Plätze)', $mitBelegung['reason']);
        $this->assertStringContainsString('deshalb ist die Summe kleiner', $mitBelegung['reason']);

        // EINE Lesart fuer denselben Wert: die Datenzeile rendert „∞" (unbegrenzt),
        // also heisst es auch hier „ohne Platzbegrenzung" und nicht „ohne gepflegte
        // Kapazitaet" — zwei Woerter fuer denselben Zustand waeren zwei Lesarten.
        $nurUnbegrenzt = $component->belegungTotals([['max' => null, 'seat_taking' => 4]]);
        $this->assertNull($nurUnbegrenzt['taken']);
        $this->assertStringContainsString('Kein Termin dieser Auswahl hat eine Platzbegrenzung', $nurUnbegrenzt['reason']);
        $this->assertStringContainsString('(4 belegte Plätze)', $nurUnbegrenzt['reason']);
        $this->assertStringNotContainsString('Kapazität', $nurUnbegrenzt['reason']);
    }

    public function test_fussnote_steht_auch_bei_null_sichtbaren_terminen(): void
    {
        // Der Fall, in dem die Erklaerung am noetigsten ist: der Zeitraum trifft
        // KEINEN Termin, die Tabelle ist leer — und die Teilnehmer stecken
        // trotzdem irgendwo. Vorher stand die Fussnote im @else-Zweig und
        // verschwieg sie genau hier.
        $component = $this->component('Essen', '2027-01-01', '2027-01-31');
        $table = $this->tableOf($component);

        $this->assertSame([], $table['rows'], 'kein Termin im Zeitraum');
        $this->assertSame(4, $table['outside']['interviews'],
            'August, Juli, der inaktive und der Termin ohne Stelle');
        $this->assertSame(6, $table['outside']['applications'], 'alle Teilnehmer der Filiale Essen');

        // Und die Summen-Belegung erfindet dabei nichts
        $belegung = $component->belegungTotals($table['rows']);
        $this->assertNull($belegung['taken']);
        $this->assertNull($belegung['max']);
        $this->assertSame(0, $belegung['unlimited_interviews']);
    }

    public function test_termin_ohne_stelle_fehlt_in_tabelle_2_und_wird_nicht_falsch_erklaert(): void
    {
        // rec_interviews.rec_position_id ist nullable: ein aktiver Termin IM
        // Zeitraum kann trotzdem fehlen, weil er an keiner Stelle haengt und
        // deshalb durch den Ort-Filter fällt — der Teilnehmer gehoert dabei sehr
        // wohl zur gewaehlten Filiale (seine Kohorten-Zeile haengt an der
        // Ausschreibung, die Schulungszeile allein an der Buchung). Genau deshalb
        // darf die Fussnote „inaktiv oder ausserhalb des Zeitraums" nicht als
        // vollstaendige Erklaerung dastehen.
        $component = $this->component('Essen');
        $table = $this->tableOf($component);

        $this->assertNotContains(
            self::INTERVIEW_OHNE_STELLE,
            array_map(fn ($row) => $row['interview_id'], $table['rows']),
            'ohne Stelle greift der Ort-Filter',
        );
        // Der Termin ist aktiv und liegt im Zeitraum — beide „naheliegenden"
        // Gruende sind hier also falsch, und er zaehlt trotzdem in outside mit
        $this->assertContains(self::INTERVIEW_OHNE_STELLE, $this->interviewIds($this->component()));
        $this->assertSame(2, $table['outside']['interviews'], 'inaktiver Termin UND Termin ohne Stelle');
        $this->assertSame(2, $table['outside']['applications']);
    }

    public function test_teilnehmer_ausserhalb_der_termin_auswahl_werden_benannt(): void
    {
        // Der inaktive Termin und der Termin ohne Stelle haben je einen
        // Teilnehmer. Beide stecken in Tabelle 1 (die kennt keinen
        // Termin-Zeitraum, kein is_active und keine Stelle am Termin), tauchen in
        // Tabelle 2 aber nicht auf. Diese Differenz wird BENANNT statt verschluckt
        // — eine Zahl, die ohne Erklaerung kleiner ist als daneben, ist genau die
        // Sorte Zahl, die der Kunde nicht nachvollziehen konnte.
        $component = $this->component('Essen');
        $table = $this->tableOf($component);

        $this->assertSame(2, $table['outside']['interviews']);
        $this->assertSame(2, $table['outside']['applications']);

        // Die Gegenrichtung des Koeln-Falls: unter Filiale Wuppertal steht 208 in
        // Tabelle 1 (seine Anzeige ist Wuppertal), sein Termin (Essen) aber nicht
        // in Tabelle 2 — genau EINE benannte Differenz, keine mehr und keine
        // weniger. Der Wuppertaler Termin selbst zaehlt nicht: dort sitzt nur ein
        // Testbewerber, also keine Kohorten-Zeile.
        $wuppertal = $this->tableOf($this->component('Wuppertal'));
        $this->assertSame(1, $wuppertal['outside']['interviews']);
        $this->assertSame(1, $wuppertal['outside']['applications']);
    }

    public function test_termin_ohne_kohorten_teilnehmer_bleibt_eine_zeile(): void
    {
        // Ein Termin ohne Kohorten-Teilnehmer verschwindet NICHT: seine Belegung
        // ist eine Aussage („fuenf Plaetze, einer belegt, keiner davon in dieser
        // Auswahl"). Hier ist es ein Testbewerber — er belegt einen Platz am
        // Termin und steckt in keiner Kohorte. Die beiden Zahlen duerfen deshalb
        // auseinandergehen, und niemand darf sie gegeneinander rechnen.
        $component = $this->component('Wuppertal');
        $table = $this->tableOf($component);
        $row = $this->rowOf($table, self::INTERVIEW_WUPPERTAL);

        $this->assertSame([], $row['rows']);
        $this->assertSame([], $row['origins']);
        $this->assertSame(1, $row['seat_taking'], 'die Buchung des Termins zaehlt trotzdem');
        $this->assertSame(5, $row['max']);
        $this->assertSame(0, $component->countIn($row['rows'], 'ids'), 'Testbewerber sind in keiner Kohorte');
    }

    public function test_termin_query_kostet_drei_queries(): void
    {
        // Query-Budget ist Abnahmekriterium §2: eine Query fuer die Termine
        // (Belegung als Subselect, Ort als Sub-Query) plus zwei Eager Loads
        // (Terminart, Ausschreibung). Kein N+1 ueber die Termine.
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->component('Essen')->interviews();

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $this->assertCount(3, $queries, 'Termine + Terminart + Ausschreibung: ' . json_encode(
            array_map(fn ($q) => $q['query'], $queries),
        ));
    }

    /** Genau eine Tabellenzeile zu einem Termin holen. */
    private function rowOf(array $table, int $interviewId): array
    {
        $hits = array_values(array_filter($table['rows'], fn ($row) => $row['interview_id'] === $interviewId));
        $this->assertCount(1, $hits, "genau eine Zeile erwartet fuer Termin {$interviewId}");

        return $hits[0];
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(\Platform\Core\Models\CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => 1, 'uuid' => 'ivpos-1', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'ivpos-2', 'team_id' => self::TEAM, 'title' => 'Küche',
             'location' => 'Wuppertal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'uuid' => 'ivph-1', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'ivph-2', 'team_id' => self::TEAM, 'rec_position_id' => 2,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_SERVICE, 'uuid' => 'ivpost-20', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_KUECHE, 'uuid' => 'ivpost-21', 'team_id' => self::TEAM, 'rec_position_id' => 2,
             'title' => 'Küchenhilfe', 'activity' => 'Küche', 'status' => 'published', 'is_active' => 1,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // Titel bewusst alphabetisch VOR „Kellner", damit die Sortierung der
            // Herkunfts-Unterzeilen (nach Titel) gepruefbar ist
            ['id' => self::POSTING_BANKETT, 'uuid' => 'ivpost-22', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'published', 'is_active' => 1,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_types')->insert([
            ['id' => 1, 'uuid' => 'ivtype-1', 'team_id' => self::TEAM, 'name' => 'Schulung',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            // Filiale Essen (Stelle 1), Veranstaltungsort bewusst anders
            ['id' => self::INTERVIEW_AUGUST, 'uuid' => 'iv-300', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 1, 'rec_posting_id' => self::POSTING_SERVICE,
             'title' => 'Schulung August', 'location' => 'Bahnhof Duisburg, Gleis 3',
             'starts_at' => '2026-08-10 10:00:00', 'max_participants' => 8,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // Essen, aber OHNE Ausschreibung am Termin -> Rueckfall auf den Titel
            ['id' => self::INTERVIEW_JULI, 'uuid' => 'iv-301', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 1, 'rec_posting_id' => null,
             'title' => 'Nachschulung Juli', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-07-05 09:00:00', 'max_participants' => 4,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::INTERVIEW_WUPPERTAL, 'uuid' => 'iv-302', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 2, 'rec_posting_id' => self::POSTING_KUECHE,
             'title' => 'Schulung Wuppertal', 'location' => 'Wuppertal, Werkstatt',
             'starts_at' => '2026-08-12 10:00:00', 'max_participants' => 5,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // stillgelegt: hat einen Teilnehmer, gehoert aber nicht in die Tabelle
            ['id' => self::INTERVIEW_INAKTIV, 'uuid' => 'iv-303', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 1, 'rec_posting_id' => self::POSTING_SERVICE,
             'title' => 'Testtermin', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-08-14 10:00:00', 'max_participants' => 6,
             'is_active' => 0, 'created_at' => $now, 'updated_at' => $now],
            // AKTIV und im Zeitraum, aber OHNE Stelle: faellt durch den Ort-Filter,
            // obwohl der Teilnehmer an einer Essener Ausschreibung haengt.
            // Zusaetzlich ohne gepflegte Kapazitaet — damit ist er auch der Fall,
            // den die Summen-Belegung aus dem Bruch nimmt und benennt.
            ['id' => self::INTERVIEW_OHNE_STELLE, 'uuid' => 'iv-304', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => null, 'rec_posting_id' => null,
             'title' => 'Sonderschulung ohne Stelle', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-08-11 10:00:00', 'max_participants' => null,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => 201, 'uuid' => 'ivapp-201', 'team_id' => self::TEAM, 'applied_at' => '2026-07-20',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 202, 'uuid' => 'ivapp-202', 'team_id' => self::TEAM, 'applied_at' => '2026-07-21',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 203, 'uuid' => 'ivapp-203', 'team_id' => self::TEAM, 'applied_at' => '2026-07-22',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 204, 'uuid' => 'ivapp-204', 'team_id' => self::TEAM, 'applied_at' => '2026-07-23',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 205, 'uuid' => 'ivapp-205', 'team_id' => self::TEAM, 'applied_at' => '2026-07-24',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // TESTBEWERBER auf dem Wuppertal-Termin: er belegt dort einen Platz
            // (Termin-Query), gehoert aber in keine Kohorte (Stufe 1 der
            // Praezedenz-Kette). Genau der Fall, in dem Belegung und Trichter
            // AUSEINANDERGEHEN — und der zeigt, dass sie zwei Quellen haben.
            ['id' => 206, 'uuid' => 'ivapp-206', 'team_id' => self::TEAM, 'applied_at' => '2026-07-25',
             'rec_phase_id' => 2, 'is_test' => 1, 'created_at' => $now, 'updated_at' => $now],
            // Teilnehmer des Termins OHNE Stelle: haengt an einer Essener
            // Ausschreibung, steht also in der Essen-Kohorte — sein Termin
            // erscheint in Tabelle 2 trotzdem nicht.
            ['id' => 207, 'uuid' => 'ivapp-207', 'team_id' => self::TEAM, 'applied_at' => '2026-07-26',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // Der KOELN-FALL (Live-Befund 25.08.2026): kam ueber die Wuppertaler
            // Ausschreibung (mehrere Wunschorte), hat sich selbst in den Essener
            // Termin gebucht und war da. Seine Kohorten-Zeile gehoert zur Filiale
            // Wuppertal (Herkunft), sein Termin zur Filiale Essen — die
            // Termin-Zeile muss ihn trotzdem zaehlen.
            ['id' => 208, 'uuid' => 'ivapp-208', 'team_id' => self::TEAM, 'applied_at' => '2026-07-27',
             'rec_phase_id' => 2, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Schulung → Einsatz (09.09.2026): Dispo-Zuweisungen matchen nur ueber
        // die ZAS-Personalnummer des Mitarbeiters. Drei Faelle:
        //  - 204: MA mit PersNr und zwei echten Einsaetzen (+ ein Storno, der
        //    NICHT zaehlt und NICHT das erste Datum stellen darf)
        //  - 208: MA mit PersNr, keine Zuweisung -> „ohne Einsatz"
        //  - 202: MA OHNE PersNr -> „nicht pruefbar" (Import kann nie matchen)
        //  - alle anderen: kein MA -> „nicht pruefbar"
        // Schulungsleiter: die interviewers-Relation zeigt auf Core-users —
        // die Tabelle existiert im Recruiting-Migrationssatz nicht, hier
        // minimal angelegt (die Detailansicht liest nur den Namen, per
        // Query-Builder-Join statt ueber das Core-Model).
        Capsule::schema()->create('users', function ($t) {
            $t->id();
            $t->string('name');
        });
        // CRM-Tabellen minimal: die Detailansicht baut den Namen aus den
        // ECHTEN Link-Models (der 500er vom 09.09. entstand, weil der Test nur
        // eine leere Link-Tabelle hatte und display() nie echte Models sah).
        // 204 bekommt einen Kontakt, alle anderen fallen auf „Bewerber #id"
        // zurueck — beide Pfade laufen.
        Capsule::schema()->create('crm_contact_links', function ($t) {
            $t->id();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('linkable_id');
            $t->string('linkable_type');
        });
        Capsule::schema()->create('crm_contacts', function ($t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });
        Capsule::table('crm_contacts')->insert([
            ['id' => 800, 'first_name' => 'Zoe', 'last_name' => 'Anders'],
        ]);
        Capsule::table('crm_contact_links')->insert([
            ['contact_id' => 800, 'linkable_id' => 204,
             'linkable_type' => \Platform\Recruiting\Models\RecApplicant::class],
        ]);
        Capsule::table('users')->insert([
            ['id' => 700, 'name' => 'Clara Setzkorn'],
            ['id' => 701, 'name' => 'Ben Trainer'],
            // Die handelnde Person der Schreibtests (auth()->id() der Attrappe):
            // ihr Name steht im Akten-Eintrag der Klaerung.
            ['id' => 77, 'name' => 'Nina Personal'],
        ]);
        Capsule::table('rec_interview_user')->insert([
            ['rec_interview_id' => self::INTERVIEW_AUGUST, 'user_id' => 700],
            ['rec_interview_id' => self::INTERVIEW_AUGUST, 'user_id' => 701],
        ]);

        // ZWEI-FIRMEN-FALL (ZAS bedient RG und MA — eine Person kann bei
        // beiden angestellt sein und hat dann ZWEI Datensaetze mit zwei
        // Personalnummern; Chaieb-Befund 10.09.2026):
        //  - 204 hat BEIDE Anstellungen verknuepft — Einsaetze beider Nummern
        //    zaehlen zusammen
        //  - 208 traegt die Nummer nur am ZWEIT-Datensatz (der erste ist
        //    nummernlos) — trotzdem pruefbar
        //  - 202 bleibt der Fall „MA ohne jede Nummer" → nicht pruefbar
        Capsule::table('rec_employees')->insert([
            // 204: RG-Datensatz verlinkt; der MA-Datensatz (505) haengt NICHT an
            // der Bewerbung, traegt aber denselben person_key — der Abgleich
            // muss ihn ueber den Key finden (Chaieb: MA18232 war unverlinkt).
            ['id' => 501, 'uuid' => 'ivemp-501', 'team_id' => self::TEAM, 'rec_applicant_id' => 204,
             'person_key' => 'pk-chaieb', 'personnel_number' => 'RG204', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 505, 'uuid' => 'ivemp-505', 'team_id' => self::TEAM, 'rec_applicant_id' => null,
             'person_key' => 'pk-chaieb', 'personnel_number' => '18232', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 502, 'uuid' => 'ivemp-502', 'team_id' => self::TEAM, 'rec_applicant_id' => 208,
             'person_key' => null, 'personnel_number' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 504, 'uuid' => 'ivemp-504', 'team_id' => self::TEAM, 'rec_applicant_id' => 208,
             'person_key' => null, 'personnel_number' => 'RG208', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 503, 'uuid' => 'ivemp-503', 'team_id' => self::TEAM, 'rec_applicant_id' => 202,
             'person_key' => null, 'personnel_number' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Capsule::table('rec_dispo_events')->insert([
            ['id' => 900, 'uuid' => 'ivdev-900', 'einsatz_ref' => 'RG-EV-900',
             'created_at' => $now, 'updated_at' => $now],
        ]);
        Capsule::table('rec_dispo_assignments')->insert([
            ['id' => 950, 'uuid' => 'ivdas-950', 'ds_ref' => 'DS-950', 'rec_dispo_event_id' => 900,
             'pnr_raw' => 'RG204', 'rec_employee_id' => 501, 'datum' => '2026-09-20',
             'status_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 951, 'uuid' => 'ivdas-951', 'ds_ref' => 'DS-951', 'rec_dispo_event_id' => 900,
             'pnr_raw' => 'RG204', 'rec_employee_id' => 501, 'datum' => '2026-09-12',
             'status_id' => 0, 'created_at' => $now, 'updated_at' => $now],
            // Storno: existiert, zaehlt aber nicht — auch nicht fuers erste Datum
            ['id' => 952, 'uuid' => 'ivdas-952', 'ds_ref' => 'DS-952', 'rec_dispo_event_id' => 900,
             'pnr_raw' => 'RG204', 'rec_employee_id' => 501, 'datum' => '2026-09-01',
             'status_id' => 3, 'created_at' => $now, 'updated_at' => $now],
            // Einsatz auf der ZWEITEN Anstellung von 204 (andere Firma, andere
            // Nummer) — zaehlt mit UND stellt das frueheste Datum
            ['id' => 953, 'uuid' => 'ivdas-953', 'ds_ref' => 'DS-953', 'rec_dispo_event_id' => 900,
             'pnr_raw' => '18232', 'rec_employee_id' => 505, 'datum' => '2026-09-10',
             'status_id' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 201, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 202, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 203, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 204, 'rec_posting_id' => self::POSTING_BANKETT, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 205, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 206, 'rec_posting_id' => self::POSTING_KUECHE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 207, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 208, 'rec_posting_id' => self::POSTING_KUECHE, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_bookings')->insert([
            // August-Termin: zwei Ausschreibungen, drei Teilnehmer, davon einer
            // auf Standby (booked + seat_released_at) -> belegt sind zwei Plaetze
            ['id' => 401, 'uuid' => 'ivb-401', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 201, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 402, 'uuid' => 'ivb-402', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 203, 'status' => 'booked', 'seat_released_at' => '2026-08-01 09:00:00',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 403, 'uuid' => 'ivb-403', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 204, 'status' => 'attended', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Juli-Termin
            ['id' => 404, 'uuid' => 'ivb-404', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_JULI,
             'rec_applicant_id' => 202, 'status' => 'attended', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Teilnehmer des INAKTIVEN Termins -> Grundlage der Fussnote
            ['id' => 405, 'uuid' => 'ivb-405', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_INAKTIV,
             'rec_applicant_id' => 205, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Wuppertal: Buchung eines TESTBEWERBERS — belegt einen Platz, steckt
            // in keiner Kohorte
            ['id' => 406, 'uuid' => 'ivb-406', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_WUPPERTAL,
             'rec_applicant_id' => 206, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Termin ohne Stelle
            ['id' => 407, 'uuid' => 'ivb-407', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_OHNE_STELLE,
             'rec_applicant_id' => 207, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 408, 'uuid' => 'ivb-408', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 208, 'status' => 'attended', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}

/**
 * Probe fuer den Test: reicht buildInterviewTable() heraus, ohne sie in der
 * Produktions-Klasse public zu machen.
 *
 * Warum die Komponente das nicht selbst anbietet: jede public Methode einer
 * Livewire-Komponente ist vom Client als Action aufrufbar, und
 * buildInterviewTable() erwartet Eloquent-Objekte — ein gecrafteter Aufruf mit
 * Arrays waere ein 500er auf Zuruf. Warum der Test nicht die Computed-Huelle
 * interviewTable() nimmt: Computed Properties gibt es nur im Livewire-Lifecycle,
 * hier laeuft die Klasse nackt. Die Probe holt deshalb dieselben beiden Quellen
 * als METHODEN — dasselbe Ergebnis, ohne dass die Huelle in Produktion ihre
 * Query-Caches verliert.
 */
final class InterviewTableProbe extends Index
{
    /** Ohne Livewire-Lebenszyklus gibt es keine Computed-Property — cohort() als Methode. */
    protected function cohortResult(): array
    {
        return $this->cohortCache ??= $this->cohort();
    }

    private ?array $cohortCache = null;

    /** @return array{rows:list<array>, outside:array{interviews:int, applications:int}} */
    public function probeInterviewTable(): array
    {
        $cohort = $this->cohort();

        return $this->buildInterviewTable($this->interviews(), $cohort['termin_rows'], $cohort['rows']);
    }
}
