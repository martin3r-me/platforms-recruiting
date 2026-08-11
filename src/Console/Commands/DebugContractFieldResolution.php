<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Services\Zas\ZasLookupResolver;

class DebugContractFieldResolution extends Command
{
    protected $signature = 'recruiting:debug-contract-resolution
        {id? : Direkte ID des RecContract zum Debuggen (optional wenn --applicant gesetzt)}
        {--applicant= : Stattdessen Applicant-ID (aus URL /recruiting/applicants/{id}). Nimmt den jüngsten Vertrag.}
        {--latest : Nimmt den zuletzt angelegten Vertrag im gesamten System (Schnell-Debug).}';

    protected $description = 'Zeigt für einen gegebenen Vertrag: Template-field_mappings, Resolver-Ergebnisse, sowie Applicant-Extra-Fields und CRM-Postal-Adressen — damit klar wird, warum Placeholder leer bleiben.';

    public function handle(): int
    {
        $contractId = $this->argument('id');
        $applicantId = $this->option('applicant');
        $latest = (bool) $this->option('latest');

        $query = RecContract::with([
            'contractTemplate',
            'applicant.crmContactLinks.contact.postalAddresses',
            'applicant.crmContactLinks.contact.emailAddresses',
            'applicant.crmContactLinks.contact.phoneNumbers',
        ]);

        if ($contractId) {
            $contract = $query->find((int) $contractId);
        } elseif ($applicantId) {
            $contract = $query->where('rec_applicant_id', (int) $applicantId)
                ->orderByDesc('id')
                ->first();
            if (!$contract) {
                $this->error("Keine Verträge für Applicant #{$applicantId} gefunden.");
                return self::FAILURE;
            }
            $this->line("→ Jüngster Vertrag für Applicant #{$applicantId}: RecContract #{$contract->id}");
        } elseif ($latest) {
            $contract = $query->orderByDesc('id')->first();
            if (!$contract) {
                $this->error('Keine Verträge im System.');
                return self::FAILURE;
            }
            $this->line("→ Jüngster Vertrag: RecContract #{$contract->id}");
        } else {
            $this->error('Bitte entweder {id}, --applicant=<id> oder --latest angeben.');
            return self::FAILURE;
        }

        if (!$contract) {
            $this->error("RecContract #{$contractId} nicht gefunden.");
            return self::FAILURE;
        }

        $template = $contract->contractTemplate;
        $applicant = $contract->applicant;
        $contact = $applicant?->crmContactLinks->first()?->contact;

        $this->components->info("Contract #{$contract->id} — Template: \"{$template?->name}\" (code={$template?->code})");
        $this->line("Applicant #{$applicant?->id}, Contact #{$contact?->id} (" . ($contact?->first_name ?? '?') . ' ' . ($contact?->last_name ?? '?') . ')');
        $this->newLine();

        $this->components->info('CRM-Contact direkte Spalten:');
        if ($contact) {
            $this->line('  birth_date     = ' . var_export($contact->birth_date, true));
            $this->line('  first_name     = ' . var_export($contact->first_name, true));
            $this->line('  last_name      = ' . var_export($contact->last_name, true));
        } else {
            $this->warn('  (kein Contact)');
        }
        $this->newLine();

        $this->components->info('CRM-Postal-Adressen (addressable = Contact):');
        if ($contact && $contact->postalAddresses->isNotEmpty()) {
            foreach ($contact->postalAddresses as $addr) {
                $this->line("  #{$addr->id} primary=" . ($addr->is_primary ? '1' : '0')
                    . " street={$addr->street} hausnr={$addr->house_number} plz={$addr->postal_code} city={$addr->city}");
            }
        } else {
            $this->warn('  (keine Postal-Adressen verknüpft)');
        }
        $this->newLine();

        $this->components->info('Applicant Extra-Fields (alle):');
        if ($applicant) {
            $arr = $applicant->getExtraFieldsArray();
            foreach ($arr as $name => $val) {
                $this->line('  ' . str_pad($name, 30) . '= ' . (is_scalar($val) ? var_export($val, true) : json_encode($val)));
            }
        }
        $this->newLine();

        $this->components->info('Template field_mappings + Resolver-Resultat:');
        $mappings = $template?->field_mappings ?? [];
        if (empty($mappings)) {
            $this->warn('  (keine field_mappings gesetzt)');
        } else {
            $resolved = [];
            $reflection = new \ReflectionClass($template);
            $method = $reflection->getMethod('resolveSource');
            $method->setAccessible(true);
            $lookups = new ZasLookupResolver();
            foreach ($mappings as $placeholder => $source) {
                $value = $method->invoke($template, $source, $applicant, $contact, $contract, $lookups);
                $status = $value === '' ? '⚠ LEER' : '✓';
                $this->line('  ' . str_pad('{{' . $placeholder . '}}', 28) . '→ '
                    . str_pad($source, 40) . '= ' . $status . ' ' . (is_string($value) ? $value : json_encode($value)));
            }
        }

        return self::SUCCESS;
    }
}
