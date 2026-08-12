<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Support\TrainingCertificateContent;
use Platform\Recruiting\Support\TrainingLeaderResolver;

/**
 * Stellt das Schulungszertifikat aus.
 *
 * KEINE Vorlagen-Aufloesung: der Inhalt steht als festes HTML in
 * TrainingCertificateContent. Damit entfaellt auch der frueher hier geplante
 * Guard "darf ein VERTRAG als Zertifikat ausgestellt werden" — es gibt keine
 * Vorlagen-ID mehr, die man verwechseln koennte.
 *
 * firstOrCreate-Semantik: das Unique (rec_applicant_id, kind) ist eine
 * Invariante, kein Fehlerfall. Wird ein abgelehnter Bewerber spaeter doch
 * eingestellt, laeuft die Ausstellung ein zweites Mal an (Weg a: HR-Absage,
 * Weg b: MA-Anlage) — und soll das bestehende Zertifikat ZURUECKGEBEN. Der
 * Snapshot der ersten Ausstellung bleibt unangetastet: ein bereits zugestelltes
 * Dokument darf sich nicht nachtraeglich aendern, sonst weicht die Kopie beim
 * Bewerber von der in der DB ab.
 *
 * DAS GATE: das Team-Setting issue_training_certificates (Default false). Mit
 * festem HTML gibt es kein default_certificate_template_id mehr; ohne diesen
 * Schalter waere ein Deploy der einzige Weg, das Feature stillzulegen.
 *
 * SO fragt ein Aufrufer, der nicht ausstellen will, wenn das Feature aus ist:
 * isEnabledForTeam() vorher aufrufen. issue() selbst WIRFT in diesem Fall
 * (dieselbe Quelle, derselbe Schluessel) — der Guard dort ist die zweite
 * Verteidigungslinie fuer Weg (b), der ohne UI laeuft und deshalb keine
 * ausgegraute Checkbox hat, die ihn aufhaelt.
 */
class IssueTrainingCertificateService
{
    /** Der Team-Schalter. Genau ein Schluesselstring im ganzen Modul. */
    public const SETTING_ENABLED = 'issue_training_certificates';

    /**
     * Darf dieses Team Zertifikate ausstellen?
     *
     * Fuer die Aufrufer, die VOR dem Ausstellen fragen muessen: die
     * HR-Schreibtisch-Checkbox (Sichtbarkeit) und die MA-Anlage (kein UI).
     */
    public function isEnabledForTeam(int $teamId): bool
    {
        return (bool) RecApplicantSettings::getOrCreateForTeam($teamId)
            ->getSetting(self::SETTING_ENABLED, false);
    }

    /**
     * Stellt aus — oder gibt das bestehende Zertifikat dieser Schulungsart
     * zurueck.
     *
     * @param  ?int  $issuedByUserId  null = ohne angemeldeten Benutzer (Weg b)
     */
    public function issue(RecApplicant $applicant, ?int $issuedByUserId): RecTrainingCertificate
    {
        $teamId = (int) $applicant->team_id;
        $applicantId = (int) $applicant->id;

        if (!$this->isEnabledForTeam($teamId)) {
            throw new \RuntimeException(
                "Zertifikat-Ausstellung ist im Team #{$teamId} nicht eingeschaltet "
                . '(Einstellung ' . self::SETTING_ENABLED . '). Aufrufer pruefen das '
                . 'vorher mit isEnabledForTeam().'
            );
        }

        // Bestand ZUERST, vor dem Aufbau des Inhalts. Zwei Gruende: der
        // Snapshot der ersten Ausstellung bleibt so garantiert unberuehrt, und
        // eine Wiederholung kann nicht an Daten scheitern, die erst nach der
        // ersten Ausstellung kaputt geworden sind (TrainingLeaderResolver wirft
        // bei einem verletzten Interviewer-Vertrag) — ein bereits ausgestelltes
        // Zertifikat abzurufen darf davon nicht abhaengen.
        $existing = RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicantId)
            ->where('kind', RecTrainingCertificate::KIND_SERVICE_BASIS)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return RecTrainingCertificate::create([
            'team_id' => $teamId,
            'rec_applicant_id' => $applicantId,
            'kind' => RecTrainingCertificate::KIND_SERVICE_BASIS,
            'personalized_content' => $this->snapshot($applicant),
            'issued_at' => Carbon::now(),
            'issued_by_user_id' => $issuedByUserId,
        ]);
    }

    /**
     * Der Inhalt zum Zeitpunkt der Ausstellung. Oeffentlich, damit die
     * Vorschau denselben Weg nehmen kann, den die Ausstellung nimmt.
     */
    public function snapshot(RecApplicant $applicant): string
    {
        $contact = $this->contactOf($applicant);
        $bookings = $this->bookingRows($applicant);

        return TrainingCertificateContent::render([
            'kontakt_vorname' => trim((string) ($contact->first_name ?? '')),
            'kontakt_nachname' => trim((string) ($contact->last_name ?? '')),
            'schulung_datum' => TrainingLeaderResolver::trainingDate($bookings),
            'schulung_leiter' => TrainingLeaderResolver::leaderNames($bookings),
            'datum_heute' => Carbon::now()->format('d.m.Y'),
        ]);
    }

    /**
     * Der Kontakt, dessen Name auf dem Dokument steht: der mit der KLEINSTEN
     * contact_id.
     *
     * SO und nicht ->first(): crmContactLinks ist ein morphMany ohne Ordering,
     * die Reihenfolge ist damit nicht garantiert (Spec F11, dieselbe
     * Begruendung wie in Support/ApplicantContactName und
     * EmployeeContactListSyncService::resolveDesired). Auf einem Dokument, das
     * den Namen des Bewerbers traegt, darf nicht die Einfuegereihenfolge der
     * Verknuepfungen entscheiden, welcher Name gedruckt wird — und ein Wechsel
     * zwischen zwei Downloads waere nicht nachvollziehbar.
     *
     * Kein Kontakt = leeres Namensfeld, keine Exception: dieselbe Policy wie
     * bei Datum und Leiter, siehe TrainingLeaderResolver.
     */
    private function contactOf(RecApplicant $applicant): mixed
    {
        return $applicant->crmContactLinks()
            ->with('contact')
            ->orderBy('contact_id')
            ->first()
            ?->contact;
    }

    /**
     * Die Buchungen als reine Datenstrukturen, wie TrainingLeaderResolver sie
     * verlangt: ['id' => int, 'status' => string, 'starts_at' => ?string,
     * 'interviewers' => list<string>].
     *
     * Der Vertrag ist streng und das ist Absicht — er faengt genau die Fehler,
     * die sonst STILL auf dem Dokument landen. Deshalb hier die beiden Stellen,
     * an denen es darauf ankommt:
     *
     *  - Namen mit pluck('name')->all(). Das ergibt eine Liste echter Strings.
     *    (->all() allein ergaebe eine Liste von MODELS, und
     *    Model::__toString() liefert toJson() — dann stuende
     *    '{"id":7,"name":"Anna Bergmann"}' auf dem Zertifikat.)
     *  - starts_at als naiver 'Y-m-d H:i:s'-String aus dem datetime-Cast.
     *
     * @return list<array{id: int, status: string, starts_at: ?string, interviewers: list<string>}>
     */
    private function bookingRows(RecApplicant $applicant): array
    {
        $applicant->load('interviewBookings.interview.interviewers');

        return $applicant->interviewBookings
            ->map(fn ($booking) => [
                'id' => (int) $booking->id,
                'status' => (string) $booking->status,
                'starts_at' => $booking->interview?->starts_at?->format('Y-m-d H:i:s'),
                'interviewers' => $booking->interview === null
                    ? []
                    : $booking->interview->interviewers->pluck('name')->all(),
            ])
            ->values()
            ->all();
    }
}
