<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Platform\Recruiting\Support\PhoneE164;
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
 *    Menschen. Seit Fixrunde 1 (Ruling T3-D) ist rec_person_id deshalb auch
 *    aus RecEmployee::$fillable entfernt — sonst waere "ein Schreiber" nur
 *    eine Verabredung, kein technischer Zwang.
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
 * aber ein geteiltes Handy darf den Lauf nicht toeten.
 *
 * Ruling T3-B (Fixrunde 1, Pruefer-Befund): PersonGroupPlanner vergleicht
 * Nummern ueber PhoneE164::suffix() (formatunabhaengig), reicht dann aber den
 * ROHEN Wert weiter. Die Kollisionspruefung und der Eindeutigkeits-Index hier
 * arbeiten dagegen auf dem gespeicherten Spaltenwert — zwei Schreibweisen
 * derselben Nummer ("+4915..." und "015...") waeren also unentdeckt an zwei
 * verschiedenen Personen-Zeilen gelandet. Deshalb normalisiert PersonLinker
 * jede Nummer an seiner eigenen Grenze, mit PhoneE164::normalize(), bevor er
 * sie vergleicht oder schreibt — in rec_persons UND in rec_employees. Liefert
 * normalize() null (Nummer nicht lesbar), gilt derselbe Weg wie bei einer
 * Kollision: die Zeile entsteht ohne Nummer, der Fall geht an HR.
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
     * Erkennungsrezept fuer den Aufrufer (Ruling T3-A/T3-B): wurde eine
     * Nummer uebergeben, aber die zurueckgegebene Person traegt an
     * rec_persons.phone trotzdem keine (leer statt Nummer), dann ist sie
     * entweder an einer im Team bereits vergebenen Nummer (geteiltes Handy)
     * oder an einer unlesbaren Schreibweise gescheitert. Es gibt dafuer
     * bewusst kein eigenes Flag — die Signatur bleibt int. Wer den Fall
     * erkennen will, prueft rec_persons.phone, nicht das Rueckgabeverhalten.
     * Diese Regel lebt NUR hier, nicht in einem zweiten Pruefweg (etwa dem
     * Backfill) — sonst gibt es die Doppelung wieder, die dieses Projekt
     * schon dreimal beseitigt hat.
     *
     * @param  list<int>  $employeeIds
     */
    public static function verbinde(array $employeeIds, ?int $teamId, ?string $phone): int
    {
        if ($employeeIds === []) {
            // I1 (Fixrunde 1): eine leere Liste darf trotzdem geschrieben
            // werden koennen (keine Anstellung zum Anhaengen) — ohne diese
            // Wache entstuende eine Personen-Zeile MIT der Nummer, an keiner
            // Anstellung. Der naechste echte Mensch mit derselben Nummer
            // bekaeme dann nach Ruling T3-A eine Zeile OHNE Nummer und koennte
            // sich nie registrieren. Deshalb: nichts anlegen, sofort weigern.
            throw new InvalidArgumentException(
                'verbinde() ohne Anstellungen wuerde eine Personen-Zeile mit der Nummer anlegen, ohne sie an '
                .'irgendjemanden zu haengen — die Nummer waere fuer den echten Menschen verbrannt.',
            );
        }

        $vorhandenePersonen = DB::table('rec_employees')
            ->whereIn('id', $employeeIds)
            ->whereNotNull('rec_person_id')
            ->pluck('rec_person_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        if ($vorhandenePersonen->count() > 1) {
            // I5 (Fixrunde 1): diese Pruefung MUSS vor jedem Schreibzugriff
            // stehen. Stuende sie danach, haengten im Fehlerfall bereits beide
            // Anstellungen auf einer Zeile, und die Ausnahme kommentierte den
            // Schaden nur noch, statt ihn zu verhindern.
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
     *
     * Nachsatz (Fixrunde 1, Review-Zweifel b): rec_employees.phone der
     * geloesten Anstellung bleibt dabei unveraendert stehen — sie traegt also
     * ab jetzt noch die ALTE Nummer, waehrend ihre neue Person keine hat. Wer
     * loese() aufruft, muss anschliessend setzeNummer() fuer die neue Person
     * aufrufen, sonst driftet rec_employees.phone gegen rec_persons.phone.
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
     *
     * Vier Wachen (C1, Fixrunde 1 — belegt, nicht vermutet: eine erfundene
     * Sieger-ID haengte die Anstellung sonst an einen Geist, und zwei
     * gegenlaeufige Aufrufe erzeugten einen RING in merged_into_person_id, an
     * dem der Mensch sich nie mehr anmelden kann):
     *  1. Sieger muss existieren.
     *  2. Sieger darf nicht selbst schon stillgelegt sein — sonst entsteht
     *     beim naechsten (versehentlich gegenlaeufigen) Aufruf die Kette.
     *  3. Verlierer muss existieren.
     *  4. Beide muessen im selben Team stehen.
     * Dazu, wie schon vorher: Sieger und Verlierer duerfen nicht dieselbe
     * Zeile sein (ein Doppelklick wuerde sonst die eigene Anmeldung loeschen).
     */
    public static function fuehreZusammen(int $siegerId, int $verliererId): void
    {
        if ($siegerId === $verliererId) {
            throw new InvalidArgumentException('Sieger und Verlierer duerfen nicht dieselbe Person sein.');
        }

        $sieger = DB::table('rec_persons')->where('id', $siegerId)->first(['id', 'team_id', 'merged_into_person_id']);
        if ($sieger === null) {
            throw new InvalidArgumentException("Sieger-Person {$siegerId} existiert nicht.");
        }
        if ($sieger->merged_into_person_id !== null) {
            throw new InvalidArgumentException(
                "Sieger-Person {$siegerId} ist selbst schon stillgelegt (zeigt auf Person "
                ."{$sieger->merged_into_person_id}) — sonst entstuende ein Ring in merged_into_person_id, "
                .'an dem sich niemand mehr anmelden kann.',
            );
        }

        $verlierer = DB::table('rec_persons')->where('id', $verliererId)->first(['id', 'team_id']);
        if ($verlierer === null) {
            throw new InvalidArgumentException("Verlierer-Person {$verliererId} existiert nicht.");
        }

        if ($sieger->team_id !== $verlierer->team_id) {
            throw new InvalidArgumentException(sprintf(
                'Sieger-Person %d (Team %s) und Verlierer-Person %d (Team %s) stehen in verschiedenen Teams — '
                .'ein Zusammenlegen ueber Teamgrenzen hinweg ist kein gueltiger Fall.',
                $siegerId,
                $sieger->team_id ?? 'NULL',
                $verliererId,
                $verlierer->team_id ?? 'NULL',
            ));
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
     *
     * Nachsatz (Fixrunde 1, Review-Befund I3 — bewusst NICHT hier behoben):
     * diese Methode zieht den verknuepften CRM-Kontakt NICHT mit. Der Abgleich
     * laeuft normalerweise ueber RecEmployeePhoneSyncObserver, der aber nur
     * auf Eloquent-Speicherungen reagiert — dieser Dienst schreibt bewusst
     * observer-frei. Wer setzeNummer() aufruft, MUSS anschliessend
     * Platform\Recruiting\Services\Zas\ContactPhoneSync::syncEmployee() fuer
     * die betroffenen Anstellungen nachziehen — sonst behaelt der CRM-Kontakt
     * die alte Nummer, WhatsApp-Antworten laufen ins Leere und der Mensch
     * sperrt sich beim naechsten Einmalcode selbst aus (Spec §9.2, Vorfall
     * RG19734). In dieser Stufe hat setzeNummer() noch keinen Aufrufer; das
     * Nachziehen ist Aufgabe des ersten Aufrufers, nicht dieser Methode.
     */
    public static function setzeNummer(int $personId, ?string $phone): void
    {
        $phone = PhoneE164::normalize($phone);

        DB::transaction(function () use ($personId, $phone) {
            DB::table('rec_persons')->where('id', $personId)->update([
                'phone'      => $phone,
                'updated_at' => now(),
            ]);

            DB::table('rec_employees')->where('rec_person_id', $personId)->update(['phone' => $phone]);
        });
    }

    /**
     * Neue Personen-Zeile. Normalisiert zuerst (Ruling T3-B) und traegt dann
     * Ruling T3-A um: eine im Team bereits vergebene Nummer (nach
     * Normalisierung verglichen) wird nicht mitgeschrieben, damit der Insert
     * nicht am Eindeutigkeits-Index scheitert.
     */
    private static function legeZeileAn(?int $teamId, ?string $phone): int
    {
        $phone = PhoneE164::normalize($phone);

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
