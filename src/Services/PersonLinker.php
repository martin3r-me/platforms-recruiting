<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Der EINE Schreiber der Personen-Zuordnung (Spec 2026-09-28, Paragraph 4).
 *
 * Drei Gruende, warum das hier zusammengefasst ist und nirgendwo sonst
 * angefasst werden darf:
 *
 *  - EIN SCHREIBER: rec_employees.rec_person_id wird ausschliesslich von den
 *    vier Methoden dieser Klasse gesetzt oder geaendert. Ein zweiter Weg
 *    (etwa ein Direkt-Update im Backfill oder im Import) wuerde genau die Art
 *    von Doppelung wiederholen, die dieses Projekt bei person_key und beim
 *    Nachweis-Spiegel schon dreimal beseitigt hat — und hier ist der Preis
 *    eines Fehlers hoeher: ein falsch verklammerter Datensatz zeigt einem
 *    Mitarbeiter Ausweiskopie, Bankverbindung und Vertrag eines fremden
 *    Menschen.
 *
 *  - OBSERVER-FREI: jeder Schreibzugriff auf rec_employees laeuft ueber
 *    DB::table(...), nie ueber Eloquent. Eine Personen-Zuordnung ist keine
 *    fachliche Aenderung am Mitarbeiter und darf zas_changed_at nicht setzen
 *    — sonst spuelt der Backfill den halben Bestand in die naechste
 *    ZAS-Update-Datei (dasselbe Muster wie PersonPairLinker::stamp() und
 *    ProofWriter, siehe deren Kopfkommentare).
 *
 *  - UMHAENGEN STATT UEBERSCHREIBEN, STILLLEGEN STATT LOESCHEN: beim
 *    Zusammenlegen verliert die Verlierer-Zeile ihre Anmeldung, bleibt aber
 *    stehen und behaelt ihre Nummer — die bleibt dadurch gesperrt, weil
 *    Anbieter Handynummern nach Monaten neu vergeben (Canvas 1793).
 *
 * Ruling T3-A: rec_persons traegt unique(team_id, phone). Im Bestand teilen
 * sich aber manchmal zwei VERSCHIEDENE Menschen eine Handynummer. verbinde()
 * wuerde dann beim Anlegen sterben. Deshalb: eine Nummer wird nur geschrieben,
 * wenn sie im Team noch frei ist. Ist sie bereits vergeben, entsteht die neue
 * Personen-Zeile ohne Nummer — eine Nummer darf nur an einem Konto haengen,
 * aber ein geteiltes Handy darf den Lauf nicht toeten. Der Aufrufer erkennt
 * den Fall daran, dass an der Zeile keine Nummer steht.
 */
class PersonLinker
{
    /**
     * Legt die Personen-Zeile an (falls noetig) und haengt die Anstellungen
     * daran. Traegt eine der genannten Anstellungen bereits eine
     * rec_person_id, wird diese Zeile benutzt. Tragen zwei verschiedene
     * Anstellungen verschiedene Personen, ist das der Zusammenlege-Fall und
     * gehoert zu fuehreZusammen(), nicht hierher.
     *
     * @param  list<int>  $employeeIds
     */
    public static function verbinde(array $employeeIds, ?int $teamId, ?string $phone): int
    {
        $vorhandenePersonen = DB::table('rec_employees')
            ->whereIn('id', $employeeIds)
            ->whereNotNull('rec_person_id')
            ->pluck('rec_person_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        if ($vorhandenePersonen->count() > 1) {
            throw new InvalidArgumentException(sprintf(
                'Die Anstellungen [%s] haengen bereits an verschiedenen Personen (%s) — das ist der '
                .'Zusammenlege-Fall, dafuer gibt es PersonLinker::fuehreZusammen(), nicht verbinde().',
                implode(', ', $employeeIds),
                $vorhandenePersonen->implode(', '),
            ));
        }

        $personId = $vorhandenePersonen->first() ?? self::legeZeileAn($teamId, $phone);

        DB::table('rec_employees')->whereIn('id', $employeeIds)->update(['rec_person_id' => $personId]);

        return $personId;
    }

    /**
     * Haengt eine Anstellung an eine ANDERE, neue Person — der Weg zurueck
     * fuer HR, wenn eine Zuordnung falsch war (Fehlklick, verwechselte
     * Nummer). Die bisherige Person behaelt ihre restlichen Anstellungen und
     * ihre Nummer unveraendert; die geloeste Anstellung bekommt eine frische,
     * nummernlose Zeile — die alte Nummer gehoert der zurueckbleibenden
     * Person, nicht dieser.
     */
    public static function loese(int $employeeId): int
    {
        $anstellung = DB::table('rec_employees')->where('id', $employeeId)->first(['team_id']);
        if ($anstellung === null) {
            throw new InvalidArgumentException("Anstellung {$employeeId} existiert nicht.");
        }

        $teamId = $anstellung->team_id !== null ? (int) $anstellung->team_id : null;
        $neuePersonId = self::legeZeileAn($teamId, null);

        DB::table('rec_employees')->where('id', $employeeId)->update(['rec_person_id' => $neuePersonId]);

        return $neuePersonId;
    }

    /**
     * Legt die Verlierer-Zeile still und haengt ihre Anstellungen an den
     * Sieger. Die Verlierer-Zeile bleibt stehen (merged_into_person_id),
     * verliert ihre Anmeldung, behaelt aber ihre Nummer — die bleibt dadurch
     * gesperrt (Canvas 1793).
     */
    public static function fuehreZusammen(int $siegerId, int $verliererId): void
    {
        if ($siegerId === $verliererId) {
            throw new InvalidArgumentException('Sieger und Verlierer duerfen nicht dieselbe Person sein.');
        }

        DB::transaction(function () use ($siegerId, $verliererId) {
            DB::table('rec_persons')->where('id', $verliererId)->update([
                'merged_into_person_id' => $siegerId,
                'password_hash'         => null,
                'registered_at'         => null,
                'updated_at'            => now(),
            ]);

            DB::table('rec_employees')->where('rec_person_id', $verliererId)->update(['rec_person_id' => $siegerId]);
        });
    }

    /**
     * Schreibt die Nummer an die Person UND auf alle ihre Anstellungen
     * (Spec 4.3, Regel 5) — sonst kommt der Einmalcode auf einer anderen
     * Nummer an als die Anmeldung.
     */
    public static function setzeNummer(int $personId, ?string $phone): void
    {
        DB::transaction(function () use ($personId, $phone) {
            DB::table('rec_persons')->where('id', $personId)->update([
                'phone'      => $phone,
                'updated_at' => now(),
            ]);

            DB::table('rec_employees')->where('rec_person_id', $personId)->update(['phone' => $phone]);
        });
    }

    /**
     * Neue Personen-Zeile. Traegt Ruling T3-A um: eine im Team bereits
     * vergebene Nummer wird nicht mitgeschrieben, damit der Insert nicht am
     * Eindeutigkeits-Index scheitert.
     */
    private static function legeZeileAn(?int $teamId, ?string $phone): int
    {
        if ($phone !== null && self::nummerIstImTeamVergeben($teamId, $phone)) {
            $phone = null;
        }

        return (int) DB::table('rec_persons')->insertGetId([
            'uuid'       => (string) UuidV7::generate(),
            'team_id'    => $teamId,
            'phone'      => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function nummerIstImTeamVergeben(?int $teamId, string $phone): bool
    {
        return DB::table('rec_persons')->where('team_id', $teamId)->where('phone', $phone)->exists();
    }
}
