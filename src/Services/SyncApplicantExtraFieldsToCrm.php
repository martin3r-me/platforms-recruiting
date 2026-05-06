<?php

namespace Platform\Recruiting\Services;

use Carbon\Carbon;
use Platform\Crm\Models\CrmAddressType;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmEmailType;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Crm\Models\CrmPostalAddress;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Propagates applicant-form inputs that belong in canonical CRM storage
 * (first/last name, email, phone, address, birth date) from the rec_applicant
 * extra-field bucket into the CRM domain.
 *
 * Replaces the HCM SyncsCrmContactFields-Trait fuer Recruiting-Bewerber:
 * der Trait setzt bei Email-Inserts kein email_type_id (NOT NULL ohne
 * Default → SQLSTATE 1364) — Recruiting-internes Find-or-Update mit
 * korrektem Type-Resolver verhindert das.
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

        $this->syncFirstName($contact, $applicant, $result);
        $this->syncLastName($contact, $applicant, $result);
        $this->syncBirthDate($contact, $applicant, $result);
        $this->syncEmail($contact, $applicant, $result);
        $this->syncPhone($contact, $applicant, $result);
        $this->syncPostalAddress($contact, $applicant, $result);

        return $result;
    }

    private function syncFirstName(CrmContact $contact, RecApplicant $applicant, SyncResult $result): void
    {
        $value = $this->cleanString($applicant->getExtraField('vorname'));
        if ($value === '') {
            $result->skipped[] = 'vorname empty on applicant';
            return;
        }
        if ($contact->first_name === $value) {
            $result->unchanged[] = 'first_name already synced';
            return;
        }
        $contact->first_name = $value;
        $contact->save();
        $result->changed[] = "first_name → {$value}";
    }

    private function syncLastName(CrmContact $contact, RecApplicant $applicant, SyncResult $result): void
    {
        $value = $this->cleanString($applicant->getExtraField('nachname'));
        if ($value === '') {
            $result->skipped[] = 'nachname empty on applicant';
            return;
        }
        if ($contact->last_name === $value) {
            $result->unchanged[] = 'last_name already synced';
            return;
        }
        $contact->last_name = $value;
        $contact->save();
        $result->changed[] = "last_name → {$value}";
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

    private function syncEmail(CrmContact $contact, RecApplicant $applicant, SyncResult $result): void
    {
        $value = $this->extractEmail($applicant->getExtraField('email'));
        if ($value === '') {
            $result->skipped[] = 'email empty on applicant';
            return;
        }

        // Find-or-update: existiert die Mail bereits am Contact?
        $existing = $contact->emailAddresses()
            ->where('email_address', $value)
            ->first();

        if ($existing) {
            if (!$existing->is_primary) {
                // Andere primary entwerten, neuen primary setzen
                $contact->emailAddresses()
                    ->where('is_primary', true)
                    ->where('id', '!=', $existing->id)
                    ->update(['is_primary' => false]);
                $existing->is_primary = true;
                $existing->is_active = true;
                $existing->save();
                $result->changed[] = "email {$value} → primary";
                return;
            }
            $result->unchanged[] = "email {$value} already primary";
            return;
        }

        $emailTypeId = $this->resolveEmailTypeId();
        if (!$emailTypeId) {
            $result->skipped[] = 'cannot create email: no CrmEmailType seeded';
            return;
        }

        $contact->emailAddresses()
            ->where('is_primary', true)
            ->update(['is_primary' => false]);

        $contact->emailAddresses()->create([
            'email_address' => $value,
            'is_primary'    => true,
            'is_active'     => true,
            'email_type_id' => $emailTypeId,
        ]);
        $result->changed[] = "created primary email: {$value}";
    }

    private function syncPhone(CrmContact $contact, RecApplicant $applicant, SyncResult $result): void
    {
        $extracted = $this->extractPhone($applicant->getExtraField('telefonnummer'));
        if ($extracted === null) {
            $result->skipped[] = 'telefonnummer empty/invalid on applicant';
            return;
        }

        // Find-or-update: bestehender Eintrag mit gleichem international/raw?
        $existing = null;
        if ($extracted['international'] !== '') {
            $existing = $contact->phoneNumbers()
                ->where('international', $extracted['international'])
                ->first();
        }
        if (!$existing && $extracted['raw'] !== '') {
            $existing = $contact->phoneNumbers()
                ->where('raw_input', $extracted['raw'])
                ->first();
        }

        if ($existing) {
            if (!$existing->is_primary) {
                $contact->phoneNumbers()
                    ->where('is_primary', true)
                    ->where('id', '!=', $existing->id)
                    ->update(['is_primary' => false]);
                $existing->is_primary = true;
                $existing->is_active = true;
                $existing->save();
                $result->changed[] = "phone " . ($extracted['international'] ?: $extracted['raw']) . " → primary";
                return;
            }
            $result->unchanged[] = "phone already primary";
            return;
        }

        $phoneTypeId = $this->resolvePhoneTypeId();
        if (!$phoneTypeId) {
            $result->skipped[] = 'cannot create phone: no CrmPhoneType seeded';
            return;
        }

        $contact->phoneNumbers()
            ->where('is_primary', true)
            ->update(['is_primary' => false]);

        $contact->phoneNumbers()->create([
            'raw_input'     => $extracted['raw'] !== '' ? $extracted['raw'] : $extracted['international'],
            'international' => $extracted['international'] ?: null,
            'country_code'  => $extracted['country'] !== '' ? $extracted['country'] : null,
            'phone_type_id' => $phoneTypeId,
            'is_primary'    => true,
            'is_active'     => true,
        ]);
        $result->changed[] = "created primary phone: " . ($extracted['international'] ?: $extracted['raw']);
    }

    /**
     * Akzeptiert string ('mail@example.com') oder array (['email' => '...']).
     */
    private function extractEmail(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            return trim((string) ($value['email'] ?? ''));
        }
        return '';
    }

    /**
     * Akzeptiert string ('+4915112345678' oder '0151...') oder das CRM-typed
     * Phone-Array {raw, country, e164, international}. Liefert immer
     * {international, raw, country} oder null wenn leer.
     */
    private function extractPhone(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $international = trim((string) ($value['international'] ?? $value['e164'] ?? ''));
            $raw           = trim((string) ($value['raw'] ?? ''));
            $country       = trim((string) ($value['country'] ?? ''));
            if ($international === '' && $raw === '') {
                return null;
            }
            return ['international' => $international, 'raw' => $raw, 'country' => $country];
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            // Best effort via libphonenumber (PHP-Lib via vendor) — Fallback: raw only
            try {
                $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
                $parsed    = $phoneUtil->parse($value, 'DE');
                return [
                    'international' => $phoneUtil->format($parsed, \libphonenumber\PhoneNumberFormat::E164),
                    'raw'           => $value,
                    'country'       => $phoneUtil->getRegionCodeForNumber($parsed) ?: 'DE',
                ];
            } catch (\Throwable) {
                return ['international' => '', 'raw' => $value, 'country' => ''];
            }
        }

        return null;
    }

    private ?int $emailTypeIdCache = null;
    private bool $emailTypeIdResolved = false;

    private function resolveEmailTypeId(): ?int
    {
        if ($this->emailTypeIdResolved) {
            return $this->emailTypeIdCache;
        }
        $this->emailTypeIdResolved = true;
        $this->emailTypeIdCache = CrmEmailType::where('code', 'PRIVATE')->value('id')
            ?? CrmEmailType::where('is_active', true)->value('id')
            ?? CrmEmailType::query()->value('id');
        return $this->emailTypeIdCache;
    }

    private ?int $phoneTypeIdCache = null;
    private bool $phoneTypeIdResolved = false;

    private function resolvePhoneTypeId(): ?int
    {
        if ($this->phoneTypeIdResolved) {
            return $this->phoneTypeIdCache;
        }
        $this->phoneTypeIdResolved = true;
        $this->phoneTypeIdCache = CrmPhoneType::where('code', 'MOBILE')->value('id')
            ?? CrmPhoneType::where('is_active', true)->value('id')
            ?? CrmPhoneType::query()->value('id');
        return $this->phoneTypeIdCache;
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
