<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\ContextFile;
use Platform\Recruiting\Services\HrDeskRoutingService;

class RecApplicantLegalStatus extends Model
{
    protected $table = 'rec_applicant_legal_statuses';

    const DOCUMENT_FIELDS = [
        'nationalpass_file_id' => 'Nationalpass',
        'aufenthaltstitel_front_file_id' => 'Aufenthaltstitel Vorderseite',
        'aufenthaltstitel_back_file_id' => 'Aufenthaltstitel Rückseite',
        'visumsblatt_file_id' => 'Visumsblatt',
        'zusatzblatt_file_id' => 'Zusatzblatt',
        'immatrikulation_file_id' => 'Immatrikulationsbescheinigung / Schulbescheinigung',
    ];

    protected $fillable = [
        'rec_applicant_id',
        'team_id',
        'is_eu_citizen',
        'nationalpass_file_id',
        'aufenthaltstitel_front_file_id',
        'aufenthaltstitel_back_file_id',
        'visumsblatt_file_id',
        'zusatzblatt_file_id',
        'immatrikulation_file_id',
        'legal_status_checked_at',
        'additional_contract_template_id',
    ];

    protected $casts = [
        'is_eu_citizen' => 'boolean',
        'nationalpass_file_id' => 'integer',
        'aufenthaltstitel_front_file_id' => 'integer',
        'aufenthaltstitel_back_file_id' => 'integer',
        'visumsblatt_file_id' => 'integer',
        'zusatzblatt_file_id' => 'integer',
        'immatrikulation_file_id' => 'integer',
        'legal_status_checked_at' => 'datetime',
        'additional_contract_template_id' => 'integer',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function nationalpassFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'nationalpass_file_id');
    }

    public function aufenthaltstitelFrontFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'aufenthaltstitel_front_file_id');
    }

    public function aufenthaltstitelBackFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'aufenthaltstitel_back_file_id');
    }

    public function visumsblattFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'visumsblatt_file_id');
    }

    public function zusatzblattFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'zusatzblatt_file_id');
    }

    public function immatrikulationFile(): BelongsTo
    {
        return $this->belongsTo(ContextFile::class, 'immatrikulation_file_id');
    }

    /**
     * Optionaler Zusatzvertrag fuer nicht-EU-Buerger (typisch AT-* Codes,
     * z.B. Arbeitsgenehmigung-Zusatz, Aufenthaltstitel-bezogenes Doc).
     * HR waehlt ihn beim Pruefen auf dem HR-Schreibtisch. SendContractsService
     * laed ihn beim Vertragsversand zusaetzlich zu AV + IFSG.
     */
    public function additionalContractTemplate(): BelongsTo
    {
        return $this->belongsTo(RecContractTemplate::class, 'additional_contract_template_id');
    }

    /**
     * True wenn der Rechtsstatus von HR explizit geprueft wurde
     * (legal_status_checked_at gesetzt). Wird im Schulungs-Index fuer die
     * rot/gruen-Markierung und im Bulk-Send-Filter verwendet.
     *
     * Nur sinnvoll fuer is_eu_citizen=false — EU-Buerger brauchen keine
     * Pruefung. Der Caller muss die EU-Frage separat checken.
     */
    public function isChecked(): bool
    {
        return $this->legal_status_checked_at !== null;
    }

    public function setEuCitizen(?bool $value, ?int $userId = null): void
    {
        $this->is_eu_citizen = $value;
        $this->save();

        // Zentrales Routing — egal ob Wert sich geändert hat oder nicht.
        // evaluateAndRoute ist idempotent (existingOpenCase-Check), damit
        // verlieren wir keine Trigger wenn der Bewerber den Wert mehrfach
        // setzt.
        app(HrDeskRoutingService::class)->evaluateAndRoute(
            $this->applicant->fresh(['legalStatus']),
            $userId,
        );
    }
}
