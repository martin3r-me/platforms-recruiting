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
    ];

    private const ARTEN = [
        Stempeluhr::ART_SELBST => 'selbst',
        Stempeluhr::ART_STELLVERTRETEND => 'stellvertretend',
        Stempeluhr::ART_KORREKTUR => 'Korrektur',
        Stempeluhr::ART_SPERRE => 'Sperre',
    ];

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
     * Verlauf neueste zuerst — leer bei $mitVerlauf = false (Sammelkonten „Nur Veranstaltungen“: dort stehen
     * Namen, Gruendezeilen und Bemerkungs-Aenderungen der Disponenten). Ohne Person: leeres Geruest (die Komponente oeffnet so eine Zeile nicht).
     *
     * @return array{name:string,tag:string,soll:string,kommen:?string,gehen:?string,pause:?int,regelpause:int,bemerkung:string,zustand:string,gesperrt:bool,verlauf:list<array{wann:string,wer:string,feld:string,alt:?string,neu:?string,grund:?string,art:string}>}
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
                $verlauf[] = [
                    'wann' => ZeitAnzeige::ortszeit($v->created_at)?->format('d.m. H:i') ?? '',
                    'wer' => (string) $v->akteur_name,
                    'feld' => self::FELDER[$v->feld] ?? (string) $v->feld,
                    'alt' => $this->wert((string) $v->feld, $v->alt),
                    'neu' => $this->wert((string) $v->feld, $v->neu),
                    'grund' => $v->grund,
                    'art' => self::ARTEN[$v->art] ?? (string) $v->art,
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
