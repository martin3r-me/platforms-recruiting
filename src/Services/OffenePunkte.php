<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\Arbeitserlaubnis;
use Platform\Recruiting\Support\EinsatzBezug;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Was ist offen, und wofuer.
 *
 * BERECHNET, NIE GESPEICHERT (Spec 3.2). Nachweise laufen ab, Einsaetze
 * kommen dazu — eine gespeicherte Liste waere nach einer Nacht falsch.
 *
 * Der Bezug ist der naechste KOMMENDE Auftrag. Er macht aus „Ausweis fehlt"
 * ein „Ausweis fehlt — gebraucht fuer deinen Einsatz am 12.10. (Projekt:
 * Messe Duesseldorf)". Projiziert wird NUR die Projektbezeichnung
 * (`rec_dispo_events.name`, siehe `event` unten) — nicht der Ort
 * (`rec_dispo_events.ort`); das Portal (Aufgabe 11) kann aus dieser
 * Rueckgabe also einen Satz mit Datum und Projektname bauen, keinen mit
 * Ort. Ohne kommenden Einsatz bleibt die Liste trotzdem richtig, nur ohne
 * Anlass. (Korrektur Review Runde 1, F2: die urspruengliche Illustration
 * aus dem Brief nannte woertlich einen Ort, den die Rueckgabe nie trug.)
 *
 * ET-8 (Review vor Task 8): der Rueckgabetyp verspricht fuer `einsatz` genau
 * {datum, taetigkeit, event}. EinsatzBezug::naechster() reicht aber die
 * ganze hereingereichte Zeile durch, also auch status_id — das Feld wird
 * hier gebraucht, damit naechster() ueberhaupt auf AUFTRAG filtern kann
 * (ET-13), aber es ist ein internes Filterkriterium von EinsatzBezug, kein
 * Teil des oeffentlichen Vertrags dieser Klasse. Entscheidung: auf die drei
 * versprochenen Felder PROJIZIEREN, status_id nicht nach aussen reichen.
 * Begruendung: das Portal (Aufgabe 11) liest genau diese Struktur; ein
 * durchgereichtes status_id waere eine zweite, stillschweigende Tuer zum
 * selben Filter-Wissen, das EinsatzBezug schon kapselt — faende das Portal
 * sie, koennte es nach status_id filtern und die Regel ein zweites Mal
 * (und womoeglich abweichend) implementieren. Gedeckt durch
 * test_der_einsatz_bezug_traegt_nur_die_drei_versprochenen_felder.
 *
 * ET-17 (Review Runde 1): der Personen-Umfang nutzt bewusst nur
 * `PersonScopeResolver::forEmployee()['ids']`, nie `['abweichend']` — genau
 * wie `ProofReader`. Eine abweichende Behandlung von Nachweisen und
 * Einsaetzen wuerde die beiden darueber uneins machen lassen, WER der
 * Mensch ist, und das waere schlimmer als die Luecke darunter. BEKANNTE
 * GRENZE (unveraendert durch diese Entscheidung): ohne `rec_person_id`
 * verlangt Zweig 2 des `PersonScopeResolver` zusaetzlich dieselbe
 * Handynummer; weicht sie ab, landet die Schwester-Anstellung in
 * `abweichend` und ihr Einsatz verschwindet aus dieser Klasse HERAUS
 * SPURLOS — kein Hinweis, keine Fehlermeldung. Der Heilweg ist das Setzen
 * von `rec_person_id` (Backfill/PersonLinker); das Sichtbarmachen dieser
 * Faelle ist NICHT Aufgabe dieser Klasse, sondern Aufgabe 10.
 */
class OffenePunkte
{
    /**
     * Die beiden Parameter sind KEINE Einspritzpunkte im ueblichen Sinn:
     * `ProofReader` und `PersonScopeResolver` sind `final`, es gibt keine
     * Schnittstelle dahinter, und `$this->scope` wird an `ProofReader` auch
     * nicht durchgereicht (der baut sich intern seinen eigenen). Nur
     * gleichwertige Instanzen sind ueberhaupt uebergebbar. Beibehalten,
     * weil `ProofReader` selbst exakt dieses Muster traegt (default-
     * instanziierter Konstruktor-Parameter) — eine abweichende Form hier
     * waere die Ausnahme im Modul, nicht die Konsistenz.
     */
    public function __construct(
        private readonly ProofReader $nachweise = new ProofReader(),
        private readonly PersonScopeResolver $scope = new PersonScopeResolver(),
        private readonly DokumentLeser $dokumente = new DokumentLeser(),
    ) {
    }

    /**
     * @return array{punkte: list<array{code:string, label:string, status:string, ko:bool}>, einsatz: ?array{datum:string, taetigkeit:?string, event:?string}, gesperrt: bool}
     */
    public function fuer(RecEmployee $employee, ?string $heute = null): array
    {
        return $this->stand($employee, $heute, null);
    }

    /**
     * Dieselbe Form fuer die Einsatz-Pruefung — ohne Dokumente, die in den
     * letzten PAUSE_TAGE ihre eigene WhatsApp bekommen haben (Spec Dokumente,
     * Nachtrag 09.10.2026). Nachweise und Pflichtangaben unveraendert.
     */
    public function fuerTrigger(RecEmployee $employee, ?string $heute = null): array
    {
        return $this->stand($employee, $heute, EinsatzBezug::PAUSE_TAGE);
    }

    private function stand(RecEmployee $employee, ?string $heute, ?int $ohneFrischGemeldeteTage): array
    {
        $heute = $heute ?? now()->toDateString();
        $checkliste = $this->nachweise->checklist($employee, $heute);

        $punkte = [];
        foreach ($checkliste as $zeile) {
            if (($zeile['offen'] ?? false) !== true) {
                continue;
            }
            $punkte[] = [
                'code'   => $zeile['code'],
                'label'  => $zeile['label'],
                'status' => $zeile['status'],
                'ko'     => ProofTypes::istKo($zeile['code']),
            ];
        }

        // Zweite Quelle (Spec Dokumente 2026-10-08, §5.2): offene Dokumente
        // sind Punkte mit Code 'dokument:<id>', ko=false, Label und Satz
        // bringen sie selbst mit — ProofTypes kennt sie nicht und wird fuer
        // sie nicht gefragt. gesperrt bleibt allein Sache der Arbeitserlaubnis.
        $punkte = array_merge($punkte, $this->dokumente->offenePunkte($employee, $ohneFrischGemeldeteTage, $heute));

        return [
            'punkte'   => $punkte,
            'einsatz'  => $this->naechsterEinsatz($employee, $heute),
            'gesperrt' => Arbeitserlaubnis::istGesperrt($checkliste),
        ];
    }

    /** @return array{datum:string, taetigkeit:?string, event:?string}|null */
    private function naechsterEinsatz(RecEmployee $employee, string $heute): ?array
    {
        $einsaetze = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $this->scope->forEmployee($employee)['ids'])
            ->whereNull('missing_since')
            // ->with('event'): ohne das waere jeder $a->event?->name unten
            // eine eigene Abfrage (N+1) — bei vielen Einbuchungen sichtbar.
            ->with('event')
            ->get(['datum', 'status_id', 'taetigkeit', 'rec_dispo_event_id'])
            ->map(fn (RecDispoAssignment $a) => [
                // Explizit formatiert, nicht (string)-gecastet: der Cast
                // 'date:Y-m-d' auf RecDispoAssignment::$casts greift nur bei
                // der Serialisierung (toArray/toJson), nicht bei (string) auf
                // dem Carbon-Objekt — das liefert das volle
                // "Y-m-d H:i:s" und haette hier ein stilles Format-Leck
                // erzeugt (so auch ueberall sonst im Modul gehandhabt, z. B.
                // DispoEmployeeAssignments::split(), Zeile 35).
                'datum'      => $a->datum?->format('Y-m-d') ?? '',
                'status_id'  => (int) $a->status_id,
                'taetigkeit' => $a->taetigkeit,
                'event'      => $a->event?->name,
            ])
            ->all();

        $naechster = EinsatzBezug::naechster($einsaetze, $heute);

        if ($naechster === null) {
            return null;
        }

        // Projektion auf den oeffentlichen Vertrag (ET-8, siehe Docblock der
        // Klasse) — status_id steckt in $naechster, geht aber bewusst nicht
        // nach aussen.
        return [
            'datum'      => $naechster['datum'],
            'taetigkeit' => $naechster['taetigkeit'],
            'event'      => $naechster['event'],
        ];
    }
}
