<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecContract;

class DebugContractFieldResolution extends Command
{
    protected $signature = 'recruiting:debug-contract-resolution
        {contractId : ID des RecContract zum Debuggen}';

    protected $description = 'Zeigt für einen gegebenen Vertrag: Template-field_mappings, Resolver-Ergebnisse, sowie Applicant-Extra-Fields und CRM-Postal-Adressen — damit klar wird, warum Placeholder leer bleiben.';

    public function handle(): int
    {
        $contractId = (int) $this->argument('contractId');

        $contract = RecContract::with([
            'contractTemplate',
            'applicant.crmContactLinks.contact.postalAddresses',
            'applicant.crmContactLinks.contact.emailAddresses',
            'applicant.crmContactLinks.contact.phoneNumbers',
        ])->find($contractId);

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
            foreach ($mappings as $placeholder => $source) {
                $value = $method->invoke($template, $source, $applicant, $contact, $contract);
                $status = $value === '' ? '⚠ LEER' : '✓';
                $this->line('  ' . str_pad('{{' . $placeholder . '}}', 28) . '→ '
                    . str_pad($source, 40) . '= ' . $status . ' ' . (is_string($value) ? $value : json_encode($value)));
            }
        }

        return self::SUCCESS;
    }
}
