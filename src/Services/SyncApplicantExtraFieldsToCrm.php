<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Platform\Crm\Models\CrmAddressType;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmPostalAddress;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Propagates applicant-form inputs that belong in canonical CRM storage
 * (address, birth date) from the rec_applicant extra-field bucket into
 * the CRM domain.
 *
 * Deliberately NOT synced:
 *  - geburtsort (CRM has no birth_place column; template mappings keep
 *    using applicant.extra_field.geburtsort)
 *  - ausweisnummer/steuer_id/iban etc. (not CRM-domain data)
 */
class SyncApplicantExtraFieldsToCrm
{
    public function sync(RecApplicant $applicant): SyncResult
    {
        $result = new SyncResult();

        $contact = $applicant->crmContactLinks->first()?->contact;
        if (!$contact) {
            $result->skipped[] = 'no CRM contact linked to applicant';
            return $result;
        }

        $this->syncBirthDate($contact, $applicant, $result);
        $this->syncPostalAddress($contact, $applicant, $result);

        return $result;
    }

    private function syncBirthDate(CrmContact $contact, RecApplicant $applicant, SyncResult $result): void
    {
        $raw = $applicant->getExtraField('geburtsdatum');
        if ($raw === null || $raw === '') {
            $result->skipped[] = 'geburtsdatum empty on applicant';
            return;
        }

        $parsed = $this->parseDate($raw);
        if (!$parsed) {
            $result->skipped[] = "geburtsdatum unparseable: " . var_export($raw, true);
            return;
        }

        if ($contact->birth_date && $contact->birth_date->isSameDay($parsed)) {
            $result->unchanged[] = 'birth_date already synced';
            return;
        }

        $contact->birth_date = $parsed;
        $contact->save();
        $result->changed[] = 'birth_date → ' . $parsed->format('Y-m-d');
    }

    private function syncPostalAddress(CrmContact $contact, RecApplicant $applicant, SyncResult $result): void
    {
        $street  = $this->cleanString($applicant->getExtraField('strasse'));
        $houseNr = $this->cleanString($applicant->getExtraField('hausnummer'));
        $postal  = $this->cleanString($applicant->getExtraField('plz'));
        $city    = $this->cleanString($applicant->getExtraField('stadt'));

        $hasAny = $street !== '' || $houseNr !== '' || $postal !== '' || $city !== '';
        if (!$hasAny) {
            $result->skipped[] = 'no address fields present on applicant';
            return;
        }

        $address = $contact->postalAddresses()->where('is_primary', true)->first()
            ?? $contact->postalAddresses()->first();

        if (!$address) {
            $addressTypeId = $this->resolvePrivateAddressTypeId();
            if (!$addressTypeId) {
                $result->skipped[] = 'cannot create postal address: no CrmAddressType PRIVATE seeded';
                return;
            }
            $address = $contact->postalAddresses()->create([
                'street'          => $street,
                'house_number'    => $houseNr,
                'postal_code'     => $postal,
                'city'            => $city,
                'address_type_id' => $addressTypeId,
                'is_primary'      => true,
                'is_active'       => true,
            ]);
            $result->changed[] = "created postal address ({$street} {$houseNr}, {$postal} {$city})";
            return;
        }

        $dirty = [];
        if ($street !== ''  && $address->street       !== $street)  { $address->street       = $street;  $dirty[] = "street={$street}"; }
        if ($houseNr !== '' && $address->house_number !== $houseNr) { $address->house_number = $houseNr; $dirty[] = "house_number={$houseNr}"; }
        if ($postal !== ''  && $address->postal_code  !== $postal)  { $address->postal_code  = $postal;  $dirty[] = "postal_code={$postal}"; }
        if ($city !== ''    && $address->city         !== $city)    { $address->city         = $city;    $dirty[] = "city={$city}"; }
        if (!$address->is_primary)                                   { $address->is_primary   = true;    $dirty[] = "is_primary=1"; }

        if (empty($dirty)) {
            $result->unchanged[] = 'postal address already synced';
            return;
        }

        $address->save();
        $result->changed[] = 'updated postal address (' . implode(', ', $dirty) . ')';
    }

    private ?int $privateAddressTypeIdCache = null;
    private bool $privateAddressTypeIdResolved = false;

    private function resolvePrivateAddressTypeId(): ?int
    {
        if ($this->privateAddressTypeIdResolved) {
            return $this->privateAddressTypeIdCache;
        }
        $this->privateAddressTypeIdResolved = true;
        $this->privateAddressTypeIdCache = CrmAddressType::where('code', 'PRIVATE')->value('id');
        return $this->privateAddressTypeIdCache;
    }

    private function cleanString(mixed $value): string
    {
        if ($value === null) return '';
        if (!is_scalar($value)) return '';
        return trim((string) $value);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            try { return Carbon::parse($value)->startOfDay(); } catch (\Throwable) { return null; }
        }
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
            try { return Carbon::createFromFormat('d.m.Y', $value)->startOfDay(); } catch (\Throwable) { return null; }
        }
        return null;
    }
}
