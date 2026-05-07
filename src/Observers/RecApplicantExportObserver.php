<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\CoreExtraFieldValue;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Setzt rec_applicants.export_changed_at = now() bei Aenderungen, die
 * fuer den ZAS-Bewerber-Export relevant sind. Der Endpoint liefert
 * dann nur Datensaetze mit gesetztem Marker aus und nullt ihn nach
 * erfolgreicher Auslieferung wieder.
 *
 * Beobachtete Modelle:
 *   - RecApplicant      (saved)              Stammdaten, Kontakt
 *   - CoreExtraFieldValue (saved/deleted)    Onboarding-Felder, Bilder
 *   - RecContract       (saved)              sent_at / signed_at
 *   - RecInterviewBooking (saved)            event_location → SchulungsStandort
 *
 * Bei CoreExtraFieldValue wird zusaetzlich auf eine Whitelist relevanter
 * Field-Keys gefiltert — interne Felder (z. B. HR-Notizen) sollen den
 * Marker nicht triggern. Liste deckt sich mit dem Mapping in
 * docs/meingedeck/zas-applicant-export.md
 *
 * Setzen erfolgt per direktem DB-Update statt via Model-Save, um
 * Rekursion zu vermeiden (sonst wuerde ein RecApplicant->saved beim
 * Marker-Set wieder den Observer triggern).
 */
class RecApplicantExportObserver
{
    /**
     * Extra-Field-Keys die fuer ZAS relevant sind. Aenderungen an einem
     * dieser Felder triggern den Marker. Der Endpoint mappt sie via
     * ZasFieldResolver auf die ZAS-CSV-Spalten (alt/neu Fallback).
     */
    public const RELEVANT_FIELD_NAMES = [
        // Phase 1 / Stammdaten (selten geaendert, aber theoretisch korrigierbar)
        'nachname', 'vorname', 'geburtsdatum', 'telefonnummer', 'email',

        // Adresse
        'strasse', 'hausnummer', 'plz', 'stadt',

        // Personenstamm
        'eu_burger', 'ich_bin', 'geburtsname', 'geburtsort', 'geburtsland',
        'geschlecht', 'familienstand',

        // Ausweis (alt + neu Keys)
        'ausweisnummer', 'ausweis_gultig_bis',
        'foto_ausweis_vorderseite', 'foto_ausweis_ruckseite',
        'ausweis_reisepass_foto_vorderseite', 'ausweis_reisepass_foto_ruckseite',

        // Krankenversicherung
        'krankenkasse',
        'foto_versicherungskarte', 'foto_versichertenkarte',

        // Steuer / Sozialversicherung
        'sozialversicherungsnummer', 'steuer_id',

        // Bank
        'geldinstitut', 'iban', 'bic',

        // Mobilitaet
        'fuhrerschein_klasse', 'pkw_vorhanden',

        // Selfie / Identitaet
        'selfie_upload',

        // Nicht-EU-Dokumente (alt + neu Keys)
        'nationalpass',
        'aufenthaltstitel_vorderseite', 'aufenthaltstitel_ruckseite',
        'visumsblatt', 'visum_foto',
        'zusatzblatt',
        'zusatzblatt_arbeitsgenehmigung_vorderseite',
        'zusatzblatt_arbeitsgenehmigung_ruckseite',
        'fiktionsbescheinigung_vorderseite', 'fiktionsbescheinigung_ruckseite',
        'immatrikulationsbescheinigung',
        'immatrikulationsbescheinigung_schulbescheinigung',
    ];

    /**
     * Registrierung aller Event-Listener. Wird in
     * RecruitingServiceProvider::boot() einmalig aufgerufen.
     *
     * Alle Listener sind in safelyRun() gewrapped — wenn die ZAS-Logik
     * aus irgendeinem Grund crasht, soll das NIE einen normalen
     * Bewerber-Save kaputt machen. Lieber stiller Log-Eintrag als 500
     * fuer den HR-User.
     */
    public static function register(): void
    {
        RecApplicant::saved(static function (RecApplicant $applicant): void {
            self::safelyRun(function () use ($applicant): void {
                // Defensiver Recursion-Guard: wenn die einzige Aenderung
                // der Marker selbst war (z. B. jemand setzt
                // export_changed_at via Eloquent-Save statt direktem
                // DB-Update), nicht erneut markieren. updated_at wird
                // ignoriert weil das bei jedem Save mit aktualisiert wird.
                $changedKeys = array_keys($applicant->getChanges());
                $businessChanges = array_diff($changedKeys, ['export_changed_at', 'updated_at']);
                if (empty($businessChanges)) {
                    return;
                }
                self::markApplicantId($applicant->id);
            }, 'rec_applicant.saved', $applicant->id);
        });

        CoreExtraFieldValue::saved(static function (CoreExtraFieldValue $value): void {
            self::safelyRun(
                fn () => self::handleExtraFieldValue($value),
                'extra_field_value.saved',
                $value->id
            );
        });

        CoreExtraFieldValue::deleted(static function (CoreExtraFieldValue $value): void {
            self::safelyRun(
                fn () => self::handleExtraFieldValue($value),
                'extra_field_value.deleted',
                $value->id
            );
        });

        RecContract::saved(static function (RecContract $contract): void {
            self::safelyRun(function () use ($contract): void {
                if (!$contract->wasChanged(['sent_at', 'signed_at'])) {
                    return;
                }
                if ($contract->rec_applicant_id) {
                    self::markApplicantId($contract->rec_applicant_id);
                }
            }, 'rec_contract.saved', $contract->id);
        });

        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                if (!$booking->wasChanged(['rec_interview_id'])) {
                    return;
                }
                if ($booking->rec_applicant_id) {
                    self::markApplicantId($booking->rec_applicant_id);
                }
            }, 'rec_interview_booking.saved', $booking->id);
        });
    }

    /**
     * Wrapper fuer Listener-Bodies: unterdrueckt Exceptions, loggt sie
     * stattdessen. Begruendung: ZAS-Export-Bugs duerfen niemals einen
     * regulaeren Save im Recruiting-Flow blockieren — der ZAS-Marker
     * waere dann zwar nicht gesetzt und der Datensatz wuerde im
     * naechsten Pull fehlen, aber der HR-User sieht keinen 500.
     *
     * Cron / Backfill-Command sind die Korrektur-Mechanismen falls
     * Marker mal verloren gehen.
     */
    protected static function safelyRun(callable $fn, string $context, mixed $entityId): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            \Log::warning('[ZAS-Observer] failed silently', [
                'context'   => $context,
                'entity_id' => $entityId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pruefe ob das Extra-Field-Value zu einem Bewerber gehoert und in der
     * Whitelist steht. Wenn ja: Marker setzen.
     */
    protected static function handleExtraFieldValue(CoreExtraFieldValue $value): void
    {
        // Nur fuer Bewerber-Felder relevant (Polymorph kann auch Postings,
        // Positions, Contracts etc. sein).
        if ($value->fieldable_type !== 'rec_applicant') {
            return;
        }

        // Whitelist-Check: Field-Definition laden und Name pruefen.
        // Der Field-Name kommt aus core_extra_field_definitions; der
        // CoreExtraFieldValue speichert nur die definition_id. Direkter
        // Join via DB um Eager-Load-Bedarf zu vermeiden.
        $fieldName = DB::table('core_extra_field_definitions')
            ->where('id', $value->definition_id)
            ->value('name');

        if (!$fieldName || !in_array($fieldName, self::RELEVANT_FIELD_NAMES, true)) {
            return;
        }

        if ($value->fieldable_id) {
            self::markApplicantId((int) $value->fieldable_id);
        }
    }

    /**
     * Setzt export_changed_at auf now() per direktem DB-Update.
     * Vermeidet Model-Events / Rekursion.
     */
    protected static function markApplicantId(int $applicantId): void
    {
        DB::table('rec_applicants')
            ->where('id', $applicantId)
            ->update(['export_changed_at' => now()]);
    }
}
