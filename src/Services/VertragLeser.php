<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\VertragsAnzeige;
use Platform\Recruiting\Support\VertragsHerkunft;

/**
 * Die Vertraege EINES Menschen im Portal (Spec Vertrag aus der Akte §2.5):
 * alle Anstellungen des Personen-Umfangs, nicht storniert. Fremde IDs aus
 * der Anfrage laufen ueber signierLink() und damit ueber dieselbe Menge —
 * es gibt keinen zweiten Weg zu einem Vertrag (Muster DokumentLeser).
 */
class VertragLeser
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver())
    {
    }

    /** @return Collection<int, RecContract> */
    public function vertraege(RecEmployee $employee): Collection
    {
        return RecContract::query()
            ->whereIn('rec_employee_id', $this->scope->forEmployee($employee)['ids'])
            ->where('status', '!=', 'cancelled')
            // publicFormLink vorab: dokumente() und signierLink() fragen je
            // Zeile nach dem Link — sonst eine Abfrage pro Vertrag (N+1).
            ->with(['contractTemplate', 'publicFormLink'])
            ->orderBy('id')
            ->get();
    }

    public function anstellungsAnzahl(RecEmployee $employee): int
    {
        return count($this->scope->forEmployee($employee)['ids']);
    }

    /**
     * Versendete Vertraege AUS DER AKTE (VertragsHerkunft::ausAkte) als
     * offene Punkte. Mit $ohneFrischVersandteTage
     * (Einsatz-Pruefung) fallen Vertraege weg, die in den letzten N Tagen
     * versandt wurden — sie hatten ihren eigenen Hinweis (Muster
     * DokumentLeser::offenePunkte, gemessen an sent_at).
     *
     * @return list<array{code:string, label:string, status:string, ko:bool, punkt:string, text:string}>
     */
    public function offenePunkte(RecEmployee $employee, ?int $ohneFrischVersandteTage = null, ?string $heute = null): array
    {
        $grenze = $ohneFrischVersandteTage === null
            ? null
            : \Carbon\Carbon::parse($heute ?? now()->toDateString())->subDays($ohneFrischVersandteTage);

        $punkte = [];
        foreach ($this->vertraege($employee) as $v) {
            // Nur Vertraege aus der Akte (Schlussreview I3). Alte offene
            // Bewerbungs-Vertraege bleiben, wie vor dem Paket, nur in der
            // Liste mit Unterschreiben-Link — sonst aenderten sie Portal-
            // Zaehler und Trigger-Signatur Hunderter Menschen auf einen Schlag.
            if ($v->status !== 'sent' || !VertragsHerkunft::ausAkte($v)) {
                continue;
            }
            if ($grenze !== null && $v->sent_at !== null && $v->sent_at->greaterThan($grenze)) {
                continue;
            }
            $firma = trim((string) $v->contractTemplate?->company);
            $punkte[] = [
                'code'   => 'vertrag:' . $v->id,
                'label'  => self::anzeigename($v->contractTemplate?->code, $v->contractTemplate?->name) . ($firma !== '' ? ' · ' . $firma : ''),
                'status' => 'offen',
                'ko'     => false,
                'punkt'  => 'crit',
                'text'   => 'Lesen und unterschreiben',
            ];
        }

        return $punkte;
    }

    /** Signierlink eines versendeten Vertrags DIESER Person — sonst null. */
    public function signierLink(RecEmployee $employee, int $vertragId): ?string
    {
        $v = $this->vertraege($employee)->firstWhere('id', $vertragId);
        if ($v === null || $v->status !== 'sent') {
            return null;
        }

        return route('recruiting.public.contract-signing', ['token' => $v->getOrCreatePublicFormLink()->token]);
    }

    /** Delegiert an die eine Namensstelle (VertragsAnzeige). */
    public static function anzeigename(?string $code, ?string $name): string
    {
        return VertragsAnzeige::name($code, $name);
    }
}
