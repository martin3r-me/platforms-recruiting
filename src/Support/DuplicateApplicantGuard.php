<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Collection;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Dedup-Schutz für den Auto-Pilot: erkennt, ob ein anderer aktiver Bewerber im
 * selben Team dieselbe Telefonnummer trägt, bevor Erstkontakt/Reminder gesendet
 * wird.
 *
 * Nummern-Vergleich über eine KANONISCHE Digit-Form (canonicalDigits) auf BEIDEN
 * Seiten in PHP — bewusst kein SQL-seitiges Strippen/Matchen: die
 * international-Spalte enthält Legacy-Formate (nationale 0-Notation, nackte NSN,
 * Leerzeichen) und über den ContactIndex-Fallback („Store raw if parsing fails")
 * auch beliebige Roh-Eingaben (Slash, Klammern, Punkte). Ein einziger
 * PHP-Strip ist damit per Konstruktion symmetrisch und engine-unabhängig.
 *
 * Spec: docs/superpowers/specs/2026-07-20-applicant-dedup-design.md
 * (Entscheidungslogik Senior-Regel folgt mit der Implementierungsphase.)
 */
class DuplicateApplicantGuard
{
    /**
     * Kanonische Digit-Form einer Telefonnummer: Ländercode-präfixierte Ziffern
     * ohne führendes '+'/'00' (z. B. '491637899743').
     *
     * Regeln, in dieser Reihenfolge:
     *  1. Nicht-Ziffern total strippen (auch Slash, Klammern, Punkt, NBSP …).
     *  2. Roh-Input beginnt mit '+'   → Ziffern sind bereits ländercodiert
     *     (erhält auch Nicht-DE-Nummern wie +43…).
     *  3. Ziffern beginnen mit '00'   → internationales Präfix strippen.
     *  4. Ziffern beginnen mit '0'    → deutsche Inlands-Notation → '49' + NSN.
     *     (Disambiguiert Ortsnetze wie 0491 Leer: '0491234567' → '49491234567'.)
     *  5. Ziffern beginnen mit '49'   → als ländercodiert interpretiert
     *     (nackte wa_id-Schreibweise des WhatsApp-Inbound-Pfads).
     *     DOKUMENTIERTE AMBIGUITÄT: eine nackte NSN eines 049x-Ortsnetzes OHNE
     *     führende 0 würde hier fehlinterpretiert → der Guard verfehlt dieses
     *     Paar (fail-open, kein False-Flag). Alle bekannten Schreibpfade nackter
     *     Werte schreiben ländercodiert; deutsche Mobil-NSN beginnen mit 1.
     *     Ebenfalls dokumentiert: Schreibweise „+49 (0) 163…" kanonisiert zu
     *     490163… und verfehlt +49163… (fail-open, kein False-Flag).
     *  6. Sonst (nackte NSN ohne 0, Legacy wie '17664744605') → '49' + Ziffern.
     */
    public static function canonicalDigits(?string $number): ?string
    {
        $raw = trim((string) $number);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            return '49' . substr($digits, 1);
        }
        if (str_starts_with($digits, '49')) {
            return $digits;
        }

        return '49' . $digits;
    }

    /**
     * Senior-Regel (Totalordnung, ordnungsunabhängig): entscheidet, ob der
     * Kandidat senden darf oder auf ein Original geflaggt wird.
     *
     * Ein Match ist senior, wenn er kontaktiert ist und der Kandidat nicht,
     * ODER beide denselben Kontakt-Status haben und der Match die kleinere ID
     * hat. Kein seniorer Match → null (senden ok). Sonst: Original = ranghöchster
     * Senior (kontaktierte vor unkontaktierten, innerhalb dessen kleinste ID).
     *
     * @param iterable<object{id: int, auto_pilot_last_reminder_at: mixed}> $matches
     * @return int|null Original-ID zum Flaggen, oder null = senden ok
     */
    public static function decide(int $candidateId, mixed $candidateLastReminderAt, iterable $matches): ?int
    {
        $candidateContacted = !empty($candidateLastReminderAt);

        $seniors = [];
        foreach ($matches as $match) {
            $id = (int) $match->id;
            if ($id === $candidateId) {
                continue;
            }
            $contacted = !empty($match->auto_pilot_last_reminder_at);

            $isSenior = ($contacted && !$candidateContacted)
                || ($contacted === $candidateContacted && $id < $candidateId);

            if ($isSenior) {
                $seniors[] = ['id' => $id, 'contacted' => $contacted];
            }
        }

        if ($seniors === []) {
            return null;
        }

        // Kontaktierte zuerst (desc), innerhalb dessen kleinste ID (asc)
        usort($seniors, fn (array $a, array $b) =>
            [$b['contacted'], $a['id']] <=> [$a['contacted'], $b['id']]);

        return $seniors[0]['id'];
    }

    /**
     * Match-Set für den Guard: alle ANDEREN aktiven, nicht abgelehnten Bewerber
     * im Team des Kandidaten, die auf irgendeiner aktiven Nummer (nicht nur
     * Primary) kanonisch dieselbe Nummer tragen wie die Versand-Nummer.
     *
     * SQL filtert nur strukturell (Team, aktiv, nicht rejected, Nummer mit
     * international vorhanden) und liefert per JOIN genau drei Spalten — KEINE
     * Modell-Hydration (bei 20k Team-Bewerbern wären das ~260 MB / >1 s; als
     * flache Rows ~1 Query im zweistelligen ms-Bereich). Der Nummern-Vergleich
     * läuft in PHP über canonicalDigits auf beiden Seiten. Geparkte/HR-Desk-
     * Bewerber bleiben bewusst im Set (ein geparktes, kontaktiertes Original
     * besitzt den Chat).
     *
     * @return Collection<int, object{id: int, auto_pilot_last_reminder_at: ?string}>
     */
    public static function matchesFor(RecApplicant $candidate, ?string $sendNumber): Collection
    {
        $canonical = self::canonicalDigits($sendNumber);
        if ($canonical === null) {
            return new Collection();
        }

        $applicantMorph = $candidate->getMorphClass();
        $contactMorph = (new \Platform\Crm\Models\CrmContact())->getMorphClass();

        $rows = RecApplicant::query()
            ->where('rec_applicants.team_id', $candidate->team_id)
            ->where('rec_applicants.id', '!=', $candidate->id)
            ->where('rec_applicants.is_active', true)
            ->whereNull('rec_applicants.rejected_at')
            ->join('crm_contact_links', function ($join) use ($applicantMorph) {
                $join->on('crm_contact_links.linkable_id', '=', 'rec_applicants.id')
                    ->where('crm_contact_links.linkable_type', '=', $applicantMorph);
            })
            ->join('crm_phone_numbers', function ($join) use ($contactMorph) {
                $join->on('crm_phone_numbers.phoneable_id', '=', 'crm_contact_links.contact_id')
                    ->where('crm_phone_numbers.phoneable_type', '=', $contactMorph)
                    ->where('crm_phone_numbers.is_active', '=', true)
                    ->whereNotNull('crm_phone_numbers.international');
            })
            ->orderBy('rec_applicants.id')
            ->toBase()
            ->get([
                'rec_applicants.id',
                'rec_applicants.auto_pilot_last_reminder_at',
                'crm_phone_numbers.international',
            ]);

        return $rows
            ->filter(fn (object $row) => self::canonicalDigits($row->international) === $canonical)
            ->unique('id')
            ->map(fn (object $row) => (object) [
                'id' => (int) $row->id,
                'auto_pilot_last_reminder_at' => $row->auto_pilot_last_reminder_at,
            ])
            ->values();
    }
}
