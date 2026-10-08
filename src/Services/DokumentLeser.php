<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;

/**
 * Die Dokumente EINES Menschen (Spec §5.1): alle Zustellungen, deren
 * Anstellung im Personen-Scope liegt, ohne zurueckgezogene, ohne geloeschte
 * Dokumente. Fremde IDs aus der Anfrage laufen immer ueber empfaenger() und
 * damit ueber dieselbe Menge — es gibt keinen zweiten Weg zu einer Zeile.
 */
class DokumentLeser
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver())
    {
    }

    /**
     * @return list<array{recipient_id:int, uuid:string, title:string, category:string, category_label:string, action:string, status:string, status_label:string, offen:bool, first_viewed_at:?string, acknowledged_at:?string, signed_at:?string, provided_at:string}>
     */
    public function fuerMitarbeiter(RecEmployee $employee): array
    {
        return $this->sichtbare($employee)
            ->map(fn (RecDocumentRecipient $z) => $this->zeile($z))
            ->all();
    }

    public function empfaenger(RecEmployee $employee, int $recipientId): ?RecDocumentRecipient
    {
        return $this->sichtbare($employee)->firstWhere('id', $recipientId);
    }

    /**
     * Offene Dokumente als Punkte. Mit $ohneFrischGemeldeteTage (die
     * Einsatz-Pruefung) fallen Zustellungen weg, die in den letzten N Tagen
     * ihre eigene WhatsApp bekommen haben — sonst kaeme eine Stunde nach
     * "im Portal liegt etwas" noch "fuer deinen Einsatz ist ein Punkt offen"
     * zum selben Dokument. Fehlversuche (notify_error, kein notified_at)
     * bleiben drin: dort ist die Einsatz-Pruefung der zweite Fang.
     *
     * @return list<array{code:string, label:string, status:string, ko:bool, punkt:string, text:string}>
     */
    public function offenePunkte(RecEmployee $employee, ?int $ohneFrischGemeldeteTage = null, ?string $heute = null): array
    {
        $grenze = $ohneFrischGemeldeteTage === null
            ? null
            : \Carbon\Carbon::parse($heute ?? now()->toDateString())->subDays($ohneFrischGemeldeteTage);
        $punkte = [];
        // Punkte aeltestes zuerst (die Liste in fuerMitarbeiter() ist neueste zuerst).
        foreach ($this->sichtbare($employee)->reverse() as $z) {
            $aktion = (string) $z->document->action;
            if (!DokumentKategorie::brauchtHandlung($aktion) || DokumentStatus::istErledigt($z->zeitstempel(), $aktion)) {
                continue;
            }
            if ($grenze !== null && $z->notified_at !== null && $z->notified_at->greaterThan($grenze)) {
                continue;
            }
            $punkte[] = [
                'code'   => 'dokument:' . $z->id,
                'label'  => (string) $z->document->title,
                'status' => DokumentStatus::fuer($z->zeitstempel(), $aktion),
                'ko'     => false,
                'punkt'  => 'crit',
                'text'   => $aktion === DokumentKategorie::AKTION_SIGN ? 'Lesen und unterschreiben' : 'Lesen und bestätigen',
            ];
        }

        return $punkte;
    }

    /** @return \Illuminate\Support\Collection<int, RecDocumentRecipient> neueste zuerst, Dokument geladen */
    private function sichtbare(RecEmployee $employee)
    {
        $ids = $this->scope->forEmployee($employee)['ids'];

        return RecDocumentRecipient::query()
            ->whereIn('rec_employee_id', $ids)
            ->whereNull('withdrawn_at')
            ->whereHas('document')            // SoftDeletes: geloeschte Dokumente fallen hier raus
            ->with('document')
            ->orderByDesc('id')
            ->get()
            ->values();
    }

    private function zeile(RecDocumentRecipient $z): array
    {
        $aktion = (string) $z->document->action;
        $status = DokumentStatus::fuer($z->zeitstempel(), $aktion);

        return [
            'recipient_id'    => (int) $z->id,
            'uuid'            => (string) $z->uuid,
            'title'           => (string) $z->document->title,
            'category'        => (string) $z->document->category,
            'category_label'  => DokumentKategorie::label((string) $z->document->category),
            'action'          => $aktion,
            'status'          => $status,
            'status_label'    => DokumentStatus::label($status),
            'offen'           => DokumentKategorie::brauchtHandlung($aktion) && !DokumentStatus::istErledigt($z->zeitstempel(), $aktion),
            'first_viewed_at' => $z->first_viewed_at?->format('Y-m-d H:i:s'),
            'acknowledged_at' => $z->acknowledged_at?->format('Y-m-d H:i:s'),
            'signed_at'       => $z->signed_at?->format('Y-m-d H:i:s'),
            'provided_at'     => $z->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }
}
