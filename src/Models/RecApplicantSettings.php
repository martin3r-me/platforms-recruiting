<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecApplicantSettings extends Model
{
    protected $table = 'rec_applicant_settings';

    protected $fillable = [
        'team_id', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    const DEFAULT_SETTINGS = [
        'use_informal_address' => false,
        'default_status_id' => null,
        // Jugendschutz-Gate: Auto-Absage <16 (WhatsApp-Template + Bewerbungsstatus).
        // Solange nicht konfiguriert, landen U16-Fälle auf dem HR-Schreibtisch.
        'minor_rejection_template_id' => null,
        'minor_rejection_status_id' => null,
        'auto_assign_owner' => false,
        'default_contact_user_id' => null,
        'auto_pilot_enabled' => true,
        'auto_pilot_channel_priority' => 'whatsapp_first',
        'auto_pilot_wa_account_id' => null,
        'auto_pilot_wa_initial_template_id' => null,
        'auto_pilot_wa_reminder_template_id' => null,
        'auto_pilot_reminder_interval_hours' => 24,
        'auto_pilot_max_reminders' => 3,
        'auto_start_auto_pilot' => false,
        'send_initial_whatsapp_template' => false,
        'enrichment_wa_template_id' => null,
        'interview_booking_wa_template_id' => null,
        // Warteliste: "Termin frei geworden"-Benachrichtigung (eigenes
        // Wording, gleicher Buchungslink-Token wie interview_booking).
        'interview_waitlist_wa_template_id' => null,
        // Termin-Warteliste (Dauerabo): "Platz im Termin frei" mit
        // {{name}}/{{termin}}-Variablen; ohne Wert greift der Fallback
        // aufs generische interview_waitlist_wa_template_id.
        'interview_waitlist_termin_wa_template_id' => null,
        'minimum_wage_hourly' => 13.90,
        'contract_wa_template_id' => null,
        'contract_wa_account_id' => null,
        'contract_wa_template_variables' => [],
        // Schulungszertifikat: Hauptschalter fuer die Ausstellung (HR-Absage
        // mit Zertifikat-Haken und MA-Anlage). Der Inhalt des Zertifikats ist
        // festes HTML im Code, es gibt also keine Vorlage, deren Fehlen das
        // Feature stilllegen wuerde — ohne diesen Schalter waere ein Deploy der
        // einzige Weg dazu. Default AUS: ein Team, das nichts konfiguriert hat,
        // stellt keine Zertifikate aus. Gelesen ueber
        // IssueTrainingCertificateService::SETTING_ENABLED.
        'issue_training_certificates' => false,
        // Schulungszertifikat: genehmigtes WhatsApp-Template, mit dem Weg (a)
        // den PDF-Link nach der Ablehnung zustellt. Das Template MUSS einen
        // dynamischen URL-Button an erster Position haben — der Link steckt im
        // Button, nicht im Fliesstext. Fehlt er, verweigert der Guard in
        // TrainingCertificateWhatsAppDelivery den Versand, statt eine Nachricht
        // ohne Link rauszuschicken. Leer = es wird trotzdem ausgestellt, nur
        // nicht zugestellt: der Versand ist die Zugabe, nicht die Bedingung.
        // Schluesselname steht in
        // Support/TrainingCertificateWaTemplate::SETTINGS_KEY.
        'training_certificate_wa_template_id' => null,
        // Mitarbeiter-Portal — eigenes Template (Wording: "Willkommen
        // im Team, hier dein Portal-Zugang"). Greift wenn ein RecEmployee
        // angelegt wurde (Phase-Config-Flag creates_employee_on_completion).
        'employee_portal_wa_template_id' => null,
        'employee_portal_wa_account_id' => null,
        // Kommunikations-Übersicht / Eskalation (WhatsApp 24h-Fenster).
        // Restzeit-Schwellen IM offenen Fenster (NICHT "Stunden seit Eingang"):
        // grün > yellow, gelb <= yellow, rot <= red, verpasst = Fenster zu.
        'comms_window_yellow_hours_left' => 12,
        'comms_window_red_hours_left' => 3,
        // In-App-Eskalation: zusätzlicher Verantwortlicher, der verpasste/rote
        // Fälle team-weit sieht (greift auch bei Krankheit/Urlaub des Owners).
        'comms_escalation_user_id' => null,
        // Allgemeines, von Meta genehmigtes Template, das ein geschlossenes
        // 24h-Fenster wieder öffnet ("deine Nachricht wird bearbeitet" o.ä.).
        'comms_holding_template_id' => null,
        // Auto-Antwort auf eingehende Sprachnachrichten: genehmigtes Template
        // mit Hinweis, dass Sprachnachrichten nicht bearbeitet werden koennen.
        // Leer = Feature aus. Gedrosselt auf 1x/24h je Konversation.
        'comms_voice_not_supported_template_id' => null,
        // HR-Abwesenheitsmodus (OOO): Template + Zeitraum. enabled/from/until/
        // back_at werden von der Conversations-Seite gesetzt; Template im
        // Einstellungen-Modal. Auto-Off lazy via OooMode (today >= back_at).
        'comms_ooo_template_id' => null,
        'comms_ooo_enabled' => false,
        'comms_ooo_from' => null,
        'comms_ooo_until' => null,
        'comms_ooo_back_at' => null,
        // Team-Timezone fuer datumsbasierte Features (OOO "heute"-Berechnung).
        // null = Fallback Europe/Berlin (aufgeloest NUR in TeamClock, nie an
        // Call-Sites). Bewusst noch nicht im Einstellungen-Modal editierbar.
        'comms_timezone' => null,
        // Payroll-Tracking: welche Felder als lohnrelevant gelten
        'employee_payroll_tracked_fields' => [
            'iban', 'bic', 'bank_institute', 'account_holder',
            'tax_class', 'steuer_id', 'sozialversicherungsnummer',
            'health_insurance',
            'street', 'house_number', 'zip', 'city',
        ],
    ];

    /**
     * Felder auf rec_employees, die als lohnrelevant getrackt werden
     * koennen. Gruppiert fuer die Settings-UI, gelabelt fuer Anzeige.
     * Single source of truth fuer Observer, View und Settings-Modal.
     */
    const PAYROLL_TRACKABLE_FIELDS = [
        'Bank' => [
            'iban'           => 'IBAN',
            'bic'            => 'BIC',
            'bank_institute' => 'Bank',
            'account_holder' => 'Kontoinhaber',
        ],
        'Steuer / SV' => [
            'tax_class'                 => 'Steuerklasse',
            'steuer_id'                 => 'Steuer-ID',
            'sozialversicherungsnummer' => 'Sozialversicherungsnummer',
            'health_insurance'          => 'Krankenkasse',
        ],
        'Adresse' => [
            'street'       => 'Strasse',
            'house_number' => 'Hausnummer',
            'zip'          => 'PLZ',
            'city'         => 'Ort',
        ],
    ];

    /**
     * Felder, die in der Lohnaenderungs-Liste und im CSV-Export ein Label
     * brauchen, aber NICHT vom Observer getrackt werden koennen — weil es
     * keine Spalte auf rec_employees ist.
     *
     * Der Zuschlag steht auf rec_applicants; gemeldet wird er ausdruecklich
     * von ReissueContractService, wenn HR einen Vertrag im laufenden
     * Verhaeltnis erhoeht. Er gehoert deshalb NICHT in
     * PAYROLL_TRACKABLE_FIELDS: diese Liste fuellt die Checkbox-Auswahl im
     * Settings-Modal, und ein Haken dort wuerde auf eine Spalte zeigen, die
     * der Observer nie in getChanges() sieht — ein Schalter, der nichts tut.
     */
    const PAYROLL_DISPLAY_ONLY_FIELDS = [
        'zuschlag' => 'Zuschlag',
    ];

    public static function payrollFieldLabels(): array
    {
        return array_merge(
            ...array_values(self::PAYROLL_TRACKABLE_FIELDS),
            ...[self::PAYROLL_DISPLAY_ONLY_FIELDS],
        );
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function serviceHours(): HasMany
    {
        return $this->hasMany(RecServiceHours::class, 'rec_applicant_settings_id');
    }

    public static function getOrCreateForTeam(int $teamId): self
    {
        return static::firstOrCreate(
            ['team_id' => $teamId],
            ['settings' => self::DEFAULT_SETTINGS]
        );
    }

    public function getSetting(string $key, $default = null)
    {
        $settings = $this->settings ?? self::DEFAULT_SETTINGS;
        return $settings[$key] ?? $default ?? (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    /**
     * Konfigurierter Absage-Status für Jugendschutz-Fälle (U16-Automatik und
     * HR-Schreibtisch-Ablehnung lesen ihn hier — eine Quelle, eine Sentinel-
     * Regel: 0/null/leer = nicht konfiguriert).
     */
    public function minorRejectionStatusId(): ?int
    {
        $value = (int) ($this->getSetting('minor_rejection_status_id') ?? 0);

        return $value > 0 ? $value : null;
    }

    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? self::DEFAULT_SETTINGS;
        $settings[$key] = $value;
        $this->settings = $settings;
    }
}
