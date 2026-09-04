<?php

namespace Platform\Recruiting\Services\Zas;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Zieht die Telefonnummer der verknuepften CRM-Kontakte auf den Akten-Stand
 * (Vorfall RG19734, 04.09.): der Kontakt ist sonst eine eingefrorene
 * Momentaufnahme vom Verknuepfungszeitpunkt. Das ist mehr als Kosmetik —
 * eingehende WhatsApp-Nachrichten werden ueber die Kontakt-Nummer zugeordnet;
 * mit veralteter Nummer landet die Antwort des MA in einem unverknuepften
 * Thread und ist im Chat der Person unsichtbar.
 *
 * Vorgehen pro Kontakt: passt KEINE aktive Kontakt-Nummer zur Akten-Nummer
 * (Vergleich letzte 9 Ziffern, formatunabhaengig), werden die abweichenden
 * aktiven Nummern deaktiviert (nicht geloescht — Historie bleibt) und die
 * Akten-Nummer als neue primaere Mobilnummer angelegt. Schreibweg wie im
 * ZasEmployeeContactLinker (Eloquent-Relation) — CrmPhoneNumber touch't den
 * Kontakt, damit CardDAV die Aenderung mitbekommt.
 */
class ContactPhoneSync
{
    /**
     * @return array{status: 'synced'|'match'|'no_phone'|'no_contact'|'unparseable'|'no_type', contacts: int}
     */
    public function syncEmployee(RecEmployee $employee, bool $dryRun = false): array
    {
        $raw = trim((string) $employee->phone);
        if ($raw === '') {
            return ['status' => 'no_phone', 'contacts' => 0];
        }
        $e164 = PhoneE164::normalize($raw);
        if ($e164 === null) {
            return ['status' => 'unparseable', 'contacts' => 0];
        }

        $contactIds = CrmContactLink::query()
            ->where('linkable_type', 'LIKE', '%RecEmployee')
            ->where('linkable_id', $employee->id)
            ->pluck('contact_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();
        if ($contactIds->isEmpty()) {
            return ['status' => 'no_contact', 'contacts' => 0];
        }

        $suffix = self::suffix($e164);
        $changedContacts = 0;

        foreach ($contactIds as $contactId) {
            $rows = CrmPhoneNumber::query()
                ->where('phoneable_id', $contactId)
                ->where(fn ($q) => $q->where('phoneable_type', 'crm_contact')->orWhere('phoneable_type', 'LIKE', '%CrmContact'))
                ->where('is_active', true)
                ->get();

            $matches = $rows->contains(fn ($pn) => self::suffix((string) ($pn->international ?? '')) === $suffix
                || self::suffix((string) ($pn->raw_input ?? '')) === $suffix);
            if ($matches) {
                continue;
            }

            $changedContacts++;
            if ($dryRun) {
                continue;
            }

            foreach ($rows as $pn) {
                $pn->is_active = false;
                $pn->is_primary = false;
                $pn->save();
            }

            if (!$this->createPhone($contactId, $e164)) {
                return ['status' => 'no_type', 'contacts' => $changedContacts];
            }
        }

        return ['status' => $changedContacts > 0 ? 'synced' : 'match', 'contacts' => $changedContacts];
    }

    private function createPhone(int $contactId, string $e164): bool
    {
        $phoneTypeId = CrmPhoneType::where('code', 'MOBILE')->value('id')
            ?? CrmPhoneType::where('is_active', true)->value('id')
            ?? CrmPhoneType::query()->value('id');
        if (!$phoneTypeId) {
            return false;
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($e164, 'DE');
        } catch (NumberParseException) {
            return false;
        }

        $contact = \Platform\Crm\Models\CrmContact::find($contactId);
        if ($contact === null) {
            return false;
        }
        $contact->phoneNumbers()->create([
            'raw_input'     => $e164,
            'international' => $util->format($parsed, PhoneNumberFormat::E164),
            'national'      => $util->format($parsed, PhoneNumberFormat::NATIONAL),
            'country_code'  => $util->getRegionCodeForNumber($parsed),
            'phone_type_id' => (int) $phoneTypeId,
            'is_primary'    => true,
            'is_active'     => true,
        ]);

        return true;
    }

    private static function suffix(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return substr($digits, -9);
    }
}
