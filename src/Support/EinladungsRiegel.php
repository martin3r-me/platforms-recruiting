<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\KontoWriter;

/**
 * Wer bekommt KEINE Einladung — und warum (Spec HR-Bereich §4.2)? Eine Regel
 * fuer das Kommando recruiting:konto-einladen und den Konto-Kasten. grund()
 * ist rein; fuerPerson()/ohneWhatsapp() lesen die Datenbank.
 */
final class EinladungsRiegel
{
    public const KEINE_NUMMER = 'keine Nummer';
    public const KEIN_GEBURTSDATUM = 'kein Geburtsdatum';
    public const KEIN_WHATSAPP = 'kein WhatsApp';

    /**
     * Pruefreihenfolge — der erste zutreffende gewinnt; "keine Nummer" macht
     * alle anderen erst gar nicht zur Frage.
     *
     * @var list<string>
     */
    public const GRUENDE = [self::KEINE_NUMMER, self::KEIN_GEBURTSDATUM, self::KEIN_WHATSAPP];

    public static function grund(?string $nummer, bool $ohneGeburtsdatum, bool $ohneWhatsapp): ?string
    {
        if (trim((string) $nummer) === '') {
            return self::KEINE_NUMMER;
        }
        if ($ohneGeburtsdatum) {
            return self::KEIN_GEBURTSDATUM;
        }
        if ($ohneWhatsapp) {
            return self::KEIN_WHATSAPP;
        }

        return null;
    }

    /** Dieselbe Frage fuer EINE Person (Konto-Kasten). */
    public static function fuerPerson(int $personId): ?string
    {
        $person = DB::table('rec_persons')->where('id', $personId)->first(['id', 'phone']);
        if ($person === null) {
            return self::KEINE_NUMMER;
        }

        return self::grund(
            $person->phone,
            KontoWriter::personenOhneGeburtsdatum([$personId]) !== [],
            self::ohneWhatsapp([$person]) !== [],
        );
    }

    /**
     * Wessen Nummer im CRM als 'unavailable' bekannt ist (Spec §3: "Ohne
     * WhatsApp — bekommen kein Konto").
     *
     * NUR DAS BEKANNTE NEIN SPERRT. 'unknown' ist die Vorgabe der Spalte
     * (Migration 2025_02_18_000001) und steht an fast jeder Nummer, die nie
     * angeschrieben wurde; wer daraus ein Nein machte, schloesse den Bestand
     * von der Umstellung aus. Und ein 'available'/'opted_in' an derselben
     * Nummer hebt ein 'unavailable' auf: dann ist die Nummer nachweislich
     * erreichbar, und die andere Zeile ist der veraltete Eintrag.
     *
     * DIE SCHREIBWEISEN SIND DIE DES BESTANDES, nicht die reine Lehre —
     * nachgelesen in ContactPhoneSync::syncEmployee(), das seit dem Vorfall
     * RG19734 genau so sucht: linkable_type mit LIKE '%RecEmployee' (es gibt
     * kein Morph-Kuerzel fuer rec_employee), phoneable_type entweder als
     * Kuerzel 'crm_contact' (Morph-Karte des CrmServiceProvider) ODER als
     * voller Klassenname. Wer nur eine der beiden Formen abfragt, findet je
     * nach Alter der Zeile nichts und haelt niemanden auf.
     *
     * VERGLICHEN WIRD UEBER PhoneE164::suffix() — die letzten neun Ziffern.
     * Dieselbe Kruecke wie in ContactPhoneSync, und aus demselben Grund: im
     * CRM stehen "+49 151 …", "0151 …" und E.164 nebeneinander, und
     * normalisieren scheitert an unvollstaendigen Nummern.
     *
     * @param  list<object>  $personen
     * @return list<int>
     */
    public static function ohneWhatsapp(array $personen): array
    {
        if ($personen === []) {
            return [];
        }

        $personIds = array_map(static fn (object $p): int => (int) $p->id, $personen);

        /** @var array<int, int> $anstellungZuPerson */
        $anstellungZuPerson = DB::table('rec_employees')
            ->whereIn('rec_person_id', $personIds)
            ->pluck('rec_person_id', 'id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        if ($anstellungZuPerson === []) {
            return [];
        }

        /** @var array<int, list<int>> $kontaktZuPersonen */
        $kontaktZuPersonen = [];
        foreach (DB::table('crm_contact_links')
            ->where('linkable_type', 'LIKE', '%RecEmployee')
            ->whereIn('linkable_id', array_keys($anstellungZuPerson))
            ->get(['linkable_id', 'contact_id']) as $link) {
            $personId = $anstellungZuPerson[(int) $link->linkable_id] ?? null;
            if ($personId === null) {
                continue;
            }
            $kontaktZuPersonen[(int) $link->contact_id][] = $personId;
        }

        if ($kontaktZuPersonen === []) {
            return [];
        }

        /** @var array<int, list<string>> $statusJePerson */
        $statusJePerson = [];

        $suffixe = [];
        foreach ($personen as $person) {
            $suffixe[(int) $person->id] = PhoneE164::suffix((string) $person->phone);
        }

        foreach (DB::table('crm_phone_numbers')
            ->whereIn('phoneable_id', array_keys($kontaktZuPersonen))
            ->where(fn ($q) => $q->where('phoneable_type', 'crm_contact')
                ->orWhere('phoneable_type', 'LIKE', '%CrmContact'))
            ->where('is_active', 1)
            ->get(['phoneable_id', 'international', 'raw_input', 'whatsapp_status']) as $nummer) {
            foreach ($kontaktZuPersonen[(int) $nummer->phoneable_id] ?? [] as $personId) {
                $suffix = $suffixe[$personId] ?? '';
                if ($suffix === '') {
                    continue;
                }
                if (PhoneE164::suffix((string) $nummer->international) !== $suffix
                    && PhoneE164::suffix((string) $nummer->raw_input) !== $suffix) {
                    continue;
                }
                $statusJePerson[$personId][] = (string) $nummer->whatsapp_status;
            }
        }

        $ohne = [];
        foreach ($statusJePerson as $personId => $status) {
            if (array_intersect(['available', 'opted_in'], $status) !== []) {
                continue;
            }
            if (in_array('unavailable', $status, true)) {
                $ohne[] = $personId;
            }
        }

        return $ohne;
    }
}
