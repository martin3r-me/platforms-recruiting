<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;

/**
 * Stellt einen bereits unterschriebenen Arbeitsvertrag neu aus.
 *
 * Anlass aus der Praxis: der Zuschlag war beim Versand falsch angesetzt.
 * Ein signierter Vertrag wird dafuer NICHT umgeschrieben — genau das tut
 * der "Felder"-Button in der Bewerber-Akte, und genau das verfaelscht das
 * Archivstueck (Datum springt auf heute, Lohnwerte auf aktuelle Werte).
 * Stattdessen: neuer Vertrag aus derselben Vorlage mit dem neuen Zuschlag,
 * der alte bleibt `completed` mit Unterschrift und PDF und traegt einen
 * Verweis auf seinen Nachfolger.
 *
 * WARUM DAS OHNE VORLAGEN-AKROBATIK GEHT: {{zuschlag}} ist in der
 * Live-Vorlage AV-default auf `applicant.zuschlag` gemappt und wird bei
 * jedem personalizeContent() frisch aufgeloest. Neuer Zuschlag am Bewerber
 * plus frischer Vertrag aus derselben Vorlage ergibt den neuen Text. Die
 * alten Varianten AV-010..AV-260 mit literal eingebackenem Zuschlag sind
 * Altbestand; wer einen davon ersetzt, bekommt einen Nachfolger aus
 * derselben Variante — der Zuschlag im Text aendert sich dann NICHT, weil
 * dort kein Platzhalter steht. Deshalb prueft reissue() das und verweigert
 * die Arbeit, statt still ein Dokument mit dem alten Betrag auszustellen.
 *
 * KEIN IFSG, KEIN ZUSATZVERTRAG: dieser Weg haengt bewusst nichts an. Der
 * Mitarbeiter hat seine Infektionsschutz-Belehrung unterschrieben; ein
 * zweites Exemplar waere sinnlose Arbeit fuer ihn.
 */
class ReissueContractService
{
    /** Vertrag war falsch und ist nie wirksam geworden — nichts zu melden. */
    public const REASON_CORRECTION = 'correction';

    /** Aenderung im laufenden Verhaeltnis — das Lohnbuero muss es erfahren. */
    public const REASON_RAISE = 'raise';

    /**
     * @param  RecContract  $old  Der unterschriebene Vertrag, der ersetzt wird.
     * @param  float  $newZuschlag  Neuer Zuschlag in Euro pro Stunde.
     * @param  string  $reason  self::REASON_CORRECTION | self::REASON_RAISE
     * @param  ?string  $vertragsbeginn  Y-m-d; leer = Wert des alten Vertrags uebernehmen.
     * @param  ?string  $hrNote  Freitext von HR, wandert in die Notiz beider Vertraege.
     *
     * @return array{contract: RecContract, payroll_reported: bool}
     *
     * @throws \RuntimeException wenn der Vertrag nicht ersetzbar ist
     */
    public function reissue(
        RecContract $old,
        float $newZuschlag,
        string $reason,
        ?string $vertragsbeginn = null,
        ?string $hrNote = null,
        ?int $userId = null,
    ): array {
        if (!in_array($reason, [self::REASON_CORRECTION, self::REASON_RAISE], true)) {
            throw new \RuntimeException("Unbekannter Grund '{$reason}'.");
        }

        if ($old->status !== 'completed' || $old->signed_at === null) {
            throw new \RuntimeException(
                'Nur ein unterschriebener Vertrag wird ersetzt. Ein noch offener Vertrag '
                . 'wird ueber "Vertrag zuweisen" neu ausgestellt — dort wird der offene storniert.'
            );
        }

        if ($old->isSuperseded()) {
            throw new \RuntimeException(
                "Vertrag #{$old->id} ist bereits durch #{$old->superseded_by_contract_id} ersetzt."
            );
        }

        $template = $old->contractTemplate;
        if (!$template || !$template->is_active) {
            throw new \RuntimeException('Die Vorlage des Vertrags fehlt oder ist inaktiv.');
        }

        $code = (string) $template->code;
        if (!str_starts_with($code, 'AV-') && $code !== 'AV') {
            throw new \RuntimeException(
                "Ersetzen ist fuer Arbeitsvertraege gedacht — Vorlage '{$code}' ist keiner."
            );
        }

        $applicant = $old->applicant;
        if (!$applicant) {
            throw new \RuntimeException('Zum Vertrag gehoert kein Bewerberdatensatz.');
        }

        // Variante mit literal eingebackenem Zuschlag: der Nachfolger waere
        // textgleich mit dem Vorgaenger. Lieber laut abbrechen als ein
        // Dokument ausstellen, das den alten Betrag nennt.
        $zuschlagSource = $this->zuschlagSource($template);
        if ($zuschlagSource === null) {
            throw new \RuntimeException(
                "Die Vorlage '{$code}' loest {{zuschlag}} nicht aus dem Bewerberfeld auf "
                . '(Altbestands-Variante mit festem Betrag im Text). Bitte einen Vertrag '
                . 'aus AV-default zuweisen, statt diese Variante zu ersetzen.'
            );
        }

        $oldZuschlag = $applicant->zuschlag !== null ? (float) $applicant->zuschlag : null;
        $beginn = $vertragsbeginn !== null && $vertragsbeginn !== ''
            ? $vertragsbeginn
            : $old->getExtraField('vertragsbeginn');
        $dates = RecContract::resolveContractDates(
            is_string($beginn) ? $beginn : null,
            null,
        );

        return DB::transaction(function () use (
            $old, $applicant, $template, $newZuschlag, $oldZuschlag, $reason, $dates, $hrNote,
            $userId, $zuschlagSource
        ) {
            // 1) Zuschlag am Bewerber — die eine Quelle. Vertragstext UND
            //    ZAS-Export lesen von hier (ZasEmployeeFieldResolver).
            $applicant->zuschlag = $newZuschlag;
            $applicant->contract_template_id = $template->id;
            $applicant->save();
            $applicant->refresh();

            // 2) Nachfolger anlegen und direkt als versendet markieren — HR
            //    kopiert den Signaturlink im Anschluss oder schickt das Portal.
            $new = RecContract::create([
                'rec_applicant_id'         => $applicant->id,
                'rec_contract_template_id' => $template->id,
                'team_id'                  => $applicant->team_id,
                'personalized_content'     => $template->personalizeContent($applicant),
                'status'                   => 'sent',
                'sent_at'                  => now(),
                'created_by_user_id'       => $userId,
                'notes'                    => $this->successorNote($old, $oldZuschlag, $newZuschlag, $reason, $hrNote),
            ]);

            // 3) Vertrags-Extra-Fields setzen und danach MIT Vertragskontext neu
            //    rendern — {{vertragsbeginn}}/{{vertragsende}} haengen an
            //    contract.extra_field.*, nicht am Bewerber.
            $touchedFields = false;
            if ($dates['vertragsbeginn']) {
                $new->setExtraField('vertragsbeginn', $dates['vertragsbeginn']);
                $touchedFields = true;
            }
            if ($dates['vertragsende']) {
                $new->setExtraField('vertragsende', $dates['vertragsende']);
                $touchedFields = true;
            }
            // Vorlagen, die den Zuschlag aus dem VERTRAGS-Feld lesen statt aus
            // dem Bewerber (eine der Live-Vorlagen tut das), bekommen den Wert
            // hier gesetzt — sonst rendert der Nachfolger einen leeren Betrag.
            // Deutsches Format, weil dieser Zweig den Wert roh durchgibt und HR
            // ihn im "Felder"-Dialog genauso tippt.
            if ($zuschlagSource === 'contract') {
                $new->setExtraField('zuschlag', number_format($newZuschlag, 2, ',', '.'));
                $touchedFields = true;
            }
            if ($touchedFields) {
                $new->personalized_content = $template->personalizeContent($applicant, $new);
                $new->save();
            }

            // 4) Vorgaenger markieren. NUR notes + superseded_by — Unterschrift,
            //    signed_at, status und personalized_content bleiben unberuehrt.
            $old->superseded_by_contract_id = $new->id;
            $old->notes = $this->appendNote(
                $old->notes,
                $this->predecessorNote($new, $oldZuschlag, $newZuschlag, $reason, $hrNote)
            );
            $old->save();

            // 5) Lohnbuero nur bei einer echten Aenderung im laufenden
            //    Verhaeltnis informieren.
            $payrollReported = false;
            if ($reason === self::REASON_RAISE) {
                $payrollReported = $this->reportToPayroll($applicant, $oldZuschlag, $newZuschlag);
            }

            return ['contract' => $new, 'payroll_reported' => $payrollReported];
        });
    }

    /**
     * Woraus loest die Vorlage den Zuschlag auf?
     *   'applicant' → applicant.zuschlag (AV-default, der Regelfall)
     *   'contract'  → contract.extra_field.zuschlag (eine Live-Vorlage tut das)
     *   null        → gar nicht: Altbestands-Variante mit festem Betrag im Text
     *
     * Geprueft wird das MAPPING, nicht der Text. Eine Vorlage ohne
     * {{zuschlag}}-Platzhalter im Body, die den Wert aber mappt, ist in
     * Ordnung; eine Variante wie AV-060, in der "0,60" literal steht, ist es
     * nicht — ihr Nachfolger waere textgleich mit dem Vorgaenger.
     */
    private function zuschlagSource($template): ?string
    {
        $mappings = $template->field_mappings;
        if (!is_array($mappings)) {
            return null;
        }

        foreach ($mappings as $source) {
            if ($source === 'applicant.zuschlag') {
                return 'applicant';
            }
        }

        foreach ($mappings as $source) {
            if ($source === 'contract.extra_field.zuschlag') {
                return 'contract';
            }
        }

        return null;
    }

    /**
     * Zuschlagsaenderung an die Lohnaenderungs-Liste haengen
     * (/employees/payroll-changes plus CSV-Export).
     *
     * Warum hier von Hand und nicht ueber RecEmployeeExportObserver: der
     * Observer vergleicht Spalten von rec_employees. Der Zuschlag steht auf
     * rec_applicants und ist ihm damit unsichtbar — eine Aenderung daran
     * loeste bis jetzt WEDER einen Payroll-Eintrag NOCH einen ZAS-Marker aus.
     * Der ZAS-Weg loest sich von selbst, sobald der neue Vertrag
     * unterschrieben wird (RecContract::saved-Listener auf signed_at); die
     * Klartext-Meldung ans Lohnbuero gibt es nur hier.
     *
     * Schreibt per DB::table wie der Observer — ein Eloquent-Save auf
     * RecEmployee wuerde ihn erneut anwerfen.
     *
     * Werte werden deutsch formatiert gespeichert ("0,60"), weil sie
     * ausschliesslich angezeigt und exportiert werden; PayrollChanges gibt
     * sie unveraendert durch.
     */
    private function reportToPayroll(RecApplicant $applicant, ?float $old, float $new): bool
    {
        if ($old !== null && abs($old - $new) < 0.005) {
            return false; // Kein Wertwechsel — keine Meldung.
        }

        $employee = DB::table('rec_employees')
            ->where('rec_applicant_id', $applicant->id)
            ->first(['id', 'payroll_data_changed_fields']);

        if (!$employee) {
            return false; // Noch kein Mitarbeiterdatensatz — nichts abzurechnen.
        }

        $entries = $employee->payroll_data_changed_fields
            ? json_decode($employee->payroll_data_changed_fields, true)
            : [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'field' => 'zuschlag',
            'old'   => $old !== null ? number_format($old, 2, ',', '.') : null,
            'new'   => number_format($new, 2, ',', '.'),
            'at'    => now()->toIso8601String(),
        ];

        DB::table('rec_employees')
            ->where('id', $employee->id)
            ->update([
                'payroll_data_changed_at'     => now(),
                'payroll_data_changed_fields' => json_encode($entries),
            ]);

        return true;
    }

    private function successorNote(RecContract $old, ?float $oldZuschlag, float $new, string $reason, ?string $hrNote): string
    {
        $note = sprintf(
            '%s von Vertrag #%d (unterschrieben am %s). Zuschlag %s → %s €/Std.',
            $reason === self::REASON_CORRECTION ? 'Korrektur' : 'Erhoehung',
            $old->id,
            $old->signed_at?->format('d.m.Y') ?? '—',
            $oldZuschlag !== null ? number_format($oldZuschlag, 2, ',', '.') : '—',
            number_format($new, 2, ',', '.'),
        );

        return $this->appendNote($note, $hrNote);
    }

    private function predecessorNote(RecContract $new, ?float $oldZuschlag, float $newZuschlag, string $reason, ?string $hrNote): string
    {
        $note = sprintf(
            'Ersetzt am %s durch Vertrag #%d — %s, Zuschlag %s → %s €/Std.%s',
            now()->format('d.m.Y'),
            $new->id,
            $reason === self::REASON_CORRECTION ? 'Korrektur' : 'Erhoehung',
            $oldZuschlag !== null ? number_format($oldZuschlag, 2, ',', '.') : '—',
            number_format($newZuschlag, 2, ',', '.'),
            $reason === self::REASON_CORRECTION ? ' Dieser Vertrag ist nicht wirksam geworden.' : '',
        );

        return $this->appendNote($note, $hrNote);
    }

    private function appendNote(?string $existing, ?string $addition): string
    {
        $parts = array_filter([
            $existing !== null ? trim($existing) : null,
            $addition !== null ? trim($addition) : null,
        ], fn ($p) => $p !== null && $p !== '');

        return implode("\n", $parts);
    }
}
