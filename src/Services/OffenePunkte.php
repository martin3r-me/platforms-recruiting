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
 * ein „Ausweis fehlt — gebraucht fuer deinen Einsatz am 12.10. in
 * Duesseldorf". Ohne kommenden Einsatz bleibt die Liste trotzdem richtig, nur
 * ohne Anlass.
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
 */
class OffenePunkte
{
    public function __construct(
        private readonly ProofReader $nachweise = new ProofReader(),
        private readonly PersonScopeResolver $scope = new PersonScopeResolver(),
    ) {
    }

    /**
     * @return array{punkte: list<array{code:string, label:string, status:string, ko:bool}>, einsatz: ?array{datum:string, taetigkeit:?string, event:?string}, gesperrt: bool}
     */
    public function fuer(RecEmployee $employee, ?string $heute = null): array
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
            ->get(['datum', 'status_id', 'taetigkeit', 'rec_dispo_event_id'])
            ->map(fn (RecDispoAssignment $a) => [
                // Explizit formatiert, nicht (string)-gecastet: der Cast
                // 'date:Y-m-d' auf RecDispoAssignment::$casts greift nur bei
                // der Serialisierung (toArray/toJson), nicht bei (string) auf
                // dem Carbon-Objekt — das liefert das volle
                // "Y-m-d H:i:s" und haette hier ein stilles Format-Leck
                // erzeugt (so auch ueberall sonst im Modul gehandhabt, z. B.
                // DispoEmployeeAssignments::naechsteEinsaetze()).
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
