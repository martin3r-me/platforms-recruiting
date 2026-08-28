<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Collection;
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
use Platform\Recruiting\Support\PersonNameMatch;

/**
 * Findet (oder erstellt) den CRM-Kontakt zu einem RecEmployee und verlinkt
 * ihn via crm_contact_links — Grundlage fuer Kommunikation (WhatsApp-Portal-
 * Einladung, Threads) bei MA, die nicht aus dem Recruiting-Flow stammen.
 * Der ZAS-Import legt keine Kontakte an; Recruiting-MA bekommen sie beim
 * Anlegen aus dem Bewerber gespiegelt (CreateEmployeeFromApplicantService).
 *
 * Match-Kaskade (non-destruktiv, Basis: IncomingApplicationService-Konvention,
 * verschaerft um Eindeutigkeits-Guards):
 *   1. E-Mail via LOWER() (CRM speichert gemischte Schreibweise) — bei GENAU
 *      EINEM Treffer linken; bei mehreren Treffern (Runde 4, #0): einengen
 *      statt abbrechen — Schnitt E-Mail ∩ Telefon, dann Kontakt mit bereits
 *      verlinktem aktiven Namensvetter; erst wenn das nicht auf genau einen
 *      Kontakt fuehrt: skip "mehrdeutig"
 *   2. Telefon per Ziffern-Suffix auf international/raw_input — Needle wird
 *      vorher per libphonenumber normalisiert (E164), weil der naive
 *      Ziffern-Vergleich an fuehrender 0 vs. Laendercode scheitert
 *      ("0151..." matcht nie "+49151..."). Bei GENAU EINEM Treffer linken;
 *      bei mehreren Treffern (Runde 4, #0): dieselbe Namensvetter-Einengung
 *      wie bei E-Mail, sonst skip "mehrdeutig"
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

        $email  = mb_strtolower(trim((string) $employee->email));
        $phone  = trim((string) $employee->phone);
        $needle = $this->normalizedPhoneDigits($phone);

        $byEmail = $email !== '' ? $this->emailCandidates($employee, $email) : collect();
        $byPhone = $needle !== null ? $this->phoneCandidates($employee, $needle) : collect();

        if ($byEmail->count() === 1) {
            return $this->linkDecision($employee, $byEmail->first(), 'email');
        }
        if ($byEmail->count() > 1) {
            // Runde 4 (#0): einengen statt abbrechen — Schnitt mit Telefon, dann Namensvetter.
            $narrowed = $this->narrow($employee, $byEmail, $byPhone);
            if ($narrowed !== null) {
                return $this->linkDecision($employee, $narrowed['contact'], 'email+' . $narrowed['by']);
            }

            return ['action' => 'skip', 'reason' => "mehrdeutig: E-Mail '{$email}' matcht {$byEmail->count()} Kontakte, Telefon/Namensvetter engen nicht ein — bitte manuell zuordnen"];
        }

        if ($byPhone->count() === 1) {
            return $this->linkDecision($employee, $byPhone->first(), 'phone');
        }
        if ($byPhone->count() > 1) {
            $narrowed = $this->narrow($employee, $byPhone, collect());
            if ($narrowed !== null) {
                return $this->linkDecision($employee, $narrowed['contact'], 'phone+' . $narrowed['by']);
            }

            return ['action' => 'skip', 'reason' => "mehrdeutig: Telefon matcht {$byPhone->count()} Kontakte — bitte manuell zuordnen"];
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

    /** Aktive Kontakte des Teams mit exakt dieser E-Mail (LOWER — CRM speichert gemischt). */
    protected function emailCandidates(RecEmployee $employee, string $email): Collection
    {
        return CrmContact::where('team_id', $employee->team_id)
            ->where('is_active', true)
            ->whereHas('emailAddresses', fn ($q) => $q->whereRaw('LOWER(email_address) = ?', [$email]))
            ->limit(10)
            ->get();
    }

    /** Aktive Kontakte des Teams, deren Nummer (international/raw_input) auf die E164-Ziffern endet. */
    protected function phoneCandidates(RecEmployee $employee, string $needle): Collection
    {
        return CrmContact::where('team_id', $employee->team_id)
            ->where('is_active', true)
            ->whereHas('phoneNumbers', function ($q) use ($needle) {
                $q->whereRaw("REPLACE(REPLACE(REPLACE(international, ' ', ''), '-', ''), '+', '') LIKE ?", ['%' . $needle])
                  ->orWhereRaw("REPLACE(REPLACE(raw_input, ' ', ''), '-', '') LIKE ?", ['%' . $needle]);
            })
            ->limit(10)
            ->get();
    }

    /**
     * Engt mehrdeutige Kandidaten ein (Runde 4, #0):
     *   1. Schnittmenge mit $other (E-Mail ∩ Telefon) — genau EIN Ueberlebender -> Treffer ('phone').
     *      Mehrere Ueberlebende -> mit der engeren Menge weiter.
     *   2. Kandidat, der bereits an einem AKTIVEN Mitarbeiter gleichen Namens im selben
     *      Team haengt (Doppel-MA RG/MA derselben Person) -> Treffer ('namesake').
     * Sonst null -> Aufrufer skippt "mehrdeutig". Nie raten.
     *
     * @return ?array{contact: CrmContact, by: string}
     */
    protected function narrow(RecEmployee $employee, Collection $candidates, Collection $other): ?array
    {
        if ($other->isNotEmpty()) {
            $otherIds = $other->pluck('id')->map(fn ($v) => (int) $v)->all();
            $both = $candidates->filter(fn ($c) => in_array((int) $c->id, $otherIds, true))->values();
            if ($both->count() === 1) {
                return ['contact' => $both->first(), 'by' => 'phone'];
            }
            if ($both->count() > 1) {
                $candidates = $both;
            }
        }

        $namesakes = $candidates->filter(fn ($c) => $this->hasLinkedNamesake($employee, $c))->values();
        if ($namesakes->count() === 1) {
            return ['contact' => $namesakes->first(), 'by' => 'namesake'];
        }

        return null;
    }

    /** Haengt an $contact bereits ein anderer aktiver MA desselben Teams mit plausibel gleichem Namen? */
    protected function hasLinkedNamesake(RecEmployee $employee, CrmContact $contact): bool
    {
        $linkedIds = CrmContactLink::query()
            ->where('contact_id', $contact->id)
            ->where('linkable_type', $employee->getMorphClass())
            ->pluck('linkable_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        if ($linkedIds === []) {
            return false;
        }

        return RecEmployee::query()
            ->whereIn('id', $linkedIds)
            ->where('team_id', $employee->team_id)
            ->where('is_active', true)
            ->where('id', '!=', $employee->id)
            ->get(['first_name', 'last_name'])
            ->contains(fn ($e) => PersonNameMatch::plausible(
                (string) $employee->first_name,
                (string) $employee->last_name,
                trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? ''))
            ));
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

    /**
     * Einheitliches link-Decision-Shape — mit Namens-Plausibilitaet als
     * letzter Huerde vor der Verknuepfung.
     *
     * Ein exakter E-Mail- oder Telefon-Treffer beweist nur, dass der KONTAKT
     * diese Adresse traegt, nicht dass er DIESEM Menschen gehoert. Im Dry-Run
     * vom 2026-08-27 waren drei Treffer technisch einwandfrei (Adresse
     * primaer + aktiv) und trotzdem der falsche Mensch, weil der MA-Stammsatz
     * aus ZAS eine fremde Adresse trug (Kollege, Vermittler-Sammelpostfach).
     * Ein falscher Link ist hier teuer: an dem Kontakt haengt die
     * WhatsApp-Portal-Einladung samt Login-Hinweisen.
     *
     * Bei Zweifel wird uebersprungen, nicht geraten und auch nicht neu
     * angelegt — ein CREATE wuerde die fremde Adresse in einen neuen Kontakt
     * schreiben. Beide Namen stehen im Grund, damit der Fall im
     * Command-Output ohne Rueckfrage entscheidbar ist.
     */
    protected function linkDecision(RecEmployee $employee, CrmContact $contact, string $matchedBy): array
    {
        $contactName  = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
        $employeeName = trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''));

        if (!PersonNameMatch::plausible((string) $employee->first_name, (string) $employee->last_name, $contactName)) {
            return [
                'action' => 'skip',
                'reason' => "Name passt nicht: MA \"{$employeeName}\" vs. Kontakt #{$contact->id} \"{$contactName}\""
                    . " (Treffer ueber {$matchedBy}) — Adresse/Nummer gehoert moeglicherweise einem anderen Menschen",
            ];
        }

        return [
            'action'       => 'link',
            'contact_id'   => $contact->id,
            'matched_by'   => $matchedBy,
            'contact_name' => $contactName,
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
