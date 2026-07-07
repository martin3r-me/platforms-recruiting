<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmContactStatus;
use Platform\Crm\Models\CrmEmailType;
use Platform\Crm\Models\CrmPhoneType;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Findet (oder erstellt) den CRM-Kontakt zu einem RecEmployee und verlinkt
 * ihn via crm_contact_links — Grundlage fuer Kommunikation (WhatsApp-Portal-
 * Einladung, Threads) bei MA, die nicht aus dem Recruiting-Flow stammen.
 * Der ZAS-Import legt keine Kontakte an; Recruiting-MA bekommen sie beim
 * Anlegen aus dem Bewerber gespiegelt (CreateEmployeeFromApplicantService).
 *
 * Match-Kaskade (non-destruktiv, Basis: IncomingApplicationService-Konvention,
 * verschaerft um Eindeutigkeits-Guards):
 *   1. E-Mail via LOWER() (CRM speichert gemischte Schreibweise) — nur bei
 *      GENAU EINEM Treffer linken, sonst skip "mehrdeutig"
 *   2. Telefon per Ziffern-Suffix auf international/raw_input — Needle wird
 *      vorher per libphonenumber normalisiert (E164), weil der naive
 *      Ziffern-Vergleich an fuehrender 0 vs. Laendercode scheitert
 *      ("0151..." matcht nie "+49151..."). Ebenfalls nur bei genau 1 Treffer.
 *   3. kein Treffer -> neuen Kontakt anlegen (Name, Geburtsdatum,
 *      E-Mail + Telefon als primaere Eintraege)
 *
 * decide() trifft nur die Entscheidung (traegt den --dry-run des Commands),
 * execute() schreibt — getrennt, damit Match-Entscheidungen VOR dem
 * Ausfuehren geprueft werden koennen (falscher Match = WhatsApp mit
 * Login-Hinweisen an die falsche Person).
 */
class ZasEmployeeContactLinker
{
    public function decide(RecEmployee $employee): array
    {
        if ($employee->crmContactLinks()->exists()) {
            return ['action' => 'skip', 'reason' => 'hat bereits CRM-Kontakt-Link'];
        }

        $email = mb_strtolower(trim((string) $employee->email));
        $phone = trim((string) $employee->phone);

        if ($email !== '') {
            // LOWER() statt Kollations-Vertrauen: CRM speichert gemischte
            // Schreibweise (kein Lowercase-Mutator am Model).
            $matches = CrmContact::where('team_id', $employee->team_id)
                ->where('is_active', true)
                ->whereHas('emailAddresses', fn ($q) => $q->whereRaw('LOWER(email_address) = ?', [$email]))
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $this->linkDecision($matches->first(), 'email');
            }
            if ($matches->count() > 1) {
                return ['action' => 'skip', 'reason' => "mehrdeutig: E-Mail '{$email}' matcht mehrere Kontakte — bitte manuell zuordnen"];
            }
        }

        // Suffix-Match nur mit normalisierter Nummer (E164-Ziffern).
        // Unparsebar/ungueltig -> kein Match-Versuch (kein Raten mit Muell).
        $needle = $this->normalizedPhoneDigits($phone);
        if ($needle !== null) {
            $matches = CrmContact::where('team_id', $employee->team_id)
                ->where('is_active', true)
                ->whereHas('phoneNumbers', function ($q) use ($needle) {
                    $q->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $needle])
                      ->orWhereRaw("REPLACE(REPLACE(raw_input, ' ', ''), '-', '') LIKE ?", ['%' . $needle]);
                })
                ->limit(2)
                ->get();
            if ($matches->count() === 1) {
                return $this->linkDecision($matches->first(), 'phone');
            }
            if ($matches->count() > 1) {
                return ['action' => 'skip', 'reason' => 'mehrdeutig: Telefon matcht mehrere Kontakte — bitte manuell zuordnen'];
            }
        }

        if (trim((string) $employee->first_name) === '' && trim((string) $employee->last_name) === '') {
            return ['action' => 'skip', 'reason' => 'kein Name am MA (CRM-Kontakt braucht first/last_name)'];
        }

        return [
            'action' => 'create',
            'email'  => $email !== '' ? $email : null,
            'phone'  => $phone !== '' ? $phone : null,
        ];
    }

    /**
     * Fuehrt eine decide()-Entscheidung aus (nur action link/create).
     *
     * @return array{contact_id: int, warnings: string[]}
     */
    public function execute(RecEmployee $employee, array $decision, ?int $userId = null): array
    {
        return DB::transaction(function () use ($employee, $decision, $userId) {
            $warnings = [];

            if ($decision['action'] === 'link') {
                $contactId = (int) $decision['contact_id'];
            } else {
                $contact = CrmContact::create([
                    'first_name'         => trim((string) $employee->first_name) ?: '-',
                    'last_name'          => trim((string) $employee->last_name) ?: '-',
                    'birth_date'         => $employee->birth_date,
                    'team_id'            => $employee->team_id,
                    'created_by_user_id' => $userId,
                    // Fallback-Kette wie bei email/phone-Typen: ACTIVE ist per
                    // Seeder ueblich, aber nicht DB-garantiert. Spalte ist
                    // nullable — explizites null wuerde aber auch den
                    // Spalten-Default (1) aushebeln -> lieber bester Treffer.
                    'contact_status_id'  => CrmContactStatus::where('code', 'ACTIVE')->value('id')
                        ?? CrmContactStatus::where('is_active', true)->value('id')
                        ?? CrmContactStatus::query()->value('id'),
                    'is_active'          => true,
                ]);
                $contactId = $contact->id;

                if (!empty($decision['email'])) {
                    $emailTypeId = CrmEmailType::where('code', 'PRIVATE')->value('id')
                        ?? CrmEmailType::where('is_active', true)->value('id')
                        ?? CrmEmailType::query()->value('id');
                    if ($emailTypeId) {
                        $contact->emailAddresses()->create([
                            'email_address' => $decision['email'],
                            'email_type_id' => $emailTypeId,
                            'is_primary'    => true,
                            'is_active'     => true,
                        ]);
                    } else {
                        $warnings[] = 'kein CrmEmailType vorhanden — E-Mail nicht angelegt';
                    }
                }

                if (!empty($decision['phone'])) {
                    $phoneResult = $this->createPhone($contact, $decision['phone']);
                    if ($phoneResult !== true) {
                        $warnings[] = $phoneResult;
                    }
                }
            }

            CrmContactLink::firstOrCreate([
                'contact_id'    => $contactId,
                'linkable_type' => $employee->getMorphClass(),
                'linkable_id'   => $employee->id,
            ], [
                'team_id'            => $employee->team_id,
                'created_by_user_id' => $userId,
            ]);

            return ['contact_id' => $contactId, 'warnings' => $warnings];
        });
    }

    /** Einheitliches link-Decision-Shape. */
    protected function linkDecision(CrmContact $contact, string $matchedBy): array
    {
        return [
            'action'       => 'link',
            'contact_id'   => $contact->id,
            'matched_by'   => $matchedBy,
            'contact_name' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
        ];
    }

    /**
     * Normalisiert eine Roh-Nummer zu E164-Ziffern fuer den Suffix-Match
     * ("0151 23456789" -> "4915123456789"). Unparsebar/ungueltig -> null.
     */
    protected function normalizedPhoneDigits(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($raw, 'DE');
            if (!$util->isValidNumber($parsed)) {
                return null;
            }
        } catch (NumberParseException) {
            return null;
        }
        return preg_replace('/[^0-9]/', '', $util->format($parsed, PhoneNumberFormat::E164));
    }

    /**
     * Telefonnummer parsen (libphonenumber, Region DE) + anlegen.
     * Ungueltig/unparsebar -> Kontakt bleibt ohne Nummer (Warn-Text zurueck);
     * eine Nummer ohne `international` koennte sendPortalNotification eh
     * nicht nutzen.
     *
     * @return true|string true bei Erfolg, sonst Warn-Text
     */
    protected function createPhone(CrmContact $contact, string $raw): bool|string
    {
        $phoneTypeId = CrmPhoneType::where('code', 'MOBILE')->value('id')
            ?? CrmPhoneType::where('is_active', true)->value('id')
            ?? CrmPhoneType::query()->value('id');
        if (!$phoneTypeId) {
            return 'kein CrmPhoneType vorhanden — Telefon nicht angelegt';
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($raw, 'DE');
            if (!$util->isValidNumber($parsed)) {
                return "Telefon '{$raw}' ungueltig — nicht angelegt";
            }
        } catch (NumberParseException) {
            return "Telefon '{$raw}' nicht parsebar — nicht angelegt";
        }

        $contact->phoneNumbers()->create([
            'raw_input'     => $raw,
            'international' => $util->format($parsed, PhoneNumberFormat::E164),
            'national'      => $util->format($parsed, PhoneNumberFormat::NATIONAL),
            'country_code'  => $util->getRegionCodeForNumber($parsed),
            'phone_type_id' => (int) $phoneTypeId,
            'is_primary'    => true,
            'is_active'     => true,
        ]);

        return true;
    }
}
