<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;
use Platform\Recruiting\Support\InfoText;

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
     * @return list<array{recipient_id:int, uuid:string, title:string, category:string, category_label:string, action:string, status:string, status_label:string, offen:bool, first_viewed_at:?string, acknowledged_at:?string, signed_at:?string, provided_at:string, is_info:bool, content_html:string, gesehen:bool}>
     */
    public function fuerMitarbeiter(RecEmployee $employee): array
    {
        return $this->sichtbare($employee)
            ->map(fn (RecDocumentRecipient $z) => $this->zeile($z))
            ->all();
    }

    public function empfaenger(RecEmployee $employee, int $recipientId): ?RecDocumentRecipient
    {
        // Ueber ALLE Zustellungen der Person, auch eine beim Anzeigen
        // verdeckte Doppelte: ein alter WhatsApp-Link darauf fuehrt weiter ans Dokument.
        return $this->alleSichtbaren($employee)->firstWhere('id', $recipientId);
    }

    /**
     * Ungesehene Infos fuer den Kasten "Neu fuer dich" (Spec 2026-10-16
     * §5.1), aelteste zuerst, als Zeilen wie fuerMitarbeiter().
     *
     * @return list<array>
     */
    public function ungeseheneInfos(RecEmployee $employee): array
    {
        return $this->kastenZustellungen($employee)
            ->map(fn (RecDocumentRecipient $z) => $this->zeile($z))
            ->all();
    }

    /**
     * Die Zustellungen fuer den Kasten beim Seitenaufbau: ungesehene Infos.
     *
     * @return \Illuminate\Support\Collection<int, RecDocumentRecipient> aelteste zuerst
     */
    public function kastenZustellungen(RecEmployee $employee)
    {
        return $this->sichtbare($employee)
            ->filter(fn (RecDocumentRecipient $z) => DokumentKategorie::istText((string) $z->document->category)
                && $z->first_viewed_at === null)
            ->reverse()
            ->values();
    }

    /**
     * Infos zu gegebenen Empfaenger-IDs (die des Kastens), egal ob gesehen.
     * Nur ein Filter auf sichtbare(): eine fremde, zurueckgezogene oder
     * geloeschte ID findet hier nichts.
     *
     * @param  list<int> $ids
     * @return \Illuminate\Support\Collection<int, RecDocumentRecipient> aelteste zuerst
     */
    public function infoZustellungen(RecEmployee $employee, array $ids)
    {
        $ids = array_map('intval', $ids);

        return $this->sichtbare($employee)
            ->filter(fn (RecDocumentRecipient $z) => DokumentKategorie::istText((string) $z->document->category)
                && in_array((int) $z->id, $ids, true))
            ->reverse()
            ->values();
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
        $alle = $this->alleSichtbaren($employee);
        // Frisch gemeldet zaehlt ueber ALLE Zustellungen desselben Dokuments:
        // hat die verdeckte Doppelte die WhatsApp bekommen, gilt es als gemeldet (Review 29.10.).
        $gemeldet = [];
        foreach ($alle as $z) {
            if ($z->notified_at !== null) {
                $doc = (int) $z->rec_document_id;
                $gemeldet[$doc] = isset($gemeldet[$doc]) && $gemeldet[$doc]->greaterThan($z->notified_at) ? $gemeldet[$doc] : $z->notified_at;
            }
        }
        // Punkte aeltestes zuerst (die Liste in fuerMitarbeiter() ist neueste zuerst).
        foreach ($this->sichtbare($employee, $alle)->reverse() as $z) {
            $aktion = (string) $z->document->action;
            if (!DokumentKategorie::brauchtHandlung($aktion) || DokumentStatus::istErledigt($z->zeitstempel(), $aktion)) {
                continue;
            }
            $zuletzt = $gemeldet[(int) $z->rec_document_id] ?? null;
            if ($grenze !== null && $zuletzt !== null && $zuletzt->greaterThan($grenze)) {
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

    /**
     * Wie alleSichtbaren(), aber jedes Dokument EINMAL je Mensch (29.10.2026):
     * liegt dasselbe Dokument an beiden Anstellungen (Altbestand vor der
     * Entdopplung, zusammengefuehrte Personen), zeigt das Portal die weiter
     * fortgeschrittene Zustellung — unterschrieben vor bestaetigt vor
     * gesehen vor offen, bei Gleichstand die kleinere id.
     *
     * @return \Illuminate\Support\Collection<int, RecDocumentRecipient> neueste zuerst, Dokument geladen
     */
    private function sichtbare(RecEmployee $employee, ?\Illuminate\Support\Collection $alle = null)
    {
        $alle ??= $this->alleSichtbaren($employee);
        $gewinner = [];
        foreach ($alle as $z) {
            $doc = (int) $z->rec_document_id;
            $bisher = $gewinner[$doc] ?? null;
            if ($bisher === null || self::fortschritt($z) > self::fortschritt($bisher)
                || (self::fortschritt($z) === self::fortschritt($bisher) && $z->id < $bisher->id)) {
                $gewinner[$doc] = $z;
            }
        }
        $ids = array_map(fn (RecDocumentRecipient $z) => (int) $z->id, $gewinner);

        return $alle->filter(fn (RecDocumentRecipient $z) => in_array((int) $z->id, $ids, true))->values();
    }

    private static function fortschritt(RecDocumentRecipient $z): int
    {
        return match (true) {
            $z->signed_at !== null       => 3,
            $z->acknowledged_at !== null => 2,
            $z->first_viewed_at !== null => 1,
            default                      => 0,
        };
    }

    /** @return \Illuminate\Support\Collection<int, RecDocumentRecipient> neueste zuerst, Dokument geladen */
    private function alleSichtbaren(RecEmployee $employee)
    {
        $ids = $this->scope->forEmployee($employee)['ids'];

        return RecDocumentRecipient::query()
            ->ohneUnterschriftsbild()           // Listen tragen nie das Unterschriftsbild
            ->whereIn('rec_employee_id', $ids)
            ->whereNull('withdrawn_at')
            ->whereHas('document')            // SoftDeletes: geloeschte Dokumente fallen hier raus
            ->with('document')
            ->orderByDesc('id')
            ->get()
            ->values();
    }

    /** Eine Zustellung als Anzeige-Zeile. Oeffentlich, damit das Portal die Kasten-Zeilen aus denselben Modellen baut, die es stempelt. */
    public function zeile(RecDocumentRecipient $z): array
    {
        $istInfo = DokumentKategorie::istText((string) $z->document->category);
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
            // Infos (Spec 2026-10-16 §5.3): Text statt PDF, schon sicher gerendert.
            'is_info'         => $istInfo,
            'content_html'    => $istInfo ? InfoText::html((string) $z->document->content_markdown) : '',
            'gesehen'         => $z->first_viewed_at !== null,
        ];
    }
}
