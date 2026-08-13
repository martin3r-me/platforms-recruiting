<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Legt einen RecEmployee aus einem RecApplicant an. Wird durch den
 * Phase-Completion-Hook `creates_employee_on_completion` getriggert.
 *
 * Mapping-Source:
 *  - extra_field_values vom Applicant (by name lookup)
 *  - rec_applicant_legal_statuses (is_eu_citizen + file_ids)
 *  - crm_contact (primary email + phone als Fallback)
 *  - primaryPosition() fuer rec_position_id
 *
 * Side-Effects:
 *  - Setzt applicant.is_active = false (raus aus default Dashboard)
 *  - Setzt applicant.auto_pilot = false (kein weiterer Reminder am Bewerber)
 *  - Dupliziert den CRM-Contact-Link mit linkable_type='rec_employee'
 *  - Schreibt RecAutoPilotLog Type 'employee_created'
 *  - Stellt das Schulungszertifikat aus (Weg b), HINTER dem Commit und nur
 *    wenn der Team-Schalter an ist und eine attended-Buchung existiert. Kein
 *    Versand. Scheitert das, bleibt der Mitarbeiter — siehe
 *    issueTrainingCertificate() unten.
 *
 * Idempotent: existiert schon ein RecEmployee fuer diesen Applicant
 * (FK rec_applicant_id), wird der existierende zurueckgegeben — kein
 * Re-Mapping, keine Duplicate-Cases. Manuelle Spalten-Updates im Portal
 * oder via HR bleiben erhalten.
 */
class CreateEmployeeFromApplicantService
{
    public function createOrUpdate(RecApplicant $applicant, ?int $createdByUserId = null): RecEmployee
    {
        // Idempotenz: schon angelegt? Zurueckgeben.
        $existing = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
        if ($existing) {
            return $existing;
        }

        $employee = DB::transaction(function () use ($applicant, $createdByUserId) {
            $applicant->loadMissing(['legalStatus', 'crmContactLinks.contact']);

            $extraValues = $this->collectExtraFieldValuesByName($applicant);
            $legalStatus = $applicant->legalStatus;
            $primaryContact = $applicant->crmContactLinks->first()?->contact;

            // Extra-Field-Mapping zentral in ApplicantEmployeeFieldMapping
            // (unit-getestet, gleiche Quelle wie das Backfill-Command —
            // schuetzt vor Mapping-Drift). resolve() liefert nur befuellte
            // Spalten; die Basis-Werte darunter sind Fallbacks (crm_contact)
            // bzw. Felder, die nicht aus Extra-Fields kommen.
            $employee = RecEmployee::create(array_merge([
                'team_id'          => $applicant->team_id,
                'rec_applicant_id' => $applicant->id,
                'rec_position_id'  => $applicant->primaryPosition()?->id,

                // Fallback-Kette extra_field → crm_contact
                'first_name' => $primaryContact?->first_name,
                'last_name'  => $primaryContact?->last_name,
                'email'      => $primaryContact?->emailAddresses?->first()?->email_address,
                'phone'      => $primaryContact?->phoneNumbers?->first()?->raw_input,

                // Legal-Status: nur diese drei kommen (noch) aus dem
                // legalStatus-Record; alle Dokument-file_ids laufen direkt
                // ueber die Extra-Fields (via Mapping unten).
                'is_eu_citizen'           => $legalStatus?->is_eu_citizen,
                'nationalpass_file_id'    => $legalStatus?->nationalpass_file_id,
                'immatrikulation_file_id' => $legalStatus?->immatrikulation_file_id,

                // Lifecycle
                'is_active'          => true,
                'employed_since'     => now()->toDateString(),
                'created_by_user_id' => $createdByUserId,
            ], \Platform\Recruiting\Support\ApplicantEmployeeFieldMapping::resolve($extraValues)));

            // CRM-Link duplizieren: gleicher Contact, neuer linkable_type
            $this->mirrorCrmContactLinks($applicant, $employee, $createdByUserId);

            // MA-Kontaktbuch: Link-Anlage feuert keinen Observer (Regel aus der
            // Spec, Benannte Luecken) — nach dem Spiegeln explizit syncen.
            // Darf die Uebernahme niemals kippen.
            try {
                // Eigener Savepoint: Sync-Fehler darf die aeussere Uebernahme-
                // Transaktion nicht vergiften (abort-on-error-Engines).
                DB::transaction(function () use ($employee) {
                    app(\Platform\Recruiting\Services\EmployeeContactListSyncService::class)
                        ->syncEmployee($employee);
                });
            } catch (\Throwable $e) {
                Log::error('[EmployeeContactListSync] Sync nach Bewerber-Uebernahme fehlgeschlagen', [
                    'employee_id' => $employee->id,
                    'team_id' => $employee->team_id,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }

            // HR-only-Datenrow anlegen — physisch getrennt vom MA-Portal-
            // sichtbaren rec_employees. Snapshot der Vertrags-Daten beim
            // Anlegen damit ZAS-Export direkt verfuegbar ist ohne JOIN.
            $hrData = $employee->ensureHrData();
            $this->snapshotContractDatesToHrData($applicant, $hrData);
            $this->transferEvaluationToHrData($applicant, $hrData);

            // Bewerber deaktivieren — raus aus default Dashboard, Statistiken
            // greifen weiter via rec_applicants ohne is_active-Filter.
            $applicant->update([
                'is_active'  => false,
                'auto_pilot' => false,
            ]);

            // ZAS-Doppel-Datensatz-Vermeidung: sobald der Bewerber zum MA
            // wird, soll er NICHT mehr im alten Bewerber-Update-Endpoint
            // erscheinen (sonst kriegt ZAS auf gleichen Match-Identifier
            // doppelte UPDATE-Operationen mit teils alten Daten). Direkt
            // per DB::update damit der RecApplicantExportObserver nicht
            // getriggert wird.
            DB::table('rec_applicants')
                ->where('id', $applicant->id)
                ->update(['export_changed_at' => null]);

            // AutoPilot-Log fuer HR-Sichtbarkeit
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $applicant->id,
                    'type'             => 'employee_created',
                    'summary'          => "Mitarbeiter angelegt (RecEmployee #{$employee->id}). Bewerber-Funnel beendet, Daten-Nachpflege uebernimmt das MA-Portal.",
                ]);
            } catch (\Throwable $e) {
                Log::warning('[CreateEmployeeFromApplicantService] Could not write employee_created log', [
                    'applicant_id' => $applicant->id,
                    'employee_id'  => $employee->id,
                    'error'        => $e->getMessage(),
                ]);
            }

            // Hinweis: RecEmployee::sendPortalNotification() ist verfuegbar
            // aber wird hier BEWUSST nicht automatisch getriggert. Der
            // explizite "MA-Portal aktivieren"-Button im Schulungs-Index
            // (eigene Iteration) wird das spaeter aufrufen. Bis dahin
            // laeuft der alte Notification-Pfad ueber
            // RecApplicant::sendContractPortalNotification weiter wie
            // bisher (alter Portal-Link funktioniert weiterhin auch fuer
            // bereits konvertierte MAs, weil ApplicantPortal kein
            // is_active-Check hat).

            return $employee->fresh();
        });

        // WEG (b) DER ZERTIFIKAT-AUSSTELLUNG, und die Stelle ist die Aussage:
        // HIER, hinter dem schliessenden `});` der Transaktion, nicht in ihrer
        // letzten Zeile. Innerhalb waere "alles oder nichts" die Zusage, und
        // genau die will man hier nicht: ein Mitarbeiter OHNE Zertifikat ist ein
        // legitimer Zustand, keine Mitarbeiter-Anlage wegen eines Defekts im
        // Zertifikat-Pfad ist keiner. Wer den Aufruf in die Closure schiebt,
        // braucht dort einen catch — also eine Ausnahme von einer Zusage, die
        // man von vornherein nicht will.
        //
        // Der Unterschied zum Ablehnen-Zweig (HrDeskRoutingService, dort
        // INNERHALB der Transaktion) ist echt: dort hat HR einen Haken bewusst
        // gesetzt und wuerde sonst glauben, beides sei passiert. Hier hakt
        // niemand etwas an.
        $this->issueTrainingCertificate($applicant);

        return $employee;
    }

    /**
     * Jeder Teilnehmer, der Mitarbeiter wird, bekommt sein Zertifikat — ohne
     * Zutun. Kein Versand: der neue Mitarbeiter bekommt seine Portal-Einladung
     * ohnehin, und dort steht das Zertifikat (Spec §D3).
     *
     * ZWEI GATES, und ihre Reihenfolge ist gemessen, nicht beliebig:
     *  1. der Team-Schalter. Dieser Weg hat keine UI, der Schalter ist die
     *     einzige Bremse — und er ist per Default AUS, also der Zustand jedes
     *     heute existierenden Teams. Deshalb steht er vorn: solange er aus ist,
     *     kostet die Mitarbeiter-Anlage genau diesen einen Query mehr als
     *     vorher und keinen zweiten.
     *  2. die attended-Buchung. Direkteinstellungen und ZAS-Importe haben keine
     *     Schulung; ein Zertifikat waere dort ein Dokument mit leerem Datum und
     *     leerem Schulungsleiter, also eine falsche Aussage in Papierform.
     * Beide Faelle sind KEIN Fehler und schreiben deshalb auch keine Log-Zeile.
     *
     * Der Schalter wird VORHER gefragt, statt den Wurf von issue() zu fangen:
     * die Meldung "Ausstellung ist nicht eingeschaltet" im Fehler-Log waere eine
     * Falschmeldung ueber einen Normalzustand.
     *
     * EIGENER SAVEPOINT um die Ausstellung, aus demselben Grund wie beim
     * Kontaktbuch-Sync weiter oben: DirectHire\Index::createEmployee() ruft
     * createOrUpdate() innerhalb einer EIGENEN DB::transaction() auf. Dann ist
     * dieser Aufruf hier nicht hinter einem Commit, sondern nur hinter einem
     * Savepoint (gemessen: transactionLevel 1 statt 0), und ein gefangener
     * Statement-Fehler wuerde auf abort-on-error-Engines die Transaktion des
     * Aufrufers vergiften — der Folgefehler waere dann die Mitarbeiter-Anlage,
     * also genau das, was diese Methode nicht anfassen darf.
     *
     * \Throwable UND NICHT EINE LISTE, und das ist eine Messung, keine
     * Bequemlichkeit: der Pfad wirft \RuntimeException (der Feature-Guard und
     * jede QueryException, die ueber PDOException von RuntimeException erbt),
     * \InvalidArgumentException (sechs Stellen in TrainingLeaderResolver und
     * TrainingCertificateContent, also LogicException), \Exception
     * (BindingResolutionException, wenn der Service nicht aufloesbar ist) und
     * \Error (TypeError bei kaputten Modell-Daten). \Exception und \Error sind
     * die beiden EINZIGEN Implementierungen von \Throwable in PHP — eine
     * Aufzaehlung waere hier also nur eine laengere Schreibweise fuer
     * \Throwable, die vortaeuscht, sie liesse etwas durch. Lieber ehrlich breit
     * als kosmetisch verengt.
     */
    private function issueTrainingCertificate(RecApplicant $applicant): void
    {
        try {
            $certificates = app(\Platform\Recruiting\Services\IssueTrainingCertificateService::class);

            if (!$certificates->isEnabledForTeam((int) $applicant->team_id)) {
                return;
            }

            $hatSchulungBesucht = $applicant->interviewBookings()
                ->where('status', 'attended')
                ->exists();

            if (!$hatSchulungBesucht) {
                return;
            }

            DB::transaction(fn () => $certificates->issue($applicant, null));
        } catch (\Throwable $e) {
            // Eigener Marker: ein Fehler bei der Ausstellung darf im Log nicht
            // wie einer beim Bewertungs-Transfer aussehen. Und Log::error, nicht
            // warning — ein Zertifikat, das ein Teilnehmer bekommen sollte und
            // nicht bekommt, ist ein Fehler, auch wenn die Anlage weiterlief.
            // Die Bewerber-ID muss mit, sonst ist die Zeile nicht nachverfolgbar
            // (dieser Weg laeuft ohne angemeldeten Benutzer und ohne UI, in der
            // die Meldung sonst auftauchen koennte).
            Log::error('[CreateEmployeeFromApplicantService] Zertifikat-Ausstellung nach MA-Anlage fehlgeschlagen', [
                'applicant_id' => $applicant->id,
                'team_id'      => $applicant->team_id,
                'exception'    => get_class($e),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Liest alle extra_field_values des Applicants und mapped sie auf
     * ein Assoc-Array [field_name => value]. Greift auf die ueber alle
     * Phasen gueltigen Definitionen zu (via getExtraFieldDefinitions),
     * die Phase-Inheritance schon handhabt.
     */
    public function collectExtraFieldValuesByName(RecApplicant $applicant): array
    {
        try {
            $definitions = $applicant->getExtraFieldDefinitions();
        } catch (\Throwable) {
            return [];
        }

        $values = $applicant->extraFieldValues()->get()->keyBy('definition_id');
        $byName = [];
        foreach ($definitions as $def) {
            if (empty($def->name)) {
                continue;
            }
            $val = $values->get($def->id);
            if (!$val) {
                continue;
            }
            $raw = $val->value;
            if ($raw === null || $raw === '' || $raw === '[]') {
                continue;
            }
            $byName[$def->name] = $raw;
        }
        return $byName;
    }

    /**
     * Schreibt Snapshot der Vertragsdaten auf die hrData-Row:
     *  - contract_sent_date  → frueheste sent_at aus den nicht-cancelled
     *    Vertraegen (= "Vertrags-Datum")
     *  - contract_end_date   → vertragsende-Extra-Field aus dem AV-Vertrag
     *    (= "Befristet bis")
     *
     * contract_signed_at bleibt initial null — wird gesetzt wenn alle
     * AV-Vertraege signed sind (separate Hook).
     */
    private function snapshotContractDatesToHrData(RecApplicant $applicant, $hrData): void
    {
        try {
            $contracts = $applicant->contracts()
                ->whereNotIn('status', ['cancelled'])
                ->with('contractTemplate')
                ->get();

            // Frueheste sent_at
            $sentDate = $contracts
                ->filter(fn ($c) => $c->sent_at !== null)
                ->sortBy('sent_at')
                ->first()?->sent_at?->toDateString();

            // contract_end aus AV-Vertrag extra_fields (vertragsende)
            $avContract = $contracts->first(function ($c) {
                $code = $c->contractTemplate?->code;
                return $code !== null && str_starts_with($code, 'AV-');
            });

            $endDate = null;
            if ($avContract && method_exists($avContract, 'getExtraField')) {
                $raw = $avContract->getExtraField('vertragsende');
                if ($raw) {
                    try {
                        $endDate = \Carbon\Carbon::parse($raw)->toDateString();
                    } catch (\Throwable) {}
                }
            }

            $updates = [];
            if ($sentDate) {
                $updates['contract_sent_date'] = $sentDate;
            }
            if ($endDate) {
                $updates['contract_end_date'] = $endDate;
            }
            if (!empty($updates)) {
                $hrData->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning('[CreateEmployeeFromApplicantService] snapshotContractDates failed', [
                'applicant_id' => $applicant->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uebernimmt die acht Bewertungsfelder vom Bewerber auf die frische
     * hrData-Row (Spec §4). Ab hier ist hrData die einzige Lese- und
     * Schreibseite; die Bewerber-Spalten werden nicht mehr angefasst.
     *
     * Eigener Log-Marker (nicht der von snapshotContractDates), damit im Log
     * unterscheidbar bleibt, welcher der beiden Uebernahme-Schritte gekippt ist.
     */
    private function transferEvaluationToHrData(RecApplicant $applicant, $hrData): void
    {
        try {
            $source = [];
            $target = [];
            foreach (\Platform\Recruiting\Support\EvaluationValues::FIELDS as $field) {
                $source[$field] = $applicant->{$field};
                $target[$field] = $hrData->{$field};
            }

            $updates = \Platform\Recruiting\Support\EvaluationTransfer::valuesToCopy($source, $target);

            if (!empty($updates)) {
                $hrData->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning('[CreateEmployeeFromApplicantService] evaluationTransfer failed', [
                'applicant_id' => $applicant->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dupliziert die existierenden CRM-Contact-Links vom Applicant auf
     * den neuen Employee — gleicher Contact, neuer linkable_type. Damit
     * sieht das CRM-UI auf der Contact-Karte beide Verknuepfungen
     * (1x Bewerber, 1x Mitarbeiter).
     */
    private function mirrorCrmContactLinks(RecApplicant $applicant, RecEmployee $employee, ?int $userId): void
    {
        $employeeMorphClass = $employee->getMorphClass();

        foreach ($applicant->crmContactLinks as $link) {
            $alreadyMirrored = CrmContactLink::where('contact_id', $link->contact_id)
                ->where('linkable_type', $employeeMorphClass)
                ->where('linkable_id', $employee->id)
                ->exists();
            if ($alreadyMirrored) {
                continue;
            }
            CrmContactLink::create([
                'contact_id'         => $link->contact_id,
                'linkable_type'      => $employeeMorphClass,
                'linkable_id'        => $employee->id,
                'team_id'            => $employee->team_id,
                'created_by_user_id' => $userId,
            ]);
        }
    }
}
