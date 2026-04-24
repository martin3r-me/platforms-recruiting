<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Contracts\InheritsExtraFields;
use Platform\Core\Traits\HasExtraFields;
use Platform\Core\Traits\HasPublicFormLink;
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
     * Build HTML for §15/§16 pre-signing data.
     */
    public static function buildPreSigningHtml(array $data): string
    {
        return self::buildPar15Html($data) . self::buildPar16Html($data);
    }

    public static function buildPar15Html(array $data): string
    {
        if (empty($data['par15_has_previous'])) {
            return '<div style="margin-top:12px;margin-bottom:16px;"><p style="font-size:13px;font-weight:600;margin-bottom:4px;">Angaben des Arbeitnehmers:</p><p style="font-size:13px;">Nein, ich war in den letzten 12 Monaten nicht kurzfristig beschäftigt.</p></div>';
        }

        if (empty($data['par15_entries'])) {
            return '';
        }

        $tableStyle = 'width:100%;border-collapse:collapse;margin-top:8px;margin-bottom:16px;';
        $thStyle = 'border:1px solid #d1d5db;padding:6px 10px;background:#f3f4f6;text-align:left;font-size:13px;';
        $tdStyle = 'border:1px solid #d1d5db;padding:6px 10px;font-size:13px;';

        $html = '<div style="margin-top:12px;"><p style="font-size:13px;font-weight:600;margin-bottom:4px;">Angaben des Arbeitnehmers:</p>';
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
        $html .= '</tbody></table></div>';

        return $html;
    }

    public static function buildPar16Html(array $data): string
    {
        if (empty($data['par16_was_jobseeking'])) {
            return '<div style="margin-top:12px;margin-bottom:16px;"><p style="font-size:13px;font-weight:600;margin-bottom:4px;">Angaben des Arbeitnehmers:</p><p style="font-size:13px;">Nein, ich war in den letzten 12 Monaten nicht bei der Arbeitsagentur als arbeitssuchend gemeldet.</p></div>';
        }

        if (empty($data['par16_entries'])) {
            return '';
        }

        $tableStyle = 'width:100%;border-collapse:collapse;margin-top:8px;margin-bottom:16px;';
        $thStyle = 'border:1px solid #d1d5db;padding:6px 10px;background:#f3f4f6;text-align:left;font-size:13px;';
        $tdStyle = 'border:1px solid #d1d5db;padding:6px 10px;font-size:13px;';

        $html = '<div style="margin-top:12px;"><p style="font-size:13px;font-weight:600;margin-bottom:4px;">Angaben des Arbeitnehmers:</p>';
        $html .= '<table style="' . $tableStyle . '">';
        $html .= '<thead><tr><th style="' . $thStyle . '">Beginn</th><th style="' . $thStyle . '">Ende</th><th style="' . $thStyle . '">Arbeitsagentur</th></tr></thead><tbody>';
        foreach ($data['par16_entries'] as $entry) {
            $html .= '<tr>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['beginn'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['ende'] ?? '') . '</td>';
            $html .= '<td style="' . $tdStyle . '">' . e($entry['arbeitsagentur'] ?? '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    public static function embedPreSigningData(string $content, array $data): string
    {
        $par15Html = self::buildPar15Html($data);
        $par16Html = self::buildPar16Html($data);

        $par16Pos = self::findSectionPosition($content, '16');
        $par15Pos = self::findSectionPosition($content, '15');

        if ($par15Html) {
            if ($par16Pos !== false && $par15Pos !== false) {
                $content = substr($content, 0, $par16Pos) . $par15Html . substr($content, $par16Pos);
                $par16Pos = self::findSectionPosition($content, '16');
            } elseif ($par15Pos !== false) {
                $insertPos = self::findSectionEnd($content, $par15Pos);
                $content = substr($content, 0, $insertPos) . $par15Html . substr($content, $insertPos);
            } else {
                $content .= $par15Html;
            }
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
