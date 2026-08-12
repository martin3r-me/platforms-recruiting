<?php

namespace Platform\Recruiting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Core\Traits\HasExtraFields;
use Platform\Recruiting\Services\Zas\ZasLookupResolver;
use Symfony\Component\Uid\UuidV7;

/**
 * ACHTUNG, Zertifikat-Teil dieser Klasse: er laeuft leer, und zwar mit Absicht.
 *
 * TYPE_CERTIFICATE, CERTIFICATE_CODE_PREFIX, scopeCertificates() und die zwei
 * Invarianten im saving-Hook (requires_signature=false, code-Praefix 'ZERT-')
 * haben seit dem Zuschnitt des Zertifikat-Pakets KEINEN Konsumenten mehr. Der
 * Zertifikat-Inhalt steht als festes HTML in TrainingCertificateHtml, nicht als
 * Vorlage in dieser Tabelle — es gibt also keine Zeile mit type='certificate',
 * und die Invarianten werden nie ausgeloest.
 *
 * WARUM SIE TROTZDEM STEHEN BLEIBEN, statt zurueckgebaut zu werden: sie
 * bewachen die Tuer fuer den Rueckweg. Legt jemand spaeter doch eine
 * Zertifikat-Vorlage an — per Hand, per SQL oder ueber die MCP-Tools
 * CreateContractTemplateTool/UpdateContractTemplateTool —, dann greift der
 * Praefixzwang sofort, ohne dass jemand daran denken muss. Der Praefix ist die
 * einzige Garantie, dass die bestehenden code-Muster-Filter ('AV%', 'AT-%',
 * 'IFSG') so eine Zeile nicht erwischen; ohne ihn bekaeme ein Zertifikat mit
 * code 'AV-ZERT' die §15/§16-Abfrage vor der Unterschrift.
 *
 * DAS IST EIN TOTER SCHALTER, und tote Schalter haben in diesem Repo schon
 * einmal Zeit gekostet (config('recruiting.sidebar') zeigte auf eine Konfig,
 * die nichts mehr steuerte). Deshalb steht es hier ausdruecklich: leer laufend
 * ist der Soll-Zustand, nicht ein Symptom. Wer den Zertifikat-Teil "aufraeumen"
 * will, entfernt damit den Schutz fuer den Rueckweg — dann bitte zusammen mit
 * der type-Spalte (Migration 2026_08_12_000001) und mit einem Blick in
 * docs/zertifikat/guard-landkarte-511451c.md, welche 22 Guards dann faellig
 * werden.
 */
class RecContractTemplate extends Model
{
    use SoftDeletes;
    use HasExtraFields;

    protected $table = 'rec_contract_templates';

    public const TYPE_CONTRACT = 'contract';

    /** Ohne Konsumenten — siehe Klassen-Docblock. */
    public const TYPE_CERTIFICATE = 'certificate';

    /**
     * Zertifikat-Codes muessen mit diesem Praefix beginnen. Grund:
     * ContractPreSigningType::forCode() entscheidet ALLEIN am code, ob ein
     * Vorschalt-Schritt vor der Unterschrift laeuft (AT-140 → Resttage,
     * Praefix AV- → §15/§16). Ein Zertifikat mit code 'AV-ZERT' bekaeme die
     * §15/§16-Abfrage. Der Praefix macht die Kollision unmoeglich statt
     * unwahrscheinlich — und er schuetzt zwoelf code-Muster-Filter in der
     * Guard-Landkarte, die sonst nur auf eine Konvention vertrauen.
     */
    public const CERTIFICATE_CODE_PREFIX = 'ZERT-';

    /**
     * Spalten-Default 'contract' auch am frischen, noch nicht gespeicherten
     * Objekt: `new self(['code' => 'AV-010'])` liest `type` schon vor einem
     * `save()` als 'contract', genau wie die DB-Spalte es per Default tut.
     * Bewusst NICHT im creating-Hook gesetzt — der feuert erst beim
     * Speichern und liesse `$model->type` bis dahin `null`, obwohl Code wie
     * tests/Integration/ContractPdfRegressionTest.php:161/182 Instanzen per
     * `new` ohne save() baut und (perspektivisch, siehe Task 9/15) `type`
     * lesen koennte.
     */
    protected $attributes = [
        'type' => self::TYPE_CONTRACT,
    ];

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'type',
        'description',
        'content',
        'field_mappings',
        'requires_signature',
        'is_active',
        'sort_order',
        'team_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'field_mappings' => 'array',
        'requires_signature' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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

        static::saving(function (self $model) {
            if ($model->type !== self::TYPE_CERTIFICATE) {
                return;
            }

            // Invariante 1: ein Zertifikat unterschreibt niemand.
            $model->requires_signature = false;

            // Invariante 2: Praefix-Zwang. Exception statt stiller Korrektur —
            // ein automatisch umgeschriebener code wuerde Verweise brechen,
            // die der Aufrufer schon gesetzt hat.
            $code = (string) $model->code;
            if (!str_starts_with($code, self::CERTIFICATE_CODE_PREFIX)) {
                throw new \InvalidArgumentException(
                    'Zertifikat-Vorlagen brauchen einen code mit Praefix "'
                    . self::CERTIFICATE_CODE_PREFIX . '" (bekommen: "' . $code . '").'
                );
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(RecContract::class, 'rec_contract_template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeContracts($query)
    {
        return $query->where('type', self::TYPE_CONTRACT);
    }

    public function scopeCertificates($query)
    {
        return $query->where('type', self::TYPE_CERTIFICATE);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function personalizeContent(RecApplicant $applicant, ?RecContract $contract = null): string
    {
        $content = $this->content ?? '';
        $mappings = $this->field_mappings ?? [];

        if (empty($mappings) || empty($content)) {
            return $content;
        }

        $applicant->load([
            'crmContactLinks.contact.emailAddresses',
            'crmContactLinks.contact.phoneNumbers',
            'crmContactLinks.contact.postalAddresses',
        ]);
        $contactModel = $applicant->crmContactLinks->first()?->contact;

        // Eine Resolver-Instanz pro Dokument: der Label-Cache lebt genau so
        // lange wie dieser Render-Vorgang. Bewusst kein Singleton — ein
        // langlebiger Queue-Worker wuerde sonst veraltete Labels ausliefern.
        $lookups = new ZasLookupResolver();

        $replacements = [];
        foreach ($mappings as $placeholder => $source) {
            $replacements['{{' . $placeholder . '}}'] = $this->resolveSource($source, $applicant, $contactModel, $contract, $lookups);
        }

        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        // Strip white/near-white color styles from TinyMCE dark mode artifacts
        $content = preg_replace('/color:\s*(?:white|#fff(?:fff)?|rgb\(\s*255\s*,\s*255\s*,\s*255\s*\))\s*;?/i', '', $content);

        return $content;
    }

    private function resolveSource(string $source, RecApplicant $applicant, $contact, ?RecContract $contract, ?ZasLookupResolver $lookups = null): string
    {
        if (str_starts_with($source, 'contact.')) {
            if (!$contact) {
                return '';
            }

            $field = substr($source, strlen('contact.'));

            if ($field === 'email') {
                return (string) ($contact->emailAddresses->where('is_primary', true)->first()?->email_address ?? $contact->emailAddresses->first()?->email_address ?? '');
            }

            if ($field === 'phone') {
                $phone = $contact->phoneNumbers->where('is_primary', true)->first() ?? $contact->phoneNumbers->first();
                return (string) ($phone?->international ?? $phone?->national ?? '');
            }

            if (str_starts_with($field, 'address.')) {
                $addressField = substr($field, strlen('address.'));
                $address = $contact->postalAddresses->where('is_primary', true)->first() ?? $contact->postalAddresses->first();
                return (string) ($address?->{$addressField} ?? '');
            }

            $value = $contact->{$field} ?? '';
            if ($value instanceof Carbon) {
                return $value->format('d.m.Y');
            }
            return (string) $value;
        }

        if (str_starts_with($source, 'applicant.')) {
            $field = substr($source, strlen('applicant.'));

            if (str_starts_with($field, 'extra_field.')) {
                $efName = substr($field, strlen('extra_field.'));
                $value = $applicant->getExtraField($efName);
                if ($value === null || $value === '') {
                    return '';
                }

                // Lookup-Felder speichern den Maschinenwert ("tr") — im Dokument
                // muss das Label stehen ("Türkei"). Nur wenn die Definition
                // wirklich ein Lookup ist, sonst bleibt alles wie gehabt.
                if ($lookups !== null) {
                    $definition = $applicant->getExtraFieldDefinitions()->firstWhere('name', $efName);
                    $lookupId = $definition?->options['lookup_id'] ?? null;
                    if ($lookupId) {
                        $label = $lookups->resolveLabel((int) $definition->id, $value);
                        if ($label !== null && $label !== '') {
                            return $label;
                        }
                    }
                }

                if ($value instanceof Carbon) {
                    return $value->format('d.m.Y');
                }
                if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}|$)/', $value)) {
                    try {
                        return Carbon::parse($value)->format('d.m.Y');
                    } catch (\Throwable) {
                        return trim($value);
                    }
                }
                return is_scalar($value) ? trim((string) $value) : (string) $value;
            }

            $value = $applicant->{$field} ?? '';
            // Zuschlag ist ein Geldbetrag → deutsches Format (0,60) wie im settings.-Zweig.
            if ($field === 'zuschlag' && $value !== '' && is_numeric($value)) {
                return number_format((float) $value, 2, ',', '.');
            }
            return (string) $value;
        }

        if (str_starts_with($source, 'contract.extra_field.') && $contract) {
            $efName = substr($source, strlen('contract.extra_field.'));
            return (string) ($contract->getExtraField($efName) ?? '');
        }

        if (str_starts_with($source, 'settings.')) {
            $key = substr($source, strlen('settings.'));
            $settings = RecApplicantSettings::getOrCreateForTeam($applicant->team_id);
            $value = $settings->settings[$key] ?? (RecApplicantSettings::DEFAULT_SETTINGS[$key] ?? null);
            if ($value === null) {
                return '';
            }
            if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
                return number_format((float) $value, 2, ',', '.');
            }
            if (is_bool($value)) {
                return $value ? 'ja' : 'nein';
            }
            return (string) $value;
        }

        if (str_starts_with($source, 'text:')) {
            return substr($source, strlen('text:'));
        }

        if (str_starts_with($source, 'meta.')) {
            $metaKey = substr($source, strlen('meta.'));
            return match ($metaKey) {
                'datum_heute' => Carbon::now()->format('d.m.Y'),
                'ort' => '',
                default => '',
            };
        }

        return '';
    }
}
