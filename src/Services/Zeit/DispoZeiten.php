<?php

namespace Platform\Recruiting\Services\Zeit;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Support\NachnameZuerst;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitAkteur;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitRechner;
use Platform\Recruiting\Zeit\ZeitRegeln;

/**
 * Zeiterfassung in der Disposition (Spec 2026-11-16 §4): Spaltentexte,
 * Fenster-Daten, Korrektur und stellvertretendes Stempeln ueber der Stempeluhr.
 * Rechte (Konten „Nur Veranstaltungen“) prueft die Komponente; dieser Dienst
 * prueft den Schalter. Zeiten gehen und kommen in Ortszeit (ZeitAnzeige).
 */
class DispoZeiten
{
    private const FELDER = [
        'kommen_at' => 'Kommen',
        'gehen_at' => 'Gehen',
        'pause_minuten' => 'Pause',
        'bemerkung' => 'Bemerkung',
        'gesperrt_at' => 'Sperre',
        'person_id' => 'Anstellung',
    ];

    private const ARTEN = [
        Stempeluhr::ART_SELBST => 'selbst',
        Stempeluhr::ART_STELLVERTRETEND => 'stellvertretend',
        Stempeluhr::ART_KORREKTUR => 'Korrektur',
        Stempeluhr::ART_SPERRE => 'Sperre',
        Stempeluhr::ART_UMZUG => 'Import',
    ];

    /** Verlaufszeile des Imports, wenn die Einbuchung auf eine andere Anstellung derselben Person wechselt. */
    public const TEXT_UMZUG = 'Anstellung gewechselt (ZAS)';

    public const GRUND_ERLEDIGT = 'erledigt';

    /** Zusatz am Namen, wenn ein Teamleiter am Eventzugang (Sammelkonto) bucht — so steht er im Verlauf. */
    public const EVENTZUGANG = ' (Eventzugang)';

    public function __construct(
        private Stempeluhr $uhr,
        private ZeitSchalter $schalter,
        private ZeitRegeln $regeln,
    ) {
    }

    /**
     * Zeitdatensaetze der Veranstaltung nach Einbuchungs-ID — zwei Abfragen,
     * unabhaengig von der Zeilenzahl. Massgeblich ist der Datensatz der Person,
     * die AKTUELL an der Zeile haengt; ein Rest einer anderen Person zaehlt nicht.
     * Leer (ohne Abfrage) bei Schalter aus. Verlauf wird nicht mitgeladen.
     *
     * @return array<int, ZeitEintrag>
     */
    public function fuerEvent(int $eventId): array
    {
        if (!$this->schalter->aktiv()) {
            return [];
        }
        $personen = [];
        foreach (RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->whereNotNull('rec_employee_id')
            ->get(['id', 'rec_employee_id']) as $a) {
            $personen[(int) $a->id] = (int) $a->rec_employee_id;
        }
        if ($personen === []) {
            return [];
        }

        $karte = [];
        foreach (ZeitEintrag::query()
            ->where('person_typ', DispoZeitBezug::PERSON_TYP)
            ->where('bezug_typ', DispoZeitBezug::BEZUG_TYP)
            ->whereIn('bezug_id', array_keys($personen))
            ->get() as $e) {
            if (($personen[(int) $e->bezug_id] ?? null) === (int) $e->person_id) {
                $karte[(int) $e->bezug_id] = $e;
            }
        }

        return $karte;
    }

    /** @return array{zustand:string,text:string,verspaetung:int,gesperrt:bool} */
    public function zelle(?ZeitEintrag $e): array
    {
        if ($e === null) {
            return ['zustand' => 'offen', 'text' => 'nicht eingecheckt', 'verspaetung' => 0, 'gesperrt' => false];
        }
        $zustand = $e->zustand();
        $kommen = ZeitAnzeige::uhrzeit($e->kommen_at);
        $gehen = ZeitAnzeige::uhrzeit($e->gehen_at);
        $text = match ($zustand) {
            'eingecheckt' => 'eingecheckt ' . $kommen,
            'ausgecheckt' => 'ausgecheckt ' . $kommen . ' – ' . $gehen,
            'erledigt' => trim('erledigt ' . $kommen . ($gehen !== '' ? ' – ' . $gehen : '')),
            default => 'nicht eingecheckt',
        };

        return [
            'zustand' => $zustand,
            'text' => $text,
            'verspaetung' => ZeitRechner::verspaetungMinuten($e->kommen_at, $e->soll_von_at),
            'gesperrt' => $e->istGesperrt(),
        ];
    }

    /**
     * Daten fuers Fenster „Zeit“. Formularwerte im Format Y-m-d\TH:i (Ortszeit),
     * Verlauf neueste zuerst — leer bei $mitVerlauf = false (nur fuer den Schnappschuss, der ihn nicht braucht).
     * Ohne Person: leeres Geruest (die Komponente oeffnet so eine Zeile nicht).
     *
     * @return array{name:string,tag:string,soll:string,kommen:?string,gehen:?string,pause:?int,regelpause:int,bemerkung:string,zustand:string,gesperrt:bool,verlauf:list<array{wann:string,wer:string,feld:string,alt:?string,neu:?string,grund:?string,art:string,text:?string}>}
     */
    public function fenster(RecDispoAssignment $a, bool $mitVerlauf = true): array
    {
        $bezug = DispoZeitBezug::fuer($a, $this->teamId());
        $e = $bezug === null ? null : ZeitEintrag::finde($bezug->personTyp, $bezug->personId, $bezug->bezugTyp, $bezug->bezugId);

        $von = $bezug === null ? '' : ZeitAnzeige::uhrzeit($bezug->sollVon);
        $bis = $bezug === null ? '' : ZeitAnzeige::uhrzeit($bezug->sollBis);

        // Regelpause fuer den Platzhalter: nach Ist-Zeiten, sonst nach der Soll-Schicht.
        $anwesend = $e === null ? null : ZeitRechner::anwesendMinuten($e->kommen_at, $e->gehen_at);
        if ($anwesend === null && $bezug !== null) {
            $anwesend = ZeitRechner::anwesendMinuten($bezug->sollVon, $bezug->sollBis);
        }

        $verlauf = [];
        if ($e !== null && $mitVerlauf) {
            foreach ($e->verlauf()->get() as $v) {
                // Umzug auf eine andere Anstellung: fertiger Satz statt roher Datensatz-IDs.
                $umzug = $v->art === Stempeluhr::ART_UMZUG;
                $verlauf[] = [
                    'wann' => ZeitAnzeige::ortszeit($v->created_at)?->format('d.m. H:i') ?? '',
                    'wer' => (string) $v->akteur_name,
                    'feld' => self::FELDER[$v->feld] ?? (string) $v->feld,
                    'alt' => $umzug ? null : $this->wert((string) $v->feld, $v->alt),
                    'neu' => $umzug ? null : $this->wert((string) $v->feld, $v->neu),
                    'grund' => $v->grund,
                    'art' => self::ARTEN[$v->art] ?? (string) $v->art,
                    'text' => $umzug ? self::TEXT_UMZUG : null,
                ];
            }
        }

        $name = $a->employee === null ? '' : NachnameZuerst::von((string) $a->employee->first_name, (string) $a->employee->last_name);
        $tag = $a->datum instanceof \DateTimeInterface ? $a->datum->format('d.m.Y') : '';

        return [
            'name' => $name,
            'tag' => $tag,
            'soll' => $von === '' ? '' : $von . '–' . $bis,
            'kommen' => $this->formular($e?->kommen_at),
            'gehen' => $this->formular($e?->gehen_at),
            'pause' => $e?->pause_minuten === null ? null : (int) $e->pause_minuten,
            'regelpause' => $anwesend === null ? 0 : $this->regeln->pauseMinuten($anwesend),
            'bemerkung' => (string) ($e?->bemerkung ?? ''),
            'zustand' => $e?->zustand() ?? 'offen',
            'gesperrt' => $e?->istGesperrt() ?? false,
            'an_zas' => $e?->getAttribute('zas_exported_at') !== null,
            'verlauf' => $verlauf,
        ];
    }

    /** ok | gesperrt | ungueltig | unveraendert | aus | ohne_person */
    public function korrigieren(RecDispoAssignment $a, ZeitAkteur $akteur, array $werte, string $grund): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        $bezug = DispoZeitBezug::fuer($a, $this->teamId());
        if ($bezug === null) {
            return 'ohne_person';
        }

        return $this->uhr->korrigieren($bezug, $akteur, $werte, $grund);
    }

    /** ok | schon | gesperrt | aus | ohne_person (ohne Fenster-Pruefung) */
    public function jetztKommen(RecDispoAssignment $a, ZeitAkteur $akteur): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        $bezug = DispoZeitBezug::fuer($a, $this->teamId());

        return $bezug === null ? 'ohne_person' : $this->uhr->kommen($bezug, $akteur, Stempeluhr::ART_STELLVERTRETEND);
    }

    /** ok | kein_kommen | schon | gesperrt | ungueltig | aus | ohne_person (ohne Fenster-Pruefung) */
    public function jetztGehen(RecDispoAssignment $a, ZeitAkteur $akteur): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        $bezug = DispoZeitBezug::fuer($a, $this->teamId());

        return $bezug === null ? 'ohne_person' : $this->uhr->gehen($bezug, $akteur, Stempeluhr::ART_STELLVERTRETEND);
    }

    /**
     * „Erledigt“: sperrt den Datensatz fuer ALLE (auch die Disposition). Nur bei ausgechecktem
     * Datensatz (Kommen UND Gehen gesetzt) — verbindlich geprueft in der Sperr-Transaktion des
     * Kerns (am gesperrt gelesenen Stand); die Pruefung hier spart nur den Schreibweg.
     * Grund ist fest „erledigt“.
     *
     * ok | schon | nicht_ausgecheckt | aus | ohne_person
     */
    public function erledigt(RecDispoAssignment $a, ZeitAkteur $akteur): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        $bezug = DispoZeitBezug::fuer($a, $this->teamId());
        if ($bezug === null) {
            return 'ohne_person';
        }
        $e = ZeitEintrag::finde($bezug->personTyp, $bezug->personId, $bezug->bezugTyp, $bezug->bezugId);
        if ($e === null) {
            return 'nicht_ausgecheckt';
        }
        if ($e->istGesperrt()) {
            return 'schon';
        }
        if ($e->kommen_at === null || $e->gehen_at === null) {
            return 'nicht_ausgecheckt';
        }

        return $this->uhr->sperren($e, $akteur, self::GRUND_ERLEDIGT, true);
    }

    /**
     * Sammel-Variante: Anzahl NEU gesperrter Datensaetze (nur ausgecheckte, noch nicht gesperrte
     * der aktuell eingebuchten Person). Die Anzahl entsteht hier, nie aus dem Browser.
     * Vorgefiltert ueber die gebuendelt geladenen Datensaetze (fuerEvent): Zeilen ohne ausgecheckten
     * Datensatz kosten keine Abfrage. Ob wirklich gesperrt wird, entscheidet weiter die
     * Sperr-Transaktion des Kerns.
     *
     * @param iterable<RecDispoAssignment> $assignments
     */
    public function alleErledigt(iterable $assignments, ZeitAkteur $akteur): int
    {
        if (!$this->schalter->aktiv()) {
            return 0;
        }
        $gewaehlt = [];
        $events = [];
        foreach ($assignments as $a) {
            $gewaehlt[(int) $a->id] = true;
            $events[(int) $a->rec_dispo_event_id] = true;
        }

        $n = 0;
        foreach (array_keys($events) as $eventId) {
            foreach ($this->fuerEvent($eventId) as $id => $e) {
                if (!isset($gewaehlt[$id]) || $e->zustand() !== 'ausgecheckt') {
                    continue;
                }
                if ($this->uhr->sperren($e, $akteur, self::GRUND_ERLEDIGT, true) === 'ok') {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * „Wieder oeffnen“: wer nach ZeitSchalter::darfEntsperren() darf (eigene Anmeldung; Eventzugang nie), mit Pflicht-Grund.
     *
     * ok | nicht_berechtigt | nicht_gesperrt | an_zas | ungueltig | aus | ohne_person
     */
    public function oeffnen(RecDispoAssignment $a, ZeitAkteur $akteur, string $grund, mixed $user): string
    {
        if (!$this->schalter->aktiv()) {
            return 'aus';
        }
        if (!$this->schalter->darfEntsperren($user)) {
            return 'nicht_berechtigt';
        }
        $bezug = DispoZeitBezug::fuer($a, $this->teamId());
        if ($bezug === null) {
            return 'ohne_person';
        }
        $grund = trim($grund);
        if ($grund === '' || mb_strlen($grund) > 255) {
            return 'ungueltig';
        }
        $e = ZeitEintrag::finde($bezug->personTyp, $bezug->personId, $bezug->bezugTyp, $bezug->bezugId);
        if ($e === null) {
            return 'nicht_gesperrt';
        }

        // Schon an ZAS uebergeben: ein zweites Mal geht die Zeile nie raus, eine Aenderung hier
        // kaeme dort nie an. Korrektur im Buero in ZAS (wie bei der Waesche, Markus 20.11.2026).
        // Gesperrt gelesen: der ZAS-Abruf (ZasZeitenExport::beanspruchen) schreibt dieselbe Zeile,
        // ohne Sperre koennte er zwischen Pruefung und Entsperren liefern.
        return $e->getConnection()->transaction(function () use ($e, $akteur, $grund) {
            $frisch = ZeitEintrag::query()->whereKey($e->getKey())->lockForUpdate()->first();
            if ($frisch === null) {
                return 'nicht_gesperrt';
            }
            if ($frisch->getAttribute('zas_exported_at') !== null) {
                return 'an_zas';
            }

            return $this->uhr->entsperren($frisch, $akteur, $grund);
        });
    }

    /**
     * Schnappschuss der Formularwerte (Strings, Ortszeit minutengenau) — damit die Komponente beim Speichern
     * erkennt, ob sich der Datensatz seit dem Oeffnen geaendert hat.
     *
     * @return array{kommen:string,gehen:string,pause:string,bemerkung:string,gesperrt:bool}
     */
    public function stand(array $fenster): array
    {
        return [
            'kommen' => (string) $fenster['kommen'],
            'gehen' => (string) $fenster['gehen'],
            'pause' => $fenster['pause'] === null ? '' : (string) $fenster['pause'],
            'bemerkung' => trim((string) $fenster['bemerkung']),
            'gesperrt' => (bool) $fenster['gesperrt'],
        ];
    }

    private function formular(?\DateTimeInterface $t): ?string
    {
        return ZeitAnzeige::ortszeit($t)?->format('Y-m-d\TH:i');
    }

    /** Verlaufswert fuers Auge: Zeiten in Ortszeit, Pause in Minuten, sonst unveraendert. */
    private function wert(string $feld, mixed $roh): ?string
    {
        if ($roh === null || $roh === '') {
            return null;
        }
        if ($feld === 'kommen_at' || $feld === 'gehen_at' || $feld === 'gesperrt_at') {
            try {
                // Gespeichert als Y-m-d H:i:s in der Standard-Zeitzone.
                return ZeitAnzeige::ortszeit(\Carbon\CarbonImmutable::parse((string) $roh, date_default_timezone_get()))->format('d.m.Y H:i');
            } catch (\Throwable) {
                return (string) $roh;
            }
        }
        if ($feld === 'pause_minuten') {
            return $roh . ' min';
        }

        return (string) $roh;
    }

    private function teamId(): ?int
    {
        $t = $this->schalter->teamId();

        return $t > 0 ? $t : null;
    }
}
