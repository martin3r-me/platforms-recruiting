<?php

namespace Platform\Recruiting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Contracts\InheritsExtraFields;
use Platform\Core\Traits\HasExtraFields;
use Platform\Core\Traits\HasPublicFormLink;
use Platform\Recruiting\Support\ResttagePlaceholder;
use Symfony\Component\Uid\UuidV7;

class RecContract extends Model implements InheritsExtraFields
{
    use HasExtraFields;
    use HasPublicFormLink;

    protected $table = 'rec_contracts';

    protected $fillable = [
        'uuid',
        'rec_applicant_id',
        'rec_contract_template_id',
        'team_id',
        'status',
        'personalized_content',
        'signature_data',
        'signed_at',
        'sent_at',
        'completed_at',
        'notes',
        'pre_signing_data',
        'created_by_user_id',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'pre_signing_data' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });

        // ZAS-Export-Snapshot: wenn ein AV-Vertrag signiert wird und der
        // zugehoerige Bewerber bereits einen RecEmployee hat (= ist
        // Mitarbeiter geworden), schreibe contract_signed_at auf die
        // hrData-Row. Idempotent — wenn schon gesetzt, kein Re-Write.
        static::saved(function (self $contract) {
            if (!$contract->signed_at) {
                return;
            }
            $applicant = $contract->applicant;
            if (!$applicant) {
                return;
            }
            $employee = $applicant->employee;
            if (!$employee) {
                return;
            }
            // Pruefe ob alle nicht-cancelled AV-Vertraege signed sind
            $avContracts = $applicant->contracts()
                ->whereNotIn('status', ['cancelled'])
                ->whereHas('contractTemplate', fn ($q) => $q->where('code', 'like', 'AV-%'))
                ->get();
            if ($avContracts->isEmpty()) {
                return;
            }
            $allSigned = $avContracts->every(fn ($c) => $c->signed_at !== null);
            if (!$allSigned) {
                return;
            }
            // Spaeteste signed_at als "Vertrag zurueck am"
            $latestSigned = $avContracts
                ->filter(fn ($c) => $c->signed_at !== null)
                ->sortByDesc('signed_at')
                ->first()?->signed_at;
            if (!$latestSigned) {
                return;
            }
            $hrData = $employee->ensureHrData();
            // Nur ueberschreiben wenn aelter — HR-Manueller Override darf nicht weg
            if ($hrData->contract_signed_at === null
                || $hrData->contract_signed_at->lt($latestSigned)) {
                $hrData->update(['contract_signed_at' => $latestSigned->toDateString()]);
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(RecContractTemplate::class, 'rec_contract_template_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function extraFieldParents(): array
    {
        $parents = [];
        if ($this->contractTemplate) {
            $parents[] = $this->contractTemplate;
        }
        return $parents;
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Wenn `vertragsbeginn` gesetzt ist und `vertragsende` leer, wird das Ende
     * berechnet: +1 Jahr, Anfang Monat, −1 Tag (z.B. 15.05.2026 → 30.04.2027).
     * Liefert ['vertragsbeginn' => Y-m-d|null, 'vertragsende' => Y-m-d|null].
     */
    public static function resolveContractDates(?string $beginn, ?string $ende): array
    {
        $beginn = $beginn !== null && $beginn !== '' ? $beginn : null;
        $ende = $ende !== null && $ende !== '' ? $ende : null;

        if ($beginn && !$ende) {
            try {
                $ende = Carbon::parse($beginn)->addYear()->startOfMonth()->subDay()->format('Y-m-d');
            } catch (\Throwable) {
                // beginn nicht parsebar — ende leer lassen
            }
        }

        return ['vertragsbeginn' => $beginn, 'vertragsende' => $ende];
    }

    /**
     * Build HTML for §15/§16 pre-signing data.
     */
    public static function buildPreSigningHtml(array $data): string
    {
        return self::buildPar15Html($data) . self::buildPar16Html($data);
    }

    public static function buildPar15Html(array $data): string
    {
        $header = '<h2 style="margin-top:24px;margin-bottom:8px;">§ 15 Angaben zu kurzfristigen Beschäftigungen</h2>';
        $intro = '<p style="margin-bottom:8px;">Der Arbeitnehmer erklärt hiermit zu kurzfristigen Beschäftigungsverhältnissen in den letzten 12 Monaten:</p>';

        if (empty($data['par15_has_previous'])) {
            return $header . $intro
                . '<p style="margin-bottom:16px;"><strong>Nein</strong>, ich war in den letzten 12 Monaten nicht kurzfristig beschäftigt.</p>';
        }

        if (empty($data['par15_entries'])) {
            return '';
        }

        $tableStyle = 'width:100%;border-collapse:collapse;margin-top:8px;margin-bottom:16px;';
        $thStyle = 'border:1px solid #d1d5db;padding:6px 10px;background:#f3f4f6;text-align:left;font-size:13px;';
        $tdStyle = 'border:1px solid #d1d5db;padding:6px 10px;font-size:13px;';

        $html = $header . $intro
            . '<p style="margin-bottom:4px;"><strong>Ja</strong>, folgende kurzfristigen Beschäftigungen lagen vor:</p>';
        $html .= '<table style="' . $tableStyle . '">';
        $html .= '<thead><tr><th style="' . $thStyle . '">Beginn</th><th style="' . $thStyle . '">Ende</th><th style="' . $thStyle . '">Arbeitgeber</th><th style="' . $thStyle . '">Tage</th></tr></thead><tbody>';
        foreach ($data['par15_entries'] as $entry) {
            $html .= '<tr>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['beginn'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['ende'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['arbeitgeber'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['tage'] ?? '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    public static function buildPar16Html(array $data): string
    {
        $header = '<h2 style="margin-top:24px;margin-bottom:8px;">§ 16 Angaben zu beschäftigungslosen Zeiten</h2>';
        $intro = '<p style="margin-bottom:8px;">Der Arbeitnehmer erklärt hiermit zu Meldungen bei der Arbeitsagentur in den letzten 12 Monaten:</p>';

        if (empty($data['par16_was_jobseeking'])) {
            return $header . $intro
                . '<p style="margin-bottom:16px;"><strong>Nein</strong>, ich war in den letzten 12 Monaten nicht bei der Arbeitsagentur als arbeitssuchend gemeldet.</p>';
        }

        if (empty($data['par16_entries'])) {
            return '';
        }

        $tableStyle = 'width:100%;border-collapse:collapse;margin-top:8px;margin-bottom:16px;';
        $thStyle = 'border:1px solid #d1d5db;padding:6px 10px;background:#f3f4f6;text-align:left;font-size:13px;';
        $tdStyle = 'border:1px solid #d1d5db;padding:6px 10px;font-size:13px;';

        $html = $header . $intro
            . '<p style="margin-bottom:4px;"><strong>Ja</strong>, folgende Zeiten der Meldung als arbeitssuchend lagen vor:</p>';
        $html .= '<table style="' . $tableStyle . '">';
        $html .= '<thead><tr><th style="' . $thStyle . '">Beginn</th><th style="' . $thStyle . '">Ende</th><th style="' . $thStyle . '">Arbeitsagentur</th></tr></thead><tbody>';
        foreach ($data['par16_entries'] as $entry) {
            $html .= '<tr>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['beginn'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['ende'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['arbeitsagentur'] ?? '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    public static function embedPreSigningData(string $content, array $data): string
    {
        // Typ-Weiche. Bestandszeilen haben kein 'type' — sie sind immer
        // §15/§16, weil das bis AT-140 der einzige Vorschalt-Schritt war.
        // Ohne diese Weiche wuerden an eine 140-Tage-Erklaerung die
        // §15/§16-Verneinungsbloecke angehaengt (siehe buildPar15Html).
        //
        // Die gesamte Entscheidung liegt in embed(): null heisst "nicht
        // zustaendig". Fehlt die Zahl, gibt embed() den Inhalt unveraendert
        // zurueck — der Platzhalter bleibt stehen, damit der Guard greift.
        $resttageContent = ResttagePlaceholder::embed($content, $data);
        if ($resttageContent !== null) {
            return $resttageContent;
        }

        $par15Html = self::buildPar15Html($data);
        $par16Html = self::buildPar16Html($data);

        $combined = $par15Html . $par16Html;
        if ($combined === '') {
            return $content;
        }

        // Prefer inserting before §17 so §15/§16 slot in between §14 and §17.
        $par17Pos = self::findSectionPosition($content, '17');
        if ($par17Pos !== false) {
            return substr($content, 0, $par17Pos) . $combined . substr($content, $par17Pos);
        }

        // Next choice: template already has §15/§16 markers (legacy) — insert each
        // into its own section.
        $par15Pos = self::findSectionPosition($content, '15');
        $par16Pos = self::findSectionPosition($content, '16');

        if ($par15Pos !== false || $par16Pos !== false) {
            if ($par15Html && $par15Pos !== false) {
                $insertPos = self::findSectionEnd($content, $par15Pos);
                $content = substr($content, 0, $insertPos) . $par15Html . substr($content, $insertPos);
            } elseif ($par15Html) {
                $content .= $par15Html;
            }

            if ($par16Html) {
                $par16Pos = self::findSectionPosition($content, '16');
                if ($par16Pos !== false) {
                    $insertPos = self::findSectionEnd($content, $par16Pos);
                    $content = substr($content, 0, $insertPos) . $par16Html . substr($content, $insertPos);
                } else {
                    $content .= $par16Html;
                }
            }

            return $content;
        }

        // Fallback: append at end.
        return $content . $combined;
    }

    private static function findSectionPosition(string $content, string $number): int|false
    {
        $patterns = [
            '§\s*' . $number . '\b',
            '&sect;\s*' . $number . '\b',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/u', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $matchPos = $matches[0][1];
                $tagStart = strrpos(substr($content, 0, $matchPos), '<');
                return $tagStart !== false ? $tagStart : $matchPos;
            }
        }

        return false;
    }

    private static function findSectionEnd(string $content, int $startPos): int
    {
        $remaining = substr($content, $startPos + 1);

        $patterns = [
            '/§\s*\d+\b/u',
            '/&sect;\s*\d+\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $remaining, $matches, PREG_OFFSET_CAPTURE)) {
                $nextSectionOffset = $matches[0][1];
                $tagStart = strrpos(substr($remaining, 0, $nextSectionOffset), '<');
                $insertPos = $startPos + 1 + ($tagStart !== false ? $tagStart : $nextSectionOffset);
                return $insertPos;
            }
        }

        return strlen($content);
    }
}
