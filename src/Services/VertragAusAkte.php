<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;
use Platform\Recruiting\Support\VertragsDeckung;
use Platform\Recruiting\Support\VertragsHerkunft;
use Platform\Recruiting\Support\VertragsVorbelegung;
use Platform\Recruiting\Support\YmdDate;

/**
 * Arbeitsvertrag aus der Mitarbeiterakte (Spec 2026-10-09 §2.2).
 *
 * EIN Vertrag, kein Massenlauf — Eloquent ist hier richtig. Der ZAS-Marker
 * auf contract_signed_at kommt erst beim Unterschreiben (RecContract::saved).
 *
 * Transaktion fuer Doppel-Waechter (unter Sperre), Anlage, Felder, Inhalt,
 * Link und Status; der WhatsApp-Hinweis laeuft DANACH und darf scheitern —
 * der Vertrag liegt im Portal, der Status steht in notes ("Hinweis: …", keine neue Spalte).
 *
 * Nur Arbeitsvertraege (AV-*), kein IFSG/AT-* (Spec §1 "nicht drin").
 */
final class VertragAusAkte
{
    private ?string $letzterHinweis = null;

    public function __construct(private readonly ?VertragHinweisSender $hinweis = null)
    {
    }

    public function erstellen(RecEmployee $anstellung, RecContractTemplate $vorlage, VertragsAngaben $angaben, ?int $userId): RecContract
    {
        $this->letzterHinweis = null;
        $this->pruefen($anstellung, $vorlage, $angaben);
        $daten = RecContract::resolveContractDates($angaben->beginn, $angaben->ende);

        $vertrag = DB::transaction(function () use ($anstellung, $vorlage, $angaben, $userId, $daten) {
            // Sperre auf die Anstellung, DANN der Doppel-Waechter: zwei schnelle
            // Klicks warten aufeinander, der zweite sieht den ersten Vertrag.
            // (SQLite ignoriert lockForUpdate harmlos.)
            RecEmployee::query()->whereKey($anstellung->id)->lockForUpdate()->first();
            $this->doppelabdeckungPruefen($anstellung, (string) $daten['vertragsbeginn'], $daten['vertragsende']);

            $vertrag = RecContract::create([
                'rec_applicant_id'         => $anstellung->rec_applicant_id,
                'rec_employee_id'          => $anstellung->id,
                'rec_contract_template_id' => $vorlage->id,
                'team_id'                  => $anstellung->team_id,
                'status'                   => 'pending',
                'personalized_content'     => '',
                'created_by_user_id'       => $userId,
                'notes'                    => 'Aus der Mitarbeiterakte erstellt.',
            ]);

            $vertrag->setExtraField('vertragsbeginn', $daten['vertragsbeginn']);
            $vertrag->setExtraField('vertragsende', $daten['vertragsende']);
            // Herkunftsmerker + Zuschlag am Vertrag (die eine Stelle:
            // VertragsHerkunft). Fehlt die Felddefinition, rollt die
            // Transaktion zurueck statt einen unmarkierten Vertrag zu senden.
            VertragsHerkunft::markieren($vertrag, $angaben->zuschlag);

            // rec_applicants.zuschlag bleibt BEWUSST unberuehrt (Ruling Task 4):
            // Quelle ist das Vertrags-Extrafeld. Sonst aenderte sich der
            // ZAS-Exportwert vor der Unterschrift ohne Marker/Lohn-Eintrag, und
            // eine RG-Bewerbung bekaeme den MA-Zuschlag.

            $vertrag->personalized_content = $vorlage->personalizeFuerAnstellung($anstellung->fresh() ?? $anstellung, $vertrag);
            $vertrag->getOrCreatePublicFormLink();
            $vertrag->status = 'sent';
            $vertrag->sent_at = now();
            $vertrag->save();

            return $vertrag;
        });

        $this->letzterHinweis = $this->hinweisSenden($anstellung);
        $vertrag->notes = trim((string) $vertrag->notes . "\nHinweis: " . $this->letzterHinweis);
        $vertrag->save();

        return $vertrag;
    }

    public function letzterHinweis(): ?string
    {
        return $this->letzterHinweis;
    }

    private function pruefen(RecEmployee $anstellung, RecContractTemplate $vorlage, VertragsAngaben $angaben): void
    {
        if (!$anstellung->is_active) {
            throw new \DomainException('Die Akte ist deaktiviert — es entsteht kein neuer Vertrag.');
        }
        if ((int) $vorlage->team_id !== (int) $anstellung->team_id || !$vorlage->is_active
            || $vorlage->type !== RecContractTemplate::TYPE_CONTRACT) {
            throw new \DomainException('Diese Vorlage steht für diese Akte nicht zur Verfügung.');
        }
        if (!str_starts_with((string) $vorlage->code, 'AV-')) {
            throw new \DomainException('Aus der Akte entstehen nur Arbeitsverträge (Vorlagen-Code AV-…).');
        }
        if (!$vorlage->giltFuerAnstellung($anstellung)) {
            throw new \DomainException(sprintf(
                'Die Vorlage %s gehört zur Gesellschaft %s, diese Akte zu %s.',
                $vorlage->code,
                $vorlage->company,
                trim((string) $anstellung->company) !== '' ? $anstellung->company : '—'
            ));
        }
        if (!YmdDate::isValid($angaben->beginn)) {
            throw new \DomainException('Bitte einen gültigen Vertragsbeginn eintragen.');
        }
        if ($angaben->ende !== null && (!YmdDate::isValid($angaben->ende) || $angaben->ende < $angaben->beginn)) {
            throw new \DomainException('Das Vertragsende muss ein Datum am oder nach dem Beginn sein.');
        }
        if (!is_finite($angaben->zuschlag) || $angaben->zuschlag < 0 || $angaben->zuschlag >= 1000) {
            throw new \DomainException('Der Zuschlag muss zwischen 0 und 999,99 liegen.');
        }
    }

    /** Laeuft in der Transaktion nach der Sperre — siehe erstellen(). */
    private function doppelabdeckungPruefen(RecEmployee $anstellung, string $beginn, ?string $ende): void
    {
        $konflikt = VertragsDeckung::ueberschneidung(VertragsZeilen::fuerAnstellung((int) $anstellung->id), $beginn, $ende);
        if ($konflikt !== null) {
            throw new \DomainException(sprintf(
                'Für diesen Zeitraum gibt es bereits einen Arbeitsvertrag (#%d, %s) — erst stornieren oder neu ausstellen.',
                $konflikt['id'],
                $konflikt['ende'] !== null ? 'bis ' . VertragsVorbelegung::deutsch($konflikt['ende']) : 'unbefristet'
            ));
        }
    }

    private function hinweisSenden(RecEmployee $anstellung): string
    {
        try {
            return ($this->hinweis ?? app(VertragHinweisSender::class))->sende($anstellung->fresh() ?? $anstellung);
        } catch (\Throwable $e) {
            Log::warning('[VertragAusAkte] Hinweis nicht versendet', [
                'rec_employee_id' => $anstellung->id,
                'error'           => $e->getMessage(),
            ]);

            return DokumentHinweisSender::STATUS_FAILED;
        }
    }
}
